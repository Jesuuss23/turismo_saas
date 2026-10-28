<?php

namespace App\Services\Experience;

use App\Domain\Bookings\BookingEngine;
use App\Domain\Bookings\QuoteSelection;
use App\Domain\Bookings\QuoteToken;
use App\Exceptions\ApiException;
use App\Models\Catalog\PickupPoint;
use App\Models\Catalog\Product;
use App\Models\Experience\WidgetConfig;
use App\Models\Experience\WidgetDomain;
use App\Models\Experience\WidgetHandoff;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Catalog\Booking\BookingConfigService;
use App\Services\Integrations\WebhookService;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Integración web (spec 17 `canales/integracion-web`, §3.8, WG-01..03): configurador y predeterminados del widget, clave pública
 * con rotación (24 h de gracia), dominios autorizados verificados por DNS TXT, traspasos al checkout (WHO-, TTL 24 h) y la vista
 * pública del producto que consume `/widget/[code]`. El precio del widget es referencial: el checkout recalcula en servidor (WG-03).
 */
class WidgetService
{
    public const METHODS = ['webcomponent' => 'Web Component', 'iframe' => 'iframe', 'wordpress' => 'Plugin WordPress', 'headless' => 'API headless'];

    public const CMS = ['WordPress', 'Wix', 'Squarespace', 'Shopify', 'Webflow', 'HTML propio', 'Otro'];

    public const WEBHOOK_EVENTS = ['reservation.created', 'reservation.confirmed', 'reservation.cancelled', 'payment.applied', 'widget.checkout_started', 'review.received', 'passenger.message.failed'];

    public const BROWSER_EVENTS = [
        'qhapaq:ready' => 'El widget cargó (incluye alto inicial)', 'qhapaq:resize' => 'Cambió el alto: ajuste el iframe', 'qhapaq:date_selected' => 'El cliente eligió una fecha',
        'qhapaq:quote' => 'Nueva cotización referencial (total y moneda)', 'qhapaq:checkout' => 'Traspaso al checkout con su referencia WHO-',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SequenceService $sequences,
        private readonly TenantContext $tenant,
    ) {}

    public function config(): WidgetConfig
    {
        return WidgetConfig::first() ?? throw ApiException::notFound();
    }

    /** Configurador: config, dominios, eventos, webhooks suscritos y últimos traspasos. */
    public function show(): array
    {
        $cfg = $this->config();
        $webhooks = DB::table('integration_webhooks')->whereNull('deleted_at')->get(['id', 'url', 'events', 'status'])
            ->map(fn ($w) => ['id' => $w->id, 'url' => $w->url, 'status' => $w->status, 'events' => array_values(array_intersect(self::WEBHOOK_EVENTS, (array) json_decode($w->events, true)))])
            ->filter(fn ($w) => $w['events'] !== [])->values()->all();

        return [
            'public_key' => $cfg->public_key, 'public_key_prev' => $cfg->prev_expires_at?->isFuture() ? $cfg->public_key_prev : null,
            'prev_expires_at' => $cfg->prev_expires_at?->isFuture() ? $cfg->prev_expires_at->toIso8601String() : null, 'tenant_slug' => $cfg->tenant_slug,
            'defaults' => (object) ($cfg->defaults ?? []), 'lock_version' => $cfg->lock_version, 'methods' => self::METHODS, 'cms' => self::CMS,
            'domains' => $this->domains(), 'browser_events' => self::BROWSER_EVENTS, 'webhook_events' => self::WEBHOOK_EVENTS, 'webhooks' => $webhooks,
            'handoffs' => WidgetHandoff::with('product:id,ref,name')->orderByDesc('created_at')->limit(10)->get()->map(fn (WidgetHandoff $h) => $this->presentHandoff($h))->all(),
            'products' => $this->bookable()->sortBy('ref')->values()->all(),
            'currencies' => app(CurrencyService::class)->activeRates(),
            'cdn' => rtrim((string) config('turismo.widget_cdn', 'https://cdn.qhapaq.app'), '/'), 'host' => rtrim((string) config('turismo.frontend_url'), '/'),
        ];
    }

