<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Admin\IntegrationAdapter;
use App\Models\Admin\Setting;
use App\Models\Finance\FxRate;
use App\Models\Iam\Sede;
use App\Models\Iam\User;
use App\Models\Sys\Sequence;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Configuración del tenant (PROC-003, spec 09 §3.8). Guardado por diferencia con auditoría por campo (CFG-BR-01).
 * Los valores que ya consumen otros módulos se reflejan en `tenants` (TC, IGV, zona horaria, hold, validez): así los
 * documentos nuevos toman la configuración nueva y los existentes conservan su snapshot (CFG-BR-11).
 */
class TenantSettings
{
    public const LABELS = ['general' => 'general', 'currency' => 'moneda', 'policies' => 'politicas', 'operations' => 'operativos', 'channels' => 'canales'];

    /** Campos de `general` que viven en `tenants`. */
    private const TENANT_FIELDS = ['legal_name' => 'legal_name', 'commercial_name' => 'trade_name', 'logo_initials' => 'logo_initials', 'timezone' => 'timezone'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly CrmClock $clock,
    ) {}

    public function tenant(): Tenant
    {
        return Tenant::with('plan')->findOrFail($this->context->id());
    }

    /** @return array<string, mixed> */
    public function values(string $section): array
    {
        $row = Setting::find($section);

        return array_merge(AdminCatalog::SETTINGS[$section] ?? [], $row?->values ?? []);
    }

    public function get(string $section, string $key, mixed $default = null): mixed
    {
        return $this->values($section)[$key] ?? $default;
    }

    /** GET /settings: todas las secciones con su versión y lo que la UI necesita para bloquear campos. */
    public function all(): array
    {
        $tenant = $this->tenant();
        $rows = Setting::all()->keyBy('section');
        $sections = [];
        foreach (array_keys(self::LABELS) as $section) {
            $sections[$section] = ['values' => $this->present($section, $tenant), 'lock_version' => (int) ($rows[$section]->lock_version ?? 0)];
        }
        $rank = (int) $tenant->plan?->rank;
        $adapters = IntegrationAdapter::pluck('status', 'code');

        return [
            'sections' => $sections,
            'tenant' => ['code' => $tenant->code, 'ruc' => $tenant->ruc, 'country' => $tenant->country, 'plan' => $tenant->plan?->name, 'plan_id' => $tenant->plan_id],
            'base_currency_locked' => $this->hasTransactions(),
            'channels' => collect(AdminCatalog::CHANNELS)->map(fn ($c, $key) => [
                'key' => $key, 'label' => $c[0], 'description' => $c[2], 'min_plan' => AdminCatalog::planName($c[1]),
                'editable' => $rank >= AdminCatalog::PLANS[$c[1]][1], 'integration' => $c[3], 'integration_status' => $c[3] ? ($adapters[$c[3]] ?? null) : null,
            ])->values()->all(),
            'sedes' => Sede::where('status', 'activa')->orderBy('code')->get(['id', 'code', 'name'])->all(),
            'options' => [
                'fx_sources' => AdminCatalog::FX_SOURCES, 'roundings' => ['0.01', '0.10', '1'], 'round_modes' => ['nearest' => 'Al más cercano', 'up' => 'Hacia arriba', 'down' => 'Hacia abajo'],
                'timezones' => ['America/Lima', 'America/La_Paz', 'America/Santiago', 'America/Guayaquil', 'America/Bogota'], 'langs' => ['es-PE' => 'Español (Perú)', 'en-US' => 'English (US)'],
                'date_formats' => ['DD/MM/AAAA', 'AAAA-MM-DD'], 'currencies' => ['PEN', 'USD', 'BOB', 'CLP'], 'alt_currencies' => ['USD', 'EUR', 'PEN'],
            ],
        ];
    }

    private function present(string $section, Tenant $tenant): array
    {
        $values = $this->values($section);

        return match ($section) {
            'general' => ['legal_name' => $tenant->legal_name, 'commercial_name' => $tenant->trade_name, 'logo_initials' => $tenant->logo_initials,
                'timezone' => $tenant->timezone, 'ruc' => $tenant->ruc, 'country' => $tenant->country] + $values,
            'currency' => ['base_currency' => $tenant->currency] + $values,
            default => $values,
        };
    }

