<?php

namespace App\Models\Supplier;

use App\Models\Finance\Payable;
use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Factura del proveedor asociada a una CxP (spec 06 §3.3). */
class SupplierInvoice extends TenantModel
{
    use SoftDeletes;

    public const ACTIVE = ['registrada', 'validada'];

    public const STATUS_LABELS = ['registrada' => 'Registrada', 'validada' => 'Validada', 'observada' => 'Observada', 'rechazada' => 'Rechazada'];

    public const AUDIT_COLUMNS = [];

    protected $table = 'supplier_invoices';

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'amount' => 'decimal:2', 'amount_difference' => 'decimal:2', 'uploaded_at' => 'datetime', 'validated_at' => 'datetime', 'size_bytes' => 'integer'];
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }
}
