<?php

namespace App\Services\Catalog\Booking;

use App\Domain\Bookings\BookingEngine;
use App\Domain\Bookings\QuoteSelection;
use App\Domain\Bookings\RuleCatalog;
use App\Domain\Bookings\RuleRegistry;
use App\Domain\Bookings\RuleResolver;
use App\Events\Catalog\BookingRuleChanged;
use App\Exceptions\ApiException;
use App\Models\Admin\Setting;
use App\Models\Catalog\Product;
use App\Models\Catalog\RuleOverride;
use App\Models\Iam\User;
use App\Models\Sys\AuditEvent;
use App\Models\Sys\BookingRule;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Centro de reglas del tenant v2.2 (`/admin/reglas`, spec 16 §2 y spec 17 §2, §5): interruptor con plan mínimo (PLN-R-01),
 * parámetros con validación cruzada (CR-01), excepciones por producto, plantillas por tipo de negocio (CR-03), reglas
 * personalizadas como banderas (CR-04), restablecer con motivo, simulador con «Plan a simular» e historial.
 */
class BookingRulesService
{
    /** Reglas cuyo cambio recalcula la programación de mensajes al pasajero (spec 17 §4). */
    public const MESSAGING_RULES = ['R-PAX-MESSAGES', 'R-REVIEW-INVITE', 'R-COMPANIONS', 'R-DEPOSIT', 'R-PAX-FEEDBACK'];

    public function __construct(private readonly RuleResolver $rules, private readonly AuditLogger $audit, private readonly TenantContext $tenant) {}

    /** Reglas con conteo de excepciones; con `$plan` el campo `effective` se calcula como si el tenant tuviera ese plan (solo visual). */
    public function list(?string $plan = null): array
    {
        $counts = RuleOverride::query()->selectRaw('rule_key, count(*) AS n')->groupBy('rule_key')->pluck('n', 'rule_key');
        $rows = BookingRule::orderBy('sort_order')->orderBy('rule_key')->get();

        return $this->rules->asPlan($this->validPlan($plan), fn () => $rows->map(fn (BookingRule $r) => $this->present($r, (int) ($counts[$r->rule_key] ?? 0)))->all());
    }

    /** Plan actual, plan de la vista previa, planes disponibles y plantilla aplicada. */
    public function meta(?string $plan = null): array
    {
        $plans = DB::table('plans')->orderBy('rank')->get(['id', 'name', 'rank']);
        $current = $this->tenant->tenant()?->plan_id;

        return [
            'plan' => $current, 'plan_name' => $plans->firstWhere('id', $current)?->name, 'preview_plan' => $this->validPlan($plan),
            'plans' => $plans->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->all(), 'groups' => RuleCatalog::GROUPS, 'scopes' => RuleCatalog::SCOPES,
            'template_applied' => $this->ecommerce()['rule_template_applied'] ?? null,
        ];
    }

    public function present(BookingRule $r, int $overrides = 0): array
    {
        $min = $this->rules->minPlan($r->rule_key);
        $allows = $this->rules->planAllows($r->rule_key);
        $parent = $r->depends_on;
        $dependsOk = ! $parent || $this->rules->on($parent);

        return [
            'key' => $r->rule_key, 'group' => $r->group_name, 'name' => $r->name, 'description' => $r->description, 'enabled' => $r->enabled,
            'params' => (object) ($r->params ?? []), 'scope' => $r->scope ?? [], 'product_override' => $r->product_override, 'is_system' => $r->is_system,
            'is_custom' => ! $r->is_system, 'schema' => $r->params_schema, 'evaluated' => $r->is_system || RuleRegistry::has($r->rule_key), 'overrides' => $overrides,
            'lock_version' => $r->lock_version, 'min_plan' => $min, 'plan_allows' => $allows, 'effective' => $r->enabled && $allows && $dependsOk,
            'state' => ! $r->enabled ? 'off_tenant' : (! $allows ? 'off_plan' : ($dependsOk ? 'on' : 'off_dependency')),
            'depends_on' => $parent, 'depends_ok' => $dependsOk, 'reads' => RuleCatalog::READS[$r->rule_key] ?? null,
        ];
    }

