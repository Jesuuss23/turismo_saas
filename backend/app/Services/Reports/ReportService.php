<?php

namespace App\Services\Reports;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Security\Access;
use App\Services\Admin\TenantSettings;
use App\Services\Crm\CrmClock;
use App\Services\Finance\FxRates;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Centro de reportes (PROC-060, spec 09 §7.3–7.4). Consultas en vivo sobre las tablas de negocio con RLS (los read models
 * materializados de §3.17 quedan como optimización): montos en PEN al TC de cada documento (REP-BR-06), pestañas y datos
 * sensibles por rol (REP-BR-01…04) y cartera propia para Ventas (REP-BR-02).
 */
class ReportService
{
    public const REPORTS = [
        'comercial' => 'Comercial / CRM', 'cotizaciones' => 'Cotizaciones', 'reservas' => 'Reservas', 'operaciones' => 'Operaciones',
        'finanzas' => 'Finanzas y rentabilidad', 'proveedores' => 'Proveedores', 'clientes' => 'Clientes',
    ];

    /** §7.3: pestañas de los roles base; los personalizados se derivan de sus permisos. */
    public const ROLE_TABS = [
        'tenant_admin' => ['comercial', 'cotizaciones', 'reservas', 'operaciones', 'finanzas', 'proveedores', 'clientes'],
        'manager' => ['comercial', 'cotizaciones', 'reservas', 'operaciones', 'finanzas', 'proveedores', 'clientes'],
        'sales' => ['comercial', 'cotizaciones'],
        'operations' => ['reservas', 'operaciones', 'proveedores'],
        'finance' => ['finanzas'],
        'accounting' => ['finanzas'],
    ];

    /** Permisos que habilitan cada pestaña para roles personalizados (cualquiera de). */
    public const TAB_PERMISSIONS = [
        'comercial' => ['crm.leads.view'], 'cotizaciones' => ['quotes.view'], 'reservas' => ['reservations.view'], 'operaciones' => ['operations.view'],
        'finanzas' => ['finance.receivables', 'finance.profitability'], 'proveedores' => ['catalog.providers.manage', 'finance.payables'], 'clientes' => ['crm.clients.manage'],
    ];

    public const PERIODS = ['mes' => 'Mes actual', 'trimestre' => 'Últimos 3 meses', '6m' => 'Últimos 6 meses', 'all' => 'Todo'];

    private const CANCELLED = ['cancelada', 'anulada'];

    private User $user;

    private Carbon $from;

    private Carbon $to;

    private ?string $sede = null;

    public function __construct(
        private readonly CrmClock $clock,
        private readonly FxRates $fx,
        private readonly TenantSettings $settings,
    ) {}

    /** @return list<string> */
    public function tabsFor(User $user): array
    {
        $role = (string) $user->roleName();
        if (isset(self::ROLE_TABS[$role])) {
            return self::ROLE_TABS[$role];
        }

        return array_values(array_filter(array_keys(self::REPORTS), fn ($tab) => collect(self::TAB_PERMISSIONS[$tab])->contains(fn ($p) => $user->hasPermissionTo($p))));
    }

    public function build(string $report, User $user, array $filters): array
    {
        if (! isset(self::REPORTS[$report])) {
            throw ApiException::notFound();
        }
        $tabs = $this->tabsFor($user);
        if (! in_array($report, $tabs, true)) {
            throw ApiException::forbidden('REP-BR-01', 'El reporte solicitado no está disponible para su rol', self::TAB_PERMISSIONS[$report]);
        }
        $this->user = $user;
        $period = isset(self::PERIODS[$filters['period'] ?? '']) ? $filters['period'] : '6m';
        [$this->from, $this->to] = $this->range($period);
        $this->sede = $filters['sede'] ?? null ?: null;

        $body = $this->{$report}();

        return $body + ['meta' => [
            'report' => $report, 'title' => self::REPORTS[$report], 'tabs' => array_map(fn ($t) => ['key' => $t, 'label' => self::REPORTS[$t]], $tabs),
            'period' => $period, 'periods' => self::PERIODS, 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString(),
            'sede' => $this->sede, 'sedes' => DB::table('iam_sedes')->whereNull('deleted_at')->orderBy('code')->get(['id', 'name'])->all(),
            'own_only' => $this->ownOnly(), 'can_export' => $user->hasPermissionTo('reports.export'),
            'links' => ['quote' => $this->can('cotizaciones'), 'client' => $this->can('crm/clientes'), 'provider' => $this->can('catalogo/proveedores'), 'reservation' => $this->can('reservas')],
            'name' => self::REPORTS[$report].' · '.self::PERIODS[$period].($this->sede ? ' · '.DB::table('iam_sedes')->where('id', $this->sede)->value('name') : ''),
        ]];
    }

    // ───────────────────────────── Reportes ─────────────────────────────

