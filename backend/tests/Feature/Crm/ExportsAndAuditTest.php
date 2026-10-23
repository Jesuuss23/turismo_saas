<?php

use App\Models\Crm\Lead;
use App\Services\Crm\SalesDashboard;
use Illuminate\Support\Arr;

beforeEach(fn () => $this->crmWorld());

it('CRM-T29: exportación CSV de leads con Ventas respeta su alcance y se audita', function () {
    $mine = $this->makeLead('u03', ['full_name' => 'Lead Propio']);
    $this->makeLead('u04', ['full_name' => 'Lead Ajeno']);
    $this->makeLead(null, ['full_name' => '=HYPERLINK("x")']);

    $response = $this->as('u03')->postJson('/api/v1/exports', ['resource' => 'crm.leads'])->assertOk()->assertHeader('X-Export-Rows', '2');
    $csv = $response->getContent();
    expect($csv)->toContain('Lead Propio')->not->toContain('Lead Ajeno')->toContain("'=HYPERLINK");

    $audit = $this->admin()->table('audit_events')->where('action', 'exportar')->first();
    expect($audit->entity)->toBe('Exportación')->and($audit->after_value)->toBe('2 filas')->and($audit->is_critical)->toBeTrue();

    $this->as('finance')->postJson('/api/v1/exports', ['resource' => 'crm.leads'])->assertForbidden();
    $this->as('finance')->postJson('/api/v1/exports', ['resource' => 'crm.clients'])->assertOk();
});

it('exporta la segmentación de fidelización con el alcance de Ventas', function () {
    $this->makeClient('u03', ['name' => 'Cliente Propio']);
    $this->makeClient('u04', ['name' => 'Cliente Ajeno']);

    $csv = $this->as('u03')->postJson('/api/v1/exports', ['resource' => 'crm.loyalty_segments', 'filters' => ['recency' => 'none']])
        ->assertOk()->getContent();
    expect($csv)->toContain('Cliente Propio')->toContain('Días sin comprar')->not->toContain('Cliente Ajeno');

    $this->as('finance')->postJson('/api/v1/exports', ['resource' => 'crm.loyalty_segments'])->assertForbidden();
});

it('CRM-T30: cada acción de §9 deja auditoría con antes/después', function () {
    $lead = $this->makeLead(null, ['email' => 'audit@correo.test']);
    $this->crmPost('u03', "leads/{$lead->code}/take")->assertOk();
    $lead = $this->inTenant(fn () => Lead::find($lead->id));
    $this->as('u03')->withHeaders($this->ifMatch($lead))->patchJson("/api/v1/crm/leads/{$lead->code}", ['pax' => 5])->assertOk();
    $lead = $this->inTenant(fn () => Lead::find($lead->id));
    $this->crmPost('u03', "leads/{$lead->code}/status", ['status' => 'contactado', 'note' => 'ok'], $this->ifMatch($lead))->assertOk();

    $rows = collect($this->auditRows('Lead', $lead->code))->keyBy('action');
    expect($rows->keys()->all())->toContain('crear', 'tomar_lead', 'editar', 'cambio_estado');
    expect($rows['tomar_lead']->before_value)->toBe('sin asignar (cola comercial)')->and($rows['tomar_lead']->after_value)->toBe('U03')
        ->and($rows['editar']->before_value)->toBe('pax')
        ->and($rows['cambio_estado']->before_value)->toBe('nuevo')->and($rows['cambio_estado']->after_value)->toBe('contactado · ok')
        ->and($rows['crear']->actor_type)->toBe('system');

    $client = $this->makeClient('u03');
    $opp = $this->makeOpportunity('u03', $client);
    expect(collect($this->auditRows('Cliente', $client->code))->pluck('action'))->toContain('crear')
        ->and(collect($this->auditRows('Oportunidad', $opp->code))->first()->after_value)->toBe('Descubrimiento · PEN 5,000.00');
});

it('los accesos denegados al CRM se auditan (SEC-BR-07)', function () {
    $this->as('ops')->getJson('/api/v1/crm/opportunities')->assertForbidden();
    expect($this->admin()->table('audit_events')->where('action', 'acceso_denegado')->where('entity_id', '/api/v1/crm/opportunities')->exists())->toBeTrue();
});

it('el panel de Ventas muestra solo "Mi cartera" y la bolsa común', function () {
    $this->makeLead('u03', ['full_name' => 'Mío']);
    $this->makeLead('u04', ['full_name' => 'Ajeno']);
    $pool = $this->makeLead(null, ['full_name' => 'En bolsa']);
    $this->makeOpportunity('u03', $this->makeClient('u03'), ['amount' => 2000]);

    $panel = $this->as('u03')->getJson('/api/v1/dashboard/sales')->assertOk();
    expect(collect($panel->json('data.leads.mine'))->pluck('full_name')->all())->toBe(['Mío'])
        ->and(collect($panel->json('data.leads.pool'))->pluck('code')->all())->toBe([$pool->code])
        ->and($panel->json('data.kpis.pipeline.total_pen'))->toEqual(2000);

    // "Tomar" desde el panel usa el endpoint del CRM y el panel se refresca (cache invalidada).
    $this->crmPost('u03', "leads/{$pool->code}/take")->assertOk();
    $panel = $this->as('u03')->getJson('/api/v1/dashboard/sales')->assertOk();
    expect($panel->json('data.leads.pool'))->toBe([])->and($panel->json('data.activities.due_now'))->toHaveCount(1);

    // Solo escalares: la cache no deserializa objetos (cache.serializable_classes = false).
    $data = $this->inTenant(fn () => app(SalesDashboard::class)->build($this->users['u03']), 'u03');
    expect(collect(Arr::dot($data))->filter(fn ($v) => is_object($v)))->toBeEmpty();
});

it('la búsqueda global aplica la cartera de Ventas (§12-10)', function () {
    $this->makeClient('u04', ['name' => 'Cliente Zeta Ajeno']);
    $mine = $this->makeClient('u03', ['name' => 'Cliente Zeta Propio']);
    $hits = $this->as('u03')->getJson('/api/v1/search?q=zeta')->assertOk()->json('data');
    expect(collect($hits)->pluck('code')->all())->toBe([$mine->code]);
});
