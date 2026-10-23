<?php

use App\Exceptions\ApiErrorRenderer;
use App\Exceptions\ApiException;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnsureExperience;
use App\Http\Middleware\EnsureMfaVerified;
use App\Http\Middleware\EnsurePlatformUser;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\Idempotent;
use App\Http\Middleware\RequireAnyPermission;
use App\Http\Middleware\ResetTenantContext;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // El frontend (BFF de Next) llama al API desde el servidor y reenvía la IP del usuario.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));

        $middleware->api(prepend: [
            AssignCorrelationId::class,
            ResetTenantContext::class,
        ]);

        $middleware->alias([
            'tenant.resolve' => ResolveTenant::class,
            'tenant.active' => EnsureTenantActive::class,
            'mfa' => EnsureMfaVerified::class,
            'experience' => EnsureExperience::class,
            'perm' => RequireAnyPermission::class,
            'platform' => EnsurePlatformUser::class,
            'idempotent' => Idempotent::class,
        ]);

        $middleware->group('tenant', [
            ResolveTenant::class,
            EnsureTenantActive::class,
            EnsureMfaVerified::class,
        ]);

        // El tenant se fija antes de resolver modelos de ruta (route model binding bajo RLS).
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->appendToPriorityList(ResolveTenant::class, EnsureTenantActive::class);
        $middleware->appendToPriorityList(EnsureTenantActive::class, EnsureMfaVerified::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(new ApiErrorRenderer);
        $exceptions->dontReport([ApiException::class]);
    })->create();
