<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ninguna solicitud hereda el tenant de otra (conexiones persistentes, Octane). */
class ResetTenantContext
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenant->clear();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenant->clear();
    }
}
