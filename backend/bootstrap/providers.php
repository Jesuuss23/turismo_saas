<?php

use App\Providers\AppServiceProvider;
use App\Providers\BookingEngineServiceProvider;
use App\Providers\BookingsServiceProvider;
use App\Providers\CatalogServiceProvider;
use App\Providers\ChannelsServiceProvider;
use App\Providers\CollaborationServiceProvider;
use App\Providers\CrmServiceProvider;
use App\Providers\InventoryServiceProvider;
use App\Providers\OpsServiceProvider;
use App\Providers\PlatformServiceProvider;
use App\Providers\QuotesServiceProvider;
use App\Providers\SupplierServiceProvider;
use App\Providers\TreasuryServiceProvider;

return [
    AppServiceProvider::class,
    ChannelsServiceProvider::class,
    OpsServiceProvider::class,
    BookingsServiceProvider::class,
    BookingEngineServiceProvider::class,
    QuotesServiceProvider::class,
    CatalogServiceProvider::class,
    CrmServiceProvider::class,
    SupplierServiceProvider::class,
    CollaborationServiceProvider::class,
    InventoryServiceProvider::class,
    TreasuryServiceProvider::class,
    PlatformServiceProvider::class,
];
