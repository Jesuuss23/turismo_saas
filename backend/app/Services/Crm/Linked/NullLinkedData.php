<?php

namespace App\Services\Crm\Linked;

/** Implementación vacía hasta que existan Cotizaciones, Reservas y Finanzas (specs 03–06). */
class NullLinkedData implements LinkedData
{
    public function clientIdsWithRecordsOf(string $userId): array
    {
        return [];
    }

    public function quotesForOpportunity(string $opportunityId): array
    {
        return [];
    }

    public function hasApprovedQuote(string $opportunityId): bool
    {
        return false;
    }

    public function clientTab(string $clientId, string $tab, ?string $ownerId): array
    {
        return [];
    }

    public function purchaseStats(array $clientIds, ?string $ownerId = null): array
    {
        return [];
    }

    public function reservation(string $reservationId): ?array
    {
        return null;
    }

    public function tripsToClose(?string $ownerId): array
    {
        return [];
    }

    public function clientTimeline(string $clientId, ?string $ownerId): array
    {
        return [];
    }

    public function productSelectable(string $productId): bool
    {
        return false;
    }

    public function destination(string $destinationId): ?array
    {
        return null;
    }

    public function salesPanel(string $userId): array
    {
        return [
            'open_quotes' => ['count' => 0, 'expiring_7d' => 0, 'items' => []],
            'upcoming_trips' => [],
            'commission' => ['total_pen' => 0, 'by_currency' => []],
        ];
    }
}
