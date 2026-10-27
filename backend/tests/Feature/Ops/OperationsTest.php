<?php

use App\Events\Ops\OperationClosed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->opsWorld();
});

it('T-01: otro tenant no lista ni abre órdenes, vouchers ni incidencias (RLS)', function () {
    $order = $this->makeOrder();
    $other = $this->makeTenant('inka');
    $ops = $this->makeUser($other, 'operations');
    $token = $this->tokenFor($ops);

    $this->api($token)->getJson('/api/v1/ops/orders')->assertOk()->assertJsonCount(0, 'data');
    $this->api($token)->getJson("/api/v1/ops/orders/{$order['code']}")->assertNotFound();
    $this->api($token)->getJson("/api/v1/ops/orders/{$order['id']}")->assertNotFound();
    $this->api($token)->getJson('/api/v1/ops/incidents')->assertOk()->assertJsonCount(0, 'data');
    $this->api($token)->getJson('/api/v1/ops/vouchers')->assertOk()->assertJsonCount(0, 'data');
});

it('T-02: Ventas/Finanzas/Contabilidad → 403; Operaciones sin rentabilidad no recibe venta ni margen', function () {
    $order = $this->makeOrder();
    foreach (['u03', 'finance', 'accounting'] as $who) {
        $this->oGet($who, 'orders')->assertForbidden();
        $this->oGet($who, "orders/{$order['code']}")->assertForbidden();
    }
    $cost = $this->oGet('ops', "orders/{$order['code']}/cost-summary")->assertOk()->json('data');
    expect($cost)->not->toHaveKey('sale')->not->toHaveKey('margin')->and($cost['can_see_margin'])->toBeFalse()->and($cost['cost'])->toBe('440.00');
    $manager = $this->oGet('manager', "orders/{$order['code']}/cost-summary")->assertOk()->json('data');
    expect($manager['sale'])->toBe('896.80')->and($manager['margin'])->toBe('456.80');
    // El 360 tampoco expone precio de venta por servicio.
    expect($this->oGet('ops', "orders/{$order['code']}")->json('data.services.0'))->not->toHaveKey('price');
});

it('T-03: reserva no confirmada → 422 PROC-031; segunda orden de la misma reserva → 409 OPS-BR-02', function () {
    $this->admin()->table('booking_reservations')->where('id', $this->res['id'])->update(['status' => 'pendiente_pago']);
    $this->oPost('ops', 'orders', ['reservation_id' => $this->res['id']], $this->idem())->assertStatus(422)->assertJsonPath('code', 'PROC-031');
    $this->admin()->table('booking_reservations')->where('id', $this->res['id'])->update(['status' => 'confirmada']);
    $this->makeOrder();
    $this->oPost('ops', 'orders', ['reservation_id' => $this->res['code'], 'coordinator_id' => $this->users['ops']->id], $this->idem())
        ->assertStatus(409)->assertJsonPath('code', 'OPS-BR-02');
    // OPS-BR-04: el coordinador debe ser de Operaciones, Gerencia o Admin.
    $this->oPost('ops', 'orders', ['reservation_id' => $this->res['id'], 'coordinator_id' => $this->users['u03']->id], $this->idem())->assertStatus(422);
});

it('T-04: la orden replica solo servicios que requieren recursos; sin ninguno queda planificada', function () {
    $order = $this->makeOrder();
    expect($order['status'])->toBe('pendiente_asignacion')->and($order['assignments_total'])->toBe(1)
        ->and(collect($order['services'])->pluck('kind')->sort()->values()->all())->toBe(['tour', 'tren_entrada'])
        ->and(collect($order['tasks']))->toHaveCount(5);
    expect(collect($this->auditRows('Orden operativa', $order['code']))->pluck('action')->all())->toContain('crear');

    $second = $this->makeReservation('u03');
    $this->admin()->table('booking_services')->where('reservation_id', $second['id'])->update(['requires_assignment' => false]);
    $planned = $this->makeOrder($second['id']);
    expect($planned['status'])->toBe('planificada')->and($planned['assignments_total'])->toBe(0);
});

it('T-05: mark-assigned con pendientes → 409; confirmar con servicio solicitado → 409 con detalle', function () {
    $order = $this->makeOrder();
    $this->oPost('ops', "orders/{$order['code']}/mark-assigned", [], $this->orderLock($order['code']))->assertStatus(409)->assertJsonPath('code', 'OPS-BR-05');
    $a = $this->assignmentOf($order['code']);
    $this->assign($a->id, ['guide_id' => $this->rsc['g1']->id])->assertOk()->assertJsonPath('data.status', 'asignada');
    $tren = $this->admin()->table('booking_services')->where('reservation_id', $this->res['id'])->where('service_kind', 'tren_entrada')->value('id');
    $this->oPost('ops', "reservation-services/{$tren}/supplier-request", [], $this->idem())->assertOk();
    $res = $this->oPost('ops', "orders/{$order['code']}/confirm", [], $this->orderLock($order['code']))->assertStatus(409);
    expect($res->json('issues'))->toContain('1 asignación(es) sin confirmar por el recurso')->toContain('2 servicio(s) pendientes o solicitados al proveedor');
});

