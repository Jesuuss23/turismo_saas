<?php

use App\Models\Finance\Payment;
use App\Models\Iam\Role;
use App\Services\Catalog\ProviderService;
use App\Services\SequenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->channelWorld();
    $this->fres = $this->makeReservation('u03');
    $this->inTenant(function () {
        $providers = app(ProviderService::class);
        $this->vendor = $providers->create(['name' => 'Transportes Inca', 'type' => 'Transporte', 'ruc' => '20100000077', 'contact_name' => 'Ventas', 'currency' => 'USD',
            'email' => 'pagos@transinca.test', 'contract_start' => now()->subYear()->toDateString(), 'contract_end' => now()->addYear()->toDateString()], $this->users['admin']);
        $providers->activate($this->vendor, $this->users['admin']);
    });
});

function apr(string $code): object
{
    return DB::connection('pgsql_admin')->table('collab_approval_requests')->where('code', $code)->first();
}

/** Solicitud viva (o la última) del registro de origen. */
function aprOf(string $subjectId, ?string $type = null): ?object
{
    return DB::connection('pgsql_admin')->table('collab_approval_requests')->where('subject_id', $subjectId)
        ->when($type, fn ($q) => $q->where('request_type', $type))->orderByDesc('code')->first();
}

function decide(object $t, string $who, string $code, string $action, array $data = [], ?string $key = null, ?int $lock = null): TestResponse
{
    return $t->qPost($who, "approvals/{$code}/{$action}", $data, ['If-Match' => (string) ($lock ?? apr($code)->lock_version)] + $t->idem($key));
}

/** Cotización de u03 con margen < 15 % y su solicitud de aprobación (abre la solicitud unificada). */
function quoteRequest(object $t, string $owner = 'u03', array $overrides = []): array
{
    $quote = $t->qPost($owner, 'quotes', $t->quotePayload('u03', $overrides, ['rate_id' => null, 'unit_cost' => 350, 'unit_price' => 380]), $t->idem())
        ->assertCreated()->json('data');
    $t->qPost($owner, "quotes/{$quote['code']}/internal-approval-requests", ['note' => 'Cliente recurrente'])->assertCreated();
    $version = DB::connection('pgsql_admin')->table('quote_versions')->where('quote_id', $quote['id'])->orderByDesc('version_no')->first();

    return [$quote, aprOf($version->id, 'quote'), $version];
}

it('§10-1 aislamiento: el tenant B no lista ni decide solicitudes del tenant A', function () {
    [, $request] = quoteRequest($this);
    $other = $this->makeTenant('inka');
    $token = $this->tokenFor($this->makeUser($other, 'manager'));
    expect($this->api($token)->getJson('/api/v1/approvals')->assertOk()->json('data'))->toBe([]);
    $this->api($token)->getJson("/api/v1/approvals/{$request->code}")->assertNotFound();
    $this->api($token)->withHeaders(['If-Match' => '1'] + $this->idem())->postJson("/api/v1/approvals/{$request->code}/approve")->assertNotFound();
});

it('§10-2 RBAC: Ventas no aprueba; Contabilidad no entra a aprobaciones; Finanzas no entra al inbox', function () {
    [, $request] = quoteRequest($this);
    decide($this, 'u04', $request->code, 'approve')->assertForbidden()->assertJsonPath('code', 'APR-SCOPE');
    decide($this, 'u03', $request->code, 'approve')->assertForbidden()->assertJsonPath('code', 'APR-BR-04');
    $this->qGet('accounting', 'approvals')->assertForbidden();
    $this->qGet('finance', 'inbox/conversations')->assertForbidden();
    // Ventas solo ve lo propio; Operaciones no ve cotizaciones.
    expect(collect($this->qGet('u04', 'approvals')->assertOk()->json('data'))->pluck('code'))->not->toContain($request->code)
        ->and(collect($this->qGet('ops', 'approvals')->assertOk()->json('data'))->pluck('type')->unique()->all())->not->toContain('quote');
});

