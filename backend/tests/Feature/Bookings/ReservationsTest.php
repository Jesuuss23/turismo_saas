<?php

use App\Services\Bookings\ReceivableService;
use App\Services\Catalog\SeasonService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->reservationWorld();
});

it('T-R01: convertir → confirmada, servicios desglosados (Σ exacto), CxC 40/60, oportunidad ganada, cotización convertida y cupos', function () {
    $opp = $this->makeOpportunity('u03', $this->clients['u03']);
    $quote = $this->approvedQuote('u03', ['opportunity_id' => $opp->id]);

    $preview = $this->qGet('u03', "quotes/{$quote['code']}/conversion-preview")->assertOk()->json('data');
    expect($preview['can_convert'])->toBeTrue(json_encode($preview))->and($preview['items'][0]['ok'])->toBeTrue();

    $res = $this->convert('u03', $quote['code'])->assertCreated()->json('data');
    expect($res['code'])->toBe('RES-'.now()->year.'-000350')
        ->and($res['status'])->toBe('confirmada')
        ->and($res['total'])->toBe('896.80')
        ->and($res['quote'])->toBe(['code' => $quote['code'], 'version_no' => 1])
        ->and(collect($res['passengers'])->pluck('full_name')->all())->toBe(['Pasajero ADT 1 (por completar)', 'Pasajero ADT 2 (por completar)']);
    $services = collect($res['services']);
    expect($services->pluck('cost')->all())->toBe(['280.00', '160.00'])
        ->and($services->pluck('price')->all())->toBe(['483.64', '276.36'])
        ->and($services->pluck('status')->unique()->all())->toBe(['pendiente'])
        ->and($services->first()['service_time'])->toBe('06:00');

    $today = Carbon::parse(now('America/Lima')->toDateString());
    [$dueA, $dueB] = ReceivableService::dueDates($today, Carbon::parse($res['travel_from']));
    expect(collect($res['receivables'])->map(fn ($c) => [$c['amount'], $c['due_date']])->all())->toBe([['358.72', $dueA], ['538.08', $dueB]])
        ->and($dueA)->toBe($today->copy()->addDays(3)->toDateString())
        ->and($dueB)->toBe(Carbon::parse($res['travel_from'])->subDays(15)->toDateString());
    expect($this->fresh('quote_quotes', $quote['id']))->status->toBe('convertida')->reservation_id->toBe($res['id']);
    expect($this->fresh('crm_opportunities', $opp->id)->stage)->toBe('ganada');
    expect($this->sold())->toBe(2);
    expect(collect($this->auditRows('Reserva', $res['code']))->pluck('action')->all())->toContain('crear_desde_cotizacion');
    $this->qGet('u03', "quotes/{$quote['code']}")->assertJsonPath('data.reservation_code', $res['code']);
});

it('T-R02: idempotencia — misma key repite la respuesta; otra key devuelve 200 con la reserva existente; UNIQUE parcial en DB', function () {
    $quote = $this->approvedQuote();
    $first = $this->convert('u03', $quote['code'], 'conv-key-1')->assertCreated();
    $again = $this->qPost('u03', "quotes/{$quote['code']}/convert", [], ['If-Match' => '999', 'Idempotency-Key' => 'conv-key-1'])->assertCreated();
    expect($again->headers->get('Idempotent-Replayed'))->toBe('true')->and($again->json('data.code'))->toBe($first->json('data.code'));

    $other = $this->convert('manager', $quote['code'])->assertOk();
    expect($other->json('data.code'))->toBe($first->json('data.code'))->and($other->json('meta.created'))->toBeFalse();
    expect($this->admin()->table('booking_reservations')->where('quote_id', $quote['id'])->count())->toBe(1)
        ->and(collect($this->auditRows('Cotización', $quote['code']))->pluck('action')->all())->toContain('convertir_idempotente');

    $row = (array) $this->admin()->table('booking_reservations')->where('quote_id', $quote['id'])->first();
    expect(fn () => $this->admin()->table('booking_reservations')->insert(['id' => (string) Str::uuid7(), 'code' => 'RES-X'] + Arr::except($row, ['id', 'code'])))
        ->toThrow(QueryException::class);
});

