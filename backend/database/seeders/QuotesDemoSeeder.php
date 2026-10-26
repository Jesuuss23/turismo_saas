<?php

namespace Database\Seeders;

use App\Models\Catalog\Product;
use App\Models\Catalog\Rate;
use App\Models\Crm\Client;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Models\Quotes\Quote;
use App\Services\Crm\CrmClock;
use App\Services\Quotes\QuoteService;
use App\Services\Quotes\QuoteWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Cotizaciones de demostración del tenant "andes" (spec 03): una por estado relevante, creadas con
 * los mismos servicios que la API (auditoría, notificaciones y efecto en oportunidades incluidos).
 * Requiere CRM, catálogo y usuarios externos ya sembrados.
 */
class QuotesDemoSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $u = [];

    public function run(): void
    {
        $this->u = User::whereIn('code', ['U01', 'U02', 'U03', 'U04'])->get()->keyBy('code')->all();
        $today = app(CrmClock::class)->today();
        $quotes = app(QuoteService::class);
        $flow = app(QuoteWorkflow::class);
        $client = fn (string $code) => Client::where('code', $code)->firstOrFail();
        $opp = fn (string $title) => Opportunity::where('title', $title)->first();
        $product = fn (string $ref) => Product::where('ref', $ref)->firstOrFail();
        $rate = fn (string $ref, string $segment = 'Adulto', string $channel = 'Directo') => Rate::whereHas('product', fn ($q) => $q->where('ref', $ref))
            ->where('segment', $segment)->where('channel', $channel)->whereNull('season_id')->whereDate('valid_to', '>=', $today)->orderBy('ref')->first();
        $day = fn (int $n) => $today->copy()->addDays($n)->toDateString();
        $item = fn (string $ref, int $in, array $extra = []) => ['product_id' => $product($ref)->id, 'service_date' => $day($in)] + $extra;

        // 1 · Carla · Ana Gutiérrez: enviada y vista (el portal del cliente la puede aprobar).
        $q1 = $this->as('U03', fn () => $quotes->create([
            'client_id' => $client('CLI-0001')->id, 'opportunity_id' => $opp('Luna de miel — Cusco y Machu Picchu')?->id,
            'title' => 'Luna de miel Cusco 4D/3N', 'travel_from' => $day(40), 'travel_to' => $day(43), 'currency' => 'PEN',
            'pax_adt' => 2, 'pax_chd' => 0, 'pax_inf' => 0, 'discount_pct' => 0,
            'items' => [
                $item('PRD-002', 40, ['rate_id' => $rate('PRD-002', 'Pasajero')?->id]),
                $item('PRD-001', 41, ['rate_id' => $rate('PRD-001')?->id]),
                $item('PRD-009', 40, ['rate_id' => Rate::whereHas('product', fn ($q) => $q->where('ref', 'PRD-009'))->value('id')]),
            ],
            'notes' => 'Hotel boutique a cotizar aparte.',
        ], $this->u['U03']));
        $this->as('U03', fn () => $flow->send($q1, ['Email', 'Portal'], 'Hola Ana, te comparto la propuesta de su luna de miel.', $this->u['U03']));
        $this->as('U03', fn () => $flow->registerView($q1->fresh(), 'portal', null, '190.43.10.5', 'Mozilla/5.0'));

        // 2 · Diego · Minera del Sur: margen < 15 % y descuento 5 % → solicitud pendiente de gerencia (ej. COT-00191 §5.1).
        $q2 = $this->as('U04', fn () => $quotes->create([
            'client_id' => $client('CLI-0002')->id, 'opportunity_id' => $opp('Viaje de incentivo — 40 colaboradores')?->id,
            'title' => 'Incentivo corporativo Cusco — 60 colaboradores', 'travel_from' => $day(60), 'travel_to' => $day(63), 'currency' => 'PEN',
            'pax_adt' => 60, 'pax_chd' => 0, 'pax_inf' => 0, 'discount_pct' => 5,
            'items' => [
                $item('PRD-003', 60, ['qty' => 1, 'pax' => 60, 'unit_cost' => 2900, 'unit_price' => 3450]),
                $item('PRD-002', 61, ['qty' => 1, 'pax' => 60, 'unit_cost' => 110, 'unit_price' => 165]),
            ],
        ], $this->u['U04']));
        $this->as('U04', fn () => $flow->requestApproval($q2, 'Cliente corporativo recurrente; el volumen compensa el margen.', $this->u['U04']));

        // 3 · Carla · María Fernández: aprobada por el cliente (registrada por la asesora).
        $q3 = $this->as('U03', fn () => $quotes->create([
            'client_id' => $client('CLI-0005')->id, 'title' => 'Fin de semana Cusco', 'travel_from' => $day(25), 'travel_to' => $day(27), 'currency' => 'PEN',
            'pax_adt' => 2, 'pax_chd' => 1, 'pax_inf' => 0,
            'items' => [$item('PRD-002', 25, ['rate_id' => $rate('PRD-002', 'Pasajero')?->id]), $item('PRD-004', 26, ['rate_id' => $rate('PRD-004', 'Pasajero')?->id])],
        ], $this->u['U03']));
        $this->as('U03', fn () => $flow->send($q3, ['WhatsApp'], null, $this->u['U03']));
        $this->as('U03', fn () => $flow->approve($q3->fresh(), ['approved_by_name' => 'María Fernández', 'channel' => 'Mensaje de WhatsApp', 'ref' => 'Chat 26/10 10:42'], $this->u['U03']));

        // 4 · Carla · John Miller (no domiciliado, IGV 0 %): enviada en USD y vencida por JOB-02.
        $q4 = $this->as('U03', fn () => $quotes->create([
            'client_id' => $client('CLI-0003')->id, 'title' => 'Machu Picchu y Valle Sagrado', 'travel_from' => $day(15), 'travel_to' => $day(18), 'currency' => 'USD',
            'pax_adt' => 2, 'pax_chd' => 0, 'pax_inf' => 0,
            'items' => [$item('PRD-001', 16, ['rate_id' => $rate('PRD-001')?->id]), $item('PRD-003', 17, ['rate_id' => $rate('PRD-003')?->id])],
        ], $this->u['U03']));
        $this->as('U03', fn () => $flow->send($q4, ['Email'], 'Hi John, here is your quote.', $this->u['U03']));
        $q4->fresh()->forceFill(['valid_until' => $day(-2)])->save();
        $flow->expireDue();

        // 5 · Diego · Luis Ramírez: rechazada con motivo.
        $q5 = $this->as('U04', fn () => $quotes->create([
            'client_id' => $client('CLI-0006')->id, 'title' => 'Puno y Lago Titicaca', 'travel_from' => $day(30), 'travel_to' => $day(31), 'currency' => 'PEN',
            'pax_adt' => 3, 'pax_chd' => 0, 'pax_inf' => 0,
            'items' => [$item('PRD-004', 30, ['rate_id' => $rate('PRD-004', 'Pasajero')?->id])],
        ], $this->u['U04']));
        $this->as('U04', fn () => $flow->send($q5, ['Email'], null, $this->u['U04']));
        $this->as('U04', fn () => $flow->reject($q5->fresh(), 'Eligió otra agencia por precio', $this->u['U04']));

        // 6 · Diego · Colegio San Agustín: descuento 8 % aprobado por gerencia, enviada y en negociación (v2 borrador).
        $q6 = $this->as('U04', fn () => $quotes->create([
            'client_id' => $client('CLI-0004')->id, 'opportunity_id' => $opp('Viaje de promoción 5to de secundaria')?->id,
            'title' => 'Viaje de promoción Cusco 5D/4N', 'travel_from' => $day(90), 'travel_to' => $day(94), 'currency' => 'PEN',
            'pax_adt' => 5, 'pax_chd' => 40, 'pax_inf' => 0, 'discount_pct' => 8,
            'items' => [
                $item('PRD-002', 90, ['rate_id' => $rate('PRD-002', 'Pasajero')?->id]),
                $item('PRD-004', 92, ['rate_id' => Rate::whereHas('product', fn ($q) => $q->where('ref', 'PRD-004'))->where('segment', 'Grupo')->value('id'), 'segment' => 'Grupo']),
            ],
        ], $this->u['U04']));
        $this->as('U04', fn () => $flow->requestApproval($q6, 'Grupo escolar de 45 pax; descuento de promoción.', $this->u['U04']));
        $this->as('U02', fn () => $flow->decideApproval($q6->fresh(), $q6->fresh()->currentVersion, 'approve', 'Aprobado por volumen del grupo.', $this->u['U02']));
        $this->as('U04', fn () => $flow->send($q6->fresh(), ['Email', 'WhatsApp'], 'Propuesta para la promoción 2026.', $this->u['U04']));
        $this->as('U04', fn () => $flow->negotiate($q6->fresh(), 'Reunión', 'Piden incluir seguro de viaje y bajar 5 % adicional.', true, $this->u['U04']));

        // 7 · Carla · Tech Andina: borrador dentro de política.
        $this->as('U03', fn () => $quotes->create([
            'client_id' => Client::where('name', 'Tech Andina S.A.C.')->value('id'), 'opportunity_id' => $opp('Viaje corporativo Arequipa — Tech Andina')?->id,
            'title' => 'Viaje corporativo Cusco — 15 pax', 'travel_from' => $day(55), 'travel_to' => $day(57), 'currency' => 'PEN',
            'pax_adt' => 15, 'pax_chd' => 0, 'pax_inf' => 0,
            'items' => [$item('PRD-002', 55, ['rate_id' => $rate('PRD-002', 'Pasajero')?->id])],
        ], $this->u['U03']));

        Auth::forgetUser();
        $this->command?->info('Cotizaciones demo: '.Quote::count());
    }

    private function as(string $code, callable $fn): mixed
    {
        Auth::setUser($this->u[$code]);

        return $fn();
    }
}
