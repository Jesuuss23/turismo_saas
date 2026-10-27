<?php

namespace App\Services\Ops;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Catalog\ProductInclusion;
use App\Models\Iam\User;
use App\Models\Ops\Assignment;
use App\Models\Ops\Incident;
use App\Models\Ops\Order;
use App\Models\Ops\RoomingGuest;
use App\Models\Ops\RoomingList;
use App\Models\Ops\TripMessage;
use App\Models\Ops\Voucher;
use App\Models\Sys\NotifMessage;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/** App del pasajero (PROC-057): su reserva (PAX-BR-01), itinerario, vouchers no anulados (PAX-BR-02), alertas, chat y soporte. */
class PassengerApp
{
    /** §12-20: número 24/7 configurable por tenant (`settings.operations.emergency_phone`). */
    public const DEFAULT_EMERGENCY = '+51 984 410 663';

    public const QUICK_REPLIES = ['¿A qué hora me recogen?', 'Llegaré tarde al punto de encuentro', 'Necesito ayuda con mi voucher', 'Tengo una consulta sobre mi hotel'];

    public function __construct(
        private readonly CrmClock $clock,
        private readonly TenantContext $tenant,
        private readonly Notify $notify,
    ) {}

    /** PAX-BR-01: el pasajero ve su reserva; un interno con `operations.assign` previsualiza cualquiera (`?reservation=`). */
    public function reservationFor(User $user, ?string $code = null): Reservation
    {
        $reservation = $user->isExternal()
            ? ($user->reservation_id ? Reservation::find($user->reservation_id) : null)
            : ($user->hasPermissionTo(OpsAccess::ASSIGN) ? $this->preview($code) : null);
        if (! $reservation || $reservation->status === Reservation::CANCELLED && $user->isExternal()) {
            throw new ApiException(404, 'TRIP_NOT_FOUND', 'PAX-BR-01', 'No encontramos un viaje vinculado a su cuenta.');
        }

        return $reservation;
    }

    /** Vista previa interna: la reserva pedida o la del primer pasajero con cuenta vinculada. */
    private function preview(?string $code): ?Reservation
    {
        if ($code) {
            return Reservation::where('code', $code)->first();
        }
        $linked = User::whereNotNull('reservation_id')->orderBy('created_at')->pluck('reservation_id');

        return Reservation::whereIn('id', $linked)->where('status', '<>', Reservation::CANCELLED)->orderByDesc('travel_from')->first();
    }

    public function trip(Reservation $r): array
    {
        $today = $this->clock->today();
        $from = $r->travel_from->copy();
        $days = (int) $from->diffInDays($r->travel_to) + 1;
        $state = match (true) {
            $today->lt($from) => ['key' => 'upcoming', 'label' => 'Faltan '.(int) $today->diffInDays($from).' día(s)'],
            $today->gt($r->travel_to) || $r->status === Reservation::COMPLETED => ['key' => 'finished', 'label' => 'Viaje finalizado'],
            default => ['key' => 'ongoing', 'label' => 'En curso · día '.((int) $from->diffInDays($today) + 1)." de {$days}"],
        };
        $next = $this->nextActivity($r);
        $critical = Incident::where('reservation_id', $r->id)->where('severity', 'critica')->whereIn('status', Incident::ACTIVE)->latest()->first();

        return [
            'reservation' => ['code' => $r->code, 'title' => $r->title, 'status' => $r->status, 'status_label' => Reservation::STATUS_LABELS[$r->status] ?? $r->status,
                'travel_from' => $r->travel_from->toDateString(), 'travel_to' => $r->travel_to->toDateString(), 'pax' => Passenger::where('reservation_id', $r->id)->count()],
            'state' => $state, 'days' => $days,
            'critical_alert' => $critical ? ['code' => $critical->code, 'title' => $critical->title, 'type' => $critical->type] : null,
            'next_activity' => $next, 'hotel' => $this->hotels($r)->first(),
            'unread_alerts' => $this->alertsQuery($r)->whereNull('read_at')->count(),
            'unread_messages' => TripMessage::where('reservation_id', $r->id)->where('sender_type', '<>', 'passenger')->whereNull('read_at')->count(),
            'emergency' => $this->emergencyNumber(),
        ];
    }

