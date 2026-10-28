<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Domain\Bookings\BookingEngine;
use App\Domain\Bookings\QuoteSelection;
use App\Exceptions\ApiException;
use App\Models\Catalog\AccommodationHotel;
use App\Models\Catalog\AccommodationLeg;
use App\Models\Catalog\AvailabilityRange;
use App\Models\Catalog\ProductAddon;
use App\Services\Catalog\Booking\AvailabilityRangeService;
use App\Services\Catalog\Booking\BookingConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Spec 16 §6.2: pestaña «Reservas online» del Product 360 (permisos por ruta, §7). */
class BookingConfigController extends CatalogController
{
    private function config(): BookingConfigService
    {
        return app(BookingConfigService::class);
    }

    private function ranges(): AvailabilityRangeService
    {
        return app(AvailabilityRangeService::class);
    }

    public function show(Request $request, string $ref): JsonResponse
    {
        return $this->ok($this->config()->summary($this->product($ref), $this->actor($request)));
    }

    // --- disponibilidad ---

    public function availability(Request $request, string $ref): JsonResponse
    {
        $product = $this->product($ref);
        [$from, $to] = self::window($request);
        $engine = app(BookingEngine::class);
        $seats = max(0, min(500, (int) $request->query('seats', 0)));

        return $this->ok([
            'from' => $from, 'to' => $to, 'seats' => $seats,
            'days' => $engine->days($product, $from, $to, $seats)->map->toArray()->all(),
            'legend' => $engine->legend($product, $from, $to),
            'next_available' => $engine->nextAvailable($product, max(1, $seats)),
            'rules_applied' => $engine->rulesApplied($product),
        ]);
    }

    public function listRanges(string $ref): JsonResponse
    {
        $product = $this->product($ref);

        return $this->ok(AvailabilityRange::where('product_id', $product->id)->orderBy('date_from')->orderBy('code')->get()->map(fn ($r) => $this->config()->range($r))->all());
    }

    public function storeRange(Request $request, string $ref): JsonResponse
    {
        $product = $this->product($ref);
        $range = $this->ranges()->create($product, $request->all(), $this->actor($request), $request->boolean('confirm_sales'));

        return $this->created($this->config()->range($range) + ['message' => "Rango {$range->code} creado"]);
    }

    public function updateRange(Request $request, string $ref, string $range): JsonResponse
    {
        $product = $this->product($ref);
        /** @var AvailabilityRange $model */
        $model = $this->child(AvailabilityRange::class, $range, 'product_id', $product->id);
        if ($request->has('lock_version') && (int) $request->input('lock_version') !== $model->lock_version) {
            throw ApiException::conflict('STALE_VERSION', 'El rango cambió mientras lo editaba. Recargue e intente de nuevo.');
        }
        $saved = $this->ranges()->update($product, $model, $request->except('lock_version'), $this->actor($request), $request->boolean('confirm_sales'));

        return $this->ok($this->config()->range($saved));
    }

