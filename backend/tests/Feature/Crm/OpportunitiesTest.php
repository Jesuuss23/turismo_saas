<?php

use App\Events\Quotes\QuoteConverted;
use App\Events\Quotes\QuoteCreated;
use App\Events\Quotes\QuoteNegotiationRecorded;
use App\Models\Crm\Opportunity;

beforeEach(function () {
    $this->crmWorld();
    $this->client = $this->makeClient('u03');
});

it('CRM-T17: perder exige motivo y "Otro" exige detalle', function () {
    $opp = $this->makeOpportunity('u03', $this->client);
    $this->crmPost('u03', "opportunities/{$opp->code}/stage", ['stage' => 'perdida'], $this->ifMatch($opp))->assertStatus(422);
    $this->crmPost('u03', "opportunities/{$opp->code}/stage", ['stage' => 'perdida', 'lost_reason_code' => 'otro'], $this->ifMatch($opp))->assertStatus(422);
    $this->crmPost('u03', "opportunities/{$opp->code}/stage", ['stage' => 'perdida', 'lost_reason_code' => 'otro', 'lost_detail' => 'Viajó por su cuenta'], $this->ifMatch($opp))
        ->assertOk()->assertJsonPath('data.probability', 0)->assertJsonPath('data.lost.at_stage', 'descubrimiento');
    expect($this->admin()->table('notif_messages')->where('event', 'oportunidad.perdida')->exists())->toBeTrue();
});

it('CRM-T18: ganar sin cotización aprobada → 200 con advertencia, prob 100 y cierre hoy', function () {
    $opp = $this->makeOpportunity('u03', $this->client);
    $response = $this->crmPost('u03', "opportunities/{$opp->code}/stage", ['stage' => 'ganada'], $this->ifMatch($opp))->assertOk();

    expect($response->json('meta.warnings'))->toHaveCount(1)
        ->and($response->json('data.probability'))->toBe(100)
        ->and($response->json('data.closed_at'))->toBe(now('America/Lima')->toDateString());
});

it('CRM-T19: reabrir una perdida desde cotización vuelve a cotización con 55 % y sin motivo', function () {
    $opp = $this->makeOpportunity('u03', $this->client, ['stage' => 'cotizacion']);
    $lost = $this->crmPost('u03', "opportunities/{$opp->code}/stage", ['stage' => 'perdida', 'lost_reason_code' => 'precio'], $this->ifMatch($opp))->assertOk();
    expect($lost->json('data.reopen_stage'))->toBe('cotizacion');

    $headers = ['If-Match' => (string) $lost->json('data.lock_version')];
    $this->crmPost('u03', "opportunities/{$opp->code}/stage", ['stage' => 'cotizacion'], $headers)->assertStatus(409)->assertJsonPath('code', 'CRM-BR-26');
    $this->crmPost('u03', "opportunities/{$opp->code}/stage", ['stage' => 'cotizacion', 'confirm_reopen' => true], $headers)
        ->assertOk()->assertJsonPath('data.stage', 'cotizacion')->assertJsonPath('data.probability', 55)
        ->assertJsonPath('data.lost', null)->assertJsonPath('data.closed_at', null);
});

it('CRM-T20: la oportunidad sigue a su cotización (cotizacion → negociacion → ganada) y queda auditada', function () {
    $opp = $this->makeOpportunity('u03', $this->client, ['stage' => 'descubrimiento']);

    $this->inTenant(function () use ($opp) {
        QuoteCreated::dispatch('q-1', $opp->id);
        expect(Opportunity::find($opp->id))->stage->toBe('cotizacion')->probability->toBe(55);
        QuoteNegotiationRecorded::dispatch('q-1', $opp->id);
        expect(Opportunity::find($opp->id))->stage->toBe('negociacion')->probability->toBe(70);
        QuoteConverted::dispatch('q-1', $opp->id);
        expect(Opportunity::find($opp->id))->stage->toBe('ganada')->probability->toBe(100)->closed_at->not->toBeNull();
    });

    $changes = collect($this->auditRows('Oportunidad', $opp->code))->where('action', 'cambio_etapa');
    expect($changes)->toHaveCount(3)->and($changes->every(fn ($r) => $r->actor_type === 'system'))->toBeTrue();
});

