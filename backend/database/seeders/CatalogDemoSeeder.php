<?php

namespace Database\Seeders;

use App\Models\Catalog\Category;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Hold;
use App\Models\Catalog\PriceRule;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductType;
use App\Models\Catalog\Provider;
use App\Models\Catalog\Rate;
use App\Models\Catalog\Season;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Models\Tenant;
use App\Services\Catalog\AvailabilityService;
use App\Services\Catalog\DestinationService;
use App\Services\Catalog\ProductLifecycle;
use App\Services\Catalog\ProductParts;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\ProductVersioning;
use App\Services\Catalog\ProviderService;
use App\Services\Catalog\SeasonService;
use App\Services\Catalog\SupplierServices;
use App\Services\Crm\CrmClock;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de demostración del tenant "andes" (spec 02): destinos, proveedores, servicios, temporadas
 * y productos PRD-001…PRD-010 en todos los estados, creados con los mismos servicios que la API
 * (el ciclo de vida pasa por revisión, aprobación y checklist). Fechas relativas a hoy.
 */
class CatalogDemoSeeder extends Seeder
{
    private User $admin;

    private Carbon $today;

    /** @var array<string, string> nombre => id */
    private array $d = [];

    /** @var array<string, Provider> */
    private array $p = [];

    /** @var array<string, SupplierService> */
    private array $s = [];

    /** @var array<string, string> */
    private array $types = [];

    /** @var array<string, array{0: string, 1: array<string, string>}> */
    private array $categories = [];

    public function run(): void
    {
        $this->admin = User::where('code', 'U01')->firstOrFail();
        Auth::setUser($this->admin);
        $this->today = app(CrmClock::class)->today();
        $this->tenantSettings();
        $this->types = ProductType::pluck('id', 'name')->all();
        foreach (Category::with('subcategories')->get() as $c) {
            $this->categories[$c->name] = [$c->id, $c->subcategories->pluck('id', 'name')->all()];
        }

        $this->destinations();
        $this->providers();
        $this->services();
        $this->seasons();
        $this->products();
        Auth::forgetUser();
    }

