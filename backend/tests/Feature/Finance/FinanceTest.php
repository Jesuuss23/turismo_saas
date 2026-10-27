<?php

use App\Models\Finance\Invoice;
use App\Services\Finance\Fiscal\FiscalProvider;
use App\Services\Finance\Fiscal\FiscalResult;
use App\Services\Finance\Fiscal\SimulatedFiscalProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->financeWorld();
});

it('T01: aislamiento — el tenant B no lista ni abre CxC, pagos ni comprobantes del tenant A', function () {
    $this->pay(0, '358.72', 'Transferencia BCP', ['reference' => 'OP-1'])->assertCreated();
    $invoice = $this->fPost('finance', 'invoices', $this->invoicePayload())->assertStatus(202)->json('data');
    $other = $this->makeTenant('inka');
    $token = $this->tokenFor($this->makeUser($other, 'finance'));

    expect($this->api($token)->getJson('/api/v1/finance/receivables')->assertOk()->json('data'))->toBe([])
        ->and($this->api($token)->getJson('/api/v1/finance/payments')->assertOk()->json('data'))->toBe([])
        ->and($this->api($token)->getJson('/api/v1/finance/invoices')->assertOk()->json('data'))->toBe([]);
    $this->api($token)->getJson("/api/v1/finance/receivables/{$this->cxc(0)->code}")->assertNotFound();
    $this->api($token)->getJson("/api/v1/finance/invoices/{$invoice['number']}")->assertNotFound();
});

it('T02: RBAC — Manager no registra pagos; Contabilidad no aprueba CxP; Ventas no ve CxC; Contabilidad emite comprobantes', function () {
    $this->pay(0, '10', 'Efectivo', [], 'manager')->assertForbidden();
    $payable = $this->makePayable();
    $this->fPost('accounting', "payables/{$payable->code}/approve")->assertForbidden();
    $this->fGet('u03', 'receivables')->assertForbidden();
    $this->pay(0, '358.72', 'Transferencia BCP', ['reference' => 'OP-2'])->assertCreated();
    $this->fPost('accounting', 'invoices', $this->invoicePayload())->assertStatus(202)->assertJsonPath('data.status', 'aceptado');
});

it('T03: permiso compuesto — finance.payables sin OP aprueba pero no programa ni paga la CxP', function () {
    $payable = $this->makePayable();
    $this->fPost('manager', "payables/{$payable->code}/approve")->assertOk()->assertJsonPath('data.approved', true);
    $this->fPost('manager', "payables/{$payable->code}/schedule", ['scheduled_date' => now()->addDay()->toDateString(), 'bank_account_id' => $this->accounts['USD']->id])->assertForbidden();
    $this->fPost('manager', "payables/{$payable->code}/payouts", ['method' => 'transferencia', 'amount' => '10', 'operation_ref' => 'X', 'fx_rate' => 3.75,
        'bank_account_id' => $this->accounts['USD']->id])->assertForbidden();
});

it('T04: pago > saldo → 422 PROC-042; transferencia sin referencia → 422; efectivo sin referencia → OK', function () {
    $this->pay(0, '400.00', 'Transferencia BCP', ['reference' => 'OP-3'])->assertStatus(422)->assertJsonPath('code', 'PROC-042');
    $this->pay(0, '100.00', 'Transferencia BCP')->assertStatus(422)->assertJsonValidationErrors('reference');
    $this->pay(0, '100.00', 'Efectivo')->assertCreated()->assertJsonPath('data.status', 'aplicado')->assertJsonPath('data.reference', 'Caja sede')
        ->assertJsonPath('data.receivable.status', 'parcial');
});

it('T05: pago total de la CxC única de una reserva pendiente_pago → CxC pagada, reserva confirmada y auditoría', function () {
    $this->admin()->table('fin_receivables')->where('id', $this->cxc(1)->id)->update(['status' => 'cancelada']);
    $this->admin()->table('booking_reservations')->where('id', $this->fres['id'])->update(['status' => 'pendiente_pago']);
    $payment = $this->pay(0, '358.72', 'Tarjeta', ['reference' => 'VISA-1'])->assertCreated()->assertJsonPath('data.receivable.status', 'pagada')->json('data');

    expect($this->fresh('booking_reservations', $this->fres['id'])->status)->toBe('confirmada')
        ->and(collect($this->auditRows('Pago', $payment['code']))->pluck('action')->all())->toBe(['registrar', 'aplicar'])
        ->and(collect($this->auditRows('Cuenta por cobrar', $this->cxc(0)->code))->pluck('action')->all())->toContain('actualizar_saldo')
        ->and(collect($this->auditRows('Reserva', $this->fres['code']))->pluck('action')->all())->toContain('cambio_estado');
});

