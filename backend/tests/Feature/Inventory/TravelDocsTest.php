<?php

use App\Services\Catalog\SupplierServices;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->reservationWorld();
    $this->res = $this->makeReservation('u03');
    $this->docs = ['res' => $this->res, 'pdf' => base64_encode('%PDF-1.4 demo')];
});

function tdDb(): Connection
{
    return DB::connection('pgsql_admin');
}

/** Pasajeros de la reserva por orden (seq). */
function tdPax(string $reservationId): array
{
    return tdDb()->table('booking_passengers')->where('reservation_id', $reservationId)->orderBy('seq')->pluck('id')->all();
}

function tdUrl(object $t, string $passenger, string $type, string $action): string
{
    return "reservations/{$t->docs['res']['code']}/travel-docs/{$passenger}/{$type}/{$action}";
}

function tdLock(string $passenger, string $type): array
{
    return ['If-Match' => (string) tdDb()->table('ops_travel_doc_items')->where('passenger_id', $passenger)->where('doc_type', $type)->value('lock_version')];
}

function tdUpload(object $t, string $passenger, string $type, array $extra = [], string $who = 'ops'): TestResponse
{
    return $t->qPost($who, tdUrl($t, $passenger, $type, 'files'), $extra + ['file_name' => "{$type}.pdf", 'mime_type' => 'application/pdf', 'content_base64' => $t->docs['pdf']], $t->idem());
}

function tdValidate(object $t, string $passenger, string $type, array $data = []): TestResponse
{
    return $t->qPost('ops', tdUrl($t, $passenger, $type, 'validate'), $data, tdLock($passenger, $type));
}

