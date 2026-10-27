<?php

use App\Services\Catalog\SupplierServices;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('turismo.evidence_disk', 'local'));
    $this->supplierWorld();
});

it('S-01: el proveedor B no ve ni opera solicitudes, rooming, vouchers, CxP, incidencias ni tarifas del proveedor A', function () {
    $tren = $this->serviceOf();
    $this->requestService($tren);
    expect($this->sGet('prv', 'requests')->assertOk()->json('data'))->toHaveCount(2);
    $this->sGet('hotel', 'requests')->assertOk()->assertJsonCount(0, 'data');
    $this->sGet('hotel', "requests/{$tren}")->assertNotFound();
    $this->sPost('hotel', "requests/{$tren}/confirm", ['confirmation_code' => 'ABCD'])->assertNotFound();

    $this->sPost('prv', "requests/{$tren}/confirm", ['confirmation_code' => 'pr-7788'])->assertOk();
    $v = $this->issuedVoucher($tren);
    $this->sPost('hotel', 'vouchers/redeem', ['code' => $v['code']])->assertNotFound()->assertJsonPath('code', 'SUP-BR-30');
    $this->sGet('hotel', 'vouchers')->assertOk()->assertJsonCount(0, 'data');

    $payable = $this->admin()->table('fin_payables')->where('reservation_service_id', $tren)->first();
    expect($payable)->not->toBeNull();
    $this->sGet('hotel', 'payables')->assertOk()->assertJsonCount(0, 'data');
    $this->sPost('hotel', "payables/{$payable->id}/invoices", ['number' => 'F001-1', 'issue_date' => now()->toDateString(), 'amount' => 10, 'file' => $this->invoiceFile()])->assertNotFound();

    $inc = $this->sPost('prv', 'incidents', ['reservation_service_id' => $tren, 'type' => 'Horario', 'severity' => 'media', 'title' => 'Cambio de horario',
        'description' => 'El tren sale 30 min antes'])->assertCreated()->json('data');
    $this->sGet('hotel', "incidents/{$inc['id']}")->assertNotFound();
    $this->qPost('hotel', "supplier/incidents/{$inc['id']}/comments", ['message' => 'Hola'])->assertNotFound();
    $this->sPost('hotel', 'rate-requests', ['service_id' => $this->svc['tren']->id, 'proposed_cost' => 150, 'effective_from' => now()->addDays(40)->toDateString(),
        'reason_type' => 'Cambio de temporada'])->assertStatus(422);
    expect(collect($this->sGet('hotel', 'rates')->json('data'))->pluck('id'))->not->toContain($this->svc['tren']->id);
});

it('S-02: un proveedor de otro tenant no accede a datos de este tenant', function () {
    $tren = $this->serviceOf();
    $this->requestService($tren);
    $other = $this->makeTenant('inka');
    $prv = $this->makeUser($other, 'provider', ['provider_id' => $this->provider->id]);
    $token = $this->tokenFor($prv);
    $this->api($token)->getJson("/api/v1/supplier/requests/{$tren}")->assertForbidden();
    $this->api($token)->getJson('/api/v1/supplier/requests')->assertForbidden();
});

it('S-03: las solicitudes no exponen precio de venta, margen, contacto del cliente ni documentos de pasajeros', function () {
    $tren = $this->serviceOf();
    $this->requestService($tren);
    $this->admin()->table('booking_passengers')->where('reservation_id', $this->res['id'])->update(['doc_number' => 'PA998877']);
    $client = $this->admin()->table('crm_clients')->where('id', $this->admin()->table('booking_reservations')->where('id', $this->res['id'])->value('client_id'))->first();
    foreach (['requests', "requests/{$tren}"] as $uri) {
        $res = $this->sGet('prv', $uri)->assertOk();
        $raw = $res->getContent();
        expect($raw)->not->toContain('price')->not->toContain('margin')->not->toContain('client_id')->not->toContain('PA998877');
        if ($client->email) {
            expect($raw)->not->toContain($client->email);
        }
    }
    $row = $this->sGet('prv', "requests/{$tren}")->json('data');
    expect($row['holder_label'])->toStartWith('Titular: ')->and($row['unit_cost'])->toBe($this->fresh('booking_services', $tren)->cost_amount)
        ->and($row['state'])->toBe('pending');
});

