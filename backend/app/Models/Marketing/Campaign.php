<?php

namespace App\Models\Marketing;

use App\Models\Catalog\ProductPromotion;
use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Campaña (spec 14 §3.6, máquina de estados §4.1). */
class Campaign extends TenantModel
{
    use OptimisticLock;

    public const STATUS_LABELS = ['borrador' => 'Borrador', 'programada' => 'Programada', 'enviando' => 'Enviando', 'enviada' => 'Enviada', 'cancelada' => 'Cancelada'];

    public const CHANNEL_LABELS = ['email' => 'Email', 'whatsapp' => 'WhatsApp'];

    protected $table = 'mkt_campaigns';

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'sent_at' => 'datetime', 'completed_at' => 'datetime', 'audience_snapshot' => 'array'];
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class)->withTrashed();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(ProductPromotion::class, 'promotion_id')->withTrashed();
    }

    public function tests(): HasMany
    {
        return $this->hasMany(CampaignTest::class)->orderBy('sent_at');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function suppressions(): HasMany
    {
        return $this->hasMany(CampaignSuppression::class);
    }

    public function attributions(): HasMany
    {
        return $this->hasMany(Attribution::class);
    }

    /** MKT-BR-04: hay al menos un envío de prueba vigente (no invalidado por una edición). */
    public function hasValidTest(): bool
    {
        return $this->tests()->whereNull('invalidated_at')->exists();
    }
}
