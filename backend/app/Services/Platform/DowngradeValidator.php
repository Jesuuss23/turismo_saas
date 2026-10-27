<?php

namespace App\Services\Platform;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * SUB-BR-03: un downgrade se bloquea si el consumo actual supera un límite del plan destino (usuarios, sedes, almacenamiento,
 * API keys, webhooks) o si hay módulos en uso que el destino no incluye. Se revalida al aplicarlo en la renovación.
 */
class DowngradeValidator
{
    /** feature => [etiqueta del uso, conteo dentro del tenant]. */
    private const MODULES = ['ecommerce', 'cms', 'mobile_apps', 'multi_sede', 'oauth_apps', 'integrations'];

    public function __construct(
        private readonly LimitService $limits,
        private readonly TenantContext $context,
    ) {}

    /** @return list<array{type: string, key: string, message: string}> */
    public function blockers(string $tenantId, string $targetPlan): array
    {
        $db = DB::connection(PlatformCatalog::CONNECTION);
        $target = $this->limits->limits($tenantId, $targetPlan);
        $gauges = $this->limits->gauges($tenantId);
        $planName = $db->table('plans')->where('id', $targetPlan)->value('name');
        $out = [];
        foreach (['users', 'sedes', 'storage_gb', 'api_keys', 'webhooks'] as $metric) {
            $limit = $target[$metric]['value'];
            if ($limit !== null && $gauges[$metric] > $limit) {
                [$label, $unit] = PlatformCatalog::METRICS[$metric];
                $out[] = ['type' => 'limit', 'key' => $metric,
                    'message' => "{$label}: usa {$this->fmt($gauges[$metric])} {$unit} y {$planName} permite {$limit}. Reduzca el consumo antes del cambio."];
            }
        }
        $features = $db->table('plan_features')->where('plan_id', $targetPlan)->pluck('value', 'feature_key');
        $inUse = $this->context->run($tenantId, fn () => $this->modulesInUse());
        foreach (self::MODULES as $feature) {
            if (($features[$feature] ?? 'not_included') === 'not_included' && ($inUse[$feature] ?? null)) {
                $out[] = ['type' => 'module', 'key' => $feature, 'message' => $inUse[$feature]." {$planName} no incluye este módulo."];
            }
        }

        return $out;
    }

    /** @return array<string, string|null> feature => descripción del uso (null = sin uso). */
    private function modulesInUse(): array
    {
        $orders = DB::table('channel_ecom_orders')->count();
        $published = DB::table('cms_contents')->where('status', 'publicado')->count();
        $field = DB::table('iam_users as u')->join('model_has_roles as m', 'm.model_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'm.role_id')
            ->whereIn('r.name', ['guide', 'driver'])->where('u.status', '<>', 'inactivo')->count();
        $sedes = DB::table('iam_sedes')->where('status', 'activa')->whereNull('deleted_at')->count();
        $apps = DB::table('integration_oauth_apps')->where('status', 'active')->count();
        $adapters = DB::table('integration_adapters')->where('status', 'activo')->count();

        return [
            'ecommerce' => $orders ? "E-commerce con {$orders} pedido(s)." : null,
            'cms' => $published ? "CMS con {$published} contenido(s) publicado(s)." : null,
            'mobile_apps' => $field ? "Apps móviles con {$field} guía(s) o conductor(es)." : null,
            'multi_sede' => $sedes > 1 ? "Multi-sede con {$sedes} sedes activas." : null,
            'oauth_apps' => $apps ? "{$apps} aplicación(es) OAuth activa(s)." : null,
            'integrations' => $adapters ? "{$adapters} integración(es) activa(s)." : null,
        ];
    }

    private function fmt(float $v): string
    {
        return floor($v) == $v ? (string) (int) $v : number_format($v, 1, '.', '');
    }
}
