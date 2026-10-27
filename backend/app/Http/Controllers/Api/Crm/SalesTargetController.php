<?php

namespace App\Http\Controllers\Api\Crm;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Crm\SalesTarget;
use App\Models\Crm\SalesTargetPolicy;
use App\Models\Crm\SalesTeam;
use App\Models\Crm\SalesTeamMember;
use App\Models\Iam\Sede;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Crm\Targets\SalesTargetService;
use App\Services\Crm\Targets\TargetProgress;
use App\Services\SequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec 14 §6: metas comerciales. Lectura con `crm.targets.view | manage`; Ventas solo ve sus metas no borrador (MET-BR-07, `owner=me`
 * forzado en servidor) y el ranking sin montos ajenos.
 */
class SalesTargetController extends Controller
{
    public function __construct(
        private readonly SalesTargetService $targets,
        private readonly TargetProgress $progress,
        private readonly CrmClock $clock,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $manage = $user->hasPermissionTo('crm.targets.manage');
        $filters = (array) $request->input('filter', []);
        $period = (string) ($filters['period'] ?? $this->clock->today()->format('Y-m'));
        $q = SalesTarget::query()->orderBy('scope_type')->orderBy('code');
        if (! $manage || ($filters['owner'] ?? null) === 'me') {
            $q->where('scope_type', 'asesor')->where('scope_id', $user->id);
        }
        if (! $manage) {
            $q->whereIn('status', ['vigente', 'cerrada']);
        }
        $all = (clone $q)->get();
        $rows = $all->filter(fn ($t) => $period === 'all' || $t->period_key === $period || ($t->period_type === 'trimestre' && $this->inQuarter($period, $t->period_key)));
        $rows = $rows->when($filters['scope_type'] ?? null, fn ($c, $s) => $c->where('scope_type', $s))
            ->when($manage && ($filters['advisor'] ?? null), fn ($c, $a) => $c->filter(fn ($t) => $t->scope_type === 'asesor' && $t->scope_id === $this->userId($a)));
        $data = $rows->map(fn ($t) => $this->row($t, $user))->values();
        $individual = $data->where('scope_type', 'asesor')->where('status', '<>', 'anulada');

        return response()->json(['data' => $data, 'meta' => [
            'period' => $period, 'manage' => $manage, 'admin' => $user->roleName() === 'tenant_admin',
            'kpis' => ['goal' => round($individual->sum(fn ($r) => $r['goals']['sales'] ?? 0), 2), 'actual' => round($individual->sum('actual.sales'), 2),
                'projection' => round($individual->sum('projection.sales'), 2), 'at_risk' => $individual->whereIn('health', ['en_riesgo', 'critico'])->count(),
                'bonus' => round($individual->sum('bonus.amount'), 2)],
            'periods' => $this->periods($all),
            'history' => $manage ? SalesTarget::whereIn('status', ['cerrada', 'vigente'])->whereDate('period_to', '<', $this->clock->today()->toDateString())
                ->orderByDesc('period_to')->orderBy('code')->limit(30)->get()->map(fn ($t) => $this->row($t, $user))->values() : null,
            'own_history' => ! $manage ? $all->sortByDesc('period_from')->map(fn ($t) => $this->row($t, $user))->values() : null,
            'options' => $manage ? ['advisors' => $this->targets->advisors()->map(fn ($u) => ['id' => $u->id, 'code' => $u->code, 'name' => $u->name])->values(),
                'sedes' => Sede::orderBy('name')->get(['id', 'code', 'name']), 'teams' => SalesTeam::orderBy('code')->get(['id', 'code', 'name'])] : null,
            'policy' => SalesTargetPolicy::first()?->only(['tiers', 'lock_decrease_after_pct', 'attribution_window_days']),
        ]]);
    }