    /**
     * PATCH /settings/{section}: valida, guarda solo lo modificado y audita un evento por campo.
     *
     * @return array{changed: list<string>, warnings: list<string>, lock_version: int, message: string}
     */
    public function update(string $section, array $input, ?string $ifMatch, User $actor): array
    {
        if (! isset(self::LABELS[$section])) {
            throw ApiException::notFound();
        }
        $data = $this->validate($section, $input);
        $tenant = $this->tenant();
        $current = $this->present($section, $tenant);
        $changed = [];
        foreach ($data as $key => $value) {
            if ($this->normalize($current[$key] ?? null) !== $this->normalize($value)) {
                $changed[$key] = [$current[$key] ?? null, $value];
            }
        }
        $row = Setting::find($section) ?? Setting::create(['section' => $section, 'values' => AdminCatalog::SETTINGS[$section], 'updated_at' => now()]);
        if ($ifMatch !== null && (int) trim($ifMatch, '"W/') !== (int) $row->lock_version) {
            throw ApiException::conflict('STALE_VERSION', 'La configuración fue modificada por otro usuario; recargue e intente de nuevo');
        }
        if ($changed === []) {
            return ['changed' => [], 'warnings' => [], 'lock_version' => (int) $row->lock_version, 'message' => 'No hay cambios que guardar'];
        }

        $warnings = [];
        if ($section === 'currency' && isset($changed['base_currency']) && $this->hasTransactions()) {
            throw ApiException::rule('CFG-BR-05', 'La moneda base no puede cambiarse: ya existen reservas o pagos registrados.');
        }
        if ($section === 'channels') {
            $warnings = $this->checkChannels($tenant, $changed);
        }

        DB::transaction(function () use ($section, $changed, $row, $tenant, $actor) {
            $values = $row->values;
            $tenantCols = [];
            $legacy = $tenant->settings ?? [];
            foreach ($changed as $key => [, $after]) {
                if ($section === 'general' && isset(self::TENANT_FIELDS[$key])) {
                    $tenantCols[self::TENANT_FIELDS[$key]] = $after;
                    if ($key === 'timezone') {
                        $legacy['timezone'] = $after;
                    }

                    continue;
                }
                if ($section === 'currency' && $key === 'base_currency') {
                    $tenantCols['currency'] = $after;

                    continue;
                }
                $values[$key] = $after;
                match ("{$section}.{$key}") {
                    'general.legal_rep' => $tenantCols['legal_rep'] = $after,
                    'general.address' => $tenantCols['fiscal_address'] = $after,
                    'general.lang' => $tenantCols['locale'] = $after,
                    'currency.alt_currency' => $tenantCols['alt_currency'] = $after,
                    'currency.tax_pct' => $tenantCols['igv_pct'] = $after,
                    'currency.fx_rate' => data_set($legacy, 'fx.USD', (float) $after),
                    'policies.quote_validity_days' => data_set($legacy, 'sales.quote_validity_days', (int) $after),
                    'policies.hold_minutes' => data_set($legacy, 'catalog.hold_minutes', (int) $after),
                    default => null,
                };
            }
            if ($section === 'currency' && isset($changed['fx_rate'])) {
                $this->recordFx((string) $changed['fx_rate'][1], (string) ($values['fx_source'] ?? 'manual'), $actor);
            }
            $row->values = $values;
            $row->updated_by = $actor->id;
            $row->updated_at = now();
            $row->save();
            $tenant->forceFill($tenantCols + ['settings' => $legacy])->save();
            foreach ($changed as $key => [$before, $after]) {
                $this->audit->log('Configuración', self::LABELS[$section].'.'.$key, 'actualizar', $this->show($before), $this->show($after), actor: $actor);
            }
        });
        $this->context->set($this->context->id());

        return ['changed' => array_keys($changed), 'warnings' => $warnings, 'lock_version' => (int) $row->fresh()->lock_version,
            'message' => count($changed).' cambio(s) guardado(s). Aplican a documentos nuevos; los existentes conservan su snapshot.'];
    }

