<?php

namespace App\Models\Finance;

use App\Models\Bookings\Reservation;
use App\Models\Crm\Client;
use App\Models\TenantModel;
use App\Support\Concerns\OptimisticLock;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Comprobante electrónico: factura, boleta o nota de crédito (PROC-050). Nunca se elimina: se anula por NC o baja. */
class Invoice extends TenantModel
{
    use OptimisticLock;

    public const INVOICE = '01';

    public const RECEIPT = '03';

    public const CREDIT_NOTE = '07';

    public const TYPE_LABELS = ['01' => 'Factura', '03' => 'Boleta', '07' => 'Nota de crédito', '08' => 'Nota de débito'];

    public const STATUS_LABELS = ['pendiente_envio' => 'Pendiente de envío', 'emitido' => 'Emitido', 'aceptado' => 'Aceptado', 'observado' => 'Observado',
        'rechazado' => 'Rechazado', 'anulado' => 'Anulado'];

    /** Comprobantes que cuentan para lo facturado (FIN-BR-43): todos salvo rechazados y anulados. */
    public const COUNTED = ['pendiente_envio', 'emitido', 'aceptado', 'observado'];

    /** Catálogo 09 de SUNAT (motivos de nota de crédito más usados en turismo). */
    public const CREDIT_REASONS = ['01' => 'Anulación de la operación', '06' => 'Devolución total', '07' => 'Devolución por ítem',
        '09' => 'Disminución en el valor', '13' => 'Ajuste de montos y/o fechas de pago'];

    protected $table = 'fin_invoices';

    protected function casts(): array
    {
        return ['total_amount' => 'decimal:2', 'taxable_amount' => 'decimal:2', 'igv_amount' => 'decimal:2', 'exempt_amount' => 'decimal:2',
            'fx_rate' => 'decimal:6', 'issue_date' => 'date', 'customer_domiciled' => 'boolean', 'ose_responded_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function isCreditNote(): bool
    {
        return $this->doc_type === self::CREDIT_NOTE;
    }

    /** Importe con signo: las NC restan. */
    public function signedAmount(): float
    {
        return ($this->isCreditNote() ? -1 : 1) * (float) $this->total_amount;
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id')->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed();
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class, 'receivable_id')->withTrashed();
    }

    public function reference(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ref_invoice_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_no');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FiscalEvent::class)->orderBy('created_at');
    }
}
