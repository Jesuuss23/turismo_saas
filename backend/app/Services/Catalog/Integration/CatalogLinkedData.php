<?php

namespace App\Services\Catalog\Integration;

use App\Models\Catalog\Destination;
use App\Models\Catalog\Product;
use App\Services\Crm\Linked\NullLinkedData;
use Illuminate\Support\Str;

/**
 * Datos del catálogo que lee el CRM (spec 01 §3.12): producto de interés y destino del lead.
 * El resto sigue vacío hasta que existan Cotizaciones, Reservas y Finanzas.
 */
class CatalogLinkedData extends NullLinkedData
{
    /** CRM-BR-06 / PRD-BR-002: ACTIVE o APPROVED. */
    public function productSelectable(string $productId): bool
    {
        return Str::isUuid($productId) && Product::whereKey($productId)->whereIn('status', [Product::ACTIVE, Product::APPROVED])->exists();
    }

    public function destination(string $destinationId): ?array
    {
        $destination = Str::isUuid($destinationId) ? Destination::find($destinationId) : null;

        return $destination ? ['id' => $destination->id, 'label' => $destination->name] : null;
    }
}
