<?php

namespace App\Services\Crm\Targets;

use App\Models\Bookings\Reservation;
use App\Models\Crm\Opportunity;
use App\Models\Crm\SalesTarget;
use App\Models\Crm\SalesTargetPolicy;
use App\Models\Crm\SalesTeamMember;
use App\Models\Iam\User;
use App\Services\Crm\CrmClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Avance, proyección y salud de una meta (read model `crm_target_progress`, MET-BR-08/09/10). Las metas cerradas usan el resultado
 * congelado al cierre (MET-BR-06); las demás se calculan en vivo con reservas y oportunidades del período.
 */
class TargetProgress
{
    public const DEFAULT_MARGIN_RATIO = 0.22;

    public const HEALTH_LABELS = ['borrador' => 'Borrador', 'anulada' => 'Anulada', 'por_iniciar' => 'Por iniciar', 'en_camino' => 'En camino', 'en_riesgo' => 'En riesgo',
        'critico' => 'Crítico', 'por_cerrar' => 'Por cerrar', 'cumplida' => 'Cumplida', 'no_cumplida' => 'No cumplida'];

    private ?SalesTargetPolicy $policy = null;

    public function __construct(private readonly CrmClock $clock) {}

    /** `2026-09` | `2026-Q3` → [desde, hasta]. @return array{0: Carbon, 1: Carbon} */
    public static function period(string $type, string $key): array
    {
        if ($type === 'mes') {
            $from = Carbon::createFromFormat('!Y-m', $key);

            return [$from, $from->copy()->endOfMonth()->startOfDay()];
        }
        [$year, $q] = explode('-Q', $key);
        $from = Carbon::create((int) $year, ((int) $q - 1) * 3 + 1, 1);

        return [$from, $from->copy()->addMonths(2)->endOfMonth()->startOfDay()];
    }

    public function policy(): SalesTargetPolicy
    {
        return $this->policy ??= SalesTargetPolicy::firstOrFail();
    }