it('T-R03: carrera de cupos — dos conversiones sobre una salida con cupo para una → 201 y 409 PRD-BR-012; sold ≤ capacity', function () {
    $this->admin()->table('catalog_product_departures')->where('id', $this->resDeparture->id)->update(['capacity' => 3]);
    $a = $this->approvedQuote('u03');
    $b = $this->approvedQuote('u04');

    $this->convert('manager', $a['code'])->assertCreated();
    $this->convert('manager', $b['code'])->assertStatus(409)->assertJsonPath('code', 'PRD-BR-012');
    expect($this->sold())->toBe(2)->and($this->fresh('quote_quotes', $b['id'])->status)->toBe('aprobada');
    $this->qGet('manager', "quotes/{$b['code']}/conversion-preview")->assertJsonPath('data.can_convert', false)->assertJsonPath('data.items.0.rule', 'PRD-BR-012');
});

it('T-R04: convertir una cotización enviada → 409 RT-3', function () {
    $quote = $this->makeQuote();
    $this->sendQuote('u03', $quote['code'])->assertOk();

    $this->convert('u03', $quote['code'])->assertStatus(409)->assertJsonPath('code', 'RT-3');
    expect($this->admin()->table('booking_reservations')->count())->toBe(0);
});

it('T-R05: reserva directa sin cupo con confirm=true → 409 RES-BR-05; confirm=false → pendiente_disponibilidad sin retener cupo', function () {
    $small = $this->makeDeparture($this->product, ['departure_date' => now()->addDays(30)->toDateString(), 'capacity' => 1]);
    $rate = $this->inTenant(fn () => $this->product->rates()->where('channel', 'Directo')->value('id'));
    $payload = ['client_id' => $this->clients['u03']->id, 'product_id' => $this->product->id, 'rate_id' => $rate, 'departure_id' => $small->id, 'adt' => 2];

    $check = $this->qPost('u03', 'reservations/availability-check', $payload)->assertOk()->json('data');
    expect($check['availability']['ok'])->toBeFalse()->and($check['total'])->toBe('760.00');
    $this->qPost('u03', 'reservations', $payload + ['confirm' => true], $this->idem())->assertStatus(409)->assertJsonPath('code', 'RES-BR-05');
    $res = $this->qPost('u03', 'reservations', $payload + ['confirm' => false], $this->idem())->assertCreated()->json('data');
    expect($res['status'])->toBe('pendiente_disponibilidad')->and($res['owner']['id'])->toBe($this->users['u03']->id)
        ->and($res['total'])->toBe('760.00')->and(count($res['receivables']))->toBe(2);
    expect($this->sold($small))->toBe(0);

    $ok = $this->qPost('u03', 'reservations', array_merge($payload, ['departure_id' => $this->resDeparture->id, 'adt' => 1, 'chd' => 1, 'confirm' => true]), $this->idem())->assertCreated()->json('data');
    expect($ok['status'])->toBe('confirmada')->and($this->sold())->toBe(2);
});

