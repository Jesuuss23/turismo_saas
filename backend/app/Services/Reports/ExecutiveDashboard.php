<?php

namespace App\Services\Reports;

use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Crm\Lead;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Models\Ops\Incident;
use App\Models\Quotes\Quote;
use App\Security\Access;
use App\Services\Crm\CrmClock;
use App\Services\Finance\FxRates;
use App\Services\Quotes\QuotePolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Panel ejecutivo de Tenant Admin y Manager (spec 09 §7.2, dashboard.js l. 34–134) y bloque de gerencia (DSH-BR-01).
 * Meta mensual (S/ 300k) y de margen (28 %) fijas hasta que exista el módulo de metas comerciales (§12-7).
 */
class ExecutiveDashboard
{
    public const SALES_TARGET = 300000;

    public const MARGIN_TARGET = 28;

    private const OPEN_QUOTES = [Quote::DRAFT, Quote::SENT, Quote::VIEWED, Quote::NEGOTIATION];

    private const LIVE_RESERVATIONS = [Reservation::CONFIRMED, Reservation::PENDING_PAYMENT, Reservation::PENDING_PROVIDER, Reservation::RESCHEDULED, Reservation::IN_OPERATION, Reservation::PENDING_AVAILABILITY];

    public function __construct(private readonly CrmClock $clock, private readonly FxRates $fx) {}