it('T-06: guía en otra orden a < 6 h → 409; misma orden → advertencia; sin ack → 422 OPS-BR-28', function () {
    $o1 = $this->makeOrder();
    $second = $this->makeReservation('u04');
    $o2 = $this->makeOrder($second['id']);
    $a1 = $this->assignmentOf($o1['code']);
    $a2 = $this->assignmentOf($o2['code']);
    $this->admin()->table('ops_assignments')->whereIn('id', [$a1->id, $a2->id])->update(['service_time' => '08:00']);
    $this->assign($a1->id, ['guide_id' => $this->rsc['g1']->id])->assertOk();
    $blocked = $this->assign($a2->id, ['guide_id' => $this->rsc['g1']->id])->assertStatus(409)->assertJsonPath('code', 'OPS-BR-21');
    expect($blocked->json('blocks.0.text'))->toContain('menos de 6 h');

    // Misma orden: segunda asignación del mismo guía a 3 h → solo advertencia; sin ack → 422.
    $svc = $this->admin()->table('booking_services')->where('reservation_id', $this->res['id'])->where('service_kind', 'tren_entrada')->value('id');
    $this->admin()->table('booking_services')->where('id', $svc)->update(['requires_assignment' => true]);
    $this->oPost('ops', "orders/{$o1['code']}/assignments", ['reservation_service_id' => $svc])->assertCreated();
    $extra = $this->admin()->table('ops_assignments')->where('reservation_service_id', $svc)->first();
    $this->admin()->table('ops_assignments')->where('id', $extra->id)->update(['service_time' => '11:00']);
    $dry = $this->oPost('ops', "assignments/{$extra->id}/validate", ['guide_id' => $this->rsc['g1']->id])->assertOk()->json('data');
    expect($dry['blocks'])->toBe([])->and(collect($dry['warnings'])->pluck('code')->all())->toContain('OPS-BR-22');
    $this->assign($extra->id, ['guide_id' => $this->rsc['g1']->id], false)->assertStatus(422)->assertJsonPath('code', 'OPS-BR-28');
    $ok = $this->assign($extra->id, ['guide_id' => $this->rsc['g1']->id])->assertOk();
    expect($this->fresh('ops_assignments', $extra->id)->warnings_ack)->toContain('OPS-BR-22');
    expect($ok->json('meta.assignment.status'))->toBe('asignada');
});

it('T-07: licencia vencida, capacidad, SOAT, mantenimiento y vacaciones bloquean', function () {
    $order = $this->makeOrder();
    $a = $this->assignmentOf($order['code']);
    $date = $a->service_date;
    $this->admin()->table('ops_drivers')->where('id', $this->rsc['c1']->id)->update(['license_expires_on' => now()->subDay()->toDateString()]);
    $this->assign($a->id, ['driver_id' => $this->rsc['c1']->id])->assertStatus(409)->assertJsonPath('code', 'OPS-BR-26');
    $this->assign($a->id, ['vehicle_id' => $this->rsc['v2']->id])->assertStatus(409)->assertJsonPath('code', 'OPS-BR-27');
    $this->admin()->table('ops_vehicles')->where('id', $this->rsc['v1']->id)->update(['soat_expires_on' => now()->subDay()->toDateString()]);
    $res = $this->assign($a->id, ['vehicle_id' => $this->rsc['v1']->id])->assertStatus(409);
    expect($res->json('blocks.0.text'))->toContain('SOAT');
    $this->admin()->table('ops_vehicles')->where('id', $this->rsc['v1']->id)->update(['soat_expires_on' => now()->addYear()->toDateString(), 'status' => 'mantenimiento']);
    expect($this->assign($a->id, ['vehicle_id' => $this->rsc['v1']->id])->assertStatus(409)->json('blocks.0.text'))->toContain('mantenimiento');
    $this->admin()->table('ops_guides')->where('id', $this->rsc['g2']->id)->update(['status' => 'vacaciones']);
    $this->assign($a->id, ['guide_id' => $this->rsc['g2']->id])->assertStatus(409)->assertJsonPath('code', 'OPS-BR-24');
    expect($date)->not->toBeNull();
});

it('T-08: asignación concurrente del mismo recurso y fecha → 409 RESOURCE_LOCKED (lock por recurso)', function () {
    $order = $this->makeOrder();
    $a = $this->assignmentOf($order['code']);
    $lock = Cache::lock("ops:resource:guide:{$this->rsc['g1']->id}:{$a->service_date}", 15);
    expect($lock->get())->toBeTrue();
    $this->assign($a->id, ['guide_id' => $this->rsc['g1']->id])->assertStatus(409)->assertJsonPath('error', 'RESOURCE_LOCKED');
    $lock->release();
    $this->assign($a->id, ['guide_id' => $this->rsc['g1']->id])->assertOk();
});

