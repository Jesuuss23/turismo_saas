<?php

use App\Exceptions\ApiException;
use App\Models\Crm\Lead;
use App\Services\Crm\LeadService;

beforeEach(fn () => $this->crmWorld());

it('CRM-T01: un usuario del tenant A no ve leads ni clientes del tenant B', function () {
    $lead = $this->makeLead('u03');
    $client = $this->makeClient('u03');
    $other = $this->makeTenant('inka');
    $token = $this->tokenFor($this->makeUser($other, 'tenant_admin'));

    $this->api($token)->getJson("/api/v1/crm/leads/{$lead->code}")->assertNotFound();
    $this->api($token)->getJson("/api/v1/crm/clients/{$client->code}")->assertNotFound();
    expect($this->api($token)->getJson('/api/v1/crm/leads')->json('data'))->toBe([]);
});

it('CRM-T02: Operaciones, Finanzas y externos no acceden a leads', function () {
    foreach (['ops', 'finance', 'accounting'] as $who) {
        $this->as($who)->getJson('/api/v1/crm/leads')->assertForbidden()->assertJsonPath('code', 'SEC-BR-03');
    }
    $customer = $this->makeUser($this->tenant, 'customer', ['client_id' => $this->makeClient('u03')->id]);
    $this->api($this->tokenFor($customer))->getJson('/api/v1/crm/leads')->assertForbidden();
});

it('CRM-T03: Finanzas lee el 360 pero no edita', function () {
    $client = $this->makeClient('u03');
    $this->as('finance')->getJson("/api/v1/crm/clients/{$client->code}")->assertOk()
        ->assertJsonPath('meta.permissions.manage', false);
    $this->as('finance')->withHeaders($this->ifMatch($client))->patchJson("/api/v1/crm/clients/{$client->code}", ['city' => 'Cusco'])->assertForbidden();
});

it('CRM-T04: Ventas ve solo sus leads y la cola; el lead de otro asesor da 403 SCOPE_OWNERSHIP', function () {
    $mine = $this->makeLead('u03');
    $queue = $this->makeLead(null);
    $foreign = $this->makeLead('u04');

    $codes = collect($this->as('u03')->getJson('/api/v1/crm/leads')->assertOk()->json('data'))->pluck('code');
    expect($codes->all())->toEqualCanonicalizing([$mine->code, $queue->code]);

    $this->as('u03')->getJson("/api/v1/crm/leads/{$foreign->code}")->assertForbidden()->assertJsonPath('code', 'SCOPE_OWNERSHIP');
    $this->as('manager')->getJson("/api/v1/crm/leads/{$foreign->code}")->assertOk();
});

it('CRM-T05: Ventas crea un lead para otro asesor y queda como responsable', function () {
    $response = $this->crmPost('u03', 'leads', $this->leadData(['owner_id' => $this->users['u04']->id]))->assertCreated();
    expect($response->json('data.owner.id'))->toBe($this->users['u03']->id);
});

it('CRM-T06: validaciones de alta (contacto, correo, teléfono, fecha)', function () {
    $this->crmPost('u03', 'leads', $this->leadData(['email' => null, 'phone' => null]))->assertStatus(422)
        ->assertJsonPath('message', 'Validación PROC-004: registre al menos correo o teléfono.');
    $this->crmPost('u03', 'leads', $this->leadData(['email' => 'no-es-correo']))->assertStatus(422);
    $this->crmPost('u03', 'leads', $this->leadData(['email' => null, 'phone' => '123456']))->assertStatus(422);
    $this->crmPost('u03', 'leads', $this->leadData(['travel_date' => now()->subDays(2)->toDateString()]))->assertStatus(422);
    $this->crmPost('u03', 'leads', $this->leadData(['source_code' => 'inventado']))->assertStatus(422);
});

it('CRM-BR-07: score inicial', function () {
    expect(LeadService::initialScore(['email' => 'a@b.co']))->toBe(33)
        ->and(LeadService::initialScore(['email' => 'a@b.co', 'phone' => '999', 'travel_date' => '2030-01-01', 'pax' => 2, 'product_id' => 'x', 'company' => 'X', 'source_code' => 'referido']))->toBe(70);
});