    private function comercial(): array
    {
        $leads = $this->owned(DB::table('crm_leads')->whereNull('deleted_at'))->whereBetween('created_at', [$this->from, $this->to])->get();
        $opps = $this->owned(DB::table('crm_opportunities')->whereNull('deleted_at'))->get();
        $quotes = $this->owned(DB::table('quote_quotes')->whereNull('deleted_at'))->whereBetween('created_at', [$this->from, $this->to])->get();
        $res = $this->reservations()->get();
        $open = $opps->whereIn('stage', ['calificacion', 'descubrimiento', 'cotizacion', 'negociacion']);
        $won = $opps->where('stage', 'ganada')->filter(fn ($o) => $o->closed_at && $o->closed_at >= $this->from->toDateString());
        $lost = $opps->where('stage', 'perdida')->filter(fn ($o) => $o->closed_at && $o->closed_at >= $this->from->toDateString());
        $pen = fn ($o) => $this->fx->toPen($o->amount ?? 0, $o->currency ?? 'PEN');
        $sales = $res->reject(fn ($r) => in_array($r->status, self::CANCELLED, true));
        $users = $this->users();
        $bySeller = $users->map(function ($u) use ($opps, $open, $won, $lost, $quotes, $res) {
            $mine = fn ($c) => $c->where('owner_id', $u->id);
            $w = $mine($won);
            $closed = $w->count() + $mine($lost)->count();
            $ventas = $mine($res)->reject(fn ($r) => in_array($r->status, self::CANCELLED, true))->sum(fn ($r) => $this->pen($r->total_amount, $r->currency, $r->fx_rate));

            return ['seller' => $u->name, 'opportunities' => $mine($opps)->count(), 'pipeline' => $this->money($mine($open)->sum(fn ($o) => $this->fx->toPen($o->amount ?? 0, $o->currency))),
                'won' => $this->money($w->sum(fn ($o) => $this->fx->toPen($o->amount ?? 0, $o->currency))), 'win_rate' => $closed ? round($w->count() * 100 / $closed, 1) : null,
                'quotes' => $mine($quotes)->count(), 'approved' => $mine($quotes)->whereIn('status', ['aprobada', 'convertida'])->count(),
                'reservations' => $mine($res)->count(), 'sales' => $this->money($ventas)];
        })->filter(fn ($r) => $r['opportunities'] + $r['quotes'] + $r['reservations'] > 0)->values();
        $sources = DB::table('crm_catalog_items')->where('kind', 'lead_source')->pluck('label', 'code');

        return [
            'kpis' => [
                ['key' => 'leads', 'label' => $this->ownOnly() ? 'Mis leads' : 'Leads recibidos', 'value' => $leads->count()],
                ['key' => 'conversion', 'label' => 'Conversión lead → reserva', 'value' => $this->pct($leads->where('status', 'convertido')->count(), $leads->count()), 'unit' => '%'],
                ['key' => 'pipeline', 'label' => 'Pipeline abierto', 'value' => $this->money($open->sum($pen)), 'unit' => 'PEN'],
                ['key' => 'win_rate', 'label' => 'Win rate', 'value' => $this->pct($won->count(), $won->count() + $lost->count()), 'unit' => '%'],
                ['key' => 'ticket', 'label' => 'Ticket promedio ganado', 'value' => $this->money($won->count() ? $won->sum($pen) / $won->count() : 0), 'unit' => 'PEN'],
                ['key' => 'sales', 'label' => $this->ownOnly() ? 'Mis ventas' : 'Ventas', 'value' => $this->money($sales->sum(fn ($r) => $this->pen($r->total_amount, $r->currency, $r->fx_rate))), 'unit' => 'PEN'],
            ],
            'charts' => [
                'monthly' => ['title' => 'Leads, cotizaciones y reservas por mes', 'type' => 'series', 'series' => ['Leads', 'Cotizaciones', 'Reservas'],
                    'rows' => $this->months()->map(fn ($m) => ['label' => $m['label'], 'values' => [
                        $leads->filter(fn ($l) => str_starts_with((string) $l->created_at, $m['key']))->count(),
                        $quotes->filter(fn ($q) => str_starts_with((string) $q->created_at, $m['key']))->count(),
                        $res->filter(fn ($r) => str_starts_with((string) $r->created_at, $m['key']))->count(),
                    ]])->all()],
                'funnel' => ['title' => 'Embudo comercial', 'type' => 'funnel', 'rows' => [
                    ['label' => 'Leads', 'value' => $leads->count()],
                    ['label' => 'Calificados / convertidos', 'value' => $leads->whereIn('status', ['calificado', 'convertido'])->count()],
                    ['label' => 'Oportunidades', 'value' => $opps->filter(fn ($o) => $o->created_at >= $this->from)->count()],
                    ['label' => 'Cotizaciones', 'value' => $quotes->count()],
                    ['label' => 'Aprobadas', 'value' => $quotes->whereIn('status', ['aprobada', 'convertida'])->count()],
                    ['label' => 'Reservas', 'value' => $res->count()],
                ]],
                'sources' => ['title' => 'Conversión por fuente', 'type' => 'bars', 'rows' => $leads->groupBy('source_code')->map(fn ($g, $code) => [
                    'label' => $sources[$code] ?? $code, 'value' => $g->count(), 'hint' => $this->pct($g->where('status', 'convertido')->count(), $g->count()).' % convertidos',
                ])->sortByDesc('value')->values()->all()],
                'sellers' => ['title' => 'Ventas por vendedor', 'type' => 'bars', 'unit' => 'PEN', 'rows' => $bySeller->map(fn ($r) => ['label' => $r['seller'], 'value' => $r['sales']])->sortByDesc('value')->values()->all()],
            ],
            'table' => [
                'columns' => [['seller', 'Vendedor'], ['opportunities', 'Oport.'], ['pipeline', 'Pipeline', 'PEN'], ['won', 'Ganado', 'PEN'], ['win_rate', 'Win rate', '%'],
                    ['quotes', 'Cotiz.'], ['approved', 'Aprob.'], ['reservations', 'Reservas'], ['sales', 'Ventas', 'PEN']],
                'rows' => $bySeller->all(),
                'totals' => ['seller' => 'Total', 'opportunities' => $bySeller->sum('opportunities'), 'pipeline' => $this->money($bySeller->sum('pipeline')),
                    'won' => $this->money($bySeller->sum('won')), 'win_rate' => null, 'quotes' => $bySeller->sum('quotes'), 'approved' => $bySeller->sum('approved'),
                    'reservations' => $bySeller->sum('reservations'), 'sales' => $this->money($bySeller->sum('sales'))],
            ],
        ];
    }

