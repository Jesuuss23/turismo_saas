<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Ficha maestra del producto (spec 02 §3.6) y máquina de estados §4.1. */
class Product extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const DRAFT = 'DRAFT';

    public const REVIEW = 'REVIEW';

    public const APPROVED = 'APPROVED';

    public const ACTIVE = 'ACTIVE';

    public const SUSPENDED = 'SUSPENDED';

    public const INACTIVE = 'INACTIVE';

    public const ARCHIVED = 'ARCHIVED';

    public const STATUS_LABELS = [
        self::DRAFT => 'Borrador', self::REVIEW => 'En revisión', self::APPROVED => 'Aprobado', self::ACTIVE => 'Activo',
        self::SUSPENDED => 'Suspendido', self::INACTIVE => 'Inactivo', self::ARCHIVED => 'Archivado',
    ];

    /** Estados desde los que el checklist admite activar (CAT-BR-06, ítem "Estado"). */
    public const ACTIVATABLE = [self::APPROVED, self::SUSPENDED, self::INACTIVE, self::ACTIVE];

    public const AVAILABILITY_MODELS = ['SIN_CONTROL', 'CUPOS', 'CAPACIDAD', 'POR_PROVEEDOR', 'POR_ACTIVIDAD', 'POR_TRANSPORTE', 'POR_HABITACION', 'POR_SALIDA', 'POR_FECHA'];

    public const MODALITIES = ['Privado', 'Compartido', 'Grupal'];

    public const DURATION_UNITS = ['horas', 'días', 'noches'];

    protected $table = 'catalog_products';

    protected function casts(): array
    {
        return [
            'duration_value' => 'decimal:1',
            'markup_pct' => 'decimal:2',
            'minimum_passengers' => 'integer',
            'maximum_passengers' => 'integer',
            'minimum_age' => 'integer',
            'maximum_age' => 'integer',
            'booking_advance_min_days' => 'integer',
            'booking_advance_max_days' => 'integer',
            'availability_capacity' => 'integer',
            'is_bookable' => 'boolean',
            'is_quoteable' => 'boolean',
            'is_b2b' => 'boolean',
            'is_ecommerce' => 'boolean',
            'is_featured' => 'boolean',
            'features' => 'array',
            'attributes' => 'array',
            'guide_languages' => 'array',
            'rating_avg' => 'decimal:2',
            'rating_count' => 'integer',
            'operating_weekdays' => 'array',
            'cutoff_hours' => 'integer',
            'accommodation_enabled' => 'boolean',
            'accommodation_optional' => 'boolean',
            'pickup_required' => 'boolean',
            'pickup_ask_flight' => 'boolean',
        ];
    }

    /** "Comercializable" (§4.1): ACTIVE y reservable. Solo así se usa en nuevas ventas (PRD-BR-002). */
    public function isSellable(): bool
    {
        return $this->status === self::ACTIVE && $this->is_bookable;
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ProductType::class, 'product_type_id')->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class)->withTrashed();
    }

    public function primaryDestination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'primary_destination_id')->withTrashed();
    }

    public function mainProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'main_provider_id')->withTrashed();
    }

    public function secondaryDestinations(): HasMany
    {
        return $this->hasMany(ProductDestination::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductItem::class)->orderBy('sort_order');
    }

    public function itinerary(): HasOne
    {
        return $this->hasOne(Itinerary::class)->where('is_default', true);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(Rate::class)->orderBy('ref');
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(ProductSeason::class);
    }

    public function policies(): HasOne
    {
        return $this->hasOne(ProductPolicy::class);
    }

    public function inclusions(): HasMany
    {
        return $this->hasMany(ProductInclusion::class)->orderBy('sort_order');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ProductRequirement::class)->orderBy('created_at');
    }

    public function related(): HasMany
    {
        return $this->hasMany(ProductRelated::class);
    }

    public function b2bRates(): HasMany
    {
        return $this->hasMany(ProductB2bRate::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(ProductPromotion::class)->orderBy('ref');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('sort_order');
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(ProductFaq::class)->orderBy('sort_order');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(ProductChannel::class);
    }

    public function configGroups(): HasMany
    {
        return $this->hasMany(ConfigGroup::class)->orderBy('sort_order');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProductVersion::class)->orderByDesc('created_at');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'current_version_id');
    }

    public function departures(): HasMany
    {
        return $this->hasMany(Departure::class);
    }

    /** Spec 16: configuración comercial del motor de reservas. */
    public function availabilityRanges(): HasMany
    {
        return $this->hasMany(AvailabilityRange::class)->orderBy('date_from')->orderBy('code');
    }

    public function paxRule(): HasOne
    {
        return $this->hasOne(PaxRule::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(ProductAddon::class)->orderBy('code');
    }

    public function accommodationLegs(): HasMany
    {
        return $this->hasMany(AccommodationLeg::class)->orderBy('sequence');
    }

    public function paymentPolicy(): HasOne
    {
        return $this->hasOne(PaymentPolicy::class);
    }

    public function pickupPoints(): HasMany
    {
        return $this->hasMany(PickupPoint::class)->orderBy('sequence');
    }

    public function ruleOverrides(): HasMany
    {
        return $this->hasMany(RuleOverride::class);
    }
}
