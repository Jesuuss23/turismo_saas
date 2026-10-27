<?php

namespace App\Services\Treasury;

use App\Exceptions\ApiException;
use App\Models\Treasury\Account;
use App\Models\Treasury\Movement;
use App\Models\Treasury\TreasurySetting;
use App\Services\Crm\CrmClock;
use App\Services\Finance\FxRates;
use App\Services\SequenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Saldos derivados (opening_balance + Σ movimientos registrados), bloqueo de cuentas y alta de movimientos (spec 12 §3.1, TES-BR-02/17). */
class TreasuryLedger
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly CrmClock $clock,
        private readonly FxRates $fx,
    ) {}

    /** Fecha del tenant a medianoche UTC: comparable con las columnas `date`. */
    public function today(): Carbon
    {
        return Carbon::parse($this->clock->today()->toDateString());
    }

    public function settings(): TreasurySetting
    {
        return TreasurySetting::firstOrCreate([]);
    }

    public function balance(Account $account, ?string $asOf = null): float
    {
        $sum = Movement::where('account_id', $account->id)->where('status', 'posted')
            ->when($asOf, fn ($q) => $q->whereDate('movement_date', '<=', $asOf))
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS s")->value('s');

        return round((float) $account->opening_balance + (float) $sum, 2);
    }

    /** @return array<string, float> saldo por cuenta (id) en una sola consulta. */
    public function balances(?string $asOf = null): array
    {
        $sums = Movement::where('status', 'posted')->when($asOf, fn ($q) => $q->whereDate('movement_date', '<=', $asOf))
            ->groupBy('account_id')->selectRaw("account_id, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) AS s")->pluck('s', 'account_id');

        return Account::withTrashed()->get(['id', 'opening_balance'])
            ->mapWithKeys(fn ($a) => [$a->id => round((float) $a->opening_balance + (float) ($sums[$a->id] ?? 0), 2)])->all();
    }

    /**
     * TES-BR-02: bloquea las cuentas (orden por id, sin interbloqueos) dentro de la transacción en curso.
     *
     * @return Collection<string, Account>
     */
    public function lock(string ...$ids): Collection
    {
        sort($ids);

        return Account::whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /** TES-BR-02: un egreso no puede dejar saldo negativo (bancos y cajas; la pasarela es de tránsito). */
    public function assertCovers(Account $account, float $amount, string $what = 'El egreso'): void
    {
        $balance = $this->balance($account);
        if ($amount > $balance + 0.004) {
            throw ApiException::unprocessable('TES-BR-02', sprintf('%s de %s %s deja %s en sobregiro (saldo %s %s).', $what, $account->currency,
                number_format($amount, 2), $account->code, $account->currency, number_format($balance, 2)), ['balance' => $balance]);
        }
    }

    /** Alta de un movimiento con correlativo y TC (TES-BR-17: las cuentas USD guardan el TC de la operación). */
    public function post(Account $account, array $attributes): Movement
    {
        $date = $attributes['movement_date'] ?? $this->today()->toDateString();
        $fx = $attributes['fx_rate'] ?? null;
        if ($account->currency !== 'PEN' && ! $fx) {
            $fx = $this->fx->rate($account->currency, $date instanceof Carbon ? $date->toDateString() : (string) $date);
        }

        return Movement::create($attributes + [
            'code' => $this->sequences->next('treasury_movement'), 'account_id' => $account->id, 'movement_date' => $date,
            'currency' => $account->currency, 'fx_rate' => $account->currency === 'PEN' ? null : $fx, 'sede_id' => $account->sede_id, 'status' => 'posted',
        ]);
    }

    /** Monto en la moneda de la cuenta a partir de otra moneda (TC de la operación o del día). */
    public function convert(float $amount, string $from, string $to, ?float $rate = null, ?string $date = null): array
    {
        if ($from === $to) {
            return [round($amount, 2), null];
        }
        $usd = $from === 'USD' ? $from : $to;
        $r = $rate ?: (float) $this->fx->rate($usd, $date);

        return [round($from === 'USD' ? $amount * $r : $amount / $r, 2), $r];
    }

    public function rate(string $currency, ?string $date = null): float
    {
        return (float) $this->fx->rate($currency, $date);
    }

    public function transaction(callable $fn): mixed
    {
        return DB::transaction($fn);
    }
}
