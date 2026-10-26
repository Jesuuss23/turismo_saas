<?php

namespace App\Http\Resources\Catalog;

use App\Http\Resources\Crm\CrmPresenter;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Destination;
use App\Models\Catalog\Hold;
use App\Models\Catalog\ItineraryDay;
use App\Models\Catalog\ItineraryItem;
use App\Models\Catalog\PriceRule;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductItem;
use App\Models\Catalog\ProductPromotion;
use App\Models\Catalog\Provider;
use App\Models\Catalog\Rate;
use App\Models\Catalog\Season;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Services\Catalog\CatalogAccess;
use App\Services\Catalog\PricingEngine;

/**
 * Serialización del catálogo con omisión de datos sensibles por rol (§7.2, CAT-BR-40):
 * los campos que el rol no ve no se envían (no basta con ocultarlos en Next.js).
 */
class CatalogPresenter
{
    public function __construct(
        private readonly CatalogAccess $access,
        public readonly CrmPresenter $users,
    ) {}

    /** Fila de listado / tarjeta. */
    public function productRow(Product $p, User $user, ?Rate $fromPrice = null, array $extra = []): array
    {
        return [
            'id' => $p->id,
            'ref' => $p->ref,
            'code' => $p->code,
            'sku' => $p->sku,
            'name' => $p->name,
            'type' => $p->type?->name,
            'category' => $p->category?->name,
            'subcategory' => $p->subcategory?->name,
            'primary_destination' => $p->primaryDestination ? ['id' => $p->primaryDestination->id, 'name' => $p->primaryDestination->name] : null,
            'status' => $p->status,
            'status_label' => Product::STATUS_LABELS[$p->status] ?? $p->status,
            'sellable' => $p->isSellable(),
            'duration' => ['value' => (float) $p->duration_value, 'unit' => $p->duration_unit],
            'modality' => $p->modality,
            'is_bookable' => $p->is_bookable,
            'is_featured' => $p->is_featured,
            'availability_model' => $p->availability_model,
            'from_price' => $fromPrice ? ['rate' => $fromPrice->ref, 'currency' => $fromPrice->currency, 'price' => (string) $fromPrice->price, 'segment' => $fromPrice->segment, 'channel' => $fromPrice->channel] : null,
            ...$this->when($this->access->seeMargin($user), fn () => ['margin_pct' => $fromPrice ? PricingEngine::marginPct($fromPrice) : null]),
            ...$extra,
        ];
    }

    /** Cabecera + ficha del 360. */
    public function productDetail(Product $p, User $user, ?Rate $fromPrice, array $extra = []): array
    {
        return $this->productRow($p, $user, $fromPrice) + [
            'internal_name' => $p->internal_name,
            'product_type_id' => $p->product_type_id,
            'category_id' => $p->category_id,
            'subcategory_id' => $p->subcategory_id,
            'primary_destination_id' => $p->primary_destination_id,
            'main_provider' => $p->mainProvider ? ['id' => $p->mainProvider->id, 'ref' => $p->mainProvider->ref, 'name' => $p->mainProvider->name, 'status' => $p->mainProvider->effectiveStatus()] : null,
            'short_description' => $p->short_description,
            'long_description' => $p->long_description,
            'minimum_passengers' => $p->minimum_passengers,
            'maximum_passengers' => $p->maximum_passengers,
            'minimum_age' => $p->minimum_age,
            'maximum_age' => $p->maximum_age,
            'booking_advance_min_days' => $p->booking_advance_min_days,
            'booking_advance_max_days' => $p->booking_advance_max_days,
            'is_quoteable' => $p->is_quoteable,
            'is_b2b' => $p->is_b2b,
            'is_ecommerce' => $p->is_ecommerce,
            'availability_capacity' => $p->availability_capacity,
            'availability_source' => $p->availability_source,
            'features' => $p->features ?? [],
            'video_url' => $p->video_url,
            'seo_title' => $p->seo_title,
            'seo_description' => $p->seo_description,
            'slug' => $p->slug,
            ...$this->when($this->access->seeMargin($user), fn () => ['markup_pct' => (string) $p->markup_pct]),
            'current_version' => $p->currentVersion ? ['id' => $p->currentVersion->id, 'version' => $p->currentVersion->version, 'status' => $p->currentVersion->status] : null,
            'lock_version' => $p->lock_version,
            'created_at' => $p->created_at,
            'updated_at' => $p->updated_at,
            'created_by' => $this->users->user($p->created_by),
            ...$extra,
        ];
    }

