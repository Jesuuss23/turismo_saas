<?php

namespace App\Services\Platform;

use App\Exceptions\ApiException;
use App\Models\Iam\PlatformUser;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dunning por factura (spec 13 §4.3): D0 = fallo inicial; reintentos en `failed_at + retry_days[i]` a las 10:00 (la prórroga se suma
 * al último). Tras el último rechazo la factura pasa a `uncollectible` y, con `suspend_after_last`, se suspende el tenant
 * (402 PAYMENT_REQUIRED). Un reintento manual no mueve el calendario.
 */
class DunningService
{
    public function __construct(
        private readonly PlatformInvoiceService $invoices,
        private readonly PlatformEvents $events,
    ) {}

    public static function retryAt(Carbon $failed, int $days, int $hour): string
    {
        return $failed->copy()->setTimezone(PlatformClock::TZ)->startOfDay()->addDays($days)->setTime($hour, 0)->utc()->toIso8601String();
    }

    /**
     * `billing:dunning`: reintenta las facturas vencidas en su calendario.
     *
     * @return list<array{invoice: string, tenant: string, result: string, next_retry_at: ?string}>
     */
    public function run(?PlatformUser $actor = null): array
    {
        $db = self::db();
        $due = $db->table('platform_invoices')->where('status', 'failed')->whereNotNull('dunning_next_retry_at')->where('dunning_next_retry_at', '<=', now())
            ->orderBy('dunning_next_retry_at')->pluck('id');
        $out = [];
        foreach ($due as $id) {
            $lock = Cache::lock("dunning:{$id}", 60);
            if (! $lock->get()) {
                continue;
            }
            try {
                $out[] = $this->retry($this->invoices->find($id));
            } finally {
                $lock->release();
            }
        }
        if ($actor) {
            $first = $db->table('tenants')->where('id', $actor->home_tenant_id)->value('id');
            if ($first) {
                $this->events->audit($first, 'Job', 'billing:dunning', 'ejecutar', null, count($out).' factura(s) procesada(s)', $actor);
            }
        }

        return $out;
    }

    /** Un reintento del calendario: cobra y reprograma, o cierra el dunning. */
    private function retry(object $invoice): array
    {
        $policy = PlatformInvoiceService::policy();
        $n = (int) $invoice->dunning_retries_done + 1;
        $result = $this->invoices->charge($invoice, 'dunning', "inv:{$invoice->id}:dunning:{$n}");
        $tenant = self::db()->table('tenants')->where('id', $invoice->tenant_id)->first();
        if ($result['attempt']->result === 'succeeded') {
            return ['invoice' => $this->invoices->number($invoice), 'tenant' => $tenant->code, 'result' => 'paid', 'next_retry_at' => null];
        }
        $days = $policy['retry_days'];
        $failed = Carbon::parse($invoice->dunning_failed_at, PlatformClock::TZ);
        if ($n < count($days)) {
            $extra = $n === count($days) - 1 ? (int) $invoice->dunning_extended_days : 0;
            $next = self::retryAt($failed, $days[$n] + $extra, $policy['retry_hour']);
            self::db()->table('platform_invoices')->where('id', $invoice->id)->update(['dunning_retries_done' => $n, 'dunning_next_retry_at' => $next, 'updated_at' => now()]);
            $this->events->audit($invoice->tenant_id, 'Factura plataforma', $this->invoices->number($invoice), 'cobro_rechazado', "Reintento {$n}",
                'Próximo reintento '.Carbon::parse($next)->setTimezone(PlatformClock::TZ)->format('d/m/Y H:i'), system: true);

            return ['invoice' => $this->invoices->number($invoice), 'tenant' => $tenant->code, 'result' => 'rescheduled', 'next_retry_at' => $next];
        }
        $this->exhaust($invoice, $policy, $tenant);

        return ['invoice' => $this->invoices->number($invoice), 'tenant' => $tenant->code, 'result' => $policy['suspend_after_last'] ? 'suspended' : 'uncollectible', 'next_retry_at' => null];
    }