    public function destroyRange(Request $request, string $ref, string $range): JsonResponse
    {
        $product = $this->product($ref);
        $this->ranges()->delete($product, $this->child(AvailabilityRange::class, $range, 'product_id', $product->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    public function closeDates(Request $request, string $ref): JsonResponse
    {
        $product = $this->product($ref);
        $result = $this->ranges()->closeDates($product, (array) $request->input('dates', []), (string) $request->input('status', 'cerrado'), $request->input('note'),
            $this->actor($request), $request->boolean('confirm_sales'));

        return $this->created(['dates' => $result['dates'], 'message' => $result['message'], 'ranges' => array_map(fn ($r) => $this->config()->range($r), $result['ranges'])]);
    }

    public function operation(Request $request, string $ref): JsonResponse
    {
        $product = $this->ranges()->operation($this->product($ref), $request->all(), $this->actor($request));

        return $this->ok($this->config()->operation($product));
    }

    // --- pasajeros ---

    public function pax(string $ref): JsonResponse
    {
        return $this->ok($this->config()->paxConfig($this->product($ref)));
    }

    public function savePax(Request $request, string $ref): JsonResponse
    {
        $result = $this->config()->savePax($this->product($ref), $request->all(), $this->actor($request));

        return $this->ok($result['pax'], $result['warnings'] ? ['warnings' => $result['warnings']] : []);
    }

    public function resetPax(Request $request, string $ref): JsonResponse
    {
        return $this->ok($this->config()->resetPax($this->product($ref), $this->actor($request)));
    }

    /** Simulador: `validatePax` + líneas base de `quote` + clasificación de edades. */
    public function simulatePax(Request $request, string $ref): JsonResponse
    {
        $product = $this->product($ref);
        $engine = app(BookingEngine::class);
        $sel = QuoteSelection::fromArray(['pax' => ['adt' => $request->input('adt', 1), 'chd' => $request->input('chd', 0), 'inf' => $request->input('inf', 0)],
            'ages' => (array) $request->input('ages', []), 'date' => $request->input('date'), 'channel' => 'Directo']);
        $quote = $engine->quote($product, $sel);

        return $this->ok([
            'validation' => $engine->validatePax($product, $sel->adt, $sel->chd, $sel->inf, $sel->ages),
            'lines' => array_values(array_filter($quote->lines, fn ($l) => $l['kind'] === 'base')),
            'subtotal' => $quote->subtotal, 'currency' => $quote->currency,
        ]);
    }

    // --- cotización interna (vista previa de alojamiento y pago) ---

    public function quote(Request $request, string $ref): JsonResponse
    {
        $product = $this->product($ref);

        return $this->ok(app(BookingEngine::class)->quote($product, QuoteSelection::fromArray($request->all()))->toArray());
    }

    // --- adicionales ---

    public function addons(string $ref): JsonResponse
    {
        return $this->ok($this->config()->addons($this->product($ref)));
    }

    public function storeAddon(Request $request, string $ref): JsonResponse
    {
        $product = $this->product($ref);
        $addon = $this->config()->saveAddon($product, $request->all(), $this->actor($request));

        return $this->created(collect($this->config()->addons($product))->firstWhere('id', $addon->id));
    }

    public function updateAddon(Request $request, string $ref, string $addon): JsonResponse
    {
        $product = $this->product($ref);
        $saved = $this->config()->saveAddon($product, $request->all(), $this->actor($request), $this->child(ProductAddon::class, $addon, 'product_id', $product->id));

        return $this->ok(collect($this->config()->addons($product))->firstWhere('id', $saved->id));
    }

    public function destroyAddon(Request $request, string $ref, string $addon): JsonResponse
    {
        $product = $this->product($ref);
        $this->config()->deleteAddon($product, $this->child(ProductAddon::class, $addon, 'product_id', $product->id), $this->actor($request));

        return $this->ok(['deleted' => true]);
    }

    // --- alojamiento ---

    public function saveAccommodation(Request $request, string $ref): JsonResponse
    {
        return $this->ok($this->config()->saveAccommodation($this->product($ref), $request->all(), $this->actor($request)));
    }

    public function storeLeg(Request $request, string $ref): JsonResponse
    {
        $product = $this->product($ref);
        $this->config()->saveLeg($product, $request->all(), $this->actor($request));

        return $this->created($this->config()->accommodation($product));
    }

    public function updateLeg(Request $request, string $ref, string $leg): JsonResponse
    {
        $product = $this->product($ref);
        $this->config()->saveLeg($product, $request->all(), $this->actor($request), $this->child(AccommodationLeg::class, $leg, 'product_id', $product->id));

        return $this->ok($this->config()->accommodation($product));
    }

    public function destroyLeg(Request $request, string $ref, string $leg): JsonResponse
    {
        $product = $this->product($ref);
        $this->config()->deleteLeg($product, $this->child(AccommodationLeg::class, $leg, 'product_id', $product->id), $this->actor($request));

        return $this->ok($this->config()->accommodation($product));
    }

    public function reorderLegs(Request $request, string $ref): JsonResponse
    {
        $product = $this->product($ref);
        $this->config()->reorderLegs($product, array_map('strval', (array) $request->input('ids', [])), $this->actor($request));

        return $this->ok($this->config()->accommodation($product));
    }

    public function storeHotel(Request $request, string $ref, string $leg): JsonResponse
    {
        $product = $this->product($ref);
        $legModel = $this->child(AccommodationLeg::class, $leg, 'product_id', $product->id);
        $this->config()->saveHotel($product, $legModel, $request->all(), $this->actor($request));

        return $this->created($this->config()->accommodation($product));
    }

    public function updateHotel(Request $request, string $ref, string $leg, string $hotel): JsonResponse
    {
        $product = $this->product($ref);
        $legModel = $this->child(AccommodationLeg::class, $leg, 'product_id', $product->id);
        $this->config()->saveHotel($product, $legModel, $request->all(), $this->actor($request), $this->child(AccommodationHotel::class, $hotel, 'leg_id', $legModel->id));

        return $this->ok($this->config()->accommodation($product));
    }

    public function destroyHotel(Request $request, string $ref, string $leg, string $hotel): JsonResponse
    {
        $product = $this->product($ref);
        $legModel = $this->child(AccommodationLeg::class, $leg, 'product_id', $product->id);
        $this->config()->deleteHotel($product, $legModel, $this->child(AccommodationHotel::class, $hotel, 'leg_id', $legModel->id), $this->actor($request));

        return $this->ok($this->config()->accommodation($product));
    }

    // --- pago, recojo y reglas ---

    public function payment(string $ref): JsonResponse
    {
        return $this->ok($this->config()->payment($this->product($ref)));
    }

    public function savePayment(Request $request, string $ref): JsonResponse
    {
        $result = $this->config()->savePayment($this->product($ref), $request->all(), $this->actor($request));

        return $this->ok($result['payment'], $result['warnings'] ? ['warnings' => $result['warnings']] : []);
    }

    public function pickup(string $ref): JsonResponse
    {
        return $this->ok($this->config()->pickup($this->product($ref)));
    }

    public function savePickup(Request $request, string $ref): JsonResponse
    {
        return $this->ok($this->config()->savePickup($this->product($ref), $request->all(), $this->actor($request)));
    }

    public function overrides(string $ref): JsonResponse
    {
        return $this->ok($this->config()->overrides($this->product($ref)));
    }

    public function saveOverride(Request $request, string $ref, string $rule): JsonResponse
    {
        $result = $this->config()->saveOverride($this->product($ref), strtoupper($rule), $request->only(['enabled', 'params']), $this->actor($request));

        return $this->ok($result['rule'], $result['warnings'] ? ['warnings' => $result['warnings']] : []);
    }

    public function resetOverride(Request $request, string $ref, string $rule): JsonResponse
    {
        return $this->ok($this->config()->resetOverride($this->product($ref), strtoupper($rule), $this->actor($request)));
    }

    /** `from`/`to` (máx. 92 días); por defecto el mes en curso. @return array{0: string, 1: string} */
    public static function window(Request $request): array
    {
        $parse = function (?string $v, string $field) {
            if ($v === null || $v === '') {
                return null;
            }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if (! $d || $d->format('Y-m-d') !== $v) {
                throw ApiException::invalid($field, 'Fecha inválida (AAAA-MM-DD).');
            }

            return $v;
        };
        $from = $parse($request->query('from'), 'from') ?? now()->startOfMonth()->toDateString();
        $to = $parse($request->query('to'), 'to') ?? CarbonImmutable::parse($from)->endOfMonth()->toDateString();
        if ($to < $from) {
            throw ApiException::invalid('to', 'La fecha final debe ser igual o posterior a la inicial.');
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= BookingEngine::MAX_DAYS) {
            throw ApiException::invalid('to', 'Máximo 92 días por consulta.');
        }

        return [$from, $to];
    }
}
