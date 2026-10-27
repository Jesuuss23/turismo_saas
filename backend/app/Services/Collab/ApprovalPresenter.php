<?php

namespace App\Services\Collab;

use App\Models\Channel\B2bAgency;
use App\Models\Collab\ApprovalEvent;
use App\Models\Collab\ApprovalInfoRequest;
use App\Models\Collab\ApprovalRequest;
use App\Models\Collab\ApprovalRule;
use App\Models\Collab\ApprovalStep;
use App\Models\Finance\Commission;
use App\Models\Finance\Payable;
use App\Models\Finance\Payment;
use App\Models\Iam\User;
use App\Models\Quotes\QuoteItem;
use App\Models\Supplier\RateRequest;
use App\Services\Bookings\CancellationService;
use App\Services\Finance\PayableManager;
use App\Services\Quotes\QuotePolicy;
use Throwable;

/** Respuestas de la bandeja de aprobaciones (§2): fila de listado y detalle con flujo, origen, consultas y acciones del usuario. */
class ApprovalPresenter
{
    public function __construct(
        private readonly ApprovalRouter $router,
        private readonly ApprovalSla $sla,
        private readonly ApprovalDecisions $decisions,
        private readonly ApprovalEffects $effects,
    ) {}

    public function row(ApprovalRequest $r, ?User $viewer = null): array
    {
        return [
            'id' => $r->id, 'code' => $r->code, 'type' => $r->request_type, 'type_label' => CollabCatalog::TYPES[$r->request_type], 'is_generic' => $r->is_generic,
            'title' => $r->title, 'subject_code' => $r->subject_code, 'subject_version' => $r->subject_version, 'link' => $this->link($r),
            'requester' => $this->requesterName($r), 'requester_id' => $r->requester_id, 'requester_kind' => $r->requester_kind,
            'created_at' => $r->created_at?->toIso8601String(), 'amount' => (string) $r->amount, 'currency' => $r->currency, 'amount_base' => (string) $r->amount_base,
            'status' => $r->status, 'status_label' => CollabCatalog::STATUS_LABELS[$r->status], 'closed_in_origin' => $r->closed_in_origin,
            'current_level' => $r->current_level, 'total_levels' => $r->total_levels, 'round' => $r->round,
            'due_at' => $r->due_at?->toIso8601String(), 'sla' => $this->sla->state($r), 'delegated_to' => $r->delegate?->name,
            'decided_by' => $r->decider?->name, 'decided_at' => $r->decided_at?->toIso8601String(), 'decision_note' => $r->decision_note,
            'lock_version' => $r->lock_version,
            'mine' => $viewer && $r->status === 'pendiente' && $this->router->blocker($viewer, $r) === null,
        ];
    }

