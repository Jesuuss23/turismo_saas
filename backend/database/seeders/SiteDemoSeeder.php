<?php

namespace Database\Seeders;

use App\Models\Catalog\Category;
use App\Models\Catalog\Destination;
use App\Models\Catalog\DestinationContent;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductTranslation;
use App\Models\Catalog\ProductType;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Services\Catalog\AvailabilityService;
use App\Services\Catalog\ProductLifecycle;
use App\Services\Catalog\ProductParts;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\ProviderService;
use App\Services\Channels\CmsService;
use App\Services\Crm\CrmClock;
use App\Services\Site\CartService;
use App\Services\Site\SiteCheckout;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Demo del sitio público (spec 15) para “andes”: Laguna Humantay (PRD-011, E-commerce S/ 129 < Directo S/ 140, por salida), el paquete
 * Cusco Mágico 4D/3N (PRD-012, USD, salidas a +20/+35/+50 días para anticipo), Valle Sagrado y Vinicunca publicados en la tienda, el proveedor
 * de asistencia al viajero (seguro), contenidos de destino ES/EN, traducciones EN de las fichas, artículos y páginas legales publicados y un
 * pedido web de CLI-0001 (U14) con anticipo por validar y saldo pendiente (Mi cuenta).
 */
class SiteDemoSeeder extends Seeder
{
    private User $admin;

