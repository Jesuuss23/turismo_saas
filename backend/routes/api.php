<?php

use App\Http\Controllers\Api\AccessRequestController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Crm\ActivityController;
use App\Http\Controllers\Api\Crm\AfterSalesController;
use App\Http\Controllers\Api\Crm\ClientController;
use App\Http\Controllers\Api\Crm\CrmMetaController;
use App\Http\Controllers\Api\Crm\LeadController;
use App\Http\Controllers\Api\Crm\OpportunityController;
use App\Http\Controllers\Api\Crm\SalesDashboardController;
use App\Http\Controllers\Api\ExportController;
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

            // CRM (spec 01 §6). Permiso por endpoint; ownership en los servicios (CrmAccess).
            Route::prefix('crm')->group(function () {
                Route::get('meta', CrmMetaController::class)->middleware('perm:crm.leads.view,crm.clients.manage,finance.receivables');
                Route::get('owners', [ActivityController::class, 'owners'])->middleware('perm:crm.leads.manage,crm.opportunities.manage,crm.activities.manage,crm.clients.manage');

                // 6.1 Leads
                Route::get('leads', [LeadController::class, 'index'])->middleware('perm:crm.leads.view');
                Route::get('leads/duplicates', [LeadController::class, 'duplicates'])->middleware('perm:crm.leads.manage');
                Route::post('leads', [LeadController::class, 'store'])->middleware('perm:crm.leads.manage');
                Route::get('leads/{code}', [LeadController::class, 'show'])->middleware('perm:crm.leads.view');
                Route::middleware('perm:crm.leads.manage')->group(function () {
                    Route::patch('leads/{code}', [LeadController::class, 'update']);
                    Route::post('leads/{code}/take', [LeadController::class, 'take']);
                    Route::post('leads/{code}/status', [LeadController::class, 'status']);
                    Route::post('leads/{code}/qualify', [LeadController::class, 'qualify']);
                    Route::post('leads/{code}/discard', [LeadController::class, 'discard']);
                    Route::post('leads/{code}/reopen', [LeadController::class, 'reopen']);
                    Route::post('leads/{code}/mark-duplicate', [LeadController::class, 'markDuplicate']);
                    Route::post('leads/{code}/merge', [LeadController::class, 'merge']);
                });
                Route::post('leads/{code}/assign', [LeadController::class, 'assign'])->middleware('perm:crm.records.reassign');
                Route::post('leads/{code}/convert', [LeadController::class, 'convert'])
                    ->middleware(['perm:crm.leads.manage', 'perm:crm.opportunities.manage', 'idempotent']);

                // 6.2 Oportunidades
                Route::middleware('perm:crm.opportunities.manage')->group(function () {
                    Route::get('pipeline-stages', [OpportunityController::class, 'stages']);
                    Route::get('opportunities', [OpportunityController::class, 'index']);
                    Route::post('opportunities', [OpportunityController::class, 'store']);
                    Route::get('opportunities/{code}', [OpportunityController::class, 'show']);
                    Route::patch('opportunities/{code}', [OpportunityController::class, 'update']);
                    Route::post('opportunities/{code}/stage', [OpportunityController::class, 'stage']);
                    Route::get('clients/{code}/rebuy-proposal', [AfterSalesController::class, 'rebuyProposal']);
                });
                Route::post('opportunities/{code}/reassign', [OpportunityController::class, 'reassign'])->middleware('perm:crm.records.reassign');

                // 6.3 Actividades
                Route::middleware('perm:crm.activities.manage')->group(function () {
                    Route::get('activities', [ActivityController::class, 'index']);
                    Route::get('activities/related-options', [ActivityController::class, 'relatedOptions']);
                    Route::post('activities', [ActivityController::class, 'store']);
                    Route::get('activities/{code}', [ActivityController::class, 'show']);
                    Route::post('activities/{code}/complete', [ActivityController::class, 'complete'])->middleware('idempotent');
                    Route::post('activities/{code}/reschedule', [ActivityController::class, 'reschedule']);
                });
                Route::post('activities/{code}/reassign', [ActivityController::class, 'reassign'])->middleware('perm:crm.records.reassign');

                // 6.4 Clientes y Customer 360 (Finanzas/Contabilidad: solo lectura)
                Route::get('clients/duplicates', [ClientController::class, 'duplicates'])->middleware('perm:crm.clients.manage');
                Route::middleware('perm:crm.clients.manage,finance.receivables')->group(function () {
                    Route::get('clients', [ClientController::class, 'index']);
                    Route::get('clients/{code}', [ClientController::class, 'show']);
                    Route::get('clients/{code}/{tab}', [ClientController::class, 'tab'])->whereIn('tab', ClientController::TABS);
                });
                Route::middleware('perm:crm.clients.manage')->group(function () {
                    Route::post('clients', [ClientController::class, 'store']);
                    Route::patch('clients/{code}', [ClientController::class, 'update']);
                    Route::post('clients/{code}/contacts', [ClientController::class, 'addContact']);
                    Route::post('clients/{code}/contacts/{contact}/make-primary', [ClientController::class, 'makePrimary']);
                    Route::post('clients/{code}/communications', [ClientController::class, 'communicate'])->middleware('idempotent');

                    // 6.5 Postventa y fidelización
                    Route::get('after-sales/trips', [AfterSalesController::class, 'trips']);
                    Route::get('surveys', [AfterSalesController::class, 'surveys']);
                    Route::post('surveys', [AfterSalesController::class, 'storeSurvey'])->middleware('idempotent');
                    Route::get('cases', [AfterSalesController::class, 'cases']);
                    Route::post('cases', [AfterSalesController::class, 'storeCase'])->middleware('idempotent');
                    Route::get('cases/{code}', [AfterSalesController::class, 'showCase']);
                    Route::post('cases/{code}/start', [AfterSalesController::class, 'start']);
                    Route::post('cases/{code}/follow-ups', [AfterSalesController::class, 'followUp']);
                    Route::post('cases/{code}/resolve', [AfterSalesController::class, 'resolve']);
                    Route::post('cases/{code}/close', [AfterSalesController::class, 'close']);
                    Route::get('loyalty/segments', [AfterSalesController::class, 'segments']);
                });
                Route::post('clients/{code}/reassign', [ClientController::class, 'reassign'])->middleware('perm:crm.records.reassign');
            });

            // 6.6 Panel de Ventas y exportación
            Route::get('dashboard/sales', SalesDashboardController::class)->middleware('perm:crm.leads.view');
            Route::post('exports', ExportController::class)->middleware('throttle:exports');
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
