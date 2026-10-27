<?php

namespace App\Services\Collab;

use App\Exceptions\ApiException;
use App\Models\Collab\ApprovalRule;
use App\Models\Iam\Permission;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Matriz de reglas (§3.1, APR-BR-02/15): CRUD, eliminar solo nivel 2 y restaurar por defecto. */
class ApprovalRules
{
    public function __construct(
        private readonly ApprovalRouter $router,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function create(array $data, User $actor): ApprovalRule
    {
        $data = $this->validate($data);
        if ($data['level'] === 1 && isset(CollabCatalog::ORIGIN_PERMISSIONS[$data['request_type']]) && $data['permission'] !== CollabCatalog::ORIGIN_PERMISSIONS[$data['request_type']]) {
            throw ApiException::unprocessable('APR-BR-02', 'El nivel 1 de '.CollabCatalog::TYPES[$data['request_type']].' usa el permiso del módulo de origen ('.CollabCatalog::ORIGIN_PERMISSIONS[$data['request_type']].')');
        }

        return DB::transaction(function () use ($data, $actor) {
            $this->assertUnique($data);
            $rule = ApprovalRule::create($data + ['code' => $this->sequences->next('approval_rule'), 'is_origin_locked' => false]);
            $this->router->forget();
            $this->audit->log('Regla de aprobación', $rule->code, 'crear', null, $this->describe($rule), actor: $actor);

            return $rule;
        });
    }

    public function update(ApprovalRule $rule, array $data, User $actor, Request $http): ApprovalRule
    {
        $rule->assertIfMatch($http);
        $merged = $this->validate(array_intersect_key($data, array_flip(['permission', 'min_amount_pen', 'sla_hours', 'forbid_self_approval', 'is_active']))
            + $rule->only(['request_type', 'level', 'permission', 'min_amount_pen', 'sla_hours', 'forbid_self_approval', 'is_active']));
        if ($rule->is_origin_locked && $merged['permission'] !== $rule->permission) {
            throw ApiException::unprocessable('APR-BR-02', 'El permiso del nivel 1 de un tipo de origen no es editable');
        }

        return DB::transaction(function () use ($rule, $merged, $actor) {
            $before = $this->describe($rule);
            $rule->fill(array_diff_key($merged, array_flip(['request_type', 'level'])));
            $this->assertUnique($merged, $rule->id);
            $rule->save();
            $this->router->forget();
            $this->assertLevelOne($rule->request_type);
            $this->audit->log('Regla de aprobación', $rule->code, 'editar', $before, $this->describe($rule), actor: $actor);

            return $rule->fresh();
        });
    }

    public function delete(ApprovalRule $rule, User $actor, Request $http): void
    {
        $rule->assertIfMatch($http);
        if ($rule->level !== 2) {
            throw ApiException::rule('APR-BR-15', 'Solo se eliminan reglas de nivel 2; el nivel 1 se desactiva o edita');
        }
        DB::transaction(function () use ($rule, $actor) {
            $rule->delete();
            $this->router->forget();
            $this->audit->log('Regla de aprobación', $rule->code, 'eliminar', $this->describe($rule), null, actor: $actor);
        });
    }

    public function reset(User $actor): void
    {
        DB::transaction(function () use ($actor) {
            $count = ApprovalRule::count();
            ApprovalRule::query()->get()->each(fn (ApprovalRule $r) => $r->delete());
            CollabProvisioner::seedRules($this->tenant->id(), DB::connection(), fn () => $this->sequences->next('approval_rule'));
            $this->router->forget();
            $this->audit->log('Regla de aprobación', 'matriz', 'restaurar', "{$count} regla(s)", count(CollabCatalog::DEFAULT_RULES).' regla(s) por defecto', actor: $actor);
        });
    }

    /** APR-BR-15: cada tipo conserva al menos una regla activa de nivel 1. */
    private function assertLevelOne(string $type): void
    {
        if (! ApprovalRule::where('request_type', $type)->where('level', 1)->where('is_active', true)->exists()) {
            throw ApiException::rule('APR-BR-15', CollabCatalog::TYPES[$type].' debe conservar al menos una regla activa de nivel 1');
        }
    }

    private function assertUnique(array $data, ?string $except = null): void
    {
        $exists = ApprovalRule::where('request_type', $data['request_type'])->where('level', $data['level'])->where('min_amount_pen', $data['min_amount_pen'])
            ->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
        if ($exists) {
            throw ApiException::invalid('min_amount_pen', 'Ya existe una regla para ese tipo, nivel y monto desde.');
        }
    }

    /** `UpdateApprovalRuleRequest`: permiso existente (FK lógica) con al menos un usuario activo (APR-BR-15). */
    private function validate(array $data): array
    {
        $type = (string) ($data['request_type'] ?? '');
        if (! isset(CollabCatalog::TYPES[$type])) {
            throw ApiException::invalid('request_type', 'Tipo de solicitud no válido.');
        }
        $level = (int) ($data['level'] ?? 0);
        if (! in_array($level, [1, 2], true)) {
            throw ApiException::invalid('level', 'El nivel debe ser 1 o 2.');
        }
        $permission = (string) ($data['permission'] ?? '');
        if (! Permission::where('name', $permission)->exists() || str_starts_with($permission, 'supplier.')) {
            throw ApiException::invalid('permission', 'Elija un permiso interno existente.');
        }
        if (User::permission($permission)->where('status', User::ACTIVE)->count() === 0) {
            throw ApiException::unprocessable('APR-BR-15', "Ningún usuario activo tiene el permiso {$permission}");
        }
        $min = $data['min_amount_pen'] ?? 0;
        if (! is_numeric($min) || (float) $min < 0) {
            throw ApiException::invalid('min_amount_pen', 'El monto desde debe ser 0 o mayor.');
        }
        $sla = $data['sla_hours'] ?? null;
        if (! is_numeric($sla) || (int) $sla < 1 || (int) $sla > 720) {
            throw ApiException::invalid('sla_hours', 'El SLA debe estar entre 1 y 720 horas.');
        }

        return ['request_type' => $type, 'level' => $level, 'permission' => $permission, 'min_amount_pen' => number_format((float) $min, 2, '.', ''),
            'sla_hours' => (int) $sla, 'forbid_self_approval' => filter_var($data['forbid_self_approval'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'is_active' => filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)];
    }

    private function describe(ApprovalRule $r): string
    {
        return CollabCatalog::TYPES[$r->request_type]." · N{$r->level} · {$r->permission} · desde S/ {$r->min_amount_pen} · SLA {$r->sla_hours} h"
            .($r->forbid_self_approval ? '' : ' · permite autoaprobación').($r->is_active ? '' : ' · inactiva');
    }
}
