<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\Finance\BankLine;
use App\Models\Finance\Invoice;
use App\Models\Finance\Payment;
use App\Models\Finance\PeriodClose;
use App\Models\Finance\PeriodCloseCheck;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Notify;
use Illuminate\Support\Facades\DB;

/** Cierre contable de periodo (§2.2, §4.8, FIN-BR-56…58): 4 controles automáticos y 3 manuales. */
class PeriodCloseService
{
    public const MANUAL = ['fx' => 'TC de cierre SBS registrado', 'aging' => 'Revisión de CxC vencidas y provisión de cobranza dudosa',
        'igv' => 'Registro de ventas conciliado con IGV'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly FinanceIndicators $indicators,
    ) {}

    public static function validPeriod(string $period): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period);
    }

    public function find(string $period): PeriodClose
    {
        if (! self::validPeriod($period)) {
            throw ApiException::notFound();
        }

        return PeriodClose::firstOrCreate(['period' => $period], ['status' => 'abierto']);
    }

    /** Controles con su estado. @return list<array{code: string, label: string, kind: string, ok: bool, detail: string, checked_by?: ?string, checked_at?: ?string}> */
    public function controls(PeriodClose $close): array
    {
        if ($close->status === 'cerrado' && is_array($close->snapshot['controls'] ?? null)) {
            return $close->snapshot['controls'];
        }
        $period = $close->period;
        $observed = Invoice::whereIn('status', ['observado', 'rechazado'])->where('period', $period)->pluck('number');
        $withoutInvoice = $this->indicators->paymentsWithoutInvoice($period);
        $reverted = Payment::where('status', 'revertido')->whereRaw("to_char(reverted_at at time zone 'America/Lima', 'YYYY-MM') = ?", [$period])->get();
        $withoutNc = $reverted->filter(fn (Payment $p) => ! Invoice::where('reservation_id', $p->reservation_id)->where('doc_type', Invoice::CREDIT_NOTE)
            ->whereIn('status', Invoice::COUNTED)->exists());
        $openLines = BankLine::whereNull('closure_id')->where('status', '<>', 'conciliado')->count();
        $checks = $close->checks()->get()->keyBy('check_code');

        $auto = [
            ['code' => 'observed', 'label' => 'Sin comprobantes observados o rechazados', 'ok' => $observed->isEmpty(),
                'detail' => $observed->isEmpty() ? 'Todos aceptados' : 'Pendientes: '.$observed->join(', ')],
            ['code' => 'invoiced', 'label' => 'Todo cobro aplicado con comprobante', 'ok' => $withoutInvoice->isEmpty(),
                'detail' => $withoutInvoice->isEmpty() ? 'Sin cobros por facturar' : $withoutInvoice->count().' cobro(s) sin comprobante: '.$withoutInvoice->pluck('code')->join(', ')],
            ['code' => 'reversals', 'label' => 'Toda reversión con NC de la reserva', 'ok' => $withoutNc->isEmpty(),
                'detail' => $withoutNc->isEmpty() ? 'Sin reversiones pendientes de NC' : 'Reversiones sin NC: '.$withoutNc->pluck('code')->join(', ')],
            ['code' => 'reconciliation', 'label' => 'Conciliación bancaria sin líneas pendientes', 'ok' => $openLines === 0,
                'detail' => $openLines === 0 ? 'Todas las líneas conciliadas' : "{$openLines} línea(s) sin conciliar", 'route' => 'finanzas/conciliacion'],
        ];
        $manual = collect(self::MANUAL)->map(function ($label, $code) use ($checks) {
            $c = $checks[$code] ?? null;

            return ['code' => $code, 'label' => $label, 'ok' => $c !== null, 'detail' => $c ? 'Marcado por '.User::find($c->checked_by)?->name : 'Control manual pendiente',
                'checked_by' => $c ? User::find($c->checked_by)?->name : null, 'checked_at' => $c?->checked_at?->toIso8601String()];
        })->values()->all();

        return [...array_map(fn ($c) => $c + ['kind' => 'auto'], $auto), ...array_map(fn ($c) => $c + ['kind' => 'manual'], $manual)];
    }

    public function present(PeriodClose $close): array
    {
        $controls = $this->controls($close);
        $ok = count(array_filter($controls, fn ($c) => $c['ok']));

        return ['period' => $close->period, 'status' => $close->status, 'closed_at' => $close->closed_at?->toIso8601String(),
            'closed_by' => $close->closed_by ? User::find($close->closed_by)?->name : null, 'lock_version' => $close->lock_version,
            'controls' => $controls, 'done' => $ok, 'total' => count($controls), 'ready' => $ok === count($controls)];
    }

    /** FIN-BR-57: marcar/desmarcar un control manual (solo con periodo abierto). */
    public function toggle(PeriodClose $close, string $code, bool $checked, User $actor): PeriodClose
    {
        if (! isset(self::MANUAL[$code])) {
            throw ApiException::notFound();
        }

        return DB::transaction(function () use ($close, $code, $checked, $actor) {
            $close = PeriodClose::whereKey($close->id)->lockForUpdate()->firstOrFail();
            if ($close->status === 'cerrado') {
                throw ApiException::rule('FIN-BR-57', "El periodo {$close->period} está cerrado.");
            }
            $existing = PeriodCloseCheck::where('period_close_id', $close->id)->where('check_code', $code)->first();
            if ($checked && ! $existing) {
                PeriodCloseCheck::create(['period_close_id' => $close->id, 'check_code' => $code, 'checked_by' => $actor->id, 'checked_at' => now()]);
            } elseif (! $checked && $existing) {
                $existing->delete();
            } else {
                return $close;
            }
            $close->forceFill(['lock_version' => $close->lock_version + 1])->save();
            $this->audit->log('Cierre contable', $close->period, $checked ? 'control_ok' : 'control_reabierto', null, self::MANUAL[$code], actor: $actor);

            return $close;
        });
    }

    /** FIN-BR-56/58: cerrar exige los 7 controles; guarda el snapshot y avisa a Finanzas. */
    public function close(PeriodClose $close, User $actor): PeriodClose
    {
        return DB::transaction(function () use ($close, $actor) {
            $close = PeriodClose::whereKey($close->id)->lockForUpdate()->firstOrFail();
            if ($close->status === 'cerrado') {
                throw ApiException::rule('FIN-BR-56', "El periodo {$close->period} ya está cerrado.");
            }
            $controls = $this->controls($close);
            $pending = array_filter($controls, fn ($c) => ! $c['ok']);
            if ($pending) {
                throw ApiException::unprocessable('FIN-BR-56', 'No se puede cerrar el periodo. Controles pendientes: '.implode('; ', array_map(fn ($c) => $c['label'], $pending)).'.',
                    ['pending' => array_values($pending)]);
            }
            $close->forceFill(['status' => 'cerrado', 'closed_at' => now(), 'closed_by' => $actor->id, 'snapshot' => ['controls' => $controls]])->save();
            $this->audit->log('Cierre contable', $close->period, 'cerrar_periodo', 'Abierto', 'Cerrado · 7/7 controles', actor: $actor);
            foreach (CatalogRecipients::role('finance') as $user) {
                $this->notify->push('contabilidad.cierre', 'email', $user, "Periodo {$close->period} cerrado", 'Contabilidad cerró el periodo: no se emiten comprobantes con fecha del periodo.');
            }

            return $close;
        });
    }
}
