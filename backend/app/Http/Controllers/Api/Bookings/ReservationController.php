<?php

namespace App\Http\Controllers\Api\Bookings;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Bookings\ReservationPresenter;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\Catalog\Provider;
use App\Models\Catalog\Rate;
use App\Models\Crm\Client;
use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentMethod;
use App\Models\Iam\User;
use App\Models\Sys\AuditEvent;
use App\Services\Bookings\BookingAccess;
use App\Services\Bookings\BookingInventory;
use App\Services\Bookings\CancellationService;
use App\Services\Bookings\ReservationService;
use App\Services\Bookings\SupplierFlow;
use App\Services\Catalog\AvailabilityService;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmUsers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Spec 04 §6: lista, KPIs, ocupación, reserva directa, Reserva 360, historial y documento de confirmación. */
class ReservationController extends Controller
{
    public function __construct(
        protected readonly BookingAccess $access,
        protected readonly ReservationPresenter $present,
        protected readonly ReservationService $reservations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $query = $this->filtered($request, $user)->with(['client:id,code,name', 'quote:id,code', 'services'])->withCount('passengers')
            ->orderByDesc('travel_from')->orderByDesc('id');
        $perPage = min(200, max(1, (int) $request->input('per_page', 50)));
        $page = $query->cursorPaginate($perPage);
        $this->present->users->preloadUsers(collect($page->items())->pluck('owner_id'));

        return response()->json([
            'data' => collect($page->items())->map(fn (Reservation $r) => $this->present->row($r, $user))->all(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'per_page' => $perPage, 'own_only' => $this->access->ownOnly($user), 'view' => $this->present->view($user)],
        ]);
    }

    /** 4 KPIs con el alcance de la lista (§2). */
    public function kpis(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $today = $this->present->today()->toDateString();
        $rows = $this->scoped($request, $user)->withCount('passengers')->get(['id', 'status', 'travel_from', 'travel_to']);

        return response()->json(['data' => [
            'confirmed' => $rows->where('status', Reservation::CONFIRMED)->count(),
            'in_operation' => $rows->where('status', Reservation::IN_OPERATION)->count(),
            'pending' => $rows->whereIn('status', [Reservation::PENDING_PAYMENT, Reservation::PENDING_PROVIDER])->count(),
            'traveling_today' => $rows->filter(fn (Reservation $r) => $r->status !== Reservation::CANCELLED
                && $r->travel_from->toDateString() <= $today && $today <= $r->travel_to->toDateString())->sum('passengers_count'),
        ]]);
    }

    /** Calendario mensual (pax por día), próximos 14 días y salidas con cupos 14 días. */
    public function occupancy(Request $request, AvailabilityService $availability): JsonResponse
    {
        $user = $this->actor($request);
        $today = $this->present->today();
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month')) ? $request->input('month') : $today->format('Y-m');
        $start = Carbon::parse("{$month}-01");
        $end = $start->copy()->endOfMonth();
        $horizon = $today->copy()->addDays(13);
        $from = min($start, $today)->toDateString();
        $to = max($end, $horizon)->toDateString();
        $rows = $this->scoped($request, $user)->where('status', '<>', Reservation::CANCELLED)->withCount('passengers')
            ->whereDate('travel_from', '<=', $to)->whereDate('travel_to', '>=', $from)->with('client:id,name')->get();
        $paxOn = fn (string $d) => $rows->filter(fn (Reservation $r) => $r->travel_from->toDateString() <= $d && $d <= $r->travel_to->toDateString());

        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $on = $paxOn($d->toDateString());
            $days[] = ['date' => $d->toDateString(), 'pax' => $on->sum('passengers_count'), 'reservations' => $on->count(),
                'codes' => $on->take(4)->map(fn (Reservation $r) => ['code' => $r->code, 'client' => $r->client?->name, 'status' => $r->status])->values()->all()];
        }
        $next = [];
        for ($d = $today->copy(); $d->lte($horizon); $d->addDay()) {
            $next[] = ['date' => $d->toDateString(), 'pax' => $paxOn($d->toDateString())->sum('passengers_count')];
        }
        $departures = Departure::with('product:id,ref,name')->whereDate('departure_date', '>=', $today->toDateString())->whereDate('departure_date', '<=', $horizon->toDateString())
            ->where('status', '<>', 'cancelada')->orderBy('departure_date')->orderBy('departure_time')->get()
            ->map(fn (Departure $d) => ['ref' => $d->ref, 'product' => $d->product ? "{$d->product->ref} · {$d->product->name}" : null, 'date' => $d->departure_date->toDateString(),
                'time' => substr((string) $d->departure_time, 0, 5), 'capacity' => $d->capacity, 'sold' => $d->sold, 'available' => $availability->available($d), 'status' => $d->status])->all();