it('S-04: confirmar exige código ≥ 4, lo guarda en mayúsculas, deja el servicio confirmado y una segunda confirmación es 409', function () {
    $tren = $this->serviceOf();
    $this->requestService($tren);
    $this->sPost('prv', "requests/{$tren}/confirm", ['confirmation_code' => 'ab1'])->assertStatus(422);
    $this->sPost('prv', "requests/{$tren}/confirm", ['confirmation_code' => ' pr-7788 ', 'confirmed_by_name' => 'Mesa de grupos'])->assertOk()
        ->assertJsonPath('data.state', 'confirmado')->assertJsonPath('data.confirmation_code', 'PR-7788');
    expect($this->fresh('booking_services', $tren))->status->toBe('confirmado')->confirmation_code->toBe('PR-7788');
    expect($this->admin()->table('ops_supplier_requests')->where('reservation_service_id', $tren)->latest('responded_at')->first())
        ->status->toBe('confirmado')->response_source->toBe('extranet');
    expect($this->admin()->table('supplier_request_responses')->where('reservation_service_id', $tren)->value('type'))->toBe('confirmacion');
    expect(collect($this->auditRows('Servicio', $this->fresh('booking_services', $tren)->code))->pluck('action'))->toContain('proveedor_confirma');
    expect($this->admin()->table('notif_messages')->where('event', 'proveedor.confirmacion')->where('channel', 'portal')->exists())->toBeTrue();
    $this->sPost('prv', "requests/{$tren}/confirm", ['confirmation_code' => 'OTRO-1'])->assertStatus(409)->assertJsonPath('code', 'SUP-BR-04');
    // La confirmación genera la cuenta por pagar al costo neto pactado.
    expect($this->admin()->table('fin_payables')->where('reservation_service_id', $tren)->value('amount'))->toBe($this->fresh('booking_services', $tren)->cost_amount);
});

it('S-05: rechazar sin motivo o detalle → 422; la alternativa no cambia el estado y queda pendiente de revisión', function () {
    $tren = $this->serviceOf();
    $guia = $this->serviceOf('tour');
    $this->requestService($tren);
    $this->requestService($guia);
    $this->sPost('prv', "requests/{$tren}/reject", ['note' => 'sin cupo'])->assertStatus(422);
    $this->sPost('prv', "requests/{$tren}/reject", ['reason' => 'Sin disponibilidad (sold out)'])->assertStatus(422);
    $this->sPost('prv', "requests/{$tren}/reject", ['reason' => 'Sin disponibilidad (sold out)', 'note' => 'Tren lleno'])->assertOk()->assertJsonPath('data.state', 'rechazado');

    $alt = $this->sPost('prv', "requests/{$guia}/alternative", ['kind' => 'Otro horario', 'note' => 'Podemos a las 10:00', 'net_cost' => 90,
        'hold_until' => now()->addDays(3)->toDateString()])->assertCreated()->assertJsonPath('data.state', 'alternative')->json('data');
    expect($this->fresh('booking_services', $guia)->status)->toBe('solicitado')
        ->and($alt['response']['review_status'])->toBe('pendiente');
    $this->sPost('prv', "requests/{$guia}/alternative", ['kind' => 'Otro horario', 'note' => 'otra'])->assertStatus(409);
    expect($this->sGet('prv', 'dashboard')->json('data'))->pending_requests->toBe(0)->alternatives_in_review->toBe(1);

    // Operaciones acepta sin código: aplica el costo y reenvía la solicitud al proveedor.
    $id = $this->oGet('ops', 'supplier-responses')->assertOk()->json('data.0.id');
    $this->oPost('ops', "supplier-responses/{$id}/accept", [], $this->idem())->assertOk()->assertJsonPath('data.review_status', 'aceptada');
    expect($this->fresh('booking_services', $guia))->status->toBe('solicitado')->cost_amount->toBe('90.00');
    expect($this->sGet('prv', "requests/{$guia}")->json('data.state'))->toBe('pending');
    $this->sPost('prv', "requests/{$guia}/confirm", ['confirmation_code' => 'GA-1010'])->assertOk();
});