it('CRM-T07: alta con el correo de un cliente sin resolución → 409 CRM-BR-04 con coincidencia client', function () {
    $client = $this->makeClient('u04', ['email' => 'ana.g@correo.test']);
    $this->crmPost('u03', 'leads', $this->leadData(['email' => '  ANA.G@correo.test ']))
        ->assertStatus(409)->assertJsonPath('code', 'CRM-BR-04')
        ->assertJsonPath('duplicates.0.type', 'client')->assertJsonPath('duplicates.0.code', $client->code);
});

it('CRM-BR-03: coincide por los últimos 9 dígitos del teléfono', function () {
    $lead = $this->makeLead('u03', ['email' => null, 'phone' => '+51 987-654-321']);
    $this->as('u03')->getJson('/api/v1/crm/leads/duplicates?phone=987654321')->assertOk()
        ->assertJsonPath('data.0.code', $lead->code)->assertJsonPath('data.0.fields', ['teléfono']);
});

it('CRM-T08: resoluciones merge, mark_duplicate y create_new', function () {
    $existing = $this->makeLead('u03', ['email' => 'dup@correo.test', 'company' => null]);
    $before = Lead::query()->count();

    $merged = $this->crmPost('u03', 'leads', $this->leadData(['email' => 'dup@correo.test', 'company' => 'Nueva SAC', 'duplicate_resolution' => 'merge']))->assertOk();
    expect($merged->json('meta.merged'))->toBeTrue()
        ->and($merged->json('data.code'))->toBe($existing->code)
        ->and($merged->json('data.score'))->toBe($existing->score + 5)
        ->and($merged->json('data.company'))->toBe('Nueva SAC')
        ->and($this->admin()->table('crm_leads')->count())->toBe(1);

    $dup = $this->crmPost('u03', 'leads', $this->leadData(['email' => 'dup@correo.test', 'duplicate_resolution' => 'mark_duplicate']))->assertCreated();
    expect($dup->json('data.status'))->toBe('duplicado')->and($dup->json('data.duplicate_of.id'))->toBe($existing->id);

    $new = $this->crmPost('u03', 'leads', $this->leadData(['email' => 'dup@correo.test', 'duplicate_resolution' => 'create_new']))->assertCreated();
    expect($new->json('data.status'))->toBe('nuevo')->and($new->json('data.notes'))->toContain("Registrado pese a coincidencia con {$existing->code}");
});

it('CRM-T09: dos asesores toman el mismo lead → uno 200, otro 409; una actividad; aviso a Managers', function () {
    $lead = $this->makeLead(null);

    $this->crmPost('u03', "leads/{$lead->code}/take")->assertOk()->assertJsonPath('data.owner.id', $this->users['u03']->id);
    $this->crmPost('u04', "leads/{$lead->code}/take")->assertStatus(409)->assertJsonPath('code', 'CRM-BR-08')
        ->assertJsonPath('message', 'Otro asesor tomó el lead antes que usted');

    $activities = $this->admin()->table('crm_activities')->where('related_id', $lead->id)->get();
    expect($activities)->toHaveCount(1)->and($activities[0]->subject)->toBe('Primer contacto — Valeria Campos')
        ->and($activities[0]->owner_id)->toBe($this->users['u03']->id);
    expect($this->admin()->table('notif_messages')->where('event', 'lead.tomado')->where('user_id', $this->users['manager']->id)->exists())->toBeTrue();
    expect(collect($this->auditRows('Lead', $lead->code))->pluck('action'))->toContain('tomar_lead');
});

it('CRM-T09: la toma es atómica aunque ambos lean el lead libre', function () {
    $lead = $this->makeLead(null);
    $stale = $this->inTenant(fn () => Lead::find($lead->id));
    $this->inTenant(fn () => app(LeadService::class)->take(Lead::find($lead->id), $this->users['u03']), 'u03');

    expect(fn () => $this->inTenant(fn () => app(LeadService::class)->take($stale, $this->users['u04']), 'u04'))
        ->toThrow(ApiException::class, 'Otro asesor tomó el lead antes que usted');
});