        return response()->json(['data' => ['month' => $month, 'days' => $days, 'next_days' => $next, 'departures' => $departures]]);
    }

    /** Pickers de la reserva directa y de los diálogos. */
    public function meta(Request $request, CrmAccess $crm, CrmUsers $users): JsonResponse
    {
        $user = $this->actor($request);
        $canCreate = $user->hasPermissionTo(BookingAccess::CREATE);

        return response()->json(['data' => [
            'statuses' => Reservation::STATUS_LABELS,
            'channels' => Reservation::CHANNELS,
            'clients' => $canCreate ? $crm->clients(Client::query(), $user)->orderBy('name')->limit(500)->get(['id', 'code', 'name', 'segment_code'])
                ->map(fn (Client $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'channel' => ReservationService::channelFor($c)])->all() : [],
            'owners' => $this->access->ownOnly($user) ? [['id' => $user->id, 'name' => $user->name]]
                : $users->advisors()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->roleName()])->values()->all(),
            'cancel_reasons' => CancellationService::REASONS,
            'reject_reasons' => SupplierFlow::REJECT_REASONS,
            'payment_methods' => PaymentMethod::where('active', true)->where('is_b2b_credit', false)->orderBy('label')->pluck('label')->all(),
            'providers' => $user->hasPermissionTo(BookingAccess::MODIFY) ? Provider::where('status', Provider::ACTIVE)->orderBy('name')->get(['id', 'name', 'type', 'status', 'contract_end'])
                ->filter(fn (Provider $p) => $p->isUsable())->map(fn (Provider $p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type])->values()->all() : [],
            'today' => $this->present->today()->toDateString(),
            'own_only' => $this->access->ownOnly($user),
            'can' => [
                'create' => $canCreate, 'modify' => $user->hasPermissionTo(BookingAccess::MODIFY), 'cancel' => $user->hasPermissionTo(BookingAccess::CANCEL),
                'approve_changes' => $user->hasPermissionTo(BookingAccess::APPROVE_CHANGES), 'payments' => $user->hasPermissionTo(BookingAccess::PAYMENTS),
                'receivables' => $user->hasPermissionTo(BookingAccess::RECEIVABLES), 'reassign' => $user->hasPermissionTo(BookingAccess::REASSIGN),
            ],
            'view' => $this->present->view($user),
        ]]);
    }

    /** Productos para la reserva directa: ACTIVE + bookable (los demás deshabilitados con motivo), tarifas y salidas. */
    public function products(Request $request, AvailabilityService $availability): JsonResponse
    {
        $today = $this->present->today()->toDateString();
        $products = Product::with(['rates', 'type'])->where('status', '<>', Product::ARCHIVED)->orderBy('ref')->get();
        $departures = Departure::whereIn('product_id', $products->pluck('id'))->whereDate('departure_date', '>=', $today)->where('status', '<>', 'cancelada')
            ->orderBy('departure_date')->orderBy('departure_time')->get()->groupBy('product_id');

        return response()->json(['data' => $products->map(fn (Product $p) => [
            'id' => $p->id, 'ref' => $p->ref, 'name' => $p->name, 'type' => $p->type?->name, 'availability_model' => $p->availability_model,
            'by_departure' => BookingInventory::bySalida($p), 'advance_min_days' => $p->booking_advance_min_days, 'max_pax' => $p->maximum_passengers,
            'duration' => $p->duration_value.' '.$p->duration_unit,
            'blocked_reason' => match (true) {
                $p->status !== Product::ACTIVE => "Producto {$p->status}: no se puede reservar (PRD-BR-002)",
                ! $p->is_bookable => 'Producto no reservable (PRD-BR-002)',
                default => null,
            },
            'rates' => $p->rates->filter(fn (Rate $r) => $r->status !== 'inactiva' && $r->valid_to->toDateString() >= $today)->sortBy('ref')->values()->map(fn (Rate $r) => [
                'id' => $r->id, 'ref' => $r->ref, 'segment' => $r->segment, 'channel' => $r->channel, 'currency' => $r->currency, 'price' => (string) $r->price,
                'valid_from' => $r->valid_from->toDateString(), 'valid_to' => $r->valid_to->toDateString(),
            ])->all(),
            'departures' => BookingInventory::bySalida($p) ? ($departures[$p->id] ?? collect())->map(fn (Departure $d) => [
                'id' => $d->id, 'ref' => $d->ref, 'date' => $d->departure_date->toDateString(), 'time' => substr((string) $d->departure_time, 0, 5),
                'capacity' => $d->capacity, 'available' => $availability->available($d), 'status' => $d->status,
            ])->values()->all() : [],
        ])->all()]);
    }

    public function availabilityCheck(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reservations->directPreview($this->directData($request), $this->actor($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->directData($request) + $request->validate([
            'channel' => ['nullable', Rule::in(Reservation::CHANNELS)], 'owner_id' => 'nullable|uuid', 'title' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:5000', 'confirm' => 'boolean',
        ]);
        $reservation = $this->reservations->createDirect($data, $this->actor($request));

        return response()->json(['data' => $this->present->detail($reservation, $this->actor($request))], 201);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->present->detail($this->find($code, $request), $this->actor($request))]);
    }

    /** Auditoría de la reserva, sus servicios, CxC, pagos y notas de crédito. */
    public function history(Request $request, string $code): JsonResponse
    {
        $r = $this->find($code, $request);
        $refs = [
            'Reserva' => [$r->code],
            'Servicio reserva' => $r->services()->pluck('code')->all(),
            'Cuenta por cobrar' => $r->receivables()->pluck('code')->all(),
            'Pago' => Payment::where('reservation_id', $r->id)->pluck('code')->all(),
            'Comprobante' => Invoice::where('reservation_id', $r->id)->pluck('number')->all(),
        ];
        $finance = ! $this->access->opsView($this->actor($request), $r);
        $rows = AuditEvent::where(function ($q) use ($refs, $finance) {
            foreach ($refs as $entity => $ids) {
                if ($ids && ($finance || in_array($entity, ['Reserva', 'Servicio reserva'], true))) {
                    $q->orWhere(fn ($q) => $q->where('entity', $entity)->whereIn('entity_id', $ids));
                }
            }
        })->orderByDesc('occurred_at')->orderByDesc('id')->limit(500)->get();
        $this->present->users->preloadUsers($rows->pluck('actor_id'));

        return response()->json(['data' => $rows->map(fn (AuditEvent $e) => [
            'entity' => $e->entity, 'entity_id' => $e->entity_id, 'action' => $e->action, 'before' => $e->before_value, 'after' => $e->after_value,
            'actor' => $e->actor_id ? $this->present->users->user($e->actor_id) : ['name' => 'Sistema'], 'at' => $e->occurred_at,
        ])->all()]);
    }

    /** Documento de confirmación (vista de impresión): sin costos ni datos internos. */
    public function confirmation(Request $request, string $code): JsonResponse
    {
        $r = $this->find($code, $request);
        $r->load(['client', 'passengers', 'services.provider', 'receivables']);

        return response()->json(['data' => [
            'code' => $r->code, 'title' => $r->title, 'status' => $r->status, 'status_label' => Reservation::STATUS_LABELS[$r->status],
            'client' => ['name' => $r->client?->name, 'code' => $r->client?->code, 'email' => $r->client?->email],
            'owner' => $this->present->users->user($r->owner_id), 'channel' => $r->channel,
            'travel_from' => $r->travel_from->toDateString(), 'travel_to' => $r->travel_to->toDateString(),
            'currency' => $r->currency, 'total' => (string) $r->total_amount,
            'passengers' => $r->passengers->map(fn ($p) => ['name' => $p->full_name, 'type' => $p->pax_type, 'doc' => trim("{$p->doc_type} {$p->doc_number}"), 'placeholder' => $p->is_placeholder])->all(),
            'services' => $r->services->filter(fn (BookedService $s) => $s->isActive() || $s->status === BookedService::COMPLETED)->map(fn (BookedService $s) => [
                'name' => $s->name, 'date' => $s->service_date->toDateString(), 'time' => $s->service_time ? substr((string) $s->service_time, 0, 5) : null,
                'pax' => $s->pax, 'status' => BookedService::STATUS_LABELS[$s->status], 'confirmation_code' => $s->confirmation_code,
            ])->values()->all(),
            'payment_plan' => $r->receivables->where('status', '<>', 'cancelada')->map(fn ($c) => ['concept' => $c->concept, 'amount' => (string) $c->amount, 'due_date' => $c->due_date->toDateString(), 'status' => $c->status])->values()->all(),
            'policies' => array_values($r->policies_snapshot ?? []),
            'issued_at' => now()->toIso8601String(),
        ]]);
    }

    protected function actor(Request $request): User
    {
        return $request->user();
    }

    /** Reserva por código o uuid dentro del alcance (otro tenant → 404 por RLS; ajena → 403). */
    protected function find(string $code, Request $request): Reservation
    {
        $reservation = Reservation::where(fn ($q) => Str::isUuid($code) ? $q->whereKey($code) : $q->where('code', $code))->first() ?? throw ApiException::notFound();
        $this->access->ensureVisible($reservation, $this->actor($request));

        return $reservation;
    }

    private function directData(Request $request): array
    {
        return $request->validate([
            'client_id' => 'nullable|uuid', 'product_id' => 'nullable|uuid', 'rate_id' => 'nullable|uuid', 'departure_id' => 'nullable|uuid', 'date' => 'nullable|date',
            'adt' => 'nullable|integer|min:0|max:999', 'chd' => 'nullable|integer|min:0|max:999', 'inf' => 'nullable|integer|min:0|max:999',
        ]);
    }

    private function scoped(Request $request, User $user): Builder
    {
        return $this->access->scope(Reservation::query(), $user)
            ->when(! $this->access->ownOnly($user) && $request->input('filter.owner'), fn ($q, $owner) => $q->where('owner_id', $owner === 'me' ? $user->id : $owner));
    }

    private function filtered(Request $request, User $user): Builder
    {
        return $this->scoped($request, $user)
            ->when($request->input('filter.status'), fn ($q, $status) => $status === 'pendientes'
                ? $q->whereIn('status', [Reservation::PENDING_PAYMENT, Reservation::PENDING_PROVIDER]) : $q->where('status', $status))
            ->when($request->input('filter.channel'), fn ($q, $channel) => $q->where('channel', $channel))
            ->when(trim((string) $request->input('filter.q')), function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($q) => $q->where('code', 'ilike', $like)->orWhere('title', 'ilike', $like)
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)));
            });
    }
}