it('§10-3 autoaprobación: el Manager no aprueba su propia cotización (APR-BR-03); el Tenant Admin sí', function () {
    $client = $this->makeClient('manager', ['name' => 'Cliente del gerente']);
    [$quote, $request] = quoteRequest($this, 'manager', ['client_id' => $client->id]);
    expect($request->requester_id)->toBe($this->users['manager']->id);
    decide($this, 'manager', $request->code, 'approve', ['note' => 'ok'])->assertForbidden()->assertJsonPath('code', 'APR-BR-03');
    $this->qGet('manager', "approvals/{$request->code}")->assertOk()->assertJsonPath('data.actions.approve', false);
    decide($this, 'admin', $request->code, 'approve', ['note' => 'Cliente estratégico'])->assertOk()->assertJsonPath('data.status', 'aprobada');
    expect($this->admin()->table('quote_versions')->where('quote_id', $quote['id'])->value('internal_approval_id'))->not->toBeNull();
});

it('§10-4 doble nivel: crédito B2B S/ 10 000 → N1 Manager, N2 Finanzas; nadie aprueba dos niveles; la agencia se reactiva', function () {
    $this->admin()->table('channel_b2b_agencies')->where('id', $this->agencies['AGB-01']->id)->update(['credit_used' => 6000, 'status' => 'bloqueada', 'block_reason' => 'credito_excedido']);
    $created = $this->qPost('u03', 'approvals', ['type' => 'credit_b2b', 'agency_code' => 'AGB-01', 'amount' => 10000, 'currency' => 'PEN',
        'title' => 'Ampliación temporal por temporada alta', 'justification' => 'Grupo de 40 pax confirmado'], $this->idem())->assertCreated()->json('data');
    expect($created['total_levels'])->toBe(2)->and($created['flow'][1]['permission'])->toBe('finance.receivables');
    $this->qGet('finance', 'approvals?filter[box]=mine')->assertOk()->assertJsonCount(0, 'data');

    decide($this, 'manager', $created['code'], 'approve', ['note' => 'Cliente confiable'])->assertOk()->assertJsonPath('data.current_level', 2)->assertJsonPath('data.status', 'pendiente');
    decide($this, 'manager', $created['code'], 'approve')->assertForbidden()->assertJsonPath('code', 'APR-BR-03');
    $this->qGet('finance', 'approvals?filter[box]=mine')->assertOk()->assertJsonPath('data.0.code', $created['code']);
    decide($this, 'finance', $created['code'], 'approve', ['note' => 'Riesgo aceptable'])->assertOk()->assertJsonPath('data.status', 'aprobada');

    $agency = $this->agency('AGB-01');
    expect((string) $agency->credit_limit)->toBe('15000.00')->and($agency->status)->toBe('activa')->and($agency->block_reason)->toBeNull()
        ->and($this->admin()->table('channel_b2b_credit_exceptions')->where('agency_id', $agency->id)->value('amount'))->toBe('10000.00')
        ->and($this->admin()->table('collab_approval_steps')->where('request_id', apr($created['code'])->id)->count())->toBe(2)
        ->and(collect($this->auditRows('Agencia B2B', 'AGB-01'))->pluck('action'))->toContain('ampliar_credito');
});

