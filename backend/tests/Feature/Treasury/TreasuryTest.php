<?php

use App\Models\Iam\Sede;
use App\Models\Tenant;
use App\Services\Treasury\TreasuryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->financeWorld();
    $this->tes = $this->inTenant(function () {
        $service = app(TreasuryService::class);
        $sede = Sede::value('id');
        $opening = now()->subDays(30)->toDateString();

        return [
            'pen' => $service->createAccount(['kind' => 'bank', 'bank' => 'BCP', 'name' => 'BCP Soles', 'account_number' => '193-1', 'currency' => 'PEN', 'sede_id' => $sede,
                'opening_balance' => 5000, 'opening_date' => $opening, 'collect_methods' => ['transferencia_bcp', 'yape'], 'bank_account_id' => $this->accounts['PEN']->id], $this->users['admin']),
            'usd' => $service->createAccount(['kind' => 'bank', 'bank' => 'BCP', 'name' => 'BCP Dólares', 'account_number' => '193-2', 'currency' => 'USD', 'sede_id' => $sede,
                'opening_balance' => 1000, 'opening_rate' => 3.70, 'opening_date' => $opening, 'collect_methods' => ['transferencia_bcp'], 'bank_account_id' => $this->accounts['USD']->id], $this->users['admin']),
            'caja' => $service->createAccount(['kind' => 'petty_cash', 'name' => 'Caja Lima', 'currency' => 'PEN', 'sede_id' => $sede, 'fund_amount' => 1000,
                'custodian_id' => $this->users['finance']->id, 'opening_balance' => 1000, 'opening_date' => $opening], $this->users['admin']),
        ];
    });
});

function tes(object $t, string $who, string $uri, array $data = [], array $headers = []): TestResponse
{
    return $t->qPost($who, 'treasury/'.ltrim($uri, '/'), $data, $headers + $t->idem());
}

function tesBalance(object $t, string $key): float
{
    return (float) collect($t->qGet('finance', 'treasury/accounts')->assertOk()->json('data'))->firstWhere('code', $t->tes[$key]->code)['balance'];
}

function tesLock(object $t, string $table, string $code): array
{
    return ['If-Match' => (string) DB::connection('pgsql_admin')->table($table)->where('code', $code)->value('lock_version')];
}

/** Caja abierta con un gasto de S/ 120 (ticket). */
function tesExpense(object $t, float $amount = 120, string $series = 'B001-1'): TestResponse
{
    return tes($t, 'finance', "petty-cash/{$t->tes['caja']->code}/expenses", ['receipt_type' => 'ticket', 'series_number' => $series, 'supplier_name' => 'Bodega',
        'description' => 'Agua para pasajeros', 'amount' => $amount]);
}

it('§10-1 aislamiento: otro tenant no lista cuentas, movimientos ni hojas', function () {
    tes($this, 'finance', 'movements', ['account_id' => $this->tes['pen']->code, 'direction' => 'in', 'amount' => 50, 'description' => 'Intereses'])->assertCreated();
    $token = $this->tokenFor($this->makeUser($this->makeTenant('inka'), 'finance'));
    expect($this->api($token)->getJson('/api/v1/treasury/accounts')->assertOk()->json('data'))->toBe([])
        ->and($this->api($token)->getJson('/api/v1/treasury/movements')->assertOk()->json('data'))->toBe([]);
    $this->api($token)->getJson("/api/v1/treasury/accounts/{$this->tes['pen']->code}/movements")->assertNotFound();
    $this->api($token)->getJson('/api/v1/treasury/movements/MOV-000001')->assertNotFound();
    $mgr = $this->tokenFor($this->makeUser(Tenant::where('slug', 'inka')->first(), 'manager'));
    expect($this->api($mgr)->getJson('/api/v1/crm/claim-book')->assertOk()->json('data'))->toBe([]);
});

