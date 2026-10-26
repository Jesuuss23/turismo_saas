<?php

namespace App\Services\Catalog\Links;

use App\Tenancy\TenantContext;

/**
 * Sin módulos de Cotizaciones, Reservas, Operaciones, Finanzas ni B2B: sin uso en ventas ni CxP.
 * Agencias, guías y vehículos se leen de forma provisional de `tenants.settings`
 * (`b2b.agencies`, `operations.guides`, `operations.vehicles`) hasta que existan sus módulos.
 */
class NullCatalogLinks implements CatalogLinks
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function productUsage(string $productId, ?string $ownerId = null): array
    {
        return ['quotes' => [], 'reservations' => [], 'operations' => []];
    }

    public function agency(string $agencyId): ?array
    {
        return collect($this->agencies())->firstWhere('id', $agencyId);
    }

    public function agencies(): array
    {
        return $this->setting('b2b.agencies');
    }

    public function guide(string $guideId): ?array
    {
        return collect($this->guides())->firstWhere('id', $guideId);
    }

    public function guides(): array
    {
        return $this->setting('operations.guides');
    }

    public function vehicle(string $vehicleId): ?array
    {
        return collect($this->vehicles())->firstWhere('id', $vehicleId);
    }

    public function vehicles(): array
    {
        return $this->setting('operations.vehicles');
    }

    public function providerPayables(string $providerId): array
    {
        return ['open_count' => 0, 'open_pen' => 0, 'items' => []];
    }

    public function providerReservations(string $providerId): array
    {
        return [];
    }

    private function setting(string $key): array
    {
        return array_values((array) data_get($this->tenant->tenant()?->settings, $key, []));
    }
}