    private function validate(string $section, array $input): array
    {
        $rules = match ($section) {
            'general' => [
                'legal_name' => 'sometimes|required|string|max:200', 'commercial_name' => 'sometimes|required|string|max:120',
                'logo_initials' => ['sometimes', 'required', 'string', 'max:3', 'regex:/^[A-ZÁÉÍÓÚÑ]{1,3}$/u'], 'timezone' => 'sometimes|required|timezone',
                'email' => 'sometimes|nullable|email|max:190', 'phone' => 'sometimes|nullable|string|max:30', 'web' => 'sometimes|nullable|string|max:190',
                'address' => 'sometimes|nullable|string|max:250', 'legal_rep' => 'sometimes|nullable|string|max:150', 'mincetur_registry' => 'sometimes|nullable|string|max:60',
                'lang' => 'sometimes|required|in:es-PE,en-US', 'date_format' => 'sometimes|required|in:DD/MM/AAAA,AAAA-MM-DD',
                'main_sede_id' => 'sometimes|required|uuid', 'ruc' => 'prohibited', 'country' => 'prohibited',
            ],
            'currency' => [
                'base_currency' => 'sometimes|required|in:PEN,USD,BOB,CLP', 'alt_currency' => 'sometimes|required|in:USD,EUR,PEN', 'price_with_tax' => 'sometimes|boolean',
                'fx_rate' => 'sometimes|required|numeric|gt:0.5|lt:20', 'fx_source' => 'sometimes|required|in:'.implode(',', AdminCatalog::FX_SOURCES),
                'tax_pct' => 'sometimes|required|numeric|min:0|max:30', 'rounding' => 'sometimes|required|in:0.01,0.10,1', 'round_mode' => 'sometimes|required|in:nearest,up,down',
            ],
            'policies' => [
                'quote_validity_days' => 'sometimes|required|integer|between:1,60', 'hold_minutes' => 'sometimes|required|integer|between:5,1440',
                'markup_pct' => 'sometimes|required|numeric|min:0|max:300', 'max_discount_pct' => 'sometimes|required|numeric|min:0|max:30',
                'min_margin_pct' => 'sometimes|required|numeric|min:0|max:100', 'advance_pct' => 'sometimes|required|numeric|min:0|max:100',
                'balance_days_before' => 'sometimes|required|integer|min:0', 'cancel_text' => 'sometimes|required|string|max:2000',
                'modify_text' => 'sometimes|nullable|string|max:2000', 'noshow_text' => 'sometimes|nullable|string|max:2000', 'refund_text' => 'sometimes|nullable|string|max:2000',
            ],
            'operations' => collect(AdminCatalog::SETTINGS['operations'])->map(fn ($v, $k) => $k === 'max_pax_per_guide'
                ? 'sometimes|required|integer|between:1,40' : 'sometimes|required|integer|min:0')->all(),
            'channels' => collect(AdminCatalog::CHANNELS)->map(fn () => 'sometimes|boolean')->all(),
        };
        $messages = [
            'fx_rate.gt' => 'El tipo de cambio está fuera de rango razonable (0.5 < TC < 20).', 'fx_rate.lt' => 'El tipo de cambio está fuera de rango razonable (0.5 < TC < 20).',
            'hold_minutes.between' => 'El hold mínimo es 5 minutos (máximo 1440).', 'max_discount_pct.max' => 'El descuento máximo sin aprobación no puede superar 30 %.',
            'ruc.prohibited' => 'El RUC no se edita desde el tenant: requiere validación de plataforma.', 'country.prohibited' => 'El país no se edita desde el tenant: requiere validación de plataforma.',
            'logo_initials.regex' => 'Las iniciales del logo van en mayúsculas (máximo 3).', 'cancel_text.required' => 'La política de cancelación es obligatoria.',
            'max_pax_per_guide.between' => 'El máximo de pasajeros por guía va de 1 a 40.',
        ];
        $input = array_intersect_key($input, $rules);
        if (isset($input['logo_initials']) && is_string($input['logo_initials'])) {
            $input['logo_initials'] = trim($input['logo_initials']);
        }
        $data = Validator::make($input, $rules, $messages)->validate();
        if (isset($data['main_sede_id']) && ! Sede::where('status', 'activa')->whereKey($data['main_sede_id'])->exists()) {
            throw ApiException::invalid('main_sede_id', 'Elija una sede activa.');
        }

        return $data;
    }

