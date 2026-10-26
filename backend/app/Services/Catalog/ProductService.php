<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\Category;
use App\Models\Catalog\Destination;
use App\Models\Catalog\Itinerary;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductChannel;
use App\Models\Catalog\ProductDestination;
use App\Models\Catalog\ProductInclusion;
use App\Models\Catalog\ProductPolicy;
use App\Models\Catalog\ProductSeason;
use App\Models\Catalog\ProductType;
use App\Models\Catalog\Provider;
use App\Models\Catalog\Rate;
use App\Models\Catalog\Season;
use App\Models\Catalog\Subcategory;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ficha maestra del producto (Spec PRD §5, CAT-BR-01..04, 09, 11, 16, 19, 29, 41): alta del asistente
 * de 10 pasos en un solo payload, edición, duplicado, proveedor principal, disponibilidad, markup y contenido.
 */
class ProductService
{
    public const MASTER = [
        'code', 'sku', 'name', 'internal_name', 'product_type_id', 'category_id', 'subcategory_id', 'primary_destination_id',
        'short_description', 'long_description', 'duration_value', 'duration_unit', 'modality',
        'minimum_passengers', 'maximum_passengers', 'minimum_age', 'maximum_age', 'booking_advance_min_days', 'booking_advance_max_days',
        'is_bookable', 'is_quoteable', 'is_b2b', 'is_ecommerce', 'is_featured',
    ];

    public const AVAILABILITY = ['availability_model', 'availability_capacity', 'availability_source'];

    public const CONTENT = ['short_description', 'long_description', 'features', 'video_url', 'seo_title', 'seo_description', 'slug'];

    /** Políticas por defecto del prototipo (`defaultPolicies()`). */
    public const DEFAULT_POLICIES = [
        'cancellation' => 'Sin penalidad hasta 15 días antes del servicio; 50 % entre 14 y 7 días; 100 % con menos de 7 días.',
        'modification' => 'Cambios de fecha sin costo hasta 7 días antes, sujetos a disponibilidad.',
        'no_show' => 'No show: se cobra el 100 % del servicio.',
        'penalties' => 'Penalidades de proveedores (trenes, entradas) según sus condiciones.',
        'refund' => 'Reembolsos en un plazo de 15 días hábiles por el mismo medio de pago.',
        'date_change' => 'Un cambio de fecha permitido sin costo.',
        'advance_min' => '48 horas',
        'advance_max' => '365 días',
    ];

    /** "No incluye" propuesto por el asistente (Spec PRD §11). */
    public const DEFAULT_EXCLUSIONS = ['Propinas', 'Bebidas', 'Gastos personales', 'Servicios no especificados'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly ProductVersioning $versions,
        private readonly ProductParts $parts,
        private readonly RateService $rates,
    ) {}