it('CRM-T10: Ventas no puede asignar ni reasignar', function () {
    $lead = $this->makeLead('u03');
    $this->crmPost('u03', "leads/{$lead->code}/assign", ['owner_id' => $this->users['u04']->id], $this->ifMatch($lead))->assertForbidden();
    $this->crmPost('manager', "leads/{$lead->code}/assign", ['owner_id' => $this->users['u04']->id, 'schedule_first_contact' => true], $this->ifMatch($lead))
        ->assertOk()->assertJsonPath('data.owner.id', $this->users['u04']->id);
    expect($this->admin()->table('notif_messages')->where('event', 'lead.reasignado')->where('user_id', $this->users['u04']->id)->exists())->toBeTrue();
});

it('CRM-T11: reglas de cambio de estado', function () {
    $lead = $this->makeLead('u03', ['travel_date' => null, 'pax' => 0]);
    expect($lead->score)->toBeLessThan(50);
    $this->crmPost('u03', "leads/{$lead->code}/status", ['status' => 'calificado'], $this->ifMatch($lead))->assertStatus(409)->assertJsonPath('code', 'CRM-BR-10');

    $queued = $this->makeLead(null);
    $this->crmPost('manager', "leads/{$queued->code}/status", ['status' => 'contactado'], $this->ifMatch($queued))->assertStatus(409)->assertJsonPath('code', 'CRM-BR-11');

    $this->crmPost('u03', "leads/{$lead->code}/status", ['status' => 'convertido'], $this->ifMatch($lead))->assertStatus(422);

    $this->crmPost('u03', "leads/{$lead->code}/status", ['status' => 'contactado', 'note' => 'Respondió WhatsApp'], $this->ifMatch($lead))
        ->assertOk()->assertJsonPath('data.status', 'contactado');
    expect($this->fresh('crm_leads', $lead->id)->first_contact_at)->not->toBeNull();
});

it('SEC-BR-17: las acciones del lead exigen If-Match vigente', function () {
    $lead = $this->makeLead('u03');
    $this->crmPost('u03', "leads/{$lead->code}/status", ['status' => 'contactado'])->assertStatus(428);
    $this->crmPost('u03', "leads/{$lead->code}/status", ['status' => 'contactado'], ['If-Match' => '99'])->assertStatus(409)->assertJsonPath('code', 'STALE_VERSION');
});

it('CRM-T12: BANT 25+25+18+10 con mark_qualified → score 78 y calificado', function () {
    $lead = $this->makeLead('u03');
    $response = $this->crmPost('u03', "leads/{$lead->code}/qualify", [
        'budget' => 25, 'authority' => 25, 'need' => 18, 'timeline' => 10, 'mark_qualified' => true, 'declared_amount' => 9000, 'currency' => 'PEN',
    ], $this->ifMatch($lead))->assertOk();

    expect($response->json('data.score'))->toBe(78)->and($response->json('data.status'))->toBe('calificado')
        ->and($response->json('meta.reached_threshold'))->toBeTrue()
        ->and($this->admin()->table('crm_lead_qualifications')->where('lead_id', $lead->id)->value('score'))->toBe(78);

    $this->crmPost('u03', "leads/{$lead->code}/qualify", ['budget' => 15, 'authority' => 0, 'need' => 0, 'timeline' => 0], ['If-Match' => (string) $response->json('data.lock_version')])->assertStatus(422);
});

function qualifiedLead($test, string $owner = 'u03', array $overrides = []): Lead
{
    $lead = $test->makeLead($owner, $overrides);
    $test->crmPost($owner, "leads/{$lead->code}/qualify", ['budget' => 25, 'authority' => 25, 'need' => 18, 'timeline' => 10, 'mark_qualified' => true], ['If-Match' => (string) $lead->lock_version])->assertOk();

    return $test->inTenant(fn () => Lead::find($lead->id));
}