    public function run(): void
    {
        $this->admin = User::where('code', 'U01')->firstOrFail();
        Auth::setUser($this->admin);
        $today = app(CrmClock::class)->today();
        $products = app(ProductService::class);
        $parts = app(ProductParts::class);
        $from = $today->copy()->subMonths(2)->toDateString();
        $to = $today->copy()->addMonths(14)->toDateString();
        $rate = fn (string $segment, string $channel, string $currency, float $cost, float $price) => ['rate_type' => 'Venta', 'segment' => $segment, 'channel' => $channel,
            'currency' => $currency, 'cost' => $cost, 'price' => $price, 'valid_from' => $from, 'valid_to' => $to];
        $dest = fn (string $name, ?string $type = null) => Destination::where('name', $name)->when($type, fn ($q) => $q->where('type', $type))->value('id');
        $svc = fn (string $name) => SupplierService::where('name', 'ilike', "%{$name}%")->orderBy('ref')->value('id');
        $type = fn (string $name) => ProductType::where('name', $name)->value('id');
        $category = fn (string $name) => Category::where('name', $name)->value('id');

        // Seguro de asistencia (SIT-BR-10): proveedor tipo «Otros» con contrato vigente.
        $providers = app(ProviderService::class);
        $insurer = $providers->create(['name' => 'Asistencia al Viajero Andina S.A.C.', 'type' => 'Otros', 'ruc' => '20605551234', 'contact_name' => 'Mesa de emergencias',
            'email' => 'emergencias@asistandina.test', 'phone' => '+51 1 700 4000', 'currency' => 'USD', 'contract_start' => $today->copy()->subYear()->toDateString(),
            'contract_end' => $today->copy()->addYears(2)->toDateString()], $this->admin);
        $providers->activate($insurer, $this->admin);

        $base = [
            'duration_unit' => 'días', 'modality' => 'Compartido', 'minimum_passengers' => 1, 'maximum_age' => 75, 'booking_advance_min_days' => 2,
            'booking_advance_max_days' => 365, 'is_ecommerce' => true, 'channels' => ['crm', 'quotes', 'ecommerce'], 'availability_model' => 'POR_SALIDA',
        ];
        $humantay = $products->create($base + [
            'code' => 'HUMANTAY', 'name' => 'Laguna Humantay Full Day', 'slug' => 'laguna-humantay-full-day', 'product_type_id' => $type('EXCURSIÓN'),
            'category_id' => $category('Aventura'), 'primary_destination_id' => $dest('Cusco', 'Departamento'), 'duration_value' => 1, 'maximum_passengers' => 16, 'minimum_age' => 8,
            'short_description' => 'Caminata a la laguna turquesa al pie del nevado Salkantay (4 200 m s. n. m.), con desayuno y almuerzo.',
            'long_description' => "Recojo de su hotel en Cusco a las 04:00 y viaje a Soraypampa con desayuno en ruta.\nAscenso de 1.5 h a la laguna, tiempo libre y retorno con almuerzo buffet en Mollepata.",
            'seo_title' => 'Laguna Humantay Full Day desde Cusco', 'seo_description' => 'Tour a la Laguna Humantay con recojo del hotel, guía bilingüe, desayuno y almuerzo. Reserve en línea.',
            'components' => [['service_id' => $svc('Guía profesional')], ['service_id' => $svc('Transporte y desayuno')]],
            'itinerary' => [['title' => 'Cusco – Soraypampa – Laguna Humantay', 'items' => [
                ['start_time' => '04:00', 'activity' => 'Recojo del hotel en Cusco', 'item_type' => 'Traslado'],
                ['start_time' => '07:30', 'activity' => 'Desayuno en Mollepata', 'item_type' => 'Alimentación'],
                ['start_time' => '09:00', 'activity' => 'Caminata a la Laguna Humantay', 'item_type' => 'Actividad', 'location' => 'Humantay'],
                ['start_time' => '14:00', 'activity' => 'Almuerzo buffet y retorno a Cusco', 'item_type' => 'Alimentación'],
            ]]],
            'rates' => [$rate('Adulto', 'Directo', 'PEN', 70, 140), $rate('Adulto', 'E-commerce', 'PEN', 70, 129), $rate('Niño', 'Directo', 'PEN', 55, 110)],
            'requirements' => [['category' => 'Condición física', 'text' => 'Caminata exigente en altura (4 200 m): aclimatarse 2 días en Cusco.'],
                ['category' => 'Equipamiento', 'text' => 'Ropa de abrigo, bloqueador, agua y bastones (opcional).']],
        ], $this->admin);
        $package = $products->create($base + [
            'code' => 'CUS-MAG', 'name' => 'Cusco Mágico 4D/3N', 'slug' => 'cusco-magico-4d-3n', 'product_type_id' => $type('PAQUETE'), 'category_id' => $category('Cultural'),
            'primary_destination_id' => $dest('Cusco', 'Ciudad'), 'duration_value' => 4, 'maximum_passengers' => 12, 'minimum_age' => 5,
            'short_description' => 'City tour, Valle Sagrado y Machu Picchu con 3 noches de hotel 3* en Cusco, traslados y guía.',
            'long_description' => "Programa de 4 días con hotel 3* en el centro histórico.\nIncluye traslados, City Tour, Valle Sagrado con almuerzo y Machu Picchu en tren Expedition.",
            'seo_title' => 'Paquete Cusco Mágico 4 días / 3 noches', 'seo_description' => 'Paquete a Cusco y Machu Picchu con hotel, tren y guía. Pague con anticipo desde 30 %.',
            'components' => [['service_id' => $svc('Habitación doble')], ['service_id' => $svc('Guía profesional')], ['service_id' => $svc('Movilidad turística')]],
            'itinerary' => [
                ['title' => 'Llegada y City Tour', 'items' => [['start_time' => '10:00', 'activity' => 'Traslado aeropuerto – hotel', 'item_type' => 'Traslado'],
                    ['start_time' => '14:00', 'activity' => 'City Tour Cusco y 4 ruinas', 'item_type' => 'Actividad', 'location' => 'Cusco']]],
                ['title' => 'Valle Sagrado', 'items' => [['start_time' => '07:30', 'activity' => 'Pisac, Urubamba y Ollantaytambo', 'item_type' => 'Actividad', 'location' => 'Valle Sagrado']]],
                ['title' => 'Machu Picchu', 'items' => [['start_time' => '04:30', 'activity' => 'Tren y visita guiada a Machu Picchu', 'item_type' => 'Actividad', 'location' => 'Machu Picchu']]],
                ['title' => 'Salida', 'items' => [['start_time' => '09:00', 'activity' => 'Traslado al aeropuerto', 'item_type' => 'Traslado']]],
            ],
            'rates' => [$rate('Adulto', 'Directo', 'USD', 430, 720), $rate('Adulto', 'E-commerce', 'USD', 430, 690), $rate('Niño', 'Directo', 'USD', 330, 520)],
        ], $this->admin);

        $lifecycle = app(ProductLifecycle::class);
        $availability = app(AvailabilityService::class);
        foreach ([[$humantay, [5, 12, 19, 26, 40], '04:00', 16], [$package, [20, 35, 50], '10:00', 12]] as [$p, $days, $time, $capacity]) {
            foreach ($days as $d) {
                $availability->createDeparture($p->fresh(), ['departure_date' => $today->copy()->addDays($d)->toDateString(), 'departure_time' => $time, 'capacity' => $capacity,
                    'meeting_point' => 'Plaza de Armas de Cusco', 'return_point' => 'Hotel del pasajero'], $this->admin);
            }
            $lifecycle->submitReview($p->fresh(), $this->admin);
            $lifecycle->approve($p->fresh(), $this->admin);
            $lifecycle->activate($p->fresh(), $this->admin);
            $parts->setChannel($p->fresh(), 'ecommerce', true, $this->admin);
        }
        $humantay->fresh()->forceFill(['difficulty' => 'exigente', 'guide_languages' => ['ES', 'EN'], 'pickup_text' => 'Recojo del hotel en el centro histórico de Cusco (04:00–04:30).',
            'rating_avg' => 4.8, 'rating_count' => 126])->save();
        $package->fresh()->forceFill(['difficulty' => 'moderada', 'guide_languages' => ['ES', 'EN'], 'pickup_text' => 'Recojo en el aeropuerto de Cusco con su número de vuelo.',
            'rating_avg' => 4.9, 'rating_count' => 58])->save();

        // Más catálogo en la tienda: Valle Sagrado y Vinicunca (tarifas Directo vigentes).
        $slugs = ['PRD-001' => null, 'PRD-002' => null, 'PRD-003' => 'valle-sagrado-vip', 'PRD-004' => 'montana-de-7-colores-vinicunca'];
        foreach ($slugs as $ref => $slug) {
            $p = Product::where('ref', $ref)->first();
            if ($slug && $p) {
                $p->forceFill(['is_ecommerce' => true, 'slug' => $p->slug ?: $slug])->save();
                $p = $p->fresh();
                $parts->setChannel($p->fresh(), 'ecommerce', true, $this->admin);
            }
        }
        Product::where('ref', 'PRD-001')->update(['difficulty' => 'moderada', 'guide_languages' => json_encode(['ES', 'EN']), 'rating_avg' => 4.9, 'rating_count' => 312,
            'pickup_text' => 'Recojo del hotel en Cusco (04:30).']);
        Product::where('ref', 'PRD-002')->update(['difficulty' => 'facil', 'guide_languages' => json_encode(['ES', 'EN', 'FR']), 'rating_avg' => 4.6, 'rating_count' => 87]);
        Product::where('ref', 'PRD-003')->update(['difficulty' => 'facil', 'guide_languages' => json_encode(['ES', 'EN']), 'rating_avg' => 4.7, 'rating_count' => 64]);
        Product::where('ref', 'PRD-004')->update(['difficulty' => 'exigente', 'rating_avg' => 4.5, 'rating_count' => 140]);

        $this->translations();
        $this->destinations($dest);
        $this->cms();
        $this->webOrder($package->fresh());
        Auth::forgetUser();
    }

