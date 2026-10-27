<?php

namespace App\Services\Finance\Fiscal;

use App\Jobs\SendInvoiceToFiscalProvider;
use App\Models\Finance\FiscalEvent;
use App\Models\Finance\Invoice;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Finance\Fin;
use App\Services\Notify;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Integración fiscal asíncrona (FIN-BR-49, RT-21): el comprobante se persiste `pendiente_envio` y se envía después del commit.
 * Un error del proveedor nunca revierte la emisión: se registra en `fin_fiscal_events`, se reintenta con backoff y, agotados
 * los intentos, queda pendiente con alerta a Contabilidad y Finanzas (T18).
 */
class FiscalGateway
{
    public const MAX_ATTEMPTS = 3;

    /** Segundos de espera antes del intento N+1. */
    public const BACKOFF = [1 => 60, 2 => 300];

    public function __construct(
        private readonly FiscalProvider $provider,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    public function providerName(): string
    {
        return $this->provider->name();
    }

    /**
     * Encola el envío al confirmar la transacción. El OSE simulado responde en el acto, así que se procesa sin cola
     * (igual fuera de la transacción de emisión: un fallo no la revierte).
     */
    public function queue(Invoice $invoice, string $action = 'send', int $attempt = 1, int $delay = 0): void
    {
        $job = new SendInvoiceToFiscalProvider($invoice->id, $action, $attempt);
        DB::afterCommit(function () use ($job, $delay) {
            if (config('queue.default') === 'sync' || $this->provider instanceof SimulatedFiscalProvider) {
                dispatch_sync($job);
            } else {
                dispatch($job)->delay($delay);
            }
        });
    }

    public function process(string $invoiceId, string $action, int $attempt): ?Invoice
    {
        $invoice = Invoice::find($invoiceId);
        if (! $invoice || $invoice->status !== 'pendiente_envio') {
            return $invoice;
        }
        try {
            $result = $this->provider->send($invoice, $this->sendCount($invoice) + 1);
        } catch (Throwable $e) {
            $result = FiscalResult::temporary($e->getMessage());
        }
        $this->event($invoice, $action, $attempt, $result);

        if ($result->status === 'ok') {
            $invoice->forceFill(['status' => 'emitido', 'ose_ticket' => $result->ticket, 'fiscal_provider' => $this->provider->name()])->save();
            Fin::history('invoice', $invoice->id, 'emitido', "Enviado al OSE ({$this->provider->name()}) · ticket {$result->ticket}", null);
            if ($result->outcome && $result->outcome !== 'emitido') {
                $this->respond($invoice, $result, null);
            }

            return $invoice;
        }
        if ($result->status === 'error_temporal' && $attempt < self::MAX_ATTEMPTS) {
            $this->queue($invoice, $action, $attempt + 1, self::BACKOFF[$attempt] ?? 600);

            return $invoice;
        }
        $this->audit->log('Comprobante', $invoice->number, 'error_fiscal', null, "Envío fallido tras {$attempt} intento(s): {$result->message}", system: true);
        $this->alert($invoice, 'comprobante.error_envio', "No se pudo enviar {$invoice->number} al OSE", "Tras {$attempt} intento(s): {$result->message}. Reintente desde Facturación.");

        return $invoice;
    }

    /** Consultar respuesta OSE (§4.6). */
    public function check(Invoice $invoice, ?User $actor): Invoice
    {
        try {
            $result = $this->provider->status($invoice);
        } catch (Throwable $e) {
            $result = FiscalResult::temporary($e->getMessage());
        }
        $this->event($invoice, 'status', 1, $result);
        if ($result->status === 'ok' && $result->outcome && $result->outcome !== $invoice->status) {
            $this->respond($invoice, $result, $actor);
        }

        return $invoice->refresh();
    }

    public function sendCount(Invoice $invoice): int
    {
        return FiscalEvent::where('invoice_id', $invoice->id)->whereIn('action', ['send', 'resend'])->where('status', 'ok')->count();
    }

    private function respond(Invoice $invoice, FiscalResult $result, ?User $actor): void
    {
        $before = Invoice::STATUS_LABELS[$invoice->status];
        $invoice->forceFill(['status' => $result->outcome, 'ose_response_code' => $result->code, 'ose_response_message' => $result->message,
            'ose_responded_at' => now(), 'hash_code' => $invoice->hash_code ?? hash('sha256', $invoice->number.'|'.$invoice->total_amount.'|'.$invoice->issue_date->toDateString())])->save();
        Fin::history('invoice', $invoice->id, $result->outcome, $result->message, $actor);
        $this->audit->log('Comprobante', $invoice->number, 'respuesta_ose', $before, Invoice::STATUS_LABELS[$result->outcome].($result->message ? " · {$result->message}" : ''),
            actor: $actor, system: $actor === null);
        if (in_array($result->outcome, ['observado', 'rechazado'], true)) {
            $this->alert($invoice, 'comprobante.'.$result->outcome, "{$invoice->number} ".mb_strtolower(Invoice::STATUS_LABELS[$result->outcome]).' por el OSE', (string) $result->message);
        }
    }

    private function event(Invoice $invoice, string $action, int $attempt, FiscalResult $result): void
    {
        FiscalEvent::create(['invoice_id' => $invoice->id, 'action' => $action, 'attempt' => $attempt,
            'request_hash' => hash('sha256', $invoice->id.'|'.$action.'|'.$attempt.'|'.$invoice->total_amount), 'response_code' => $result->code,
            'response_message' => $result->message, 'status' => $result->status, 'created_at' => now()]);
    }

    private function alert(Invoice $invoice, string $event, string $subject, string $body): void
    {
        foreach (CatalogRecipients::role(['accounting', 'finance']) as $user) {
            $this->notify->push($event, 'portal', $user, $subject, $body, ['related_type' => 'invoice', 'related_id' => $invoice->id]);
        }
    }
}
