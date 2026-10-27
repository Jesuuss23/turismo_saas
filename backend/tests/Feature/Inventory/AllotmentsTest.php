<?php

use App\Models\Bookings\Reservation;
use App\Services\Catalog\ProviderService;
use App\Services\Catalog\SupplierServices;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->reservationWorld();
    $this->res = $this->makeReservation('u03');
    $this->cup = ['provider' => $this->provider->id, 'tren' => $this->svc['tren']->id, 'res' => $this->res['id']];
});

/** Contrato de cupo sobre el tren del escenario (5 asientos/día, release 7 d, 100 USD, penalidad 50 %). */
function cupContract(object $t, array $overrides = [], string $who = 'ops'): TestResponse
{
    return $t->qPost($who, 'catalog/allotments', $overrides + [
        'provider_id' => $t->cup['provider'], 'service_id' => $t->cup['tren'], 'name' => 'PeruRail temporada', 'contract_ref' => 'CONV-1',
        'valid_from' => now()->subDays(5)->toDateString(), 'valid_to' => now()->addDays(40)->toDateString(), 'base_qty' => 5, 'release_days' => 7,
        'net_rate' => 100, 'currency' => 'USD', 'penalty_pct' => 50,
    ], $t->idem());
}

function cupDay(object $t, string $allotmentId, string $date): object
{
    return DB::connection('pgsql_admin')->table('catalog_allotment_days')->where('allotment_id', $allotmentId)->whereDate('service_date', $date)->first();
}

function cupTrain(object $t): object
{
    return DB::connection('pgsql_admin')->table('booking_services')->where('reservation_id', $t->cup['res'])->where('catalog_service_id', $t->cup['tren'])->first();
}

it('§10-1 RLS: el tenant B no lista ni abre contratos del tenant A', function () {
    $a = cupContract($this)->assertCreated()->json('data');
    $token = $this->tokenFor($this->makeUser($this->makeTenant('inka'), 'operations'));
    expect($this->api($token)->getJson('/api/v1/catalog/allotments')->assertOk()->json('data'))->toBe([]);
    $this->api($token)->getJson("/api/v1/catalog/allotments/{$a['code']}")->assertNotFound();
    $this->api($token)->getJson("/api/v1/catalog/allotments/{$a['id']}")->assertNotFound();
    expect($this->api($token)->getJson('/api/v1/catalog/allotments/grid')->assertOk()->json('data.rows'))->toBe([]);
});

it('§10-2 RBAC: Ventas 403; Finanzas lee pero no crea, edita ni libera', function () {
    $a = cupContract($this)->assertCreated()->json('data');
    $this->qGet('u03', 'catalog/allotments')->assertForbidden();
    $this->qGet('u03', "catalog/allotments/{$a['code']}")->assertForbidden();
    $this->qGet('finance', 'catalog/allotments')->assertOk()->assertJsonPath('meta.can_manage', false);
    $this->qGet('finance', "catalog/allotments/{$a['code']}")->assertOk();
    $this->qGet('finance', 'catalog/allotments/grid')->assertOk();
    cupContract($this, ['valid_from' => now()->addDays(60)->toDateString(), 'valid_to' => now()->addDays(70)->toDateString()], 'finance')->assertForbidden();
    $this->as('finance')->withHeaders(['If-Match' => '0'])->patchJson("/api/v1/catalog/allotments/{$a['code']}", ['base_qty' => 9])->assertForbidden();
    $this->qPost('finance', "catalog/allotments/{$a['code']}/days/".now()->addDays(20)->toDateString().'/release', ['qty' => 1])->assertForbidden();
    $this->qGet('accounting', 'catalog/allotments')->assertForbidden();
});