it('T06: crédito B2B — agencia bloqueada → RT-14; insuficiente → 422; OK suma credit_used; revertir lo resta', function () {
    $agency = (string) Str::uuid7();
    $this->admin()->table('channel_b2b_agencies')->insert(['id' => $agency, 'tenant_id' => $this->tenant->id, 'code' => 'AGB-01', 'name' => 'Sol Viajes',
        'client_id' => $this->cxc(0)->client_id, 'status' => 'bloqueada', 'credit_limit' => 1000, 'credit_used' => 0, 'commission_pct' => 10,
        'created_at' => now(), 'updated_at' => now()]);
    $this->pay(0, '100.00', 'Crédito B2B')->assertStatus(422)->assertJsonPath('code', 'RT-14');
    $this->admin()->table('channel_b2b_agencies')->where('id', $agency)->update(['status' => 'activa']);
    // 358.72 USD × 3.75 = PEN 1345.20 > línea disponible 1000.
    $this->pay(0, '358.72', 'Crédito B2B')->assertStatus(422)->assertJsonPath('code', 'FIN-BR-10');
    expect(collect($this->auditRows('Agencia B2B', 'AGB-01'))->pluck('action')->all())->toBe(['credito_excedido']);
    $this->admin()->table('channel_b2b_agencies')->where('id', $agency)->update(['credit_limit' => 2000]);
    $p = $this->pay(0, '358.72', 'Crédito B2B')->assertCreated()->assertJsonPath('data.reference', 'Línea B2B')->json('data');
    expect($this->fresh('channel_b2b_agencies', $agency)->credit_used)->toBe('1345.20');
    $this->fPost('finance', "payments/{$p['code']}/revert", ['reason' => 'Agencia pagó por transferencia'])->assertOk();
    expect($this->fresh('channel_b2b_agencies', $agency)->credit_used)->toBe('0.00');
});

it('T07: máquina de estados del pago — aplicar pendiente → 409; revertir rechazado → 409; rechazar sin motivo → 422; reintento suma retries', function () {
    $p = $this->pendingPayment(0, '100.00');
    $this->fPost('finance', "payments/{$p->code}/apply", ['receivable_id' => $this->cxc(0)->id])->assertStatus(409)->assertJsonPath('code', 'FIN-BR-14');
    $this->fPost('finance', "payments/{$p->code}/reject", ['reason' => ''])->assertStatus(422);
    $this->fPost('finance', "payments/{$p->code}/reject", ['reason' => 'Voucher ilegible'])->assertOk()->assertJsonPath('data.status', 'rechazado');
    $this->fPost('finance', "payments/{$p->code}/revert", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'FIN-BR-16');
    $this->fPost('finance', "payments/{$p->code}/retry")->assertOk()->assertJsonPath('data.status', 'pendiente')->assertJsonPath('data.retries', 1);
    $this->fPost('finance', "payments/{$p->code}/validate", ['apply' => true])->assertOk()->assertJsonPath('data.status', 'aplicado');
    expect($this->cxc(0)->paid_amount)->toBe('100.00')->and($this->cxc(0)->status)->toBe('parcial');
});

it('T08: revertir sin finance.payments.revert → 403; con permiso recalcula pagado y estado', function () {
    $p = $this->pay(0, '358.72', 'Transferencia BCP', ['reference' => 'OP-8'])->assertCreated()->json('data');
    $this->fPost('accounting', "payments/{$p['code']}/revert", ['reason' => 'Error'])->assertForbidden();
    $this->fPost('finance', "payments/{$p['code']}/revert", ['reason' => 'Abono duplicado'])->assertOk()->assertJsonPath('data.status', 'revertido');
    expect($this->cxc(0))->paid_amount->toBe('0.00')->status->toBe('abierta')
        ->and(collect($this->auditRows('Cuenta por cobrar', $this->cxc(0)->code))->pluck('action')->all())->toContain('recalcular_saldo');
});

