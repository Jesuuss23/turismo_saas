<?php

namespace App\Services\Marketing;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Models\Marketing\Campaign;
use App\Models\Marketing\CampaignRecipient;
use App\Models\Marketing\Consent;
use App\Models\Marketing\ConsentEvent;
use App\Services\AuditLogger;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Consentimiento por titular y canal (spec 14 §4.2, MKT-BR-01/02/11; Ley 29733): otorgar exige origen del catálogo, evidencia y
 * versión de política; la baja se aplica de inmediato, prevalece sobre cualquier segmento y se confirma al titular. Sin borrados:
 * cada cambio queda en `mkt_consent_events`.
 */
class ConsentService
{
    public const SOURCES = ['Formulario web (casilla)', 'Checkout e-commerce (casilla)', 'Newsletter (doble opt-in)', 'Ficha firmada', 'Llamada grabada',
        'Opt-in WhatsApp', 'Portal del cliente', 'Convenio corporativo'];

    public const REVOKE_SOURCES = ['Enlace de baja', 'Respuesta BAJA en WhatsApp', 'Solicitud ARCO', 'Portal del cliente', 'Solicitud verbal', 'Baja desde destinatario de campaña'];

    public const POLICY_VERSIONS = ['PDP-2026.1', 'PDP-2025.2'];

    public const CHANNELS = ['email' => 'Email', 'whatsapp' => 'WhatsApp'];

