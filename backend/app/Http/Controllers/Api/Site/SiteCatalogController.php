<?php

namespace App\Http\Controllers\Api\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\CartService;
use App\Services\Site\SiteCatalog;
use App\Services\Site\SitePricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo y contenido públicos del sitio (spec 15 §6.1): tenant por host / `X-Tenant-Slug`; idioma (`locale`) y moneda (`currency`) por query.
 * Respuestas cacheables (`Cache-Control`); precio y cupos siempre se recalculan.
 */
class SiteCatalogController extends Controller
{
    public function __construct(private readonly SiteCatalog $catalog, private readonly SitePricing $pricing) {}

    public static function locale(Request $request): string
    {
        return in_array($request->query('locale', $request->header('X-Locale')), ['es', 'en'], true) ? $request->query('locale', $request->header('X-Locale')) : 'es';
    }

    public static function currency(Request $request): string
    {
        $c = strtoupper((string) $request->query('currency', $request->input('currency', 'PEN')));

        return in_array($c, SitePricing::CURRENCIES, true) ? $c : 'PEN';
    }

    public function site(Request $request): JsonResponse
    {
        return $this->cached(['data' => $this->catalog->site(self::locale($request))], 600);
    }

    public function destinations(Request $request): JsonResponse
    {
        return $this->cached(['data' => $this->catalog->destinations(self::locale($request), self::currency($request))], 3600);
    }

    public function destination(Request $request, string $slug): JsonResponse
    {
        return $this->cached(['data' => $this->catalog->destination($slug, self::locale($request), self::currency($request))], 3600);
    }

    public function products(Request $request): JsonResponse
    {
        return $this->cached($this->catalog->products($request->query(), self::locale($request), self::currency($request)), 300);
    }

    public function product(Request $request, string $slug): JsonResponse
    {
        return $this->cached(['data' => $this->catalog->product($slug, self::locale($request), self::currency($request))], 300);
    }

    public function calendar(Request $request, string $slug): JsonResponse
    {
        return $this->cached(['data' => $this->catalog->calendar($slug, (string) $request->query('month', ''), max(1, (int) $request->query('pax', 1)), self::currency($request))], 60);
    }

    public function departures(Request $request, string $slug): JsonResponse
    {
        $p = $this->catalog->find($slug);

        return $this->cached(['data' => $this->catalog->departures($p, $request->query('from'), $request->query('to'))], 60);
    }

    public function guaranteed(Request $request): JsonResponse
    {
        return $this->cached(['data' => $this->catalog->guaranteed(min(12, max(1, (int) $request->query('limit', 6))), self::locale($request), self::currency($request))], 60);
    }

    public function offers(Request $request): JsonResponse
    {
        return $this->cached(['data' => $this->catalog->offers(self::locale($request), self::currency($request))], 300);
    }

    public function posts(): JsonResponse
    {
        return $this->cached(['data' => $this->catalog->posts()], 600);
    }

    public function post(string $slug): JsonResponse
    {
        return $this->cached(['data' => $this->catalog->page($slug, ['Artículo'])], 600);
    }

    public function page(string $slug): JsonResponse
    {
        return $this->cached(['data' => $this->catalog->page($slug, ['Página', 'Página legal'])], 600);
    }

    /** POST /public/price-quote (SIT-BR-02/08/10/11/12): precio de una selección en la moneda elegida; el servidor manda (RT-5). */
    public function quote(Request $request): JsonResponse
    {
        $p = $this->catalog->find((string) $request->input('product', ''));
        $date = (string) $request->input('date', '');
        $currency = self::currency($request);
        $sel = ['date' => $date, 'adults' => (int) $request->input('adults', 1), 'children' => (int) $request->input('children', 0), 'infants' => (int) $request->input('infants', 0),
            'options' => (array) $request->input('options', []), 'extras' => (array) $request->input('extras', [])];
        app(CartService::class)->validatePax($p, $sel['adults'], $sel['children'], $sel['infants']);
        $q = $this->pricing->line($p, $sel, $request->input('promo_code'));
        $conv = fn (string $v) => $this->pricing->convert($v, $q['currency'], $currency);
        $status = $this->catalog->dayStatus($p, $date, $sel['adults'] + $sel['children']);

        return response()->json(['data' => [
            'currency' => $currency, 'rate_currency' => $q['currency'], 'season' => $q['season'], 'adults' => $q['adults'], 'children' => $q['children'], 'infants' => $q['infants'],
            'unit_adult' => $conv($q['unit_adult']), 'unit_child' => $conv($q['unit_child']), 'units' => $q['units'], 'options' => $q['options'],
            'subtotal' => $conv($q['subtotal']), 'discount' => $conv($q['discount']), 'discount_label' => $q['discount_label'],
            'extras' => array_map(fn ($e) => ['code' => $e['code'], 'label' => $e['label'], 'amount' => $conv($e['amount_line'])], $q['extras']),
            'extras_total' => $conv($q['extras_total']), 'total' => $conv($q['total']), 'list_price' => $q['list_price'] ? $conv($q['list_price']) : null,
            'availability' => $status, 'valid_until' => now()->addMinutes(15)->toIso8601String(), 'fx' => ['usd_pen' => $this->pricing->usdRate()],
        ]]);
    }

    /** POST /public/availability (SIT-BR-04). */
    public function availability(Request $request): JsonResponse
    {
        $p = $this->catalog->find((string) $request->input('product', ''));

        return response()->json(['data' => $this->catalog->dayStatus($p, (string) $request->input('date', ''), max(1, (int) $request->input('pax', 1)))]);
    }

    private function cached(array $body, int $seconds): JsonResponse
    {
        return response()->json($body)->header('Cache-Control', "public, max-age={$seconds}, stale-while-revalidate=60");
    }
}
