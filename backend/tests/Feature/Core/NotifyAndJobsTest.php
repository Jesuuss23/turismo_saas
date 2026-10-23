<?php

use App\Jobs\SendNotification;
use App\Models\Iam\AccessRequest;
use App\Models\Sys\NotifMessage;
use App\Notifications\Channels\ChannelSender;
use App\Services\Notify;
use App\Tenancy\MissingTenantContext;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tenant = $this->makeTenant();
    $this->admin = $this->makeUser($this->tenant, 'tenant_admin');
});

it('X-17: una notificación fallida no revierte la transacción de negocio', function () {
    app(Notify::class)->extend('email', FailingSender::class);

    app(TenantContext::class)->run($this->tenant, function () {
        DB::transaction(function () {
            AccessRequest::create(['user_id' => $this->admin->id, 'path' => 'reservas', 'status' => 'pendiente', 'created_at' => now()]);
            app(Notify::class)->push('reserva.confirmada', 'email', $this->admin, 'Reserva confirmada');
        });

        expect(AccessRequest::count())->toBe(1)
            ->and(NotifMessage::first()->status)->toBe('fallido')
            ->and(NotifMessage::first()->last_error)->toBe('SMTP caído');
    });
});

it('una transacción revertida no deja notificaciones', function () {
    app(TenantContext::class)->run($this->tenant, function () {
        try {
            DB::transaction(function () {
                app(Notify::class)->push('reserva.confirmada', 'portal', $this->admin, 'Reserva confirmada');
                throw new RuntimeException;
            });
        } catch (RuntimeException) {
        }

        expect(NotifMessage::count())->toBe(0);
    });
});

it('la solicitud de acceso desde el 403 notifica al Tenant Admin en su campana', function () {
    $sales = $this->makeUser($this->tenant, 'sales');

    $this->api($this->tokenFor($sales))->postJson('/api/v1/access-requests', ['path' => '#/finanzas/rentabilidad'])
        ->assertCreated()
        ->assertJsonPath('path', 'finanzas/rentabilidad')
        ->assertJsonPath('required_permissions', ['finance.profitability']);

    $bell = $this->api($this->tokenFor($this->admin))->getJson('/api/v1/me/notifications')->assertOk();
    expect($bell->json('unread_count'))->toBe(1)
        ->and($bell->json('data.0.event'))->toBe('acceso.solicitud');

    $this->api($this->tokenFor($this->admin))->postJson('/api/v1/me/notifications/read-all')->assertJsonPath('marked', 1);
});

it('X-04: un job sin contexto de tenant falla explícitamente', function () {
    $job = new SendNotification('00000000-0000-0000-0000-000000000000');
    expect($job->tenantId)->toBeNull();

    expect(fn () => dispatch_sync($job))->toThrow(MissingTenantContext::class);
});

it('un job captura el tenant al despacharse y lo restablece al ejecutarse', function () {
    $id = app(TenantContext::class)->run($this->tenant, function () {
        $message = NotifMessage::create(['event' => 'x', 'channel' => 'portal', 'recipient' => 'a', 'subject' => 's']);

        return [$message->id, new SendNotification($message->id)];
    });
    [$messageId, $job] = $id;
    expect(app(TenantContext::class)->has())->toBeFalse();

    dispatch_sync($job);

    app(TenantContext::class)->run($this->tenant, fn () => expect(NotifMessage::find($messageId)->status)->toBe('enviado'));
});

class FailingSender implements ChannelSender
{
    public function send(NotifMessage $message): void
    {
        throw new RuntimeException('SMTP caído');
    }
}