it('S-06: alternativa con un servicio de otro proveedor → 422', function () {
    $tren = $this->serviceOf();
    $this->requestService($tren);
    $hotelService = $this->inTenant(fn () => app(SupplierServices::class)->create(['provider_id' => $this->hotel->id, 'name' => 'Habitación doble',
        'type' => 'Alojamiento', 'unit' => 'noche', 'cost' => 95, 'currency' => 'USD', 'valid_from' => now()->subMonth()->toDateString(), 'valid_to' => now()->addYear()->toDateString()], $this->users['admin'])['service']);
    $this->sPost('prv', "requests/{$tren}/alternative", ['kind' => 'Otra fecha', 'note' => 'x', 'service_id' => $hotelService->id])->assertStatus(422);
    $this->sPost('prv', "requests/{$tren}/alternative", ['kind' => 'Otra fecha', 'note' => 'Salida al día siguiente', 'service_id' => $this->svc['tren']->id,
        'date' => now()->addDays(22)->toDateString()])->assertCreated();
});

it('S-07: rooming — confirmar deja las observadas; la observación crea incidencia de la extranet con provider_id', function () {
    $list = $this->sentRooming();
    $rooming = $this->sGet('hotel', 'rooming-lists')->assertOk()->json('data.0');
    expect($rooming['state'])->toBe('por_acusar')->and($rooming['rooms'])->toHaveCount(2);
    $this->sGet('prv', 'rooming-lists')->assertOk()->assertJsonCount(0, 'data');
    $this->sPost('hotel', "rooming-lists/{$list['id']}/acknowledge")->assertOk()->assertJsonPath('data.state', 'recibida');
    $this->sPost('hotel', "rooming-lists/{$list['id']}/acknowledge")->assertStatus(409);

    $room = collect($rooming['rooms'])->firstWhere('room_number', '202');
    $this->sPost('hotel', "rooming-rooms/{$room['id']}/observations", ['kind' => 'Tipo de habitación distinto', 'impact' => 'media'])->assertStatus(422);
    $inc = $this->sPost('hotel', "rooming-rooms/{$room['id']}/observations", ['kind' => 'Tipo de habitación distinto', 'impact' => 'media',
        'proposal' => 'Ofrecemos doble twin sin costo'])->assertCreated()->json('data');
    $row = $this->admin()->table('ops_incidents')->where('id', $inc['id'])->first();
    expect($row)->provider_id->toBe($this->hotel->id)->source->toBe('extranet')->type->toBe('Proveedor');
    expect($this->fresh('ops_rooming_rooms', $room['id']))->status->toBe('observada')->incident_id->toBe($inc['id']);

    $this->sPost('hotel', "rooming-lists/{$list['id']}/confirm", [], $this->lockOf('ops_rooming_lists', $list['id']))->assertOk()->assertJsonPath('data.state', 'confirmada');
    $statuses = $this->admin()->table('ops_rooming_rooms')->where('rooming_list_id', $list['id'])->pluck('status', 'room_number');
    expect($statuses['201'])->toBe('confirmada')->and($statuses['202'])->toBe('observada');
    expect(collect($this->auditRows('Rooming', $this->res['code']))->pluck('action')->all())->toContain('acuse_recibo_hotel', 'observacion_hotel', 'confirmacion_hotel');

    // §12-8: reenviar una nueva versión exige acusar y confirmar de nuevo.
    $this->oPost('ops', "rooming-lists/{$list['id']}/send", [], $this->lockOf('ops_rooming_lists', $list['id']) + $this->idem())->assertOk();
    expect($this->sGet('hotel', 'rooming-lists')->json('data.0.state'))->toBe('por_acusar');
});