it('T-09: liberar → asignación pendiente, orden recalculada y WhatsApp encolado', function () {
    $order = $this->makeOrder();
    $a = $this->assignmentOf($order['code']);
    $this->admin()->table('ops_guides')->where('id', $this->rsc['g1']->id)->update(['phone' => '+51 984 000 111']);
    $this->assign($a->id, ['guide_id' => $this->rsc['g1']->id])->assertOk()->assertJsonPath('data.status', 'asignada');
    $this->oPost('ops', "assignments/{$a->id}/release", [], $this->lockOf('ops_assignments', $a->id))->assertOk()->assertJsonPath('data.status', 'pendiente_asignacion');
    expect($this->fresh('ops_assignments', $a->id))->status->toBe('pendiente')->guide_id->toBeNull();
    expect($this->admin()->table('notif_messages')->where('event', 'asignacion.liberada')->where('channel', 'whatsapp')->exists())->toBeTrue();
    // Push al guía vinculado al asignarlo.
    expect($this->admin()->table('notif_messages')->where('event', 'asignacion.nueva')->where('user_id', $this->users['guide']->id)->exists())->toBeTrue();
});

it('T-10: rooming — conflictos, capacidad, duplicados, solape con otra reserva, modificada al editar y enviar sin confirmar', function () {
    $this->makeOrder();
    $pax = $this->admin()->table('booking_passengers')->where('reservation_id', $this->res['id'])->orderBy('seq')->pluck('id')->all();
    $list = $this->oPost('ops', 'rooming-lists', ['reservation_id' => $this->res['code'], 'hotel_provider_id' => $this->hotel->id,
        'check_in' => now()->addDays(20)->toDateString(), 'check_out' => now()->addDays(23)->toDateString()])->assertCreated()->json('data');
    $lock = fn () => $this->lockOf('ops_rooming_lists', $list['id']);
    $this->oPost('ops', "rooming-lists/{$list['id']}/send", [], $lock() + $this->idem())->assertStatus(409)->assertJsonPath('code', 'OPS-BR-34');
    $this->oPost('ops', "rooming-lists/{$list['id']}/rooms", ['room_number' => '101', 'room_type' => 'Simple', 'passenger_ids' => $pax], $lock())
        ->assertStatus(409)->assertJsonPath('code', 'OPS-BR-32');
    $this->oPost('ops', "rooming-lists/{$list['id']}/rooms", ['room_number' => '101', 'room_type' => 'Doble', 'passenger_ids' => [$pax[0]]], $lock())->assertCreated();
    $this->oPost('ops', "rooming-lists/{$list['id']}/rooms", ['room_number' => '101', 'room_type' => 'Simple', 'passenger_ids' => []], $lock())->assertStatus(409)->assertJsonPath('code', 'OPS-BR-31');
    $this->oPost('ops', "rooming-lists/{$list['id']}/rooms", ['room_number' => '102', 'room_type' => 'Simple', 'passenger_ids' => [$pax[0]]], $lock())
        ->assertStatus(409)->assertJsonPath('code', 'OPS-BR-30');
    $room = $this->oPost('ops', "rooming-lists/{$list['id']}/rooms", ['room_number' => '102', 'room_type' => 'Simple', 'passenger_ids' => [$pax[1]]], $lock())->assertCreated()->json('data');
    expect($room['validation']['conflicts'])->toBe([]);

    // Otra reserva en el mismo hotel y fechas superpuestas no puede usar la habitación 101.
    $other = $this->makeReservation('u04');
    $this->makeOrder($other['id']);
    $otherPax = $this->admin()->table('booking_passengers')->where('reservation_id', $other['id'])->value('id');
    $l2 = $this->oPost('ops', 'rooming-lists', ['reservation_id' => $other['id'], 'hotel_provider_id' => $this->hotel->id,
        'check_in' => now()->addDays(22)->toDateString(), 'check_out' => now()->addDays(24)->toDateString()])->assertCreated()->json('data');
    $this->oPost('ops', "rooming-lists/{$l2['id']}/rooms", ['room_number' => '101', 'room_type' => 'Doble', 'passenger_ids' => [$otherPax]], $this->lockOf('ops_rooming_lists', $l2['id']))
        ->assertStatus(409)->assertJsonPath('code', 'OPS-BR-31');

    $this->oPost('ops', "rooming-lists/{$list['id']}/confirm", [], $lock())->assertOk()->assertJsonPath('data.status', 'confirmada');
    $this->oPost('ops', "rooming-lists/{$list['id']}/send", [], $lock() + $this->idem())->assertOk()->assertJsonPath('data.status', 'enviada')->assertJsonPath('data.sent_version', 1);
    expect($this->admin()->table('notif_messages')->where('event', 'rooming.enviado')->value('recipient'))->toBe('reservas@intiwasi.test');
    $roomId = collect($room['rooms'])->firstWhere('room_number', '102')['id'];
    $this->oSend('PUT', 'ops', "rooming-rooms/{$roomId}", ['room_number' => '103', 'room_type' => 'Simple', 'passenger_ids' => [$pax[1]]], $lock())
        ->assertOk()->assertJsonPath('data.status', 'modificada');
});

