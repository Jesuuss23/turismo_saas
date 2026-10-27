<?php

namespace Database\Seeders;

use App\Models\Bookings\Reservation;
use App\Models\Catalog\Provider;
use App\Models\Catalog\SupplierService;
use App\Models\Collab\ApprovalRequest;
use App\Models\Collab\Conversation;
use App\Models\Finance\Payment;
use App\Models\Iam\User;
use App\Models\Quotes\Quote;
use App\Services\Bookings\CancellationService;
use App\Services\Collab\ApprovalDecisions;
use App\Services\Collab\InboundProcessor;
use App\Services\Collab\InboxService;
use App\Services\Collab\OriginSync;
use App\Services\Quotes\QuoteWorkflow;
use App\Services\Supplier\RateRequests;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Demo de Colaboración (spec 10 §11 «Datos semilla») para “andes”: solicitudes de cotización (8 % y margen), anulación,
 * reversión de un abono duplicado, tarifa de proveedor, 4 genéricas (2 históricas), CxP y comisiones pendientes, y 7 conversaciones
 * más el ticket del portal, coherentes con los clientes, reservas e incidencias de la demo.
 */
class CollabDemoSeeder extends Seeder
{
    public function run(): void
    {
        $u = fn (string $code) => User::where('code', $code)->firstOrFail();
        [$admin, $manager, $carla, $diego, $rosa, $elena] = [$u('U01'), $u('U02'), $u('U03'), $u('U04'), $u('U05'), $u('U07')];
        $decisions = app(ApprovalDecisions::class);

        // Excepciones que la demo cargó antes del módulo (aprobación pendiente de COT-00193, CxP y comisiones por aprobar).
        app(OriginSync::class)->backfill();

        // Cotización v2 con 8 % de descuento: la solicita el asesor dueño.
        $quote = Quote::where('code', 'COT-2026-00197')->first();
        if ($quote) {
            $this->as($diego, fn () => app(QuoteWorkflow::class)->requestApproval($quote, 'Cliente corporativo recurrente: 8 % por volumen anual (3 grupos en 2026)', $diego));
        }

        // Anulación solicitada por la asesora (PROC-028): la clienta consolida el viaje en otra cotización.
        $toCancel = Reservation::where('code', 'RES-2026-000360')->first();
        if ($toCancel) {
            $this->as($carla, fn () => app(CancellationService::class)->request($toCancel, 'Cambio de planes', 'La clienta consolida el viaje en la cotización COT-2026-00198', $carla));
        }

        // Reversión de un abono en soles registrado dos veces (el Yape si la demo lo tiene; si no, el primer pago contado).
        $counted = fn () => Payment::where('kind', 'cobro')->whereIn('status', Payment::COUNTED)->where('currency', 'PEN')->orderBy('code');
        if ($payment = $counted()->where('method', 'Yape')->first() ?? $counted()->first()) {
            $this->as($elena, fn () => $decisions->requestReversal($payment, 'Abono duplicado: el extracto BNK-2 muestra un solo depósito', $elena));
        }

        // Tarifas del Hotel Inti Wasi (extranet, ≥ 30 días): una pendiente por servicio (SUP-BR-61); hoy tiene un solo servicio y la
        // SOL-TAR-0101 rechazada ya está en su historial.
        $provider = Provider::where('ref', 'PRV-001')->first();
        $providerUser = User::where('code', 'U17')->first();
        if ($provider && $providerUser) {
            $services = SupplierService::where('provider_id', $provider->id)->orderBy('ref')->limit(2)->get();
            foreach ($services as $i => $service) {
                $this->as($providerUser, fn () => app(RateRequests::class)->create($provider, [
                    'service_id' => $service->id, 'proposed_cost' => round((float) $service->cost * ($i === 0 ? 1.08 : 1.15), 2),
                    'effective_from' => now()->addDays(35 + 10 * $i)->toDateString(), 'reason_type' => $i === 0 ? 'Cambio de temporada' : 'Ajuste por inflación / costos',
                    'reason_detail' => $i === 0 ? 'Temporada alta (julio–agosto).' : 'Incremento de insumos y planilla 2027.',
                ], $providerUser));
            }
        }

        // Genéricas: crédito B2B de dos niveles y reembolso pendientes; una «otra» aprobada y un reembolso rechazado (historial).
        $this->as($carla, fn () => $decisions->createGeneric(['type' => 'credit_b2b', 'agency_code' => 'AGB-01', 'amount' => 6000, 'currency' => 'PEN',
            'title' => 'Ampliación de línea para temporada alta', 'justification' => 'Sol Viajes confirmó 3 grupos de julio; su consumo proyectado supera la línea actual.'], $carla));
        $this->as($carla, fn () => $decisions->createGeneric(['type' => 'refund', 'reservation_code' => 'RES-2026-000357', 'amount' => 60, 'currency' => 'PEN',
            'title' => 'Reembolso parcial por traslado no prestado', 'justification' => 'El traslado al aeropuerto no se realizó por cambio de vuelo de la clienta.'], $carla));
        $other = $this->as($diego, fn () => $decisions->createGeneric(['type' => 'other', 'amount' => 0, 'currency' => 'PEN',
            'title' => 'Cortesía: upgrade de habitación para Colegio San Agustín', 'justification' => 'Compensación por el cambio de hotel de la promoción anterior.'], $diego));
        $this->as($manager, fn () => $decisions->approve($other, 'Aprobado como cortesía comercial', $manager, $this->http($other)));
        $refund = $this->as($diego, fn () => $decisions->createGeneric(['type' => 'refund', 'reservation_code' => 'RES-2026-000351', 'amount' => 80, 'currency' => 'PEN',
            'title' => 'Reembolso por desistimiento', 'justification' => 'El cliente desiste de la excursión opcional.'], $diego));
        $this->as($manager, fn () => $decisions->reject($refund, 'Fuera de política: el desistimiento fue a menos de 48 h', $manager, $this->http($refund)));
        DB::table('collab_approval_requests')->whereIn('id', [$other->id, $refund->id])->update(['created_at' => now()->subDays(4), 'decided_at' => now()->subDays(3)]);

        $this->conversations($carla, $diego, $rosa);
    }