    /** Agencias B2B, guías y vehículos provisionales (hasta los módulos B2B y Operaciones). */
    private function tenantSettings(): void
    {
        $tenant = Tenant::findOrFail(app(TenantContext::class)->id());
        $settings = $tenant->settings ?? [];
        data_set($settings, 'catalog.hold_minutes', 30);
        data_set($settings, 'b2b.agencies', [
            ['id' => DemoSeeder::linkId('AGB-01'), 'code' => 'AGB-01', 'name' => 'Agencia Sol Viajes', 'status' => 'activa', 'credit_line' => 15000],
            ['id' => DemoSeeder::linkId('AGB-02'), 'code' => 'AGB-02', 'name' => 'Andes Partners Tour Operator', 'status' => 'activa', 'credit_line' => 0],
            ['id' => DemoSeeder::linkId('AGB-03'), 'code' => 'AGB-03', 'name' => 'Viajes Express (bloqueada)', 'status' => 'bloqueada', 'credit_line' => 5000],
        ]);
        data_set($settings, 'operations.guides', [
            ['id' => DemoSeeder::linkId('G01'), 'name' => 'Wilber Condori', 'on_vacation' => false],
            ['id' => DemoSeeder::linkId('G02'), 'name' => 'Nadia Flores', 'on_vacation' => false],
            ['id' => DemoSeeder::linkId('G03'), 'name' => 'Rubén Quispe (vacaciones)', 'on_vacation' => true],
        ]);
        data_set($settings, 'operations.vehicles', [
            ['id' => DemoSeeder::linkId('V01'), 'plate' => 'CUS-123 · Sprinter', 'seats' => 15, 'in_maintenance' => false],
            ['id' => DemoSeeder::linkId('V02'), 'plate' => 'CUS-456 · Bus', 'seats' => 30, 'in_maintenance' => false],
            ['id' => DemoSeeder::linkId('V03'), 'plate' => 'CUS-789 · Van (mantenimiento)', 'seats' => 12, 'in_maintenance' => true],
        ]);
        DB::connection('pgsql_admin')->table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);
        app(TenantContext::class)->set(Tenant::findOrFail($tenant->id));
    }

    private function destinations(): void
    {
        $svc = app(DestinationService::class);
        $add = function (string $key, string $name, string $type, ?string $parent, ?float $lat = null, ?float $lng = null, ?string $description = null) use ($svc) {
            $this->d[$key] = $svc->create(['name' => $name, 'type' => $type, 'parent_id' => $parent ? $this->d[$parent] : null, 'lat' => $lat, 'lng' => $lng, 'description' => $description], $this->admin)['destination']->id;
        };
        $add('peru', 'Perú', 'País', null, -9.19, -75.0152);
        $add('cusco-dep', 'Cusco', 'Departamento', 'peru', -13.5319, -71.9675);
        $add('cusco', 'Cusco', 'Ciudad', 'cusco-dep', -13.5167, -71.9787, 'Capital histórica del Imperio Inca.');
        $add('valle', 'Valle Sagrado', 'Zona', 'cusco-dep', -13.3333, -72.0833);
        $add('machu', 'Machu Picchu', 'Atracción', 'valle', -13.1631, -72.545, 'Santuario histórico, maravilla del mundo moderno.');
        $add('vinicunca', 'Montaña de 7 Colores', 'Atracción', 'cusco-dep', -13.8697, -71.3031);
        $add('lima-dep', 'Lima', 'Departamento', 'peru', -12.0464, -77.0428);
        $add('lima', 'Lima', 'Ciudad', 'lima-dep', -12.0464, -77.0428);
        $add('miraflores', 'Miraflores', 'Lugar', 'lima', -12.1211, -77.0297);
        $add('puno', 'Puno', 'Departamento', 'peru', -15.8402, -70.0219);
        $add('titicaca', 'Lago Titicaca', 'Lugar', 'puno', -15.9254, -69.3354);
        $add('arequipa', 'Arequipa', 'Departamento', 'peru', -16.409, -71.5375);
        $add('colca', 'Cañón del Colca', 'Atracción', 'arequipa', -15.609, -71.884);
        $add('mdd', 'Madre de Dios', 'Departamento', 'peru', -12.5933, -69.1891);
        $add('tambopata', 'Tambopata', 'Lugar', 'mdd', -12.8333, -69.2833);
    }

    private function providers(): void
    {
        $svc = app(ProviderService::class);
        $rows = [
            'inti' => ['PRV-001', ['name' => 'Hotel Inti Wasi S.A.C.', 'type' => 'Hotel', 'ruc' => '20600111222', 'city' => 'Cusco', 'stars' => 4, 'contact_name' => 'Rosa Ccori', 'phone' => '084 231 456', 'email' => 'reservas@intiwasi.test', 'currency' => 'USD', 'payment_terms' => 'Crédito 30 días'], 365, true, 4.5],
            'rail' => ['PRV-002', ['name' => 'PeruRail S.A.', 'type' => 'Tren', 'ruc' => '20431871808', 'city' => 'Cusco', 'contact_name' => 'Mesa de grupos', 'email' => 'grupos@perurail.test', 'currency' => 'USD', 'payment_terms' => 'Prepago'], 70, true, 4.7],
            'qhapaq' => ['PRV-003', ['name' => 'Transportes Qhapaq E.I.R.L.', 'type' => 'Transporte', 'ruc' => '20600333444', 'city' => 'Cusco', 'contact_name' => 'Julio Mamani', 'phone' => '984 112 233', 'currency' => 'PEN', 'payment_terms' => 'Crédito 15 días'], 300, true, 4.2],
            'guias' => ['PRV-004', ['name' => 'Guías Andinos S.A.C.', 'type' => 'Guía', 'ruc' => '20600555666', 'city' => 'Cusco', 'contact_name' => 'Elsa Huamán', 'email' => 'operaciones@guiasandinos.test', 'currency' => 'PEN'], 400, true, 4.8],
            'tupay' => ['PRV-005', ['name' => 'Restaurante Tupay', 'type' => 'Restaurante', 'ruc' => '10456789012', 'city' => 'Urubamba', 'contact_name' => 'Carmen Ríos', 'currency' => 'PEN', 'payment_terms' => 'Contado'], 200, true, 4.0],
            'consettur' => ['PRV-006', ['name' => 'Consettur Machupicchu S.A.C.', 'type' => 'Transporte', 'ruc' => '20527077771', 'city' => 'Aguas Calientes', 'contact_name' => 'Ventas corporativas', 'currency' => 'USD'], 500, true, 4.1],
            'amazon' => ['PRV-007', ['name' => 'Amazon Lodge Tambopata S.A.C.', 'type' => 'Hotel', 'ruc' => '20600777888', 'city' => 'Puerto Maldonado', 'stars' => 3, 'contact_name' => 'Iván Torres', 'currency' => 'USD'], 200, true, 3.8],
            'colca' => ['PRV-008', ['name' => 'Colca Adventures S.R.L.', 'type' => 'Operador', 'ruc' => '20600999000', 'city' => 'Arequipa', 'contact_name' => 'Luz Apaza', 'currency' => 'PEN'], -20, true, 3.5],
        ];
        foreach ($rows as $key => [$ref, $data, $endDays, $active, $rating]) {
            $start = $this->today->copy()->subYear()->toDateString();
            $end = $this->today->copy()->addDays($endDays)->toDateString();
            $provider = $svc->create($data + ['contract_start' => $start, 'contract_end' => $endDays < 0 ? $this->today->copy()->addDays(30)->toDateString() : $end], $this->admin);
            // PRV-001 conserva el id que DemoSeeder asigna al usuario proveedor U17.
            if ($ref === 'PRV-001') {
                DB::table('catalog_providers')->where('id', $provider->id)->update(['id' => DemoSeeder::linkId('PRV-001')]);
                $provider = Provider::findOrFail(DemoSeeder::linkId('PRV-001'));
            }
            if ($active) {
                $svc->activate($provider, $this->admin);
            }
            if ($endDays < 0) {
                // Contrato vencido: el job diario lo marcará "vencido".
                $provider->forceFill(['contract_end' => $end])->saveQuietly();
                $svc->markExpired();
            }
            $svc->rate($provider->fresh(), $rating, null, $this->admin);
            $this->p[$key] = $provider->fresh();
        }
        $svc->addContact($this->p['inti'], ['name' => 'Mario Quispe', 'role' => 'Gerente comercial', 'phone' => '984 000 111', 'email' => 'mquispe@intiwasi.test'], $this->admin);
        $svc->addContact($this->p['rail'], ['name' => 'Ana Torres', 'role' => 'Ejecutiva de cuentas', 'email' => 'atorres@perurail.test'], $this->admin);
    }

    private function services(): void
    {
        $svc = app(SupplierServices::class);
        $from = $this->today->copy()->subMonths(6)->toDateString();
        $to = $this->today->copy()->addYear()->toDateString();
        $rows = [
            'tren' => ['rail', 'Tren Expedition Ollantaytambo – Aguas Calientes (ida y vuelta)', 'Tren', 'pax', 140, 'USD'],
            'bus' => ['consettur', 'Bus Consettur Aguas Calientes – Machu Picchu (ida y vuelta)', 'Transporte', 'pax', 24, 'USD'],
            'entrada' => ['guias', 'Entrada Machu Picchu circuito 2', 'Entrada', 'pax', 152, 'PEN'],
            'guia' => ['guias', 'Guía profesional bilingüe (día completo)', 'Guía', 'servicio', 250, 'PEN'],
            'almuerzo' => ['tupay', 'Almuerzo buffet novoandino', 'Alimentación', 'pax', 55, 'PEN'],
            'traslado' => ['qhapaq', 'Traslado hotel – estación Ollantaytambo', 'Traslado', 'vehículo', 180, 'PEN'],
            'citytour' => ['qhapaq', 'Movilidad turística City Tour Cusco', 'Transporte', 'vehículo', 220, 'PEN'],
            'hotel' => ['inti', 'Habitación doble estándar Hotel Inti Wasi', 'Alojamiento', 'noche', 95, 'USD'],
            'vinicunca' => ['qhapaq', 'Transporte y desayuno Vinicunca', 'Transporte', 'pax', 60, 'PEN'],
            'aeropuerto' => ['qhapaq', 'Traslado aeropuerto – hotel Cusco', 'Traslado', 'vehículo', 70, 'PEN'],
            'lodge' => ['amazon', 'Lodge Tambopata pensión completa', 'Alojamiento', 'noche', 120, 'USD'],
            'colca' => ['colca', 'Tour Colca 2D/1N compartido', 'Tour', 'pax', 280, 'PEN'],
            'cocina' => ['tupay', 'Clase de cocina peruana con mercado', 'Actividad', 'pax', 90, 'PEN'],
            'titicaca' => ['qhapaq', 'Lancha Uros y Taquile', 'Transporte', 'pax', 45, 'PEN'],
        ];
        foreach ($rows as $key => [$provider, $name, $type, $unit, $cost, $currency]) {
            $this->s[$key] = $svc->create(['provider_id' => $this->p[$provider]->id, 'name' => $name, 'type' => $type, 'unit' => $unit, 'cost' => $cost,
                'currency' => $currency, 'valid_from' => $from, 'valid_to' => $to, 'capacity' => $type === 'Tren' ? 40 : null], $this->admin)['service'];
        }
        // Un servicio vencido (PRD-BR-003).
        $this->s['old'] = $svc->create(['provider_id' => $this->p['qhapaq']->id, 'name' => 'Tour nocturno (temporada 2025)', 'type' => 'Tour', 'unit' => 'pax', 'cost' => 35,
            'currency' => 'PEN', 'valid_from' => $this->today->copy()->subYear()->toDateString(), 'valid_to' => $this->today->copy()->subDays(10)->toDateString()], $this->admin)['service'];
    }

    private function seasons(): void
    {
        $svc = app(SeasonService::class);
        $y = $this->today->year;
        foreach ([
            ['Temporada baja', 'Baja', "{$y}-01-01", "{$y}-03-31", 10, 0.90],
            ['Temporada media', 'Media', "{$y}-04-01", "{$y}-05-31", 10, 1.00],
            ['Temporada alta', 'Alta', "{$y}-06-01", "{$y}-08-31", 10, 1.20],
            ['Inti Raymi', 'Feriado', "{$y}-06-20", "{$y}-06-25", 0, 1.35],
            ['Fiestas Patrias', 'Feriado', "{$y}-07-26", "{$y}-07-30", 0, 1.30],
            ['Temporada media (primavera)', 'Media', "{$y}-09-01", "{$y}-12-15", 10, 1.05],
            ['Navidad y Año Nuevo', 'Fecha especial', "{$y}-12-16", ($y + 1).'-01-05', 5, 1.25],
            ['Temporada baja '.($y + 1), 'Baja', ($y + 1).'-01-06', ($y + 1).'-03-31', 10, 0.90],
        ] as [$name, $type, $from, $to, $priority, $factor]) {
            $svc->create(['name' => $name, 'type' => $type, 'date_from' => $from, 'date_to' => $to, 'priority' => $priority, 'factor' => $factor], $this->admin);
        }
    }

    private function products(): void
    {
        $products = app(ProductService::class);
        $parts = app(ProductParts::class);
        $lifecycle = app(ProductLifecycle::class);
        $versions = app(ProductVersioning::class);
        $availability = app(AvailabilityService::class);
        $from = $this->today->copy()->subMonths(2)->toDateString();
        $to = $this->today->copy()->addMonths(14)->toDateString();
        $rate = fn (string $type, string $segment, string $channel, string $currency, float $cost, float $price, array $extra = []) => [
            'rate_type' => $type, 'segment' => $segment, 'channel' => $channel, 'currency' => $currency, 'cost' => $cost, 'price' => $price,
            'valid_from' => $from, 'valid_to' => $to] + $extra;
        $base = fn (string $code, string $name, string $type, string $category, ?string $sub, string $destination, array $extra = []) => [
            'code' => $code, 'name' => $name, 'product_type_id' => $this->types[$type], 'category_id' => $this->categories[$category][0],
            'subcategory_id' => $sub ? $this->categories[$category][1][$sub] : null, 'primary_destination_id' => $this->d[$destination],
            'duration_value' => 1, 'duration_unit' => 'días', 'modality' => 'Compartido', 'minimum_passengers' => 1, 'maximum_passengers' => 16,
            'minimum_age' => 3, 'maximum_age' => 80, 'booking_advance_min_days' => 2, 'booking_advance_max_days' => 365,
        ] + $extra;
        $altaSeason = Season::where('name', 'Temporada alta')->value('id');

        // PRD-001 · Machu Picchu Full Day (ACTIVE, POR_SALIDA, versión 2.0 vigente).
        $p1 = $products->create($base('MAPI-FD', 'Machu Picchu Full Day', 'EXCURSIÓN', 'Cultural', 'Arqueología', 'machu', [
            'short_description' => 'Visita guiada a la ciudadela inca en tren Expedition, con almuerzo buffet.',
            'long_description' => "Recojo temprano del hotel en Cusco, traslado a Ollantaytambo y viaje en tren hasta Aguas Calientes.\nAscenso en bus y visita guiada de 2.5 horas por la ciudadela.",
            'secondary_destination_ids' => [$this->d['valle'], $this->d['cusco']], 'is_b2b' => true, 'is_ecommerce' => true, 'is_featured' => true,
            'availability_model' => 'POR_SALIDA', 'availability_capacity' => 30,
            'components' => [['service_id' => $this->s['traslado']->id, 'quantity' => 1], ['service_id' => $this->s['tren']->id], ['service_id' => $this->s['bus']->id],
                ['service_id' => $this->s['entrada']->id], ['service_id' => $this->s['guia']->id], ['service_id' => $this->s['almuerzo']->id, 'is_required' => false]],
            'itinerary' => [['title' => 'Cusco – Machu Picchu – Cusco', 'items' => [
                ['start_time' => '04:30', 'activity' => 'Recojo del hotel y traslado a Ollantaytambo', 'item_type' => 'Traslado', 'location' => 'Cusco'],
                ['start_time' => '06:10', 'activity' => 'Tren Expedition a Aguas Calientes', 'item_type' => 'Tren', 'location' => 'Ollantaytambo'],
                ['start_time' => '09:00', 'activity' => 'Visita guiada a la ciudadela', 'item_type' => 'Guía', 'location' => 'Machu Picchu'],
                ['start_time' => '13:00', 'activity' => 'Almuerzo buffet', 'item_type' => 'Alimentación', 'location' => 'Aguas Calientes'],
                ['start_time' => null, 'activity' => 'Tiempo libre en Aguas Calientes', 'item_type' => 'Libre'],
            ]]],
            'rates' => [
                $rate('Venta', 'Adulto', 'Directo', 'USD', 260, 380, ['min_price' => 300]),
                $rate('Venta', 'Niño', 'Directo', 'USD', 210, 290),
                $rate('Venta', 'Adulto', 'E-commerce', 'USD', 260, 395),
                $rate('B2B', 'Adulto', 'B2B', 'USD', 260, 320),
                $rate('Corporativa', 'Adulto', 'Corporativo', 'USD', 260, 350),
                $rate('Venta', 'Adulto', 'Directo', 'USD', 290, 430, ['season_id' => $altaSeason, 'conditions' => 'Temporada alta (jun–ago)']),
            ],
            'channels' => ['crm', 'quotes', 'b2b', 'ecommerce', 'cms'],
            'requirements' => [['category' => 'Documentos', 'text' => 'Pasaporte o DNI vigente (el mismo de la reserva).'], ['category' => 'Condición física', 'text' => 'Caminatas de hasta 3 horas.']],
        ]), $this->admin);
        // Tarifa vencida (no se puede crear por API: PRD-BR-005).
        Rate::create(['ref' => app(SequenceService::class)->next('catalog_rate'), 'product_id' => $p1->id, 'rate_type' => 'Promocional', 'segment' => 'Adulto', 'channel' => 'Directo',
            'currency' => 'USD', 'cost' => 240, 'price' => 340, 'valid_from' => $this->today->copy()->subYear()->toDateString(), 'valid_to' => $this->today->copy()->subMonths(3)->toDateString(), 'status' => 'vigente', 'conditions' => 'Campaña verano']);
        $this->departures($p1, [[3, '04:30', 20], [7, '04:30', 20], [10, '05:00', 16], [14, '04:30', 20], [21, '04:30', 20], [35, '04:30', 12]]);
        $this->activate($p1);
        $versions->create($p1->fresh(), 'major', $this->today->copy()->subMonths(4)->toDateString(), 'Lanzamiento comercial', $this->admin);
        $versions->create($p1->fresh(), 'major', $this->today->copy()->subMonth()->toDateString(), 'Nuevo horario de tren y almuerzo incluido', $this->admin);
        $parts->saveB2bRate($p1->fresh(), ['agency_id' => DemoSeeder::linkId('AGB-01'), 'price_list' => 'Tarifario agencias 2026', 'price' => 315, 'commission_pct' => 10, 'is_credit' => true,
            'valid_to' => $to, 'conditions' => 'Mínimo 2 pax'], $this->admin);
        $parts->addPromotion($p1, ['name' => 'Preventa temporada alta', 'kind' => 'Descuento %', 'value' => 10, 'channel' => 'E-commerce',
            'date_from' => $this->today->toDateString(), 'date_to' => $this->today->copy()->addMonths(2)->toDateString()], $this->admin);
        $parts->addPromotion($p1, ['name' => 'Código bienvenida', 'kind' => 'Código promocional', 'value' => 5, 'channel' => 'Todos', 'promo_code' => 'BIENVENIDO5',
            'date_from' => $this->today->toDateString(), 'date_to' => $this->today->copy()->addMonths(6)->toDateString()], $this->admin);
        foreach (['ecommerce', 'b2b', 'quotes', 'crm', 'cms'] as $channel) {
            $parts->setChannel($p1->fresh(), $channel, true, $this->admin);
        }
        $parts->addMedia($p1, ['storage_path' => 'demo/machu-picchu.jpg', 'alt_text' => 'Vista de la ciudadela de Machu Picchu'], $this->admin);
        $parts->addMedia($p1, ['storage_path' => 'demo/tren-expedition.jpg', 'alt_text' => 'Tren Expedition en el valle del Urubamba'], $this->admin);
        $parts->addFaq($p1, ['question' => '¿Qué pasa si llueve?', 'answer' => 'El tour se realiza igual; recomendamos llevar poncho.'], $this->admin);
        $products->setContent($p1->fresh(), ['slug' => 'machu-picchu-full-day', 'seo_title' => 'Machu Picchu Full Day desde Cusco', 'seo_description' => 'Tour de un día a Machu Picchu con tren, bus, entrada y guía.',
            'features' => ['Tren Expedition', 'Guía bilingüe', 'Almuerzo buffet', 'Grupos de máximo 16']], $this->admin);
        PriceRule::create(['ref' => app(SequenceService::class)->next('catalog_price_rule'), 'name' => 'Precio mínimo Machu Picchu (USD)', 'kind' => 'Precio mínimo', 'value' => 300,
            'scope' => 'PRD-001 en USD', 'product_id' => $p1->id, 'currency' => 'USD', 'priority' => 95, 'status' => 'activa']);

        // PRD-002 · City Tour Cusco (ACTIVE, con personalización).
        $p2 = $products->create($base('CUS-CITY', 'City Tour Cusco y 4 ruinas', 'TOUR', 'Cultural', 'Ciudad', 'cusco', [
            'duration_value' => 5, 'duration_unit' => 'horas', 'availability_model' => 'CAPACIDAD', 'availability_capacity' => 25, 'is_ecommerce' => true,
            'short_description' => 'Qoricancha, Catedral, Sacsayhuamán, Q’enqo, Puca Pucara y Tambomachay.',
            'components' => [['service_id' => $this->s['citytour']->id], ['service_id' => $this->s['guia']->id]],
            'itinerary' => [['title' => 'City Tour', 'items' => [
                ['start_time' => '13:30', 'activity' => 'Recojo del hotel', 'item_type' => 'Traslado'],
                ['start_time' => '14:00', 'activity' => 'Qoricancha y Catedral', 'item_type' => 'Actividad', 'location' => 'Cusco'],
                ['start_time' => '16:00', 'activity' => 'Sacsayhuamán, Q’enqo, Puca Pucara y Tambomachay', 'item_type' => 'Actividad'],
            ]]],
            'rates' => [$rate('Venta', 'Pasajero', 'Directo', 'PEN', 45, 95), $rate('Venta', 'Pasajero', 'E-commerce', 'PEN', 45, 99), $rate('Mayorista', 'Pasajero', 'Mayorista', 'PEN', 45, 70)],
            'channels' => ['crm', 'quotes', 'ecommerce'],
        ]), $this->admin);
        $this->activate($p2);
        $parts->addConfigGroup($p2, 'Idioma del guía', [['label' => 'Español', 'price_delta' => 0], ['label' => 'Inglés', 'price_delta' => 0], ['label' => 'Francés', 'price_delta' => 15]], $this->admin);
        $parts->addConfigGroup($p2, 'Servicio', [['label' => 'Compartido', 'price_delta' => 0], ['label' => 'Privado', 'price_delta' => 120]], $this->admin);
        $parts->setChannel($p2->fresh(), 'ecommerce', true, $this->admin);

        // PRD-003 · Valle Sagrado VIP (ACTIVE, POR_SALIDA).
        $p3 = $products->create($base('VALLE-VIP', 'Valle Sagrado VIP', 'TOUR', 'Cultural', 'Arqueología', 'valle', [
            'modality' => 'Privado', 'maximum_passengers' => 10, 'availability_model' => 'POR_SALIDA',
            'components' => [['service_id' => $this->s['traslado']->id], ['service_id' => $this->s['guia']->id], ['service_id' => $this->s['almuerzo']->id]],
            'itinerary' => [['title' => 'Pisac, Urubamba y Ollantaytambo', 'items' => [
                ['start_time' => '07:30', 'activity' => 'Mercado y parque arqueológico de Pisac', 'item_type' => 'Actividad', 'location' => 'Pisac'],
                ['start_time' => '13:00', 'activity' => 'Almuerzo en Urubamba', 'item_type' => 'Alimentación', 'location' => 'Urubamba'],
                ['start_time' => '15:00', 'activity' => 'Fortaleza de Ollantaytambo', 'item_type' => 'Actividad', 'location' => 'Ollantaytambo'],
            ]]],
            'rates' => [$rate('VIP', 'Adulto', 'Directo', 'USD', 85, 160), $rate('Venta', 'Niño', 'Directo', 'USD', 70, 120)],
        ]), $this->admin);
        $this->departures($p3, [[2, '07:00', 10], [9, '07:00', 10], [16, '07:00', 10]]);
        $this->activate($p3);

        // PRD-004 · Montaña de 7 Colores (ACTIVE, POR_SALIDA).
        $p4 = $products->create($base('VINI-7C', 'Montaña de 7 Colores (Vinicunca)', 'EXCURSIÓN', 'Aventura', 'Trekking', 'vinicunca', [
            'minimum_age' => 10, 'maximum_age' => 65, 'availability_model' => 'POR_SALIDA',
            'components' => [['service_id' => $this->s['vinicunca']->id], ['service_id' => $this->s['guia']->id]],
            'itinerary' => [['title' => 'Vinicunca', 'items' => [
                ['start_time' => '03:30', 'activity' => 'Recojo y viaje a Cusipata', 'item_type' => 'Transporte'],
                ['start_time' => '08:00', 'activity' => 'Caminata a la montaña (5 100 m s. n. m.)', 'item_type' => 'Actividad', 'location' => 'Vinicunca'],
            ]]],
            'rates' => [$rate('Venta', 'Pasajero', 'Directo', 'PEN', 70, 150), $rate('Grupal', 'Grupo', 'Directo', 'PEN', 65, 130, ['conditions' => 'Mín. 6 pax'])],
            'requirements' => [['category' => 'Condición física', 'text' => 'Buena condición física; altitud superior a 5 000 m.'], ['category' => 'Equipamiento', 'text' => 'Ropa de abrigo y bloqueador.']],
        ]), $this->admin);
        $this->departures($p4, [[1, '03:30', 20], [4, '03:30', 20], [8, '03:30', 20]]);
        $this->activate($p4);

        // PRD-005 · Lago Titicaca 2D/1N (REVIEW).
        $p5 = $products->create($base('TITI-2D', 'Lago Titicaca: Uros y Taquile 2D/1N', 'PAQUETE', 'Naturaleza', 'Lagos y montañas', 'titicaca', [
            'duration_value' => 2, 'components' => [['service_id' => $this->s['titicaca']->id]],
            'itinerary' => [['title' => 'Puno – Uros – Amantaní', 'items' => [['start_time' => '07:00', 'activity' => 'Islas flotantes de los Uros', 'item_type' => 'Actividad']]],
                ['title' => 'Taquile – Puno', 'items' => [['start_time' => '08:00', 'activity' => 'Isla Taquile', 'item_type' => 'Actividad']]]],
            'rates' => [$rate('Venta', 'Adulto', 'Directo', 'PEN', 180, 320)],
        ]), $this->admin);
        $lifecycle->submitReview($p5, $this->admin);

        // PRD-006 · Cañón del Colca (APPROVED, con un día sin actividades: el checklist falla en Itinerario).
        $p6 = $products->create($base('COLCA-3D', 'Cañón del Colca 3D/2N', 'CIRCUITO', 'Aventura', 'Trekking', 'colca', [
            'duration_value' => 3, 'components' => [['service_id' => $this->s['guia']->id]],
            'itinerary' => [['title' => 'Arequipa – Chivay', 'items' => [['start_time' => '08:00', 'activity' => 'Viaje a Chivay', 'item_type' => 'Transporte']]],
                ['title' => 'Cruz del Cóndor'], ['title' => 'Retorno a Arequipa', 'items' => [['start_time' => '09:00', 'activity' => 'Baños termales', 'item_type' => 'Actividad']]]],
            'rates' => [$rate('Venta', 'Adulto', 'Directo', 'PEN', 280, 520)],
        ]), $this->admin);
        $lifecycle->submitReview($p6, $this->admin);
        $lifecycle->approve($p6->fresh(), $this->admin);

        // PRD-007 · Tambopata (SUSPENDED; el lodge quedó inactivo después).
        $p7 = $products->create($base('TAMBO-3D', 'Tambopata Lodge 3D/2N', 'PAQUETE', 'Naturaleza', 'Amazonía', 'tambopata', [
            'duration_value' => 3, 'components' => [['service_id' => $this->s['lodge']->id, 'quantity' => 2]],
            'itinerary' => [['title' => 'Llegada al lodge', 'items' => [['start_time' => '11:00', 'activity' => 'Navegación por el río Madre de Dios', 'item_type' => 'Transporte']]],
                ['title' => 'Lago Sandoval', 'items' => [['start_time' => '05:30', 'activity' => 'Caminata al lago Sandoval', 'item_type' => 'Actividad']]],
                ['title' => 'Retorno', 'items' => [['start_time' => '07:00', 'activity' => 'Traslado al aeropuerto', 'item_type' => 'Traslado']]]],
            'rates' => [$rate('Venta', 'Adulto', 'Directo', 'USD', 260, 450)],
        ]), $this->admin);
        $this->activate($p7);
        $lifecycle->suspend($p7->fresh(), 'Lodge en remodelación hasta nuevo aviso', $this->admin);
        app(ProviderService::class)->deactivate($this->p['amazon']->fresh(), 'Lodge cerrado por remodelación', $this->admin);

        // PRD-008 · Clase de cocina (DRAFT).
        $products->create($base('COCINA-PE', 'Clase de cocina peruana', 'EXPERIENCIA', 'Gastronomía', 'Clases de cocina', 'cusco', [
            'duration_value' => 4, 'duration_unit' => 'horas', 'components' => [['service_id' => $this->s['cocina']->id]],
        ]), $this->admin);

        // PRD-009 · Traslado aeropuerto (ACTIVE, sin control de disponibilidad).
        $p9 = $products->create($base('TRF-CUZ', 'Traslado aeropuerto – hotel Cusco', 'TRASLADO', 'Corporativo', 'Incentivos', 'cusco', [
            'duration_value' => 1, 'duration_unit' => 'horas', 'modality' => 'Privado', 'minimum_age' => 0, 'maximum_age' => 99,
            'components' => [['service_id' => $this->s['aeropuerto']->id]],
            'rates' => [$rate('Venta', 'Grupo', 'Todos', 'PEN', 70, 120, ['price_basis' => 'Por unidad'])],
        ]), $this->admin);
        $this->activate($p9);

        // PRD-010 · Paquete con productos componentes (INACTIVE).
        $p10 = $products->create($base('PERU-MAG', 'Perú Mágico 4D/3N', 'PAQUETE', 'Cultural', 'Arqueología', 'cusco', [
            'duration_value' => 4, 'components' => [['component_product_id' => $p2->id], ['component_product_id' => $p3->id], ['component_product_id' => $p1->id], ['service_id' => $this->s['hotel']->id, 'quantity' => 3]],
            'itinerary' => [['title' => 'Llegada y City Tour', 'items' => [['start_time' => '14:00', 'activity' => 'City Tour Cusco', 'item_type' => 'Actividad']]],
                ['title' => 'Valle Sagrado', 'items' => [['start_time' => '07:30', 'activity' => 'Valle Sagrado VIP', 'item_type' => 'Actividad']]],
                ['title' => 'Machu Picchu', 'items' => [['start_time' => '04:30', 'activity' => 'Machu Picchu Full Day', 'item_type' => 'Actividad']]],
                ['title' => 'Salida', 'items' => [['start_time' => '09:00', 'activity' => 'Traslado al aeropuerto', 'item_type' => 'Traslado']]]],
            'rates' => [$rate('Venta', 'Adulto', 'Directo', 'USD', 620, 980)],
        ]), $this->admin);
        $this->activate($p10);
        $lifecycle->deactivate($p10->fresh(), 'Reemplazado por el programa 2027', $this->admin);

        // Relacionados y holds de ejemplo.
        $parts->relate($p1->fresh(), $p3->id, 'Complementario', $this->admin);
        $parts->relate($p1->fresh(), $p4->id, 'Cross-sell', $this->admin);
        $parts->relate($p2->fresh(), $p1->id, 'Upsell', $this->admin);

        $d1 = Departure::where('product_id', $p1->id)->orderBy('departure_date')->get();
        $ops = User::where('code', 'U05')->first() ?? $this->admin;
        Auth::setUser($ops);
        $availability->confirmSeats($d1[0], ['quantity' => 12, 'reference' => 'RES-2026-000347'], $ops);
        $availability->hold($d1[0], ['quantity' => 4, 'reference' => 'COT-2026-00192', 'minutes' => 60 * 24], $ops);
        $availability->confirmSeats($d1[1], ['quantity' => 20, 'reference' => 'Grupo Minera del Sur'], $ops);
        $held = $availability->hold($d1[2], ['quantity' => 3, 'reference' => 'WEB-10021', 'minutes' => 30], $ops);
        $availability->confirm($held, $ops);
        $availability->hold($d1[3], ['quantity' => 2, 'reference' => 'Agencia Sol Viajes', 'minutes' => 120], $ops);
        $expired = $availability->hold($d1[4], ['quantity' => 5, 'reference' => 'COT-2026-00188', 'minutes' => 30], $ops);
        Hold::whereKey($expired->id)->update(['expires_at' => now()->subHour()]);
        $released = $availability->hold($d1[4], ['quantity' => 2, 'reference' => 'COT-2026-00185', 'minutes' => 30], $ops);
        $availability->release($released, 'Cliente desistió', $ops);
        $cancelled = Departure::where('product_id', $p3->id)->orderByDesc('departure_date')->first();
        $availability->updateDeparture($cancelled, ['status' => 'cancelada', 'notes' => 'Paro regional anunciado'], $ops);
        Auth::setUser($this->admin);
    }

    /** @param list<array{0: int, 1: string, 2: int}> $rows días desde hoy, hora, capacidad */
    private function departures(Product $product, array $rows): void
    {
        foreach ($rows as [$days, $time, $capacity]) {
            app(AvailabilityService::class)->createDeparture($product, ['departure_date' => $this->today->copy()->addDays($days)->toDateString(), 'departure_time' => $time,
                'capacity' => $capacity, 'meeting_point' => 'Plaza de Armas de Cusco', 'return_point' => 'Hotel del pasajero',
                'guide_id' => DemoSeeder::linkId($days % 2 ? 'G01' : 'G02'), 'vehicle_id' => DemoSeeder::linkId($capacity > 15 ? 'V02' : 'V01')], $this->admin);
        }
    }

    /** DRAFT → REVIEW → APPROVED → ACTIVE con el checklist real. */
    private function activate(Product $product): void
    {
        $lifecycle = app(ProductLifecycle::class);
        $lifecycle->submitReview($product->fresh(), $this->admin);
        $lifecycle->approve($product->fresh(), $this->admin);
        $lifecycle->activate($product->fresh(), $this->admin);
    }

    /** Segundo tenant: catálogo mínimo para verificar el aislamiento (PRD-T02). */
    public static function minimal(User $admin): void
    {
        Auth::setUser($admin);
        $dest = app(DestinationService::class)->create(['name' => 'Perú', 'type' => 'País'], $admin)['destination'];
        $cusco = app(DestinationService::class)->create(['name' => 'Cusco', 'type' => 'Departamento', 'parent_id' => $dest->id], $admin)['destination'];
        $category = Category::where('name', 'Aventura')->value('id');
        app(ProductService::class)->create(['code' => 'INKA-TRAIL', 'name' => 'Camino Inca clásico 4D/3N', 'product_type_id' => ProductType::where('name', 'PAQUETE')->value('id'),
            'category_id' => $category, 'primary_destination_id' => $cusco->id, 'duration_value' => 4, 'duration_unit' => 'días', 'modality' => 'Grupal',
            'minimum_passengers' => 2, 'maximum_passengers' => 16], $admin);
        Auth::forgetUser();
    }
}
