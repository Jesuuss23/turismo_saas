<?php

namespace App\Services\Site;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Destination;
use App\Models\Catalog\DestinationContent;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductPromotion;
use App\Models\Catalog\ProductTranslation;
use App\Models\Catalog\Season;
use App\Models\Channel\CmsContent;
use App\Models\Iam\Sede;
use App\Services\Bookings\BookingInventory;
use App\Services\Catalog\AvailabilityService;
use App\Services\Channels\EcomCheckout;
use App\Services\Channels\EcomPricing;
use App\Services\Crm\CrmClock;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Catálogo y contenido públicos del sitio (spec 15 §6.1, SIT-BR-01/03/04): solo ACTIVE ∧ publicado en e-commerce ∧ tarifa vigente.
 * Nunca serializa costos, márgenes ni proveedores (§7).
 */
class SiteCatalog
{
    use RequestMemo;

    /** Tipo del listado público → tipos de producto del catálogo. */
    public const TYPES = [
        'tours' => ['TOUR', 'EXCURSIÓN'], 'paquetes' => ['PAQUETE', 'CIRCUITO', 'PROGRAMA TURÍSTICO'],
        'experiencias' => ['EXPERIENCIA', 'ACTIVIDAD'], 'traslados' => ['TRASLADO'],
    ];

    public const DIFFICULTY = ['facil' => 'Fácil', 'moderada' => 'Moderada', 'moderada_exigente' => 'Moderada a exigente', 'exigente' => 'Exigente'];

    /** Salida garantizada: confirmada con al menos este número de pasajeros vendidos. */
    public const GUARANTEED_MIN_SOLD = 4;

    /** @var array<string, int>|null */
    private ?array $sales = null;

