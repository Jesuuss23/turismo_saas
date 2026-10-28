<?php

namespace App\Domain\Bookings;

/** Estado de un día de un producto (spec 16 §4.1), calculado, no persistido. */
final class DayStatus
{
    public const LABELS = [
        'pasado' => 'Fecha pasada', 'sin_configurar' => 'Consultar', 'fuera_de_plazo' => 'Fuera de plazo', 'no_opera' => 'No opera',
        'cerrado' => 'Cerrado', 'agotado' => 'Agotado', 'sin_cupo' => 'Sin cupo suficiente', 'disponible' => 'Disponible',
    ];

    public function __construct(
        public readonly string $date,
        public readonly string $status,
        public readonly string $label,
        public readonly ?string $note = null,
        public readonly ?int $left = null,
        public readonly ?int $cap = null,
        public readonly ?int $sold = null,
        public readonly ?int $held = null,
        public readonly ?string $range = null,
        public readonly ?string $departure = null,
    ) {}

    public function bookable(int $seats = 1): bool
    {
        return $this->status === 'disponible' && ($this->left === null || $this->left >= max(1, $seats));
    }

    /** Vista pública (§6.1). */
    public function toPublic(): array
    {
        return ['date' => $this->date, 'status' => $this->status, 'label' => $this->label, 'note' => $this->note, 'left' => $this->left];
    }

    /** Vista interna: además capacidad, vendidos, holds, rango y salida. */
    public function toArray(): array
    {
        return $this->toPublic() + ['cap' => $this->cap, 'sold' => $this->sold, 'held' => $this->held, 'range' => $this->range, 'departure' => $this->departure];
    }
}