it('T09: idempotencia — dos POST /payments con la misma Idempotency-Key → un solo pago y la misma respuesta', function () {
    $body = ['receivable_id' => $this->cxc(0)->id, 'amount' => '50.00', 'method' => 'Efectivo'];
    $a = $this->qPost('finance', 'finance/payments', $body, ['Idempotency-Key' => 'pay-once'])->assertCreated();
    $b = $this->qPost('finance', 'finance/payments', $body, ['Idempotency-Key' => 'pay-once'])->assertCreated();
    expect($b->json('data.code'))->toBe($a->json('data.code'))->and($b->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($this->admin()->table('fin_payments')->where('receivable_id', $this->cxc(0)->id)->count())->toBe(1);
});

it('T10: concurrencia — dos aplicaciones que juntas exceden el saldo → la segunda 409; versión vieja → 409', function () {
    $a = $this->pendingPayment(0, '300.00', '1001');
    $b = $this->pendingPayment(0, '300.00', '1002');
    $this->fPost('finance', "payments/{$a->code}/validate")->assertOk()->assertJsonPath('data.status', 'confirmado');
    $this->fPost('finance', "payments/{$b->code}/validate")->assertOk();
    $this->fPost('finance', "payments/{$a->code}/apply", [], ['If-Match' => '1'])->assertOk()->assertJsonPath('data.status', 'aplicado');
    $this->fPost('finance', "payments/{$b->code}/apply", [], ['If-Match' => '0'])->assertStatus(409);
    $this->fPost('finance', "payments/{$b->code}/apply")->assertStatus(409)->assertJsonPath('code', 'FIN-BR-14');
    expect($this->cxc(0)->paid_amount)->toBe('300.00');
});

it('T11: anticipo > disponible no comprometido → 422 PROC-041; reserva cancelada → 422', function () {
    $this->fGet('finance', "reservations/{$this->fres['code']}/account")->assertOk()->assertJsonPath('data.available', '0.00');
    $this->fPost('finance', 'receivables/advances', ['reservation_id' => $this->fres['id'], 'amount' => 10, 'due_date' => now()->addDays(3)->toDateString()])
        ->assertStatus(422)->assertJsonPath('code', 'PROC-041');
    $this->admin()->table('fin_receivables')->where('id', $this->cxc(1)->id)->update(['status' => 'cancelada']);
    $adv = $this->fPost('finance', 'receivables/advances', ['reservation_id' => $this->fres['code'], 'amount' => 200, 'due_date' => now()->addDays(3)->toDateString(), 'notify' => true])
        ->assertCreated()->json('data');
    expect($adv)->kind->toBe('anticipo')->amount->toBe('200.00')->status->toBe('abierta')
        ->and($this->admin()->table('notif_messages')->where('event', 'pago.solicitud')->count())->toBe(2);
    $this->admin()->table('booking_reservations')->where('id', $this->fres['id'])->update(['status' => 'cancelada']);
    $this->fPost('finance', 'receivables/advances', ['reservation_id' => $this->fres['id'], 'amount' => 10, 'due_date' => now()->addDays(3)->toDateString()])
        ->assertStatus(422)->assertJsonPath('code', 'FIN-BR-02');
});

it('T12: CxP no aprobada — programar/pagar → 422 PROC-046; fecha pasada → 422; pago total limpia la programación', function () {
    $p = $this->makePayable('200.00');
    $schedule = ['scheduled_date' => now()->addDays(2)->toDateString(), 'bank_account_id' => $this->accounts['USD']->id];
    $payout = ['method' => 'transferencia', 'bank_account_id' => $this->accounts['USD']->id, 'amount' => '200.00', 'fx_rate' => '3.76', 'operation_ref' => 'OP-55'];
    $this->fPost('finance', "payables/{$p->code}/schedule", $schedule)->assertStatus(422)->assertJsonPath('code', 'PROC-046');
    $this->fPost('finance', "payables/{$p->code}/payouts", $payout)->assertStatus(422)->assertJsonPath('code', 'PROC-046');
    $this->fPost('finance', "payables/{$p->code}/approve")->assertOk()->assertJsonPath('data.status', 'aprobada');
    $this->fPost('finance', "payables/{$p->code}/schedule", ['scheduled_date' => now()->subDay()->toDateString()] + $schedule)->assertStatus(422);
    $this->fPost('finance', "payables/{$p->code}/schedule", ['bank_account_id' => $this->accounts['PEN']->id] + $schedule)->assertStatus(422);
    $this->fPost('finance', "payables/{$p->code}/schedule", $schedule)->assertOk()->assertJsonPath('data.scheduled_date', $schedule['scheduled_date']);
    $this->fPost('finance', "payables/{$p->code}/payouts", ['amount' => '250.00'] + $payout)->assertStatus(422);
    $this->fPost('finance', "payables/{$p->code}/payouts", ['amount' => '50.00'] + $payout)->assertCreated()->assertJsonPath('data.payable.status', 'parcial');
    $this->fPost('finance', "payables/{$p->code}/payouts", ['amount' => '150.00'] + $payout)->assertCreated()
        ->assertJsonPath('data.payable.status', 'pagada')->assertJsonPath('data.payable.scheduled_date', null);
    expect($this->admin()->table('notif_messages')->where('event', 'pago.proveedor')->count())->toBe(2);
    // Pagado en el mes (KPI de CxP) al TC del pago: 200 × 3,76.
    $this->fGet('finance', 'payables/summary')->assertOk()->assertJsonPath('data.paid_month', '752.00');
});

it('T13: costo adicional no aprobado no altera la rentabilidad; aprobado sí', function () {
    $before = $this->fGet('finance', "reservations/{$this->fres['code']}/settlement")->assertOk()->json('data.figures');
    $preview = $this->fPost('finance', 'costs/preview', ['reservation_id' => $this->fres['id'], 'amount' => 100, 'currency' => 'USD'])->assertOk()->json('data');
    expect((float) $preview['after']['margin_pct'])->toBeLessThan((float) $preview['before']['margin_pct']);
    $cost = $this->fPost('finance', 'costs', ['reservation_id' => $this->fres['id'], 'provider_id' => $this->vendor->id, 'cost_type' => 'costo_adicional',
        'concept' => 'Ingreso adicional', 'amount' => 100])->assertCreated()->json('data');
    expect($cost)->kind->toBe('costo_adicional')->status->toBe('abierta')->approved->toBeFalse();
    expect($this->fGet('finance', "reservations/{$this->fres['code']}/settlement")->json('data.figures'))->toBe($before);
    $this->fPost('manager', "payables/{$cost['code']}/approve")->assertOk();
    $after = $this->fGet('finance', "reservations/{$this->fres['code']}/settlement")->json('data.figures');
    expect($after['extra_cost'])->toBe('375.00')->and((float) $after['margin_pct'])->toBe((float) $preview['after']['margin_pct'])
        ->and(collect($this->auditRows('Rentabilidad', $this->fres['code']))->pluck('action')->all())->toBe(['impacto_costo']);
});

it('T14: comisiones — sin duplicados; 3 % / 2,5 % sin IGV / % convenio B2B; recalcular pagada → 422; recalcular aprobada → calculada', function () {
    $corp = $this->makeReservation('u04');
    $b2b = $this->makeReservation('u03');
    $this->admin()->table('booking_reservations')->where('id', $corp['id'])->update(['currency' => 'PEN']);
    $this->admin()->table('crm_clients')->where('id', $this->clients['u04']->id)->update(['segment_code' => 'corporativo']);
    $this->admin()->table('booking_reservations')->where('id', $b2b['id'])->update(['channel' => 'B2B', 'client_id' => $this->makeClient('u03', ['kind' => 'empresa', 'name' => 'Sol Viajes',
        'doc_type' => 'RUC', 'doc_number' => '20512345678'])->id]);
    $agencyClient = $this->fresh('booking_reservations', $b2b['id'])->client_id;
    $this->admin()->table('channel_b2b_agencies')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'code' => 'AGB-01', 'name' => 'Sol Viajes',
        'client_id' => $agencyClient, 'status' => 'activa', 'credit_limit' => 0, 'credit_used' => 0, 'commission_pct' => 10, 'created_at' => now(), 'updated_at' => now()]);

    expect($this->fPost('finance', 'commissions/calculate')->assertCreated()->json('meta.created'))->toBe(3)
        ->and($this->fPost('finance', 'commissions/calculate')->json('meta.created'))->toBe(0);
    $rows = collect($this->fGet('finance', 'commissions')->json('data'))->keyBy('reservation');
    expect($rows[$this->fres['code']])->kind->toBe('vendedor')->pct->toBe('3.00')->base->toBe('896.80')->amount->toBe('26.90')
        ->and($rows[$corp['code']])->pct->toBe('2.50')->base->toBe('760.00')->amount->toBe('19.00')->currency->toBe('PEN')
        ->and($rows[$b2b['code']])->kind->toBe('agencia_b2b')->beneficiary->toBe('Sol Viajes')->pct->toBe('10.00')->amount->toBe('89.68');

    $c = $rows[$this->fres['code']]['code'];
    $this->fPost('finance', "commissions/{$c}/pay", ['method' => 'Planilla del mes', 'reference' => 'PL-1'])->assertStatus(409);
    $this->fPost('manager', "commissions/{$c}/approve")->assertOk()->assertJsonPath('data.status', 'aprobada');
    $this->fPost('finance', "commissions/{$c}/recalculate")->assertOk()->assertJsonPath('data.status', 'calculada');
    $this->fPost('finance', "commissions/{$c}/approve")->assertOk();
    $this->fPost('finance', "commissions/{$c}/pay", ['method' => 'NC a cuenta', 'reference' => 'PL-1'])->assertStatus(422);
    $this->fPost('finance', "commissions/{$c}/pay", ['method' => 'Planilla del mes', 'reference' => 'PL-1'])->assertOk()->assertJsonPath('data.status', 'pagada');
    $this->fPost('finance', "commissions/{$c}/recalculate")->assertStatus(422)->assertJsonPath('code', 'FIN-BR-29');
    expect($this->admin()->table('fin_commissions')->count())->toBe(3);
});

