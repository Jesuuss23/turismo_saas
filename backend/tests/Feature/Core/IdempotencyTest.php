<?php

use App\Services\Outbox;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    // Endpoint crítico de prueba que simula “registrar pago”: escribe en el outbox.
    Route::middleware(['api', 'auth:sanctum', 'tenant', 'idempotent'])->post('/api/v1/_test/payments', function () {
        $msg = app(Outbox::class)->record('Payment', (string) Str::uuid7(), 'PaymentRegistered', request()->all());

        return response()->json(['payment' => $msg->aggregate_id], 201);
    });

    $this->tenant = $this->makeTenant();
    $this->token = $this->tokenFor($this->makeUser($this->tenant, 'finance'));
});

function payments(): int
{
    return test()->admin()->table('sys_outbox')->where('event', 'PaymentRegistered')->count();
}

it('X-13: misma key y mismo cuerpo → un solo pago y la misma respuesta', function () {
    $key = (string) Str::uuid();
    $first = $this->api($this->token)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/_test/payments', ['amount' => '150.00', 'currency' => 'PEN'])->assertCreated();
    $second = $this->api($this->token)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/_test/payments', ['currency' => 'PEN', 'amount' => '150.00'])->assertCreated();

    expect(payments())->toBe(1)
        ->and($second->json())->toBe($first->json())
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true');
});

it('X-13: misma key con otro cuerpo → 422 IDEMPOTENCY_KEY_REUSED', function () {
    $key = (string) Str::uuid();
    $this->api($this->token)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/_test/payments', ['amount' => '150.00'])->assertCreated();
    $this->api($this->token)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/_test/payments', ['amount' => '999.00'])
        ->assertStatus(422)->assertJsonPath('error', 'IDEMPOTENCY_KEY_REUSED');

    expect(payments())->toBe(1);
});

it('X-13: key en proceso (concurrente) → 409 IDEMPOTENCY_IN_PROGRESS', function () {
    $key = (string) Str::uuid();
    $body = ['amount' => '150.00'];
    $hash = hash('sha256', 'POST /api/v1/_test/payments '.json_encode($body));
    $this->admin()->table('sys_idempotency_keys')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'key' => $key, 'method' => 'POST',
        'path' => '/api/v1/_test/payments', 'request_hash' => $hash, 'status' => 'processing',
        'locked_until' => now()->addMinute(), 'created_at' => now(), 'expires_at' => now()->addDay(),
    ]);

    $this->api($this->token)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/_test/payments', $body)
        ->assertStatus(409)->assertJsonPath('error', 'IDEMPOTENCY_IN_PROGRESS');
    expect(payments())->toBe(0);
});

it('exige el header Idempotency-Key', function () {
    $this->api($this->token)->postJson('/api/v1/_test/payments', ['amount' => '1'])
        ->assertStatus(400)->assertJsonPath('error', 'IDEMPOTENCY_KEY_REQUIRED');
});

it('la misma key en otro tenant es independiente', function () {
    $key = (string) Str::uuid();
    $other = $this->tokenFor($this->makeUser($this->makeTenant('inka'), 'finance'));

    $this->api($this->token)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/_test/payments', ['amount' => '1'])->assertCreated();
    $this->api($other)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/_test/payments', ['amount' => '2'])->assertCreated();

    expect(payments())->toBe(2);
});
