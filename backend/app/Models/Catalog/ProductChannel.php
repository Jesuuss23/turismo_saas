<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Publicación por canal (§4.8, CAT-BR-28). */
class ProductChannel extends TenantModel
{
    public const CHANNELS = [
        'erp' => 'ERP', 'crm' => 'CRM', 'quotes' => 'Cotizaciones', 'b2b' => 'B2B', 'portal' => 'Portal cliente',
        'portal_pax' => 'Portal pasajero', 'cms' => 'CMS', 'ecommerce' => 'E-commerce', 'mobile' => 'Mobile',
    ];

    protected $table = 'catalog_product_channels';

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'is_requested' => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}