    private function translations(): void
    {
        $rows = [
            'PRD-001' => ['Machu Picchu Full Day', 'machu-picchu-full-day-tour', 'Guided visit to the Inca citadel by Expedition train, with buffet lunch.',
                "Early pick-up from your hotel in Cusco, transfer to Ollantaytambo and train ride to Aguas Calientes.\nBus up and 2.5-hour guided tour of the citadel."],
            'PRD-002' => ['Cusco City Tour & 4 Ruins', 'cusco-city-tour-4-ruins', 'Qoricancha, Cathedral, Sacsayhuamán, Q’enqo, Puca Pucara and Tambomachay.', null],
            'PRD-003' => ['Sacred Valley VIP', 'sacred-valley-vip', 'Private tour to Pisac, Urubamba and Ollantaytambo with lunch.', null],
            'PRD-004' => ['Rainbow Mountain (Vinicunca)', 'rainbow-mountain-vinicunca', 'Full-day hike to the 5,100 m Rainbow Mountain with breakfast and lunch.', null],
            'PRD-011' => ['Humantay Lake Full Day', 'humantay-lake-full-day', 'Hike to the turquoise lake at the foot of Mount Salkantay (4,200 m), with breakfast and lunch.',
                "Hotel pick-up in Cusco at 04:00 and drive to Soraypampa with breakfast on the way.\n1.5-hour climb to the lake, free time and return with buffet lunch in Mollepata."],
            'PRD-012' => ['Magical Cusco 4D/3N', 'magical-cusco-4d-3n', 'City tour, Sacred Valley and Machu Picchu with 3 nights at a 3* hotel in Cusco, transfers and guide.', null],
        ];
        foreach ($rows as $ref => [$name, $slug, $short, $long]) {
            $p = Product::where('ref', $ref)->first();
            if ($p) {
                ProductTranslation::create(['product_id' => $p->id, 'locale' => 'en', 'name' => $name, 'slug' => $slug, 'short' => $short, 'long' => $long,
                    'seo_title' => "{$name} · Andes Travel", 'seo_description' => $short]);
            }
        }
    }

