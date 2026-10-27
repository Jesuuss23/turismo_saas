<?php

namespace App\Services\Collab;

use App\Models\Bookings\CancelRequest;
use App\Models\Bookings\Reservation;
use App\Models\Channel\SupportTicket;
use App\Models\Collab\ApprovalRequest;
use App\Models\Finance\Commission;
use App\Models\Finance\Payable;
use App\Models\Finance\Payment;
use App\Models\Iam\User;
use App\Models\Quotes\InternalApproval;
use App\Models\Quotes\QuoteVersion;
use App\Models\Supplier\RateRequest;
use App\Services\Quotes\QuotePolicy;
use App\Tenancy\TenantContext;

/**
 * Listeners de los módulos de origen (§8): abren la solicitud unificada cuando nace la excepción y la cierran como
 * `closed_in_origin` si se resuelve en su módulo (APR-BR-12). Reemplaza el `sync()` del prototipo.
 */
class OriginSync
{
    public static function register(): void
    {
        InternalApproval::saved(fn (InternalApproval $a) => self::run(fn (self $s) => $s->quoteApproval($a)));
        QuoteVersion::updated(fn (QuoteVersion $v) => self::run(fn (self $s) => $s->quoteVersion($v)));
        CancelRequest::saved(fn (CancelRequest $c) => self::run(fn (self $s) => $s->cancelRequest($c)));
        Payable::saved(fn (Payable $p) => self::run(fn (self $s) => $s->payable($p)));
        Commission::saved(fn (Commission $c) => self::run(fn (self $s) => $s->commission($c)));
        RateRequest::saved(fn (RateRequest $r) => self::run(fn (self $s) => $s->rateRequest($r)));
        Payment::updated(fn (Payment $p) => self::run(fn (self $s) => $s->payment($p)));
        SupportTicket::created(fn (SupportTicket $t) => app(TenantContext::class)->has() ? app(InboxService::class)->fromTicket($t) : null);
    }

    private static function run(callable $fn): void
    {
        if (ApprovalEffects::applying() || ! app(TenantContext::class)->has()) {
            return;
        }
        $fn(app(self::class));
    }

    public function __construct(private readonly ApprovalRequests $requests) {}

    /**
     * Abre las solicitudes de las excepciones pendientes que existían antes del módulo (o cargadas sin eventos, p. ej. la demo)
     * y refleja los tickets sin conversación. Idempotente por `dedupe_key`. @return int solicitudes vivas tras el proceso
     */
    public function backfill(): int
    {
        $asNew = fn ($model) => tap($model, fn ($m) => $m->wasRecentlyCreated = true);
        InternalApproval::where('status', InternalApproval::PENDING)->get()->each(fn ($a) => $this->quoteApproval($asNew($a)));
        CancelRequest::where('status', 'pendiente')->get()->each(fn ($c) => $this->cancelRequest($asNew($c)));
        Payable::whereNull('approved_at')->whereIn('status', Payable::PAYABLE)->get()->each(fn ($p) => $this->payable($asNew($p)));
        Commission::where('status', 'calculada')->get()->each(fn ($c) => $this->commission($asNew($c)));
        RateRequest::where('status', RateRequest::PENDING)->get()->each(fn ($r) => $this->rateRequest($asNew($r)));
        SupportTicket::whereNotExists(fn ($q) => $q->from('collab_conversations as c')->whereColumn('c.support_ticket_id', 'channel_support_tickets.id'))
            ->get()->each(fn (SupportTicket $t) => app(InboxService::class)->fromTicket($t));

        return ApprovalRequest::whereIn('status', ApprovalRequest::LIVE)->count();
    }

    /** QuoteApprovalRequested / QuoteInternallyApproved. */
    public function quoteApproval(InternalApproval $a): void
    {
        $version = QuoteVersion::with('quote')->find($a->quote_version_id);
        if (! $version?->quote) {
            return;
        }
        if ($a->status === InternalApproval::PENDING && $a->wasRecentlyCreated) {
            $this->openQuote($version, User::find($a->requested_by), $a->request_note, 'user');

            return;
        }
        if ($a->status !== InternalApproval::PENDING && ($a->wasRecentlyCreated || $a->wasChanged('status'))) {
            $this->requests->closeFromOrigin('quote_versions', $version->id, $a->status === InternalApproval::VOIDED ? 'anulada' : $a->status,
                User::find($a->decided_by), $a->decision_note, 'quote');
        }
    }