it('T-R06/T-R07: rechazo sin alternativa bloquea confirmar; con alternativa → pendiente_proveedor y al confirmarla vuelve a confirmada', function () {
    $res = $this->makeReservation();
    [$tren, $guia] = collect($res['services'])->pluck('code')->all();
    $this->rPost('manager', $res['code'], 'services/request-pending')->assertOk()->assertJsonPath('meta.requested', [$tren, $guia]);
    $this->rPost('manager', $res['code'], "services/{$guia}/confirm", [])->assertStatus(422);
    $this->rPost('manager', $res['code'], "services/{$guia}/confirm", ['confirmation_code' => 'G-1'])->assertOk();

    $this->rPost('manager', $res['code'], "services/{$tren}/reject", ['reason' => 'Sin disponibilidad'])->assertStatus(422);
    $after = $this->rPost('manager', $res['code'], "services/{$tren}/reject", ['reason' => 'Sin disponibilidad', 'detail' => 'Tren lleno'])->assertOk()->json('data');
    expect($after['status'])->toBe('pendiente_proveedor');
    $this->rPost('manager', $res['code'], 'confirm', [], $this->resLock($res['code']))->assertStatus(409)->assertJsonPath('code', 'RES-BR-07');

    // Otra reserva: rechazo con alternativa (mismo proveedor, otra fecha) y confirmación automática.
    $res2 = $this->makeReservation('u04');
    [$tren2, $guia2] = collect($res2['services'])->pluck('code')->all();
    $this->rPost('manager', $res2['code'], 'services/request-pending')->assertOk();
    $this->rPost('manager', $res2['code'], "services/{$guia2}/confirm", ['confirmation_code' => 'G-2'])->assertOk();
    $data = $this->rPost('manager', $res2['code'], "services/{$tren2}/reject", ['reason' => 'Sin disponibilidad', 'detail' => 'Lleno',
        'alternative_provider_id' => 'same', 'alternative_date' => now()->addDays(22)->toDateString()])->assertOk()->json('data');
    $alt = collect($data['services'])->firstWhere('alt_of', collect($data['services'])->firstWhere('code', $tren2)['id']);
    expect($data['status'])->toBe('pendiente_proveedor')->and($alt['status'])->toBe('pendiente');
    $this->rPost('manager', $res2['code'], "services/{$alt['code']}/request")->assertOk();
    $done = $this->rPost('manager', $res2['code'], "services/{$alt['code']}/confirm", ['confirmation_code' => 'T-9', 'time' => '07:30'])->assertOk()->json('data');
    expect($done['status'])->toBe('confirmada');
    expect(collect($this->auditRows('Servicio reserva', $tren2))->pluck('action')->all())->toBe(['solicitar_proveedor', 'rechazo_proveedor']);
});

it('T-R08: modificación de Operaciones queda pendiente; Gerencia aprueba (total, CxC, cupos); delta negativo reduce CxC', function () {
    $res = $this->makeReservation();
    $preview = $this->rPost('ops', $res['code'], 'changes/preview', ['type' => 'pax', 'extra_pax' => 1])->assertOk()->json('data');
    expect($preview['suggested_delta'])->toBe('448.40')->and($preview['availability']['ok'])->toBeTrue();

    $pending = $this->rPost('ops', $res['code'], 'changes', ['type' => 'pax', 'extra_pax' => 1, 'description' => 'Se suma un amigo', 'apply_now' => true], $this->resLock($res['code']))
        ->assertCreated()->json();
    expect($pending['meta']['change']['status'])->toBe('pendiente')->and($pending['data']['total'] ?? null)->toBeNull();
    $this->rPost('ops', $res['code'], "changes/{$pending['meta']['change']['id']}/approve", [], $this->resLock($res['code']))->assertForbidden();

    $applied = $this->rPost('manager', $res['code'], "changes/{$pending['meta']['change']['id']}/approve", [], $this->resLock($res['code']))->assertOk()->json('data');
    expect($applied['total'])->toBe('1345.20')->and(count($applied['passengers']))->toBe(3)
        ->and(collect($applied['services'])->pluck('pax')->unique()->all())->toBe([3])
        ->and(collect($applied['receivables'])->last())->toMatchArray(['amount' => '448.40'])
        ->and($this->sold())->toBe(3);

    $credit = $this->rPost('manager', $res['code'], 'changes', ['type' => 'otro', 'description' => 'Descuento por demora', 'delta_amount' => -100, 'apply_now' => true],
        $this->resLock($res['code']))->assertCreated()->json('data');
    expect($credit['total'])->toBe('1245.20')->and($credit['receivables'][0]['amount'])->toBe('258.72');
});

