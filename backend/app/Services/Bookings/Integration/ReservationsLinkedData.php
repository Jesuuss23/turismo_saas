<?php

namespace App\Services\Bookings\Integration;

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Services\Crm\CrmClock;
use App\Services\Quotes\QuotePricing;
use App\Services\Quotes\QuotesLinkedData;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Reservas, pasajeros, CxC, pagos y notas de crédito que lee el CRM (spec 01 §3.12): cartera, pestañas y KPIs
 * del 360, encuestas, viajes por cerrar y panel de Ventas. Hereda cotizaciones y catálogo.
 */
class ReservationsLinkedData extends QuotesLinkedData
{
    public function clientIdsWithRecordsOf(string $userId): array
    {
        return array_values(array_unique([...parent::clientIdsWithRecordsOf($userId), ...Reservation::where('owner_id', $userId)->distinct()->pluck('client_id')->all()]));
    }

    public function clientTab(string $clientId, string $tab, ?string $ownerId): array
    {
        $reservations = fn () => Reservation::where('client_id', $clientId)->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId));

        return match ($tab) {
            'reservations' => $reservations()->with('owner:id,name')->withCount('passengers')->orderByDesc('travel_from')->get()
                ->map(fn (Reservation $r) => ['code' => $r->code, 'title' => $r->title, 'status' => Reservation::STATUS_LABELS[$r->status],
                    'travel_from' => $r->travel_from->toDateString(), 'travel_to' => $r->travel_to->toDateString(), 'pax' => $r->passengers_count,
                    'total' => "{$r->currency} {$r->total_amount}", 'owner' => $r->owner?->name])->all(),
            'passengers' => Passenger::whereIn('reservation_id', $reservations()->select('id'))->where('is_placeholder', false)->orderBy('full_name')->get()
                ->map(fn (Passenger $p) => ['name' => $p->full_name, 'type' => $p->pax_type, 'document' => trim("{$p->doc_type} {$p->doc_number}"),
                    'nationality' => $p->nationality, 'reservation' => Reservation::whereKey($p->reservation_id)->value('code')])->all(),
            'receivables' => Receivable::whereIn('reservation_id', $reservations()->select('id'))->orderBy('due_date')->get()
                ->map(fn (Receivable $c) => ['code' => $c->code, 'concept' => $c->concept, 'amount' => "{$c->currency} {$c->amount}", 'balance' => $c->balance(),
                    'due_date' => $c->due_date->toDateString(), 'status' => $c->status])->all(),
            'payments' => Payment::whereIn('reservation_id', $reservations()->select('id'))->orderByDesc('paid_on')->get()
                ->map(fn (Payment $p) => ['code' => $p->code, 'kind' => $p->kind, 'method' => $p->method, 'amount' => "{$p->currency} {$p->amount}",
                    'paid_on' => $p->paid_on->toDateString(), 'status' => $p->status])->all(),
            'invoices' => Invoice::whereIn('reservation_id', $reservations()->select('id'))->orderByDesc('issued_on')->get()
                ->map(fn (Invoice $i) => ['code' => $i->code, 'type' => $i->type, 'amount' => "{$i->currency} {$i->amount}", 'issued_on' => $i->issued_on, 'status' => $i->status])->all(),
            default => parent::clientTab($clientId, $tab, $ownerId),
        };
    }

    /** CRM-BR-34: compras no canceladas convertidas a soles; saldo y vencido de CxC abiertas. */
    public function purchaseStats(array $clientIds, ?string $ownerId = null): array
    {
        if ($clientIds === []) {
            return [];
        }
        $pricing = app(QuotePricing::class);
        $today = app(CrmClock::class)->today()->toDateString();
        $rows = Reservation::whereIn('client_id', $clientIds)->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))
            ->where('status', '<>', Reservation::CANCELLED)->get();
        $receivables = Receivable::whereIn('reservation_id', $rows->pluck('id'))->whereIn('status', Receivable::OPEN)->get()->groupBy('client_id');
        $fx = $rows->pluck('fx_rate', 'id');
        $out = [];
        foreach ($rows->groupBy('client_id') as $clientId => $list) {
            $open = $receivables[$clientId] ?? collect();
            $pen = fn (Collection $items, callable $amount) => (float) (string) $items->reduce(fn (BigDecimal $s, $x) => $s->plus($pricing->toPen($amount($x), $x->currency, (string) ($fx[$x->reservation_id ?? $x->id] ?? $pricing->fx()))), BigDecimal::zero());
            $out[$clientId] = [
                'reservations_count' => $list->count(),
                'total_pen' => $pen($list, fn (Reservation $r) => (string) $r->total_amount),
                'balance_pen' => $pen($open, fn (Receivable $c) => $c->balance()),
                'overdue_pen' => $pen($open->filter(fn (Receivable $c) => $c->due_date->toDateString() < $today), fn (Receivable $c) => $c->balance()),
                'last_purchase_date' => $list->max('created_at')?->toDateString(),
                'visited_destinations' => $this->destinations($list->whereIn('status', [Reservation::COMPLETED, Reservation::IN_OPERATION])),
            ];
        }

        return $out;
    }

    public function reservation(string $reservationId): ?array
    {
        $r = Reservation::find($reservationId);

        return $r ? ['id' => $r->id, 'code' => $r->code, 'client_id' => $r->client_id, 'status' => $r->status, 'owner_id' => $r->owner_id, 'end_date' => $r->travel_to->toDateString()] : null;
    }

    /** CRM-BR-37: completadas o en operación. */
    public function tripsToClose(?string $ownerId): array
    {
        return Reservation::whereIn('status', [Reservation::COMPLETED, Reservation::IN_OPERATION])->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))
            ->with('client:id,code,name')->orderByDesc('travel_to')->limit(200)->get()
            ->map(fn (Reservation $r) => ['id' => $r->id, 'code' => $r->code, 'client_id' => $r->client_id, 'client_code' => $r->client?->code, 'client_name' => $r->client?->name,
                'destination' => $this->destinationOf($r), 'start_date' => $r->travel_from->toDateString(), 'end_date' => $r->travel_to->toDateString(), 'status' => $r->status])->all();
    }

    public function clientTimeline(string $clientId, ?string $ownerId): array
    {
        $events = parent::clientTimeline($clientId, $ownerId);
        foreach (Reservation::where('client_id', $clientId)->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))->get() as $r) {
            $events[] = ['type' => 'reservation', 'title' => "Reserva {$r->code} creada: {$r->title}", 'at' => $r->created_at, 'ref' => $r->code];
            if ($r->cancelled_at) {
                $events[] = ['type' => 'reservation', 'title' => "Reserva {$r->code} cancelada", 'at' => $r->cancelled_at, 'ref' => $r->code];
            }
            foreach (Payment::where('reservation_id', $r->id)->where('kind', 'cobro')->get() as $p) {
                $events[] = ['type' => 'payment', 'title' => "Pago {$p->code} de {$p->currency} {$p->amount} ({$r->code})", 'at' => $p->created_at, 'ref' => $p->code];
            }
        }

        return $events;
    }

    /** §7.4 "Próximos viajes" del asesor. */
    public function salesPanel(string $userId): array
    {
        $today = app(CrmClock::class)->today()->toDateString();
        $trips = Reservation::where('owner_id', $userId)->whereNotIn('status', Reservation::CLOSED)->whereDate('travel_from', '>=', $today)
            ->with('client:id,name')->orderBy('travel_from')->limit(5)->get()
            ->map(fn (Reservation $r) => ['code' => $r->code, 'client' => $r->client?->name, 'destination' => $this->destinationOf($r) ?? $r->title, 'start_date' => $r->travel_from->toDateString()])->all();

        return ['upcoming_trips' => $trips] + parent::salesPanel($userId);
    }

    private function destinationOf(Reservation $r): ?string
    {
        return BookedService::where('reservation_id', $r->id)->whereNotNull('product_id')->orderBy('service_date')->with('product.primaryDestination')->first()?->product?->primaryDestination?->name;
    }

    /** @return list<string> */
    private function destinations(Collection $reservations): array
    {
        return $reservations->map(fn (Reservation $r) => $this->destinationOf($r))->filter()->unique()->values()->all();
    }
}
