<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVersion;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Versiones del producto con snapshot inmutable (§4.2, CAT-BR-31, PRD-BR-008/009/017).
 * El snapshot se amplía con requisitos, B2B, promociones, canales y TC (§12-28, RT-15).
 */
class ProductVersioning
{
    public function __construct(
        private readonly CrmClock $clock,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /** Estado de versión que corresponde al estado del producto (§4.2). */
    public static function statusFor(string $productStatus): string
    {
        return match ($productStatus) {
            Product::DRAFT => 'borrador',
            Product::REVIEW => 'en_revision',
            default => 'vigente',
        };
    }

    /** Versión inicial 0.1 (CAT-BR-04, CAT-BR-09). */
    public function initial(Product $product, string $reason): ProductVersion
    {
        $version = ProductVersion::create([
            'product_id' => $product->id,
            'version' => '0.1',
            'status' => 'borrador',
            'valid_from' => $this->clock->today()->toDateString(),
            'reason' => $reason,
            'snapshot' => $this->snapshot($product),
        ]);
        $product->forceFill(['current_version_id' => $version->id])->saveQuietly();

        return $version;
    }

    /** CAT-BR-31: nueva versión menor/mayor; las anteriores pasan a histórica. */
    public function create(Product $product, string $kind, string $validFrom, string $reason, User $actor): ProductVersion
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ApiException::invalid('reason', 'Indique el motivo de la nueva versión.');
        }
        if (! in_array($kind, ['minor', 'major'], true)) {
            throw ApiException::invalid('kind', 'Tipo de versión inválido.');
        }