it('§10-4 CUP-BR-002 servicio de otro proveedor o tipo no admitido → 422; CUP-BR-003 solape → 409; CUP-BR-001 vigencia', function () {
    $hotel = $this->inTenant(function () {
        $providers = app(ProviderService::class);
        $p = $providers->create(['name' => 'Hotel Inti Wasi', 'type' => 'Hotel', 'ruc' => '20100000001', 'contact_name' => 'Recepción', 'currency' => 'USD',
            'contract_start' => now()->subYear()->toDateString(), 'contract_end' => now()->addYear()->toDateString()], $this->users['admin']);
        $providers->activate($p, $this->users['admin']);

        return app(SupplierServices::class)->create(['provider_id' => $p->id, 'name' => 'Doble estándar', 'type' => 'Alojamiento', 'unit' => 'habitación', 'cost' => 95,
            'currency' => 'USD', 'valid_from' => now()->subMonth()->toDateString(), 'valid_to' => now()->addYear()->toDateString()], $this->users['admin'])['service'];
    });
    cupContract($this, ['service_id' => $hotel->id])->assertStatus(422)->assertJsonPath('code', 'CUP-BR-002');
    cupContract($this, ['service_id' => $this->svc['guia']->id])->assertStatus(422)->assertJsonPath('code', 'CUP-BR-002');
    cupContract($this, ['valid_to' => now()->subDays(10)->toDateString()])->assertStatus(422)->assertJsonPath('code', 'CUP-BR-001');

    $a = cupContract($this)->assertCreated()->json('data');
    expect($a['code'])->toBe('CUP-'.now()->year.'-001')->and($a['kind'])->toBe('tren')->and($a['status'])->toBeIn(['activo', 'en_release']);
    expect(DB::connection('pgsql_admin')->table('catalog_allotment_days')->where('allotment_id', $a['id'])->count())->toBe(46);
    cupContract($this, ['valid_from' => now()->addDays(30)->toDateString(), 'valid_to' => now()->addDays(60)->toDateString()])
        ->assertStatus(409)->assertJsonPath('code', 'CUP-BR-003');
    cupContract($this, ['valid_from' => now()->addDays(41)->toDateString(), 'valid_to' => now()->addDays(60)->toDateString()])->assertCreated();
    expect(collect($this->auditRows('Cupo', $a['code']))->pluck('action')->all())->toContain('crear');
});

it('§10-5 CUP-BR-005 solo se libera lo disponible; liberaciones concurrentes → 409', function () {
    $a = cupContract($this)->assertCreated()->json('data');
    $date = now()->addDays(20)->toDateString();
    $url = "catalog/allotments/{$a['code']}/days/{$date}/release";
    $this->qPost('ops', $url, ['qty' => 6])->assertStatus(422)->assertJsonPath('code', 'CUP-BR-005');
    $this->qPost('ops', $url, ['qty' => 2], ['If-Match' => '0'])->assertOk()->assertJsonPath('data.released', 2)->assertJsonPath('data.available', 3);
    // Segunda liberación con la misma versión (la otra pestaña): STALE_VERSION.
    $this->qPost('ops', $url, ['qty' => 1], ['If-Match' => '0'])->assertStatus(409);
    // Otra liberación de la misma fecha en curso (lock Redis): 409 LOCKED.
    $lock = Cache::lock("allot:{$a['id']}:{$date}", 10);
    expect($lock->get())->toBeTrue();
    $this->qPost('ops', $url, ['qty' => 1])->assertStatus(409)->assertJsonPath('error', 'LOCKED');
    $lock->release();
    $this->qPost('ops', $url, ['qty' => 3])->assertOk()->assertJsonPath('data.available', 0)->assertJsonPath('data.state', 'liberado');
    expect(cupDay($this, $a['id'], $date)->released_qty)->toBe(5);
    expect(collect($this->auditRows('Cupo', $a['code']))->where('action', 'liberar')->count())->toBe(2);
});