    public function detail(ApprovalRequest $r, User $viewer): array
    {
        $r->load(['requester', 'delegate', 'decider', 'steps.user', 'steps.rule', 'events.actor', 'infoRequests.asker', 'infoRequests.answerer']);
        $levels = $r->isLive() ? $this->router->levels($r->request_type, (float) $r->amount_base) : [];
        $steps = $r->steps->keyBy('level');
        $flow = [];
        for ($level = 1; $level <= $r->total_levels; $level++) {
            $step = $steps->get($level);
            $rule = $step?->rule ?? ($levels[$level] ?? ($level === $r->current_level ? $r->rule : null));
            $flow[] = [
                'level' => $level, 'rule' => $rule?->code, 'permission' => $rule?->permission, 'min_amount_pen' => $rule ? (string) $rule->min_amount_pen : null,
                'sla_hours' => $rule?->sla_hours,
                'state' => $step ? $step->decision : ($r->isLive() ? ($level === $r->current_level ? ($r->status === 'info' ? 'info' : 'pendiente') : 'en_espera') : 'no_aplica'),
                'decided_by' => $step?->user?->name, 'decided_at' => $step?->decided_at?->toIso8601String(), 'note' => $step?->note,
            ];
        }
        $rule = $r->isLive() ? $this->router->currentRule($r) : $r->rule;
        $eligible = $r->isLive() ? $this->router->eligibleUsers($r, $rule) : collect();
        $blocker = $r->isLive() ? $this->router->blocker($viewer, $r, $rule) : null;
        $canDecide = $r->status === 'pendiente' && $blocker === null;
        $openInfo = $r->infoRequests->first(fn (ApprovalInfoRequest $i) => $i->answered_at === null);

        return $this->row($r, $viewer) + [
            'justification' => $r->justification, 'flow' => $flow,
            'rule' => $rule ? ['code' => $rule->code, 'permission' => $rule->permission, 'sla_hours' => $rule->sla_hours, 'forbid_self_approval' => $rule->forbid_self_approval] : null,
            'eligible' => $eligible->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
            'delegates' => $canDecide ? $this->router->eligibleUsers($r, $rule, true)->reject(fn (User $u) => $u->id === $viewer->id)->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all() : [],
            'steps' => $r->steps->map(fn (ApprovalStep $s) => ['level' => $s->level, 'decision' => $s->decision, 'by' => $s->user?->name, 'at' => $s->decided_at->toIso8601String(), 'note' => $s->note, 'rule' => $s->rule?->code])->all(),
            'info_requests' => $r->infoRequests->map(fn (ApprovalInfoRequest $i) => [
                'id' => $i->id, 'question' => $i->question, 'asked_by' => $i->asker?->name, 'asked_at' => $i->asked_at->toIso8601String(),
                'answer' => $i->answer, 'answered_by' => $i->answerer?->name, 'answered_at' => $i->answered_at?->toIso8601String(),
            ])->all(),
            'events' => $r->events->map(fn (ApprovalEvent $e) => ['event' => $e->event, 'label' => CollabCatalog::EVENT_LABELS[$e->event], 'actor' => $e->actor?->name ?? 'Sistema',
                'note' => $e->note, 'at' => $e->occurred_at->toIso8601String()])->all(),
            'effect' => CollabCatalog::EFFECTS[$r->request_type], 'origin' => $this->origin($r, $viewer), 'snapshot' => $this->snapshot($r, $viewer),
            'actions' => [
                'approve' => $canDecide, 'reject' => $canDecide, 'ask_info' => $canDecide, 'delegate' => $canDecide,
                'answer' => $r->status === 'info' && $openInfo && $this->decisions->canAnswer($viewer, $r) ? $openInfo->id : null,
                'withdraw' => $r->isLive() && $r->requester_id === $viewer->id && in_array($r->request_type, CollabCatalog::WITHDRAWABLE, true),
                'resubmit' => $r->status === 'rechazada' && isset(CollabCatalog::RESUBMITTABLE[$r->request_type])
                    && ($r->requester_id === $viewer->id || $viewer->checkPermissionTo(CollabCatalog::RESUBMITTABLE[$r->request_type])) && ! $r->closed_in_origin,
                'blocked_reason' => $r->isLive() && ! $canDecide ? $blocker[1] ?? null : null,
                'needs_override' => $r->request_type === 'quote' && ($r->origin_snapshot['over_cap'] ?? false) && ! $viewer->checkPermissionTo('quotes.discount_override'),
            ],
        ];
    }

    /** §7 datos sensibles: margen y costos con permiso de rentabilidad/aprobación o si es el dueño. */
    public function canSeeMargin(ApprovalRequest $r, User $viewer): bool
    {
        return $viewer->checkPermissionTo('finance.profitability') || $viewer->checkPermissionTo('catalog.products.view_profitability')
            || $viewer->checkPermissionTo('quotes.approve_internal') || ($r->requester_id !== null && $r->requester_id === $viewer->id);
    }

    private function snapshot(ApprovalRequest $r, User $viewer): ?array
    {
        $snapshot = $r->origin_snapshot;
        if ($snapshot && $r->request_type === 'quote' && ! $this->canSeeMargin($r, $viewer)) {
            unset($snapshot['margin_pct'], $snapshot['cost_total']);
        }

        return $snapshot;
    }

    /** Detalle vivo del registro de origen (§2: líneas y margen, impacto de cancelación, saldo de CxC…). */
    private function origin(ApprovalRequest $r, User $viewer): ?array
    {
        try {
            return match ($r->request_type) {
                'quote' => $this->quoteOrigin($r, $viewer),
                'cancel' => $this->cancelOrigin($r),
                'reversal' => $this->reversalOrigin($r),
                'payable' => $this->payableOrigin($r),
                'commission' => $this->commissionOrigin($r),
                'provider_rate' => $this->rateOrigin($r),
                'credit_b2b' => $this->creditOrigin($r),
                'refund' => $this->refundOrigin($r),
                default => null,
            };
        } catch (Throwable) {
            return ['unavailable' => 'El registro de origen ya no está disponible o cambió de estado'];
        }
    }