    public function show(Request $request, string $target): JsonResponse
    {
        $t = $this->find($target);
        $user = $request->user();
        $this->assertVisible($t, $user);
        $p = $this->progress->progress($t, true);
        $commission = $t->commission;

        return response()->json(['data' => $this->row($t, $user, $p) + [
            'reservations' => $p['reservations'], 'pipeline' => $p['pipeline'],
            'adjustments' => $t->adjustments()->with('actor')->get()->map(fn ($a) => ['action' => $a->action, 'before' => $a->before, 'after' => $a->after, 'reason' => $a->reason,
                'actor' => $a->actor?->name, 'at' => $a->occurred_at->toIso8601String()]),
            'commission' => $commission ? ['code' => $commission->code, 'amount' => (string) $commission->amount, 'status' => $commission->status,
                'can_view' => $user->hasPermissionTo('finance.commissions')] : null,
            'ranking' => $t->scope_type === 'asesor' ? $this->targets->ranking($t->period_key, $user) : [],
            'result' => $t->result,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $t = $this->targets->create($request->all(), $request->user());

        return response()->json(['data' => ['id' => $t->id, 'code' => $t->code], 'message' => "Meta {$t->code} creada (".SalesTarget::STATUS_LABELS[$t->status].')'], 201);
    }

    public function activate(Request $request, string $target): JsonResponse
    {
        $t = $this->targets->activate($this->find($target)->assertIfMatch($request), $request->user());

        return response()->json(['data' => ['code' => $t->code, 'status' => $t->status, 'lock_version' => $t->lock_version], 'message' => "Meta {$t->code} vigente"]);
    }

    public function adjust(Request $request, string $target): JsonResponse
    {
        $t = $this->targets->adjust($this->find($target)->assertIfMatch($request), $request->all(), $request->user());

        return response()->json(['data' => ['code' => $t->code, 'lock_version' => $t->lock_version], 'message' => "Meta {$t->code} ajustada"]);
    }

    public function void(Request $request, string $target): JsonResponse
    {
        $t = $this->targets->void($this->find($target)->assertIfMatch($request), (string) $request->input('reason'), $request->user());

        return response()->json(['data' => ['code' => $t->code, 'status' => $t->status], 'message' => "Meta {$t->code} anulada"]);
    }

    public function close(Request $request, string $target): JsonResponse
    {
        $t = $this->find($target);
        if ($request->hasHeader('If-Match')) {
            $t->assertIfMatch($request);
        }
        $t = $this->targets->close($t, $request->user());
        $c = $t->commission;

        return response()->json(['data' => ['code' => $t->code, 'status' => $t->status, 'result' => $t->result, 'commission' => $c ? ['code' => $c->code, 'amount' => (string) $c->amount] : null],
            'message' => "Meta {$t->code} cerrada · ".number_format($t->result['pct'], 1, ',', '.').' %'.($c ? " · comisión {$c->code} PEN {$c->amount}" : '')]);
    }

    public function ranking(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->targets->ranking((string) $request->input('period', $this->clock->today()->format('Y-m')), $request->user())]);
    }

    public function teams(): JsonResponse
    {
        return response()->json(['data' => SalesTeam::with(['leader', 'members.user'])->orderBy('code')->get()->map(fn ($t) => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name,
            'leader' => $t->leader?->name, 'members' => $t->members->map(fn ($m) => ['user' => $m->user?->name, 'user_id' => $m->user_id, 'valid_from' => $m->valid_from->toDateString(),
                'valid_to' => $m->valid_to?->toDateString()])])]);
    }

    /** PUT /sales-teams: crea o actualiza un equipo (nombre, líder e integrantes vigentes desde hoy). */
    public function saveTeam(Request $request, SequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $name = trim((string) $request->input('name'));
        $leader = User::where('id', $request->input('leader_id'))->orWhere('code', $request->input('leader_id'))->first();
        if ($name === '' || ! $leader) {
            throw ApiException::invalid($name === '' ? 'name' : 'leader_id', 'Indique el nombre y el líder del equipo.');
        }
        $members = collect((array) $request->input('members', []))->map(fn ($ref) => $this->userId((string) $ref))->filter()->unique();
        $team = DB::transaction(function () use ($request, $sequences, $name, $leader, $members) {
            $team = $request->filled('code') ? SalesTeam::where('code', $request->input('code'))->first() ?? throw ApiException::notFound() : null;
            $team ??= SalesTeam::create(['code' => $sequences->next('sales_team'), 'name' => $name, 'leader_id' => $leader->id]);
            $team->forceFill(['name' => $name, 'leader_id' => $leader->id])->save();
            $today = $this->clock->today()->toDateString();
            $current = SalesTeamMember::where('team_id', $team->id)->whereNull('valid_to')->get();
            foreach ($current->whereNotIn('user_id', $members) as $m) {
                $m->forceFill(['valid_to' => $today])->save();
            }
            foreach ($members->diff($current->pluck('user_id')) as $userId) {
                SalesTeamMember::firstOrCreate(['team_id' => $team->id, 'user_id' => $userId, 'valid_from' => $today]);
            }

            return $team;
        });
        $audit->log('Equipo de ventas', $team->code, 'guardar', null, "{$team->name} · {$members->count()} integrante(s)");

        return response()->json(['data' => ['code' => $team->code], 'message' => "Equipo {$team->code} guardado"]);
    }

