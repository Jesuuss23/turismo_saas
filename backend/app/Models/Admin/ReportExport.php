<?php

namespace App\Models\Admin;

use App\Models\Iam\User;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Exportación PROC-061 (spec 09 §3.16): en_cola → procesando → disponible (7 días) → expirado. */
class ReportExport extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const STATUS = ['en_cola' => 'En cola', 'procesando' => 'Procesando', 'disponible' => 'Disponible', 'fallido' => 'Fallido', 'expirado' => 'Expirado'];

    protected $table = 'report_exports';

    protected $guarded = ['id', 'code', 'tenant_id'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'expires_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function displayCode(): string
    {
        return sprintf('EXP-%04d', $this->code);
    }

    public function effectiveStatus(): string
    {
        return $this->status === 'disponible' && $this->expires_at->isPast() ? 'expirado' : $this->status;
    }
}