    private function cotizaciones(): array
    {
        $rows = $this->owned(DB::table('quote_quotes as q'), 'q.owner_id')->whereNull('q.deleted_at')->whereBetween('q.created_at', [$this->from, $this->to])
            ->leftJoin('quote_versions as v', 'v.id', '=', 'q.current_version_id')->leftJoin('crm_clients as c', 'c.id', '=', 'q.client_id')
            ->leftJoin('iam_users as u', 'u.id', '=', 'q.owner_id')
            ->select('q.*', 'v.grand_total', 'v.margin_pct', 'c.name as client', 'u.name as seller')->orderByDesc('q.created_at')->get();
        $versions = DB::table('quote_versions')->whereIn('quote_id', $rows->pluck('id'))->count();
        $decided = $rows->whereIn('status', ['aprobada', 'convertida', 'rechazada', 'vencida'])->count();
        $approved = $rows->whereIn('status', ['aprobada', 'convertida']);
        $viewed = $rows->filter(fn ($q) => $q->viewed_at);
        $hours = $viewed->map(fn ($q) => Carbon::parse($q->created_at)->diffInMinutes(Carbon::parse($q->viewed_at)) / 60);
        $cycle = $approved->filter(fn ($q) => $q->approved_at)->map(fn ($q) => Carbon::parse($q->created_at)->diffInHours(Carbon::parse($q->approved_at)) / 24);
        $labels = ['borrador' => 'Borrador', 'enviada' => 'Enviada', 'vista' => 'Vista', 'negociacion' => 'Negociación', 'aprobada' => 'Aprobada', 'convertida' => 'Convertida', 'rechazada' => 'Rechazada', 'vencida' => 'Vencida'];
        $reasons = $rows->where('status', 'rechazada')->groupBy(fn ($q) => $q->rejected_reason ?: 'Sin motivo')->map->count();
        if ($n = $rows->where('status', 'vencida')->count()) {
            $reasons['Vencida sin respuesta'] = $n;
        }
        $lostOpps = $this->owned(DB::table('crm_opportunities'))->where('stage', 'perdida')->whereNull('deleted_at')->where('closed_at', '>=', $this->from->toDateString())
            ->whereNotIn('id', $rows->where('status', 'rechazada')->pluck('opportunity_id')->filter())->pluck('lost_reason_code');
        foreach ($lostOpps->countBy() as $reason => $n) {
            $reasons['Oportunidad perdida: '.($reason ?: 'otro')] = ($reasons['Oportunidad perdida: '.($reason ?: 'otro')] ?? 0) + $n;
        }

        return [
            'kpis' => [
                ['key' => 'issued', 'label' => 'Emitidas', 'value' => $rows->count(), 'hint' => "{$versions} versiones"],
                ['key' => 'approval_rate', 'label' => 'Tasa de aprobación', 'value' => $this->pct($approved->count(), $decided), 'unit' => '%'],
                ['key' => 'first_view', 'label' => 'Tiempo a primera vista', 'value' => $hours->isEmpty() ? null : round($hours->avg(), 1), 'unit' => 'h'],
                ['key' => 'cycle', 'label' => 'Ciclo hasta aprobación', 'value' => $cycle->isEmpty() ? null : round($cycle->avg(), 1), 'unit' => 'días'],
                ['key' => 'value', 'label' => 'Valor cotizado', 'value' => $this->money($rows->sum(fn ($q) => $this->pen($q->grand_total ?? 0, $q->currency, $q->fx_rate))), 'unit' => 'PEN',
                    'hint' => 'Margen medio '.($rows->whereNotNull('margin_pct')->isEmpty() ? '—' : round($rows->whereNotNull('margin_pct')->avg('margin_pct'), 1).' %')],
            ],
            'charts' => [
                'status' => ['title' => 'Cotizaciones por estado', 'type' => 'donut', 'rows' => $rows->groupBy('status')->map(fn ($g, $s) => ['label' => $labels[$s] ?? $s, 'value' => $g->count(), 'key' => $s])->values()->all()],
                'reasons' => ['title' => 'Motivos de rechazo y pérdida', 'type' => 'bars', 'rows' => collect($reasons)->map(fn ($n, $l) => ['label' => $l, 'value' => $n])->sortByDesc('value')->values()->all()],
                'opening' => ['title' => 'Horas hasta la apertura', 'type' => 'bars', 'rows' => collect(['< 2 h' => [0, 2], '2–24 h' => [2, 24], '1–3 días' => [24, 72], '> 3 días' => [72, PHP_INT_MAX]])
                    ->map(fn ($r, $l) => ['label' => $l, 'value' => $hours->filter(fn ($h) => $h >= $r[0] && $h < $r[1])->count()])->values()->all()],
            ],
            'table' => [
                'columns' => [['code', 'Cotización', 'link:quote'], ['client', 'Cliente'], ['seller', 'Vendedor'], ['status_label', 'Estado'], ['version', 'Vers.'],
                    ['total', 'Total S/', 'PEN'], ['margin', 'Margen', '%'], ['viewed_at', 'Vista en', 'datetime'], ['valid_until', 'Vence', 'date']],
                'rows' => $rows->map(fn ($q) => ['code' => $q->code, 'client' => $q->client, 'seller' => $q->seller, 'status' => $q->status, 'status_label' => $labels[$q->status] ?? $q->status,
                    'version' => $q->current_version_no, 'total' => $this->money($this->pen($q->grand_total ?? 0, $q->currency, $q->fx_rate)),
                    'margin' => $q->margin_pct === null ? null : round((float) $q->margin_pct, 1), 'warn' => $q->margin_pct !== null && (float) $q->margin_pct < 15,
                    'viewed_at' => $q->viewed_at, 'valid_until' => $q->valid_until])->all(),
            ],
        ];
    }

