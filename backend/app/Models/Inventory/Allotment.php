<?php

namespace App\Models\Inventory;

use App\Models\Catalog\Provider;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Contrato de cupo con un proveedor (spec 11 §3.1). */
class Allotment extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const LIVE = ['activo', 'en_release'];

    public const KINDS = ['hotel' => 'Hotel', 'tren' => 'Tren', 'entrada' => 'Entrada'];

    /** CUP-BR-002: tipo de servicio del catálogo → tipo de cupo. */
    public const SERVICE_TYPES = ['Alojamiento' => 'hotel', 'Tren' => 'tren', 'Entrada' => 'entrada'];

    public const STATUS_LABELS = ['activo' => 'Activo', 'en_release' => 'En release', 'liberado' => 'Liberado', 'vencido' => 'Vencido'];

    public const UNITS = ['hotel' => 'hab./noche', 'tren' => 'asiento/día', 'entrada' => 'cupo/día'];

    protected $table = 'catalog_allotments';

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'net_rate' => 'decimal:2', 'penalty_pct' => 'decimal:2', 'released_at' => 'datetime',
            'base_qty' => 'integer', 'release_days' => 'integer'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(SupplierService::class, 'service_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(AllotmentDay::class)->orderBy('service_date');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(AllotmentRequest::class)->orderByDesc('created_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AllotmentEvent::class)->orderByDesc('occurred_at');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }
}
