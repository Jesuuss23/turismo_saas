<?php

use App\Providers\AppServiceProvider;
use App\Providers\BookingsServiceProvider;
use App\Providers\CatalogServiceProvider;
use App\Providers\CrmServiceProvider;
use App\Providers\OpsServiceProvider;
use App\Providers\QuotesServiceProvider;
use App\Providers\SupplierServiceProvider;

return [
    AppServiceProvider::class,
    OpsServiceProvider::class,
    BookingsServiceProvider::class,
    QuotesServiceProvider::class,
    CatalogServiceProvider::class,
    CrmServiceProvider::class,
    SupplierServiceProvider::class,
];