    private function reservas(): array
    {
        $rows = $this->reservations()->leftJoin('crm_clients as c', 'c.id', '=', 'r.client_id')->select('r.*', 'c.name as client')->orderByDesc('r.created_at')->get();
        $active = $rows->reject(fn ($r) => in_array($r->status, self::CANCELLED, true));
        $pax = DB::table('booking_passengers')->whereIn('reservation_id', $active->pluck('id'))->count();
        $sales = $active->sum(fn ($r) => $this->pen($r->total_amount, $r->currency, $r->fx_rate));
        $dest = DB::table('booking_services as s')->join('catalog_product_destinations as pd', 'pd.product_id', '=', 's.product_id')
            ->join('catalog_destinations as d', 'd.id', '=', 'pd.destination_id')->whereIn('s.reservation_id', $active->pluck('id'))
            ->select('s.reservation_id', 'd.name')->get()->unique('reservation_id');
        $labels = ['pendiente_pago' => 'Pendiente de pago', 'pendiente_proveedor' => 'Pendiente proveedor', 'confirmada' => 'Confirmada', 'en_operacion' => 'En operación',
            'finalizada' => 'Finalizada', 'cancelada' => 'Cancelada'];

        return [
            'kpis' => [
                ['key' => 'reservations', 'label' => 'Reservas', 'value' => $rows->count(), 'hint' => $active->count().' vigentes'],
                ['key' => 'pax', 'label' => 'Pasajeros', 'value' => $pax],
                ['key' => 'sales', 'label' => 'Ventas', 'value' => $this->money($sales), 'unit' => 'PEN'],
                ['key' => 'ticket', 'label' => 'Ticket promedio', 'value' => $this->money($active->count() ? $sales / $active->count() : 0), 'unit' => 'PEN'],
                ['key' => 'cancelled', 'label' => 'Canceladas', 'value' => $rows->count() - $active->count()],
            ],
            'charts' => [
                'channel' => ['title' => 'Ventas por canal', 'type' => 'donut', 'unit' => 'PEN', 'rows' => $active->groupBy('channel')
                    ->map(fn ($g, $c) => ['label' => $c, 'value' => $this->money($g->sum(fn ($r) => $this->pen($r->total_amount, $r->currency, $r->fx_rate)))])->values()->all()],
                'destination' => ['title' => 'Reservas por destino', 'type' => 'bars', 'rows' => $dest->countBy('name')->map(fn ($n, $l) => ['label' => $l, 'value' => $n])->sortByDesc('value')->values()->all()],
                'status' => ['title' => 'Reservas por estado', 'type' => 'bars', 'rows' => $rows->groupBy('status')->map(fn ($g, $s) => ['label' => $labels[$s] ?? $s, 'value' => $g->count()])->values()->all()],
            ],
            'table' => [
                'columns' => [['code', 'Reserva', 'link:reservation'], ['client', 'Cliente'], ['channel', 'Canal'], ['status_label', 'Estado'], ['travel_from', 'Inicio', 'date'],
                    ['pax', 'Pax'], ['total', 'Total S/', 'PEN']],
                'rows' => $rows->map(fn ($r) => ['code' => $r->code, 'client' => $r->client, 'channel' => $r->channel, 'status' => $r->status, 'status_label' => $labels[$r->status] ?? $r->status,
                    'travel_from' => $r->travel_from, 'pax' => DB::table('booking_passengers')->where('reservation_id', $r->id)->count(),
                    'total' => $this->money(in_array($r->status, self::CANCELLED, true) ? 0 : $this->pen($r->total_amount, $r->currency, $r->fx_rate))])->all(),
            ],
        ];
    }

