<?php

use App\Services\Crm\Linked\LinkedData;
use App\Services\Crm\Linked\NullLinkedData;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->crmWorld());

/** Reservas/cotizaciones simuladas hasta que existan sus módulos. */
function fakeLinked(array $reservations = [], array $quotes = []): void
{
    app()->instance(LinkedData::class, new class($reservations, $quotes) extends NullLinkedData
    {
        public function __construct(private array $reservations, private array $quotes) {}

        public function reservation(string $id): ?array
        {
            return collect($this->reservations)->firstWhere('id', $id);
        }

        public function clientIdsWithRecordsOf(string $userId): array
        {
            return collect($this->quotes)->where('owner_id', $userId)->pluck('client_id')->unique()->values()->all();
        }

        public function clientTab(string $clientId, string $tab, ?string $ownerId): array
        {
            return $tab === 'quotes'
                ? collect($this->quotes)->where('client_id', $clientId)->when($ownerId, fn ($c) => $c->where('owner_id', $ownerId))->values()->all()
                : [];
        }
    });
}

it('CRM-T25: NPS 3 sobre reserva completada → encuesta, caso alta, actividad mañana 10:00 y Email', function () {
    $client = $this->makeClient('u03');
    fakeLinked([['id' => $rid = (string) Str::uuid(), 'code' => 'RES-2026-000347', 'client_id' => $client->id, 'status' => 'completada', 'owner_id' => $this->users['u03']->id]]);

    $response = $this->crmPost('u03', 'surveys', ['client_id' => $client->id, 'reservation_id' => $rid, 'channel_code' => 'llamada', 'score' => 3, 'comment' => 'Hotel sucio'], $this->idem())->assertCreated();

    expect($response->json('data.survey.category'))->toBe('detractor')
        ->and($response->json('data.case.priority'))->toBe('alta')
        ->and($response->json('data.case.subject'))->toBe('Detractor NPS 3 — RES-2026-000347')
        ->and($response->json('data.case.owner.id'))->toBe($this->users['u03']->id)
        ->and($response->json('data.activity.type'))->toBe('postventa');

    $due = Carbon::parse($response->json('data.activity.due_at'))->setTimezone('America/Lima');
    expect($due->toDateString())->toBe(now('America/Lima')->addDay()->toDateString())->and($due->format('H:i'))->toBe('10:00')
        ->and($this->fresh('crm_clients', $client->id)->nps_last)->toBe(3)
        ->and($this->admin()->table('notif_messages')->where('event', 'postventa.detractor')->where('channel', 'email')->exists())->toBeTrue();
});

it('CRM-T26: segunda encuesta a la misma reserva → 409 CRM-BR-41', function () {
    $client = $this->makeClient('u03');
    fakeLinked([['id' => $rid = (string) Str::uuid(), 'code' => 'RES-1', 'client_id' => $client->id, 'status' => 'completada', 'owner_id' => $this->users['u03']->id]]);
    $this->crmPost('u03', 'surveys', ['client_id' => $client->id, 'reservation_id' => $rid, 'channel_code' => 'email', 'score' => 9], $this->idem())->assertCreated();
    $this->crmPost('u03', 'surveys', ['client_id' => $client->id, 'reservation_id' => $rid, 'channel_code' => 'email', 'score' => 8], $this->idem())
        ->assertStatus(409)->assertJsonPath('code', 'CRM-BR-41');
});

