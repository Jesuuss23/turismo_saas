<?php

namespace App\Services\Platform;

use App\Exceptions\ApiException;
use App\Models\Iam\PlatformUser;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Trials (spec 13 §4.1, SUB-BR-10): avisos a 7, 3 y 1 día; extensión de 7 días (máx. 14 acumulados); conversión con cupón opcional;
 * al vencer, con tarjeta se convierte y cobra; sin tarjeta se suspende 7 días (solo lectura) y luego se cancela.
 */
class TrialService
{
    public const NOTICE_DAYS = [7, 3, 1];

    public const GRACE_DAYS = 7;

    public function __construct(
        private readonly PlatformInvoiceService $invoices,
        private readonly CouponService $coupons,
        private readonly PlatformEvents $events,
    ) {}

    public function list(): array
    {
        $db = self::db();
        $today = PlatformClock::today();

        return $db->table('subscriptions as s')->join('tenants as t', 't.id', '=', 's.tenant_id')->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.status', 'trialing')->orderBy('s.trial_ends_at')
            ->get(['s.*', 't.code as tenant_code', 't.trade_name', 't.legal_name', 'p.name as plan_name'])
            ->map(function ($s) use ($db, $today) {
                $left = (int) $today->diffInDays(Carbon::parse($s->trial_ends_at, PlatformClock::TZ), false);

                return ['tenant_id' => $s->tenant_id, 'tenant_code' => $s->tenant_code, 'tenant_name' => $s->trade_name ?? $s->legal_name, 'plan_id' => $s->plan_id,
                    'plan_name' => $s->plan_name, 'subscription' => $s->code, 'trial_starts_at' => $s->trial_starts_at, 'trial_ends_at' => $s->trial_ends_at,
                    'days_left' => $left, 'extended_days' => (int) $s->trial_extended_days, 'can_extend' => (int) $s->trial_extended_days + 7 <= 14,
                    'has_card' => $db->table('payment_methods')->where('tenant_id', $s->tenant_id)->where('is_default', true)->where('status', 'active')->exists(),
                    'level' => $left <= 1 ? 'error' : ($left <= 7 ? 'warn' : 'ok')];
            })->all();
    }

    /** Extiende 7 días (SUB-BR-10: máximo 14 acumulados). */
    public function extend(string $tenantId, PlatformUser $actor): array
    {
        $sub = $this->trialing($tenantId);
        if ((int) $sub->trial_extended_days + 7 > 14) {
            throw ApiException::unprocessable('SUB-BR-10', 'SUB-BR-10: la extensión del trial no puede superar 14 días acumulados.');
        }
        $ends = Carbon::parse($sub->trial_ends_at, PlatformClock::TZ)->addDays(7)->toDateString();
        $db = self::db();
        $db->transaction(function (ConnectionInterface $db) use ($sub, $ends) {
            $db->table('subscriptions')->where('id', $sub->id)->update(['trial_ends_at' => $ends, 'current_period_end' => $ends,
                'trial_extended_days' => (int) $sub->trial_extended_days + 7, 'lock_version' => $sub->lock_version + 1, 'updated_at' => now()]);
            $db->table('tenants')->where('id', $sub->tenant_id)->update(['trial_ends_at' => $ends, 'updated_at' => now()]);
        });
        $tenant = $db->table('tenants')->where('id', $tenantId)->first();
        $date = Carbon::parse($ends)->format('d/m/Y');
        $this->events->audit($tenantId, 'Tenant', $tenant->code, 'extender_trial', Carbon::parse($sub->trial_ends_at)->format('d/m/Y'), "{$date} (+7 días)", $actor);
        $this->events->notifyTenant($tenantId, 'trial.extendido', 'Extendimos su periodo de prueba', "Su prueba ahora termina el {$date}.");

        return ['message' => "Trial de {$tenant->code} extendido hasta el {$date}.", 'trials' => $this->list()];
    }