    private function destinations(callable $dest): void
    {
        $rows = [
            ['Cusco', 'Departamento', 'cusco', true, 'Capital histórica del Imperio Inca', 'The historic capital of the Inca Empire', '3 399 m s. n. m.',
                'Seco y soleado de mayo a octubre (0–20 °C); lluvias de diciembre a marzo.', 'Dry and sunny May–October (0–20 °C); rainy December–March.'],
            ['Machu Picchu', 'Atracción', 'machu-picchu', true, 'Santuario histórico y maravilla del mundo', 'Historic sanctuary and wonder of the world', '2 430 m s. n. m.',
                'Templado y húmedo todo el año; neblina por la mañana.', 'Mild and humid all year; morning mist.'],
            ['Valle Sagrado', 'Zona', 'valle-sagrado', true, 'Pueblos andinos, mercados y andenes incas', 'Andean villages, markets and Inca terraces', '2 800 m s. n. m.',
                'Más cálido que Cusco; ideal para aclimatarse.', 'Warmer than Cusco; ideal for acclimatization.'],
            ['Lima', 'Ciudad', 'lima', false, 'Capital gastronómica de Sudamérica', 'South America’s food capital', '154 m s. n. m.',
                'Sin lluvias; verano soleado (dic–abr) e invierno nublado.', 'No rain; sunny summer (Dec–Apr) and cloudy winter.'],
            ['Arequipa', 'Departamento', 'arequipa', false, 'La Ciudad Blanca y el Cañón del Colca', 'The White City and the Colca Canyon', '2 335 m s. n. m.',
                'Soleado casi todo el año.', 'Sunny most of the year.'],
            ['Puno', 'Departamento', 'puno', false, 'Lago Titicaca, Uros y Taquile', 'Lake Titicaca, Uros and Taquile', '3 827 m s. n. m.',
                'Frío y seco; noches bajo cero en invierno.', 'Cold and dry; freezing nights in winter.'],
        ];
        foreach ($rows as [$name, $type, $slug, $featured, $es, $en, $alt, $climateEs, $climateEn]) {
            $id = $dest($name, $type);
            if (! $id) {
                continue;
            }
            $faq = [['q' => '¿Necesito aclimatarme?', 'a' => 'Recomendamos 1–2 días de descanso a partir de 3 000 m s. n. m.'],
                ['q' => '¿Cuál es la mejor época?', 'a' => 'De abril a octubre (temporada seca).']];
            DestinationContent::create(['destination_id' => $id, 'locale' => 'es', 'slug' => $slug, 'tagline' => $es, 'description' => "{$es}. Descubra {$name} con operación propia, guías certificados y atención 24/7.",
                'climate' => $climateEs, 'altitude_text' => $alt, 'faqs' => $faq, 'featured' => $featured]);
            DestinationContent::create(['destination_id' => $id, 'locale' => 'en', 'slug' => $slug, 'tagline' => $en, 'description' => "{$en}. Discover {$name} with our own operation, certified guides and 24/7 support.",
                'climate' => $climateEn, 'altitude_text' => str_replace(' s. n. m.', ' a.s.l.', $alt), 'faqs' => [['q' => 'Do I need to acclimatize?', 'a' => 'We recommend 1–2 rest days above 3,000 m.'],
                    ['q' => 'When is the best time to go?', 'a' => 'April to October (dry season).']], 'featured' => $featured]);
        }
    }

