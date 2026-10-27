<?php

namespace App\Services\Marketing;

use App\Exceptions\ApiException;
use App\Jobs\SendCampaignBatch;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductPromotion;
use App\Models\Iam\User;
use App\Models\Marketing\Campaign;
use App\Models\Marketing\CampaignRecipient;
use App\Models\Marketing\CampaignSuppression;
use App\Models\Marketing\CampaignTest;
use App\Models\Marketing\Segment;
use App\Models\Marketing\Template;
use App\Services\AuditLogger;
use App\Services\Catalog\ProductParts;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Campañas (spec 14 §4.1): borrador → programada → enviando → enviada, o cancelada. Reglas MKT-BR-02/03/04/05/07/08/09/14/18.
 * El envío congela la audiencia (snapshot + supresiones con motivo) y encola lotes que re-evalúan el consentimiento justo antes
 * de enviar por los adaptadores de Notify (PROC-059).
 */
class CampaignService
{
    public const BATCH_SIZE = 200;

    public const MIN_LEAD_MINUTES = 15;

    public const WHATSAPP_HOURS = [8, 20];

    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly ContactDirectory $contacts,
        private readonly MessageRenderer $renderer,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly CrmClock $clock,
        private readonly ProductParts $parts,
    ) {}

    public function create(array $data, User $actor): Campaign
    {
        $fields = $this->fields($data, null);

        return DB::transaction(function () use ($fields, $data, $actor) {
            $code = $this->sequences->next('mkt_campaign');
            $promo = $this->promotion($data, $actor);
            $c = Campaign::create($fields + ['code' => $code, 'owner_id' => $actor->id, 'status' => 'borrador',
                'utm' => "utm_source={$fields['channel']}&utm_medium=campaign&utm_campaign=".mb_strtolower($code),
                'promotion_id' => $promo?->id, 'promo_code' => $promo?->promo_code]);
            if ($promo && $promo->campaign_id === null && ! empty($data['new_promo'])) {
                $promo->forceFill(['campaign_id' => $c->id])->save();
            }
            $this->audit->log('Campaña', $c->code, 'crear', null, "{$c->name} · ".Campaign::CHANNEL_LABELS[$c->channel]." · {$c->segment->code}", actor: $actor);

            return $c;
        });
    }

    /** PUT: solo en borrador (MKT-BR-08); editar el contenido invalida las pruebas (MKT-BR-04). */
    public function update(Campaign $campaign, array $data, User $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $data, $actor) {
            $c = $this->lock($campaign);
            if ($c->status !== 'borrador') {
                throw ApiException::rule('MKT-BR-08', $c->status === 'programada' ? 'La campaña está programada: desprográmela para editarla.' : 'Solo se editan campañas en borrador.');
            }
            $fields = $this->fields($data + ['channel' => $c->channel], $c);
            $promo = array_key_exists('promotion_id', $data) || ! empty($data['new_promo']) ? $this->promotion($data, $actor) : $c->promotion;
            $before = $c->only(['name', 'subject', 'body', 'template_id', 'segment_id', 'promotion_id']);
            $c->forceFill($fields + ['promotion_id' => $promo?->id, 'promo_code' => $promo?->promo_code])->save();
            if ($promo && ! empty($data['new_promo'])) {
                $promo->forceFill(['campaign_id' => $c->id])->save();
            }
            $contentChanged = $before['subject'] !== $c->subject || $before['body'] !== $c->body || $before['template_id'] !== $c->template_id
                || $before['promotion_id'] !== $c->promotion_id;
            $invalidated = $contentChanged ? CampaignTest::where('campaign_id', $c->id)->whereNull('invalidated_at')->update(['invalidated_at' => now(), 'updated_at' => now()]) : 0;
            $this->audit->log('Campaña', $c->code, 'editar', null, $c->name.($invalidated ? " · {$invalidated} prueba(s) invalidada(s)" : ''), actor: $actor);

            return $c->refresh();
        });
    }

    /** POST test-send: a un usuario interno activo (MKT-BR-18), nunca con plantilla WA no aprobada (MKT-BR-03). */
    public function testSend(Campaign $campaign, array $data, User $actor): CampaignTest
    {
        $c = $campaign->fresh(['template', 'segment']);
        if (! in_array($c->status, ['borrador', 'programada'], true)) {
            throw ApiException::rule('MKT-BR-08', 'Solo se prueban campañas en borrador o programadas.');
        }
        $this->assertTemplate($c);
        $to = User::where('id', $data['to_user_id'] ?? null)->orWhere('code', $data['to_user_id'] ?? null)->first();
        if (! $to || $to->status !== User::ACTIVE || $to->isExternal()) {
            throw ApiException::unprocessable('MKT-BR-18', 'Las pruebas solo se envían a usuarios internos activos del tenant.');
        }
        $address = $c->channel === 'email' ? $to->email : ContactDirectory::mobile($to->phone);
        if (! $address) {
            throw ApiException::unprocessable('MKT-BR-18', "{$to->name} no tiene un móvil registrado para recibir la prueba por WhatsApp.");
        }
        $sample = $this->sample($c, $data['sample'] ?? null);
        $subject = $this->renderer->render($c->subject, $sample, $c->channel, $c->promo_code, $c->id);
        $body = $this->renderer->render($c->body, $sample, $c->channel, $c->promo_code, $c->id);
        $test = CampaignTest::create(['campaign_id' => $c->id, 'to_user_id' => $to->id, 'to_address' => $address, 'sample_subject_type' => $sample['id'] ? $sample['type'] : null,
            'sample_subject_id' => $sample['id'] ?: null, 'sent_at' => now()]);
        $this->notify->push('marketing.prueba', $c->channel, $address, '[PRUEBA] '.($subject ?: $c->name), $body, ['related_type' => 'mkt_campaign', 'related_id' => $c->id, 'test' => true]);
        $this->audit->log('Campaña', $c->code, 'envio_prueba', null, "{$to->name} · {$address} · ejemplo {$sample['name']}", actor: $actor);

        return $test;
    }

    public function schedule(Campaign $campaign, mixed $at, User $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $at, $actor) {
            $c = $this->lock($campaign);
            if ($c->status !== 'borrador') {
                throw ApiException::rule('MKT-BR-08', 'Solo se programa una campaña en borrador (estado actual: '.Campaign::STATUS_LABELS[$c->status].').');
            }
            $when = $this->assertSchedule($c, $at);
            $this->assertReady($c, $when);
            $c->forceFill(['status' => 'programada', 'scheduled_at' => $when])->save();
            $local = $when->copy()->setTimezone($this->clock->timezone())->format('d/m/Y H:i');
            $this->audit->log('Campaña', $c->code, 'programar', 'Borrador', "Programada {$local}", actor: $actor);
            $this->notify->push('marketing.programada', 'portal', $c->owner, "Campaña {$c->code} programada", "{$c->name} · {$local}", ['related_type' => 'mkt_campaign', 'related_id' => $c->id]);

            return $c;
        });
    }

    public function unschedule(Campaign $campaign, User $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $actor) {
            $c = $this->lock($campaign);
            if ($c->status !== 'programada') {
                throw ApiException::rule('MKT-BR-08', 'Solo se desprograma una campaña programada.');
            }
            $c->forceFill(['status' => 'borrador', 'scheduled_at' => null])->save();
            $this->audit->log('Campaña', $c->code, 'desprogramar', 'Programada', 'Borrador', actor: $actor);

            return $c;
        });
    }

    /** Enviar ahora (MK) o al llegar `scheduled_at` (scheduler, `$actor` null): congela la audiencia y encola los lotes. */
    public function send(Campaign $campaign, ?User $actor): Campaign
    {
        $c = DB::transaction(function () use ($campaign, $actor) {
            $c = $this->lock($campaign);
            if (! in_array($c->status, ['borrador', 'programada'], true)) {
                throw ApiException::rule('MKT-BR-08', 'La campaña ya está '.mb_strtolower(Campaign::STATUS_LABELS[$c->status]).': no se puede enviar de nuevo.');
            }
            $now = now();
            $audience = $this->assertReady($c, $now);
            $snapshot = ['matched' => $audience['matched'], 'eligible' => $audience['eligible']->count()] + $audience['counts'] + ['at' => $now->toIso8601String()];
            foreach ($audience['eligible'] as $r) {
                CampaignRecipient::create(['campaign_id' => $c->id, 'subject_type' => $r['type'], 'subject_id' => $r['id'], 'name' => $r['name'], 'address' => $r['address'], 'status' => 'pendiente']);
            }
            foreach ($audience['suppressed'] as $s) {
                CampaignSuppression::create(['campaign_id' => $c->id, 'subject_type' => $s['type'], 'subject_id' => $s['id'], 'name' => $s['name'], 'reason' => $s['reason']]);
            }
            $before = Campaign::STATUS_LABELS[$c->status];
            $c->forceFill(['status' => 'enviando', 'sent_at' => $now, 'audience_snapshot' => $snapshot])->save();
            $this->audit->log('Campaña', $c->code, 'enviar', $before, "Enviando a {$snapshot['eligible']} destinatario(s) · {$audience['suppressed']->count()} suprimido(s)",
                actor: $actor, system: $actor === null);

            return $c;
        });
        dispatch(new SendCampaignBatch($c->id))->afterCommit();

        return $c->refresh();
    }

    /**
     * Lote de envío (job SendCampaignBatch o «Procesar envío»): re-evalúa baja y consentimiento por destinatario (MKT-BR-01/02),
     * envía por el adapter del canal y, sin pendientes, cierra la campaña como enviada.
     *
     * @return array{sent: int, suppressed: int, pending: int}
     */
    public function processBatch(Campaign $campaign, int $size = self::BATCH_SIZE): array
    {
        $c = $campaign->fresh(['template']);
        if ($c->status !== 'enviando') {
            return ['sent' => 0, 'suppressed' => 0, 'pending' => 0];
        }
        $sent = $suppressed = 0;
        $pending = CampaignRecipient::where('campaign_id', $c->id)->where('status', 'pendiente')->orderBy('name')->limit($size)->get();
        $states = $this->contacts->consentStates();
        foreach ($pending as $r) {
            $state = $states["{$r->subject_type}:{$r->subject_id}"][$c->channel] ?? null;
            if ($state !== 'otorgado') {
                $r->forceFill(['status' => 'suprimido', 'error' => $state === 'revocado' ? 'Baja registrada antes del envío' : 'Sin consentimiento al enviar'])->save();
                $suppressed++;

                continue;
            }
            $contact = $this->contacts->find($r->subject_type, $r->subject_id) ?? ['type' => $r->subject_type, 'id' => $r->subject_id, 'name' => $r->name,
                'destination' => null, 'owner' => null, 'next_trip' => null];
            $messageId = ($c->channel === 'whatsapp' ? 'wamid.' : 'esp-').Str::lower(Str::random(24));
            $this->notify->push('marketing.campana', $c->channel, $r->address, $this->renderer->render($c->subject, $contact, $c->channel, $c->promo_code, $c->id) ?: $c->name,
                $this->renderer->render($c->body, $contact, $c->channel, $c->promo_code, $c->id),
                ['related_type' => 'mkt_campaign', 'related_id' => $c->id, 'provider_message_id' => $messageId, 'client_id' => $r->subject_type === 'client' ? $r->subject_id : null]);
            $r->forceFill(['status' => 'enviado', 'sent_at' => now(), 'provider_message_id' => $messageId, 'attempts' => $r->attempts + 1])->save();
            $sent++;
        }
        $left = CampaignRecipient::where('campaign_id', $c->id)->where('status', 'pendiente')->count();
        if ($left === 0) {
            $done = Campaign::whereKey($c->id)->where('status', 'enviando')->update(['status' => 'enviada', 'completed_at' => now(), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            if ($done) {
                $total = CampaignRecipient::where('campaign_id', $c->id)->whereNotNull('sent_at')->count();
                $this->audit->log('Campaña', $c->code, 'envio_completado', 'Enviando', "Enviada · {$total} mensaje(s)", system: true);
                $this->notify->push('marketing.enviada', 'portal', $c->owner, "Campaña {$c->code} enviada", "{$c->name} · {$total} mensaje(s)", ['related_type' => 'mkt_campaign', 'related_id' => $c->id]);
            }
        }

        return ['sent' => $sent, 'suppressed' => $suppressed, 'pending' => $left];
    }

    public function cancel(Campaign $campaign, string $reason, User $actor): Campaign
    {
        if (trim($reason) === '') {
            throw ApiException::invalid('reason', 'Indique el motivo de la cancelación.');
        }

        return DB::transaction(function () use ($campaign, $reason, $actor) {
            $c = $this->lock($campaign);
            if (! in_array($c->status, ['borrador', 'programada'], true)) {
                throw ApiException::rule('MKT-BR-08', 'Solo se cancela una campaña en borrador o programada.');
            }
            $before = Campaign::STATUS_LABELS[$c->status];
            $c->forceFill(['status' => 'cancelada', 'cancel_reason' => trim($reason), 'scheduled_at' => null])->save();
            $this->audit->log('Campaña', $c->code, 'cancelar', $before, 'Cancelada · '.trim($reason), actor: $actor);

            return $c;
        });
    }

    /** Copia en borrador sin pruebas ni destinatarios. */
    public function duplicate(Campaign $campaign, User $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $actor) {
            $code = $this->sequences->next('mkt_campaign');
            $c = Campaign::create(['code' => $code, 'name' => mb_substr("Copia de {$campaign->name}", 0, 160), 'channel' => $campaign->channel,
                'segment_id' => $campaign->segment_id, 'template_id' => $campaign->template_id, 'subject' => $campaign->subject, 'body' => $campaign->body,
                'promotion_id' => $campaign->promotion_id, 'promo_code' => $campaign->promo_code, 'owner_id' => $actor->id, 'status' => 'borrador',
                'utm' => "utm_source={$campaign->channel}&utm_medium=campaign&utm_campaign=".mb_strtolower($code)]);
            $this->audit->log('Campaña', $c->code, 'duplicar', $campaign->code, $c->name, actor: $actor);

            return $c;
        });
    }

    /** Audiencia estimada (o histórica con `at`) de la campaña. */
    public function audience(Campaign $c, ?Carbon $at = null): array
    {
        $segment = $c->segment;

        return $this->audience->resolve($c->channel, $segment->match, $segment->rules ?? [], $at, $c->id);
    }

    /** MKT-BR-03/04/07/09: validaciones comunes a programar y enviar. */
    private function assertReady(Campaign $c, Carbon $at): array
    {
        $this->assertTemplate($c);
        if (! $c->hasValidTest()) {
            throw ApiException::unprocessable('MKT-BR-04', 'Envíe una prueba a un usuario interno antes de programar o enviar (editar el contenido invalida las pruebas).');
        }
        if ($c->promotion_id) {
            $p = $c->promotion;
            $day = $at->copy()->setTimezone($this->clock->timezone())->toDateString();
            if (! $p || $p->status !== 'activa' || $p->date_from->toDateString() > $day || $p->date_to->toDateString() < $day) {
                throw ApiException::unprocessable('MKT-BR-09', "El código {$c->promo_code} no está activo y vigente a la fecha de envío.");
            }
        }
        $audience = $this->audience($c, $at->isFuture() ? null : $at);
        if ($audience['eligible']->isEmpty()) {
            throw ApiException::unprocessable('MKT-BR-07', 'La campaña no tiene destinatarios elegibles: revise el segmento y los consentimientos.', ['audience' => $audience['counts']]);
        }

        return $audience;
    }

    private function assertTemplate(Campaign $c): void
    {
        if ($c->channel === 'whatsapp' && $c->template->status !== 'aprobada') {
            throw ApiException::unprocessable('MKT-BR-03', "La plantilla de WhatsApp {$c->template->name} no está aprobada por Meta (".mb_strtolower(Template::STATUS_LABELS[$c->template->status]).').');
        }
    }

    /** MKT-BR-05: ≥ 15 min en el futuro; WhatsApp comercial solo entre 08:00 y 20:00 hora del tenant. */
    private function assertSchedule(Campaign $c, mixed $at): Carbon
    {
        try {
            $when = Carbon::parse((string) $at, $this->clock->timezone())->utc();
        } catch (\Throwable) {
            throw ApiException::invalid('scheduled_at', 'Indique la fecha y hora de envío.');
        }
        if (! $at || $when->lessThan(now()->addMinutes(self::MIN_LEAD_MINUTES)->subSeconds(30))) {
            throw ApiException::unprocessable('MKT-BR-05', 'Programe el envío con al menos '.self::MIN_LEAD_MINUTES.' minutos de anticipación.');
        }
        $local = $when->copy()->setTimezone($this->clock->timezone());
        $minutes = $local->hour * 60 + $local->minute;
        if ($c->channel === 'whatsapp' && ($minutes < self::WHATSAPP_HOURS[0] * 60 || $minutes > self::WHATSAPP_HOURS[1] * 60)) {
            throw ApiException::unprocessable('MKT-BR-05', 'WhatsApp comercial solo se envía entre las 08:00 y las 20:00 (Ley 29571, art. 58).');
        }

        return $when;
    }

    /** Campos validados del editor; WhatsApp copia el texto de la plantilla aprobada (no editable, MKT-BR-03). */
    private function fields(array $data, ?Campaign $current): array
    {
        $name = trim((string) ($data['name'] ?? $current?->name ?? ''));
        if ($name === '') {
            throw ApiException::invalid('name', 'Indique el nombre de la campaña.');
        }
        $channel = (string) ($current?->channel ?? $data['channel'] ?? '');
        if (! isset(Campaign::CHANNEL_LABELS[$channel])) {
            throw ApiException::invalid('channel', 'Elija el canal: email o WhatsApp.');
        }
        $segmentRef = $data['segment_id'] ?? $data['segment'] ?? $current?->segment_id;
        $segment = $segmentRef ? Segment::where(fn ($q) => Str::isUuid((string) $segmentRef) ? $q->whereKey($segmentRef) : $q->where('code', $segmentRef))->first() : null;
        if (! $segment) {
            throw ApiException::invalid('segment_id', 'Elija un segmento.');
        }
        $templateRef = $data['template_id'] ?? $data['template'] ?? $current?->template_id;
        $template = $templateRef ? Template::where(fn ($q) => Str::isUuid((string) $templateRef) ? $q->whereKey($templateRef) : $q->where('code', $templateRef))->first() : null;
        if (! $template || $template->channel !== $channel) {
            throw ApiException::invalid('template_id', 'Elija una plantilla del canal de la campaña.');
        }
        if ($channel === 'whatsapp') {
            if ($template->status !== 'aprobada') {
                throw ApiException::unprocessable('MKT-BR-03', "La plantilla {$template->name} no está aprobada por Meta.");
            }

            return ['name' => $name, 'channel' => $channel, 'segment_id' => $segment->id, 'template_id' => $template->id, 'subject' => null, 'body' => $template->body];
        }
        $templateChanged = $current && $current->template_id !== $template->id;
        $subject = trim((string) (array_key_exists('subject', $data) && ! $templateChanged ? $data['subject'] : ($templateChanged ? $template->subject : ($current?->subject ?? $template->subject))));
        $body = (string) (array_key_exists('body', $data) && ! $templateChanged ? $data['body'] : ($templateChanged ? $template->body : ($current?->body ?? $template->body)));
        if ($subject === '') {
            throw ApiException::invalid('subject', 'Indique el asunto del correo.');
        }
        if (! str_contains($body, '{{enlace_baja}}')) {
            throw ApiException::unprocessable('MKT-BR-02', 'El cuerpo del correo debe incluir la variable {{enlace_baja}}.');
        }

        return ['name' => $name, 'channel' => $channel, 'segment_id' => $segment->id, 'template_id' => $template->id, 'subject' => mb_substr($subject, 0, 200), 'body' => $body];
    }

    /** Código existente (`promotion_id`) o nuevo inline (`new_promo`), MKT-BR-09: 4–20 alfanuméricos en mayúsculas, único. */
    private function promotion(array $data, User $actor): ?ProductPromotion
    {
        if (! empty($data['new_promo']) && is_array($data['new_promo'])) {
            $p = $data['new_promo'];
            $code = mb_strtoupper(trim((string) ($p['code'] ?? '')));
            if (! preg_match('/^[A-Z0-9]{4,20}$/', $code)) {
                throw ApiException::unprocessable('MKT-BR-09', 'El código promocional debe tener de 4 a 20 letras o números, en mayúsculas.');
            }
            if (ProductPromotion::withTrashed()->where('promo_code', $code)->exists()) {
                throw ApiException::unprocessable('MKT-BR-09', "El código {$code} ya existe en el tenant.");
            }
            $productRef = (string) ($p['product_id'] ?? '');
            $product = Product::where(fn ($q) => Str::isUuid($productRef) ? $q->whereKey($productRef) : $q->where('ref', $productRef))->first()
                ?? throw ApiException::invalid('new_promo.product_id', 'Elija el producto del código.');
            $promo = $this->parts->addPromotion($product, ['name' => 'Código de campaña '.$code, 'kind' => 'Código promocional', 'value' => $p['value'] ?? null,
                'channel' => 'Todos', 'promo_code' => $code, 'date_from' => $p['date_from'] ?? $this->clock->today()->toDateString(), 'date_to' => $p['date_to'] ?? null], $actor);
            $this->audit->log('Promoción', $promo->ref, 'crear', null, "{$code} · {$promo->value} % · desde campaña", actor: $actor);

            return $promo;
        }
        $ref = $data['promotion_id'] ?? null;
        if ($ref === null || $ref === '') {
            return null;
        }
        $promo = ProductPromotion::where(fn ($q) => Str::isUuid((string) $ref) ? $q->whereKey($ref) : $q->where('promo_code', mb_strtoupper((string) $ref)))->whereNotNull('promo_code')->first();

        return $promo ?? throw ApiException::invalid('promotion_id', 'El código promocional no existe.');
    }

    /** Contacto de ejemplo para variables: el indicado, o el primer elegible de la audiencia, o cualquiera del segmento. */
    private function sample(Campaign $c, mixed $ref): array
    {
        if (is_array($ref) && isset($ref['type'], $ref['id']) && ($found = $this->contacts->find((string) $ref['type'], (string) $ref['id']))) {
            return $found;
        }
        $audience = $this->audience($c);

        return $audience['eligible']->first() ?? $audience['suppressed']->first() ?? ['type' => 'client', 'id' => '', 'name' => 'Cliente de ejemplo', 'destination' => 'Cusco',
            'owner' => null, 'next_trip' => null];
    }

    private function lock(Campaign $campaign): Campaign
    {
        $c = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
        if (request()->hasHeader('If-Match')) {
            $c->assertIfMatch(request());
        }

        return $c;
    }
}