        return Cache::lock("catalog:product:{$product->id}:version", 10)->block(5, fn () => DB::transaction(function () use ($product, $kind, $validFrom, $reason, $actor) {
            $latest = ProductVersion::where('product_id', $product->id)->where('status', '<>', 'historica')->orderByDesc('created_at')->first()
                ?? ProductVersion::where('product_id', $product->id)->orderByDesc('created_at')->first();
            [$major, $minor] = array_map('intval', explode('.', $latest?->version ?? '0.0') + [0, 0]);
            $number = $kind === 'major' ? ($major + 1).'.0' : $major.'.'.($minor + 1);
            if (ProductVersion::where('product_id', $product->id)->where('version', $number)->exists()) {
                throw ApiException::invalid('kind', "La versión {$number} ya existe.");
            }
            // El trigger de inmutabilidad solo admite cambiar `status`.
            ProductVersion::where('product_id', $product->id)->where('status', '<>', 'historica')->update(['status' => 'historica', 'updated_at' => now()]);
            $version = ProductVersion::create([
                'product_id' => $product->id,
                'version' => $number,
                'status' => self::statusFor($product->status),
                'valid_from' => $validFrom,
                'reason' => $reason,
                'snapshot' => $this->snapshot($product),
            ]);
            $product->forceFill(['current_version_id' => $version->id])->saveQuietly();
            $this->audit->log('Producto', $product->ref, 'nueva_version', $latest?->version, "{$number} — {$reason}", actor: $actor);

            return $version;
        }));
    }

    /** Al cambiar el estado del producto, las versiones abiertas acompañan (§4.1/§4.2). */
    public function follow(Product $product, string $from, string $to): void
    {
        $map = match (true) {
            $to === Product::REVIEW => ['borrador' => 'en_revision'],
            $to === Product::DRAFT && $from === Product::REVIEW => ['en_revision' => 'borrador'],
            $to === Product::ACTIVE => ['borrador' => 'vigente', 'en_revision' => 'vigente'],
            default => [],
        };
        foreach ($map as $old => $new) {
            ProductVersion::where('product_id', $product->id)->where('status', $old)->update(['status' => $new, 'updated_at' => now()]);
        }
    }

    /** Snapshot completo (igual que `snap()` del prototipo, ampliado). */
    public function snapshot(Product $product): array
    {
        $product->load(['type', 'category', 'subcategory', 'primaryDestination', 'mainProvider', 'items.service.provider', 'items.componentProduct',
            'itinerary.days.items', 'rates.season', 'policies', 'inclusions', 'requirements', 'b2bRates', 'promotions', 'channels', 'secondaryDestinations.destination']);

        return [
            'name' => $product->name,
            'code' => $product->code,
            'sku' => $product->sku,
            'type' => $product->type?->name,
            'category' => $product->category?->name,
            'subcategory' => $product->subcategory?->name,
            'primary_destination' => $product->primaryDestination?->name,
            'secondary_destinations' => $product->secondaryDestinations->map(fn ($d) => $d->destination?->name)->filter()->values()->all(),
            'duration_value' => (string) $product->duration_value,
            'duration_unit' => $product->duration_unit,
            'modality' => $product->modality,
            'main_provider' => $product->mainProvider?->name,
            'passengers' => [$product->minimum_passengers, $product->maximum_passengers],
            'ages' => [$product->minimum_age, $product->maximum_age],
            'components' => $product->items->map(fn ($i) => [
                'kind' => $i->service_id ? 'service' : 'product',
                'ref' => $i->service?->ref ?? $i->componentProduct?->ref,
                'name' => $i->service?->name ?? $i->componentProduct?->name,
                'provider' => $i->service?->provider?->name,
                'quantity' => (string) $i->quantity,
                'unit' => $i->unit,
                'order' => $i->sort_order,
                'required' => $i->is_required,
                'included' => $i->is_included,
                'cost' => $i->unit_cost_override !== null ? (string) $i->unit_cost_override : ($i->service ? (string) $i->service->cost : null),
                'currency' => $i->service?->currency,
                'notes' => $i->notes,
            ])->all(),
            'itinerary' => $product->itinerary?->days->map(fn ($d) => [
                'day' => $d->day_number,
                'title' => $d->title,
                'items' => $d->items->map(fn ($it) => ['time' => $it->start_time ? substr((string) $it->start_time, 0, 5) : null, 'activity' => $it->activity, 'type' => $it->item_type, 'location' => $it->location])->all(),
            ])->all() ?? [],
            'rates' => $product->rates->map(fn ($r) => [
                'ref' => $r->ref, 'type' => $r->rate_type, 'segment' => $r->segment, 'channel' => $r->channel, 'currency' => $r->currency, 'basis' => $r->price_basis,
                'cost' => (string) $r->cost, 'price' => (string) $r->price, 'valid_from' => $r->valid_from->toDateString(), 'valid_to' => $r->valid_to->toDateString(),
                'season' => $r->season?->ref, 'status' => $r->status,
            ])->all(),
            'policies' => $product->policies?->only(['cancellation', 'modification', 'no_show', 'penalties', 'refund', 'date_change', 'advance_min', 'advance_max']),
            'inclusions' => $product->inclusions->where('kind', 'include')->pluck('text')->values()->all(),
            'exclusions' => $product->inclusions->where('kind', 'exclude')->pluck('text')->values()->all(),
            'requirements' => $product->requirements->map(fn ($r) => trim(($r->category ? "{$r->category}: " : '').$r->text))->all(),
            'b2b_rates' => $product->b2bRates->map(fn ($b) => ['agency_id' => $b->agency_id, 'price_list' => $b->price_list, 'price' => (string) $b->price, 'currency' => $b->currency, 'commission_pct' => (string) $b->commission_pct, 'valid_to' => $b->valid_to?->toDateString()])->all(),
            'promotions' => $product->promotions->map(fn ($p) => ['ref' => $p->ref, 'name' => $p->name, 'kind' => $p->kind, 'value' => (string) $p->value, 'channel' => $p->channel, 'from' => $p->date_from->toDateString(), 'to' => $p->date_to->toDateString()])->all(),
            'channels' => $product->channels->where('is_published', true)->pluck('channel')->values()->all(),
            'markup_pct' => (string) $product->markup_pct,
            'fx' => ['USD' => $this->tenant->tenant()?->fxToPen('USD')],
        ];
    }

    /** Diferencias campo a campo entre dos snapshots (PRD-BR-017). @return list<array{field: string, a: mixed, b: mixed}> */
    public static function compare(array $a, array $b): array
    {
        $diff = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $field) {
            $va = $a[$field] ?? null;
            $vb = $b[$field] ?? null;
            if (json_encode($va) !== json_encode($vb)) {
                $diff[] = ['field' => $field, 'a' => $va, 'b' => $vb];
            }
        }

        return $diff;
    }
}