it('T-R09: reprogramación con diferencia por temporada, salidas liberadas/retenidas y servicios reiniciados; bloqueada con servicio completado', function () {
    $res = $this->makeReservation();
    $next = $this->makeDeparture($this->product, ['departure_date' => now()->addDays(28)->toDateString(), 'capacity' => 10]);
    $this->inTenant(fn () => app(SeasonService::class)->create(['name' => 'Baja diciembre', 'type' => 'Baja', 'priority' => 1, 'factor' => 0.88,
        'date_from' => now()->addDays(25)->toDateString(), 'date_to' => now()->addDays(35)->toDateString()], $this->users['admin']));
    $newStart = Carbon::parse($res['travel_from'])->addDays(7)->toDateString();
    $tren = $res['services'][0]['code'];
    $this->rPost('manager', $res['code'], 'services/request-pending')->assertOk();
    $this->rPost('manager', $res['code'], "services/{$tren}/confirm", ['confirmation_code' => 'X1'])->assertOk();

    $preview = $this->rPost('manager', $res['code'], 'reschedule/preview', ['new_start' => $newStart])->assertOk()->json('data');
    expect($preview['diff'])->toBe('-91.20')->and($preview['can_apply'])->toBeTrue()->and($preview['lines'][0]['departure']['ref'])->toBe($next->ref);
    $this->rPost('manager', $res['code'], 'reschedule', ['new_start' => $newStart], $this->resLock($res['code']) + $this->idem())->assertStatus(422);
    $done = $this->rPost('manager', $res['code'], 'reschedule', ['new_start' => $newStart, 'reason' => 'Cambio de vuelo'], $this->resLock($res['code']) + $this->idem())
        ->assertOk()->json('data');
    expect($done['status'])->toBe('reprogramada')->and($done['travel_from'])->toBe($newStart)->and($done['total'])->toBe('805.60')
        ->and(collect($done['services'])->pluck('status')->unique()->all())->toBe(['pendiente'])
        ->and(collect($done['services'])->pluck('confirmation_code')->filter()->all())->toBe([])
        ->and(collect($done['services'])->pluck('price')->all())->toBe(['425.60', '243.20'])
        ->and($done['services'][0]['name'])->toContain($next->ref)
        ->and($this->sold())->toBe(0)->and($this->sold($next))->toBe(2)
        ->and(collect($done['changes'])->first()['type'])->toBe('reprogramacion');

    $this->admin()->table('booking_services')->where('code', $done['services'][1]['code'])->update(['status' => 'completado']);
    $this->rPost('manager', $res['code'], 'reschedule/preview', ['new_start' => Carbon::parse($newStart)->addDay()->toDateString()])
        ->assertOk()->assertJsonPath('data.can_apply', false);
    $this->rPost('manager', $res['code'], 'reschedule', ['new_start' => Carbon::parse($newStart)->addDay()->toDateString(), 'reason' => 'otra'],
        $this->resLock($res['code']) + $this->idem())->assertStatus(409)->assertJsonPath('code', 'RES-BR-20');
});

it('T-R10: cancelación dentro de 7 días → penalidad 100 % con factor k, CxC de penalidad, cupos liberados, CxC abiertas canceladas y auditoría', function () {
    $res = $this->makeReservation();
    $this->admin()->table('booking_services')->where('reservation_id', $res['id'])->update(['service_date' => now('America/Lima')->addDays(3)->toDateString()]);

    $preview = $this->rPost('manager', $res['code'], 'cancellation/preview')->assertOk()->json('data');
    expect($preview)->toMatchArray(['penalty' => '896.80', 'refund' => '0.00', 'owed' => '896.80', 'penalty_services' => '760.00'])
        ->and(collect($preview['lines'])->pluck('pct')->all())->toBe([100, 100]);
    $this->rPost('manager', $res['code'], 'cancel', ['reason' => 'Salud'], $this->resLock($res['code']) + $this->idem())->assertStatus(422);
    $done = $this->rPost('manager', $res['code'], 'cancel', ['reason' => 'Salud', 'detail' => 'Hospitalización'], $this->resLock($res['code']) + $this->idem())
        ->assertOk()->json('data');

    expect($done['status'])->toBe('cancelada')->and($done['cancel_reason'])->toBe('Salud: Hospitalización')
        ->and(collect($done['services'])->pluck('status')->unique()->all())->toBe(['cancelado'])
        ->and(collect($done['receivables'])->map(fn ($c) => [$c['status'], $c['amount']])->all())->toBe([['cancelada', '358.72'], ['cancelada', '538.08'], ['abierta', '896.80']])
        ->and($done['cancellation'])->toMatchArray(['penalty' => '896.80', 'owed' => '896.80'])
        ->and($this->sold())->toBe(0)
        ->and(collect($done['actions'])->pluck('key')->all())->not->toContain('cancel');
    expect(collect($this->auditRows('Reserva', $res['code']))->pluck('action')->all())->toContain('cancelar');
    $this->rPost('manager', $res['code'], 'cancel', ['reason' => 'Salud', 'detail' => 'x'], $this->resLock($res['code']) + $this->idem())->assertStatus(409);
});