    private function operaciones(): array
    {
        $services = DB::table('booking_services as s')->join('booking_reservations as r', 'r.id', '=', 's.reservation_id')->whereNull('r.deleted_at')
            ->whereBetween('s.service_date', [$this->from->toDateString(), $this->to->toDateString()])->where('s.status', '<>', 'cancelado')
            ->when($this->sede, fn ($q) => $q->where('r.sede_id', $this->sede))
            ->leftJoin('catalog_providers as p', 'p.id', '=', 's.provider_id')->select('s.*', 'r.code as reservation', 'r.currency', 'r.fx_rate', 'p.name as provider')->orderBy('s.service_date')->get();
        $incidents = DB::table('ops_incidents')->whereBetween('created_at', [$this->from, $this->to])->get();
        $tolerance = (int) $this->settings->get('operations', 'checkin_tolerance_min', 15);
        $checkins = DB::table('ops_assignment_checkins as c')->join('ops_assignments as a', 'a.id', '=', 'c.assignment_id')->whereNull('c.undone_at')
            ->whereBetween('a.service_date', [$this->from->toDateString(), $this->to->toDateString()])->select('c.checked_at', 'a.service_date', 'a.service_time')->get();
        $onTime = $checkins->filter(fn ($c) => $c->service_time === null || Carbon::parse($c->checked_at)->lte(Carbon::parse($c->service_date.' '.$c->service_time, $this->clock->timezone())->addMinutes($tolerance)))->count();
        $labels = ['pendiente' => 'Pendiente', 'solicitado' => 'Solicitado', 'confirmado' => 'Confirmado', 'en_curso' => 'En curso', 'completado' => 'Completado', 'rechazado' => 'Rechazado'];

        return [
            'kpis' => [
                ['key' => 'services', 'label' => 'Servicios', 'value' => $services->count()],
                ['key' => 'confirmed', 'label' => 'Confirmados', 'value' => $this->pct($services->whereIn('status', ['confirmado', 'en_curso', 'completado'])->count(), $services->count()), 'unit' => '%'],
                ['key' => 'checkin', 'label' => 'Puntualidad de check-in', 'value' => $checkins->isEmpty() ? null : $this->pct($onTime, $checkins->count()), 'unit' => '%', 'hint' => "tolerancia {$tolerance} min"],
                ['key' => 'incidents', 'label' => 'Incidencias', 'value' => $incidents->count(), 'hint' => $incidents->where('status', '<>', 'cerrada')->count().' abiertas'],
            ],
            'charts' => [
                'status' => ['title' => 'Servicios por estado', 'type' => 'bars', 'rows' => $services->groupBy('status')->map(fn ($g, $s) => ['label' => $labels[$s] ?? $s, 'value' => $g->count()])->values()->all()],
                'severity' => ['title' => 'Incidencias por severidad', 'type' => 'bars', 'rows' => $incidents->groupBy('severity')->map(fn ($g, $s) => ['label' => ucfirst($s), 'value' => $g->count()])->values()->all()],
                'type' => ['title' => 'Incidencias por tipo', 'type' => 'bars', 'rows' => $incidents->groupBy('type')->map(fn ($g, $s) => ['label' => $s, 'value' => $g->count()])->values()->all()],
            ],
            'table' => [
                'columns' => [['code', 'Servicio'], ['name', 'Detalle'], ['reservation', 'Reserva', 'link:reservation'], ['date', 'Fecha', 'date'], ['provider', 'Proveedor'],
                    ['status_label', 'Estado'], ['cost', 'Costo S/', 'PEN']],
                'rows' => $services->map(fn ($s) => ['code' => $s->code, 'name' => $s->name, 'reservation' => $s->reservation, 'date' => $s->service_date, 'provider' => $s->provider,
                    'status' => $s->status, 'status_label' => $labels[$s->status] ?? $s->status, 'cost' => $this->money($this->pen($s->cost_amount ?? 0, $s->currency, $s->fx_rate))])->all(),
            ],
        ];
    }