    public function rate(Rate $r, User $user): array
    {
        return [
            'id' => $r->id,
            'ref' => $r->ref,
            'product_id' => $r->product_id,
            'rate_type' => $r->rate_type,
            'segment' => $r->segment,
            'channel' => $r->channel,
            'currency' => $r->currency,
            'price_basis' => $r->price_basis,
            ...$this->when($this->access->seeCosts($user), fn () => ['cost' => (string) $r->cost]),
            'price' => (string) $r->price,
            'min_price' => $r->min_price !== null ? (string) $r->min_price : null,
            'max_price' => $r->max_price !== null ? (string) $r->max_price : null,
            ...$this->when($this->access->seeMargin($user), fn () => ['margin_pct' => PricingEngine::marginPct($r)]),
            'valid_from' => $r->valid_from->toDateString(),
            'valid_to' => $r->valid_to->toDateString(),
            'season' => $r->season ? ['id' => $r->season->id, 'ref' => $r->season->ref, 'name' => $r->season->name] : null,
            'conditions' => $r->conditions,
            'status' => $r->status,
            'effective_status' => $r->effectiveStatus(),
            'is_net' => $r->isNet(),
            'lock_version' => $r->lock_version,
        ];
    }

    public function component(ProductItem $i, User $user, float $fx): array
    {
        $service = $i->service;
        $unitCost = $i->unit_cost_override !== null ? (float) $i->unit_cost_override : ($service ? (float) $service->cost : null);
        $currency = $service?->currency ?? 'PEN';

        return [
            'id' => $i->id,
            'kind' => $service ? 'service' : 'product',
            'service' => $service ? ['id' => $service->id, 'ref' => $service->ref, 'name' => $service->name, 'type' => $service->type, 'status' => $service->effectiveStatus(),
                'valid_from' => $service->valid_from->toDateString(), 'valid_to' => $service->valid_to->toDateString(), 'selectable' => $service->isSelectable()] : null,
            'component_product' => $i->componentProduct ? ['id' => $i->componentProduct->id, 'ref' => $i->componentProduct->ref, 'name' => $i->componentProduct->name, 'status' => $i->componentProduct->status] : null,
            'provider' => $service?->provider ? ['id' => $service->provider->id, 'ref' => $service->provider->ref, 'name' => $service->provider->name, 'status' => $service->provider->effectiveStatus()] : null,
            'quantity' => (float) $i->quantity,
            'unit' => $i->unit,
            'sort_order' => $i->sort_order,
            'is_required' => $i->is_required,
            'is_included' => $i->is_included,
            'notes' => $i->notes,
            ...$this->when($this->access->seeCosts($user), fn () => [
                'unit_cost' => $unitCost,
                'unit_cost_override' => $i->unit_cost_override !== null ? (float) $i->unit_cost_override : null,
                'currency' => $currency,
                'total_cost_pen' => $unitCost === null ? null : round($unitCost * (float) $i->quantity * ($currency === 'USD' ? $fx : 1), 2),
            ]),
        ];
    }

    public function day(ItineraryDay $d, User $user): array
    {
        return [
            'id' => $d->id,
            'day_number' => $d->day_number,
            'title' => $d->title,
            'items' => $d->items->map(fn (ItineraryItem $it) => [
                'id' => $it->id,
                'start_time' => $it->start_time ? substr((string) $it->start_time, 0, 5) : null,
                'activity' => $it->activity,
                'item_type' => $it->item_type,
                'location' => $it->location,
                'service_id' => $it->service_id,
                'note' => $it->note,
                'image_ref' => $it->image_ref,
                ...$this->when($this->access->seeCosts($user), fn () => ['cost' => (float) $it->cost, 'price' => (float) $it->price]),
            ])->all(),
        ];
    }

    public function promotion(ProductPromotion $p): array
    {
        return [
            'id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'kind' => $p->kind, 'value' => (string) $p->value, 'channel' => $p->channel,
            'promo_code' => $p->promo_code, 'date_from' => $p->date_from->toDateString(), 'date_to' => $p->date_to->toDateString(),
            'status' => $p->status, 'effective_status' => $p->effectiveStatus(), 'is_percent' => in_array($p->kind, ProductPromotion::PERCENT_KINDS, true),
        ];
    }

    public function departure(Departure $d, int $held, int $available, array $extra = []): array
    {
        return [
            'id' => $d->id,
            'ref' => $d->ref,
            'product' => $d->relationLoaded('product') && $d->product ? ['id' => $d->product->id, 'ref' => $d->product->ref, 'name' => $d->product->name, 'status' => $d->product->status] : null,
            'departure_date' => $d->departure_date->toDateString(),
            'departure_time' => substr((string) $d->departure_time, 0, 5),
            'capacity' => $d->capacity,
            'sold' => $d->sold,
            'held' => $held,
            'available' => $available,
            'occupancy_pct' => $d->capacity > 0 ? round(($d->sold + $held) / $d->capacity * 100) : 0,
            'status' => $d->status,
            'provider' => $d->relationLoaded('provider') && $d->provider ? ['id' => $d->provider->id, 'name' => $d->provider->name] : null,
            'provider_id' => $d->provider_id,
            'guide_id' => $d->guide_id,
            'vehicle_id' => $d->vehicle_id,
            'meeting_point' => $d->meeting_point,
            'return_point' => $d->return_point,
            'notes' => $d->notes,
            'lock_version' => $d->lock_version,
            ...$extra,
        ];
    }