it('§10-2 RBAC: Manager no registra (403); Contabilidad registra la diferencia cambiaria (201); Finanzas no ve el libro', function () {
    tes($this, 'manager', 'movements', ['account_id' => $this->tes['pen']->code, 'direction' => 'in', 'amount' => 50, 'description' => 'Intereses'])->assertForbidden();
    $this->qGet('manager', 'treasury/summary')->assertOk()->assertJsonPath('data.can.register', false)->assertJsonPath('data.can.approve', true);
    tes($this, 'accounting', 'fx/revaluations', ['close_rate' => 3.74])->assertCreated();
    tes($this, 'u03', 'fx/revaluations', ['close_rate' => 3.74])->assertForbidden();
    $this->qGet('finance', 'crm/claim-book')->assertForbidden();
    $this->qGet('u03', 'treasury/summary')->assertForbidden();
});

it('§10-3 sincronización: cobro → 1 ingreso; reproceso → 0; reversión → egreso; Yape USD → BCP PEN con monto original y TC', function () {
    $p = $this->pay(0, '100.00', 'Transferencia BCP', ['reference' => 'OP-7781'])->assertCreated()->json('data');
    $in = $this->admin()->table('fin_treasury_movements')->where('source_key', "PAG:{$p['code']}")->get();
    expect($in)->toHaveCount(1)->and($in[0]->account_id)->toBe($this->tes['usd']->id)->and($in[0]->amount)->toBe('100.00')->and($in[0]->type)->toBe('collection');
    tes($this, 'finance', 'sync')->assertOk()->assertJsonPath('data.created', 0);

    $this->fPost('finance', "payments/{$p['code']}/revert", ['reason' => 'Transferencia observada'])->assertOk();
    $out = $this->admin()->table('fin_treasury_movements')->where('source_key', "REV:{$p['code']}")->first();
    expect($out->direction)->toBe('out')->and($out->amount)->toBe('100.00')->and($out->type)->toBe('collection_reversal');
    expect(tesBalance($this, 'usd'))->toBe(1000.0);

    $y = $this->pay(0, '100.00', 'Yape', ['reference' => 'YP-123'])->assertCreated()->json('data');
    $m = $this->admin()->table('fin_treasury_movements')->where('source_key', "PAG:{$y['code']}")->first();
    expect($m->account_id)->toBe($this->tes['pen']->id)->and($m->amount)->toBe('375.00')->and($m->orig_amount)->toBe('100.00')
        ->and($m->orig_currency)->toBe('USD')->and((float) $m->orig_fx_rate)->toBe(3.75);
    tes($this, 'finance', "movements/{$this->admin()->table('fin_treasury_movements')->where('id', $m->id)->value('code')}/void", ['reason' => 'Cobro duplicado'])
        ->assertStatus(422)->assertJsonPath('code', 'TES-BR-03');
});

it('§10-4 TES-BR-02: transferencia mayor al saldo → 422; dos transferencias seguidas nunca dejan saldo negativo', function () {
    $data = ['from_account_id' => $this->tes['pen']->code, 'to_account_id' => $this->tes['caja']->code, 'reference' => 'OP-1'];
    tes($this, 'finance', 'transfers', $data + ['amount_from' => 6000])->assertStatus(422)->assertJsonPath('code', 'TES-BR-02');
    tes($this, 'finance', 'transfers', ['to_account_id' => $this->tes['usd']->code] + $data + ['amount_from' => 3750])->assertStatus(422)->assertJsonPath('errors.deal_rate.0', 'Indique el tipo de cambio pactado.');
    $t = tes($this, 'finance', 'transfers', ['to_account_id' => $this->tes['usd']->code, 'deal_rate' => 3.75] + $data + ['amount_from' => 3750])->assertCreated()->json('data');
    expect(tesBalance($this, 'usd'))->toBe(2000.0);
    tes($this, 'finance', 'transfers', ['to_account_id' => $this->tes['usd']->code, 'deal_rate' => 3.75] + $data + ['amount_from' => 3750])->assertStatus(422)->assertJsonPath('code', 'TES-BR-02');
    expect(tesBalance($this, 'pen'))->toBe(1250.0);
    $in = $this->admin()->table('fin_treasury_movements')->where('source_code', $t['code'])->where('direction', 'in')->first();
    expect($in->orig_amount)->toBe('3750.00')->and($in->orig_currency)->toBe('PEN');
    expect(collect($this->auditRows('Transferencia', $t['code']))->pluck('action')->all())->toBe(['registrar']);
});