    public function executive(User $user): array
    {
        $now = $this->clock->now();
        $monthStart = $now->copy()->startOfMonth();
        $month = $this->salesBetween($monthStart, $now->copy()->endOfDay());
        $openOpps = DB::table('crm_opportunities')->whereNull('deleted_at')->whereIn('stage', Opportunity::OPEN_STAGES)->get();
        $live = DB::table('booking_reservations')->whereNull('deleted_at')->whereIn('status', self::LIVE_RESERVATIONS)->pluck('id');
        $receivables = DB::table('fin_receivables')->whereNull('deleted_at')->whereIn('status', ['abierta', 'parcial', 'vencida'])->get();
        $today = $this->clock->today();
        $overdue = $receivables->filter(fn ($r) => $r->status === 'vencida' || $r->due_date < $today->toDateString());
        $critical = DB::table('ops_incidents')->where('severity', 'critica')->whereIn('status', Incident::ACTIVE)->orderBy('created_at')->first();
        $can = fn (string $route) => Access::check($user->accessSubject(), $route)->allowed;

        return [
            'kpis' => [
                ['key' => 'sales', 'label' => 'Ventas del mes', 'value' => $this->money($month['sales']), 'unit' => 'PEN', 'hint' => $month['count'].' reservas'],
                ['key' => 'margin', 'label' => 'Margen bruto', 'value' => $month['margin'], 'unit' => '%', 'hint' => 'Meta '.self::MARGIN_TARGET.' %',
                    'tone' => $month['margin'] === null ? null : ($month['margin'] >= self::MARGIN_TARGET ? 'ok' : 'warn')],
                ['key' => 'pipeline', 'label' => 'Pipeline abierto', 'value' => $this->money($openOpps->sum(fn ($o) => $this->fx->toPen($o->amount ?? 0, $o->currency ?? 'PEN'))), 'unit' => 'PEN',
                    'hint' => $openOpps->count().' oportunidades'],
                ['key' => 'reservations', 'label' => 'Reservas activas', 'value' => $live->count(), 'hint' => DB::table('booking_passengers')->whereIn('reservation_id', $live)->count().' pasajeros'],
                ['key' => 'receivables', 'label' => 'Por cobrar', 'value' => $this->money($receivables->sum(fn ($r) => $this->fx->toPen((float) $r->amount - (float) $r->paid_amount, $r->currency, $r->fx_rate ?? null))),
                    'unit' => 'PEN', 'hint' => $overdue->count().' vencidas', 'tone' => $overdue->isNotEmpty() ? 'warn' : null],
            ],
            'critical_incident' => $critical ? ['code' => $critical->code, 'title' => $critical->title, 'link' => $can('operaciones/incidencias')] : null,
            'monthly' => $this->monthly(),
            'funnel' => $this->funnel($now->copy()->subMonthsNoOverflow(5)->startOfMonth()),
            'services_7d' => $this->services($today),
            'departures' => DB::table('catalog_product_departures as d')->join('catalog_products as p', 'p.id', '=', 'd.product_id')
                ->where('d.departure_date', '>=', $today->toDateString())->where('d.departure_date', '<=', $today->copy()->addDays(30)->toDateString())
                ->whereNotIn('d.status', ['cancelada'])->orderBy('d.departure_date')->limit(6)
                ->get(['d.ref', 'd.departure_date', 'd.capacity', 'd.sold', 'd.status', 'p.name'])
                ->map(fn ($d) => ['ref' => $d->ref, 'product' => $d->name, 'date' => $d->departure_date, 'capacity' => (int) $d->capacity, 'sold' => (int) $d->sold,
                    'pct' => $d->capacity > 0 ? (int) round($d->sold * 100 / $d->capacity) : 0, 'status' => $d->status])->all(),
            'team_pending' => $this->teamPending($today),
            'quotes_followup' => DB::table('quote_quotes as q')->leftJoin('crm_clients as c', 'c.id', '=', 'q.client_id')->leftJoin('iam_users as u', 'u.id', '=', 'q.owner_id')
                ->whereNull('q.deleted_at')->whereIn('q.status', [Quote::SENT, Quote::VIEWED, Quote::NEGOTIATION])->orderBy('q.valid_until')->limit(6)
                ->get(['q.code', 'q.status', 'q.valid_until', 'q.viewed_at', 'c.name as client', 'u.name as owner'])
                ->map(fn ($q) => (array) $q + ['expires_in' => $q->valid_until ? (int) $today->diffInDays(Carbon::parse($q->valid_until), false) : null])->all(),
            'incidents' => DB::table('ops_incidents')->whereIn('status', Incident::ACTIVE)
                ->orderByRaw("case severity when 'critica' then 0 when 'alta' then 1 when 'media' then 2 else 3 end")->orderBy('created_at')->limit(5)
                ->get(['code', 'title', 'severity', 'status', 'sla_due_at'])->map(fn ($i) => (array) $i + ['severity_label' => Incident::SEVERITY_LABELS[$i->severity] ?? $i->severity])->all(),
            'leads' => $this->leads($monthStart),
            'by_channel' => $this->byChannel($now->copy()->subMonthsNoOverflow(5)->startOfMonth()),
            'targets' => ['sales' => self::SALES_TARGET, 'margin' => self::MARGIN_TARGET],
            'permissions' => [
                'reports' => $user->hasPermissionTo('reports.view'), 'new_quote' => $user->hasPermissionTo('quotes.create'),
                'approve' => $user->hasPermissionTo('quotes.approve_internal'),
                'manager' => $user->hasPermissionTo('quotes.approve_internal') && $user->roleName() !== 'tenant_admin',
                'links' => ['incidents' => $can('operaciones/incidencias'), 'calendar' => $can('operaciones/calendario'), 'profitability' => $can('finanzas/rentabilidad'),
                    'opportunities' => $can('crm/oportunidades'), 'quotes' => $can('cotizaciones'), 'reservations' => $can('reservas'), 'leads' => $can('crm/leads')],
            ],
        ];
    }

