<?php

namespace App\Services\Channels\Integration;

use App\Models\Channel\B2bAgency;
use App\Services\Ops\Integration\OpsCatalogLinks;

/** Con Canales (spec 08) las agencias B2B del catálogo salen de `channel_b2b_agencies` con su estado efectivo y lista de precios. */
class ChannelCatalogLinks extends OpsCatalogLinks
{
    public function agency(string $agencyId): ?array
    {
        $agency = B2bAgency::with('priceList')->find($agencyId);

        return $agency ? $this->row($agency) : parent::agency($agencyId);
    }

    public function agencies(): array
    {
        $agencies = B2bAgency::with('priceList')->orderBy('code')->get();

        return $agencies->isEmpty() ? parent::agencies() : $agencies->map(fn (B2bAgency $a) => $this->row($a))->all();
    }

    private function row(B2bAgency $a): array
    {
        return ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'status' => $a->effectiveStatus(), 'credit_line' => (float) $a->credit_limit,
            'price_list_id' => $a->price_list_id, 'price_list' => $a->priceList?->name, 'commission_pct' => (float) $a->commission_pct];
    }
}
