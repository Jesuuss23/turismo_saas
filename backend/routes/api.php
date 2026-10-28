<?php

use App\Http\Controllers\Api\AccessRequestController;
use App\Http\Controllers\Api\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Api\Admin\BookingRulesController;
use App\Http\Controllers\Api\Admin\IntegrationController as AdminIntegrationController;
use App\Http\Controllers\Api\Admin\NotificationAdminController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Api\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Auth\InvitationController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Billing\SubscriptionController;
use App\Http\Controllers\Api\Bookings\QuoteConversionController;
use App\Http\Controllers\Api\Bookings\ReservationActionController;
use App\Http\Controllers\Api\Bookings\ReservationController;
use App\Http\Controllers\Api\Catalog\AllotmentController;
use App\Http\Controllers\Api\Catalog\BookingConfigController;
use App\Http\Controllers\Api\Catalog\DepartureController;
use App\Http\Controllers\Api\Catalog\PricingController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\Catalog\ProductTabsController;
use App\Http\Controllers\Api\Catalog\ReferenceController;
use App\Http\Controllers\Api\Catalog\SupplierController;
use App\Http\Controllers\Api\Channels\B2bAdminController;
use App\Http\Controllers\Api\Channels\B2bPortalController;
use App\Http\Controllers\Api\Channels\ClaimBookPortalController;
use App\Http\Controllers\Api\Channels\ClientPortalController;
use App\Http\Controllers\Api\Channels\CmsController;
use App\Http\Controllers\Api\Channels\EcommerceController;
use App\Http\Controllers\Api\Channels\PassengerPortalController;
use App\Http\Controllers\Api\Channels\PublicShopController;
use App\Http\Controllers\Api\Collab\ApprovalController;
use App\Http\Controllers\Api\Collab\ApprovalRuleController;
use App\Http\Controllers\Api\Collab\InboundWebhookController;
use App\Http\Controllers\Api\Collab\InboxController;
use App\Http\Controllers\Api\Crm\ActivityController;
use App\Http\Controllers\Api\Crm\AfterSalesController;
use App\Http\Controllers\Api\Crm\ClaimBookController;
use App\Http\Controllers\Api\Crm\ClientController;
use App\Http\Controllers\Api\Crm\CrmMetaController;
use App\Http\Controllers\Api\Crm\LeadController;
use App\Http\Controllers\Api\Crm\OpportunityController;
use App\Http\Controllers\Api\Crm\SalesDashboardController;
use App\Http\Controllers\Api\Crm\SalesTargetController;
use App\Http\Controllers\Api\Experience\CurrenciesController;
use App\Http\Controllers\Api\Experience\PaxMessagesController;
use App\Http\Controllers\Api\Experience\ProviderAckController;
use App\Http\Controllers\Api\Experience\ReviewsController;
use App\Http\Controllers\Api\Experience\WidgetController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\Ext\ExtApiController;
use App\Http\Controllers\Api\Finance\CommissionController as FinCommissionController;
use App\Http\Controllers\Api\Finance\InvoiceController as FinInvoiceController;
use App\Http\Controllers\Api\Finance\PanelController as FinPanelController;
use App\Http\Controllers\Api\Finance\PayableController as FinPayableController;
use App\Http\Controllers\Api\Finance\PaymentController;
use App\Http\Controllers\Api\Finance\ProfitabilityController as FinProfitabilityController;
use App\Http\Controllers\Api\Finance\ReceivableController as FinReceivableController;
use App\Http\Controllers\Api\Finance\ReconciliationController as FinReconciliationController;
use App\Http\Controllers\Api\Integrations\PublicApiController;
use App\Http\Controllers\Api\Marketing\CampaignController;
use App\Http\Controllers\Api\Marketing\MarketingController;
use App\Http\Controllers\Api\Marketing\PublicUnsubscribeController;
use App\Http\Controllers\Api\Me\MeController;
use App\Http\Controllers\Api\Me\MfaController;
use App\Http\Controllers\Api\Me\NotificationController;
use App\Http\Controllers\Api\Mobile\OperatorController;
use App\Http\Controllers\Api\Mobile\PassengerController;
use App\Http\Controllers\Api\Ops\AssignmentController;
use App\Http\Controllers\Api\Ops\IncidentController;
use App\Http\Controllers\Api\Ops\OrderController;
use App\Http\Controllers\Api\Ops\RoomingController;
use App\Http\Controllers\Api\Ops\TravelDocController;
use App\Http\Controllers\Api\Ops\VoucherController;
use App\Http\Controllers\Api\Platform\BillingConsoleController;
use App\Http\Controllers\Api\Platform\PaymentWebhookController;
use App\Http\Controllers\Api\Platform\PlatformAuthController;
use App\Http\Controllers\Api\Platform\TenantController as PlatformTenantController;
use App\Http\Controllers\Api\Quotes\PortalQuoteController;
use App\Http\Controllers\Api\Quotes\PublicQuoteController;
use App\Http\Controllers\Api\Quotes\QuoteActionController;
use App\Http\Controllers\Api\Quotes\QuoteController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\Site\BookingEngineController;
use App\Http\Controllers\Api\Site\SiteAccountController;
use App\Http\Controllers\Api\Site\SiteCartController;
use App\Http\Controllers\Api\Site\SiteCatalogController;
use App\Http\Controllers\Api\Site\SiteIntakeController;
use App\Http\Controllers\Api\Supplier\BackofficeController as SupplierBackofficeController;
use App\Http\Controllers\Api\Supplier\ExtranetController;
use App\Http\Controllers\Api\Treasury\TreasuryController;
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

    // Spec 09 §6.2: aceptar invitación (público, el token resuelve tenant y usuario).
    Route::middleware('throttle:login')->group(function () {
        Route::get('auth/invitations/{token}', [InvitationController::class, 'show']);
        Route::post('auth/invitations/{token}/accept', [InvitationController::class, 'accept']);
    });
    // Spec 13 §6: OpenAPI 3.1 de la API pública y webhook de la pasarela de cobro de la plataforma (firma + idempotencia por evento).
    Route::get('docs/openapi.json', [PublicApiController::class, 'openapi']);
    Route::post('platform/webhooks/payments/{gateway_code}', PaymentWebhookController::class)->middleware('throttle:60,1');

    // Spec 13: API pública del tenant (guard api-key; el scope se exige por ruta y el rate limit es por key).
    Route::prefix('ext')->group(function () {
        $x = ExtApiController::class;
        Route::get('reservations', [$x, 'reservations'])->middleware('api.key:reservations:read');
        Route::get('reservations/{code}', [$x, 'reservation'])->middleware('api.key:reservations:read');
        Route::get('products', [$x, 'products'])->middleware('api.key:catalog:read');
        Route::get('clients', [$x, 'clients'])->middleware('api.key:clients:read');
        Route::post('leads', [$x, 'createLead'])->middleware('api.key:leads:write');
        Route::get('webhooks', [$x, 'webhooks'])->middleware(['api.key:webhooks:manage', 'feature:webhooks']);
        Route::post('webhooks', [$x, 'createWebhook'])->middleware(['api.key:webhooks:manage', 'feature:webhooks']);
    });

    // EXP-BR-02: descarga por URL firmada de 5 minutos.
    Route::get('files/exports/{export}', [AdminReportController::class, 'file'])->middleware('signed')->name('exports.file');

    // Paso MFA: token con ability mfa:verify (sin EnsureMfaVerified).
    // Enlace público del cliente (spec 03 §6): sin sesión; el token identifica tenant y cotización.
    Route::prefix('public/quotes')->middleware('throttle:public-quote')->group(function () {
        Route::get('{token}', [PublicQuoteController::class, 'show']);
        Route::post('{token}/approve', [PublicQuoteController::class, 'approve'])->middleware('idempotent');
        Route::post('{token}/reject', [PublicQuoteController::class, 'reject'])->middleware('idempotent');
    });

    // Spec 14 §6: baja en un clic con token firmado (sin sesión; el tenant va en el token).
    Route::get('public/unsubscribe/{token}', [PublicUnsubscribeController::class, 'show'])->middleware('throttle:public-shop');
    Route::post('public/unsubscribe/{token}', [PublicUnsubscribeController::class, 'confirm'])->middleware('throttle:public-shop');

    // Spec 10 §6: entradas de canal de la bandeja omnicanal (firma HMAC / token del canal / rate limit).
    Route::middleware('tenant.public')->group(function () {
        $wh = InboundWebhookController::class;
        Route::get('integrations/whatsapp/webhook', [$wh, 'whatsappVerify']);
        Route::post('integrations/whatsapp/webhook', [$wh, 'whatsapp']);
        Route::post('integrations/email/inbound', [$wh, 'email']);
        // Spec 14 §6: webhooks de marketing (estados de entrega y «BAJA», plantillas de Meta, eventos del ESP).
        Route::post('integrations/whatsapp/webhooks/messages', [$wh, 'whatsapp']);
        Route::post('integrations/whatsapp/webhooks/templates', [$wh, 'whatsappTemplates']);
        Route::post('integrations/email/webhooks', [$wh, 'emailEvents']);
        // Spec 17 §6: acuses de mensajes al pasajero (SES, WhatsApp Cloud API, FCM).
        Route::post('webhooks/providers/{provider}', ProviderAckController::class)->whereIn('provider', ['ses', 'whatsapp', 'fcm']);
        Route::post('public/contact', [$wh, 'contact'])->middleware('throttle:public-shop');
    });

    // Spec 08 §6.3–6.7: tienda pública (consumida por la espec. 15), CMS público, webhook de pagos y acceso del pasajero por enlace.
    Route::middleware(['tenant.public', 'throttle:public-shop'])->group(function () {
        Route::prefix('public/shop')->group(function () {
            Route::get('products', [PublicShopController::class, 'products']);
            Route::get('products/{slug}', [PublicShopController::class, 'product']);
            Route::post('price', [PublicShopController::class, 'price']);
            Route::post('availability', [PublicShopController::class, 'availability']);
            Route::post('orders', [PublicShopController::class, 'createOrder'])->middleware('idempotent');
            Route::get('orders/{code}', [PublicShopController::class, 'order']);
            Route::post('orders/{code}/payments', [PublicShopController::class, 'pay'])->middleware('idempotent');
        });
        Route::get('public/cms/contents/{slug}', [CmsController::class, 'publicShow']);
        Route::get('public/cms/placements/{placement}', [CmsController::class, 'publicPlacement']);
        Route::post('webhooks/payments/{provider}', [PublicShopController::class, 'webhook']);
        Route::post('portal/passenger/auth/link', [PassengerPortalController::class, 'requestLink'])->middleware('throttle:login');
        Route::post('portal/passenger/auth/verify', [PassengerPortalController::class, 'verify'])->middleware('throttle:login');
    });

    // Spec 15 §6: sitio web público (catálogo, carrito, checkout, captación y cuenta del cliente).
    Route::middleware(['tenant.public', 'throttle:site-read'])->prefix('public')->group(function () {
        $cat = SiteCatalogController::class;
        Route::get('site', [$cat, 'site']);
        Route::get('destinations', [$cat, 'destinations']);
        Route::get('destinations/{slug}', [$cat, 'destination']);
        Route::get('products', [$cat, 'products']);
        Route::get('products/{slug}', [$cat, 'product']);
        Route::get('products/{slug}/calendar', [$cat, 'calendar']);
        Route::get('products/{slug}/departures', [$cat, 'departures']);
        Route::get('departures/guaranteed', [$cat, 'guaranteed']);
        Route::get('offers', [$cat, 'offers']);
        Route::get('posts', [$cat, 'posts']);
        Route::get('posts/{slug}', [$cat, 'post']);
        Route::get('pages/{slug}', [$cat, 'page']);
        Route::post('price-quote', [$cat, 'quote']);
        Route::post('availability', [$cat, 'availability']);
        // Spec 16 §6.1: motor de reservas (sitio, widget, CMS externo).
        Route::get('products/{id}/availability', [BookingEngineController::class, 'availability']);
        Route::post('quote', [BookingEngineController::class, 'quote']);
        // Spec 17 §6: widget (clave pública X-Qhapaq-Key + Origin verificado, o X-Tenant-Slug desde el BFF) y reseñas.
        Route::get('widget/config', [WidgetController::class, 'publicConfig']);
        Route::get('widget/products/{code}', [WidgetController::class, 'publicProduct']);
        Route::get('widget-handoffs/{code}', [WidgetController::class, 'checkout']);
        Route::get('reviews', [ReviewsController::class, 'public']);
        Route::get('carts/current', [SiteCartController::class, 'show']);
        Route::get('orders/{code}', [SiteCartController::class, 'order']);

        $in = SiteIntakeController::class;
        Route::get('chat/sessions/{session}/messages', [$in, 'chatMessages']);
        Route::get('analytics/events/{session}', [$in, 'recent']);
        // Eventos de analítica: límite propio para no consumir el de carrito/checkout al navegar rápido.
        Route::post('analytics/events', [$in, 'events'])->withoutMiddleware('throttle:site-read')->middleware('throttle:site-events');
        Route::get('newsletter/confirm/{token}', [$in, 'confirm']);
        Route::get('claims', [ClaimBookPortalController::class, 'publicForm']);
    });

    Route::middleware(['tenant.public', 'throttle:site-write'])->prefix('public')->group(function () {
        $cart = SiteCartController::class;
        Route::post('carts', [$cart, 'create'])->middleware('idempotent');
        Route::post('carts/current/items', [$cart, 'add']);
        Route::patch('carts/current/items/{line}', [$cart, 'update'])->whereNumber('line');
        Route::delete('carts/current/items/{line}', [$cart, 'remove'])->whereNumber('line');
        Route::post('carts/current/items/{line}/hold', [$cart, 'rehold'])->whereNumber('line');
        Route::put('carts/current/promo', [$cart, 'promo']);
        Route::delete('carts/current/promo', [$cart, 'promo']);
        Route::patch('carts/current/preferences', [$cart, 'preferences']);
        Route::post('checkout/validate', [$cart, 'validateStep']);
        Route::post('checkout/orders', [$cart, 'createOrder'])->middleware('idempotent');
        Route::post('bookings', [BookingEngineController::class, 'book'])->middleware('idempotent');
        Route::post('widget-handoffs', [WidgetController::class, 'handoff'])->middleware('idempotent');
        Route::post('orders/{code}/payments', [$cart, 'pay'])->middleware('idempotent');
        Route::post('orders/lookup', [$cart, 'lookup'])->middleware('throttle:site-lookup');

        $in = SiteIntakeController::class;
        Route::post('leads', [$in, 'lead']);
        Route::post('contact-form', [$in, 'contact']);
        Route::post('chat/sessions', [$in, 'chatStart']);
        Route::post('chat/sessions/{session}/messages', [$in, 'chatPost']);
        Route::post('newsletter', [$in, 'subscribe']);
        Route::post('newsletter/confirm/{token}', [$in, 'confirm']);
        Route::post('newsletter/unsubscribe', [$in, 'unsubscribe']);
        Route::post('claims', [ClaimBookPortalController::class, 'publicStore'])->middleware(['throttle:claim-book', 'idempotent']);

        Route::post('auth/register', [SiteAccountController::class, 'register'])->middleware('throttle:login');
        Route::post('auth/login', [SiteAccountController::class, 'login'])->middleware('throttle:login');
    });

    // Lecturas de la cuenta con el límite de lectura (cada página del sitio consulta `me`); escrituras además con el de escritura.
    Route::middleware(['auth:sanctum', 'tenant.resolve', 'tenant.active', 'throttle:site-read'])->prefix('public/me')->group(function () {
        $acc = SiteAccountController::class;
        Route::get('/', [$acc, 'me']);
        Route::get('reservations', [$acc, 'reservations']);
        Route::get('reservations/{code}', [$acc, 'reservation']);
        Route::get('vouchers', [$acc, 'vouchers']);
        Route::get('receivables', [$acc, 'receivables']);
        Route::get('wishlist', [$acc, 'wishlist']);
        Route::middleware('throttle:site-write')->group(function () use ($acc) {
            Route::post('logout', [$acc, 'logout']);
            Route::patch('/', [$acc, 'update']);
            Route::post('receivables/{id}/pay', [$acc, 'pay'])->middleware('idempotent');
            Route::post('wishlist/{product}', [$acc, 'wish']);
            Route::delete('wishlist/{product}', [$acc, 'wish']);
            Route::post('arco', [$acc, 'arco']);
        });
    });

    // Spec 12 §6: hoja de reclamación desde el sitio web (5/min por IP + captcha simulado).
    Route::prefix('public/{tenant}/claim-book')->middleware('tenant.public')->group(function () {
        Route::get('/', [ClaimBookPortalController::class, 'publicForm'])->middleware('throttle:public-shop');
        Route::post('/', [ClaimBookPortalController::class, 'publicStore'])->middleware(['throttle:claim-book', 'idempotent']);
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

        // Spec 05 §6.7–6.8: apps móviles. Operador = guía/conductor (experiencia mobile) o vista previa interna;
        // pasajero = portal del pasajero o vista previa interna. El alcance por recurso/reserva va en los servicios.
        Route::prefix('mobile')->group(function () {
            Route::post('devices', [OperatorController::class, 'registerDevice'])->middleware('experience:mobile,portal,backoffice');
            Route::delete('devices/{device}', [OperatorController::class, 'revokeDevice'])->middleware('experience:mobile,portal,backoffice');
            Route::prefix('operator')->middleware(['experience:mobile,backoffice', 'perm:operations.view'])->group(function () {
                Route::get('me', [OperatorController::class, 'me']);
                Route::get('bootstrap', [OperatorController::class, 'bootstrap']);
                Route::get('assignments/{assignment}', [OperatorController::class, 'assignment']);
                Route::post('actions', [OperatorController::class, 'action']);
                Route::post('sync', [OperatorController::class, 'sync']);
                Route::get('incidents', [OperatorController::class, 'incidents']);
                Route::post('incidents', [OperatorController::class, 'storeIncident'])->middleware(['perm:operations.incidents', 'idempotent']);
            });
            Route::prefix('passenger')->middleware(['experience:portal,backoffice', 'perm:reservations.view'])->group(function () {
                Route::get('trip', [PassengerController::class, 'trip']);
                Route::get('itinerary', [PassengerController::class, 'itinerary']);
                Route::get('vouchers', [PassengerController::class, 'vouchers']);
                Route::get('vouchers/{voucher}', [PassengerController::class, 'voucher']);
                Route::get('documents', [PassengerController::class, 'documents']);
                Route::get('documents/{document}/download', [PassengerController::class, 'download']);
                Route::get('alerts', [PassengerController::class, 'alerts']);
                Route::post('alerts/{alert}/read', [PassengerController::class, 'readAlert']);
                Route::get('messages', [PassengerController::class, 'messages']);
                Route::post('messages', [PassengerController::class, 'postMessage'])->middleware('throttle:30,1');
                Route::get('support', [PassengerController::class, 'support']);
            });
        });

        // Spec 08 §6.2: portal de agencias (usuario B2B; interno con channels.b2b.manage previsualiza en solo lectura).
        Route::prefix('b2b')->middleware('experience:portal,backoffice')->group(function () {
            $b = B2bPortalController::class;
            Route::get('me', [$b, 'me']);
            Route::get('catalog', [$b, 'catalog']);
            Route::get('catalog/{product}/departures', [$b, 'departures']);
            Route::post('reservations/quote-price', [$b, 'quotePrice']);
            Route::post('reservations', [$b, 'reserve'])->middleware('idempotent');
            Route::get('reservations', [$b, 'reservations']);
            Route::get('reservations/{code}', [$b, 'reservation']);
            Route::get('vouchers/{code}', [$b, 'voucher']);
            Route::get('statement', [$b, 'statement']);
            Route::post('receivables/{receivable}/payment-reports', [$b, 'reportPayment'])->middleware('idempotent');
            Route::post('settlement-requests', [$b, 'settle'])->middleware('idempotent');
        });

        // Spec 08 §6.6: portal del cliente (PCL-BR-01: su cuenta).
        Route::prefix('portal/client')->middleware('experience:portal')->group(function () {
            $c = ClientPortalController::class;
            Route::get('home', [$c, 'home']);
            Route::get('quotes', [$c, 'quotes']);
            Route::get('quotes/{code}', [$c, 'quote']);
            Route::post('quotes/{code}/view', [$c, 'view']);
            Route::post('quotes/{code}/approve', [$c, 'approve'])->middleware('idempotent');
            Route::post('quotes/{code}/reject', [$c, 'reject']);
            Route::post('quotes/{code}/update-request', [$c, 'updateRequest']);
            Route::get('reservations', [$c, 'reservations']);
            Route::get('reservations/{code}', [$c, 'reservation']);
            Route::get('documents', [$c, 'documents']);
            Route::get('receivables', [$c, 'receivables']);
            Route::get('payments', [$c, 'payments']);
            Route::post('receivables/{receivable}/payments', [$c, 'pay'])->middleware('idempotent');
            Route::get('notifications', [$c, 'notifications']);
            Route::get('tickets', [$c, 'tickets']);
            Route::post('tickets', [$c, 'storeTicket'])->middleware('throttle:30,1');
        });

        // Spec 08 §6.7: portal del pasajero (PPX-BR-01); internos con operations.assign previsualizan con ?reservation=.
        Route::prefix('portal/passenger')->middleware(['experience:portal,backoffice', 'perm:reservations.view,operations.assign'])->group(function () {
            $p = PassengerPortalController::class;
            Route::get('trip', [$p, 'trip']);
            Route::get('itinerary', [$p, 'itinerary']);
            Route::get('hotels', [$p, 'hotels']);
            Route::get('transport', [$p, 'transport']);
            Route::get('activities', [$p, 'activities']);
            Route::get('vouchers', [$p, 'vouchers']);
            Route::get('vouchers/{code}', [$p, 'voucher']);
            Route::get('documents', [$p, 'documents']);
            Route::get('alerts', [$p, 'alerts']);
            Route::post('alerts/{incident}/ack', [$p, 'ack']);
            Route::get('contacts', [$p, 'contacts']);
            Route::get('messages', [$p, 'inbox']);
            Route::post('messages', [$p, 'message'])->middleware('throttle:30,1');
            Route::post('messages/read-all', [$p, 'read']);
            Route::post('messages/{message}/read', [$p, 'read'])->whereUuid('message');
            Route::get('feedback', [$p, 'feedbackList']);
            Route::post('feedback', [$p, 'feedback'])->middleware('idempotent');
            Route::get('companions', [$p, 'companions']);
            Route::put('companions', [$p, 'updateCompanions']);
        });

        // Spec 12 §6: libro de reclamaciones en el portal del cliente y del pasajero (LR-BR-09); interno con crm.claims.manage = vista previa.
        Route::prefix('portal/claim-book')->middleware('experience:portal,backoffice')->group(function () {
            $lb = ClaimBookPortalController::class;
            Route::get('form', [$lb, 'form']);
            Route::get('/', [$lb, 'index']);
            Route::post('/', [$lb, 'store'])->middleware(['throttle:30,1', 'idempotent']);
            Route::get('{code}', [$lb, 'show']);
            Route::get('{code}/certificate', [$lb, 'certificate']);
        });

        // Spec 06 §6: extranet de proveedores. `supplier` exige rol Proveedor con vínculo (SUP-BR-01) y el permiso dentro de su provider_role.
        Route::prefix('supplier')->middleware(['experience:portal', 'supplier'])->group(function () {
            $x = ExtranetController::class;
            $respond = 'supplier:supplier.requests.respond';
            $rooming = 'supplier:supplier.rooming.manage';
            Route::get('me', [$x, 'me']);
            Route::get('dashboard', [$x, 'dashboard']);
            Route::get('requests', [$x, 'requests']);
            Route::get('requests/{service}', [$x, 'request'])->whereUuid('service');
            Route::post('requests/{service}/{action}', [$x, 'respond'])->whereUuid('service')->whereIn('action', ['confirm', 'reject', 'alternative'])->middleware([$respond, 'idempotent']);
            Route::get('rooming-lists', [$x, 'rooming']);
            Route::post('rooming-lists/{list}/{action}', [$x, 'roomingAction'])->whereUuid('list')->whereIn('action', ['acknowledge', 'confirm'])->middleware([$rooming, 'idempotent']);
            Route::post('rooming-rooms/{room}/observations', [$x, 'observe'])->whereUuid('room')->middleware([$rooming, 'idempotent']);
            Route::get('vouchers', [$x, 'vouchers']);
            Route::post('vouchers/redeem', [$x, 'redeem'])->middleware(['supplier:supplier.vouchers.redeem', 'idempotent']);
            Route::get('payables', [$x, 'payables'])->middleware('supplier:supplier.payables.view');
            Route::post('payables/{payable}/invoices', [$x, 'uploadInvoice'])->whereUuid('payable')->middleware(['supplier:supplier.invoices.upload', 'idempotent']);
            Route::get('invoices/{invoice}/file', [$x, 'invoiceFile'])->whereUuid('invoice')->middleware('supplier:supplier.payables.view');
            Route::get('incidents', [$x, 'incidents']);
            Route::get('incidents/{incident}', [$x, 'incident']);
            Route::post('incidents', [$x, 'storeIncident'])->middleware(['supplier:supplier.incidents.report', 'idempotent']);
            Route::post('incidents/{incident}/comments', [$x, 'comment'])->middleware('supplier:supplier.incidents.report');
            Route::get('contract', [$x, 'contract']);
            Route::get('rates', [$x, 'rates']);
            Route::get('rate-requests', [$x, 'rateRequests']);
            Route::post('rate-requests', [$x, 'storeRateRequest'])->middleware(['supplier:supplier.rates.request', 'idempotent']);
            Route::post('rate-requests/{rateRequest}/withdraw', [$x, 'withdrawRateRequest'])->whereUuid('rateRequest')->middleware('supplier:supplier.rates.request');
        });

        // Spec 13 §6: entitlements efectivos (menú de Next; UX, no seguridad).
        Route::get('billing/entitlements', [SubscriptionController::class, 'entitlements']);

        Route::middleware('experience:backoffice')->group(function () {
            Route::get('me/tenant', [MeController::class, 'tenant']);

            // Spec 13 §6: suscripción del tenant (Tenant Admin).
            Route::prefix('billing')->middleware('perm:admin.billing.manage')->group(function () {
                $b = SubscriptionController::class;
                Route::get('subscription', [$b, 'show']);
                Route::patch('subscription', [$b, 'update']);
                Route::post('subscription/preview-change', [$b, 'previewChange']);
                Route::post('subscription/change', [$b, 'change'])->middleware('idempotent');
                Route::delete('subscription/scheduled-change', [$b, 'cancelScheduledChange']);
                Route::post('subscription/cancel', [$b, 'cancel']);
                Route::post('subscription/resume', [$b, 'resume']);
                Route::get('usage', [$b, 'usage']);
                Route::get('usage/history', [$b, 'history']);
                Route::get('plan-rules', [$b, 'planRules']);
                Route::post('addons/{code}', [$b, 'addAddon'])->middleware('idempotent');
                Route::patch('addons/{code}', [$b, 'updateAddon'])->middleware('idempotent');
                Route::delete('addons/{code}', [$b, 'removeAddon']);
                Route::post('coupon', [$b, 'coupon']);
                Route::get('invoices', [$b, 'invoices']);
                Route::get('invoices/{number}', [$b, 'invoice']);
                Route::get('invoices/{number}/pdf', [$b, 'pdf']);
                Route::post('invoices/{number}/pay', [$b, 'pay']);
                Route::post('invoices/{number}/resend', [$b, 'resend']);
                Route::post('payment-methods/setup-intent', [$b, 'setupIntent']);
                Route::put('payment-methods/default', [$b, 'setDefaultMethod'])->middleware('idempotent');
            });

            // Spec 13 §6: API pública del tenant (Tenant Admin + entitlement por grupo).
            Route::prefix('integrations')->middleware('perm:admin.api.manage')->group(function () {
                $p = PublicApiController::class;
                Route::get('public-api', [$p, 'overview']);
                Route::get('api-limits', [$p, 'limits']);
                Route::middleware('feature:api_access')->group(function () use ($p) {
                    Route::get('api-keys', [$p, 'keys']);
                    Route::post('api-keys', [$p, 'createKey']);
                    Route::delete('api-keys/{key}', [$p, 'revokeKey']);
                });
                Route::middleware('feature:webhooks')->group(function () use ($p) {
                    Route::get('webhooks', [$p, 'webhooks']);
                    Route::post('webhooks', [$p, 'createWebhook']);
                    Route::patch('webhooks/{webhook}', [$p, 'updateWebhook']);
                    Route::delete('webhooks/{webhook}', [$p, 'deleteWebhook']);
                    Route::post('webhooks/{webhook}/rotate-secret', [$p, 'rotateSecret']);
                    Route::post('webhooks/{webhook}/ping', [$p, 'ping']);
                    Route::get('webhook-deliveries', [$p, 'deliveries']);
                    Route::get('webhook-deliveries/{delivery}', [$p, 'delivery']);
                    Route::post('webhook-deliveries/{delivery}/redeliver', [$p, 'redeliver'])->middleware('idempotent');
                });
                Route::middleware('feature:oauth_apps')->group(function () use ($p) {
                    Route::get('oauth-apps', [$p, 'oauthApps']);
                    Route::post('oauth-apps', [$p, 'createOauthApp']);
                    Route::post('oauth-apps/{app}/secret', [$p, 'rotateOauthSecret']);
                    Route::delete('oauth-apps/{app}', [$p, 'revokeOauthApp']);
                });
            });
            Route::get('search', SearchController::class);
            Route::post('access-requests', [AccessRequestController::class, 'store']);
            Route::get('access-requests', [AccessRequestController::class, 'index'])->middleware('perm:admin.users.manage');
            Route::get('access-requests/{accessRequest}', [AccessRequestController::class, 'show'])->middleware('perm:admin.users.manage');

            // CRM (spec 01 §6). Permiso por endpoint; ownership en los servicios (CrmAccess).
            Route::prefix('crm')->group(function () {
                Route::get('meta', CrmMetaController::class)->middleware('perm:crm.leads.view,crm.clients.manage,finance.receivables');
                Route::get('owners', [ActivityController::class, 'owners'])->middleware('perm:crm.leads.manage,crm.opportunities.manage,crm.activities.manage,crm.clients.manage');

                // Spec 14 §6: metas comerciales (Ventas: solo las propias, MET-BR-07).
                $st = SalesTargetController::class;
                $tview = 'perm:crm.targets.view,crm.targets.manage';
                $tmanage = 'perm:crm.targets.manage';
                Route::get('sales-targets', [$st, 'index'])->middleware($tview);
                Route::get('sales-targets/ranking', [$st, 'ranking'])->middleware($tview);
                Route::post('sales-targets', [$st, 'store'])->middleware($tmanage);
                Route::get('sales-targets/{target}', [$st, 'show'])->middleware($tview);
                Route::post('sales-targets/{target}/activate', [$st, 'activate'])->middleware($tmanage);
                Route::post('sales-targets/{target}/adjust', [$st, 'adjust'])->middleware($tmanage);
                Route::post('sales-targets/{target}/void', [$st, 'void'])->middleware($tmanage);
                Route::post('sales-targets/{target}/close', [$st, 'close'])->middleware([$tmanage, 'idempotent']);
                Route::get('sales-teams', [$st, 'teams'])->middleware($tmanage);
                Route::put('sales-teams', [$st, 'saveTeam'])->middleware($tmanage);
                Route::get('sales-target-policy', [$st, 'policy'])->middleware($tmanage);
                Route::put('sales-target-policy', [$st, 'savePolicy'])->middleware('perm:admin.settings.manage');

                // Spec 12 §6: libro de reclamaciones (hojas = casos de postventa con is_book).
                Route::prefix('claim-book')->group(function () {
                    $cb = ClaimBookController::class;
                    Route::get('settings', [$cb, 'settings'])->middleware('perm:crm.claims.manage,admin.settings.manage');
                    Route::put('settings', [$cb, 'saveSettings'])->middleware('perm:admin.settings.manage');
                    Route::middleware('perm:crm.claims.manage')->group(function () use ($cb) {
                        Route::get('/', [$cb, 'index']);
                        Route::get('form', [$cb, 'form']);
                        Route::get('reports', [$cb, 'reports']);
                        Route::post('exports', [$cb, 'export'])->middleware('perm:reports.export');
                        Route::post('alerts', [$cb, 'runAlerts']);
                        Route::post('/', [$cb, 'store'])->middleware('idempotent');
                        Route::get('{claim}', [$cb, 'show']);
                        Route::post('{claim}/review', [$cb, 'review']);
                        Route::post('{claim}/response', [$cb, 'respond'])->middleware('idempotent');
                        Route::post('{claim}/response/resend', [$cb, 'resend']);
                        Route::post('{claim}/close', [$cb, 'close']);
                        Route::patch('{claim}/links', [$cb, 'links']);
                        Route::get('{claim}/certificate', [$cb, 'certificate']);
                    });
                });

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
            // Spec 07 §6: Finanzas y Contabilidad. OP = `finance.payments.register` además del permiso del recurso (FIN-BR-59).
            Route::prefix('finance')->group(function () {
                $op = 'perm:finance.payments.register';
                $rec = 'perm:finance.receivables';
                $pay = PaymentController::class;
                $rc = FinReceivableController::class;
                $pb = FinPayableController::class;
                $cm = FinCommissionController::class;
                $pf = FinProfitabilityController::class;
                $iv = FinInvoiceController::class;
                $bk = FinReconciliationController::class;
                $pn = FinPanelController::class;

                Route::get('receivables', [$rc, 'index'])->middleware($rec);
                Route::get('receivables/summary', [$rc, 'summary'])->middleware($rec);
                Route::post('receivables/advances', [$rc, 'advance'])->middleware([$rec, $op, 'idempotent']);
                Route::get('receivables/{code}', [$rc, 'show'])->middleware($rec);
                Route::post('receivables/{code}/reminders', [$rc, 'remind'])->middleware([$rec, $op, 'idempotent']);
                Route::get('receivables/{code}/invoice-draft', [$iv, 'draft'])->middleware('perm:finance.invoicing');
                Route::get('reservations/{code}/account', [$rc, 'account'])->middleware($rec);

                Route::get('payments', [$pay, 'index'])->middleware('perm:finance.receivables,finance.payments.register');
                Route::get('payments/summary', [$pay, 'summary'])->middleware('perm:finance.receivables,finance.payments.register');
                Route::get('payment-methods', [$pay, 'methods'])->middleware('perm:finance.receivables,finance.payments.register,reservations.view');
                Route::post('payments', [$pay, 'store'])->middleware([$op, 'idempotent']);
                Route::get('payments/{code}', [$pay, 'show'])->middleware('perm:finance.receivables,finance.payments.register');
                Route::post('payments/{code}/validate', [$pay, 'validatePayment'])->middleware([$op, 'idempotent']);
                Route::post('payments/{code}/reject', [$pay, 'reject'])->middleware($op);
                Route::post('payments/{code}/retry', [$pay, 'retry'])->middleware($op);
                Route::post('payments/{code}/apply', [$pay, 'apply'])->middleware([$op, 'idempotent']);
                Route::post('payments/{code}/revert', [$pay, 'revert'])->middleware(['perm:finance.payments.revert', 'idempotent']);

                $payables = 'perm:finance.payables';
                Route::get('payables', [$pb, 'index'])->middleware($payables);
                Route::get('payables/summary', [$pb, 'summary'])->middleware($payables);
                Route::get('payables/by-provider', [$pb, 'byProvider'])->middleware($payables);
                Route::get('payables/{code}', [$pb, 'show'])->middleware($payables);
                Route::post('payables/{code}/approve', [$pb, 'approve'])->middleware($payables);
                Route::post('payables/{code}/schedule', [$pb, 'schedule'])->middleware([$payables, $op]);
                Route::post('payables/{code}/payouts', [$pb, 'payout'])->middleware([$payables, $op, 'idempotent']);
                Route::post('costs/preview', [$pb, 'previewCost'])->middleware($payables);
                Route::post('costs', [$pb, 'storeCost'])->middleware([$payables, $op, 'idempotent']);
                Route::get('bank-accounts', [$pb, 'bankAccounts'])->middleware('perm:finance.payables,finance.payments.register');

                $com = 'perm:finance.commissions';
                Route::get('commissions', [$cm, 'index'])->middleware($com);
                Route::get('commissions/summary', [$cm, 'summary'])->middleware($com);
                Route::get('commission-rules', [$cm, 'rules'])->middleware($com);
                Route::post('commissions/calculate', [$cm, 'calculate'])->middleware([$com, $op, 'idempotent']);
                Route::get('commissions/{code}', [$cm, 'show'])->middleware($com);
                Route::post('commissions/{code}/approve', [$cm, 'approve'])->middleware($com);
                Route::post('commissions/{code}/recalculate', [$cm, 'recalculate'])->middleware([$com, $op]);
                Route::post('commissions/{code}/pay', [$cm, 'pay'])->middleware([$com, $op, 'idempotent']);

                $prof = 'perm:finance.profitability';
                Route::get('profitability', [$pf, 'overview'])->middleware($prof);
                Route::get('profitability/reservations', [$pf, 'reservations'])->middleware($prof);
                Route::get('reservations/{code}/settlement', [$pf, 'settlement'])->middleware($prof);
                Route::post('reservations/{code}/settlement', [$pf, 'settle'])->middleware([$prof, $op, 'idempotent']);
                Route::post('reservations/{code}/account-statement', [$pf, 'accountStatement'])->middleware([$op, 'idempotent']);

                $inv = 'perm:finance.invoicing';
                Route::get('invoices', [$iv, 'index'])->middleware($inv);
                Route::get('invoices/summary', [$iv, 'summary'])->middleware($inv);
                Route::post('invoices/validate', [$iv, 'validateDraft'])->middleware($inv);
                Route::post('invoices', [$iv, 'store'])->middleware([$inv, 'idempotent']);
                Route::get('invoices/{number}', [$iv, 'show'])->middleware($inv);
                Route::post('invoices/{number}/status-check', [$iv, 'statusCheck'])->middleware($inv);
                Route::post('invoices/{number}/resend', [$iv, 'resend'])->middleware([$inv, 'idempotent']);
                Route::post('invoices/{number}/deliver', [$iv, 'deliver'])->middleware([$inv, 'idempotent']);

                Route::get('bank-lines', [$bk, 'lines'])->middleware($op);
                Route::post('bank-statements', [$bk, 'import'])->middleware([$op, 'idempotent']);
                Route::post('reconciliation/matching', [$bk, 'match'])->middleware([$op, 'idempotent']);
                Route::post('bank-lines/{code}/confirm', [$bk, 'confirm'])->middleware($op);
                Route::post('bank-lines/{code}/reject', [$bk, 'reject'])->middleware($op);
                Route::post('bank-lines/{code}/resolve', [$bk, 'resolve'])->middleware($op);
                Route::get('reconciliation/closures', [$bk, 'closures'])->middleware($op);
                Route::post('reconciliation/closures', [$bk, 'close'])->middleware([$op, 'idempotent']);

                Route::get('dashboards/finance', [$pn, 'finance'])->middleware($rec);
                Route::get('dashboards/accounting', [$pn, 'accounting'])->middleware($inv);
                Route::get('period-closes/{period}', [$pn, 'period'])->middleware($inv);
                Route::match(['put', 'delete'], 'period-closes/{period}/checks/{check}', [$pn, 'check'])->middleware($inv);
                Route::post('period-closes/{period}/close', [$pn, 'close'])->middleware([$inv, 'idempotent']);
            });
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
                // Spec 16 §6.2: pestaña «Reservas online» (motor de reservas y reglas del producto).
                $bk = BookingConfigController::class;
                Route::middleware($view)->group(function () use ($bk) {
                    Route::get('products/{ref}/booking-config', [$bk, 'show']);
                    Route::get('products/{ref}/booking-availability', [$bk, 'availability']);
                    Route::get('products/{ref}/availability-ranges', [$bk, 'listRanges']);
                    Route::get('products/{ref}/pax-rules', [$bk, 'pax']);
                    Route::post('products/{ref}/pax-rules/simulate', [$bk, 'simulatePax']);
                    Route::post('products/{ref}/booking-quote', [$bk, 'quote']);
                    Route::get('products/{ref}/addons', [$bk, 'addons']);
                    Route::get('products/{ref}/payment-policy', [$bk, 'payment']);
                    Route::get('products/{ref}/pickup', [$bk, 'pickup']);
                    Route::get('products/{ref}/rule-overrides', [$bk, 'overrides']);
                });
                Route::middleware($avail)->group(function () use ($bk) {
                    Route::post('products/{ref}/availability-ranges', [$bk, 'storeRange'])->name('products.availability-ranges.store');
                    Route::post('products/{ref}/availability-ranges/close-dates', [$bk, 'closeDates']);
                    Route::patch('products/{ref}/availability-ranges/{range}', [$bk, 'updateRange']);
                    Route::delete('products/{ref}/availability-ranges/{range}', [$bk, 'destroyRange']);
                    Route::put('products/{ref}/operation', [$bk, 'operation']);
                });
                Route::middleware($update)->group(function () use ($bk) {
                    Route::put('products/{ref}/pax-rules', [$bk, 'savePax']);
                    Route::delete('products/{ref}/pax-rules', [$bk, 'resetPax']);
                    Route::put('products/{ref}/rule-overrides/{rule}', [$bk, 'saveOverride']);
                    Route::delete('products/{ref}/rule-overrides/{rule}', [$bk, 'resetOverride']);
                });
                Route::middleware($rates)->group(function () use ($bk) {
                    Route::post('products/{ref}/addons', [$bk, 'storeAddon']);
                    Route::patch('products/{ref}/addons/{addon}', [$bk, 'updateAddon']);
                    Route::delete('products/{ref}/addons/{addon}', [$bk, 'destroyAddon']);
                    Route::put('products/{ref}/accommodation', [$bk, 'saveAccommodation']);
                    Route::post('products/{ref}/accommodation/legs', [$bk, 'storeLeg']);
                    Route::post('products/{ref}/accommodation/legs/reorder', [$bk, 'reorderLegs']);
                    Route::patch('products/{ref}/accommodation/legs/{leg}', [$bk, 'updateLeg']);
                    Route::delete('products/{ref}/accommodation/legs/{leg}', [$bk, 'destroyLeg']);
                    Route::post('products/{ref}/accommodation/legs/{leg}/hotels', [$bk, 'storeHotel']);
                    Route::patch('products/{ref}/accommodation/legs/{leg}/hotels/{hotel}', [$bk, 'updateHotel']);
                    Route::delete('products/{ref}/accommodation/legs/{leg}/hotels/{hotel}', [$bk, 'destroyHotel']);
                    Route::put('products/{ref}/payment-policy', [$bk, 'savePayment']);
                });
                Route::put('products/{ref}/pickup', [$bk, 'savePickup'])->middleware('perm:catalog.products.update,catalog.products.manage_availability');
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

            // Spec 05 §6.1–6.6: Operaciones. Venta y margen solo con permisos de rentabilidad (OpsPresenter / cost-summary).
            Route::prefix('ops')->group(function () {
                $view = 'perm:operations.view';
                $assign = 'perm:operations.assign';
                $vouchers = 'perm:operations.vouchers';
                $incidents = 'perm:operations.incidents';
                $rooming = 'perm:reservations.modify';
                $o = OrderController::class;
                $a = AssignmentController::class;
                $rm = RoomingController::class;
                $v = VoucherController::class;
                $i = IncidentController::class;

                Route::get('orders', [$o, 'index'])->middleware($view);
                Route::get('orders/kpis', [$o, 'kpis'])->middleware($view);
                Route::get('orders/candidates', [$o, 'candidates'])->middleware($assign);
                Route::post('orders', [$o, 'store'])->middleware([$assign, 'idempotent']);
                Route::get('orders/{order}', [$o, 'show'])->middleware($view);
                Route::patch('orders/{order}/coordinator', [$o, 'coordinator'])->middleware($assign);
                Route::post('orders/{order}/{action}', [$o, 'transition'])->whereIn('action', ['to-assignment', 'mark-assigned', 'confirm', 'start'])->middleware($assign);
                Route::get('orders/{order}/close-checks', [$o, 'closeChecks'])->middleware($view);
                Route::post('orders/{order}/close', [$o, 'close'])->middleware(['perm:operations.close', 'idempotent']);
                Route::get('orders/{order}/cost-summary', [$o, 'costSummary'])->middleware($view);
                Route::post('orders/{order}/tasks', [$o, 'storeTask'])->middleware($assign);
                Route::patch('order-tasks/{task}', [$o, 'updateTask'])->middleware($assign);
                Route::delete('order-tasks/{task}', [$o, 'destroyTask'])->middleware($assign);
                Route::post('orders/{order}/assignments', [$o, 'ensureAssignment'])->middleware($assign);
                Route::post('orders/{order}/evidences', [$i, 'storeOrderEvidences'])->middleware([$assign, 'idempotent']);

                Route::post('reservation-services/{service}/{action}', [$o, 'serviceAction'])
                    ->whereIn('action', ['supplier-request', 'supplier-confirmation', 'supplier-rejection', 'complete'])->middleware($assign);
                Route::get('supplier-requests', [$o, 'supplierRequests'])->middleware($view);

                Route::get('assignments', [$a, 'index'])->middleware($view);
                Route::post('assignments/{assignment}/validate', [$a, 'validateResources'])->middleware($assign);
                Route::put('assignments/{assignment}/resources', [$a, 'assign'])->middleware($assign);
                Route::post('assignments/{assignment}/confirm', [$a, 'confirm'])->middleware($assign);
                Route::post('assignments/{assignment}/release', [$a, 'release'])->middleware($assign);
                Route::get('resources', [$a, 'resources'])->middleware($view);
                Route::get('resources/availability', [$a, 'availability'])->middleware($view);
                Route::get('resources/document-alerts', [$a, 'documentAlerts'])->middleware($view);
                Route::get('resources/{type}/{id}/agenda', [$a, 'agenda'])->whereIn('type', ['guide', 'driver', 'vehicle'])->middleware($view);
                Route::patch('resources/{type}/{id}/status', [$a, 'status'])->whereIn('type', ['guide', 'driver', 'vehicle'])->middleware($assign);
                Route::get('calendar', [$a, 'calendar'])->middleware($view);

                Route::get('rooming-lists', [$rm, 'index'])->middleware($view);
                Route::post('rooming-lists', [$rm, 'store'])->middleware($rooming);
                Route::get('rooming-lists/{list}', [$rm, 'show'])->middleware($view);
                Route::get('rooming-lists/{list}/document', [$rm, 'document'])->middleware($view);
                Route::post('rooming-lists/{list}/validate', [$rm, 'validateRooms'])->middleware($rooming);
                Route::post('rooming-lists/{list}/rooms', [$rm, 'storeRoom'])->middleware($rooming);
                Route::post('rooming-lists/{list}/confirm', [$rm, 'transition'])->defaults('action', 'confirm')->middleware($rooming);
                Route::post('rooming-lists/{list}/send', [$rm, 'transition'])->defaults('action', 'send')->middleware([$rooming, 'idempotent']);
                Route::put('rooming-rooms/{room}', [$rm, 'updateRoom'])->middleware($rooming);
                Route::delete('rooming-rooms/{room}', [$rm, 'destroyRoom'])->middleware($rooming);

                Route::get('vouchers', [$v, 'index'])->middleware($view);
                Route::get('vouchers/pending-issue', [$v, 'pendingIssue'])->middleware($view);
                Route::post('vouchers/check', [$v, 'check'])->middleware($vouchers);
                Route::post('vouchers', [$v, 'store'])->middleware([$vouchers, 'idempotent']);
                Route::get('vouchers/{voucher}', [$v, 'show'])->middleware($view);
                Route::get('vouchers/{voucher}/pdf', [$v, 'pdf'])->middleware($view);
                Route::post('vouchers/{voucher}/{action}', [$v, 'action'])->whereIn('action', ['resend', 'versions', 'redeem', 'void'])->middleware([$vouchers, 'idempotent']);

                Route::get('incidents', [$i, 'index'])->middleware($view);
                Route::post('incidents', [$i, 'store'])->middleware([$incidents, 'idempotent']);
                Route::get('incidents/{incident}', [$i, 'show'])->middleware($view);
                Route::post('incidents/{incident}/evidences', [$i, 'storeIncidentEvidences'])->middleware($incidents);
                Route::match(['post', 'patch'], 'incidents/{incident}/{action}', [$i, 'action'])
                    ->whereIn('action', ['take', 'assignee', 'classification', 'resolve', 'close', 'reopen', 'comments'])->middleware($incidents);
                Route::get('evidences/{evidence}/file', [$i, 'file'])->middleware($view);
                Route::delete('evidences/{evidence}', [$i, 'destroyEvidence'])->middleware($assign);
            });

            // Spec 06 §6: contrapartes de la extranet en el back-office.
            $sb = SupplierBackofficeController::class;
            Route::get('ops/supplier-responses', [$sb, 'responses'])->middleware('perm:operations.view');
            Route::post('ops/supplier-responses/{response}/{action}', [$sb, 'reviewResponse'])->whereUuid('response')->whereIn('action', ['accept', 'reject'])
                ->middleware(['perm:operations.assign', 'idempotent']);
            Route::get('catalog/rate-requests', [$sb, 'rateRequests'])->middleware('perm:catalog.providers.manage,catalog.products.manage_rates');
            Route::post('catalog/rate-requests/{rateRequest}/{action}', [$sb, 'reviewRateRequest'])->whereIn('action', ['approve', 'reject'])
                ->middleware('perm:catalog.products.manage_rates');
            Route::get('catalog/providers/{provider}/users', [$sb, 'providerUsers'])->middleware('perm:catalog.providers.manage');
            Route::post('catalog/providers/{provider}/users', [$sb, 'inviteProviderUser'])->middleware(['perm:catalog.providers.manage', 'perm:admin.users.manage']);
            Route::post('fin/supplier-invoices/{invoice}/{action}', [$sb, 'reviewInvoice'])->whereUuid('invoice')->whereIn('action', ['validate', 'observe', 'reject'])
                ->middleware('perm:finance.payables');
            Route::get('fin/supplier-invoices/{invoice}/file', [$sb, 'invoiceFile'])->whereUuid('invoice')->middleware('perm:finance.payables,operations.view');

            // 6.6 Panel de Ventas y exportación
            Route::get('dashboard/sales', SalesDashboardController::class)->middleware('perm:crm.leads.view');
            Route::post('exports', ExportController::class)->middleware('throttle:exports');

            // Spec 08 §6.1: canal B2B (administración).
            Route::prefix('channels/b2b')->middleware(['perm:channels.b2b.manage', 'feature:b2b'])->group(function () {
                $a = B2bAdminController::class;
                Route::get('summary', [$a, 'summary']);
                Route::get('agencies', [$a, 'index']);
                Route::post('agencies', [$a, 'store']);
                Route::get('agencies/{agency}', [$a, 'show']);
                Route::patch('agencies/{agency}', [$a, 'update']);
                Route::post('agencies/{agency}/block', [$a, 'block']);
                Route::post('agencies/{agency}/unblock', [$a, 'unblock']);
                Route::get('agencies/{agency}/credit-movements', [$a, 'movements']);
                Route::post('agencies/{agency}/impersonation', [$a, 'impersonation']);
                Route::get('price-lists', [$a, 'priceLists']);
                Route::get('product-rates', [$a, 'productRates']);
                Route::put('product-rates/{product}/agencies/{agency}', [$a, 'saveRate']);
                Route::delete('product-rates/{product}/agencies/{agency}', [$a, 'removeRate']);
            });
            Route::get('channels/b2b/settlement-requests', [B2bAdminController::class, 'settlements'])->middleware(['perm:channels.b2b.manage,finance.commissions', 'feature:b2b']);

            // Spec 08 §6.3: gestión del e-commerce.
            Route::prefix('channels/ecommerce')->middleware(['perm:channels.ecommerce.manage', 'feature:ecommerce'])->group(function () {
                $e = EcommerceController::class;
                Route::get('summary', [$e, 'summary']);
                Route::get('orders', [$e, 'orders']);
                Route::get('orders/{code}', [$e, 'order']);
                Route::post('orders/{code}/payment-link', [$e, 'paymentLink'])->middleware('idempotent');
                Route::post('orders/{code}/cancel', [$e, 'cancel']);
                Route::get('products', [$e, 'products']);
                Route::post('products/{product}/{action}', [$e, 'publish'])->whereIn('action', ['publish', 'unpublish']);
                Route::get('promotions', [$e, 'promotions']);
                Route::post('products/{product}/promotions', [$e, 'storePromotion']);
                Route::post('promotions/{promotion}/{action}', [$e, 'promotionStatus'])->whereIn('action', ['activate', 'deactivate']);
            });

            // Spec 08 §6.4: CMS (acceso con channels.cms.publish; las transiciones editoriales se validan en el servicio).
            Route::prefix('cms/contents')->middleware(['perm:channels.cms.publish', 'feature:cms'])->group(function () {
                $m = CmsController::class;
                Route::get('/', [$m, 'index']);
                Route::post('/', [$m, 'store']);
                Route::post('preview', [$m, 'preview']);
                Route::get('{content}', [$m, 'show']);
                Route::patch('{content}', [$m, 'update']);
                Route::post('{content}/preview', [$m, 'preview']);
                Route::post('{content}/{action}', [$m, 'transition'])->whereIn('action', ['submit', 'return', 'approve', 'publish', 'unpublish']);
            });

            // Spec 08 §6.6: previsualización interna del portal del cliente (solo lectura; Ventas: su cartera).
            Route::prefix('channels/portal-preview/clients')->middleware('perm:channels.b2b.manage,channels.cms.publish,crm.clients.manage')->group(function () {
                $c = ClientPortalController::class;
                Route::get('/', [$c, 'previewClients']);
                Route::get('{client}/home', [$c, 'home']);
                Route::get('{client}/quotes', [$c, 'quotes']);
                Route::get('{client}/quotes/{code}', [$c, 'quote']);
                Route::get('{client}/reservations', [$c, 'reservations']);
                Route::get('{client}/reservations/{code}', [$c, 'reservation']);
                Route::get('{client}/documents', [$c, 'documents']);
                Route::get('{client}/receivables', [$c, 'receivables']);
                Route::get('{client}/payments', [$c, 'payments']);
                Route::get('{client}/notifications', [$c, 'notifications']);
                Route::get('{client}/tickets', [$c, 'tickets']);
            });

            // Spec 09 §6.2: usuarios y sedes (PROC-002).
            Route::prefix('iam')->middleware('perm:admin.users.manage')->group(function () {
                $u = AdminUserController::class;
                Route::get('users', [$u, 'index']);
                Route::post('users', [$u, 'store'])->middleware('idempotent');
                Route::get('users/{user}', [$u, 'show']);
                Route::patch('users/{user}', [$u, 'update']);
                Route::post('users/{user}/deactivate', [$u, 'deactivate']);
                Route::post('users/{user}/activate', [$u, 'activate']);
                Route::post('users/{user}/reset-mfa', [$u, 'resetMfa']);
                Route::post('users/{user}/resend-invitation', [$u, 'resendInvitation']);
                Route::get('sedes', [$u, 'sedes']);
                Route::post('sedes', [$u, 'storeSede']);
                Route::patch('sedes/{sede}', [$u, 'updateSede']);
                Route::post('sedes/{sede}/{action}', [$u, 'sedeStatus'])->whereIn('action', ['activate', 'deactivate']);
                Route::delete('sedes/{sede}', [$u, 'destroySede']);
            });

            // Spec 09 §6.3: roles y permisos.
            Route::prefix('iam')->middleware('perm:admin.roles.manage')->group(function () {
                $r = AdminRoleController::class;
                Route::get('roles', [$r, 'index']);
                Route::post('roles', [$r, 'store']);
                Route::patch('roles/{role}', [$r, 'update']);
                Route::delete('roles/{role}', [$r, 'destroy']);
                Route::get('permissions', [$r, 'permissions']);
                Route::get('role-permissions', [$r, 'matrix']);
                Route::put('roles/{role}/permissions/{permission}', [$r, 'grant']);
                Route::delete('roles/{role}/permissions/{permission}', [$r, 'revoke']);
                Route::post('roles/{role}/permissions/bulk', [$r, 'bulk']);
                Route::get('access-map', [$r, 'accessMap']);
            });

            // Spec 09 §6.4: configuración (PROC-003).
            Route::get('settings/fx-rates', [AdminSettingsController::class, 'fxRates'])->middleware('perm:admin.settings.manage,finance.treasury');
            Route::get('settings/travel-docs', [TravelDocController::class, 'settings'])->middleware('perm:operations.travel_docs,reservations.create');
            Route::put('settings/travel-docs', [TravelDocController::class, 'saveSettings'])->middleware('perm:operations.travel_docs');
            Route::prefix('settings')->middleware('perm:admin.settings.manage')->group(function () {
                $st = AdminSettingsController::class;
                Route::get('/', [$st, 'index']);
                Route::get('sequences', [$st, 'sequences']);
                Route::patch('sequences', [$st, 'updateSequences']);
                Route::post('sequences', [$st, 'addSequence']);
                Route::patch('{section}', [$st, 'update']);
            });

            // Spec 16 §6.2: centro de reglas de reserva del tenant.
            Route::prefix('admin/booking-rules')->middleware('perm:admin.settings.manage')->group(function () {
                $br = BookingRulesController::class;
                Route::get('/', [$br, 'index']);
                Route::post('/', [$br, 'store']);
                Route::get('templates', [$br, 'templates']);
                Route::post('apply-template', [$br, 'applyTemplate']);
                Route::post('reset', [$br, 'reset']);
                Route::post('simulate', [$br, 'simulate']);
                Route::get('audit', [$br, 'audit']);
                Route::get('{rule}/overrides', [$br, 'overrides']);
                Route::patch('{rule}', [$br, 'update']);
                Route::delete('{rule}', [$br, 'destroy']);
            });

            // Spec 17 §6: mensajes al pasajero (plantillas: admin; programación y envíos: admin u operaciones, MSG-03).
            $pm = PaxMessagesController::class;
            $pmOps = 'perm:admin.settings.manage,operations.assign';
            Route::prefix('notifications')->group(function () use ($pm, $pmOps) {
                Route::get('pax-templates', [$pm, 'templates'])->middleware($pmOps);
                Route::post('pax-templates/{id}/preview', [$pm, 'preview'])->middleware($pmOps);
                Route::middleware('perm:admin.settings.manage')->group(function () use ($pm) {
                    Route::post('pax-templates', [$pm, 'storeTemplate'])->middleware('idempotent');
                    Route::patch('pax-templates/{id}', [$pm, 'updateTemplate']);
                    Route::delete('pax-templates/{id}', [$pm, 'destroyTemplate']);
                    Route::post('pax-templates/{id}/duplicate', [$pm, 'duplicate'])->middleware('idempotent');
                    Route::post('pax-templates/{id}/test', [$pm, 'test'])->middleware('idempotent');
                });
                Route::middleware($pmOps)->group(function () use ($pm) {
                    Route::get('pax-messages', [$pm, 'index']);
                    Route::get('pax-messages/reservations', [$pm, 'reservations']);
                    Route::post('pax-messages/reschedule', [$pm, 'reschedule']);
                    Route::post('pax-messages/sync-acks', [$pm, 'syncAcks']);
                    Route::post('pax-messages/{id}/{action}', [$pm, 'action'])->whereIn('action', ['send-now', 'cancel', 'retry'])->middleware('idempotent');
                });
            });
            Route::post('reservations/{reservation}/pax-messages', [$pm, 'manual'])->middleware([$pmOps, 'idempotent']);

            // Spec 17 §6: monedas y tipos de cambio (DEMO v2.2). Activar/cobro solo admin (se valida en el servicio).
            Route::prefix('finance')->middleware('perm:admin.settings.manage,finance.treasury')->group(function () {
                $cu = CurrenciesController::class;
                Route::get('currencies', [$cu, 'index']);
                Route::get('currencies/convert', [$cu, 'convert']);
                Route::post('currencies/refresh', [$cu, 'refresh']);
                Route::get('currencies/{code}/rates', [$cu, 'rates']);
                Route::patch('currencies/{code}', [$cu, 'update']);
                Route::get('fx-rates', [$cu, 'fxRates']);
                Route::get('fx-providers', [$cu, 'providers']);
                Route::post('fx-providers/{code}/test', [$cu, 'testProvider'])->middleware('perm:admin.settings.manage');
                Route::patch('fx-settings', [$cu, 'settings'])->middleware('perm:admin.settings.manage');
            });

            // Spec 17 §6: reseñas externas (conectores solo admin) e invitaciones postviaje.
            $rv = ReviewsController::class;
            $rvRead = 'perm:admin.settings.manage,channels.ecommerce.manage,crm.clients.manage';
            Route::get('integrations/review-sources', [$rv, 'sources'])->middleware($rvRead);
            Route::middleware('perm:admin.settings.manage')->group(function () use ($rv) {
                Route::post('integrations/review-sources/{provider}', [$rv, 'connect'])->middleware('idempotent');
                Route::delete('integrations/review-sources/{provider}', [$rv, 'disconnect']);
                Route::post('integrations/review-sources/{provider}/sync', [$rv, 'sync']);
            });
            Route::middleware($rvRead)->group(function () use ($rv) {
                Route::get('channels/external-reviews', [$rv, 'inbox']);
                Route::post('channels/external-reviews/{id}/{action}', [$rv, 'action'])->whereIn('action', ['reply', 'mark-published', 'escalate'])->middleware('idempotent');
                Route::get('channels/review-invitations', [$rv, 'invitations']);
                Route::post('reservations/{reservation}/review-invitations', [$rv, 'invite'])->middleware('idempotent');
                Route::get('channels/reviews-widget', [$rv, 'widget']);
            });
            Route::put('channels/reviews-widget', [$rv, 'saveWidget'])->middleware('perm:admin.settings.manage,channels.ecommerce.manage');

            // Spec 17 §6: integración web (widget, dominios, clave pública).
            Route::prefix('channels/widget')->middleware('perm:admin.settings.manage,channels.ecommerce.manage')->group(function () {
                $wg = WidgetController::class;
                Route::get('/', [$wg, 'show']);
                Route::put('/', [$wg, 'update']);
                Route::get('domains', [$wg, 'domains']);
                Route::post('domains', [$wg, 'addDomain']);
                Route::post('domains/{id}/verify', [$wg, 'verifyDomain']);
                Route::delete('domains/{id}', [$wg, 'deleteDomain']);
            });
            Route::post('channels/widget/rotate-key', [WidgetController::class, 'rotateKey'])->middleware(['perm:admin.api.manage', 'idempotent']);

            // Spec 09 §6.5: notificaciones (PROC-059).
            Route::prefix('notifications')->middleware('perm:admin.settings.manage')->group(function () {
                $n = NotificationAdminController::class;
                Route::get('rules', [$n, 'rules']);
                Route::patch('rules/{event}', [$n, 'updateRule']);
                Route::post('rules/{event}/test', [$n, 'testRule']);
                Route::get('templates/{event}/{channel}', [$n, 'template']);
                Route::put('templates/{event}/{channel}', [$n, 'saveTemplate']);
                Route::delete('templates/{event}/{channel}', [$n, 'restore']);
                Route::post('templates/{event}/{channel}/test', [$n, 'testTemplate']);
                Route::get('messages', [$n, 'messages']);
                Route::post('messages/retry-failed', [$n, 'retryFailed']);
                Route::post('messages/{message}/retry', [$n, 'retry']);
                Route::post('messages/{message}/fallback', [$n, 'fallback']);
            });

            // Spec 09 §6.6: integraciones, scheduler y errores (PROC-064/065).
            Route::delete('jobs/{job}', [AdminIntegrationController::class, 'destroy'])->middleware('perm:admin.settings.manage,reports.export');
            Route::middleware('perm:admin.settings.manage')->group(function () {
                $i = AdminIntegrationController::class;
                Route::get('integrations', [$i, 'index']);
                Route::post('integrations/{adapter}/test', [$i, 'test']);
                Route::patch('integrations/{adapter}/config', [$i, 'configure']);
                Route::get('jobs', [$i, 'jobs']);
                Route::post('jobs/{job}/run', [$i, 'run'])->middleware('idempotent');
                Route::post('jobs/{job}/{action}', [$i, 'pause'])->whereIn('action', ['pause', 'resume']);
                Route::get('integration-errors', [$i, 'errors']);
                Route::post('integration-errors/{error}/retry', [$i, 'retry']);
                Route::post('integration-errors/{error}/resolve', [$i, 'resolve']);
            });

            // Spec 09 §6.7: auditoría, reportes, exportación y panel ejecutivo.
            Route::get('audit-events', [AdminAuditController::class, 'index'])->middleware('perm:admin.audit.view,admin.roles.manage');
            Route::middleware('perm:admin.audit.view')->group(function () {
                Route::get('audit-events/{event}', [AdminAuditController::class, 'show']);
                Route::post('audit-events/exports', [AdminAuditController::class, 'export'])->middleware('idempotent');
            });
            Route::prefix('reports')->group(function () {
                $rp = AdminReportController::class;
                // Sin `perm:` en POST: el servicio decide y audita `exportar_denegado` (EXP-BR-01).
                Route::post('exports', [$rp, 'export'])->middleware('idempotent');
                Route::get('exports', [$rp, 'history'])->middleware('perm:reports.export,admin.audit.view');
                Route::get('exports/{export}/download', [$rp, 'download'])->middleware('perm:reports.export,admin.audit.view');
                Route::post('schedules', [$rp, 'schedule'])->middleware('perm:reports.export');
                Route::get('{report}', [$rp, 'show'])->middleware('perm:reports.view');
            });
            Route::get('dashboard/executive', [AdminReportController::class, 'executive'])->middleware('perm:reports.view');
            Route::get('dashboard/manager', [AdminReportController::class, 'manager'])->middleware('perm:quotes.approve_internal,approvals.view');

            // Spec 10 §6: bandeja de aprobaciones unificada. Decidir exige además la regla del nivel (servicio, APR-BR-04).
            Route::middleware('perm:approvals.view')->group(function () {
                $ap = ApprovalController::class;
                Route::get('approvals', [$ap, 'index']);
                Route::get('approvals/counters', [$ap, 'counters']);
                Route::get('approvals/meta', [$ap, 'meta']);
                Route::get('approvals/cancel-preview/{reservation}', [$ap, 'cancelPreview']);
                Route::post('approvals', [$ap, 'store'])->middleware('idempotent');
                Route::get('approvals/{code}', [$ap, 'show']);
                Route::post('approvals/{code}/approve', [$ap, 'approve'])->middleware('idempotent');
                Route::post('approvals/{code}/reject', [$ap, 'reject'])->middleware('idempotent');
                Route::post('approvals/{code}/info-requests', [$ap, 'askInfo']);
                Route::post('approvals/{code}/info-requests/{info}/answer', [$ap, 'answer'])->whereUuid('info');
                Route::post('approvals/{code}/delegate', [$ap, 'delegate']);
                Route::post('approvals/{code}/withdraw', [$ap, 'withdraw']);
                Route::post('approvals/{code}/resubmit', [$ap, 'resubmit'])->middleware('idempotent');
                $ar = ApprovalRuleController::class;
                Route::get('approval-rules', [$ar, 'index']);
                Route::middleware('perm:admin.settings.manage')->group(function () use ($ar) {
                    Route::post('approval-rules/reset', [$ar, 'reset']);
                    Route::post('approval-rules', [$ar, 'store']);
                    Route::patch('approval-rules/{rule}', [$ar, 'update']);
                    Route::delete('approval-rules/{rule}', [$ar, 'destroy']);
                });
            });
            // Spec 11 §6: cupos contratados (allotments). Lectura: manage o view_profitability (Finanzas, solo lectura).
            Route::prefix('catalog/allotments')->group(function () {
                $al = AllotmentController::class;
                $read = 'perm:catalog.allotments.manage,catalog.products.view_profitability';
                $manage = 'perm:catalog.allotments.manage';
                Route::get('/', [$al, 'index'])->middleware($read);
                Route::get('grid', [$al, 'grid'])->middleware($read);
                Route::get('pickup', [$al, 'pickup'])->middleware($read);
                Route::get('release-board', [$al, 'releaseBoard'])->middleware($read);
                Route::get('options', [$al, 'options'])->middleware($manage);
                Route::post('/', [$al, 'store'])->middleware([$manage, 'idempotent']);
                Route::post('jobs/release', [$al, 'runJob'])->middleware($manage);
                Route::get('{allotment}', [$al, 'show'])->middleware($read);
                Route::patch('{allotment}', [$al, 'update'])->middleware($manage);
                Route::post('{allotment}/release', [$al, 'release'])->middleware([$manage, 'idempotent']);
                Route::get('{allotment}/days/{date}', [$al, 'day'])->middleware($read);
                Route::post('{allotment}/days/{date}/release', [$al, 'releaseDay'])->middleware($manage);
                Route::put('{allotment}/days/{date}/retention', [$al, 'retain'])->middleware($manage);
                Route::delete('{allotment}/days/{date}/retention', [$al, 'unretain'])->middleware($manage);
                Route::get('{allotment}/requests', [$al, 'requests'])->middleware($read);
                Route::post('{allotment}/requests', [$al, 'storeRequest'])->middleware([$manage, 'idempotent']);
                Route::post('{allotment}/requests/{requestId}/respond', [$al, 'respond'])->middleware($manage);
                Route::get('{allotment}/charges', [$al, 'charges'])->middleware($read);
            });

            // Spec 11 §6: documentos de viaje. Ventas (reservations.create) solo lo propio y sin acciones de ítem (DOC-BR-011/012).
            $td = TravelDocController::class;
            $tdRead = 'perm:operations.travel_docs,reservations.create';
            $tdManage = 'perm:operations.travel_docs';
            Route::get('ops/travel-docs', [$td, 'index'])->middleware($tdRead);
            Route::get('ops/travel-docs/passengers', [$td, 'passengers'])->middleware($tdRead);
            Route::post('ops/travel-docs/jobs/reminders', [$td, 'runReminders'])->middleware($tdManage);
            Route::prefix('reservations/{reservation}/travel-docs')->group(function () use ($td, $tdRead, $tdManage) {
                Route::get('/', [$td, 'show'])->middleware($tdRead);
                Route::get('gate', [$td, 'gate'])->middleware('perm:operations.view,operations.travel_docs');
                Route::post('requests', [$td, 'request'])->middleware([$tdRead, 'idempotent']);
                Route::put('{passenger}/emergency-contact', [$td, 'contact'])->middleware($tdManage);
                Route::put('{passenger}/special-attention', [$td, 'special'])->middleware($tdManage);
                Route::post('{passenger}/{type}/files', [$td, 'upload'])->middleware([$tdManage, 'idempotent']);
                Route::get('{passenger}/{type}/file', [$td, 'file'])->middleware($tdManage);
                Route::post('{passenger}/{type}/validate', [$td, 'validateItem'])->middleware($tdManage);
                Route::post('{passenger}/{type}/observe', [$td, 'observe'])->middleware($tdManage);
            });

            // Spec 14 §6: marketing (campañas, segmentos, plantillas, automatizaciones y consentimientos).
            Route::prefix('marketing')->middleware(['perm:marketing.campaigns.manage', 'feature:marketing_campaigns'])->group(function () {
                $mk = MarketingController::class;
                $cp = CampaignController::class;
                Route::get('options', [$mk, 'options']);
                Route::get('contacts', [$mk, 'contacts']);
                Route::get('contacts/{type}/{id}/consents', [$mk, 'contactConsents']);
                Route::get('segments', [$mk, 'segments']);
                Route::post('segments', [$mk, 'storeSegment']);
                Route::post('segments/preview', [$mk, 'preview']);
                Route::get('segments/{segment}', [$mk, 'segment']);
                Route::put('segments/{segment}', [$mk, 'updateSegment']);
                Route::delete('segments/{segment}', [$mk, 'destroySegment']);
                Route::post('segments/{segment}/duplicate', [$mk, 'duplicateSegment']);
                Route::get('templates', [$mk, 'templates']);
                Route::post('templates', [$mk, 'storeTemplate']);
                Route::put('templates/{template}', [$mk, 'updateTemplate']);
                Route::post('templates/{template}/review', [$mk, 'reviewTemplate']);
                Route::get('campaigns', [$cp, 'index']);
                Route::post('campaigns', [$cp, 'store']);
                Route::get('campaigns/{campaign}', [$cp, 'show']);
                Route::put('campaigns/{campaign}', [$cp, 'update']);
                Route::get('campaigns/{campaign}/audience', [$cp, 'audience']);
                Route::post('campaigns/{campaign}/test-send', [$cp, 'testSend'])->middleware('idempotent');
                Route::post('campaigns/{campaign}/schedule', [$cp, 'schedule']);
                Route::post('campaigns/{campaign}/unschedule', [$cp, 'unschedule']);
                Route::post('campaigns/{campaign}/send', [$cp, 'send'])->middleware('idempotent');
                Route::post('campaigns/{campaign}/process', [$cp, 'process']);
                Route::post('campaigns/{campaign}/cancel', [$cp, 'cancel']);
                Route::post('campaigns/{campaign}/duplicate', [$cp, 'duplicate']);
                Route::get('campaigns/{campaign}/recipients', [$cp, 'recipients']);
                Route::post('campaigns/{campaign}/recipients/{recipient}/unsubscribe', [$cp, 'unsubscribe']);
                Route::get('automations', [$cp, 'automations']);
                Route::put('automations/{automation}', [$cp, 'configureAutomation']);
                Route::post('automations/{automation}/activate', [$cp, 'activateAutomation']);
                Route::post('automations/{automation}/pause', [$cp, 'pauseAutomation']);
                Route::post('automations/{automation}/run', [$cp, 'runAutomation'])->middleware('idempotent');
                Route::get('consents', [$mk, 'consents']);
                Route::post('consents', [$mk, 'storeConsent']);
                Route::post('consents/{consent}/revoke', [$mk, 'revokeConsent']);
                Route::get('consents/{consent}/events', [$mk, 'consentEvents']);
            });

            // Spec 12 §6: tesorería. Lectura `finance.treasury`; registro `finance.payments.register`; aprobar = profitability + approvals.view.
            Route::prefix('treasury')->middleware('feature:treasury')->group(function () {
                $tr = TreasuryController::class;
                $read = 'perm:finance.treasury';
                $register = 'perm:finance.payments.register';
                $approve = ['perm:finance.profitability', 'perm:approvals.view'];
                Route::get('summary', [$tr, 'summary'])->middleware($read);
                Route::get('accounts', [$tr, 'accounts'])->middleware($read);
                Route::post('accounts', [$tr, 'storeAccount'])->middleware(['perm:admin.settings.manage', 'idempotent']);
                Route::patch('accounts/{account}', [$tr, 'updateAccount'])->middleware('perm:admin.settings.manage');
                Route::get('accounts/{account}/movements', [$tr, 'accountMovements'])->middleware($read);
                Route::get('movements', [$tr, 'movements'])->middleware($read);
                Route::post('movements', [$tr, 'storeMovement'])->middleware([$register, 'idempotent']);
                Route::get('movements/{movement}', [$tr, 'movement'])->middleware($read);
                Route::get('movements/{movement}/receipt', [$tr, 'receiptFile'])->middleware($read);
                Route::post('movements/{movement}/void', [$tr, 'voidMovement'])->middleware($register);
                Route::post('transfers', [$tr, 'transfer'])->middleware([$register, 'idempotent']);
                Route::post('gateway/{account}/settlements', [$tr, 'settle'])->middleware([$register, 'idempotent']);
                Route::get('petty-cash', [$tr, 'pettyCash'])->middleware($read);
                Route::post('petty-cash/{account}/sessions', [$tr, 'openSession'])->middleware($register);
                Route::post('petty-cash/{account}/expenses', [$tr, 'expense'])->middleware([$register, 'idempotent']);
                Route::post('petty-cash/{account}/counts', [$tr, 'count'])->middleware($register);
                Route::post('petty-cash/{account}/replenishments', [$tr, 'requestReplenishment'])->middleware($register);
                Route::post('counts/{count}/approve', [$tr, 'approveCount'])->middleware($approve);
                Route::post('counts/{count}/validate', [$tr, 'validateCount'])->middleware('perm:finance.invoicing');
                Route::post('replenishments/{replenishment}/approve', [$tr, 'approveReplenishment'])->middleware($approve);
                Route::post('replenishments/{replenishment}/reject', [$tr, 'rejectReplenishment'])->middleware($approve);
                Route::post('replenishments/{replenishment}/pay', [$tr, 'payReplenishment'])->middleware([$register, 'idempotent']);
                Route::get('cash-flow', [$tr, 'cashFlow'])->middleware($read);
                Route::get('fx/revaluation-preview', [$tr, 'fxPreview'])->middleware($read);
                Route::post('fx/revaluations', [$tr, 'fxRegister'])->middleware('perm:finance.invoicing');
                Route::post('sync', [$tr, 'sync'])->middleware($read);
                Route::get('settings', [$tr, 'settings'])->middleware($read);
                Route::put('settings', [$tr, 'saveSettings'])->middleware('perm:admin.settings.manage');
            });

            Route::post('finance/payments/{code}/reversal-requests', [ApprovalController::class, 'requestReversal'])
                ->middleware(['perm:finance.payments.register,finance.payments.revert', 'idempotent']);

            // Spec 10 §6: bandeja omnicanal. inbox.manage para actuar; el alcance por rol lo aplica InboxScope (INB-BR-04).
            Route::prefix('inbox')->middleware('perm:inbox.view')->group(function () {
                $ib = InboxController::class;
                $manage = 'perm:inbox.manage';
                Route::get('conversations', [$ib, 'index']);
                Route::get('conversations/{code}', [$ib, 'show']);
                Route::post('conversations/{code}/messages', [$ib, 'reply'])->middleware([$manage, 'idempotent']);
                Route::post('conversations/{code}/notes', [$ib, 'note'])->middleware($manage);
                Route::post('conversations/{code}/assign', [$ib, 'assign'])->middleware($manage);
                Route::post('conversations/{code}/resolve', [$ib, 'resolve'])->middleware($manage);
                Route::post('conversations/{code}/reopen', [$ib, 'reopen'])->middleware($manage);
                Route::patch('conversations/{code}', [$ib, 'update'])->middleware($manage);
                Route::post('conversations/{code}/link', [$ib, 'link'])->middleware([$manage, 'perm:crm.clients.manage']);
                Route::post('conversations/{code}/lead', [$ib, 'lead'])->middleware(['perm:crm.leads.manage', 'idempotent']);
                Route::post('conversations/{code}/activities', [$ib, 'activity'])->middleware('perm:crm.activities.manage');
                Route::post('conversations/{code}/quote-intent', [$ib, 'quoteIntent'])->middleware($manage);
                Route::post('attachments', [$ib, 'attachment'])->middleware($manage);
                Route::post('simulate', [$ib, 'simulate'])->middleware($manage);
                Route::get('channels', [$ib, 'channels']);
                Route::get('templates', [$ib, 'templates']);
                Route::post('templates', [$ib, 'storeTemplate'])->middleware('perm:admin.settings.manage');
                Route::patch('templates/{template}', [$ib, 'updateTemplate'])->middleware('perm:admin.settings.manage');
            });
        });
    });

    // Consola de plataforma (SEC-BR-06)
    Route::prefix('platform')->group(function () {
        Route::post('auth/mfa/verify', [PlatformAuthController::class, 'verifyMfa'])->middleware(['auth:sanctum', 'throttle:login']);

        Route::middleware(['auth:sanctum', 'platform', 'mfa', 'throttle:api'])->group(function () {
            Route::get('me', [PlatformAuthController::class, 'me']);
            Route::post('auth/logout', [PlatformAuthController::class, 'logout']);
            Route::get('me/access', [PlatformTenantController::class, 'access']);
            Route::get('me/access/check', [PlatformTenantController::class, 'accessCheck']);

            // Spec 09 §6.1: consola de plataforma (PROC-001).
            Route::middleware('perm:admin.tenant.manage')->group(function () {
                $t = PlatformTenantController::class;
                Route::get('tenants', [$t, 'index']);
                Route::get('tenants/wizard', [$t, 'wizard']);
                Route::get('tenants/health', [$t, 'health']);
                Route::post('tenants/validate-step', [$t, 'validateStep']);
                Route::post('tenants/isolation-test', [$t, 'isolation']);
                Route::post('tenants', [$t, 'store'])->middleware('idempotent');
                Route::get('tenants/{tenant}', [$t, 'show']);
                Route::post('tenants/{tenant}/suspend', [$t, 'suspend']);
                Route::post('tenants/{tenant}/reactivate', [$t, 'reactivate']);
                Route::post('tenants/{tenant}/change-plan', [$t, 'changePlan']);
                Route::get('plans', [$t, 'plans']);
                Route::get('integrations', [$t, 'integrations']);
            });

            // Spec 13 §6: facturación de la plataforma (Super Admin). Solo contadores y montos de suscripción.
            Route::middleware('perm:admin.tenant.manage')->group(function () {
                $f = BillingConsoleController::class;
                Route::get('metrics', [$f, 'metrics']);
                Route::get('invoices', [$f, 'invoices']);
                Route::get('invoices/{invoice}', [$f, 'invoice']);
                Route::get('invoices/{invoice}/pdf', [$f, 'pdf']);
                Route::post('invoices/{invoice}/retry', [$f, 'retry']);
                Route::post('invoices/{invoice}/manual-payment', [$f, 'manualPayment']);
                Route::post('invoices/{invoice}/void', [$f, 'void']);
                Route::post('invoices/{invoice}/dunning/extend', [$f, 'extend']);
                Route::post('invoices/{invoice}/payment-link', [$f, 'paymentLink']);
                Route::get('dunning', [$f, 'dunning']);
                Route::post('dunning/run', [$f, 'runDunning']);
                Route::get('dunning-policy', [$f, 'policy']);
                Route::patch('dunning-policy', [$f, 'updatePolicy']);
                Route::get('trials', [$f, 'trials']);
                Route::post('trials/{tenant}/extend', [$f, 'extendTrial']);
                Route::post('trials/{tenant}/convert', [$f, 'convertTrial']);
                Route::get('coupons', [$f, 'coupons']);
                Route::post('coupons', [$f, 'createCoupon']);
                Route::patch('coupons/{coupon}', [$f, 'updateCoupon']);
                Route::get('billing-plans', [$f, 'plans']);
                Route::patch('plans/{plan}', [$f, 'updatePlan']);
                Route::get('features', [$f, 'features']);
                Route::put('features/{key}/plans/{plan}', [$f, 'setPlanFeature']);
                Route::get('feature-overrides', [$f, 'overrides']);
                Route::post('feature-overrides', [$f, 'addOverride']);
                Route::delete('feature-overrides/{override}', [$f, 'removeOverride']);
                Route::get('plan-rules', [$f, 'planRules']);
                Route::put('plan-rules/{rule}', [$f, 'setPlanRule']);
                Route::post('plan-rules/reset-demo', [$f, 'resetPlanRules']);
                Route::get('usage', [$f, 'usage']);
            });
            Route::middleware('perm:admin.audit.view')->group(function () {
                Route::get('audit-events', [PlatformTenantController::class, 'audit']);
                Route::get('audit-events/{event}', [PlatformTenantController::class, 'auditEvent']);
            });
        });
    });
});
