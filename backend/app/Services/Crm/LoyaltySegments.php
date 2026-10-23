<?php

namespace App\Services\Crm;

use App\Models\Crm\Client;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Services\Crm\Linked\LinkedData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Segmentación para fidelización (CRM-BR-46), usada por la pantalla de Postventa y la exportación. */
class LoyaltySegments
{
    public const RECENCY = ['le30', 'gt90', 'gt180', 'none'];

    public function __construct(
        private readonly CrmAccess $access,
        private readonly CrmClock $clock,
        private readonly LinkedData $linked,
    ) {}

    /**
     * @param  Builder<Client>  $clients  clientes ya acotados al alcance del usuario (y filtro de asesor)
     * @param  array{segment?: ?string, recency?: ?string, destination?: ?string}  $filters
     * @return Collection<int, array{client: Client, stats: array, days_since_purchase: ?int, destinations: list<string>, has_open_opportunity: bool}>
     */
    public function rows(Builder $clients, User $user, array $filters): Collection
    {
        $clients = $clients->when($filters['segment'] ?? null, fn ($q, $s) => $q->where('segment_code', $s))
            ->orderBy('name')->limit(1000)->get();
        $ids = $clients->pluck('id');

        $stats = $this->linked->purchaseStats($ids->all(), $this->access->ownOnly($user) ? $user->id : null);
        $won = Opportunity::whereIn('client_id', $ids)->where('stage', Opportunity::WON)->whereNotNull('destination_label')
            ->get(['client_id', 'destination_label'])->groupBy('client_id');
        $openOpp = Opportunity::whereIn('client_id', $ids)->whereIn('stage', Opportunity::OPEN_STAGES)->pluck('client_id')->flip();
        $today = $this->clock->today();
        $destination = $filters['destination'] ?? null;

        return $clients->map(function (Client $c) use ($stats, $won, $openOpp, $today) {
            $s = $stats[$c->id] ?? [];
            $last = isset($s['last_purchase_date']) ? Carbon::parse($s['last_purchase_date']) : null;

            return [
                'client' => $c,
                'stats' => $s,
                'days_since_purchase' => $last ? (int) $last->diffInDays($today) : null,
                'destinations' => collect($s['visited_destinations'] ?? [])->merge(($won[$c->id] ?? collect())->pluck('destination_label'))->unique()->values()->all(),
                'has_open_opportunity' => isset($openOpp[$c->id]),
            ];
        })->filter(function ($row) use ($filters, $destination) {
            $days = $row['days_since_purchase'];
            $ok = match ($filters['recency'] ?? null) {
                'le30' => $days !== null && $days <= 30,
                'gt90' => $days !== null && $days > 90,
                'gt180' => $days !== null && $days > 180,
                'none' => $days === null,
                default => true,
            };

            return $ok && ($destination === null || collect($row['destinations'])->contains(fn ($d) => stripos($d, $destination) !== false));
        })->values();
    }
}
