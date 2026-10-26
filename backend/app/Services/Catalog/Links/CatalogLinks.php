<?php

namespace App\Services\Catalog\Links;

/**
 * Datos de otros módulos que el catálogo lee (spec 02 §3, §6, §7): uso en cotizaciones y reservas,
 * agencias B2B, guías y vehículos de Operaciones y cuentas por pagar. Mientras esos módulos no
 * existan se usa NullCatalogLinks; cada módulo reemplazará el binding.
 */
interface CatalogLinks
{
    /**
     * Uso del producto en ventas (§6.1 `usage`, CAT-BR-08). `$ownerId` aplica "Uso en mi cartera" de Ventas.
     *
     * @return array{quotes: list<array>, reservations: list<array>, operations: list<array>}
     */
    public function productUsage(string $productId, ?string $ownerId = null): array;

    /** Agencia B2B: [id, code, name, status, credit_line] o null (CAT-BR-26). */
    public function agency(string $agencyId): ?array;

    /** @return list<array{id: string, code: string, name: string, status: string}> agencias para el selector. */
    public function agencies(): array;

    /** Guía: [id, name, on_vacation] o null (CAT-BR-20). */
    public function guide(string $guideId): ?array;

    /** @return list<array{id: string, name: string, on_vacation: bool}> guías para el selector de salidas. */
    public function guides(): array;

    /** Vehículo: [id, plate, seats, in_maintenance] o null (CAT-BR-20). */
    public function vehicle(string $vehicleId): ?array;

    /** @return list<array{id: string, plate: string, seats: int, in_maintenance: bool}> vehículos para el selector. */
    public function vehicles(): array;

    /** CxP abiertas del proveedor: [open_count, open_pen, items[]] (solo con finance.payables). */
    public function providerPayables(string $providerId): array;

    /** @return list<array> reservas en que participa el proveedor. */
    public function providerReservations(string $providerId): array;
}