it('T15: liquidación — checklist incompleto → 422 con pendientes; completo → snapshot; segunda vez → 409', function () {
    $res = $this->fPost('finance', "reservations/{$this->fres['code']}/settlement")->assertStatus(422)->assertJsonPath('code', 'FIN-BR-36');
    expect(collect($res->json('pending'))->pluck('key')->all())->toBe(['receivables', 'commissions', 'completed']);

    $this->pay(0, '358.72', 'Efectivo')->assertCreated();
    $this->pay(1, '538.08', 'Efectivo')->assertCreated();
    $this->fPost('finance', 'commissions/calculate')->assertCreated();
    $c = $this->fGet('finance', 'commissions')->json('data.0.code');
    $this->fPost('finance', "commissions/{$c}/approve")->assertOk();
    $this->admin()->table('booking_reservations')->where('id', $this->fres['id'])->update(['status' => 'completada']);

    $done = $this->fPost('finance', "reservations/{$this->fres['code']}/settlement")->assertCreated()->json('data');
    expect($done['settlement'])->income_pen->toBe('3363.00')->commissions_pen->toBe('100.88')
        ->and($done['ready'])->toBeTrue();
    expect(json_decode($this->admin()->table('fin_settlements')->value('snapshot'), true))->toHaveKeys(['checklist', 'figures', 'cash', 'receivables', 'payables', 'commissions']);
    expect($this->admin()->table('notif_messages')->where('event', 'reserva.liquidada')->count())->toBe(1);
    $this->fPost('finance', "reservations/{$this->fres['code']}/settlement")->assertStatus(409)->assertJsonPath('code', 'FIN-BR-37');
});

