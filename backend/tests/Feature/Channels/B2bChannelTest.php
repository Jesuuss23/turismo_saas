<?php

use App\Models\Bookings\Reservation;

beforeEach(function () {
    $this->channelWorld();
});

it('T02: la agencia solo ve lo suyo — AGB-02 no abre la reserva ni la CxC de AGB-01; sin agencia vinculada → 403', function () {
    $code = $this->b2bReserve()->assertCreated()->json('data.code');
    $this->qGet('b2b1', "b2b/reservations/{$code}")->assertOk()->assertJsonPath('data.agency_ref', 'SV-1');
    $this->qGet('b2b2', "b2b/reservations/{$code}")->assertNotFound();
    expect($this->qGet('b2b2', 'b2b/reservations')->assertOk()->json('data'))->toBe([])
        ->and($this->qGet('b2b2', 'b2b/statement')->assertOk()->json('data.receivables'))->toBe([]);
    $cxc = $this->admin()->table('fin_receivables')->where('reservation_id', $this->admin()->table('booking_reservations')->where('code', $code)->value('id'))->value('id');
    $this->qPost('b2b2', "b2b/receivables/{$cxc}/payment-reports", ['amount' => 10, 'method' => 'Transferencia BCP', 'operation_number' => 'X'], $this->idem())->assertNotFound();
    $this->addUser('orphan', 'b2b');
    $this->qGet('orphan', 'b2b/me')->assertForbidden()->assertJsonPath('code', 'B2B-BR-17');
});

it('T03: RBAC — Ventas, Operaciones y Finanzas no administran canales; la vista previa interna es de solo lectura', function () {
    foreach (['u03', 'ops', 'finance'] as $who) {
        $this->qGet($who, 'channels/b2b/agencies')->assertForbidden();
        $this->qGet($who, 'channels/ecommerce/orders')->assertForbidden();
        $this->qGet($who, 'cms/contents')->assertForbidden();
    }
    $this->qGet('manager', 'channels/b2b/agencies')->assertOk()->assertJsonCount(2, 'data');
    $this->qGet('manager', 'b2b/me?agency=AGB-02')->assertOk()->assertJsonPath('data.code', 'AGB-02')->assertJsonPath('meta.preview', true);
    $this->b2bReserve('manager')->assertForbidden()->assertJsonPath('code', 'PREVIEW_READ_ONLY');
});

it('T04: RT-14 — sin tarifa asignada o con tarifa vencida no reserva', function () {
    $this->b2bReserve('b2b2', ['pay_mode' => 'prepago'])->assertStatus(422)->assertJsonPath('code', 'RT-14');
    $this->admin()->table('catalog_product_b2b_rates')->where('agency_id', $this->agencies['AGB-01']->id)->update(['valid_to' => now()->subDay()->toDateString()]);
    $row = collect($this->qGet('b2b1', 'b2b/catalog')->assertOk()->json('data'))->firstWhere('id', $this->product->id);
    expect($row['ok'])->toBeFalse()->and($row['reason'])->toContain('Tarifa B2B vencida');
    $this->b2bReserve()->assertStatus(422)->assertJsonPath('code', 'RT-14');
});

it('T05: B2B-BR-04 — crédito insuficiente → 422 sin reserva y con auditoría; con prepago → pendiente_pago', function () {
    // 6 × USD 320 = USD 1920 ≈ PEN 7200 > línea 5000.
    $this->b2bReserve('b2b1', ['pax' => 6])->assertStatus(422)->assertJsonPath('code', 'B2B-BR-04');
    expect($this->admin()->table('booking_reservations')->where('channel', 'B2B')->count())->toBe(0)
        ->and(collect($this->auditRows('Agencia B2B', 'AGB-01'))->pluck('action')->all())->toContain('credito_excedido');
    $this->b2bReserve('b2b1', ['pax' => 6, 'pay_mode' => 'prepago'])->assertCreated()->assertJsonPath('data.status', Reservation::PENDING_PAYMENT)
        ->assertJsonPath('data.services.0.voucher_note', 'Tras el pago');
    expect($this->agency('AGB-01')->credit_used)->toBe('0.00');
});

