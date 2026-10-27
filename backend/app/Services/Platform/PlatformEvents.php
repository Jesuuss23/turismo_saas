<?php

namespace App\Services\Platform;

use App\Models\Iam\PlatformUser;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Notify;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Auditoría (PROC-063), notificaciones (PROC-059) y eventos del ciclo de vida de la plataforma. La auditoría y los avisos viven en el
 * tenant afectado (RLS); nunca se registran tokens, secretos ni números de tarjeta (§9).
 */
class PlatformEvents
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    public function audit(string $tenantId, string $entity, string $entityId, string $action, ?string $before, ?string $after, User|PlatformUser|null $actor = null, bool $system = false): void
    {
        $this->context->run($tenantId, fn () => $this->audit->log($entity, $entityId, $action, $before, $after, actor: $actor, system: $system));
    }

    /** Aviso al Tenant Admin: correo al contacto de facturación y notificación en el portal a los administradores activos. */
    public function notifyTenant(string $tenantId, string $event, string $subject, string $body): void
    {
        $email = DB::connection(PlatformCatalog::CONNECTION)->table('subscriptions')->where('tenant_id', $tenantId)->where('status', '<>', 'canceled')->value('billing_email');
        $this->context->run($tenantId, function () use ($email, $event, $subject, $body) {
            $data = ['related_type' => 'subscription'];
            if ($email) {
                $this->notify->push($event, 'email', $email, $subject, $body, $data);
            }
            foreach (User::where('status', User::ACTIVE)->whereHas('roles', fn ($q) => $q->where('name', 'tenant_admin'))->get() as $admin) {
                $this->notify->push($event, 'portal', $admin, $subject, $body, $data);
            }
        });
    }

    /** Aviso a operaciones de la plataforma (p. ej. cancelaciones, §8.5). */
    public function notifyOps(string $tenantId, string $event, string $subject, string $body): void
    {
        $this->context->run($tenantId, fn () => $this->notify->push($event, 'email', PlatformCatalog::ISSUER['ops_email'], $subject, $body, ['related_type' => 'subscription']));
    }

    /** Evento del ciclo de vida para el panel de métricas (altas, bajas, upgrades, suspensiones). */
    public function lifecycle(string $tenantId, string $kind, string $description, float|string $mrrDelta = 0): void
    {
        DB::connection(PlatformCatalog::CONNECTION)->table('platform_events')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'kind' => $kind, 'description' => $description, 'mrr_delta' => $mrrDelta, 'created_at' => now(),
        ]);
    }
}
