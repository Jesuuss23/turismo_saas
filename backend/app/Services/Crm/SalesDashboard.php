<?php

namespace App\Services\Crm;

use App\Models\Crm\Activity;
use App\Models\Crm\CrmModel;
use App\Models\Crm\Lead;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Services\Crm\Linked\LinkedData;
use Illuminate\Support\Facades\Cache;

/** Panel de Ventas "Mi cartera" (spec 01 §7.4). Cache 60 s por usuario, invalidado por escrituras CRM. */
class SalesDashboard
{
    public function __construct(
        private readonly CrmCatalog $catalog,
        private readonly CrmClock $clock,
        private readonly LinkedData $linked,
    ) {}

    /** La clave incluye la versión de datos CRM del tenant: cualquier escritura CRM invalida el panel. */
    public static function cacheKey(User $user): string
    {
        return "crm:sales-panel:{$user->tenant_id}:v".CrmModel::dataVersion().":{$user->id}";
    }

    public function build(User $user): array
    {
        // Solo valores escalares: el payload se cachea y debe salir igual que recién calculado.
        return Cache::remember(self::cacheKey($user), 60, fn () => $this->compute($user));
    }

    private function compute(User $user): array
    {
        $pen = fn ($o) => $this->clock->toPen((float) $o->amount, $o->currency);
        $endOfToday = $this->clock->today()->endOfDay()->utc();

        $opps = Opportunity::where('owner_id', $user->id)->get();
        $open = $opps->whereIn('stage', Opportunity::OPEN_STAGES);
        $won = $opps->where('stage', Opportunity::WON)->count();
        $lost = $opps->where('stage', Opportunity::LOST)->count();

        $pending = Activity::where('owner_id', $user->id)->where('status', '<>', Activity::DONE)->orderBy('due_at')->get();
        $dueNow = $pending->filter(fn ($a) => $a->due_at->lte($endOfToday));

        $myLeads = Lead::where('owner_id', $user->id);
        $leadsTotal = (clone $myLeads)->count();
        $leadsConverted = (clone $myLeads)->where('status', Lead::CONVERTED)->count();

        $linked = $this->linked->salesPanel($user->id);

        $stages = [];
        foreach (Opportunity::OPEN_STAGES as $stage) {
            $rows = $open->where('stage', $stage);
            $stages[] = ['stage' => $stage, 'name' => $this->catalog->stageName($stage), 'count' => $rows->count(), 'total_pen' => round($rows->sum($pen), 2)];
        }

        $opp = fn (Opportunity $o) => [
            'code' => $o->code, 'title' => $o->title, 'stage' => $o->stage, 'amount' => (float) $o->amount, 'currency' => $o->currency,
            'expected_close_date' => $o->expected_close_date?->toDateString(),
        ];
        $act = fn (Activity $a) => [
            'code' => $a->code, 'type' => $a->type, 'subject' => $a->subject, 'due_at' => $a->due_at?->toJSON(), 'status' => $a->effectiveStatus(),
            'related' => $a->related_type ? ['type' => $a->related_type, 'id' => $a->related_id] : null,
        ];
        $lead = fn (Lead $l) => [
            'code' => $l->code, 'full_name' => $l->full_name, 'destination_label' => $l->destination_label, 'pax' => $l->pax,
            'score' => $l->score, 'status' => $l->status, 'created_at' => $l->created_at?->toJSON(),
        ];

        return [
            'alert' => $dueNow->isNotEmpty()
                ? "{$dueNow->count()} actividad(es) para hoy o vencidas. Los recordatorios se envían cada hora (JOB-04)" : null,
            'kpis' => [
                'pipeline' => ['total_pen' => round($open->sum($pen), 2), 'count' => $open->count(), 'weighted_pen' => round($open->sum(fn ($o) => $pen($o) * $o->probability / 100), 2)],
                'open_quotes' => ['count' => $linked['open_quotes']['count'], 'expiring_7d' => $linked['open_quotes']['expiring_7d']],
                'activities' => ['due_now' => $dueNow->count(), 'pending' => $pending->count()],
                'conversion' => [
                    'rate' => $won + $lost > 0 ? round($won / ($won + $lost) * 100, 1) : 0,
                    'won' => $won, 'lost' => $lost, 'leads_converted' => $leadsConverted, 'leads_total' => $leadsTotal,
                ],
                'commission' => $linked['commission'],
            ],
            'pipeline_by_stage' => $stages,
            'closing_soon' => $open->sortBy('expected_close_date')->take(4)->map($opp)->values()->all(),
            'activities' => [
                'due_now' => $dueNow->map($act)->values()->all(),
                'upcoming' => $pending->reject(fn ($a) => $a->due_at->lte($endOfToday))->take(3)->map($act)->values()->all(),
            ],
            'quotes_expiring' => $linked['open_quotes']['items'],
            'leads' => [
                'mine' => Lead::where('owner_id', $user->id)->whereIn('status', Lead::OPEN)->orderByDesc('score')->orderByDesc('created_at')->limit(5)->get()->map($lead)->all(),
                'pool' => Lead::whereNull('owner_id')->whereIn('status', [Lead::NEW, Lead::CONTACTED])->orderBy('created_at')->limit(10)->get()->map($lead)->all(),
            ],
            'upcoming_trips' => $linked['upcoming_trips'],
        ];
    }
}
