<?php

namespace App\Jobs\Middleware;

use App\Tenancy\MissingTenantContext;
use App\Tenancy\TenantContext;
use Closure;

class SetTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        if (empty($job->tenantId)) {
            throw new MissingTenantContext('Job '.$job::class.' sin tenant_id: no se procesa (X-04)');
        }

        return app(TenantContext::class)->run($job->tenantId, fn () => $next($job));
    }
}