    public function update(string $key, array $data, User $actor, ?string $ifMatch = null): array
    {
        $rule = BookingRule::where('rule_key', $key)->first() ?? throw ApiException::notFound();
        if ($ifMatch !== null && $ifMatch !== '' && (int) trim(preg_replace('/^W\//', '', $ifMatch), '"') !== (int) $rule->lock_version) {
            throw ApiException::conflict('STALE_VERSION', 'La regla fue modificada por otro usuario; recargue e intente de nuevo');
        }
        $wasEnabled = $rule->enabled;
        $beforeParams = (array) ($rule->params ?? []);
        $before = self::label($rule);
        if (array_key_exists('enabled', $data)) {
            $rule->enabled = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN);
            // PLN-R-01: no se puede encender una regla fuera del plan (apagarla sí, la configuración se conserva).
            if ($rule->enabled && ! $wasEnabled && ! $this->rules->planAllows($key)) {
                $min = DB::table('plans')->where('id', $this->rules->minPlan($key))->value('name');
                throw ApiException::forbidden('RULE_NOT_IN_PLAN', "La regla {$key} está disponible desde el plan {$min}.");
            }
        }
        if (array_key_exists('params', $data)) {
            $params = BookingConfigService::validParams(['key' => $key, 'schema' => $rule->params_schema], (array) $data['params']);
            $merged = array_replace((array) $rule->params, $params);
            self::crossCheck($key, $merged, $this->rules);
            $rule->params = (object) $merged;
        }
        if (array_key_exists('scope', $data)) {
            $rule->scope = $this->scope($data['scope']);
        }
        if (! $rule->is_system) {
            foreach (['name' => 120, 'description' => 2000] as $field => $max) {
                if (array_key_exists($field, $data)) {
                    $rule->{$field} = $this->text($data[$field], $field, $max);
                }
            }
            if (array_key_exists('product_override', $data)) {
                $rule->product_override = filter_var($data['product_override'], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if ($rule->isDirty()) {
            DB::transaction(function () use ($rule, $before, $actor, $wasEnabled, $beforeParams) {
                $paramsChanged = $rule->isDirty('params');
                $rule->save();
                if ($rule->enabled !== $wasEnabled) {
                    $this->audit->log('Regla de reserva', $rule->rule_key, $rule->enabled ? 'activar' : 'desactivar', $before, self::label($rule), actor: $actor);
                }
                if ($paramsChanged) {
                    $this->audit->log('Regla de reserva', $rule->rule_key, 'editar_parametros', json_encode((object) $beforeParams), json_encode((object) $rule->params), actor: $actor,
                        diff: ['before' => (object) $beforeParams, 'after' => (object) $rule->params]);
                }
                if ($rule->enabled === $wasEnabled && ! $paramsChanged) {
                    $this->audit->log('Regla de reserva', $rule->rule_key, 'modificar', $before, self::label($rule), actor: $actor);
                }
                $this->syncDefaults($rule);
            });
            BookingRuleChanged::dispatch($key);
        }

        return $this->present($rule->fresh(), RuleOverride::where('rule_key', $key)->count());
    }

    /**
     * CR-01: validaciones cruzadas entre parámetros (niño mín. ≤ máx.; infante máx. < niño mín.; niño máx. < 18) y adelanto 1–99 %.
     */
    public static function crossCheck(string $key, array $params, RuleResolver $rules): void
    {
        $fail = fn (string $msg) => throw ApiException::unprocessable('CR-01', "CR-01: {$msg}");
        if ($key === 'R-CHILD-PER-ADULT' || $key === 'R-INFANT-FREE') {
            $child = $key === 'R-CHILD-PER-ADULT' ? $params : $rules->params('R-CHILD-PER-ADULT');
            $infant = $key === 'R-INFANT-FREE' ? $params : $rules->params('R-INFANT-FREE');
            $cMin = $child['childMinAge'] ?? null;
            $cMax = $child['childMaxAge'] ?? null;
            if ($cMin !== null && $cMax !== null && $cMin > $cMax) {
                $fail("la edad mínima del niño ({$cMin}) no puede ser mayor que la máxima ({$cMax}).");
            }
            if ($cMax !== null && $cMax >= 18) {
                $fail('la edad máxima del niño debe ser menor que la edad adulta (18).');
            }
            if (isset($infant['infantMaxAge'], $cMin) && $infant['infantMaxAge'] >= $cMin) {
                $fail("la edad máxima del infante ({$infant['infantMaxAge']}) debe ser menor que la mínima del niño ({$cMin}).");
            }
        }
        if ($key === 'R-DEPOSIT' && isset($params['pct']) && ($params['pct'] < 1 || $params['pct'] > 99)) {
            $fail('el adelanto debe estar entre 1 % y 99 %.');
        }
    }

    /** CR-04: regla personalizada (`is_system=false`), creada siempre **inactiva**; es una bandera consultable hasta que desarrollo implemente su efecto. */
    public function create(array $data, User $actor): array
    {
        $key = strtoupper(trim((string) ($data['rule_key'] ?? $data['code'] ?? '')));
        if (! str_starts_with($key, 'R-')) {
            $key = 'R-'.$key;
        }
        if (! preg_match('/^R-[A-Z0-9-]{2,38}$/', $key)) {
            throw ApiException::invalid('rule_key', 'La clave debe tener el formato R-ALGO (mayúsculas, números y guiones).');
        }
        if (BookingRule::where('rule_key', $key)->exists() || isset(RuleCatalog::RULES[$key])) {
            throw ApiException::invalid('rule_key', "La regla {$key} ya existe.");
        }
        $group = (string) ($data['group_name'] ?? $data['group'] ?? '');
        if (! in_array($group, RuleCatalog::GROUPS, true)) {
            throw ApiException::invalid('group_name', 'Grupo inválido.');
        }
        $params = [];
        $raw = $data['params'] ?? [];
        if (is_string($raw)) {
            // Formulario del back-office: «minPax=2, dias=7».
            parse_str(str_replace([',', ';', ' '], ['&', '&', ''], $raw), $raw);
        }
        foreach ((array) $raw as $name => $value) {
            if (! preg_match('/^[a-zA-Z][a-zA-Z0-9]{0,30}$/', (string) $name) || ! is_numeric($value)) {
                throw ApiException::invalid('params', 'Los parámetros son numéricos con nombre alfanumérico.');
            }
            $params[$name] = $value + 0;
        }
        $rule = DB::transaction(function () use ($key, $group, $data, $params, $actor) {
            $rule = BookingRule::create([
                'rule_key' => $key, 'group_name' => $group, 'name' => $this->text($data['name'] ?? '', 'name', 120), 'description' => $this->text($data['description'] ?? '', 'description', 2000),
                'enabled' => false, 'params' => (object) $params, 'scope' => $this->scope($data['scope'] ?? ['Sitio web']),
                'product_override' => filter_var($data['product_override'] ?? true, FILTER_VALIDATE_BOOLEAN), 'is_system' => false,
                'params_schema' => RuleCatalog::schema($params), 'sort_order' => (int) BookingRule::max('sort_order') + 1,
            ]);
            $this->audit->log('Regla de reserva', $key, 'crear', null, self::label($rule), actor: $actor);

            return $rule;
        });
        BookingRuleChanged::dispatch($key);

        return $this->present($rule);
    }

    /** Solo las personalizadas se eliminan (con sus excepciones por producto). */
    public function delete(string $key, User $actor): void
    {
        $rule = BookingRule::where('rule_key', $key)->first() ?? throw ApiException::notFound();
        if ($rule->is_system) {
            throw ApiException::unprocessable('CR-04', 'CR-04: solo se eliminan reglas personalizadas; las estándar se desactivan.');
        }
        DB::transaction(function () use ($rule, $actor) {
            RuleOverride::where('rule_key', $rule->rule_key)->delete();
            $this->audit->log('Regla de reserva', $rule->rule_key, 'eliminar', self::label($rule), null, actor: $actor);
            $rule->delete();
        });
        BookingRuleChanged::dispatch($key);
    }

    /** CR-03: 4 plantillas con diferencia +/− contra la configuración actual (solo `enabled` de reglas estándar). */
    public function templates(): array
    {
        $current = BookingRule::where('is_system', true)->pluck('enabled', 'rule_key');
        $names = BookingRule::pluck('name', 'rule_key');

        return DB::table('sys_rule_templates')->orderBy('code')->get()->map(function ($t) use ($current, $names) {
            $codes = json_decode($t->enabled_codes, true);
            $on = $off = [];
            foreach ($current as $key => $enabled) {
                $want = in_array($key, $codes, true);
                if ($want && ! $enabled) {
                    $on[] = ['key' => $key, 'name' => $names[$key] ?? $key, 'plan_allows' => $this->rules->planAllows($key)];
                } elseif (! $want && $enabled) {
                    $off[] = ['key' => $key, 'name' => $names[$key] ?? $key];
                }
            }

            return ['code' => $t->code, 'name' => $t->name, 'description' => $t->description, 'enabled_codes' => $codes, 'enable' => $on, 'disable' => $off];
        })->all();
    }

    /** Aplica una plantilla: enciende/apaga reglas estándar; las fuera del plan quedan encendidas pero sin efecto. */
    public function applyTemplate(string $code, User $actor): array
    {
        $template = collect($this->templates())->firstWhere('code', $code) ?? throw ApiException::invalid('template', 'Plantilla inválida.');
        DB::transaction(function () use ($template, $actor) {
            foreach (BookingRule::where('is_system', true)->get() as $rule) {
                $want = in_array($rule->rule_key, $template['enabled_codes'], true);
                if ($rule->enabled !== $want) {
                    $before = self::label($rule);
                    $rule->enabled = $want;
                    $rule->save();
                    $this->audit->log('Regla de reserva', $rule->rule_key, $want ? 'activar' : 'desactivar', $before, self::label($rule)." · plantilla {$template['code']}", actor: $actor);
                }
            }
            $this->audit->log('Regla de reserva', 'plantilla', 'aplicar_plantilla', null, "{$template['name']}: +".count($template['enable']).' / −'.count($template['disable']), actor: $actor);
            $this->saveEcommerce(['rule_template_applied' => ['code' => $template['code'], 'name' => $template['name'], 'at' => now()->toIso8601String(), 'by' => $actor->name]]);
        });
        BookingRuleChanged::dispatch('*');

        return ['template' => $template['code'], 'enabled' => count($template['enable']), 'disabled' => count($template['disable']),
            'message' => "Plantilla «{$template['name']}» aplicada: ".count($template['enable']).' activada(s), '.count($template['disable']).' desactivada(s).'];
    }

    /** Restablece reglas estándar desde el catálogo de plataforma (enabled, params, scope); no toca personalizadas ni `min_plan`. */
    public function reset(string $reason, User $actor): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ApiException::invalid('reason', 'Indique el motivo (mínimo 5 caracteres).');
        }
        $templates = DB::table('platform_booking_rule_templates')->get()->keyBy('rule_key');
        $changed = 0;
        DB::transaction(function () use ($templates, $actor, $reason, &$changed) {
            foreach (BookingRule::where('is_system', true)->get() as $rule) {
                $t = $templates[$rule->rule_key] ?? null;
                if (! $t) {
                    continue;
                }
                $rule->fill(['enabled' => (bool) $t->enabled, 'params' => (object) json_decode($t->params, true), 'scope' => json_decode($t->scope, true), 'params_schema' => json_decode($t->params_schema, true)]);
                if ($rule->isDirty()) {
                    $changed++;
                    $rule->save();
                    $this->syncDefaults($rule);
                }
            }
            $this->audit->log('Regla de reserva', 'todas', 'restablecer', null, "{$changed} regla(s) restablecida(s) · motivo: {$reason}", actor: $actor);
            $this->saveEcommerce(['rule_template_applied' => null]);
        });
        BookingRuleChanged::dispatch('*');

        return ['changed' => $changed, 'message' => "Valores por defecto restablecidos ({$changed} regla(s))."];
    }