    private function finanzas(): array
    {
        $profit = $this->user->hasPermissionTo('finance.profitability');
        $payables = $this->user->hasPermissionTo('finance.payables');
        $res = $this->reservations()->leftJoin('crm_clients as c', 'c.id', '=', 'r.client_id')->select('r.*', 'c.name as client')->get()
            ->reject(fn ($r) => in_array($r->status, self::CANCELLED, true));
        $costs = DB::table('booking_services')->whereIn('reservation_id', $res->pluck('id'))->where('status', '<>', 'cancelado')
            ->selectRaw('reservation_id, sum(cost_amount) as cost')->groupBy('reservation_id')->pluck('cost', 'reservation_id');
        $paid = DB::table('fin_receivables')->whereIn('reservation_id', $res->pluck('id'))->whereNull('deleted_at')
            ->selectRaw('reservation_id, sum(amount) as amount, sum(paid_amount) as paid')->groupBy('reservation_id')->get()->keyBy('reservation_id');
        $lines = $res->map(function ($r) use ($costs, $paid) {
            $sale = $this->pen($r->total_amount, $r->currency, $r->fx_rate);
            $cost = $this->pen($costs[$r->id] ?? 0, $r->currency, $r->fx_rate);
            $p = $paid[$r->id] ?? null;

            return ['code' => $r->code, 'client' => $r->client, 'sale' => $this->money($sale), 'cost' => $this->money($cost), 'profit' => $this->money($sale - $cost),
                'margin' => $sale > 0 ? round(($sale - $cost) * 100 / $sale, 1) : null, 'collected' => $p && (float) $p->amount > 0 ? round((float) $p->paid * 100 / (float) $p->amount, 1) : 0,
                'month' => substr((string) $r->created_at, 0, 7)];
        });
        $today = $this->clock->today();
        $open = DB::table('fin_receivables')->whereNull('deleted_at')->whereIn('status', ['abierta', 'parcial', 'vencida'])->get();
        $aging = ['Por vencer' => 0, '1–30' => 0, '31–60' => 0, '> 60' => 0];
        foreach ($open as $r) {
            $days = Carbon::parse($r->due_date)->diffInDays($today, false);
            $bal = $this->pen((float) $r->amount - (float) $r->paid_amount, $r->currency, $r->fx_rate);
            $aging[match (true) {
                $days <= 0 => 'Por vencer', $days <= 30 => '1–30', $days <= 60 => '31–60', default => '> 60'
            }] += $bal;
        }
        $sales = $lines->sum('sale');
        $cost = $lines->sum('cost');
        $kpis = [['key' => 'sales', 'label' => 'Ventas netas', 'value' => $this->money($sales), 'unit' => 'PEN']];
        if ($profit) {
            $kpis[] = ['key' => 'cost', 'label' => 'Costo', 'value' => $this->money($cost), 'unit' => 'PEN'];
            $kpis[] = ['key' => 'profit', 'label' => 'Utilidad', 'value' => $this->money($sales - $cost), 'unit' => 'PEN'];
            $kpis[] = ['key' => 'margin', 'label' => 'Margen', 'value' => $sales > 0 ? round(($sales - $cost) * 100 / $sales, 1) : null, 'unit' => '%'];
        }
        $kpis[] = ['key' => 'receivables', 'label' => 'Por cobrar', 'value' => $this->money(array_sum($aging)), 'unit' => 'PEN', 'hint' => $open->where('status', 'vencida')->count().' vencidas'];
        $charts = [
            'monthly' => ['title' => $profit ? 'Ventas vs costo' : 'Ventas facturables por mes', 'type' => 'series', 'unit' => 'PEN', 'series' => $profit ? ['Ventas', 'Costo'] : ['Ventas'],
                'rows' => $this->months()->map(fn ($m) => ['label' => $m['label'], 'values' => array_values(array_filter([
                    $this->money($lines->where('month', $m['key'])->sum('sale')), $profit ? $this->money($lines->where('month', $m['key'])->sum('cost')) : null,
                ], fn ($v) => $v !== null))])->all()],
            'aging' => ['title' => 'Antigüedad de CxC', 'type' => 'bars', 'unit' => 'PEN', 'rows' => collect($aging)->map(fn ($v, $l) => ['label' => $l, 'value' => $this->money($v)])->values()->all()],
        ];
        if ($payables) {
            $charts['payables'] = ['title' => 'CxP por estado', 'type' => 'bars', 'unit' => 'PEN', 'rows' => DB::table('fin_payables')->whereNull('deleted_at')->get()->groupBy('status')
                ->map(fn ($g, $s) => ['label' => ucfirst($s), 'value' => $this->money($g->sum(fn ($p) => $this->pen((float) $p->amount - (float) $p->paid_amount, $p->currency, $p->fx_rate)))])->values()->all()];
        }
        $columns = [['code', 'Reserva', 'link:reservation'], ['client', 'Cliente'], ['sale', 'Venta', 'PEN']];
        if ($profit) {
            array_push($columns, ['cost', 'Costo', 'PEN'], ['profit', 'Utilidad', 'PEN'], ['margin', 'Margen', '%']);
        }
        $columns[] = ['collected', '% cobrado', '%'];
        $keys = array_column($columns, 0);

        return [
            'kpis' => $kpis, 'charts' => $charts, 'variant' => $profit ? 'completa' : 'contable',
            'table' => ['columns' => $columns, 'rows' => $lines->map(fn ($l) => array_intersect_key($l, array_flip($keys)))->values()->all()],
        ];
    }