it('T06: crédito + salida → confirmada, voucher v1, CxC a 15 días, comisión calculada y cargo en el libro', function () {
    $r = $this->b2bReserve()->assertCreated()->assertJsonPath('data.status', Reservation::CONFIRMED)->json('data');
    expect($r['services'][0]['voucher'])->not->toBeNull()
        ->and($r['receivables'][0]['due_date'])->toBe(now('America/Lima')->addDays(15)->toDateString())
        ->and($r['receivables'][0]['amount'])->toBe('640.00');
    $id = $this->admin()->table('booking_reservations')->where('code', $r['code'])->value('id');
    $commission = $this->admin()->table('fin_commissions')->where('reservation_id', $id)->first();
    expect($commission->status)->toBe('calculada')->and($commission->amount)->toBe('64.00')
        ->and($this->agency('AGB-01')->credit_used)->toBe('2400.00')
        ->and($this->admin()->table('channel_b2b_credit_movements')->where('agency_id', $this->agencies['AGB-01']->id)->value('movement_type'))->toBe('cargo_reserva')
        ->and($this->sold())->toBe(2);
    $this->qGet('b2b1', 'b2b/statement')->assertOk()->assertJsonPath('data.commissions.0.amount', '64.00')->assertJsonPath('data.can_settle', true);
});

it('T07: RT-4 — salida con menos cupos que pasajeros → 409', function () {
    $this->admin()->table('catalog_product_departures')->where('id', $this->resDeparture->id)->update(['capacity' => 3]);
    $this->b2bReserve('b2b1', ['pax' => 4, 'pay_mode' => 'prepago'])->assertStatus(409)->assertJsonPath('code', 'RT-4');
    $this->b2bReserve('b2b1', ['pax' => 3, 'pay_mode' => 'prepago'])->assertCreated();
    $this->b2bReserve('b2b1', ['pax' => 1, 'pay_mode' => 'prepago'])->assertStatus(409);
});

it('T08: B2B-BR-07/08 — límite por debajo del usado bloquea; no se desbloquea con crédito excedido', function () {
    $this->b2bReserve()->assertCreated();
    $lock = fn () => ['If-Match' => (string) $this->agency('AGB-01')->lock_version];
    $this->as('admin')->withHeaders($lock())->patchJson('/api/v1/channels/b2b/agencies/AGB-01', ['credit_limit' => 1000])->assertOk()
        ->assertJsonPath('data.effective_status', 'bloqueada')->assertJsonPath('data.block_reason', 'credito_excedido')
        ->assertJsonPath('meta.warnings.0', fn ($w) => str_contains($w, 'B2B-BR-08'));
    $this->qPost('admin', 'channels/b2b/agencies/AGB-01/unblock', ['reason' => 'pago'], $lock())->assertStatus(409)->assertJsonPath('code', 'B2B-BR-07');
    expect(collect($this->auditRows('Agencia B2B', 'AGB-01'))->pluck('action')->all())->toContain('cambiar_limite_credito');
    $this->qPost('admin', 'channels/b2b/agencies/AGB-01/block', [], $lock())->assertStatus(422);
    // Ficha: tarifas asignadas, últimas reservas y libro de crédito.
    $this->qGet('admin', 'channels/b2b/agencies/AGB-01')->assertOk()->assertJsonCount(1, 'data.rates')->assertJsonCount(1, 'data.reservations');
    $this->qGet('admin', 'channels/b2b/agencies/AGB-01/credit-movements')->assertOk()->assertJsonPath('data.0.kind', 'cargo_reserva');
});

it('T09: convenio vencido o agencia inactiva no reserva ni con prepago; inactiva no entra al portal', function () {
    $this->admin()->table('channel_b2b_agencies')->where('id', $this->agencies['AGB-01']->id)->update(['valid_to' => now()->subDays(2)->toDateString()]);
    $this->b2bReserve('b2b1', ['pay_mode' => 'prepago'])->assertStatus(422)->assertJsonPath('code', 'RT-14');
    $this->qGet('b2b1', 'b2b/me')->assertOk()->assertJsonPath('data.status', 'vencido');
    $this->admin()->table('channel_b2b_agencies')->where('id', $this->agencies['AGB-01']->id)->update(['valid_to' => now()->addYear()->toDateString(), 'status' => 'inactiva']);
    $this->qGet('b2b1', 'b2b/me')->assertForbidden()->assertJsonPath('code', 'B2B-BR-02');
});

