<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\Catalog\Provider;
use App\Models\Catalog\Rate;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use Illuminate\Support\Collection;

/** Exportación CSV del catálogo respetando la visibilidad del rol (§7.2, §12-30: sin permiso de exportar aparte). */
class CatalogExporter
{
    public const RESOURCES = [
        'catalog.products' => ['catalog.products.view'],
        'catalog.rates' => ['catalog.products.view'],
        'catalog.departures' => ['catalog.products.view'],
        'catalog.services' => ['catalog.products.view'],
        'catalog.providers' => ['catalog.providers.manage', 'finance.payables', 'catalog.products.update'],
    ];

    public function __construct(
        private readonly CatalogAccess $access,
        private readonly ProductQuery $products,
        private readonly AvailabilityService $availability,
    ) {}

    /** @return array{0: list<string>, 1: Collection<int, list<mixed>>} */
    public function rows(string $resource, User $user, array $filters = []): array
    {
        $costs = $this->access->seeCosts($user);
        $margin = $this->access->seeMargin($user);

        return match ($resource) {
            'catalog.products' => (function () use ($user, $filters, $margin) {
                $rows = $this->products->query($filters)->get();
                $prices = $this->products->fromPrices($rows, $user);

                return [
                    ['Ref', 'Código', 'SKU', 'Nombre', 'Tipo', 'Categoría', 'Destino', 'Estado', 'Duración', 'Modalidad', 'Precio desde', 'Moneda', ...($margin ? ['Margen %'] : [])],
                    $rows->map(fn (Product $p) => [$p->ref, $p->code, $p->sku, $p->name, $p->type?->name, $p->category?->name, $p->primaryDestination?->name,
                        Product::STATUS_LABELS[$p->status], (float) $p->duration_value.' '.$p->duration_unit, $p->modality,
                        $prices[$p->id]?->price, $prices[$p->id]?->currency, ...($margin ? [$prices[$p->id] ? PricingEngine::marginPct($prices[$p->id]) : null] : [])]),
                ];
            })(),
            'catalog.rates' => [
                ['Ref', 'Producto', 'Tipo', 'Segmento', 'Canal', 'Moneda', ...($costs ? ['Costo'] : []), 'Precio', ...($margin ? ['Margen %'] : []), 'Desde', 'Hasta', 'Estado'],
                Rate::with('product:id,ref')->orderBy('ref')->get()
                    ->when(! $this->access->seeNetRates($user), fn ($c) => $c->reject(fn (Rate $r) => $r->isNet()))
                    ->map(fn (Rate $r) => [$r->ref, $r->product?->ref, $r->rate_type, $r->segment, $r->channel, $r->currency, ...($costs ? [(string) $r->cost] : []), (string) $r->price,
                        ...($margin ? [PricingEngine::marginPct($r)] : []), $r->valid_from->toDateString(), $r->valid_to->toDateString(), $r->effectiveStatus()])->values(),
            ],
            'catalog.departures' => [
                ['Ref', 'Producto', 'Fecha', 'Hora', 'Capacidad', 'Vendidos', 'En hold', 'Disponibles', 'Estado', 'Punto de encuentro'],
                Departure::with('product:id,ref')->orderBy('departure_date')->get()->map(function (Departure $d) {
                    $held = $this->availability->held($d);

                    return [$d->ref, $d->product?->ref, $d->departure_date->toDateString(), substr((string) $d->departure_time, 0, 5), $d->capacity, $d->sold, $held,
                        $this->availability->available($d, $held), $d->status, $d->meeting_point];
                }),
            ],
            'catalog.services' => [
                ['Ref', 'Servicio', 'Tipo', 'Proveedor', 'Unidad', ...($costs ? ['Costo', 'Moneda'] : []), 'Desde', 'Hasta', 'Estado'],
                SupplierService::with('provider')->orderBy('ref')->get()->map(fn (SupplierService $s) => [$s->ref, $s->name, $s->type, $s->provider?->name, $s->unit,
                    ...($costs ? [(string) $s->cost, $s->currency] : []), $s->valid_from->toDateString(), $s->valid_to->toDateString(), $s->effectiveStatus()]),
            ],
            'catalog.providers' => [
                ['Ref', 'Razón social', 'Tipo', 'RUC', 'Ciudad', 'Contacto', 'Teléfono', 'Correo', ...($costs ? ['Fin de contrato', 'Moneda'] : []), 'Estado', 'Calificación'],
                Provider::orderBy('ref')->get()->map(fn (Provider $p) => [$p->ref, $p->name, $p->type, $p->ruc, $p->city, $p->contact_name, $p->phone, $p->email,
                    ...($costs ? [$p->contract_end->toDateString(), $p->currency] : []), $p->effectiveStatus(), (float) $p->rating]),
            ],
        };
    }
}