    /** Bloque de gerencia: aprobaciones, metas del mes y desempeño por asesor. */
    public function manager(User $user): array
    {
        $now = $this->clock->now();
        $today = $this->clock->today();
        $month = $this->salesBetween($now->copy()->startOfMonth(), $now->copy()->endOfDay());
        $quotes = Quote::with(['currentVersion', 'client:id,name', 'owner:id,name'])->whereIn('status', self::OPEN_QUOTES)->orderBy('code')->get();
        $outOfPolicy = $quotes->filter(fn (Quote $q) => $q->currentVersion && ! in_array(QuotePolicy::state($q->currentVersion), ['no_requerida', 'aprobada'], true));

        $overdueActivities = DB::table('crm_activities')->whereIn('status', ['pendiente', 'vencida'])->where('due_at', '<', $now)->count();

        return [
            'approvals' => [
                'quotes' => $outOfPolicy->map(fn (Quote $q) => [
                    'code' => $q->code, 'client' => $q->client?->name, 'owner' => $q->owner?->name, 'status' => $q->status, 'version' => $q->currentVersion->version_no,
                    'total' => $this->money($this->fx->toPen($q->currentVersion->grand_total ?? 0, $q->currency, $q->currentVersion->fx_rate)),
                    'discount_pct' => (float) $q->currentVersion->discount_pct, 'margin_pct' => (float) $q->currentVersion->margin_pct,
                    'reasons' => QuotePolicy::versionReasons($q->currentVersion), 'state' => QuotePolicy::state($q->currentVersion),
                    'over_cap' => QuotePolicy::overCap((string) $q->currentVersion->discount_pct), 'lock_version' => $q->lock_version,
                    'can_approve' => in_array($q->currentVersion->status, ['borrador', 'enviada'], true),
                ])->values()->all(),
                'cancellations' => DB::table('booking_cancel_requests as c')->join('booking_reservations as r', 'r.id', '=', 'c.reservation_id')
                    ->leftJoin('iam_users as u', 'u.id', '=', 'c.requested_by')->where('c.status', 'pendiente')->orderBy('c.requested_at')->limit(10)
                    ->get(['r.code', 'r.title', 'c.reason', 'c.requested_at', 'u.name as requested_by'])->map(fn ($c) => (array) $c)->all(),
                'payments' => DB::table('fin_payments as p')->leftJoin('crm_clients as c', 'c.id', '=', 'p.client_id')->where('p.status', 'pendiente')
                    ->orderBy('p.created_at')->limit(10)->get(['p.code', 'p.amount', 'p.currency', 'p.paid_on', 'p.source', 'c.name as client'])->map(fn ($p) => (array) $p)->all(),
            ],
            'goals' => [
                ['key' => 'sales', 'label' => 'Ventas del mes', 'value' => $this->money($month['sales']), 'target' => self::SALES_TARGET, 'unit' => 'PEN',
                    'pct' => (int) round($month['sales'] * 100 / self::SALES_TARGET)],
                ['key' => 'margin', 'label' => 'Margen bruto', 'value' => $month['margin'], 'target' => self::MARGIN_TARGET, 'unit' => '%',
                    'pct' => $month['margin'] === null ? 0 : (int) round($month['margin'] * 100 / self::MARGIN_TARGET)],
                ['key' => 'overdue_activities', 'label' => 'Actividades vencidas', 'value' => $overdueActivities, 'tone' => $overdueActivities > 0 ? 'warn' : 'ok'],
                ['key' => 'uncontacted', 'label' => 'Leads nuevos sin contactar', 'value' => DB::table('crm_leads')->whereNull('deleted_at')->where('status', Lead::NEW)->whereNull('first_contact_at')->count()],
                ['key' => 'unassigned', 'label' => 'Leads sin asignar', 'value' => DB::table('crm_leads')->whereNull('deleted_at')->whereNull('owner_id')->whereIn('status', [Lead::NEW, Lead::CONTACTED])->count()],
            ],
            'advisors' => $this->advisors($now, $quotes),
            'min_margin' => QuotePolicy::MARGIN_MIN,
            'max_discount' => QuotePolicy::DISCOUNT_INTERNAL,
            'today' => $today->toDateString(),
        ];
    }