function conversionPayload(array $client = [], array $opportunity = []): array
{
    return [
        'client_mode' => 'new',
        'client' => $client + ['kind' => 'persona', 'doc_type' => 'DNI', 'doc_number' => '45678912'],
        'opportunity' => $opportunity + ['title' => 'Cusco 5D/4N', 'amount' => 7800, 'currency' => 'PEN', 'expected_close_date' => now()->addDays(30)->toDateString()],
        'schedule_discovery_meeting' => true,
    ];
}

it('CRM-T13: convertir un lead contactado → 409 CRM-BR-12', function () {
    $lead = $this->makeLead('u03');
    $this->crmPost('u03', "leads/{$lead->code}/convert", conversionPayload(), $this->idem())->assertStatus(409)->assertJsonPath('code', 'CRM-BR-12');
});

it('CRM-T14: documentos inválidos o repetidos en la conversión', function () {
    $lead = qualifiedLead($this);
    $this->crmPost('u03', "leads/{$lead->code}/convert", conversionPayload(['doc_number' => '1234567']), $this->idem())->assertStatus(422);
    $this->crmPost('u03', "leads/{$lead->code}/convert", conversionPayload(['doc_type' => 'RUC', 'doc_number' => '30123456789']), $this->idem())->assertStatus(422);
    $this->crmPost('u03', "leads/{$lead->code}/convert", conversionPayload(['kind' => 'empresa', 'doc_number' => '45678912']), $this->idem())->assertStatus(422);

    $this->makeClient('u04', ['doc_number' => '45678912']);
    $this->crmPost('u03', "leads/{$lead->code}/convert", conversionPayload(), $this->idem())->assertStatus(409)->assertJsonPath('code', 'CRM-BR-16');

    expect($this->fresh('crm_leads', $lead->id)->status)->toBe('calificado');
});

it('CRM-T15: conversión repetida con la misma Idempotency-Key crea una sola vez', function () {
    $lead = qualifiedLead($this, 'u03', ['interest_code' => 'promocion']);
    $key = $this->idem('conv-1');

    $first = $this->crmPost('u03', "leads/{$lead->code}/convert", conversionPayload(), $key)->assertCreated();
    $second = $this->crmPost('u03', "leads/{$lead->code}/convert", conversionPayload(), $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('data.opportunity.id'))->toBe($first->json('data.opportunity.id'))
        ->and($this->admin()->table('crm_clients')->count())->toBe(1)
        ->and($this->admin()->table('crm_opportunities')->count())->toBe(1)
        ->and($this->admin()->table('crm_activities')->where('type', 'reunion')->count())->toBe(1);

    $row = $this->fresh('crm_leads', $lead->id);
    expect($row->status)->toBe('convertido')
        ->and($row->converted_client_id)->toBe($first->json('data.client.id'))
        ->and($row->converted_opportunity_id)->toBe($first->json('data.opportunity.id'))
        ->and($first->json('data.client.segment.code'))->toBe('grupos')
        ->and($first->json('data.client.tags'))->toBe(['Formulario web'])
        ->and($first->json('data.opportunity.stage'))->toBe('descubrimiento')
        ->and($first->json('data.opportunity.probability'))->toBe(35);

    $actions = collect($this->admin()->table('audit_events')->whereIn('entity', ['Lead', 'Cliente', 'Oportunidad'])->get())->map(fn ($r) => "{$r->entity} {$r->action}");
    expect($actions)->toContain('Cliente crear', 'Lead convertir', 'Oportunidad crear');
});

