<?php

namespace App\Providers;

use App\Services\Treasury\TreasurySync;
use Illuminate\Support\ServiceProvider;

/** Tesorería (spec 12 §8): los cobros, reversiones y pagos a proveedores se reflejan por evento en el libro de movimientos. */
class TreasuryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TreasurySync::register();
    }
}
