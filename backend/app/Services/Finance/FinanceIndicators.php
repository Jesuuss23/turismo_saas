<?php

namespace App\Services\Finance;

use App\Models\Bookings\Reservation;
use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Services\Crm\CrmClock;
use Illuminate\Support\Collection;

/** Indicadores compartidos por paneles, facturación y cierre (una sola fuente con TC conservado y estado derivado; §11 C19). */
class FinanceIndicators
{
    public function __construct(private readonly CrmClock $clock, private readonly FxRates $fx) {}

    public function paymentPen(Payment $p): float
    {
        return $this->fx->toPen($p->amount, $p->currency, $p->fx_rate);
    }

    public function invoicePen(Invoice $i): float
    {
        return $this->fx->toPen($i->total_amount, $i->currency, $i->fx_rate);
    }

    public function receivablePen(Receivable $r, ?float $amount = null): float
    {
        return $this->fx->toPen($amount ?? (float) $r->balance(), $r->currency, $r->fx_rate);
    }

    /**
     * "Cobros sin comprobante" (§2.2): pagos aplicados/confirmados sin comprobante ≠ NC de la misma reserva con importe igual ±0,50 PEN.
     *
     * @return Collection<int, Payment>
     */
    public function paymentsWithoutInvoice(?string $period = null): Collection
    {
        $payments = Payment::whereIn('status', ['aplicado', 'confirmado'])->where('kind', '<>', 'reembolso')
            ->when($period, fn ($q) => $q->whereRaw("to_char(paid_on, 'YYYY-MM') = ?", [$period]))->with(['reservation:id,code', 'client:id,name'])->orderByDesc('paid_on')->get();
        $invoices = Invoice::whereIn('reservation_id', $payments->pluck('reservation_id')->filter()->unique())->where('doc_type', '<>', Invoice::CREDIT_NOTE)
            ->whereIn('status', Invoice::COUNTED)->get()->groupBy('reservation_id');

        return $payments->filter(function (Payment $p) use ($invoices) {
            $pen = $this->paymentPen($p);

            return ! ($invoices[$p->reservation_id] ?? collect())->contains(fn (Invoice $i) => abs($this->invoicePen($i) - $pen) < Fin::MATCH);
        })->values();
    }

    /**
     * FIN-BR-50 "Cobrado por facturar" por reserva: Σ pagos aplicados (PEN) − Σ comprobantes ≠ NC no anulados (PEN) > 0,50.
     *
     * @return list<array{reservation: string, reservation_id: string, client: ?string, collected: string, invoiced: string, pending: string, receivables: list<array>}>
     */
    public function uninvoiced(): array
    {
        $applied = Payment::where('status', 'aplicado')->where('kind', '<>', 'reembolso')->whereNotNull('reservation_id')->get()->groupBy('reservation_id');
        $invoices = Invoice::whereIn('reservation_id', $applied->keys())->where('doc_type', '<>', Invoice::CREDIT_NOTE)->whereIn('status', Invoice::COUNTED)->get()->groupBy('reservation_id');
        $reservations = Reservation::whereIn('id', $applied->keys())->with('client:id,name')->get()->keyBy('id');
        $rows = [];
        foreach ($applied as $reservationId => $payments) {
            $collected = $payments->sum(fn (Payment $p) => $this->paymentPen($p));
            $invoiced = ($invoices[$reservationId] ?? collect())->sum(fn (Invoice $i) => $this->invoicePen($i));
            if ($collected - $invoiced <= Fin::MATCH || ! ($r = $reservations[$reservationId] ?? null)) {
                continue;
            }
            $receivables = Receivable::where('reservation_id', $reservationId)->where('status', '<>', 'cancelada')->where('paid_amount', '>', 0)->orderBy('due_date')->get()
                ->map(fn (Receivable $c) => ['id' => $c->id, 'code' => $c->code, 'concept' => $c->concept, 'currency' => $c->currency, 'paid' => $c->paid_amount,
                    'max' => Fin::money(app(InvoiceService::class)->maxInvoiceable($c))])->filter(fn ($c) => (float) $c['max'] > Fin::EPS)->values()->all();
            $rows[] = ['reservation' => $r->code, 'reservation_id' => $r->id, 'client' => $r->client?->name, 'collected' => Fin::money($collected),
                'invoiced' => Fin::money($invoiced), 'pending' => Fin::money($collected - $invoiced), 'receivables' => $receivables];
        }

        return $rows;
    }

    /** Antigüedad de CxC abiertas en PEN (TC conservado). `$buckets` 4 (módulo) o 5 (panel Finanzas). */
    public function aging(int $buckets = 4): array
    {
        $today = $this->clock->today();
        $labels = $buckets === 5 ? ['Por vencer', '1–30', '31–60', '61–90', '> 90'] : ['Por vencer', '1–30', '31–60', '> 60'];
        $totals = array_fill_keys($labels, 0.0);
        foreach (Receivable::whereIn('status', Receivable::OPEN)->get() as $r) {
            $days = (int) $r->due_date->diffInDays($today, false);
            $label = match (true) {
                $days <= 0 => 'Por vencer',
                $days <= 30 => '1–30',
                $days <= 60 => '31–60',
                $buckets === 4 => '> 60',
                $days <= 90 => '61–90',
                default => '> 90',
            };
            $totals[$label] += $this->receivablePen($r);
        }

        return array_map(fn ($label) => ['label' => $label, 'amount' => Fin::money($totals[$label])], $labels);
    }
}