it('CRM-BR-18: vincular a cliente existente agrega el correo del lead como contacto', function () {
    $client = $this->makeClient('u03', ['email' => 'otro@correo.test']);
    $lead = qualifiedLead($this, 'u03', ['email' => 'nuevo.contacto@correo.test']);

    $this->crmPost('u03', "leads/{$lead->code}/convert", ['client_mode' => 'existing', 'client_id' => $client->id] + conversionPayload(), $this->idem())
        ->assertCreated()->assertJsonPath('data.client_created', false);

    $contact = $this->admin()->table('crm_client_contacts')->where('client_id', $client->id)->first();
    expect($contact->email)->toBe('nuevo.contacto@correo.test')->and($contact->is_primary)->toBeTrue()->and($contact->role)->toBe("Contacto (desde {$lead->code})");
});

it('CRM-T16: descartar un lead cierra sus actividades pendientes', function () {
    $lead = $this->makeLead(null);
    $this->crmPost('u03', "leads/{$lead->code}/take")->assertOk();
    $this->crmPost('u03', 'activities', ['type' => 'email', 'subject' => 'Enviar brochure', 'related_type' => 'lead', 'related_id' => $lead->id, 'due_at' => now()->addDay()->toIso8601String()])->assertCreated();
    $lead = $this->inTenant(fn () => Lead::find($lead->id));

    $this->crmPost('u03', "leads/{$lead->code}/discard", ['reason_code' => 'sin_interes', 'detail' => 'Solo cotizaba'], $this->ifMatch($lead))
        ->assertOk()->assertJsonPath('data.status', 'descartado')->assertJsonPath('data.discard.step', 6);

    $activities = $this->admin()->table('crm_activities')->where('related_id', $lead->id)->get();
    expect($activities)->toHaveCount(2)
        ->and($activities->every(fn ($a) => $a->status === 'completada' && $a->auto_closed && $a->result === 'Cerrada automáticamente: lead descartado'))->toBeTrue();

    $lead = $this->inTenant(fn () => Lead::find($lead->id));
    $this->crmPost('u03', "leads/{$lead->code}/reopen", [], $this->ifMatch($lead))->assertOk()->assertJsonPath('data.status', 'nuevo');
});

it('fusionar en este lead mueve actividades y marca el origen como duplicado', function () {
    $target = $this->makeLead('u03', ['email' => 'mismo@correo.test', 'pax' => 0]);
    $source = $this->makeLead('u03', ['email' => 'mismo@correo.test', 'pax' => 4, 'travel_date' => now()->addDays(10)->toDateString()]);
    $this->crmPost('u03', 'activities', ['type' => 'llamada', 'subject' => 'Llamar', 'related_type' => 'lead', 'related_id' => $source->id, 'due_at' => now()->addDay()->toIso8601String()])->assertCreated();

    $this->crmPost('u03', "leads/{$target->code}/merge", ['source_lead_id' => $source->id])->assertOk()->assertJsonPath('data.pax', 4);

    expect($this->fresh('crm_leads', $source->id)->status)->toBe('duplicado')
        ->and($this->admin()->table('crm_activities')->where('related_id', $target->id)->where('merged_from_lead_id', $source->id)->count())->toBe(1);
});

it('la ficha del lead trae BANT, actividades, historial y permisos de UI', function () {
    $lead = $this->makeLead(null);
    $this->crmPost('u03', "leads/{$lead->code}/take")->assertOk();

    $this->as('u03')->getJson("/api/v1/crm/leads/{$lead->code}")->assertOk()
        ->assertJsonPath('data.flow.step', 6)
        ->assertJsonPath('meta.permissions.manage', true)
        ->assertJsonPath('meta.permissions.assign', false)
        ->assertJsonCount(1, 'data.activities')
        ->assertJsonPath('data.history.0.action', 'tomar_lead');
});

it('los KPIs de leads respetan el alcance de Ventas', function () {
    $this->makeLead('u03');
    $this->makeLead('u04');
    $this->makeLead(null);

    expect($this->as('u03')->getJson('/api/v1/crm/leads')->json('meta.kpis.uncontacted'))->toBe(2)
        ->and($this->as('manager')->getJson('/api/v1/crm/leads')->json('meta.kpis.uncontacted'))->toBe(3)
        ->and($this->as('manager')->getJson('/api/v1/crm/leads?filter[owner]=none')->json('data'))->toHaveCount(1);
});