    private function cms(): void
    {
        $cms = app(CmsService::class);
        $u = User::where('code', 'U02')->firstOrFail();
        Auth::setUser($u);
        $publish = function (array $data) use ($cms, $u) {
            $c = $cms->create($data, $u, true);

            return $cms->publish($cms->approve($c, [], $u), $u);
        };
        $publish(['title' => 'Cómo llegar a Machu Picchu: tren, bus y entradas', 'type' => 'Artículo', 'channel' => 'Web', 'slug' => 'como-llegar-a-machu-picchu',
            'seo_title' => 'Cómo llegar a Machu Picchu (guía 2026)', 'seo_description' => 'Trenes desde Ollantaytambo, bus a la ciudadela, circuitos y entradas nominativas.',
            'body' => "## En tren\nExpedition o Vistadome desde Ollantaytambo (1 h 40 min).\n## En bus\nConsettur sube desde Aguas Calientes cada 15 minutos.\n- Lleve su pasaporte: la entrada es nominativa."]);
        $publish(['title' => 'Laguna Humantay: qué llevar y cómo prepararse', 'type' => 'Artículo', 'channel' => 'Web', 'slug' => 'laguna-humantay-que-llevar',
            'seo_title' => 'Laguna Humantay: qué llevar', 'seo_description' => 'Lista de equipo, aclimatación y consejos para la caminata a 4 200 m.',
            'body' => "## Equipo\n- Ropa de abrigo por capas\n- Bloqueador y lentes de sol\n## Aclimatación\nDescanse dos días en Cusco antes de la caminata."]);
        $publish(['title' => 'Términos y condiciones', 'type' => 'Página legal', 'channel' => 'Web', 'slug' => 'terminos-y-condiciones', 'seo_title' => 'Términos y condiciones de venta en línea',
            'body' => "## Reservas\nLa reserva se confirma con el pago aprobado o validado.\n## Precios\nPrecios por persona en la moneda indicada; el TC es referencial."]);
        $publish(['title' => 'Política de cancelación', 'type' => 'Página legal', 'channel' => 'Web', 'slug' => 'politica-de-cancelacion', 'seo_title' => 'Política de cancelación',
            'body' => "## Cancelación gratuita\nHasta 48 horas antes en excursiones de un día.\n## Paquetes\nAnticipo no reembolsable a menos de 15 días del inicio."]);
        $publish(['title' => 'Política de cookies', 'type' => 'Página legal', 'channel' => 'Web', 'slug' => 'politica-de-cookies', 'seo_title' => 'Política de cookies',
            'body' => "## Cookies necesarias\nCarrito, idioma, moneda y sesión.\n## Analítica\nSolo con su consentimiento."]);
        Auth::setUser($this->admin);
    }

    /** Pedido web de CLI-0001 (U14): Cusco Mágico a +35 días con anticipo 30 % por Yape (por validar) y saldo pendiente. */
    private function webOrder(Product $package): void
    {
        $u14 = User::where('code', 'U14')->first();
        if (! $u14?->client_id) {
            return;
        }
        $carts = app(CartService::class);
        $checkout = app(SiteCheckout::class);
        $departure = $package->departures()->orderBy('departure_date')->skip(1)->first();
        $cart = $carts->create(['currency' => 'USD', 'locale' => 'es', 'utm' => ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'cusco-2026']], $u14->client_id)['cart'];
        $carts->add($cart, ['product' => $package->ref, 'date' => $departure->departure_date->toDateString(), 'departure_id' => $departure->id, 'adults' => 2, 'extras' => ['insurance' => true]]);
        $order = $checkout->createOrder($cart->fresh(), [
            'buyer' => ['name' => 'Ana Gutiérrez', 'email' => 'cliente@correo.test', 'phone' => '+51 987 654 321', 'country' => 'Perú', 'doc_type' => 'DNI', 'doc_number' => '45678912'],
            'billing' => ['type' => 'Boleta'], 'pay_mode' => 'anticipo', 'consents' => ['terms' => true, 'cancellation' => true, 'privacy' => true],
            'passengers' => [
                ['type' => 'ADT', 'first_name' => 'Ana', 'last_name' => 'Gutiérrez', 'doc_type' => 'DNI', 'doc_number' => '45678912', 'birth_date' => '1988-04-12', 'nationality' => 'Perú'],
                ['type' => 'ADT', 'first_name' => 'Jorge', 'last_name' => 'Salas', 'doc_type' => 'DNI', 'doc_number' => '41234567', 'birth_date' => '1986-09-30', 'nationality' => 'Perú', 'diet' => 'Vegetariano'],
            ],
        ], $u14)['order'];
        $checkout->pay($order, ['method' => 'yape', 'operation_ref' => 'YP-0458812']);
    }
}