    /** PAX-BR-03: primer servicio con proveedor, con hora, no completado, posterior a ahora. */
    public function nextActivity(Reservation $r): ?array
    {
        $now = $this->clock->now();
        $service = $this->services($r)->filter(fn (BookedService $s) => $s->provider_id && $s->service_time && $s->status !== BookedService::COMPLETED
            && $s->service_kind !== 'alojamiento' && $s->service_date->copy()->setTimeFromTimeString((string) $s->service_time)->gt($now))->first();

        return $service ? $this->activity($service) + ['starts_at' => $service->service_date->toDateString().'T'.substr((string) $service->service_time, 0, 5)] : null;
    }

    /** PAX-BR-02: día por día, sin el alojamiento como actividad; noche en hotel y habitación. */
    public function itinerary(Reservation $r): array
    {
        $services = $this->services($r)->where('service_kind', '<>', 'alojamiento');
        $hotels = $this->hotels($r);
        $days = [];
        for ($d = $r->travel_from->copy(); $d->lte($r->travel_to); $d->addDay()) {
            $date = $d->toDateString();
            $night = $hotels->first(fn ($h) => $h['check_in'] <= $date && $date < $h['check_out']);
            $days[] = [
                'date' => $date, 'n' => count($days) + 1,
                'services' => $services->filter(fn (BookedService $s) => $s->service_date->toDateString() === $date)->map(fn ($s) => $this->activity($s))->values()->all(),
                'night' => $night,
            ];
        }

        return $days;
    }

    public function vouchers(Reservation $r): Collection
    {
        return Voucher::where('reservation_id', $r->id)->where('status', '<>', Voucher::VOIDED)->with('provider:id,name')->orderBy('service_date')->get();
    }

    public function documents(Reservation $r): array
    {
        $passengers = Passenger::where('reservation_id', $r->id)->orderBy('seq')->get();

        return [
            ['key' => 'itinerary', 'label' => 'Itinerario del viaje', 'kind' => 'Itinerario', 'available' => true],
            ['key' => 'confirmation', 'label' => "Confirmación de reserva {$r->code}", 'kind' => 'Comprobante', 'available' => true],
            ['key' => 'policies', 'label' => 'Políticas de cancelación y cambios', 'kind' => 'Políticas', 'available' => true],
            ['key' => 'insurance', 'label' => 'Póliza de asistencia al viajero', 'kind' => 'Póliza', 'available' => false],
            ...$passengers->filter(fn ($p) => $p->doc_number)->map(fn (Passenger $p) => [
                'key' => "doc-{$p->id}", 'label' => "{$p->doc_type} · {$p->full_name}", 'kind' => 'Documento registrado', 'available' => true,
                'detail' => '••••'.substr((string) $p->doc_number, -4),
            ])->values()->all(),
        ];
    }

    /** Alertas del viaje (§12-16): notif_messages del pasajero vinculadas a la reserva. */
    public function alerts(User $user, Reservation $r): Collection
    {
        return $this->alertsQuery($r, $user)->orderByDesc('created_at')->limit(50)->get();
    }

    public function markRead(User $user, Reservation $r, string $id): void
    {
        $this->alertsQuery($r, $user)->whereKey($id)->update(['read_at' => now()]);
    }

    public function messages(Reservation $r, User $user): Collection
    {
        if ($user->isExternal()) {
            TripMessage::where('reservation_id', $r->id)->where('sender_type', '<>', 'passenger')->whereNull('read_at')->update(['read_at' => now()]);
        }

        return TripMessage::where('reservation_id', $r->id)->orderBy('created_at')->get();
    }

