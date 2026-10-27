<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\ConfigGroup;
use App\Models\Catalog\ConfigOption;
use App\Models\Catalog\Itinerary;
use App\Models\Catalog\ItineraryDay;
use App\Models\Catalog\ItineraryItem;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductB2bRate;
use App\Models\Catalog\ProductChannel;
use App\Models\Catalog\ProductFaq;
use App\Models\Catalog\ProductInclusion;
use App\Models\Catalog\ProductItem;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductPolicy;
use App\Models\Catalog\ProductPromotion;
use App\Models\Catalog\ProductRelated;
use App\Models\Catalog\ProductRequirement;
use App\Models\Catalog\Rate;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Catalog\Links\CatalogLinks;
use App\Services\Crm\CrmClock;
use App\Services\SequenceService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Sub-recursos del Product 360 (§6.2): componentes, itinerario, políticas, inclusiones, requisitos,
 * relacionados, B2B, promociones, personalización, contenido multimedia, FAQ y canales.
 * Cada cambio queda auditado sobre el producto (§9).
 */
class ProductParts
{
    public const ITEM_TYPES = ['Traslado', 'Transporte', 'Tren', 'Hotel', 'Alimentación', 'Guía', 'Actividad', 'Entrada', 'Libre', 'Otro'];

    public const REQUIREMENT_CATEGORIES = ['Condición física', 'Documentos', 'Nacionalidad', 'Restricción', 'Recomendación', 'Equipamiento', 'Requisito especial'];

    public const RELATIONS = ['Complementario', 'Alternativo', 'Upgrade', 'Cross-sell', 'Upsell'];

    public const PROMO_CHANNELS = ['Todos', 'Directo', 'E-commerce', 'B2B', 'Corporativo'];

    public const MAX_PROMO_PCT = 60;

