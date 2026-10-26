<?php

namespace App\Models\Catalog;

use App\Models\TenantModel;

/** Contactos adicionales del proveedor. */
class ProviderContact extends TenantModel
{
    protected $table = 'catalog_provider_contacts';
}
