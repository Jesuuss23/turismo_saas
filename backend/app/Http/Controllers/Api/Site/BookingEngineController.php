<?php

namespace App\Http\Controllers\Api\Site;

use App\Domain\Bookings\AvailabilityCache;
use App\Domain\Bookings\BookingEngine;
use App\Domain\Bookings\QuoteSelection;
use App\Domain\Bookings\QuoteToken;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Catalog\BookingConfigController;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductTranslation;
use App\Services\Catalog\Booking\PublicBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * API pública del motor (spec 16 §6.1) para sitio, widget y CMS externos: disponibilidad (cacheada con ETag),
 * cotización con `quote_token` firmado y reserva idempotente. El navegador nunca es autoridad del precio.
 */
class BookingEngineController extends Controller
{
    public function availability(Request $request, string $id): JsonResponse
    {
        $product = $this->product($id);
        [$from, $to] = BookingConfigController::window($request);
        $seats = max(0, min(500, (int) $request->query('seats', 0)));
        $engine = app(BookingEngine::class);
        $cache = app(AvailabilityCache::class);
        $today = $engine->today();
        $etag = '"'.$product->id.':'.$cache->version($product).':'.$today.'"';
        if ($request->header('If-None-Match') === $etag && $seats === 0) {
            return response()->json(null, 304)->setEtag(trim($etag, '"'));
        }
        // Meses cacheados con seats=0; `seats` se aplica en memoria sobre `left` (§8.3).
        $days = [];
        for ($m = CarbonImmutable::parse($from)->startOfMonth(); $m->toDateString() <= $to; $m = $m->addMonth()) {
            $month = $cache->month($product, $m->format('Y-m'), $today, fn () => $engine->month($product, $m->year, $m->month)->map->toPublic()->all());
            foreach ($month as $d) {
                if ($d['date'] >= $from && $d['date'] <= $to) {
                    $days[] = self::withSeats($d, $seats);
                }
            }
        }

        $legend = $engine->legend($product, $from, $to);

        return response()->json(['data' => [
            'product' => ['id' => $product->id, 'ref' => $product->ref, 'name' => $product->name],
            'from' => $from, 'to' => $to, 'seats' => $seats, 'days' => $days,
            'legend' => $legend,
            'next_available' => $engine->nextAvailable($product, max(1, $seats)),
            'rules_applied' => $engine->rulesApplied($product),
        ]])->setEtag(trim($etag, '"'));
    }

    public function quote(Request $request): JsonResponse
    {
        $product = $this->product((string) $request->input('product_id', ''));
        $sel = QuoteSelection::fromArray($request->all());
        $quote = app(BookingEngine::class)->quote($product, $sel);

        return response()->json(['data' => $quote->toArray() + [
            'quote_token' => app(QuoteToken::class)->sign($product->id, $sel, $quote), 'quote_ttl_minutes' => QuoteToken::TTL_MINUTES,
        ]]);
    }

    public function book(Request $request): JsonResponse
    {
        return response()->json(['data' => app(PublicBookingService::class)->book($request->all())], 201);
    }

    /** Producto comercializable por uuid, correlativo (PRD-011) o slug. */
    private function product(string $id): Product
    {
        $q = Product::query()->where('status', Product::ACTIVE)->where('is_bookable', true);
        $product = match (true) {
            Str::isUuid($id) => $q->whereKey($id)->first(),
            (bool) preg_match('/^PRD-\d+$/', $id) => $q->where('ref', $id)->first(),
            default => $q->where(fn ($w) => $w->where('slug', $id)->orWhereIn('id', ProductTranslation::where('slug', $id)->select('product_id')))->first(),
        };

        return $product ?? throw ApiException::notFound();
    }

    /** Aplica los asientos pedidos sobre un día cacheado con seats=0. */
    private static function withSeats(array $d, int $seats): array
    {
        if ($seats > 0 && $d['status'] === 'disponible' && $d['left'] !== null && $seats > $d['left']) {
            return ['status' => 'sin_cupo', 'label' => "Solo {$d['left']} cupo(s)"] + $d;
        }

        return $d;
    }
}