    public function __construct(
        private readonly CrmClock $clock,
        private readonly CatalogLinks $links,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    // ── Componentes (CAT-BR-10/11, PRD-BR-006/007) ────────────────────────────

    public function addComponent(Product $product, array $data, User $actor, bool $audit = true): ProductItem
    {
        $service = null;
        $component = null;
        if (! empty($data['service_id'])) {
            $service = SupplierService::with('provider')->find($data['service_id']) ?? throw ApiException::invalid('service_id', 'Servicio inexistente.');
            if (! $service->isSelectable()) {
                throw ApiException::unprocessable('PRD-BR-007', "El servicio {$service->ref} no está activo/vigente o su proveedor no está activo (PRD-BR-007).");
            }
        } elseif (! empty($data['component_product_id'])) {
            $component = Product::find($data['component_product_id']) ?? throw ApiException::invalid('component_product_id', 'Producto inexistente.');
            if ($component->id === $product->id) {
                throw ApiException::invalid('component_product_id', 'Un producto no puede ser componente de sí mismo.');
            }
            if ($component->status !== Product::ACTIVE) {
                throw ApiException::unprocessable('PRD-BR-002', "El producto {$component->ref} no está activo: no puede usarse como componente.");
            }
        } else {
            throw ApiException::invalid('service_id', 'Seleccione un servicio o un producto.');
        }
        $quantity = (float) ($data['quantity'] ?? 1);
        if ($quantity < 1) {
            throw ApiException::invalid('quantity', 'La cantidad debe ser al menos 1.');
        }

        return DB::transaction(function () use ($product, $data, $service, $component, $quantity, $actor, $audit) {
            $item = ProductItem::create([
                'product_id' => $product->id,
                'service_id' => $service?->id,
                'component_product_id' => $component?->id,
                'provider_id' => $service?->provider_id,
                'quantity' => $quantity,
                'unit' => $data['unit'] ?? $service?->unit ?? 'unidad',
                'sort_order' => (int) ProductItem::where('product_id', $product->id)->max('sort_order') + 1,
                'is_required' => (bool) ($data['is_required'] ?? true),
                'is_included' => (bool) ($data['is_included'] ?? true),
                'unit_cost_override' => $data['unit_cost_override'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            if ($service && ! $product->main_provider_id) {
                $product->forceFill(['main_provider_id' => $service->provider_id])->save();
                if ($audit) {
                    $this->audit->log('Producto', $product->ref, 'cambio_proveedor', null, "{$service->provider->name} — primer servicio agregado (CAT-BR-11)", actor: $actor);
                }
            }
            if ($audit) {
                $this->audit->log('Producto', $product->ref, 'cambio_componentes', null, 'Agregado '.($service?->ref ?? $component?->ref).' · '.($service?->name ?? $component?->name), actor: $actor);
            }

            return $item;
        });
    }

    public function updateComponent(Product $product, ProductItem $item, array $data, User $actor): ProductItem
    {
        $data = Arr::only($data, ['quantity', 'unit', 'is_required', 'is_included', 'unit_cost_override', 'notes']);
        if (isset($data['quantity']) && (float) $data['quantity'] < 1) {
            throw ApiException::invalid('quantity', 'La cantidad debe ser al menos 1.');
        }

        return DB::transaction(function () use ($product, $item, $data, $actor) {
            $item->fill($data);
            $changed = array_keys($item->getDirty());
            if ($changed) {
                $item->save();
                $this->audit->log('Producto', $product->ref, 'cambio_componentes', null, 'Editado componente #'.$item->sort_order.': '.implode(', ', $changed), actor: $actor);
            }

            return $item;
        });
    }

    public function removeComponent(Product $product, ProductItem $item, User $actor): void
    {
        DB::transaction(function () use ($product, $item, $actor) {
            $label = $item->service?->ref ?? $item->componentProduct?->ref;
            $item->delete();
            // Actividades vinculadas al servicio quitado quedan sin vínculo (CAT-BR-12).
            if ($item->service_id && ! ProductItem::where('product_id', $product->id)->where('service_id', $item->service_id)->exists()) {
                ItineraryItem::where('service_id', $item->service_id)->whereIn('day_id', $this->dayIds($product))->update(['service_id' => null]);
            }
            $this->renumber($product);
            $this->audit->log('Producto', $product->ref, 'cambio_componentes', $label, 'Quitado', actor: $actor);
        });
    }

    /** @param list<string> $ids nuevo orden completo */
    public function reorderComponents(Product $product, array $ids, User $actor): void
    {
        $current = ProductItem::where('product_id', $product->id)->pluck('id')->all();
        if (count($ids) !== count($current) || array_diff($current, $ids) !== []) {
            throw ApiException::invalid('ids', 'El orden debe incluir todos los componentes del producto.');
        }
        DB::transaction(function () use ($product, $ids, $actor) {
            foreach ($ids as $i => $id) {
                ProductItem::whereKey($id)->update(['sort_order' => $i + 1]);
            }
            $this->audit->log('Producto', $product->ref, 'cambio_componentes', null, 'Reordenados', actor: $actor);
        });
    }

    private function renumber(Product $product): void
    {
        ProductItem::where('product_id', $product->id)->orderBy('sort_order')->get()->values()
            ->each(fn (ProductItem $item, int $i) => $item->sort_order === $i + 1 ?: $item->update(['sort_order' => $i + 1]));
    }

    // ── Itinerario (CAT-BR-12) ─────────────────────────────────────────────

    public function itinerary(Product $product): Itinerary
    {
        return Itinerary::firstOrCreate(['product_id' => $product->id, 'is_default' => true], ['name' => 'Principal']);
    }

    public function addDay(Product $product, array $data, User $actor, bool $audit = true): ItineraryDay
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw ApiException::invalid('title', 'El título del día es obligatorio.');
        }
        $itinerary = $this->itinerary($product);
        $day = ItineraryDay::create(['itinerary_id' => $itinerary->id, 'day_number' => (int) ItineraryDay::where('itinerary_id', $itinerary->id)->max('day_number') + 1, 'title' => $title]);
        if ($audit) {
            $this->audit->log('Producto', $product->ref, 'cambio_itinerario', null, "Día {$day->day_number}: {$title}", actor: $actor);
        }

        return $day;
    }

    public function updateDay(Product $product, ItineraryDay $day, array $data, User $actor): ItineraryDay
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw ApiException::invalid('title', 'El título del día es obligatorio.');
        }
        $before = $day->title;
        $day->update(['title' => $title]);
        $this->audit->log('Producto', $product->ref, 'cambio_itinerario', "Día {$day->day_number}: {$before}", "Día {$day->day_number}: {$title}", actor: $actor);

        return $day;
    }