    /** CFG-BR-12 / TEN-BR-06: solo los canales del plan; e-commerce con pasarela por validar avisa. */
    private function checkChannels(Tenant $tenant, array $changed): array
    {
        $rank = (int) $tenant->plan?->rank;
        $warnings = [];
        foreach ($changed as $key => [, $after]) {
            $min = AdminCatalog::CHANNELS[$key][1];
            if ($rank < AdminCatalog::PLANS[$min][1]) {
                throw ApiException::rule('TEN-BR-06', AdminCatalog::CHANNELS[$key][0].' requiere el plan '.AdminCatalog::planName($min).' o superior.');
            }
            $integration = AdminCatalog::CHANNELS[$key][3];
            if ($after && $integration && in_array(IntegrationAdapter::where('code', $integration)->value('status'), ['por_validar', 'sandbox', 'futuro'], true)) {
                $warnings[] = $key === 'ecommerce'
                    ? 'La pasarela de pagos (INT-02) está por validar: los pagos web quedarán como pendientes.'
                    : AdminCatalog::CHANNELS[$key][0]." depende de {$integration}, que aún no está en producción.";
            }
        }

        return $warnings;
    }

    /** CFG-BR-06: historial del TC (fin_fx_rates). Un registro por día; el último cambio del día lo actualiza. */
    private function recordFx(string $rate, string $source, User $actor): void
    {
        $today = $this->clock->today()->toDateString();
        $row = FxRate::where('currency', 'USD')->whereDate('rate_date', $today)->first();
        $attrs = ['buy' => $rate, 'sell' => $rate, 'source' => str_contains($source, 'SBS') ? 'SBS' : 'manual', 'updated_by' => $actor->id];
        $row ? $row->update($attrs) : FxRate::create($attrs + ['rate_date' => $today, 'currency' => 'USD', 'created_by' => $actor->id]);
    }

    public function fxHistory(): array
    {
        return FxRate::orderByDesc('rate_date')->limit(30)->get()->map(fn (FxRate $r) => [
            'date' => $r->rate_date->toDateString(), 'currency' => $r->currency, 'buy' => (string) $r->buy, 'sell' => (string) $r->sell, 'source' => $r->source,
            'by' => $r->updated_by ? User::find($r->updated_by)?->name : ($r->created_by ? User::find($r->created_by)?->name : 'Sistema'),
        ])->all();
    }

    public function hasTransactions(): bool
    {
        return DB::table('booking_reservations')->exists() || DB::table('fin_payments')->exists();
    }

    /** Series y correlativos con el próximo documento (§6.4). */
    public function sequences(): array
    {
        $rows = Sequence::orderBy('doc_type')->get()->sortBy(fn (Sequence $s) => array_search($s->doc_type, AdminCatalog::VISIBLE_SERIES, true) === false
            ? 99 : array_search($s->doc_type, AdminCatalog::VISIBLE_SERIES, true))->values();
        $existing = $rows->pluck('doc_type')->all();

        return [
            'data' => $rows->map(fn (Sequence $s) => [
                'doc_type' => $s->doc_type, 'label' => $s->label, 'series' => $s->series, 'prefix' => $s->prefix, 'pad_width' => $s->pad_width,
                'next_number' => (int) $s->next_number, 'next_document' => $s->prefix.str_pad((string) $s->next_number, $s->pad_width, '0', STR_PAD_LEFT),
                'year_reset' => (bool) $s->year_reset, 'main' => in_array($s->doc_type, AdminCatalog::VISIBLE_SERIES, true),
            ])->all(),
            'can_add' => collect(AdminCatalog::NEW_SERIES)->reject(fn ($v, $k) => in_array($k, $existing, true))->map(fn ($v, $k) => ['doc_type' => $k, 'label' => $v[0], 'prefix' => $v[1]])->values()->all(),
        ];
    }

