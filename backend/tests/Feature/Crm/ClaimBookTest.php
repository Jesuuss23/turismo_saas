<?php

use App\Exceptions\ApiException;
use App\Models\Crm\AfterSalesCase;
use App\Services\Crm\ClaimBookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->crmWorld();
    $this->admin()->table('iam_sedes')->where('tenant_id', $this->tenant->id)->update(['city' => 'Lima']);
    $this->sede = $this->admin()->table('iam_sedes')->where('tenant_id', $this->tenant->id)->value('id');
    $this->cli = ['ana' => $this->makeClient('u03', ['name' => 'Ana Gutiérrez', 'email' => 'ana@correo.test']), 'luis' => $this->makeClient('u04', ['name' => 'Luis Paz', 'email' => 'luis@correo.test'])];
    $this->addUser('customer', 'customer', ['client_id' => $this->cli['ana']->id, 'email' => 'ana@correo.test']);
    $this->addUser('customer2', 'customer', ['client_id' => $this->cli['luis']->id, 'email' => 'luis@correo.test']);
});

function lrData(object $t, array $overrides = []): array
{
    return array_replace_recursive(['sede_id' => $t->sede, 'type' => 'reclamo', 'accept_email' => true,
        'consumer' => ['name' => 'Ana Gutiérrez', 'doc_type' => 'DNI', 'doc_number' => '45678912', 'email' => 'ana@correo.test', 'address' => 'Calle Los Pinos 230'],
        'good' => ['kind' => 'Servicio', 'description' => 'Tour Paracas'], 'detail' => 'El bus salió dos horas tarde y perdimos el paseo en lancha.', 'request' => 'Devolución del paseo'], $overrides);
}

function lrPost(object $t, string $who, string $uri, array $data = [], array $headers = []): TestResponse
{
    return $t->qPost($who, ltrim($uri, '/'), $data, $headers + $t->idem());
}

function lrLock(object $t, string $number): array
{
    return ['If-Match' => (string) DB::connection('pgsql_admin')->table('crm_cases')->where('book_number', $number)->value('lock_version')];
}

it('§10-2 RBAC: Finanzas no ve el libro; el cliente solo lista sus hojas; el interno solo previsualiza', function () {
    $mine = lrPost($this, 'customer', 'portal/claim-book', lrData($this))->assertCreated()->assertJsonPath('data.book_number', 'LR-LIM-'.now()->year.'-000001')->json('data');
    lrPost($this, 'customer2', 'portal/claim-book', lrData($this, ['consumer' => ['name' => 'Luis Paz', 'email' => 'luis@correo.test']]))->assertCreated();
    lrPost($this, 'manager', 'crm/claim-book', lrData($this, ['consumer' => ['email' => 'otra@correo.test']]))->assertCreated();

    expect(collect($this->qGet('customer', 'portal/claim-book')->assertOk()->json('data'))->pluck('book_number')->all())->toBe([$mine['book_number']]);
    $this->qGet('customer2', "portal/claim-book/{$mine['book_number']}")->assertNotFound();
    $this->qGet('customer', "portal/claim-book/{$mine['book_number']}/certificate")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->qGet('finance', 'crm/claim-book')->assertForbidden();
    $this->qGet('u03', 'crm/claim-book')->assertForbidden();
    expect($this->qGet('manager', 'crm/claim-book')->assertOk()->json('data'))->toHaveCount(3);
    $this->qGet('manager', 'portal/claim-book/form')->assertOk()->assertJsonPath('data.mode', 'preview');
    lrPost($this, 'manager', 'portal/claim-book', lrData($this))->assertForbidden()->assertJsonPath('code', 'LR-PREVIEW');
    $this->qGet('finance', 'portal/claim-book/form')->assertForbidden();
    // El cliente sale del token: se vincula a su cuenta aunque el cuerpo diga otra cosa.
    expect(DB::connection('pgsql_admin')->table('crm_cases')->where('book_number', $mine['book_number'])->value('client_id'))->toBe($this->cli['ana']->id);
});