it('T-11: vouchers — RT-11, duplicado 409, proveedor inactivo, versión con snapshot, anular sin motivo 422 y acciones sobre anulado 409', function () {
    $this->makeOrder();
    $tren = $this->admin()->table('booking_services')->where('reservation_id', $this->res['id'])->where('service_kind', 'tren_entrada')->value('id');
    $this->oPost('ops', 'vouchers', ['reservation_service_id' => $tren], $this->idem())->assertStatus(422)->assertJsonPath('code', 'RT-11');
    $this->confirmSuppliers();
    $v = $this->oPost('ops', 'vouchers', ['reservation_service_id' => $tren, 'note' => 'Llevar pasaporte'], $this->idem())->assertCreated()->json('data');
    expect($v['code'])->toStartWith('VOU-')->and($v['version'])->toBe(1)->and($v['qr_token'])->toContain($v['code'])
        ->and($v['document']['confirmation_code'])->toStartWith('CF-');
    $this->oPost('ops', 'vouchers', ['reservation_service_id' => $tren], $this->idem())->assertStatus(409)->assertJsonPath('code', 'OPS-BR-41');

    $v2 = $this->oPost('ops', "vouchers/{$v['id']}/versions", ['reason' => 'Cambio de horario'], $this->lockOf('ops_vouchers', $v['id']) + $this->idem())->assertCreated()->json('data');
    expect($v2['version'])->toBe(2)->and($v2['code'])->toBe($v['code'])->and($v2['versions'])->toHaveCount(2)
        ->and($this->admin()->table('ops_voucher_versions')->where('voucher_id', $v['id'])->where('version', 2)->value('snapshot'))->toContain('confirmation_code');

    $this->oPost('ops', "vouchers/{$v['id']}/void", [], $this->lockOf('ops_vouchers', $v['id']) + $this->idem())->assertStatus(422);
    $this->oPost('ops', "vouchers/{$v['id']}/void", ['reason' => 'Error de emisión'], $this->lockOf('ops_vouchers', $v['id']) + $this->idem())->assertOk()->assertJsonPath('data.status', 'anulado');
    $this->oPost('ops', "vouchers/{$v['id']}/redeem", [], $this->lockOf('ops_vouchers', $v['id']) + $this->idem())->assertStatus(409);

    $guia = $this->admin()->table('booking_services')->where('reservation_id', $this->res['id'])->where('service_kind', 'tour')->value('id');
    $this->admin()->table('catalog_providers')->where('id', $this->provider->id)->update(['contract_end' => now()->subDays(3)->toDateString()]);
    $this->oPost('ops', 'vouchers', ['reservation_service_id' => $guia], $this->idem())->assertStatus(422)->assertJsonPath('code', 'OPS-BR-42');
    // Operaciones con permiso; Finanzas no ve el módulo.
    $this->oGet('finance', "vouchers/{$v['id']}")->assertForbidden();
});

it('T-12: POST /ops/vouchers con la misma Idempotency-Key devuelve el mismo voucher', function () {
    $this->makeOrder();
    $this->confirmSuppliers();
    $tren = $this->admin()->table('booking_services')->where('reservation_id', $this->res['id'])->where('service_kind', 'tren_entrada')->value('id');
    $first = $this->oPost('ops', 'vouchers', ['reservation_service_id' => $tren], $this->idem('vou-1'))->assertCreated();
    $again = $this->oPost('ops', 'vouchers', ['reservation_service_id' => $tren], $this->idem('vou-1'))->assertCreated();
    expect($again->headers->get('Idempotent-Replayed'))->toBe('true')->and($again->json('data.code'))->toBe($first->json('data.code'))
        ->and($this->admin()->table('ops_vouchers')->count())->toBe(1);
});

