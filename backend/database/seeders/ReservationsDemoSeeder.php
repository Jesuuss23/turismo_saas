<?php

namespace Database\Seeders;

use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\Catalog\Rate;
use App\Models\Crm\Client;
use App\Models\Iam\User;
use App\Models\Quotes\Quote;
use App\Services\Bookings\CancellationService;
use App\Services\Bookings\ChangeService;
use App\Services\Bookings\ReceivableService;
use App\Services\Bookings\ReservationService;
use App\Services\Bookings\SupplierFlow;
use App\Services\Catalog\AvailabilityService;
use App\Services\Crm\CrmClock;
use App\Services\Quotes\QuoteService;
use App\Services\Quotes\QuoteWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Reservas de demostración del tenant "andes" (spec 04), creadas con los mismos servicios que la API:
 * una convertida desde cotización con pago y proveedores en curso, una directa con modificación pendiente,
 * una con rechazo de proveedor y anulación solicitada, y una cancelada con penalidad. COT-2026-00194
 * queda aprobada para probar "Convertir en reserva" en vivo.
 */
class ReservationsDemoSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $u = [];

    public function run(): void
    {
        $this->u = User::whereIn('code', ['U01', 'U02', 'U03', 'U04', 'U05', 'U07'])->get()->keyBy('code')->all();
        $today = app(CrmClock::class)->today();
        $day = fn (int $n) => $today->copy()->addDays($n)->toDateString();
        $client = fn (string $code) => Client::where('code', $code)->firstOrFail();
        $product = fn (string $ref) => Product::where('ref', $ref)->firstOrFail();
        $rate = fn (string $ref, string $channel = 'Directo') => Rate::whereHas('product', fn ($q) => $q->where('ref', $ref))->where('segment', '<>', 'Niño')
            ->where('channel', $channel)->whereNull('season_id')->whereDate('valid_to', '>=', $today)->orderBy('ref')->first();
        $availability = app(AvailabilityService::class);
        $departure = fn (string $ref, int $minDays, int $pax) => Departure::where('product_id', $product($ref)->id)->whereDate('departure_date', '>=', $day($minDays))
            ->where('status', 'abierta')->orderBy('departure_date')->get()->first(fn (Departure $d) => $availability->available($d) >= $pax);
        $reservations = app(ReservationService::class);
        $supplier = app(SupplierFlow::class);

        // 1 · Carla · Ana Gutiérrez: cotización aprobada → convertida (RES-…350), pasajeros, proveedores y anticipo pagado.
        $mapi = $departure('PRD-001', 12, 2);
        $quote = $this->as('U03', fn () => app(QuoteService::class)->create([
            'client_id' => $client('CLI-0001')->id, 'title' => 'Machu Picchu en pareja', 'currency' => 'USD',
            'travel_from' => $mapi->departure_date->toDateString(), 'travel_to' => $mapi->departure_date->toDateString(),
            'pax_adt' => 2, 'pax_chd' => 0, 'pax_inf' => 0, 'discount_pct' => 0,
            'items' => [['product_id' => $product('PRD-001')->id, 'rate_id' => $rate('PRD-001')?->id, 'service_date' => $mapi->departure_date->toDateString()]],
        ], $this->u['U03']));
        $flow = app(QuoteWorkflow::class);
        $this->as('U03', fn () => $flow->send($quote, ['Email'], 'Propuesta Machu Picchu', $this->u['U03']));
        $this->as('U03', fn () => $flow->approve(Quote::find($quote->id), ['approved_by_name' => 'Ana Gutiérrez', 'channel' => 'Correo electrónico del cliente', 'ref' => 'Correo del 27/10'], $this->u['U03']));
        $q = Quote::find($quote->id);
        [$r1] = $this->as('U03', fn () => $reservations->convert($q, (int) $q->lock_version, $this->u['U03']));
        $pax = $r1->passengers()->get();
        $this->as('U05', fn () => $reservations->savePassenger($r1->fresh(), $pax[0], ['full_name' => 'Ana Gutiérrez Salas', 'doc_type' => 'DNI', 'doc_number' => '45678912',
            'nationality' => 'Peruana', 'birth_date' => '1990-04-12', 'relationship' => 'Titular', 'is_lead' => true, 'phone' => '+51 987 654 321'], $this->u['U05']));
        $this->as('U05', fn () => $reservations->savePassenger($r1->fresh(), $pax[1], ['full_name' => 'Jorge Ríos Vega', 'doc_type' => 'DNI', 'doc_number' => '44321987',
            'nationality' => 'Peruana', 'birth_date' => '1988-09-03', 'relationship' => 'Cónyuge / pareja', 'diet_notes' => 'Vegetariano'], $this->u['U05']));
        $this->as('U05', fn () => $supplier->requestPending($r1->fresh(), $this->u['U05']));
        $first = $r1->services()->first();
        $this->as('U05', fn () => $supplier->confirm($r1->fresh(), $first, ['confirmation_code' => 'PR-88412', 'confirmed_by' => 'Mesa PeruRail'], $this->u['U05']));
        $anticipo = $r1->receivables()->where('concept', 'like', 'Anticipo%')->first();
        $this->as('U07', fn () => app(ReceivableService::class)->applyPayment($anticipo, ['amount' => (string) $anticipo->amount, 'method' => 'Transferencia', 'reference' => 'BCP OP-552190'], $this->u['U07']));

        // 2 · Diego · Luis Ramírez: reserva directa confirmada (traslado) y modificación de Operaciones pendiente de gerencia.
        $r2 = $this->as('U04', fn () => $reservations->createDirect([
            'client_id' => $client('CLI-0006')->id, 'product_id' => $product('PRD-009')->id, 'rate_id' => $rate('PRD-009', 'Todos')?->id,
            'date' => $day(9), 'adt' => 2, 'title' => 'Traslado aeropuerto — Luis Ramírez', 'confirm' => true,
        ], $this->u['U04']));
        $this->as('U05', fn () => app(ChangeService::class)->request($r2->fresh(), ['type' => 'pax', 'extra_pax' => 1, 'description' => 'Se suma su hermano en el traslado'], $this->u['U05']));

        // 3 · Diego · Minera del Sur: Valle Sagrado VIP, rechazo del proveedor con alternativa y anulación solicitada por el asesor.
        $valle = $departure('PRD-003', 8, 4);
        $r3 = $this->as('U04', fn () => $reservations->createDirect([
            'client_id' => $client('CLI-0002')->id, 'product_id' => $product('PRD-003')->id, 'rate_id' => $rate('PRD-003')?->id,
            'departure_id' => $valle->id, 'adt' => 4, 'title' => 'Valle Sagrado VIP — gerencia Minera del Sur', 'confirm' => true,
        ], $this->u['U04']));
        $this->as('U05', fn () => $supplier->requestPending($r3->fresh(), $this->u['U05']));
        $service = $r3->services()->first();
        $this->as('U05', fn () => $supplier->reject($r3->fresh(), $service, ['reason' => 'Sin disponibilidad', 'detail' => 'Bus VIP en mantenimiento',
            'alternative_provider_id' => 'same', 'alternative_date' => $valle->departure_date->copy()->addDay()->toDateString()], $this->u['U05']));
        $this->as('U04', fn () => app(CancellationService::class)->request($r3->fresh(), 'Solicitud del cliente', 'La gerencia reprograma su visita a Cusco', $this->u['U04']));

        // 4 · Carla · John Miller: City Tour cancelado por Gerencia (dentro de 7 días: penalidad 100 %).
        $r4 = $this->as('U03', fn () => $reservations->createDirect([
            'client_id' => $client('CLI-0003')->id, 'product_id' => $product('PRD-002')->id, 'rate_id' => $rate('PRD-002')?->id,
            'date' => $day(4), 'adt' => 2, 'title' => 'City Tour Cusco — John Miller', 'confirm' => true,
        ], $this->u['U03']));
        $this->as('U02', fn () => app(CancellationService::class)->cancel(Reservation::find($r4->id), 'Salud', 'Pasajero con mal de altura; no viajará', $this->u['U02']));

        // 5 · COT-2026-00194 (María): Operaciones programa la salida de Vinicunca del día 26 para que la conversión en vivo tenga cupo.
        $vinicunca = $product('PRD-004');
        if (! Departure::where('product_id', $vinicunca->id)->whereDate('departure_date', $day(26))->exists()) {
            $this->as('U05', fn () => $availability->createDeparture($vinicunca, ['departure_date' => $day(26), 'departure_time' => '03:30', 'capacity' => 20,
                'meeting_point' => 'Plaza de Armas de Cusco', 'return_point' => 'Hotel del pasajero'], $this->u['U05']));
        }
    }

    private function as(string $code, callable $fn): mixed
    {
        Auth::setUser($this->u[$code]);

        return $fn();
    }
}