it('§10-5 TES-BR-05/06/07: gasto con la caja cerrada → 422; segunda apertura → 409; conteo distinto → 422', function () {
    tesExpense($this)->assertStatus(422)->assertJsonPath('code', 'TES-BR-05');
    tes($this, 'finance', "petty-cash/{$this->tes['caja']->code}/sessions", ['opening_amount' => 990])->assertStatus(422)->assertJsonPath('code', 'TES-BR-07');
    tes($this, 'finance', "petty-cash/{$this->tes['caja']->code}/sessions", ['opening_amount' => 1000])->assertCreated();
    tes($this, 'finance', "petty-cash/{$this->tes['caja']->code}/sessions", ['opening_amount' => 1000])->assertStatus(409)->assertJsonPath('code', 'TES-BR-06');
    tesExpense($this, 600)->assertStatus(422)->assertJsonPath('code', 'TES-BR-11');
    tes($this, 'finance', "petty-cash/{$this->tes['caja']->code}/expenses", ['receipt_type' => 'invoice', 'series_number' => 'F001-9', 'supplier_name' => 'Tai Loy',
        'description' => 'Útiles', 'amount' => 50])->assertStatus(422)->assertJsonPath('code', 'TES-BR-12');
    $invoice = ['receipt_type' => 'invoice', 'series_number' => 'F001-9', 'issuer_ruc' => '20512345678', 'supplier_name' => 'Tai Loy', 'description' => 'Útiles', 'amount' => 120];
    tes($this, 'finance', "petty-cash/{$this->tes['caja']->code}/expenses", $invoice)->assertCreated();
    tes($this, 'finance', "petty-cash/{$this->tes['caja']->code}/expenses", $invoice)->assertStatus(409)->assertJsonPath('code', 'TES-BR-12');
    expect(tesBalance($this, 'caja'))->toBe(880.0);
});

it('§10-6 arqueo −25 con tolerancia 10 → pendiente sin ajuste; el firmante no aprueba (403); el Manager aprueba → ajuste de 25', function () {
    $c = tes($this, 'finance', "petty-cash/{$this->tes['caja']->code}/counts", ['kind' => 'surprise', 'denominations' => ['200' => 4, '100' => 1, '50' => 1, '20' => 1, '5' => 1]])
        ->assertStatus(422)->assertJsonPath('code', 'TES-BR-13');
    $c = tes($this, 'finance', "petty-cash/{$this->tes['caja']->code}/counts", ['kind' => 'surprise', 'denominations' => ['200' => 4, '100' => 1, '50' => 1, '20' => 1, '5' => 1],
        'note' => 'Faltante sin explicar'])->assertCreated()->assertJsonPath('data.status', 'pending_approval')->assertJsonPath('data.difference', '-25.00')->json('data');
    expect($this->admin()->table('fin_treasury_movements')->where('type', 'count_adjustment')->count())->toBe(0);
    tes($this, 'finance', "counts/{$c['code']}/approve", [], tesLock($this, 'fin_cash_counts', $c['code']))->assertForbidden()->assertJsonPath('code', 'TES-BR-14');
    tes($this, 'manager', "counts/{$c['code']}/approve", ['note' => 'Descontar al custodio'], tesLock($this, 'fin_cash_counts', $c['code']))->assertOk()->assertJsonPath('data.status', 'approved');
    $adj = $this->admin()->table('fin_treasury_movements')->where('type', 'count_adjustment')->first();
    expect($adj->amount)->toBe('25.00')->and($adj->direction)->toBe('out');
    expect(tesBalance($this, 'caja'))->toBe(975.0);
    tes($this, 'accounting', "counts/{$c['code']}/validate")->assertOk()->assertJsonPath('data.status', 'validated');
});

