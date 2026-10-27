<?php

namespace App\Models\Marketing;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Destinatario de una campaña con su estado de entrega (spec 14 §3.8). */
class CampaignRecipient extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    public const STATUS_LABELS = ['pendiente' => 'Pendiente', 'enviado' => 'Enviado', 'entregado' => 'Entregado', 'rebotado' => 'Rebotado', 'fallido' => 'Fallido', 'suprimido' => 'Suprimido al enviar'];

    protected $table = 'mkt_campaign_recipients';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'delivered_at' => 'datetime', 'opened_at' => 'datetime', 'clicked_at' => 'datetime', 'replied_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
