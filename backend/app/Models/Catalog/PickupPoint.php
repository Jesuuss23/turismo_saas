<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Punto de partida o recojo del producto (spec 16 §3.8). */
class PickupPoint extends TenantModel
{
    public const KINDS = ['aeropuerto', 'hotel', 'punto_encuentro', 'terminal', 'por_cuenta'];

    protected $table = 'catalog_pickup_points';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'requires_address' => 'boolean'];
    }

    /** Tipo derivado del texto (el prototipo guarda solo la etiqueta). */
    public static function kindOf(string $label): string
    {
        $l = mb_strtolower($label);

        return match (true) {
            str_contains($l, 'aeropuerto') => 'aeropuerto',
            str_contains($l, 'hotel') => 'hotel',
            str_contains($l, 'terminal') || str_contains($l, 'estación') => 'terminal',
            str_contains($l, 'por cuenta') || str_contains($l, 'propia cuenta') => 'por_cuenta',
            default => 'punto_encuentro',
        };
    }
}