    /** Integrantes del alcance: asesor, equipo (vigentes en el período) o asesores de la sede (conversión y pipeline). @return list<string> */
    public function users(SalesTarget $t): array
    {
        return match ($t->scope_type) {
            'asesor' => [$t->scope_id],
            'equipo' => SalesTeamMember::where('team_id', $t->scope_id)->whereDate('valid_from', '<=', $t->period_to)
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $t->period_from))->pluck('user_id')->unique()->values()->all(),
            default => User::where('sede_id', $t->scope_id)->pluck('id')->all(),
        };
    }

    /** MET-BR-08: reservas no borrador/canceladas creadas en el período (owner, sede de la reserva o integrantes del equipo). */
    public function reservations(SalesTarget $t): Collection
    {
        $tz = $this->clock->timezone();
        $from = Carbon::parse($t->period_from->toDateString(), $tz)->startOfDay()->utc();
        $to = Carbon::parse($t->period_to->toDateString(), $tz)->endOfDay()->utc();
        $q = Reservation::with(['client:id,name', 'owner:id,name'])->whereNotIn('status', [Reservation::DRAFT, Reservation::CANCELLED])->whereBetween('created_at', [$from, $to]);
        $t->scope_type === 'sede' ? $q->where('sede_id', $t->scope_id) : $q->whereIn('owner_id', $this->users($t));
        $rows = $q->orderBy('created_at')->get();
        $costs = DB::table('booking_services')->whereIn('reservation_id', $rows->pluck('id'))->whereNotIn('status', ['cancelado', 'rechazado'])
            ->groupBy('reservation_id')->select('reservation_id', DB::raw('sum(cost_amount) as cost'))->pluck('cost', 'reservation_id');

        return $rows->map(function (Reservation $r) use ($costs) {
            $fx = $r->currency === 'PEN' ? 1.0 : (float) $r->fx_rate;
            $sale = round((float) $r->total_amount * $fx, 2);

            return ['id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'client' => $r->client?->name, 'owner' => $r->owner?->name, 'owner_id' => $r->owner_id,
                'status' => $r->status, 'status_label' => Reservation::STATUS_LABELS[$r->status] ?? $r->status, 'currency' => $r->currency, 'total' => (string) $r->total_amount,
                'fx_rate' => (string) $r->fx_rate, 'sales_pen' => $sale, 'margin_pen' => round(((float) $r->total_amount - (float) ($costs[$r->id] ?? 0)) * $fx, 2),
                'created_at' => $r->created_at->toIso8601String()];
        });
    }

    /** Oportunidades abiertas con cierre entre hoy y el fin del período (pipeline ponderado, MET-BR-09). */
    public function pipeline(SalesTarget $t): Collection
    {
        $today = $this->clock->today()->toDateString();
        if ($t->period_to->toDateString() < $today) {
            return collect();
        }

        return Opportunity::with(['client:id,name', 'owner:id,name'])->whereIn('owner_id', $this->users($t))->whereIn('stage', Opportunity::OPEN_STAGES)
            ->whereBetween('expected_close_date', [max($today, $t->period_from->toDateString()), $t->period_to->toDateString()])->orderBy('expected_close_date')->get()
            ->map(fn (Opportunity $o) => ['code' => $o->code, 'title' => $o->title, 'client' => $o->client?->name, 'owner' => $o->owner?->name, 'stage' => $o->stage,
                'amount_pen' => $this->clock->toPen((float) $o->amount, $o->currency), 'probability' => $o->probability,
                'weighted_pen' => round($this->clock->toPen((float) $o->amount, $o->currency) * $o->probability / 100, 2), 'close_date' => $o->expected_close_date->toDateString()]);
    }

    /** Actual en vivo: ventas, reservas, margen y conversión del período. */
    public function actual(SalesTarget $t, ?Collection $reservations = null): array
    {
        $reservations ??= $this->reservations($t);
        $closed = Opportunity::whereIn('owner_id', $this->users($t))->whereIn('stage', [Opportunity::WON, Opportunity::LOST])
            ->whereBetween('closed_at', [$t->period_from->toDateString(), $t->period_to->toDateString()])->get(['stage']);
        $won = $closed->where('stage', Opportunity::WON)->count();

        return ['sales' => round($reservations->sum('sales_pen'), 2), 'bookings' => $reservations->count(), 'margin' => round($reservations->sum('margin_pen'), 2),
            'conversion' => $closed->isEmpty() ? null : round(100 * $won / $closed->count(), 1), 'won' => $won, 'lost' => $closed->count() - $won];
    }

    /** Avance completo de la meta (KPIs, 4 indicadores, proyección, salud y bono estimado). */
    public function progress(SalesTarget $t, bool $details = false): array
    {
        $today = $this->clock->today();
        $total = (int) $t->period_from->diffInDays($t->period_to) + 1;
        $elapsed = max(0, min($total, (int) $t->period_from->diffInDays($today, false) + 1));
        $frozen = $t->status === 'cerrada' && $t->result;
        $reservations = $frozen && ! $details ? null : $this->reservations($t);
        $actual = $frozen ? $t->result : $this->actual($t, $reservations);
        $pipeline = $frozen ? collect() : $this->pipeline($t);
        $weighted = round($pipeline->sum('weighted_pen'), 2);
        $sales = (float) $actual['sales'];
        $ratio = $sales > 0 ? (float) $actual['margin'] / $sales : self::DEFAULT_MARGIN_RATIO;
        $projection = $frozen ? $sales : round($sales + $weighted, 2);
        $goals = $t->goals();
        $pct = fn (?float $value, ?float $goal) => $goal ? round(100 * (float) $value / $goal, 1) : null;
        $salesPct = $pct($sales, $goals['sales']);
        $projectionPct = $pct($projection, $goals['sales']);

        $out = [
            'actual' => ['sales' => round($sales, 2), 'bookings' => (int) $actual['bookings'], 'margin' => round((float) $actual['margin'], 2), 'conversion' => $actual['conversion']],
            'pct' => ['sales' => $salesPct, 'bookings' => $goals['bookings'] ? $pct((float) $actual['bookings'], (float) $goals['bookings']) : null,
                'margin' => $pct((float) $actual['margin'], $goals['margin']), 'conversion' => $actual['conversion'] !== null && $goals['conversion'] ? $pct((float) $actual['conversion'], $goals['conversion']) : null],
            'projection' => ['sales' => $projection, 'pct' => $projectionPct, 'margin' => $frozen ? (float) $actual['margin'] : round($projection * $ratio, 2),
                'pipeline_weighted' => $weighted, 'linear' => $elapsed > 0 ? round($sales / $elapsed * $total, 2) : 0.0],
            'days' => ['total' => $total, 'elapsed' => $elapsed, 'remaining' => $total - $elapsed, 'elapsed_pct' => round(100 * $elapsed / $total, 1)],
            'health' => $health = $this->health($t, $frozen ? $salesPct : $projectionPct),
            'health_label' => self::HEALTH_LABELS[$health],
            'bonus' => $this->bonus($t, $frozen ? $salesPct : $projectionPct),
        ];
        if ($details) {
            $out['reservations'] = $reservations?->values()->all() ?? [];
            $out['pipeline'] = $pipeline->values()->all();
        }

        return $out;
    }

    /** MET-BR-10: tramo de la política sobre el % de ventas. @return array{base: ?float, tier: ?array, amount: float} */
    public function bonus(SalesTarget $t, ?float $pct): array
    {
        $base = $t->scope_type === 'asesor' && $t->bonus_amount !== null ? (float) $t->bonus_amount : null;
        $tier = null;
        foreach (collect($this->policy()->tiers)->sortBy('min') as $row) {
            if ($pct !== null && $pct >= $row['min']) {
                $tier = $row;
            }
        }

        return ['base' => $base, 'tier' => $tier, 'amount' => $base && $tier ? round($base * $tier['pay'] / 100, 2) : 0.0, 'tiers' => array_values($this->policy()->tiers)];
    }

    private function health(SalesTarget $t, ?float $pct): string
    {
        $today = $this->clock->today()->toDateString();

        return match (true) {
            $t->status === 'borrador' => 'borrador',
            $t->status === 'anulada' => 'anulada',
            $t->status === 'cerrada' => ($pct ?? 0) >= 100 ? 'cumplida' : 'no_cumplida',
            $t->period_from->toDateString() > $today => 'por_iniciar',
            $t->period_to->toDateString() < $today => 'por_cerrar',
            ($pct ?? 0) >= 100 => 'en_camino',
            ($pct ?? 0) >= 80 => 'en_riesgo',
            default => 'critico',
        };
    }
}
