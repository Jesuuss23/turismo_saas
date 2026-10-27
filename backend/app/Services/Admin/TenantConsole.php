<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Admin\Setting;
use App\Models\Iam\PlatformUser;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Auth\TokenRevoker;
use App\Services\Notify;
use App\Services\TenantProvisioner;
use App\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Consola de plataforma (PROC-001, spec 09 §4.1, §6.1). Solo metadatos de tenants: nunca lee datos de negocio
 * (los contadores salen de `platform_tenant_stats()`, SECURITY DEFINER). Escribe con la conexión de plataforma.
 */
class TenantConsole
{
    /** Asistente de alta en 11 pasos, en el orden del documento (spec 09 §11-1). */
    public const STEPS = [
        1 => 'Crear tenant', 2 => 'Datos fiscales', 3 => 'Plan y entitlements', 4 => 'Sede principal', 5 => 'Administrador',
        6 => 'Roles', 7 => 'Permisos', 8 => 'Canales', 9 => 'Configuración comercial', 10 => 'Configuración financiera', 11 => 'Revisar y activar',
    ];

    public const CURRENCIES = ['PEN', 'USD', 'BOB', 'CLP'];

    public const ALT_CURRENCIES = ['USD', 'PEN', 'EUR'];

    public const LOCALES = ['es-PE' => 'Español (Perú)', 'es-419' => 'Español (Latinoamérica)', 'en-US' => 'English (US)'];

    public const TIMEZONES = ['America/Lima', 'America/La_Paz', 'America/Santiago', 'America/Guayaquil', 'America/Bogota'];

    public const TEMPLATES = ['estandar' => 'Estándar (matriz recomendada)', 'minimo_privilegio' => 'Mínimo privilegio', 'copiar_tenant' => 'Copiar de otro tenant'];

