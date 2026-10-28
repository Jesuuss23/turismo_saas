<?php

namespace App\Models\Channel;

use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductPromotion;
use App\Models\Crm\Client;
use App\Models\Finance\Receivable;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pedido web (spec 08 §3.6, §4.2). */
class EcomOrder extends TenantModel
{
    use OptimisticLock;

    public const OPEN = ['pago_pendiente', 'pago_rechazado'];

    public const STATUS_LABELS = ['pago_pendiente' => 'Pago pendiente', 'pago_rechazado' => 'Pago rechazado', 'pago_por_validar' => 'Pago por validar', 'confirmado' => 'Confirmado',
        'cancelado' => 'Cancelado', 'expirado' => 'Expirado'];

    protected $table = 'channel_ecom_orders';

    protected array $generated = ['pax_total'];

    protected $hidden = ['access_token_hash'];

    protected function casts(): array
    {
        return ['ordered_at' => 'datetime', 'service_date' => 'date', 'config_snapshot' => 'array', 'price_snapshot' => 'array', 'buyer_snapshot' => 'array',
            'utm' => 'array', 'discount_amount' => 'decimal:2', 'total' => 'decimal:2', 'cost_total' => 'decimal:2', 'hold_expires_at' => 'datetime',
            'billing' => 'array', 'consents' => 'array', 'amount_due_now' => 'decimal:2', 'balance_due_date' => 'date'];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }

    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class, 'departure_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(ProductPromotion::class, 'promotion_id')->withTrashed();
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id')->withTrashed();
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class, 'receivable_id');
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(EcomPassenger::class, 'order_id')->orderBy('position');
    }

    /** Spec 15: pedido multiproducto (una reserva por línea). */
    public function lines(): HasMany
    {
        return $this->hasMany(EcomOrderLine::class, 'order_id')->orderBy('line_no');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(EcomPaymentAttempt::class, 'order_id')->orderBy('attempted_at');
    }
}
