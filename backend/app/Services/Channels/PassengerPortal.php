<?php

namespace App\Services\Channels;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Channel\PaxFeedback;
use App\Models\Channel\PortalAccessLink;
use App\Models\Crm\Client;
use App\Models\Iam\User;
use App\Models\Ops\Incident;
use App\Models\Ops\IncidentLog;
use App\Models\Ops\IncidentPaxAck;
use App\Models\Ops\Order;
use App\Models\Ops\Voucher;
use App\Models\Sys\NotifMessage;
use App\Services\AuditLogger;
use App\Services\Auth\TokenIssuer;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\Ops\PassengerApp;
use App\Services\Ops\VoucherService;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Portal del pasajero (spec 08 §6.7, PROC-052): acceso por enlace (PPX-BR-02), su viaje (PPX-BR-01), alertas con confirmación de lectura,
 * vouchers auditados, mensajes a operaciones y, v2.1, bandeja, comentarios y acompañantes según las reglas del portal.
 */
class PassengerPortal
{
    public const LINK_MINUTES = 30;

    /** PPX-BR-03: tipos de incidencia que el pasajero ve como alerta. */
    public const ALERT_TYPES = ['Transporte', 'Horario'];

    /** v2.1 (§5.6): reglas del portal por tenant (`settings.channels.portal_rules`), activas por defecto. */
    public const RULES = ['R-PAX-MESSAGES' => true, 'R-PAX-FEEDBACK' => true, 'R-COMPANIONS' => true];

    public const COMPANION_DAYS_BEFORE = 2;

    public function __construct(
        private readonly PassengerApp $app,
        private readonly SupportTickets $tickets,
        private readonly VoucherService $vouchers,
        private readonly TokenIssuer $tokens,
        private readonly CrmClock $clock,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly TenantContext $tenant,
    ) {}

    /** PPX-BR-02: email + código deben coincidir con el email del titular; envía el enlace mágico (un uso, 30 min). */
    public function requestLink(string $email, string $code): array
    {
        $email = mb_strtolower(trim($email));
        $reservation = Reservation::where('code', mb_strtoupper(trim($code)))->where('status', '<>', Reservation::CANCELLED)->first();
        $client = $reservation ? Client::find($reservation->client_id) : null;
        $lead = $reservation ? Passenger::where('reservation_id', $reservation->id)->where('is_lead', true)->first() : null;
        $matches = $client && $email !== '' && (mb_strtolower((string) $client->email) === $email || ($lead?->email && mb_strtolower($lead->email) === $email));
        if (! $matches) {
            $this->audit->isolated('Portal pasajero', $code, 'acceso_fallido', null, "Correo o código no coinciden · {$email}");

            throw ApiException::unprocessable('PPX-BR-02', 'Correo o código de reserva no coinciden.');
        }
        $token = Str::random(48);
        PortalAccessLink::create(['reservation_id' => $reservation->id, 'passenger_id' => $lead?->id, 'email' => $email, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::LINK_MINUTES)]);
        $url = rtrim((string) config('turismo.frontend_url'), '/')."/portal-pasajero/acceso?token={$token}&tenant=".$this->tenant->tenant()?->slug;
        $this->notify->push('portal.acceso', 'email', $email, "Su acceso al viaje {$reservation->code}",
            'Ingrese con este enlace (válido '.self::LINK_MINUTES." minutos, un solo uso): {$url}", ['client_id' => $reservation->client_id, 'related_type' => 'reservation', 'related_id' => $reservation->id]);
        $this->audit->isolated('Portal pasajero', $reservation->code, 'enviar_enlace', null, "Enlace de acceso a {$email}");