it('§10-6 CUP-BR-008: solicitado → en trámite; confirmar consume; cancelar la reserva revierte', function () {
    $svc = cupTrain($this);
    $a = cupContract($this)->assertCreated()->json('data');
    $date = substr($svc->service_date, 0, 10);
    $d = cupDay($this, $a['id'], $date);
    expect($d->used_qty)->toBe(0)->and($d->pending_qty)->toBe(2);

    $this->makeOrder();
    $this->qPost('ops', "ops/reservation-services/{$svc->id}/supplier-confirmation", ['confirmation_code' => 'PR-77'])->assertOk();
    $d = cupDay($this, $a['id'], $date);
    expect($d->used_qty)->toBe(2)->and($d->pending_qty)->toBe(0);
    $cell = $this->qGet('ops', "catalog/allotments/{$a['code']}/days/{$date}")->assertOk()->json('data');
    expect($cell['available'])->toBe(3)->and($cell['consumptions'][0]['reservation'])->toBe($this->res['code'])->and($cell['consumptions'][0]['state'])->toBe('consumido');

    // No se libera lo consumido.
    $this->qPost('ops', "catalog/allotments/{$a['code']}/days/{$date}/release", ['qty' => 4])->assertStatus(422)->assertJsonPath('code', 'CUP-BR-005');

    $this->inTenant(fn () => Reservation::find($this->res['id'])->forceFill(['status' => Reservation::CANCELLED])->save());
    $d = cupDay($this, $a['id'], $date);
    expect($d->used_qty)->toBe(0)->and($d->pending_qty)->toBe(0);
    expect(DB::connection('pgsql_admin')->table('catalog_allotment_consumptions')->where('booking_service_id', $svc->id)->value('state'))->toBe('revertido');
});

it('§10-7 job: libera release vencido no retenido, en_release → activo, cobra la fecha pasada retenida con CxP y no duplica', function () {
    $a = cupContract($this)->assertCreated()->json('data');
    $db = DB::connection('pgsql_admin');
    $past = now()->subDays(2)->toDateString();
    // Fechas pasadas ya cerradas salvo una retenida para un grupo que no llegó.
    $db->table('catalog_allotment_days')->where('allotment_id', $a['id'])->whereDate('service_date', '<', now()->toDateString())->whereDate('service_date', '<>', $past)
        ->update(['closed_at' => now()]);
    $db->table('catalog_allotment_days')->where('allotment_id', $a['id'])->whereDate('service_date', $past)->update(['retained_reason' => 'Grupo por confirmar']);
    // Retención futura tras el release: muestra la exposición y el job no la libera.
    $kept = now()->addDays(3)->toDateString();
    $this->as('ops')->putJson("/api/v1/catalog/allotments/{$a['code']}/days/{$kept}/retention", ['reason' => 'Grupo Keller'])->assertOk()
        ->assertJsonPath('data.state', 'retenido')->assertJsonPath('data.exposure', 250);
    expect($this->qGet('ops', "catalog/allotments/{$a['code']}")->json('data.status'))->toBe('en_release');

    $this->qPost('ops', 'catalog/allotments/jobs/release')->assertOk()->assertJsonPath('data.last_status', 'ok');
    expect(cupDay($this, $a['id'], now()->addDays(5)->toDateString())->released_qty)->toBe(5)
        ->and(cupDay($this, $a['id'], now()->toDateString())->released_qty)->toBe(5)
        ->and(cupDay($this, $a['id'], $kept)->released_qty)->toBe(0)
        ->and(cupDay($this, $a['id'], now()->addDays(8)->toDateString())->released_qty)->toBe(0);
    expect($this->qGet('ops', "catalog/allotments/{$a['code']}")->json('data.status'))->toBe('activo');

    $charge = $db->table('catalog_allotment_charges')->first();
    expect($db->table('catalog_allotment_charges')->count())->toBe(1)->and($charge->qty)->toBe(5)->and($charge->amount)->toBe('250.00')->and($charge->currency)->toBe('USD');
    $payable = $db->table('fin_payables')->where('id', $charge->payable_id)->first();
    expect($payable->kind)->toBe('costo_adicional')->and($payable->cost_type)->toBe('penalidad_proveedor')->and($payable->amount)->toBe('250.00')
        ->and($payable->currency)->toBe('USD')->and($payable->fx_rate)->not->toBeNull()->and($payable->provider_id)->toBe($this->provider->id);
    expect($db->table('sys_job_runs')->count())->toBe(1);

    $this->qPost('ops', 'catalog/allotments/jobs/release')->assertOk();
    expect($db->table('catalog_allotment_charges')->count())->toBe(1)->and($db->table('fin_payables')->where('cost_type', 'penalidad_proveedor')->count())->toBe(1);
    $this->qGet('finance', "catalog/allotments/{$a['code']}")->assertOk()->assertJsonPath('data.penalties', '250.00');
});

