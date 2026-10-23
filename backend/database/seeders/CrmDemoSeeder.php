<?php

namespace Database\Seeders;

use App\Models\Crm\Activity;
use App\Models\Crm\Lead;
use App\Models\Iam\User;
use App\Services\Crm\ActivityService;
use App\Services\Crm\AfterSalesService;
use App\Services\Crm\ClientService;
use App\Services\Crm\CrmClock;
use App\Services\Crm\LeadConverter;
use App\Services\Crm\LeadService;
use App\Services\Crm\OpportunityService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Cartera CRM de demostración del tenant "andes" (spec 01). Se crea con los mismos servicios
 * que usa la API, así cada registro deja su auditoría y respeta las reglas de negocio.
 * Requiere el contexto de tenant y los usuarios internos ya creados.
 */
class CrmDemoSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $u = [];

    public function run(): void
    {
        $this->u = User::whereIn('code', ['U01', 'U02', 'U03', 'U04', 'U05'])->get()->keyBy('code')->all();
        $clock = app(CrmClock::class);
        $today = $clock->today();

        // ── Clientes (CLI-0001…CLI-0007) ────────────────────────────────
        $clients = app(ClientService::class);
        $c = [];
        foreach ([
            ['U03', ['kind' => 'persona', 'name' => 'Ana Gutiérrez', 'doc_type' => 'DNI', 'doc_number' => '45678912', 'email' => 'cliente@correo.test', 'phone' => '+51 987 654 321', 'city' => 'Lima', 'segment_code' => 'retail', 'tags' => ['Formulario web']]],
            ['U04', ['kind' => 'empresa', 'name' => 'Minera del Sur S.A.', 'doc_type' => 'RUC', 'doc_number' => '20512345678', 'email' => 'viajes@minerasur.test', 'phone' => '01 4455667', 'city' => 'Arequipa', 'segment_code' => 'corporativo', 'tags' => ['B2B']], ['name' => 'Patricia León', 'role' => 'Jefa de RR. HH.', 'email' => 'pleon@minerasur.test']],
            ['U03', ['kind' => 'persona', 'name' => 'John Miller', 'doc_type' => 'PASAPORTE', 'doc_number' => 'X1234567', 'email' => 'pasajero@correo.test', 'phone' => '+1 415 555 0101', 'country' => 'Estados Unidos', 'city' => 'San Francisco', 'segment_code' => 'vip', 'tags' => ['Referido']]],
            ['U04', ['kind' => 'empresa', 'name' => 'Colegio San Agustín', 'doc_type' => 'RUC', 'doc_number' => '20456789123', 'email' => 'promocion@sanagustin.test', 'phone' => '01 3344556', 'city' => 'Lima', 'segment_code' => 'grupos', 'tags' => ['Feria Turismo']], ['name' => 'Hno. Carlos Ruiz', 'role' => 'Coordinador de promoción', 'phone' => '999 111 222']],
            ['U03', ['kind' => 'persona', 'name' => 'María Fernández', 'doc_type' => 'DNI', 'doc_number' => '41234567', 'email' => 'maria.fernandez@correo.test', 'phone' => '956 123 456', 'city' => 'Trujillo', 'segment_code' => 'retail', 'tags' => ['Instagram']]],
            ['U04', ['kind' => 'persona', 'name' => 'Luis Ramírez', 'doc_type' => 'DNI', 'doc_number' => '40987654', 'email' => 'luis.ramirez@correo.test', 'phone' => '945 678 123', 'city' => 'Lima', 'segment_code' => 'vip', 'tags' => ['Referido']]],
            ['U02', ['kind' => 'empresa', 'name' => 'Agencia Sol Viajes S.A.C.', 'doc_type' => 'RUC', 'doc_number' => '20587654321', 'email' => 'b2b@solviajes.test', 'phone' => '01 2233445', 'city' => 'Lima', 'segment_code' => 'b2b', 'tags' => ['B2B'], 'b2b_agency_id' => DemoSeeder::linkId('AGB-01')], ['name' => 'Ricardo Soto', 'role' => 'Gerente comercial', 'email' => 'b2b@solviajes.test']],
        ] as $row) {
            [$owner, $data, $contact] = $row + [2 => null];
            $c[] = $this->as('U01', fn () => $clients->create($data + ['owner_id' => $this->u[$owner]->id], $this->u['U01'], true, $contact));
        }

        // ── Oportunidades en distintas etapas ───────────────────────────
        $opps = app(OpportunityService::class);
        $o = [];
        foreach ([
            ['U03', 0, 'Luna de miel — Cusco y Machu Picchu', 'descubrimiento', 7800, 'PEN', 25, 'Cusco y Machu Picchu', 2],
            ['U04', 1, 'Viaje de incentivo — 40 colaboradores', 'cotizacion', 18500, 'USD', 40, 'Arequipa y Cañón del Colca', 40],
            ['U03', 2, 'Tour gastronómico Lima + Cusco', 'negociacion', 6200, 'USD', 12, 'Lima y Cusco', 2],
            ['U04', 3, 'Viaje de promoción 5to de secundaria', 'calificacion', 42000, 'PEN', 60, 'Cusco y Valle Sagrado', 45],
            ['U03', 4, 'Fin de semana en Paracas', 'cotizacion', 2400, 'PEN', 20, 'Paracas e Islas Ballestas', 2],
            ['U04', 5, 'Puno y Lago Titicaca', 'negociacion', 5600, 'PEN', 10, 'Lago Titicaca — Puno', 3],
        ] as [$owner, $ci, $title, $stage, $amount, $currency, $closeIn, $destination, $pax]) {
            $o[] = $this->as($owner, fn () => $opps->create([
                'client_id' => $c[$ci]->id, 'title' => $title, 'stage' => $stage, 'amount' => $amount, 'currency' => $currency,
                'expected_close_date' => $today->copy()->addDays($closeIn)->toDateString(), 'destination_label' => $destination, 'pax' => $pax,
            ], $this->u[$owner]));
        }
        $this->as('U03', fn () => $opps->changeStage($o[2], 'ganada', $this->u['U03']));
        $this->as('U04', fn () => $opps->changeStage($o[5], 'perdida', $this->u['U04'], 'precio'));

        // ── Leads: cola, propios de cada asesor y un convertido ─────────
        $leads = app(LeadService::class);
        $mk = fn (?string $owner, array $data) => $this->as($owner ?? 'U02', fn () => $leads->create($data + [
            'owner_id' => $owner ? $this->u[$owner]->id : null,
        ], $this->u[$owner ?? 'U02'], 'create_new')['lead']);
        $travel = fn (int $days) => $today->copy()->addDays($days)->toDateString();

        $queue1 = $leads->create(['full_name' => 'Valeria Campos', 'email' => 'valeria.campos@correo.test', 'phone' => '987 111 222', 'channel_code' => 'web', 'source_code' => 'formulario_web', 'interest_code' => 'paquete', 'destination_label' => 'Cusco', 'travel_date' => $travel(45), 'pax' => 2], null)['lead'];
        $queue2 = $leads->create(['full_name' => 'Jorge Pacheco', 'phone' => '+51 912 333 444', 'channel_code' => 'whatsapp', 'source_code' => 'whatsapp', 'interest_code' => 'tour', 'destination_label' => 'Ica', 'pax' => 4], null)['lead'];
        $carla1 = $mk('U03', ['full_name' => 'Fiorella Ríos', 'email' => 'fiorella.rios@correo.test', 'phone' => '934 555 666', 'channel_code' => 'instagram', 'source_code' => 'instagram', 'interest_code' => 'luna_miel', 'destination_label' => 'Machu Picchu', 'travel_date' => $travel(90), 'pax' => 2]);
        $carla2 = $mk('U03', ['full_name' => 'Andrés Vega', 'company' => 'Tech Andina S.A.C.', 'email' => 'avega@techandina.test', 'channel_code' => 'email', 'source_code' => 'referido', 'interest_code' => 'corporativo', 'destination_label' => 'Arequipa', 'travel_date' => $travel(60), 'pax' => 15]);
        $diego1 = $mk('U04', ['full_name' => 'Camila Torres', 'email' => 'camila.torres@correo.test', 'phone' => '921 777 888', 'channel_code' => 'facebook', 'source_code' => 'facebook_ads', 'interest_code' => 'paquete', 'destination_label' => 'Puno', 'travel_date' => $travel(30), 'pax' => 3]);
        $diego2 = $mk('U04', ['full_name' => 'Ernesto Salas', 'phone' => '998 444 333', 'channel_code' => 'telefono', 'source_code' => 'llamada_entrante', 'interest_code' => 'excursion', 'destination_label' => 'Paracas', 'pax' => 6]);

        // Carla: primer contacto hecho y BANT calificado; Andrés convertido en cliente + oportunidad.
        $first = Activity::where('related_type', 'lead')->where('related_id', $carla1->id)->first()
            ?? $this->as('U03', fn () => app(ActivityService::class)->schedule('llamada', 'Primer contacto — Fiorella Ríos', $this->u['U03']->id, $clock->now()->subHour(), 'lead', $carla1->id, $this->u['U03']));
        $this->as('U03', fn () => app(ActivityService::class)->complete($first, 'Interesada en paquete de 5 días, pide cotización con hotel boutique', [
            'subject' => 'Enviar propuesta a Fiorella', 'due_at' => $clock->now()->addDays(2)->setTime(10, 0)->toIso8601String(),
        ], $this->u['U03']));
        $this->as('U03', fn () => $leads->qualify($carla1->refresh(), ['budget' => 18, 'authority' => 25, 'need' => 18, 'timeline' => 10, 'mark_qualified' => true], $this->u['U03']));

        $this->as('U03', fn () => $leads->changeStatus($carla2, Lead::CONTACTED, 'Llamada inicial', $this->u['U03']));
        $this->as('U03', fn () => $leads->qualify($carla2->refresh(), ['budget' => 25, 'authority' => 25, 'need' => 18, 'timeline' => 18, 'declared_amount' => 30000, 'currency' => 'PEN', 'mark_qualified' => true], $this->u['U03']));
        $this->as('U03', fn () => app(LeadConverter::class)->convert($carla2->refresh(), [
            'client_mode' => 'new',
            'client' => ['kind' => 'empresa', 'name' => 'Tech Andina S.A.C.', 'doc_type' => 'RUC', 'doc_number' => '20601112223', 'city' => 'Lima'],
            'opportunity' => ['title' => 'Viaje corporativo Arequipa — Tech Andina', 'stage' => 'descubrimiento', 'amount' => 28000, 'currency' => 'PEN', 'expected_close_date' => $travel(30)],
            'schedule_discovery_meeting' => true,
        ], $this->u['U03']));

        // Diego: un lead descartado.
        $this->as('U04', fn () => $leads->discard($diego2, 'sin_interes', 'Solo consultaba precios', $this->u['U04']));

        // ── Actividades sueltas (vencida, hoy, próximas) ───────────────
        $acts = app(ActivityService::class);
        $this->as('U03', fn () => $acts->schedule('llamada', 'Confirmar fechas con Ana Gutiérrez', $this->u['U03']->id, $clock->now()->subDay()->setTime(16, 0), 'opportunity', $o[0]->id, $this->u['U03']));
        $this->as('U03', fn () => $acts->schedule('whatsapp', 'Enviar itinerario Paracas', $this->u['U03']->id, $clock->now()->setTime(18, 0), 'opportunity', $o[4]->id, $this->u['U03']));
        $this->as('U04', fn () => $acts->schedule('reunion', 'Presentación propuesta Minera del Sur', $this->u['U04']->id, $clock->tomorrowAt(11), 'opportunity', $o[1]->id, $this->u['U04']));
        $this->as('U02', fn () => $acts->schedule('tarea', 'Confirmar cupos de tren para grupo San Agustín', $this->u['U05']->id, $clock->tomorrowAt(9, 3), 'client', $c[3]->id, $this->u['U02']));

        // ── Postventa: encuestas (una detractora genera caso) ──────────
        $after = app(AfterSalesService::class);
        $this->as('U03', fn () => $after->recordSurvey(['client_id' => $c[0]->id, 'channel_code' => 'whatsapp', 'score' => 9, 'comment' => 'Excelente guía en Cusco'], $this->u['U03']));
        $this->as('U03', fn () => $after->recordSurvey(['client_id' => $c[2]->id, 'channel_code' => 'email', 'score' => 10], $this->u['U03']));
        $this->as('U04', fn () => $after->recordSurvey(['client_id' => $c[5]->id, 'channel_code' => 'llamada', 'score' => 4, 'comment' => 'El traslado al aeropuerto llegó 40 minutos tarde'], $this->u['U04']));
        $this->as('U02', fn () => $after->createCase(['client_id' => $c[1]->id, 'type_code' => 'sugerencia', 'priority' => 'baja', 'subject' => 'Incluir seguro de viaje por defecto', 'description' => 'El cliente sugiere ofrecer el seguro dentro del paquete corporativo', 'owner_id' => $this->u['U04']->id], $this->u['U02']));

        Auth::forgetUser();
    }

    /** Ejecuta como el usuario dado (autoría created_by/updated_by y actor de auditoría). */
    private function as(string $code, callable $fn): mixed
    {
        Auth::setUser($this->u[$code]);

        return $fn();
    }
}
