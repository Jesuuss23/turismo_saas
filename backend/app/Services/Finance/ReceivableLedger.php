<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentApplication;
use App\Models\Finance\Receivable;
use App\Models\Finance\ReceivableReminder;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Bookings\ReceivableService;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use Illuminate\Support\Facades\DB;

/**
 * Cuentas por cobrar (PROC-040/041, FIN-BR-01…06): estado derivado, saldo recalculado desde las aplicaciones vigentes,
 * sincronización de la reserva, anticipos, recordatorios y vencimiento diario.
 */
class ReceivableLedger
{
    public function __construct(
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly FxRates $fx,
    ) {}

    /** §4.1: cancelada (almacenado) → pagada → vencida → parcial → abierta. */
    public function deriveStatus(Receivable $r): string
    {
        if ($r->status === 'cancelada') {
            return 'cancelada';
        }
        $balance = (float) $r->amount - (float) $r->paid_amount;

        return match (true) {
            $balance <= Fin::EPS => 'pagada',
            $r->due_date->toDateString() < $this->clock->today()->toDateString() => 'vencida',
            (float) $r->paid_amount > Fin::EPS => 'parcial',
            default => 'abierta',
        };
    }

    /** FIN-BR-01: `paid_amount` = Σ aplicaciones vigentes de pagos aplicados (nunca el valor enviado). */
    public function recalc(Receivable $r, ?User $actor, string $action = 'actualizar_saldo'): Receivable
    {
        $paid = (float) PaymentApplication::where('receivable_id', $r->id)->whereNull('reversed_at')
            ->whereIn('payment_id', Payment::where('status', 'aplicado')->select('id'))->sum('amount');
        $before = "pagado {$r->currency} ".Fin::money($r->paid_amount)." · {$r->status}";
        $r->paid_amount = Fin::money(min($paid, (float) $r->amount));
        $r->status = $this->deriveStatus($r);
        if ($r->isDirty()) {
            $r->save();
            $this->audit->log('Cuenta por cobrar', $r->code, $action, $before, "pagado {$r->currency} {$r->paid_amount} · {$r->status}", actor: $actor, system: $actor === null);
        }

        return $r;
    }

    /** FIN-BR-15 / §4.9: `pendiente_pago → confirmada` cuando todas sus CxC no canceladas (al menos una) quedan pagadas. */
    public function syncReservation(?Reservation $reservation, ?User $actor): void
    {
        if (! $reservation || $reservation->status !== Reservation::PENDING_PAYMENT) {
            return;
        }
        $open = $reservation->receivables()->where('status', '<>', 'cancelada')->get();
        if ($open->isNotEmpty() && $open->every(fn (Receivable $r) => $r->status === 'pagada')) {
            $reservation->forceFill(['status' => Reservation::CONFIRMED])->save();
            $this->audit->log('Reserva', $reservation->code, 'cambio_estado', Reservation::STATUS_LABELS[Reservation::PENDING_PAYMENT], 'Confirmada (obligaciones pagadas)', actor: $actor);
        }
    }

    /** `GET /reservations/{code}/account`: total, comprometido en CxC no canceladas y disponible para anticipo (FIN-BR-03). */
    public function account(Reservation $reservation): array
    {
        $committed = (float) $reservation->receivables()->where('status', '<>', 'cancelada')->sum('amount');
        $available = max(0, (float) $reservation->total_amount - $committed);

        return ['reservation' => $reservation->code, 'title' => $reservation->title, 'status' => $reservation->status, 'currency' => $reservation->currency,
            'total' => Fin::money($reservation->total_amount), 'committed' => Fin::money($committed), 'available' => Fin::money($available),
            'suggested' => Fin::money(min($available, Fin::r2((float) $reservation->total_amount * 0.5))), 'fx_rate' => $this->fx->rate($reservation->currency),
            'client_email' => $reservation->client?->email, 'due_date' => $this->clock->today()->addDays(3)->toDateString(),
            'blocked' => in_array($reservation->status, [Reservation::CANCELLED, Reservation::COMPLETED], true)];
    }

