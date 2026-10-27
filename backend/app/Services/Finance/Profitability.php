<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Finance\Commission;
use App\Models\Finance\Payable;
use App\Models\Finance\Payment;
use App\Models\Finance\Receivable;
use App\Models\Finance\Settlement;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rentabilidad y liquidación (PROC-048, FIN-BR-31…38). Todo en PEN al TC conservado de cada documento (T22): la reserva para
 * ingresos y costos de servicio, la CxP para costos adicionales, la comisión para comisiones.
 */
class Profitability
{
    public const ACTIVE = [Reservation::CONFIRMED, Reservation::PENDING_PAYMENT, Reservation::PENDING_PROVIDER, Reservation::PENDING_AVAILABILITY,
        Reservation::RESCHEDULED, Reservation::IN_OPERATION, Reservation::COMPLETED];

    public function __construct(
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    public static function fxOf(Reservation $r): float
    {
        return $r->currency === 'PEN' ? 1.0 : (float) $r->fx_rate;
    }

    /** FIN-BR-33 unificado: < 15 % rojo, 15–25 % ámbar, ≥ 25 % verde. */
    public static function light(float $pct): string
    {
        return $pct < 15 ? 'r' : ($pct < 25 ? 'a' : 'g');
    }

    /** FIN-BR-32 por reserva. `$extraAmountPen` simula un costo adicional aprobado (preview de FIN-BR-24). */
    public function figures(Reservation $r, float $extraAmountPen = 0): array
    {
        $fx = self::fxOf($r);
        $income = (float) $r->total_amount * $fx;
        $serviceCost = (float) BookedService::where('reservation_id', $r->id)->whereNotIn('status', [BookedService::CANCELLED, BookedService::REJECTED])->sum('cost_amount') * $fx;
        $extra = Payable::where('reservation_id', $r->id)->where('kind', 'costo_adicional')->whereNotNull('approved_at')->where('status', '<>', Payable::CANCELLED)->get()
            ->sum(fn (Payable $p) => (float) $p->amount * ($p->currency === 'PEN' ? 1 : (float) ($p->fx_rate ?? $fx))) + $extraAmountPen;
        $commissions = Commission::where('reservation_id', $r->id)->get()
            ->sum(fn (Commission $c) => (float) $c->amount * ($c->currency === 'PEN' ? 1 : (float) ($c->fx_rate ?? $fx)));
        $cost = $serviceCost + $extra;
        $gross = $income - $cost;
        $pct = $income > 0 ? $gross / $income * 100 : 0;

        return ['income' => Fin::money($income), 'service_cost' => Fin::money($serviceCost), 'extra_cost' => Fin::money($extra), 'cost' => Fin::money($cost),
            'gross' => Fin::money($gross), 'commissions' => Fin::money($commissions), 'net' => Fin::money($gross - $commissions),
            'margin_pct' => round($pct, 1), 'light' => self::light($pct)];
    }

    /** @return Collection<int, Reservation> */
    public function universe(array $filters = []): Collection
    {
        return Reservation::whereNotIn('status', [Reservation::DRAFT, Reservation::CANCELLED])
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('travel_from', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('travel_from', '<=', $d))
            ->with(['client:id,code,name', 'owner:id,name'])->orderByDesc('travel_from')->get();
    }

    /** `GET /profitability/reservations`. */
    public function rows(array $filters = []): array
    {
        $settled = Settlement::pluck('reservation_id')->flip();

        return $this->universe($filters)->map(fn (Reservation $r) => ['code' => $r->code, 'title' => $r->title, 'client' => $r->client?->name,
            'owner' => $r->owner?->name, 'channel' => $r->channel, 'status' => $r->status, 'status_label' => Reservation::STATUS_LABELS[$r->status],
            'travel_from' => $r->travel_from->toDateString(), 'currency' => $r->currency, 'settled' => $settled->has($r->id)] + $this->figures($r))
            ->when($filters['light'] ?? null, fn ($rows, $l) => $rows->where('light', $l))->values()->all();
    }

    /** `GET /profitability`: KPIs, serie mensual y cortes por producto, vendedor y canal. */
    public function overview(array $filters = []): array
    {
        $reservations = $this->universe($filters);
        $rows = $reservations->map(fn (Reservation $r) => ['r' => $r] + $this->figures($r));
        $sum = fn (Collection $set, string $k) => $set->sum(fn ($x) => (float) $x[$k]);
        $kpis = function (Collection $set) use ($sum) {
            $income = $sum($set, 'income');
            $gross = $sum($set, 'gross');

            return ['count' => $set->count(), 'income' => Fin::money($income), 'cost' => Fin::money($sum($set, 'cost')), 'gross' => Fin::money($gross),
                'commissions' => Fin::money($sum($set, 'commissions')), 'net' => Fin::money($sum($set, 'net')),
                'margin_pct' => $income > 0 ? round($gross / $income * 100, 1) : 0, 'light' => self::light($income > 0 ? $gross / $income * 100 : 0)];
        };
        $group = fn (callable $key) => $rows->groupBy($key)->map(fn ($set, $label) => ['label' => (string) $label] + $kpis($set))
            ->sortByDesc(fn ($x) => (float) $x['gross'])->values()->all();

        // FIN-BR-34: margen por producto = Σ (precio − costo) de servicios con producto, en PEN al TC de la reserva.
        $byProduct = BookedService::whereIn('reservation_id', $reservations->pluck('id'))->whereNotNull('product_id')
            ->whereNotIn('status', [BookedService::CANCELLED, BookedService::REJECTED])->with('product:id,name')->get()
            ->groupBy('product_id')->map(function (Collection $services) use ($reservations) {
                $fx = fn (BookedService $s) => self::fxOf($reservations->firstWhere('id', $s->reservation_id));
                $price = $services->sum(fn (BookedService $s) => (float) $s->price_amount * $fx($s));
                $cost = $services->sum(fn (BookedService $s) => (float) $s->cost_amount * $fx($s));

                return ['label' => (string) $services->first()->product?->name, 'income' => Fin::money($price), 'cost' => Fin::money($cost),
                    'gross' => Fin::money($price - $cost), 'margin_pct' => $price > 0 ? round(($price - $cost) / $price * 100, 1) : 0,
                    'count' => $services->pluck('reservation_id')->unique()->count()];
            })->sortByDesc(fn ($x) => (float) $x['gross'])->values()->all();

        $today = $this->clock->today();
        $months = collect(range(5, 0))->map(fn ($i) => $today->copy()->startOfMonth()->subMonths($i)->format('Y-m'));
        $monthly = $months->map(fn ($m) => ['month' => $m] + $kpis($rows->filter(fn ($x) => $x['r']->travel_from->format('Y-m') === $m)))->all();

        return ['kpis' => $kpis($rows), 'monthly' => $monthly, 'by_product' => $byProduct,
            'by_seller' => $group(fn ($x) => $x['r']->owner?->name ?? '—'), 'by_channel' => $group(fn ($x) => $x['r']->channel),
            'lights' => ['r' => $rows->where('light', 'r')->count(), 'a' => $rows->where('light', 'a')->count(), 'g' => $rows->where('light', 'g')->count()]];
    }

    /** FIN-BR-36: checklist de liquidación. @return list<array{key: string, label: string, ok: bool, detail: string}> */
    public function checklist(Reservation $r): array
    {
        $receivables = Receivable::where('reservation_id', $r->id)->where('status', '<>', 'cancelada')->get();
        $payables = Payable::where('reservation_id', $r->id)->where('status', '<>', Payable::CANCELLED)->get();
        $costs = $payables->where('kind', 'costo_adicional');
        $commissions = Commission::where('reservation_id', $r->id)->get();
        $unpaid = $receivables->where('status', '<>', 'pagada');
        $owed = $payables->filter(fn (Payable $p) => (float) $p->balance() > Fin::EPS);
        $unapproved = $costs->whereNull('approved_at');
        $pendingCommissions = $commissions->where('status', 'calculada');

        return [
            ['key' => 'receivables', 'label' => 'Cobros completos', 'ok' => $receivables->isNotEmpty() && $unpaid->isEmpty(),
                'detail' => $receivables->isEmpty() ? 'La reserva no tiene cuentas por cobrar' : ($unpaid->isEmpty() ? 'Todas las CxC pagadas' : 'CxC pendientes: '.$unpaid->pluck('code')->join(', '))],
            ['key' => 'payables', 'label' => 'Proveedores pagados', 'ok' => $owed->isEmpty(),
                'detail' => $owed->isEmpty() ? 'Sin saldos con proveedores' : 'CxP con saldo: '.$owed->pluck('code')->join(', ')],
            ['key' => 'costs', 'label' => 'Costos adicionales aprobados', 'ok' => $unapproved->isEmpty(),
                'detail' => $unapproved->isEmpty() ? ($costs->isEmpty() ? 'Sin costos adicionales' : 'Todos aprobados') : 'Sin aprobar: '.$unapproved->pluck('code')->join(', ')],
            ['key' => 'commissions', 'label' => 'Comisiones aprobadas o pagadas', 'ok' => $commissions->isNotEmpty() && $pendingCommissions->isEmpty(),
                'detail' => $commissions->isEmpty() ? 'Comisiones sin calcular' : ($pendingCommissions->isEmpty() ? 'Comisiones aprobadas/pagadas' : 'Por aprobar: '.$pendingCommissions->pluck('code')->join(', '))],
            ['key' => 'completed', 'label' => 'Reserva completada', 'ok' => $r->status === Reservation::COMPLETED,
                'detail' => 'Estado: '.Reservation::STATUS_LABELS[$r->status]],
        ];
    }

    /** `GET /reservations/{code}/settlement`: estado de resultados, flujo de caja, cobros, costos, comisiones y checklist. */
    public function settlement(Reservation $r): array
    {
        $fx = self::fxOf($r);
        $collected = Payment::where('reservation_id', $r->id)->where('status', 'aplicado')->where('kind', '<>', 'reembolso')->get()
            ->sum(fn (Payment $p) => (float) $p->amount * ($p->currency === 'PEN' ? 1 : (float) ($p->fx_rate ?? $fx)));
        $refunds = Payment::where('reservation_id', $r->id)->where('kind', 'reembolso')->whereNotIn('status', ['revertido', 'rechazado'])->get()
            ->sum(fn (Payment $p) => (float) $p->amount * ($p->currency === 'PEN' ? 1 : (float) ($p->fx_rate ?? $fx)));
        $payables = Payable::where('reservation_id', $r->id)->with(['provider:id,name', 'payouts'])->orderBy('due_date')->get();
        $paidOut = $payables->sum(fn (Payable $p) => $p->payouts->sum(fn ($o) => (float) $o->amount * ($p->currency === 'PEN' ? 1 : (float) ($o->fx_rate ?? $p->fx_rate ?? $fx))));
        $settlement = Settlement::where('reservation_id', $r->id)->first();
        $checklist = $this->checklist($r);

        return ['reservation' => ['id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'status' => $r->status, 'status_label' => Reservation::STATUS_LABELS[$r->status],
            'client' => $r->client?->name, 'client_email' => $r->client?->email, 'owner' => $r->owner?->name, 'channel' => $r->channel, 'currency' => $r->currency,
            'fx_rate' => $r->currency === 'PEN' ? null : (string) $r->fx_rate, 'total' => $r->total_amount, 'travel_from' => $r->travel_from->toDateString(),
            'travel_to' => $r->travel_to->toDateString(), 'lock_version' => $r->lock_version],
            'figures' => $this->figures($r),
            'cash' => ['collected' => Fin::money($collected), 'refunds' => Fin::money($refunds), 'paid_out' => Fin::money($paidOut), 'net' => Fin::money($collected - $refunds - $paidOut)],
            'receivables' => Receivable::where('reservation_id', $r->id)->orderBy('due_date')->get()->map(fn (Receivable $c) => ['code' => $c->code, 'concept' => $c->concept,
                'currency' => $c->currency, 'amount' => $c->amount, 'paid' => $c->paid_amount, 'balance' => $c->balance(), 'due_date' => $c->due_date->toDateString(),
                'status' => $c->status, 'status_label' => Receivable::STATUS_LABELS[$c->status]])->all(),
            'payables' => $payables->map(fn (Payable $p) => ['code' => $p->code, 'concept' => $p->concept, 'provider' => $p->provider?->name, 'kind' => $p->kind,
                'currency' => $p->currency, 'amount' => $p->amount, 'paid' => $p->paid_amount, 'balance' => $p->balance(), 'approved' => $p->approved_at !== null,
                'status' => $p->status, 'status_label' => Payable::STATUS_LABELS[$p->status]])->all(),
            'commissions' => Commission::where('reservation_id', $r->id)->get()->map(fn (Commission $c) => ['code' => $c->code, 'kind' => $c->kind,
                'beneficiary' => $c->beneficiaryName(), 'currency' => $c->currency, 'amount' => $c->amount, 'status' => $c->status,
                'status_label' => Commission::STATUS_LABELS[$c->status]])->all(),
            'checklist' => $checklist, 'ready' => collect($checklist)->every('ok'),
            'settlement' => $settlement ? ['closed_at' => $settlement->closed_at->toIso8601String(), 'closed_by' => User::find($settlement->closed_by)?->name,
                'income_pen' => $settlement->income_pen, 'cost_pen' => $settlement->cost_pen, 'commissions_pen' => $settlement->commissions_pen,
                'net_pen' => $settlement->net_pen, 'gross_margin_pct' => $settlement->gross_margin_pct] : null];
    }

    /** Cerrar liquidación (FIN-BR-36/37). */
    public function settle(Reservation $reservation, User $actor): Settlement
    {
        return DB::transaction(function () use ($reservation, $actor) {
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if (Settlement::where('reservation_id', $r->id)->exists()) {
                throw ApiException::rule('FIN-BR-37', "La reserva {$r->code} ya está liquidada: no se re-liquida.");
            }
            $checklist = $this->checklist($r);
            $pending = collect($checklist)->where('ok', false);
            if ($pending->isNotEmpty()) {
                throw ApiException::unprocessable('FIN-BR-36', 'No se puede liquidar: '.$pending->map(fn ($c) => "{$c['label']} ({$c['detail']})")->join('; ').'.',
                    ['pending' => $pending->values()->all()]);
            }
            $f = $this->figures($r);
            $detail = $this->settlement($r);
            $settlement = Settlement::create(['reservation_id' => $r->id, 'closed_at' => now(), 'closed_by' => $actor->id, 'income_pen' => $f['income'],
                'cost_pen' => $f['cost'], 'commissions_pen' => $f['commissions'], 'net_pen' => $f['net'], 'gross_margin_pct' => $f['margin_pct'],
                'snapshot' => ['checklist' => $checklist, 'figures' => $f, 'cash' => $detail['cash'], 'receivables' => $detail['receivables'],
                    'payables' => $detail['payables'], 'commissions' => $detail['commissions']]]);
            $this->audit->log('Reserva', $r->code, 'liquidar', null, "Liquidada · ingreso PEN {$f['income']} · costo {$f['cost']} · comisiones {$f['commissions']} · utilidad {$f['net']} ({$f['margin_pct']} %)", actor: $actor);
            if ($r->owner) {
                $this->notify->push('reserva.liquidada', 'portal', $r->owner, "Reserva {$r->code} liquidada", "Utilidad neta PEN {$f['net']} · margen {$f['margin_pct']} %",
                    ['related_type' => 'reservation', 'related_id' => $r->id]);
            }

            return $settlement;
        });
    }

    /** Estado de cuenta al cliente (FIN-BR-38). */
    public function accountStatement(Reservation $r, User $actor): array
    {
        $email = $r->client?->email ?? throw ApiException::unprocessable('FIN-BR-38', 'El cliente no tiene email registrado.');
        $open = Receivable::where('reservation_id', $r->id)->whereIn('status', Receivable::OPEN)->get();
        $balance = Fin::money($open->sum(fn (Receivable $c) => (float) $c->balance()));
        $lines = $open->map(fn (Receivable $c) => "{$c->code} · {$c->concept} · saldo {$c->currency} {$c->balance()} · vence {$c->due_date->format('d/m/Y')}")->join("\n");
        $this->notify->push('estado_cuenta.enviado', 'email', $email, "Estado de cuenta {$r->code} — saldo {$r->currency} {$balance}", $lines ?: 'Sin saldos pendientes.',
            ['client_id' => $r->client_id, 'related_type' => 'reservation', 'related_id' => $r->id]);
        $this->audit->log('Reserva', $r->code, 'enviar_estado_cuenta', null, "Enviado a {$email} · saldo {$r->currency} {$balance}", actor: $actor);

        return ['email' => $email, 'balance' => $balance, 'currency' => $r->currency];
    }
}
