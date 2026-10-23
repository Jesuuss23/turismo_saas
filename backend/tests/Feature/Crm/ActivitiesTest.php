<?php

use App\Services\Crm\ActivityService;
use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => $this->crmWorld());

it('CRM-T22: completar una llamada de un lead nuevo con próxima actividad', function () {
    $lead = $this->makeLead(null);
    $this->crmPost('u03', "leads/{$lead->code}/take")->assertOk();
    $activity = $this->admin()->table('crm_activities')->where('related_id', $lead->id)->first();

    $response = $this->crmPost('u03', "activities/{$activity->code}/complete", [
        'result' => 'Conversamos 10 minutos, quiere opciones para julio',
        'next' => ['subject' => 'Enviar propuesta', 'due_at' => now()->addDays(2)->toIso8601String()],
    ], $this->idem())->assertOk();

    expect($response->json('data.activity.status'))->toBe('completada')
        ->and($response->json('data.next.previous_activity_id'))->toBe($activity->id)
        ->and($response->json('data.next.related'))->toBe(['type' => 'lead', 'id' => $lead->id])
        ->and($response->json('data.next.owner.id'))->toBe($this->users['u03']->id)
        ->and($response->json('data.lead_contacted'))->toBeTrue()
        ->and($this->fresh('crm_leads', $lead->id)->status)->toBe('contactado')
        ->and(collect($this->auditRows('Lead', $lead->code))->pluck('action'))->toContain('primer_contacto');
});

it('CRM-T22: la misma Idempotency-Key no crea dos próximas actividades', function () {
    $activity = $this->inTenant(fn () => app(ActivityService::class)->create(['type' => 'tarea', 'subject' => 'X', 'due_at' => now()->addHour()->toIso8601String()], $this->users['u03']), 'u03');
    $body = ['result' => 'Listo', 'next' => ['subject' => 'Seguimiento', 'due_at' => now()->addDays(2)->toIso8601String()]];
    $key = $this->idem();
    $this->crmPost('u03', "activities/{$activity->code}/complete", $body, $key)->assertOk();
    $this->crmPost('u03', "activities/{$activity->code}/complete", $body, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    expect($this->admin()->table('crm_activities')->where('previous_activity_id', $activity->id)->count())->toBe(1);
});

it('CRM-T23: completar sin resultado → 422; Ventas no completa actividades ajenas → 403', function () {
    $mine = $this->inTenant(fn () => app(ActivityService::class)->create(['type' => 'llamada', 'subject' => 'Llamar', 'due_at' => now()->addHour()->toIso8601String()], $this->users['u03']), 'u03');
    $foreign = $this->inTenant(fn () => app(ActivityService::class)->create(['type' => 'llamada', 'subject' => 'Llamar', 'due_at' => now()->addHour()->toIso8601String()], $this->users['u04']), 'u04');

    $this->crmPost('u03', "activities/{$mine->code}/complete", [], $this->idem())->assertStatus(422);
    $this->crmPost('u03', "activities/{$foreign->code}/complete", ['result' => 'ok'], $this->idem())->assertForbidden()->assertJsonPath('code', 'SCOPE_OWNERSHIP');
    $this->crmPost('manager', "activities/{$foreign->code}/complete", ['result' => 'Cerrada por gerencia'], $this->idem())->assertOk();
});

it('CRM-T24: JOB-04 marca vencidas las pendientes de ayer y notifica al responsable', function () {
    $activity = $this->inTenant(fn () => app(ActivityService::class)->schedule('llamada', 'Llamada de ayer', $this->users['u03']->id, now()->subDay(), null, null, $this->users['u03']), 'u03');

    Artisan::call('crm:activities-remind');

    expect($this->fresh('crm_activities', $activity->id)->status)->toBe('vencida')
        ->and($this->admin()->table('notif_messages')->where('event', 'actividad.recordatorio')->where('user_id', $this->users['u03']->id)->exists())->toBeTrue();
});

it('CRM-BR-31: Ventas solo es responsable de sus actividades; el supervisor puede asignar a Operaciones', function () {
    $due = now()->addHour()->toIso8601String();
    $this->crmPost('u03', 'activities', ['type' => 'tarea', 'subject' => 'X', 'owner_id' => $this->users['u04']->id, 'due_at' => $due])
        ->assertCreated()->assertJsonPath('data.owner.id', $this->users['u03']->id);
    $this->crmPost('manager', 'activities', ['type' => 'tarea', 'subject' => 'Confirmar cupos', 'owner_id' => $this->users['ops']->id, 'due_at' => $due])
        ->assertCreated()->assertJsonPath('data.owner.id', $this->users['ops']->id);
    $this->crmPost('manager', 'activities', ['type' => 'tarea', 'subject' => 'X', 'owner_id' => $this->users['finance']->id, 'due_at' => $due])->assertStatus(422);
    expect($this->admin()->table('notif_messages')->where('event', 'actividad.asignada')->where('user_id', $this->users['ops']->id)->exists())->toBeTrue();
});

it('CRM-BR-28: la relación debe existir y ser visible para el actor', function () {
    $foreign = $this->makeLead('u04');
    $due = now()->addHour()->toIso8601String();
    $this->crmPost('u03', 'activities', ['type' => 'llamada', 'subject' => 'X', 'related_type' => 'lead', 'due_at' => $due])->assertStatus(422);
    $this->crmPost('u03', 'activities', ['type' => 'llamada', 'subject' => 'X', 'related_type' => 'lead', 'related_id' => $foreign->id, 'due_at' => $due])->assertStatus(422);
});

it('reprogramar exige fecha futura y vuelve a pendiente; reasignar es de supervisores', function () {
    $activity = $this->inTenant(fn () => app(ActivityService::class)->schedule('llamada', 'Llamar', $this->users['u03']->id, now()->subHour(), null, null, $this->users['u03']), 'u03');
    $this->crmPost('u03', "activities/{$activity->code}/reschedule", ['due_at' => now()->subDay()->toIso8601String()])->assertStatus(422);
    $this->crmPost('u03', "activities/{$activity->code}/reschedule", ['due_at' => now()->addDay()->toIso8601String(), 'reason' => 'Cliente de viaje'])
        ->assertOk()->assertJsonPath('data.status', 'pendiente');
    $this->crmPost('u03', "activities/{$activity->code}/reassign", ['owner_id' => $this->users['u04']->id])->assertForbidden();
    $this->crmPost('admin', "activities/{$activity->code}/reassign", ['owner_id' => $this->users['u04']->id])->assertOk();
});

it('la agenda calcula KPIs y filtra por estado derivado', function () {
    $svc = app(ActivityService::class);
    $this->inTenant(function () use ($svc) {
        $svc->schedule('llamada', 'Vencida', $this->users['u03']->id, now()->subHours(3), null, null, $this->users['u03']);
        $svc->schedule('llamada', 'Próxima', $this->users['u03']->id, now()->addDays(3), null, null, $this->users['u03']);
        $svc->schedule('llamada', 'De otro', $this->users['u04']->id, now()->subHours(3), null, null, $this->users['u04']);
    }, 'u03');

    $response = $this->as('u03')->getJson('/api/v1/crm/activities?filter[status]=vencida')->assertOk();
    expect($response->json('data'))->toHaveCount(1)->and($response->json('data.0.status'))->toBe('vencida')
        ->and($response->json('meta.kpis.overdue'))->toBe(1)->and($response->json('meta.kpis.next_7_days'))->toBe(1);
});