it('T-13: incidencia crítica alerta a gerencia; cierre crítica sin evidencia 422; alta sin resolución 422; reabrir exige motivo', function () {
    $order = $this->makeOrder();
    $inc = $this->oPost('ops', 'incidents', ['order_id' => $order['code'], 'type' => 'Transporte', 'severity' => 'critica', 'title' => 'Bus averiado',
        'description' => 'Falla mecánica en ruta'], $this->idem())->assertCreated()->json('data');
    expect($inc['code'])->toStartWith('INC-')->and($inc['sla_hours'])->toBe(4)->and($inc['assignee']['id'])->toBe($this->users['ops']->id);
    $alerts = $this->admin()->table('notif_messages')->where('event', 'incidencia.critica')->pluck('channel')->all();
    expect($alerts)->toContain('push')->toContain('whatsapp');

    $lock = fn () => $this->lockOf('ops_incidents', $inc['id']);
    $this->oPost('ops', "incidents/{$inc['id']}/close", ['closing_comment' => 'ok'], $lock())->assertStatus(422)->assertJsonPath('code', 'OPS-BR-62');
    $this->oPost('ops', "incidents/{$inc['id']}/evidences", ['uploads' => [$this->pixel()]])->assertCreated();
    $this->oPost('ops', "incidents/{$inc['id']}/resolve", ['resolution' => 'Se envió unidad de reemplazo'], $lock())->assertOk()->assertJsonPath('data.status', 'resuelta');
    $this->oPost('ops', "incidents/{$inc['id']}/close", [], $lock())->assertOk()->assertJsonPath('data.status', 'cerrada');
    $this->oPost('ops', "incidents/{$inc['id']}/reopen", [], $lock())->assertStatus(422);
    $this->oPost('ops', "incidents/{$inc['id']}/reopen", ['reason' => 'El cliente reclama'], $lock())->assertOk()->assertJsonPath('data.status', 'en_proceso');

    $high = $this->oPost('ops', 'incidents', ['order_id' => $order['id'], 'type' => 'Proveedor', 'severity' => 'alta', 'title' => 'Hotel sobrevendido',
        'description' => 'Reubicar'], $this->idem())->assertCreated()->json('data');
    $this->oPost('ops', "incidents/{$high['id']}/close", [], $this->lockOf('ops_incidents', $high['id']))->assertStatus(422);
    $this->oPost('u03', 'incidents', ['order_id' => $order['id'], 'type' => 'Otro', 'severity' => 'baja', 'title' => 'x', 'description' => 'y'], $this->idem())->assertForbidden();
});

it('T-14: reclasificar recalcula sla_due_at desde el registro; el job marca vencidas', function () {
    $order = $this->makeOrder();
    $inc = $this->oPost('ops', 'incidents', ['order_id' => $order['id'], 'type' => 'Clima', 'severity' => 'baja', 'title' => 'Lluvia', 'description' => 'Ruta cerrada'],
        $this->idem())->assertCreated()->json('data');
    $created = $this->fresh('ops_incidents', $inc['id'])->created_at;
    $this->as('ops')->withHeaders($this->lockOf('ops_incidents', $inc['id']))
        ->patchJson("/api/v1/ops/incidents/{$inc['id']}/classification", ['severity' => 'alta', 'type' => 'Clima'])->assertOk()->assertJsonPath('data.sla_hours', 8);
    $due = Carbon::parse($this->fresh('ops_incidents', $inc['id'])->sla_due_at);
    expect((int) round(Carbon::parse($created)->diffInMinutes($due)))->toBe(480);

    $this->admin()->table('ops_incidents')->where('id', $inc['id'])->update(['sla_due_at' => now()->subMinute()]);
    $this->artisan('ops:monitor', ['mode' => 'sla'])->assertSuccessful();
    expect($this->fresh('ops_incidents', $inc['id'])->sla_breached_at)->not->toBeNull()
        ->and($this->admin()->table('notif_messages')->where('event', 'incidencia.sla_vencido')->exists())->toBeTrue();
    expect($this->oGet('ops', 'incidents')->json('meta.kpis.sla_breached'))->toBe(1);
});

