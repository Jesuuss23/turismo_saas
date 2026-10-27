<?php

namespace App\Http\Controllers\Api\Collab;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Bookings\Reservation;
use App\Models\Channel\B2bAgency;
use App\Models\Collab\ApprovalRequest;
use App\Models\Finance\Payment;
use App\Models\Iam\User;
use App\Models\Sys\AuditEvent;
use App\Services\Bookings\CancellationService;
use App\Services\Collab\ApprovalDecisions;
use App\Services\Collab\ApprovalPresenter;
use App\Services\Collab\ApprovalRouter;
use App\Services\Collab\ApprovalSla;
use App\Services\Collab\CollabCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Spec 10 §6: bandeja de aprobaciones unificada. El alcance por rol se aplica en el servidor (§7). */
class ApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalRouter $router,
        private readonly ApprovalPresenter $present,
        private readonly ApprovalDecisions $decisions,
        private readonly ApprovalSla $sla,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $box = $request->input('filter.box', 'all');
        $rows = $this->visible($user)
            ->when($box === 'mine', fn ($q) => $q->where('status', 'pendiente'))
            ->when($box === 'sent', fn ($q) => $q->where('requester_id', $user->id))
            ->when($box === 'history', fn ($q) => $q->whereIn('status', ['aprobada', 'rechazada', 'anulada']))
            ->when($request->input('filter.type'), fn ($q, $t) => $q->where('request_type', $t))
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->with(['requester:id,name', 'delegate:id,name', 'decider:id,name', 'rule'])
            ->orderByRaw($box === 'history' ? 'decided_at DESC NULLS LAST' : "CASE WHEN status IN ('pendiente','info') THEN 0 ELSE 1 END, due_at ASC NULLS LAST, created_at DESC")
            ->limit(400)->get();
        if ($box === 'mine') {
            $rows = $rows->filter(fn (ApprovalRequest $r) => $this->router->blocker($user, $r) === null);
        }
        if ($sla = $request->input('filter.sla')) {
            $rows = $rows->filter(fn (ApprovalRequest $r) => $this->sla->state($r) === $sla);
        }

        return response()->json(['data' => $rows->values()->map(fn (ApprovalRequest $r) => $this->present->row($r, $user))->all(), 'meta' => [
            'box' => $box, 'counters' => $this->countersFor($user), 'types' => CollabCatalog::TYPES, 'statuses' => CollabCatalog::STATUS_LABELS,
            'scope' => $this->router->seesAll($user) ? 'Todas las solicitudes del tenant' : 'Sus solicitudes, las delegadas y los tipos que puede aprobar',
            'can_manage_rules' => $user->checkPermissionTo('admin.settings.manage'),
            'decisions' => $box === 'history' ? $this->recentDecisions($user) : [],
        ]]);
    }

    public function counters(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->countersFor($request->user())]);
    }

    public function meta(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'types' => CollabCatalog::TYPES, 'generic' => array_intersect_key(CollabCatalog::TYPES, array_flip(CollabCatalog::GENERIC)),
            'agencies' => B2bAgency::orderBy('name')->get(['code', 'name', 'credit_currency', 'credit_limit', 'credit_used', 'status'])
                ->map(fn (B2bAgency $a) => ['code' => $a->code, 'name' => $a->name, 'currency' => $a->credit_currency, 'status' => $a->effectiveStatus(), 'available' => $a->available()])->all(),
            'reservations' => Reservation::whereNotIn('status', [Reservation::DRAFT])->orderByDesc('created_at')->limit(80)->get(['code', 'title', 'currency'])
                ->map(fn (Reservation $r) => ['code' => $r->code, 'title' => $r->title, 'currency' => $r->currency])->all(),
        ]]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->present->detail($this->find($request, $code), $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $r = $this->decisions->createGeneric($request->all(), $request->user());

        return response()->json(['data' => $this->present->detail($r, $request->user())], 201);
    }

    public function approve(Request $request, string $code): JsonResponse
    {
        $r = $this->decisions->approve($this->find($request, $code), $request->input('note'), $request->user(), $request);

        return response()->json(['data' => $this->present->detail($r, $request->user())]);
    }

    public function reject(Request $request, string $code): JsonResponse
    {
        $r = $this->decisions->reject($this->find($request, $code), $request->input('note'), $request->user(), $request);

        return response()->json(['data' => $this->present->detail($r, $request->user())]);
    }

    public function askInfo(Request $request, string $code): JsonResponse
    {
        $r = $this->decisions->askInfo($this->find($request, $code), $request->input('question'), $request->user(), $request);

        return response()->json(['data' => $this->present->detail($r, $request->user())]);
    }

    public function answer(Request $request, string $code, string $info): JsonResponse
    {
        $r = $this->decisions->answer($this->find($request, $code), $info, $request->input('answer'), $request->user());

        return response()->json(['data' => $this->present->detail($r, $request->user())]);
    }

    public function delegate(Request $request, string $code): JsonResponse
    {
        $r = $this->decisions->delegate($this->find($request, $code), $request->input('user_id'), $request->user(), $request);

        return response()->json(['data' => $this->present->detail($r, $request->user())]);
    }

    public function withdraw(Request $request, string $code): JsonResponse
    {
        $r = $this->decisions->withdraw($this->find($request, $code), $request->user(), $request);

        return response()->json(['data' => $this->present->detail($r, $request->user())]);
    }

    public function resubmit(Request $request, string $code): JsonResponse
    {
        $r = $this->decisions->resubmit($this->find($request, $code), $request->input('note'), $request->user());

        return response()->json(['data' => $this->present->detail($r, $request->user())], 201);
    }

    /** Misma función de penalidad que PROC-028. */
    public function cancelPreview(Request $request, CancellationService $cancellations, string $reservation): JsonResponse
    {
        $user = $request->user();
        $r = Reservation::where('code', $reservation)->first() ?? throw ApiException::notFound();
        $allowed = $user->checkPermissionTo('reservations.cancel') || $this->router->activeRules('cancel')->contains(fn ($rule) => $user->checkPermissionTo($rule->permission));
        if (! $allowed) {
            throw ApiException::forbidden('FORBIDDEN', 'Requiere reservations.cancel o un nivel de la regla de anulación', ['reservations.cancel']);
        }

        return response()->json(['data' => $cancellations->preview($r)]);
    }

    /** PROC-043: Finanzas solicita la reversión; la decide la bandeja (o `POST /revert` directo con el permiso). */
    public function requestReversal(Request $request, string $code): JsonResponse
    {
        $payment = Payment::where('code', $code)->first() ?? throw ApiException::notFound();
        $r = $this->decisions->requestReversal($payment, $request->input('reason'), $request->user());

        return response()->json(['data' => $this->present->detail($r, $request->user())], 201);
    }

    private function visible(User $user)
    {
        return $this->router->scopeVisible(ApprovalRequest::query(), $user);
    }

    private function find(Request $request, string $code): ApprovalRequest
    {
        $r = ApprovalRequest::where('code', $code)->first() ?? throw ApiException::notFound();
        if (! $this->router->visible($request->user(), $r)) {
            throw ApiException::forbidden('APR-SCOPE', 'La solicitud no es visible para su rol', ['approvals.view']);
        }

        return $r;
    }

    /** KPIs (§2): pendientes para mí, SLA vencido, enviadas por mí en curso, resueltas 7 días con tiempo medio. */
    private function countersFor(User $user): array
    {
        /** @var Collection<int, ApprovalRequest> $live */
        $live = $this->visible($user)->whereIn('status', ApprovalRequest::LIVE)->get();
        $resolved = $this->visible($user)->whereIn('status', ['aprobada', 'rechazada'])->where('decided_at', '>=', now()->subDays(7))->get(['created_at', 'decided_at']);
        $hours = $resolved->avg(fn (ApprovalRequest $r) => $r->created_at->diffInMinutes($r->decided_at, true) / 60);

        return [
            'mine' => $live->filter(fn (ApprovalRequest $r) => $r->status === 'pendiente' && $this->router->blocker($user, $r) === null)->count(),
            'overdue' => $live->filter(fn (ApprovalRequest $r) => $this->sla->state($r) === 'overdue')->count(),
            'sent_open' => $live->where('requester_id', $user->id)->count(),
            'resolved_7d' => $resolved->count(), 'avg_hours' => $hours !== null ? round($hours, 1) : null,
        ];
    }

    private function recentDecisions(User $user): array
    {
        $codes = $this->visible($user)->whereNotNull('decided_at')->orderByDesc('decided_at')->limit(50)->pluck('code')->all();
        $rows = AuditEvent::where('entity', 'Aprobación')->whereIn('entity_id', $codes)->whereIn('action', ['aprobar', 'rechazar', 'retirar', 'cerrar_en_origen'])
            ->orderByDesc('occurred_at')->limit(15)->get();
        $names = User::whereIn('id', $rows->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn (AuditEvent $e) => ['code' => $e->entity_id, 'action' => $e->action, 'after' => $e->after_value,
            'actor' => $e->actor_id ? ($names[$e->actor_id] ?? '—') : 'Sistema', 'at' => $e->occurred_at])->all();
    }
}