    public function __construct(
        private readonly EcomCheckout $checkout,
        private readonly SitePricing $pricing,
        private readonly AvailabilityService $availability,
        private readonly CrmClock $clock,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /public/site: cabecera y pie (§2 «Pie de página»). */
    public function site(string $locale = 'es'): array
    {
        $t = $this->tenant->tenant();
        $s = (array) data_get($t?->settings, 'site', []);
        $banner = CmsContent::where('type', 'Banner')->where('status', 'publicado')->where('channel', 'ilike', '%Web%')->orderByDesc('published_at')->first();

        return [
            'name' => $t?->trade_name ?? $t?->legal_name, 'legal_name' => $t?->legal_name, 'ruc' => $t?->ruc, 'fiscal_address' => $t?->fiscal_address,
            'whatsapp' => $s['whatsapp'] ?? '+51 984 123 456', 'phone' => $s['phone'] ?? '+51 1 445 7788', 'emergency' => $s['emergency'] ?? '+51 984 765 432',
            'email' => $s['email'] ?? ($t?->admin_email ?? null), 'hours' => $s['hours'] ?? 'Lun–Vie 9:00–19:00 · Sáb 9:00–13:00 (hora de Lima)',
            'socials' => $s['socials'] ?? ['Facebook: /andestravel', 'Instagram: @andestravel', 'TikTok: @andestravel'],
            'payment_methods' => ['Visa', 'Mastercard', 'American Express', 'Diners Club', 'Transferencia bancaria', 'Yape / Plin'],
            'sedes' => Sede::where('status', 'activa')->orderByDesc('is_main')->orderBy('name')->get()->map(fn (Sede $x) => ['code' => $x->code, 'name' => $x->name, 'city' => $x->city,
                'address' => $x->address, 'phone' => $x->phone])->all(),
            'fx' => ['usd_pen' => $this->pricing->usdRate(), 'date' => $this->clock->today()->toDateString(), 'source' => 'TC del día (venta, referencial SBS)'],
            'locales' => ['es', 'en'], 'currencies' => SitePricing::CURRENCIES, 'hold_minutes' => $this->availability->holdMinutes(),
            'banner' => $banner ? ['title' => $banner->title, 'body' => $banner->body, 'slug' => $banner->slug] : null,
            'locale' => $locale,
        ];
    }

    /** Productos publicados (ECO-BR-02 / SIT-BR-01). @return Collection<int, Product> */
    public function published(): Collection
    {
        return $this->memo('published', fn () => $this->checkout->published()->load(['type', 'category', 'policies', 'secondaryDestinations']));
    }

    public function find(string $slug): Product
    {
        $translated = ProductTranslation::where('slug', $slug)->value('product_id');
        $product = $this->published()->first(fn (Product $p) => $p->id === $translated || self::slugOf($p) === $slug || $p->ref === $slug);
        if (! $product) {
            throw new ApiException(404, 'PRD-BR-002', 'PRD-BR-002', 'Este producto no está disponible para la venta en línea (PRD-BR-002).');
        }

        return $product;
    }

    public static function slugOf(Product $p): string
    {
        return $p->slug ?: Str::slug($p->name);
    }

    /** GET /public/products (§6.1): filtros en query; precios en la moneda elegida. */
    public function products(array $f, string $locale, string $currency): array
    {
        $today = $this->clock->today()->toDateString();
        $rows = $this->published();
        if ($dest = $f['destination'] ?? null) {
            $ids = $this->destinationTree($dest);
            $rows = $rows->filter(fn (Product $p) => in_array($p->primary_destination_id, $ids, true) || $p->secondaryDestinations->whereIn('destination_id', $ids)->isNotEmpty());
        }
        if (($type = $f['type'] ?? null) && isset(self::TYPES[$type])) {
            $rows = $rows->filter(fn (Product $p) => in_array($p->type?->name, self::TYPES[$type], true));
        }
        if ($q = mb_strtolower(trim((string) ($f['q'] ?? '')))) {
            $rows = $rows->filter(fn (Product $p) => str_contains(mb_strtolower($p->name.' '.$p->primaryDestination?->name.' '.$this->translation($p, $locale)?->name), $q));
        }
        if ($cat = $f['category'] ?? null) {
            $rows = $rows->filter(fn (Product $p) => $p->category?->name === $cat);
        }
        if ($diff = $f['difficulty'] ?? null) {
            $rows = $rows->filter(fn (Product $p) => $p->difficulty === $diff);
        }
        if ($lang = strtoupper((string) ($f['language'] ?? ''))) {
            $rows = $rows->filter(fn (Product $p) => in_array($lang, (array) $p->guide_languages, true));
        }
        if ($mod = $f['modality'] ?? null) {
            $rows = $rows->filter(fn (Product $p) => mb_strtolower($p->modality) === mb_strtolower($mod));
        }
        if ($dur = $f['duration'] ?? null) {
            $rows = $rows->filter(fn (Product $p) => match ($dur) {
                '1' => self::days($p) <= 1, '2-4' => self::days($p) >= 2 && self::days($p) <= 4, '5+' => self::days($p) >= 5, default => true
            });
        }
        $pax = max(1, (int) ($f['pax'] ?? 1));
        if (($date = $f['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $rows = $rows->filter(fn (Product $p) => $this->dayStatus($p, $date, $pax)['sellable']);
        }
        $cards = $rows->map(fn (Product $p) => $this->card($p, $locale, $currency, $date ?? null))->values();
        foreach (['price_min' => fn ($c, $v) => $c['from_price'] !== null && (float) $c['from_price'] >= $v, 'price_max' => fn ($c, $v) => $c['from_price'] !== null && (float) $c['from_price'] <= $v] as $key => $test) {
            if (isset($f[$key]) && is_numeric($f[$key])) {
                $cards = $cards->filter(fn ($c) => $test($c, (float) $f[$key]))->values();
            }
        }
        $cards = match ($f['sort'] ?? 'recommended') {
            'price' => $cards->sortBy(fn ($c) => (float) ($c['from_price'] ?? PHP_INT_MAX)),
            '-price' => $cards->sortByDesc(fn ($c) => (float) ($c['from_price'] ?? 0)),
            'duration' => $cards->sortBy('duration_days'),
            'best_seller' => $cards->sortByDesc('sold'),
            'rating' => $cards->sortByDesc(fn ($c) => (float) ($c['rating']['avg'] ?? 0)),
            default => $cards->sortByDesc(fn ($c) => ($c['featured'] ? 1000 : 0) + $c['sold']),
        };

        return ['data' => $cards->values()->all(), 'meta' => ['count' => $cards->count(), 'today' => $today,
            'facets' => ['categories' => $this->published()->pluck('category.name')->filter()->unique()->sort()->values()->all(),
                'destinations' => $this->destinationOptions($locale)]]];
    }

    /** Tarjeta del listado: precio «desde» (SIT-BR-03), etiquetas, próxima salida y rating de catálogo (rotulado demo). */
    public function card(Product $p, string $locale, string $currency, ?string $date = null): array
    {
        $tr = $this->translation($p, $locale);
        $departures = $this->openDepartures($p);
        $from = $date ? ($this->pricing->day($p, $date) ?? $this->pricing->fromPrice($p, $departures)) : $this->pricing->fromPrice($p, $departures);
        $next = $departures->first(fn (Departure $d) => $this->availability->available($d) > 0);
        $promo = app(EcomPricing::class)->promotions($p)->isNotEmpty();
        $sold = $this->sales()[$p->id] ?? 0;
        $conv = fn (?string $v) => $v === null || ! $from ? null : $this->pricing->convert($v, $from['currency'], $currency);
        $tags = array_values(array_filter([
            $sold > 0 && $sold >= max($this->sales() ?: [0]) ? 'best_seller' : null,
            $promo || ($from['list_price'] ?? null) ? 'offer' : null,
            self::freeCancellation($p) ? 'free_cancellation' : null,
            BookingInventory::bySalida($p) ? 'instant_confirmation' : null,
        ]));

        return [
            'id' => $p->id, 'ref' => $p->ref, 'slug' => $tr?->slug ?? self::slugOf($p), 'name' => $tr?->name ?? $p->name, 'summary' => $tr?->short ?? $p->short_description,
            'translated' => $locale === 'es' || $tr !== null, 'destination' => $p->primaryDestination?->name, 'type' => $p->type?->name, 'category' => $p->category?->name,
            'duration' => self::durationLabel($p, $locale), 'duration_days' => self::days($p), 'modality' => $p->modality, 'languages' => array_values((array) $p->guide_languages),
            'difficulty' => $p->difficulty, 'difficulty_label' => self::DIFFICULTY[$p->difficulty] ?? null, 'featured' => (bool) $p->is_featured,
            'rating' => $p->rating_count > 0 ? ['avg' => (string) $p->rating_avg, 'count' => $p->rating_count, 'demo' => true] : null,
            'currency' => $currency, 'from_price' => $conv($from['adult'] ?? null), 'list_price' => $conv($from['list_price'] ?? null),
            'rate_currency' => $from['currency'] ?? null, 'tags' => $tags, 'sold' => $sold,
            'next_departure' => $next ? ['date' => $next->departure_date->toDateString(), 'time' => substr((string) $next->departure_time, 0, 5), 'available' => $this->availability->available($next)] : null,
            'daily' => ! BookingInventory::bySalida($p),
        ];
    }

    /** GET /public/products/{slug}: ficha completa (§2 «Ficha»). */
    public function product(string $slug, string $locale, string $currency): array
    {
        $p = $this->find($slug);
        $p->loadMissing(['inclusions', 'requirements', 'faqs', 'itinerary.days.items', 'configGroups.options', 'related', 'policies']);
        $tr = $this->translation($p, $locale);
        $card = $this->card($p, $locale, $currency);
        $includes = $tr?->inclusions ?? $p->inclusions->where('kind', 'include')->pluck('text')->values()->all();
        $excludes = $tr?->exclusions ?? $p->inclusions->where('kind', 'exclude')->pluck('text')->values()->all();
        $faqs = $tr?->faqs ?? $p->faqs->sortBy('sort_order')->map(fn ($f) => ['q' => $f->question, 'a' => $f->answer])->values()->all();
        $general = [['q' => $locale === 'en' ? 'Can I pay a deposit?' : '¿Puedo pagar un anticipo?', 'a' => $locale === 'en'
            ? 'Yes, if your trip starts in 15 days or more: 30 % (30+ days) or 40 % (15–29 days). The balance is due 15 days before departure.'
            : 'Sí, si su viaje inicia en 15 días o más: 30 % (30 días o más) o 40 % (15 a 29 días). El saldo vence 15 días antes del inicio.']];
        $relatedIds = $p->related->pluck('related_product_id');
        $related = $this->published()->filter(fn (Product $x) => $x->id !== $p->id && ($relatedIds->contains($x->id) || $x->primary_destination_id === $p->primary_destination_id))->take(3)
            ->map(fn (Product $x) => $this->card($x, $locale, $currency))->values()->all();
        $policy = $p->policies;
        $transfer = $this->pricing->transferAllowed($p) ? $this->pricing->transferProduct() : null;
        $meeting = Departure::where('product_id', $p->id)->whereNotNull('meeting_point')->orderByDesc('departure_date')->value('meeting_point');

        return $card + [
            'description' => $tr?->long ?? $p->long_description, 'min_pax' => $p->minimum_passengers, 'max_pax' => $p->maximum_passengers, 'min_age' => $p->minimum_age,
            'advance_min_days' => $p->booking_advance_min_days, 'advance_max_days' => $p->booking_advance_max_days, 'by_departure' => BookingInventory::bySalida($p),
            'children_allowed' => $p->minimum_age < 12, 'infants_allowed' => $p->minimum_age === 0, 'pickup' => $p->pickup_text, 'meeting_point' => $meeting,
            'gallery' => [['alt' => $p->name, 'tone' => 'sky'], ['alt' => $p->primaryDestination?->name ?? $p->name, 'tone' => 'emerald'], ['alt' => $locale === 'en' ? 'Group on tour' : 'Grupo en ruta', 'tone' => 'amber']],
            'route' => $this->routeOf($p),
            'itinerary' => $p->itinerary?->days->sortBy('day_number')->map(fn ($d) => ['day' => $d->day_number, 'title' => $d->title,
                'items' => $d->items->sortBy('start_time')->map(fn ($i) => ['time' => $i->start_time ? substr((string) $i->start_time, 0, 5) : null, 'activity' => $i->activity])->values()->all()])->values()->all() ?? [],
            'includes' => $includes, 'excludes' => $excludes,
            'requirements' => $p->requirements->pluck('text')->values()->all(),
            'policies' => ['cancellation' => $policy?->cancellation, 'modification' => $policy?->modification, 'no_show' => $policy?->no_show, 'refund' => $policy?->refund,
                'advance' => $p->booking_advance_min_days > 0 ? "Reserve con al menos {$p->booking_advance_min_days} día(s) de anticipación." : null],
            'faqs' => array_merge($faqs, $general),
            'config' => $p->configGroups->sortBy('sort_order')->map(fn ($g) => ['id' => $g->id, 'name' => $g->name,
                'options' => $g->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label, 'delta_pp' => (string) $o->price_delta])->values()->all()])->values()->all(),
            'extras' => array_values(array_filter([
                ['code' => 'insurance', 'label' => $locale === 'en' ? 'Travel assistance insurance' : 'Seguro de asistencia al viajero', 'price' => 'US$ '.SitePricing::INSURANCE_USD_PER_PAX_DAY.' por pasajero y día'],
                $transfer ? ['code' => 'airport_transfer', 'label' => $locale === 'en' ? 'Private airport → hotel transfer (Cusco)' : 'Traslado privado aeropuerto → hotel (Cusco)', 'price' => 'por vehículo'] : null,
            ])),
            'departures' => $this->departures($p),
            'promotions' => app(EcomPricing::class)->promotions($p)->map(fn (ProductPromotion $x) => ['name' => $x->name, 'label' => EcomPricing::percent($x), 'code' => $x->promo_code,
                'min_pax' => $x->promo_code ? null : ($x->min_pax ?? EcomPricing::PROMO_MIN_PAX), 'ends_at' => $x->date_to?->toDateString()])->values()->all(),
            'related' => $related,
            'seo' => ['title' => ($tr?->seo_title ?? $p->seo_title ?: $card['name']), 'description' => $tr?->seo_description ?? $p->seo_description ?: $card['summary']],
            'json_ld' => $this->jsonLd($p, $card),
        ];
    }

    /** SIT-BR-04: día vendible para N pasajeros (adultos + niños; los infantes no ocupan cupo). */
    public function dayStatus(Product $p, string $date, int $pax): array
    {
        $today = $this->clock->today();
        $days = (int) $today->diffInDays(Carbon::parse($date), false);
        $base = ['date' => $date, 'sellable' => false, 'reason' => null, 'departure' => null, 'available' => null];
        if ($days < $p->booking_advance_min_days) {
            return ['reason' => $days < 0 ? 'pasado' : 'fuera_de_plazo'] + $base;
        }
        if ($days > $p->booking_advance_max_days) {
            return ['reason' => 'fuera_de_plazo'] + $base;
        }
        if (! $this->pricing->day($p, $date)) {
            return ['reason' => 'sin_tarifa'] + $base;
        }
        if (! BookingInventory::bySalida($p)) {
            return ['sellable' => true, 'reason' => 'bajo_solicitud'] + $base;
        }
        $deps = $this->openDepartures($p)->filter(fn (Departure $d) => $d->departure_date->toDateString() === $date);
        if ($deps->isEmpty()) {
            return ['reason' => 'sin_salida'] + $base;
        }
        $best = $deps->sortByDesc(fn (Departure $d) => $this->availability->available($d))->first();
        $free = $this->availability->available($best);
        $dep = ['id' => $best->id, 'ref' => $best->ref, 'time' => substr((string) $best->departure_time, 0, 5), 'guaranteed' => $best->sold >= self::GUARANTEED_MIN_SOLD];

        return $free >= $pax ? ['sellable' => true, 'reason' => null, 'departure' => $dep, 'available' => $free] + $base
            : ['reason' => $free === 0 ? 'agotado' : 'sin_cupo_para_el_grupo', 'departure' => $dep, 'available' => $free] + $base;
    }

    /** GET /public/products/{slug}/calendar?month=YYYY-MM&pax=: precio adulto por día y marca de temporada. */
    public function calendar(string $slug, string $month, int $pax, string $currency): array
    {
        $p = $this->find($slug);
        $start = preg_match('/^\d{4}-\d{2}$/', $month) ? Carbon::parse("{$month}-01") : $this->clock->today()->startOfMonth();
        $days = [];
        for ($d = $start->copy(); $d->month === $start->month; $d->addDay()) {
            $date = $d->toDateString();
            $status = $this->dayStatus($p, $date, max(1, $pax));
            $price = $status['reason'] === 'sin_tarifa' || in_array($status['reason'], ['pasado'], true) ? null : $this->pricing->day($p, $date);
            $days[] = $status + ['price' => $price ? $this->pricing->convert($price['adult'], $price['currency'], $currency) : null,
                'season' => $price['season']['kind'] ?? null, 'season_name' => $price['season']['name'] ?? null];
        }
        $next = collect($days)->first(fn ($x) => $x['sellable']);

        return ['month' => $start->format('Y-m'), 'currency' => $currency, 'days' => $days, 'next_available' => $next['date'] ?? $this->nextAvailable($p, $pax)];
    }

    public function nextAvailable(Product $p, int $pax): ?string
    {
        $today = $this->clock->today();
        if (BookingInventory::bySalida($p)) {
            foreach ($this->openDepartures($p) as $d) {
                $s = $this->dayStatus($p, $d->departure_date->toDateString(), $pax);
                if ($s['sellable']) {
                    return $s['date'];
                }
            }

            return null;
        }
        $date = $today->copy()->addDays($p->booking_advance_min_days)->toDateString();

        return $this->pricing->day($p, $date) ? $date : null;
    }

    /** Próximas salidas con cupos y «garantizada». */
    public function departures(Product $p, ?string $from = null, ?string $to = null): array
    {
        return $this->openDepartures($p)
            ->filter(fn (Departure $d) => (! $from || $d->departure_date->toDateString() >= $from) && (! $to || $d->departure_date->toDateString() <= $to))
            ->map(fn (Departure $d) => ['id' => $d->id, 'ref' => $d->ref, 'date' => $d->departure_date->toDateString(), 'time' => substr((string) $d->departure_time, 0, 5),
                'capacity' => $d->capacity, 'sold' => $d->sold, 'available' => $this->availability->available($d), 'guaranteed' => $d->sold >= self::GUARANTEED_MIN_SOLD,
                'meeting_point' => $d->meeting_point])->values()->all();
    }

    /** GET /public/departures/guaranteed: salidas grupales garantizadas con cupo (home/ofertas). */
    public function guaranteed(int $limit, string $locale, string $currency): array
    {
        $rows = collect();
        foreach ($this->published() as $p) {
            foreach ($this->departures($p) as $d) {
                if ($d['guaranteed'] && $d['available'] > 0) {
                    $price = $this->pricing->day($p, $d['date']);
                    $rows->push($d + ['product' => ['slug' => $this->translation($p, $locale)?->slug ?? self::slugOf($p), 'name' => $this->translation($p, $locale)?->name ?? $p->name],
                        'price' => $price ? $this->pricing->convert($price['adult'], $price['currency'], $currency) : null, 'currency' => $currency]);
                }
            }
        }

        return $rows->sortBy('date')->take($limit)->values()->all();
    }

    /** GET /public/offers: promociones vigentes con código o automáticas + precios web, con cuenta regresiva. */
    public function offers(string $locale, string $currency): array
    {
        $out = [];
        foreach ($this->published() as $p) {
            $card = $this->card($p, $locale, $currency);
            foreach (app(EcomPricing::class)->promotions($p) as $x) {
                $out[] = ['kind' => $x->promo_code ? 'codigo' : 'automatica', 'name' => $x->name, 'label' => EcomPricing::percent($x), 'code' => $x->promo_code,
                    'min_pax' => $x->promo_code ? null : ($x->min_pax ?? EcomPricing::PROMO_MIN_PAX), 'ends_at' => $x->date_to?->toDateString(), 'product' => $card];
            }
            if ($card['list_price']) {
                $out[] = ['kind' => 'precio_web', 'name' => $locale === 'en' ? 'Online price' : 'Precio web', 'label' => null, 'code' => null, 'min_pax' => null,
                    'ends_at' => null, 'product' => $card];
            }
        }

        return $out;
    }

    /** GET /public/destinations: destinos con página, nº de experiencias y franja de 12 meses (mejor época). */
    public function destinations(string $locale, string $currency): array
    {
        return $this->contents($locale)->map(fn (DestinationContent $c) => $this->destinationCard($c, $locale))->values()->all();
    }

    public function destination(string $slug, string $locale, string $currency): array
    {
        $c = $this->contents($locale)->first(fn (DestinationContent $x) => $x->slug === $slug)
            ?? $this->contents('es')->first(fn (DestinationContent $x) => $x->slug === $slug)
            ?? throw ApiException::notFound();
        $ids = $this->destinationTree($c->destination_id);
        $products = $this->published()->filter(fn (Product $p) => in_array($p->primary_destination_id, $ids, true) || $p->secondaryDestinations->whereIn('destination_id', $ids)->isNotEmpty())
            ->map(fn (Product $p) => $this->card($p, $locale, $currency))->values()->all();
        $special = Season::where('status', 'activa')->whereIn('type', ['Feriado', 'Fecha especial'])->whereDate('date_to', '>=', $this->clock->today()->toDateString())->orderBy('date_from')->get()
            ->map(fn (Season $s) => ['name' => $s->name, 'from' => $s->date_from->toDateString(), 'to' => $s->date_to->toDateString(), 'factor' => (string) $s->factor])->values()->all();

        return $this->destinationCard($c, $locale) + ['description' => $c->description, 'climate' => $c->climate, 'faqs' => $c->faqs ?? [],
            'lat' => $c->destination?->lat, 'lng' => $c->destination?->lng, 'products' => $products, 'special_dates' => $special];
    }

    /** CMS publicado: blog (Artículo), páginas legales y páginas (spec 15 §3.12). */
    public function posts(): array
    {
        return CmsContent::where('type', 'Artículo')->where('status', 'publicado')->where('channel', 'ilike', '%Web%')->orderByDesc('published_at')->get()
            ->map(fn (CmsContent $c) => ['slug' => $c->slug, 'title' => $c->title, 'excerpt' => Str::limit(strip_tags((string) $c->body), 180), 'published_at' => $c->published_at?->toDateString(),
                'author' => $c->author?->name, 'example' => false])->all();
    }

    public function page(string $slug, array $types = ['Artículo', 'Página', 'Página legal']): array
    {
        $c = CmsContent::whereIn('type', $types)->where('status', 'publicado')->where('slug', $slug)->first() ?? throw ApiException::notFound();

        return ['slug' => $c->slug, 'title' => $c->title, 'type' => $c->type, 'body' => $c->body, 'published_at' => $c->published_at?->toDateString(), 'author' => $c->author?->name,
            'seo' => ['title' => $c->seo_title ?: $c->title, 'description' => $c->seo_description]];
    }

    public function translation(Product $p, string $locale): ?ProductTranslation
    {
        if ($locale === 'es') {
            return null;
        }
        $all = $this->memo('translations', fn () => ProductTranslation::all()->groupBy(fn ($t) => "{$t->product_id}:{$t->locale}"));

        return $all["{$p->id}:{$locale}"][0] ?? null;
    }

    public static function days(Product $p): int
    {
        return $p->duration_unit === 'días' ? max(1, (int) ceil((float) $p->duration_value)) : ($p->duration_unit === 'noches' ? (int) $p->duration_value + 1 : 1);
    }

    public static function durationLabel(Product $p, string $locale): string
    {
        $v = rtrim(rtrim((string) $p->duration_value, '0'), '.');
        $unit = $locale === 'en' ? ['días' => 'days', 'horas' => 'hours', 'noches' => 'nights'][$p->duration_unit] ?? $p->duration_unit : $p->duration_unit;
        if ($v === '1') {
            $unit = $locale === 'en' ? rtrim($unit, 's') : ['días' => 'día', 'horas' => 'hora', 'noches' => 'noche'][$p->duration_unit] ?? $p->duration_unit;
        }

        return "{$v} {$unit}";
    }

    public static function freeCancellation(Product $p): bool
    {
        $text = mb_strtolower((string) $p->policies?->cancellation);

        return $text !== '' && (str_contains($text, 'sin penalidad') || str_contains($text, 'sin costo') || str_contains($text, 'gratuita') || str_contains($text, 'reembolso total'));
    }

    /** Salidas futuras abiertas o completas, por fecha. @return Collection<int, Departure> */
    private function openDepartures(Product $p): Collection
    {
        $all = $this->memo('departures', fn () => Departure::whereIn('status', ['abierta', 'completa'])->whereDate('departure_date', '>=', $this->clock->today()->toDateString())
            ->orderBy('departure_date')->orderBy('departure_time')->get()->groupBy('product_id'));

        return ($all[$p->id] ?? collect())->values();
    }

    /** Pasajeros vendidos por producto en reservas no canceladas (para «Más vendido»). @return array<string, int> */
    private function sales(): array
    {
        return $this->sales ??= BookedService::whereNotIn('status', BookedService::INACTIVE)->whereNotNull('product_id')
            ->selectRaw('product_id, count(distinct reservation_id) as n')->groupBy('product_id')->pluck('n', 'product_id')->map(fn ($n) => (int) $n)->all();
    }

    /** @return list<string> ids del destino (id o slug) y sus descendientes */
    private function destinationTree(string $idOrSlug): array
    {
        $id = preg_match('/^[0-9a-f-]{36}$/i', $idOrSlug) ? $idOrSlug : DestinationContent::where('slug', $idOrSlug)->value('destination_id');
        if (! $id) {
            $id = Destination::whereRaw('lower(name) = ?', [mb_strtolower(str_replace('-', ' ', $idOrSlug))])->orderBy('level')->value('id');
        }

        return $id ? Destination::where('path', 'like', "%/{$id}/%")->pluck('id')->push($id)->unique()->values()->all() : ['00000000-0000-0000-0000-000000000000'];
    }

    private function destinationOptions(string $locale): array
    {
        return $this->contents($locale)->map(fn (DestinationContent $c) => ['slug' => $c->slug, 'name' => $c->destination?->name])->values()->all();
    }

    /** @return Collection<int, DestinationContent> */
    private function contents(string $locale): Collection
    {
        $all = $this->memo('destinations', fn () => DestinationContent::with('destination')->orderByDesc('featured')->orderBy('slug')->get());
        $rows = $all->where('locale', $locale);

        return ($rows->isEmpty() ? $all->where('locale', 'es') : $all->where('locale', 'es')->map(fn ($es) => $rows->firstWhere('destination_id', $es->destination_id) ?? $es))->values();
    }

    private function destinationCard(DestinationContent $c, string $locale): array
    {
        $ids = $this->destinationTree($c->destination_id);
        $count = $this->published()->filter(fn (Product $p) => in_array($p->primary_destination_id, $ids, true) || $p->secondaryDestinations->whereIn('destination_id', $ids)->isNotEmpty())->count();
        $months = [];
        $start = $this->clock->today()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonths($i);
            $s = $this->pricing->season($m->copy()->day(15)->toDateString());
            $months[] = ['month' => $m->format('Y-m'), 'kind' => SitePricing::seasonRow($s)['kind'] ?? 'media', 'season' => $s?->name];
        }

        return ['slug' => $c->slug, 'name' => $c->destination?->name, 'tagline' => $c->tagline, 'altitude' => $c->altitude_text, 'featured' => $c->featured,
            'products_count' => $count, 'best_season' => $months, 'translated' => $c->locale === $locale];
    }

    private function routeOf(Product $p): array
    {
        $ids = array_values(array_filter(explode('/', (string) $p->primaryDestination?->path)));

        return Destination::whereIn('id', $ids)->orderBy('level')->pluck('name')->all();
    }

    /** §11.3: TouristTrip + Product (sin aggregateRating: solo con reseñas verificadas). */
    private function jsonLd(Product $p, array $card): array
    {
        return ['@context' => 'https://schema.org', '@type' => ['TouristTrip', 'Product'], 'name' => $card['name'], 'description' => $card['summary'], 'sku' => $p->ref,
            'brand' => ['@type' => 'Brand', 'name' => $this->tenant->tenant()?->trade_name], 'touristType' => $p->category?->name,
            'offers' => $card['from_price'] ? ['@type' => 'AggregateOffer', 'lowPrice' => $card['from_price'], 'priceCurrency' => $card['currency'],
                'availability' => 'https://schema.org/InStock', 'validFrom' => $this->clock->today()->toDateString()] : null];
    }
}
