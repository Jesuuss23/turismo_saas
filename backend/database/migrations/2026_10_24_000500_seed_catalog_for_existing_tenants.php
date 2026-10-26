<?php

use App\Services\Catalog\CatalogProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Tipos, categorías, reglas de pricing y correlativos del catálogo para los tenants ya creados.
    public function up(): void
    {
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            CatalogProvisioner::seedTenant($tenantId, DB::connection());
        }
    }

    public function down(): void {}
};