it('T-R11: cancelación con pago facturado y sin penalidad → nota de crédito (vía OSE) + pago de reembolso pendiente', function () {
    $res = $this->makeReservation();
    $rid = $res['receivables'][0]['id'];
    $this->qPost('finance', 'finance/payments', ['receivable_id' => $rid, 'amount' => '358.72', 'method' => 'Transferencia BCP', 'reference' => 'OP-1'], $this->idem())
        ->assertCreated()->assertJsonPath('data.receivable.status', 'pagada');
    // Spec 07 §12 P-10: la NC acredita un comprobante aceptado de la reserva.
    $code = $this->admin()->table('fin_receivables')->where('id', $rid)->value('code');
    $draft = $this->qGet('finance', "finance/receivables/{$code}/invoice-draft")->assertOk()->json('data');
    $this->qPost('finance', 'finance/invoices', ['receivable_id' => $rid] + $draft, $this->idem())->assertStatus(202)->assertJsonPath('data.status', 'aceptado');

    $done = $this->rPost('admin', $res['code'], 'cancel', ['reason' => 'Cambio de planes', 'detail' => 'Posterga'], $this->resLock($res['code']) + $this->idem())->assertOk()->json('data');
    expect($done['cancellation'])->toMatchArray(['penalty' => '0.00', 'refund' => '358.72', 'owed' => '0.00'])
        ->and(collect($done['payments'])->firstWhere('kind', 'reembolso'))->toMatchArray(['status' => 'pendiente', 'amount' => '358.72']);
    $note = $this->admin()->table('fin_invoices')->where('reservation_id', $res['id'])->where('doc_type', '07')->first();
    expect($note->number)->toMatch('/^[FB]C01-\d{8}$/')->and($note->total_amount)->toBe('358.72')->and($note->status)->toBe('aceptado');
});

it('T-R13: Ventas — no ve reservas ajenas; 403 en confirmar/modificar/reprogramar/cancelar; solicita anulación solo en las propias', function () {
    $res = $this->makeReservation('u03');

    expect(collect($this->qGet('u04', 'reservations')->json('data'))->pluck('code'))->not->toContain($res['code']);
    $this->rGet('u04', $res['code'])->assertForbidden()->assertJsonPath('code', 'SCOPE_OWNERSHIP');
    expect(collect($this->qGet('u04', 'search?q='.$res['code'])->json('data'))->where('type', 'reservation'))->toBeEmpty();
    $this->rPost('u03', $res['code'], 'confirm', [], $this->resLock($res['code']))->assertForbidden();
    $this->rPost('u03', $res['code'], 'changes', ['type' => 'otro', 'description' => 'x'], $this->resLock($res['code']))->assertForbidden();
    $this->rPost('u03', $res['code'], 'reschedule', ['new_start' => now()->addDays(40)->toDateString(), 'reason' => 'x'], $this->resLock($res['code']) + $this->idem())->assertForbidden();
    $this->rPost('u03', $res['code'], 'cancel', ['reason' => 'x', 'detail' => 'y'], $this->resLock($res['code']) + $this->idem())->assertForbidden();

    $detail = $this->rGet('u03', $res['code'])->assertOk()->json('data');
    expect(collect($detail['actions'])->firstWhere('key', 'request_cancel')['enabled'])->toBeTrue()
        ->and(collect($detail['actions'])->firstWhere('key', 'confirm')['enabled'])->toBeFalse()
        ->and($detail['view']['see_margin'])->toBeTrue();
    $this->rPost('u04', $res['code'], 'cancel-requests', ['reason' => 'Salud', 'detail' => 'x'])->assertForbidden();
    $this->rPost('u03', $res['code'], 'cancel-requests', ['reason' => 'Salud', 'detail' => 'Fractura'])->assertCreated()
        ->assertJsonPath('data.cancel_request.reason', 'Salud: Fractura');
    $this->rPost('u03', $res['code'], 'cancel-requests', ['reason' => 'Salud', 'detail' => 'otra vez'])->assertStatus(409)->assertJsonPath('code', 'RES-BR-33');
    $rid = $this->admin()->table('booking_cancel_requests')->value('id');
    $this->rPost('manager', $res['code'], "cancel-requests/{$rid}/reject", ['note' => 'Aún hay tiempo'])->assertOk()->assertJsonPath('data.cancel_request', null);
    expect($this->admin()->table('notif_messages')->where('event', 'reserva.anulacion_rechazada')->count())->toBe(1);
});

