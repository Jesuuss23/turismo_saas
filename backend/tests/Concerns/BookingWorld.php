<?php

namespace Tests\Concerns;

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\AccommodationLeg;
use App\Models\Catalog\Product;
use App\Services\Catalog\Booking\AvailabilityRangeService;
use App\Services\Catalog\Booking\BookingConfigService;
use App\Services\SequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Escenario del motor de reservas (spec 16 §10.1) sobre CatalogWorld, con «hoy» = 2026-09-29 09:00 (Lima): circuito «Perú Circuito
 * 10 días» (PER-CIR-10D, USD Adulto 1 490 / Niño 1 090, anticipación 48 h) con rangos RG-1101 disponible 02–06 oct (12/día), RG-1102
 * cerrado 07–09 oct y RG-1103 disponible 10–14 oct (12/día); pasajeros adulto 11+, niño 3–10 al 73 %, infante ≤ 2, máx. 2 niños y
 * 1 infante por adulto; alojamiento en Lima 2, Cusco 3, Puno 2 y Arequipa 2 noches (dobles 70/65/60/65) y el adicional «Museo Larco».
 */
trait BookingWorld
{
    public Product $circuit;

    /** @var array<string, AccommodationLeg> */
    public array $legs = [];

    public function bookingWorld(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-29 09:00', 'America/Lima'));
        Carbon::setTestNow(Carbon::parse('2026-09-29 09:00', 'America/Lima'));
        $this->catalogWorld();
        $this->circuit = $this->makeProduct(['code' => 'PER-CIR-10D', 'name' => 'Perú Circuito 10 días', 'duration_value' => 10, 'duration_unit' => 'días',
            'modality' => 'Grupal', 'minimum_passengers' => 1, 'maximum_passengers' => 12, 'rates' => [
                ['rate_type' => 'Venta', 'segment' => 'Adulto', 'channel' => 'Directo', 'currency' => 'USD', 'cost' => 900, 'price' => 1490, 'valid_from' => '2026-09-01', 'valid_to' => '2027-09-30'],
                ['rate_type' => 'Venta', 'segment' => 'Niño', 'channel' => 'Directo', 'currency' => 'USD', 'cost' => 650, 'price' => 1090, 'valid_from' => '2026-09-01', 'valid_to' => '2027-09-30'],
            ]], Product::ACTIVE);
        $this->inTenant(function () {
            $admin = $this->users['admin'];
            $this->circuit->forceFill(['cutoff_hours' => 48])->save();
            $ranges = app(AvailabilityRangeService::class);
            foreach ([['2026-10-02', '2026-10-06', 'disponible', 12], ['2026-10-07', '2026-10-09', 'cerrado', 0], ['2026-10-10', '2026-10-14', 'disponible', 12]] as [$from, $to, $status, $cap]) {
                $ranges->create($this->circuit, ['date_from' => $from, 'date_to' => $to, 'status' => $status, 'capacity_per_day' => $cap], $admin);
            }
            $config = app(BookingConfigService::class);
            $config->savePax($this->circuit, ['adult_min_age' => 11, 'child_min_age' => 3, 'child_max_age' => 10, 'infant_max_age' => 2, 'child_pays' => true,
                'child_price_pct' => 73, 'infant_allowed' => true, 'infant_pays' => false, 'max_children_per_adult' => 2, 'max_infants_per_adult' => 1, 'min_adults' => 1], $admin);
            $config->saveAccommodation($this->circuit, ['enabled' => true, 'optional' => true, 'currency' => 'USD'], $admin);
            foreach ([['Lima', 2, [['Doble', 2, 70], ['Simple', 1, 60]]], ['Cusco', 3, [['Doble', 2, 65], ['Triple', 3, 90]]], ['Puno', 2, [['Doble', 2, 60]]], ['Arequipa', 2, [['Doble', 2, 65]]]] as [$city, $nights, $types]) {
                $leg = $config->saveLeg($this->circuit, ['city' => $city, 'nights' => $nights], $admin);
                $config->saveHotel($this->circuit, $leg, ['name' => "Hotel {$city} Centro", 'category' => '3 estrellas',
                    'room_types' => array_map(fn ($t) => ['room_type' => $t[0], 'capacity' => $t[1], 'price_per_night' => $t[2]], $types)], $admin);
                $this->legs[$city] = $leg->fresh('hotels.roomTypes');
            }
            $config->saveAddon($this->circuit, ['name' => 'Museo Larco', 'category' => 'Museo', 'price' => 25, 'currency' => 'USD', 'charge_per' => 'pax', 'applies_to' => ['ADT', 'CHD']], $admin);
            $config->savePickup($this->circuit, ['required' => true, 'ask_flight' => true, 'points' => [['label' => 'Aeropuerto Jorge Chávez'], ['label' => 'Hotel en Miraflores']]], $admin);
        });
        $this->circuit = $this->inTenant(fn () => $this->circuit->fresh());
    }

