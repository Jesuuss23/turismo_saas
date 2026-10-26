<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductItem;
use App\Models\Catalog\Provider;
use App\Models\Catalog\ProviderContact;
use App\Models\Catalog\SupplierService;
use App\Services\Catalog\Links\CatalogLinks;
use App\Services\Catalog\ProviderService;
use App\Services\Catalog\SupplierServices;
use App\Services\Crm\CrmClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Spec 02 §6.5: servicios (PROC-013) y proveedores (PROC-012). */
class SupplierController extends CatalogController
{
    // ── Servicios ───────────────────────────────────────────────

    public function services(Request $request, CrmClock $clock): JsonResponse
    {
        $user = $this->actor($request);
        $today = $clock->today();
        $all = SupplierService::with('provider')->orderBy('ref')->get();
        $uses = ProductItem::whereNotNull('service_id')->groupBy('service_id')->selectRaw('service_id, count(distinct product_id) as n')->pluck('n', 'service_id');
        $rows = $all
            ->when($request->input('filter.type'), fn ($c, $v) => $c->where('type', $v))
            ->when($request->input('filter.provider'), fn ($c, $v) => $c->where('provider_id', $v))
            ->when($request->input('filter.status'), fn ($c, $v) => $c->filter(fn ($s) => $s->effectiveStatus() === $v))
            ->when($request->input('filter.q'), fn ($c, $v) => $c->filter(fn ($s) => str_contains(mb_strtolower($s->name.' '.$s->ref), mb_strtolower($v))));

        return $this->ok($rows->map(fn ($s) => $this->present->service($s, $user, ['products' => (int) ($uses[$s->id] ?? 0)]))->values()->all(), ['kpis' => [
            'active' => $all->filter(fn ($s) => $s->effectiveStatus() === 'activo')->count(),
            'expired_or_inactive' => $all->filter(fn ($s) => $s->effectiveStatus() !== 'activo')->count(),
            'expiring_90' => $all->filter(fn ($s) => $s->effectiveStatus() === 'activo' && $s->valid_to->toDateString() <= $today->copy()->addDays(90)->toDateString())->count(),
            'provider_not_active' => $all->filter(fn ($s) => ! $s->provider?->isUsable())->count(),
        ]]);
    }