it('S-08: canje — otro proveedor 404, anulado 409, utilizado 409 con fecha, futuro sin confirmar 422, más allá de mañana 422 y doble canje uno solo', function () {
    $this->makeOrder();
    $this->confirmSuppliers();
    $tren = $this->serviceOf();
    $guia = $this->serviceOf('tour');
    $v = $this->issuedVoucher($tren, 1);
    $this->sPost('hotel', 'vouchers/redeem', ['code' => $v['code']])->assertNotFound();
    $this->sPost('prv', 'vouchers/redeem', ['code' => $v['code']])->assertStatus(422)->assertJsonPath('code', 'SUP-BR-32')->assertJsonPath('requires_confirmation', true);
    $this->admin()->table('ops_vouchers')->where('id', $v['id'])->update(['service_date' => now('America/Lima')->addDays(5)->toDateString()]);
    $this->sPost('prv', 'vouchers/redeem', ['code' => $v['code'], 'confirm_future' => true])->assertStatus(422);
    $this->admin()->table('ops_vouchers')->where('id', $v['id'])->update(['service_date' => now('America/Lima')->addDay()->toDateString()]);

    $key = $this->idem('canje-1');
    $first = $this->sPost('prv', 'vouchers/redeem', ['code' => strtolower($v['code']), 'confirm_future' => true], $key)->assertOk()->assertJsonPath('data.status', 'utilizado');
    $replay = $this->sPost('prv', 'vouchers/redeem', ['code' => strtolower($v['code']), 'confirm_future' => true], $key)->assertOk();
    expect($replay->headers->get('Idempotent-Replayed'))->toBe('true')->and($first->json('data.used_at'))->toBe($replay->json('data.used_at'));
    $again = $this->sPost('prv', 'vouchers/redeem', ['code' => $v['code'], 'confirm_future' => true])->assertStatus(409)->assertJsonPath('code', 'SUP-BR-31');
    expect($again->json('used_at'))->not->toBeNull();
    expect($this->fresh('ops_vouchers', $v['id'])->used_via)->toBe('extranet');
    expect($this->admin()->table('audit_events')->where('entity', 'Voucher')->where('action', 'check_in_proveedor')->count())->toBe(1);

    $g = $this->issuedVoucher($guia);
    $this->oPost('ops', "vouchers/{$g['id']}/void", ['reason' => 'Cambio'], $this->lockOf('ops_vouchers', $g['id']) + $this->idem())->assertOk();
    $this->sPost('prv', 'vouchers/redeem', ['code' => $g['code']])->assertStatus(409)->assertJsonPath('code', 'SUP-BR-31');
});

it('S-09: factura — formato y archivo obligatorios, diferencia de importe con advertencia y segunda factura activa → 409; Finanzas valida', function () {
    $tren = $this->serviceOf();
    $this->requestService($tren);
    $this->sPost('prv', "requests/{$tren}/confirm", ['confirmation_code' => 'PR-1234'])->assertOk();
    $payable = $this->sGet('prv', 'payables')->assertOk()->json('data.0');
    expect($payable['can_upload'])->toBeTrue()->and($payable['status_label'])->toBe('En revisión');
    $base = ['issue_date' => now()->toDateString(), 'amount' => $payable['balance']];
    $this->sPost('prv', "payables/{$payable['id']}/invoices", $base + ['number' => 'F1-23', 'file' => $this->invoiceFile()])->assertStatus(422);
    $this->sPost('prv', "payables/{$payable['id']}/invoices", $base + ['number' => 'F001-123'])->assertStatus(422);
    $this->sPost('prv', "payables/{$payable['id']}/invoices", $base + ['number' => 'F001-123', 'file' => $this->invoiceFile('x.exe', 'application/x-msdownload')])->assertStatus(422);
    $res = $this->sPost('prv', "payables/{$payable['id']}/invoices", ['number' => 'f001-123', 'issue_date' => now()->toDateString(), 'amount' => (float) $payable['balance'] + 5,
        'file' => $this->invoiceFile()])->assertCreated();
    expect($res->json('data'))->number->toBe('F001-123')->amount_difference->toBe('5.00')->and($res->json('meta.warnings.0'))->toContain('Finanzas revisará');
    $this->sPost('prv', "payables/{$payable['id']}/invoices", $base + ['number' => 'F001-124', 'file' => $this->invoiceFile()])->assertStatus(409)->assertJsonPath('code', 'SUP-BR-42');
    expect($this->admin()->table('notif_messages')->where('event', 'proveedor.factura')->exists())->toBeTrue();

    // Finanzas la ve en CxP y la valida.
    $row = $this->qGet('finance', "finance/payables/{$payable['id']}")->assertOk()->json('data');
    expect($row['invoices'][0]['status'])->toBe('registrada')->and($row['supplier_invoice']['status'])->toBe('registrada');
    $this->qPost('finance', "fin/supplier-invoices/{$row['invoices'][0]['id']}/observe")->assertStatus(422);
    $this->qPost('finance', "fin/supplier-invoices/{$row['invoices'][0]['id']}/validate")->assertOk()->assertJsonPath('data.status', 'validada');
    $this->qPost('ops', "fin/supplier-invoices/{$row['invoices'][0]['id']}/validate")->assertForbidden();
});