it('T-R14: Operaciones — sin total, precios ni margen; ve costos; 403 en cancelar y crear', function () {
    $res = $this->makeReservation();
    $detail = $this->rGet('ops', $res['code'])->assertOk()->json('data');

    expect($detail)->not->toHaveKeys(['total', 'balance', 'margin_pct', 'paid'])
        ->and($detail['provider_cost'])->toBe('440.00')
        ->and($detail['services'][0])->toHaveKey('cost')->not->toHaveKey('price')
        ->and($detail['receivables'])->toBeNull()
        ->and($detail['view']['ops_view'])->toBeTrue();
    $row = collect($this->qGet('ops', 'reservations')->json('data'))->firstWhere('code', $res['code']);
    expect($row)->not->toHaveKey('total')->toHaveKey('provider_cost');
    $this->rPost('ops', $res['code'], 'cancel', ['reason' => 'x', 'detail' => 'y'], $this->resLock($res['code']) + $this->idem())->assertForbidden();
    $this->qPost('ops', 'reservations', [], $this->idem())->assertForbidden();

    $acc = $this->rGet('accounting', $res['code'])->assertOk()->json('data');
    expect($acc)->toHaveKey('total')->not->toHaveKey('provider_cost')->and($acc['services'][0])->not->toHaveKey('cost')->and($acc['view']['acc'])->toBeTrue();
});

it('T-R15: pago rápido — monto > saldo → 422; el pago que salda todas las CxC de una reserva pendiente_pago la confirma', function () {
    $res = $this->makeReservation();
    [$a, $b] = $res['receivables'];
    $this->qPost('finance', 'finance/payments', ['receivable_id' => $a['id'], 'amount' => '400', 'method' => 'Transferencia BCP', 'reference' => 'OP-9'], $this->idem())
        ->assertStatus(422)->assertJsonPath('code', 'PROC-042');
    $this->qPost('manager', 'finance/payments', ['receivable_id' => $a['id'], 'amount' => '10', 'method' => 'Efectivo'], $this->idem())->assertForbidden();
    $this->admin()->table('booking_reservations')->where('id', $res['id'])->update(['status' => 'pendiente_pago']);

    $this->qPost('finance', 'finance/payments', ['receivable_id' => $a['id'], 'amount' => '358.72', 'method' => 'Transferencia BCP', 'reference' => 'OP-10'], $this->idem())->assertCreated();
    expect($this->fresh('booking_reservations', $res['id'])->status)->toBe('pendiente_pago');
    $this->qPost('finance', 'finance/payments', ['receivable_id' => $b['id'], 'amount' => '538.00', 'method' => 'Tarjeta', 'reference' => 'VISA-1'], $this->idem())
        ->assertCreated()->assertJsonPath('data.receivable.status', 'parcial');
    expect($this->fresh('booking_reservations', $res['id'])->status)->toBe('pendiente_pago');
    // FIN-BR-15: se confirma cuando todas las CxC no canceladas quedan pagadas.
    $rest = $this->fresh('fin_receivables', $b['id']);
    $this->qPost('finance', 'finance/payments', ['receivable_id' => $b['id'], 'amount' => number_format($rest->amount - $rest->paid_amount, 2, '.', ''), 'method' => 'Efectivo'], $this->idem())
        ->assertCreated()->assertJsonPath('data.receivable.status', 'pagada');
    expect($this->fresh('booking_reservations', $res['id'])->status)->toBe('confirmada');
    expect(collect($this->auditRows('Reserva', $res['code']))->pluck('after_value')->all())->toContain('Confirmada (obligaciones pagadas)');
});

