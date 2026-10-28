<?php

namespace App\Services\Site;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Destination;
use App\Models\Catalog\DestinationContent;
use App\Models\Collab\Conversation;
use App\Models\Collab\Message;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Marketing\Consent;
use App\Models\Marketing\Subscriber;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Channels\EcomCheckout;
use App\Services\Collab\InboundProcessor;
use App\Services\Collab\InboxService;
use App\Services\Crm\BusinessDayCalculator;
use App\Services\Crm\CrmClock;
use App\Services\Crm\LeadDuplicateFinder;
use App\Services\Crm\LeadService;
use App\Services\Marketing\ConsentService;
use App\Services\Notify;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Captación del sitio (spec 15 §6.4, SIT-BR-22..25): viajes a medida y contacto «cotizar» → lead sin asignar con SLA y score; contacto →
 * conversación `Formulario`; chat → conversación `Web chat` con respuesta automática; newsletter con consentimiento y doble opt-in; eventos del embudo.
 */
class SiteIntake
{
    public const ANALYTICS_EVENTS = ['page_view', 'search', 'view_item_list', 'view_item', 'add_to_wishlist', 'remove_from_wishlist', 'add_to_cart', 'remove_from_cart',
        'view_cart', 'apply_promo', 'select_promotion', 'begin_checkout', 'add_shipping_info', 'add_payment_info', 'payment_failed', 'purchase', 'purchase_pending',
        'purchase_balance', 'generate_lead', 'sign_up', 'sign_up_newsletter', 'login', 'share', 'contact_whatsapp', 'select_language', 'select_currency', 'claim_submitted'];

    public const AUTO_REPLY = 'Gracias por escribirnos. Un asesor le responderá en unos minutos (horario: lunes a viernes de 9:00 a 19:00 y sábados de 9:00 a 13:00).';