it('S-10: tarifa con vigencia < hoy + 30 → 422; segunda pendiente del mismo servicio → 409; la aprobación crea una nueva vigencia', function () {
    $data = ['service_id' => $this->svc['tren']->id, 'proposed_cost' => 155, 'reason_type' => 'Ajuste por inflación / costos'];
    $this->sPost('prv', 'rate-requests', $data + ['effective_from' => now()->addDays(10)->toDateString()])->assertStatus(422);
    $r = $this->sPost('prv', 'rate-requests', $data + ['effective_from' => now()->addDays(45)->toDateString()])->assertCreated()->json('data');
    expect($r['code'])->toBe('SOL-TAR-0101')->and($r['current_cost'])->toBe('140.00');
    $this->sPost('prv', 'rate-requests', $data + ['effective_from' => now()->addDays(60)->toDateString()])->assertStatus(409)->assertJsonPath('code', 'SUP-BR-61');
    expect(collect($this->sGet('prv', 'rates')->json('data'))->firstWhere('id', $this->svc['tren']->id))->can_request->toBeFalse()->pending_request->toBe('SOL-TAR-0101');

    $this->qPost('ops', "catalog/rate-requests/{$r['code']}/approve")->assertForbidden();
    $this->qPost('manager', "catalog/rate-requests/{$r['code']}/approve")->assertOk()->assertJsonPath('data.status', 'aprobada');
    $rate = $this->admin()->table('catalog_service_rates')->where('service_id', $this->svc['tren']->id)->first();
    expect($rate->cost)->toBe('155.00')->and($rate->applied_at)->toBeNull()
        ->and($this->fresh('catalog_services', $this->svc['tren']->id)->cost)->toBe('140.00');
    // Al llegar la vigencia el job la aplica al servicio.
    $this->admin()->table('catalog_service_rates')->where('id', $rate->id)->update(['valid_from' => now()->subDay()->toDateString()]);
    $this->artisan('supplier:monitor daily')->assertSuccessful();
    expect($this->fresh('catalog_services', $this->svc['tren']->id)->cost)->toBe('155.00');
});

