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

/** Cuenta por pagar a proveedor (PROC-044/046). Base mínima: la crea la confirmación del proveedor; Finanzas la paga. */
class Payable extends TenantModel
{
    use OptimisticLock;

    public const OPEN = 'abierta';

    public const PAID = 'pagada';

    /** Etiquetas en lenguaje del proveedor (spec 06 §4.4). */
    public const SUPPLIER_LABELS = ['abierta' => 'En revisión', 'aprobada' => 'Programado', 'parcial' => 'Pago parcial', 'pagada' => 'Pagado', 'vencida' => 'Vencido', 'anulada' => 'Anulado'];

    public const STATUS_LABELS = ['abierta' => 'Abierta', 'aprobada' => 'Aprobada', 'parcial' => 'Parcial', 'pagada' => 'Pagada', 'vencida' => 'Vencida', 'anulada' => 'Anulada'];

    protected $table = 'fin_payables';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'due_date' => 'date', 'paid_at' => 'datetime'];
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