    /** @return array{sales: float, cost: float, margin: ?float, count: int} */
    private function salesBetween(Carbon $from, Carbon $to): array
    {
        $res = DB::table('booking_reservations')->whereNull('deleted_at')->whereBetween('created_at', [$from, $to])->whereNotIn('status', [Reservation::CANCELLED])->get();
        $costs = DB::table('booking_services')->whereIn('reservation_id', $res->pluck('id'))->whereNotIn('status', [BookedService::CANCELLED, BookedService::REJECTED])
            ->selectRaw('reservation_id, sum(cost_amount) as cost')->groupBy('reservation_id')->pluck('cost', 'reservation_id');
        $sales = $res->sum(fn ($r) => $this->fx->toPen($r->total_amount ?? 0, $r->currency, $r->fx_rate));
        $cost = $res->sum(fn ($r) => $this->fx->toPen($costs[$r->id] ?? 0, $r->currency, $r->fx_rate));

        return ['sales' => $sales, 'cost' => $cost, 'margin' => $sales > 0 ? round(($sales - $cost) * 100 / $sales, 1) : null, 'count' => $res->count()];
    }

    private function monthly(): array
    {
        $names = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'set', 'oct', 'nov', 'dic'];
        $rows = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $this->clock->now()->startOfMonth()->subMonthsNoOverflow($i);
            $s = $this->salesBetween($m->copy(), $m->copy()->endOfMonth());
            $rows[] = ['label' => $names[$m->month - 1].' '.$m->format('y'), 'values' => [$this->money($s['sales']), $this->money($s['cost'])]];
        }

        return ['series' => ['Ventas', 'Costo'], 'rows' => $rows];
    }

    private function funnel(Carbon $from): array
    {
        $leads = DB::table('crm_leads')->whereNull('deleted_at')->where('created_at', '>=', $from);
        $quotes = DB::table('quote_quotes')->whereNull('deleted_at')->where('created_at', '>=', $from);

        return [
            ['label' => 'Leads', 'value' => (clone $leads)->count()],
            ['label' => 'Calificados', 'value' => (clone $leads)->whereIn('status', [Lead::QUALIFIED, Lead::CONVERTED])->count()],
            ['label' => 'Oportunidades', 'value' => DB::table('crm_opportunities')->whereNull('deleted_at')->where('created_at', '>=', $from)->count()],
            ['label' => 'Cotizaciones', 'value' => (clone $quotes)->count()],
            ['label' => 'Aprobadas', 'value' => (clone $quotes)->whereIn('status', [Quote::APPROVED, Quote::CONVERTED])->count()],
            ['label' => 'Reservas', 'value' => DB::table('booking_reservations')->whereNull('deleted_at')->where('created_at', '>=', $from)->whereNotIn('status', [Reservation::CANCELLED])->count()],
        ];
    }

    private function services(Carbon $today): array
    {
        $rows = DB::table('booking_services as s')->join('booking_reservations as r', 'r.id', '=', 's.reservation_id')->leftJoin('catalog_providers as p', 'p.id', '=', 's.provider_id')
            ->whereBetween('s.service_date', [$today->toDateString(), $today->copy()->addDays(6)->toDateString()])->whereNotIn('s.status', BookedService::INACTIVE)
            ->whereNull('r.deleted_at')->orderBy('s.service_date')->orderBy('s.service_time')
            ->get(['s.code', 's.name', 's.service_date', 's.service_time', 's.status', 's.pax', 'r.code as reservation', 'p.name as provider']);
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $today->copy()->addDays($i)->toDateString();
            $days[] = ['date' => $d, 'count' => $rows->where('service_date', $d)->count(), 'pending' => $rows->where('service_date', $d)->whereIn('status', [BookedService::REQUESTED, BookedService::PENDING])->count()];
        }

        return ['days' => $days, 'total' => $rows->count(), 'items' => $rows->take(8)->map(fn ($s) => (array) $s)->values()->all()];
    }

    private function teamPending(Carbon $today): array
    {
        return DB::table('crm_activities as a')->join('iam_users as u', 'u.id', '=', 'a.owner_id')->whereIn('a.status', ['pendiente', 'vencida'])
            ->where('a.due_at', '<=', $today->copy()->endOfDay())->groupBy('u.id', 'u.name')
            ->selectRaw('u.name, count(*) as total, sum(case when a.due_at < ? then 1 else 0 end) as overdue', [$today->copy()->startOfDay()])
            ->orderByDesc('total')->limit(8)->get()->map(fn ($r) => ['user' => $r->name, 'total' => (int) $r->total, 'overdue' => (int) $r->overdue])->all();
    }

    private function leads(Carbon $from): array
    {
        $month = DB::table('crm_leads')->whereNull('deleted_at')->where('created_at', '>=', $from)->get(['status', 'source_code']);
        $sources = DB::table('crm_catalog_items')->where('kind', 'lead_source')->pluck('label', 'code');

        return [
            'new' => $month->count(), 'converted' => $month->where('status', Lead::CONVERTED)->count(),
            'conversion' => $month->count() ? round($month->where('status', Lead::CONVERTED)->count() * 100 / $month->count(), 1) : null,
            'by_source' => $month->groupBy('source_code')->map(fn ($g, $code) => ['label' => $sources[$code] ?? ($code ?: 'Sin fuente'), 'value' => $g->count()])
                ->sortByDesc('value')->values()->take(6)->all(),
        ];
    }

    private function byChannel(Carbon $from): array
    {
        return DB::table('booking_reservations')->whereNull('deleted_at')->where('created_at', '>=', $from)->whereNotIn('status', [Reservation::CANCELLED])->get()
            ->groupBy(fn ($r) => $r->channel ?: 'Directo')
            ->map(fn ($g, $channel) => ['label' => $channel, 'value' => $this->money($g->sum(fn ($r) => $this->fx->toPen($r->total_amount ?? 0, $r->currency, $r->fx_rate))), 'count' => $g->count()])
            ->sortByDesc('value')->values()->all();
    }

    /** Usuarios activos Ventas/Manager: leads activos, pipeline, cotizaciones abiertas, ganado, conversión, margen medio y vencidas. */
    private function advisors(Carbon $now, Collection $openQuotes): array
    {
        $users = User::role(['sales', 'manager'])->where('status', User::ACTIVE)->orderBy('name')->get(['id', 'name']);
        $opps = DB::table('crm_opportunities')->whereNull('deleted_at')->whereIn('owner_id', $users->pluck('id'))->get();
        $leads = DB::table('crm_leads')->whereNull('deleted_at')->whereIn('owner_id', $users->pluck('id'))->whereIn('status', [Lead::NEW, Lead::CONTACTED, Lead::QUALIFIED])
            ->selectRaw('owner_id, count(*) as n')->groupBy('owner_id')->pluck('n', 'owner_id');
        $overdue = DB::table('crm_activities')->whereIn('owner_id', $users->pluck('id'))->whereIn('status', ['pendiente', 'vencida'])->where('due_at', '<', $now)
            ->selectRaw('owner_id, count(*) as n')->groupBy('owner_id')->pluck('n', 'owner_id');
        $pen = fn ($o) => $this->fx->toPen($o->amount ?? 0, $o->currency ?? 'PEN');

        return $users->map(function (User $u) use ($opps, $leads, $overdue, $openQuotes, $pen) {
            $mine = $opps->where('owner_id', $u->id);
            $won = $mine->where('stage', Opportunity::WON);
            $closed = $won->count() + $mine->where('stage', Opportunity::LOST)->count();
            $quotes = $openQuotes->where('owner_id', $u->id);
            $margins = $quotes->map(fn (Quote $q) => $q->currentVersion?->margin_pct)->filter(fn ($m) => $m !== null);
            $margin = $margins->isEmpty() ? null : round($margins->avg(), 1);

            return [
                'user' => $u->name, 'leads' => (int) ($leads[$u->id] ?? 0), 'pipeline' => $this->money($mine->whereIn('stage', Opportunity::OPEN_STAGES)->sum($pen)),
                'open_quotes' => $quotes->count(), 'won' => $this->money($won->sum($pen)), 'conversion' => $closed ? round($won->count() * 100 / $closed, 1) : null,
                'margin' => $margin, 'margin_low' => $margin !== null && $margin < QuotePolicy::MARGIN_MIN, 'overdue' => (int) ($overdue[$u->id] ?? 0),
            ];
        })->values()->all();
    }

    private function money(float|int $v): float
    {
        return round((float) $v, 2);
    }
}