it('S-11: incidencia de un servicio de hace más de 7 días → 422; comentario en incidencia cerrada → 409', function () {
    $tren = $this->serviceOf();
    $this->admin()->table('booking_services')->where('id', $tren)->update(['service_date' => now()->subDays(9)->toDateString()]);
    $payload = ['reservation_service_id' => $tren, 'type' => 'Proveedor', 'severity' => 'critica', 'title' => 'Corte de vía', 'description' => 'Huaico en la vía férrea'];
    $this->sPost('prv', 'incidents', $payload)->assertStatus(422)->assertJsonPath('code', 'SUP-BR-51');
    $this->admin()->table('booking_services')->where('id', $tren)->update(['service_date' => now()->addDay()->toDateString()]);
    $inc = $this->sPost('prv', 'incidents', $payload)->assertCreated()->json('data');
    // Crítica: misma alerta a gerencia que en back-office (PROC-037).
    expect($this->admin()->table('notif_messages')->where('event', 'incidencia.critica')->where('channel', 'whatsapp')->exists())->toBeTrue();
    $this->qPost('prv', "supplier/incidents/{$inc['code']}/comments", ['message' => 'Seguimos evaluando'])->assertCreated()
        ->assertJsonPath('data.logs.1.author', 'Prv');
    $this->oPost('ops', "incidents/{$inc['id']}/comments", ['message' => 'Recibido, coordinamos bus', 'visibility' => 'proveedor'])->assertSuccessful();
    $logs = $this->sGet('prv', "incidents/{$inc['id']}")->json('data.logs');
    expect(collect($logs)->last()['author'])->toStartWith('Operaciones ');
    $this->admin()->table('ops_incidents')->where('id', $inc['id'])->update(['status' => 'cerrada']);
    $this->qPost('prv', "supplier/incidents/{$inc['id']}/comments", ['message' => 'otra'])->assertStatus(409)->assertJsonPath('code', 'SUP-BR-53');
});

it('S-12: un interno → 403 en /supplier/*; el proveedor → 403 en /ops/*; provider_role acota los permisos', function () {
    $this->qGet('ops', 'supplier/me')->assertForbidden();
    $this->qGet('admin', 'supplier/requests')->assertForbidden();
    $this->oGet('prv', 'orders')->assertForbidden();
    $this->qGet('prv', 'finance/payables')->assertForbidden();
    expect($this->sGet('prv', 'me')->assertOk()->json('data.permissions'))->toContain('supplier.rates.request');

    $this->addUser('recepcion', 'provider', ['provider_id' => $this->provider->id, 'provider_role' => 'recepcion']);
    $this->sGet('recepcion', 'payables')->assertForbidden();
    $this->sPost('recepcion', "requests/{$this->serviceOf()}/confirm", ['confirmation_code' => 'ABCD'])->assertForbidden();
    $this->sGet('recepcion', 'vouchers')->assertOk();
    // Sin vínculo a proveedor: mensaje explícito.
    $this->addUser('suelto', 'provider');
    $this->sGet('suelto', 'me')->assertForbidden()->assertJsonPath('code', 'SUP-BR-01');
});

it('Catálogo invita usuarios del proveedor con su perfil; Operaciones rechaza una alternativa y vuelve a "Por responder"', function () {
    $this->qPost('admin', "catalog/providers/{$this->provider->id}/users", ['name' => 'Recepción PeruRail', 'email' => 'recepcion@perurail.test', 'provider_role' => 'recepcion'])
        ->assertCreated()->assertJsonPath('data.provider_role', 'recepcion')->assertJsonPath('data.status', 'pendiente');
    $this->qPost('ops', "catalog/providers/{$this->provider->id}/users", ['name' => 'X', 'email' => 'x@perurail.test'])->assertForbidden();
    expect($this->qGet('admin', "catalog/providers/{$this->provider->id}/users")->json('data'))->toHaveCount(2);

    $tren = $this->serviceOf();
    $this->requestService($tren);
    $this->sPost('prv', "requests/{$tren}/alternative", ['kind' => 'Otra fecha', 'date' => now()->addDays(22)->toDateString(), 'note' => 'Un día después'])->assertCreated();
    $id = $this->oGet('ops', 'supplier-responses')->json('data.0.id');
    $this->oPost('ops', "supplier-responses/{$id}/reject", [], $this->idem())->assertStatus(422);
    $this->oPost('ops', "supplier-responses/{$id}/reject", ['note' => 'El cliente no puede cambiar la fecha'], $this->idem())->assertOk()->assertJsonPath('data.review_status', 'rechazada');
    expect($this->sGet('prv', "requests/{$tren}")->json('data'))->state->toBe('pending')->can_propose->toBeTrue();
    expect($this->admin()->table('notif_messages')->where('event', 'proveedor.alternativa_rechazada')->exists())->toBeTrue();
});