    /** Quitar un día renumera los siguientes (unique DEFERRABLE). */
    public function removeDay(Product $product, ItineraryDay $day, User $actor): void
    {
        DB::transaction(function () use ($product, $day, $actor) {
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            ItineraryItem::where('day_id', $day->id)->delete();
            $day->delete();
            ItineraryDay::where('itinerary_id', $day->itinerary_id)->where('day_number', '>', $day->day_number)
                ->update(['day_number' => DB::raw('day_number - 1')]);
            $this->audit->log('Producto', $product->ref, 'cambio_itinerario', "Día {$day->day_number}: {$day->title}", 'Quitado (días renumerados)', actor: $actor);
        });
    }

    public function addItem(Product $product, ItineraryDay $day, array $data, User $actor, bool $audit = true): ItineraryItem
    {
        $data = $this->itemData($product, $data);
        $item = ItineraryItem::create($data + ['day_id' => $day->id]);
        if ($audit) {
            $this->audit->log('Producto', $product->ref, 'cambio_itinerario', null, "Día {$day->day_number}: + {$item->activity}", actor: $actor);
        }

        return $item;
    }

    public function updateItem(Product $product, ItineraryDay $day, ItineraryItem $item, array $data, User $actor): ItineraryItem
    {
        $item->update($this->itemData($product, $data + $item->only(['activity', 'item_type', 'start_time', 'location', 'service_id', 'note', 'image_ref', 'cost', 'price'])));
        $this->audit->log('Producto', $product->ref, 'cambio_itinerario', null, "Día {$day->day_number}: editado {$item->activity}", actor: $actor);

        return $item;
    }

    public function removeItem(Product $product, ItineraryDay $day, ItineraryItem $item, User $actor): void
    {
        $item->delete();
        $this->audit->log('Producto', $product->ref, 'cambio_itinerario', "Día {$day->day_number}: {$item->activity}", 'Quitado', actor: $actor);
    }

    private function itemData(Product $product, array $data): array
    {
        $data = Arr::only($data, ['start_time', 'activity', 'item_type', 'location', 'service_id', 'note', 'image_ref', 'cost', 'price']);
        if (trim((string) ($data['activity'] ?? '')) === '') {
            throw ApiException::invalid('activity', 'La actividad es obligatoria.');
        }
        $data['item_type'] ??= 'Actividad';
        if (! in_array($data['item_type'], self::ITEM_TYPES, true)) {
            throw ApiException::invalid('item_type', 'Tipo de actividad inválido.');
        }
        if (($data['start_time'] ?? '') === '') {
            $data['start_time'] = null;
        }
        if (! empty($data['service_id']) && ! ProductItem::where('product_id', $product->id)->where('service_id', $data['service_id'])->exists()) {
            throw ApiException::invalid('service_id', 'El servicio vinculado debe ser un componente del producto.');
        }
        $data['service_id'] = ($data['service_id'] ?? null) ?: null;
        $data['cost'] = (float) ($data['cost'] ?? 0);
        $data['price'] = (float) ($data['price'] ?? 0);

        return $data;
    }

    private function dayIds(Product $product): array
    {
        return ItineraryDay::whereIn('itinerary_id', Itinerary::where('product_id', $product->id)->pluck('id'))->pluck('id')->all();
    }

    // ── Políticas, inclusiones, requisitos (CAT-BR-32/33/35) ───────────────────

