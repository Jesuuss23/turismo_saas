<?php

namespace App\Providers;

use App\Services\Collab\ApprovalRouter;
use App\Services\Collab\OriginSync;
use Illuminate\Support\ServiceProvider;

/** Colaboración (spec 10): las excepciones de los módulos de origen abren/cierran la solicitud unificada (§8). */
class CollaborationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ApprovalRouter::class);
    }

    public function boot(): void
    {
        OriginSync::register();
    }
}