    private function proveedores(): array
    {
        $payables = $this->user->hasPermissionTo('finance.payables');
        $services = DB::table('booking_services as s')->join('booking_reservations as r', 'r.id', '=', 's.reservation_id')->whereNull('r.deleted_at')
            ->whereNotNull('s.provider_id')->where('s.status', '<>', 'cancelado')->whereBetween('s.service_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->when($this->sede, fn ($q) => $q->where('r.sede_id', $this->sede))->select('s.*', 'r.currency', 'r.fx_rate')->get();
        $providers = DB::table('catalog_providers')->whereNull('deleted_at')->whereIn('id', $services->pluck('provider_id')->unique())->get()->keyBy('id');
        $due = $payables ? DB::table('fin_payables')->whereNull('deleted_at')->whereIn('status', ['abierta', 'aprobada', 'parcial', 'vencida'])->get()->groupBy('provider_id') : collect();
        $soon = DB::table('catalog_providers')->whereNull('deleted_at')->whereBetween('contract_end', [$this->clock->today()->toDateString(), $this->clock->today()->addDays(60)->toDateString()])->count();
        $rows = $services->groupBy('provider_id')->map(function ($g, $id) use ($providers, $due, $payables) {
            $p = $providers[$id] ?? null;
            $row = ['provider' => $p?->name ?? '—', 'type' => $p?->type, 'services' => $g->count(), 'cost' => $this->money($g->sum(fn ($s) => $this->pen($s->cost_amount ?? 0, $s->currency, $s->fx_rate))),
                'confirmation' => $this->pct($g->whereIn('status', ['confirmado', 'en_curso', 'completado'])->count(), $g->count()), 'rating' => $p?->rating !== null ? (float) $p->rating : null,
                'contract_end' => $p?->contract_end, 'status' => $p?->status];
            if ($payables) {
                $row['payable'] = $this->money(collect($due[$id] ?? [])->sum(fn ($x) => $this->pen((float) $x->amount - (float) $x->paid_amount, $x->currency, $x->fx_rate)));
            }

            return $row;
        })->sortByDesc('cost')->values();
        $columns = [['provider', 'Proveedor', 'link:provider'], ['services', 'Servicios'], ['cost', 'Costo', 'PEN'], ['confirmation', 'Confirmación', '%'], ['rating', 'Calificación']];
        if ($payables) {
            $columns[] = ['payable', 'Por pagar', 'PEN'];
        }
        array_push($columns, ['contract_end', 'Contrato hasta', 'date'], ['status', 'Estado']);

        return [
            'kpis' => [
                ['key' => 'providers', 'label' => 'Proveedores utilizados', 'value' => $rows->count()],
                ['key' => 'services', 'label' => 'Servicios', 'value' => $services->count(), 'hint' => $this->pct($services->whereIn('status', ['confirmado', 'en_curso', 'completado'])->count(), $services->count()).' % confirmados'],
                ['key' => 'cost', 'label' => 'Costo', 'value' => $this->money($rows->sum('cost')), 'unit' => 'PEN'],
                ['key' => 'rating', 'label' => 'Calificación media', 'value' => $rows->whereNotNull('rating')->isEmpty() ? null : round($rows->whereNotNull('rating')->avg('rating'), 1)],
                ['key' => 'contracts', 'label' => 'Contratos por vencer (≤ 60 días)', 'value' => $soon],
            ],
            'charts' => [
                'cost' => ['title' => 'Costo por proveedor', 'type' => 'bars', 'unit' => 'PEN', 'rows' => $rows->take(8)->map(fn ($r) => ['label' => $r['provider'], 'value' => $r['cost']])->all()],
                'volume' => ['title' => 'Volumen de servicios', 'type' => 'bars', 'rows' => $rows->sortByDesc('services')->take(8)->map(fn ($r) => ['label' => $r['provider'], 'value' => $r['services']])->values()->all()],
                'types' => ['title' => 'Costo por tipo', 'type' => 'donut', 'unit' => 'PEN', 'rows' => $rows->groupBy('type')->map(fn ($g, $t) => ['label' => $t ?: 'Otro', 'value' => $this->money($g->sum('cost'))])->values()->all()],
            ],
            'table' => ['columns' => $columns, 'rows' => $rows->map(fn ($r) => array_intersect_key($r, array_flip(array_column($columns, 0))))->all()],
        ];
    }

    private function clientes(): array
    {
        $clients = $this->owned(DB::table('crm_clients as c'), 'c.owner_id')->whereNull('c.deleted_at')->leftJoin('iam_users as u', 'u.id', '=', 'c.owner_id')
            ->select('c.*', 'u.name as owner')->get();
        $res = $this->reservations()->get()->reject(fn ($r) => in_array($r->status, self::CANCELLED, true))->groupBy('client_id');
        $quotes = DB::table('quote_quotes')->whereNull('deleted_at')->whereBetween('created_at', [$this->from, $this->to])->selectRaw('client_id, count(*) as n')->groupBy('client_id')->pluck('n', 'client_id');
        $surveys = DB::table('crm_surveys')->whereNotNull('score')->get();
        $promoters = $surveys->where('score', '>=', 9)->count();
        $detractors = $surveys->where('score', '<=', 6)->count();
        $segments = DB::table('crm_catalog_items')->where('kind', 'client_segment')->pluck('label', 'code');
        $rows = $clients->map(fn ($c) => ['client' => $c->name, 'code' => $c->code, 'segment' => $segments[$c->segment_code] ?? $c->segment_code, 'country' => $c->country,
            'quotes' => (int) ($quotes[$c->id] ?? 0), 'reservations' => collect($res[$c->id] ?? [])->count(),
            'sales' => $this->money(collect($res[$c->id] ?? [])->sum(fn ($r) => $this->pen($r->total_amount, $r->currency, $r->fx_rate))), 'nps' => $c->nps_last, 'owner' => $c->owner])
            ->sortByDesc('sales')->values();
        $withRes = $rows->where('reservations', '>', 0);

        return [
            'kpis' => [
                ['key' => 'clients', 'label' => 'Clientes', 'value' => $clients->count(), 'hint' => $clients->where('kind', 'empresa')->count().' empresas · '.$clients->where('kind', 'persona')->count().' personas'],
                ['key' => 'with_reservations', 'label' => 'Con reservas', 'value' => $withRes->count()],
                ['key' => 'avg_sale', 'label' => 'Venta por cliente', 'value' => $this->money($withRes->count() ? $withRes->sum('sales') / $withRes->count() : 0), 'unit' => 'PEN'],
                ['key' => 'nps', 'label' => 'NPS', 'value' => $surveys->isEmpty() ? null : (int) round(($promoters - $detractors) * 100 / $surveys->count()), 'hint' => $surveys->count().' respuestas'],
                ['key' => 'foreign', 'label' => 'Extranjeros', 'value' => $clients->filter(fn ($c) => $c->country && $c->country !== 'Perú')->count()],
            ],
            'charts' => [
                'segments' => ['title' => 'Clientes por segmento', 'type' => 'donut', 'rows' => $rows->countBy('segment')->map(fn ($n, $l) => ['label' => $l ?: 'Sin segmento', 'value' => $n])->values()->all()],
                'top' => ['title' => 'Top clientes por ventas', 'type' => 'bars', 'unit' => 'PEN', 'rows' => $withRes->take(8)->map(fn ($r) => ['label' => $r['client'], 'value' => $r['sales']])->values()->all()],
                'country' => ['title' => 'País de origen', 'type' => 'bars', 'rows' => $rows->countBy('country')->map(fn ($n, $l) => ['label' => $l ?: '—', 'value' => $n])->sortByDesc('value')->values()->all()],
            ],
            'table' => [
                'columns' => [['client', 'Cliente', 'link:client'], ['segment', 'Segmento'], ['country', 'País'], ['quotes', 'Cotiz.'], ['reservations', 'Reservas'],
                    ['sales', 'Ventas', 'PEN'], ['nps', 'NPS'], ['owner', 'Responsable']],
                'rows' => $rows->all(),
            ],
        ];
    }

    // ───────────────────────────── Apoyo ─────────────────────────────

    /** @return array{0: Carbon, 1: Carbon} */
    public function range(string $period): array
    {
        $now = $this->clock->now();

        return match ($period) {
            'mes' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'trimestre' => [$now->copy()->subMonthsNoOverflow(2)->startOfMonth(), $now->copy()->endOfDay()],
            'all' => [Carbon::create(2020, 1, 1, 0, 0, 0, $now->timezone), $now->copy()->addYears(2)],
            default => [$now->copy()->subMonthsNoOverflow(5)->startOfMonth(), $now->copy()->endOfDay()],
        };
    }

    /** @return Collection<int, array{key: string, label: string}> meses del periodo (máx. 12, los últimos). */
    private function months(): Collection
    {
        $names = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'set', 'oct', 'nov', 'dic'];
        $end = $this->clock->now()->startOfMonth();
        $start = $this->from->copy()->startOfMonth()->max($end->copy()->subMonthsNoOverflow(11));
        $out = collect();
        for ($m = $start->copy(); $m->lte($end); $m->addMonthNoOverflow()) {
            $out->push(['key' => $m->format('Y-m'), 'label' => $names[$m->month - 1].' '.$m->format('y')]);
        }

        return $out;
    }