it('T-15: cierre — cada precondición falla por separado y el éxito completa orden y reserva con OperationClosed', function () {
    Event::fake([OperationClosed::class]);
    $order = $this->makeOrder();
    $code = $order['code'];
    $a = $this->assignmentOf($code);
    $this->confirmSuppliers();
    $this->assign($a->id, ['guide_id' => $this->rsc['g1']->id])->assertOk();
    $this->oPost('ops', "assignments/{$a->id}/confirm", [], $this->lockOf('ops_assignments', $a->id))->assertOk()->assertJsonPath('data.status', 'confirmada');
    $this->oPost('ops', "orders/{$code}/close", [], $this->orderLock($code) + $this->idem())->assertStatus(409);
    $this->oPost('ops', "orders/{$code}/start", [], $this->orderLock($code))->assertOk()->assertJsonPath('data.status', 'en_ejecucion');
    expect($this->fresh('booking_reservations', $this->res['id'])->status)->toBe('en_operacion');

    $close = fn () => $this->oPost('ops', "orders/{$code}/close", [], $this->orderLock($code) + $this->idem());
    // 1) Servicios sin completar.
    $close()->assertStatus(422)->assertJsonPath('code', 'OPS-BR-80');
    $this->admin()->table('booking_services')->where('reservation_id', $this->res['id'])->update(['service_date' => now('America/Lima')->toDateString()]);
    foreach ($this->admin()->table('booking_services')->where('reservation_id', $this->res['id'])->pluck('id') as $id) {
        $this->oPost('ops', "reservation-services/{$id}/complete")->assertOk();
    }
    // 2) Sin evidencias.
    $close()->assertStatus(422)->assertJsonPath('code', 'OPS-BR-82');
    $this->oPost('ops', "orders/{$code}/evidences", ['uploads' => [$this->pixel()]], $this->idem())->assertCreated();
    // 3) Incidencia abierta.
    $inc = $this->oPost('ops', 'incidents', ['order_id' => $code, 'type' => 'Otro', 'severity' => 'baja', 'title' => 'Demora', 'description' => '10 min'], $this->idem())->json('data');
    $close()->assertStatus(422)->assertJsonPath('code', 'OPS-BR-81');
    $this->oPost('ops', "incidents/{$inc['id']}/close", ['closing_comment' => 'Sin impacto'], $this->lockOf('ops_incidents', $inc['id']))->assertOk();
    // 4) Servicio sin costo.
    $this->admin()->table('booking_services')->where('reservation_id', $this->res['id'])->limit(1)->update(['cost_amount' => 0]);
    $close()->assertStatus(422)->assertJsonPath('code', 'OPS-BR-83');
    $this->admin()->table('booking_services')->where('reservation_id', $this->res['id'])->update(['cost_amount' => 100]);

    $checks = $this->oGet('ops', "orders/{$code}/close-checks")->assertOk()->json('data');
    expect($checks['ready'])->toBeTrue()->and($checks['pending_tasks'])->toHaveCount(5);
    $this->oPost('u03', "orders/{$code}/close", [], $this->orderLock($code) + $this->idem())->assertForbidden();
    $close()->assertOk()->assertJsonPath('data.status', 'completada');
    expect($this->fresh('booking_reservations', $this->res['id'])->status)->toBe('completada');
    Event::assertDispatched(OperationClosed::class);
});

it('T-16: el guía no puede ver ni actuar sobre asignaciones de otro recurso (403) ni entrar al back-office', function () {
    $order = $this->makeOrder();
    $a = $this->assignmentOf($order['code']);
    // Ventana de la app: [-2 d, +14 d].
    $this->admin()->table('ops_assignments')->where('id', $a->id)->update(['service_date' => now()->addDays(3)->toDateString()]);
    $this->assign($a->id, ['guide_id' => $this->rsc['g1']->id])->assertOk();
    $this->qGet('guide2', "mobile/operator/assignments/{$a->id}")->assertForbidden();
    $this->mobileAction('guide2', 'confirm', $a->id)->assertForbidden();
    $this->qGet('guide', "mobile/operator/assignments/{$a->id}")->assertOk()->assertJsonPath('data.label', $a->service_label);
    $this->oGet('guide', 'orders')->assertForbidden();
    expect($this->qGet('guide2', 'mobile/operator/bootstrap')->assertOk()->json('data.assignments'))->toBe([]);
    $this->qGet('guide', 'mobile/operator/bootstrap')->assertOk()->assertJsonCount(1, 'data.assignments');
});

it('T-17: misma client_action_id dos veces → duplicate con la misma respuesta', function () {
    $order = $this->makeOrder();
    $a = $this->assignmentOf($order['code']);
    $this->assign($a->id, ['guide_id' => $this->rsc['g1']->id])->assertOk();
    $id = (string) Str::uuid();
    $first = $this->mobileAction('guide', 'confirm', $a->id, [], ['client_action_id' => $id])->assertOk()->json('data');
    expect($first['result'])->toBe('applied')->and($first['assignment']['status'])->toBe('confirmada');
    $again = $this->mobileAction('guide', 'confirm', $a->id, [], ['client_action_id' => $id])->assertOk()->json('data');
    expect($again['result'])->toBe('duplicate')->and($again['assignment'])->toEqual($first['assignment']);
    expect($this->admin()->table('ops_sync_actions')->count())->toBe(1);
    expect($this->fresh('ops_assignments', $a->id)->confirmed_via)->toBe('app');
});

it('T-18: check-in offline con marca anterior conserva la más temprana (conflict_resolved)', function () {
    $order = $this->makeOrder();
    $a = $this->assignmentOf($order['code']);
    $this->assign($a->id, ['guide_id' => $this->rsc['g1']->id])->assertOk();
    $this->mobileAction('guide', 'confirm', $a->id)->assertOk();
    $this->mobileAction('guide', 'start', $a->id)->assertOk()->assertJsonPath('data.result', 'applied');
    $pax = $this->admin()->table('booking_passengers')->where('reservation_id', $this->res['id'])->orderBy('seq')->value('id');
    $this->mobileAction('guide', 'checkin', $a->id, ['passenger_id' => $pax])->assertOk()->assertJsonPath('data.result', 'applied');
    $earlier = now()->subMinutes(20)->toIso8601String();
    $res = $this->mobileAction('guide', 'checkin', $a->id, ['passenger_id' => $pax], ['occurred_at' => $earlier, 'offline' => true])->assertOk()->json('data');
    expect($res['result'])->toBe('conflict_resolved')->and($res['conflict_rule'])->toBe('MOB-BR-06');
    $row = $this->admin()->table('ops_assignment_checkins')->where('assignment_id', $a->id)->first();
    expect(Carbon::parse($row->checked_at)->toIso8601String())->toBe(Carbon::parse($earlier)->toIso8601String())
        ->and($this->admin()->table('ops_assignment_checkins')->where('assignment_id', $a->id)->count())->toBe(1);
});

