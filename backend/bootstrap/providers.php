<?php

use App\Providers\AppServiceProvider;
use App\Providers\BookingsServiceProvider;
use App\Providers\CatalogServiceProvider;
use App\Providers\CrmServiceProvider;
use App\Providers\QuotesServiceProvider;

return [
    AppServiceProvider::class,
    BookingsServiceProvider::class,
    QuotesServiceProvider::class,
    CatalogServiceProvider::class,
    CrmServiceProvider::class,
];
