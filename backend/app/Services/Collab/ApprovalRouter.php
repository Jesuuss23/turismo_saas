<?php

namespace App\Services\Collab;

use App\Exceptions\ApiException;
use App\Models\Collab\ApprovalRequest;
use App\Models\Collab\ApprovalRule;
use App\Models\Collab\ApprovalStep;
use App\Models\Iam\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Enrutamiento por la matriz (APR-BR-01), elegibilidad del aprobador (APR-BR-03/04) y visibilidad por rol (§7).
 */
class ApprovalRouter
{
    /** Ven todas las solicitudes del tenant (§7). */
    public const SEE_ALL_ROLES = ['tenant_admin', 'manager'];

    /** @var array<string, Collection<int, ApprovalRule>> */
    private array $cache = [];

    /** @return Collection<int, ApprovalRule> */
    public function activeRules(string $type): Collection
    {
        return $this->cache[app(TenantContext::class)->id().':'.$type] ??= ApprovalRule::where('request_type', $type)->where('is_active', true)->orderByDesc('min_amount_pen')->get();
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * APR-BR-01: niveles aplicables = reglas activas del tipo con umbral ≤ monto base; por nivel, la de mayor umbral.
     *
     * @return array<int, ApprovalRule> [1 => regla, 2 => regla?]
     */
    public function levels(string $type, float $amountBase): array
    {
        $rules = $this->activeRules($type);
        $levels = [];
        foreach ([1, 2] as $level) {
            $rule = $rules->first(fn (ApprovalRule $r) => $r->level === $level && (float) $r->min_amount_pen <= $amountBase + 0.004);
            if ($rule) {
                $levels[$level] = $rule;
            }
        }
        if (! isset($levels[1])) {
            $first = $rules->where('level', 1)->sortBy('min_amount_pen')->first()
                ?? throw ApiException::rule('APR-BR-15', 'El tipo '.CollabCatalog::TYPES[$type].' no tiene una regla activa de nivel 1');
            $levels = [1 => $first] + $levels;
        }
        ksort($levels);

        return $levels;
    }

    /** Regla del nivel en curso (recalculada mientras está viva; si ya no aplica, la registrada). */
    public function currentRule(ApprovalRequest $request): ApprovalRule
    {
        if ($request->isLive()) {
            $levels = $this->levels($request->request_type, (float) $request->amount_base);
            if (isset($levels[$request->current_level])) {
                return $levels[$request->current_level];
            }
        }

        return $request->rule ?? throw ApiException::rule('APR-BR-15', 'La solicitud no tiene una regla vigente');
    }

    /** APR-BR-04: activo + approvals.view + permiso del nivel; sin el solicitante (si la regla lo prohíbe) ni quien ya decidió un nivel. */
    public function eligibleUsers(ApprovalRequest $request, ?ApprovalRule $rule = null, bool $ignoreDelegation = false): Collection
    {
        $rule ??= $this->currentRule($request);
        $deciders = ApprovalStep::where('request_id', $request->id)->pluck('decided_by')->all();
        $users = User::permission($rule->permission)->where('status', User::ACTIVE)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->checkPermissionTo('approvals.view'))
            ->reject(fn (User $u) => in_array($u->id, $deciders, true))
            ->reject(fn (User $u) => $rule->forbid_self_approval && $u->id === $request->requester_id)
            ->values();
        if (! $ignoreDelegation && $request->delegated_to) {
            return $users->where('id', $request->delegated_to)->values();
        }

        return $users;
    }

    /** Motivo por el que el usuario no puede decidir el nivel actual (null = puede). @return array{0: string, 1: string}|null */
    public function blocker(User $user, ApprovalRequest $request, ?ApprovalRule $rule = null): ?array
    {
        if ($request->status !== 'pendiente') {
            return ['APR-STATE', $request->status === 'info' ? 'La solicitud espera la información pedida al solicitante' : 'La solicitud ya fue resuelta'];
        }
        $rule ??= $this->currentRule($request);
        if (! $user->checkPermissionTo('approvals.view') || ! $user->checkPermissionTo($rule->permission)) {
            return ['APR-BR-04', "El nivel {$request->current_level} requiere el permiso {$rule->permission}"];
        }
        if ($request->delegated_to && $request->delegated_to !== $user->id) {
            return ['APR-BR-04', 'La solicitud fue delegada a otro aprobador'];
        }
        if ($rule->forbid_self_approval && $request->requester_id === $user->id) {
            return ['APR-BR-03', 'Segregación de funciones: no puede aprobar su propia solicitud'];
        }
        if (ApprovalStep::where('request_id', $request->id)->where('decided_by', $user->id)->exists()) {
            return ['APR-BR-03', 'Segregación de funciones: ya aprobó un nivel de esta solicitud'];
        }

        return null;
    }

    public function assertCanDecide(User $user, ApprovalRequest $request): ApprovalRule
    {
        $rule = $this->currentRule($request);
        if ($blocker = $this->blocker($user, $request, $rule)) {
            [$code, $message] = $blocker;
            throw $code === 'APR-STATE' ? ApiException::rule('APR-BR-08', $message) : ApiException::forbidden($code, $message, [$rule->permission]);
        }

        return $rule;
    }

    public function seesAll(User $user): bool
    {
        return $user->hasAnyRole(self::SEE_ALL_ROLES);
    }

    /** §7: Admin/Manager todo; el resto lo propio, lo delegado, lo que decidió y los tipos/niveles cuyo permiso tiene. */
    public function scopeVisible(Builder $query, User $user): Builder
    {
        if ($this->seesAll($user)) {
            return $query;
        }
        $grants = [];
        foreach (array_keys(CollabCatalog::TYPES) as $type) {
            $held = $this->activeRules($type)->filter(fn (ApprovalRule $r) => $user->checkPermissionTo($r->permission))->pluck('level')->unique()->all();
            if (in_array(1, $held, true)) {
                $grants[$type] = 1;
            } elseif (in_array(2, $held, true)) {
                $grants[$type] = 2;
            }
        }

        return $query->where(function (Builder $q) use ($user, $grants) {
            $q->where('requester_id', $user->id)->orWhere('delegated_to', $user->id)
                ->orWhereExists(fn ($s) => $s->from('collab_approval_steps as s')->whereColumn('s.request_id', 'collab_approval_requests.id')->where('s.decided_by', $user->id));
            foreach ($grants as $type => $minLevel) {
                $q->orWhere(fn (Builder $w) => $w->where('request_type', $type)->when($minLevel === 2, fn ($x) => $x->where('total_levels', 2)));
            }
        });
    }

    public function visible(User $user, ApprovalRequest $request): bool
    {
        return $this->scopeVisible(ApprovalRequest::whereKey($request->id), $user)->exists();
    }
}
