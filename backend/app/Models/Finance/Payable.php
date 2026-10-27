<?php

namespace App\Models\Finance;

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Provider;
use App\Models\Supplier\SupplierInvoice;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Cuenta por pagar a proveedor o costo adicional (PROC-044/045/046). La crea la confirmación del servicio o Finanzas al registrar un costo. */
class Payable extends TenantModel
{
    use OptimisticLock, SoftDeletes;

    public const OPEN = 'abierta';

    public const PAID = 'pagada';

    public const CANCELLED = 'cancelada';

    /** Estados con saldo por pagar (programar, pagar, vencer). */
    public const PAYABLE = ['abierta', 'aprobada', 'parcial', 'vencida'];

    public const COST_TYPES = ['costo_adicional' => 'Costo adicional', 'ajuste_tarifa' => 'Ajuste de tarifa', 'penalidad_proveedor' => 'Penalidad del proveedor',
        'gasto_operativo_destino' => 'Gasto operativo en destino'];

    /** Etiquetas en lenguaje del proveedor (spec 06 §4.4). */
    public const SUPPLIER_LABELS = ['abierta' => 'En revisión', 'aprobada' => 'Programado', 'parcial' => 'Pago parcial', 'pagada' => 'Pagado', 'vencida' => 'Vencido', 'cancelada' => 'Anulado'];

    public const STATUS_LABELS = ['abierta' => 'Abierta', 'aprobada' => 'Aprobada', 'parcial' => 'Parcial', 'pagada' => 'Pagada', 'vencida' => 'Vencida', 'cancelada' => 'Cancelada'];

    protected $table = 'fin_payables';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'fx_rate' => 'decimal:6', 'due_date' => 'date', 'paid_at' => 'datetime',
            'approved_at' => 'datetime', 'scheduled_date' => 'date'];
    }

    public function balance(): string
    {
        return number_format(max(0, (float) $this->amount - (float) $this->paid_amount), 2, '.', '');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id')->withTrashed();
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id')->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookedService::class, 'reservation_service_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class)->orderBy('paid_on');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class)->orderByDesc('uploaded_at');
    }

    /** Factura activa (registrada o validada; SUP-BR-42). */
    public function activeInvoice(): HasOne
    {
        return $this->hasOne(SupplierInvoice::class)->whereIn('status', SupplierInvoice::ACTIVE)->latest('uploaded_at');
    }
}