it('§10-8 ampliación aceptada suma al rango; rechazada no altera; CUP-BR-004 fuera de vigencia o pasada', function () {
    $a = cupContract($this)->assertCreated()->json('data');
    $base = "catalog/allotments/{$a['code']}/requests";
    $range = ['date_from' => now()->addDays(10)->toDateString(), 'date_to' => now()->addDays(12)->toDateString()];
    $this->qPost('ops', $base, $range + ['extra_qty' => 3, 'note' => 'Grupo'], $this->idem())->assertCreated()->assertJsonPath('data.code', 'AMP-0011');
    $this->qPost('ops', $base, ['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->addDay()->toDateString(), 'extra_qty' => 1, 'note' => 'x'], $this->idem())
        ->assertStatus(422)->assertJsonPath('code', 'CUP-BR-004');
    $this->qPost('ops', $base, ['date_from' => now()->addDays(39)->toDateString(), 'date_to' => now()->addDays(45)->toDateString(), 'extra_qty' => 1, 'note' => 'x'], $this->idem())
        ->assertStatus(422)->assertJsonPath('code', 'CUP-BR-004');
    $this->qPost('ops', $base, $range + ['extra_qty' => 2, 'note' => 'Otro'], $this->idem())->assertCreated();

    $this->qPost('ops', "{$base}/AMP-0011/respond", ['accepted' => true])->assertStatus(422);
    $this->qPost('ops', "{$base}/AMP-0011/respond", ['accepted' => true, 'response' => 'OK del proveedor'])->assertOk()->assertJsonPath('data.status', 'aceptada');
    $this->qPost('ops', "{$base}/AMP-0012/respond", ['accepted' => false, 'response' => 'Sin disponibilidad'])->assertOk()->assertJsonPath('data.status', 'rechazada');
    $this->qPost('ops', "{$base}/AMP-0011/respond", ['accepted' => false, 'response' => 'otra vez'])->assertStatus(409);

    foreach ([9 => 5, 10 => 8, 11 => 8, 12 => 8, 13 => 5] as $offset => $blocked) {
        expect(cupDay($this, $a['id'], now()->addDays($offset)->toDateString())->blocked_qty)->toBe($blocked);
    }
    expect(collect($this->auditRows('Cupo', $a['code']))->pluck('action')->all())->toContain('solicitar_ampliacion', 'ampliacion_aceptada', 'ampliacion_rechazada');
});

it('editar acorta/extiende la vigencia (sin borrar fechas con consumo) y liberar el contrato deja de aceptar consumo', function () {
    $svc = cupTrain($this);
    $a = cupContract($this)->assertCreated()->json('data');
    $edit = fn (array $data) => $this->as('ops')->withHeaders(['If-Match' => (string) $this->admin()->table('catalog_allotments')->where('id', $a['id'])->value('lock_version')])
        ->patchJson("/api/v1/catalog/allotments/{$a['code']}", $data);
    $edit(['valid_to' => now()->addDays(15)->toDateString()])->assertStatus(422)->assertJsonPath('code', 'CUP-BR-001'); // el servicio (+21) consume
    $edit(['valid_to' => now()->addDays(30)->toDateString(), 'base_qty' => 7])->assertOk()->assertJsonPath('data.base_qty', 7);
    expect(DB::connection('pgsql_admin')->table('catalog_allotment_days')->where('allotment_id', $a['id'])->count())->toBe(36)
        ->and(cupDay($this, $a['id'], now()->addDays(20)->toDateString())->blocked_qty)->toBe(7);
    $this->as('ops')->withHeaders(['If-Match' => '0'])->patchJson("/api/v1/catalog/allotments/{$a['code']}", ['base_qty' => 3])->assertStatus(409);

    $this->qPost('ops', "catalog/allotments/{$a['code']}/release", [], $this->idem())->assertStatus(422);
    $this->qPost('ops', "catalog/allotments/{$a['code']}/release", ['reason' => 'Fin de temporada'], $this->idem())->assertOk()->assertJsonPath('data.status', 'liberado');
    $d = cupDay($this, $a['id'], now()->addDays(25)->toDateString());
    expect($d->released_qty)->toBe(7);
    // La fecha con el servicio en trámite conserva su consumo; un contrato liberado no toma consumo nuevo.
    expect(cupDay($this, $a['id'], substr($svc->service_date, 0, 10))->pending_qty)->toBe(2);
});
