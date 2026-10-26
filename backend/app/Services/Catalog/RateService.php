<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductSeason;
use App\Models\Catalog\Rate;
use App\Models\Catalog\Season;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\SequenceService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Tarifas del producto (PROC-017, §4.3, CAT-BR-13/14/15/16). */
class RateService
{
    public const FIELDS = ['rate_type', 'segment', 'channel', 'currency', 'price_basis', 'cost', 'price', 'min_price', 'max_price', 'valid_from', 'valid_to', 'season_id', 'conditions'];

    public function __construct(
        private readonly CrmClock $clock,
        private readonly CatalogAccess $access,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{rate: Rate, warnings: list<string>} */
    public function create(Product $product, array $data, User $actor, bool $audit = true): array
    {
        $data = Arr::only($data, self::FIELDS);
        $warnings = $this->validateNew($data);

        return DB::transaction(function () use ($product, $data, $warnings, $actor, $audit) {
            $rate = Rate::create($data + [
                'ref' => $this->sequences->next('catalog_rate'),
                'product_id' => $product->id,
                'price_basis' => $data['price_basis'] ?? 'Por persona',
                'status' => 'vigente',
            ]);
            if ($rate->season_id) {
                $this->associateSeason($product, $rate->season_id);
            }
            if ($audit) {
                $this->audit->log('Tarifa', $rate->ref, 'crear', null, $this->label($rate)." · {$product->ref}", actor: $actor);
            }

            return ['rate' => $rate, 'warnings' => $warnings];
        });
    }

    /** CAT-BR-14: costo y precio con motivo; nunca sobre tarifa vencida o inactiva. @return array{rate: Rate, warnings: list<string>} */
    public function changePrice(Rate $rate, array $data, User $actor): array
    {
        $status = $rate->effectiveStatus();
        if (in_array($status, ['vencida', 'inactiva'], true)) {
            throw ApiException::unprocessable('PRD-BR-005', "No se puede cambiar el precio de una tarifa {$status}.");
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw ApiException::invalid('reason', 'Indique el motivo del cambio de precio.');
        }
        $price = (float) $data['price'];
        $cost = (float) $data['cost'];
        if ($price <= 0) {
            throw ApiException::invalid('price', 'El precio debe ser mayor que 0.');
        }
        if ($cost < 0) {
            throw ApiException::invalid('cost', 'El costo no puede ser negativo.');
        }
        if ($rate->min_price !== null && $price < (float) $rate->min_price) {
            throw ApiException::invalid('price', "El precio no puede ser menor que el mínimo ({$rate->min_price}).");
        }
        // §12-17: se valida también el máximo.
        if ($rate->max_price !== null && $price > (float) $rate->max_price) {
            throw ApiException::invalid('price', "El precio no puede ser mayor que el máximo ({$rate->max_price}).");
        }

        return DB::transaction(function () use ($rate, $price, $cost, $reason, $actor) {
            $before = "costo {$rate->cost} · precio {$rate->price}";
            $rate->update(['cost' => $cost, 'price' => $price]);
            $this->audit->log('Tarifa', $rate->ref, 'cambio_precio', $before, "costo {$rate->cost} · precio {$rate->price} — {$reason}",
                ['reason' => $reason, 'product_id' => $rate->product_id], actor: $actor);

            return ['rate' => $rate, 'warnings' => $price < $cost ? ['El precio quedó por debajo del costo.'] : []];
        });
    }

    public function setStatus(Rate $rate, bool $active, User $actor): Rate
    {
        if ($rate->effectiveStatus() === 'vencida') {
            throw ApiException::unprocessable('PRD-BR-005', 'Una tarifa vencida no se puede activar ni desactivar; registre una nueva.');
        }
        $target = $active ? 'vigente' : 'inactiva';
        if ($rate->status === $target) {
            return $rate;
        }

        return DB::transaction(function () use ($rate, $target, $actor) {
            $before = $rate->status;
            $rate->update(['status' => $target]);
            $this->audit->log('Tarifa', $rate->ref, 'cambio_estado', $before, $target, actor: $actor);

            return $rate;
        });
    }

    /** Las temporadas usadas por tarifas se asocian automáticamente al producto (CAT-BR-16). */
    public function associateSeason(Product $product, string $seasonId): void
    {
        ProductSeason::firstOrCreate(['product_id' => $product->id, 'season_id' => $seasonId]);
    }

    /** Tarifas del producto visibles para el rol (§7.2: netas solo con permiso). */
    public function visible(Product $product, User $user): Collection
    {
        $rates = $product->rates()->with('season')->get();

        return $this->access->seeNetRates($user) ? $rates : $rates->reject(fn (Rate $r) => $r->isNet())->values();
    }

    /**
     * CAT-BR-15: tarifa aplicable para fecha/segmento/canal con el motivo de descarte de las demás.
     *
     * @return array{applicable: ?Rate, season: ?Season, rows: list<array{rate: Rate, selectable: bool, reason: ?string}>}
     */
    public function resolve(Collection $rates, string $date, ?string $segment, ?string $channel, ?Season $season): array
    {
        $today = $this->clock->today()->toDateString();
        $rows = [];
        foreach ($rates as $rate) {
            $reason = match (true) {
                $rate->status === 'inactiva' => 'Inactiva',
                $rate->valid_to->toDateString() < $today => 'Vencida (PRD-BR-005)',
                $date < $rate->valid_from->toDateString() || $date > $rate->valid_to->toDateString() => 'Fecha fuera de vigencia',
                $segment && $rate->segment !== $segment => "Otro segmento ({$rate->segment})",
                $channel && $rate->channel !== 'Todos' && $channel !== 'Todos' && $rate->channel !== $channel => "Otro canal ({$rate->channel})",
                default => null,
            };
            $rows[] = ['rate' => $rate, 'selectable' => $reason === null, 'reason' => $reason];
        }
        $valid = collect($rows)->where('selectable', true)->pluck('rate');
        $applicable = $valid->sortBy(fn (Rate $r) => [
            $season && $r->season_id === $season->id ? 0 : ($r->season_id ? 2 : 1),
            $channel && $r->channel === $channel ? 0 : 1,
            $r->ref,
        ])->first();

        return ['applicable' => $applicable, 'season' => $season, 'rows' => $rows];
    }

    /**
     * CAT-BR-44: "precio desde" = vigente Directo y Adulto/Pasajero; si no, Directo; si no, la primera vigente.
     */
    public function fromPrice(Collection $rates): ?Rate
    {
        $today = $this->clock->today();
        $current = $rates->filter(fn (Rate $r) => $r->effectiveStatus($today) === 'vigente')->sortBy('ref')->values();

        return $current->first(fn (Rate $r) => $r->channel === 'Directo' && in_array($r->segment, ['Adulto', 'Pasajero'], true))
            ?? $current->first(fn (Rate $r) => $r->channel === 'Directo')
            ?? $current->first();
    }

    /** @return list<string> advertencias (CAT-BR-13) */
    private function validateNew(array $data): array
    {
        foreach (['currency' => 'Seleccione la moneda (PRD-BR-004).', 'valid_from' => 'Indique la vigencia desde (PRD-BR-004).', 'valid_to' => 'Indique la vigencia hasta (PRD-BR-004).'] as $field => $message) {
            if (empty($data[$field])) {
                throw ApiException::invalid($field, $message);
            }
        }
        $from = (string) $data['valid_from'];
        $to = (string) $data['valid_to'];
        if ($to < $from) {
            throw ApiException::invalid('valid_to', 'La vigencia “hasta” debe ser igual o posterior a “desde”.');
        }
        if ($to < $this->clock->today()->toDateString()) {
            throw ApiException::invalid('valid_to', 'No se puede registrar una tarifa vencida (PRD-BR-005).');
        }
        $price = (float) $data['price'];
        if ($price <= 0) {
            throw ApiException::invalid('price', 'El precio debe ser mayor que 0.');
        }
        if (isset($data['min_price']) && $data['min_price'] !== null && $data['min_price'] !== '' && $price < (float) $data['min_price']) {
            throw ApiException::invalid('price', 'El precio no puede ser menor que el precio mínimo.');
        }
        if (isset($data['max_price']) && $data['max_price'] !== null && $data['max_price'] !== '' && $price > (float) $data['max_price']) {
            throw ApiException::invalid('price', 'El precio no puede ser mayor que el precio máximo.');
        }
        if (! empty($data['season_id']) && ! Season::whereKey($data['season_id'])->where('status', 'activa')->exists()) {
            throw ApiException::invalid('season_id', 'Solo se pueden usar temporadas activas.');
        }

        return $price < (float) ($data['cost'] ?? 0) ? ['El precio es menor que el costo: la tarifa tendrá margen negativo.'] : [];
    }

    private function label(Rate $r): string
    {
        return "{$r->rate_type} · {$r->segment} · {$r->channel} · {$r->currency} {$r->price}";
    }
}
