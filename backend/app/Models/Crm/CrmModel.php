<?php

namespace App\Models\Crm;

use App\Models\TenantModel;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/** Base de las tablas CRM (spec 01 §3): además invalida los read models del CRM al escribir. */
abstract class CrmModel extends TenantModel
{
    protected static function booted(): void
    {
        parent::booted();
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
}