it('T-R16: aislamiento — el tenant B no ve reservas, holds ni CxC del tenant A', function () {
    $res = $this->makeReservation();
    $other = $this->makeTenant('inka');
    $token = $this->tokenFor($this->makeUser($other, 'tenant_admin'));

    expect($this->api($token)->getJson('/api/v1/reservations')->assertOk()->json('data'))->toBe([]);
    $this->api($token)->getJson("/api/v1/reservations/{$res['code']}")->assertNotFound();
    $this->api($token)->getJson("/api/v1/reservations/{$res['id']}")->assertNotFound();
    $this->api($token)->withHeaders($this->idem())->postJson('/api/v1/finance/payments', ['receivable_id' => $res['receivables'][0]['id'], 'amount' => 1, 'method' => 'x'])->assertNotFound();
    $this->api($token)->getJson('/api/v1/reservations/kpis')->assertOk()->assertJsonPath('data.confirmed', 0);
});

it('Reserva 360: pasajeros con tipo por edad (advertencia), titular único, CxC manual, reasignación y CRM', function () {
    $res = $this->makeReservation();
    $pid = $res['passengers'][0]['id'];
    $this->as('manager')->withHeaders($this->resLock($res['code']))->putJson("/api/v1/reservations/{$res['code']}/passengers/{$pid}", ['full_name' => 'Ana'])->assertStatus(422);
    $saved = $this->as('manager')->withHeaders($this->resLock($res['code']))->putJson("/api/v1/reservations/{$res['code']}/passengers/{$pid}", [
        'full_name' => 'Ana Gutiérrez', 'doc_number' => '44556677', 'nationality' => 'Peruana', 'birth_date' => now()->subYears(8)->toDateString(), 'pax_type' => 'ADT', 'is_lead' => true,
    ])->assertOk()->json();
    expect($saved['data'])->toMatchArray(['pax_type' => 'ADT', 'age' => 8, 'is_lead' => true, 'is_placeholder' => false])
        ->and($saved['meta']['warnings'][0])->toContain('corresponde CHD');

    $this->rPost('manager', $res['code'], 'reassign', ['owner_id' => $this->users['u04']->id], $this->resLock($res['code']))->assertOk()->assertJsonPath('data.owner.id', $this->users['u04']->id);
    $this->rPost('finance', $res['code'], 'receivables/generate', [], $this->idem())->assertStatus(409)->assertJsonPath('code', 'RES-BR-41');
    $this->rPost('u03', $res['code'], 'reassign', ['owner_id' => $this->users['u03']->id], $this->resLock($res['code']))->assertForbidden();

    $client = $this->clients['u03'];
    $tab = $this->as('manager')->getJson("/api/v1/crm/clients/{$client->code}/reservations")->assertOk()->json('data');
    expect($tab[0]['code'])->toBe($res['code']);
    $this->as('manager')->getJson("/api/v1/crm/clients/{$client->code}")->assertOk()->assertJsonPath('data.kpis.reservations_count', 1);
    $history = $this->rGet('manager', $res['code'], 'history')->assertOk()->json('data');
    expect(collect($history)->pluck('action')->all())->toContain('reasignar', 'crear_desde_cotizacion');
    $provider = collect($res['services'])->pluck('provider.id')->filter()->first();
    $this->as('finance')->getJson("/api/v1/catalog/providers/{$provider}")->assertOk()->assertJsonPath('data.reservations.0.code', $res['code']);
    $csv = $this->qPost('ops', 'exports', ['resource' => 'reservations.reservations'])->assertOk()->getContent();
    expect($csv)->toContain('Costo proveedores')->toContain($res['code'])->not->toContain('Saldo');
});