    /** Alta del asistente (PROC-014): siempre DRAFT, versión 0.1, ERP publicado (CAT-BR-04). */
    public function create(array $data, User $actor): Product
    {
        $master = Arr::only($data, self::MASTER);
        $this->validateMaster($master, null);
        $master['code'] = trim($master['code']);
        $master['sku'] = trim((string) ($master['sku'] ?? '')) ?: $this->defaultSku($master['code']);
        $master['internal_name'] = trim((string) ($master['internal_name'] ?? '')) ?: $master['name'];
        if (! empty($data['main_provider_id'])) {
            $provider = Provider::find($data['main_provider_id']);
            if (! $provider?->isUsable()) {
                throw ApiException::unprocessable('PRD-BR-007', 'El proveedor principal no está activo o su contrato está vencido.');
            }
            $master['main_provider_id'] = $provider->id;
        }

        return DB::transaction(function () use ($data, $master, $actor) {
            $product = Product::create($master + Arr::only($data, self::AVAILABILITY) + Arr::only($data, ['features', 'video_url', 'seo_title', 'seo_description']) + [
                'ref' => $this->sequences->next('catalog_product'),
                'status' => Product::DRAFT,
                'markup_pct' => $data['markup_pct'] ?? 25,
                'slug' => isset($data['slug']) && $data['slug'] !== '' ? $this->slug($data['slug'], null) : null,
            ]);

            foreach (array_unique($data['secondary_destination_ids'] ?? []) as $destinationId) {
                $this->addSecondary($product, $destinationId);
            }
            $seen = [];
            foreach ($data['components'] ?? [] as $component) {
                $key = ($component['service_id'] ?? '').'|'.($component['component_product_id'] ?? '');
                if (isset($seen[$key])) {
                    throw ApiException::invalid('components', 'El asistente no admite el mismo componente dos veces (CAT-BR-10).');
                }
                $seen[$key] = true;
                $this->parts->addComponent($product, $component, $actor, false);
            }
            Itinerary::create(['product_id' => $product->id, 'name' => 'Principal', 'is_default' => true]);
            foreach ($data['itinerary'] ?? [] as $day) {
                $created = $this->parts->addDay($product, ['title' => $day['title']], $actor, false);
                foreach ($day['items'] ?? [] as $item) {
                    $this->parts->addItem($product, $created, $item, $actor, false);
                }
            }
            foreach ($data['rates'] ?? [] as $rate) {
                $this->rates->create($product, $rate, $actor, false);
            }
            foreach (array_unique($data['season_ids'] ?? []) as $seasonId) {
                $this->associateSeason($product, $seasonId, $actor, false);
            }
            ProductPolicy::create(['product_id' => $product->id] + array_merge(self::DEFAULT_POLICIES, array_filter(Arr::only($data['policies'] ?? [], array_keys(self::DEFAULT_POLICIES)), fn ($v) => $v !== null && $v !== '')));
            $includes = $data['inclusions'] ?? $product->items()->with('service', 'componentProduct')->get()->where('is_included', true)
                ->map(fn ($i) => $i->service?->name ?? $i->componentProduct?->name)->filter()->all();
            $excludes = $data['exclusions'] ?? self::DEFAULT_EXCLUSIONS;
            $order = 0;
            foreach (['include' => $includes, 'exclude' => $excludes] as $kind => $texts) {
                foreach (array_unique(array_filter(array_map('trim', $texts))) as $text) {
                    ProductInclusion::create(['product_id' => $product->id, 'kind' => $kind, 'text' => Str::limit($text, 200, ''), 'sort_order' => ++$order]);
                }
            }
            foreach ($data['requirements'] ?? [] as $requirement) {
                $this->parts->addRequirement($product, $requirement, $actor, false);
            }
            $requested = array_unique([...($data['channels'] ?? []), 'erp']);
            foreach (array_keys(ProductChannel::CHANNELS) as $channel) {
                ProductChannel::create([
                    'product_id' => $product->id, 'channel' => $channel,
                    'is_published' => $channel === 'erp', 'is_requested' => in_array($channel, $requested, true),
                    'published_at' => $channel === 'erp' ? now() : null, 'published_by' => $channel === 'erp' ? $actor->id : null,
                ]);
            }

            $this->versions->initial($product, 'Creación del producto');
            $this->audit->log('Producto', $product->ref, 'crear', null, "{$product->code} · {$product->name} · DRAFT", actor: $actor);

            return $product->fresh();
        });
    }

    /** PATCH de la ficha (If-Match en el controlador). Cambio de proveedor principal va aparte. */
    public function update(Product $product, array $data, User $actor, ?string $reason = null): Product
    {
        $data = Arr::only($data, [...self::MASTER, 'main_provider_id']);
        $this->validateMaster($data + $product->only(self::MASTER), $product);

        return DB::transaction(function () use ($product, $data, $actor, $reason) {
            $providerChange = array_key_exists('main_provider_id', $data) && $data['main_provider_id'] !== $product->main_provider_id;
            if ($providerChange) {
                $this->setMainProvider($product, $data['main_provider_id'], $reason ?: 'Edición de ficha', $actor);
            }
            unset($data['main_provider_id']);
            if (isset($data['code'])) {
                $data['code'] = trim($data['code']);
            }
            $product->fill($data);
            $changed = array_keys(Arr::except($product->getDirty(), ['lock_version']));
            if ($changed === []) {
                return $product;
            }
            $product->save();
            $this->audit->log('Producto', $product->ref, 'modificar', null, implode(', ', $changed), ['fields' => $changed], actor: $actor);

            return $product;
        });
    }

