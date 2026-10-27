<?php

use App\Http\Controllers\Api\AccessRequestController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Bookings\QuoteConversionController;
use App\Http\Controllers\Api\Bookings\ReservationActionController;
use App\Http\Controllers\Api\Bookings\ReservationController;
use App\Http\Controllers\Api\Catalog\DepartureController;
use App\Http\Controllers\Api\Catalog\PricingController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\Catalog\ProductTabsController;
use App\Http\Controllers\Api\Catalog\ReferenceController;
use App\Http\Controllers\Api\Catalog\SupplierController;
use App\Http\Controllers\Api\Crm\ActivityController;
use App\Http\Controllers\Api\Crm\AfterSalesController;
use App\Http\Controllers\Api\Crm\ClientController;
use App\Http\Controllers\Api\Crm\CrmMetaController;
use App\Http\Controllers\Api\Crm\LeadController;
use App\Http\Controllers\Api\Crm\OpportunityController;
use App\Http\Controllers\Api\Crm\SalesDashboardController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\Finance\PaymentController;
use App\Http\Controllers\Api\Me\MeController;
use App\Http\Controllers\Api\Me\MfaController;
use App\Http\Controllers\Api\Me\NotificationController;
use App\Http\Controllers\Api\Platform\PlatformAuthController;
use App\Http\Controllers\Api\Quotes\PortalQuoteController;
use App\Http\Controllers\Api\Quotes\PublicQuoteController;
use App\Http\Controllers\Api\Quotes\QuoteActionController;
use App\Http\Controllers\Api\Quotes\QuoteController;
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
    // Enlace público del cliente (spec 03 §6): sin sesión; el token identifica tenant y cotización.
    Route::prefix('public/quotes')->middleware('throttle:public-quote')->group(function () {
        Route::get('{token}', [PublicQuoteController::class, 'show']);
        Route::post('{token}/approve', [PublicQuoteController::class, 'approve'])->middleware('idempotent');
        Route::post('{token}/reject', [PublicQuoteController::class, 'reject'])->middleware('idempotent');
    });

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
        // Portal del cliente: cotizaciones propias (spec 03 §2).
        Route::prefix('portal/quotes')->middleware(['experience:portal', 'perm:quotes.view'])->group(function () {
            Route::get('/', [PortalQuoteController::class, 'index']);
            Route::get('{code}', [PortalQuoteController::class, 'show']);
            Route::post('{code}/approve', [PortalQuoteController::class, 'approve'])->middleware('idempotent');
            Route::post('{code}/reject', [PortalQuoteController::class, 'reject']);
        });

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

            // Catálogo turístico (spec 02 §6). Datos sensibles por rol en CatalogPresenter (§7.2).
            // Spec 03 §6: cotizaciones. Los pickers del catálogo van antes de `catalog/products/{ref}`.
            Route::get('catalog/products/quoteable', [QuoteController::class, 'quoteable'])->middleware('perm:quotes.create');
            Route::get('catalog/products/{product}/rate-suggestion', [QuoteController::class, 'rateSuggestion'])->middleware('perm:quotes.create');
            Route::prefix('quotes')->group(function () {
                $view = 'perm:quotes.view';
                $create = 'perm:quotes.create';
                $send = 'perm:quotes.send';
                $actions = QuoteActionController::class;

                Route::get('/', [QuoteController::class, 'index'])->middleware($view);
                Route::get('kpis', [QuoteController::class, 'kpis'])->middleware($view);
                Route::get('meta', [QuoteController::class, 'meta'])->middleware($view);
                Route::post('pricing/preview', [QuoteController::class, 'preview'])->middleware($create);
                Route::post('/', [QuoteController::class, 'store'])->middleware([$create, 'idempotent']);
                Route::get('{code}', [QuoteController::class, 'show'])->middleware($view);
                Route::get('{code}/versions', [QuoteController::class, 'versions'])->middleware($view);
                Route::get('{code}/versions/compare', [QuoteController::class, 'compare'])->middleware($view);
                Route::get('{code}/versions/{v}', [QuoteController::class, 'showVersion'])->middleware($view)->whereNumber('v');
                Route::put('{code}/versions/{v}', [QuoteController::class, 'updateVersion'])->middleware($create)->whereNumber('v');
                Route::post('{code}/versions', [QuoteController::class, 'newVersion'])->middleware($create);
                Route::post('{code}/duplicate', [QuoteController::class, 'duplicate'])->middleware([$create, 'idempotent']);
                Route::post('{code}/reassign', [QuoteController::class, 'reassign'])->middleware('perm:quotes.reassign');
                Route::get('{code}/activity', [QuoteController::class, 'activity'])->middleware($view);

                Route::post('{code}/internal-approval-requests', [$actions, 'requestApproval'])->middleware('perm:quotes.create,quotes.send');
                Route::post('{code}/versions/{v}/internal-approval', [$actions, 'decideApproval'])->middleware('perm:quotes.approve_internal')->whereNumber('v');
                Route::post('{code}/token', [$actions, 'token'])->middleware($send);
                Route::post('{code}/send', [$actions, 'send'])->middleware([$send, 'idempotent']);
                Route::post('{code}/views', [$actions, 'registerView'])->middleware($send);
                Route::post('{code}/negotiations', [$actions, 'negotiate'])->middleware($send);
                Route::post('{code}/approve', [$actions, 'approve'])->middleware(['perm:reservations.create', 'idempotent']);
                Route::post('{code}/reject', [$actions, 'reject'])->middleware($send);
            });

            // Spec 04 §6: reservas. Datos sensibles por rol en ReservationPresenter (§7).
            Route::get('quotes/{code}/conversion-preview', [QuoteConversionController::class, 'preview'])->middleware('perm:reservations.create');
            Route::post('quotes/{code}/convert', [QuoteConversionController::class, 'convert'])->middleware(['perm:reservations.create', 'idempotent']);
            Route::post('finance/payments', [PaymentController::class, 'store'])->middleware(['perm:finance.payments.register', 'idempotent']);
            Route::prefix('reservations')->group(function () {
                $view = 'perm:reservations.view';
                $create = 'perm:reservations.create';
                $modify = 'perm:reservations.modify';
                $cancel = 'perm:reservations.cancel';
                $r = ReservationController::class;
                $a = ReservationActionController::class;

                Route::get('/', [$r, 'index'])->middleware($view);
                Route::get('kpis', [$r, 'kpis'])->middleware($view);
                Route::get('occupancy', [$r, 'occupancy'])->middleware($view);
                Route::get('meta', [$r, 'meta'])->middleware($view);
                Route::get('products', [$r, 'products'])->middleware($create);
                Route::post('availability-check', [$r, 'availabilityCheck'])->middleware($create);
                Route::post('/', [$r, 'store'])->middleware([$create, 'idempotent']);
                Route::get('{code}', [$r, 'show'])->middleware($view);
                Route::get('{code}/history', [$r, 'history'])->middleware($view);
                Route::get('{code}/documents/confirmation', [$r, 'confirmation'])->middleware($view);

                Route::post('{code}/confirm', [$a, 'confirm'])->middleware($modify);
                Route::post('{code}/start-operation', [$a, 'startOperation'])->middleware($modify);
                Route::post('{code}/complete', [$a, 'complete'])->middleware($modify);
                Route::post('{code}/passengers', [$a, 'storePassenger'])->middleware($modify);
                Route::match(['put', 'patch'], '{code}/passengers/{passenger}', [$a, 'updatePassenger'])->middleware($modify);
                Route::delete('{code}/passengers/{passenger}', [$a, 'destroyPassenger'])->middleware($modify);
                Route::post('{code}/services/request-pending', [$a, 'requestPending'])->middleware($modify);
                Route::post('{code}/services/{service}/request', [$a, 'requestService'])->middleware($modify);
                Route::post('{code}/services/{service}/confirm', [$a, 'confirmService'])->middleware($modify);
                Route::post('{code}/services/{service}/reject', [$a, 'rejectService'])->middleware($modify);
                Route::post('{code}/changes/preview', [$a, 'previewChange'])->middleware($modify);
                Route::post('{code}/changes', [$a, 'storeChange'])->middleware($modify);
                Route::post('{code}/changes/{change}/approve', [$a, 'approveChange'])->middleware('perm:quotes.approve_internal');
                Route::post('{code}/changes/{change}/reject', [$a, 'rejectChange'])->middleware('perm:quotes.approve_internal');
                Route::post('{code}/reschedule/preview', [$a, 'previewReschedule'])->middleware($modify);
                Route::post('{code}/reschedule', [$a, 'reschedule'])->middleware([$modify, 'idempotent']);
                Route::post('{code}/cancellation/preview', [$a, 'previewCancel'])->middleware($cancel);
                Route::post('{code}/cancel', [$a, 'cancel'])->middleware([$cancel, 'idempotent']);
                Route::post('{code}/cancel-requests', [$a, 'requestCancel'])->middleware($create);
                Route::post('{code}/cancel-requests/{cancelRequest}/reject', [$a, 'rejectCancelRequest'])->middleware($cancel);
                Route::post('{code}/receivables/generate', [$a, 'generateReceivables'])->middleware(['perm:finance.receivables', 'idempotent']);
                Route::post('{code}/reassign', [$a, 'reassign'])->middleware('perm:reservations.reassign');
            });

            Route::prefix('catalog')->group(function () {
                $view = 'perm:catalog.products.view';
                $update = 'perm:catalog.products.update';
                $activate = 'perm:catalog.products.activate';
                $rates = 'perm:catalog.products.manage_rates';
                $avail = 'perm:catalog.products.manage_availability';
                $profit = 'perm:catalog.products.view_profitability';
                $prov = 'perm:catalog.providers.manage';
                $tabs = ProductTabsController::class;

                Route::get('meta', [ReferenceController::class, 'meta'])->middleware($view);

                // 6.1 Productos y ciclo de vida
                Route::get('products', [ProductController::class, 'index'])->middleware($view);
                Route::get('products/kpis', [ProductController::class, 'kpis'])->middleware($view);
                Route::post('products', [ProductController::class, 'store'])->middleware(['perm:catalog.products.create', 'idempotent']);
                Route::get('products/{ref}', [ProductController::class, 'show'])->middleware($view);
                Route::patch('products/{ref}', [ProductController::class, 'update'])->middleware($update);
                Route::delete('products/{ref}', [ProductController::class, 'destroy'])->middleware($activate);
                Route::get('products/{ref}/references', [ProductController::class, 'references'])->middleware($activate);
                Route::post('products/{ref}/duplicate', [ProductController::class, 'duplicate'])->middleware(['perm:catalog.products.create', 'idempotent']);
                Route::get('products/{ref}/activation-checklist', [ProductController::class, 'checklist'])->middleware($view);
                Route::post('products/{ref}/submit-review', [ProductController::class, 'transition'])->defaults('action', 'submit-review')->middleware($update);
                Route::post('products/{ref}/{action}', [ProductController::class, 'transition'])
                    ->whereIn('action', ['approve', 'return-to-draft', 'activate', 'suspend', 'deactivate', 'archive'])->middleware($activate);
                Route::get('products/{ref}/usage', [ProductController::class, 'usage'])->middleware($view);
                Route::get('products/{ref}/audit', [ProductController::class, 'audit'])->middleware('perm:catalog.products.update,catalog.products.view_profitability,admin.audit.view');

                // 6.2 Sub-recursos
                Route::middleware($view)->group(function () use ($tabs) {
                    Route::get('products/{ref}/components', [$tabs, 'components']);
                    Route::get('products/{ref}/itineraries', [$tabs, 'itinerary']);
                    Route::get('products/{ref}/rates', [$tabs, 'rates']);
                    Route::get('products/{ref}/rates/resolve', [$tabs, 'resolveRate']);
                    Route::get('products/{ref}/seasons', [$tabs, 'seasons']);
                    Route::get('products/{ref}/availability', [$tabs, 'availability']);
                    Route::get('products/{ref}/departures', [$tabs, 'departures']);
                    Route::get('products/{ref}/providers', [$tabs, 'providers']);
                    Route::get('products/{ref}/policies', [$tabs, 'policies']);
                    Route::get('products/{ref}/inclusions', [$tabs, 'inclusions']);
                    Route::get('products/{ref}/requirements', [$tabs, 'requirements']);
                    Route::get('products/{ref}/related', [$tabs, 'related']);
                    Route::get('products/{ref}/b2b-rates', [$tabs, 'b2bRates']);
                    Route::get('products/{ref}/promotions', [$tabs, 'promotions']);
                    Route::get('products/{ref}/config-groups', [$tabs, 'configGroups']);
                    Route::post('products/{ref}/price-quote', [$tabs, 'priceQuote']);
                    Route::get('products/{ref}/content', [$tabs, 'content']);
                    Route::get('products/{ref}/channels', [$tabs, 'channels']);
                });
                Route::middleware($update)->group(function () use ($tabs) {
                    Route::post('products/{ref}/requirements', [$tabs, 'addRequirement']);
                    Route::delete('products/{ref}/requirements/{requirement}', [$tabs, 'removeRequirement']);
                    Route::post('products/{ref}/components', [$tabs, 'addComponent']);
                    Route::post('products/{ref}/components/reorder', [$tabs, 'reorderComponents']);
                    Route::patch('products/{ref}/components/{component}', [$tabs, 'updateComponent']);
                    Route::delete('products/{ref}/components/{component}', [$tabs, 'removeComponent']);
                    Route::post('products/{ref}/itineraries/days', [$tabs, 'addDay']);
                    Route::patch('products/{ref}/itineraries/days/{day}', [$tabs, 'updateDay']);
                    Route::delete('products/{ref}/itineraries/days/{day}', [$tabs, 'removeDay']);
                    Route::post('products/{ref}/itineraries/days/{day}/items', [$tabs, 'addItem']);
                    Route::patch('products/{ref}/itineraries/days/{day}/items/{item}', [$tabs, 'updateItem']);
                    Route::delete('products/{ref}/itineraries/days/{day}/items/{item}', [$tabs, 'removeItem']);
                    Route::put('products/{ref}/main-provider', [$tabs, 'setMainProvider']);
                    Route::put('products/{ref}/policies', [$tabs, 'setPolicies']);
                    Route::post('products/{ref}/inclusions', [$tabs, 'addInclusion']);
                    Route::delete('products/{ref}/inclusions/{inclusion}', [$tabs, 'removeInclusion']);
                    Route::post('products/{ref}/related', [$tabs, 'addRelated']);
                    Route::delete('products/{ref}/related/{related}', [$tabs, 'removeRelated']);
                    Route::post('products/{ref}/b2b-rates', [$tabs, 'saveB2bRate']);
                    Route::patch('products/{ref}/b2b-rates/{b2b}', [$tabs, 'saveB2bRate']);
                    Route::delete('products/{ref}/b2b-rates/{b2b}', [$tabs, 'removeB2bRate']);
                    Route::post('products/{ref}/promotions', [$tabs, 'addPromotion']);
                    Route::post('products/{ref}/promotions/{promotion}/{action}', [$tabs, 'promotionStatus'])->whereIn('action', ['pause', 'activate']);
                    Route::delete('products/{ref}/promotions/{promotion}', [$tabs, 'removePromotion']);
                    Route::post('products/{ref}/config-groups', [$tabs, 'addConfigGroup']);
                    Route::delete('products/{ref}/config-groups/{group}', [$tabs, 'removeConfigGroup']);
                    Route::put('products/{ref}/content', [$tabs, 'setContent']);
                    Route::post('products/{ref}/media', [$tabs, 'addMedia']);
                    Route::patch('products/{ref}/media/{media}', [$tabs, 'updateMedia']);
                    Route::delete('products/{ref}/media/{media}', [$tabs, 'removeMedia']);
                    Route::post('products/{ref}/faqs', [$tabs, 'addFaq']);
                    Route::delete('products/{ref}/faqs/{faq}', [$tabs, 'removeFaq']);
                    Route::post('products/{ref}/versions', [$tabs, 'storeVersion']);
                });
                Route::middleware($rates)->group(function () use ($tabs) {
                    Route::post('products/{ref}/rates', [$tabs, 'storeRate']);
                    Route::patch('products/{ref}/rates/{rate}/price', [$tabs, 'changePrice']);
                    Route::post('products/{ref}/rates/{rate}/{action}', [$tabs, 'rateStatus'])->whereIn('action', ['activate', 'deactivate']);
                    Route::post('products/{ref}/seasons', [$tabs, 'associateSeason']);
                    Route::delete('products/{ref}/seasons/{season}', [$tabs, 'dissociateSeason']);
                    Route::put('products/{ref}/markup', [$tabs, 'setMarkup']);
                });
                Route::middleware($avail)->group(function () use ($tabs) {
                    Route::put('products/{ref}/availability', [$tabs, 'setAvailability']);
                    Route::post('products/{ref}/departures', [$tabs, 'storeDeparture']);
                    Route::patch('products/{ref}/departures/{departure}', [$tabs, 'updateDeparture']);
                });
                Route::get('products/{ref}/profitability', [$tabs, 'profitability'])->middleware($profit);
                Route::put('products/{ref}/channels/{channel}', [$tabs, 'setChannel'])->middleware('perm:catalog.products.publish');
                Route::middleware('perm:catalog.products.update,catalog.products.view_profitability')->group(function () use ($tabs) {
                    Route::get('products/{ref}/versions', [$tabs, 'versions']);
                    Route::get('products/{ref}/versions/compare', [$tabs, 'compareVersions']);
                    Route::get('products/{ref}/versions/{version}', [$tabs, 'showVersion']);
                });

                // 6.3 Salidas y holds
                Route::get('departures', [DepartureController::class, 'index'])->middleware($view);
                Route::get('departures/{id}', [DepartureController::class, 'show'])->middleware($view);
                Route::get('holds', [DepartureController::class, 'holds'])->middleware($view);
                Route::middleware($avail)->group(function () {
                    Route::post('departures', [DepartureController::class, 'store'])->middleware('idempotent');
                    Route::post('departures/{id}/confirm-seats', [DepartureController::class, 'confirmSeats'])->middleware('idempotent');
                    Route::post('holds', [DepartureController::class, 'storeHold'])->middleware('idempotent');
                    Route::post('holds/expire', [DepartureController::class, 'expire']);
                    Route::post('holds/{id}/{action}', [DepartureController::class, 'holdAction'])->whereIn('action', ['confirm', 'release', 'consume']);
                });

                // 6.4 Tarifas, temporadas y pricing
                Route::get('rates', [PricingController::class, 'rates'])->middleware($view);
                Route::get('seasons', [PricingController::class, 'seasons'])->middleware($view);
                Route::get('seasons/resolve', [PricingController::class, 'resolveSeason'])->middleware($view);
                Route::middleware($rates)->group(function () {
                    Route::post('seasons', [PricingController::class, 'storeSeason']);
                    Route::patch('seasons/{id}', [PricingController::class, 'updateSeason']);
                    Route::delete('seasons/{id}', [PricingController::class, 'destroySeason']);
                    Route::post('price-rules', [PricingController::class, 'storePriceRule']);
                    Route::post('price-rules/{id}/{action}', [PricingController::class, 'priceRuleStatus'])->whereIn('action', ['activate', 'deactivate']);
                });
                Route::middleware('perm:catalog.products.view_profitability,catalog.products.manage_rates')->group(function () {
                    Route::get('price-rules', [PricingController::class, 'priceRules']);
                    Route::post('pricing/simulate', [PricingController::class, 'simulate']);
                });

                // 6.5 Tipos, categorías, destinos, servicios, proveedores
                Route::get('taxonomy', [ReferenceController::class, 'taxonomy'])->middleware($view);
                Route::middleware($update)->group(function () {
                    Route::post('product-types', [ReferenceController::class, 'storeType']);
                    Route::delete('product-types/{id}', [ReferenceController::class, 'destroyType']);
                    Route::post('categories', [ReferenceController::class, 'storeCategory']);
                    Route::delete('categories/{id}', [ReferenceController::class, 'destroyCategory']);
                    Route::post('categories/{id}/subcategories', [ReferenceController::class, 'storeSubcategory']);
                    Route::delete('categories/{id}/subcategories/{sub}', [ReferenceController::class, 'destroySubcategory']);
                    Route::post('destinations', [ReferenceController::class, 'storeDestination']);
                    Route::patch('destinations/{id}', [ReferenceController::class, 'updateDestination']);
                    Route::delete('destinations/{id}', [ReferenceController::class, 'destroyDestination']);
                });
                Route::get('destinations', [ReferenceController::class, 'destinations'])->middleware($view);
                Route::get('destinations/{id}', [ReferenceController::class, 'showDestination'])->middleware($view);

                Route::get('services', [SupplierController::class, 'services'])->middleware($view);
                Route::get('services/{id}', [SupplierController::class, 'showService'])->middleware($view);
                Route::middleware($prov)->group(function () {
                    Route::post('services', [SupplierController::class, 'storeService']);
                    Route::patch('services/{id}', [SupplierController::class, 'updateService']);
                    Route::post('services/{id}/{action}', [SupplierController::class, 'serviceStatus'])->whereIn('action', ['activate', 'deactivate']);
                    Route::post('providers', [SupplierController::class, 'storeProvider']);
                    Route::patch('providers/{id}', [SupplierController::class, 'updateProvider']);
                    Route::post('providers/{id}/contacts', [SupplierController::class, 'addContact']);
                    Route::delete('providers/{id}/contacts/{contact}', [SupplierController::class, 'removeContact']);
                    Route::post('providers/{id}/{action}', [SupplierController::class, 'providerAction'])->whereIn('action', ['activate', 'deactivate', 'renew-contract', 'rating']);
                });
                Route::middleware('perm:catalog.providers.manage,finance.payables,catalog.products.update')->group(function () {
                    Route::get('providers', [SupplierController::class, 'providers']);
                    Route::get('providers/{id}', [SupplierController::class, 'showProvider']);
                });
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