    private function reservations(): Builder
    {
        return $this->owned(DB::table('booking_reservations as r'), 'r.owner_id')->whereNull('r.deleted_at')->whereBetween('r.created_at', [$this->from, $this->to])
            ->when($this->sede, fn ($q) => $q->where('r.sede_id', $this->sede))->select('r.*');
    }

    /** REP-BR-02 (Ventas: su cartera) y filtro de sede por la sede del responsable. */
    private function owned(Builder $q, string $column = 'owner_id'): Builder
    {
        if ($this->ownOnly()) {
            $q->where($column, $this->user->id);
        }
        if ($this->sede && ! str_starts_with($column, 'r.')) {
            $q->whereIn($column, DB::table('iam_users')->where('sede_id', $this->sede)->select('id'));
        }

        return $q;
    }

    private function ownOnly(): bool
    {
        return $this->user->dataScope() === 'own';
    }

    private function users(): Collection
    {
        return DB::table('iam_users as u')->join('model_has_roles as m', 'm.model_id', '=', 'u.id')->join('roles as ro', 'ro.id', '=', 'm.role_id')
            ->whereIn('ro.name', ['sales', 'manager', 'tenant_admin'])->whereNull('u.deleted_at')
            ->when($this->ownOnly(), fn ($q) => $q->where('u.id', $this->user->id))->orderBy('u.name')->get(['u.id', 'u.name']);
    }

    private function can(string $route): bool
    {
        return Access::check($this->user->accessSubject(), $route)->allowed;
    }

    private function pen(mixed $amount, ?string $currency, mixed $fx): float
    {
        return $this->fx->toPen($amount ?? 0, $currency ?? 'PEN', $fx);
    }

    private function money(float|int $v): float
    {
        return round((float) $v, 2);
    }

    private function pct(int $part, int $total): ?float
    {
        return $total > 0 ? round($part * 100 / $total, 1) : null;
    }
}
