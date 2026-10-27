<?php

namespace App\Services\Bookings;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Penalidad de cancelación (§4.6, PROC-028 · E2E-09). Funciones puras: los tramos se derivan del texto
 * de la política snapshot (§12-1) y la fórmula reproduce `cancelModal` del prototipo.
 */
final class Penalty
{
    /**
     * Tramos `[díasMínimos, %]` en orden descendente.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function tiersFor(?string $policy): array
    {
        $text = mb_strtolower((string) $policy);

        return match (true) {
            str_contains($text, '48 h') && str_contains($text, 'sin penalidad') => [[2, 0], [0, 100]],
            str_contains($text, '24 h') && str_contains($text, 'sin penalidad') => [[1, 0], [0, 100]],
            str_contains($text, 'tren:') => [[15, 0], [7, 50], [2, 50], [0, 100]],
            default => [[15, 0], [7, 50], [0, 100]],
        };
    }

    /** % del primer tramo con `días ≥ díasMínimos`; si ninguno, 100. */
    public static function pct(array $tiers, int $days): int
    {
        foreach ($tiers as [$min, $pct]) {
            if ($days >= $min) {
                return $pct;
            }
        }

        return 100;
    }

    /**
     * @param  list<array{code: string, name: string, product: ?string, price: string, days: int, policy: ?string}>  $active  servicios no cancelados/completados/rechazados
     * @param  list<string>  $consumed  precios de servicios completados
     * @return array{lines: list<array>, penalty_services: string, consumed: string, k: string, penalty: string, refund: string, owed: string}
     */
    public static function compute(string $total, string $paid, array $active, array $consumed): array
    {
        $lines = [];
        $penSvc = BigDecimal::zero();
        $allTot = BigDecimal::zero();
        foreach ($active as $s) {
            $tiers = self::tiersFor($s['policy']);
            $pct = self::pct($tiers, $s['days']);
            $pen = BigDecimal::of($s['price'])->multipliedBy($pct)->dividedBy(100, 2, RoundingMode::HalfUp);
            $penSvc = $penSvc->plus($pen);
            $allTot = $allTot->plus($s['price']);
            $lines[] = [
                'code' => $s['code'], 'name' => $s['name'], 'product' => $s['product'], 'days' => $s['days'], 'policy' => $s['policy'],
                'tiers' => $tiers, 'pct' => $pct, 'price' => (string) BigDecimal::of($s['price'])->toScale(2), 'penalty' => (string) $pen,
            ];
        }
        $used = BigDecimal::zero();
        foreach ($consumed as $price) {
            $used = $used->plus($price);
            $allTot = $allTot->plus($price);
        }
        if ($allTot->isZero()) {
            $allTot = BigDecimal::one();
        }
        $total = BigDecimal::of($total);
        $k = $total->dividedBy($allTot, 6, RoundingMode::HalfUp);
        $raw = $penSvc->plus($used)->multipliedBy($total)->dividedBy($allTot, 2, RoundingMode::HalfUp);
        $penalty = $raw->isGreaterThan($total) ? $total->toScale(2) : $raw;
        $paid = BigDecimal::of($paid);
        $refund = $paid->minus($penalty);
        $owed = $penalty->minus($paid);

        return [
            'lines' => $lines,
            'penalty_services' => (string) $penSvc->toScale(2),
            'consumed' => (string) $used->toScale(2),
            'k' => (string) $k,
            'penalty' => (string) $penalty->toScale(2),
            'refund' => (string) ($refund->isNegative() ? BigDecimal::zero()->toScale(2) : $refund->toScale(2)),
            'owed' => (string) ($owed->isNegative() ? BigDecimal::zero()->toScale(2) : $owed->toScale(2)),
        ];
    }
}
