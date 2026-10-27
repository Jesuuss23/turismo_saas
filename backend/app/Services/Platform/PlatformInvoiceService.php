<?php

namespace App\Services\Platform;

use App\Exceptions\ApiException;
use App\Jobs\EmitPlatformInvoiceJob;
use App\Models\Iam\PlatformUser;
use App\Models\Iam\User;
use App\Services\Platform\Gateway\PaymentGateway;
use App\Services\Reports\FileWriter;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Comprobantes de la plataforma (spec 13 §3.8, §4.2, §8.1): factura FQ01 del emisor Qhapaq Cloud con IGV 18 %, cobro con la tarjeta
 * tokenizada (SUB-BR-12, idempotente por `payment_attempts.idempotency_key`), emisión SUNAT por OSE con reintentos (PROC-065)
 * y nota de crédito NQ01 al anular (reembolso si estaba pagada).
 */
class PlatformInvoiceService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PlatformEvents $events,
    ) {}

    /**
     * Emite una factura `open`. `$lines`: [descripción, cantidad, precio unitario, tipo].
     *
     * @param  list<array{0: string, 1: float|int|string, 2: string, 3: string}>  $lines
     */
    public function issue(object $sub, string $kind, array $lines, string $periodFrom, string $periodTo, int $dueDays = 0, ?Carbon $issueDate = null): object
    {
        $db = self::db();
        $issuer = self::issuer();
        $issue = ($issueDate ?? PlatformClock::today())->copy();
        $subtotal = BigDecimal::zero()->toScale(2);
        $rows = [];
        foreach ($lines as [$description, $qty, $unit, $type]) {
            $amount = BigDecimal::of($unit)->multipliedBy((string) $qty)->toScale(2, RoundingMode::HalfUp);
            $subtotal = $subtotal->plus($amount);
            $rows[] = ['description' => Str::limit($description, 245), 'quantity' => $qty, 'unit_price' => $unit, 'amount' => (string) $amount, 'line_type' => $type];
        }
        if ($subtotal->isNegative()) {
            throw new RuntimeException('El subtotal de la factura no puede ser negativo');
        }
        $igv = $subtotal->multipliedBy($issuer['igv_pct'])->dividedBy(100, 2, RoundingMode::HalfUp);
        $id = (string) Str::uuid7();
        $db->transaction(function (ConnectionInterface $db) use ($id, $sub, $kind, $rows, $subtotal, $igv, $periodFrom, $periodTo, $issue, $dueDays, $issuer) {
            $db->table('platform_invoices')->insert([
                'id' => $id, 'series' => $issuer['invoice_series'], 'number' => PlatformProvisioner::nextNumber($db, $issuer['invoice_series']),
                'tenant_id' => $sub->tenant_id, 'subscription_id' => $sub->id, 'kind' => $kind, 'period_from' => $periodFrom, 'period_to' => $periodTo,
                'issue_date' => $issue->toDateString(), 'due_date' => $issue->copy()->addDays($dueDays)->toDateString(), 'currency' => 'PEN',
                'subtotal' => (string) $subtotal, 'igv' => (string) $igv, 'total' => (string) $subtotal->plus($igv), 'igv_pct' => $issuer['igv_pct'], 'status' => 'open',
                // SUB-BR-09: snapshot de los datos de facturación; las emitidas no cambian.
                'bill_to' => json_encode(['ruc' => $sub->billing_ruc, 'name' => $sub->billing_name, 'address' => $sub->billing_address, 'ubigeo' => $sub->billing_ubigeo,
                    'email' => $sub->billing_email]),
                'sunat_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($rows as $row) {
                $db->table('platform_invoice_lines')->insert(['id' => (string) Str::uuid7(), 'invoice_id' => $id] + $row);
            }
        });
        $this->emitSafely($id);

        return $this->find($id);
    }

    /**
     * Cobra la factura con la tarjeta por defecto. Idempotente: la misma clave devuelve el intento ya registrado (sin doble cargo).
     * `initial` y `portal` con rechazo inician el dunning si aún no corre; `manual_retry` y `dunning` no mueven el calendario aquí.
     *
     * @return array{attempt: object, invoice: object}
     */
    public function charge(object $invoice, string $source, string $idempotencyKey, User|PlatformUser|null $actor = null): array
    {
        $db = self::db();
        if ($existing = $db->table('payment_attempts')->where('idempotency_key', $idempotencyKey)->first()) {
            return ['attempt' => $existing, 'invoice' => $this->find($existing->invoice_id)];
        }
        if (! in_array($invoice->status, ['open', 'failed', 'uncollectible'], true)) {
            throw ApiException::conflict('INVOICE_NOT_PAYABLE', "La factura {$this->number($invoice)} está ".mb_strtolower(PlatformCatalog::INVOICE_STATUS[$invoice->status]).'.');
        }
        $method = $db->table('payment_methods')->where('tenant_id', $invoice->tenant_id)->where('is_default', true)->where('status', 'active')->first();
        $result = $method
            ? $this->gateway->charge($method->gateway_token, (string) $invoice->total, $invoice->currency, $idempotencyKey, "Qhapaq Cloud {$this->number($invoice)}")
            : new Gateway\ChargeResult(false, null, 'no_payment_method', 'No hay una tarjeta registrada', true);

        return $db->transaction(function (ConnectionInterface $db) use ($invoice, $source, $idempotencyKey, $result, $method, $actor) {
            $locked = $db->table('platform_invoices')->where('id', $invoice->id)->lockForUpdate()->first();
            $attemptNo = (int) $db->table('payment_attempts')->where('invoice_id', $invoice->id)->max('attempt_no') + 1;
            $attempt = ['id' => (string) Str::uuid7(), 'invoice_id' => $invoice->id, 'attempt_no' => $attemptNo, 'source' => $source,
                'result' => $result->succeeded ? 'succeeded' : ($result->error ? 'error' : 'declined'), 'gateway_code' => $result->code,
                'message' => $result->message ?? ($method ? "Tarjeta {$method->brand} •••• {$method->last4}" : null), 'reference' => $result->reference,
                'idempotency_key' => $idempotencyKey, 'created_at' => now()];
            $db->table('payment_attempts')->insert($attempt);
            $number = $this->number($locked);
            if ($result->succeeded) {
                $this->markPaid($locked, $result->reference, $actor, $source === 'portal' ? 'pagar' : 'cobro_exitoso');
            } else {
                $update = ['status' => 'failed', 'updated_at' => now()];
                if ($locked->dunning_failed_at === null && in_array($source, ['initial', 'portal'], true)) {
                    $policy = self::policy();
                    $failed = PlatformClock::today();
                    $update += ['dunning_failed_at' => $failed->toDateString(), 'dunning_retries_done' => 0,
                        'dunning_next_retry_at' => DunningService::retryAt($failed, $policy['retry_days'][0], $policy['retry_hour'])];
                }
                if ($locked->status !== 'uncollectible') {
                    $db->table('platform_invoices')->where('id', $locked->id)->update($update);
                }
                $sub = $db->table('subscriptions')->where('id', $locked->subscription_id)->first();
                if ($sub && $sub->status === 'active') {
                    $db->table('subscriptions')->where('id', $sub->id)->update(['status' => 'past_due', 'lock_version' => $sub->lock_version + 1, 'updated_at' => now()]);
                }
                $this->events->audit($locked->tenant_id, 'Factura plataforma', $number, 'cobro_rechazado', PlatformCatalog::INVOICE_STATUS[$locked->status],
                    "Fallida · {$attempt['gateway_code']}", $actor, $actor === null);
                $this->events->notifyTenant($locked->tenant_id, 'plataforma.cobro_fallido', "No pudimos cobrar la factura {$number}",
                    "El cobro de S/ {$locked->total} fue rechazado ({$attempt['message']}). Actualice su tarjeta o pague desde Suscripción › Facturas.");
            }

            return ['attempt' => (object) $attempt, 'invoice' => $this->find($invoice->id)];
        });
    }

    /** Pago confirmado (pasarela, pago manual o portal): limpia el dunning y reactiva si no queda deuda (§4.1). */
    public function markPaid(object $invoice, ?string $reference, User|PlatformUser|null $actor, string $action): void
    {
        $db = self::db();
        $db->table('platform_invoices')->where('id', $invoice->id)->update([
            'status' => 'paid', 'paid_at' => now(), 'payment_ref' => $reference, 'dunning_next_retry_at' => null, 'updated_at' => now(),
        ]);
        $this->events->audit($invoice->tenant_id, 'Factura plataforma', $this->number($invoice), $action, PlatformCatalog::INVOICE_STATUS[$invoice->status],
            'Pagada'.($reference ? " · {$reference}" : ''), $actor, $actor === null);
        $this->events->notifyTenant($invoice->tenant_id, 'suscripcion.pago_recibido', "Recibimos su pago de S/ {$invoice->total}",
            "La factura {$this->number($invoice)} quedó pagada. Gracias por confiar en Qhapaq Cloud.");
        $this->settle($invoice->tenant_id, $actor);
    }

    /** Sin deuda vencida: suscripción activa y tenant reactivado si la suspensión fue por cobranza. */
    public function settle(string $tenantId, User|PlatformUser|null $actor = null): bool
    {
        $db = self::db();
        if ($this->debt($tenantId) > 0) {
            return false;
        }
        $sub = $db->table('subscriptions')->where('tenant_id', $tenantId)->where('status', '<>', 'canceled')->first();
        if ($sub && in_array($sub->status, ['past_due', 'suspended'], true)) {
            $db->table('subscriptions')->where('id', $sub->id)->update(['status' => 'active', 'lock_version' => $sub->lock_version + 1, 'updated_at' => now()]);
        }
        $tenant = $db->table('tenants')->where('id', $tenantId)->first();
        if ($tenant->status === 'suspendido' && $tenant->suspension_source === 'billing') {
            $db->table('tenants')->where('id', $tenantId)->update(['status' => 'activo', 'suspend_reason' => null, 'suspended_at' => null, 'suspension_source' => null,
                'lock_version' => $tenant->lock_version + 1, 'updated_at' => now()]);
            $this->events->audit($tenantId, 'Tenant', $tenant->code, 'reactivar', 'Suspendido', 'Activo · deuda pagada', $actor, $actor === null);
            $this->events->lifecycle($tenantId, 'reactivated', "{$tenant->trade_name} reactivado tras pagar la deuda");
        }

        return true;
    }

    /** Deuda exigible: facturas fallidas, vencidas o pendientes ya vencidas. */
    public function debt(string $tenantId): float
    {
        return (float) self::db()->table('platform_invoices')->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereIn('status', ['failed', 'uncollectible'])->orWhere(fn ($q) => $q->where('status', 'open')->where('due_date', '<', PlatformClock::today()->toDateString())))
            ->sum('total');
    }

    /** Anulación con nota de crédito NQ01; si estaba pagada, reembolso por la pasarela. */
    public function void(object $invoice, string $reason, string $idempotencyKey, PlatformUser $actor): object
    {
        if ($invoice->status === 'void') {
            throw ApiException::conflict('INVOICE_ALREADY_VOID', "La factura {$this->number($invoice)} ya está anulada.");
        }
        $db = self::db();
        $refund = $invoice->status === 'paid' ? $this->gateway->refund((string) $invoice->payment_ref, (string) $invoice->total, "refund:{$idempotencyKey}") : null;
        $issuer = self::issuer();
        $db->transaction(function (ConnectionInterface $db) use ($invoice, $reason, $refund, $actor, $issuer) {
            $number = PlatformProvisioner::nextNumber($db, $issuer['credit_series']);
            $db->table('platform_credit_notes')->insert(['id' => (string) Str::uuid7(), 'series' => $issuer['credit_series'], 'number' => $number, 'invoice_id' => $invoice->id,
                'reason' => $reason, 'amount' => $invoice->total, 'sunat_status' => 'accepted', 'refund_ref' => $refund?->reference, 'created_by' => $actor->id, 'created_at' => now()]);
            $db->table('platform_invoices')->where('id', $invoice->id)->update(['status' => 'void', 'void_reason' => $reason, 'dunning_next_retry_at' => null, 'updated_at' => now()]);
            $this->events->audit($invoice->tenant_id, 'Factura plataforma', $this->number($invoice), 'anular', PlatformCatalog::INVOICE_STATUS[$invoice->status],
                sprintf('Anulada con %s-%08d%s · %s', $issuer['credit_series'], $number, $refund ? ' y reembolso' : '', $reason), $actor);
        });
        $this->settle($invoice->tenant_id, $actor);

        return $this->find($invoice->id);
    }

    /** Emisión SUNAT por OSE: si falla, la factura queda `pending` y el job reintenta con backoff sin bloquear el cobro (§10-12). */
    public function emitSafely(string $id): void
    {
        DB::afterCommit(function () use ($id) {
            try {
                $this->emit($id);
            } catch (Throwable $e) {
                Log::warning('OSE de la plataforma no disponible: se reintenta', ['invoice' => $id, 'error' => $e->getMessage()]);
                self::db()->table('platform_invoices')->where('id', $id)->update(['sunat_error' => Str::limit($e->getMessage(), 190), 'updated_at' => now()]);
                try {
                    EmitPlatformInvoiceJob::dispatch($id);
                } catch (Throwable) {
                    // Cola síncrona (pruebas): el reintento queda para el siguiente intento del job.
                }
            }
        });
    }

    public function emit(string $id): void
    {
        $db = self::db();
        $invoice = $db->table('platform_invoices')->where('id', $id)->first();
        if (! $invoice || $invoice->sunat_status === 'accepted') {
            return;
        }
        $db->table('platform_invoices')->where('id', $id)->increment('sunat_attempts');
        if (config('turismo.platform.ose_down')) {
            throw new RuntimeException('OSE sin respuesta (timeout)');
        }
        $number = $this->number($invoice);
        $year = Carbon::parse($invoice->issue_date)->year;
        $db->table('platform_invoices')->where('id', $id)->update([
            'sunat_status' => 'accepted', 'sunat_hash' => base64_encode(hash('sha256', "{$number}|{$invoice->total}|{$invoice->issue_date}", true)), 'sunat_error' => null,
            'xml_path' => "platform/invoices/{$year}/{$number}.xml", 'pdf_path' => "platform/invoices/{$year}/{$number}.pdf", 'updated_at' => now(),
        ]);
    }

    /** Representación impresa (PDF) con el emisor, el adquirente (snapshot), las líneas y el estado SUNAT. */
    public function pdf(object $invoice): string
    {
        $issuer = self::issuer();
        $bill = json_decode($invoice->bill_to, true);
        $rows = [
            ['Emisor', "{$issuer['name']} · RUC {$issuer['ruc']}"], ['Dirección del emisor', $issuer['address']],
            ['Comprobante', 'Factura electrónica '.$this->number($invoice)], ['Fecha de emisión', Carbon::parse($invoice->issue_date)->format('d/m/Y')],
            ['Vencimiento', Carbon::parse($invoice->due_date)->format('d/m/Y')], ['Adquirente', "{$bill['name']} · RUC {$bill['ruc']}"], ['Dirección', $bill['address']],
            ['Periodo', Carbon::parse($invoice->period_from)->format('d/m/Y').' al '.Carbon::parse($invoice->period_to)->format('d/m/Y')],
        ];
        foreach (self::db()->table('platform_invoice_lines')->where('invoice_id', $invoice->id)->get() as $line) {
            $rows[] = [$line->description, "{$line->quantity} × S/ {$line->unit_price} = S/ {$line->amount}"];
        }
        array_push($rows, ['Subtotal', "S/ {$invoice->subtotal}"], ["IGV {$invoice->igv_pct} %", "S/ {$invoice->igv}"], ['Total', "S/ {$invoice->total}"],
            ['Estado', PlatformCatalog::INVOICE_STATUS[$invoice->status]], ['SUNAT', PlatformCatalog::SUNAT_STATUS[$invoice->sunat_status].($invoice->sunat_hash ? " · hash {$invoice->sunat_hash}" : '')]);

        return FileWriter::pdf('Factura '.$this->number($invoice), ['Concepto', 'Detalle'], $rows);
    }

    public function find(string $id): object
    {
        return self::db()->table('platform_invoices')->where('id', $id)->first() ?? throw ApiException::notFound();
    }

    /** FQ01-00000123 → fila (filtrada por tenant si se indica). */
    public function byNumber(string $number, ?string $tenantId = null): object
    {
        if (! preg_match('/^([A-Z0-9]{4})-(\d{1,8})$/', $number, $m)) {
            throw ApiException::notFound();
        }

        return self::db()->table('platform_invoices')->where('series', $m[1])->where('number', (int) $m[2])
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->first() ?? throw ApiException::notFound();
    }

    public function number(object $invoice): string
    {
        return sprintf('%s-%08d', $invoice->series, $invoice->number);
    }

    public static function issuer(): array
    {
        return json_decode((string) self::db()->table('platform_settings')->where('key', 'issuer')->value('value'), true) ?: PlatformCatalog::ISSUER;
    }

    public static function policy(): array
    {
        return (json_decode((string) self::db()->table('platform_settings')->where('key', 'dunning_policy')->value('value'), true) ?: []) + PlatformCatalog::DUNNING;
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection(PlatformCatalog::CONNECTION);
    }
}