it('§10-9 correlativo sin duplicados en 20 altas seguidas (contador con upsert bloqueante)', function () {
    $numbers = $this->inTenant(fn () => collect(range(1, 20))->map(fn ($i) => app(ClaimBookService::class)->register(lrData($this, ['consumer' => ['email' => "c{$i}@correo.test"]]), 'web')->book_number));
    expect($numbers->unique())->toHaveCount(20)->and($numbers->last())->toBe('LR-LIM-'.now()->year.'-000020');
    expect(DB::connection('pgsql_admin')->table('crm_claim_book_counters')->where('tenant_id', $this->tenant->id)->value('last_number'))->toBe(20);
});

it('§10-9 due_date en días hábiles: salta fines de semana y el feriado del 08/10', function () {
    $this->travelTo('2026-10-02 10:00:00');
    lrPost($this, 'customer', 'portal/claim-book', lrData($this))->assertCreated()->assertJsonPath('data.due_date', '2026-10-26');
    $this->travelTo('2026-10-07 10:00:00');
    // 15 hábiles desde el 08/10 (feriado, no cuenta): del 09/10 al 29/10.
    lrPost($this, 'customer', 'portal/claim-book', lrData($this))->assertCreated()->assertJsonPath('data.due_date', '2026-10-29');
});

it('§10-9 menor sin apoderado → 422; cerrar sin respuesta → 409; responder vencida → auditoría FUERA DE PLAZO', function () {
    lrPost($this, 'customer', 'portal/claim-book', lrData($this, ['consumer' => ['is_minor' => true]]))->assertStatus(422)->assertJsonPath('code', 'LR-BR-04');
    lrPost($this, 'customer', 'portal/claim-book', lrData($this, ['consumer' => ['is_minor' => true, 'guardian' => ['name' => 'Rosa Gutiérrez', 'doc_number' => '41234567']]]))->assertCreated();
    lrPost($this, 'customer', 'portal/claim-book', lrData($this, ['accept_email' => false]))->assertStatus(422);

    $n = lrPost($this, 'customer', 'portal/claim-book', lrData($this))->assertCreated()->json('data.book_number');
    lrPost($this, 'manager', "crm/claim-book/{$n}/close", ['note' => 'Sin respuesta'], lrLock($this, $n))->assertStatus(409)->assertJsonPath('code', 'LR-BR-07');
    lrPost($this, 'manager', "crm/claim-book/{$n}/review", [], lrLock($this, $n))->assertOk();
    lrPost($this, 'manager', "crm/claim-book/{$n}/response", ['decision' => 'fundado', 'text' => 'Muy corto', 'actions' => 'x'])->assertStatus(422)->assertJsonPath('code', 'LR-BR-06');

    // Plazo vencido (ayer).
    DB::connection('pgsql_admin')->table('crm_cases')->where('book_number', $n)->update(['due_date' => now()->subDay()->toDateString()]);
    lrPost($this, 'manager', "crm/claim-book/{$n}/response", ['decision' => 'fundado_parcial', 'text' => 'Confirmamos la demora del bus con el proveedor de transporte y le devolvemos el paseo.',
        'actions' => 'Devolución de S/ 120 en 5 días hábiles.'])->assertOk()->assertJsonPath('data.late', true);
    $audit = collect($this->auditRows('Libro de reclamaciones', $n));
    expect($audit->pluck('action')->all())->toContain('registrar', 'evaluar', 'responder')
        ->and($audit->firstWhere('action', 'responder')->after_value)->toContain('FUERA DE PLAZO');
    lrPost($this, 'manager', "crm/claim-book/{$n}/response/resend")->assertOk();
    lrPost($this, 'manager', "crm/claim-book/{$n}/close", ['note' => 'Conforme'], lrLock($this, $n))->assertOk();
    $this->qGet('customer', "portal/claim-book/{$n}")->assertOk()->assertJsonPath('data.book_status', 'closed')->assertJsonPath('data.response.decision', 'Fundado en parte');
});

