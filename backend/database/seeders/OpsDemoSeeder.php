<?php

namespace Database\Seeders;

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Product;
use App\Models\Catalog\Provider;
use App\Models\Catalog\Rate;
use App\Models\Catalog\SupplierService;
use App\Models\Crm\Client;
use App\Models\Iam\User;
use App\Models\Ops\Assignment;
use App\Models\Ops\Checkin;
use App\Models\Ops\Driver;
use App\Models\Ops\Guide;
use App\Models\Ops\Order;
use App\Models\Ops\TripMessage;
use App\Models\Ops\Vehicle;
use App\Services\Bookings\ReservationService;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\Ops\AssignmentService;
use App\Services\Ops\EvidenceService;
use App\Services\Ops\IncidentService;
use App\Services\Ops\OrderService;
use App\Services\Ops\RoomingService;
use App\Services\Ops\VoucherService;
use App\Services\SequenceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Demo de Operaciones (spec 05): recursos vinculados a los usuarios guía/conductor, órdenes en distintos estados,
 * y el viaje en curso de John Miller (pasajero U15) para las apps del operador y del pasajero.
 */
class OpsDemoSeeder extends Seeder
{
    /** PNG 1×1 como evidencia de ejemplo. */
    public const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @var array<string, User> */
    private array $u = [];

