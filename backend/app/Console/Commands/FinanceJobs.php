<?php

namespace App\Console\Commands;

use App\Models\Finance\Invoice;
use App\Models\Finance\Receivable;
use App\Models\Sys\NotifMessage;
use App\Services\Crm\CrmClock;
use App\Services\Finance\Fiscal\FiscalGateway;
use App\Services\Finance\PayableManager;
use App\Services\Finance\ReceivableLedger;
use App\Support\OperatingTenants;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Jobs de Finanzas (spec 07 §8.2), por tenant activo:
 * - `fin:mark-overdue` (diario 00:05): CxC y CxP vencidas (T21).
 * - `fin:payment-reminders` (diario): recordatorio a CxC vencidas o que vencen en 3 días, como máximo uno cada 3 días por CxC.
 * - `fin:fiscal-poll` (cada 5 min): reintenta envíos pendientes y consulta la respuesta OSE de comprobantes emitidos (PollFiscalStatus).
 */
class FinanceJobs extends Command
{
    protected $signature = 'fin:run {job : mark-overdue|payment-reminders|fiscal-poll} {--tenant= : solo este tenant (Ejecutar ahora)}';

    protected $description = 'Finanzas: vencimientos, recordatorios de pago y consulta al OSE';

    public function handle(TenantContext $context): int
    {
        $job = $this->argument('job');
        $method = match ($job) {
            'mark-overdue' => 'markOverdue', 'payment-reminders' => 'reminders', 'fiscal-poll' => 'fiscalPoll', default => null,
        };
        if (! $method) {
            $this->error('Job inválido.');

            return self::INVALID;
        }
        foreach (OperatingTenants::ids($this->option('tenant'), $this->argument('job') === 'payment-reminders' ? 'JOB-03' : null) as $tenantId) {
            $context->run($tenantId, fn () => $this->line("{$job}: ".$this->{$method}()));
        }

        return self::SUCCESS;
    }

    private function markOverdue(): string
    {
        return app(ReceivableLedger::class)->markOverdue().' CxC y '.app(PayableManager::class)->markOverdue().' CxP vencidas';
    }

    private function reminders(): string
    {
        $today = app(CrmClock::class)->today();
        $ledger = app(ReceivableLedger::class);
        $sent = 0;
        $due = Receivable::whereIn('status', Receivable::OPEN)->whereDate('due_date', '<=', $today->copy()->addDays(3)->toDateString())
            ->where(fn ($q) => $q->whereNull('last_reminder_at')->orWhere('last_reminder_at', '<', $today->copy()->subDays(3)))->get();
        foreach ($due as $r) {
            if ($r->client?->email && ! NotifMessage::where('event', 'pago.recordatorio')->where('related_id', $r->id)->whereDate('created_at', $today->toDateString())->exists()) {
                $ledger->remind($r, null);
                $sent++;
            }
        }

        return "{$sent} recordatorio(s)";
    }

    private function fiscalPoll(): string
    {
        $gateway = app(FiscalGateway::class);
        $count = 0;
        foreach (Invoice::whereIn('status', ['pendiente_envio', 'emitido'])->where('updated_at', '<', now()->subMinute())->get() as $invoice) {
            $invoice->status === 'pendiente_envio' ? $gateway->process($invoice->id, 'send', 1) : $gateway->check($invoice, null);
            $count++;
        }

        return "{$count} comprobante(s) consultados";
    }
}