it('incidencia desde la app con foto adjunta: la foto queda como evidencia de la incidencia', function () {
    $order = $this->makeOrder();
    $a = $this->assignmentOf($order['code']);
    $this->assign($a->id, ['guide_id' => $this->rsc['g1']->id])->assertOk();
    $res = $this->mobileAction('guide', 'incident', $a->id, ['type' => 'Transporte', 'severity' => 'media', 'title' => 'Bus con retraso',
        'photo' => $this->pixel()])->assertOk()->json('data');
    expect($res['result'])->toBe('applied');
    $incident = $this->admin()->table('ops_incidents')->where('title', 'Bus con retraso')->first();
    expect($incident->source)->toBe('app_operador')
        ->and($this->admin()->table('ops_evidences')->where('incident_id', $incident->id)->count())->toBe(1)
        ->and($this->admin()->table('ops_sync_actions')->value('payload'))->not->toContain('content_base64');
});

it('T-19: finish sin check-in o sin evidencia → rejected; sobre asignación liberada → rejected', function () {
    $order = $this->makeOrder();
    $a = $this->assignmentOf($order['code']);
    $this->assign($a->id, ['guide_id' => $this->rsc['g1']->id])->assertOk();
    $this->mobileAction('guide', 'confirm', $a->id)->assertOk();
    $this->mobileAction('guide', 'start', $a->id)->assertOk();
    $finish = $this->mobileAction('guide', 'finish', $a->id)->assertOk()->json('data');
    expect($finish['result'])->toBe('rejected')->and($finish['conflict_rule'])->toBe('OPS-BR-51');
    $this->mobileAction('guide', 'checkin_all', $a->id)->assertOk()->assertJsonPath('data.result', 'applied');
    expect($this->mobileAction('guide', 'finish', $a->id)->json('data.result'))->toBe('rejected');
    $ev = $this->mobileAction('guide', 'evidence', $a->id, $this->pixel() + ['sha256' => hash('sha256', base64_decode($this->pixel()['content_base64']))])->assertOk()->json('data');
    expect($ev['result'])->toBe('applied')->and($ev['assignment']['evidences'])->toHaveCount(1);
    // Misma foto otra vez → MOB-BR-09.
    expect($this->mobileAction('guide', 'evidence', $a->id, $this->pixel())->json('data.conflict_rule'))->toBe('MOB-BR-09');

    // Liberada por Operaciones mientras el guía estaba offline → rejected (MOB-BR-10).
    $this->admin()->table('ops_assignments')->where('id', $a->id)->update(['status' => 'asignada']);
    $this->oPost('ops', "assignments/{$a->id}/release", [], $this->lockOf('ops_assignments', $a->id))->assertOk();
    $late = $this->mobileAction('guide', 'finish', $a->id)->assertOk()->json('data');
    expect($late['result'])->toBe('rejected')->and($late['conflict_rule'])->toBe('MOB-BR-10');
});

it('T-20: start desde la app pasa la orden a en_ejecucion y la reserva a en_operacion; finish completa el servicio', function () {
    $order = $this->makeOrder();
    $a = $this->assignmentOf($order['code']);
    $this->confirmSuppliers();
    $this->assign($a->id, ['guide_id' => $this->rsc['g1']->id])->assertOk();
    $this->mobileAction('guide', 'confirm', $a->id)->assertOk();
    expect($this->fresh('ops_orders', $order['id'])->status)->toBe('confirmada');
    $this->mobileAction('guide', 'start', $a->id)->assertOk();
    expect($this->fresh('ops_orders', $order['id'])->status)->toBe('en_ejecucion')
        ->and($this->fresh('booking_reservations', $this->res['id'])->status)->toBe('en_operacion');

    // Lote de sincronización (outbox): check-in de todos, evidencia y fin; una acción de otra asignación se rechaza sin cortar el lote.
    $base = now()->subMinutes(5);
    $batch = [
        ['client_action_id' => (string) Str::uuid(), 'type' => 'finish', 'assignment_id' => $a->id, 'occurred_at' => $base->copy()->addMinutes(3)->toIso8601String(), 'offline' => true],
        ['client_action_id' => (string) Str::uuid(), 'type' => 'checkin_all', 'assignment_id' => $a->id, 'occurred_at' => $base->toIso8601String(), 'offline' => true],
        ['client_action_id' => (string) Str::uuid(), 'type' => 'evidence', 'assignment_id' => $a->id, 'occurred_at' => $base->copy()->addMinute()->toIso8601String(), 'offline' => true, 'payload' => $this->pixel()],
    ];
    $results = $this->qPost('guide', 'mobile/operator/sync', ['actions' => $batch])->assertOk()->json('data');
    expect(collect($results)->pluck('type', 'result')->keys()->all())->toBe(['applied'])
        ->and(collect($results)->pluck('result')->all())->toBe(['applied', 'applied', 'applied']);
    expect($this->fresh('ops_assignments', $a->id)->status)->toBe('completada')
        ->and($this->admin()->table('booking_services')->where('id', $a->reservation_service_id)->value('status'))->toBe('completado');
    expect($this->admin()->table('ops_evidences')->where('captured_offline', true)->count())->toBe(1);
});

