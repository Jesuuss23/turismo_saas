<?php

namespace Database\Seeders;

use App\Models\Bookings\Reservation;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\SalesTarget;
use App\Models\Crm\SalesTargetAdjustment;
use App\Models\Crm\SalesTeam;
use App\Models\Crm\SalesTeamMember;
use App\Models\Iam\User;
use App\Models\Marketing\Automation;
use App\Models\Marketing\CampaignRecipient;
use App\Models\Marketing\Consent;
use App\Models\Marketing\Subscriber;
use App\Models\Marketing\Template;
use App\Services\Crm\CrmClock;
use App\Services\Crm\Targets\SalesTargetService;
use App\Services\Marketing\AttributionService;
use App\Services\Marketing\AutomationService;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\ConsentService;
use App\Services\Marketing\ContactDirectory;
use App\Services\Marketing\SegmentService;
use App\Services\Marketing\TemplateService;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo de Marketing y Metas comerciales (spec 14) para “andes”: suscriptores y consentimientos con evidencia (LEAD-0005 queda sin
 * consentimiento), plantillas aprobadas y en revisión, 4 segmentos, campañas en los 4 estados (CMP-0001 enviada hace 12 días con
 * aperturas, clics, una baja y atribución), 3 automatizaciones activas, 2 equipos y metas: julio/septiembre cerradas como snapshot
 * (§12-11), agosto de U03 vigente por cerrar con 112,9 % (reservas históricas) y las del mes en curso calculadas en vivo.
 */
class MarketingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $u = fn (string $code) => User::where('code', $code)->firstOrFail();
        [$admin, $manager, $carla, $diego] = [$u('U01'), $u('U02'), $u('U03'), $u('U04')];
        // Tercera asesora (sede Cusco) para el ranking de metas: «Su posición: 3 de 3» (E2E 20).
        $jimena = User::create(['code' => 'U20', 'name' => 'Jimena Vela', 'email' => 'jimena.ventas@andes.test', 'password' => DemoSeeder::PASSWORD,
            'sede_id' => DB::table('iam_sedes')->where('tenant_id', app(TenantContext::class)->id())->where('code', 'S02')->value('id'),
            'status' => User::ACTIVE, 'avatar_color' => '#0f766e']);
        $jimena->assignRole('sales');
        $manager->forceFill(['phone' => '+51 999 111 222'])->save(); // recibe las pruebas de WhatsApp (MKT-BR-18)
        $clock = app(CrmClock::class);
        $today = $clock->today();

        $this->marketing($admin, $manager, $today);
        $this->targets($admin, $manager, $carla, $diego, $jimena, $today);
        Carbon::setTestNow();
    }

    private function marketing(User $admin, User $manager, Carbon $today): void
    {
        $at = fn (int $daysAgo, string $time = '10:00') => Carbon::parse($today->copy()->subDays($daysAgo)->toDateString().' '.$time, $today->timezone);
        $sequences = app(SequenceService::class);

        // Suscriptores del newsletter (doble opt-in); SUB-0005 se inscribió ayer (cola de bienvenida).
        $subscribers = [
            ['Rocío Delgado', 'rocio.delgado@correo.test', '987 222 333', 'Perú', 'Cusco', 40],
            ['Thomas Becker', 'thomas.becker@correo.test', null, 'Alemania', 'Machu Picchu', 33],
            ['Gabriela Núñez', 'gabriela.nunez@correo.test', '965 444 777', 'Perú', 'Arequipa', 21],
            ['Paolo Rinaldi', 'paolo.rinaldi@correo.test', null, 'Italia', 'Cusco', 15],
            ['Daniela Paz', 'daniela.paz@correo.test', '954 888 111', 'Perú', 'Paracas', 1],
        ];
        foreach ($subscribers as [$name, $email, $phone, $country, $destination, $days]) {
            Carbon::setTestNow($at($days, '09:15'));
            $s = Subscriber::create(['code' => $sequences->next('mkt_subscriber'), 'full_name' => $name, 'email' => $email, 'phone' => $phone,
                'phone_e164' => ContactDirectory::mobile($phone), 'country' => $country, 'interest_destination' => $destination, 'source' => 'Newsletter web',
                'double_opt_in_at' => now()->addMinutes(4)]);
            $this->grant('subscriber', $s->id, 'email', 'Newsletter (doble opt-in)', 'Doble opt-in · formulario NL-web · IP 190.235.'.(10 + $days).'.4');
        }

        // Consentimientos de clientes y leads con su evidencia (LEAD-0005 Camila Torres queda sin consentimiento: E2E 18).
        $client = fn (string $code) => Client::where('code', $code)->first();
        $grants = [
            ['CLI-0001', 'email', 'Portal del cliente', 'Casilla independiente en el portal · sesión cliente@correo.test', 60],
            ['CLI-0001', 'whatsapp', 'Opt-in WhatsApp', 'wamid.HBgLNTE5ODc2NTQzMjEVAgARGBI0', 58],
            ['CLI-0003', 'email', 'Checkout e-commerce (casilla)', 'Pedido web · casilla «Quiero recibir ofertas» · IP 73.92.18.5', 55],
            ['CLI-0005', 'email', 'Ficha firmada', 'Ficha de cliente F-2026-0412 escaneada', 50],
            ['CLI-0005', 'whatsapp', 'Ficha firmada', 'Ficha de cliente F-2026-0412 escaneada', 50],
            ['CLI-0006', 'email', 'Formulario web (casilla)', 'Formulario contacto-web · IP 181.65.20.11', 45],
            ['CLI-0011', 'email', 'Formulario web (casilla)', 'Formulario contacto-web · IP 200.48.3.90', 40],
            ['CLI-0011', 'whatsapp', 'Opt-in WhatsApp', 'wamid.HBgLNTE5ODcxMTEyMjIVAgARGBI1', 40],
            ['CLI-0012', 'email', 'Llamada grabada', 'Grabación CALL-2026-0931 (min 03:12)', 35],
            ['CLI-0013', 'email', 'Portal del cliente', 'Casilla independiente en el portal', 30],
            ['CLI-0013', 'whatsapp', 'Opt-in WhatsApp', 'wamid.HBgLNTE5ODc2NTQzMjEVAgARGBI2', 30],
        ];
        foreach ($grants as [$code, $channel, $source, $evidence, $days]) {
            if ($c = $client($code)) {
                Carbon::setTestNow($at($days, '11:30'));
                $this->grant('client', $c->id, $channel, $source, $evidence, $code === 'CLI-0012' ? 'PDP-2025.2' : 'PDP-2026.1');
            }
        }
        foreach (['LEAD-0003' => 'Formulario web (casilla)', 'LEAD-0002' => 'Opt-in WhatsApp'] as $code => $source) {
            if ($lead = Lead::where('code', $code)->first()) {
                Carbon::setTestNow($at(20, '16:00'));
                $this->grant('lead', $lead->id, $source === 'Opt-in WhatsApp' ? 'whatsapp' : 'email', $source,
                    $source === 'Opt-in WhatsApp' ? 'wamid.HBgLNTE5MTIzMzM0NDQVAgARGBI3' : 'Formulario cotiza-web · IP 179.7.55.120');
            }
        }

        // Plantillas: TPL-03 (recordatorio pre-viaje) aprobada por Meta; nuevas de newsletter y promoción.
        $templates = app(TemplateService::class);
        Carbon::setTestNow($at(25));
        $templates->review(Template::where('code', 'TPL-03')->firstOrFail(), 'aprobada', null);
        $newsletter = $templates->create(['channel' => 'email', 'name' => 'Newsletter mensual', 'category' => 'MARKETING',
            'subject' => '{{nombre}}, nuevas salidas para este mes',
            'body' => "Hola {{nombre}}:\n\nTe compartimos las salidas del mes con cupos confirmados y precios de temporada.\n\nReserva con tu asesor o en nuestra web.\n\nSi no deseas recibir más correos: {{enlace_baja}}"], $admin);
        $promo = $templates->create(['channel' => 'whatsapp', 'name' => 'promo_temporada', 'category' => 'MARKETING',
            'body' => "Hola {{nombre}} 👋 Tenemos cupos para la temporada en {{destino}}. Usa el código {{codigo_promo}} y obtén un descuento especial.\nResponde BAJA para no recibir más mensajes."], $admin);
        $templates->review($promo, 'aprobada', null);
        $templates->create(['channel' => 'whatsapp', 'name' => 'novedades_trekking', 'category' => 'MARKETING',
            'body' => "Hola {{nombre}}, abrimos nuevas fechas de trekking. ¿Te enviamos el itinerario?\nResponde BAJA para no recibir más mensajes."], $admin);

        // Segmentos SEG-001…004.
        $segments = app(SegmentService::class);
        $peru = $segments->create(['name' => 'Clientes Perú', 'description' => 'Clientes con país Perú', 'match' => 'all',
            'rules' => [['f' => 'type', 'op' => 'eq', 'v' => 'client'], ['f' => 'country', 'op' => 'eq', 'v' => 'Perú']]], $manager);
        $cusco = $segments->create(['name' => 'Interesados en Cusco', 'description' => 'Destino de interés Cusco o Machu Picchu', 'match' => 'any',
            'rules' => [['f' => 'destination', 'op' => 'contains', 'v' => 'Cusco'], ['f' => 'destination', 'op' => 'contains', 'v' => 'Machu']]], $manager);
        $segments->create(['name' => 'Sin compras hace un año', 'description' => 'Clientes para reactivación', 'match' => 'all',
            'rules' => [['f' => 'type', 'op' => 'eq', 'v' => 'client'], ['f' => 'last_purchase', 'op' => 'older', 'v' => 365]]], $manager);
        $segments->create(['name' => 'Suscriptores del newsletter', 'description' => null, 'match' => 'all',
            'rules' => [['f' => 'type', 'op' => 'eq', 'v' => 'subscriber']]], $manager);

        $campaigns = app(CampaignService::class);
        app(ContactDirectory::class)->forget();

        // CMP-0001: newsletter enviado hace 12 días con aperturas, clics y una baja (CLI-0006 desde el enlace).
        Carbon::setTestNow($at(12, '09:00'));
        $sent = $this->as($manager, fn () => $campaigns->create(['name' => 'Newsletter octubre', 'channel' => 'email', 'segment_id' => $peru->id,
            'template_id' => $newsletter->id], $manager));
        $campaigns->testSend($sent, ['to_user_id' => $manager->id], $manager);
        Carbon::setTestNow($at(12, '10:00'));
        $campaigns->send($sent, $manager);
        $campaigns->processBatch($sent->fresh());
        $recipients = CampaignRecipient::where('campaign_id', $sent->id)->orderBy('name')->get();
        foreach ($recipients as $i => $r) {
            $r->forceFill(['delivered_at' => $r->sent_at?->copy()->addMinute(), 'status' => 'entregado',
                'opened_at' => $i % 3 !== 2 ? $at(12, sprintf('%02d:%02d', 11 + $i, 5 * $i)) : null,
                'clicked_at' => $i % 3 === 0 ? $at(12, sprintf('%02d:%02d', 11 + $i, 5 * $i + 3)) : null])->save();
        }
        $optout = Client::where('code', 'CLI-0006')->first();
        if ($optout && $r = $recipients->firstWhere('subject_id', $optout->id)) {
            Carbon::setTestNow($at(11, '08:40'));
            $consent = Consent::where('subject_type', 'client')->where('subject_id', $optout->id)->where('channel', 'email')->first();
            app(ConsentService::class)->revoke($consent, 'Enlace de baja', null, $sent, '181.65.20.11');
            $r->forceFill(['unsubscribed_at' => now()])->save();
        }

        // CMP-0002: WhatsApp programada para mañana 10:00; CMP-0003 en borrador; CMP-0004 cancelada.
        Carbon::setTestNow($at(1, '15:00'));
        $wa = $campaigns->create(['name' => 'Temporada Cusco por WhatsApp', 'channel' => 'whatsapp', 'segment_id' => $cusco->id, 'template_id' => $promo->id,
            'promotion_id' => 'BIENVENIDO5'], $manager);
        $campaigns->testSend($wa, ['to_user_id' => $manager->id], $manager);
        Carbon::setTestNow();
        $campaigns->schedule($wa->fresh(), $today->copy()->addDay()->setTime(10, 0)->toIso8601String(), $manager);
        $campaigns->create(['name' => 'Newsletter noviembre', 'channel' => 'email', 'segment_id' => $peru->id, 'template_id' => $newsletter->id], $manager);
        $cancelled = $campaigns->create(['name' => 'Feriado largo (duplicada)', 'channel' => 'email', 'segment_id' => $peru->id, 'template_id' => $newsletter->id], $manager);
        $campaigns->cancel($cancelled, 'Se duplicó con el newsletter de octubre', $manager);

        // Automatizaciones activas: bienvenida, recordatorio pre-viaje y encuesta post-viaje.
        $automations = app(AutomationService::class);
        foreach (['AUT-01', 'AUT-03', 'AUT-04'] as $code) {
            $automations->activate(Automation::with('template')->where('code', $code)->firstOrFail(), $admin);
        }

        app(AttributionService::class)->recompute();
        app(ContactDirectory::class)->forget();
    }

    private function targets(User $admin, User $manager, User $carla, User $diego, User $jimena, Carbon $today): void
    {
        $sequences = app(SequenceService::class);
        $service = app(SalesTargetService::class);
        $tenant = app(TenantContext::class)->id();
        $lima = $today->timezone;
        $month = fn (int $offset) => $today->copy()->startOfMonth()->addMonthsNoOverflow($offset);
        $sede = fn (string $code) => DB::table('iam_sedes')->where('tenant_id', $tenant)->where('code', $code)->orderBy('created_at')->value('id');

        // Equipos de ventas.
        Carbon::setTestNow($month(-4)->setTime(9, 0));
        $team = fn (string $name, User $leader, array $members) => tap(SalesTeam::create(['code' => $sequences->next('sales_team'), 'name' => $name,
            'leader_id' => $leader->id]), function ($t) use ($members) {
                foreach ($members as $m) {
                    SalesTeamMember::create(['team_id' => $t->id, 'user_id' => $m->id, 'valid_from' => now()->toDateString()]);
                }
            });
        $lima1 = $team('Ventas Lima', $manager, [$carla, $diego]);
        $team('Ventas Cusco', $manager, [$jimena]);

        // Reservas históricas de Carla en agosto (meta por cerrar: 13 548 / 12 000 = 112,9 %) y una de Jimena al inicio del mes en curso.
        $template = Reservation::where('code', 'RES-2026-000355')->first();
        $history = [
            ['RES-2026-000341', $carla, $month(-2)->addDays(4), 'Circuito Sur 6D/5N · familia Gutiérrez', 'CLI-0001', 5200.00],
            ['RES-2026-000342', $carla, $month(-2)->addDays(12), 'Machu Picchu y Valle Sagrado 4D/3N', 'CLI-0005', 4848.00],
            ['RES-2026-000343', $carla, $month(-2)->addDays(20), 'Arequipa y Colca 3D/2N', 'CLI-0012', 3500.00],
            ['RES-2026-000344', $jimena, $month(0)->addDays(1), 'Cusco clásico 4D/3N', 'CLI-0013', 4000.00],
        ];
        foreach ($history as [$code, $owner, $created, $title, $clientCode, $amount]) {
            if (! $template || Reservation::where('code', $code)->exists()) {
                continue;
            }
            $past = $created->lessThan($today->copy()->startOfMonth());
            $from = $past ? $created->copy()->addDays(14) : $today->copy()->addDays(20);
            $row = (array) DB::table('booking_reservations')->where('id', $template->id)->first();
            DB::table('booking_reservations')->insert(array_merge($row, ['id' => (string) Str::uuid7(), 'code' => $code, 'quote_id' => null, 'quote_version_id' => null,
                'quote_version_no' => null, 'ecom_order_id' => null, 'client_id' => Client::where('code', $clientCode)->value('id') ?? $row['client_id'],
                'owner_id' => $owner->id, 'sede_id' => $owner->sede_id, 'title' => $title, 'status' => $past ? Reservation::COMPLETED : Reservation::CONFIRMED,
                'travel_from' => $from->toDateString(), 'travel_to' => $from->copy()->addDays(4)->toDateString(), 'currency' => 'PEN', 'fx_rate' => 1,
                'total_amount' => $amount, 'cancelled_at' => null, 'cancel_reason' => null, 'notes' => null, 'lock_version' => 0,
                'created_by' => $owner->id, 'updated_by' => $owner->id, 'created_at' => $created->copy()->setTime(11, 20)->utc(), 'updated_at' => $created->copy()->setTime(11, 20)->utc()]));
        }

        // Metas: julio (M-3) y septiembre (M-1) cerradas como snapshot; agosto (M-2) de U04 cerrada y la de U03 vigente por cerrar.
        $create = function (array $data, Carbon $when) use ($service, $manager) {
            Carbon::setTestNow($when);

            return $service->create($data + ['period_type' => 'mes', 'activate' => true], $manager);
        };
        $snapshot = function (SalesTarget $t, float $sales, int $bookings, float $margin, ?float $conversion) use ($manager, $lima) {
            $closedAt = Carbon::parse($t->period_to->toDateString(), $lima)->addDays(2)->setTime(18, 0);
            $pct = round(100 * $sales / (float) $t->goal_sales_pen, 1);
            $t->forceFill(['status' => 'cerrada', 'result' => ['sales' => $sales, 'bookings' => $bookings, 'margin' => $margin, 'conversion' => $conversion,
                'won' => 0, 'lost' => 0, 'pct' => $pct, 'reservation_ids' => [], 'closed_at' => $closedAt->toIso8601String(), 'closed_by' => $manager->id, 'snapshot' => true]])->save();
            SalesTargetAdjustment::create(['target_id' => $t->id, 'action' => 'cerrar', 'after' => ['sales' => $sales, 'pct' => $pct],
                'reason' => 'Resultado histórico (snapshot)', 'actor_id' => $manager->id, 'occurred_at' => $closedAt]);
        };
        $key = fn (int $offset) => $month($offset)->format('Y-m');
        $start = fn (int $offset) => $month($offset)->setTime(9, 0);

        $snapshot($create(['scope_type' => 'asesor', 'scope_id' => $carla->id, 'period_key' => $key(-3), 'goal_sales_pen' => 11000, 'goal_bookings' => 6], $start(-3)), 10120, 6, 2380, 42.9);
        $snapshot($create(['scope_type' => 'asesor', 'scope_id' => $diego->id, 'period_key' => $key(-3), 'goal_sales_pen' => 9000, 'goal_bookings' => 5], $start(-3)), 7650, 4, 1710, 33.3);
        $create(['scope_type' => 'asesor', 'scope_id' => $carla->id, 'period_key' => $key(-2), 'goal_sales_pen' => 12000, 'goal_bookings' => 3, 'bonus_amount' => 600], $start(-2));
        $snapshot($create(['scope_type' => 'asesor', 'scope_id' => $diego->id, 'period_key' => $key(-2), 'goal_sales_pen' => 9000], $start(-2)), 8280, 5, 1890, 40.0);
        $snapshot($create(['scope_type' => 'asesor', 'scope_id' => $carla->id, 'period_key' => $key(-1), 'goal_sales_pen' => 12000], $start(-1)), 9840, 5, 2210, 37.5);
        $snapshot($create(['scope_type' => 'asesor', 'scope_id' => $diego->id, 'period_key' => $key(-1), 'goal_sales_pen' => 9000], $start(-1)), 9450, 6, 2050, 50.0);

        // Mes en curso (en vivo): MET-0007 de Diego (fuera de la cartera de Carla), MET-0008 de Carla, MET-0009 de Jimena, sede Lima y equipo.
        $create(['scope_type' => 'asesor', 'scope_id' => $diego->id, 'period_key' => $key(0), 'goal_sales_pen' => 10000, 'goal_bookings' => 6, 'bonus_amount' => 500], $start(0));
        $create(['scope_type' => 'asesor', 'scope_id' => $carla->id, 'period_key' => $key(0), 'goal_sales_pen' => 50000, 'goal_bookings' => 12,
            'goal_margin_pen' => 9000, 'goal_conversion_pct' => 35, 'bonus_amount' => 800], $start(0));
        $create(['scope_type' => 'asesor', 'scope_id' => $jimena->id, 'period_key' => $key(0), 'goal_sales_pen' => 8000, 'goal_bookings' => 3, 'bonus_amount' => 400], $start(0));
        $create(['scope_type' => 'sede', 'scope_id' => $sede('S01'), 'period_key' => $key(0), 'goal_sales_pen' => 60000], $start(0));
        $create(['scope_type' => 'equipo', 'scope_id' => $lima1->id, 'period_key' => $key(0), 'goal_sales_pen' => 55000, 'goal_bookings' => 18], $start(0));

        // Próximo mes en borrador.
        Carbon::setTestNow();
        $service->create(['scope_type' => 'asesor', 'scope_id' => $carla->id, 'period_type' => 'mes', 'period_key' => $key(1), 'goal_sales_pen' => 40000, 'bonus_amount' => 800], $manager);
    }

    private function grant(string $type, string $id, string $channel, string $source, string $evidence, string $policy = 'PDP-2026.1'): void
    {
        app(ConsentService::class)->grant(['subject_type' => $type, 'subject_id' => $id, 'channel' => $channel, 'source' => $source, 'evidence' => $evidence,
            'policy_version' => $policy], null);
    }

    private function as(User $user, callable $fn): mixed
    {
        Auth::setUser($user);
        try {
            return $fn();
        } finally {
            Auth::forgetUser();
        }
    }
}
