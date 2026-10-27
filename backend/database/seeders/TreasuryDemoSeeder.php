<?php

namespace Database\Seeders;

use App\Models\Crm\AfterSalesCase;
use App\Models\Finance\BankAccount;
use App\Models\Iam\Sede;
use App\Models\Iam\User;
use App\Models\Treasury\Account;
use App\Models\Treasury\CashCount;
use App\Models\Treasury\Movement;
use App\Services\Crm\BusinessDayCalculator;
use App\Services\Crm\ClaimBookService;
use App\Services\Treasury\FxValuationService;
use App\Services\Treasury\PettyCashService;
use App\Services\Treasury\TreasuryLedger;
use App\Services\Treasury\TreasuryService;
use App\Services\Treasury\TreasurySync;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Demo de tesorería y libro de reclamaciones (spec 12) para “andes”. La demo tiene sedes en Lima y Cusco (no Arequipa): las cajas
 * chicas son Caja Lima (custodia Elena, U07) y Caja Cusco (custodia Rosa, U05). Los cobros y pagos ya cargados se reflejan con la
 * sincronización; el arqueo ARQ-0040 de Caja Lima queda con diferencia por aprobar (TES-E2E-02) y Caja Cusco abierta y cuadrada (TES-E2E-01).
 */
class TreasuryDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = fn (string $code) => User::where('code', $code)->firstOrFail();
        [$admin, $manager, $rosa, $elena, $pedro] = [$user('U01'), $user('U02'), $user('U05'), $user('U07'), $user('U08')];
        Auth::setUser($elena);
        try {
            $accounts = $this->accounts($admin, $rosa, $elena);
            app(TreasurySync::class)->run();
            $this->bankActivity($accounts, $elena);
            $this->pettyCash($accounts, $manager, $rosa, $elena, $pedro);
            $this->fx($accounts, $pedro);
            $this->claims($manager);
        } finally {
            Auth::forgetUser();
        }
    }

    /** @return array<string, Account> */
    private function accounts(User $admin, User $rosa, User $elena): array
    {
        $service = app(TreasuryService::class);
        $lima = Sede::where('code', 'S01')->firstOrFail();
        $cusco = Sede::where('code', 'S02')->firstOrFail();
        $opening = app(TreasuryLedger::class)->today()->subDays(90)->toDateString();
        $bank = fn (string $bank, string $currency) => BankAccount::where('bank', $bank)->where('currency', $currency)->value('id');
        $a = [];
        $a['bcp_pen'] = $service->createAccount(['kind' => 'bank', 'bank' => 'BCP', 'name' => 'BCP Cta. Cte. Soles', 'account_number' => '193-2245871-0-45',
            'cci' => '00219300224587104512', 'currency' => 'PEN', 'sede_id' => $lima->id, 'opening_balance' => 86500, 'opening_date' => $opening,
            'collect_methods' => ['transferencia_bcp', 'yape', 'plin'], 'bank_account_id' => $bank('BCP', 'PEN')], $admin);
        $a['bcp_usd'] = $service->createAccount(['kind' => 'bank', 'bank' => 'BCP', 'name' => 'BCP Cta. Cte. Dólares', 'account_number' => '193-2245903-1-12',
            'cci' => '00219300224590311218', 'currency' => 'USD', 'sede_id' => $lima->id, 'opening_balance' => 24800, 'opening_rate' => 3.70, 'opening_date' => $opening,
            'collect_methods' => ['transferencia_bcp'], 'bank_account_id' => $bank('BCP', 'USD')], $admin);
        $a['ibk_pen'] = $service->createAccount(['kind' => 'bank', 'bank' => 'Interbank', 'name' => 'Interbank Cta. Cte. Soles', 'account_number' => '200-3001456789',
            'currency' => 'PEN', 'sede_id' => $lima->id, 'opening_balance' => 18200, 'opening_date' => $opening,
            'collect_methods' => ['transferencia_interbank'], 'bank_account_id' => $bank('Interbank', 'PEN')], $admin);
        $a['caja_lima'] = $service->createAccount(['kind' => 'petty_cash', 'name' => 'Caja chica Lima', 'currency' => 'PEN', 'sede_id' => $lima->id,
            'fund_amount' => 2000, 'custodian_id' => $elena->id, 'opening_balance' => 2000, 'opening_date' => $opening, 'collect_methods' => ['efectivo']], $admin);
        $a['caja_cusco'] = $service->createAccount(['kind' => 'petty_cash', 'name' => 'Caja chica Cusco', 'currency' => 'PEN', 'sede_id' => $cusco->id,
            'fund_amount' => 1500, 'custodian_id' => $rosa->id, 'opening_balance' => 1500, 'opening_date' => $opening], $admin);
        $a['niubiz'] = $service->createAccount(['kind' => 'gateway', 'name' => 'Pasarela Niubiz', 'currency' => 'PEN', 'sede_id' => $lima->id, 'opening_date' => $opening,
            'collect_methods' => ['tarjeta', 'tarjeta_ecommerce', 'tarjeta_portal'], 'settle_to_account_id' => $a['bcp_pen']->id], $admin);

        return $a;
    }

    /** Transferencias, cambio de moneda, comisiones bancarias, intereses y una liquidación de la pasarela. */
    private function bankActivity(array $a, User $elena): void
    {
        $service = app(TreasuryService::class);
        $day = fn (int $ago) => app(TreasuryLedger::class)->today()->subDays($ago)->toDateString();
        $service->transfer(['from_account_id' => $a['bcp_pen']->id, 'to_account_id' => $a['ibk_pen']->id, 'amount_from' => 12000, 'transfer_date' => $day(40),
            'reference' => 'OP-778120', 'description' => 'Fondeo de pagos a proveedores'], $elena);
        $service->transfer(['from_account_id' => $a['bcp_usd']->id, 'to_account_id' => $a['bcp_pen']->id, 'amount_from' => 3000, 'deal_rate' => 3.752,
            'transfer_date' => $day(21), 'reference' => 'CV-20931', 'description' => 'Compra de soles para planilla'], $elena);
        foreach ([[30, 'bcp_pen', 'out', 48.50, 'Comisión de mantenimiento', 'Gastos bancarios'], [30, 'bcp_usd', 'out', 12.00, 'Comisión por transferencia al exterior', 'Gastos bancarios'],
            [15, 'ibk_pen', 'in', 36.80, 'Intereses de la cuenta', 'Intereses'], [3, 'bcp_pen', 'out', 1850, 'Alquiler de oficina Cusco', 'Alquileres']] as [$ago, $acc, $dir, $amount, $desc, $cat]) {
            $service->manual(['account_id' => $a[$acc]->id, 'direction' => $dir, 'amount' => $amount, 'movement_date' => $day($ago), 'description' => $desc, 'category' => $cat,
                'fx_rate' => $a[$acc]->currency === 'USD' ? 3.75 : null], $elena);
        }
        $card = Movement::where('account_id', $a['niubiz']->id)->where('direction', 'in')->where('status', 'posted')->orderBy('movement_date')->first();
        if ($card) {
            $service->settleGateway($a['niubiz'], ['gross_amount' => $card->amount, 'batch' => 'LOTE-0912', 'settlement_date' => $day(2)], $elena);
        }
        app(TreasuryLedger::class)->settings()->forceFill(['recurring_outflows' => [
            ['concept' => 'Planilla', 'day' => 28, 'amount' => 38500], ['concept' => 'Alquiler de oficinas', 'day' => 5, 'amount' => 6200],
            ['concept' => 'Servicios y software', 'day' => 12, 'amount' => 1450],
        ]])->save();
    }

    private function pettyCash(array $a, User $manager, User $rosa, User $elena, User $pedro): void
    {
        $petty = app(PettyCashService::class);
        $lima = $a['caja_lima'];
        $cusco = $a['caja_cusco'];
        $balance = fn (Account $acc) => app(TreasuryLedger::class)->balance($acc);
        $bills = fn (float $amount) => $this->bills($amount);
        // El historial de arqueos empieza en ARQ-0038 y el de reposiciones en REP-0007.
        DB::table('sys_sequences')->where('doc_type', 'cash_count')->update(['next_number' => 38]);
        DB::table('sys_sequences')->where('doc_type', 'cash_replenishment')->update(['next_number' => 7]);

        $c = $petty->count($cusco, ['kind' => 'surprise', 'denominations' => $bills($balance($cusco)), 'witness_id' => $elena->id], $rosa, '190.12.40.8');
        CashCount::whereKey($c->id)->update(['counted_at' => now()->subDays(9), 'signed_at' => now()->subDays(9)]);

        // Caja Lima: gastos rendidos y pagados (REP-0007), otro rechazado (REP-0008) y nuevos gastos pendientes.
        $petty->open($lima, ['opening_amount' => $balance($lima)], $elena);
        foreach ([['mobility_sheet', null, null, 'Taxi aeropuerto – oficina', 'Traslado de documentos de grupo Keller', 45.00, 'Movilidad'],
            ['ticket', 'B201-004512', null, 'Bodega Santa Cruz', 'Agua y snacks para pasajeros', 62.30, 'Atención a pasajeros'],
            ['invoice', 'F001-008812', '20512345678', 'Tai Loy S.A.', 'Útiles de oficina', 118.90, 'Útiles']] as [$type, $series, $ruc, $supplier, $desc, $amount, $cat]) {
            $petty->expense($lima, ['receipt_type' => $type, 'series_number' => $series, 'issuer_ruc' => $ruc, 'supplier_name' => $supplier, 'description' => $desc,
                'amount' => $amount, 'category' => $cat], $elena);
        }
        $rep = $petty->request($lima, 'Rendición semanal', $elena);
        $petty->resolve($rep, true, null, $manager);
        $petty->pay($rep, ['from_account_id' => $a['bcp_pen']->id, 'reference' => 'OP-781455'], $elena);
        $petty->expense($lima, ['receipt_type' => 'sworn_statement', 'supplier_name' => 'Mercado de Surquillo', 'description' => 'Flores para recepción de grupo', 'amount' => 80, 'category' => 'Atención a pasajeros'], $elena);
        $rejected = $petty->request($lima, null, $elena);
        $petty->resolve($rejected, false, 'Falta el sustento de la declaración jurada: adjunte foto del puesto.', $manager);
        $petty->expense($lima, ['receipt_type' => 'ticket', 'series_number' => 'B004-120931', 'supplier_name' => 'Tambo+', 'description' => 'Pilas para radios de guías', 'amount' => 34.50, 'category' => 'Operaciones'], $elena);
        $c = $petty->count($lima, ['kind' => 'surprise', 'denominations' => $bills($balance($lima)), 'witness_id' => $pedro->id], $elena, '190.12.40.21');
        CashCount::whereKey($c->id)->update(['counted_at' => now()->subDays(3), 'signed_at' => now()->subDays(3)]);
        $petty->validateCount($c, $pedro);
        // ARQ-0040: faltan S/ 25 (tolerancia S/ 10) → pendiente de aprobación por Manager o Finanzas distinto del firmante.
        $petty->count($lima, ['kind' => 'surprise', 'denominations' => $bills($balance($lima) - 25), 'witness_id' => $pedro->id,
            'note' => 'Faltan S/ 25: posible vuelto no registrado de la compra de pilas.'], $elena, '190.12.40.21');

        // Caja Cusco: abierta hoy y cuadrada; Elena registra el gasto, la cierra y pide la reposición (TES-E2E-01).
        $petty->open($cusco, ['opening_amount' => $balance($cusco)], $rosa);
    }

    /** Billetes y monedas que suman el monto (para el acta de arqueo). */
    private function bills(float $amount): array
    {
        $out = [];
        $cents = (int) round($amount * 100);
        foreach (CashCount::DENOMINATIONS as $value) {
            $unit = (int) round((float) $value * 100);
            if ($cents >= $unit) {
                $out[$value] = intdiv($cents, $unit);
                $cents %= $unit;
            }
        }

        return $out;
    }

    /** Revaluación del mes anterior; la del mes en curso la registra Contabilidad (TES-E2E-03). */
    private function fx(array $a, User $pedro): void
    {
        $period = app(TreasuryLedger::class)->today()->subMonthNoOverflow()->format('Y-m');
        app(FxValuationService::class)->register(['period' => $period, 'close_rate' => 3.738], $pedro);
    }

    /** Hojas del libro en distintos estados (LR-LIM y LR-CUS); fechas desplazadas para mostrar plazos vencidos y por vencer. */
    private function claims(User $manager): void
    {
        $service = app(ClaimBookService::class);
        $days = app(BusinessDayCalculator::class);
        $today = $service->today();
        $lima = Sede::where('code', 'S01')->value('id');
        $cusco = Sede::where('code', 'S02')->value('id');
        $consumer = fn (string $name, string $doc, string $email, string $address) => ['name' => $name, 'doc_type' => 'DNI', 'doc_number' => $doc, 'email' => $email,
            'address' => $address, 'phone' => '+51 987 654 321'];
        $sheets = [
            // [sede, tipo, canal, consumidor, bien, detalle, pedido, días hábiles atrás, acción]
            [$lima, 'reclamo', 'web', $consumer('Carlos Ríos Paz', '41258796', 'carlos.rios@correo.test', 'Jr. Huallaga 450, Lima'), 'Tour Lima colonial 22/09',
                'El guía llegó 50 minutos tarde al hotel y se acortó la visita a la Catedral; no se nos ofreció ninguna compensación.', 'Devolución parcial del tour.', 18, 'close'],
            [$cusco, 'queja', 'in_person', $consumer('María Fernanda Soto', '70145236', 'mfsoto@correo.test', 'Av. El Sol 120, Cusco'), 'Atención en oficina San Blas',
                'Esperé más de una hora en la oficina de San Blas para recoger mis vouchers sin que nadie me atendiera.', 'Mejorar la atención en oficina.', 12, 'answer'],
            [$lima, 'reclamo', 'customer_portal', $consumer('Ana Gutiérrez', '45879632', 'cliente@correo.test', 'Calle Los Pinos 230, Surco'), 'Paquete Cusco Mágico 4D/3N',
                'El hotel asignado en Cusco no correspondía a la categoría contratada (3* en lugar de 4*) durante dos noches.', 'Reembolso de la diferencia de categoría.', 16, 'review'],
            [$cusco, 'reclamo', 'web', $consumer('Jorge Valdivia', '29874563', 'jvaldivia@correo.test', 'Calle Mercaderes 302, Arequipa'), 'Traslado aeropuerto Cusco',
                'El traslado contratado al aeropuerto nunca llegó y tuve que tomar un taxi por mi cuenta, con riesgo de perder el vuelo.', 'Reembolso del traslado y del taxi.', 13, null],
            [$lima, 'queja', 'web', $consumer('Lucía Herrera', '73654128', 'lucia.herrera@correo.test', 'Av. Arequipa 3010, San Isidro'), 'Cotización por correo',
                'Solicité una cotización hace una semana y recién me respondieron con precios distintos a los publicados en la web.', 'Que se respeten los precios publicados.', 2, null],
        ];
        foreach ($sheets as [$sede, $type, $channel, $who, $good, $detail, $request, $ago, $action]) {
            $c = $service->register(['sede_id' => $sede, 'type' => $type, 'consumer' => $who, 'good' => ['kind' => 'Servicio', 'description' => $good, 'amount' => 320, 'currency' => 'PEN'],
                'detail' => $detail, 'request' => $request, 'accept_email' => true], $channel, $channel === 'in_person' ? $manager : null);
            $received = $this->businessDaysAgo($days, $today, $ago);
            $due = $days->addBusinessDays($received, $c->response_days);
            DB::table('crm_cases')->where('id', $c->id)->update(['received_at' => $received->copy()->setTime(10, 30), 'due_date' => $due->toDateString(),
                'created_at' => $received->copy()->setTime(10, 30), 'ack_sent_at' => $received->copy()->setTime(10, 31)]);
            $c = AfterSalesCase::find($c->id);
            if ($action === null) {
                continue;
            }
            $c = $service->review($c, null, $manager);
            if ($action === 'review') {
                continue;
            }
            $c = $service->respond($c, ['decision' => $type === 'reclamo' ? 'fundado_parcial' : 'fundado',
                'text' => 'Lamentamos lo ocurrido. Revisamos el servicio con el proveedor y confirmamos la demora; aplicaremos las medidas indicadas y le contactaremos para coordinar.',
                'actions' => $type === 'reclamo' ? 'Devolución del 30 % del servicio en 5 días hábiles.' : 'Capacitación al personal de la oficina San Blas.'], $manager);
            if ($action === 'close') {
                $service->close($c, 'Consumidor conforme con la devolución.', $manager);
            }
        }
    }

    private function businessDaysAgo(BusinessDayCalculator $days, Carbon $today, int $n): Carbon
    {
        $d = $today->copy();
        while ($n > 0) {
            $d->subDay();
            if ($days->isBusinessDay($d)) {
                $n--;
            }
        }

        return $d;
    }
}
