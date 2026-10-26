<?php

namespace App\Services\Quotes;

use App\Events\Quotes\QuoteCreated;
use App\Events\Quotes\QuoteReassigned;
use App\Events\Quotes\QuoteVersionCreated;
use App\Exceptions\ApiException;
use App\Models\Catalog\Product;
use App\Models\Catalog\Rate;
use App\Models\Crm\Client;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Models\Quotes\InternalApproval;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteItem;
use App\Models\Quotes\QuoteVersion;
use App\Services\AuditLogger;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmClock;
use App\Services\Crm\CrmUsers;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Constructor de cotizaciones (PROC-021/022): alta, edición del borrador, nueva versión, duplicar y reasignar.
 * Precios, totales, snapshot y políticas se calculan siempre aquí (RT-5, QTE-BR-20).
 */
class QuoteService
{
    public const DEFAULT_CONDITIONS = 'Precios sujetos a disponibilidad al momento de reservar. 40% de anticipo para confirmar; saldo 15 días antes del viaje.';

    public const HEADER = ['title', 'travel_from', 'travel_to', 'valid_until', 'currency', 'pax_adt', 'pax_chd', 'pax_inf'];

    public function __construct(
        private readonly QuotePricing $pricing,
        private readonly QuoteAccess $access,
        private readonly CrmAccess $crm,
        private readonly CrmClock $clock,
        private readonly CrmUsers $users,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** PROC-021: cotización + v1 borrador; la oportunidad pasa a `cotizacion` (CRM-BR-27). */
    public function create(array $data, User $actor): Quote
    {
        [$client, $opportunity] = $this->relations($data, $actor);
        $this->assertDiscount($data['discount_pct'] ?? 0, $actor);
        $fx = $this->pricing->fx();
        $header = $this->header($data, $client);
        $lines = $this->lines($data['items'], $header + ['fx_rate' => $fx], $client);

        return DB::transaction(function () use ($data, $actor, $client, $opportunity, $fx, $header, $lines) {
            $quote = Quote::create($header + [
                'code' => $this->sequences->next('quote'),
                'client_id' => $client->id,
                'opportunity_id' => $opportunity?->id,
                'owner_id' => $actor->id,
                'status' => Quote::DRAFT,
                'current_version_no' => 1,
                'fx_rate' => $fx,
            ]);
            $version = $this->newVersionRow($quote, 1, $actor, $data + ['tax_pct' => $data['tax_pct'] ?? $this->pricing->defaultTax($client)], $fx);
            $this->saveLines($version, $lines);
            $quote->forceFill(['current_version_id' => $version->id])->save();

            $this->audit->log('Cotización', $quote->code, 'crear', $opportunity ? "desde {$opportunity->code}" : null,
                "v1 · {$quote->currency} {$version->grand_total}", actor: $actor);
            $this->auditDiscountOverCap($quote, $version, $actor);
            QuoteCreated::dispatch($quote->id, $quote->opportunity_id);

            return $quote->fresh();
        });
    }

    /** QTE-BR-10: edita en sitio la versión vigente en borrador; anula la solicitud y la aprobación interna. */
    public function updateDraft(Quote $quote, QuoteVersion $version, array $data, User $actor): Quote
    {
        if ($version->id !== $quote->current_version_id || $version->status !== QuoteVersion::DRAFT || $version->isFrozen()) {
            throw ApiException::rule('QTE-BR-10', 'Solo se edita la versión vigente en borrador');
        }
        [$client, $opportunity] = $this->relations($data + ['client_id' => $quote->client_id], $actor, $quote);
        $this->assertDiscount($data['discount_pct'] ?? $version->discount_pct, $actor);
        $header = $this->header($data + ['valid_until' => $quote->valid_until->toDateString()], $client);
        $lines = $this->lines($data['items'], $header + ['fx_rate' => (string) $version->fx_rate], $client);

        return DB::transaction(function () use ($quote, $version, $data, $actor, $client, $opportunity, $header, $lines) {
            $before = "{$quote->currency} {$version->grand_total}";
            $quote->fill($header + ['client_id' => $client->id, 'opportunity_id' => $opportunity?->id])->save();
            $version->fill(Arr::only($data, ['discount_pct', 'notes', 'conditions']) + [
                'tax_pct' => $data['tax_pct'] ?? $version->tax_pct,
            ]);
            $this->voidInternalApproval($quote, $version, $actor);
            $version->items()->delete();
            $this->saveLines($version, $lines);

            $this->audit->log('Cotización', $quote->code, 'editar_version', "v{$version->version_no} · {$before}",
                "v{$version->version_no} · {$quote->currency} {$version->grand_total}", actor: $actor);
            $this->auditDiscountOverCap($quote, $version, $actor);

            return $quote->fresh();
        });
    }

    /** QTE-BR-18: v(n+1) borrador desde la vigente; la anterior queda `reemplazada`. */
    public function newVersion(Quote $quote, User $actor, ?string $note = null): Quote
    {
        if ($quote->status === Quote::CONVERTED) {
            throw ApiException::rule('QTE-BR-18', 'Una cotización convertida en reserva no admite nuevas versiones');
        }

        return DB::transaction(function () use ($quote, $actor, $note) {
            $current = $quote->currentVersion()->with('items')->firstOrFail();
            $next = (int) $quote->versions()->max('version_no') + 1;
            $fx = $this->pricing->fx();
            $version = $this->newVersionRow($quote, $next, $actor, [
                'discount_pct' => $current->discount_pct, 'tax_pct' => $current->tax_pct, 'notes' => $current->notes, 'conditions' => $current->conditions,
            ], $fx);
            $this->saveLines($version, $this->copyLines($current->items));

            if ($current->status !== QuoteVersion::REPLACED) {
                $current->forceFill(['status' => QuoteVersion::REPLACED])->save();
            }
            $quote->forceFill([
                'status' => Quote::DRAFT, 'current_version_no' => $next, 'current_version_id' => $version->id, 'fx_rate' => $fx,
                'valid_until' => $this->renewedValidity($quote),
                ...$this->clearedApproval(),
            ])->save();

            $this->audit->log('Cotización', $quote->code, 'nueva_version', "v{$current->version_no} ".QuoteVersion::STATUS_LABELS[$current->status],
                "v{$next} borrador".($note ? " · {$note}" : ''), actor: $actor);
            QuoteVersionCreated::dispatch($quote->id, $quote->opportunity_id);

            return $quote->fresh();
        });
    }

    /** Duplicar: nueva cotización v1 borrador, dueño = usuario actual, validez renovada (§11-15). */
    public function duplicate(Quote $source, User $actor): Quote
    {
        return DB::transaction(function () use ($source, $actor) {
            $current = $source->currentVersion()->with('items')->firstOrFail();
            $opportunity = $source->opportunity_id && (! $this->access->ownOnly($actor) || $source->opportunity?->owner_id === $actor->id)
                && ! $source->opportunity?->isClosed() ? $source->opportunity_id : null;
            $fx = $this->pricing->fx();
            $quote = Quote::create(Arr::only($source->getAttributes(), ['client_id', 'title', 'travel_from', 'travel_to', 'currency', 'pax_adt', 'pax_chd', 'pax_inf']) + [
                'code' => $this->sequences->next('quote'),
                'opportunity_id' => $opportunity,
                'owner_id' => $actor->id,
                'status' => Quote::DRAFT,
                'current_version_no' => 1,
                'fx_rate' => $fx,
                'valid_until' => $this->clock->today()->addDays($this->pricing->validityDays())->toDateString(),
                'duplicated_from_id' => $source->id,
            ]);
            $version = $this->newVersionRow($quote, 1, $actor, [
                'discount_pct' => $current->discount_pct, 'tax_pct' => $current->tax_pct, 'notes' => $current->notes, 'conditions' => $current->conditions,
            ], $fx);
            $this->saveLines($version, $this->copyLines($current->items));
            $quote->forceFill(['current_version_id' => $version->id])->save();

            $this->audit->log('Cotización', $quote->code, 'duplicar', "desde {$source->code} v{$current->version_no}", "v1 · {$quote->currency} {$version->grand_total}", actor: $actor);
            $this->audit->log('Cotización', $source->code, 'duplicar', null, "copiada en {$quote->code}", actor: $actor);
            QuoteVersionCreated::dispatch($quote->id, $quote->opportunity_id);

            return $quote->fresh();
        });
    }

    /** QTE-BR-25: solo con quotes.reassign; el nuevo dueño debe ser otro asesor activo. */
    public function reassign(Quote $quote, string $ownerId, ?string $note, User $actor): Quote
    {
        $owner = $this->users->assertAssignable($ownerId);
        if ($owner->id === $quote->owner_id) {
            throw ApiException::invalid('owner_id', 'El nuevo asesor debe ser distinto al actual');
        }

        return DB::transaction(function () use ($quote, $owner, $note, $actor) {
            $previous = $quote->owner?->name;
            $quote->forceFill(['owner_id' => $owner->id])->save();
            $this->audit->log('Cotización', $quote->code, 'reasignar', $previous, $owner->name.($note ? " · {$note}" : ''), actor: $actor);
            $this->notify->push('cotizacion.reasignada', 'portal', $owner, "Se le asignó la cotización {$quote->code}", $quote->title,
                ['client_id' => $quote->client_id, 'related_type' => 'quote', 'related_id' => $quote->id]);
            QuoteReassigned::dispatch($quote->id, $quote->opportunity_id);

            return $quote->fresh();
        });
    }

    /**
     * Líneas normalizadas y validadas (QTE-BR-05/06/07, PRC-01/02/03). Sin precio enviado se usa el sugerido de la tarifa.
     *
     * @param  array{currency: string, fx_rate: string, pax_adt: int, pax_chd: int}  $header
     * @return list<array>
     */
    public function lines(array $items, array $header, ?Client $client): array
    {
        $products = Product::with(['type', 'currentVersion'])->whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
        $lines = [];
        foreach (array_values($items) as $i => $item) {
            $n = $i + 1;
            $product = $products[$item['product_id']] ?? throw ApiException::invalid("items.{$i}.product_id", "Ítem {$n}: producto inexistente");
            if ($reason = self::blockedReason($product)) {
                throw ApiException::unprocessable('PRD-BR-002', "Ítem {$n}: {$product->ref} no se puede cotizar ({$reason})", ['field' => "items.{$i}.product_id"]);
            }
            $date = $item['service_date'];
            $rate = null;
            $suggestion = null;
            if (! empty($item['rate_id'])) {
                $rate = Rate::whereKey($item['rate_id'])->where('product_id', $product->id)->first()
                    ?? throw ApiException::invalid("items.{$i}.rate_id", "Ítem {$n}: la tarifa no pertenece al producto");
                if (! QuotePricing::rateValidOn($rate, $date)) {
                    throw ApiException::unprocessable('PRD-BR-005', "Ítem {$n}: la tarifa {$rate->ref} no está vigente para el {$date}", ['field' => "items.{$i}.rate_id"]);
                }
                $suggestion = $this->pricing->suggest($rate, $date, $header['currency'], $header['fx_rate']);
            }
            $defaults = QuotePricing::lineDefaults($product, (int) $header['pax_adt'], (int) $header['pax_chd'], $rate);
            $unitCost = $item['unit_cost'] ?? $suggestion['unit_cost'] ?? null;
            $unitPrice = $item['unit_price'] ?? $suggestion['unit_price'] ?? null;
            if ($unitPrice === null || $unitCost === null) {
                throw ApiException::invalid("items.{$i}.unit_price", "Ítem {$n}: sin tarifa, ingrese costo y precio unitarios");
            }

            $lines[] = [
                'line_no' => $n,
                'product_id' => $product->id,
                'product_version' => $product->currentVersion?->version ?? '1.0',
                'rate_id' => $rate?->id,
                'segment' => $item['segment'] ?? $defaults['segment'],
                'service_date' => $date,
                'qty' => (int) ($item['qty'] ?? $defaults['qty']),
                'pax' => (int) ($item['pax'] ?? $defaults['pax']),
                'unit_cost' => (string) $unitCost,
                'unit_price' => (string) $unitPrice,
                'season_id' => $suggestion['season_id'] ?? null,
                'season_factor' => $suggestion['season_factor'] ?? null,
                'price_source' => $rate ? 'rate' : 'manual',
                'rate_snapshot' => $suggestion ? $suggestion['rate_snapshot'] + ['suggested_cost' => $suggestion['unit_cost'], 'suggested_price' => $suggestion['unit_price'], 'season' => $suggestion['season']] : null,
            ];
        }

        return $lines;
    }

    /** QTE-BR-05 / PRD-BR-002: motivo por el que un producto no se puede cotizar, o null. */
    public static function blockedReason(Product $product): ?string
    {
        return match (true) {
            $product->status !== Product::ACTIVE => 'producto '.mb_strtolower(Product::STATUS_LABELS[$product->status] ?? $product->status),
            $product->is_quoteable === false => 'no habilitado para cotizaciones',
            default => null,
        };
    }

    /** Totales de una lista de líneas + motivos de aprobación (preview y guardado). */
    public function evaluate(array $lines, string|float $discountPct, string|float $taxPct): array
    {
        $totals = $this->pricing->totals($lines, $discountPct, $taxPct);

        return $totals + ['reasons' => QuotePolicy::reasons($discountPct, $totals['marginPct']), 'over_cap' => QuotePolicy::overCap($discountPct)];
    }

    // ── Internos ──────────────────────────────────────────────────────

    /** @return array{0: Client, 1: ?Opportunity} QTE-BR-09 */
    private function relations(array $data, User $actor, ?Quote $quote = null): array
    {
        $client = Client::whereKey($data['client_id'])->first() ?? throw ApiException::invalid('client_id', 'Seleccione un cliente válido');
        $this->crm->ensureClient($client, $actor);
        $opportunity = null;
        if (! empty($data['opportunity_id'])) {
            $opportunity = Opportunity::whereKey($data['opportunity_id'])->first() ?? throw ApiException::invalid('opportunity_id', 'Oportunidad inexistente');
            if ($opportunity->client_id !== $client->id) {
                throw ApiException::invalid('opportunity_id', 'La oportunidad pertenece a otro cliente');
            }
            if ($opportunity->id !== $quote?->opportunity_id) {
                $this->crm->ensureOpportunity($opportunity, $actor);
                if ($opportunity->isClosed()) {
                    throw ApiException::invalid('opportunity_id', 'No se puede cotizar una oportunidad ganada o perdida');
                }
            }
        }

        return [$client, $opportunity];
    }

    private function header(array $data, Client $client): array
    {
        return Arr::only($data, ['title', 'travel_from', 'travel_to', 'currency']) + [
            'valid_until' => $data['valid_until'] ?? $this->clock->today()->addDays($this->pricing->validityDays())->toDateString(),
            'pax_adt' => (int) ($data['pax_adt'] ?? 0), 'pax_chd' => (int) ($data['pax_chd'] ?? 0), 'pax_inf' => (int) ($data['pax_inf'] ?? 0),
        ];
    }

    /** QTE-BR-08: tope 10 % salvo `quotes.discount_override` (máx. 50 %). */
    private function assertDiscount(string|float|int|null $discount, User $actor): void
    {
        if (QuotePolicy::overCap((float) $discount) && ! $actor->hasPermissionTo(QuoteAccess::DISCOUNT_OVERRIDE)) {
            throw ApiException::unprocessable('QTE-BR-08', 'El descuento máximo es '.QuotePolicy::MAX_DISCOUNT.' %; superarlo requiere quotes.discount_override', ['field' => 'discount_pct']);
        }
    }

    private function auditDiscountOverCap(Quote $quote, QuoteVersion $version, User $actor): void
    {
        if (QuotePolicy::overCap((string) $version->discount_pct)) {
            $this->audit->log('Cotización', $quote->code, 'descuento_sobre_tope', 'tope '.QuotePolicy::MAX_DISCOUNT.' %', "v{$version->version_no} · {$version->discount_pct} %", actor: $actor);
        }
    }

    private function newVersionRow(Quote $quote, int $number, User $actor, array $data, string $fx): QuoteVersion
    {
        return QuoteVersion::create([
            'quote_id' => $quote->id,
            'version_no' => $number,
            'status' => QuoteVersion::DRAFT,
            'issued_on' => $this->clock->today()->toDateString(),
            'author_id' => $actor->id,
            'discount_pct' => $data['discount_pct'] ?? 0,
            'tax_pct' => $data['tax_pct'],
            'notes' => $data['notes'] ?? null,
            'conditions' => $data['conditions'] ?? self::DEFAULT_CONDITIONS,
            'fx_rate' => $fx,
        ]);
    }

    /** Guarda líneas, totales, snapshot de catálogo y copia de políticas (QTE-BR-20). */
    private function saveLines(QuoteVersion $version, array $lines): void
    {
        foreach ($lines as $line) {
            QuoteItem::create($line + ['quote_version_id' => $version->id]);
        }
        $totals = $this->pricing->totals($lines, (string) $version->discount_pct, (string) $version->tax_pct);
        [$label, $catalog, $policies] = $this->snapshot($lines);
        $version->fill([
            'cost_total' => $totals['cost'], 'sale_total' => $totals['sale'], 'discount_total' => $totals['discount'], 'net_total' => $totals['net'],
            'tax_total' => $totals['tax'], 'grand_total' => $totals['total'], 'profit_total' => $totals['profit'], 'margin_pct' => $totals['marginPct'],
            'markup_pct' => $totals['markupPct'], 'snapshot_label' => mb_substr($label, 0, 300), 'catalog_snapshot' => $catalog, 'policies_snapshot' => $policies,
        ])->save();
    }

    /** Líneas de una versión anterior, con la versión vigente del producto al momento de copiar. */
    private function copyLines(Collection $items): array
    {
        $versions = Product::with('currentVersion')->whereIn('id', $items->pluck('product_id'))->get()->mapWithKeys(fn (Product $p) => [$p->id => $p->currentVersion?->version]);

        return $items->map(fn (QuoteItem $i) => Arr::only($i->getAttributes(), ['line_no', 'product_id', 'rate_id', 'segment', 'qty', 'pax', 'unit_cost', 'unit_price', 'season_id', 'season_factor', 'price_source']) + [
            'service_date' => $i->service_date->toDateString(),
            'product_version' => $versions[$i->product_id] ?? $i->product_version,
            'rate_snapshot' => $i->rate_snapshot,
        ])->values()->all();
    }

    /** @return array{0: string, 1: list<array>, 2: array<string, array>} */
    private function snapshot(array $lines): array
    {
        $products = Product::withTrashed()->with(['currentVersion', 'policies', 'inclusions', 'items.service', 'items.componentProduct'])
            ->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');
        $labels = [];
        $catalog = [];
        $policies = [];
        foreach ($lines as $line) {
            $p = $products[$line['product_id']];
            $labels[$p->id] = "{$p->ref} v{$line['product_version']}";
            $catalog[] = [
                'line_no' => $line['line_no'],
                'product' => ['id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'version' => $line['product_version'], 'short_description' => $p->short_description],
                'components' => $p->items->map(fn ($i) => $i->service?->name ?? $i->componentProduct?->name)->filter()->values()->all(),
                'rate' => $line['rate_snapshot'],
                'inclusions' => $p->inclusions->where('kind', 'include')->pluck('text')->values()->all(),
                'exclusions' => $p->inclusions->where('kind', 'exclude')->pluck('text')->values()->all(),
            ];
            if ($p->policies) {
                $policies[$p->id] = ['product' => $p->ref, 'cancel' => $p->policies->cancellation, 'modify' => $p->policies->modification, 'noshow' => $p->policies->no_show,
                    'refund' => $p->policies->refund, 'advanceMin' => $p->policies->advance_min, 'advanceMax' => $p->policies->advance_max];
            }
        }

        return [implode(' / ', $labels), $catalog, $policies];
    }

    /** Editar el borrador anula la solicitud pendiente y la aprobación ya otorgada (QTE-BR-10, §4.2). */
    private function voidInternalApproval(Quote $quote, QuoteVersion $version, User $actor): void
    {
        $voided = InternalApproval::where('quote_version_id', $version->id)->whereIn('status', [InternalApproval::PENDING, InternalApproval::APPROVED])->get();
        foreach ($voided as $approval) {
            $approval->forceFill(['status' => InternalApproval::VOIDED])->save();
            $this->audit->log('Cotización', $quote->code, 'anular_solicitud_aprobacion', 'v'.$version->version_no.' '.($approval->decided_at ? 'aprobada' : 'pendiente'), 'anulada por edición', actor: $actor);
        }
        $version->internal_approval_id = null;
    }

    /** Renueva la validez si quedan menos de los días por defecto (QTE-BR-18, excepción vencida → nueva versión). */
    private function renewedValidity(Quote $quote): string
    {
        $renewed = $this->clock->today()->addDays($this->pricing->validityDays());

        return $quote->valid_until->lt($renewed) ? $renewed->toDateString() : $quote->valid_until->toDateString();
    }

    public function clearedApproval(): array
    {
        return array_fill_keys(['approved_at', 'approved_by_name', 'approval_channel', 'approval_ref', 'approval_recorded_by', 'approval_ip',
            'approval_hash', 'approval_version_no', 'rejected_at', 'rejected_reason'], null);
    }
}