it('T-21: el pasajero solo ve su reserva; vouchers anulados no se devuelven', function () {
    $this->makeOrder();
    $this->confirmSuppliers();
    $services = $this->admin()->table('booking_services')->where('reservation_id', $this->res['id'])->pluck('id');
    $v1 = $this->oPost('ops', 'vouchers', ['reservation_service_id' => $services[0]], $this->idem())->assertCreated()->json('data');
    $v2 = $this->oPost('ops', 'vouchers', ['reservation_service_id' => $services[1]], $this->idem())->assertCreated()->json('data');
    $this->oPost('ops', "vouchers/{$v2['id']}/void", ['reason' => 'Duplicado'], $this->lockOf('ops_vouchers', $v2['id']) + $this->idem())->assertOk();

    $trip = $this->qGet('pax', 'mobile/passenger/trip')->assertOk()->json('data');
    expect($trip['reservation']['code'])->toBe($this->res['code'])->and($trip['emergency'])->toBe('+51 984 410 663');
    expect($this->qGet('pax', 'mobile/passenger/vouchers')->assertOk()->json('data.*.code'))->toBe([$v1['code']]);
    $this->qGet('pax', "mobile/passenger/vouchers/{$v2['code']}")->assertNotFound();
    $this->qGet('pax', "mobile/passenger/vouchers/{$v1['code']}")->assertOk()->assertJsonPath('data.qr_token', $v1['qr_token']);
    // ?reservation= no permite ver otra reserva siendo pasajero.
    $other = $this->makeReservation('u04');
    $this->qGet('pax', "mobile/passenger/trip?reservation={$other['code']}")->assertOk()->assertJsonPath('data.reservation.code', $this->res['code']);
    $this->qGet('pax', 'ops/orders')->assertForbidden();

    $this->qPost('pax', 'mobile/passenger/messages', ['body' => '¿A qué hora me recogen?', 'quick_reply' => true])->assertCreated()->assertJsonPath('data.sender', 'passenger');
    expect($this->admin()->table('notif_messages')->where('event', 'pasajero.mensaje')->where('user_id', $this->users['u03']->id)->exists())->toBeTrue();
    $this->qGet('pax', 'mobile/passenger/itinerary')->assertOk();

    // Sin vínculo: 404 TRIP_NOT_FOUND.
    $this->addUser('pax2', 'passenger');
    $this->qGet('pax2', 'mobile/passenger/trip')->assertNotFound()->assertJsonPath('error', 'TRIP_NOT_FOUND');
});

it('cancelar la reserva cancela la orden, libera recursos y anula vouchers vigentes', function () {
    $order = $this->makeOrder();
    $this->confirmSuppliers();
    $a = $this->assignmentOf($order['code']);
    $this->assign($a->id, ['guide_id' => $this->rsc['g1']->id])->assertOk();
    $tren = $this->admin()->table('booking_services')->where('reservation_id', $this->res['id'])->where('service_kind', 'tren_entrada')->value('id');
    $v = $this->oPost('ops', 'vouchers', ['reservation_service_id' => $tren], $this->idem())->assertCreated()->json('data');
    $this->rPost('manager', $this->res['code'], 'cancel', ['reason' => 'Salud', 'detail' => 'No viaja'], $this->resLock($this->res['code']) + $this->idem())->assertOk();
    expect($this->fresh('ops_orders', $order['id'])->status)->toBe('cancelada')
        ->and($this->fresh('ops_assignments', $a->id)->guide_id)->toBeNull()
        ->and($this->fresh('ops_vouchers', $v['id'])->status)->toBe('anulado');
    // La reserva enlaza a su orden.
    expect(collect($this->rGet('manager', $this->res['code'])->json('data.actions'))->firstWhere('key', 'operation_order')['target'])->toBe("/operaciones/ordenes/{$order['code']}");
});