    /** APR-BR-06: versión enviada sin aprobación requerida → «detectada por regla». */
    public function quoteVersion(QuoteVersion $version): void
    {
        if ($version->wasChanged('status') && $version->status === QuoteVersion::SENT && $version->internal_approval_id === null
            && QuotePolicy::state($version) === 'requerida') {
            $version->loadMissing('quote');
            $this->openQuote($version, null, 'Detectada por regla: la versión se envió sin aprobación interna', 'system');
        }
    }

    private function openQuote(QuoteVersion $version, ?User $requester, ?string $note, string $kind): void
    {
        $quote = $version->quote;
        $reasons = QuotePolicy::versionReasons($version);
        $this->requests->open([
            'type' => 'quote', 'subject_type' => 'quote_versions', 'subject_id' => $version->id, 'subject_code' => $quote->code, 'subject_version' => $version->version_no,
            'dedupe_key' => "quote:{$version->id}:v{$version->version_no}", 'title' => "{$quote->code} v{$version->version_no} · ".implode('; ', $reasons),
            'justification' => $note, 'requester' => $requester, 'requester_kind' => $kind,
            'amount' => $version->grand_total, 'currency' => $quote->currency, 'fx_rate' => $version->fx_rate ?? $quote->fx_rate,
            'snapshot' => ['reasons' => $reasons, 'discount_pct' => (string) $version->discount_pct, 'margin_pct' => (string) $version->margin_pct,
                'net_total' => (string) $version->net_total, 'cost_total' => (string) $version->cost_total, 'over_cap' => QuotePolicy::overCap((string) $version->discount_pct)],
        ], $requester);
    }

    /** ReservationCancelRequested / ReservationCancelled. */
    public function cancelRequest(CancelRequest $c): void
    {
        $reservation = Reservation::withTrashed()->find($c->reservation_id);
        if (! $reservation) {
            return;
        }
        if ($c->status === 'pendiente' && $c->wasRecentlyCreated) {
            $requester = User::find($c->requested_by);
            $this->requests->open([
                'type' => 'cancel', 'subject_type' => 'booking_reservations', 'subject_id' => $reservation->id, 'subject_code' => $reservation->code,
                'dedupe_key' => "cancel:{$c->id}", 'title' => "Anulación de {$reservation->code} · {$reservation->title}", 'justification' => $c->reason,
                'requester' => $requester, 'amount' => $reservation->total_amount, 'currency' => $reservation->currency, 'fx_rate' => $reservation->fx_rate,
                'snapshot' => ['cancel_request_id' => $c->id, 'reservation_status' => $reservation->status, 'travel_from' => $reservation->travel_from?->toDateString()],
            ], $requester);

            return;
        }
        if ($c->wasChanged('status') && $c->status !== 'pendiente') {
            $status = ['aprobada' => 'aprobada', 'rechazada' => 'rechazada', 'retirada' => 'anulada'][$c->status] ?? 'anulada';
            $this->requests->closeFromOrigin('booking_reservations', $reservation->id, $status, User::find($c->decided_by), $c->decision_note, 'cancel');
        }
    }

    /** PayableRegistered / PayableApproved. Las CxP automáticas (reserva, factura) las solicita el sistema; el costo adicional, quien lo registró. */
    public function payable(Payable $p): void
    {
        if ($p->wasRecentlyCreated) {
            if ($p->approved_at === null && in_array($p->status, Payable::PAYABLE, true)) {
                $requester = $p->kind === 'costo_adicional' && $p->created_by ? User::find($p->created_by) : null;
                $this->requests->open([
                    'type' => 'payable', 'subject_type' => 'fin_payables', 'subject_id' => $p->id, 'subject_code' => $p->code,
                    'dedupe_key' => "payable:{$p->id}:{$p->approval_round}", 'title' => "{$p->code} · {$p->concept}", 'requester' => $requester,
                    'amount' => $p->amount, 'currency' => $p->currency, 'fx_rate' => $p->fx_rate,
                    'snapshot' => ['provider' => $p->provider?->name, 'reservation' => $p->reservation?->code, 'kind' => $p->kind, 'cost_type' => $p->cost_type, 'due_date' => $p->due_date?->toDateString()],
                ], $requester);
            }

            return;
        }
        if ($p->wasChanged('approved_at') && $p->approved_at !== null) {
            $this->requests->closeFromOrigin('fin_payables', $p->id, 'aprobada', User::find($p->approved_by), null, 'payable');
        } elseif ($p->wasChanged('status') && $p->status === Payable::CANCELLED) {
            $this->requests->closeFromOrigin('fin_payables', $p->id, 'anulada', null, 'Cuenta por pagar anulada', 'payable');
        }
    }

