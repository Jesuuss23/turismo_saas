<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Departure;
use App\Models\Catalog\Destination;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductChannel;
use App\Models\Catalog\ProductDestination;
use App\Models\Catalog\Rate;
use App\Models\Iam\User;
use App\Services\Crm\CrmClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Listado de productos con filtros (§2, §6.1, CAT-BR-43/44), KPIs y "precio desde" visible al rol. */
class ProductQuery
{
    public function __construct(
        private readonly CatalogAccess $access,
        private readonly RateService $rates,
        private readonly CrmClock $clock,
    ) {}

    /** @param array{type?: string, category?: string, status?: string, destination?: string, channel?: string, q?: string} $filters */
    public function query(array $filters): Builder
    {
        $query = Product::query()->with(['type', 'category', 'subcategory', 'primaryDestination']);

        if ($v = $filters['type'] ?? null) {
            $query->where('product_type_id', $v);
        }
        if ($v = $filters['category'] ?? null) {
            $query->where('category_id', $v);
        }
        if ($v = $filters['status'] ?? null) {
            $query->whereIn('status', explode(',', $v));
        }
        if ($v = $filters['destination'] ?? null) {
            $this->whereDestinationIn($query, $this->descendants([$v]));
        }
        if ($v = $filters['channel'] ?? null) {
            $query->whereIn('id', ProductChannel::where('channel', $v)->where('is_published', true)->select('product_id'));
        }
        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
            $destinations = Destination::whereRaw('lower(name) like ?', [$like])->pluck('id')->all();
            $query->where(function ($q) use ($like, $destinations) {
                $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(code) like ?', [$like])
                    ->orWhereRaw('lower(sku) like ?', [$like])->orWhereRaw('lower(ref) like ?', [$like]);
                if ($destinations) {
                    $q->orWhere(fn ($q) => $this->whereDestinationIn($q, $this->descendants($destinations)));
                }
            });
        }

        return match ($filters['sort'] ?? 'ref') {
            'name' => $query->orderBy('name'),
            '-created_at' => $query->orderByDesc('created_at'),
            default => $query->orderBy('ref'),
        };
    }

    /** Ids del destino y sus descendientes (CAT-BR-43). */
    public function descendants(array $ids): array
    {
        $paths = Destination::whereIn('id', $ids)->pluck('path');
        if ($paths->isEmpty()) {
            return ['00000000-0000-0000-0000-000000000000'];
        }

        return Destination::where(fn ($q) => $paths->each(fn ($p) => $q->orWhere('path', 'like', $p.'%')))->pluck('id')->all();
    }

    private function whereDestinationIn(Builder $query, array $ids): void
    {
        $query->where(fn ($q) => $q->whereIn('primary_destination_id', $ids)
            ->orWhereIn('id', ProductDestination::whereIn('destination_id', $ids)->select('product_id')));
    }

    /** "Precio desde" visible al rol por producto (CAT-BR-44). @return array<string, ?Rate> */
    public function fromPrices(Collection $products, User $user): array
    {
        $rates = Rate::whereIn('product_id', $products->pluck('id'))->get()->groupBy('product_id');
        $seeNet = $this->access->seeNetRates($user);
        $out = [];
        foreach ($products as $p) {
            $visible = ($rates[$p->id] ?? collect())->when(! $seeNet, fn ($c) => $c->reject(fn (Rate $r) => $r->isNet()));
            $out[$p->id] = $this->rates->fromPrice($visible);
        }

        return $out;
    }

    /** Salidas futuras abiertas por producto. @return array<string, int> */
    public function futureDepartures(Collection $productIds): array
    {
        return Departure::whereIn('product_id', $productIds)->where('status', 'abierta')->whereDate('departure_date', '>=', $this->clock->today()->toDateString())
            ->groupBy('product_id')->selectRaw('product_id, count(*) as n')->pluck('n', 'product_id')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<string, int> canales publicados por producto */
    public function channelCounts(Collection $productIds): array
    {
        return ProductChannel::whereIn('product_id', $productIds)->where('is_published', true)->groupBy('product_id')
            ->selectRaw('product_id, count(*) as n')->pluck('n', 'product_id')->map(fn ($n) => (int) $n)->all();
    }

    /** KPIs del listado (§2): margen promedio solo con rentabilidad; si no, "Reservables hoy". */
    public function kpis(User $user): array
    {
        $all = Product::query()->get(['id', 'status', 'is_bookable']);
        $future = count($this->futureDepartures($all->pluck('id')));
        $kpis = [
            'active' => $all->where('status', Product::ACTIVE)->count(),
            'in_progress' => $all->whereIn('status', [Product::DRAFT, Product::REVIEW])->count(),
            'with_future_departures' => $future,
            'total' => $all->count(),
        ];
        if ($this->access->seeMargin($user)) {
            $active = Product::where('status', Product::ACTIVE)->get(['id']);
            $margins = collect($this->fromPrices($active, $user))->filter()->map(fn (Rate $r) => (float) PricingEngine::marginPct($r));
            $kpis['avg_margin_pct'] = $margins->isEmpty() ? null : round($margins->avg(), 1);
        } else {
            $kpis['bookable_today'] = $all->filter(fn ($p) => $p->status === Product::ACTIVE && $p->is_bookable)->count();
        }

        return $kpis;
    }
}
