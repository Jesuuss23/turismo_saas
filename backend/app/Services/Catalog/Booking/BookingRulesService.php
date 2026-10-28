<?php

namespace App\Services\Catalog\Booking;

use App\Domain\Bookings\RuleCatalog;
use App\Domain\Bookings\RuleRegistry;
use App\Domain\Bookings\RuleResolver;
use App\Events\Catalog\BookingRuleChanged;
use App\Exceptions\ApiException;
use App\Models\Catalog\RuleOverride;
use App\Models\Iam\User;
use App\Models\Sys\BookingRule;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Centro de reglas del tenant (`#/admin/reglas`, spec 16 §2 y §6.2): activar/desactivar, parámetros, canales y reglas
 * propias del tenant (`is_system=false`, evaluadas solo si tienen handler en `RuleRegistry`, §12 #1).
 */
class BookingRulesService
{
    public function __construct(private readonly RuleResolver $rules, private readonly AuditLogger $audit) {}

    public function list(): array
    {
        $counts = RuleOverride::query()->selectRaw('rule_key, count(*) AS n')->groupBy('rule_key')->pluck('n', 'rule_key');

        return BookingRule::orderBy('sort_order')->orderBy('rule_key')->get()->map(fn (BookingRule $r) => $this->present($r, (int) ($counts[$r->rule_key] ?? 0)))->all();
    }

    public function present(BookingRule $r, int $overrides = 0): array
    {
        return [
            'key' => $r->rule_key, 'group' => $r->group_name, 'name' => $r->name, 'description' => $r->description, 'enabled' => $r->enabled,
            'params' => (object) ($r->params ?? []), 'scope' => $r->scope ?? [], 'product_override' => $r->product_override, 'is_system' => $r->is_system,
            'schema' => $r->params_schema, 'evaluated' => $r->is_system || RuleRegistry::has($r->rule_key), 'overrides' => $overrides, 'lock_version' => $r->lock_version,
        ];
    }

    public function update(string $key, array $data, User $actor): array
    {
        $rule = BookingRule::where('rule_key', $key)->first() ?? throw ApiException::notFound();
        $before = self::label($rule);
        if (array_key_exists('enabled', $data)) {
            $rule->enabled = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('params', $data)) {
            $params = BookingConfigService::validParams(['key' => $key, 'schema' => $rule->params_schema], (array) $data['params']);
            $rule->params = (object) array_replace((array) $rule->params, $params);
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
            DB::transaction(function () use ($rule, $before, $actor) {
                $rule->save();
                $this->audit->log('Regla de reserva', $rule->rule_key, 'modificar', $before, self::label($rule), actor: $actor);
            });
            BookingRuleChanged::dispatch($key);
        }

        return $this->present($rule->fresh());
    }

    /** «Se pueden añadir otras reglas»: fila `is_system=false` (sin handler el motor no la evalúa). */
    public function create(array $data, User $actor): array
    {
        $key = strtoupper(trim((string) ($data['rule_key'] ?? '')));
        if (! str_starts_with($key, 'R-')) {
            $key = 'R-'.$key;
        }
        if (! preg_match('/^R-[A-Z0-9-]{2,37}$/', $key)) {
            throw ApiException::invalid('rule_key', 'La clave debe tener el formato R-ALGO (mayúsculas, números y guiones).');
        }
        if (BookingRule::where('rule_key', $key)->exists() || isset(RuleCatalog::RULES[$key])) {
            throw ApiException::invalid('rule_key', "La regla {$key} ya existe.");
        }
        $group = (string) ($data['group_name'] ?? '');
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
                'enabled' => filter_var($data['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN), 'params' => (object) $params, 'scope' => $this->scope($data['scope'] ?? ['Sitio web']),
                'product_override' => filter_var($data['product_override'] ?? true, FILTER_VALIDATE_BOOLEAN), 'is_system' => false,
                'params_schema' => RuleCatalog::schema($params), 'sort_order' => (int) BookingRule::max('sort_order') + 1,
            ]);
            $this->audit->log('Regla de reserva', $key, 'crear', null, self::label($rule), actor: $actor);

            return $rule;
        });
        BookingRuleChanged::dispatch($key);

        return $this->present($rule);
    }

    public static function label(BookingRule $r): string
    {
        return ($r->enabled ? 'Activa' : 'Inactiva').' · '.json_encode((object) ($r->params ?? [])).' · '.implode(', ', (array) $r->scope);
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