    public function hold(Hold $h): array
    {
        $d = $h->departure;

        return [
            'id' => $h->id,
            'ref' => $h->ref,
            'departure' => $d ? ['id' => $d->id, 'ref' => $d->ref, 'date' => $d->departure_date->toDateString(), 'time' => substr((string) $d->departure_time, 0, 5),
                'product' => $d->relationLoaded('product') && $d->product ? ['ref' => $d->product->ref, 'name' => $d->product->name] : null] : null,
            'quantity' => $h->quantity,
            'reference' => $h->reference,
            'status' => $h->status,
            'expired_pending' => $h->status === 'hold' && $h->expires_at->isPast(),
            'expires_at' => $h->expires_at,
            'released_reason' => $h->released_reason,
            'notes' => $h->notes,
            'created_at' => $h->created_at,
            'created_by' => $this->users->user($h->created_by),
            'lock_version' => $h->lock_version,
        ];
    }

    public function season(Season $s, array $extra = []): array
    {
        return [
            'id' => $s->id, 'ref' => $s->ref, 'name' => $s->name, 'type' => $s->type,
            'date_from' => $s->date_from->toDateString(), 'date_to' => $s->date_to->toDateString(),
            'priority' => $s->priority, 'factor' => (string) $s->factor, 'status' => $s->status, ...$extra,
        ];
    }

    public function priceRule(PriceRule $r): array
    {
        return [
            'id' => $r->id, 'ref' => $r->ref, 'name' => $r->name, 'kind' => $r->kind, 'value' => rtrim(rtrim((string) $r->value, '0'), '.'),
            'scope' => $r->scope, 'channel' => $r->channel, 'min_pax' => $r->min_pax, 'customer_residency' => $r->customer_residency,
            'product_id' => $r->product_id, 'currency' => $r->currency, 'priority' => $r->priority, 'status' => $r->status,
        ];
    }

    public function provider(Provider $p, User $user, array $extra = []): array
    {
        return [
            'id' => $p->id,
            'ref' => $p->ref,
            'name' => $p->name,
            'type' => $p->type,
            'ruc' => $p->ruc,
            'city' => $p->city,
            'stars' => $p->stars,
            'contact_name' => $p->contact_name,
            'phone' => $p->phone,
            'email' => $p->email,
            ...$this->when($this->access->seeCosts($user), fn () => [
                'contract_start' => $p->contract_start->toDateString(),
                'contract_end' => $p->contract_end->toDateString(),
                'currency' => $p->currency,
                'payment_terms' => $p->payment_terms,
            ]),
            'status' => $p->status,
            'effective_status' => $p->effectiveStatus(),
            'rating' => (float) $p->rating,
            'rating_note' => $p->rating_note,
            'lock_version' => $p->lock_version,
            ...$extra,
        ];
    }

    public function service(SupplierService $s, User $user, array $extra = []): array
    {
        return [
            'id' => $s->id,
            'ref' => $s->ref,
            'name' => $s->name,
            'type' => $s->type,
            'unit' => $s->unit,
            'provider' => $s->provider ? ['id' => $s->provider->id, 'ref' => $s->provider->ref, 'name' => $s->provider->name, 'status' => $s->provider->effectiveStatus()] : null,
            'provider_id' => $s->provider_id,
            ...$this->when($this->access->seeCosts($user), fn () => ['cost' => (string) $s->cost, 'currency' => $s->currency]),
            'capacity' => $s->capacity,
            'valid_from' => $s->valid_from->toDateString(),
            'valid_to' => $s->valid_to->toDateString(),
            'status' => $s->status,
            'effective_status' => $s->effectiveStatus(),
            'selectable' => $s->isSelectable(),
            'lock_version' => $s->lock_version,
            ...$extra,
        ];
    }

    public function destination(Destination $d, array $extra = []): array
    {
        return [
            'id' => $d->id, 'ref' => $d->ref, 'name' => $d->name, 'type' => $d->type, 'level' => $d->level, 'parent_id' => $d->parent_id,
            'lat' => $d->lat !== null ? (float) $d->lat : null, 'lng' => $d->lng !== null ? (float) $d->lng : null, 'description' => $d->description, ...$extra,
        ];
    }

    private function when(bool $condition, callable $fields): array
    {
        return $condition ? $fields() : [];
    }
}