    private function quoteOrigin(ApprovalRequest $r, User $viewer): array
    {
        $version = $this->effects->version($r);
        $quote = $version->quote;
        $margin = $this->canSeeMargin($r, $viewer);
        $items = QuoteItem::where('quote_version_id', $version->id)->with('product:id,name')->orderBy('line_no')->get();

        return ['kind' => 'quote', 'title' => "{$quote->code} v{$version->version_no} · {$quote->title}", 'status' => $version->status,
            'client' => $quote->client?->name, 'owner' => $quote->owner?->name, 'currency' => $quote->currency,
            'policy_state' => QuotePolicy::state($version), 'reasons' => QuotePolicy::versionReasons($version), 'over_cap' => QuotePolicy::overCap((string) $version->discount_pct),
            'discount_pct' => (string) $version->discount_pct, 'net_total' => (string) $version->net_total, 'grand_total' => (string) $version->grand_total,
            'margin_pct' => $margin ? (string) $version->margin_pct : null, 'cost_total' => $margin ? (string) $version->cost_total : null, 'show_margin' => $margin,
            'lines' => $items->map(fn (QuoteItem $i) => ['line' => $i->line_no, 'product' => $i->product?->name, 'date' => $i->service_date?->toDateString(),
                'qty' => $i->qty, 'unit_price' => (string) $i->unit_price, 'sale_total' => (string) $i->sale_total, 'cost_total' => $margin ? (string) $i->cost_total : null])->all()];
    }

    private function cancelOrigin(ApprovalRequest $r): array
    {
        $reservation = $this->effects->reservation($r);
        $request = $this->effects->cancelRequest($r);
        $preview = in_array($reservation->status, ['completada', 'cancelada'], true) ? null : app(CancellationService::class)->preview($reservation);

        return ['kind' => 'cancel', 'title' => "{$reservation->code} · {$reservation->title}", 'status' => $reservation->status, 'client' => $reservation->client?->name,
            'travel' => $reservation->travel_from?->toDateString().' → '.$reservation->travel_to?->toDateString(), 'reason' => $request->reason, 'request_status' => $request->status,
            'impact' => $preview ? ['currency' => $preview['currency'], 'total' => $preview['total'], 'paid' => $preview['paid'], 'penalty' => $preview['penalty'],
                'refund' => $preview['refund'], 'owed' => $preview['owed'], 'effects' => $preview['effects']] : null];
    }

    private function reversalOrigin(ApprovalRequest $r): array
    {
        $p = $this->effects->payment($r)->load(['receivable', 'reservation:id,code', 'client:id,name']);

        return ['kind' => 'reversal', 'title' => "{$p->code} · {$p->method}", 'status' => $p->status, 'client' => $p->client?->name, 'reservation' => $p->reservation?->code,
            'amount' => "{$p->currency} {$p->amount}", 'paid_on' => $p->paid_on?->toDateString(), 'reference' => $p->reference,
            'receivable' => $p->receivable ? ['code' => $p->receivable->code, 'balance' => $p->receivable->balance(), 'status' => $p->receivable->status] : null,
            'valid' => in_array($p->status, ['confirmado', 'aplicado'], true)];
    }

    private function payableOrigin(ApprovalRequest $r): array
    {
        $p = Payable::with(['provider:id,name,status', 'reservation:id,code'])->findOrFail($r->subject_id);

        return ['kind' => 'payable', 'title' => "{$p->code} · {$p->concept}", 'status' => $p->status, 'provider' => $p->provider?->name, 'reservation' => $p->reservation?->code,
            'amount' => "{$p->currency} {$p->amount}", 'due_date' => $p->due_date?->toDateString(), 'kind_label' => $p->kind, 'cost_type' => $p->cost_type,
            'approved' => $p->approved_at !== null, 'round' => $p->approval_round, 'observation' => $p->observation, 'warnings' => app(PayableManager::class)->warnings($p)];
    }