    /**
     * Convierte el trial en suscripción pagada: nuevo periodo desde hoy, cupón opcional y primera factura
     * (cobro inmediato si hay tarjeta; si no, `open` con vencimiento a 7 días).
     */
    public function convert(string $tenantId, ?string $couponCode, ?PlatformUser $actor): array
    {
        $sub = $this->trialing($tenantId);
        $db = self::db();
        $today = PlatformClock::today();
        if ($couponCode) {
            $this->coupons->redeem($sub, $couponCode, $actor);
            $sub = $db->table('subscriptions')->where('id', $sub->id)->first();
        }
        $end = BillingMath::periodEnd($today, $sub->cycle);
        $db->transaction(function (ConnectionInterface $db) use ($sub, $today, $end) {
            $db->table('subscriptions')->where('id', $sub->id)->update(['status' => 'active', 'current_period_start' => $today->toDateString(),
                'current_period_end' => $end->toDateString(), 'billing_anchor_day' => min(28, $today->day), 'lock_version' => $sub->lock_version + 1, 'updated_at' => now()]);
            $tenant = $db->table('tenants')->where('id', $sub->tenant_id)->first();
            $db->table('tenants')->where('id', $sub->tenant_id)->update(['status' => 'activo', 'suspension_source' => null, 'suspend_reason' => null, 'suspended_at' => null,
                'mrr' => BillingMath::monthly(BillingMath::price($sub), $sub->cycle), 'lock_version' => $tenant->lock_version + 1, 'updated_at' => now()]);
        });
        $fresh = $db->table('subscriptions')->where('id', $sub->id)->first();
        $lines = app(SubscriptionService::class)->renewalLines($fresh, $fresh->plan_id, $fresh->cycle, $today, true);
        $hasCard = $db->table('payment_methods')->where('tenant_id', $tenantId)->where('is_default', true)->where('status', 'active')->exists();
        $invoice = $this->invoices->issue($fresh, 'subscription', $lines, $today->toDateString(), $end->toDateString(), $hasCard ? 0 : 7);
        if ($hasCard) {
            $this->invoices->charge($invoice, 'initial', "inv:{$invoice->id}:attempt:1", $actor);
        }
        $tenant = $db->table('tenants')->where('id', $tenantId)->first();
        $number = $this->invoices->number($invoice);
        $this->events->audit($tenantId, 'Tenant', $tenant->code, 'convertir_trial', 'En prueba', "Activo · {$number}".($couponCode ? " · cupón {$couponCode}" : ''), $actor, $actor === null);
        $this->events->notifyTenant($tenantId, 'trial.convertido', '¡Bienvenido a Qhapaq Cloud!',
            "Su suscripción está activa. Emitimos la factura {$number} por S/ {$invoice->total}".($hasCard ? '.' : ', con vencimiento a 7 días.'));
        $this->events->lifecycle($tenantId, 'new', "{$tenant->trade_name} convirtió su trial", (float) BillingMath::monthly(BillingMath::price($fresh), $fresh->cycle));

        return ['message' => "Trial de {$tenant->code} convertido ({$number}).", 'invoice' => $number, 'trials' => $this->list()];
    }

    /** `billing:trials` (diario 08:00). */
    public function run(): array
    {
        $db = self::db();
        $today = PlatformClock::today();
        $out = [];
        foreach ($db->table('subscriptions')->where('status', 'trialing')->get() as $sub) {
            $left = (int) $today->diffInDays(Carbon::parse($sub->trial_ends_at, PlatformClock::TZ), false);
            $code = $db->table('tenants')->where('id', $sub->tenant_id)->value('code');
            if (in_array($left, self::NOTICE_DAYS, true)) {
                $this->events->notifyTenant($sub->tenant_id, "trial.aviso_{$left}", "Su prueba termina en {$left} día(s)",
                    'Registre su tarjeta en Suscripción › Pago para continuar sin interrupciones.');
                $out[] = ['tenant' => $code, 'result' => "aviso_{$left}"];
            } elseif ($left < 0) {
                $hasCard = $db->table('payment_methods')->where('tenant_id', $sub->tenant_id)->where('is_default', true)->where('status', 'active')->exists();
                if ($hasCard) {
                    $this->convert($sub->tenant_id, null, null);
                    $out[] = ['tenant' => $code, 'result' => 'converted'];
                } else {
                    $this->expire($sub);
                    $out[] = ['tenant' => $code, 'result' => 'suspended'];
                }
            }
        }
        // Trial vencido sin tarjeta: tras 7 días de solo lectura pasa a `canceled`.
        $limit = $today->copy()->subDays(self::GRACE_DAYS)->toDateString();
        foreach ($db->table('subscriptions')->where('status', 'suspended')->whereNotNull('trial_ends_at')->where('trial_ends_at', '<', $limit)
            ->whereColumn('trial_ends_at', 'current_period_end')->get() as $sub) {
            $db->table('subscriptions')->where('id', $sub->id)->update(['status' => 'canceled', 'auto_renew' => false, 'updated_at' => now()]);
            $this->events->audit($sub->tenant_id, 'Suscripción', $sub->code, 'cancelar', 'Suspendida', 'Cancelada: trial vencido sin método de pago', system: true);
            $out[] = ['tenant' => $db->table('tenants')->where('id', $sub->tenant_id)->value('code'), 'result' => 'canceled'];
        }

        return $out;
    }

    private function expire(object $sub): void
    {
        $db = self::db();
        $tenant = $db->table('tenants')->where('id', $sub->tenant_id)->first();
        $db->transaction(function (ConnectionInterface $db) use ($sub, $tenant) {
            $db->table('subscriptions')->where('id', $sub->id)->update(['status' => 'suspended', 'lock_version' => $sub->lock_version + 1, 'updated_at' => now()]);
            $db->table('tenants')->where('id', $tenant->id)->update(['status' => 'suspendido', 'suspension_source' => 'billing', 'suspended_at' => now(),
                'suspend_reason' => 'Trial vencido sin método de pago', 'lock_version' => $tenant->lock_version + 1, 'updated_at' => now()]);
        });
        $this->events->audit($tenant->id, 'Tenant', $tenant->code, 'suspender', 'En prueba', 'Suspendido: trial vencido sin método de pago', system: true);
        $this->events->notifyTenant($tenant->id, 'tenant.suspendido', 'Su periodo de prueba terminó',
            'Registre su tarjeta en Suscripción › Pago dentro de 7 días para conservar su cuenta.');
        $this->events->lifecycle($tenant->id, 'trial_expired', "{$tenant->trade_name}: trial vencido sin tarjeta");
    }

    private function trialing(string $tenantId): object
    {
        return self::db()->table('subscriptions')->where('tenant_id', $tenantId)->where('status', 'trialing')->first()
            ?? throw ApiException::conflict('SUBSCRIPTION_NOT_TRIALING', 'El tenant no está en periodo de prueba.');
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection(PlatformCatalog::CONNECTION);
    }
}
