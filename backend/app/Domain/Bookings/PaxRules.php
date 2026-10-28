<?php

namespace App\Domain\Bookings;

/** Reglas de pasajeros efectivas de un producto (spec 16 §3.4 + efectos de reglas apagadas). `null` = sin máximo. */
final class PaxRules
{
    public function __construct(
        public readonly int $adultMinAge,
        public readonly int $childMinAge,
        public readonly int $childMaxAge,
        public readonly int $infantMaxAge,
        public readonly bool $childPays,
        public readonly string $childPricePct,
        public readonly bool $infantAllowed,
        public readonly bool $infantPays,
        public readonly ?int $maxChildrenPerAdult,
        public readonly ?int $maxInfantsPerAdult,
        public readonly int $minAdults,
        public readonly ?string $note,
        /** producto | tenant */
        public readonly string $source,
    ) {}

    public function toArray(): array
    {
        return [
            'adult_min_age' => $this->adultMinAge, 'child_min_age' => $this->childMinAge, 'child_max_age' => $this->childMaxAge, 'infant_max_age' => $this->infantMaxAge,
            'child_pays' => $this->childPays, 'child_price_pct' => $this->childPricePct, 'infant_allowed' => $this->infantAllowed, 'infant_pays' => $this->infantPays,
            'max_children_per_adult' => $this->maxChildrenPerAdult, 'max_infants_per_adult' => $this->maxInfantsPerAdult, 'min_adults' => $this->minAdults,
            'note' => $this->note, 'source' => $this->source,
        ];
    }
}
