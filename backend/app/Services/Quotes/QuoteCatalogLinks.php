<?php

namespace App\Services\Quotes;

use App\Models\Quotes\Quote;
use App\Services\Catalog\Links\NullCatalogLinks;
use App\Services\Crm\CrmClock;

/** Uso del producto en cotizaciones (spec 02 §6.1 `usage`, CAT-BR-08): versión vigente de cada cotización. */
class QuoteCatalogLinks extends NullCatalogLinks
{
    public function productUsage(string $productId, ?string $ownerId = null): array
    {
        $today = app(CrmClock::class)->today();
        $quotes = Quote::query()
            ->whereHas('currentVersion.items', fn ($q) => $q->where('product_id', $productId))
            ->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))
            ->with(['client:id,name', 'owner:id,name', 'currentVersion:id,grand_total'])->orderByDesc('created_at')->limit(100)->get();

        return ['quotes' => $quotes->map(fn (Quote $q) => [
            'code' => $q->code, 'title' => $q->title, 'client' => $q->client?->name, 'owner' => $q->owner?->name,
            'status' => $q->displayStatus($today), 'total' => (string) $q->currentVersion?->grand_total, 'currency' => $q->currency,
        ])->all()] + parent::productUsage($productId, $ownerId);
    }
}
