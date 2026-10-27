<?php

namespace Database\Seeders;

use App\Models\Bookings\Reservation;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductB2bRate;
use App\Models\Channel\B2bAgency;
use App\Models\Channel\B2bPriceList;
use App\Models\Crm\Client;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Models\Ops\Order;
use App\Services\Catalog\ProductParts;
use App\Services\Channels\B2bAgencyService;
use App\Services\Channels\B2bPortal;
use App\Services\Channels\CmsService;
use App\Services\Channels\EcomAdmin;
use App\Services\Channels\EcomCheckout;
use App\Services\Channels\SupportTickets;
use App\Services\Crm\ClientService;
use App\Services\Crm\CrmClock;
use App\Services\Finance\InvoiceService;
use App\Services\Ops\IncidentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Demo de Canales y portales (spec 08): convenios B2B completos (uno por vencer, uno bloqueado, uno vencido), tarifas por agencia,
 * una reserva B2B a crédito con pago informado, tienda con promociones y pedidos web en distintos estados, contenidos CMS,
 * una alerta pública para el pasajero y una solicitud del portal del cliente.
 */
class ChannelsDemoSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $u = [];

    public function run(): void
    {
        $this->u = User::whereIn('code', ['U01', 'U02', 'U05', 'U07', 'U13', 'U14', 'U15'])->get()->keyBy('code')->all();
        $today = app(CrmClock::class)->today();
        $lists = B2bPriceList::pluck('id', 'name');
        $agencies = app(B2bAgencyService::class);

        // Convenios: datos de alta completos (B2B-BR-09) sobre las agencias que venían de Finanzas.
        $rows = [
            'AGB-01' => ['ruc' => '20512345671', 'contact_name' => 'Lucía Paredes', 'booking_email' => 'reservas@solviajes.test', 'price_list_id' => $lists['Mayorista 2026'],
                'commission_pct' => 10, 'credit_limit' => 15000, 'valid_from' => '2026-01-01', 'valid_to' => $today->copy()->addDays(150)->toDateString(), 'status' => 'activa'],
            'AGB-02' => ['ruc' => '20587654329', 'contact_name' => 'Diego Salas', 'booking_email' => 'operaciones@andespartners.test', 'price_list_id' => $lists['Corporativo 2026'],
                'commission_pct' => 12, 'credit_limit' => 0, 'valid_from' => '2026-01-01', 'valid_to' => $today->copy()->addDays(20)->toDateString(), 'status' => 'activa'],
            'AGB-03' => ['ruc' => '20555666771', 'contact_name' => 'Marta León', 'booking_email' => 'reservas@viajesexpress.test', 'price_list_id' => $lists['Minorista 2026'],
                'commission_pct' => 8, 'credit_limit' => 5000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'status' => 'bloqueada'],
        ];
        $this->as('U01', function () use ($rows, $agencies) {
            foreach ($rows as $code => $data) {
                $agency = B2bAgency::where('code', $code)->firstOrFail();
                if (! $agency->client_id) {
                    $client = app(ClientService::class)->create(['kind' => 'empresa', 'name' => $agency->name, 'doc_type' => 'RUC', 'doc_number' => $data['ruc'],
                        'email' => $data['booking_email'], 'segment_code' => 'b2b', 'tags' => ['B2B']], $this->u['U01'], true,
                        ['name' => $data['contact_name'], 'role' => 'Contacto de convenio', 'email' => $data['booking_email']]);
                    $client->forceFill(['b2b_agency_id' => $agency->id])->save();
                    $agency->forceFill(['client_id' => $client->id])->save();
                }
                $agencies->update($agency->fresh(), $data + ['name' => str_replace(' (bloqueada)', ' S.A.C.', $agency->name)], $this->u['U01']);
            }
            B2bAgency::where('code', 'AGB-03')->update(['block_reason' => 'manual', 'block_note' => 'Facturas con más de 60 días de atraso']);
            $agencies->create(['name' => 'Cusco Trails E.I.R.L.', 'ruc' => '20601112223', 'contact_name' => 'Renzo Quispe', 'booking_email' => 'ventas@cuscotrails.test',
                'price_list_id' => B2bPriceList::where('name', 'Minorista 2026')->value('id'), 'commission_pct' => 9, 'credit_limit' => 3000,
                'valid_from' => '2025-11-01', 'valid_to' => app(CrmClock::class)->today()->subDays(5)->toDateString(), 'status' => 'activa'], $this->u['U01']);
        });

        // Tarifas B2B por agencia (B2B-BR-10) y publicación del canal B2B.
        $parts = app(ProductParts::class);
        $mp = Product::where('ref', 'PRD-001')->firstOrFail();
        $vinicunca = Product::where('ref', 'PRD-004')->firstOrFail();
        $this->as('U01', function () use ($parts, $mp, $vinicunca) {
            $id = fn (string $code) => B2bAgency::where('code', $code)->value('id');
            $parts->saveB2bRate($vinicunca, ['agency_id' => $id('AGB-01'), 'price_list' => 'Mayorista 2026', 'price' => 125, 'currency' => 'PEN', 'commission_pct' => 10, 'is_credit' => true,
                'valid_to' => '2027-06-30', 'conditions' => 'Incluye desayuno y almuerzo', 'restrictions' => 'No reembolsable a 48 h'], $this->u['U01']);
            $parts->saveB2bRate($mp, ['agency_id' => $id('AGB-02'), 'price_list' => 'Corporativo 2026', 'price' => 330, 'commission_pct' => 12, 'is_credit' => false,
                'valid_to' => '2027-06-30'], $this->u['U01']);
            $parts->setChannel($vinicunca->fresh(), 'b2b', true, $this->u['U01']);
            // Viajes Express tenía tarifa acordada antes del bloqueo manual: la conserva, pero solo reserva con prepago (P02).
            ProductB2bRate::create(['product_id' => $vinicunca->id, 'agency_id' => $id('AGB-03'), 'price_list_id' => B2bPriceList::where('name', 'Minorista 2026')->value('id'),
                'price_list' => 'Minorista 2026', 'price' => 135, 'currency' => 'PEN', 'commission_pct' => 8, 'is_credit' => true, 'valid_to' => '2026-12-31']);
        });

        // Reserva B2B a crédito de Sol Viajes (U13) con pago informado por validar.
        $departure = Departure::where('product_id', $mp->id)->where('status', 'abierta')
            ->whereDate('departure_date', '>=', $today->copy()->addDays($mp->booking_advance_min_days)->toDateString())->orderBy('departure_date')->firstOrFail();
        $sol = B2bAgency::where('code', 'AGB-01')->firstOrFail();
        $reservation = $this->as('U13', fn () => app(B2bPortal::class)->reserve($sol, ['product_id' => $mp->id, 'departure_id' => $departure->id, 'pax' => 2, 'pay_mode' => 'credito',
            'passengers' => "Ana Torres — 45678912\nLuis Vega — 47896541", 'agency_ref' => 'SV-2026-118'], $this->u['U13']));
        $this->as('U13', fn () => app(B2bPortal::class)->reportPayment($sol->fresh(), Receivable::where('reservation_id', $reservation->id)->firstOrFail(),
            ['amount' => 300, 'method' => 'Transferencia BCP', 'operation_number' => 'OP-902341'], $this->u['U13']));

        // Tienda: publicación, promociones (código y por cantidad) y pedidos web.
        $city = Product::where('ref', 'PRD-002')->firstOrFail();
        $admin = app(EcomAdmin::class);
        $this->as('U02', function () use ($admin, $mp, $city, $today) {
            $admin->publish($city, true, $this->u['U02']);
            $admin->createPromotion($mp, ['name' => 'Primavera web', 'value' => 8, 'promo_code' => 'PRIMAVERA8', 'date_from' => $today->toDateString(),
                'date_to' => $today->copy()->addDays(60)->toDateString()], $this->u['U02']);
            $admin->createPromotion($city, ['name' => 'Grupo familiar', 'value' => 10, 'min_pax' => 4, 'date_from' => $today->toDateString(),
                'date_to' => $today->copy()->addDays(90)->toDateString()], $this->u['U02']);
        });
        Auth::forgetUser();
        $shop = app(EcomCheckout::class);
        $buyer = fn (string $name, string $email, string $doc) => ['name' => $name, 'doc_type' => 'DNI', 'doc_number' => $doc, 'email' => $email,
            'phone' => '+51 987 654 321', 'country' => 'Perú', 'accept_privacy' => true];
        $paid = $shop->createOrder(['product' => 'PRD-002', 'date' => $today->copy()->addDays(6)->toDateString(), 'adults' => 2,
            'buyer' => $buyer('Valeria Campos', 'valeria.campos@correo.test', '70123456'), 'passengers' => [['full_name' => 'Jorge Campos', 'doc_number' => '70123457']]]);
        $shop->pay($paid['order'], ['number' => '4111 1111 1111 1111', 'expiry' => '12/28', 'cvv' => '123']);
        // Contabilidad ya emitió la boleta del pedido pagado (los cobros web no se facturan solos).
        $this->as('U07', function () use ($paid) {
            $invoices = app(InvoiceService::class);
            $r = Receivable::where('reservation_id', $paid['order']->fresh()->reservation_id)->firstOrFail();
            $invoices->issue(['receivable_id' => $r->id, 'domiciled' => true, 'amount' => $r->paid_amount] + $invoices->draft($r), $this->u['U07']);
        });
        $rejected = $shop->createOrder(['product' => 'PRD-001', 'departure_id' => $departure->id, 'adults' => 1, 'code' => 'PRIMAVERA8',
            'buyer' => $buyer('Martín Rojas', 'martin.rojas@correo.test', '71234567'), 'passengers' => []]);
        $shop->pay($rejected['order'], ['number' => '4000 0000 0000 0002', 'expiry' => '11/27', 'cvv' => '321']);
        $shop->createOrder(['product' => 'PRD-002', 'date' => $today->copy()->addDays(10)->toDateString(), 'adults' => 4,
            'buyer' => $buyer('Sofía Herrera', 'sofia.herrera@correo.test', '72345678'), 'passengers' => [['full_name' => 'Pablo Herrera', 'doc_number' => '72345679'],
                ['full_name' => 'Inés Herrera', 'doc_number' => '72345680'], ['full_name' => 'Tomás Herrera', 'doc_number' => '72345681']]]);

        // CMS: flujo editorial completo en varios estados.
        $cms = app(CmsService::class);
        $this->as('U02', function () use ($cms) {
            $u = $this->u['U02'];
            $publish = function (array $data) use ($cms, $u) {
                $c = $cms->create($data, $u, true);
                $c = $cms->approve($c, [], $u);

                return $cms->publish($c, $u);
            };
            $publish(['title' => 'Quiénes somos', 'type' => 'Página', 'channel' => 'Web', 'slug' => 'nosotros', 'seo_title' => 'Andes Travel · Agencia de viajes en Cusco',
                'seo_description' => 'Operador turístico con 15 años en Cusco, Valle Sagrado y Machu Picchu.',
                'body' => "## Nuestra historia\nSomos un equipo cusqueño que diseña viajes con operación propia.\n- Guías certificados\n- Atención 24/7"]);
            $publish(['title' => 'Primavera en Machu Picchu: 8 % de descuento', 'type' => 'Banner', 'channel' => 'Web / E-commerce', 'slug' => 'primavera-machu-picchu',
                'placement' => 'shop_top', 'seo_title' => 'Primavera en Machu Picchu', 'body' => 'Use el código PRIMAVERA8 al pagar en línea.']);
            $cms->create(['title' => '10 consejos para el soroche', 'type' => 'Artículo', 'channel' => 'Web', 'body' => "## Antes de llegar\n- Hidrátese\n- Descanse el primer día"], $u, true);
            $cms->create(['title' => 'Landing Fiestas Patrias', 'type' => 'Landing', 'channel' => 'Web', 'body' => 'Borrador de campaña.'], $u);
            $publish(['title' => 'Política de privacidad', 'type' => 'Página legal', 'channel' => 'Web / Portal', 'slug' => 'politica-de-privacidad',
                'seo_title' => 'Política de privacidad · Ley 29733', 'body' => "## Tratamiento de datos personales\nSus datos se usan solo para gestionar su viaje (Ley 29733)."]);
            $publish(['title' => 'Bienvenido a su portal', 'type' => 'Banner', 'channel' => 'Portal cliente', 'slug' => 'bienvenida-portal-cliente', 'placement' => 'portal_home',
                'seo_title' => 'Portal del cliente', 'body' => 'Revise sus propuestas, pague en línea y descargue sus documentos.']);
        });

        // Alerta pública para el pasajero (PPX-BR-03) en el viaje de John Miller.
        $john = $this->u['U15']->fresh()->reservation_id ? Reservation::find($this->u['U15']->fresh()->reservation_id) : null;
        $order = $john ? Order::where('reservation_id', $john->id)->first() : null;
        if ($order) {
            $incident = $this->as('U05', fn () => app(IncidentService::class)->create(['order_id' => $order->id, 'type' => 'Horario', 'severity' => 'media',
                'title' => 'Proveedor adelanta recojo del Valle Sagrado', 'description' => 'Transportes Qhapaq adelanta el recojo 30 min por obras en la vía.'], $this->u['U05']));
            // En proceso: Operaciones ya coordina con el proveedor; sigue activa para el pasajero hasta cerrarse.
            $incident->forceFill(['status' => 'en_proceso', 'public_title' => 'Cambio de hora de recojo',
                'public_message' => 'Mañana lo recogeremos 30 minutos antes (06:30) en el lobby de su hotel por obras en la carretera.'])->save();
        }

        // Solicitud desde el portal del cliente (PCL-BR-09).
        $client = Client::find($this->u['U14']->client_id);
        if ($client) {
            app(SupportTickets::class)->forClient($client, ['subject' => '¿Puedo agregar una noche en Aguas Calientes?', 'category' => 'Cambio de fecha',
                'message' => 'Quisiéramos quedarnos una noche más antes de volver a Cusco. ¿Qué opciones tienen?'], $this->u['U14']);
        }
        Auth::forgetUser();
    }

    private function as(string $code, callable $fn): mixed
    {
        Auth::setUser($this->u[$code]);

        return $fn();
    }
}
