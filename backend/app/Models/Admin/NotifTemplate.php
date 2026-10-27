<?php

namespace App\Models\Admin;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla versionada (spec 09 §3.11). tenant_id NULL = predeterminada del sistema: por eso no usa BelongsToTenant
 * (RLS ya muestra solo las del tenant y las de sistema; el alta fija el tenant en el servicio).
 */
class NotifTemplate extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'notif_templates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean'];
    }

    /** Vigente: la del tenant si existe; si no, la del sistema. */
    public static function current(string $event, string $channel): ?self
    {
        $tenantId = app(TenantContext::class)->id();

        return self::where('event_code', $event)->where('channel', $channel)->where('is_current', true)
            ->where(fn (Builder $q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderByRaw('tenant_id IS NULL')->first();
    }

    public function isSystem(): bool
    {
        return $this->tenant_id === null;
    }
}