it('CRM-T21: alta con monto 0 o cierre pasado → 422', function () {
    $base = ['client_id' => $this->client->id, 'title' => 'Cusco', 'amount' => 1000, 'currency' => 'PEN', 'expected_close_date' => now()->addDays(5)->toDateString()];
    $this->crmPost('u03', 'opportunities', ['amount' => 0] + $base)->assertStatus(422);
    $this->crmPost('u03', 'opportunities', ['expected_close_date' => now()->subDays(3)->toDateString()] + $base)->assertStatus(422);
    $this->crmPost('u03', 'opportunities', $base)->assertCreated()->assertJsonPath('data.stage', 'calificacion')->assertJsonPath('data.probability', 20);
});

it('CRM-BR-21: Ventas solo crea oportunidades para su cartera y siempre como responsable', function () {
    $foreign = $this->makeClient('u04');
    $base = ['title' => 'Cusco', 'amount' => 1000, 'currency' => 'PEN', 'expected_close_date' => now()->addDays(5)->toDateString()];
    $this->crmPost('u03', 'opportunities', ['client_id' => $foreign->id] + $base)->assertForbidden()->assertJsonPath('code', 'SCOPE_OWNERSHIP');
    $this->crmPost('u03', 'opportunities', ['client_id' => $this->client->id, 'owner_id' => $this->users['u04']->id] + $base)
        ->assertCreated()->assertJsonPath('data.owner.id', $this->users['u03']->id);
});

it('Ventas solo ve sus oportunidades; el kanban agrupa por etapa con totales en PEN', function () {
    $this->makeOpportunity('u03', $this->client, ['amount' => 1000, 'currency' => 'USD']);
    $foreign = $this->makeOpportunity('u04', $this->makeClient('u04'));

    $this->as('u03')->getJson("/api/v1/crm/opportunities/{$foreign->code}")->assertForbidden();
    $board = $this->as('u03')->getJson('/api/v1/crm/opportunities?group_by=stage')->assertOk();
    $discovery = collect($board->json('data'))->firstWhere('stage', 'descubrimiento');
    expect($discovery['count'])->toBe(1)->and($discovery['total_pen'])->toEqual(3750)
        ->and($board->json('meta.kpis.pipeline_pen'))->toEqual(3750)
        ->and($board->json('meta.kpis.weighted_pen'))->toEqual(1312.5);
});

it('reasignar oportunidad es solo de supervisores y notifica al nuevo asesor', function () {
    $opp = $this->makeOpportunity('u03', $this->client);
    $this->crmPost('u03', "opportunities/{$opp->code}/reassign", ['owner_id' => $this->users['u04']->id], $this->ifMatch($opp))->assertForbidden();
    $this->crmPost('manager', "opportunities/{$opp->code}/reassign", ['owner_id' => $this->users['u04']->id, 'note' => 'Vacaciones'], $this->ifMatch($opp))
        ->assertOk()->assertJsonPath('data.owner.id', $this->users['u04']->id);
    expect(collect($this->auditRows('Oportunidad', $opp->code))->last()->after_value)->toBe('U04 · Vacaciones');
});

it('editar oportunidad cambia datos y probabilidad pero no la etapa', function () {
    $opp = $this->makeOpportunity('u03', $this->client);
    $this->as('u03')->withHeaders($this->ifMatch($opp))->patchJson("/api/v1/crm/opportunities/{$opp->code}", ['amount' => 6000, 'probability' => 50, 'stage' => 'ganada'])
        ->assertOk()->assertJsonPath('data.amount', 6000)->assertJsonPath('data.probability', 50)->assertJsonPath('data.stage', 'descubrimiento');
});
