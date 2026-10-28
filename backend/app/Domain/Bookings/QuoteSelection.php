<?php

namespace App\Domain\Bookings;

/** `sel` del motor (spec 16 §6.1): lo que el cliente eligió. Inmutable; se normaliza desde el cuerpo de la API. */
final class QuoteSelection
{
    public function __construct(
        public readonly ?string $date,
        public readonly int $adt,
        public readonly int $chd = 0,
        public readonly int $inf = 0,
        /** @var list<array{id: string, qty: int}> */
        public readonly array $addons = [],
        /** @var list<array{leg_id: string, hotel_id: string, rooms: list<array{type: string, qty: int}>}> */
        public readonly array $lodging = [],
        /** @var list<string> ids de opciones de personalización */
        public readonly array $options = [],
        public readonly ?string $currency = null,
        public readonly ?string $paymentMode = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $promoCode = null,
        /** @var list<string> claves de líneas quitadas en la vista previa */
        public readonly array $removed = [],
        public readonly string $channel = 'E-commerce',
        /** @var list<int> edades declaradas (opcional) */
        public readonly array $ages = [],
    ) {}

    public static function fromArray(array $d): self
    {
        $pax = (array) ($d['pax'] ?? []);
        $int = fn ($v) => max(0, (int) $v);

        return new self(
            date: isset($d['date']) && $d['date'] !== '' ? substr((string) $d['date'], 0, 10) : null,
            adt: $int($pax['adt'] ?? $d['adt'] ?? 0),
            chd: $int($pax['chd'] ?? $d['chd'] ?? 0),
            inf: $int($pax['inf'] ?? $d['inf'] ?? 0),
            addons: array_values(array_map(fn ($a) => ['id' => (string) ($a['id'] ?? ''), 'qty' => $int($a['qty'] ?? 1)], array_filter((array) ($d['addons'] ?? []), 'is_array'))),
            lodging: array_values(array_map(fn ($l) => [
                'leg_id' => (string) ($l['leg_id'] ?? ''), 'hotel_id' => (string) ($l['hotel_id'] ?? ''),
                'rooms' => array_values(array_map(fn ($r) => ['type' => (string) ($r['type'] ?? ''), 'qty' => $int($r['qty'] ?? 0)], array_filter((array) ($l['rooms'] ?? []), 'is_array'))),
            ], array_filter((array) ($d['lodging'] ?? []), 'is_array'))),
            options: array_values(array_map('strval', array_filter((array) ($d['options'] ?? []), 'is_scalar'))),
            currency: isset($d['currency']) ? strtoupper((string) $d['currency']) : null,
            paymentMode: isset($d['payment_mode']) ? (string) $d['payment_mode'] : null,
            paymentMethod: isset($d['payment_method']) ? (string) $d['payment_method'] : null,
            promoCode: isset($d['promo_code']) && trim((string) $d['promo_code']) !== '' ? strtoupper(trim((string) $d['promo_code'])) : null,
            removed: array_values(array_map('strval', array_filter((array) ($d['removed'] ?? []), 'is_scalar'))),
            channel: in_array($d['channel'] ?? null, ['Directo', 'E-commerce', 'B2B', 'Corporativo'], true) ? $d['channel'] : 'E-commerce',
            ages: array_values(array_map('intval', array_filter((array) ($d['ages'] ?? []), 'is_numeric'))),
        );
    }

    public function seats(): int
    {
        return $this->adt + $this->chd;
    }

    public function toArray(): array
    {
        return [
            'date' => $this->date, 'pax' => ['adt' => $this->adt, 'chd' => $this->chd, 'inf' => $this->inf], 'addons' => $this->addons, 'lodging' => $this->lodging,
            'options' => $this->options, 'currency' => $this->currency, 'payment_mode' => $this->paymentMode, 'payment_method' => $this->paymentMethod,
            'promo_code' => $this->promoCode, 'removed' => $this->removed, 'channel' => $this->channel, 'ages' => $this->ages,
        ];
    }
}
