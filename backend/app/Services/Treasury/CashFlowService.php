<?php

namespace App\Services\Treasury;

use App\Models\Finance\Commission;
use App\Models\Finance\Payable;
use App\Models\Finance\Receivable;
use App\Models\Treasury\Account;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Flujo de caja proyectado (spec 12 §2 «Flujo»): saldo consolidado de hoy + CxC por cobrar − CxP por pagar − comisiones − egresos
 * fijos, por horizontes 0–30/31–60/61–90 y por semana (13). Todo en soles al TC del día. Cache de 5 minutos (JOB-14 la precalienta).
 */
class CashFlowService
{
    public function __construct(private readonly TreasuryLedger $ledger, private readonly TenantContext $tenant) {}

    public function cached(int $horizon = 90, bool $overdue = true, bool $fixed = true): array
    {
        $key = sprintf('treasury:flow:%s:%d:%d:%d:%s', $this->tenant->id(), $horizon, $overdue, $fixed, $this->ledger->today()->toDateString());

        return Cache::remember($key, 300, fn () => $this->project($horizon, $overdue, $fixed));
    }

    public function forget(): void
    {
        foreach ([30, 60, 90] as $h) {
            foreach ([[1, 1], [1, 0], [0, 1], [0, 0]] as [$o, $f]) {
                Cache::forget(sprintf('treasury:flow:%s:%d:%d:%d:%s', $this->tenant->id(), $h, $o, $f, $this->ledger->today()->toDateString()));
            }
        }
    }

    public function project(int $horizon = 90, bool $overdue = true, bool $fixed = true): array
    {
        $horizon = in_array($horizon, [30, 60, 90], true) ? $horizon : 90;
        $today = $this->ledger->today();
        $end = $today->copy()->addDays($horizon);
        $usd = $this->ledger->rate('USD');
        $pen = fn ($amount, $currency) => round((float) $amount * ($currency === 'USD' ? $usd : 1), 2);

        $balances = $this->ledger->balances();
        $start = round(Account::get()->sum(fn ($a) => $pen($balances[$a->id] ?? 0, $a->currency)), 2);
        $items = [];
        $place = function (?Carbon $date) use ($today, $end, $overdue): ?Carbon {
            if (! $date) {
                return null;
            }
            if ($date->lt($today)) {
                return $overdue ? $today->copy() : null;
            }

            return $date->gt($end) ? null : $date;
        };

        foreach (Receivable::whereIn('status', Receivable::OPEN)->with('client:id,name')->get() as $r) {
            $date = $place($r->due_date ? Carbon::parse($r->due_date->toDateString()) : null);
            if ($date && (float) $r->balance() > 0) {
                $items[] = ['date' => $date->toDateString(), 'kind' => 'CxC', 'direction' => 'in', 'ref' => $r->code, 'party' => $r->client?->name,
                    'amount_pen' => $pen($r->balance(), $r->currency), 'overdue' => $r->due_date->lt($today)];
            }
        }
        foreach (Payable::whereIn('status', Payable::PAYABLE)->with('provider:id,name')->get() as $p) {
            $raw = $p->scheduled_date ?? $p->due_date;
            $date = $place($raw ? Carbon::parse(Carbon::parse($raw)->toDateString()) : null);
            if ($date && (float) $p->balance() > 0) {
                $items[] = ['date' => $date->toDateString(), 'kind' => 'CxP', 'direction' => 'out', 'ref' => $p->code, 'party' => $p->provider?->name,
                    'amount_pen' => $pen($p->balance(), $p->currency), 'overdue' => Carbon::parse($raw)->lt($today)];
            }
        }
        // Comisiones calculadas o aprobadas: se pagan con la planilla / transferencia de fin de mes.
        $payday = $today->copy()->endOfMonth();
        foreach (Commission::whereIn('status', ['calculada', 'aprobada'])->get() as $c) {
            $items[] = ['date' => $payday->toDateString(), 'kind' => 'Comisión', 'direction' => 'out', 'ref' => $c->code, 'party' => $c->beneficiaryName(),
                'amount_pen' => $pen($c->amount, $c->currency), 'overdue' => false];
        }
        if ($fixed) {
            foreach ((array) $this->ledger->settings()->recurring_outflows as $f) {
                $day = max(1, min(28, (int) ($f['day'] ?? 1)));
                for ($month = $today->copy()->startOfMonth(); $month->lte($end); $month->addMonth()) {
                    $date = $month->copy()->day($day);
                    if ($date->gte($today) && $date->lte($end)) {
                        $items[] = ['date' => $date->toDateString(), 'kind' => 'Egreso fijo', 'direction' => 'out', 'ref' => null, 'party' => (string) ($f['concept'] ?? 'Egreso fijo'),
                            'amount_pen' => round((float) ($f['amount'] ?? 0), 2), 'overdue' => false];
                    }
                }
            }
        }
        usort($items, fn ($a, $b) => [$a['date'], $a['direction']] <=> [$b['date'], $b['direction']]);

        $buckets = [];
        $running = $start;
        foreach ([[0, 30], [31, 60], [61, 90]] as [$from, $to]) {
            if ($from > $horizon) {
                break;
            }
            $in = $out = 0.0;
            foreach ($items as $i) {
                $d = $today->diffInDays(Carbon::parse($i['date']));
                if ($d >= $from && $d <= $to) {
                    $i['direction'] === 'in' ? $in += $i['amount_pen'] : $out += $i['amount_pen'];
                }
            }
            $running = round($running + $in - $out, 2);
            $buckets[] = ['label' => "{$from}–{$to} días", 'in' => round($in, 2), 'out' => round($out, 2), 'net' => round($in - $out, 2), 'balance' => $running];
        }
        $weeks = [];
        $running = $start;
        for ($w = 0; $w < 13; $w++) {
            $from = $today->copy()->addDays($w * 7);
            $to = $from->copy()->addDays(6);
            $in = $out = 0.0;
            foreach ($items as $i) {
                if ($i['date'] >= $from->toDateString() && $i['date'] <= $to->toDateString()) {
                    $i['direction'] === 'in' ? $in += $i['amount_pen'] : $out += $i['amount_pen'];
                }
            }
            $running = round($running + $in - $out, 2);
            $weeks[] = ['label' => $from->format('d/m'), 'in' => round($in, 2), 'out' => round($out, 2), 'balance' => $running];
        }
        $by = fn (string $kind) => round(collect($items)->where('kind', $kind)->sum('amount_pen'), 2);

        return [
            'as_of' => $today->toDateString(), 'horizon' => $horizon, 'include_overdue' => $overdue, 'include_fixed' => $fixed, 'fx_usd' => $usd,
            'opening_pen' => $start, 'buckets' => $buckets, 'weeks' => $weeks,
            'totals' => ['receivables' => $by('CxC'), 'payables' => $by('CxP'), 'commissions' => $by('Comisión'), 'fixed' => $by('Egreso fijo'),
                'ending' => round($start + $by('CxC') - $by('CxP') - $by('Comisión') - $by('Egreso fijo'), 2)],
            'projected_30' => $buckets[0]['balance'] ?? $start,
            'min_week' => collect($weeks)->sortBy('balance')->first(),
            'items' => $items,
        ];
    }
}
