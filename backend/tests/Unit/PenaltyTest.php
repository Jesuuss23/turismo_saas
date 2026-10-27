<?php

use App\Services\Bookings\Penalty;

it('T-R12: tramos 48 h / 24 h / Tren / estándar con días 0, 1, 2, 6, 7, 14, 15', function () {
    $days = [0, 1, 2, 6, 7, 14, 15];
    $pcts = fn (string $text) => array_map(fn ($d) => Penalty::pct(Penalty::tiersFor($text), $d), $days);

    expect($pcts('Sin penalidad hasta 48 h antes. 100% después.'))->toBe([100, 100, 0, 0, 0, 0, 0])
        ->and($pcts('Sin penalidad hasta 24 h antes; luego 100%.'))->toBe([100, 0, 0, 0, 0, 0, 0])
        ->and($pcts('Tren: 50 % hasta 7 días antes; sin reembolso dentro de 48 h.'))->toBe([100, 100, 50, 50, 50, 50, 0])
        ->and($pcts('Sin penalidad hasta 15 días antes. 50% entre 14 y 7 días. 100% dentro de 7 días o no-show.'))->toBe([100, 100, 100, 100, 50, 50, 0])
        ->and(Penalty::pct([], 3))->toBe(100);
});

it('T-R10: ejemplo RES-2026-000348 → penalidad 53 480, reembolso 0, por cobrar 30 036', function () {
    $std = 'Sin penalidad hasta 15 días antes. 50% entre 14 y 7 días. 100% dentro de 7 días o no-show.';
    $active = [
        ['code' => 'T1', 'name' => 'Traslado', 'product' => 'PRD-005', 'price' => '1600', 'days' => 2, 'policy' => 'Sin penalidad hasta 24 h antes'],
        ['code' => 'T2', 'name' => 'Traslado', 'product' => 'PRD-005', 'price' => '1600', 'days' => 6, 'policy' => 'Sin penalidad hasta 24 h antes'],
        ['code' => 'H', 'name' => 'Humantay', 'product' => 'PRD-004', 'price' => '1930', 'days' => 5, 'policy' => 'Sin penalidad hasta 48 h antes'],
        ...array_map(fn ($p, $i) => ['code' => "S{$i}", 'name' => 'Servicio', 'product' => 'PRD-002', 'price' => $p, 'days' => 2 + $i % 3, 'policy' => $std],
            ['23900', '1660', '5120', '17930', '4870'], range(0, 4)),
    ];
    $r = Penalty::compute('58610', '23444', $active, []);

    expect($r)->toMatchArray(['penalty_services' => '53480.00', 'k' => '1.000000', 'penalty' => '53480.00', 'refund' => '0.00', 'owed' => '30036.00'])
        ->and(array_column($r['lines'], 'pct'))->toBe([0, 0, 0, 100, 100, 100, 100, 100]);
});
