<?php

namespace App\Services\Quotes;

use App\Models\Quotes\Quote;
use App\Services\Catalog\Integration\CatalogLinkedData;
use App\Services\Crm\CrmClock;

/**
 * Cotizaciones que lee el CRM (spec 01 §3.12): cartera, oportunidad, pestaña y línea de tiempo del 360
 * y panel de Ventas. Hereda los datos de catálogo; Reservas y Finanzas siguen vacíos.
 */
class QuotesLinkedData extends CatalogLinkedData
{
    public function clientIdsWithRecordsOf(string $userId): array
    {
        return Quote::where('owner_id', $userId)->distinct()->pluck('client_id')->all();
    }

    public function quotesForOpportunity(string $opportunityId): array
    {
        return Quote::where('opportunity_id', $opportunityId)->with('currentVersion:id,grand_total')->orderByDesc('created_at')->get()
            ->map(fn (Quote $q) => ['id' => $q->id, 'code' => $q->code, 'status' => $q->displayStatus($this->today()), 'title' => $q->title,
                'total' => (float) $q->currentVersion?->grand_total, 'currency' => $q->currency, 'version_no' => $q->current_version_no])->all();
    }

    /** CRM-BR-23: aprobada o convertida. */
    public function hasApprovedQuote(string $opportunityId): bool
    {
        return Quote::where('opportunity_id', $opportunityId)->whereIn('status', [Quote::APPROVED, Quote::CONVERTED])->exists();
    }

    public function clientTab(string $clientId, string $tab, ?string $ownerId): array
    {
        if ($tab !== 'quotes') {
            return parent::clientTab($clientId, $tab, $ownerId);
        }

        return Quote::where('client_id', $clientId)->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))
            ->with(['currentVersion:id,grand_total', 'owner:id,name'])->orderByDesc('created_at')->get()
            ->map(fn (Quote $q) => ['id' => $q->id, 'code' => $q->code, 'title' => $q->title, 'status' => $q->displayStatus($this->today()),
                'status_label' => Quote::STATUS_LABELS[$q->displayStatus($this->today())], 'version_no' => $q->current_version_no,
                'total' => (string) $q->currentVersion?->grand_total, 'currency' => $q->currency, 'valid_until' => $q->valid_until->toDateString(),
                'owner' => $q->owner?->name])->all();
    }

    public function clientTimeline(string $clientId, ?string $ownerId): array
    {
        $events = parent::clientTimeline($clientId, $ownerId);
        foreach (Quote::where('client_id', $clientId)->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))->get() as $q) {
            $events[] = ['type' => 'quote', 'title' => "Cotización {$q->code} creada: {$q->title}", 'at' => $q->created_at, 'ref' => $q->code];
            if ($q->approved_at) {
                $events[] = ['type' => 'quote', 'title' => "Cotización {$q->code} aprobada por {$q->approved_by_name}", 'at' => $q->approved_at, 'ref' => $q->code];
            }
            if ($q->rejected_at) {
                $events[] = ['type' => 'quote', 'title' => "Cotización {$q->code} rechazada: {$q->rejected_reason}", 'at' => $q->rejected_at, 'ref' => $q->code];
            }
        }

        return $events;
    }

    /** §7.4 "Mis cotizaciones abiertas": abiertas y no vencidas; vencen en ≤ 7 días. */
    public function salesPanel(string $userId): array
    {
        $today = $this->today();
        $open = Quote::where('owner_id', $userId)->whereIn('status', Quote::EXPIRABLE)->with('client:id,name')->orderBy('valid_until')->get()
            ->reject(fn (Quote $q) => $q->isExpired($today) || ($q->status === Quote::DRAFT && $q->valid_until->lt($today)));

        return ['open_quotes' => [
            'count' => $open->count(),
            'expiring_7d' => $open->filter(fn (Quote $q) => $q->valid_until->lte($today->copy()->addDays(7)))->count(),
            'items' => $open->take(5)->map(fn (Quote $q) => ['code' => $q->code, 'title' => $q->title, 'client' => $q->client?->name,
                'status' => $q->status, 'valid_until' => $q->valid_until->toDateString()])->values()->all(),
        ]] + parent::salesPanel($userId);
    }

    private function today()
    {
        return app(CrmClock::class)->today();
    }
}
