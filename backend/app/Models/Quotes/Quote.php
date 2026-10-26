<?php

namespace App\Models\Quotes;

use App\Models\Crm\Client;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Cabecera de cotización (spec 03 §3) y máquina de estados §4.1. */
class Quote extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const DRAFT = 'borrador';

    public const SENT = 'enviada';

    public const VIEWED = 'vista';

    public const NEGOTIATION = 'negociacion';

    public const APPROVED = 'aprobada';

    public const REJECTED = 'rechazada';

    public const EXPIRED = 'vencida';

    public const CONVERTED = 'convertida';

    public const CANCELLED = 'cancelada';

    /** Estados "en seguimiento": enviada al cliente y esperando respuesta. */
    public const FOLLOW_UP = [self::SENT, self::VIEWED, self::NEGOTIATION];

    /** Estados abiertos que vence el job JOB-02. */
    public const EXPIRABLE = [self::DRAFT, self::SENT, self::VIEWED, self::NEGOTIATION];

    public const STATUS_LABELS = [
        self::DRAFT => 'Borrador', self::SENT => 'Enviada', self::VIEWED => 'Vista', self::NEGOTIATION => 'Negociación',
        self::APPROVED => 'Aprobada', self::REJECTED => 'Rechazada', self::EXPIRED => 'Vencida', self::CONVERTED => 'Convertida',
        self::CANCELLED => 'Cancelada',
    ];

    /** Flujo principal mostrado en el 360. */
    public const FLOW = [self::DRAFT, self::SENT, self::VIEWED, self::NEGOTIATION, self::APPROVED, self::CONVERTED];

    protected $table = 'quote_quotes';

    protected function casts(): array
    {
        return [
            'travel_from' => 'date',
            'travel_to' => 'date',
            'valid_until' => 'date',
            'fx_rate' => 'decimal:6',
            'pax_adt' => 'integer',
            'pax_chd' => 'integer',
            'pax_inf' => 'integer',
            'current_version_no' => 'integer',
            'approval_version_no' => 'integer',
            'viewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    /**
     * QTE-BR-16: vencida por el job, o enviada/vista/negociación con validez pasada aunque el job no haya corrido.
     */
    public function isExpired(CarbonInterface $today): bool
    {
        return $this->status === self::EXPIRED
            || (in_array($this->status, self::FOLLOW_UP, true) && $this->valid_until->toDateString() < $today->toDateString());
    }

    /** Estado mostrado (la UI trata como vencida la cotización en seguimiento con validez pasada). */
    public function displayStatus(CarbonInterface $today): string
    {
        return $this->isExpired($today) ? self::EXPIRED : $this->status;
    }

    public function paxTotal(): int
    {
        return $this->pax_adt + $this->pax_chd + $this->pax_inf;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed();
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id')->withTrashed();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(QuoteVersion::class)->orderBy('version_no');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(QuoteVersion::class, 'current_version_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(QuoteSend::class)->orderByDesc('sent_at');
    }

    public function views(): HasMany
    {
        return $this->hasMany(QuoteView::class)->orderByDesc('viewed_at');
    }

    public function negotiations(): HasMany
    {
        return $this->hasMany(QuoteNegotiation::class)->orderByDesc('created_at');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(QuoteAccessToken::class)->orderByDesc('created_at');
    }
}