    /** PROC-041 Solicitar anticipo (FIN-BR-02/03/04). */
    public function advance(Reservation $reservation, array $data, User $actor): Receivable
    {
        if (in_array($reservation->status, [Reservation::CANCELLED, Reservation::COMPLETED, Reservation::DRAFT], true)) {
            throw ApiException::unprocessable('FIN-BR-02', "La reserva {$reservation->code} está ".mb_strtolower(Reservation::STATUS_LABELS[$reservation->status]).': no admite anticipos.');
        }
        $amount = Fin::r2($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw ApiException::invalid('amount', 'El importe del anticipo debe ser mayor a 0.');
        }
        if (empty($data['due_date'])) {
            throw ApiException::invalid('due_date', 'Indique el vencimiento.');
        }

        return DB::transaction(function () use ($reservation, $data, $actor, $amount) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $account = $this->account($reservation);
            if ($amount > (float) $account['available'] + Fin::EPS) {
                throw ApiException::unprocessable('PROC-041', "PROC-041: el anticipo excede el saldo no comprometido de la reserva ({$reservation->currency} {$account['available']}).");
            }
            $pct = (float) $reservation->total_amount > 0 ? round($amount / (float) $reservation->total_amount * 100) : 0;
            $concept = trim((string) ($data['concept'] ?? '')) ?: "Anticipo {$pct}% — {$reservation->title}";
            $receivable = app(ReceivableService::class)->create($reservation, $concept, Fin::money($amount), $data['due_date'], $actor, 'anticipo solicitado por Finanzas', 'anticipo',
                $reservation->currency === 'PEN' ? null : ($data['fx_rate'] ?? $this->fx->rate($reservation->currency)), 'crear_anticipo');
            if (filter_var($data['notify'] ?? true, FILTER_VALIDATE_BOOLEAN) && ($email = $reservation->client?->email)) {
                $subject = "Solicitud de anticipo {$receivable->code} — {$receivable->currency} {$receivable->amount}, vence {$receivable->due_date->format('d/m/Y')}";
                $context = ['client_id' => $reservation->client_id, 'related_type' => 'receivable', 'related_id' => $receivable->id];
                $this->notify->push('pago.solicitud', 'email', $email, $subject, $concept, $context);
                $this->notify->push('pago.solicitud', 'portal', $email, $subject, $concept, $context);
            }

            return $receivable;
        });
    }

    /** FIN-BR-05: recordatorio por email (+ WhatsApp si el cliente tiene teléfono). `$actor` null = scheduler. */
    public function remind(Receivable $r, ?User $actor): ReceivableReminder
    {
        if (! in_array($r->status, Receivable::OPEN, true)) {
            throw ApiException::rule('FIN-BR-05', "La CxC {$r->code} está ".mb_strtolower(Receivable::STATUS_LABELS[$r->status]).': no requiere recordatorio.');
        }
        $client = $r->client;
        $channels = $client?->phone ? 'email+whatsapp' : 'email';
        $subject = "Recordatorio de pago {$r->code} — saldo {$r->currency} {$r->balance()}, vence {$r->due_date->format('d/m/Y')}";
        $context = ['client_id' => $r->client_id, 'related_type' => 'receivable', 'related_id' => $r->id];
        if ($client?->email) {
            $this->notify->push('pago.recordatorio', 'email', $client->email, $subject, $r->concept, $context);
        }
        if ($client?->phone) {
            $this->notify->push('pago.recordatorio', 'whatsapp', $client->phone, $subject, $r->concept, $context);
        }
        $reminder = ReceivableReminder::create(['receivable_id' => $r->id, 'channels' => $channels, 'sent_by' => $actor?->id, 'sent_at' => now(), 'balance_at_send' => $r->balance()]);
        $r->forceFill(['last_reminder_at' => now()])->save();
        $this->audit->log('Cuenta por cobrar', $r->code, 'recordatorio', null, "{$channels} · saldo {$r->currency} {$r->balance()}", actor: $actor, system: $actor === null);

        return $reminder;
    }

    /** `fin:mark-overdue` (T21): CxC abiertas o parciales con vencimiento pasado → vencida; no toca pagadas ni canceladas. */
    public function markOverdue(): int
    {
        $count = 0;
        foreach (Receivable::whereIn('status', ['abierta', 'parcial'])->whereDate('due_date', '<', $this->clock->today()->toDateString())->get() as $r) {
            $before = $r->status;
            $r->forceFill(['status' => 'vencida'])->save();
            $this->audit->log('Cuenta por cobrar', $r->code, 'vencer', $before, 'vencida', system: true);
            $count++;
        }

        return $count;
    }
}
