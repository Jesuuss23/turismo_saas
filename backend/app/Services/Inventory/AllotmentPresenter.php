<?php

namespace App\Services\Inventory;

use App\Models\Admin\ScheduledJob;
use App\Models\Iam\User;
use App\Models\Inventory\Allotment;
use App\Models\Inventory\AllotmentCharge;
use App\Models\Inventory\AllotmentConsumption;
use App\Models\Inventory\AllotmentDay;
use App\Models\Inventory\AllotmentEvent;
use App\Models\Inventory\AllotmentRequest;
use App\Models\Sys\AuditEvent;
use App\Services\Finance\FxRates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Lecturas de cupos (spec 11 §2): grilla, contratos, release, pick-up, detalle y celda. */
class AllotmentPresenter
{
    public const STATE_LABELS = [
        'holgado' => 'Holgado', 'medio' => '≥ 50 % usado', 'completo' => 'Completo', 'release_proximo' => 'Release próximo', 'release_vencido' => 'Release vencido',
        'retenido' => 'Retenido', 'sobreventa' => 'Sobreventa', 'liberado' => 'Liberado', 'pasada' => 'Pasada',
    ];

    public function __construct(private readonly AllotmentService $service, private readonly FxRates $fx) {}

    private function today(): Carbon
    {
        return $this->service->today();
    }

    public function canManage(User $user): bool
    {
        return $user->checkPermissionTo('catalog.allotments.manage');
    }

    /** §4.2 estado derivado de una fecha. */
    public function state(AllotmentDay $d): string
    {
        $today = $this->today();
        $toDeadline = $today->diffInDays($d->release_deadline, false);

        return match (true) {
            $d->service_date->lt($today) => 'pasada',
            $d->current() <= 0 && $d->used_qty === 0 => 'liberado',
            $d->available() < 0 => 'sobreventa',
            $d->retained_reason !== null && $toDeadline <= 0 && $d->available() > 0 => 'retenido',
            $toDeadline <= 0 && $d->available() > 0 => 'release_vencido',
            $toDeadline <= 3 && $d->available() > 0 => 'release_proximo',
            $d->available() === 0 => 'completo',
            $d->current() > 0 && $d->used_qty / $d->current() >= 0.5 => 'medio',
            default => 'holgado',
        };
    }

    public function cell(AllotmentDay $d, Allotment $a): array
    {
        $state = $this->state($d);

        return [
            'date' => $d->service_date->toDateString(), 'blocked' => $d->blocked_qty, 'released' => $d->released_qty, 'used' => $d->used_qty, 'pending' => $d->pending_qty,
            'available' => $d->available(), 'current' => $d->current(), 'deadline' => $d->release_deadline->toDateString(), 'retained' => $d->retained_reason,
            'state' => $state, 'state_label' => self::STATE_LABELS[$state], 'lock_version' => (int) $d->lock_version,
            'utilization' => $d->current() > 0 ? (int) round(100 * $d->used_qty / $d->current()) : null,
            'exposure' => in_array($state, ['retenido', 'release_vencido', 'release_proximo'], true) ? $this->service->exposure($a, $d) : 0.0,
        ];
    }

    public function row(Allotment $a, ?Collection $next30 = null): array
    {
        $next30 ??= $this->window($a, 30);
        $current = $next30->sum(fn (AllotmentDay $d) => max(0, $d->current()));
        $used = $next30->sum('used_qty');

        return [
            'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'kind' => $a->kind, 'kind_label' => Allotment::KINDS[$a->kind], 'unit' => Allotment::UNITS[$a->kind],
            'provider' => ['id' => $a->provider_id, 'name' => $a->provider?->name, 'ref' => $a->provider?->ref],
            'service' => ['id' => $a->service_id, 'name' => $a->service?->name, 'ref' => $a->service?->ref],
            'contract_ref' => $a->contract_ref, 'valid_from' => $a->valid_from->toDateString(), 'valid_to' => $a->valid_to->toDateString(),
            'base_qty' => $a->base_qty, 'release_days' => $a->release_days, 'net_rate' => $a->net_rate, 'currency' => $a->currency, 'penalty_pct' => $a->penalty_pct,
            'status' => $a->status, 'status_label' => Allotment::STATUS_LABELS[$a->status], 'owner' => $a->owner?->name, 'notes' => $a->notes,
            'released_at' => $a->released_at?->toIso8601String(), 'release_reason' => $a->release_reason, 'lock_version' => (int) $a->lock_version,
            'utilization_30' => $current > 0 ? (int) round(100 * $used / $current) : null, 'used_30' => (int) $used, 'current_30' => (int) $current,
            'penalties' => $this->penalties($a),
        ];
    }

