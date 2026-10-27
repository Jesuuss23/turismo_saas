<?php

namespace App\Models\Ops;

use App\Models\Iam\User;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evidencia (§3.12, PROC-038): retiro lógico con motivo. */
class Evidence extends TenantModel
{
    public const AUDIT_COLUMNS = [];

    protected $table = 'ops_evidences';

    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'captured_offline' => 'boolean', 'removed_at' => 'datetime', 'size_bytes' => 'integer'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
