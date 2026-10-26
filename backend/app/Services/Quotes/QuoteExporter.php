<?php

namespace App\Services\Quotes;

use App\Models\Iam\User;
use App\Models\Quotes\Quote;
use App\Services\Crm\CrmClock;
use Illuminate\Support\Collection;

/** Exportación CSV de la lista de cotizaciones (§2) con el alcance del rol; margen solo si lo ve. */
class QuoteExporter
{
    public const RESOURCES = ['quotes.quotes' => ['quotes.view']];

    public function __construct(
        private readonly QuoteAccess $access,
        private readonly QuotePricing $pricing,
        private readonly CrmClock $clock,
    ) {}

    /** @return array{0: list<string>, 1: Collection<int, list<mixed>>} */
    public function rows(string $resource, User $user, array $filters = []): array
    {
        $margin = $this->access->seeMargin($user);
        $today = $this->clock->today();
        $rows = $this->access->scope(Quote::query(), $user)->with(['client:id,code,name', 'owner:id,name', 'currentVersion'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(! $this->access->ownOnly($user) && ($filters['owner'] ?? null), fn ($q) => $q->where('owner_id', $filters['owner']))
            ->orderByDesc('created_at')->get();

        return [
            ['Código', 'Título', 'Cliente', 'Asesor', 'Estado', 'Versión', 'Moneda', 'Total', 'Total S/', 'Válida hasta', ...($margin ? ['Margen %'] : [])],
            $rows->map(fn (Quote $q) => [
                $q->code, $q->title, $q->client?->name, $q->owner?->name, Quote::STATUS_LABELS[$q->displayStatus($today)], $q->current_version_no, $q->currency,
                (string) $q->currentVersion?->grand_total, $this->pricing->toPen((string) ($q->currentVersion?->grand_total ?? 0), $q->currency, (string) ($q->currentVersion?->fx_rate ?? 1)),
                $q->valid_until->toDateString(), ...($margin ? [(string) $q->currentVersion?->margin_pct] : []),
            ]),
        ];
    }
}