    public function saveDefaults(array $data, User $actor, ?string $ifMatch): array
    {
        $cfg = $this->config();
        if ($ifMatch !== null && $ifMatch !== '' && (int) trim(preg_replace('/^W\//', '', $ifMatch), '"') !== (int) $cfg->lock_version) {
            throw ApiException::conflict('STALE_VERSION', 'La configuración fue modificada por otro usuario; recargue e intente de nuevo');
        }
        $d = (array) ($data['defaults'] ?? $data);
        $method = (string) ($d['method'] ?? 'webcomponent');
        $lang = strtolower((string) ($d['lang'] ?? 'es'));
        $currency = strtoupper((string) ($d['currency'] ?? 'USD'));
        $color = (string) ($d['color'] ?? '#0369a1');
        $radius = (int) ($d['radius'] ?? 8);
        if (! isset(self::METHODS[$method])) {
            throw ApiException::invalid('method', 'Método de integración inválido.');
        }
        if (! in_array($lang, ['es', 'en'], true)) {
            throw ApiException::invalid('lang', 'Idioma inválido (es o en).');
        }
        if (! array_key_exists($currency, app(CurrencyService::class)->activeRates())) {
            throw ApiException::invalid('currency', 'La moneda debe estar activa.');
        }
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw ApiException::invalid('color', 'Color en formato #RRGGBB.');
        }
        if ($radius < 0 || $radius > 24) {
            throw ApiException::invalid('radius', 'El radio va de 0 a 24 px.');
        }
        $product = ! empty($d['product']) ? (Product::where('ref', $d['product'])->value('ref') ?? throw ApiException::invalid('product', 'Producto no encontrado.')) : null;
        $row = ['method' => $method, 'product' => $product, 'category' => ! empty($d['category']) ? mb_substr((string) $d['category'], 0, 60) : null, 'lang' => $lang,
            'currency' => $currency, 'color' => strtolower($color), 'radius' => $radius, 'reviews' => filter_var($d['reviews'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'domain' => ! empty($d['domain']) ? strtolower((string) $d['domain']) : null];
        $before = json_encode($cfg->defaults);
        $cfg->defaults = $row;
        $cfg->save();
        $this->audit->log('Widget web', 'config', 'editar', $before, json_encode($row), actor: $actor);

        return $this->show();
    }

    /** WG-02: regenera la clave; la anterior sigue válida 24 h. */
    public function rotateKey(string $reason, User $actor): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ApiException::invalid('reason', 'Indique el motivo (mínimo 5 caracteres).');
        }
        $cfg = $this->config();
        $old = $cfg->public_key;
        $cfg->forceFill(['public_key_prev' => $old, 'prev_expires_at' => now()->addDay(), 'public_key' => 'pk_live_'.Str::lower(Str::random(28))])->save();
        $this->audit->log('Widget web', 'clave', 'regenerar_clave', 'pk_…'.substr($old, -4), 'pk_…'.substr($cfg->public_key, -4)." · motivo: {$reason}", actor: $actor);

