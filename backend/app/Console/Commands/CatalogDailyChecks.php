<?php

namespace App\Console\Commands;

use App\Models\Catalog\Product;
use App\Models\Catalog\Provider;
use App\Models\Catalog\Rate;
use App\Models\Catalog\SupplierService;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Catalog\ProviderService;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Jobs diarios del catálogo (spec 02 §8.2): MarkExpiredProviderContracts, ProviderContractExpiryAlerts,
 * ServiceValidityAlerts y RateValidityAlerts. Las alertas van a gerencia y operaciones por Portal.
 */
class CatalogDailyChecks extends Command
{
    protected $signature = 'catalog:daily {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Contratos vencidos y alertas de vigencia de proveedores, servicios y tarifas';

    public function handle(TenantContext $context): int
    {
        foreach (OperatingTenants::ids($this->option('tenant'), null) as $tenantId) {
            $context->run($tenantId, function () {
                $expired = app(ProviderService::class)->markExpired();
                $today = app(CrmClock::class)->today();
                $notify = app(Notify::class);

                $contracts = Provider::where('status', Provider::ACTIVE)->whereDate('contract_end', '>=', $today->toDateString())
                    ->whereDate('contract_end', '<=', $today->copy()->addDays(95)->toDateString())->orderBy('contract_end')->get();
                $services = SupplierService::where('status', 'activo')->whereDate('valid_to', '>=', $today->toDateString())
                    ->whereDate('valid_to', '<=', $today->copy()->addDays(90)->toDateString())->orderBy('valid_to')->get();
                $withoutRate = Product::where('status', Product::ACTIVE)->get()
                    ->filter(fn (Product $p) => ! Rate::where('product_id', $p->id)->get()->contains(fn (Rate $r) => $r->effectiveStatus($today) === 'vigente'));

                $lines = array_filter([
                    $contracts->isNotEmpty() ? 'Contratos que vencen en ≤95 días: '.$contracts->map(fn ($p) => "{$p->name} ({$p->contract_end->toDateString()})")->implode(', ') : null,
                    $services->isNotEmpty() ? 'Servicios que vencen en ≤90 días: '.$services->map(fn ($s) => "{$s->ref} {$s->name} ({$s->valid_to->toDateString()})")->implode(', ') : null,
                    $withoutRate->isNotEmpty() ? 'Productos activos sin tarifa vigente: '.$withoutRate->pluck('ref')->implode(', ') : null,
                ]);
                if ($lines) {
                    foreach (CatalogRecipients::role(['manager', 'operations']) as $user) {
                        $notify->push('catalogo.alertas_vigencia', 'portal', $user, 'Alertas de vigencia del catálogo', implode("\n", $lines));
                    }
                }
                $this->line(count($expired).' contrato(s) vencido(s); '.count($lines).' alerta(s).');
            });
        }

        return self::SUCCESS;
    }
}