    /** Productos que sobrescriben la regla (drawer de excepciones). */
    public function overrides(string $key): array
    {
        BookingRule::where('rule_key', $key)->exists() || throw ApiException::notFound();
        $rows = RuleOverride::where('rule_key', $key)->get()->keyBy('product_id');
        $products = Product::whereIn('id', $rows->keys())->orderBy('ref')->get(['id', 'ref', 'name']);

        return [
            'items' => $products->map(fn (Product $p) => ['product_id' => $p->id, 'ref' => $p->ref, 'name' => $p->name,
                'enabled' => $rows[$p->id]->enabled, 'params' => (object) ($rows[$p->id]->params ?? [])])->all(),
            'candidates' => Product::where('is_bookable', true)->whereNotIn('id', $rows->keys())->orderBy('ref')->limit(200)->get(['id', 'ref', 'name'])
                ->map(fn (Product $p) => ['product_id' => $p->id, 'ref' => $p->ref, 'name' => $p->name])->all(),
        ];
    }

    /**
     * Simulador: producto + selección → estado del día, calendario del mes, `quote` con líneas quitables y estado de cada regla,
     * opcionalmente con «Plan a simular» (no cambia la suscripción).
     */
    public function simulate(array $data): array
    {
        $ref = (string) ($data['product'] ?? $data['product_ref'] ?? '');
        $product = Product::where(fn ($q) => $q->where('ref', $ref)->orWhere('id', preg_match('/^[0-9a-f-]{36}$/', $ref) ? $ref : null))->first()
            ?? throw ApiException::invalid('product', 'Elija un producto.');
        $plan = $this->validPlan($data['plan'] ?? null);
        $engine = app(BookingEngine::class);

        $rules = $engine->rules;

        return $rules->asPlan($plan, function () use ($engine, $rules, $product, $data, $plan) {
            $sel = QuoteSelection::fromArray((array) ($data['selection'] ?? $data));
            $date = $sel->date ?? $engine->nextAvailable($product, max(1, $sel->seats())) ?? $engine->today();
            $sel = QuoteSelection::fromArray(['date' => $date] + $sel->toArray());
            $month = CarbonImmutable::parse($date);
            $quote = $engine->quote($product, $sel);

            return [
                'product' => ['id' => $product->id, 'ref' => $product->ref, 'name' => $product->name], 'plan' => $plan ?? $rules->plan(),
                'date' => $date, 'day' => $engine->dayStatus($product, $date, $sel->seats())->toPublic(),
                'month' => $engine->month($product, $month->year, $month->month)->map->toPublic()->values()->all(),
                'legend' => $engine->legend($product, $month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()),
                'pax' => $engine->validatePax($product, $sel->adt, $sel->chd, $sel->inf, $sel->ages), 'quote' => $quote->toArray(),
                'rules' => collect($rules->rules())->map(fn ($r, $k) => ['key' => $k, 'name' => $r['name'], 'group' => $r['group'],
                    'state' => $rules->state($k, $product), 'effective' => $rules->on($k, $product), 'plan_allows' => $rules->planAllows($k),
                    'min_plan' => $rules->minPlan($k)])->values()->all(),
            ];
        });
    }

