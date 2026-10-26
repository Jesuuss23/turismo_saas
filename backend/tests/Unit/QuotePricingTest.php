<?php

use App\Services\PricingService;
use App\Services\Quotes\QuotePolicy;
use App\Services\Quotes\QuotePricing;

/*
| T-Q01: `quoteTotals` reproduce COT-00191 v3 y PRC-01 reproduce el ejemplo de temporada (spec 03 §5.1).
*/

it('T-Q01: quoteTotals de COT-00191 v3 → total 243 144.90, margen 12.35 % y requiere aprobación interna', function () {
    $totals = (new PricingService)->quoteTotals([
        ['unitCost' => 2900, 'unitPrice' => 3450, 'qty' => 1, 'pax' => 60],
        ['unitCost' => 110, 'unitPrice' => 165, 'qty' => 1, 'pax' => 60],
    ], 5, 18);

    expect($totals)->toMatchArray([
        'cost' => '180600.00', 'sale' => '216900.00', 'discount' => '10845.00', 'net' => '206055.00',
        'tax' => '37089.90', 'total' => '243144.90', 'profit' => '25455.00', 'marginPct' => '12.35',
    ])->and(QuotePolicy::reasons(5, $totals['marginPct']))->toBe(['margen 12.4% < 15%']);
});

it('T-Q01: PRC-01 TAR-001 (USD 268/345, base ×1.00) el 30/12 con Año Nuevo ×1.40 en PEN → 1 806.42 / 1 403.25', function () {
    expect(QuotePricing::suggestAmount(345, '1.00', '1.40', 'USD', 'PEN', '3.74'))->toBe('1806.42')
        ->and(QuotePricing::suggestAmount(268, '1.00', '1.40', 'USD', 'PEN', '3.74'))->toBe('1403.25')
        // Tarifa cargada en temporada alta (×1.20) usada en una fecha de temporada baja (×0.90): f = 0.75.
        ->and(QuotePricing::suggestAmount(400, '1.20', '0.90', 'USD', 'USD', '3.74'))->toBe('300.00')
        // PRC-06: PEN → USD divide por el tipo de cambio.
        ->and(QuotePricing::convertMoney('1806.42', 'PEN', 'USD', '3.74'))->toBe('483.00');
});

it('QTE-BR-11: descuento > 5 % o margen < 15 % requieren aprobación; tope 10 %', function () {
    expect(QuotePolicy::reasons(5, 15))->toBe([])
        ->and(QuotePolicy::reasons('8', '20'))->toBe(['descuento 8.0% > 5%'])
        ->and(QuotePolicy::overCap(10))->toBeFalse()
        ->and(QuotePolicy::overCap(10.5))->toBeTrue();
});