it('T16: validación fiscal — factura con DNI → RT-10; boleta con RUC → 422; NC sin referencia o sobre no aceptado → 422; importe > máximo → 422', function () {
    $this->pay(0, '358.72', 'Efectivo')->assertCreated();
    $base = $this->invoicePayload();
    expect($base)->doc_type->toBe('03')->customer_doc_type->toBe('DNI')->amount->toBe('358.72')->max->toBe('358.72');

    $this->fPost('accounting', 'invoices', ['doc_type' => '01'] + $base)->assertStatus(422)->assertJsonPath('code', 'RT-10');
    $this->fPost('accounting', 'invoices', ['customer_doc_type' => 'RUC', 'customer_doc_number' => '20512345678'] + $base)->assertStatus(422)->assertJsonPath('code', 'FIN-BR-40');
    $this->fPost('accounting', 'invoices', ['amount' => '358.73'] + $base)->assertStatus(422)->assertJsonPath('code', 'FIN-BR-43');
    $this->fPost('accounting', 'invoices', ['doc_type' => '07', 'credit_note_reason_code' => '01'] + $base)->assertStatus(422)->assertJsonPath('code', 'FIN-BR-41');
    $check = $this->fPost('accounting', 'invoices/validate', ['doc_type' => '01'] + $base)->assertOk()->json('data');
    expect($check['ok'])->toBeFalse()->and(collect($check['checks'])->firstWhere('key', 'document')['ok'])->toBeFalse();

    $boleta = $this->fPost('accounting', 'invoices', $base)->assertStatus(202)->json('data');
    expect($boleta)->number->toBe('B001-00008811')->taxable->toBe('304.00')->igv->toBe('54.72')->in_words->toBe('SON: TRESCIENTOS CINCUENTA Y OCHO CON 72/100 DÓLARES AMERICANOS');
    $this->admin()->table('fin_invoices')->where('id', $boleta['id'])->update(['status' => 'observado']);
    $nc = ['doc_type' => '07', 'ref_invoice_id' => $boleta['id'], 'credit_note_reason_code' => '01', 'amount' => '100.00', 'description' => 'Anulación parcial'] + $base;
    $this->fPost('accounting', 'invoices', $nc)->assertStatus(422)->assertJsonPath('code', 'FIN-BR-41');
    $this->admin()->table('fin_invoices')->where('id', $boleta['id'])->update(['status' => 'aceptado']);
    $this->fPost('accounting', 'invoices', ['amount' => '400.00'] + $nc)->assertStatus(422)->assertJsonPath('code', 'FIN-BR-41');
    $credit = $this->fPost('accounting', 'invoices', $nc)->assertStatus(202)->json('data');
    expect($credit)->number->toBe('BC01-00000012')->reference->toBe('B001-00008811')->status->toBe('aceptado');
    // La NC devuelve margen facturable a la CxC.
    expect($this->fGet('finance', "receivables/{$this->cxc(0)->code}/invoice-draft")->json('data.max'))->toBe('100.00');
});