    /** Historial de cambios del centro (auditoría filtrable por regla y acción). */
    public function history(array $filters): array
    {
        $q = AuditEvent::query()->where(fn ($w) => $w->where('entity', 'Regla de reserva')->orWhere(fn ($p) => $p->where('entity', 'Producto')->where('action', 'like', 'regla%')))
            ->orderByDesc('occurred_at');
        if (! empty($filters['rule'])) {
            $q->where(fn ($w) => $w->where('entity_id', $filters['rule'])->orWhere('before_value', 'like', $filters['rule'].':%')->orWhere('after_value', 'like', $filters['rule'].':%'));
        }
        if (! empty($filters['action'])) {
            $q->where('action', $filters['action']);
        }

        return $q->limit(200)->get()->map(fn (AuditEvent $e) => ['id' => $e->id, 'at' => $e->occurred_at?->toIso8601String(), 'entity' => $e->entity, 'entity_id' => $e->entity_id,
            'action' => $e->action, 'actor' => $e->actor_role, 'actor_id' => $e->actor_id, 'before' => $e->before_value, 'after' => $e->after_value])->all();
    }

    public static function label(BookingRule $r): string
    {
        return ($r->enabled ? 'Activa' : 'Inactiva').' · '.json_encode((object) ($r->params ?? [])).' · '.implode(', ', (array) $r->scope);
    }