    public function run(): void
    {
        $this->u = User::whereIn('code', ['U01', 'U02', 'U03', 'U05', 'U06', 'U09', 'U10', 'U11', 'U12', 'U15'])->get()->keyBy('code')->all();
        $today = app(CrmClock::class)->today();
        $day = fn (int $n) => $today->copy()->addDays($n)->toDateString();
        $orders = app(OrderService::class);
        $assign = app(AssignmentService::class);

        // Recursos (§3.7): los ids coinciden con los que ya usan las salidas del catálogo.
        $guide = fn (string $code, array $attrs) => Guide::create(['id' => DemoSeeder::linkId($code), 'code' => $code] + $attrs);
        $g1 = $guide('G01', ['full_name' => 'Wilber Condori', 'user_id' => $this->u['U09']->id, 'languages' => ['es', 'en', 'qu'], 'license_number' => 'GT-0451',
            'license_expires_on' => $day(400), 'rating' => 4.8, 'phone' => '+51 984 112 233']);
        $g2 = $guide('G02', ['full_name' => 'Nadia Flores', 'user_id' => $this->u['U10']->id, 'languages' => ['es', 'en', 'fr'], 'license_number' => 'GT-0518',
            'license_expires_on' => $day(20), 'rating' => 4.6, 'phone' => '+51 984 223 344']);
        $guide('G03', ['full_name' => 'Rubén Quispe', 'languages' => ['es', 'qu'], 'license_number' => 'GT-0377', 'license_expires_on' => $day(210), 'rating' => 4.3,
            'status' => 'vacaciones', 'phone' => '+51 984 334 455']);
        $driver = fn (string $code, array $attrs) => Driver::create(['id' => DemoSeeder::linkId($code), 'code' => $code] + $attrs);
        $c1 = $driver('C01', ['full_name' => 'Raúl Mamani', 'user_id' => $this->u['U11']->id, 'license_number' => 'Q-40551822', 'license_class' => 'A-IIb',
            'license_expires_on' => $day(300), 'phone' => '+51 974 556 677']);
        $c2 = $driver('C02', ['full_name' => 'Elmer Huamán', 'license_number' => 'Q-41233901', 'license_class' => 'A-IIIa', 'license_expires_on' => $day(12),
            'phone' => '+51 974 667 788']);
        $vehicle = fn (string $code, array $attrs) => Vehicle::create(['id' => DemoSeeder::linkId($code), 'code' => $code] + $attrs);
        $v1 = $vehicle('V01', ['plate' => 'CUS-123', 'vehicle_type' => 'Sprinter', 'capacity' => 15, 'soat_expires_on' => $day(200)]);
        $v2 = $vehicle('V02', ['plate' => 'CUS-456', 'vehicle_type' => 'Bus', 'capacity' => 30, 'soat_expires_on' => $day(25)]);
        $vehicle('V03', ['plate' => 'CUS-789', 'vehicle_type' => 'Van', 'capacity' => 12, 'soat_expires_on' => $day(-3), 'status' => 'mantenimiento']);

        // 1 · Machu Picchu en pareja (RES-…350): orden pendiente de asignación; el guía ya está asignado.
        $mapi = Reservation::where('title', 'Machu Picchu en pareja')->firstOrFail();
        $o1 = $this->as('U05', fn () => $orders->create($mapi, $this->u['U05']->id, $this->u['U05']));
        $guideService = $o1->assignments()->get()->first(fn (Assignment $a) => str_starts_with($a->service_label, 'Guía'));
        $this->as('U05', fn () => $assign->assign($guideService, ['guide_id' => $g2->id, 'ack_warnings' => true], $this->u['U05']));

        // 2 · Traslado aeropuerto de Luis Ramírez (RES-…351): orden recién creada por Javier.
        $transfer = Reservation::where('title', 'Traslado aeropuerto — Luis Ramírez')->firstOrFail();
        $this->as('U06', fn () => $orders->create($transfer, $this->u['U06']->id, $this->u['U06']));

        // 3 · Viaje en curso de John Miller: llegada ayer, City Tour hoy, salida en 2 días.
        $john = $this->johnTrip($day);
        // 4 · Reserva confirmada sin orden: candidata de "Nueva orden" (E2E-03).
        $product = Product::where('ref', 'PRD-002')->firstOrFail();
        $rate = Rate::where('product_id', $product->id)->where('segment', '<>', 'Niño')->where('channel', 'Directo')->whereNull('season_id')
            ->whereDate('valid_to', '>=', $day(12))->orderBy('ref')->first();
        $this->as('U03', fn () => app(ReservationService::class)->createDirect([
            'client_id' => Client::where('code', 'CLI-0003')->value('id'), 'product_id' => $product->id, 'rate_id' => $rate?->id,
            'date' => $day(12), 'adt' => 2, 'title' => 'Cusco de regreso — John y Emily Miller', 'confirm' => true,
            'notes' => 'Segunda visita de la pareja; desean guía en inglés.',
        ], $this->u['U03']));

        $o3 = $this->as('U05', fn () => $orders->create($john, $this->u['U12']->id, $this->u['U05']));
        $byLabel = fn (string $prefix, string $date) => $o3->assignments()->get()->first(fn (Assignment $a) => str_starts_with($a->service_label, $prefix) && $a->service_date->toDateString() === $date);
        $arrival = $byLabel('Traslado aeropuerto', $day(-1));
        $cityBus = $byLabel('Movilidad', $day(0));
        $cityGuide = $byLabel('Guía', $day(0));
        $departure = $byLabel('Traslado hotel', $day(2));
        $this->as('U05', function () use ($assign, $arrival, $cityBus, $cityGuide, $departure, $g1, $c1, $c2, $v1, $v2) {
            $assign->assign($arrival, ['driver_id' => $c2->id, 'vehicle_id' => $v2->id, 'ack_warnings' => true], $this->u['U05']);
            $assign->assign($cityBus, ['driver_id' => $c1->id, 'vehicle_id' => $v1->id, 'ack_warnings' => true], $this->u['U05']);
            $assign->assign($cityGuide, ['guide_id' => $g1->id, 'ack_warnings' => true], $this->u['U05']);
            $assign->assign($departure, ['driver_id' => $c1->id, 'vehicle_id' => $v2->id, 'ack_warnings' => true], $this->u['U05']);
            foreach ([$arrival, $cityBus, $cityGuide, $departure] as $a) {
                $assign->confirm($a->fresh(), $this->u['U05']);
            }
        });
        $o3 = $o3->fresh();
        if ($o3->status === Order::CONFIRMED) {
            $this->as('U12', fn () => $orders->start($o3, $this->u['U12']));
        }
        // Llegada de ayer ejecutada: check-in de ambos pasajeros, evidencia y fin.
        $arrival = $arrival->fresh();
        $passengers = $john->passengers()->orderBy('seq')->get();
        $started = $today->copy()->subDay()->setTime(10, 40);
        $arrival->forceFill(['status' => Assignment::COMPLETED, 'started_at' => $started, 'first_checkin_at' => $started->copy()->addMinutes(12),
            'finished_at' => $started->copy()->addMinutes(55)])->save();
        foreach ($passengers as $p) {
            Checkin::create(['assignment_id' => $arrival->id, 'passenger_id' => $p->id, 'checked_at' => $started->copy()->addMinutes(12), 'source' => 'web', 'checked_by' => $this->u['U12']->id]);
        }
        BookedService::whereKey($arrival->reservation_service_id)->update(['status' => BookedService::COMPLETED]);
        $this->as('U12', fn () => app(EvidenceService::class)->store(['file_name' => 'llegada-aeropuerto.png', 'mime_type' => 'image/png', 'content_base64' => self::PIXEL,
            'captured_at' => $started->copy()->addMinutes(15)->toIso8601String()], $o3, BookedService::find($arrival->reservation_service_id), null, $this->u['U12']));

        // Rooming (Hotel Inti Wasi), vouchers e incidencias.
        $rooming = app(RoomingService::class);
        $hotel = Provider::where('name', 'like', 'Hotel Inti Wasi%')->firstOrFail();
        $list = $this->as('U12', fn () => $rooming->create($john, $hotel->id, $day(-1), $day(2), $this->u['U12']));
        $this->as('U12', fn () => $rooming->addRoom($list, ['room_number' => '304', 'room_type' => 'Matrimonial', 'status' => 'confirmada',
            'passenger_ids' => $passengers->pluck('id')->all()], $this->u['U12']));
        $this->as('U12', fn () => $rooming->confirm($list->fresh(), $this->u['U12']));
        $this->as('U12', fn () => $rooming->send($list->fresh(), $this->u['U12']));

        $vouchers = app(VoucherService::class);
        foreach (BookedService::where('reservation_id', $john->id)->where('status', BookedService::CONFIRMED)->orderBy('service_date')->get() as $service) {
            $this->as('U12', fn () => $vouchers->issue($service, null, $this->u['U12']));
        }

        $incidents = app(IncidentService::class);
        $this->as('U12', function () use ($incidents, $o3, $cityGuide) {
            $i = $incidents->create(['order_id' => $o3->id, 'reservation_service_id' => $cityGuide->reservation_service_id, 'type' => 'Pasajero', 'severity' => 'media',
                'title' => 'Alergia a mariscos: confirmar menú', 'description' => 'Emily Miller es alérgica a mariscos; confirmar con el restaurante del almuerzo del City Tour.'], $this->u['U12']);
            $incidents->take($i, $this->u['U12']);
        });
        $this->as('U05', fn () => $incidents->create(['order_id' => $o1->id, 'type' => 'Transporte', 'severity' => 'critica',
            'title' => 'Paro de transportistas en Ollantaytambo', 'description' => 'Anuncian paro regional; evaluar traslado alternativo a la estación para la salida del grupo.'], $this->u['U05']));
        $this->as('U05', fn () => $incidents->create(['order_id' => $o1->id, 'type' => 'Proveedor', 'severity' => 'baja',
            'title' => 'PeruRail aún no confirma horario', 'description' => 'Solicitud enviada; reiterar por teléfono.'], $this->u['U05']));

        // App del pasajero: U15 queda vinculado a este viaje, con chat y alertas.
        $this->u['U15']->forceFill(['reservation_id' => $john->id])->save();
        TripMessage::create(['reservation_id' => $john->id, 'sender_type' => 'agency', 'sender_user_id' => $this->u['U12']->id,
            'body' => '¡Bienvenidos a Cusco! Su guía Wilber los recogerá en el lobby a las 13:30 para el City Tour.']);
        TripMessage::create(['reservation_id' => $john->id, 'sender_type' => 'passenger', 'sender_user_id' => $this->u['U15']->id,
            'body' => '¿Podemos llevar las mochilas grandes en la movilidad?', 'read_at' => now()]);
        app(Notify::class)->push('viaje.bienvenida', 'push', $this->u['U15'], 'Bienvenido a Cusco', 'Hoy: City Tour Cusco y 4 ruinas a las 13:30. Punto de encuentro: lobby del hotel.',
            ['related_type' => 'reservation', 'related_id' => $john->id, 'reservation_id' => $john->id]);
    }

