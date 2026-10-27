<?php

namespace App\Models\Admin;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Error de job, notificación o integración (spec 09 §3.15, §4.6). */
class IntegrationError extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS = ['reintentando' => 'Reintentando', 'fallo_definitivo' => 'Fallo definitivo', 'resuelto' => 'Resuelto'];

    protected $table = 'integration_errors';

    protected $guarded = ['id', 'code', 'tenant_id'];

    protected function casts(): array
    {
        return ['next_retry_at' => 'datetime'];
    }

    public function displayCode(): string
    {
        return sprintf('ERR-%04d', $this->code);
    }
}