/** Entrada nominativa MAPI confirmada en la reserva (DOC-BR-010). */
function tdMapi(object $t, string $providerId, $admin): string
{
    $svc = $t->inTenant(fn () => app(SupplierServices::class)->create(['provider_id' => $providerId, 'name' => 'Entrada Machu Picchu circuito 2', 'type' => 'Entrada',
        'unit' => 'pax', 'cost' => 152, 'currency' => 'PEN', 'valid_from' => now()->subMonth()->toDateString(), 'valid_to' => now()->addYear()->toDateString()], $admin)['service']);
    $id = (string) Str::uuid7();
    $r = tdDb()->table('booking_reservations')->where('id', $t->docs['res']['id'])->first();
    tdDb()->table('booking_services')->insert(['id' => $id, 'tenant_id' => $r->tenant_id, 'reservation_id' => $r->id, 'code' => 'RS-MAPI', 'catalog_service_id' => $svc->id,
        'provider_id' => $providerId, 'name' => 'Entrada Machu Picchu circuito 2', 'service_date' => $r->travel_from, 'pax' => 2, 'status' => 'confirmado',
        'cost_amount' => 304, 'price_amount' => 400, 'service_kind' => 'tren_entrada', 'confirmation_code' => 'MC-1', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

it('§10-1 RLS: el tenant B no ve checklists del tenant A', function () {
    $this->qGet('ops', 'ops/travel-docs')->assertOk()->assertJsonCount(1, 'data');
    $token = $this->tokenFor($this->makeUser($this->makeTenant('inka'), 'operations'));
    expect($this->api($token)->getJson('/api/v1/ops/travel-docs')->assertOk()->json('data'))->toBe([]);
    $this->api($token)->getJson("/api/v1/reservations/{$this->res['code']}/travel-docs")->assertNotFound();
});

it('§10-3 Ventas: solo sus reservas, detalle ajeno fuera de cartera, solicita en la propia y no actúa sobre ítems; Finanzas 403', function () {
    $other = $this->makeReservation('u04');
    expect(collect($this->qGet('u03', 'ops/travel-docs')->assertOk()->json('data'))->pluck('code')->all())->toBe([$this->res['code']]);
    expect(collect($this->qGet('ops', 'ops/travel-docs')->json('data'))->pluck('code')->sort()->values()->all())->toBe(collect([$this->res['code'], $other['code']])->sort()->values()->all());
    $this->qGet('u03', "reservations/{$other['code']}/travel-docs")->assertForbidden()->assertJsonPath('code', 'SCOPE_OWNERSHIP');
    $this->qPost('u03', "reservations/{$other['code']}/travel-docs/requests", [], $this->idem())->assertForbidden();
    $detail = $this->qGet('u03', "reservations/{$this->res['code']}/travel-docs")->assertOk()->json('data');
    expect($detail['can_manage'])->toBeFalse()->and($detail['can_request'])->toBeTrue();
    $this->qPost('u03', "reservations/{$this->res['code']}/travel-docs/requests", ['channels' => ['Email']], $this->idem())->assertCreated();
    expect(tdDb()->table('ops_travel_doc_reminders')->where('trigger', 'manual')->count())->toBe(1);
    [$p1] = tdPax($this->res['id']);
    tdUpload($this, $p1, 'id_doc', [], 'u03')->assertForbidden();
    $this->qGet('finance', 'ops/travel-docs')->assertForbidden();
    $this->qGet('finance', "reservations/{$this->res['code']}/travel-docs")->assertForbidden();
    expect(collect($this->auditRows('Documento de viaje', $this->res['code']))->pluck('action')->all())->toContain('solicitar_documentos');
});

it('§10-9 DOC-BR-001: pasaporte de extranjero que vence antes de fin + 6 meses → 422; DOC-BR-013 formato y antivirus', function () {
    [$p1] = tdPax($this->res['id']);
    tdDb()->table('booking_passengers')->where('id', $p1)->update(['nationality' => 'Estadounidense', 'full_name' => 'Mark Johnson']);
    $end = Carbon::parse(tdDb()->table('booking_reservations')->where('id', $this->res['id'])->value('travel_to'));

    tdUpload($this, $p1, 'id_doc', ['mime_type' => 'application/zip'])->assertStatus(422)->assertJsonPath('code', 'DOC-BR-013');
    tdUpload($this, $p1, 'id_doc', ['file_name' => 'eicar-test.pdf'])->assertStatus(422)->assertJsonPath('code', 'DOC-BR-013');
    tdUpload($this, $p1, 'id_doc', ['size' => 11 * 1024 * 1024, 'content_base64' => null])->assertStatus(422)->assertJsonPath('code', 'DOC-BR-013');
    tdValidate($this, $p1, 'id_doc')->assertStatus(422)->assertJsonPath('code', 'DOC-BR-013'); // sin archivo

    tdUpload($this, $p1, 'id_doc', ['doc_number' => 'P123', 'expiry_date' => $end->copy()->addMonths(4)->toDateString()])->assertCreated()->assertJsonPath('data.status', 'recibido');
    $row = collect($this->qGet('ops', "reservations/{$this->res['code']}/travel-docs")->json('data.passengers.0.items'))->firstWhere('type', 'id_doc');
    expect($row['status'])->toBe('vencido')->and($row['min_expiry'])->toBe($end->copy()->addMonths(6)->toDateString());
    tdValidate($this, $p1, 'id_doc')->assertStatus(422)->assertJsonPath('code', 'DOC-BR-001');
    tdValidate($this, $p1, 'id_doc', ['expiry_date' => $end->copy()->addMonths(7)->toDateString()])->assertOk()->assertJsonPath('data.status', 'completo');
    // Peruano: basta vigencia al fin del viaje (DOC-BR-002).
    [, $p2] = tdPax($this->res['id']);
    tdDb()->table('booking_passengers')->where('id', $p2)->update(['nationality' => 'Peruana']);
    tdUpload($this, $p2, 'id_doc', ['doc_number' => '45678912', 'expiry_date' => $end->copy()->subDay()->toDateString()])->assertCreated();
    tdValidate($this, $p2, 'id_doc')->assertStatus(422)->assertJsonPath('code', 'DOC-BR-002');
    tdValidate($this, $p2, 'id_doc', ['expiry_date' => $end->toDateString()])->assertOk();
    // Observar exige motivo y notifica al cliente; aplicabilidad: el extranjero necesita seguro y TAM, el peruano no.
    $detail = $this->qGet('ops', "reservations/{$this->res['code']}/travel-docs")->json('data.passengers');
    expect(collect($detail[0]['items'])->pluck('type')->all())->toContain('insurance', 'migration')
        ->and(collect($detail[1]['not_applicable'])->pluck('type')->all())->toContain('insurance', 'migration', 'visa');
    tdUpload($this, $p1, 'insurance')->assertCreated();
    $this->qPost('ops', tdUrl($this, $p1, 'insurance', 'observe'), [], tdLock($p1, 'insurance'))->assertStatus(422);
    $this->qPost('ops', tdUrl($this, $p1, 'insurance', 'observe'), ['reason' => 'Póliza sin cobertura en Perú'], tdLock($p1, 'insurance'))->assertOk()->assertJsonPath('data.status', 'observado');
    expect(collect($this->auditRows('Documento de viaje', $this->res['code']))->pluck('action')->all())->toContain('cargar', 'validar', 'observar');
});

it('§10-10 DOC-BR-010: menor sin autorización completa bloquea la entrada MAPI y su voucher; al validar se habilita', function () {
    [$p1, $p2] = tdPax($this->res['id']);
    tdDb()->table('booking_passengers')->whereIn('id', [$p1, $p2])->update(['nationality' => 'Peruana']);
    tdDb()->table('booking_passengers')->where('id', $p2)->update(['pax_type' => 'CHD', 'birth_date' => now()->subYears(12)->toDateString()]);
    $mapi = tdMapi($this, $this->provider->id, $this->users['admin']);
    $end = tdDb()->table('booking_reservations')->where('id', $this->res['id'])->value('travel_to');

    $gate = $this->qGet('ops', "reservations/{$this->res['code']}/travel-docs/gate")->assertOk()->json('data');
    expect($gate['blocked'])->toBeTrue()->and(collect($gate['missing'])->pluck('type')->all())->toBe(['id_doc', 'id_doc', 'minor_auth']);
    $check = $this->qPost('ops', 'ops/vouchers/check', ['reservation_service_id' => $mapi])->assertOk()->json('data');
    expect(collect($check['blocks'])->pluck('code')->all())->toContain('DOC-BR-010');
    $this->qPost('ops', 'ops/vouchers', ['reservation_service_id' => $mapi], $this->idem())->assertStatus(422)->assertJsonPath('code', 'DOC-BR-010');

    foreach ([$p1, $p2] as $p) {
        tdUpload($this, $p, 'id_doc', ['doc_number' => '4'.random_int(1000000, 9999999), 'expiry_date' => substr($end, 0, 10)])->assertCreated();
        tdValidate($this, $p, 'id_doc')->assertOk();
    }
    expect($this->qGet('ops', "reservations/{$this->res['code']}/travel-docs/gate")->json('data.missing.0.type'))->toBe('minor_auth');
    tdValidate($this, $p2, 'minor_auth')->assertStatus(422)->assertJsonPath('code', 'DOC-BR-006');
    tdUpload($this, $p2, 'minor_auth', ['file_name' => 'permiso-notarial.pdf'])->assertCreated();
    tdValidate($this, $p2, 'minor_auth')->assertOk();
    $this->qGet('ops', "reservations/{$this->res['code']}/travel-docs/gate")->assertOk()->assertJsonPath('data.blocked', false);
    expect(collect($this->qPost('ops', 'ops/vouchers/check', ['reservation_service_id' => $mapi])->json('data.blocks'))->pluck('code')->all())->not->toContain('DOC-BR-010');
    // El tablero refleja el bloqueo resuelto.
    $this->qGet('ops', 'ops/travel-docs?filter[mapi]=blocked')->assertOk()->assertJsonCount(0, 'data');
});

it('§10-11 DOC-BR-009 atención especial sin términos clínicos; DOC-BR-008 contacto con nombre y teléfono', function () {
    [$p1] = tdPax($this->res['id']);
    $base = "reservations/{$this->res['code']}/travel-docs/{$p1}";
    $this->as('ops')->putJson("/api/v1/{$base}/special-attention", ['requires_attention' => true, 'operational_note' => 'Paciente con diabetes tipo 2'])
        ->assertStatus(422)->assertJsonPath('code', 'DOC-BR-009');
    $this->as('ops')->putJson("/api/v1/{$base}/special-attention", ['requires_attention' => true, 'operational_note' => 'Silla de ruedas en traslados'])->assertOk();
    $this->as('u03')->putJson("/api/v1/{$base}/special-attention", ['requires_attention' => false])->assertForbidden();
    expect(tdDb()->table('ops_travel_doc_special')->where('passenger_id', $p1)->value('operational_note'))->toBe('Silla de ruedas en traslados');

    $this->as('ops')->putJson("/api/v1/{$base}/emergency-contact", ['name' => 'Rosa', 'relation' => 'Familiar'])->assertStatus(422)->assertJsonPath('code', 'DOC-BR-008');
    $this->as('ops')->putJson("/api/v1/{$base}/emergency-contact", ['name' => 'Rosa Pérez', 'phone' => '+51 984 111 222', 'relation' => 'Familiar'])->assertOk();
    $items = collect($this->qGet('ops', "reservations/{$this->res['code']}/travel-docs")->json('data.passengers.0.items'))->keyBy('type');
    expect($items['emergency']['status'])->toBe('completo')->and($items['special']['status'])->toBe('completo');
    expect(collect($this->auditRows('Documento de viaje', $this->res['code']))->pluck('action')->all())->toContain('declarar_atencion', 'registrar_contacto');
});

it('§10-12 JOB-11: recuerda a reservas a 3 días (o menos) y no reenvía el mismo día', function () {
    $far = $this->makeReservation('u04');
    tdDb()->table('booking_reservations')->where('id', $this->res['id'])->update(['travel_from' => now()->addDays(3)->toDateString(), 'travel_to' => now()->addDays(5)->toDateString()]);
    tdDb()->table('booking_reservations')->where('id', $far['id'])->update(['travel_from' => now()->addDays(10)->toDateString(), 'travel_to' => now()->addDays(12)->toDateString()]);

    $this->qPost('ops', 'ops/travel-docs/jobs/reminders')->assertOk()->assertJsonPath('data.last_status', 'ok');
    $auto = tdDb()->table('ops_travel_doc_reminders')->where('trigger', 'auto');
    expect((clone $auto)->count())->toBe(1)->and((clone $auto)->value('reservation_id'))->toBe($this->res['id'])->and((clone $auto)->value('days_before'))->toBe(3);
    expect(tdDb()->table('notif_messages')->where('event', 'documentos.recordatorio')->count())->toBeGreaterThan(0);

    $this->qPost('ops', 'ops/travel-docs/jobs/reminders')->assertOk();
    expect(tdDb()->table('ops_travel_doc_reminders')->where('trigger', 'auto')->count())->toBe(1);
    $this->qPost('u03', 'ops/travel-docs/jobs/reminders')->assertForbidden();
});

it('reglas: Operaciones edita la configuración (auditada); Ventas solo la consulta', function () {
    $this->qGet('u03', 'settings/travel-docs')->assertOk()->assertJsonPath('data.passport_months', 6);
    $this->as('u03')->putJson('/api/v1/settings/travel-docs', ['passport_months' => 3])->assertForbidden();
    $this->as('ops')->putJson('/api/v1/settings/travel-docs', ['reminder_days' => [10, 5, 2], 'passport_months' => 3, 'visa_nationalities' => 'China, India',
        'channels' => ['Email'], 'insurance_trekking' => false])->assertOk()->assertJsonPath('data.reminder_days', [10, 5, 2])->assertJsonPath('data.visa_nationalities', ['China', 'India']);
    $this->as('ops')->putJson('/api/v1/settings/travel-docs', ['channels' => ['Fax']])->assertStatus(422);
    expect($this->qGet('ops', 'settings/travel-docs')->json('data.passport_months'))->toBe(3);
    expect(collect($this->auditRows('Documentos de viaje', 'reglas'))->pluck('action')->all())->toContain('editar_reglas');
});