    public function __construct(
        private readonly LeadService $leads,
        private readonly LeadDuplicateFinder $duplicates,
        private readonly InboundProcessor $inbound,
        private readonly InboxService $inbox,
        private readonly ConsentService $consents,
        private readonly BusinessDayCalculator $days,
        private readonly SequenceService $sequences,
        private readonly CrmClock $clock,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** SIT-BR-22: viaje a medida → lead `nuevo` sin asignar (o `duplicado`), score, SLA 24 h hábiles y consentimiento. */
    public function lead(array $d, string $origin = 'tailor_made'): array
    {
        $e = [];
        $dests = array_values(array_filter(array_map('trim', (array) ($d['destinations'] ?? []))));
        $undecided = filter_var($d['undecided_dates'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $today = $this->clock->today();
        if (! $dests) {
            $e['destinations'] = ['Elija al menos un destino.'];
        }
        $start = (string) ($d['start_date'] ?? '');
        if (! $undecided) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || $start < $today->copy()->addDays(2)->toDateString()) {
                $e['start_date'] = ['La fecha de inicio debe ser al menos en 2 días (o marque «Aún no defino fechas»).'];
            }
            $return = (string) ($d['return_date'] ?? '');
            if ($return !== '' && $return < $start) {
                $e['return_date'] = ['El regreso no puede ser antes del inicio.'];
            }
        }
        $adults = (int) ($d['adults'] ?? 0);
        if ($adults < 1) {
            $e['adults'] = ['Indique al menos un adulto.'];
        }
        foreach (['name' => 'Indique su nombre.', 'phone' => 'Indique su teléfono.'] as $f => $msg) {
            if (trim((string) ($d[$f] ?? '')) === '') {
                $e[$f] = [$msg];
            }
        }
        if (! preg_match(EcomCheckout::EMAIL, (string) ($d['email'] ?? ''))) {
            $e['email'] = ['Ingrese un correo válido.'];
        }
        if (! filter_var($d['consent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $e['consent'] = ['Acepte el tratamiento de sus datos personales (Ley 29733).'];
        }
        if ($e) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'VALIDATION_ERROR', reset($e)[0], [], ['errors' => $e]);
        }
        $email = mb_strtolower(trim((string) $d['email']));
        $children = max(0, (int) ($d['children'] ?? 0));
        $pax = $adults + $children;
        $budget = (float) ($d['budget'] ?? 0);
        $product = ! empty($d['product']) ? (app(SiteCatalog::class)->published()->first(fn ($p) => SiteCatalog::slugOf($p) === $d['product'] || $p->ref === $d['product'])) : null;
        $destId = $this->destinationId($dests[0]);
        $score = min(95, 40 + ($undecided ? 0 : 12) + ($pax >= 4 ? 10 : 0) + (trim((string) $d['phone']) !== '' ? 6 : 0) + ($budget >= 2000 ? 12 : 0) + ($product ? 8 : 0));
        $openDup = Lead::where('email_norm', $email)->whereIn('status', ['nuevo', 'contactado', 'calificado'])->orderBy('created_at')->first();
        $client = Client::where('email_norm', $email)->orderBy('created_at')->first();
        $notes = trim(implode("\n", array_filter([
            $origin === 'contact_form' ? 'Formulario de contacto: «Quiero cotizar un viaje».' : 'Viaje a medida desde el sitio web.',
            trim((string) ($d['comments'] ?? '')),
        ])));

        $lead = DB::transaction(function () use ($d, $dests, $undecided, $start, $pax, $adults, $children, $budget, $product, $destId, $score, $openDup, $client, $notes, $email, $origin) {
            $lead = $this->leads->create([
                'full_name' => mb_substr(trim((string) $d['name']), 0, 160), 'email' => $email, 'phone' => mb_substr(trim((string) $d['phone']), 0, 40),
                'channel_code' => 'web', 'source_code' => 'formulario_web', 'interest_code' => count($dests) > 1 || ($product && SitePricing::isPackage($product)) ? 'paquete' : 'tour',
                'destination_id' => $destId, 'destination_label' => mb_substr(implode(', ', $dests), 0, 120), 'product_id' => $product?->id,
                'travel_date' => $undecided ? null : $start, 'pax' => $pax, 'notes' => $notes,
            ], null, 'create_new')['lead'];
            $now = now();
            $lead->forceFill([
                'score' => $score, 'status' => $openDup ? Lead::DUPLICATE : Lead::NEW, 'duplicate_of_type' => $openDup ? 'lead' : null, 'duplicate_of_id' => $openDup?->id,
                'matched_client_id' => $client?->id, 'sla_due_at' => $this->slaDue($now),
                'web_request' => ['origin' => $origin, 'destinations' => $dests, 'return_date' => $d['return_date'] ?? null, 'flexibility' => $d['flexibility'] ?? null,
                    'adults' => $adults, 'children' => $children, 'budget' => $budget ?: null, 'interests' => array_values((array) ($d['interests'] ?? [])), 'hotel' => $d['hotel'] ?? null,
                    'service_mode' => $d['service_mode'] ?? null, 'country' => $d['country'] ?? null, 'preferred_contact' => $d['preferred_contact'] ?? null],
                'consent' => ['data_processing' => true, 'ts' => $now->toIso8601String(), 'policy_version' => SiteCheckout::POLICY_VERSION],
            ])->save();
            $this->audit->log('Lead', $lead->code, 'crear_desde_sitio_web', null, ($openDup ? "Duplicado de {$openDup->code}" : 'Nuevo sin asignar')." · score {$score} · SLA ".$lead->sla_due_at->toIso8601String(), system: true);

            return $lead;
        });
        foreach (CatalogRecipients::managers() as $user) {
            $this->notify->push('lead.nuevo', 'portal', $user, "Lead web sin asignar: {$lead->full_name} ({$lead->code})", "{$lead->destination_label} · {$pax} pax · score {$score}",
                ['related_type' => 'lead', 'related_id' => $lead->id]);
        }
        $this->notify->push('lead.acuse', 'email', $email, "Recibimos su solicitud {$lead->code}", 'Un asesor le enviará una propuesta en un máximo de 24 horas hábiles (4 h en horario de oficina).',
            ['related_type' => 'lead', 'related_id' => $lead->id]);

        return ['code' => $lead->code, 'status' => $lead->status, 'sla_due_at' => $lead->sla_due_at->toIso8601String(), 'score' => $lead->score,
            'message' => 'Un asesor le enviará una propuesta en un máximo de 24 horas hábiles (4 h en horario de oficina).'];
    }

    /** SIT-BR-23: contacto → conversación `Formulario` (vinculada a la reserva si el código existe); «Quiero cotizar» además crea el lead. */
    public function contact(array $d): array
    {
        $e = [];
        if (trim((string) ($d['name'] ?? '')) === '') {
            $e['name'] = ['Indique su nombre.'];
        }
        if (! preg_match(EcomCheckout::EMAIL, (string) ($d['email'] ?? ''))) {
            $e['email'] = ['Ingrese un correo válido.'];
        }
        if (mb_strlen(trim((string) ($d['message'] ?? ''))) < 10) {
            $e['message'] = ['Escriba su mensaje (mínimo 10 caracteres).'];
        }
        if (! filter_var($d['consent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $e['consent'] = ['Acepte el tratamiento de sus datos personales (Ley 29733).'];
        }
        if ($e) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'VALIDATION_ERROR', reset($e)[0], [], ['errors' => $e]);
        }
        $reason = (string) ($d['reason'] ?? 'Consulta general');
        $code = mb_strtoupper(trim((string) ($d['reservation'] ?? '')));
        $reservation = $code !== '' ? Reservation::where('code', $code)->first() : null;
        $id = 'web-'.Str::uuid();
        $result = $this->inbound->process('form', ['external_message_id' => $id, 'name' => trim($d['name']), 'email' => mb_strtolower(trim($d['email'])), 'phone' => $d['phone'] ?? null,
            'subject' => mb_substr("{$reason}".($code ? " · {$code}" : ''), 0, 200), 'body' => trim($d['message']), 'reservation_id' => $reservation?->id], $id, $d);
        if (($result['status'] ?? '') !== 'procesado') {
            throw ApiException::conflict('CONTACT_FAILED', 'No pudimos registrar su mensaje. Intente nuevamente.');
        }
        $this->audit->log('Conversación', $result['conversation'], 'crear', null, "Formulario de contacto · {$reason}", system: true);
        $lead = null;
        if (str_contains(mb_strtolower($reason), 'cotizar')) {
            $lead = $this->lead(['destinations' => [(string) ($d['destination'] ?? 'Por definir')], 'undecided_dates' => true, 'adults' => max(1, (int) ($d['adults'] ?? 1)),
                'name' => $d['name'], 'email' => $d['email'], 'phone' => $d['phone'] ?? '-', 'consent' => true, 'comments' => $d['message']], 'contact_form');
        }

        return ['conversation' => $result['conversation'], 'lead' => $lead['code'] ?? null, 'message' => 'Recibimos su mensaje; un asesor le responderá pronto.'];
    }

    /** SIT-BR-24: inicio del chat web → conversación `Web chat` sin asignar + respuesta automática (no cuenta para el SLA). */
    public function chatStart(array $d): array
    {
        $contact = trim((string) ($d['contact'] ?? $d['email'] ?? ''));
        $isEmail = (bool) preg_match(EcomCheckout::EMAIL, $contact);
        $isPhone = strlen(preg_replace('/\D/', '', $contact)) >= 7;
        if (trim((string) ($d['name'] ?? '')) === '' || (! $isEmail && ! $isPhone) || mb_strlen(trim((string) ($d['message'] ?? ''))) < 2) {
            throw ApiException::invalid('contact', 'Indique su nombre, un correo o WhatsApp y su consulta.');
        }
        if (! filter_var($d['consent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ApiException::invalid('consent', 'Acepte el tratamiento de sus datos personales (Ley 29733).');
        }
        $session = 'wc-'.Str::lower(Str::random(32));
        $id = 'web-'.Str::uuid();
        $result = $this->inbound->process('webchat', ['external_message_id' => $id, 'thread_id' => $session, 'name' => trim($d['name']), 'email' => $isEmail ? mb_strtolower($contact) : null,
            'phone' => $isEmail ? null : $contact, 'subject' => 'Chat del sitio web', 'body' => trim($d['message'])], $id, ['session' => $session]);
        $conversation = Conversation::where('channel', 'webchat')->where('external_thread_id', $session)->firstOrFail();
        Message::create(['conversation_id' => $conversation->id, 'channel' => 'webchat', 'direction' => 'system', 'body' => self::AUTO_REPLY,
            'external_message_id' => 'auto-'.$session, 'sent_at' => now()]);
        $this->audit->log('Conversación', $result['conversation'] ?? $conversation->code, 'crear', null, 'Chat web', system: true);

        return ['session' => $session, 'conversation' => $conversation->code, 'messages' => $this->chatMessages($session)];
    }

    public function chatPost(string $session, string $body): array
    {
        $this->conversation($session);
        if (trim($body) === '') {
            throw ApiException::invalid('message', 'Escriba su mensaje.');
        }
        $id = 'web-'.Str::uuid();
        $this->inbound->process('webchat', ['external_message_id' => $id, 'thread_id' => $session, 'body' => mb_substr(trim($body), 0, 2000)], $id);

        return $this->chatMessages($session);
    }

    /** Mensajes del contacto, del asesor y la respuesta automática; nunca notas internas ni eventos. */
    public function chatMessages(string $session): array
    {
        $c = $this->conversation($session);

        return Message::where('conversation_id', $c->id)
            ->where(fn ($q) => $q->whereIn('direction', ['in', 'out'])->orWhere(fn ($s) => $s->where('direction', 'system')->where('external_message_id', 'like', 'auto-%')))
            ->orderBy('sent_at')->orderBy('created_at')->get()
            ->map(fn (Message $m) => ['from' => match ($m->direction) {
                'in' => 'contact', 'out' => 'agent', default => 'system'
            }, 'body' => $m->body, 'at' => $m->sent_at->toIso8601String()])->all();
    }

    /** SIT-BR-25: newsletter con casilla expresa; activo → «ya está suscrito»; en baja → reactiva; doble opt-in por email. */
    public function subscribe(array $d, ?string $ip): array
    {
        $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
        if (! preg_match(EcomCheckout::EMAIL, $email)) {
            throw ApiException::invalid('email', 'Ingrese un correo válido.');
        }
        if (! filter_var($d['consent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ApiException::unprocessable('SIT-BR-25', 'Marque la casilla para aceptar recibir novedades (Ley 29733).');
        }
        $sub = Subscriber::whereRaw('lower(email) = ?', [$email])->first();
        $consent = $sub ? Consent::where('subject_type', 'subscriber')->where('subject_id', $sub->id)->where('channel', 'email')->first() : null;
        if ($sub && $consent?->status === 'otorgado') {
            return ['status' => 'ya_suscrito', 'code' => $sub->code, 'message' => 'Este correo ya está suscrito a nuestras novedades.'];
        }
        $token = Str::random(40);
        $sub = DB::transaction(function () use ($sub, $email, $d, $token) {
            $sub ??= Subscriber::create(['code' => $this->sequences->next('mkt_subscriber'), 'full_name' => trim((string) ($d['name'] ?? '')) ?: Str::before($email, '@'), 'email' => $email,
                'source' => 'Sitio web', 'client_id' => Client::where('email_norm', $email)->value('id'), 'interest_destination' => $d['destination'] ?? null]);
            $sub->forceFill(['opt_in_token_hash' => hash('sha256', $token), 'double_opt_in_at' => null])->save();

            return $sub;
        });
        $reactivated = $consent !== null;
        $this->consents->grant(['subject_type' => 'subscriber', 'subject_id' => $sub->id, 'channel' => 'email', 'source' => 'Newsletter (doble opt-in)',
            'evidence' => 'Newsletter del sitio web · casilla no premarcada · IP '.($ip ?? 'desconocida'), 'policy_version' => SiteCheckout::POLICY_VERSION], null, $ip);
        $this->audit->log('Suscriptor', $sub->code, $reactivated ? 'reactivar' : 'crear', null, "{$email} · doble opt-in pendiente", system: true);
        $url = rtrim((string) config('turismo.frontend_url'), '/')."/sitio/newsletter/confirmar/{$token}";
        $this->notify->push('newsletter.doble_optin', 'email', $email, 'Confirme su suscripción', "Para recibir nuestras novedades confirme aquí: {$url}", ['related_type' => 'mkt_subscriber', 'related_id' => $sub->id]);

        return ['status' => $reactivated ? 'reactivado' : 'pendiente_confirmacion', 'code' => $sub->code,
            'message' => 'Le enviamos un correo para confirmar su suscripción (doble opt-in).', 'confirm_token' => app()->environment('production') ? null : $token];
    }

    public function confirmSubscription(string $token): array
    {
        $sub = Subscriber::where('opt_in_token_hash', hash('sha256', $token))->first() ?? throw ApiException::notFound();
        $sub->forceFill(['double_opt_in_at' => $sub->double_opt_in_at ?? now()])->save();
        $this->audit->log('Suscriptor', $sub->code, 'confirmar_opt_in', null, $sub->email, system: true);

        return ['status' => 'confirmado', 'email' => $sub->email, 'message' => '¡Listo! Su suscripción está confirmada.'];
    }

    public function unsubscribe(string $email): array
    {
        $this->consents->revokeByAddress('email', mb_strtolower(trim($email)), 'Enlace de baja');

        return ['status' => 'baja', 'message' => 'Le dimos de baja de nuestras comunicaciones.'];
    }

    /** POST /public/analytics/events (beacon): lote de eventos GA4 con consentimiento analítico. */
    public function events(array $batch, ?string $clientId): int
    {
        $n = 0;
        foreach (array_slice($batch, 0, 20) as $ev) {
            $name = (string) ($ev['event'] ?? '');
            if (! in_array($name, self::ANALYTICS_EVENTS, true) || ! preg_match('/^[A-Za-z0-9_-]{6,40}$/', (string) ($ev['session_id'] ?? ''))) {
                continue;
            }
            DB::table('channel_web_events')->insert(['tenant_id' => $this->tenant->id(), 'occurred_at' => now(), 'session_id' => $ev['session_id'], 'client_id' => $clientId,
                'event' => $name, 'params' => json_encode(array_slice((array) ($ev['params'] ?? []), 0, 20)), 'locale' => in_array($ev['locale'] ?? 'es', ['es', 'en'], true) ? ($ev['locale'] ?? 'es') : 'es',
                'currency' => in_array($ev['currency'] ?? 'PEN', SitePricing::CURRENCIES, true) ? ($ev['currency'] ?? 'PEN') : 'PEN', 'utm' => isset($ev['utm']) ? json_encode((array) $ev['utm']) : null]);
            $n++;
        }

        return $n;
    }

    /** Últimos 300 eventos (modal «Eventos de analítica (demo)»). */
    public function recentEvents(string $session): array
    {
        return DB::table('channel_web_events')->where('tenant_id', $this->tenant->id())->where('session_id', $session)->orderByDesc('id')->limit(300)->get(['event', 'params', 'occurred_at'])
            ->map(fn ($r) => ['event' => $r->event, 'params' => json_decode($r->params, true), 'at' => $r->occurred_at])->all();
    }

    /** SLA del lead web: 24 h hábiles (se salta fines de semana y feriados). */
    private function slaDue(Carbon $from): Carbon
    {
        $due = $from->copy()->addDay();
        while (! $this->days->isBusinessDay($due->copy()->timezone($this->clock->timezone()))) {
            $due->addDay();
        }

        return $due;
    }

    private function destinationId(string $label): ?string
    {
        return DestinationContent::where('slug', Str::slug($label))->value('destination_id')
            ?? Destination::whereRaw('lower(name) = ?', [mb_strtolower($label)])->orderBy('level')->value('id');
    }

    private function conversation(string $session): Conversation
    {
        if (! preg_match('/^wc-[a-z0-9]{32}$/', $session)) {
            throw ApiException::notFound();
        }

        return Conversation::where('channel', 'webchat')->where('external_thread_id', $session)->first() ?? throw ApiException::notFound();
    }
}