it('T10: el pago aplicado por Finanzas libera el crédito sin doble conteo con "Crédito B2B"', function () {
    $code = $this->b2bReserve()->assertCreated()->json('data.code');
    $cxc = $this->admin()->table('fin_receivables')->where('reservation_id', $this->admin()->table('booking_reservations')->where('code', $code)->value('id'))->first();
    // Con la reserva ya cargada a la línea, cobrar con "Crédito B2B" no vuelve a sumar.
    $this->fPost('finance', 'payments', ['receivable_id' => $cxc->id, 'amount' => '100.00', 'method' => 'Crédito B2B'])->assertCreated();
    expect($this->agency('AGB-01')->credit_used)->toBe('2400.00');
    $report = $this->qPost('b2b1', "b2b/receivables/{$cxc->id}/payment-reports", ['amount' => 200, 'method' => 'Transferencia BCP', 'operation_number' => 'OP-9'], $this->idem())
        ->assertCreated()->assertJsonPath('data.status_label', 'Por validar')->json('data');
    expect($this->agency('AGB-01')->credit_used)->toBe('2400.00');
    $this->fPost('finance', "payments/{$report['code']}/validate", ['apply' => true])->assertOk();
    // USD 200 × 3.75 = PEN 750 liberados.
    expect($this->agency('AGB-01')->credit_used)->toBe('1650.00')
        ->and($this->admin()->table('channel_b2b_credit_movements')->where('agency_id', $this->agencies['AGB-01']->id)->pluck('movement_type')->all())->toContain('pago_aplicado');
});

it('T22: Idempotency-Key repetida devuelve la misma reserva sin duplicar', function () {
    $first = $this->b2bReserve('b2b1', [], 'same-key')->assertCreated()->json('data.code');
    $second = $this->b2bReserve('b2b1', [], 'same-key')->assertCreated()->json('data.code');
    expect($second)->toBe($first)->and($this->admin()->table('booking_reservations')->where('channel', 'B2B')->count())->toBe(1)
        ->and($this->agency('AGB-01')->credit_used)->toBe('2400.00');
});

it('B2B-BR-10: la tarifa por agencia no supera el precio público y marca el producto como B2B', function () {
    $this->as('admin')->putJson("/api/v1/channels/b2b/product-rates/{$this->product->ref}/agencies/AGB-02", ['price' => 400, 'valid_to' => now()->addMonths(6)->toDateString()])
        ->assertStatus(422)->assertJsonPath('code', 'B2B-BR-10');
    $this->as('admin')->putJson("/api/v1/channels/b2b/product-rates/{$this->product->ref}/agencies/AGB-02", ['price' => 340, 'valid_to' => now()->addMonths(6)->toDateString()])
        ->assertCreated();
    $row = collect($this->qGet('admin', 'channels/b2b/product-rates')->assertOk()->json('data'))->firstWhere('id', $this->product->id);
    expect(collect($row['assignments'])->pluck('agency_code')->sort()->values()->all())->toBe(['AGB-01', 'AGB-02'])->and($row['is_b2b'])->toBeTrue();
    $this->b2bReserve('b2b2', ['pay_mode' => 'prepago'])->assertCreated();
    $this->as('admin')->deleteJson("/api/v1/channels/b2b/product-rates/{$this->product->ref}/agencies/AGB-02")->assertNoContent();
    $this->b2bReserve('b2b2', ['pay_mode' => 'prepago'])->assertStatus(422)->assertJsonPath('code', 'RT-14');
});

it('B2B-BR-15: la liquidación de comisiones exige comisiones pendientes', function () {
    $this->qPost('b2b1', 'b2b/settlement-requests', [], $this->idem())->assertStatus(422)->assertJsonPath('code', 'B2B-BR-15');
    $this->b2bReserve()->assertCreated();
    $this->qPost('b2b1', 'b2b/settlement-requests', [], $this->idem())->assertCreated()->assertJsonPath('data.count', 1);
    $this->qPost('b2b1', 'b2b/settlement-requests', [], $this->idem())->assertStatus(422);
    $this->qGet('finance', 'channels/b2b/settlement-requests')->assertOk()->assertJsonCount(1, 'data');
});

it('alta de agencia: valida RUC, comisión y vigencias; crea el cliente CRM vinculado', function () {
    $base = ['name' => 'Nueva', 'ruc' => '123', 'contact_name' => 'X', 'booking_email' => 'n@n.test', 'commission_pct' => 10, 'credit_limit' => 0,
        'price_list_id' => $this->agency('AGB-01')->price_list_id, 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31'];
    $this->qPost('admin', 'channels/b2b/agencies', $base)->assertStatus(422)->assertJsonValidationErrors('ruc');
    $this->qPost('admin', 'channels/b2b/agencies', ['ruc' => '20600000001', 'commission_pct' => 40] + $base)->assertStatus(422)->assertJsonValidationErrors('commission_pct');
    $this->qPost('admin', 'channels/b2b/agencies', ['ruc' => '20600000001', 'valid_to' => '2025-12-31'] + $base)->assertStatus(422)->assertJsonValidationErrors('valid_to');
    $a = $this->qPost('admin', 'channels/b2b/agencies', ['ruc' => '20600000001'] + $base)->assertCreated()->assertJsonPath('data.code', 'AGB-06')->json('data');
    expect($this->fresh('crm_clients', $a['client_id'])->doc_number)->toBe('20600000001');
});