it('CRM-T27: resolver sin texto → 422; cerrar desde abierta → 409; Felicitación nace cerrada', function () {
    $client = $this->makeClient('u03');
    $case = $this->crmPost('u03', 'cases', ['client_id' => $client->id, 'type_code' => 'reclamo', 'priority' => 'media', 'subject' => 'Demora', 'description' => 'Bus llegó tarde'], $this->idem())
        ->assertCreated();
    $code = $case->json('data.code');
    $lock = ['If-Match' => (string) $case->json('data.lock_version')];

    $this->crmPost('u03', "cases/{$code}/resolve", [], $lock)->assertStatus(422);
    $this->crmPost('u03', "cases/{$code}/close", [], $lock)->assertStatus(409);

    $praise = $this->crmPost('u03', 'cases', ['client_id' => $client->id, 'type_code' => 'felicitacion', 'priority' => 'baja', 'subject' => 'Gracias', 'description' => 'Todo excelente'], $this->idem());
    expect($praise->json('data.status'))->toBe('cerrada');

    // Ciclo completo: iniciar → seguimiento → resolver → cerrar (Email al cliente).
    $started = $this->crmPost('u03', "cases/{$code}/start", [], $lock)->assertOk();
    $this->crmPost('u03', "cases/{$code}/follow-ups", ['message' => 'Se llamó al proveedor'])->assertCreated();
    $resolved = $this->crmPost('u03', "cases/{$code}/resolve", ['resolution' => 'Se reembolsó el traslado'], ['If-Match' => (string) $started->json('data.lock_version')])->assertOk();
    $this->crmPost('u03', "cases/{$code}/close", [], ['If-Match' => (string) $resolved->json('data.lock_version')])->assertOk()->assertJsonPath('data.status', 'cerrada');

    $events = $this->as('u03')->getJson("/api/v1/crm/cases/{$code}")->json('data.events');
    expect(collect($events)->pluck('event')->all())->toBe(['registro', 'estado', 'seguimiento', 'estado', 'estado'])
        ->and($this->admin()->table('notif_messages')->where('event', 'postventa.cierre')->exists())->toBeTrue();
});

it('CRM-T28: Ventas en el 360 de un cliente compartido ve solo lo suyo con vista parcial', function () {
    $client = $this->makeClient('u04');
    $mine = $this->makeOpportunity('u03', $client);
    $theirs = $this->makeOpportunity('u04', $client);
    fakeLinked([], [
        ['id' => 'q1', 'code' => 'COT-2026-00001', 'client_id' => $client->id, 'owner_id' => $this->users['u03']->id],
        ['id' => 'q2', 'code' => 'COT-2026-00002', 'client_id' => $client->id, 'owner_id' => $this->users['u04']->id],
    ]);

    $this->as('u03')->getJson("/api/v1/crm/clients/{$client->code}")->assertOk()->assertJsonPath('data.partial_view', true);
    $opps = $this->as('u03')->getJson("/api/v1/crm/clients/{$client->code}/opportunities")->assertOk()->json('data');
    expect(collect($opps)->pluck('code')->all())->toBe([$mine->code]);
    $quotes = $this->as('u03')->getJson("/api/v1/crm/clients/{$client->code}/quotes")->json('data');
    expect(collect($quotes)->pluck('code')->all())->toBe(['COT-2026-00001']);

    // Un cliente sin registros suyos queda fuera de su cartera.
    $other = $this->makeClient('u04');
    $this->as('u03')->getJson("/api/v1/crm/clients/{$other->code}")->assertForbidden()->assertJsonPath('code', 'SCOPE_OWNERSHIP');
    expect(collect($this->as('u03')->getJson('/api/v1/crm/clients')->json('data'))->pluck('code')->all())->toBe([$client->code]);
    $this->as('manager')->getJson("/api/v1/crm/clients/{$client->code}/opportunities")->assertJsonCount(2, 'data');
});

