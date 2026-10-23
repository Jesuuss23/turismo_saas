<?php

use App\Http\Controllers\Api\AccessRequestController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Me\MeController;
use App\Http\Controllers\Api\Me\MfaController;
use App\Http\Controllers\Api\Me\NotificationController;
use App\Http\Controllers\Api\Platform\PlatformAuthController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

/*
| Cadena PROC-062 (§11-1): auth:sanctum → ResolveTenant → EnsureTenantActive → EnsureMfaVerified
| → (RLS + team spatie, fijados por TenantContext) → EnsureExperience → permiso → Policy.
| Alias definidos en bootstrap/app.php; el grupo `tenant` agrupa los tres primeros.
*/

Route::prefix('v1')->group(function () {

    // Público
    Route::middleware('throttle:login')->group(function () {
        Route::post('auth/login', [LoginController::class, 'login']);
        Route::post('auth/password/forgot', [PasswordController::class, 'forgot']);
        Route::post('auth/password/reset', [PasswordController::class, 'reset']);
        Route::post('platform/auth/login', [PlatformAuthController::class, 'login']);
    });

    // Paso MFA: token con ability mfa:verify (sin EnsureMfaVerified).
    Route::middleware(['auth:sanctum', 'tenant.resolve', 'tenant.active', 'throttle:login'])->group(function () {
        Route::post('auth/mfa/verify', [LoginController::class, 'verifyMfa']);
        Route::post('me/mfa/enroll', [MfaController::class, 'enroll']);
        Route::post('me/mfa/confirm', [MfaController::class, 'confirm']);
    });

    // Usuarios de tenant autenticados (todas las experiencias).
    Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [LoginController::class, 'logout']);
        Route::get('me', [MeController::class, 'show']);
        Route::get('me/permissions', [MeController::class, 'permissions']);
        Route::get('me/access', [MeController::class, 'access']);
        Route::get('me/access/check', [MeController::class, 'check']);
        Route::get('me/notifications', [NotificationController::class, 'index']);
        Route::post('me/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::delete('me/mfa', [MfaController::class, 'destroy']);

        // Back-office
        Route::middleware('experience:backoffice')->group(function () {
            Route::get('me/tenant', [MeController::class, 'tenant']);
            Route::get('search', SearchController::class);
            Route::post('access-requests', [AccessRequestController::class, 'store']);
            Route::get('access-requests', [AccessRequestController::class, 'index'])->middleware('perm:admin.users.manage');
            Route::get('access-requests/{accessRequest}', [AccessRequestController::class, 'show'])->middleware('perm:admin.users.manage');
        });
    });

    // Consola de plataforma (SEC-BR-06)
    Route::prefix('platform')->group(function () {
        Route::post('auth/mfa/verify', [PlatformAuthController::class, 'verifyMfa'])->middleware(['auth:sanctum', 'throttle:login']);

        Route::middleware(['auth:sanctum', 'platform', 'mfa', 'throttle:api'])->group(function () {
            Route::get('me', [PlatformAuthController::class, 'me']);
            Route::post('auth/logout', [PlatformAuthController::class, 'logout']);
        });
    });
});