it('T17: numeración por serie sin huecos ni duplicados', function () {
    $this->pay(0, '358.72', 'Efectivo')->assertCreated();
    $this->pay(1, '538.08', 'Efectivo')->assertCreated();
    $numbers = [];
    foreach ([[0, '100.00'], [0, '100.00'], [1, '200.00'], [0, '158.72']] as [$i, $amount]) {
        $numbers[] = $this->fPost('accounting', 'invoices', $this->invoicePayload($i, ['amount' => $amount]))->assertStatus(202)->json('data.number');
    }
    expect($numbers)->toBe(['B001-00008811', 'B001-00008812', 'B001-00008813', 'B001-00008814'])
        ->and($this->admin()->table('fin_document_series')->where('series', 'B001')->value('next_number'))->toBe(8815);
    $this->fPost('accounting', 'invoices', $this->invoicePayload(0, ['amount' => '1.00']))->assertStatus(422)->assertJsonPath('code', 'FIN-BR-43');
    expect($this->admin()->table('fin_document_series')->where('series', 'B001')->value('next_number'))->toBe(8815);
});

it('T18: outbox fiscal — el fallo del adaptador no revierte la emisión; reintentos; fallo definitivo → alerta; luego se consulta y acepta', function () {
    $this->pay(0, '358.72', 'Efectivo')->assertCreated();
    $this->app->bind(FiscalProvider::class, fn () => new class implements FiscalProvider
    {
        public function name(): string
        {
            return 'ose-caido';
        }

        public function send(Invoice $invoice, int $attempt): FiscalResult
        {
            throw new RuntimeException('Timeout del OSE');
        }

        public function status(Invoice $invoice): FiscalResult
        {
            return FiscalResult::temporary('Timeout del OSE');
        }
    });
    $invoice = $this->fPost('accounting', 'invoices', $this->invoicePayload())->assertStatus(202)->json('data');
    expect($invoice['status'])->toBe('pendiente_envio')
        ->and($this->admin()->table('fin_fiscal_events')->where('invoice_id', $invoice['id'])->pluck('status')->all())->toBe(['error_temporal', 'error_temporal', 'error_temporal'])
        ->and($this->admin()->table('notif_messages')->where('event', 'comprobante.error_envio')->count())->toBeGreaterThan(0)
        ->and(collect($this->auditRows('Comprobante', $invoice['number']))->pluck('action')->all())->toBe(['emitir', 'error_fiscal']);

    $this->app->bind(FiscalProvider::class, SimulatedFiscalProvider::class);
    $this->fPost('accounting', "invoices/{$invoice['number']}/status-check")->assertOk()->assertJsonPath('data.status', 'aceptado');
    $this->fPost('accounting', "invoices/{$invoice['number']}/deliver")->assertOk();
    expect($this->admin()->table('notif_messages')->where('event', 'comprobante.emitido')->count())->toBe(2);
});