it('CRM-BR-16/17: documento único bloqueante y correo repetido como advertencia', function () {
    $existing = $this->makeClient('u03', ['doc_number' => '45678912', 'email' => 'mismo@correo.test']);
    $base = ['kind' => 'persona', 'name' => 'Otra Persona', 'doc_type' => 'DNI', 'segment_code' => 'retail'];

    $this->crmPost('u03', 'clients', $base + ['doc_number' => '45678912', 'email' => 'x@correo.test'])->assertStatus(409)
        ->assertJsonPath('code', 'CRM-BR-16')->assertJsonPath('client.code', $existing->code);
    $this->crmPost('u03', 'clients', $base + ['doc_number' => '11112222', 'email' => 'MISMO@correo.test'])->assertStatus(409)->assertJsonPath('code', 'CRM-BR-17');
    $this->crmPost('u03', 'clients', $base + ['doc_number' => '11112222', 'email' => 'mismo@correo.test', 'confirm_duplicate' => true])->assertCreated();
    $this->crmPost('u03', 'clients', ['kind' => 'empresa', 'name' => 'Empresa', 'doc_type' => 'RUC', 'doc_number' => '20123456789', 'segment_code' => 'corporativo'])->assertStatus(422);
});

it('CRM-BR-35/36: contactos y comunicaciones del 360', function () {
    $client = $this->makeClient('u03', ['phone' => null]);
    $first = $this->crmPost('u03', "clients/{$client->code}/contacts", ['name' => 'Pedro'])->assertCreated()->assertJsonPath('data.is_primary', true);
    $second = $this->crmPost('u03', "clients/{$client->code}/contacts", ['name' => 'Lucía'])->assertCreated()->assertJsonPath('data.is_primary', false);
    $this->crmPost('u03', "clients/{$client->code}/contacts/{$second->json('data.id')}/make-primary")->assertOk();
    expect($this->fresh('crm_client_contacts', $first->json('data.id'))->is_primary)->toBeFalse();

    $this->crmPost('u03', "clients/{$client->code}/communications", ['channel' => 'whatsapp', 'subject' => 'Hola'], $this->idem())->assertStatus(422);
    $this->crmPost('u03', "clients/{$client->code}/communications", ['channel' => 'email', 'subject' => 'Su itinerario', 'body' => 'Adjuntamos…'], $this->idem())->assertStatus(202);
    $this->as('u03')->getJson("/api/v1/crm/clients/{$client->code}/communications")->assertOk()->assertJsonPath('data.0.subject', 'Su itinerario');
});

it('CRM-BR-45: propuesta de recompra y oportunidad de Fidelización auditada en el cliente', function () {
    $client = $this->makeClient('u03');
    $this->makeOpportunity('u03', $client, ['destination_label' => 'Cusco y Machu Picchu']);
    $proposal = $this->as('u03')->getJson("/api/v1/crm/clients/{$client->code}/rebuy-proposal")->assertOk();
    expect($proposal->json('data.proposal.amount'))->toEqual(4000)->and($proposal->json('data.requires_confirmation'))->toBeTrue();

    $data = $proposal->json('data.proposal');
    $this->crmPost('u03', 'opportunities', $data)->assertCreated()->assertJsonPath('data.origin', 'Fidelización');
    expect(collect($this->auditRows('Cliente', $client->code))->pluck('action'))->toContain('accion_fidelizacion');
});

it('NPS de encuestas y segmentación', function () {
    $a = $this->makeClient('u03');
    $b = $this->makeClient('u03', ['segment_code' => 'vip']);
    foreach ([[$a, 10], [$b, 9], [$a, 5]] as [$client, $score]) {
        $this->crmPost('u03', 'surveys', ['client_id' => $client->id, 'channel_code' => 'web', 'score' => $score], $this->idem())->assertCreated();
    }
    $stats = $this->as('u03')->getJson('/api/v1/crm/surveys')->assertOk()->json('meta.stats');
    expect($stats['nps'])->toBe(33)->and($stats['detractors'])->toBe(1)->and($stats['distribution'][10])->toBe(1);

    $rows = $this->as('u03')->getJson('/api/v1/crm/loyalty/segments?filter[segment]=vip&filter[recency]=none')->assertOk()->json('data');
    expect(collect($rows)->pluck('code')->all())->toBe([$b->code]);
});
