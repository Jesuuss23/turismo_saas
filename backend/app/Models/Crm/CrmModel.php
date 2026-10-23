<?php

namespace App\Models\Crm;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Base de las tablas CRM (spec 01 §3): tenant por contexto, uuid, autoría.
 * `created_by` NULL = Sistema (ingreso automático, jobs).
 */
abstract class CrmModel extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /** Columnas generadas por PostgreSQL: nunca se escriben desde la app. */
    protected array $generated = [];

    protected static function booted(): void
    {
        static::creating(function (CrmModel $model) {
            if ($model->hasColumn('created_by') && $model->created_by === null) {
                $model->created_by = auth()->id();
            }
            if ($model->hasColumn('updated_by')) {
                $model->updated_by = auth()->id();
            }
        });

        static::updating(function (CrmModel $model) {
            if ($model->hasColumn('updated_by')) {
                $model->updated_by = auth()->id();
            }
        });

        // Recarga tras el INSERT: defaults de BD (lock_version, status…) y columnas generadas.
        static::created(fn (CrmModel $model) => $model->refresh());
        static::saved(fn () => self::bumpVersion());
    }

    /** Versión de datos CRM del tenant: invalida read models en cache (panel de Ventas, §6.6). */
    public static function bumpVersion(): void
    {
        $tenant = app(TenantContext::class)->id();
        if ($tenant !== null) {
            Cache::forever("crm:version:{$tenant}", (int) Cache::get("crm:version:{$tenant}", 0) + 1);
        }
    }

    public static function dataVersion(): int
    {
        return (int) Cache::get('crm:version:'.app(TenantContext::class)->id(), 0);
    }

    protected function hasColumn(string $column): bool
    {
        return in_array($column, static::AUDIT_COLUMNS, true);
    }

    /** Las tablas maestras tienen created_by/updated_by; las de bitácora no. */
    public const AUDIT_COLUMNS = ['created_by', 'updated_by'];

    /** Fechas con zona (horas de negocio en la zona del tenant) se guardan en UTC, la zona de la app. */
    public function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)->setTimezone(config('app.timezone'))->format($this->getDateFormat());
    }

    protected function getAttributesForInsert()
    {
        return array_diff_key(parent::getAttributesForInsert(), array_flip($this->generated));
    }
}