    /** Último reintento fallido: factura vencida y, según la política, suspensión del tenant. */
    private function exhaust(object $invoice, array $policy, object $tenant): void
    {
        $db = self::db();
        $number = $this->invoices->number($invoice);
        $db->transaction(function (ConnectionInterface $db) use ($invoice, $policy, $tenant, $number) {
            $db->table('platform_invoices')->where('id', $invoice->id)->update(['status' => 'uncollectible', 'dunning_retries_done' => count($policy['retry_days']),
                'dunning_next_retry_at' => null, 'updated_at' => now()]);
            if (! $policy['suspend_after_last']) {
                return;
            }
            $sub = $db->table('subscriptions')->where('id', $invoice->subscription_id)->first();
            $db->table('subscriptions')->where('id', $sub->id)->update(['status' => 'suspended', 'lock_version' => $sub->lock_version + 1, 'updated_at' => now()]);
            if ($tenant->status !== 'suspendido') {
                $db->table('tenants')->where('id', $tenant->id)->update(['status' => 'suspendido', 'suspended_at' => now(), 'suspension_source' => 'billing',
                    'suspend_reason' => "Cobranza: factura {$number} impaga tras el último reintento", 'lock_version' => $tenant->lock_version + 1, 'updated_at' => now()]);
            }
        });
        if ($policy['suspend_after_last']) {
            $this->events->audit($tenant->id, 'Tenant', $tenant->code, 'suspender', 'Activo', "Suspendido por dunning · {$number}", system: true);
            $this->events->notifyTenant($tenant->id, 'tenant.suspendido', 'Su cuenta fue suspendida por falta de pago',
                "No pudimos cobrar la factura {$number} tras los reintentos. Sus datos están intactos: pague la deuda desde Suscripción › Facturas para reactivar la cuenta.");
            $this->events->lifecycle($tenant->id, 'suspended', "{$tenant->trade_name} suspendido por cobranza ({$number})");
        }
    }

    /** Reintento manual (Super Admin): no mueve el calendario. */
    public function retryNow(object $invoice, string $idempotencyKey, PlatformUser $actor): array
    {
        if ($invoice->status !== 'failed' && $invoice->status !== 'uncollectible' && $invoice->status !== 'open') {
            throw ApiException::conflict('INVOICE_NOT_PAYABLE', 'Solo se reintentan facturas pendientes, fallidas o vencidas.');
        }

        return $this->invoices->charge($invoice, 'manual_retry', "manual:{$idempotencyKey}", $actor);
    }

    /** Pago manual (transferencia o depósito): reactiva el tenant si no queda deuda. */
    public function manualPayment(object $invoice, string $reference, string $method, string $idempotencyKey, PlatformUser $actor): object
    {
        $db = self::db();
        if ($db->table('payment_attempts')->where('idempotency_key', "manual-payment:{$idempotencyKey}")->exists()) {
            return $this->invoices->find($invoice->id);
        }
        if (! in_array($invoice->status, ['open', 'failed', 'uncollectible'], true)) {
            throw ApiException::conflict('INVOICE_NOT_PAYABLE', "La factura {$this->invoices->number($invoice)} no tiene saldo pendiente.");
        }
        $db->table('payment_attempts')->insert(['id' => (string) Str::uuid7(), 'invoice_id' => $invoice->id,
            'attempt_no' => (int) $db->table('payment_attempts')->where('invoice_id', $invoice->id)->max('attempt_no') + 1, 'source' => 'manual_payment',
            'result' => 'succeeded', 'gateway_code' => null, 'message' => "Pago manual por {$method}", 'reference' => $reference,
            'idempotency_key' => "manual-payment:{$idempotencyKey}", 'created_at' => now()]);
        $this->invoices->markPaid($invoice, "{$method} {$reference}", $actor, 'pago_manual');

        return $this->invoices->find($invoice->id);
    }