    public const STATUS = ['prueba' => 'En prueba', 'activo' => 'Activo', 'suspendido' => 'Suspendido'];

    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly TokenRevoker $tokens,
    ) {}

    // ───────────────────────────── Lectura ─────────────────────────────

    public function list(array $filters): array
    {
        $stats = $this->stats();
        $plans = $this->plans();
        $rows = $this->db()->table('tenants')->orderBy('code')->get()->map(fn ($t) => $this->row($t, $stats[$t->id] ?? null, $plans));
        $all = $rows;
        $rows = $rows->when($filters['plan'] ?? null, fn ($c, $p) => $c->where('plan_id', $p))
            ->when($filters['status'] ?? null, fn ($c, $s) => $c->where('status', $s))
            ->when($filters['q'] ?? null, fn ($c, $q) => $c->filter(fn ($r) => Str::contains(Str::lower($r['trade_name'].' '.$r['legal_name'].' '.$r['slug'].' '.$r['ruc'].' '.$r['code']), Str::lower($q))));
        $operating = $all->whereIn('status', ['prueba', 'activo']);
        $mrr = (float) $all->sum('mrr');

        return [
            'data' => $rows->values()->all(),
            'meta' => [
                'kpis' => [
                    ['key' => 'active', 'label' => 'Tenants operando', 'value' => $operating->count(), 'hint' => $all->where('status', 'prueba')->count().' en prueba · '.$all->where('status', 'suspendido')->count().' suspendidos'],
                    ['key' => 'users', 'label' => 'Usuarios activos', 'value' => $all->sum('users')],
                    ['key' => 'mrr', 'label' => 'MRR', 'value' => round($mrr, 2), 'unit' => 'PEN', 'hint' => 'ARR S/ '.number_format($mrr * 12, 0, '.', ',')],
                    ['key' => 'storage', 'label' => 'Almacenamiento', 'value' => round($all->sum('storage_gb'), 2), 'unit' => 'GB'],
                ],
                'plans' => array_values($plans),
                'entitlements' => $this->entitlements(),
                'statuses' => self::STATUS,
            ],
        ];
    }

    public function show(string $id): array
    {
        $t = $this->find($id);
        $stats = $this->stats()[$t->id] ?? null;
        $plans = $this->plans();
        $plan = $plans[$t->plan_id];
        $features = $this->db()->table('plan_features')->where('plan_id', $t->plan_id)->orderBy('feature_key')->get();

        return $this->row($t, $stats, $plans) + [
            'fiscal_address' => $t->fiscal_address, 'business_type' => $t->business_type, 'legal_rep' => $t->legal_rep, 'custom_domain' => $t->custom_domain,
            'timezone' => $t->timezone, 'locale' => $t->locale, 'currency' => $t->currency, 'alt_currency' => $t->alt_currency, 'suspend_reason' => $t->suspend_reason,
            'suspended_at' => $t->suspended_at, 'trial_ends_at' => $t->trial_ends_at, 'created_at' => $t->created_at, 'lock_version' => (int) $t->lock_version,
            'usage' => [
                ['label' => 'Usuarios', 'used' => $stats->users ?? 0, 'limit' => $plan['max_users']],
                ['label' => 'Sedes', 'used' => $stats->sedes ?? 0, 'limit' => $plan['max_sedes']],
                ['label' => 'Almacenamiento (GB)', 'used' => round(((int) $t->storage_used_bytes) / 1024 ** 3, 2), 'limit' => $plan['max_storage_gb']],
            ],
            'features' => $features->map(fn ($f) => ['key' => $f->feature_key, 'label' => $f->label, 'value' => $f->value, 'included' => $f->value !== 'not_included'])->all(),
            'channels' => $this->db()->table('plan_channels')->orderBy('channel_key')->get()->map(fn ($c) => [
                'key' => $c->channel_key, 'label' => $c->label, 'min_plan' => $plans[$c->min_plan_id]['name'], 'included' => $plans[$c->min_plan_id]['rank'] <= $plan['rank'],
            ])->all(),
            'health' => $stats ? (array) $stats : null,
            'onboarding' => $this->db()->table('tenant_onboarding')->where('tenant_id', $t->id)->first(['permission_template', 'created_at']),
            'history' => $this->db()->table('audit_events')->where('entity', 'Tenant')->where('entity_id', $t->code)->orderByDesc('occurred_at')->limit(12)
                ->get(['code', 'occurred_at', 'action', 'before_value', 'after_value', 'actor_id'])
                ->map(fn ($e) => ['code' => 'AUD-'.$e->code, 'occurred_at' => (string) $e->occurred_at, 'action' => $e->action, 'before' => $e->before_value, 'after' => $e->after_value])->all(),
        ];
    }

    /** §6.1 health: salud por tenant (integraciones, jobs, errores abiertos, última actividad). */
    public function health(): array
    {
        $stats = $this->stats();
        $plans = $this->plans();

        return ['data' => $this->db()->table('tenants')->orderBy('code')->get()->map(function ($t) use ($stats, $plans) {
            $s = $stats[$t->id] ?? null;
            $level = match (true) {
                $t->status === 'suspendido' => 'suspendido',
                ($s->definitive_errors ?? 0) > 0 || ($s->jobs_error ?? 0) > 0 => 'error',
                ($s->open_errors ?? 0) > 0 || ($s->mfa_missing ?? 0) > 0 => 'advertencia',
                default => 'ok',
            };

            return ['id' => $t->id, 'code' => $t->code, 'name' => $t->trade_name ?? $t->legal_name, 'plan' => $plans[$t->plan_id]['name'] ?? $t->plan_id, 'status' => $t->status,
                'integrations' => ($s->integrations_ok ?? 0).'/'.($s->integrations_total ?? 0), 'jobs_error' => $s->jobs_error ?? 0, 'jobs_paused' => $s->jobs_paused ?? 0,
                'open_errors' => $s->open_errors ?? 0, 'definitive_errors' => $s->definitive_errors ?? 0, 'mfa_missing' => $s->mfa_missing ?? 0,
                'last_activity' => $s->last_activity ?? null, 'level' => $level];
        })->all()];
    }

    public function plans(): array
    {
        return $this->db()->table('plans')->orderBy('rank')->get()->mapWithKeys(fn ($p) => [$p->id => [
            'id' => $p->id, 'name' => $p->name, 'rank' => (int) $p->rank, 'price' => (float) $p->price_monthly, 'currency' => $p->currency, 'max_users' => $p->max_users,
            'max_storage_gb' => (int) $p->max_storage_gb, 'max_sedes' => $p->max_sedes, 'support' => $p->support_level, 'modules' => (int) $p->module_count,
        ]])->all();
    }

    public function entitlements(): array
    {
        $rows = $this->db()->table('plan_features')->get()->groupBy('feature_key');

        return [
            'features' => collect(AdminCatalog::FEATURES)->map(fn ($f, $key) => ['key' => $key, 'label' => $f[0],
                'values' => ($rows[$key] ?? collect())->mapWithKeys(fn ($r) => [$r->plan_id => $r->value])->all()])->values()->all(),
            'channels' => $this->db()->table('plan_channels')->orderBy('channel_key')->get()->map(fn ($c) => (array) $c)->all(),
        ];
    }

    /** Datos del asistente (pasos, catálogos y roles plantilla). */
    public function wizard(): array
    {
        return [
            'steps' => collect(self::STEPS)->map(fn ($label, $n) => ['n' => $n, 'label' => $label])->values()->all(),
            'plans' => array_values($this->plans()), 'business_types' => AdminCatalog::BUSINESS_TYPES, 'countries' => AdminCatalog::COUNTRIES,
            'currencies' => self::CURRENCIES, 'alt_currencies' => self::ALT_CURRENCIES, 'locales' => self::LOCALES, 'timezones' => self::TIMEZONES,
            'templates' => self::TEMPLATES, 'roles' => $this->templateRoles(), 'channels' => $this->entitlements()['channels'],
            'tenants' => $this->db()->table('tenants')->orderBy('code')->get(['id', 'code', 'trade_name', 'legal_name'])
                ->map(fn ($t) => ['value' => $t->id, 'label' => $t->code.' · '.($t->trade_name ?? $t->legal_name)])->all(),
            'base_domain' => config('turismo.tenant_base_domain'),
            'defaults' => ['quote_validity_days' => 10, 'hold_minutes' => 30, 'max_discount_pct' => 5, 'currency' => 'PEN', 'alt_currency' => 'USD', 'igv_pct' => 18,
                'fx_rate' => 3.75, 'timezone' => 'America/Lima', 'locale' => 'es-PE', 'country' => 'Perú', 'plan_id' => 'professional', 'status' => 'prueba',
                'permission_template' => 'estandar', 'roles' => array_column($this->templateRoles(), 'name'), 'channels' => ['portal_cliente', 'portal_pasajero', 'b2b']],
        ];
    }

    // ───────────────────────────── Alta (PROC-001) ─────────────────────────────

    /** Valida un paso del asistente (TEN-BR-01…05). */
    public function validateStep(int $step, array $input): array
    {
        if (! isset(self::STEPS[$step])) {
            throw ApiException::invalid('step', 'Paso inválido');
        }
        Validator::make($this->normalize($input), $this->rules($step, $input), $this->messages())->validate();
        if ($step === 8) {
            $this->assertChannels($input['plan_id'] ?? 'professional', $input['channels'] ?? []);
        }

        return ['step' => $step, 'valid' => true];
    }

    public function store(array $input, string $idempotencyKey, PlatformUser $actor): array
    {
        if ($existing = $this->db()->table('tenant_onboarding')->where('idempotency_key', $idempotencyKey)->value('tenant_id')) {
            return $this->show($existing);
        }
        $input = $this->normalize($input);
        $rules = [];
        foreach (array_keys(self::STEPS) as $step) {
            $rules += $this->rules($step, $input);
        }
        $data = Validator::make($input, $rules, $this->messages())->validate();
        $this->assertChannels($data['plan_id'], $data['channels'] ?? []);

        $tenant = $this->provisioner->provision([
            'slug' => $data['slug'], 'legal_name' => $data['legal_name'], 'trade_name' => $data['trade_name'], 'ruc' => $data['ruc'], 'plan_id' => $data['plan_id'],
            'status' => $data['status'], 'logo_initials' => $data['logo_initials'] ?? null, 'fiscal_address' => $data['fiscal_address'], 'business_type' => $data['business_type'],
            'legal_rep' => $data['legal_rep'] ?? null, 'country' => $data['country'], 'currency' => $data['currency'], 'alt_currency' => $data['alt_currency'],
            'timezone' => $data['timezone'], 'locale' => $data['locale'], 'igv_pct' => $data['igv_pct'], 'admin_email' => $data['admin_email'], 'created_by' => $actor->id,
            'custom_domain' => $data['custom_domain'] ?? null, 'fx_rate' => $data['fx_rate'],
            'settings' => ['timezone' => $data['timezone'], 'fx' => ['USD' => (float) $data['fx_rate']],
                'sales' => ['quote_validity_days' => (int) $data['quote_validity_days']], 'catalog' => ['hold_minutes' => (int) $data['hold_minutes']]],
        ], $data['sede_name'], [
            'roles' => $data['roles'] ?? null, 'template' => $data['permission_template'], 'copy_from' => $data['copy_from_tenant_id'] ?? null,
            'sede' => ['name' => $data['sede_name'], 'city' => $data['sede_city'], 'address' => $data['sede_address']],
            'overrides' => [
                'policies' => ['quote_validity_days' => (int) $data['quote_validity_days'], 'hold_minutes' => (int) $data['hold_minutes'], 'max_discount_pct' => (string) $data['max_discount_pct']],
                'currency' => ['alt_currency' => $data['alt_currency'], 'fx_rate' => number_format((float) $data['fx_rate'], 6, '.', ''), 'tax_pct' => (string) (float) $data['igv_pct']],
                'general' => ['lang' => $data['locale'], 'email' => $data['admin_email'], 'address' => $data['fiscal_address'], 'legal_rep' => $data['legal_rep'] ?? null],
                'channels' => collect(AdminCatalog::CHANNELS)->mapWithKeys(fn ($c, $key) => [$key => in_array($key, $data['channels'] ?? [], true)])->all(),
            ],
        ]);
        DB::connection(TenantProvisioner::CONNECTION)->table('tenant_onboarding')->insert([
            'tenant_id' => $tenant->id, 'payload' => json_encode(collect($data)->except([])->all()), 'permission_template' => $data['permission_template'],
            'copied_from_tenant_id' => $data['permission_template'] === 'copiar_tenant' ? ($data['copy_from_tenant_id'] ?? null) : null,
            'idempotency_key' => $idempotencyKey, 'created_at' => now(),
        ]);

        $link = $this->context->run($tenant, function () use ($tenant, $data, $actor) {
            $link = app(UserAdmin::class)->inviteInitialAdmin($data['admin_name'], $data['admin_email'], $data['admin_phone'] ?? null, $actor);
            $this->audit->log('Tenant', $tenant->code, 'alta', null, "{$tenant->trade_name} · plan ".AdminCatalog::planName($tenant->plan_id).' · '.self::STATUS[$tenant->status], actor: $actor);
            $this->notify->push('tenant.activado', 'email', $data['admin_email'], "Su cuenta {$tenant->trade_name} está lista",
                "Se creó la cuenta {$tenant->code} ({$tenant->slug}.".config('turismo.tenant_base_domain').') en el plan '.AdminCatalog::planName($tenant->plan_id).
                ($tenant->status === 'prueba' ? ' con 14 días de prueba.' : '.')."\nActive su usuario administrador desde la invitación enviada.", ['related_type' => 'tenant', 'related_id' => $tenant->id]);

            return $link;
        });

        return $this->show($tenant->id) + ['invitation_url' => app()->isProduction() ? null : $link, 'message' => "Tenant {$tenant->code} creado"];
    }

    // ───────────────────────────── Ciclo de vida ─────────────────────────────

    /** TEN-BR-07/08: motivo obligatorio; no se suspende el tenant de la sesión activa; revoca tokens. */
    public function suspend(string $id, string $reason, ?string $ifMatch, PlatformUser $actor): array
    {
        $t = $this->find($id);
        $this->assertVersion($t, $ifMatch);
        if ($actor->home_tenant_id === $t->id) {
            throw ApiException::conflict('TEN-BR-07', 'No puede suspender el tenant de la sesión activa: solicítelo desde otra cuenta de plataforma.');
        }
        if ($t->status === 'suspendido') {
            throw ApiException::rule('TEN-BR-08', 'El tenant ya está suspendido');
        }
        $this->db()->transaction(fn ($db) => $db->table('tenants')->where('id', $t->id)->update([
            'status' => 'suspendido', 'suspend_reason' => $reason, 'suspended_at' => now(), 'lock_version' => $t->lock_version + 1, 'updated_at' => now(),
        ]));
        $revoked = $this->tokens->forTenant($t->id);
        $this->tenantEvent($t, 'suspender', self::STATUS[$t->status], "Suspendido · {$reason} · {$revoked} sesión(es) revocada(s)", $actor,
            'tenant.suspendido', "Cuenta {$t->trade_name} suspendida", "La cuenta fue suspendida por la plataforma. Motivo: {$reason}\nSus datos se conservan; contacte a soporte para reactivarla.");

        return $this->show($t->id) + ['message' => "Tenant {$t->code} suspendido"];
    }

    public function reactivate(string $id, string $reason, ?string $ifMatch, PlatformUser $actor): array
    {
        $t = $this->find($id);
        $this->assertVersion($t, $ifMatch);
        if ($t->status !== 'suspendido') {
            throw ApiException::rule('TEN-BR-08', 'Solo se reactiva un tenant suspendido');
        }
        $price = $this->plans()[$t->plan_id]['price'];
        $this->db()->table('tenants')->where('id', $t->id)->update([
            'status' => 'activo', 'suspend_reason' => null, 'suspended_at' => null, 'mrr' => (float) $t->mrr > 0 ? $t->mrr : $price,
            'lock_version' => $t->lock_version + 1, 'updated_at' => now(),
        ]);
        $this->tenantEvent($t, 'reactivar', 'Suspendido', "Activo · {$reason}", $actor, 'tenant.reactivado', "Cuenta {$t->trade_name} reactivada",
            'La cuenta fue reactivada. Sus usuarios ya pueden iniciar sesión.');

        return $this->show($t->id) + ['message' => "Tenant {$t->code} reactivado"];
    }

    /** TEN-BR-09: bloquea si usuarios o almacenamiento superan el plan destino; mismo plan → aviso. */
    public function changePlan(string $id, string $planId, string $effective, ?string $ifMatch, PlatformUser $actor): array
    {
        $t = $this->find($id);
        $this->assertVersion($t, $ifMatch);
        $plans = $this->plans();
        $target = $plans[$planId] ?? throw ApiException::invalid('plan_id', 'Plan inexistente');
        if ($planId === $t->plan_id) {
            return $this->show($t->id) + ['warnings' => ['El tenant ya está en el plan '.$target['name'].': sin cambios.']];
        }
        $stats = $this->stats()[$t->id] ?? null;
        $users = (int) ($stats->users ?? 0);
        if ($target['max_users'] !== null && $users > $target['max_users']) {
            throw ApiException::conflict('PLAN_LIMIT_EXCEEDED', "TEN-BR-09: el plan {$target['name']} permite {$target['max_users']} usuarios y el tenant tiene {$users}. Desactive usuarios antes del downgrade.",
                ['rule' => 'TEN-BR-09']);
        }
        $gb = ((int) $t->storage_used_bytes) / 1024 ** 3;
        if ($gb > $target['max_storage_gb']) {
            throw ApiException::conflict('PLAN_LIMIT_EXCEEDED', 'TEN-BR-09: el almacenamiento usado ('.round($gb, 1)." GB) supera el límite del plan {$target['name']} ({$target['max_storage_gb']} GB).", ['rule' => 'TEN-BR-09']);
        }
        $from = $plans[$t->plan_id]['name'] ?? $t->plan_id;
        $this->db()->table('tenants')->where('id', $t->id)->update([
            'plan_id' => $planId, 'modules' => $target['modules'], 'mrr' => $t->status === 'activo' ? $target['price'] : $t->mrr,
            'lock_version' => $t->lock_version + 1, 'updated_at' => now(),
        ]);
        $label = $effective === 'next_cycle' ? 'Próximo ciclo' : 'Inmediata (prorrateo)';
        $this->context->run($t->id, function () use ($t, $from, $target, $label, $actor) {
            // TEN-BR-06: canales fuera del nuevo plan quedan apagados.
            $setting = Setting::where('section', 'channels')->first();
            if ($setting) {
                $values = $setting->values;
                foreach (AdminCatalog::CHANNELS as $key => [, $min]) {
                    if (AdminCatalog::PLANS[$min][1] > $target['rank']) {
                        $values[$key] = false;
                    }
                }
                $setting->forceFill(['values' => $values])->save();
            }
            $this->audit->log('Tenant', $t->code, 'cambio_plan', $from, "{$target['name']} · {$label}", actor: $actor);
        });

        return $this->show($t->id) + ['message' => "Plan cambiado a {$target['name']} ({$label})"];
    }

    /**
     * E2E-08 (spec 09 §2, T-01): con el contexto RLS de A se pide un registro de B; debe resolverse como 404 y quedar auditado.
     *
     * @return array{passed: bool, log: list<array{t: string, line: string, ok: bool}>}
     */
    public function isolationTest(string $tenantA, string $tenantB, PlatformUser $actor): array
    {
        $a = $this->find($tenantA);
        $b = $this->find($tenantB);
        if ($a->id === $b->id) {
            throw ApiException::invalid('tenant_b', 'Elija dos tenants distintos');
        }
        $admin = DB::connection(TenantProvisioner::CONNECTION);
        $probe = $admin->table('booking_reservations')->where('tenant_id', $b->id)->value('id') ?? $admin->table('iam_sedes')->where('tenant_id', $b->id)->value('id');
        $table = $admin->table('booking_reservations')->where('id', $probe)->exists() ? 'booking_reservations' : 'iam_sedes';
        $log = [];
        $add = function (string $line, bool $ok = true) use (&$log) {
            $log[] = ['t' => now()->format('H:i:s.v'), 'line' => $line, 'ok' => $ok];
        };
        $add("Token de prueba emitido para {$a->code} ({$a->slug}) · contexto RLS app.tenant_id={$a->id}");
        $path = $table === 'booking_reservations' ? "/api/v1/reservations/{$probe}" : "/api/v1/iam/sedes/{$probe}";
        $add("GET {$path} (registro de {$b->code})");
        $visible = $this->context->run($a->id, fn () => DB::table($table)->where('id', $probe)->exists());
        $add($visible ? 'RLS devolvió 1 fila: FUGA DE DATOS' : 'RLS devolvió 0 filas → 404 RESOURCE_NOT_FOUND', ! $visible);
        $this->context->run($a->id, fn () => $this->audit->isolated('Acceso', $path, 'acceso_denegado', $b->code, 'E2E-08 prueba de aislamiento desde consola', $actor));
        $add("Evento acceso_denegado registrado en la bitácora de {$a->code}");
        $crossCount = $this->context->run($a->id, fn () => DB::table($table)->where('tenant_id', $b->id)->count());
        $add("SELECT … WHERE tenant_id = {$b->code} con contexto {$a->code} → {$crossCount} filas", $crossCount === 0);
        $passed = ! $visible && $crossCount === 0;
        $this->audit->log('Tenant', $a->code, 'prueba_aislamiento', $b->code, $passed ? 'OK: aislamiento verificado' : 'FALLÓ', actor: $actor);

        return ['passed' => $passed, 'log' => $log];
    }

    // ───────────────────────────── Apoyo ─────────────────────────────

    private function tenantEvent(object $t, string $action, ?string $before, string $after, PlatformUser $actor, string $event, string $subject, string $body): void
    {
        $this->context->run($t->id, function () use ($t, $action, $before, $after, $actor, $event, $subject, $body) {
            $this->audit->log('Tenant', $t->code, $action, $before, $after, actor: $actor);
            if ($t->admin_email) {
                $this->notify->push($event, 'email', $t->admin_email, $subject, $body, ['related_type' => 'tenant', 'related_id' => $t->id]);
            }
        });
    }

    private function rules(int $step, array $input): array
    {
        return match ($step) {
            1 => ['trade_name' => 'required|string|max:120', 'slug' => ['required', 'string', 'regex:/^[a-z0-9-]{3,30}$/', Rule::unique('pgsql_platform.tenants', 'slug')],
                'logo_initials' => 'nullable|string|max:3', 'country' => ['required', Rule::in(AdminCatalog::COUNTRIES)],
                'custom_domain' => ['nullable', 'string', 'max:190', Rule::unique('pgsql_platform.tenants', 'custom_domain')]],
            2 => ['legal_name' => 'required|string|max:200', 'ruc' => ['required', 'regex:/^(10|15|17|20)[0-9]{9}$/', Rule::unique('pgsql_platform.tenants', 'ruc')],
                'fiscal_address' => 'required|string|max:250', 'business_type' => ['required', Rule::in(AdminCatalog::BUSINESS_TYPES)], 'legal_rep' => 'nullable|string|max:150'],
            3 => ['plan_id' => ['required', Rule::exists('pgsql_platform.plans', 'id')]],
            4 => ['sede_name' => 'required|string|max:120', 'sede_city' => 'required|string|max:80', 'sede_address' => 'required|string|max:250'],
            5 => ['admin_name' => 'required|string|max:150', 'admin_email' => 'required|email|max:190', 'admin_phone' => 'nullable|string|max:30'],
            6 => ['roles' => 'nullable|array', 'roles.*' => ['string', Rule::in(array_column($this->templateRoles(), 'name'))]],
            7 => ['permission_template' => ['required', Rule::in(array_keys(self::TEMPLATES))],
                'copy_from_tenant_id' => ['nullable', 'required_if:permission_template,copiar_tenant', 'uuid', Rule::exists('pgsql_platform.tenants', 'id')]],
            8 => ['channels' => 'nullable|array', 'channels.*' => ['string', Rule::in(array_keys(AdminCatalog::CHANNELS))]],
            9 => ['quote_validity_days' => 'required|integer|min:1|max:90', 'hold_minutes' => 'required|integer|min:5|max:1440', 'max_discount_pct' => 'required|numeric|min:0|max:30'],
            10 => ['currency' => ['required', Rule::in(self::CURRENCIES)], 'alt_currency' => ['required', Rule::in(self::ALT_CURRENCIES)], 'igv_pct' => 'required|numeric|min:0|max:30',
                'fx_rate' => 'required|numeric|gt:0', 'timezone' => ['required', Rule::in(self::TIMEZONES)], 'locale' => ['required', Rule::in(array_keys(self::LOCALES))]],
            11 => ['status' => ['required', Rule::in(['prueba', 'activo'])]],
        };
    }

    private function messages(): array
    {
        return [
            'slug.required' => 'El subdominio es obligatorio.', 'slug.regex' => 'Subdominio inválido: use 3 a 30 caracteres en minúsculas, números o guiones.',
            'slug.unique' => 'El subdominio ya está en uso por otro tenant.', 'ruc.regex' => 'RUC inválido: 11 dígitos que inician con 10, 15, 17 o 20.',
            'ruc.unique' => 'Ya existe un tenant con ese RUC', 'ruc.required' => 'El RUC es obligatorio.', 'trade_name.required' => 'El nombre comercial es obligatorio.',
            'legal_name.required' => 'La razón social es obligatoria.', 'fiscal_address.required' => 'El domicilio fiscal es obligatorio.',
            'sede_name.required' => 'El nombre de la sede es obligatorio.', 'sede_city.required' => 'La ciudad de la sede es obligatoria.', 'sede_address.required' => 'La dirección de la sede es obligatoria.',
            'admin_name.required' => 'El nombre del administrador es obligatorio.', 'admin_email.required' => 'El correo del administrador es obligatorio.', 'admin_email.email' => 'Correo del administrador inválido.',
            'max_discount_pct.max' => 'TEN-BR-05: el descuento máximo sin aprobación no puede superar 30 %.', 'fx_rate.gt' => 'El tipo de cambio debe ser mayor a 0.',
            'custom_domain.unique' => 'El dominio propio ya está en uso por otro tenant.', 'copy_from_tenant_id.required_if' => 'Elija el tenant del que se copiará la matriz.',
        ];
    }

    /** TEN-BR-01: el subdominio se normaliza a minúsculas. */
    private function normalize(array $input): array
    {
        if (isset($input['slug'])) {
            $input['slug'] = Str::lower(trim((string) $input['slug']));
        }
        if (isset($input['admin_email'])) {
            $input['admin_email'] = Str::lower(trim((string) $input['admin_email']));
        }

        return $input;
    }

    /** TEN-BR-06: canales fuera del plan elegido quedan bloqueados. */
    private function assertChannels(string $planId, array $channels): void
    {
        $rank = AdminCatalog::PLANS[$planId][1] ?? 2;
        $blocked = array_values(array_filter($channels, fn ($c) => isset(AdminCatalog::CHANNELS[$c]) && AdminCatalog::PLANS[AdminCatalog::CHANNELS[$c][1]][1] > $rank));
        if ($blocked) {
            throw ApiException::unprocessable('TEN-BR-06', 'Canales no incluidos en el plan '.AdminCatalog::planName($planId).': '
                .implode(', ', array_map(fn ($c) => AdminCatalog::CHANNELS[$c][0], $blocked)), ['errors' => ['channels' => ['Canal no incluido en el plan.']]]);
        }
    }

    /** @return list<array{name: string, label: string, scope: string, required: bool}> */
    private function templateRoles(): array
    {
        return DB::connection(TenantProvisioner::CONNECTION)->table('roles')->whereNull('tenant_id')->where('name', '<>', 'super_admin')->orderBy('id')->get(['name', 'display_name', 'scope'])
            ->map(fn ($r) => ['name' => $r->name, 'label' => $r->display_name, 'scope' => $r->scope, 'required' => $r->name === 'tenant_admin'])->all();
    }

    private function row(object $t, ?object $s, array $plans): array
    {
        return [
            'id' => $t->id, 'code' => $t->code, 'slug' => $t->slug, 'domain' => $t->slug.'.'.config('turismo.tenant_base_domain'), 'trade_name' => $t->trade_name ?? $t->legal_name,
            'legal_name' => $t->legal_name, 'ruc' => $t->ruc, 'country' => $t->country, 'plan_id' => $t->plan_id, 'plan' => $plans[$t->plan_id]['name'] ?? $t->plan_id,
            'status' => $t->status, 'status_label' => self::STATUS[$t->status] ?? $t->status, 'users' => (int) ($s->users ?? 0), 'max_users' => $plans[$t->plan_id]['max_users'] ?? null,
            'mrr' => (float) $t->mrr, 'modules' => (int) $t->modules, 'storage_gb' => round(((int) $t->storage_used_bytes) / 1024 ** 3, 2),
            'admin_email' => $t->admin_email, 'logo_initials' => $t->logo_initials, 'lock_version' => (int) $t->lock_version,
            'trial_ends_at' => $t->trial_ends_at ? (string) $t->trial_ends_at : null, 'last_activity' => $s->last_activity ?? null,
        ];
    }

    /** @return array<string, object> */
    private function stats(): array
    {
        return collect($this->db()->select('SELECT * FROM platform_tenant_stats()'))->keyBy('tenant_id')->all();
    }

    private function find(string $id): object
    {
        $q = $this->db()->table('tenants');

        return (Str::isUuid($id) ? $q->where('id', $id) : $q->where('code', $id))->first() ?? throw ApiException::notFound();
    }

    private function assertVersion(object $t, ?string $ifMatch): void
    {
        if ($ifMatch === null || $ifMatch === '') {
            throw new ApiException(428, 'PRECONDITION_REQUIRED', 'SEC-BR-17', 'Este recurso exige el header If-Match con su lock_version');
        }
        if ((int) trim(preg_replace('/^W\//', '', $ifMatch), '"') !== (int) $t->lock_version) {
            throw ApiException::conflict('STALE_VERSION', 'El registro fue modificado por otro usuario; recargue e intente de nuevo');
        }
    }

    private function db(): ConnectionInterface
    {
        return DB::connection('pgsql_platform');
    }

    public function model(string $id): Tenant
    {
        return Tenant::findOrFail($this->find($id)->id);
    }
}
