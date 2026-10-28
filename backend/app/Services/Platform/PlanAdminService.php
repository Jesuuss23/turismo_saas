<?php

namespace App\Services\Platform;

use App\Domain\Bookings\RuleResolver;
use App\Exceptions\ApiException;
use App\Models\Iam\PlatformUser;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Consola de planes (spec 13 §2 `plataforma/planes` y `flags`): precios y límites (PLN-BR-01/02), matriz de feature flags por plan,
 * overrides por tenant (PLN-BR-03) y matriz Planes × reglas de reserva (v2.2, DEMO editable, PLN-BR-04).
 */
class PlanAdminService
{
    public function __construct(private readonly PlatformEvents $events) {}

    public function plans(): array
    {
        $db = self::db();
        $limits = $db->table('plan_limits')->get()->groupBy('plan_id');
        $ranks = $db->table('plans')->pluck('rank', 'id');
        $rules = $db->table('plan_rule_entitlements')->get();
        $tenants = $db->table('subscriptions')->where('status', '<>', 'canceled')->groupBy('plan_id')->selectRaw('plan_id, count(*) as n')->pluck('n', 'plan_id');

        return $db->table('plans')->orderBy('rank')->get()->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'rank' => (int) $p->rank, 'price_month' => (float) $p->price_monthly, 'price_year' => (float) $p->price_year,
            'trial_days' => (int) $p->trial_days, 'status' => $p->status ?? 'active', 'currency' => $p->currency, 'lock_version' => (int) $p->lock_version,
            'tenants' => (int) ($tenants[$p->id] ?? 0), 'rules_count' => $rules->filter(fn ($r) => $ranks[$r->min_plan] <= $p->rank)->count(),
            'limits' => collect(PlatformCatalog::METRICS)->map(function ($meta, $metric) use ($limits, $p) {
                $row = ($limits[$p->id] ?? collect())->firstWhere('metric', $metric);

                return ['metric' => $metric, 'label' => $meta[0], 'unit' => $meta[1], 'value' => $row?->value === null ? null : (int) $row->value, 'hard' => (bool) ($row?->hard ?? true)];
            })->values()->all(),
        ])->all();
    }

    /** PLN-BR-01: el precio respeta el orden de rank y el anual no supera 12 mensualidades. PLN-BR-02: grandfathering de 30 días. */
    public function updatePlan(string $planId, array $data, ?string $ifMatch, PlatformUser $actor): array
    {
        $db = self::db();
        $plan = $db->table('plans')->where('id', $planId)->first() ?? throw ApiException::notFound();
        if ($ifMatch !== null && $ifMatch !== '' && (int) trim(preg_replace('/^W\//', '', $ifMatch), '"') !== (int) $plan->lock_version) {
            throw ApiException::conflict('STALE_VERSION', 'El plan fue modificado por otro usuario; recargue e intente de nuevo');
        }
        $month = array_key_exists('price_month', $data) ? (float) $data['price_month'] : (float) $plan->price_monthly;
        $year = array_key_exists('price_year', $data) ? (float) $data['price_year'] : (float) $plan->price_year;
        $trial = array_key_exists('trial_days', $data) ? (int) $data['trial_days'] : (int) $plan->trial_days;
        if ($month < 0 || $year < 0) {
            throw ApiException::invalid('price_month', 'Los precios no pueden ser negativos.');
        }
        if ($year > $month * 12) {
            throw ApiException::invalid('price_year', 'PLN-BR-01: el precio anual no puede superar 12 mensualidades (S/ '.number_format($month * 12, 2).').');
        }
        if ($trial < 0 || $trial > 60) {
            throw ApiException::invalid('trial_days', 'Los días de prueba van de 0 a 60.');
        }
        $lower = $db->table('plans')->where('rank', '<', $plan->rank)->orderByDesc('rank')->first();
        $higher = $db->table('plans')->where('rank', '>', $plan->rank)->orderBy('rank')->first();
        if (($lower && $month <= (float) $lower->price_monthly) || ($higher && $month >= (float) $higher->price_monthly)) {
            throw ApiException::invalid('price_month', "PLN-BR-01: el precio de {$plan->name} debe quedar entre el de "
                .($lower ? "{$lower->name} (S/ {$lower->price_monthly})" : 'cero').' y el de '.($higher ? "{$higher->name} (S/ {$higher->price_monthly})" : 'ningún plan superior').'.');
        }
        $limits = (array) ($data['limits'] ?? []);
        foreach ($limits as $metric => $value) {
            if (! isset(PlatformCatalog::METRICS[$metric])) {
                throw ApiException::invalid("limits.{$metric}", 'Métrica desconocida.');
            }
            if ($value !== null && (! is_numeric($value) || (int) $value < 0)) {
                throw ApiException::invalid("limits.{$metric}", 'El límite es un entero ≥ 0 o vacío (ilimitado).');
            }
        }
        $priceChanged = $month !== (float) $plan->price_monthly || $year !== (float) $plan->price_year;
        $lockUntil = PlatformClock::today()->addDays(30)->toDateString();
        $affected = [];
        $db->transaction(function (ConnectionInterface $db) use ($plan, $month, $year, $trial, $limits, $priceChanged, $lockUntil, &$affected) {
            $db->table('plans')->where('id', $plan->id)->update(['price_monthly' => $month, 'price_year' => $year, 'trial_days' => $trial,
                'lock_version' => $plan->lock_version + 1, 'updated_at' => now()]);
            foreach ($limits as $metric => $value) {
                $db->table('plan_limits')->updateOrInsert(['plan_id' => $plan->id, 'metric' => $metric], ['value' => $value === null || $value === '' ? null : (int) $value]);
            }
            if (! $priceChanged) {
                return;
            }
            // PLN-BR-02: las suscripciones vigentes conservan el precio anterior 30 días (aviso previo) y desde la renovación siguiente rige el nuevo.
            foreach ($db->table('subscriptions')->where('plan_id', $plan->id)->whereIn('status', ['active', 'past_due', 'cancel_scheduled'])->whereNull('negotiated_price')->get() as $sub) {
                $db->table('subscriptions')->where('id', $sub->id)->update(['negotiated_price' => $sub->cycle === 'annual' ? $plan->price_year : $plan->price_monthly,
                    'price_locked_until' => $lockUntil, 'updated_at' => now()]);
                $affected[] = $sub->tenant_id;
            }
        });
        if (isset($data['max_users']) || array_key_exists('users', $limits) || array_key_exists('storage_gb', $limits) || array_key_exists('sedes', $limits)) {
            // Compatibilidad con la consola de spec 09 (columnas del plan).
            $row = $db->table('plan_limits')->where('plan_id', $plan->id)->pluck('value', 'metric');
            $db->table('plans')->where('id', $plan->id)->update(['max_users' => $row['users'] ?? null, 'max_storage_gb' => $row['storage_gb'] ?? 0, 'max_sedes' => $row['sedes'] ?? null]);
        }
        foreach ($affected as $tenantId) {
            $this->events->notifyTenant($tenantId, 'plan.cambio_condiciones', "Nuevo precio del plan {$plan->name}",
                'Desde el '.Carbon::parse($lockUntil)->format('d/m/Y')." el plan {$plan->name} costará S/ ".number_format($month, 2).' al mes (S/ '.number_format($year, 2).' al año). Hasta entonces mantiene su precio actual.');
        }
        $this->auditHome($actor, 'Plan', $plan->id, 'editar_plan', "S/ {$plan->price_monthly} · {$plan->price_year} · {$plan->trial_days} d",
            "S/ {$month} · {$year} · {$trial} d".($limits ? ' · '.count($limits).' límite(s)' : '').($affected ? ' · '.count($affected).' tenant(s) avisados' : ''));
        EntitlementService::forgetAll();

        return ['message' => "Plan {$plan->name} actualizado.".($affected ? ' '.count($affected).' tenant(s) conservan su precio 30 días (PLN-BR-02).' : ''), 'plans' => $this->plans()];
    }

    // ───────────────────────────── Feature flags ─────────────────────────────

    public function features(): array
    {
        $db = self::db();
        $values = $db->table('plan_features')->get()->groupBy('feature_key');
        $plans = $db->table('plans')->orderBy('rank')->get(['id', 'name']);
        $tenants = $db->table('tenants')->pluck('code', 'id');
        $names = $db->table('tenants')->get(['id', 'trade_name', 'legal_name'])->mapWithKeys(fn ($t) => [$t->id => $t->trade_name ?? $t->legal_name]);
        $today = PlatformClock::today()->toDateString();

        return [
            'plans' => $plans->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->all(),
            'features' => $db->table('features')->orderBy('sort')->get()->map(fn ($f) => ['key' => $f->key, 'label' => $f->label, 'module' => $f->module, 'kind' => $f->kind,
                'addon_code' => $f->addon_code, 'routes' => json_decode($f->routes, true),
                'values' => ($values[$f->key] ?? collect())->mapWithKeys(fn ($v) => [$v->plan_id => $v->value])->all()])->all(),
            'overrides' => $db->table('feature_overrides as o')->join('features as f', 'f.key', '=', 'o.feature_key')->orderByDesc('o.created_at')
                ->get(['o.*', 'f.label'])->map(fn ($o) => ['id' => $o->id, 'tenant_id' => $o->tenant_id, 'tenant_code' => $tenants[$o->tenant_id] ?? '—',
                    'tenant_name' => $names[$o->tenant_id] ?? '', 'feature_key' => $o->feature_key, 'feature_label' => $o->label, 'value' => (bool) $o->value,
                    'reason' => $o->reason, 'valid_until' => $o->valid_until, 'expired' => $o->valid_until !== null && $o->valid_until < $today, 'created_at' => $o->created_at])->all(),
            'tenants' => $db->table('tenants')->orderBy('code')->get(['id', 'code', 'trade_name', 'legal_name', 'plan_id'])
                ->map(fn ($t) => ['value' => $t->id, 'label' => "{$t->code} · ".($t->trade_name ?? $t->legal_name), 'plan_id' => $t->plan_id])->all(),
            'values' => ['included' => 'Incluido', 'not_included' => 'No incluido', 'basico' => 'Básico', 'completo' => 'Completo'],
        ];
    }

    public function setPlanFeature(string $key, string $planId, string $value, PlatformUser $actor): array
    {
        $db = self::db();
        $feature = $db->table('features')->where('key', $key)->first() ?? throw ApiException::notFound();
        $plan = $db->table('plans')->where('id', $planId)->first() ?? throw ApiException::notFound();
        if (! in_array($value, ['included', 'not_included', 'basico', 'completo'], true)) {
            throw ApiException::invalid('value', 'Valor inválido.');
        }
        $before = $db->table('plan_features')->where('feature_key', $key)->where('plan_id', $planId)->value('value') ?? 'not_included';
        $db->table('plan_features')->updateOrInsert(['feature_key' => $key, 'plan_id' => $planId], ['value' => $value, 'label' => $feature->label]
            + ($db->table('plan_features')->where('feature_key', $key)->where('plan_id', $planId)->exists() ? [] : ['id' => (string) Str::uuid7()]));
        EntitlementService::forgetAll();
        $this->auditHome($actor, 'Feature flag', "{$key}:{$planId}", 'plan_entitlement', "{$plan->name}: {$before}", "{$plan->name}: {$value}");

        return ['message' => "{$feature->label} en {$plan->name}: ".($value === 'not_included' ? 'no incluido' : 'habilitado').'.'] + $this->features();
    }

    /** PLN-BR-03: un override dura como máximo 180 días; vencido deja de regir sin job. */
    public function addOverride(array $data, PlatformUser $actor): array
    {
        $db = self::db();
        $tenant = $db->table('tenants')->where('id', $data['tenant_id'] ?? '')->first() ?? throw ApiException::invalid('tenant_id', 'Elija un tenant.');
        $feature = $db->table('features')->where('key', $data['feature_key'] ?? '')->first() ?? throw ApiException::invalid('feature_key', 'Elija una funcionalidad.');
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw ApiException::invalid('reason', 'El motivo es obligatorio.');
        }
        $today = PlatformClock::today();
        $until = $data['valid_until'] ?? null;
        if ($until !== null && $until !== '') {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $until) || $until < $today->toDateString()) {
                throw ApiException::invalid('valid_until', 'La vigencia debe ser una fecha futura.');
            }
            if ($until > $today->copy()->addDays(180)->toDateString()) {
                throw ApiException::invalid('valid_until', 'PLN-BR-03: un override dura como máximo 180 días.');
            }
        } else {
            $until = $today->copy()->addDays(180)->toDateString();
        }
        $value = filter_var($data['value'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $db->table('feature_overrides')->where('tenant_id', $tenant->id)->where('feature_key', $feature->key)->delete();
        $db->table('feature_overrides')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'feature_key' => $feature->key, 'value' => $value,
            'reason' => Str::limit($reason, 295), 'valid_until' => $until, 'granted_by' => $actor->id, 'created_at' => now()]);
        EntitlementService::forget($tenant->id);
        $this->events->audit($tenant->id, 'Feature flag', $feature->key, 'override', null, ($value ? 'Habilitado' : 'Deshabilitado').' hasta '.Carbon::parse($until)->format('d/m/Y')." · {$reason}", $actor);

        return ['message' => "Excepción registrada para {$tenant->code}: {$feature->label}."] + $this->features();
    }

    public function removeOverride(string $id, PlatformUser $actor): array
    {
        $db = self::db();
        $o = $db->table('feature_overrides')->where('id', $id)->first() ?? throw ApiException::notFound();
        $db->table('feature_overrides')->where('id', $id)->delete();
        EntitlementService::forget($o->tenant_id);
        $this->events->audit($o->tenant_id, 'Feature flag', $o->feature_key, 'quitar_override', $o->value ? 'Habilitado' : 'Deshabilitado', 'Rige el plan', $actor);

        return ['message' => 'Excepción eliminada.'] + $this->features();
    }

    // ───────────────────────────── v2.2 Planes × reglas (DEMO) ─────────────────────────────

    public function planRules(): array
    {
        $db = self::db();
        $plans = $db->table('plans')->orderBy('rank')->get(['id', 'name', 'rank']);
        $ranks = $plans->pluck('rank', 'id');
        $tenantsByPlan = $db->table('tenants')->get(['id', 'plan_id'])->groupBy('plan_id')->map->count();
        $rules = $db->table('plan_rule_entitlements as e')->join('booking_rule_catalog as c', 'c.code', '=', 'e.rule_code')->orderBy('c.sort')->get(['c.*', 'e.min_plan', 'e.is_demo', 'e.lock_version']);
        $groups = [];
        foreach (PlatformCatalog::RULE_GROUPS as $key => $label) {
            $groups[$key] = ['key' => $key, 'label' => $label, 'rules' => []];
        }
        foreach ($rules as $r) {
            $groups[$r->group_key]['rules'][] = ['code' => $r->code, 'label' => $r->label, 'min_plan' => $r->min_plan, 'lock_version' => (int) $r->lock_version,
                'tenants_with_access' => $plans->filter(fn ($p) => $p->rank >= $ranks[$r->min_plan])->sum(fn ($p) => $tenantsByPlan[$p->id] ?? 0)];
        }

        return ['is_demo' => (bool) $rules->contains('is_demo', true), 'note' => 'Matriz de demostración (decisión del cliente 30/09/2026): editable hasta que negocio la apruebe.',
            'plans' => $plans->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'rules_count' => $rules->filter(fn ($r) => $ranks[$r->min_plan] <= $p->rank)->count()])->all(),
            'groups' => array_values($groups)];
    }

    /** PLN-BR-04: cambia el plan mínimo de una regla; devuelve los tenants que ganan o pierden. `dry_run` solo calcula. */
    public function setRuleMinPlan(string $rule, string $minPlan, ?string $ifMatch, bool $dryRun, PlatformUser $actor): array
    {
        $db = self::db();
        $row = $db->table('plan_rule_entitlements as e')->join('booking_rule_catalog as c', 'c.code', '=', 'e.rule_code')->where('e.rule_code', $rule)->first(['e.*', 'c.label'])
            ?? throw ApiException::notFound();
        $ranks = $db->table('plans')->pluck('rank', 'id');
        if (! isset($ranks[$minPlan])) {
            throw ApiException::invalid('min_plan', 'Plan inexistente.');
        }
        if (! $dryRun && $ifMatch !== null && $ifMatch !== '' && (int) trim(preg_replace('/^W\//', '', $ifMatch), '"') !== (int) $row->lock_version) {
            throw ApiException::conflict('STALE_VERSION', 'La regla fue modificada por otro usuario; recargue e intente de nuevo');
        }
        $from = $ranks[$row->min_plan];
        $to = $ranks[$minPlan];
        $tenants = $db->table('tenants')->orderBy('code')->get(['id', 'code', 'trade_name', 'legal_name', 'plan_id']);
        $affected = [];
        foreach ($tenants as $t) {
            $had = $ranks[$t->plan_id] >= $from;
            $has = $ranks[$t->plan_id] >= $to;
            if ($had !== $has) {
                $affected[] = ['tenant_id' => $t->id, 'code' => $t->code, 'name' => $t->trade_name ?? $t->legal_name, 'plan_id' => $t->plan_id, 'change' => $has ? 'gana' : 'pierde'];
            }
        }
        $names = $db->table('plans')->pluck('name', 'id');
        if ($dryRun || $minPlan === $row->min_plan) {
            $summary = $affected === [] ? 'ningún tenant cambia de acceso' : implode(', ', array_map(fn ($a) => "{$a['code']} {$a['change']} acceso", $affected));

            return ['rule' => $rule, 'label' => $row->label, 'from' => $row->min_plan, 'to' => $minPlan, 'affected_tenants' => $affected, 'applied' => false,
                'message' => "Simulación «{$row->label}» con {$names[$minPlan]}: {$summary}."];
        }
        $db->table('plan_rule_entitlements')->where('rule_code', $rule)->update(['min_plan' => $minPlan, 'lock_version' => $row->lock_version + 1, 'updated_by' => $actor->id, 'updated_at' => now()]);
        RuleResolver::bumpEntitlements();
        $gain = count(array_filter($affected, fn ($a) => $a['change'] === 'gana'));
        $lose = count($affected) - $gain;
        $this->auditHome($actor, 'Plan × regla', $rule, 'cambiar_plan_minimo', $names[$row->min_plan], "{$names[$minPlan]} · ganan {$gain} · pierden {$lose}");
        foreach ($affected as $a) {
            // Los que pierden la regla conservan su configuración; el motor deja de aplicarla (RULE_NOT_IN_PLAN).
            $this->events->notifyTenant($a['tenant_id'], 'plan.cambio_reglas', $a['change'] === 'gana' ? "Su plan ahora incluye «{$row->label}»" : "«{$row->label}» deja de estar incluida en su plan",
                $a['change'] === 'gana' ? 'Ya puede configurarla en el centro de reglas.' : "Ahora requiere el plan {$names[$minPlan]}. Su configuración se conserva por si mejora su plan.");
        }

        return ['rule' => $rule, 'label' => $row->label, 'from' => $row->min_plan, 'to' => $minPlan, 'affected_tenants' => $affected, 'applied' => true,
            'message' => "«{$row->label}» ahora requiere {$names[$minPlan]}."] + ['matrix' => $this->planRules()];
    }

    public function resetDemo(PlatformUser $actor): array
    {
        $db = self::db();
        foreach (PlatformCatalog::bookingRules() as $code => [, , $min]) {
            $db->table('plan_rule_entitlements')->where('rule_code', $code)->update(['min_plan' => $min, 'is_demo' => true, 'updated_by' => $actor->id, 'updated_at' => now()]);
        }
        RuleResolver::bumpEntitlements();
        $this->auditHome($actor, 'Plan × regla', 'matriz', 'restablecer_demo', null, 'Matriz demo restablecida (15/14/3)');

        return ['message' => 'Matriz de demostración restablecida.'] + $this->planRules();
    }

    private function auditHome(PlatformUser $actor, string $entity, string $id, string $action, ?string $before, ?string $after): void
    {
        if ($actor->home_tenant_id) {
            $this->events->audit($actor->home_tenant_id, $entity, $id, $action, $before, $after, $actor);
        }
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection(PlatformCatalog::CONNECTION);
    }
}