it('T19: conciliación — match ±0,50 y referencia; pago revertido no es candidato; cerrar con excepciones → 422; línea cerrada inmutable', function () {
    $good = $this->pay(0, '100.00', 'Transferencia BCP', ['reference' => 'OP-4455661'])->assertCreated()->json('data');
    $twin = $this->pay(0, '100.00', 'Transferencia BCP', ['reference' => 'OP-9999999'])->assertCreated()->json('data');
    $gone = $this->pay(1, '50.00', 'Transferencia BCP', ['reference' => 'OP-7777777'])->assertCreated()->json('data');
    $this->fPost('finance', "payments/{$gone['code']}/revert", ['reason' => 'Duplicado'])->assertOk();
    $pen = fn (string $usd) => number_format((float) $usd * 3.75, 2, '.', '');
    $csv = "fecha;descripcion;importe\n".now()->format('d/m/Y').';ABONO TRANSF OP 4455661;'.((float) $pen('100.00') + 0.30)."\n"
        .now()->format('d/m/Y').';ABONO TRANSF 7777777;'.$pen('50.00')."\n".now()->format('d/m/Y').';COMISION MANTENIMIENTO;-15.00'."\n";
    $this->qPost('finance', 'finance/bank-statements', ['bank' => 'BCP', 'file' => UploadedFile::fake()->createWithContent('extracto.csv', $csv)], $this->idem())
        ->assertCreated()->assertJsonPath('data.lines', 3);
    expect($this->fPost('finance', 'reconciliation/matching')->assertOk()->json('data'))->toBe(['suggested' => 1, 'exceptions' => 2]);
    $lines = collect($this->fGet('finance', 'bank-lines')->json('data'))->keyBy('description');
    expect($lines['ABONO TRANSF OP 4455661']['match']['code'])->toBe($good['code'])
        ->and(collect($lines['ABONO TRANSF 7777777']['candidates'])->pluck('code'))->not->toContain($gone['code'])->toContain($twin['code']);

    $this->fPost('finance', 'reconciliation/closures')->assertStatus(422)->assertJsonPath('code', 'FIN-BR-55');
    $this->fPost('finance', "bank-lines/{$lines['ABONO TRANSF OP 4455661']['code']}/confirm")->assertOk()->assertJsonPath('data.status', 'conciliado');
    $this->fPost('finance', "bank-lines/{$lines['COMISION MANTENIMIENTO']['code']}/resolve", ['mode' => 'other', 'note' => ''])->assertStatus(422);
    $this->fPost('finance', "bank-lines/{$lines['COMISION MANTENIMIENTO']['code']}/resolve", ['mode' => 'other', 'note' => 'Comisión mensual BCP'])
        ->assertOk()->assertJsonPath('data.resolution', 'gasto_bancario');
    $this->fPost('finance', "bank-lines/{$lines['ABONO TRANSF 7777777']['code']}/resolve", ['mode' => 'assign', 'target_id' => $twin['code'], 'note' => 'Depósito con referencia distinta'])
        ->assertOk()->assertJsonPath('data.match.code', $twin['code']);
    $closure = $this->fPost('finance', 'reconciliation/closures')->assertCreated()->json('data');
    expect($closure['code'])->toBe('CON-'.now()->year.'-001')->and($closure['lines'])->toBe(3);
    $this->fPost('finance', "bank-lines/{$lines['COMISION MANTENIMIENTO']['code']}/reject")->assertStatus(409)->assertJsonPath('code', 'FIN-BR-55');
    $this->fGet('accounting', 'bank-lines')->assertForbidden();
});

