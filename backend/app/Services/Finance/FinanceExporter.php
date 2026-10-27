<?php

namespace App\Services\Finance;

use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use Illuminate\Support\Collection;

/** Exportación CSV de CxC, pagos y comprobantes (spec 07 §2). Alcance: todo el tenant para los roles con acceso (§7). */
class FinanceExporter
{
    public const RESOURCES = [
        'finance.receivables' => ['finance.receivables'],
        'finance.payments' => ['finance.receivables', 'finance.payments.register'],
        'finance.invoices' => ['finance.invoicing'],
    ];

    /** @return array{0: list<string>, 1: Collection<int, list<mixed>>} */
    public function rows(string $resource, User $user, array $filters = []): array
    {
        return match ($resource) {
            'finance.payments' => [
                ['Código', 'Tipo', 'Método', 'Referencia', 'CxC', 'Reserva', 'Cliente', 'Fecha', 'Moneda', 'Monto', 'Estado'],
                Payment::with(['receivable:id,code', 'reservation:id,code', 'client:id,name'])
                    ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                    ->when($filters['method'] ?? null, fn ($q, $m) => $q->where('method', $m))
                    ->orderByDesc('paid_on')->get()
                    ->map(fn (Payment $p) => [$p->code, $p->kind, $p->method, $p->reference, $p->receivable?->code, $p->reservation?->code, $p->client?->name,
                        $p->paid_on->toDateString(), $p->currency, (string) $p->amount, Payment::STATUS_LABELS[$p->status] ?? $p->status]),
            ],
            'finance.invoices' => [
                ['Número', 'Tipo', 'Emisión', 'Cliente', 'Documento', 'Reserva', 'Moneda', 'Base', 'IGV', 'Total', 'Estado', 'Código OSE'],
                Invoice::with('reservation:id,code')
                    ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('doc_type', $t))
                    ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                    ->orderByDesc('issue_date')->get()
                    ->map(fn (Invoice $i) => [$i->number, Invoice::TYPE_LABELS[$i->doc_type], $i->issue_date->toDateString(), $i->customer_name,
                        "{$i->customer_doc_type} {$i->customer_doc_number}", $i->reservation?->code, $i->currency, (string) $i->taxable_amount, (string) $i->igv_amount,
                        (string) $i->total_amount, Invoice::STATUS_LABELS[$i->status], $i->ose_response_code]),
            ],
            default => [
                ['Código', 'Concepto', 'Reserva', 'Cliente', 'Vence', 'Moneda', 'Importe', 'Pagado', 'Saldo', 'Estado'],
                Receivable::with(['reservation:id,code', 'client:id,name'])
                    ->when($filters['status'] ?? null, fn ($q, $s) => $s === 'abiertas' ? $q->whereIn('status', Receivable::OPEN) : $q->where('status', $s))
                    ->when($filters['currency'] ?? null, fn ($q, $c) => $q->where('currency', $c))
                    ->orderBy('due_date')->get()
                    ->map(fn (Receivable $r) => [$r->code, $r->concept, $r->reservation?->code, $r->client?->name, $r->due_date->toDateString(), $r->currency,
                        (string) $r->amount, (string) $r->paid_amount, $r->balance(), Receivable::STATUS_LABELS[$r->status]]),
            ],
        };
    }
}