    /** CFG-BR-07: prefijo no vacío y único; el número solo avanza. */
    public function updateSequences(array $items, User $actor): array
    {
        $changed = [];
        DB::transaction(function () use ($items, $actor, &$changed) {
            foreach ($items as $i => $item) {
                $seq = Sequence::where('doc_type', $item['doc_type'] ?? '')->where('series', $item['series'] ?? 'default')->lockForUpdate()->first()
                    ?? Sequence::where('doc_type', $item['doc_type'] ?? '')->lockForUpdate()->first()
                    ?? throw ApiException::invalid("items.{$i}.doc_type", 'Serie inexistente.');
                $prefix = trim((string) ($item['prefix'] ?? $seq->prefix));
                $next = (int) ($item['next_number'] ?? $seq->next_number);
                if ($prefix === '') {
                    throw ApiException::invalid("items.{$i}.prefix", "El prefijo de {$seq->label} no puede quedar vacío.");
                }
                if ($prefix !== $seq->prefix && Sequence::where('prefix', $prefix)->whereKeyNot($seq->getKey())->exists()) {
                    throw ApiException::invalid("items.{$i}.prefix", "El prefijo {$prefix} ya lo usa otra serie.");
                }
                if ($next < (int) $seq->next_number) {
                    throw ApiException::unprocessable('CFG-BR-07', "El correlativo de {$seq->label} no puede retroceder (actual {$seq->next_number}); los números no se reutilizan.");
                }
                if ($prefix === $seq->prefix && $next === (int) $seq->next_number) {
                    continue;
                }
                $before = $seq->prefix.$seq->next_number;
                $seq->prefix = $prefix;
                $seq->next_number = $next;
                $seq->save();
                $changed[] = $seq->label;
                $this->audit->log('Configuración', 'series.'.$seq->label, 'actualizar', $before, $prefix.$next, actor: $actor);
            }
        });

        return ['changed' => $changed, 'message' => $changed === [] ? 'No hay cambios que guardar' : count($changed).' serie(s) actualizada(s).'];
    }

    /** CFG-BR-08: nuevas series solo para tipos aún no existentes. */
    public function addSequence(array $input, User $actor): array
    {
        $data = Validator::make($input, [
            'doc_type' => 'required|in:'.implode(',', array_keys(AdminCatalog::NEW_SERIES)),
            'prefix' => 'required|string|max:30', 'next_number' => 'nullable|integer|min:1',
        ], ['doc_type.in' => 'Solo se agregan series de Nota de crédito, Nota de débito, Liquidación de comisión, Recibo de caja u Orden de compra.'])->validate();
        [$label, , $pad] = AdminCatalog::NEW_SERIES[$data['doc_type']];
        if (Sequence::where('doc_type', $data['doc_type'])->exists()) {
            throw ApiException::unprocessable('CFG-BR-08', "Ya existe una serie de {$label}.");
        }
        if (Sequence::where('prefix', trim($data['prefix']))->exists()) {
            throw ApiException::invalid('prefix', 'Ese prefijo ya lo usa otra serie.');
        }
        $seq = Sequence::create(['doc_type' => $data['doc_type'], 'label' => $label, 'series' => 'default', 'prefix' => trim($data['prefix']),
            'pad_width' => $pad, 'next_number' => $data['next_number'] ?? 1, 'year_reset' => false, 'updated_at' => now()]);
        $this->audit->log('Configuración', 'series.'.$label, 'crear', null, $seq->prefix.$seq->next_number, actor: $actor);

        return ['doc_type' => $seq->doc_type, 'label' => $label];
    }

    private function normalize(mixed $v): string
    {
        return is_bool($v) ? ($v ? '1' : '0') : (is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.') : (string) $v);
    }

    private function show(mixed $v): ?string
    {
        return $v === null ? null : (is_bool($v) ? ($v ? 'sí' : 'no') : Str::limit((string) $v, 200));
    }
}
