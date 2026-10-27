<?php

namespace App\Services\Inventory;

use App\Models\Finance\Payable;
use App\Models\Inventory\Allotment;
use App\Models\Inventory\AllotmentCharge;
use App\Models\Inventory\AllotmentDay;
use App\Services\AuditLogger;
use App\Services\Finance\FxRates;
use App\Services\Finance\PayableService;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;

/**
 * Job `ReleaseAllotments` (JOB-10, CUP-BR-009/010): libera fechas con release vencido no retenidas, cierra las fechas pasadas
 * (cargo por lo no usado ni liberado → CxP al proveedor si el monto es > 0) y vence contratos. Idempotente: `closed_at` y el
 * UNIQUE de `catalog_allotment_charges` impiden duplicar.
 */
class ReleaseAllotments
{
    public function __construct(
        private readonly AllotmentService $allotments,
        private readonly AuditLogger $audit,
        private readonly SequenceService $sequences,
        private readonly FxRates $fx,
    ) {}

    /** @return array{units: int, dates: int, charges: int, expired: int, summary: string} */
    public function run(): array
    {
        $today = $this->allotments->today();
        $r = ['units' => 0, 'dates' => 0, 'charges' => 0, 'expired' => 0];
        foreach (Allotment::with('provider')->whereIn('status', [...Allotment::LIVE, 'vencido', 'liberado'])->orderBy('code')->get() as $a) {
            if ($a->isLive()) {
                [$units, $dates] = $this->releaseDue($a, $today->toDateString());
                $r['units'] += $units;
                $r['dates'] += $dates;
            }
            $r['charges'] += $this->closePast($a, $today->toDateString());
            if ($a->isLive() && $a->valid_to->lt($today)) {
                Allotment::whereKey($a->id)->update(['status' => 'vencido', 'updated_at' => now()]);
                $this->allotments->event($a, 'Contrato vencido: terminó la vigencia', null);
                $this->audit->log('Cupo', $a->code, 'vencer', Allotment::STATUS_LABELS[$a->status], 'Vencido', system: true);
                $r['expired']++;
            } else {
                $this->allotments->resolveStatus($a);
            }
        }
        $r['summary'] = "{$r['units']} unidades liberadas en {$r['dates']} fechas".($r['charges'] ? " · {$r['charges']} cargo(s) por no-uso" : '')
            .($r['expired'] ? " · {$r['expired']} contrato(s) vencido(s)" : '');

        return $r;
    }

    /** @return array{0: int, 1: int} unidades y fechas liberadas */
    private function releaseDue(Allotment $a, string $today): array
    {
        $units = 0;
        $dates = [];
        DB::transaction(function () use ($a, $today, &$units, &$dates) {
            $due = AllotmentDay::where('allotment_id', $a->id)->whereDate('service_date', '>=', $today)->whereDate('release_deadline', '<=', $today)
                ->whereNull('retained_reason')->whereRaw('blocked_qty - released_qty - used_qty > 0')->lockForUpdate()->get();
            foreach ($due as $day) {
                $free = $day->available();
                $day->forceFill(['released_qty' => $day->released_qty + $free])->save();
                $units += $free;
                $dates[] = $day->service_date->format('d/m');
            }
        });
        if ($units > 0) {
            $list = implode(', ', array_slice($dates, 0, 8)).(count($dates) > 8 ? '…' : '');
            $this->allotments->event($a, "Release automático: {$units} {$this->allotments->unit($a)} en ".count($dates)." fecha(s) ({$list})", null);
            $this->audit->log('Cupo', $a->code, 'release_automatico', null, "{$units} unidades · ".count($dates)." fecha(s) · {$list}", system: true);
            $this->allotments->notifyProvider($a, 'cupo.release', "Release de cupo {$a->code}", "{$a->name}: liberamos {$units} {$this->allotments->unit($a)} en ".count($dates)." fecha(s) ({$list}).");
        }

        return [$units, count($dates)];
    }

    /** CUP-BR-009/010: fechas pasadas sin cerrar; lo disponible no liberado genera cargo y CxP. */
    private function closePast(Allotment $a, string $today): int
    {
        $charges = 0;
        foreach (AllotmentDay::where('allotment_id', $a->id)->whereDate('service_date', '<', $today)->whereNull('closed_at')->orderBy('service_date')->get() as $day) {
            DB::transaction(function () use ($a, $day, &$charges) {
                $day = AllotmentDay::whereKey($day->id)->lockForUpdate()->first();
                if (! $day || $day->closed_at !== null) {
                    return;
                }
                $qty = max(0, $day->available());
                if ($qty > 0 && ! AllotmentCharge::where('allotment_day_id', $day->id)->exists()) {
                    $this->charge($a, $day, $qty);
                    $charges++;
                }
                $day->forceFill(['closed_at' => now()])->save();
            });
        }

        return $charges;
    }

    private function charge(Allotment $a, AllotmentDay $day, int $qty): AllotmentCharge
    {
        $amount = round($qty * (float) $a->net_rate * (float) $a->penalty_pct / 100, 2);
        $fx = $a->currency === 'PEN' ? null : $this->fx->rate($a->currency);
        $date = $day->service_date->format('d/m/Y');
        $reason = $day->retained_reason ? "No usado tras retener: {$day->retained_reason}" : 'No usado ni liberado antes del servicio';
        $payable = null;
        if ($amount > 0) {
            $payable = Payable::create(['code' => $this->sequences->next('payable'), 'provider_id' => $a->provider_id, 'kind' => 'costo_adicional',
                'cost_type' => 'penalidad_proveedor', 'concept' => mb_substr("Penalidad por no-uso de cupo {$a->code} · {$date} · {$qty} {$this->allotments->unit($a)}", 0, 200),
                'amount' => number_format($amount, 2, '.', ''), 'currency' => $a->currency, 'fx_rate' => $fx,
                'due_date' => PayableService::dueDate($this->allotments->today(), $a->provider?->payment_terms)->toDateString(), 'status' => Payable::OPEN]);
            $this->audit->log('Cuenta por pagar', $payable->code, 'crear', null, "{$a->currency} {$payable->amount} · {$a->provider?->name} · penalidad {$a->code} {$date}", system: true);
        }
        $charge = AllotmentCharge::create(['allotment_day_id' => $day->id, 'qty' => $qty, 'amount' => number_format($amount, 2, '.', ''), 'currency' => $a->currency,
            'fx_rate' => $fx, 'reason' => mb_substr($reason, 0, 200), 'payable_id' => $payable?->id]);
        $this->allotments->event($a, "Cargo por no-uso del {$date}: {$qty} {$this->allotments->unit($a)} · {$a->currency} ".number_format($amount, 2, '.', '')
            .($payable ? " · CxP {$payable->code}" : ' · sin penalidad'), null);

        return $charge;
    }
}