    public function policy(): JsonResponse
    {
        return response()->json(['data' => SalesTargetPolicy::firstOrFail()]);
    }

    /** PUT /sales-target-policy (`admin.settings.manage`): tramos crecientes y bloqueo de reducción. */
    public function savePolicy(Request $request, AuditLogger $audit): JsonResponse
    {
        $policy = SalesTargetPolicy::firstOrFail()->assertIfMatch($request);
        $tiers = collect((array) $request->input('tiers', $policy->tiers))->map(fn ($t) => ['min' => (float) ($t['min'] ?? 0), 'pay' => (float) ($t['pay'] ?? 0)])->sortBy('min')->values();
        if ($tiers->isEmpty() || $tiers->contains(fn ($t) => $t['min'] <= 0 || $t['pay'] <= 0) || $tiers->pluck('min')->unique()->count() !== $tiers->count()) {
            throw ApiException::invalid('tiers', 'Cada tramo necesita un % mínimo y un % de pago mayores que 0, sin repetir.');
        }
        $lock = (int) $request->input('lock_decrease_after_pct', $policy->lock_decrease_after_pct);
        $window = (int) $request->input('attribution_window_days', $policy->attribution_window_days);
        if ($lock < 1 || $lock > 100 || $window < 1 || $window > 120) {
            throw ApiException::invalid('lock_decrease_after_pct', 'El bloqueo va de 1 a 100 % y la ventana de atribución de 1 a 120 días.');
        }
        $before = json_encode($policy->tiers);
        $policy->forceFill(['tiers' => $tiers->all(), 'lock_decrease_after_pct' => $lock, 'attribution_window_days' => $window])->save();
        $audit->log('Política de metas', 'policy', 'editar', $before, json_encode($policy->tiers));

        return response()->json(['data' => $policy, 'message' => 'Política de bonos guardada']);
    }

    /** Fila de listado con avance; para Ventas las metas ajenas nunca llegan aquí (MET-BR-07). */
    private function row(SalesTarget $t, User $viewer, ?array $p = null): array
    {
        $p ??= $this->progress->progress($t);

        return ['id' => $t->id, 'code' => $t->code, 'scope_type' => $t->scope_type, 'scope_label' => SalesTarget::SCOPE_LABELS[$t->scope_type], 'scope_id' => $t->scope_id,
            'scope_name' => $this->targets->scopeName($t), 'period_type' => $t->period_type, 'period_key' => $t->period_key, 'period_from' => $t->period_from->toDateString(),
            'period_to' => $t->period_to->toDateString(), 'status' => $t->status, 'status_label' => SalesTarget::STATUS_LABELS[$t->status],
            'goals' => $t->goals(), 'own' => $t->scope_type === 'asesor' && $t->scope_id === $viewer->id, 'lock_version' => $t->lock_version] + $p;
    }

    private function assertVisible(SalesTarget $t, User $user): void
    {
        if ($user->hasPermissionTo('crm.targets.manage')) {
            return;
        }
        if ($t->scope_type !== 'asesor' || $t->scope_id !== $user->id || ! in_array($t->status, ['vigente', 'cerrada'], true)) {
            throw ApiException::forbidden('MET-BR-07', 'Registro fuera de su cartera: solo puede ver sus propias metas.');
        }
    }

    private function periods($all): array
    {
        $today = $this->clock->today();
        $keys = collect(range(-3, 2))->map(fn ($i) => $today->copy()->startOfMonth()->addMonths($i)->format('Y-m'))
            ->merge(collect(range(-1, 1))->map(fn ($i) => ($d = $today->copy()->startOfQuarter()->addMonths(3 * $i))->year.'-Q'.$d->quarter))
            ->merge($all->pluck('period_key'))->unique()->sort()->values();

        return $keys->all();
    }

    private function inQuarter(string $month, string $quarter): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
            return false;
        }

        return $quarter === $m[1].'-Q'.(intdiv((int) $m[2] - 1, 3) + 1);
    }

    private function userId(string $ref): ?string
    {
        return User::where(fn ($q) => Str::isUuid($ref) ? $q->whereKey($ref) : $q->where('code', $ref))->value('id');
    }

    private function find(string $ref): SalesTarget
    {
        return SalesTarget::where(fn ($q) => Str::isUuid($ref) ? $q->whereKey($ref) : $q->where('code', $ref))->first() ?? throw ApiException::notFound();
    }
}