    public function postMessage(Reservation $r, User $user, string $body, bool $quick = false): TripMessage
    {
        $body = trim($body);
        if ($body === '') {
            throw ApiException::invalid('body', 'Escriba su mensaje.');
        }
        $passenger = $user->isExternal();
        $message = TripMessage::create(['reservation_id' => $r->id, 'sender_type' => $passenger ? 'passenger' : 'agency', 'sender_user_id' => $user->id,
            'body' => mb_substr($body, 0, 2000), 'quick_reply' => $quick]);
        if ($passenger) {
            // §12-17: hasta integrar la Bandeja omnicanal, el mensaje llega al asesor y al coordinador.
            $order = Order::where('reservation_id', $r->id)->first();
            foreach (array_filter([$r->owner, $order?->coordinator]) as $to) {
                $this->notify->push('pasajero.mensaje', 'portal', $to, "Mensaje del pasajero · {$r->code}", mb_substr($body, 0, 140),
                    ['client_id' => $r->client_id, 'related_type' => 'reservation', 'related_id' => $r->id]);
            }
        } else {
            foreach (User::where('reservation_id', $r->id)->get() as $pax) {
                $this->notify->push('agencia.mensaje', 'push', $pax, 'Nuevo mensaje de la agencia', mb_substr($body, 0, 140),
                    ['related_type' => 'reservation', 'related_id' => $r->id, 'reservation_id' => $r->id]);
            }
        }

        return $message;
    }

    public function emergencyNumber(): string
    {
        return (string) data_get($this->tenant->tenant()?->settings, 'operations.emergency_phone', self::DEFAULT_EMERGENCY);
    }

    private function alertsQuery(Reservation $r, ?User $user = null)
    {
        $users = $user ? [$user->id] : User::where('reservation_id', $r->id)->pluck('id')->all();

        return NotifMessage::whereIn('user_id', $users)->whereIn('channel', ['push', 'portal'])
            ->where(fn ($q) => $q->where('data->reservation_id', $r->id)->orWhere(fn ($q) => $q->where('related_type', 'reservation')->where('related_id', $r->id)));
    }

    public function services(Reservation $r): Collection
    {
        return BookedService::where('reservation_id', $r->id)->whereNotIn('status', [BookedService::CANCELLED, BookedService::REJECTED])
            ->with(['provider:id,name,phone', 'product:id,ref,name'])->orderBy('service_date')->orderBy('service_time')->get();
    }

    public function activity(BookedService $s): array
    {
        $assignment = Assignment::where('reservation_service_id', $s->id)->with(['guide', 'driver', 'vehicle'])->first();
        $departure = $s->departure_id ? Departure::find($s->departure_id) : null;

        return [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'date' => $s->service_date->toDateString(),
            'time' => $s->service_time ? substr((string) $s->service_time, 0, 5) : null, 'kind' => $s->service_kind,
            'status' => $s->status, 'status_label' => BookedService::STATUS_LABELS[$s->status] ?? $s->status,
            'provider' => $s->provider?->name, 'confirmation_code' => $s->confirmation_code,
            'meeting_point' => $departure?->meeting_point ?? ($s->provider_id ? 'Lobby del hotel' : null),
            'guide' => $assignment?->guide ? ['name' => $assignment->guide->full_name, 'phone' => $assignment->guide->phone] : null,
            'driver' => $assignment?->driver ? ['name' => $assignment->driver->full_name, 'vehicle' => $assignment->vehicle?->label()] : null,
            'includes' => $s->product_id ? ProductInclusion::where('product_id', $s->product_id)->where('kind', 'include')->orderBy('sort_order')->pluck('text')->all() : [],
        ];
    }

    /** Hotel y habitación de las rooming lists (Mi viaje, noches del itinerario). */
    public function hotels(Reservation $r): Collection
    {
        $passengers = Passenger::where('reservation_id', $r->id)->pluck('id');

        return RoomingList::where('reservation_id', $r->id)->with('hotel:id,name,phone')->orderBy('check_in')->get()->map(function (RoomingList $l) use ($passengers) {
            $guest = RoomingGuest::where('rooming_list_id', $l->id)->whereIn('passenger_id', $passengers)->with('room')->first();

            return ['name' => $l->hotel?->name, 'phone' => $l->hotel?->phone, 'check_in' => $l->check_in->toDateString(), 'check_out' => $l->check_out->toDateString(),
                'room' => $guest?->room?->room_number, 'room_type' => $guest?->room?->room_type];
        });
    }
}
