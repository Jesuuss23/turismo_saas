<?php

use App\Providers\AppServiceProvider;
use App\Providers\CatalogServiceProvider;
use App\Providers\CrmServiceProvider;
use App\Providers\QuotesServiceProvider;

return [
    AppServiceProvider::class,
    QuotesServiceProvider::class,
    CatalogServiceProvider::class,
    CrmServiceProvider::class,
];
