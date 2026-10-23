<?php

namespace App\Http\Middleware;

use App\Services\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** X-Request-Id entrante o generado; se devuelve en la respuesta y se guarda en auditoría (§9). */
class AssignCorrelationId
{
    public function __construct(private readonly CorrelationId $correlation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->correlation->set($request->header('X-Request-Id'));

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