    private function commissionOrigin(ApprovalRequest $r): array
    {
        $c = Commission::with('reservation:id,code')->findOrFail($r->subject_id);

        return ['kind' => 'commission', 'title' => "{$c->code} · {$c->beneficiaryName()}", 'status' => $c->status, 'reservation' => $c->reservation?->code,
            'rule' => $c->rule_description, 'base' => (string) $c->base_amount, 'pct' => (string) $c->pct, 'amount' => "{$c->currency} {$c->amount}",
            'round' => $c->approval_round, 'observation' => $c->observation];
    }

    private function rateOrigin(ApprovalRequest $r): array
    {
        $q = RateRequest::with(['provider:id,name', 'service'])->findOrFail($r->subject_id);

        return ['kind' => 'provider_rate', 'title' => "{$q->code} · {$q->provider?->name}", 'status' => $q->status, 'service' => $q->service?->name,
            'current_cost' => "{$q->currency} {$q->current_cost}", 'proposed_cost' => "{$q->currency} {$q->proposed_cost}", 'effective_from' => $q->effective_from?->toDateString(),
            'reason' => trim("{$q->reason_type}. {$q->reason_detail}"),
            'variation_pct' => (float) $q->current_cost > 0 ? round(((float) $q->proposed_cost / (float) $q->current_cost - 1) * 100, 1) : null];
    }

    private function creditOrigin(ApprovalRequest $r): array
    {
        $a = B2bAgency::findOrFail($r->subject_id);

        return ['kind' => 'credit_b2b', 'title' => "{$a->code} · {$a->name}", 'status' => $a->effectiveStatus(), 'currency' => $a->credit_currency,
            'credit_limit' => (string) $a->credit_limit, 'credit_used' => (string) $a->credit_used, 'available' => $a->available(),
            'limit_after' => number_format((float) $a->credit_limit + (float) $r->amount, 2, '.', ''), 'block_reason' => $a->block_reason];
    }

    private function refundOrigin(ApprovalRequest $r): array
    {
        $res = $this->effects->reservation($r);
        $paid = Payment::where('reservation_id', $res->id)->where('kind', 'cobro')->whereIn('status', Payment::COUNTED)->sum('amount');

        return ['kind' => 'refund', 'title' => "{$res->code} · {$res->title}", 'status' => $res->status, 'client' => $res->client?->name,
            'total' => "{$res->currency} {$res->total_amount}", 'paid' => "{$res->currency} ".number_format((float) $paid, 2, '.', '')];
    }

    public function link(ApprovalRequest $r): ?string
    {
        if (! $r->subject_code) {
            return null;
        }

        return match ($r->request_type) {
            'quote' => "/cotizaciones/{$r->subject_code}",
            'cancel', 'refund' => "/reservas/{$r->subject_code}",
            'reversal' => '/finanzas/pagos',
            'payable' => "/finanzas/cuentas-por-pagar/{$r->subject_code}",
            'commission' => '/finanzas/comisiones',
            'provider_rate' => '/catalogo/proveedores',
            'credit_b2b' => '/canales/b2b/admin',
            default => null,
        };
    }

    private function requesterName(ApprovalRequest $r): string
    {
        return match ($r->requester_kind) {
            'supplier' => 'Proveedor · '.($r->origin_snapshot['provider'] ?? 'extranet'),
            'system' => 'Sistema',
            default => $r->requester?->name ?? '—',
        };
    }

    public function rule(ApprovalRule $rule): array
    {
        $users = User::permission($rule->permission)->where('status', User::ACTIVE)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->checkPermissionTo('approvals.view'))->pluck('name')->values()->all();

        return ['id' => $rule->id, 'code' => $rule->code, 'type' => $rule->request_type, 'type_label' => CollabCatalog::TYPES[$rule->request_type], 'level' => $rule->level,
            'permission' => $rule->permission, 'approvers' => $users, 'min_amount_pen' => (string) $rule->min_amount_pen, 'sla_hours' => $rule->sla_hours,
            'forbid_self_approval' => $rule->forbid_self_approval, 'is_active' => $rule->is_active, 'is_origin_locked' => $rule->is_origin_locked,
            'lock_version' => $rule->lock_version];
    }
}
