<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Primera capa del doble aislamiento (§11-2): scope global de Eloquent. La segunda es RLS.
 * Sin contexto de tenant la consulta no devuelve filas (falla cerrada, igual que RLS).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $tenantId = app(TenantContext::class)->id();
            $column = $query->getModel()->qualifyColumn('tenant_id');

            $tenantId === null ? $query->whereRaw('false') : $query->where($column, $tenantId);
        });

        static::creating(function ($model) {
            // El tenant nunca viene del cliente: se impone el del contexto.
            $model->tenant_id = app(TenantContext::class)->id() ?? throw new MissingTenantContext;
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
