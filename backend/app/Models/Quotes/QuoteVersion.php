<?php

namespace App\Models\Quotes;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Versión de cotización (§3 quote_versions): editable en borrador, congelada al enviarse (QTE-BR-19). */
class QuoteVersion extends TenantModel
{
    use OptimisticLock;

    public const DRAFT = 'borrador';

    public const SENT = 'enviada';

    public const APPROVED = 'aprobada';

    public const REJECTED = 'rechazada';

    public const REPLACED = 'reemplazada';

    public const EXPIRED = 'vencida';

    public const STATUS_LABELS = [
        self::DRAFT => 'Borrador', self::SENT => 'Enviada', self::APPROVED => 'Aprobada', self::REJECTED => 'Rechazada',
        self::REPLACED => 'Reemplazada', self::EXPIRED => 'Vencida',
    ];

    public const TOTALS = ['cost_total', 'sale_total', 'discount_total', 'net_total', 'tax_total', 'grand_total', 'profit_total'];

    protected $table = 'quote_versions';

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'issued_on' => 'date',
            'discount_pct' => 'decimal:2',
            'tax_pct' => 'decimal:2',
            'markup_pct' => 'decimal:2',
            'fx_rate' => 'decimal:6',
            'catalog_snapshot' => 'array',
            'policies_snapshot' => 'array',
            'cost_total' => 'decimal:2',
            'sale_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'net_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'profit_total' => 'decimal:2',
            'margin_pct' => 'decimal:2',
            'frozen_at' => 'datetime',
        ];
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('line_no');
    }

    public function internalApproval(): BelongsTo
    {
        return $this->belongsTo(InternalApproval::class, 'internal_approval_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(InternalApproval::class)->orderByDesc('created_at');
    }
}
