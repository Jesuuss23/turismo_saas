<?php

namespace App\Services\Marketing;

use App\Models\Marketing\Campaign;
use App\Models\Marketing\CampaignRecipient;
use App\Models\Marketing\Template;

/**
 * Eventos de proveedor para marketing (spec 14 §8): estados de entrega del ESP y de WhatsApp sobre `mkt_campaign_recipients`, respuesta
 * «BAJA» (revoca el consentimiento de WhatsApp, MKT-BR-02) y aprobación de plantillas de Meta. Idempotentes: cada evento solo completa
 * un estado o una marca de tiempo que aún no existe.
 */
class MarketingWebhooks
{
    /** ESP → estado del destinatario. */
    private const EMAIL_EVENTS = ['delivered' => 'delivered_at', 'opened' => 'opened_at', 'clicked' => 'clicked_at', 'bounced' => null, 'unsubscribed' => 'unsubscribed_at'];

    /** WhatsApp Cloud API → estado del destinatario (lectura = apertura). */
    private const WA_STATUSES = ['delivered' => 'delivered_at', 'read' => 'opened_at', 'failed' => null];

    public function __construct(private readonly ConsentService $consents, private readonly TemplateService $templates) {}

    public function emailEvent(string $messageId, string $event): ?string
    {
        $r = CampaignRecipient::where('provider_message_id', $messageId)->first();
        if (! $r || ! array_key_exists($event, self::EMAIL_EVENTS)) {
            return null;
        }
        $this->mark($r, $event === 'bounced' ? 'rebotado' : null, self::EMAIL_EVENTS[$event]);
        if (in_array($event, ['opened', 'clicked'], true)) {
            $this->mark($r, null, 'delivered_at');
        }
        if ($event === 'unsubscribed') {
            $this->consents->revokeByAddress('email', $r->address, 'Enlace de baja', Campaign::find($r->campaign_id));
        }

        return $event;
    }

    public function whatsappStatus(string $messageId, string $status): ?string
    {
        $r = CampaignRecipient::where('provider_message_id', $messageId)->first();
        if (! $r || ! array_key_exists($status, self::WA_STATUSES)) {
            return null;
        }
        $this->mark($r, $status === 'failed' ? 'fallido' : null, self::WA_STATUSES[$status]);
        if ($status === 'read') {
            $this->mark($r, null, 'delivered_at');
        }

        return $status;
    }

    /** Respuesta entrante: marca `replied_at` en el último envío a ese número; «BAJA» revoca el consentimiento de WhatsApp. */
    public function whatsappReply(string $from, string $body): ?string
    {
        $mobile = ContactDirectory::mobile(str_starts_with($from, '+') ? $from : "+{$from}");
        if (! $mobile) {
            return null;
        }
        $last = CampaignRecipient::where('address', $mobile)->whereNotNull('sent_at')->orderByDesc('sent_at')->first();
        if ($last && ! $last->replied_at) {
            $last->forceFill(['replied_at' => now()])->save();
        }
        if (preg_match('/^\s*BAJA\s*[.!]?\s*$/iu', $body)) {
            $this->consents->revokeByAddress('whatsapp', $mobile, 'Respuesta BAJA en WhatsApp', $last ? Campaign::find($last->campaign_id) : null);

            return 'baja';
        }

        return $last ? 'respuesta' : null;
    }

    public function templateStatus(string $name, string $status): ?Template
    {
        $map = ['APPROVED' => 'aprobada', 'REJECTED' => 'rechazada', 'aprobada' => 'aprobada', 'rechazada' => 'rechazada'];
        $t = Template::where('channel', 'whatsapp')->where(fn ($q) => $q->where('name', $name)->orWhere('provider_template_id', $name))->first();

        return $t && isset($map[$status]) ? $this->templates->review($t, $map[$status], null) : null;
    }

    private function mark(CampaignRecipient $r, ?string $status, ?string $column): void
    {
        $changes = [];
        if ($status && $r->status !== $status) {
            $changes['status'] = $status;
        } elseif ($column === 'delivered_at' && $r->status === 'enviado') {
            $changes['status'] = 'entregado';
        }
        if ($column && $r->{$column} === null) {
            $changes[$column] = now();
        }
        if ($changes) {
            $r->forceFill($changes)->save();
        }
    }
}