    /** `sys_settings.ecommerce` (spec 17 §3.6). */
    public function ecommerce(): array
    {
        return (array) (Setting::where('section', 'ecommerce')->value('values') ?? []);
    }

    public function saveEcommerce(array $patch): array
    {
        $row = Setting::where('section', 'ecommerce')->first();
        $values = array_replace((array) ($row?->values ?? []), $patch);
        if ($row) {
            $row->values = $values;
            $row->save();
        } else {
            Setting::create(['section' => 'ecommerce', 'values' => $values]);
        }

        return $values;
    }

    private function validPlan(mixed $plan): ?string
    {
        if ($plan === null || $plan === '') {
            return null;
        }
        $plan = strtolower((string) $plan);
        DB::table('plans')->where('id', $plan)->exists() || throw ApiException::invalid('plan', 'Plan inválido.');

        return $plan;
    }

    /** Parámetros que el motor también lee desde otras fuentes: R-HOLD ⇄ políticas.hold_minutes y R-MULTICURRENCY ⇄ ecommerce.fx_margin_pct. */
    private function syncDefaults(BookingRule $rule): void
    {
        $params = (array) $rule->params;
        if ($rule->rule_key === 'R-HOLD' && isset($params['minutes'])) {
            $policies = Setting::where('section', 'policies')->first();
            if ($policies) {
                $policies->values = array_replace((array) $policies->values, ['hold_minutes' => (int) $params['minutes']]);
                $policies->save();
            }
        }
        if ($rule->rule_key === 'R-MULTICURRENCY' && isset($params['fxMarginPct'])) {
            $this->saveEcommerce(['fx_margin_pct' => $params['fxMarginPct'] + 0]);
        }
    }

    private function scope(mixed $scope): array
    {
        $scope = array_values(array_intersect(RuleCatalog::SCOPES, (array) $scope));
        if ($scope === []) {
            throw ApiException::invalid('scope', 'Elija al menos un canal.');
        }

        return $scope;
    }

    private function text(mixed $value, string $field, int $max): string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw ApiException::invalid($field, "Campo obligatorio (hasta {$max} caracteres).");
        }

        return $value;
    }
}