        return $this->show();
    }

    // --- dominios (WG-01) ---

    public function domains(): array
    {
        return WidgetDomain::orderBy('domain')->get()->map(fn (WidgetDomain $d) => [
            'id' => $d->id, 'domain' => $d->domain, 'cms' => $d->cms, 'status' => $d->status, 'verify_token' => $d->verify_token,
            'txt_record' => ['host' => "_qhapaq.{$d->domain}", 'value' => $d->verify_token], 'verified_at' => $d->verified_at?->toIso8601String(),
            'last_check_at' => $d->last_check_at?->toIso8601String(),
        ])->all();
    }

    public function addDomain(array $data, User $actor): array
    {
        $domain = strtolower(trim((string) ($data['domain'] ?? '')));
        $domain = preg_replace('#^https?://#', '', rtrim($domain, '/'));
        if (! preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) || strlen($domain) > 253) {
            throw ApiException::invalid('domain', 'Dominio inválido (ej.: www.miagencia.com, sin https:// ni rutas).');
        }
        if (WidgetDomain::where('domain', $domain)->exists()) {
            throw ApiException::invalid('domain', 'El dominio ya está registrado.');
        }
        $cms = in_array($data['cms'] ?? null, self::CMS, true) ? $data['cms'] : null;
        $d = WidgetDomain::create(['domain' => $domain, 'cms' => $cms, 'status' => 'pendiente', 'verify_token' => 'qhapaq-verify='.Str::lower(Str::random(32))]);
        $this->audit->log('Widget web', $domain, 'agregar_dominio', null, 'pendiente'.($cms ? " · {$cms}" : ''), actor: $actor);

        return $this->domains();
    }

    /** VerifyWidgetDomain: busca el TXT `_qhapaq.{dominio}` con el token. */
    public function verifyDomain(WidgetDomain $d, ?User $actor = null): array
    {
        $found = in_array($d->verify_token, app(DnsTxtResolver::class)->txt("_qhapaq.{$d->domain}", $d->verify_token), true);
        $d->forceFill(['last_check_at' => now()] + ($found ? ['status' => 'verificado', 'verified_at' => now()] : []))->save();
        if ($found) {
            $this->audit->log('Widget web', $d->domain, 'verificar_dominio', 'pendiente', 'verificado', actor: $actor, system: $actor === null);
        }

        return ['verified' => $found, 'message' => $found ? "Dominio {$d->domain} verificado: CORS y frame-ancestors actualizados."
            : "No se encontró el registro TXT en _qhapaq.{$d->domain}. La propagación DNS puede tardar hasta 72 h.", 'domains' => $this->domains()];
    }

    public function deleteDomain(WidgetDomain $d, User $actor): array
    {
        $this->audit->log('Widget web', $d->domain, 'quitar_dominio', $d->status, null, actor: $actor);
        $d->delete();

        return $this->domains();
    }

    /** Job (cada 10 min durante 72 h). */
    public function verifyPending(): int
    {
        $n = 0;
        foreach (WidgetDomain::where('status', 'pendiente')->where('created_at', '>=', now()->subHours(72))->get() as $d) {
            $n += (int) $this->verifyDomain($d)['verified'];
        }

        return $n;
    }

    /** CSP `frame-ancestors` y orígenes CORS resultantes (solo verificados). @return list<string> */
    public function allowedOrigins(): array
    {
        return WidgetDomain::where('status', 'verificado')->orderBy('domain')->pluck('domain')->flatMap(fn ($d) => ["https://{$d}"])->all();
    }

    // --- vista pública ---

    /** Bootstrap del widget: tema, monedas, reglas que lo afectan y producto (o catálogo). */
    public function publicConfig(): array
    {
        $cfg = $this->config();
        $engine = app(BookingEngine::class);
        $tenant = $this->tenant->tenant();

        return [
            'agency' => $tenant?->trade_name ?? $tenant?->legal_name, 'defaults' => (object) ($cfg->defaults ?? []), 'currencies' => app(CurrencyService::class)->activeRates(),
            'multicurrency' => $engine->rules->on('R-MULTICURRENCY'), 'preview_remove' => $engine->rules->on('R-PREVIEW-REMOVE'),
            'products' => $this->bookable()->sortBy('name')->values()->all(),
        ];
    }

    /** Producto para el widget: reglas de pasajeros, adicionales y alojamiento (sin costos ni proveedores). */
    public function publicProduct(string $ref): array
    {
        $p = Product::where('status', Product::ACTIVE)->where('is_bookable', true)->where('ref', strtoupper($ref))->first() ?? throw ApiException::notFound();
        $engine = app(BookingEngine::class);
        $config = app(BookingConfigService::class);
        $on = fn (string $k) => $engine->rules->on($k, $p);
        $acc = $on('R-LODGING') && $p->accommodation_enabled ? $config->accommodation($p) : null;
        $quote = $engine->quote($p, QuoteSelection::fromArray(['adt' => 1]));

        return [
            'ref' => $p->ref, 'name' => $p->name, 'category' => $p->category?->name, 'duration' => self::duration($p),
            'pax' => $engine->paxRules->resolve($p)->toArray(), 'max_pax' => $engine->rules->on('R-MAX-PAX', $p) ? (int) $p->maximum_passengers : null,
            'addons' => $on('R-ADDONS') ? array_map(fn ($a) => array_intersect_key($a, array_flip(['id', 'code', 'name', 'category', 'price', 'currency', 'charge_per', 'applies_to', 'stock_note'])),
                array_values(array_filter($config->addons($p), fn ($a) => $a['is_optional']))) : [],
            'lodging' => $acc ? ['optional' => $acc['optional'], 'currency' => $acc['currency'], 'note' => $acc['note'], 'nights' => $acc['nights'],
                'legs' => array_map(fn ($l) => ['id' => $l['id'], 'city' => $l['city'], 'nights' => $l['nights'], 'hotels' => array_map(fn ($h) => ['id' => $h['id'], 'name' => $h['name'],
                    'category' => $h['category'], 'room_types' => $h['room_types']], $l['hotels'])], $acc['legs'])] : null,
            'modes' => $quote->modes, 'rules' => ['promo' => $on('R-PROMO'), 'preview_remove' => $on('R-PREVIEW-REMOVE'), 'multicurrency' => $on('R-MULTICURRENCY'),
                'deposit' => $on('R-DEPOSIT'), 'pay_later' => $on('R-PAY-LATER'), 'lodging' => (bool) $acc, 'addons' => $on('R-ADDONS')],
            'next_available' => $engine->nextAvailable($p, 1),
        ];
    }

    /** Productos comercializables con categoría y duración. */
    private function bookable()
    {
        return Product::with('category:id,name')->where('status', Product::ACTIVE)->where('is_bookable', true)->get(['id', 'ref', 'name', 'category_id', 'duration_value', 'duration_unit'])
            ->map(fn (Product $p) => ['ref' => $p->ref, 'name' => $p->name, 'category' => $p->category?->name, 'duration' => self::duration($p)]);
    }

    private static function duration(Product $p): string
    {
        $n = rtrim(rtrim((string) $p->duration_value, '0'), '.');
        $unit = (string) $p->duration_unit;

        return $n.' '.($n === '1' ? (['días' => 'día', 'horas' => 'hora', 'noches' => 'noche'][$unit] ?? $unit) : $unit);
    }

    // --- traspasos al checkout ---

    /** POST /public/widget-handoffs: cotiza en servidor, guarda la selección (TTL 24 h) y devuelve `checkout_url`. Idempotente por Idempotency-Key. */
    public function handoff(array $data, ?string $idempotencyKey, ?string $origin): array
    {
        if ($idempotencyKey && ($existing = WidgetHandoff::where('idempotency_key', $idempotencyKey)->first())) {
            return $this->presentHandoff($existing) + ['replayed' => true];
        }
        $p = Product::where('status', Product::ACTIVE)->where('is_bookable', true)->where('ref', strtoupper((string) ($data['product'] ?? '')))->first()
            ?? throw ApiException::invalid('product', 'Producto no disponible.');
        $sel = QuoteSelection::fromArray($data);
        if (! $sel->date) {
            throw ApiException::invalid('date', 'Elija una fecha.');
        }
        $engine = app(BookingEngine::class);
        $day = $engine->dayStatus($p, $sel->date, $sel->seats());
        if (! $day->bookable(max(1, $sel->seats()))) {
            throw ApiException::unprocessable('DAY_NOT_AVAILABLE', "La fecha elegida no está disponible ({$day->label}).");
        }
        $quote = $engine->quote($p, $sel);
        if (! $quote->ok()) {
            throw ApiException::unprocessable('QUOTE_INVALID', 'La selección no cumple las reglas de reserva.', ['warnings' => $quote->warnings]);
        }
        $lang = in_array($data['lang'] ?? 'es', ['es', 'en'], true) ? $data['lang'] : 'es';
        $host = $origin ? parse_url($origin, PHP_URL_HOST) : null;
        $h = DB::transaction(function () use ($p, $sel, $quote, $lang, $host, $idempotencyKey) {
            $h = WidgetHandoff::create([
                'code' => $this->sequences->next('widget_handoff'), 'product_id' => $p->id, 'service_date' => $sel->date, 'pax' => ['adt' => $sel->adt, 'chd' => $sel->chd, 'inf' => $sel->inf],
                'addons' => $sel->addons, 'lodging' => $sel->lodging, 'removed' => $sel->removed, 'currency_display' => $quote->currency, 'fx_rate' => $quote->fxRate,
                'payment_mode' => $quote->paymentMode, 'lang' => $lang, 'origin_domain' => $host ? mb_substr($host, 0, 253) : null,
                'quote_snapshot' => $quote->toArray() + ['selection' => $sel->toArray()], 'rules_applied' => $quote->rulesApplied, 'status' => 'pendiente',
                'idempotency_key' => $idempotencyKey ? mb_substr($idempotencyKey, 0, 100) : null, 'expires_at' => now()->addDay(),
            ]);
            app(WebhookService::class)->dispatch('widget.checkout_started', $h->code, ['code' => $h->code, 'product' => $p->ref, 'date' => $sel->date,
                'pax' => $h->pax, 'currency' => $quote->currency, 'total' => $quote->total, 'origin_domain' => $h->origin_domain]);

            return $h;
        });

        return $this->presentHandoff($h->load('product:id,ref,name'));
    }

    /** Checkout del traspaso: re-cotiza en servidor (WG-03) en una moneda de cobro y firma el `quote_token`. Marca `en_carrito`. */
    public function checkout(string $code): array
    {
        $h = WidgetHandoff::with('product')->where('code', $code)->first() ?? throw ApiException::notFound();
        if ($h->status === 'expirado' || ($h->status !== 'convertido' && $h->expires_at->isPast())) {
            $h->forceFill(['status' => 'expirado'])->save();
            throw ApiException::unprocessable('HANDOFF_EXPIRED', 'El traspaso venció (24 h). Vuelva a elegir su viaje en el widget.');
        }
        if ($h->status === 'pendiente') {
            $h->forceFill(['status' => 'en_carrito'])->save();
        }
        $selection = (array) ($h->quote_snapshot['selection'] ?? []);
        $charge = app(CurrencyService::class)->activeRates()[$h->currency_display]['is_charge'] ?? false;
        $sel = QuoteSelection::fromArray(['currency' => $charge ? $h->currency_display : null] + $selection);
        $quote = app(BookingEngine::class)->quote($h->product, $sel);

        $rules = app(BookingEngine::class)->rules;
        $pickup = $rules->on('R-PICKUP', $h->product) ? PickupPoint::where('product_id', $h->product->id)->orderBy('sequence')->get(['id', 'label', 'kind', 'requires_address']) : collect();

        return $this->presentHandoff($h) + ['quote' => $quote->toArray(), 'quote_token' => app(QuoteToken::class)->sign($h->product->id, $sel, $quote),
            'display' => ['currency' => $h->currency_display, 'total' => $h->quote_snapshot['total'] ?? null], 'charge_currency' => $quote->currency,
            'pickup' => ['required' => (bool) $h->product->pickup_required && $pickup->isNotEmpty(), 'ask_flight' => (bool) $h->product->pickup_ask_flight && $pickup->isNotEmpty(),
                'points' => $pickup->map(fn (PickupPoint $p) => ['id' => $p->id, 'label' => $p->label, 'kind' => $p->kind, 'requires_address' => (bool) $p->requires_address])->all()],
            'comments' => $rules->on('R-COMMENTS', $h->product)];
    }

    /** Llamado por PublicBookingService al crear la reserva desde el traspaso. */
    public function converted(string $code, string $reservationId): ?WidgetHandoff
    {
        $h = WidgetHandoff::where('code', $code)->whereIn('status', ['pendiente', 'en_carrito'])->first();
        $h?->forceFill(['status' => 'convertido', 'reservation_id' => $reservationId])->save();

        return $h;
    }

    public function expire(): int
    {
        return WidgetHandoff::whereIn('status', ['pendiente', 'en_carrito'])->where('expires_at', '<=', now())->update(['status' => 'expirado', 'updated_at' => now()]);
    }

    public function presentHandoff(WidgetHandoff $h): array
    {
        $url = rtrim((string) config('turismo.frontend_url'), '/');

        return [
            'code' => $h->code, 'status' => $h->status, 'product' => $h->product ? ['ref' => $h->product->ref, 'name' => $h->product->name] : null,
            'date' => $h->service_date?->toDateString(), 'pax' => $h->pax, 'currency' => $h->currency_display, 'payment_mode' => $h->payment_mode, 'lang' => $h->lang,
            'total' => $h->quote_snapshot['total'] ?? null, 'lines' => $h->quote_snapshot['lines'] ?? [], 'adjustments' => $h->quote_snapshot['adjustments'] ?? [],
            'deposit' => $h->quote_snapshot['deposit'] ?? null, 'balance' => $h->quote_snapshot['balance'] ?? null, 'rooms' => $h->quote_snapshot['rooms'] ?? 0,
            'nights' => $h->quote_snapshot['nights'] ?? 0, 'origin_domain' => $h->origin_domain, 'expires_at' => $h->expires_at?->toIso8601String(),
            'created_at' => $h->created_at?->toIso8601String(), 'reservation_id' => $h->reservation_id,
            'checkout_url' => "{$url}/sitio/reserva/{$h->code}?tenant=".($this->tenant->tenant()?->slug ?? ''),
        ];
    }
}
