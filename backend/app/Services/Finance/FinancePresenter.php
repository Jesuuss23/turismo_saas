<?php

namespace App\Services\Finance;

use App\Models\Finance\BankLine;
use App\Models\Finance\Commission;
use App\Models\Finance\Invoice;
use App\Models\Finance\Payable;
use App\Models\Finance\Payment;
use App\Models\Finance\Payout;
use App\Models\Finance\Receivable;
use App\Models\Iam\User;
use App\Models\Sys\AuditEvent;
use App\Models\Sys\NotifMessage;
use App\Services\Crm\CrmClock;

/** Filas y detalles del módulo Finanzas (§6). Los montos van como string con 2 decimales; `pen` al TC conservado del documento. */
class FinancePresenter
{
    public function __construct(private readonly FxRates $fx, private readonly CrmClock $clock) {}

    public function receivable(Receivable $r): array
    {
        return ['id' => $r->id, 'code' => $r->code, 'concept' => $r->concept, 'kind' => $r->kind, 'kind_label' => Receivable::KIND_LABELS[$r->kind] ?? $r->kind,
            'reservation' => $r->reservation?->code, 'reservation_id' => $r->reservation_id, 'client' => $r->client?->name, 'client_code' => $r->client?->code,
            'client_id' => $r->client_id, 'currency' => $r->currency, 'fx_rate' => $r->fx_rate, 'amount' => (string) $r->amount, 'paid' => (string) $r->paid_amount,
            'balance' => $r->balance(), 'balance_pen' => Fin::money($this->fx->toPen($r->balance(), $r->currency, $r->fx_rate)),
            'due_date' => $r->due_date->toDateString(), 'status' => $r->status, 'status_label' => Receivable::STATUS_LABELS[$r->status],
            'days_overdue' => $r->status === 'vencida' ? (int) $r->due_date->diffInDays($this->clock->today()) : 0,
            'last_reminder_at' => $r->last_reminder_at?->toIso8601String(), 'lock_version' => $r->lock_version];
    }

    public function payment(Payment $p): array
    {
        return ['id' => $p->id, 'code' => $p->code, 'kind' => $p->kind, 'source' => $p->source, 'method' => $p->method, 'reference' => $p->reference,
            'receivable' => $p->receivable?->code, 'receivable_id' => $p->receivable_id, 'reservation' => $p->reservation?->code, 'client' => $p->client?->name,
            'currency' => $p->currency, 'fx_rate' => $p->fx_rate, 'amount' => (string) $p->amount, 'amount_pen' => Fin::money($this->fx->toPen($p->amount, $p->currency, $p->fx_rate)),
            'paid_on' => $p->paid_on->toDateString(), 'status' => $p->status, 'status_label' => Payment::STATUS_LABELS[$p->status] ?? $p->status,
            'note' => $p->note, 'reject_reason' => $p->reject_reason, 'retries' => (int) $p->retries, 'revert_reason' => $p->revert_reason,
            'registered_by' => $p->registeredBy?->name, 'lock_version' => $p->lock_version];
    }

    public function payable(Payable $p): array
    {
        $today = $this->clock->today()->toDateString();

        return ['id' => $p->id, 'code' => $p->code, 'kind' => $p->kind, 'cost_type' => $p->cost_type, 'cost_type_label' => Payable::COST_TYPES[$p->cost_type] ?? null,
            'provider' => $p->provider?->name, 'provider_ref' => $p->provider?->ref, 'provider_id' => $p->provider_id, 'provider_status' => $p->provider?->status,
            'reservation' => $p->reservation?->code, 'reservation_id' => $p->reservation_id, 'service' => $p->service?->code, 'concept' => $p->concept,
            'currency' => $p->currency, 'fx_rate' => $p->fx_rate, 'amount' => (string) $p->amount, 'paid' => (string) $p->paid_amount, 'balance' => $p->balance(),
            'balance_pen' => Fin::money($this->fx->toPen($p->balance(), $p->currency, $p->fx_rate)), 'due_date' => $p->due_date->toDateString(),
            'overdue' => $p->due_date->toDateString() < $today && in_array($p->status, Payable::PAYABLE, true),
            'status' => $p->status, 'status_label' => Payable::STATUS_LABELS[$p->status], 'approved' => $p->approved_at !== null,
            'approved_at' => $p->approved_at?->toIso8601String(), 'scheduled_date' => $p->scheduled_date?->toDateString(),
            'supplier_doc_number' => $p->supplier_doc_number, 'evidence_note' => $p->evidence_note,
            'supplier_invoice' => $p->relationLoaded('activeInvoice') && $p->activeInvoice ? ['number' => $p->activeInvoice->number, 'status' => $p->activeInvoice->status] : null,
            'lock_version' => $p->lock_version];
    }

    public function payout(Payout $o): array
    {
        return ['code' => $o->code, 'paid_on' => $o->paid_on->toDateString(), 'amount' => (string) $o->amount, 'fx_rate' => $o->fx_rate, 'method' => $o->method,
            'method_label' => Payout::METHODS[$o->method], 'operation_ref' => $o->operation_ref, 'bank_line' => $o->bank_line_id ? BankLine::find($o->bank_line_id)?->code : null];
    }

