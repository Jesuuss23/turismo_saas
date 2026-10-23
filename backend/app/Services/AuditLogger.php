<?php

namespace App\Services;

use App\Models\Iam\PlatformUser;
use App\Models\Iam\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Equivalente a `Audit.log` (§9, PROC-063). Completa tenant, actor, rol, IP, user agent,
 * correlación y criticidad. Se escribe en la transacción de negocio (si falla, no hay evento),
 * salvo `isolated()` (acceso_denegado, login_fallido), que usa una conexión aparte.
 */
class AuditLogger
{
    public const CRITICAL = [
        'login', 'asignar', 'aprobar', 'rechazar', 'cancelar', 'crear_desde_cotizacion', 'revertir',
        'cambio_precio', 'descuento_override', 'emitir', 'otorgar_permiso', 'revocar_permiso', 'suspender',
        'reactivar', 'alta', 'desactivar', 'resetear_mfa', 'exportar', 'acceso_denegado', 'cambio_plan',
        'crear_rol', 'cambio_rol', 'facturar', 'exportar_denegado',
    ];

    /** Conexión independiente para eventos que deben sobrevivir al rollback de la solicitud. */
    public const ISOLATED_CONNECTION = 'pgsql_audit';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CorrelationId $correlation,
    ) {}

    public function log(
        string $entity,
        string $entityId,
        string $action,
        ?string $before = null,
        ?string $after = null,
        ?array $evidence = null,
        ?array $diff = null,
        User|PlatformUser|null $actor = null,
        bool $system = false,
    ): string {
        $row = $this->row($entity, $entityId, $action, $before, $after, $evidence, $diff, $actor, $system);
        DB::table('audit_events')->insert($row);

        return $row['id'];
    }

    /** Escritura en transacción propia (§9): no se pierde si la solicitud hace rollback. */
    public function isolated(
        string $entity,
        string $entityId,
        string $action,
        ?string $before = null,
        ?string $after = null,
        User|PlatformUser|null $actor = null,
    ): string {
        $row = $this->row($entity, $entityId, $action, $before, $after, null, null, $actor);

        DB::connection(self::ISOLATED_CONNECTION)->transaction(function ($db) use ($row) {
            $db->select("SELECT set_config('app.tenant_id', ?, true)", [$row['tenant_id'] ?? '']);
            $db->table('audit_events')->insert($row);
        });

        return $row['id'];
    }

    /** `$system` = acción automática (listeners, jobs): se registra como Sistema aunque haya usuario en sesión. */
    private function row(string $entity, string $entityId, string $action, ?string $before, ?string $after, ?array $evidence, ?array $diff, User|PlatformUser|null $actor, bool $system = false): array
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $actor ??= $system ? null : auth()->user();

        [$actorType, $actorId, $actorRole] = match (true) {
            $actor instanceof User => ['user', $actor->getKey(), $actor->roleName()],
            $actor instanceof PlatformUser => ['platform_user', $actor->getKey(), $actor->roleName()],
            default => ['system', null, 'Sistema'],
        };

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $actor instanceof PlatformUser ? null : $this->tenant->id(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'entity' => Str::limit($entity, 40, ''),
            'entity_id' => Str::limit($entityId, 80, ''),
            'action' => $action,
            'is_critical' => in_array($action, self::CRITICAL, true),
            'before_value' => $before,
            'after_value' => $after,
            'diff' => $diff === null ? null : json_encode($diff),
            'evidence' => $evidence === null ? null : json_encode($evidence),
            'ip' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'correlation_id' => $this->correlation->get(),
            'occurred_at' => now(),
        ];
    }
}