    /** CAT-BR-11: proveedor principal activo con contrato vigente, distinto del actual y con motivo. */
    public function setMainProvider(Product $product, ?string $providerId, ?string $reason, User $actor): Product
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ApiException::invalid('reason', 'Indique el motivo del cambio de proveedor.');
        }
        if ($providerId === $product->main_provider_id) {
            throw ApiException::invalid('provider_id', 'Seleccione un proveedor distinto del actual.');
        }
        $provider = Provider::find($providerId) ?? throw ApiException::invalid('provider_id', 'Proveedor inexistente.');
        if (! $provider->isUsable()) {
            throw ApiException::unprocessable('PRD-BR-007', "El proveedor {$provider->name} no está activo o su contrato está vencido.");
        }

        return DB::transaction(function () use ($product, $provider, $reason, $actor) {
            $before = $product->mainProvider?->name;
            $product->forceFill(['main_provider_id' => $provider->id])->save();
            $this->audit->log('Producto', $product->ref, 'cambio_proveedor', $before, "{$provider->name} — {$reason}", actor: $actor);

            return $product->setRelation('mainProvider', $provider);
        });
    }

    /** CAT-BR-09: copia DRAFT sin salidas ni promociones; tarifas con nuevo ref; versión 0.1. */
    public function duplicate(Product $source, User $actor): Product
    {
        $source->load(['items', 'itinerary.days.items', 'rates', 'policies', 'inclusions', 'requirements', 'related', 'b2bRates', 'faqs', 'configGroups.options', 'secondaryDestinations', 'seasons', 'channels']);

        return DB::transaction(function () use ($source, $actor) {
            $n = 1;
            do {
                $code = $source->code.'-C'.str_pad((string) $n++, 2, '0', STR_PAD_LEFT);
            } while (Product::withTrashed()->whereRaw('lower(code) = ?', [mb_strtolower($code)])->exists());

            $copy = $source->replicate(['ref', 'status', 'current_version_id', 'lock_version', 'slug', 'created_by', 'updated_by']);
            $copy->fill([
                'ref' => $this->sequences->next('catalog_product'),
                'code' => $code,
                'sku' => Str::limit($source->sku.'-C', 40, ''),
                'name' => Str::limit($source->name.' (copia)', 160, ''),
                'internal_name' => Str::limit($source->internal_name.' (copia)', 160, ''),
                'status' => Product::DRAFT,
                'slug' => null,
            ])->save();

            $clone = function ($rows, array $override) {
                foreach ($rows as $row) {
                    $row->replicate(['created_by', 'updated_by'])->fill($override)->save();
                }
            };
            $clone($source->secondaryDestinations, ['product_id' => $copy->id]);
            $clone($source->items, ['product_id' => $copy->id]);
            $clone($source->inclusions, ['product_id' => $copy->id]);
            $clone($source->requirements, ['product_id' => $copy->id]);
            $clone($source->related, ['product_id' => $copy->id]);
            $clone($source->b2bRates, ['product_id' => $copy->id]);
            $clone($source->faqs, ['product_id' => $copy->id]);
            $clone($source->seasons, ['product_id' => $copy->id]);
            if ($source->policies) {
                $clone([$source->policies], ['product_id' => $copy->id]);
            }
            foreach ($source->rates as $rate) {
                $rate->replicate(['ref', 'lock_version', 'created_by', 'updated_by'])->fill(['product_id' => $copy->id, 'ref' => $this->sequences->next('catalog_rate')])->save();
            }
            $itinerary = Itinerary::create(['product_id' => $copy->id, 'name' => $source->itinerary?->name ?? 'Principal', 'is_default' => true]);
            foreach ($source->itinerary?->days ?? [] as $day) {
                $newDay = $day->replicate(['created_by', 'updated_by'])->fill(['itinerary_id' => $itinerary->id]);
                $newDay->save();
                $clone($day->items, ['day_id' => $newDay->id]);
            }
            foreach ($source->configGroups as $group) {
                $newGroup = $group->replicate(['created_by', 'updated_by'])->fill(['product_id' => $copy->id]);
                $newGroup->save();
                $clone($group->options, ['group_id' => $newGroup->id]);
            }
            foreach (array_keys(ProductChannel::CHANNELS) as $channel) {
                $was = $source->channels->firstWhere('channel', $channel);
                ProductChannel::create(['product_id' => $copy->id, 'channel' => $channel, 'is_published' => $channel === 'erp',
                    'is_requested' => $channel === 'erp' || (bool) $was?->is_requested, 'published_at' => $channel === 'erp' ? now() : null, 'published_by' => $channel === 'erp' ? $actor->id : null]);
            }

            $this->versions->initial($copy, "Duplicado de {$source->ref}");
            $this->audit->log('Producto', $copy->ref, 'crear', null, "Duplicado de {$source->ref} · {$copy->code}", actor: $actor);

            return $copy->fresh();
        });
    }

    /** CAT-BR-19: modelo, capacidad de referencia y fuente. */
    public function setAvailability(Product $product, array $data, User $actor): Product
    {
        $data = Arr::only($data, self::AVAILABILITY);
        if (isset($data['availability_capacity']) && $data['availability_capacity'] !== null && (int) $data['availability_capacity'] < 0) {
            throw ApiException::invalid('availability_capacity', 'La capacidad no puede ser negativa.');
        }

        return $this->simple($product, $data, 'cambio_disponibilidad', $actor);
    }

    /** CAT-BR-41: markup de referencia (permiso manage_rates en la ruta). */
    public function setMarkup(Product $product, float $markup, User $actor): Product
    {
        if ($markup < 0 || $markup > 500) {
            throw ApiException::invalid('markup_pct', 'El markup debe estar entre 0 y 500 %.');
        }

        return $this->simple($product, ['markup_pct' => $markup], 'cambio_markup', $actor);
    }

    /** CAT-BR-29: contenido comercial y slug normalizado único. */
    public function setContent(Product $product, array $data, User $actor): Product
    {
        $data = Arr::only($data, self::CONTENT);
        if (array_key_exists('slug', $data)) {
            $data['slug'] = $data['slug'] === null || $data['slug'] === '' ? null : $this->slug($data['slug'], $product);
        }
        if (array_key_exists('features', $data)) {
            $data['features'] = array_values(array_unique(array_filter(array_map(fn ($f) => trim((string) $f), (array) $data['features']))));
        }

        return $this->simple($product, $data, 'cambio_contenido', $actor);
    }

    /** CAT-BR-16: solo temporadas activas; idempotente. */
    public function associateSeason(Product $product, string $seasonId, User $actor, bool $audit = true): void
    {
        $season = Season::find($seasonId) ?? throw ApiException::invalid('season_id', 'Temporada inexistente.');
        if ($season->status !== 'activa') {
            throw ApiException::invalid('season_id', 'Solo se pueden asociar temporadas activas.');
        }
        $link = ProductSeason::firstOrCreate(['product_id' => $product->id, 'season_id' => $season->id]);
        if ($audit && $link->wasRecentlyCreated) {
            $this->audit->log('Producto', $product->ref, 'asociar_temporada', null, "{$season->ref} {$season->name}", actor: $actor);
        }
    }

    public function dissociateSeason(Product $product, string $seasonId, User $actor): void
    {
        $season = Season::withTrashed()->find($seasonId) ?? throw ApiException::notFound();
        if (Rate::where('product_id', $product->id)->where('season_id', $season->id)->exists()) {
            throw ApiException::rule('CAT-BR-16', "La temporada {$season->ref} la usan tarifas del producto: no se puede desasociar.");
        }
        DB::transaction(function () use ($product, $season, $actor) {
            ProductSeason::where('product_id', $product->id)->where('season_id', $season->id)->delete();
            $this->audit->log('Producto', $product->ref, 'desasociar_temporada', "{$season->ref} {$season->name}", null, actor: $actor);
        });
    }

    public function addSecondary(Product $product, string $destinationId): void
    {
        if ($destinationId === $product->primary_destination_id) {
            throw ApiException::invalid('secondary_destination_ids', 'El destino principal no puede ser también secundario.');
        }
        if (! Destination::whereKey($destinationId)->exists()) {
            throw ApiException::invalid('secondary_destination_ids', 'Destino secundario inexistente.');
        }
        ProductDestination::firstOrCreate(['product_id' => $product->id, 'destination_id' => $destinationId], ['role' => 'secondary']);
    }

    /** Reemplaza los destinos secundarios (edición de ficha). */
    public function syncSecondary(Product $product, array $ids, User $actor): void
    {
        DB::transaction(function () use ($product, $ids, $actor) {
            $ids = array_values(array_unique($ids));
            ProductDestination::where('product_id', $product->id)->whereNotIn('destination_id', $ids)->delete();
            foreach ($ids as $id) {
                $this->addSecondary($product, $id);
            }
            $this->audit->log('Producto', $product->ref, 'modificar', null, 'destinos secundarios', ['fields' => ['secondary_destinations']], actor: $actor);
        });
    }

    public function slug(string $value, ?Product $current): string
    {
        $slug = Str::slug(Str::ascii($value));
        if ($slug === '') {
            throw ApiException::invalid('slug', 'El slug no es válido.');
        }
        $taken = Product::withTrashed()->where('slug', $slug)->when($current, fn ($q) => $q->where('id', '<>', $current->id))->exists();
        if ($taken) {
            throw ApiException::invalid('slug', 'El slug ya está en uso por otro producto.');
        }

        return $slug;
    }

    /** CAT-BR-03: `<prefijo tenant>-<code>`. */
    public function defaultSku(string $code): string
    {
        $prefix = data_get($this->tenant->tenant()?->settings, 'catalog.sku_prefix') ?? Str::upper(Str::substr((string) $this->tenant->tenant()?->slug, 0, 3));

        return Str::limit(Str::upper($prefix).'-'.Str::upper($code), 40, '');
    }

    private function simple(Product $product, array $data, string $action, User $actor): Product
    {
        return DB::transaction(function () use ($product, $data, $action, $actor) {
            $product->fill($data);
            $changed = array_keys(Arr::except($product->getDirty(), ['lock_version']));
            if ($changed === []) {
                return $product;
            }
            $before = Arr::only($product->getOriginal(), $changed);
            $product->save();
            $this->audit->log('Producto', $product->ref, $action, self::flat($before), self::flat(Arr::only($product->getAttributes(), $changed)), ['fields' => $changed], actor: $actor);

            return $product;
        });
    }

    /** CAT-BR-01/02 y coherencia de referencias. */
    private function validateMaster(array $data, ?Product $current): void
    {
        if (isset($data['code'])) {
            $exists = Product::withTrashed()->whereRaw('lower(code) = ?', [mb_strtolower(trim($data['code']))])->when($current, fn ($q) => $q->where('id', '<>', $current->id))->exists();
            if ($exists) {
                throw ApiException::invalid('code', 'El código interno ya existe en el tenant.');
            }
        }
        foreach ([['minimum_passengers', 'maximum_passengers', 'pasajeros'], ['minimum_age', 'maximum_age', 'edad'], ['booking_advance_min_days', 'booking_advance_max_days', 'anticipación']] as [$min, $max, $label]) {
            if (isset($data[$min], $data[$max]) && (int) $data[$min] > (int) $data[$max]) {
                throw ApiException::invalid($max, "El máximo de {$label} debe ser mayor o igual que el mínimo.");
            }
        }
        if (isset($data['product_type_id']) && ! ProductType::whereKey($data['product_type_id'])->exists()) {
            throw ApiException::invalid('product_type_id', 'Seleccione un tipo de producto válido.');
        }
        if (isset($data['category_id']) && ! Category::whereKey($data['category_id'])->exists()) {
            throw ApiException::invalid('category_id', 'Seleccione una categoría válida.');
        }
        if (! empty($data['subcategory_id']) && ! Subcategory::whereKey($data['subcategory_id'])->where('category_id', $data['category_id'] ?? null)->exists()) {
            throw ApiException::invalid('subcategory_id', 'La subcategoría no pertenece a la categoría.');
        }
        if (isset($data['primary_destination_id']) && ! Destination::whereKey($data['primary_destination_id'])->exists()) {
            throw ApiException::invalid('primary_destination_id', 'Seleccione un destino válido.');
        }
    }

    private static function flat(array $values): string
    {
        return collect($values)->map(fn ($v, $k) => $k.': '.(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'sí' : 'no') : (string) $v)))->implode(' · ');
    }
}