    public function commission(Commission $c): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'kind' => $c->kind, 'kind_label' => Commission::KIND_LABELS[$c->kind],
            'beneficiary' => $c->beneficiaryName(), 'reservation' => $c->reservation?->code, 'channel' => $c->reservation?->channel ?? ($c->kind === 'bono_meta' ? 'Meta comercial' : null),
            'sales_target' => $c->salesTarget?->code,
            'rule' => $c->rule_description, 'base' => (string) $c->base_amount, 'pct' => (string) $c->pct, 'amount' => (string) $c->amount, 'currency' => $c->currency,
            'amount_pen' => Fin::money($this->fx->toPen($c->amount, $c->currency, $c->fx_rate)), 'status' => $c->status, 'status_label' => Commission::STATUS_LABELS[$c->status],
            'payment_method' => $c->payment_method, 'payment_ref' => $c->payment_ref, 'paid_at' => $c->paid_at?->toIso8601String(),
            'pay_methods' => Commission::PAY_METHODS[$c->kind], 'lock_version' => $c->lock_version];
    }

    public function invoice(Invoice $i): array
    {
        return ['id' => $i->id, 'number' => $i->number, 'doc_type' => $i->doc_type, 'type_label' => Invoice::TYPE_LABELS[$i->doc_type], 'series' => $i->series,
            'issue_date' => $i->issue_date->toDateString(), 'period' => $i->period, 'customer_name' => $i->customer_name, 'customer_doc_type' => $i->customer_doc_type,
            'customer_doc_number' => $i->customer_doc_number, 'customer_address' => $i->customer_address, 'domiciled' => $i->customer_domiciled,
            'reservation' => $i->reservation?->code, 'receivable' => $i->receivable?->code, 'receivable_id' => $i->receivable_id, 'client' => $i->client?->name,
            'operation_type' => $i->operation_type, 'currency' => $i->currency, 'fx_rate' => $i->fx_rate, 'taxable' => (string) $i->taxable_amount,
            'igv' => (string) $i->igv_amount, 'total' => (string) $i->total_amount, 'total_pen' => Fin::money($this->fx->toPen($i->total_amount, $i->currency, $i->fx_rate)),
            'in_words' => $i->amount_in_words, 'description' => $i->description, 'reference' => $i->reference?->number, 'credit_reason' => $i->credit_note_reason,
            'credit_reason_code' => $i->credit_note_reason_code, 'status' => $i->status, 'status_label' => Invoice::STATUS_LABELS[$i->status],
            'provider' => $i->fiscal_provider, 'ticket' => $i->ose_ticket, 'ose_code' => $i->ose_response_code, 'ose_message' => $i->ose_response_message,
            'ose_responded_at' => $i->ose_responded_at?->toIso8601String(), 'hash' => $i->hash_code, 'delivered_at' => $i->delivered_at?->toIso8601String(),
            'lock_version' => $i->lock_version];
    }

    public function bankLine(BankLine $l): array
    {
        $target = $l->matched_payment_id ? $l->payment : ($l->matched_payable_id ? $l->payable : null);

        return ['id' => $l->id, 'code' => $l->code, 'bank' => $l->bank, 'value_date' => $l->value_date->toDateString(), 'description' => $l->description,
            'amount' => (string) $l->amount, 'currency' => $l->currency, 'kind' => (float) $l->amount > 0 ? 'abono' : 'cargo',
            'status' => $l->status, 'status_label' => BankLine::STATUS_LABELS[$l->status], 'resolution' => $l->resolution, 'resolution_note' => $l->resolution_note,
            'match' => $target ? ['type' => $l->matched_payment_id ? 'payment' : 'payable', 'code' => $target->code,
                'label' => $l->matched_payment_id ? "{$target->method} · {$target->reference}" : (string) $target->provider?->name,
                'amount' => "{$target->currency} ".(string) $target->amount] : null,
            'closed' => $l->closure_id !== null, 'lock_version' => $l->lock_version];
    }

    /** Auditoría de las entidades indicadas (entidad => ids). */
    public function audit(array $refs): array
    {
        $rows = AuditEvent::where(function ($q) use ($refs) {
            foreach ($refs as $entity => $ids) {
                $ids = array_values(array_filter((array) $ids));
                if ($ids) {
                    $q->orWhere(fn ($w) => $w->where('entity', $entity)->whereIn('entity_id', $ids));
                }
            }
        })->orderByDesc('occurred_at')->limit(100)->get();
        $users = User::whereIn('id', $rows->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn (AuditEvent $e) => ['entity' => $e->entity, 'entity_id' => $e->entity_id, 'action' => $e->action, 'before' => $e->before_value,
            'after' => $e->after_value, 'actor' => $e->actor_id ? ($users[$e->actor_id] ?? '—') : 'Sistema', 'at' => $e->occurred_at])->all();
    }

    /** Notificaciones vinculadas a un documento. */
    public function notifications(string $type, array $ids): array
    {
        return NotifMessage::where('related_type', $type)->whereIn('related_id', $ids)->orderByDesc('created_at')->limit(30)->get()
            ->map(fn (NotifMessage $m) => ['event' => $m->event, 'channel' => $m->channel, 'recipient' => $m->recipient, 'subject' => $m->subject,
                'status' => $m->status, 'at' => $m->created_at?->toIso8601String()])->all();
    }
}
