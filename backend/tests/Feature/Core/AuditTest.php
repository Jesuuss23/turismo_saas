<?php

use App\Models\Sys\AuditEvent;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tenant = $this->makeTenant();
    $this->sales = $this->makeUser($this->tenant, 'sales');
});

it('X-16: audit_events es append-only (UPDATE/DELETE fallan incluso para el dueño del esquema)', function () {
    app(TenantContext::class)->run($this->tenant, fn () => app(AuditLogger::class)->log('Cotización', 'COT-2026-00192', 'aprobar', 'pendiente', 'aprobada'));

    app(TenantContext::class)->run($this->tenant, function () {
        expect(fn () => DB::table('audit_events')->update(['action' => 'x']))->toThrow(QueryException::class, 'permission denied');
        expect(fn () => DB::table('audit_events')->delete())->toThrow(QueryException::class, 'permission denied');
    });

    expect(fn () => $this->admin()->table('audit_events')->update(['action' => 'x']))->toThrow(QueryException::class, 'append-only');
    expect(fn () => $this->admin()->table('audit_events')->delete())->toThrow(QueryException::class, 'append-only');
});

it('X-16: un acceso denegado genera evento con la misma correlación que el header de respuesta', function () {
    $response = $this->api($this->tokenFor($this->sales))
        ->withHeader('X-Request-Id', 'c-8812a')
        ->getJson('/api/v1/access-requests')
        ->assertForbidden()
        ->assertHeader('X-Request-Id', 'c-8812a')
        ->assertJsonPath('correlation_id', 'c-8812a')
        ->assertJsonPath('required_permissions', ['admin.users.manage']);

    $event = $this->admin()->table('audit_events')->where('action', 'acceso_denegado')->first();
    expect($event->correlation_id)->toBe('c-8812a')
        ->and($event->entity)->toBe('Acceso')
        ->and($event->entity_id)->toBe('/api/v1/access-requests')
        ->and($event->before_value)->toBe('sales')
        ->and($event->after_value)->toBe('requiere admin.users.manage')
        ->and($event->is_critical)->toBeTrue()
        ->and($event->tenant_id)->toBe($this->tenant->id);
});

it('genera X-Request-Id cuando falta', function () {
    $response = $this->api($this->tokenFor($this->sales))->getJson('/api/v1/me')->assertOk();

    expect($response->headers->get('X-Request-Id'))->toStartWith('c-');
});

it('el evento de negocio se pierde con el rollback; el acceso denegado no', function () {
    app(TenantContext::class)->run($this->tenant, function () {
        try {
            DB::transaction(function () {
                app(AuditLogger::class)->log('Reserva', 'RES-1', 'cancelar');
                app(AuditLogger::class)->isolated('Acceso', '/reservas', 'acceso_denegado', 'sales', 'requiere reservations.cancel');
                throw new RuntimeException;
            });
        } catch (RuntimeException) {
        }
    });

    expect($this->admin()->table('audit_events')->pluck('action')->all())->toBe(['acceso_denegado']);
});

it('cada tenant solo ve su auditoría', function () {
    $other = $this->makeTenant('inka');
    app(TenantContext::class)->run($other, fn () => app(AuditLogger::class)->log('Reserva', 'RES-9', 'cancelar'));

    app(TenantContext::class)->run($this->tenant, function () {
        expect(AuditEvent::count())->toBe(0);
    });
});