    /** 7 conversaciones por los 4 canales externos (el ticket TCK-0001 ya se reflejó como conversación del portal). */
    private function conversations(User $carla, User $diego, User $rosa): void
    {
        $in = app(InboundProcessor::class);
        $inbox = app(InboxService::class);
        $code = fn (array $r) => Conversation::where('code', $r['conversation'])->firstOrFail();
        $miller = Reservation::where('code', 'RES-2026-000354')->first();

        // 1. Pasajero en viaje (John Miller, en operación): visible para Operaciones.
        $c1 = $code($in->process('whatsapp', ['external_message_id' => 'wamid.demo-001', 'thread_id' => '14155550134', 'name' => 'John Miller', 'phone' => '+14155550134',
            'body' => 'Hi! We heard there is a strike in Ollantaytambo. Is our train tomorrow still running?', 'reservation_id' => $miller?->id, 'at' => now()->subMinutes(25)]));
        $c1->forceFill(['priority' => 'alta', 'tags' => ['en_viaje', 'huelga']])->save();
        $this->as($rosa, fn () => $inbox->assign($c1, $rosa->id, $rosa));
        $this->as($rosa, fn () => $inbox->note($c1, 'Confirmar con PeruRail antes de responder; hay 2 grupos más en la misma salida.', $rosa));

        // 2. Chat web nuevo sin asignar (lead potencial, SLA 15 min).
        $in->process('webchat', ['external_message_id' => 'web-demo-002', 'thread_id' => 'web-sess-8812', 'name' => 'Pedro Ruiz', 'email' => 'pedro.ruiz@correo.test',
            'body' => 'Hola, ¿cuánto cuesta el Camino Inca de 4 días para 2 personas en mayo?', 'at' => now()->subMinutes(6)]);

        // 3. Email de un cliente (Ana Gutiérrez) ya respondido: espera al cliente.
        $c3 = $code($in->process('email', ['external_message_id' => '<demo-003@correo.test>', 'thread_id' => '<demo-003@correo.test>', 'name' => 'Ana Gutiérrez',
            'email' => 'cliente@correo.test', 'subject' => 'Factura de mi reserva', 'body' => 'Buenas tardes, ¿me pueden enviar la factura a nombre de mi empresa?', 'at' => now()->subHours(3)]));
        $this->as($carla, fn () => $inbox->reply($c3, ['body' => 'Hola Ana, claro: envíanos el RUC y la razón social y la emitimos hoy.'], $carla));

        // 4. Formulario de contacto sin asignar (grupo escolar).
        $in->process('form', ['external_message_id' => 'form-demo-004', 'name' => 'Prof. Laura Medina', 'email' => 'lmedina@colegio.test', 'phone' => '+51 999 111 222',
            'subject' => 'Viaje de promoción 2027', 'body' => 'Somos 38 alumnos y 4 docentes. Queremos Cusco y Machu Picchu en octubre.', 'at' => now()->subHour()]);

        // 5. WhatsApp fuera de la ventana de 24 h (solo plantillas aprobadas).
        $c5 = $code($in->process('whatsapp', ['external_message_id' => 'wamid.demo-005', 'thread_id' => '51984222731', 'name' => 'Valeria Campos', 'phone' => '+51 984 222 731',
            'body' => 'Gracias, lo converso con mi esposo y les aviso.', 'at' => now()->subHours(30)]));
        $this->as($carla, fn () => $inbox->assign($c5, $carla->id, $carla));

        // 6. Email resuelto (cliente corporativo).
        $c6 = $code($in->process('email', ['external_message_id' => '<demo-006@minera.test>', 'thread_id' => '<demo-006@minera.test>', 'name' => 'Compras Minera del Sur',
            'email' => 'compras@minera.test', 'subject' => 'Constancia de reserva', 'body' => 'Requerimos la constancia de la reserva para el área de compras.', 'at' => now()->subDays(2)]));
        $this->as($diego, fn () => $inbox->reply($c6, ['body' => 'Adjuntamos la constancia solicitada. Quedamos atentos.', 'resolve' => true], $diego));

        // 7. Chat web de María Fernández asignado a Diego, esperando al cliente.
        $c7 = $code($in->process('webchat', ['external_message_id' => 'web-demo-007', 'thread_id' => 'web-sess-9001', 'name' => 'María Fernández',
            'email' => 'maria.fernandez@correo.test', 'body' => '¿Puedo pagar el saldo en dos partes?', 'at' => now()->subMinutes(50)]));
        $this->as($diego, fn () => $inbox->reply($c7, ['body' => 'Sí María, puedes pagar 50 % ahora y 50 % 15 días antes del viaje.'], $diego));

        // Horarios coherentes con la demo: la respuesta llega minutos después del mensaje entrante.
        foreach ([$c3, $c6, $c7] as $c) {
            $first = DB::table('collab_messages')->where('conversation_id', $c->id)->where('direction', 'in')->min('sent_at');
            $at = Carbon::parse($first)->addMinutes(9);
            DB::table('collab_messages')->where('conversation_id', $c->id)->whereIn('direction', ['out', 'system'])->update(['sent_at' => $at]);
            DB::table('collab_conversations')->where('id', $c->id)->update(['created_at' => $first, 'first_response_at' => $at, 'last_message_at' => $at,
                'resolved_at' => DB::raw("CASE WHEN status = 'resuelta' THEN '{$at->toDateTimeString()}'::timestamptz ELSE NULL END")]);
        }
        foreach (Conversation::whereNotIn('id', [$c3->id, $c6->id, $c7->id])->get() as $c) {
            DB::table('collab_conversations')->where('id', $c->id)->update(['created_at' => DB::table('collab_messages')->where('conversation_id', $c->id)->min('sent_at')]);
        }
    }

    /** Las acciones de la demo se registran a nombre de quien las hace. */
    private function as(User $user, callable $fn): mixed
    {
        Auth::setUser($user);
        try {
            return $fn();
        } finally {
            Auth::forgetUser();
        }
    }

    private function http(ApprovalRequest $r): Request
    {
        return Request::create('/', 'POST', server: ['HTTP_IF_MATCH' => (string) $r->fresh()->lock_version]);
    }
}