    /** CommissionCalculated / aprobada en Finanzas. */
    public function commission(Commission $c): void
    {
        if ($c->wasRecentlyCreated) {
            if ($c->status === 'calculada') {
                $this->requests->open([
                    'type' => 'commission', 'subject_type' => 'fin_commissions', 'subject_id' => $c->id, 'subject_code' => $c->code,
                    'dedupe_key' => "commission:{$c->id}:{$c->approval_round}", 'title' => "{$c->code} · {$c->beneficiaryName()}",
                    'amount' => $c->amount, 'currency' => $c->currency, 'fx_rate' => $c->fx_rate,
                    'snapshot' => ['reservation' => $c->reservation?->code, 'kind' => $c->kind, 'rule' => $c->rule_description, 'base' => (string) $c->base_amount, 'pct' => (string) $c->pct],
                ]);
            }

            return;
        }
        if ($c->wasChanged('status') && $c->status === 'aprobada') {
            $this->requests->closeFromOrigin('fin_commissions', $c->id, 'aprobada', User::find($c->approved_by), null, 'commission');
        } elseif ($c->wasChanged('deleted_at') && $c->deleted_at !== null) {
            $this->requests->closeFromOrigin('fin_commissions', $c->id, 'anulada', null, 'Comisión eliminada', 'commission');
        }
    }

    /** SupplierRateRequested: el solicitante es el proveedor (§7 Externos). */
    public function rateRequest(RateRequest $r): void
    {
        if ($r->wasRecentlyCreated && $r->status === RateRequest::PENDING) {
            $r->loadMissing(['provider', 'service']);
            $this->requests->open([
                'type' => 'provider_rate', 'subject_type' => 'supplier_rate_requests', 'subject_id' => $r->id, 'subject_code' => $r->code,
                'dedupe_key' => "provider_rate:{$r->id}", 'title' => "{$r->provider?->name} · {$r->service?->name}: {$r->currency} {$r->current_cost} → {$r->proposed_cost}",
                'justification' => trim("{$r->reason_type}. {$r->reason_detail}"), 'requester_kind' => 'supplier', 'requester_supplier_id' => $r->provider_id,
                'amount' => $r->proposed_cost, 'currency' => $r->currency,
                'snapshot' => ['current_cost' => (string) $r->current_cost, 'proposed_cost' => (string) $r->proposed_cost, 'effective_from' => $r->effective_from?->toDateString(),
                    'provider' => $r->provider?->name, 'service' => $r->service?->name],
            ]);

            return;
        }
        if ($r->wasChanged('status') && $r->status !== RateRequest::PENDING) {
            $status = ['aprobada' => 'aprobada', 'rechazada' => 'rechazada'][$r->status] ?? 'anulada';
            $this->requests->closeFromOrigin('supplier_rate_requests', $r->id, $status, User::find($r->reviewed_by), $r->review_note, 'provider_rate');
        }
    }

    /** PaymentReverted desde Finanzas: la solicitud de reversión viva queda aprobada en origen. */
    public function payment(Payment $p): void
    {
        if ($p->wasChanged('status') && $p->status === 'revertido') {
            $this->requests->closeFromOrigin('fin_payments', $p->id, 'aprobada', User::find($p->reverted_by), $p->revert_reason, 'reversal');
            if ($p->reversal_request_id) {
                $p->forceFill(['reversal_request_id' => null])->saveQuietly();
            }
        }
    }
}
