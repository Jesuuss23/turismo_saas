<?php

namespace Database\Seeders;

use App\Models\Catalog\Category;
use App\Models\Catalog\Destination;
use App\Models\Catalog\ProductType;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Services\Catalog\Booking\AvailabilityRangeService;
use App\Services\Catalog\Booking\BookingConfigService;
use App\Services\Catalog\ProductLifecycle;
use App\Services\Catalog\ProductParts;
use App\Services\Catalog\ProductService;
use App\Services\Crm\CrmClock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Demo del motor de reservas (spec 16) para “andes”: circuito «Perú Circuito 10 días» (PER-CIR-10D, USD 1 490 / 1 090) con rangos de
 * fechas relativos a hoy (+5…+9 disponible 12/día, +10…+12 cerrado por mantenimiento, +13…+17 disponible 12/día y +18…+120 disponible
 * 8/día), anticipación 48 h, pasajeros adulto 11+ / niño 3–10 al 73 %, alojamiento Lima–Cusco–Puno–Arequipa, adicionales, recojo y
 * pago total o adelanto 30 %.
 */
class BookingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('code', 'U01')->firstOrFail();
        Auth::setUser($admin);
        $today = app(CrmClock::class)->today();
        $day = fn (int $d) => $today->copy()->addDays($d)->toDateString();
        $rate = fn (string $segment, float $cost, float $price) => ['rate_type' => 'Venta', 'segment' => $segment, 'channel' => 'Directo', 'currency' => 'USD',
            'cost' => $cost, 'price' => $price, 'valid_from' => $today->copy()->subMonth()->toDateString(), 'valid_to' => $today->copy()->addYear()->toDateString()];

        $p = app(ProductService::class)->create([
            'code' => 'PER-CIR-10D', 'name' => 'Perú Circuito 10 días', 'slug' => 'peru-circuito-10-dias', 'product_type_id' => ProductType::where('name', 'PAQUETE')->value('id'),
            'category_id' => Category::where('name', 'Cultural')->value('id'), 'primary_destination_id' => Destination::where('name', 'Perú')->value('id'),
            'duration_value' => 10, 'duration_unit' => 'días', 'modality' => 'Grupal', 'minimum_passengers' => 1, 'maximum_passengers' => 12,
            'is_ecommerce' => true, 'channels' => ['crm', 'quotes', 'ecommerce'], 'availability_model' => 'POR_FECHA',
            'components' => array_map(fn ($n) => ['service_id' => SupplierService::where('name', 'ilike', "%{$n}%")->orderBy('ref')->value('id')], ['Habitación doble', 'Guía profesional', 'Movilidad turística']),
            'short_description' => 'Lima, Cusco, Machu Picchu, lago Titicaca y Arequipa en 10 días con hoteles a elección y traslados.',
            'itinerary' => [
                ['title' => 'Lima', 'items' => [['start_time' => '10:00', 'activity' => 'Llegada y city tour colonial', 'item_type' => 'Actividad', 'location' => 'Lima']]],
                ['title' => 'Cusco y Machu Picchu', 'items' => [['start_time' => '06:00', 'activity' => 'Vuelo a Cusco y Valle Sagrado', 'item_type' => 'Traslado']]],
                ['title' => 'Puno', 'items' => [['start_time' => '07:00', 'activity' => 'Bus turístico a Puno y Uros', 'item_type' => 'Traslado']]],
                ['title' => 'Arequipa', 'items' => [['start_time' => '09:00', 'activity' => 'Ciudad Blanca y Cañón del Colca', 'item_type' => 'Actividad']]],
            ],
            'rates' => [$rate('Adulto', 900, 1490), $rate('Niño', 650, 1090)],
        ], $admin);
        $life = app(ProductLifecycle::class);
        $life->submitReview($p->fresh(), $admin);
        $life->approve($p->fresh(), $admin);
        $life->activate($p->fresh(), $admin);
        app(ProductParts::class)->setChannel($p->fresh(), 'ecommerce', true, $admin);
        $p = $p->fresh();

        $ranges = app(AvailabilityRangeService::class);
        $ranges->operation($p, ['operating_weekdays' => [0, 1, 2, 3, 4, 5, 6], 'cutoff_hours' => 48], $admin);
        foreach ([[5, 9, 'disponible', 12, null], [10, 12, 'cerrado', 0, 'Mantenimiento del tren a Machu Picchu'], [13, 17, 'disponible', 12, null], [18, 120, 'disponible', 8, 'Temporada baja']] as [$a, $b, $status, $cap, $note]) {
            $ranges->create($p->fresh(), ['date_from' => $day($a), 'date_to' => $day($b), 'status' => $status, 'capacity_per_day' => $cap, 'note' => $note], $admin);
        }

        $config = app(BookingConfigService::class);
        $config->savePax($p, ['adult_min_age' => 11, 'child_min_age' => 3, 'child_max_age' => 10, 'infant_max_age' => 2, 'child_pays' => true, 'child_price_pct' => 73,
            'infant_allowed' => true, 'infant_pays' => false, 'max_children_per_adult' => 2, 'max_infants_per_adult' => 1, 'min_adults' => 1], $admin);
        $config->saveAccommodation($p, ['enabled' => true, 'optional' => true, 'currency' => 'USD', 'note' => 'Precios por habitación y noche; desayuno incluido.'], $admin);
        foreach ([['Lima', 2, 'Hotel Miraflores Park', [['Doble', 2, 70], ['Simple', 1, 60]]], ['Cusco', 3, 'Hotel San Blas', [['Doble', 2, 65], ['Triple', 3, 90]]],
            ['Puno', 2, 'Hotel Lago Titicaca', [['Doble', 2, 60]]], ['Arequipa', 2, 'Casona Santa Catalina', [['Doble', 2, 65], ['Matrimonial', 2, 75]]]] as [$city, $nights, $hotel, $types]) {
            $leg = $config->saveLeg($p, ['city' => $city, 'nights' => $nights], $admin);
            $config->saveHotel($p, $leg, ['name' => $hotel, 'category' => '3 estrellas',
                'room_types' => array_map(fn ($t) => ['room_type' => $t[0], 'capacity' => $t[1], 'price_per_night' => $t[2]], $types)], $admin);
        }
        $config->saveAddon($p, ['name' => 'Museo Larco', 'category' => 'Museo', 'price' => 25, 'currency' => 'USD', 'charge_per' => 'pax', 'applies_to' => ['ADT', 'CHD']], $admin);
        $config->saveAddon($p, ['name' => 'Cena show en Cusco', 'category' => 'Alimentación', 'price' => 45, 'currency' => 'USD', 'charge_per' => 'pax', 'applies_to' => ['ADT', 'CHD']], $admin);
        $config->saveAddon($p, ['name' => 'Seguro de asistencia', 'category' => 'Seguro', 'price' => 30, 'currency' => 'USD', 'charge_per' => 'pax', 'applies_to' => ['ADT', 'CHD', 'INF']], $admin);
        $config->savePickup($p, ['required' => true, 'ask_flight' => true, 'points' => [['label' => 'Aeropuerto Jorge Chávez'], ['label' => 'Hotel en Miraflores'], ['label' => 'Hotel en Barranco']]], $admin);
        $config->savePayment($p, ['modes' => ['total', 'adelanto'], 'deposit_pct' => 30, 'balance_due_days' => 30], $admin);
        Auth::forgetUser();
    }
}