    public function __construct(
        private readonly ContactDirectory $contacts,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** POST /marketing/consents: alta o nuevo consentimiento expreso tras una baja (upsert por titular y canal). */
    public function grant(array $data, ?User $actor, ?string $ip = null): Consent
    {
        $type = (string) ($data['subject_type'] ?? '');
        $channel = (string) ($data['channel'] ?? '');
        $source = trim((string) ($data['source'] ?? ''));
        $evidence = trim((string) ($data['evidence'] ?? ''));
        $policy = trim((string) ($data['policy_version'] ?? ''));
        if (! isset(ContactDirectory::TYPES[$type])) {
            throw ApiException::invalid('subject_type', 'Tipo de titular no válido.');
        }
        if (! isset(self::CHANNELS[$channel])) {
            throw ApiException::invalid('channel', 'Canal no válido.');
        }
        if (! in_array($source, self::SOURCES, true)) {
            throw ApiException::unprocessable('MKT-BR-11', 'Indique el origen del consentimiento según el catálogo.');
        }
        if ($evidence === '') {
            throw ApiException::unprocessable('MKT-BR-11', 'El consentimiento exige evidencia (IP, ID de formulario, grabación, ficha o wamid).');
        }
        if (! in_array($policy, self::POLICY_VERSIONS, true)) {
            throw ApiException::unprocessable('MKT-BR-11', 'Indique la versión de la política de privacidad informada.');
        }
        if (filter_var($data['prechecked'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ApiException::unprocessable('MKT-BR-11', 'Una casilla pre-marcada no es un consentimiento expreso e inequívoco.');
        }
        $contact = $this->contacts->find($type, (string) ($data['subject_id'] ?? '')) ?? throw ApiException::invalid('subject_id', 'El titular no existe.');
        if (($channel === 'email' ? $contact['email'] : $contact['mobile']) === null) {
            throw ApiException::unprocessable('MKT-BR-06', $channel === 'email' ? 'El contacto no tiene email.' : 'El contacto no tiene un móvil válido para WhatsApp.');
        }

        $consent = DB::transaction(function () use ($type, $contact, $channel, $source, $evidence, $policy, $actor, $ip) {
            $now = now();
            $consent = Consent::where('subject_type', $type)->where('subject_id', $contact['id'])->where('channel', $channel)->lockForUpdate()->first();
            $fields = ['status' => 'otorgado', 'granted_at' => $now, 'source' => $source, 'evidence' => $evidence, 'policy_version' => $policy,
                'revoked_at' => null, 'revoke_source' => null, 'revoke_campaign_id' => null];
            if ($consent) {
                $consent->forceFill($fields)->save();
            } else {
                try {
                    $consent = Consent::create(['code' => $this->sequences->next('mkt_consent'), 'subject_type' => $type, 'subject_id' => $contact['id'], 'channel' => $channel] + $fields);
                } catch (UniqueConstraintViolationException) {
                    throw ApiException::conflict('STALE_VERSION', 'El consentimiento se registró en paralelo; recargue e intente de nuevo.');
                }
            }
            ConsentEvent::create(['consent_id' => $consent->id, 'event' => 'otorgado', 'source' => $source, 'evidence' => $evidence, 'policy_version' => $policy,
                'actor_id' => $actor?->id, 'occurred_at' => $now, 'ip' => $ip]);
            $this->audit->log('Consentimiento', $consent->code, "otorgar_{$channel}", null, "{$contact['code']} · {$contact['name']} · {$source}", actor: $actor, system: $actor === null);

            return $consent;
        });
        $this->contacts->forget();

        return $consent;
    }

    /** Baja (idempotente): enlace público, respuesta BAJA, ARCO o registro manual; `$campaign` = baja desde un envío. */
    public function revoke(Consent $consent, string $source, ?User $actor, ?Campaign $campaign = null, ?string $ip = null, bool $confirm = true): Consent
    {
        if (! in_array($source, self::REVOKE_SOURCES, true)) {
            throw ApiException::invalid('source', 'Indique el origen de la baja según el catálogo.');
        }
        $changed = false;
        $consent = DB::transaction(function () use ($consent, $source, $actor, $campaign, $ip, &$changed) {
            $c = Consent::whereKey($consent->id)->lockForUpdate()->firstOrFail();
            if ($c->status === 'revocado') {
                return $c;
            }
            $now = now();
            $c->forceFill(['status' => 'revocado', 'revoked_at' => $now, 'revoke_source' => $source, 'revoke_campaign_id' => $campaign?->id])->save();
            ConsentEvent::create(['consent_id' => $c->id, 'event' => 'revocado', 'source' => $source, 'campaign_id' => $campaign?->id, 'actor_id' => $actor?->id,
                'occurred_at' => $now, 'ip' => $ip]);
            if ($campaign) {
                CampaignRecipient::where('campaign_id', $campaign->id)->where('subject_type', $c->subject_type)->where('subject_id', $c->subject_id)
                    ->whereNull('unsubscribed_at')->update(['unsubscribed_at' => $now, 'updated_at' => $now]);
            }
            $contact = $this->contacts->find($c->subject_type, $c->subject_id);
            $this->audit->log('Consentimiento', $c->code, "baja_{$c->channel}", 'Otorgado', trim("{$source}".($campaign ? " · {$campaign->code}" : '').' · '.($contact['name'] ?? '')),
                actor: $actor, system: $actor === null);
            $changed = true;

            return $c;
        });
        if ($changed && $confirm) {
            $this->confirm($consent);
        }
        $this->contacts->forget();

        return $consent;
    }

    /** POST /marketing/campaigns/{code}/recipients/{id}/unsubscribe: baja manual desde un destinatario (canal de la campaña). */
    public function unsubscribeRecipient(Campaign $campaign, CampaignRecipient $recipient, User $actor): Consent
    {
        $consent = Consent::where('subject_type', $recipient->subject_type)->where('subject_id', $recipient->subject_id)->where('channel', $campaign->channel)->first()
            ?? throw ApiException::rule('MKT-BR-02', 'El destinatario no tiene consentimiento registrado para este canal.');

        return $this->revoke($consent, 'Baja desde destinatario de campaña', $actor, $campaign);
    }

    /** Baja por dirección (enlace o respuesta BAJA): revoca cada titular con esa dirección. @return list<Consent> */
    public function revokeByAddress(string $channel, string $address, string $source, ?Campaign $campaign = null, ?string $ip = null): array
    {
        $out = [];
        foreach ($this->contacts->findByAddress($channel, $address) as $contact) {
            $consent = Consent::where('subject_type', $contact['type'])->where('subject_id', $contact['id'])->where('channel', $channel)->first();
            if ($consent) {
                $out[] = $this->revoke($consent, $source, null, $campaign, $ip);
            }
        }

        return $out;
    }

    /** MKT-BR-02 / PROC-059: confirmación de baja al titular por el mismo canal. */
    private function confirm(Consent $consent): void
    {
        $contact = $this->contacts->find($consent->subject_type, $consent->subject_id);
        $address = $consent->channel === 'email' ? $contact['email'] ?? null : $contact['mobile'] ?? null;
        if ($address) {
            $this->notify->push('marketing.baja', $consent->channel, $address, 'Confirmamos tu baja',
                'Ya no recibirás comunicaciones comerciales por '.self::CHANNELS[$consent->channel].'. Puedes volver a suscribirte cuando quieras.',
                ['related_type' => 'mkt_consent', 'related_id' => $consent->id]);
        }
    }
}