    public function setPolicies(Product $product, array $data, User $actor): ProductPolicy
    {
        $fields = ['cancellation', 'modification', 'no_show', 'penalties', 'refund', 'date_change', 'advance_min', 'advance_max'];
        $data = Arr::only($data, $fields);
        foreach (['cancellation' => 'cancelación', 'modification' => 'modificación', 'no_show' => 'no-show', 'refund' => 'reembolso'] as $field => $label) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                throw ApiException::invalid($field, "La política de {$label} es obligatoria.");
            }
        }

        return DB::transaction(function () use ($product, $data, $actor) {
            $policy = ProductPolicy::firstOrNew(['product_id' => $product->id]);
            $policy->fill($data);
            $changed = array_keys($policy->getDirty());
            $policy->save();
            if ($changed) {
                $this->audit->log('Producto', $product->ref, 'cambio_politicas', null, implode(', ', $changed), actor: $actor);
            }

            return $policy;
        });
    }

    public function addInclusion(Product $product, string $kind, string $text, User $actor): ProductInclusion
    {
        $text = trim($text);
        if (! in_array($kind, ['include', 'exclude'], true)) {
            throw ApiException::invalid('kind', 'Tipo inválido.');
        }
        if ($text === '') {
            throw ApiException::invalid('text', 'El texto es obligatorio.');
        }
        if (ProductInclusion::where('product_id', $product->id)->where('kind', $kind)->whereRaw('lower(text) = ?', [mb_strtolower($text)])->exists()) {
            throw ApiException::invalid('text', 'Ya está en la lista.');
        }
        $row = ProductInclusion::create(['product_id' => $product->id, 'kind' => $kind, 'text' => $text, 'sort_order' => (int) ProductInclusion::where('product_id', $product->id)->max('sort_order') + 1]);
        $this->audit->log('Producto', $product->ref, 'modificar', null, ($kind === 'include' ? 'Incluye' : 'No incluye').": + {$text}", ['fields' => ['inclusions']], actor: $actor);

        return $row;
    }

    public function removeInclusion(Product $product, ProductInclusion $row, User $actor): void
    {
        $row->delete();
        $this->audit->log('Producto', $product->ref, 'modificar', ($row->kind === 'include' ? 'Incluye' : 'No incluye').": {$row->text}", 'Quitado', ['fields' => ['inclusions']], actor: $actor);
    }

    public function addRequirement(Product $product, array $data, User $actor, bool $audit = true): ProductRequirement
    {
        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '') {
            throw ApiException::invalid('text', 'El detalle del requisito es obligatorio.');
        }
        $category = $data['category'] ?? null;
        if ($category !== null && $category !== '' && ! in_array($category, self::REQUIREMENT_CATEGORIES, true)) {
            throw ApiException::invalid('category', 'Categoría de requisito inválida.');
        }
        $row = ProductRequirement::create(['product_id' => $product->id, 'category' => $category ?: null, 'text' => $text]);
        if ($audit) {
            $this->audit->log('Producto', $product->ref, 'modificar', null, 'Requisito: + '.($category ? "{$category}: " : '').$text, ['fields' => ['requirements']], actor: $actor);
        }

        return $row;
    }

    public function removeRequirement(Product $product, ProductRequirement $row, User $actor): void
    {
        $row->delete();
        $this->audit->log('Producto', $product->ref, 'modificar', 'Requisito: '.$row->text, 'Quitado', ['fields' => ['requirements']], actor: $actor);
    }

    // ── Relacionados (CAT-BR-34) ─────────────────────────────────────────

    public function relate(Product $product, string $relatedId, string $relation, User $actor): ProductRelated
    {
        $related = Product::find($relatedId) ?? throw ApiException::invalid('related_product_id', 'Producto inexistente.');
        if ($related->id === $product->id) {
            throw ApiException::invalid('related_product_id', 'No puede relacionar el producto consigo mismo.');
        }
        if (! in_array($relation, self::RELATIONS, true)) {
            throw ApiException::invalid('relation', 'Tipo de relación inválido.');
        }
        if (ProductRelated::where('product_id', $product->id)->where('related_product_id', $related->id)->exists()) {
            throw ApiException::invalid('related_product_id', 'El producto ya está relacionado.');
        }
        $row = ProductRelated::create(['product_id' => $product->id, 'related_product_id' => $related->id, 'relation' => $relation]);
        $this->audit->log('Producto', $product->ref, 'relacionar', null, "{$relation}: {$related->ref}", actor: $actor);

        return $row;
    }

    public function unrelate(Product $product, ProductRelated $row, User $actor): void
    {
        $row->delete();
        $this->audit->log('Producto', $product->ref, 'desrelacionar', "{$row->relation}: ".($row->related?->ref ?? $row->related_product_id), null, actor: $actor);
    }

    // ── B2B por agencia (CAT-BR-26) ───────────────────────────────────────

    /** @return array{rate: ProductB2bRate, warnings: list<string>} */
    public function saveB2bRate(Product $product, array $data, User $actor, ?ProductB2bRate $current = null): array
    {
        $agencyId = $current?->agency_id ?? ($data['agency_id'] ?? null);
        $agency = $agencyId ? $this->links->agency($agencyId) : null;
        if (! $agency) {
            throw ApiException::invalid('agency_id', 'Agencia B2B inexistente.');
        }
        if (($agency['status'] ?? null) !== 'activa') {
            throw ApiException::unprocessable('CAT-BR-26', "La agencia {$agency['name']} no está activa.");
        }
        if (! $current && ProductB2bRate::where('product_id', $product->id)->where('agency_id', $agencyId)->exists()) {
            throw ApiException::invalid('agency_id', 'La agencia ya tiene una lista de precios para este producto.');
        }
        if (empty($data['valid_to'])) {
            throw ApiException::invalid('valid_to', 'Indique la vigencia.');
        }
        $price = (float) $data['price'];
        $public = $product->rates()->get()->first(fn (Rate $r) => $r->effectiveStatus() === 'vigente' && ! $r->isNet());
        $warnings = [];
        if ($public && $price < (float) $public->cost) {
            throw ApiException::unprocessable('CAT-BR-26', "El precio B2B no puede ser menor que el costo de la tarifa pública ({$public->currency} {$public->cost}).");
        }
        // B2B-BR-10 (spec 08 §11-7): la misma regla en Catálogo y Canales; el neto no supera el público.
        if ($public && $price > (float) $public->price) {
            throw ApiException::unprocessable('B2B-BR-10', "El precio B2B no puede superar el precio público ({$public->currency} {$public->price}).");
        }
        if (! empty($data['is_credit']) && (float) ($agency['credit_line'] ?? 0) <= 0) {
            throw ApiException::unprocessable('CAT-BR-26', 'La venta a crédito exige una línea de crédito aprobada para la agencia.');
        }
        if (! $product->is_b2b || ! ProductChannel::where('product_id', $product->id)->where('channel', 'b2b')->where('is_published', true)->exists()) {
            $warnings[] = 'El producto no está publicado en el canal B2B: la lista no se ofrecerá hasta publicarlo.';
        }

        return DB::transaction(function () use ($product, $data, $agency, $public, $current, $warnings, $actor, $price) {
            $fields = Arr::only($data, ['price_list', 'commission_pct', 'is_credit', 'valid_to', 'conditions', 'restrictions']) + ['price' => $price];
            // §3.3: la lista se copia de la agencia al asignar.
            if (! empty($agency['price_list_id'])) {
                $fields['price_list_id'] = $agency['price_list_id'];
                $fields['price_list'] = $agency['price_list'] ?? ($fields['price_list'] ?? null);
            }
            if (! $product->is_b2b) {
                $product->forceFill(['is_b2b' => true])->save();
            }
            if ($current) {
                $before = "{$current->currency} {$current->price}";
                $current->update($fields);
                $this->audit->log('Producto', $product->ref, 'cambio_precio_b2b', $before, "{$agency['name']}: {$current->currency} {$current->price}", actor: $actor);

                return ['rate' => $current, 'warnings' => $warnings];
            }
            $row = ProductB2bRate::create($fields + ['product_id' => $product->id, 'agency_id' => $agency['id'], 'currency' => $public?->currency ?? 'USD', 'commission_pct' => $data['commission_pct'] ?? 10]);
            $this->audit->log('Producto', $product->ref, 'crear_precio_b2b', null, "{$agency['name']}: {$row->currency} {$row->price}", actor: $actor);

            return ['rate' => $row, 'warnings' => $warnings];
        });
    }

    public function removeB2bRate(Product $product, ProductB2bRate $row, User $actor): void
    {
        $row->delete();
        $name = $this->links->agency($row->agency_id)['name'] ?? $row->agency_id;
        $this->audit->log('Producto', $product->ref, 'eliminar_precio_b2b', "{$name}: {$row->currency} {$row->price}", null, actor: $actor);
    }

    // ── Promociones (CAT-BR-27) ──────────────────────────────────────────

    public function addPromotion(Product $product, array $data, User $actor): ProductPromotion
    {
        $data = Arr::only($data, ['name', 'kind', 'value', 'channel', 'promo_code', 'date_from', 'date_to', 'min_pax']);
        if (empty($data['date_from']) || empty($data['date_to'])) {
            throw ApiException::invalid(empty($data['date_from']) ? 'date_from' : 'date_to', 'Indique la vigencia de la promoción.');
        }
        if ((string) $data['date_to'] < (string) $data['date_from']) {
            throw ApiException::invalid('date_to', 'La fecha “hasta” debe ser igual o posterior a “desde”.');
        }
        if ((string) $data['date_to'] < $this->clock->today()->toDateString()) {
            throw ApiException::invalid('date_to', 'La promoción no puede terminar en el pasado.');
        }
        $value = (float) $data['value'];
        if (in_array($data['kind'], ProductPromotion::PERCENT_KINDS, true) && ($value <= 0 || $value >= self::MAX_PROMO_PCT)) {
            throw ApiException::invalid('value', 'Los descuentos porcentuales deben estar entre 0 y '.self::MAX_PROMO_PCT.'%.');
        }
        if ($value <= 0) {
            throw ApiException::invalid('value', 'El valor debe ser mayor que 0.');
        }
        $code = mb_strtoupper(trim((string) ($data['promo_code'] ?? '')));
        if ($data['kind'] === 'Código promocional' && $code === '') {
            throw ApiException::invalid('promo_code', 'Indique el código promocional.');
        }
        // §12-27: código único por tenant.
        if ($code !== '' && ProductPromotion::where('promo_code', $code)->exists()) {
            throw ApiException::invalid('promo_code', 'El código promocional ya está en uso.');
        }

        return DB::transaction(function () use ($product, $data, $code, $actor) {
            $promo = ProductPromotion::create(['product_id' => $product->id, 'ref' => $this->sequences->next('catalog_promotion'), 'promo_code' => $code ?: null,
                'channel' => $data['channel'] ?? 'Todos', 'status' => 'activa'] + Arr::except($data, ['promo_code', 'channel']));
            $this->audit->log('Producto', $product->ref, 'crear_promocion', null, "{$promo->ref} {$promo->name} · {$promo->kind} {$promo->value}", actor: $actor);

            return $promo;
        });
    }

    public function setPromotionStatus(Product $product, ProductPromotion $promo, bool $active, User $actor): ProductPromotion
    {
        if ($promo->effectiveStatus() === 'vencida') {
            throw ApiException::unprocessable('CAT-BR-27', 'Una promoción vencida no se puede pausar ni activar.');
        }
        $before = $promo->status;
        $promo->update(['status' => $active ? 'activa' : 'inactiva']);
        $this->audit->log('Producto', $product->ref, 'cambio_promocion', "{$promo->ref} {$before}", "{$promo->ref} {$promo->status}", actor: $actor);

        return $promo;
    }

    public function removePromotion(Product $product, ProductPromotion $promo, User $actor): void
    {
        $promo->delete();
        $this->audit->log('Producto', $product->ref, 'eliminar_promocion', "{$promo->ref} {$promo->name}", null, actor: $actor);
    }

    // ── Personalización (CAT-BR-30) ──────────────────────────────────────

    /** @param list<array{label: string, price_delta: mixed}> $options */
    public function addConfigGroup(Product $product, string $name, array $options, User $actor): ConfigGroup
    {
        $name = trim($name);
        if ($name === '') {
            throw ApiException::invalid('name', 'El nombre del grupo es obligatorio.');
        }
        if (ConfigGroup::where('product_id', $product->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ApiException::invalid('name', 'Ya existe un grupo con ese nombre.');
        }
        $options = array_values(array_filter($options, fn ($o) => trim((string) ($o['label'] ?? '')) !== ''));
        if (count($options) < 2) {
            throw ApiException::invalid('options', 'Cada grupo necesita al menos 2 opciones “Etiqueta | diferencia”.');
        }

        return DB::transaction(function () use ($product, $name, $options, $actor) {
            $group = ConfigGroup::create(['product_id' => $product->id, 'name' => $name, 'sort_order' => (int) ConfigGroup::where('product_id', $product->id)->max('sort_order') + 1]);
            foreach ($options as $i => $option) {
                ConfigOption::create(['group_id' => $group->id, 'label' => trim($option['label']), 'price_delta' => (float) ($option['price_delta'] ?? 0), 'sort_order' => $i + 1]);
            }
            $this->audit->log('Producto', $product->ref, 'cambio_personalizacion', null, "Grupo {$name}: ".collect($options)->map(fn ($o) => "{$o['label']} ({$o['price_delta']})")->implode(', '), actor: $actor);

            return $group;
        });
    }

    public function removeConfigGroup(Product $product, ConfigGroup $group, User $actor): void
    {
        DB::transaction(function () use ($product, $group, $actor) {
            ConfigOption::where('group_id', $group->id)->delete();
            $group->delete();
            $this->audit->log('Producto', $product->ref, 'cambio_personalizacion', "Grupo {$group->name}", 'Quitado', actor: $actor);
        });
    }

    // ── Multimedia y FAQ (CAT-BR-29) ──────────────────────────────────────

    public function addMedia(Product $product, array $data, User $actor): ProductMedia
    {
        if (trim((string) ($data['alt_text'] ?? '')) === '') {
            throw ApiException::invalid('alt_text', 'El texto alternativo es obligatorio.');
        }

        return DB::transaction(function () use ($product, $data, $actor) {
            $first = ! ProductMedia::where('product_id', $product->id)->exists();
            $main = $first || ! empty($data['is_main']);
            if ($main) {
                ProductMedia::where('product_id', $product->id)->update(['is_main' => false]);
            }
            $media = ProductMedia::create(['product_id' => $product->id, 'kind' => 'image', 'storage_path' => $data['storage_path'], 'alt_text' => trim($data['alt_text']),
                'is_main' => $main, 'sort_order' => (int) ProductMedia::where('product_id', $product->id)->max('sort_order') + 1]);
            $this->audit->log('Producto', $product->ref, 'cambio_contenido', null, 'Imagen agregada: '.$media->alt_text.($main ? ' (principal)' : ''), actor: $actor);

            return $media;
        });
    }

    public function setMainMedia(Product $product, ProductMedia $media, User $actor): void
    {
        DB::transaction(function () use ($product, $media, $actor) {
            ProductMedia::where('product_id', $product->id)->update(['is_main' => false]);
            $media->update(['is_main' => true]);
            $this->audit->log('Producto', $product->ref, 'cambio_contenido', null, "Imagen principal: {$media->alt_text}", actor: $actor);
        });
    }

    public function removeMedia(Product $product, ProductMedia $media, User $actor): void
    {
        if ($media->is_main) {
            throw ApiException::unprocessable('CAT-BR-29', 'La imagen principal no se puede quitar: marque otra como principal primero.');
        }
        $media->delete();
        $this->audit->log('Producto', $product->ref, 'cambio_contenido', "Imagen: {$media->alt_text}", 'Quitada', actor: $actor);
    }

    public function addFaq(Product $product, array $data, User $actor): ProductFaq
    {
        if (trim((string) ($data['question'] ?? '')) === '' || trim((string) ($data['answer'] ?? '')) === '') {
            throw ApiException::invalid('question', 'Pregunta y respuesta son obligatorias.');
        }
        $faq = ProductFaq::create(['product_id' => $product->id, 'question' => trim($data['question']), 'answer' => trim($data['answer']),
            'sort_order' => (int) ProductFaq::where('product_id', $product->id)->max('sort_order') + 1]);
        $this->audit->log('Producto', $product->ref, 'cambio_contenido', null, "FAQ: {$faq->question}", actor: $actor);

        return $faq;
    }

    public function removeFaq(Product $product, ProductFaq $faq, User $actor): void
    {
        $faq->delete();
        $this->audit->log('Producto', $product->ref, 'cambio_contenido', "FAQ: {$faq->question}", 'Quitada', actor: $actor);
    }

    // ── Canales (CAT-BR-28) ──────────────────────────────────────────────

    /** Flags de la ficha sincronizados con su canal. */
    public const CHANNEL_FLAGS = ['b2b' => 'is_b2b', 'ecommerce' => 'is_ecommerce', 'quotes' => 'is_quoteable'];

    public function setChannel(Product $product, string $channel, bool $published, User $actor): ProductChannel
    {
        if (! array_key_exists($channel, ProductChannel::CHANNELS)) {
            throw ApiException::notFound();
        }
        if ($channel === 'erp' && ! $published) {
            throw ApiException::unprocessable('CAT-BR-28', 'El canal ERP no se puede despublicar.');
        }
        if ($published && $product->status !== Product::ACTIVE) {
            throw ApiException::unprocessable('PRD-BR-002', 'PRD-BR-002: active el producto antes de publicarlo en '.ProductChannel::CHANNELS[$channel].'.');
        }

        return DB::transaction(function () use ($product, $channel, $published, $actor) {
            $row = ProductChannel::firstOrNew(['product_id' => $product->id, 'channel' => $channel]);
            if ($row->exists && $row->is_published === $published) {
                return $row;
            }
            $row->fill(['is_published' => $published, 'is_requested' => $published || $row->is_requested, 'published_at' => $published ? now() : null, 'published_by' => $published ? $actor->id : null])->save();
            if ($flag = self::CHANNEL_FLAGS[$channel] ?? null) {
                $product->forceFill([$flag => $published])->save();
            }
            $this->audit->log('Producto', $product->ref, $published ? 'publicacion' : 'despublicacion', null, ProductChannel::CHANNELS[$channel], actor: $actor);

            return $row;
        });
    }
}