it('LR-BR-07/08: Postventa no cambia una hoja del libro; los datos del consumidor son inmutables', function () {
    $n = lrPost($this, 'customer', 'portal/claim-book', lrData($this))->assertCreated()->json('data');
    $this->qPost('manager', "crm/cases/{$n['code']}/start", [], lrLock($this, $n['book_number']))->assertStatus(409)->assertJsonPath('code', 'LR-BR-07');
    $this->inTenant(function () use ($n) {
        $c = AfterSalesCase::where('book_number', $n['book_number'])->first();
        expect(fn () => $c->forceFill(['detail' => 'Otro detalle'])->save())->toThrow(ApiException::class);
    });
    // Se guarda cifrado.
    expect(DB::connection('pgsql_admin')->table('crm_cases')->where('book_number', $n['book_number'])->value('consumer'))->not->toContain('45678912');
});

it('§10-10 idempotencia y captcha del registro público', function () {
    $form = $this->getJson("/api/v1/public/{$this->tenant->slug}/claim-book")->assertOk()->json('data');
    preg_match_all('/\d+/', $form['captcha']['question'], $m);
    $body = lrData($this, ['consumer' => ['email' => 'web@correo.test']]) + ['captcha_token' => $form['captcha']['token'], 'captcha_answer' => (string) array_sum($m[0])];
    $this->postJson("/api/v1/public/{$this->tenant->slug}/claim-book", ['captcha_answer' => '0'] + $body, $this->idem())->assertStatus(422)->assertJsonValidationErrors('captcha_answer');
    $key = $this->idem();
    $a = $this->postJson("/api/v1/public/{$this->tenant->slug}/claim-book", $body, $key)->assertCreated()->json('data.book_number');
    $b = $this->postJson("/api/v1/public/{$this->tenant->slug}/claim-book", $body, $key)->assertCreated()->json('data.book_number');
    expect($b)->toBe($a)->and(DB::connection('pgsql_admin')->table('crm_cases')->where('is_book', true)->count())->toBe(1);
    $this->getJson('/api/v1/public/no-existe/claim-book')->assertNotFound();
});

it('reportes, relación anual y parámetros: solo admin.settings.manage edita; alertas de plazo', function () {
    $this->travelTo(now()->subDays(25));
    $n = lrPost($this, 'customer', 'portal/claim-book', lrData($this))->assertCreated()->json('data.book_number');
    $this->travelBack();
    $this->qGet('manager', 'crm/claim-book')->assertOk()->assertJsonPath('kpis.overdue', 1)->assertJsonPath('data.0.deadline.state', 'vencido');
    lrPost($this, 'manager', 'crm/claim-book/alerts')->assertOk()->assertJsonPath('data.summary', '1 hoja(s) por vencer o vencidas: alerta enviada');
    $this->qGet('manager', 'crm/claim-book/reports')->assertOk()->assertJsonPath('data.compliance.overdue_open', 1)->assertJsonPath('data.annual.0.0', $n);
    $this->as('manager')->post('/api/v1/crm/claim-book/exports', ['format' => 'CSV'])->assertOk();
    $this->as('u03')->post('/api/v1/crm/claim-book/exports', ['format' => 'CSV'])->assertForbidden();
    $this->as('manager')->withHeaders(['If-Match' => '0'])->putJson('/api/v1/crm/claim-book/settings', ['response_days' => 10])->assertForbidden();
    $this->as('admin')->withHeaders(['If-Match' => '0'])->putJson('/api/v1/crm/claim-book/settings', ['response_days' => 10, 'add_holidays' => [['date' => '2026-12-31', 'name' => 'Cierre']]])
        ->assertOk()->assertJsonPath('data.response_days', 10);
    expect(collect($this->qGet('manager', 'crm/claim-book/settings')->assertOk()->json('data.holidays'))->where('local', true)->pluck('date')->all())->toBe(['2026-12-31']);
});