it('§10-5 tope: descuento 12 % sin quotes.discount_override → 422 APR-BR-05; con override se aprueba', function () {
    [$quote, $request, $version] = quoteRequest($this, 'u03', ['discount_pct' => 8]);
    $this->admin()->table('quote_versions')->where('id', $version->id)->update(['discount_pct' => 12]);
    $this->inTenant(fn () => Role::where('name', 'manager')->where('tenant_id', $this->tenant->id)->first()->revokePermissionTo('quotes.discount_override'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    decide($this, 'manager', $request->code, 'approve', ['note' => 'ok'])->assertStatus(422)->assertJsonPath('code', 'APR-BR-05');
    decide($this, 'manager', $request->code, 'reject', ['note' => 'Fuera de política'])->assertOk()->assertJsonPath('data.status', 'rechazada');
    expect($this->admin()->table('quote_internal_approvals')->where('quote_version_id', $version->id)->value('status'))->toBe('rechazada');

    // Nueva solicitud del asesor → la aprueba el Tenant Admin (con override).
    $this->qPost('u03', "quotes/{$quote['code']}/internal-approval-requests", ['note' => 'Reconsiderar'])->assertCreated();
    $second = aprOf($version->id, 'quote');
    expect($second->code)->not->toBe($request->code);
    decide($this, 'admin', $second->code, 'approve', ['note' => 'Override por volumen'])->assertOk()->assertJsonPath('data.status', 'aprobada');
});

it('§10-6 efecto cotización: aprobar habilita el envío; rechazar marca la solicitud de origen rechazada', function () {
    [$quote, $request] = quoteRequest($this);
    $this->sendQuote('u03', $quote['code'])->assertStatus(409)->assertJsonPath('code', 'QTE-BR-12');
    decide($this, 'manager', $request->code, 'approve', ['note' => 'Volumen anual'])->assertOk()->assertJsonPath('data.status', 'aprobada');
    expect(collect($this->auditRows('Cotización', $quote['code']))->pluck('action'))->toContain('aprobacion_interna')
        ->and($this->admin()->table('notif_messages')->where('event', 'aprobacion.resultado')->where('user_id', $this->users['u03']->id)->exists())->toBeTrue();
    $this->sendQuote('u03', $quote['code'])->assertOk()->assertJsonPath('data.status', 'enviada');

    [, $other, $version] = quoteRequest($this);
    decide($this, 'manager', $other->code, 'reject')->assertStatus(422)->assertJsonValidationErrors('note');
    decide($this, 'manager', $other->code, 'reject', ['note' => 'Margen insuficiente'])->assertOk();
    expect($this->admin()->table('quote_internal_approvals')->where('quote_version_id', $version->id)->value('status'))->toBe('rechazada')
        ->and(apr($other->code)->decision_note)->toBe('Margen insuficiente');
});

it('§10-7 efecto anulación: mismas penalidades que la cancelación directa, cupos liberados y CxC canceladas', function () {
    $code = $this->fres['code'];
    $this->rPost('u03', $code, 'cancel-requests', ['reason' => 'Cambio de planes', 'detail' => 'El cliente consolida en otra cotización'])->assertCreated();
    $request = aprOf($this->fres['id'], 'cancel');
    expect($request->status)->toBe('pendiente')->and($request->total_levels)->toBe(1);
    $preview = $this->qGet('manager', "approvals/cancel-preview/{$code}")->assertOk()->json('data');
    $detail = $this->qGet('manager', "approvals/{$request->code}")->assertOk()->json('data');
    expect($detail['origin']['impact']['penalty'])->toBe($preview['penalty'])->and($detail['origin']['reason'])->toContain('Cambio de planes');
    $sold = $this->sold();

    decide($this, 'manager', $request->code, 'approve', ['note' => 'Procede'])->assertOk()->assertJsonPath('data.status', 'aprobada');
    $res = $this->admin()->table('booking_reservations')->where('id', $this->fres['id'])->first();
    $cancellation = $this->admin()->table('booking_cancellations')->where('reservation_id', $res->id)->first();
    expect($res->status)->toBe('cancelada')
        ->and((string) $cancellation->penalty_amount)->toBe($preview['penalty'])->and((string) $cancellation->refund_amount)->toBe($preview['refund'])
        ->and($this->sold())->toBeLessThan($sold)
        ->and($this->admin()->table('fin_receivables')->where('reservation_id', $res->id)->whereNotIn('status', ['cancelada'])->where('kind', '<>', 'cancelación')->count())->toBe(0)
        ->and($this->admin()->table('booking_cancel_requests')->where('reservation_id', $res->id)->value('status'))->toBe('aprobada')
        ->and(apr($request->code)->closed_in_origin)->toBeFalse();
});

it('§10-8 efecto reversión: pago revertido y CxC recalculada; un pago pendiente → 409 APR-BR-09', function () {
    $cxc = $this->fresh('fin_receivables', $this->fres['receivables'][0]['id']);
    $payment = $this->qPost('finance', 'finance/payments', ['receivable_id' => $cxc->id, 'amount' => '100.00', 'method' => 'Efectivo'], $this->idem())->assertCreated()->json('data');
    expect($this->fresh('fin_receivables', $cxc->id)->status)->toBe('parcial');
    $created = $this->qPost('finance', "finance/payments/{$payment['code']}/reversal-requests", ['reason' => 'Depósito duplicado'], $this->idem())->assertCreated()->json('data');
    decide($this, 'finance', $created['code'], 'approve')->assertForbidden()->assertJsonPath('code', 'APR-BR-03');
    decide($this, 'admin', $created['code'], 'approve', ['note' => 'Verificado con el banco'])->assertOk()->assertJsonPath('data.status', 'aprobada');
    $after = $this->admin()->table('fin_payments')->where('code', $payment['code'])->first();
    expect($after->status)->toBe('revertido')->and($after->reversal_request_id)->toBeNull()
        ->and($this->fresh('fin_receivables', $cxc->id)->status)->toBe('abierta');

    $pending = $this->inTenant(fn () => Payment::create(['code' => app(SequenceService::class)->next('payment'), 'receivable_id' => $cxc->id,
        'reservation_id' => $cxc->reservation_id, 'client_id' => $cxc->client_id, 'kind' => 'cobro', 'source' => 'portal_cliente', 'method' => 'Yape', 'amount' => '50.00',
        'currency' => $cxc->currency, 'fx_rate' => $cxc->fx_rate, 'paid_on' => now()->toDateString(), 'reference' => 'X1', 'status' => 'pendiente']));
    $this->qPost('finance', "finance/payments/{$pending->code}/reversal-requests", ['reason' => 'x'], $this->idem())->assertStatus(409)->assertJsonPath('code', 'APR-BR-09');
});

it('§10-9 CxP rechazada → observada; el reenvío crea la ronda 2 con nuevo dedupe_key', function () {
    $payable = $this->makePayable('300.00');
    $request = aprOf($payable->id, 'payable');
    expect($request->dedupe_key)->toBe("payable:{$payable->id}:1")->and($request->requester_kind)->toBe('system');
    decide($this, 'finance', $request->code, 'reject', ['note' => 'Falta la factura del proveedor'])->assertOk();
    $observed = $this->fresh('fin_payables', $payable->id);
    expect($observed->observation)->toBe('Falta la factura del proveedor')->and($observed->approved_at)->toBeNull();
    $this->qGet('finance', "approvals/{$request->code}")->assertJsonPath('data.actions.resubmit', true);

    $new = $this->qPost('finance', "approvals/{$request->code}/resubmit", ['note' => 'Factura adjunta'], $this->idem())->assertCreated()->json('data');
    expect($new['round'])->toBe(2)->and(apr($new['code'])->dedupe_key)->toBe("payable:{$payable->id}:2")
        ->and($this->fresh('fin_payables', $payable->id)->observation)->toBeNull()
        ->and($this->fresh('fin_payables', $payable->id)->approval_round)->toBe(2);
    decide($this, 'finance', $new['code'], 'approve')->assertOk();
    expect($this->fresh('fin_payables', $payable->id)->approved_at)->not->toBeNull();
});

it('§10-10 idempotencia y concurrencia: misma Idempotency-Key = un solo efecto; dos aprobadores con la misma versión → 409', function () {
    [, $request] = quoteRequest($this);
    $lock = apr($request->code)->lock_version;
    decide($this, 'manager', $request->code, 'approve', ['note' => 'ok'], 'k-1', $lock)->assertOk();
    decide($this, 'manager', $request->code, 'approve', ['note' => 'ok'], 'k-1', $lock)->assertOk();
    expect($this->admin()->table('collab_approval_steps')->where('request_id', $request->id)->count())->toBe(1)
        ->and($this->admin()->table('quote_internal_approvals')->where('status', 'aprobada')->count())->toBe(1);

    [, $other] = quoteRequest($this);
    $stale = apr($other->code)->lock_version;
    decide($this, 'manager', $other->code, 'approve', ['note' => 'primero'], null, $stale)->assertOk();
    decide($this, 'admin', $other->code, 'approve', ['note' => 'segundo'], null, $stale)->assertStatus(409);
});

it('§10-11 SLA: pedir información pausa y extiende due_at; approvals:sla-check advierte y vence', function () {
    [, $request] = quoteRequest($this);
    $due = Carbon::parse(apr($request->code)->due_at);
    decide($this, 'manager', $request->code, 'info-requests', ['question' => '¿El cliente acepta pago anticipado?'])->assertOk()->assertJsonPath('data.status', 'info')
        ->assertJsonPath('data.sla', 'paused');
    decide($this, 'manager', $request->code, 'approve', ['note' => 'x'])->assertStatus(409);
    $info = $this->admin()->table('collab_approval_info_requests')->where('request_id', $request->id)->first();
    Carbon::setTestNow(now()->addHours(2));
    $this->qPost('u04', "approvals/{$request->code}/info-requests/{$info->id}/answer", ['answer' => 'x'])->assertForbidden();
    $this->qPost('u03', "approvals/{$request->code}/info-requests/{$info->id}/answer", ['answer' => 'Sí, 50 % al confirmar'])->assertOk()->assertJsonPath('data.status', 'pendiente');
    expect(abs(Carbon::parse(apr($request->code)->due_at)->diffInSeconds($due->copy()->addHours(2))))->toBeLessThan(5);

    Carbon::setTestNow(Carbon::parse(apr($request->code)->due_at)->subHour());
    $this->artisan('approvals:sla-check')->assertSuccessful();
    Carbon::setTestNow(Carbon::parse(apr($request->code)->due_at)->addMinute());
    $this->artisan('approvals:sla-check')->assertSuccessful();
    $this->artisan('approvals:sla-check')->assertSuccessful();
    $events = $this->admin()->table('collab_approval_events')->where('request_id', $request->id)->pluck('event')->all();
    expect(array_count_values($events)['sla_advertencia'] ?? 0)->toBe(1)->and(array_count_values($events)['sla_vencido'] ?? 0)->toBe(1)
        ->and($this->admin()->table('notif_messages')->where('event', 'aprobacion.sla_vencido')->where('user_id', $this->users['manager']->id)->exists())->toBeTrue();
    Carbon::setTestNow();
});

it('§10-12 cierre en origen: aprobar desde /quotes/{code}/versions/{v}/internal-approval cierra la solicitud de la bandeja', function () {
    [$quote, $request] = quoteRequest($this);
    $this->qPost('manager', "quotes/{$quote['code']}/versions/1/internal-approval", ['decision' => 'approve', 'note' => 'Desde la cotización'], $this->quoteLock($quote['code']))->assertOk();
    $closed = apr($request->code);
    expect($closed->status)->toBe('aprobada')->and($closed->closed_in_origin)->toBeTrue()->and($closed->decided_by)->toBe($this->users['manager']->id)
        ->and($this->admin()->table('collab_approval_events')->where('request_id', $request->id)->pluck('event')->all())->toContain('cerrada_en_origen');
});

it('§10-13 reglas: no se desactiva el único nivel 1 ni se cambia el permiso de un nivel 1 de origen; Manager solo lee', function () {
    $rules = collect($this->qGet('manager', 'approval-rules')->assertOk()->assertJsonPath('meta.can_edit', false)->json('data'));
    expect($rules)->toHaveCount(14);
    $quoteRule = $rules->firstWhere('type', 'quote');
    $patch = fn (string $who, array $rule, array $data) => $this->as($who)->withHeaders(['If-Match' => (string) $rule['lock_version']])->patchJson("/api/v1/approval-rules/{$rule['code']}", $data);
    $patch('manager', $quoteRule, ['sla_hours' => 4])->assertForbidden();
    $patch('admin', $quoteRule, ['permission' => 'finance.payables'])->assertStatus(422)->assertJsonPath('code', 'APR-BR-02');
    $patch('admin', $quoteRule, ['is_active' => false])->assertStatus(409)->assertJsonPath('code', 'APR-BR-15');
    $patch('admin', $quoteRule, ['sla_hours' => 4])->assertOk()->assertJsonPath('data.sla_hours', 4);

    $level1 = $rules->firstWhere('type', 'other');
    $this->as('admin')->withHeaders(['If-Match' => (string) $level1['lock_version']])->deleteJson("/api/v1/approval-rules/{$level1['code']}")->assertStatus(409);
    $level2 = $rules->first(fn ($r) => $r['type'] === 'refund' && $r['level'] === 2);
    $this->as('admin')->withHeaders(['If-Match' => (string) $level2['lock_version']])->deleteJson("/api/v1/approval-rules/{$level2['code']}")->assertNoContent();
    $this->qPost('admin', 'approval-rules', ['request_type' => 'other', 'level' => 2, 'permission' => 'no.existe', 'min_amount_pen' => 500, 'sla_hours' => 24])->assertStatus(422);
    $this->qPost('admin', 'approval-rules', ['request_type' => 'other', 'level' => 2, 'permission' => 'finance.payables', 'min_amount_pen' => 500, 'sla_hours' => 24])->assertCreated();
    $reset = $this->qPost('admin', 'approval-rules/reset')->assertOk()->json('data');
    expect($reset)->toHaveCount(14)->and(collect($reset)->firstWhere('type', 'quote')['sla_hours'])->toBe(8);
    expect(collect($this->auditRows('Regla de aprobación', 'matriz'))->pluck('action')->all())->toBe(['restaurar']);
});

it('retiro y origen: el asesor retira la anulación (retirada) y la CxP aprobada en Finanzas cierra su solicitud', function () {
    $code = $this->fres['code'];
    $this->rPost('u03', $code, 'cancel-requests', ['reason' => 'Error', 'detail' => 'Solicitud duplicada'])->assertCreated();
    $request = aprOf($this->fres['id'], 'cancel');
    decide($this, 'u04', $request->code, 'withdraw')->assertForbidden();
    decide($this, 'u03', $request->code, 'withdraw')->assertOk()->assertJsonPath('data.status', 'anulada');
    expect($this->admin()->table('booking_cancel_requests')->where('reservation_id', $this->fres['id'])->value('status'))->toBe('retirada')
        ->and($this->admin()->table('booking_reservations')->where('id', $this->fres['id'])->value('status'))->not->toBe('cancelada');

    $payable = $this->makePayable('120.00');
    $this->qPost('finance', "finance/payables/{$payable->code}/approve", [], $this->idem())->assertOk();
    $closed = aprOf($payable->id, 'payable');
    expect($closed->status)->toBe('aprobada')->and($closed->closed_in_origin)->toBeTrue();

    $counters = $this->qGet('u03', 'approvals/counters')->assertOk()->json('data');
    expect($counters)->toHaveKeys(['mine', 'overdue', 'sent_open', 'resolved_7d', 'avg_hours']);
});
