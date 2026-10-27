<?php

namespace App\Providers;

use App\Models\Iam\PlatformUser;
use App\Models\Iam\User;
use App\Models\Sanctum\PersonalAccessToken;
use App\Services\AuditLogger;
use App\Services\CorrelationId;
use App\Services\Finance\Fiscal\FiscalProvider;
use App\Services\Finance\Fiscal\SimulatedFiscalProvider;
use App\Services\Notify;
use App\Services\Search\GlobalSearch;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(CorrelationId::class);
        $this->app->singleton(GlobalSearch::class);
        $this->app->singleton(Notify::class);
        // Adaptador OSE/PSE por validar (spec 07 §12 P-02): simulado hasta homologar un proveedor.
        $this->app->bind(FiscalProvider::class, SimulatedFiscalProvider::class);

        // Conexión aparte para auditoría en transacción propia (§9): mismas credenciales que la app.
        config(['database.connections.'.AuditLogger::ISOLATED_CONNECTION => config('database.connections.'.config('database.default'))]);
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Usuario desactivado → 401 (X-12). El tenant suspendido se trata en EnsureTenantActive (403).
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid) {
            if (! $isValid) {
                return false;
            }
            $user = $token->tokenable;

            return match (true) {
                $user instanceof User => $user->isActive(),
                $user instanceof PlatformUser => $user->status === 'activo',
                default => false,
            };
        });

        // SEC-BR-25
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip().'|'.mb_strtolower((string) $request->input('email'))));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('turismo.api_rate_per_minute'))->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
        // Enlace público de cotización (spec 03 §6): por IP y token.
        RateLimiter::for('public-quote', fn (Request $request) => Limit::perMinute(30)->by($request->ip().'|'.$request->route('token')));
        RateLimiter::for('exports', fn (Request $request) => Limit::perHour(10)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }
}