        // Fuera de producción el enlace se devuelve para poder probar sin correo (precisado en el PR).
        return ['sent_to' => $email] + (app()->isProduction() ? [] : ['dev_link' => $url, 'dev_token' => $token]);
    }

    /** Canjea el token (un uso) → usuario pasajero vinculado a la reserva y sesión Sanctum. */
    public function verify(string $token, ?string $ip): array
    {
        return DB::transaction(function () use ($token, $ip) {
            $link = PortalAccessLink::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $link || $link->used_at || $link->expires_at->isPast()) {
                throw ApiException::unprocessable('PPX-BR-02', 'El enlace no es válido o ya fue usado. Solicite uno nuevo.');
            }
            $link->forceFill(['used_at' => now()])->save();
            $reservation = Reservation::findOrFail($link->reservation_id);
            $user = User::whereRaw('lower(email) = ?', [$link->email])->first();
            if ($user && ! $user->hasRole('passenger')) {
                throw ApiException::rule('PPX-BR-02', 'Este correo ya tiene una cuenta en la agencia: ingrese con su usuario y contraseña.');
            }
            if ($user) {
                $user->forceFill(['reservation_id' => $reservation->id, 'client_id' => $reservation->client_id, 'status' => User::ACTIVE])->save();
            } else {
                $lead = $link->passenger_id ? Passenger::find($link->passenger_id) : null;
                $user = User::create(['code' => 'PAX-U'.Str::upper(Str::random(5)), 'name' => $lead?->full_name ?? Client::find($reservation->client_id)?->name ?? $link->email,
                    'email' => $link->email, 'password' => null, 'status' => User::ACTIVE, 'sede_id' => DB::table('iam_sedes')->where('is_main', true)->value('id'), 'client_id' => $reservation->client_id, 'reservation_id' => $reservation->id]);
                $user->assignRole('passenger');
            }
            $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->save();
            $issued = $this->tokens->issue($user, false, 'portal-pasajero', $ip);
            $this->audit->log('Sesión', $user->email, 'login', '', "Enlace portal pasajero · {$reservation->code}", actor: $user);

            return ['token' => $issued->plainTextToken, 'expires_at' => $issued->accessToken->expires_at, 'reservation' => $reservation->code, 'user' => ['id' => $user->id, 'name' => $user->name]];
        });
    }

    public function reservation(User $user, ?string $code = null): Reservation
    {
        return $this->app->reservationFor($user, $code);
    }

    /** v2.1: secciones visibles según reglas del tenant (CAN-V21-01). */
    public function rules(): array
    {
        $cfg = (array) data_get($this->tenant->tenant()?->settings, 'channels.portal_rules', []);
        $rules = [];
        foreach (self::RULES as $key => $default) {
            $rules[$key] = (bool) ($cfg[$key] ?? $default);
        }

        return $rules + ['companions_days_before' => (int) ($cfg['companions_days_before'] ?? self::COMPANION_DAYS_BEFORE)];
    }

    public function ensureRule(string $rule): void
    {
        if (! ($this->rules()[$rule] ?? false)) {
            throw new ApiException(404, 'SECTION_DISABLED', 'CAN-V21-01', 'Esta sección no está disponible para su viaje.');
        }
    }

    public function trip(Reservation $r, User $user): array
    {
        $today = $this->clock->today()->toDateString();

        return $this->app->trip($r) + [
            'today' => $this->app->services($r)->filter(fn (BookedService $s) => $s->service_date->toDateString() === $today && $s->service_kind !== 'alojamiento')
                ->map(fn ($s) => $this->serviceRow($s))->values()->all(),
            'alerts' => $this->alertQuery($r)->count(), 'unacked_alerts' => $this->alertQuery($r)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ops_incident_pax_acks as a')
                ->whereColumn('a.incident_id', 'ops_incidents.id')->where('a.user_id', $user->id))->count(),
            'rules' => $this->rules(),
        ];
    }

    /** PPX-BR-07: completado si status completado o fecha pasada; Hoy; hotel En curso durante la estadía; Próximo. */
    public function serviceRow(BookedService $s): array
    {
        return $this->app->activity($s) + ['visual' => $this->visual($s->status, $s->service_date->toDateString(), null)];
    }

    public function visual(string $status, string $from, ?string $to): array
    {
        $today = $this->clock->today()->toDateString();
        [$key, $label] = match (true) {
            $status === BookedService::COMPLETED || ($to ?? $from) < $today => ['done', 'Completado'],
            $to !== null && $from <= $today && $today < $to => ['ongoing', 'En curso'],
            $from === $today => ['today', 'Hoy'],
            default => ['next', 'Próximo'],
        };

        return ['key' => $key, 'label' => $label];
    }

    public function hotels(Reservation $r): array
    {
        return $this->app->hotels($r)->map(fn (array $h) => $h + ['visual' => $this->visual('', $h['check_in'], $h['check_out']),
            'nights' => (int) CarbonImmutable::parse($h['check_in'])->diffInDays(CarbonImmutable::parse($h['check_out']))])->values()->all();
    }

    public function transport(Reservation $r): array
    {
        return $this->app->services($r)->filter(fn (BookedService $s) => in_array($s->service_kind, ['traslado', 'tren_entrada'], true))
            ->map(fn ($s) => $this->serviceRow($s))->values()->all();
    }

    public function activities(Reservation $r): array
    {
        return $this->app->services($r)->filter(fn (BookedService $s) => in_array($s->service_kind, ['tour', 'libre'], true))
            ->map(fn ($s) => $this->serviceRow($s))->values()->all();
    }

    /** PPX-BR-05: solo no anulados (última versión); cada apertura se audita. */
    public function voucher(Reservation $r, string $code, User $actor): array
    {
        $v = Voucher::where('reservation_id', $r->id)->where('status', '<>', Voucher::VOIDED)->where('code', $code)->with('provider:id,name')->first() ?? throw ApiException::notFound();
        $this->audit->log('Voucher', $v->code, 'ver_portal_pasajero', null, "v{$v->version} · {$r->code}", actor: $actor);

        return ['code' => $v->code, 'status' => $v->status, 'status_label' => Voucher::STATUS_LABELS[$v->status], 'version' => $v->version, 'qr_token' => $v->qr_token,
            'document' => $this->vouchers->snapshot($v)];
    }

    /** PPX-BR-03: incidencias Transporte/Horario no cerradas ni resueltas, con su texto público. */
    public function alerts(Reservation $r, User $user): array
    {
        $acks = IncidentPaxAck::where('user_id', $user->id)->pluck('acknowledged_at', 'incident_id');

        return $this->alertQuery($r)->orderByDesc('created_at')->get()->map(fn (Incident $i) => [
            'id' => $i->id, 'code' => $i->code, 'type' => $i->type, 'severity' => $i->severity, 'title' => $i->public_title ?: $i->title,
            'message' => $i->public_message ?: 'Operaciones está atendiendo un cambio en su viaje. Le avisaremos cualquier novedad.',
            'at' => $i->created_at->toIso8601String(), 'acknowledged' => isset($acks[$i->id]), 'acknowledged_at' => isset($acks[$i->id]) ? CarbonImmutable::parse($acks[$i->id])->toIso8601String() : null,
        ])->all();
    }

    /** PPX-BR-04: idempotente (UNIQUE); fecha, usuario, bitácora de la incidencia y aviso al responsable. */
    public function ack(Reservation $r, string $incidentId, User $user): array
    {
        $incident = $this->alertQuery($r)->whereKey(preg_match('/^[0-9a-f-]{36}$/i', $incidentId) ? $incidentId : null)->first() ?? throw ApiException::notFound();

        return DB::transaction(function () use ($incident, $user, $r) {
            $existing = IncidentPaxAck::where('incident_id', $incident->id)->where('user_id', $user->id)->first();
            if ($existing) {
                return ['acknowledged_at' => $existing->acknowledged_at->toIso8601String(), 'already' => true];
            }
            $ack = IncidentPaxAck::create(['incident_id' => $incident->id, 'user_id' => $user->id, 'acknowledged_at' => now()]);
            IncidentLog::create(['incident_id' => $incident->id, 'user_id' => $user->id, 'kind' => 'comentario', 'visibility' => 'interna',
                'message' => "El pasajero {$user->name} confirmó la lectura de la alerta en el portal."]);
            $this->audit->log('Incidencia', $incident->code, 'lectura_pasajero', null, "{$user->name} · {$r->code}", actor: $user);
            $to = $incident->assignee_id ? User::find($incident->assignee_id) : (Order::where('reservation_id', $r->id)->first()?->coordinator ?? CatalogRecipients::role('operations')->first());
            if ($to) {
                $this->notify->push('alerta.leida', 'portal', $to, "{$user->name} leyó la alerta {$incident->code}", $incident->public_title ?: $incident->title,
                    ['client_id' => $r->client_id, 'related_type' => 'incident', 'related_id' => $incident->id]);
            }

            return ['acknowledged_at' => $ack->acknowledged_at->toIso8601String(), 'already' => false];
        });
    }

    public function contacts(Reservation $r): array
    {
        $order = Order::where('reservation_id', $r->id)->with('coordinator')->first();
        $next = $this->app->nextActivity($r);

        return [
            'emergency' => ['label' => 'Emergencias 24/7', 'phone' => $this->app->emergencyNumber()],
            'coordinator' => $order?->coordinator ? ['name' => $order->coordinator->name, 'phone' => $order->coordinator->phone, 'email' => $order->coordinator->email] : null,
            'advisor' => $r->owner ? ['name' => $r->owner->name, 'email' => $r->owner->email] : null,
            'next_guide' => $next['guide'] ?? null,
            'hotel' => $this->app->hotels($r)->first(fn ($h) => $h['check_out'] >= $this->clock->today()->toDateString()),
        ];
    }

    /** CAN-V21-02: mensajes de la reserva enviados/entregados/leídos/fallidos (no programados ni cancelados). */
    public function inbox(Reservation $r, User $user, ?string $channel = null): array
    {
        return $this->inboxQuery($r, $user)->when($channel, fn ($q, $c) => $q->where('channel', $c))->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (NotifMessage $n) => ['id' => $n->id, 'event' => $n->event, 'channel' => $n->channel, 'subject' => $n->subject, 'body' => $n->body,
                'status' => $n->read_at ? 'leido' : ($n->status === 'fallido' ? 'fallido' : 'entregado'), 'at' => $n->created_at->toIso8601String(), 'read' => (bool) $n->read_at])->all();
    }

    public function markRead(Reservation $r, User $user, ?string $id = null): int
    {
        return $this->inboxQuery($r, $user)->whereNull('read_at')->when($id, fn ($q) => $q->whereKey($id))->update(['read_at' => now()]);
    }

    /** CAN-V21-03/04: texto 5–1000, calificación 1–5 o nula, servicio de la reserva; reclamo/incidencia abre conversación (ticket). */
    public function feedback(Reservation $r, array $data, User $user): PaxFeedback
    {
        $type = (string) ($data['type'] ?? 'comentario');
        if (! isset(PaxFeedback::TYPES[$type])) {
            throw ApiException::invalid('type', 'Elija el tipo de comentario.');
        }
        $text = trim((string) ($data['text'] ?? ''));
        if (mb_strlen($text) < 5 || mb_strlen($text) > 1000) {
            throw ApiException::invalid('text', 'El comentario debe tener entre 5 y 1000 caracteres.');
        }
        $rating = $data['rating'] ?? null;
        if ($rating !== null && ($rating < 1 || $rating > 5 || (int) $rating != $rating)) {
            throw ApiException::invalid('rating', 'La calificación va de 1 a 5.');
        }
        $service = null;
        if (! empty($data['service_id'])) {
            $service = BookedService::where('reservation_id', $r->id)->whereKey($data['service_id'])->first() ?? throw ApiException::invalid('service_id', 'El servicio no pertenece a su reserva.');
        }

        return DB::transaction(function () use ($r, $user, $type, $text, $rating, $service, $data) {
            $ticket = in_array($type, ['reclamo', 'incidencia'], true)
                ? $this->tickets->forPassenger($r, ['topic' => 'Otro', 'message' => PaxFeedback::TYPES[$type].($service ? " · {$service->name}" : '').": {$text}"], $user) : null;
            $feedback = PaxFeedback::create(['code' => $this->sequences->next('pax_feedback'), 'reservation_id' => $r->id, 'reservation_service_id' => $service?->id,
                'user_id' => $user->id, 'type' => $type, 'rating' => $rating !== null ? (int) $rating : null, 'text' => $text, 'channel' => 'portal_pasajero',
                'contact_email' => $data['contact_email'] ?? $user->email, 'contact_phone' => $data['contact_phone'] ?? null, 'status' => 'recibido', 'ticket_id' => $ticket?->id]);
            $this->audit->log('Comentario pasajero', $feedback->code, 'crear', null, PaxFeedback::TYPES[$type].($rating ? " · {$rating}/5" : '')." · {$r->code}", actor: $user);

            return $feedback;
        });
    }

    public function feedbackList(Reservation $r, User $user): array
    {
        return PaxFeedback::where('reservation_id', $r->id)->where('user_id', $user->id)->with('service:id,name')->orderByDesc('created_at')->get()->map(fn (PaxFeedback $f) => [
            'id' => $f->id, 'code' => $f->code, 'type' => $f->type, 'type_label' => PaxFeedback::TYPES[$f->type], 'rating' => $f->rating, 'text' => $f->text,
            'service' => $f->service?->name, 'status' => $f->status, 'status_label' => PaxFeedback::STATUS_LABELS[$f->status], 'response' => $f->response,
            'at' => $f->created_at->toIso8601String(),
            'complaints_book' => $f->type === 'reclamo' ? 'Puede registrar su reclamo en el Libro de Reclamaciones (Ley 29571).' : null,
        ])->all();
    }

    /** CAN-V21-05: editable hasta travel_from − n días; titular solo documento/nacimiento; el tipo ADT/CHD/INF no puede cambiar. */
    public function companions(Reservation $r): array
    {
        $deadline = $r->travel_from->copy()->subDays($this->rules()['companions_days_before']);

        return ['editable' => $this->clock->today()->lte($deadline), 'deadline' => $deadline->toDateString(), 'lock_version' => $r->lock_version,
            'passengers' => Passenger::where('reservation_id', $r->id)->orderBy('seq')->get()->map(fn (Passenger $p) => [
                'id' => $p->id, 'seq' => $p->seq, 'name' => $p->full_name, 'doc_type' => $p->doc_type, 'doc_number' => $p->doc_number, 'birth_date' => $p->birth_date?->toDateString(),
                'type' => $p->pax_type, 'lead' => (bool) $p->is_lead, 'nationality' => $p->nationality, 'placeholder' => (bool) $p->is_placeholder,
            ])->all()];
    }

    public function updateCompanions(Reservation $r, array $rows, User $user): array
    {
        $state = $this->companions($r);
        if (! $state['editable']) {
            throw ApiException::rule('CAN-V21-05', 'Los datos de acompañantes se editan hasta el '.$state['deadline'].'. Contacte a su asesor.');
        }
        $passengers = Passenger::where('reservation_id', $r->id)->get()->keyBy('id');
        DB::transaction(function () use ($rows, $passengers, $r, $user) {
            foreach ($rows as $row) {
                $p = $passengers[$row['id'] ?? ''] ?? throw ApiException::invalid('passengers', 'Pasajero no encontrado en su reserva.');
                $changes = array_intersect_key($row, array_flip($p->is_lead ? ['doc_type', 'doc_number', 'birth_date'] : ['name', 'doc_type', 'doc_number', 'birth_date', 'nationality']));
                if (isset($changes['name'])) {
                    $changes['full_name'] = trim((string) $changes['name']) ?: throw ApiException::invalid('name', 'El nombre es obligatorio.');
                    unset($changes['name']);
                }
                if (! empty($changes['birth_date'])) {
                    $age = (int) CarbonImmutable::parse($changes['birth_date'])->diffInYears($r->travel_from);
                    $type = $age < 2 ? 'INF' : ($age < 12 ? 'CHD' : 'ADT');
                    if ($type !== $p->pax_type) {
                        throw ApiException::unprocessable('CAN-V21-05', "Con esa fecha de nacimiento {$p->full_name} viajaría como {$type} (reservado como {$p->pax_type}): el cambio modifica el precio; solicítelo a su asesor.");
                    }
                    $changes['age'] = $age;
                }
                if ($changes === []) {
                    continue;
                }
                $before = $p->only(array_keys($changes));
                $p->forceFill($changes + ['is_placeholder' => false])->save();
                $this->audit->log('Reserva', $r->code, 'editar_acompanante', json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($changes, JSON_UNESCAPED_UNICODE), actor: $user);
            }
        });

        return $this->companions($r->fresh());
    }

    private function alertQuery(Reservation $r): Builder
    {
        return Incident::where('reservation_id', $r->id)->whereIn('type', self::ALERT_TYPES)->whereIn('status', Incident::ACTIVE);
    }

    private function inboxQuery(Reservation $r, User $user)
    {
        $users = $user->isExternal() ? [$user->id] : User::where('reservation_id', $r->id)->pluck('id')->all();

        return NotifMessage::whereIn('user_id', $users)->whereIn('status', ['enviado', 'fallido', 'pendiente'])
            ->where(fn ($q) => $q->where('data->reservation_id', $r->id)->orWhere(fn ($q) => $q->where('related_type', 'reservation')->where('related_id', $r->id)));
    }
}