    /** GET /catalog/allotments: contratos con utilización 30 d y KPIs del tablero. */
    public function index(array $filters): array
    {
        $rows = Allotment::with(['provider', 'service', 'owner'])
            ->when(empty($filters['all']), fn ($q) => $q->whereIn('status', Allotment::LIVE))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->orderBy('code')->get();

        return ['data' => $rows->map(fn (Allotment $a) => $this->row($a))->all(), 'meta' => ['kpis' => $this->kpis()]];
    }

    /** KPIs (§2): contratos activos, utilización 30 d, releases ≤ 3 d, exposición y penalidades devengadas (en S/). */
    public function kpis(): array
    {
        $live = Allotment::whereIn('status', Allotment::LIVE)->get();
        $days = AllotmentDay::with('allotment')->whereIn('allotment_id', $live->pluck('id'))
            ->whereBetween('service_date', [$this->today()->toDateString(), $this->today()->addDays(29)->toDateString()])->get();
        $current = $days->sum(fn ($d) => max(0, $d->current()));
        $soon = $days->filter(fn ($d) => in_array($this->state($d), ['release_proximo', 'release_vencido'], true));
        $exposure = $days->filter(fn ($d) => in_array($this->state($d), ['retenido', 'release_vencido'], true))
            ->sum(fn ($d) => $this->fx->toPen($this->service->exposure($d->allotment, $d), $d->allotment->currency));
        $accrued = AllotmentCharge::all()->sum(fn ($c) => $this->fx->toPen((float) $c->amount, $c->currency, $c->fx_rate));

        return [
            'active' => $live->count(), 'utilization_30' => $current > 0 ? (int) round(100 * $days->sum('used_qty') / $current) : 0,
            'release_soon' => $soon->count(), 'exposure_pen' => round($exposure, 2), 'penalties_pen' => round($accrued, 2),
            'overdue' => $days->filter(fn ($d) => $this->state($d) === 'release_vencido')->count(),
            'overbooked' => $days->filter(fn ($d) => $d->available() < 0)->count(),
        ];
    }

