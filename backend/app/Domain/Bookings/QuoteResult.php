<?php

namespace App\Domain\Bookings;

/**
 * Contrato `quote` (spec 16 §6.1): líneas {key, kind, label, qty, unit, amount, removable, removed, meta}, ajustes {key, label, amount},
 * subtotal, total, modalidades y montos de pago, habitaciones/noches y advertencias {code, message, blocking}.
 */
final class QuoteResult
{
    public function __construct(
        public readonly array $lines,
        public readonly array $adjustments,
        public readonly string $subtotal,
        public readonly string $total,
        public readonly string $currency,
        public readonly string $fxRate,
        public readonly array $modes,
        public readonly string $paymentMode,
        public readonly string $depositPct,
        public readonly string $deposit,
        public readonly string $balance,
        public readonly ?string $balanceDue,
        public readonly ?int $holdHours,
        public readonly int $rooms,
        public readonly int $nights,
        public readonly string $lodgingTotal,
        public readonly array $warnings,
        public readonly array $rulesApplied,
        public readonly array $meta = [],
    ) {}

    public function ok(): bool
    {
        return ! collect($this->warnings)->contains(fn ($w) => $w['blocking']);
    }

    public function toArray(): array
    {
        return [
            'lines' => $this->lines, 'adjustments' => $this->adjustments, 'subtotal' => $this->subtotal, 'total' => $this->total,
            'currency' => $this->currency, 'fx_rate' => $this->fxRate, 'modes' => $this->modes, 'payment_mode' => $this->paymentMode,
            'deposit_pct' => $this->depositPct, 'deposit' => $this->deposit, 'balance' => $this->balance, 'balance_due' => $this->balanceDue,
            'hold_hours' => $this->holdHours, 'rooms' => $this->rooms, 'nights' => $this->nights, 'lodging_total' => $this->lodgingTotal,
            'warnings' => $this->warnings, 'ok' => $this->ok(), 'rules_applied' => $this->rulesApplied,
        ] + $this->meta;
    }
}
