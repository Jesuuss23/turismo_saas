<?php

use App\Services\PricingService;

/*
| X-18: casos calculados a mano con la fórmula de §5.1 (BigDecimal, redondeo 0.10).
| Pendiente: contrastar con salidas reales de Pricing.calc del prototipo cuando estén disponibles.
*/

it('calc aplica markup, descuento, IGV, redondeo 0.10, comisión, utilidad y margen', function () {
    $r = (new PricingService)->calc([
        'cost' => '100', 'extra' => '20', 'markupPct' => '25', 'markupFixed' => '5',
        'discountPct' => '10', 'discountFixed' => '0', 'taxPct' => '18', 'commissionPct' => '10',
    ]);

    expect($r)->toBe([
        'base' => '120.00', 'markup' => '35.00', 'subtotal' => '155.00', 'discount' => '15.50',
        'net' => '139.50', 'tax' => '25.11', 'total' => '164.60', 'commission' => '13.95',
        'profit' => '5.55', 'marginPct' => '3.98',
    ]);
});

it('calc redondea el total al múltiplo de 0.10 más cercano', function () {
    $pricing = new PricingService;

    expect($pricing->calc(['cost' => '10.04'])['total'])->toBe('10.00')
        ->and($pricing->calc(['cost' => '10.05'])['total'])->toBe('10.10')
        ->and($pricing->calc(['cost' => '10.05', 'rounding' => '1'])['total'])->toBe('10.00')
        ->and($pricing->calc(['cost' => '10.05', 'rounding' => '0'])['total'])->toBe('10.05');
});

it('calc con neto cero no divide por cero', function () {
    expect((new PricingService)->calc([])['marginPct'])->toBe('0.00');
});

it('quoteTotals suma costo × qty × pax y precio × qty × pax', function () {
    $r = (new PricingService)->quoteTotals([
        ['unitCost' => '50', 'unitPrice' => '80', 'qty' => 2, 'pax' => 3],
        ['unitCost' => '10', 'unitPrice' => '15', 'qty' => 1, 'pax' => 3],
    ], 5, 18);

    expect($r)->toBe([
        'cost' => '330.00', 'sale' => '525.00', 'discount' => '26.25', 'net' => '498.75',
        'tax' => '89.78', 'total' => '588.53', 'profit' => '168.75', 'marginPct' => '33.83',
    ]);
});

it('toPen convierte USD con el TC congelado y deja PEN igual', function () {
    $pricing = new PricingService;

    expect($pricing->toPen('100', 'USD', '3.7520'))->toBe('375.20')
        ->and($pricing->toPen('100', 'PEN', '3.7520'))->toBe('100.00');
});

it('seasonFor elige la temporada activa de menor prioridad', function () {
    $seasons = [
        ['name' => 'Alta', 'status' => 'activa', 'start' => '2026-06-01', 'end' => '2026-08-31', 'priority' => 5],
        ['name' => 'Fiestas Patrias', 'status' => 'activa', 'start' => '2026-07-27', 'end' => '2026-07-30', 'priority' => 0],
        ['name' => 'Inactiva', 'status' => 'inactiva', 'start' => '2026-07-01', 'end' => '2026-07-31', 'priority' => 0],
    ];
    $pricing = new PricingService;

    expect($pricing->seasonFor('2026-07-28', $seasons)['name'])->toBe('Fiestas Patrias')
        ->and($pricing->seasonFor('2026-07-10', $seasons)['name'])->toBe('Alta')
        ->and($pricing->seasonFor('2026-12-10', $seasons))->toBeNull();
});