    /** Prórroga de 7 días: se suma al último reintento (máximo 14 días acumulados). */
    public function extend(object $invoice, PlatformUser $actor): object
    {
        if ($invoice->status !== 'failed') {
            throw ApiException::conflict('INVOICE_NOT_IN_DUNNING', 'Solo se prorroga una factura en cobranza.');
        }
        if ((int) $invoice->dunning_extended_days >= 14) {
            throw ApiException::unprocessable('DUNNING_EXTENSION_MAX', 'La prórroga acumulada no puede superar 14 días.');
        }
        $policy = PlatformInvoiceService::policy();
        $update = ['dunning_extended_days' => (int) $invoice->dunning_extended_days + 7, 'updated_at' => now()];
        // Si el próximo reintento ya es el último, se mueve con la prórroga.
        if ((int) $invoice->dunning_retries_done === count($policy['retry_days']) - 1 && $invoice->dunning_next_retry_at) {
            $update['dunning_next_retry_at'] = Carbon::parse($invoice->dunning_next_retry_at)->addDays(7)->toIso8601String();
        }
        self::db()->table('platform_invoices')->where('id', $invoice->id)->update($update);
        $this->events->audit($invoice->tenant_id, 'Factura plataforma', $this->invoices->number($invoice), 'prorroga_dunning', null, '+7 días al último reintento', $actor);

        return $this->invoices->find($invoice->id);
    }

    public function paymentLink(object $invoice, PlatformUser $actor): string
    {
        $url = rtrim((string) config('turismo.frontend_url'), '/').'/admin/suscripcion/facturas?pagar='.$this->invoices->number($invoice);
        $this->events->notifyTenant($invoice->tenant_id, 'plataforma.enlace_pago', "Enlace de pago de la factura {$this->invoices->number($invoice)}",
            "Puede pagar S/ {$invoice->total} con su tarjeta desde: {$url}");
        $this->events->audit($invoice->tenant_id, 'Factura plataforma', $this->invoices->number($invoice), 'enviar_enlace_pago', null, 'Enlace enviado al contacto de facturación', $actor);

        return $url;
    }

    /**
     * Calendario D+1/D+3/D+7 de la factura con el estado de cada reintento.
     *
     * @return list<array{label: string, at: string, state: string}>
     */
    public function calendar(object $invoice): array
    {
        if (! $invoice->dunning_failed_at) {
            return [];
        }
        $policy = PlatformInvoiceService::policy();
        $failed = Carbon::parse($invoice->dunning_failed_at, PlatformClock::TZ);
        $count = count($policy['retry_days']);
        $out = [['label' => 'D0 · cobro rechazado', 'at' => $failed->toDateString(), 'state' => 'done']];
        foreach ($policy['retry_days'] as $i => $days) {
            $extra = $i === $count - 1 ? (int) $invoice->dunning_extended_days : 0;
            $at = $failed->copy()->addDays($days + $extra);
            $state = $i < (int) $invoice->dunning_retries_done ? 'done' : ($i === (int) $invoice->dunning_retries_done && $invoice->status === 'failed' ? 'next' : 'pending');
            if ($invoice->status === 'paid' && $i >= (int) $invoice->dunning_retries_done) {
                $state = 'skipped';
            }
            $out[] = ['label' => "D+{$days}".($extra ? " (+{$extra} prórroga)" : ''), 'at' => $at->toDateString(), 'state' => $state];
        }

        return $out;
    }

    public function updatePolicy(array $input, PlatformUser $actor): array
    {
        $days = array_values(array_map('intval', (array) ($input['retry_days'] ?? [])));
        $sorted = $days;
        sort($sorted);
        if ($days === [] || count($days) > 5 || $days !== $sorted || count(array_unique($days)) !== count($days) || min($days) < 1 || max($days) > 30) {
            throw ApiException::invalid('retry_days', 'Indique de 1 a 5 días crecientes entre 1 y 30.');
        }
        $policy = ['retry_days' => $days, 'suspend_after_last' => (bool) ($input['suspend_after_last'] ?? true), 'retry_hour' => PlatformCatalog::DUNNING['retry_hour']];
        $before = PlatformInvoiceService::policy();
        self::db()->table('platform_settings')->where('key', 'dunning_policy')->update(['value' => json_encode($policy), 'updated_by' => $actor->id, 'updated_at' => now()]);
        if ($actor->home_tenant_id) {
            $this->events->audit($actor->home_tenant_id, 'Plan', 'dunning_policy', 'editar_politica', implode('/', $before['retry_days']), implode('/', $days).($policy['suspend_after_last'] ? ' · suspende' : ' · no suspende'), $actor);
        }

        return $policy;
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection(PlatformCatalog::CONNECTION);
    }
}
