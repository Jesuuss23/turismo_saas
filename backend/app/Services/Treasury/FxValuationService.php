<?php

namespace App\Services\Treasury;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Models\Treasury\Account;
use App\Models\Treasury\FxRevaluation;
use App\Models\Treasury\Movement;
use App\Services\AuditLogger;
use App\Services\SequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Valorización de saldos USD por costo promedio ponderado (spec 12 TES-BR-18): las entradas suman al TC de la operación y las
 * salidas descargan al promedio. Diferencia realizada = monto × (TC operación − promedio); no realizada = saldo × TC cierre − valor contable.
 * Cada revaluación registrada lleva el valor contable al TC de cierre.
 */
class FxValuationService
{
    public function __construct(
        private readonly TreasuryLedger $ledger,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    /** Libro de valorización de una cuenta USD hasta `$asOf`: saldo, valor contable, promedio y diferencias realizadas. */
    public function book(Account $account, ?string $asOf = null): array
    {
        $units = (float) $account->opening_balance;
        $value = $units * (float) ($account->opening_rate ?: $this->ledger->rate('USD', $account->opening_date?->toDateString()));
        $realized = [];
        $revaluations = FxRevaluation::where('account_id', $account->id)->orderBy('revaluation_date')->get();
        $rows = Movement::where('account_id', $account->id)->where('status', 'posted')
            ->when($asOf, fn ($q) => $q->whereDate('movement_date', '<=', $asOf))->orderBy('movement_date')->orderBy('created_at')->get();
        $events = $rows->map(fn ($m) => ['date' => $m->movement_date->toDateString(), 'm' => $m])
            ->concat($revaluations->filter(fn ($r) => ! $asOf || $r->revaluation_date->toDateString() <= $asOf)
                ->map(fn ($r) => ['date' => $r->revaluation_date->toDateString().' 99', 'r' => $r]))
            ->sortBy('date')->values();

        foreach ($events as $e) {
            if (isset($e['r'])) {
                $value = $units * (float) $e['r']->close_rate;

                continue;
            }
            $m = $e['m'];
            $amount = (float) $m->amount;
            $rate = (float) ($m->fx_rate ?: $this->ledger->rate('USD', $m->movement_date->toDateString()));
            if ($m->direction === 'in') {
                $units += $amount;
                $value += $amount * $rate;
            } else {
                $avg = $units > 0 ? $value / $units : $rate;
                $diff = round($amount * ($rate - $avg), 2);
                $realized[] = ['date' => $m->movement_date->toDateString(), 'code' => $m->code, 'description' => $m->description, 'amount_usd' => round($amount, 2),
                    'rate' => round($rate, 6), 'avg_rate' => round($avg, 6), 'diff_pen' => $diff];
                $value -= $amount * $avg;
                $units -= $amount;
            }
        }

        return ['balance_usd' => round($units, 2), 'book_value_pen' => round($value, 2), 'book_rate' => $units > 0 ? round($value / $units, 6) : 0.0, 'realized' => $realized];
    }

    /** GET /treasury/fx/revaluation-preview?close_rate=…&period=YYYY-MM */
    public function preview(?float $closeRate, ?string $period = null): array
    {
        $period = $this->period($period);
        $closeRate = $closeRate && $closeRate > 0 ? $closeRate : $this->ledger->rate('USD', $this->periodEnd($period));
        $rows = [];
        foreach ($this->usdAccounts() as $account) {
            $book = $this->book($account, $this->periodEnd($period));
            $closeValue = round($book['balance_usd'] * $closeRate, 2);
            $rows[] = ['account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name], 'balance_usd' => $book['balance_usd'],
                'book_rate' => $book['book_rate'], 'book_value_pen' => $book['book_value_pen'], 'close_value_pen' => $closeValue,
                'diff_pen' => round($closeValue - $book['book_value_pen'], 2),
                'realized' => array_values(array_filter($book['realized'], fn ($r) => str_starts_with($r['date'], $period))),
                'registered' => FxRevaluation::where('account_id', $account->id)->where('period', $period)->value('code')];
        }

        return ['period' => $period, 'close_rate' => round($closeRate, 6), 'rows' => $rows, 'diff_pen' => round(array_sum(array_column($rows, 'diff_pen')), 2),
            'realized_pen' => round(array_sum(array_map(fn ($r) => array_sum(array_column($r['realized'], 'diff_pen')), $rows)), 2),
            'registered' => collect($rows)->contains(fn ($r) => $r['registered'] !== null)];
    }

    /** POST /treasury/fx/revaluations: una por cuenta USD y periodo (TES-BR-16, 409 la segunda vez). */
    public function register(array $data, User $actor): array
    {
        $closeRate = (float) ($data['close_rate'] ?? 0);
        if ($closeRate <= 0 || $closeRate > 10) {
            throw ApiException::invalid('close_rate', 'Indique el tipo de cambio de cierre.');
        }
        $period = $this->period($data['period'] ?? null);
        if ($period > $this->ledger->today()->format('Y-m')) {
            throw ApiException::invalid('period', 'El periodo no puede ser futuro.');
        }
        $preview = $this->preview($closeRate, $period);
        if ($preview['registered']) {
            throw ApiException::conflict('TES-BR-16', "La diferencia cambiaria de {$period} ya está registrada.");
        }
        $date = min($this->periodEnd($period), $this->ledger->today()->toDateString());
        $created = DB::transaction(function () use ($preview, $period, $closeRate, $date) {
            $out = [];
            foreach ($preview['rows'] as $row) {
                try {
                    $out[] = FxRevaluation::create(['code' => $this->sequences->next('fx_revaluation'), 'account_id' => $row['account']['id'], 'period' => $period,
                        'revaluation_date' => $date, 'balance_usd' => $row['balance_usd'], 'book_rate' => $row['book_rate'] ?: $closeRate, 'close_rate' => $closeRate,
                        'book_value_pen' => $row['book_value_pen'], 'diff_pen' => $row['diff_pen']]);
                } catch (QueryException) {
                    throw ApiException::conflict('TES-BR-16', "La diferencia cambiaria de {$period} ya está registrada.");
                }
            }

            return $out;
        });
        foreach ($created as $r) {
            $this->audit->log('Diferencia cambiaria', $r->code, 'registrar', null,
                "{$period} · {$r->balance_usd} USD · TC libro {$r->book_rate} → cierre {$r->close_rate} · ".($r->diff_pen >= 0 ? 'ganancia (776)' : 'pérdida (676)')." S/ {$r->diff_pen}", actor: $actor);
        }

        return ['period' => $period, 'codes' => collect($created)->pluck('code')->all(), 'diff_pen' => $preview['diff_pen']];
    }

    public function usdAccounts()
    {
        return Account::where('currency', 'USD')->where('kind', '<>', 'gateway')->orderBy('code')->get();
    }

    private function period(?string $period): string
    {
        if ($period === null || $period === '') {
            return $this->ledger->today()->format('Y-m');
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw ApiException::invalid('period', 'Periodo inválido (AAAA-MM).');
        }

        return $period;
    }

    private function periodEnd(string $period): string
    {
        return Carbon::parse("{$period}-01")->endOfMonth()->toDateString();
    }
}