it('T20: periodo cerrado — emitir con fecha del periodo → 422 FIN-BR-48; marcar control sin permiso → 403', function () {
    $period = now('America/Lima')->format('Y-m');
    $this->fGet('accounting', "period-closes/{$period}")->assertOk()->assertJsonPath('data.status', 'abierto')->assertJsonPath('data.total', 7);
    $this->qPost('manager', "finance/period-closes/{$period}/checks/fx")->assertStatus(405);
    $this->as('manager')->putJson("/api/v1/finance/period-closes/{$period}/checks/fx")->assertForbidden();
    $this->as('accounting')->putJson("/api/v1/finance/period-closes/{$period}/checks/fx")->assertOk()->assertJsonPath('data.done', 5);
    $this->fPost('accounting', "period-closes/{$period}/close")->assertStatus(422)->assertJsonPath('code', 'FIN-BR-56');
    $this->as('accounting')->putJson("/api/v1/finance/period-closes/{$period}/checks/aging")->assertOk();
    $this->as('accounting')->putJson("/api/v1/finance/period-closes/{$period}/checks/igv")->assertOk()->assertJsonPath('data.ready', true);
    $this->fPost('accounting', "period-closes/{$period}/close")->assertOk()->assertJsonPath('data.status', 'cerrado');
    $this->as('accounting')->deleteJson("/api/v1/finance/period-closes/{$period}/checks/igv")->assertStatus(409);

    $this->pay(0, '358.72', 'Efectivo')->assertCreated();
    $this->fPost('accounting', 'invoices', $this->invoicePayload())->assertStatus(422)->assertJsonPath('code', 'FIN-BR-48');
});

it('T21: fin:mark-overdue marca CxC y CxP vencidas y no toca pagadas ni canceladas', function () {
    $this->pay(1, '538.08', 'Efectivo')->assertCreated();
    $past = now()->subDays(2)->toDateString();
    $this->admin()->table('fin_receivables')->whereIn('id', [$this->cxc(0)->id, $this->cxc(1)->id])->update(['due_date' => $past]);
    $open = $this->makePayable('80.00', ['due_date' => $past]);
    $paid = $this->makePayable('90.00', ['due_date' => $past, 'status' => 'pagada', 'paid_amount' => '90.00']);
    $void = $this->makePayable('70.00', ['due_date' => $past, 'status' => 'cancelada']);

    $this->artisan('fin:mark-overdue')->assertSuccessful();
    expect($this->cxc(0)->status)->toBe('vencida')->and($this->cxc(1)->status)->toBe('pagada')
        ->and($this->fresh('fin_payables', $open->id)->status)->toBe('vencida')
        ->and($this->fresh('fin_payables', $paid->id)->status)->toBe('pagada')
        ->and($this->fresh('fin_payables', $void->id)->status)->toBe('cancelada');
});

it('T22: consolidación — USD usa el TC conservado del documento, no el del día', function () {
    $this->admin()->table('fin_receivables')->where('id', $this->cxc(0)->id)->update(['fx_rate' => '3.700000']);
    $this->admin()->table('fin_fx_rates')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'rate_date' => now()->toDateString(), 'currency' => 'USD',
        'buy' => '3.880000', 'sell' => '3.900000', 'source' => 'SBS', 'created_at' => now(), 'updated_at' => now()]);
    $row = collect($this->fGet('finance', 'receivables')->json('data'))->firstWhere('code', $this->cxc(0)->code);
    expect($row['balance_pen'])->toBe('1327.26');
    $p = $this->pay(0, '100.00', 'Efectivo')->assertCreated()->json('data');
    expect($p['fx_rate'])->toBe('3.900000')->and($p['amount_pen'])->toBe('390.00');
    expect($this->fGet('finance', "reservations/{$this->fres['code']}/account")->json('data.fx_rate'))->toBe('3.900000');
});