    /** GET /grid: matriz fecha × contrato (14 días por defecto) con alertas. */
    public function grid(?string $from, int $days, ?string $kind, bool $includeClosed): array
    {
        $start = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? Carbon::parse($from)->startOfDay() : $this->today();
        $days = max(1, min(42, $days));
        $end = $start->copy()->addDays($days - 1);
        $contracts = Allotment::with(['provider', 'service'])->when(! $includeClosed, fn ($q) => $q->whereIn('status', Allotment::LIVE))
            ->when($kind, fn ($q, $k) => $q->where('kind', $k))
            ->whereDate('valid_from', '<=', $end->toDateString())->whereDate('valid_to', '>=', $start->toDateString())->orderBy('kind')->orderBy('code')->get();
        $byContract = AllotmentDay::whereIn('allotment_id', $contracts->pluck('id'))->whereBetween('service_date', [$start->toDateString(), $end->toDateString()])
            ->get()->groupBy('allotment_id');
        $dates = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        $alerts = [];
        $rows = $contracts->map(function (Allotment $a) use ($byContract, $dates, &$alerts) {
            $cells = ($byContract[$a->id] ?? collect())->keyBy(fn ($d) => $d->service_date->toDateString());

            return [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'kind' => $a->kind, 'kind_label' => Allotment::KINDS[$a->kind], 'unit' => Allotment::UNITS[$a->kind],
                'provider' => $a->provider?->name, 'status' => $a->status, 'status_label' => Allotment::STATUS_LABELS[$a->status], 'currency' => $a->currency,
                'cells' => array_map(function ($date) use ($cells, $a, &$alerts) {
                    $d = $cells[$date] ?? null;
                    if (! $d) {
                        return null;
                    }
                    $cell = $this->cell($d, $a);
                    if (in_array($cell['state'], ['release_vencido', 'sobreventa'], true)) {
                        $alerts[] = ['type' => $cell['state'], 'code' => $a->code, 'name' => $a->name, 'date' => $date,
                            'text' => $cell['state'] === 'sobreventa' ? "Sobreventa: {$a->name} el ".Carbon::parse($date)->format('d/m')." ({$cell['available']})"
                                : "Release vencido sin liberar: {$a->name} el ".Carbon::parse($date)->format('d/m')." ({$cell['available']} disp.)"];
                    }

                    return $cell;
                }, $dates),
            ];
        })->values()->all();

        return ['data' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'dates' => $dates, 'rows' => $rows, 'alerts' => $alerts,
            'today' => $this->today()->toDateString()], 'meta' => ['kpis' => $this->kpis(), 'states' => self::STATE_LABELS]];
    }

    /** GET /{id}/days/{date}: detalle de la celda con las reservas que consumen. */
    public function day(Allotment $a, AllotmentDay $d): array
    {
        $consumptions = AllotmentConsumption::with(['reservation:id,code,title,status', 'service:id,code,name,status,pax'])->where('allotment_day_id', $d->id)
            ->orderByRaw("CASE state WHEN 'consumido' THEN 0 WHEN 'en_tramite' THEN 1 ELSE 2 END")->get();
        $charge = AllotmentCharge::with('payable:id,code,status')->where('allotment_day_id', $d->id)->first();

        return $this->cell($d, $a) + [
            'contract' => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'unit' => Allotment::UNITS[$a->kind], 'currency' => $a->currency,
                'net_rate' => $a->net_rate, 'penalty_pct' => $a->penalty_pct, 'status' => $a->status, 'provider' => $a->provider?->name],
            'retained_by' => $d->retainer?->name, 'retained_at' => $d->retained_at?->toIso8601String(), 'closed_at' => $d->closed_at?->toIso8601String(),
            'retain_exposure' => $this->service->exposure($a, $d),
            'consumptions' => $consumptions->map(fn (AllotmentConsumption $c) => [
                'reservation' => $c->reservation?->code, 'reservation_title' => $c->reservation?->title, 'service' => $c->service?->code, 'service_name' => $c->service?->name,
                'service_status' => $c->service?->status, 'qty' => $c->qty, 'state' => $c->state,
            ])->all(),
            'charge' => $charge ? ['qty' => $charge->qty, 'amount' => $charge->amount, 'currency' => $charge->currency, 'reason' => $charge->reason, 'payable' => $charge->payable?->code] : null,
        ];
    }

    /** GET /{id}: detalle con KPIs, calendario (28 días), consumo, ampliaciones, penalidades e historial. */
    public function detail(Allotment $a, ?string $from = null): array
    {
        $a->loadMissing(['provider', 'service', 'owner']);
        $start = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? Carbon::parse($from)->startOfDay() : $this->today()->max($a->valid_from)->copy();
        $calendar = AllotmentDay::where('allotment_id', $a->id)->whereBetween('service_date', [$start->toDateString(), $start->copy()->addDays(27)->toDateString()])->get()
            ->map(fn ($d) => $this->cell($d, $a))->values()->all();
        $next30 = $this->window($a, 30);
        $exposure = $next30->filter(fn ($d) => in_array($this->state($d), ['retenido', 'release_vencido'], true))->sum(fn ($d) => $this->service->exposure($a, $d));
        $consumptions = AllotmentConsumption::with(['reservation:id,code,title', 'service:id,code,status', 'day:id,service_date'])
            ->whereIn('allotment_day_id', AllotmentDay::where('allotment_id', $a->id)->select('id'))->orderByDesc('updated_at')->limit(200)->get();
        $charges = AllotmentCharge::with(['day:id,service_date', 'payable:id,code,status'])->whereIn('allotment_day_id', AllotmentDay::where('allotment_id', $a->id)->select('id'))->get()
            ->sortByDesc(fn ($c) => $c->day?->service_date)->values();
        $audit = AuditEvent::where('entity', 'Cupo')->where('entity_id', $a->code)->orderByDesc('occurred_at')->limit(50)->get();
        $actors = User::whereIn('id', $audit->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return $this->row($a, $next30) + [
            'kpis' => ['available_30' => (int) $next30->sum(fn ($d) => max(0, $d->available())), 'pending_30' => (int) $next30->sum('pending_qty'),
                'exposure' => round($exposure, 2), 'accrued' => round($charges->sum(fn ($c) => (float) $c->amount), 2),
                'requests_open' => AllotmentRequest::where('allotment_id', $a->id)->where('status', 'solicitada')->count()],
            'flow' => ['activo', 'en_release', 'liberado', 'vencido'],
            'calendar_from' => $start->toDateString(), 'calendar' => $calendar,
            'consumptions' => $consumptions->map(fn ($c) => ['date' => $c->day?->service_date?->toDateString(), 'reservation' => $c->reservation?->code,
                'reservation_title' => $c->reservation?->title, 'service' => $c->service?->code, 'qty' => $c->qty, 'state' => $c->state])->all(),
            'requests' => AllotmentRequest::with(['responder:id,name', 'requester:id,name'])->where('allotment_id', $a->id)->orderByDesc('created_at')->get()
                ->map(fn ($r) => $this->request($r))->all(),
            'charges' => $charges->map(fn ($c) => ['date' => $c->day?->service_date?->toDateString(), 'qty' => $c->qty, 'amount' => $c->amount, 'currency' => $c->currency,
                'reason' => $c->reason, 'payable' => $c->payable?->code, 'payable_status' => $c->payable?->status, 'system' => $c->created_by === null])->all(),
            'events' => AllotmentEvent::with('user:id,name')->where('allotment_id', $a->id)->orderByDesc('occurred_at')->limit(100)->get()
                ->map(fn ($e) => ['at' => $e->occurred_at->toIso8601String(), 'user' => $e->user?->name ?? 'Sistema', 'message' => $e->message])->all(),
            'audit' => $audit->map(fn ($e) => ['at' => $e->occurred_at?->toIso8601String(), 'action' => $e->action, 'actor' => $actors[$e->actor_id] ?? 'Sistema',
                'before' => $e->before_value, 'after' => $e->after_value])->all(),
        ];
    }

    public function request(AllotmentRequest $r): array
    {
        return ['id' => $r->id, 'code' => $r->code, 'date_from' => $r->date_from->toDateString(), 'date_to' => $r->date_to->toDateString(), 'extra_qty' => $r->extra_qty,
            'note' => $r->note, 'status' => $r->status, 'status_label' => AllotmentRequest::STATUS_LABELS[$r->status], 'response' => $r->response,
            'responded_at' => $r->responded_at?->toIso8601String(), 'responder' => $r->responder?->name, 'requester' => $r->requester?->name,
            'created_at' => $r->created_at?->toIso8601String()];
    }

    /** Pestaña Release: vencidos sin liberar, próximos (≤ 3 d), retenciones activas y el estado de JOB-10. */
    public function releaseBoard(): array
    {
        $today = $this->today();
        $days = AllotmentDay::with('allotment.provider')->whereHas('allotment', fn ($q) => $q->whereIn('status', Allotment::LIVE))
            ->whereBetween('service_date', [$today->toDateString(), $today->copy()->addDays(120)->toDateString()])
            ->whereDate('release_deadline', '<=', $today->copy()->addDays(3)->toDateString())->orderBy('release_deadline')->orderBy('service_date')->get();
        $map = fn (AllotmentDay $d) => $this->cell($d, $d->allotment) + ['code' => $d->allotment->code, 'name' => $d->allotment->name, 'provider' => $d->allotment->provider?->name,
            'currency' => $d->allotment->currency, 'unit' => Allotment::UNITS[$d->allotment->kind], 'retain_exposure' => $this->service->exposure($d->allotment, $d)];
        $job = ScheduledJob::where('code', 'JOB-10')->first();

        return [
            'overdue' => $days->filter(fn ($d) => $this->state($d) === 'release_vencido')->map($map)->values()->all(),
            'upcoming' => $days->filter(fn ($d) => $this->state($d) === 'release_proximo')->map($map)->values()->all(),
            'retained' => AllotmentDay::with(['allotment.provider', 'retainer'])->whereNotNull('retained_reason')->whereDate('service_date', '>=', $today->toDateString())
                ->whereHas('allotment', fn ($q) => $q->whereIn('status', Allotment::LIVE))->orderBy('service_date')->get()
                ->map(fn ($d) => $map($d) + ['retained_by' => $d->retainer?->name])->values()->all(),
            'job' => $job ? ['code' => $job->code, 'name' => $job->name, 'cron_label' => $job->cron_label, 'last_run_at' => $job->last_run_at?->toIso8601String(),
                'last_status' => $job->last_status, 'last_result' => $job->last_result, 'next_run_at' => $job->next_run_at?->toIso8601String(), 'paused' => (bool) $job->paused] : null,
        ];
    }

    /** GET /pickup: por contrato (próximos N días) y curva semanal vendido acumulado vs cupo vs ritmo objetivo 85 %. */
    public function pickup(int $days, int $weeks): array
    {
        $today = $this->today();
        $end = $today->copy()->addDays($days - 1);
        $contracts = Allotment::with('provider')->whereIn('status', Allotment::LIVE)->orderBy('code')->get();
        $rows = [];
        $totalCurrent = 0;
        $consumed = collect();
        foreach ($contracts as $a) {
            $window = AllotmentDay::where('allotment_id', $a->id)->whereBetween('service_date', [$today->toDateString(), $end->toDateString()])->get();
            $current = (int) $window->sum(fn ($d) => max(0, $d->current()));
            $sold = (int) $window->sum('used_qty');
            $c = AllotmentConsumption::whereIn('allotment_day_id', $window->pluck('id'))->where('state', 'consumido')->get(['qty', 'created_at']);
            $consumed = $consumed->concat($c);
            $pick7 = (int) $c->filter(fn ($x) => $x->created_at?->gte($today->copy()->subDays(7)))->sum('qty');
            $pick30 = (int) $c->filter(fn ($x) => $x->created_at?->gte($today->copy()->subDays(30)))->sum('qty');
            $util = $current > 0 ? (int) round(100 * $sold / $current) : 0;
            $soonFree = $window->filter(fn ($d) => in_array($this->state($d), ['release_proximo', 'release_vencido'], true))->sum(fn ($d) => max(0, $d->available()));
            $rows[] = ['code' => $a->code, 'name' => $a->name, 'kind_label' => Allotment::KINDS[$a->kind], 'provider' => $a->provider?->name, 'unit' => Allotment::UNITS[$a->kind],
                'current' => $current, 'sold' => $sold, 'pending' => (int) $window->sum('pending_qty'), 'utilization' => $util, 'pickup_7' => $pick7, 'pickup_30' => $pick30,
                'recommendation' => match (true) {
                    $util >= 85 => 'Solicitar ampliación: el ritmo supera el 85 % del cupo',
                    $soonFree > 0 && $util < 30 => "Liberar {$soonFree} unidad(es) antes del release para evitar penalidad",
                    $pick7 === 0 && $util < 50 => 'Ritmo bajo: revisar con Ventas o liberar parcialmente',
                    default => 'Mantener: ritmo dentro de lo esperado',
                }];
            $totalCurrent += $current;
        }
        $series = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $cut = $today->copy()->subWeeks($i)->endOfDay();
            $series[] = ['week' => $cut->copy()->startOfWeek()->toDateString(), 'label' => 'Sem '.$cut->copy()->startOfWeek()->format('d/m'),
                'sold' => (int) $consumed->filter(fn ($x) => $x->created_at?->lte($cut))->sum('qty'), 'cupo' => $totalCurrent,
                'target' => (int) round($totalCurrent * 0.85 * ($weeks - $i) / $weeks)];
        }

        return ['rows' => $rows, 'series' => $series, 'days' => $days, 'weeks' => $weeks];
    }

    /** Días de los próximos N días del contrato. */
    public function window(Allotment $a, int $days): Collection
    {
        return AllotmentDay::where('allotment_id', $a->id)
            ->whereBetween('service_date', [$this->today()->toDateString(), $this->today()->addDays($days - 1)->toDateString()])->get();
    }

    /** Penalidades devengadas del contrato (en su moneda). */
    public function penalties(Allotment $a): string
    {
        return number_format((float) AllotmentCharge::whereIn('allotment_day_id', AllotmentDay::where('allotment_id', $a->id)->select('id'))->sum('amount'), 2, '.', '');
    }
}