it('§10-7 reposición: el solicitante no aprueba (403); el pago crea la transferencia y marca paid; anular la transferencia → approved', function () {
    tes($this, 'finance', "petty-cash/{$this->tes['caja']->code}/sessions", ['opening_amount' => 1000])->assertCreated();
    tesExpense($this, 120, 'B001-1')->assertCreated();
    tesExpense($this, 80, 'B001-2')->assertCreated();
    $r = tes($this, 'finance', "petty-cash/{$this->tes['caja']->code}/replenishments")->assertCreated()->assertJsonPath('data.amount', '200.00')->json('data');
    tes($this, 'finance', "petty-cash/{$this->tes['caja']->code}/replenishments")->assertStatus(409)->assertJsonPath('code', 'TES-BR-15');
    tes($this, 'finance', "replenishments/{$r['code']}/approve", [], tesLock($this, 'fin_cash_replenishments', $r['code']))->assertForbidden()->assertJsonPath('code', 'TES-BR-14');
    tes($this, 'manager', "replenishments/{$r['code']}/approve", [], tesLock($this, 'fin_cash_replenishments', $r['code']))->assertOk();
    tes($this, 'finance', "replenishments/{$r['code']}/pay", ['from_account_id' => $this->tes['pen']->code, 'reference' => 'OP-9'])->assertOk()->assertJsonPath('data.status', 'paid');
    expect(tesBalance($this, 'caja'))->toBe(1000.0)->and(tesBalance($this, 'pen'))->toBe(4800.0);
    $mov = $this->admin()->table('fin_treasury_movements')->where('type', 'replenishment')->where('direction', 'in')->value('code');
    tes($this, 'finance', "movements/{$mov}/void", ['reason' => 'Transferencia rebotada'])->assertOk();
    expect($this->admin()->table('fin_cash_replenishments')->where('code', $r['code'])->value('status'))->toBe('approved')
        ->and(tesBalance($this, 'caja'))->toBe(800.0)->and(tesBalance($this, 'pen'))->toBe(5000.0);
    expect(collect($this->auditRows('Reposición de caja', $r['code']))->pluck('action')->all())->toContain('solicitar', 'aprobar', 'pagar');
});

it('§10-8 revaluación: promedio ponderado del fixture (+8.00, realizada 5.00) y la segunda del periodo → 409', function () {
    tes($this, 'finance', 'movements', ['account_id' => $this->tes['usd']->code, 'direction' => 'in', 'amount' => 500, 'fx_rate' => 3.80, 'description' => 'Cobro en dólares'])->assertCreated();
    tes($this, 'finance', 'movements', ['account_id' => $this->tes['usd']->code, 'direction' => 'out', 'amount' => 300, 'fx_rate' => 3.75, 'description' => 'Pago en dólares'])->assertCreated();
    $p = $this->qGet('accounting', 'treasury/fx/revaluation-preview?close_rate=3.74')->assertOk()->json('data');
    expect($p['rows'][0]['balance_usd'])->toEqual(1200)->and($p['diff_pen'])->toEqual(8.0)->and($p['realized_pen'])->toEqual(5.0);
    tes($this, 'accounting', 'fx/revaluations', ['close_rate' => 3.74])->assertCreated()->assertJsonPath('data.diff_pen', 8);
    tes($this, 'accounting', 'fx/revaluations', ['close_rate' => 3.74])->assertStatus(409)->assertJsonPath('code', 'TES-BR-16');
});

it('TES-BR-01 sin fechas futuras; la pasarela liquida neto y comisión', function () {
    tes($this, 'finance', 'movements', ['account_id' => $this->tes['pen']->code, 'direction' => 'in', 'amount' => 50, 'description' => 'Intereses',
        'movement_date' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonPath('code', 'TES-BR-01');
    $gw = $this->inTenant(fn () => app(TreasuryService::class)->createAccount(['kind' => 'gateway', 'name' => 'Niubiz', 'currency' => 'PEN', 'sede_id' => Sede::value('id'),
        'collect_methods' => ['tarjeta'], 'settle_to_account_id' => $this->tes['pen']->id], $this->users['admin']));
    $this->pay(0, '100.00', 'Tarjeta', ['reference' => 'AUT-5521'])->assertCreated();
    tes($this, 'finance', "gateway/{$gw->code}/settlements", ['gross_amount' => 375, 'batch' => 'L-1'])->assertCreated();
    expect(tesBalance($this, 'pen'))->toBe(5360.0);
});