    public function showService(Request $request, string $id, CrmClock $clock): JsonResponse
    {
        $service = $this->find(SupplierService::with('provider'), $id);
        $user = $this->actor($request);
        $products = Product::whereIn('id', ProductItem::where('service_id', $service->id)->select('product_id'))->orderBy('ref')->get(['id', 'ref', 'name', 'status']);
        $daysLeft = (int) $clock->today()->diffInDays($service->valid_to->copy()->setTimezone($clock->timezone())->startOfDay(), false);

        return $this->ok($this->present->service($service, $user, [
            'days_to_expiry' => $daysLeft,
            'expiry_alert' => $service->effectiveStatus() === 'activo' && $daysLeft <= 60,
            'products' => $products->map(fn ($p) => ['id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'status' => $p->status])->all(),
            'history' => $this->access->seeHistory($user) ? $this->history(['Servicio' => [$service->ref]]) : null,
        ]));
    }

    public function storeService(Request $request, SupplierServices $services): JsonResponse
    {
        $result = $services->create($this->serviceRules($request), $this->actor($request));

        return $this->created($this->present->service($result['service']->load('provider'), $this->actor($request)), $result['warnings']);
    }

    public function updateService(Request $request, string $id, SupplierServices $services): JsonResponse
    {
        $service = $this->find(SupplierService::query(), $id);
        $this->ifMatch($request, $service);
        $result = $services->update($service, $this->serviceRules($request, true), $this->actor($request));

        return $this->ok($this->present->service($result['service']->load('provider'), $this->actor($request)), $result['warnings'] ? ['warnings' => $result['warnings']] : []);
    }

    public function serviceStatus(Request $request, string $id, string $action, SupplierServices $services): JsonResponse
    {
        $service = $this->find(SupplierService::with('provider'), $id);
        $user = $this->actor($request);
        if ($action === 'activate') {
            return $this->ok($this->present->service($services->activate($service, $user), $user));
        }
        $result = $services->deactivate($service, (string) $request->input('reason'), $user);

        return $this->ok($this->present->service($result['service'], $user), $result['products_using'] ? ['warnings' => ["{$result['products_using']} producto(s) usan este servicio."]] : []);
    }

    private function serviceRules(Request $request, bool $editing = false): array
    {
        $req = $editing ? 'sometimes' : 'required';

        return $request->validate([
            'provider_id' => "{$req}|uuid",
            'name' => "{$req}|string|max:200",
            'type' => [$req, Rule::in(SupplierService::TYPES)],
            'unit' => [$req, Rule::in(SupplierService::UNITS)],
            'cost' => "{$req}|numeric|min:0",
            'currency' => "{$req}|in:PEN,USD",
            'capacity' => 'nullable|integer|min:1',
            'valid_from' => "{$req}|date_format:Y-m-d",
            'valid_to' => "{$req}|date_format:Y-m-d",
            'status' => 'sometimes|in:activo,inactivo',
        ]);
    }

    // ── Proveedores ─────────────────────────────────────────────

    public function providers(Request $request, CrmClock $clock, CatalogLinks $links): JsonResponse
    {
        $user = $this->actor($request);
        $today = $clock->today()->toDateString();
        $all = Provider::orderBy('ref')->get();
        $services = SupplierService::groupBy('provider_id')->selectRaw('provider_id, count(*) as n')->pluck('n', 'provider_id');
        $rows = $all
            ->when($request->input('filter.type'), fn ($c, $v) => $c->where('type', $v))
            ->when($request->input('filter.status'), fn ($c, $v) => $c->filter(fn ($p) => $p->effectiveStatus() === $v))
            ->when($request->input('filter.q'), fn ($c, $v) => $c->filter(fn ($p) => str_contains(mb_strtolower("{$p->name} {$p->ref} {$p->ruc} {$p->city}"), mb_strtolower($v))));
        $kpis = [
            'active' => $all->filter(fn ($p) => $p->effectiveStatus() === Provider::ACTIVE)->count(),
            'expiring_95' => $all->filter(fn ($p) => $p->effectiveStatus() === Provider::ACTIVE && $p->contract_end->toDateString() <= $clock->today()->addDays(95)->toDateString())->count(),
            'expired_or_inactive' => $all->filter(fn ($p) => $p->effectiveStatus() !== Provider::ACTIVE)->count(),
        ];
        if ($this->access->seePayables($user)) {
            $kpis['open_payables'] = $all->sum(fn ($p) => $links->providerPayables($p->id)['open_count'] ?? 0);
        }

        return $this->ok($rows->map(fn ($p) => $this->present->provider($p, $user, [
            'services' => (int) ($services[$p->id] ?? 0),
            'contract_expiring' => $p->effectiveStatus() === Provider::ACTIVE && $p->contract_end->toDateString() <= $clock->today()->addDays(95)->toDateString() && $p->contract_end->toDateString() >= $today,
        ]))->values()->all(), ['kpis' => $kpis]);
    }

    public function showProvider(Request $request, string $id, CatalogLinks $links, ProviderService $providers): JsonResponse
    {
        $provider = $this->find(Provider::query(), $id);
        $user = $this->actor($request);
        $services = SupplierService::where('provider_id', $provider->id)->with('provider')->orderBy('ref')->get();
        $productIds = ProductItem::whereIn('service_id', $services->pluck('id'))->pluck('product_id');
        $products = Product::where(fn ($q) => $q->whereIn('id', $productIds)->orWhere('main_provider_id', $provider->id))->orderBy('ref')->get(['id', 'ref', 'name', 'status', 'main_provider_id']);

        return $this->ok($this->present->provider($provider, $user, [
            'contacts' => ProviderContact::where('provider_id', $provider->id)->orderBy('name')->get()->map(fn ($c) => $c->only(['id', 'name', 'role', 'phone', 'email']))->all(),
            'services' => $services->map(fn ($s) => $this->present->service($s, $user))->all(),
            'products' => $products->map(fn ($p) => ['id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'status' => $p->status, 'is_main' => $p->main_provider_id === $provider->id])->all(),
            'active_products' => $providers->activeProductsUsing($provider),
            'reservations' => $links->providerReservations($provider->id),
            ...($this->access->seePayables($user) ? ['payables' => $links->providerPayables($provider->id)] : []),
            'history' => $this->access->seeHistory($user) ? $this->history(['Proveedor' => [$provider->ref]]) : null,
        ]));
    }

    public function storeProvider(Request $request, ProviderService $providers): JsonResponse
    {
        $provider = $providers->create($this->providerRules($request), $this->actor($request));

        return $this->created($this->present->provider($provider, $this->actor($request)));
    }

    public function updateProvider(Request $request, string $id, ProviderService $providers): JsonResponse
    {
        $provider = $this->find(Provider::query(), $id);
        $this->ifMatch($request, $provider);
        $provider = $providers->update($provider, $this->providerRules($request, true), $this->actor($request));

        return $this->ok($this->present->provider($provider, $this->actor($request)));
    }

    /** activate · deactivate {reason} · renew-contract {contract_end, payment_terms} · rating {rating, note}. */
    public function providerAction(Request $request, string $id, string $action, ProviderService $providers): JsonResponse
    {
        $provider = $this->find(Provider::query(), $id);
        $user = $this->actor($request);
        $meta = [];
        switch ($action) {
            case 'activate':
                $providers->activate($provider, $user);
                break;
            case 'deactivate':
                $result = $providers->deactivate($provider, (string) $request->input('reason'), $user);
                if ($result['affected_products']) {
                    $meta['warnings'] = ['Productos activos afectados (se notificó a gerencia): '.implode(', ', $result['affected_products'])];
                }
                break;
            case 'renew-contract':
                $data = $request->validate(['contract_end' => 'required|date_format:Y-m-d', 'payment_terms' => 'nullable|string|max:120']);
                $providers->renew($provider, $data['contract_end'], $data['payment_terms'] ?? null, $user);
                break;
            case 'rating':
                $data = $request->validate(['rating' => 'required|numeric|min:1|max:5', 'note' => 'nullable|string|max:1000']);
                $providers->rate($provider, (float) $data['rating'], $data['note'] ?? null, $user);
                break;
        }

        return $this->ok($this->present->provider($provider->fresh(), $user), $meta);
    }

    public function addContact(Request $request, string $id, ProviderService $providers): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'role' => 'nullable|string|max:80', 'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:160']);
        $contact = $providers->addContact($this->find(Provider::query(), $id), $data, $this->actor($request));

        return $this->created($contact->only(['id', 'name', 'role', 'phone', 'email']));
    }

    public function removeContact(Request $request, string $id, string $contact, ProviderService $providers): JsonResponse
    {
        $provider = $this->find(Provider::query(), $id);
        $providers->removeContact($provider, $this->child(ProviderContact::class, $contact, 'provider_id', $provider->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    private function providerRules(Request $request, bool $editing = false): array
    {
        $req = $editing ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "{$req}|string|max:160",
            'type' => [$req, Rule::in(Provider::TYPES)],
            'ruc' => "{$req}|string|size:11",
            'city' => 'nullable|string|max:80',
            'stars' => 'nullable|integer|min:0|max:5',
            'contact_name' => "{$req}|string|max:120",
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:160',
            'contract_start' => "{$req}|date_format:Y-m-d",
            'contract_end' => "{$req}|date_format:Y-m-d",
            'currency' => "{$req}|in:PEN,USD",
            'payment_terms' => 'nullable|string|max:120',
        ]);
    }
}