    /** Reserva directa del City Tour (hoy) ampliada con llegada, hotel y salida; servicios confirmados por proveedor. */
    private function johnTrip(callable $day): Reservation
    {
        $reservations = app(ReservationService::class);
        $product = Product::where('ref', 'PRD-002')->firstOrFail();
        $rate = Rate::where('product_id', $product->id)->where('segment', '<>', 'Niño')->where('channel', 'Directo')->whereNull('season_id')
            ->whereDate('valid_to', '>=', $day(0))->orderBy('ref')->first();
        $r = $this->as('U03', fn () => $reservations->createDirect([
            'client_id' => Client::where('code', 'CLI-0003')->value('id'), 'product_id' => $product->id, 'rate_id' => $rate?->id,
            'date' => $day(3), 'adt' => 2, 'title' => 'Cusco esencial — John y Emily Miller', 'confirm' => true,
            'notes' => 'Celebran su aniversario. Prefieren guía en inglés.',
        ], $this->u['U03']));
        // RES-BR-06 exige anticipación: se vende para dentro de 3 días y la demo la trae a hoy (viaje en curso).
        BookedService::where('reservation_id', $r->id)->update(['service_date' => $day(0)]);
        [$p1, $p2] = $r->passengers()->orderBy('seq')->get()->all();
        $this->as('U05', fn () => $reservations->savePassenger($r->fresh(), $p1, ['full_name' => 'John Miller', 'doc_type' => 'Pasaporte', 'doc_number' => 'US5512309',
            'nationality' => 'Estadounidense', 'birth_date' => '1982-06-21', 'relationship' => 'Titular', 'is_lead' => true, 'phone' => '+1 415 555 0142',
            'email' => 'pasajero@correo.test'], $this->u['U05']));
        $this->as('U05', fn () => $reservations->savePassenger($r->fresh(), $p2, ['full_name' => 'Emily Miller', 'doc_type' => 'Pasaporte', 'doc_number' => 'US5512377',
            'nationality' => 'Estadounidense', 'birth_date' => '1984-02-03', 'relationship' => 'Cónyuge / pareja', 'diet_notes' => 'Alergia a mariscos'], $this->u['U05']));

        $sequences = app(SequenceService::class);
        $extra = function (string $serviceName, string $date, ?string $time, float $cost, float $price) use ($r, $sequences) {
            $service = SupplierService::where('name', $serviceName)->firstOrFail();

            return BookedService::create(BookedService::kindFor($service->type) + [
                'reservation_id' => $r->id, 'code' => $sequences->next('booking_service'), 'catalog_service_id' => $service->id,
                'provider_id' => $service->provider_id, 'name' => $serviceName, 'service_date' => $date, 'service_time' => $time, 'pax' => 2,
                'status' => BookedService::PENDING, 'cost_amount' => $cost, 'price_amount' => $price, 'is_required' => true,
            ]);
        };
        $this->as('U05', function () use ($extra, $day) {
            $extra('Traslado aeropuerto – hotel Cusco', $day(-1), '10:30', 35, 60);
            $extra('Habitación doble estándar Hotel Inti Wasi', $day(-1), null, 270, 390);
            $extra('Traslado aeropuerto – hotel Cusco', $day(2), '09:00', 35, 60);
        });
        // El regreso usa el mismo servicio de catálogo con nombre propio.
        BookedService::where('reservation_id', $r->id)->where('service_date', $day(2))->update(['name' => 'Traslado hotel – aeropuerto Cusco']);
        $r->forceFill(['travel_from' => $day(-1), 'travel_to' => $day(2)])->save();
        $codes = ['traslado' => 'QH-', 'tour' => 'GA-', 'alojamiento' => 'IW-'];
        foreach (BookedService::where('reservation_id', $r->id)->get() as $i => $s) {
            $s->forceFill(['status' => BookedService::CONFIRMED, 'confirmation_code' => ($codes[$s->service_kind] ?? 'CF-').(7710 + $i), 'confirmed_at' => now(),
                'confirmed_by' => $this->u['U05']->id, 'service_time' => $s->service_time ?? ($s->service_kind === 'tour' ? '13:30' : null)])->save();
        }

        return $r->fresh();
    }

    private function as(string $code, callable $fn): mixed
    {
        Auth::setUser($this->u[$code]);

        return $fn();
    }
}
