<?php

namespace App\Services\Experience;

use App\Domain\Bookings\RuleResolver;
use App\Exceptions\ApiException;
use App\Models\Admin\Setting;
use App\Models\Experience\Currency;
use App\Models\Experience\FxRateProvider;
use App\Models\Finance\FxRate;
use App\Models\Iam\User;
use App\Models\Sys\BookingRule;
use App\Services\AuditLogger;
use App\Services\Catalog\Booking\BookingRulesService;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Monedas y tipos de cambio (spec 17 `admin/monedas`, §3.6, FX-01..04). Todo valor es de DEMOSTRACIÓN (decisión del cliente
 * 30/09/2026): el proveedor «Simulador» genera ±0,6 % sobre el último valor; los reales (BCRP, SBS, OXR) quedan por validar.
 * `fin_currencies.rate_pen` es el TC vigente (soles por unidad) y `fin_fx_rates` el histórico de un valor por día y moneda.
 */
class CurrencyService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CrmClock $clock,
        private readonly TenantContext $tenant,
        private readonly RuleResolver $rules,
        private readonly BookingRulesService $settings,
    ) {}

    public function list(): array
    {
        $settings = $this->settings->ecommerce();
        $margin = $this->margin();

        return [
            'items' => Currency::orderByDesc('is_base')->orderByDesc('active')->orderBy('code')->get()->map(fn (Currency $c) => $this->present($c, $margin))->all(),
            'settings' => [
                'fx_margin_pct' => $margin, 'fx_alert_pct' => (float) ($settings['fx_alert_pct'] ?? 3), 'rate_provider_id' => $settings['rate_provider_id'] ?? null,
                'rate_source' => $settings['rate_source'] ?? null, 'rate_updated_at' => $settings['rate_updated_at'] ?? null, 'is_demo_rates' => (bool) ($settings['is_demo_rates'] ?? true),
            ],
            'providers' => $this->providers(),
            'multicurrency_on' => $this->rules->on('R-MULTICURRENCY'),
            'alerts' => FxRate::where('alert_raised', true)->where('rate_date', '>=', $this->clock->today()->subDays(7)->toDateString())->orderByDesc('rate_date')->limit(10)->get()
                ->map(fn (FxRate $r) => ['currency' => $r->currency, 'date' => $r->rate_date->toDateString(), 'rate' => (string) $r->sell, 'change_pct' => (float) $r->daily_change_pct])->all(),
        ];
    }

    public function present(Currency $c, ?float $margin = null): array
    {
        $margin ??= $this->margin();
        $rate = BigDecimal::of((string) $c->rate_pen);

        return [
            'code' => trim($c->code), 'name' => $c->name, 'symbol' => $c->symbol, 'rate_pen' => (string) $rate->toScale(6), 'active' => $c->active, 'is_base' => $c->is_base,
            'is_charge' => $c->is_charge, 'lock_version' => $c->lock_version,
            // FX-03: TC al cliente = TC × (1 − margen); equivalencia de S/ 100.
            'client_rate' => $c->is_base ? '1.000000' : (string) $rate->multipliedBy(self::factor($margin))->toScale(6, RoundingMode::HalfUp),
            'per_100_pen' => $c->is_base ? '100.00' : (string) BigDecimal::of(100)->dividedBy($rate->multipliedBy(self::factor($margin)), 2, RoundingMode::HalfUp),
        ];
    }

    /**
     * PATCH moneda. `rate_pen` (FX-01: > 0; variación mayor que `fx_alert_pct` pide `confirm`); `active` / `is_charge` (FX-02) solo
     * con `admin.settings.manage`. Cada cambio de TC registra el valor del día; USD sincroniza `settings.fx` y la configuración de moneda.
     */
    public function update(string $code, array $data, User $actor, ?string $ifMatch = null): array
    {
        $c = Currency::where('code', strtoupper($code))->first() ?? throw ApiException::notFound();
        if ($ifMatch !== null && $ifMatch !== '' && (int) trim(preg_replace('/^W\//', '', $ifMatch), '"') !== (int) $c->lock_version) {
            throw ApiException::conflict('STALE_VERSION', 'La moneda fue modificada por otro usuario; recargue e intente de nuevo');
        }
        $admin = $actor->hasPermissionTo('admin.settings.manage');
        foreach (['active', 'is_charge'] as $flag) {
            if (array_key_exists($flag, $data) && ! $admin) {
                throw ApiException::forbidden('SEC-BR-03', 'Activar monedas y monedas de cobro requiere admin.settings.manage', ['admin.settings.manage']);
            }
        }
        if (array_key_exists('rate_pen', $data) && ! $admin && ! $actor->hasPermissionTo('finance.treasury')) {
            throw ApiException::forbidden('SEC-BR-03', 'Requiere finance.treasury', ['finance.treasury']);
        }
        $warnings = [];
        DB::transaction(function () use ($c, $data, $actor, &$warnings) {
            if (array_key_exists('active', $data)) {
                $active = filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
                if (! $active && $c->is_base) {
                    throw ApiException::unprocessable('FX-02', 'FX-02: la moneda base no se desactiva.');
                }
                if (! $active && $c->is_charge) {
                    throw ApiException::unprocessable('FX-02', 'FX-02: una moneda de cobro debe estar activa; quítela primero de las monedas de cobro.');
                }
                if ($active !== $c->active) {
                    $c->active = $active;
                    $c->save();
                    $this->audit->log('Moneda', trim($c->code), $active ? 'activar' : 'desactivar', null, $active ? 'Activa' : 'Inactiva', actor: $actor);
                }
            }
            if (array_key_exists('is_charge', $data)) {
                $charge = filter_var($data['is_charge'], FILTER_VALIDATE_BOOLEAN);
                if (! $charge && $c->is_base) {
                    throw ApiException::unprocessable('FX-02', 'FX-02: la moneda base siempre es de cobro.');
                }
                if ($charge && ! $c->active) {
                    throw ApiException::unprocessable('FX-02', 'FX-02: active la moneda antes de usarla para cobrar.');
                }
                if ($charge !== $c->is_charge) {
                    $c->is_charge = $charge;
                    $c->save();
                    $this->audit->log('Moneda', trim($c->code), $charge ? 'agregar_cobro' : 'quitar_cobro', null, $charge ? 'De cobro' : 'Solo visualización', actor: $actor);
                }
            }
            if (array_key_exists('rate_pen', $data)) {
                $warnings = $this->setRate($c, $data['rate_pen'], $actor, 'Manual', null, filter_var($data['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN));
            }
        });

        return ['currency' => $this->present($c->fresh()), 'warnings' => $warnings];
    }

    /** FX-01: valida, compara contra `fx_alert_pct`, registra el valor del día y sincroniza USD. */
    private function setRate(Currency $c, mixed $value, ?User $actor, string $source, ?FxRateProvider $provider, bool $confirm, bool $demo = false): array
    {
        if ($c->is_base) {
            throw ApiException::unprocessable('FX-01', 'FX-01: la moneda base tiene TC 1.');
        }
        if (! is_numeric($value) || (float) $value <= 0 || (float) $value > 100000) {
            throw ApiException::unprocessable('FX-01', 'FX-01: el tipo de cambio debe ser mayor que 0.');
        }
        $rate = BigDecimal::of((string) $value)->toScale(6, RoundingMode::HalfUp);
        $previous = BigDecimal::of((string) $c->rate_pen);
        if ($rate->isEqualTo($previous)) {
            return [];
        }
        $change = $previous->isZero() ? 0.0 : $rate->minus($previous)->dividedBy($previous, 8, RoundingMode::HalfUp)->multipliedBy(100)->toFloat();
        $alertPct = (float) ($this->settings->ecommerce()['fx_alert_pct'] ?? 3);
        $alert = abs($change) > $alertPct;
        if ($alert && ! $confirm && $actor !== null) {
            throw ApiException::unprocessable('FX-01', sprintf('FX-01: la variación de %s es %+.2f %% (umbral %.1f %%). Confirme para guardar.', trim($c->code), $change, $alertPct),
                ['requires_confirmation' => true, 'change_pct' => round($change, 3)]);
        }
        $before = (string) $previous;
        $c->rate_pen = (string) $rate;
        $c->save();
        $today = $this->clock->today()->toDateString();
        $row = FxRate::where('currency', trim($c->code))->whereDate('rate_date', $today)->first();
        $attrs = ['buy' => (string) $rate, 'sell' => (string) $rate, 'source' => mb_substr($source, 0, 80), 'previous_rate' => $before, 'provider_id' => $provider?->id,
            'daily_change_pct' => round($change, 3), 'alert_raised' => $alert, 'is_demo' => $demo, 'effective_at' => now(), 'updated_by' => $actor?->id];
        $row ? $row->forceFill($attrs)->save() : FxRate::create($attrs + ['rate_date' => $today, 'currency' => trim($c->code), 'created_by' => $actor?->id]);
        if (trim($c->code) === 'USD') {
            $this->syncUsd((string) $rate);
        }
        $this->audit->log('Tipo de cambio', trim($c->code), $provider ? 'actualizar_desde_proveedor' : 'actualizar', $before, "{$rate} · {$source}", actor: $actor, system: $actor === null);
        $warnings = [];
        if ($alert) {
            $warnings[] = sprintf('Variación diaria de %s: %+.2f %% (supera el umbral de %.1f %%).', trim($c->code), $change, $alertPct);
            foreach (CatalogRecipients::role(['finance', 'accounting']) as $user) {
                app(Notify::class)->push('fx.variacion_alta', 'portal', $user, sprintf('Variación alta del tipo de cambio %s', trim($c->code)),
                    sprintf('%s pasó de %s a %s (%+.2f %%). Fuente: %s.', trim($c->code), $before, $rate, $change, $source), ['related_type' => 'fin_currency', 'related_id' => $c->id]);
            }
        }

        return $warnings;
    }

    /** USD sincroniza `tenants.settings.fx.USD` y `sys_settings.currency.fx_rate` (lo que leen cotizaciones y ventas). */
    private function syncUsd(string $rate): void
    {
        $tenant = $this->tenant->tenant();
        if ($tenant) {
            $settings = (array) $tenant->settings;
            data_set($settings, 'fx.USD', (float) $rate);
            $tenant->forceFill(['settings' => $settings])->save();
        }
        $currency = Setting::where('section', 'currency')->first();
        if ($currency) {
            $currency->values = array_replace((array) $currency->values, ['fx_rate' => $rate]);
            $currency->save();
        }
        $this->tenant->set($this->tenant->id());
    }

    /** Historial diario (v2.2): último valor de cada día de los últimos N días. */
    public function history(string $code, int $days = 30): array
    {
        $code = strtoupper($code);
        Currency::where('code', $code)->exists() || throw ApiException::notFound();
        $days = max(1, min(365, $days));
        $from = $this->clock->today()->subDays($days - 1)->toDateString();

        return FxRate::where('currency', $code)->where('rate_date', '>=', $from)->orderBy('rate_date')->get()
            ->map(fn (FxRate $r) => ['date' => $r->rate_date->toDateString(), 'rate' => (string) $r->sell, 'previous' => $r->previous_rate !== null ? (string) $r->previous_rate : null,
                'change_pct' => $r->daily_change_pct !== null ? (float) $r->daily_change_pct : null, 'alert' => (bool) $r->alert_raised, 'source' => $r->source, 'is_demo' => (bool) $r->is_demo])->all();
    }

    public function providers(): array
    {
        $active = $this->settings->ecommerce()['rate_provider_id'] ?? null;

        return FxRateProvider::orderByRaw("CASE status WHEN 'activo' THEN 0 WHEN 'por_validar' THEN 1 ELSE 2 END")->orderBy('code')->get()
            ->map(fn (FxRateProvider $p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'kind' => $p->kind, 'status' => $p->status, 'schedule_cron' => $p->schedule_cron,
                'last_run_at' => $p->last_run_at?->toIso8601String(), 'last_error' => $p->last_error, 'note' => $p->note, 'is_current' => $p->id === $active])->all();
    }

    /** FX-04: proveedor activo (solo uno, con status `activo`), umbral de alerta y margen (⇄ R-MULTICURRENCY.fxMarginPct). */
    public function saveSettings(array $data, User $actor): array
    {
        $current = $this->settings->ecommerce();
        $patch = [];
        if (array_key_exists('rate_provider_id', $data)) {
            $provider = FxRateProvider::whereKey((string) $data['rate_provider_id'])->first() ?? throw ApiException::invalid('rate_provider_id', 'Proveedor no encontrado.');
            if ($provider->status !== 'activo') {
                throw ApiException::unprocessable('FX-04', 'FX-04: solo un proveedor en estado «activo» puede ser el proveedor del tenant.');
            }
            if ($provider->id !== ($current['rate_provider_id'] ?? null)) {
                $before = FxRateProvider::whereKey($current['rate_provider_id'] ?? null)->value('name');
                $patch += ['rate_provider_id' => $provider->id, 'rate_source' => $provider->name];
                $this->audit->log('Tipo de cambio', 'proveedor', 'cambiar_proveedor', $before, $provider->name, actor: $actor);
            }
        }
        if (array_key_exists('fx_alert_pct', $data)) {
            $v = $data['fx_alert_pct'];
            if (! is_numeric($v) || $v < 0.1 || $v > 20) {
                throw ApiException::invalid('fx_alert_pct', 'El umbral de alerta va de 0,1 % a 20 %.');
            }
            $patch['fx_alert_pct'] = round((float) $v, 2);
        }
        if (array_key_exists('fx_margin_pct', $data)) {
            $v = $data['fx_margin_pct'];
            if (! is_numeric($v) || $v < 0 || $v > 15) {
                throw ApiException::invalid('fx_margin_pct', 'El margen de conversión va de 0 % a 15 %.');
            }
            $patch['fx_margin_pct'] = round((float) $v, 2);
            $rule = BookingRule::where('rule_key', 'R-MULTICURRENCY')->first();
            if ($rule) {
                $rule->params = (object) array_replace((array) $rule->params, ['fxMarginPct' => $patch['fx_margin_pct']]);
                $rule->save();
                $this->rules->bump();
            }
        }
        if (isset($patch['fx_alert_pct']) || isset($patch['fx_margin_pct'])) {
            $this->audit->log('Moneda', 'ajustes', 'editar', sprintf('margen %s %% · alerta %s %%', $current['fx_margin_pct'] ?? $this->margin(), $current['fx_alert_pct'] ?? 3),
                sprintf('margen %s %% · alerta %s %%', $patch['fx_margin_pct'] ?? ($current['fx_margin_pct'] ?? $this->margin()), $patch['fx_alert_pct'] ?? ($current['fx_alert_pct'] ?? 3)), actor: $actor);
        }
        $this->settings->saveEcommerce($patch);

        return $this->list();
    }

    /** «Actualizar ahora desde proveedor (demo)» y job `fx:update-daily` (RefreshFxRates). */
    public function refresh(?User $actor = null): array
    {
        $settings = $this->settings->ecommerce();
        $provider = FxRateProvider::whereKey($settings['rate_provider_id'] ?? null)->first() ?? FxRateProvider::where('code', 'SIM')->first();
        if (! $provider || $provider->status !== 'activo') {
            throw ApiException::unprocessable('FX-04', 'FX-04: no hay un proveedor de tipo de cambio activo.');
        }
        if ($provider->code === 'MANUAL') {
            throw ApiException::unprocessable('FX-04', 'El proveedor activo es «Ingreso manual»: edite el tipo de cambio en la tabla.');
        }
        $updated = [];
        $warnings = [];
        DB::transaction(function () use ($provider, $actor, &$updated, &$warnings) {
            foreach (Currency::where('active', true)->where('is_base', false)->orderBy('code')->get() as $c) {
                $new = $this->simulated($c);
                $warnings = array_merge($warnings, $this->setRate($c, $new, null, $provider->name.' (demo)', $provider, true, true));
                $updated[] = ['code' => trim($c->code), 'rate_pen' => $new];
            }
            $provider->forceFill(['last_run_at' => now(), 'last_error' => null])->save();
            $this->settings->saveEcommerce(['rate_updated_at' => now()->toIso8601String(), 'rate_source' => $provider->name]);
            $this->audit->log('Tipo de cambio', $provider->code, 'actualizar_desde_proveedor', null, count($updated).' moneda(s) · '.$provider->name, actor: $actor, system: $actor === null);
        });

        return ['provider' => $provider->name, 'updated' => $updated, 'warnings' => $warnings, 'is_demo' => true,
            'message' => count($updated).' tipo(s) de cambio actualizados desde '.$provider->name.' (demo).'];
    }

    /** Variación simulada ±0,6 % (determinística por día y moneda para que el job y el botón coincidan). */
    private function simulated(Currency $c): string
    {
        $seed = crc32(trim($c->code).$this->clock->today()->toDateString().$this->tenant->id());
        $pct = (($seed % 1201) - 600) / 100000;

        return (string) BigDecimal::of((string) $c->rate_pen)->multipliedBy(BigDecimal::of(number_format(1 + $pct, 8, '.', '')))->toScale(6, RoundingMode::HalfUp);
    }

    /** «Probar conexión (demo)»: los oficiales quedan por validar, OXR necesita la clave en el vault. */
    public function testProvider(string $code, User $actor): array
    {
        $p = FxRateProvider::where('code', strtoupper($code))->first() ?? throw ApiException::notFound();
        [$ok, $message] = match ($p->kind) {
            'Simulado', 'Manual' => [true, 'Proveedor interno: no requiere conexión.'],
            'API' => [false, 'Sin API key en el vault: registre la credencial antes de activarlo.'],
            default => [true, 'Conexión de demostración correcta. El acceso real a la serie oficial sigue por validar con negocio.'],
        };
        $p->forceFill(['last_error' => $ok ? null : $message, 'last_run_at' => now()])->save();
        $this->audit->log('Tipo de cambio', $p->code, 'probar_conexion', null, ($ok ? 'OK · ' : 'Error · ').$message, actor: $actor);

        return ['ok' => $ok, 'message' => $message, 'status' => $p->status];
    }

    /** Conversor `BE.convert` con y sin margen. */
    public function convert(mixed $amount, string $from, string $to): array
    {
        if (! is_numeric($amount) || $amount < 0) {
            throw ApiException::invalid('amount', 'Monto inválido.');
        }
        $rates = Currency::whereIn('code', [strtoupper($from), strtoupper($to)])->get()->keyBy(fn ($c) => trim($c->code));
        $fromC = $rates[strtoupper($from)] ?? throw ApiException::invalid('from', 'Moneda de origen inválida.');
        $toC = $rates[strtoupper($to)] ?? throw ApiException::invalid('to', 'Moneda de destino inválida.');
        $margin = $this->margin();
        $pen = BigDecimal::of((string) $amount)->multipliedBy((string) $fromC->rate_pen);
        $plain = $pen->dividedBy((string) $toC->rate_pen, 2, RoundingMode::HalfUp);
        $client = $toC->is_base ? $plain : $pen->dividedBy(BigDecimal::of((string) $toC->rate_pen)->multipliedBy(self::factor($margin)), 2, RoundingMode::HalfUp);

        return ['amount' => (string) $amount, 'from' => trim($fromC->code), 'to' => trim($toC->code), 'result' => (string) $plain, 'result_with_margin' => (string) $client, 'margin_pct' => $margin];
    }

    /** 1 − margen/100 como decimal exacto (BigDecimal::of no acepta floats con fiabilidad). */
    private static function factor(float $margin): BigDecimal
    {
        return BigDecimal::one()->minus(BigDecimal::of(number_format($margin, 4, '.', ''))->dividedBy(100, 6, RoundingMode::HalfUp));
    }

    /** Margen de conversión vigente: parámetro de R-MULTICURRENCY (fuente única) o el de ecommerce. */
    public function margin(): float
    {
        return (float) $this->rules->param('R-MULTICURRENCY', 'fxMarginPct', null, $this->settings->ecommerce()['fx_margin_pct'] ?? 0);
    }

    /** Monedas activas para el selector del widget: code => [símbolo, TC al cliente]. */
    public function activeRates(): array
    {
        $margin = $this->margin();

        return Currency::where('active', true)->orderByDesc('is_base')->orderBy('code')->get()
            ->mapWithKeys(fn (Currency $c) => [trim($c->code) => ['symbol' => $c->symbol, 'name' => $c->name, 'rate_pen' => (string) $c->rate_pen,
                'client_rate' => $this->present($c, $margin)['client_rate'], 'is_charge' => $c->is_charge]])->all();
    }
}