    /** Selección de alojamiento con la doble más económica de cada tramo. */
    public function cheapestLodging(int $doubles = 1, ?array $cities = null): array
    {
        $out = [];
        foreach ($this->legs as $city => $leg) {
            if ($cities === null || in_array($city, $cities, true)) {
                $out[] = ['leg_id' => $leg->id, 'hotel_id' => $leg->hotels->first()->id, 'rooms' => [['type' => 'Doble', 'qty' => $doubles]]];
            }
        }

        return $out;
    }

    /** Venta existente de `pax` pasajeros del circuito en una fecha (reserva confirmada con un servicio). */
    public function soldOn(string $date, int $pax): Reservation
    {
        return $this->inTenant(function () use ($date, $pax) {
            $client = $this->makeClient('u03');
            $seq = app(SequenceService::class);
            $r = Reservation::create(['code' => $seq->next('reservation'), 'client_id' => $client->id, 'owner_id' => $this->users['u03']->id, 'title' => 'Grupo previo',
                'status' => Reservation::CONFIRMED, 'channel' => 'Directo', 'travel_from' => $date, 'travel_to' => $date, 'currency' => 'USD', 'fx_rate' => 1,
                'total_amount' => 1490 * $pax, 'snapshot_label' => 'prueba', 'policies_snapshot' => []]);
            BookedService::create(['reservation_id' => $r->id, 'code' => $seq->next('booking_service'), 'product_id' => $this->circuit->id, 'name' => 'Circuito',
                'service_date' => $date, 'pax' => $pax, 'status' => BookedService::CONFIRMED, 'confirmation_code' => 'X-1', 'cost_amount' => 0, 'price_amount' => 1490 * $pax]);

            return $r;
        });
    }

    /** Llamada pública con el tenant por `X-Tenant-Slug`. */
    public function pub(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders($headers + ['Accept' => 'application/json', 'X-Tenant-Slug' => $this->tenant->slug])->json($method, '/api/v1/public/'.ltrim($uri, '/'), $data);
    }

    public function contact(array $overrides = []): array
    {
        return $overrides + ['name' => 'Lucía Ramos', 'email' => 'lucia@correo.test', 'phone' => '+51 987 111 222', 'doc_type' => 'DNI', 'doc_number' => '45678912'];
    }

    /** Cotiza y reserva: devuelve la respuesta de POST /public/bookings. */
    public function bookOnline(array $sel, ?string $key = null, array $extra = []): TestResponse
    {
        $token = $this->pub('POST', 'quote', $sel + ['product_id' => $this->circuit->id])->assertOk()->json('data.quote_token');

        return $this->pub('POST', 'bookings', $extra + ['quote_token' => $token, 'contact' => $this->contact(),
            'pickup' => ['point_id' => $this->inTenant(fn () => $this->circuit->pickupPoints()->first()->id)]], $this->idem($key));
    }
}
