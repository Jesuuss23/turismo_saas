<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Provider;
use App\Models\Finance\BankAccount;
use App\Models\Finance\Payable;
use App\Models\Finance\Payout;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cuentas por pagar y costos (PROC-044/045/046, FIN-BR-18…25): estado derivado, aprobación con advertencias, programación,
 * pagos a proveedores (payouts), registro de costos adicionales con impacto en el margen y vencimiento diario.
 */
class PayableManager
{
    public function __construct(
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly SequenceService $sequences,
        private readonly FxRates $fx,
        private readonly Profitability $profitability,
    ) {}

    /** §4.3 con las decisiones de §11 C06: cancelada → pagada → vencida → parcial → aprobada → abierta. */
    public function deriveStatus(Payable $p): string
    {
        return match (true) {
            $p->status === Payable::CANCELLED => Payable::CANCELLED,
            (float) $p->amount - (float) $p->paid_amount <= Fin::EPS => Payable::PAID,
            $p->due_date->toDateString() < $this->clock->today()->toDateString() => 'vencida',
            (float) $p->paid_amount > Fin::EPS => 'parcial',
            $p->approved_at !== null => 'aprobada',
            default => Payable::OPEN,
        };
    }

    /** Aprobar (FIN-BR-20): advertencias no bloqueantes. @return array{payable: Payable, warnings: list<string>} */
    public function approve(Payable $payable, User $actor): array
    {
        return DB::transaction(function () use ($payable, $actor) {
            $p = $this->lock($payable);
            if ($p->approved_at !== null || ! in_array($p->status, Payable::PAYABLE, true)) {
                throw ApiException::rule('FIN-BR-19', "La CxP {$p->code} ya está ".($p->approved_at ? 'aprobada' : mb_strtolower(Payable::STATUS_LABELS[$p->status])).'.');
            }
            $warnings = $this->warnings($p);
            $before = Payable::STATUS_LABELS[$p->status];
            $p->forceFill(['approved_at' => now(), 'approved_by' => $actor->id]);
            $p->status = $this->deriveStatus($p);
            $p->save();
            $this->audit->log($p->kind === 'costo_adicional' ? 'Costo' : 'Cuenta por pagar', $p->code, 'aprobar', $before,
                Payable::STATUS_LABELS[$p->status].' (aprobada)'.($warnings ? ' · advertencias: '.implode('; ', $warnings) : ''), actor: $actor);
            if ($p->kind === 'costo_adicional' && $p->reservation) {
                $f = $this->profitability->figures($p->reservation);
                $this->audit->log('Rentabilidad', $p->reservation->code, 'impacto_costo', null, "{$p->code} {$p->currency} {$p->amount} · margen {$f['margin_pct']} %", actor: $actor);
            }

            return ['payable' => $p, 'warnings' => $warnings];
        });
    }

    /** @return list<string> */
    public function warnings(Payable $p): array
    {
        $warnings = [];
        if ($p->provider?->status !== 'activo') {
            $warnings[] = "El proveedor {$p->provider?->name} no está activo";
        }
        if ($p->reservation_id && (! $p->reservation || $p->reservation->trashed() || $p->reservation->status === Reservation::CANCELLED)) {
            $warnings[] = 'La reserva asociada no existe o está cancelada';
        }

        return $warnings;
    }

    /** Programar pago (FIN-BR-19/21). */
    public function schedule(Payable $payable, array $data, User $actor): Payable
    {
        return DB::transaction(function () use ($payable, $data, $actor) {
            $p = $this->lock($payable);
            $this->assertApproved($p);
            $date = (string) ($data['scheduled_date'] ?? '');
            if ($date === '' || $date < $this->clock->today()->toDateString()) {
                throw ApiException::invalid('scheduled_date', 'La fecha programada debe ser hoy o posterior.');
            }
            $account = $this->account($data['bank_account_id'] ?? null, $p->currency, true);
            $p->forceFill(['scheduled_date' => $date, 'scheduled_bank_account_id' => $account->id, 'scheduled_by' => $actor->id])->save();
            $this->audit->log('Cuenta por pagar', $p->code, 'programar_pago', null, "{$date} · {$account->label}", actor: $actor);

            return $p;
        });
    }

    /** Registrar pago a proveedor (FIN-BR-22). `$bankLineId` cuando nace de la conciliación (FIN-BR-53). */
    public function payout(Payable $payable, array $data, User $actor, ?string $bankLineId = null): Payout
    {
        return DB::transaction(function () use ($payable, $data, $actor, $bankLineId) {
            $p = $this->lock($payable, $bankLineId === null);
            $this->assertApproved($p);
            $amount = Fin::r2($data['amount'] ?? 0);
            $method = (string) ($data['method'] ?? '');
            $ref = trim((string) ($data['operation_ref'] ?? ''));
            if (! isset(Payout::METHODS[$method])) {
                throw ApiException::invalid('method', 'Seleccione el medio de pago.');
            }
            if ($amount <= 0 || $amount > (float) $p->balance() + Fin::EPS) {
                throw ApiException::unprocessable('FIN-BR-22', "El monto debe ser mayor a 0 y no exceder el saldo ({$p->currency} {$p->balance()}).");
            }
            if ($ref === '') {
                throw ApiException::invalid('operation_ref', 'Indique el N° de operación.');
            }
            if ($p->currency !== 'PEN' && empty($data['fx_rate'])) {
                throw ApiException::invalid('fx_rate', 'Indique el tipo de cambio del pago en USD.');
            }
            $account = $method === 'efectivo_caja_chica' ? null : $this->account($data['bank_account_id'] ?? null, $p->currency, $method !== 'cargo_bancario');
            $paidOn = (string) ($data['paid_on'] ?? $this->clock->today()->toDateString());
            if ($paidOn > $this->clock->today()->toDateString()) {
                throw ApiException::invalid('paid_on', 'La fecha de pago no puede ser futura.');
            }
            $payout = Payout::create(['code' => $this->sequences->next('payout'), 'payable_id' => $p->id, 'paid_on' => $paidOn, 'amount' => Fin::money($amount),
                'fx_rate' => $p->currency === 'PEN' ? null : (string) $data['fx_rate'], 'method' => $method, 'bank_account_id' => $account?->id,
                'operation_ref' => $ref, 'bank_line_id' => $bankLineId, 'paid_by' => $actor->id]);
            $before = Payable::STATUS_LABELS[$p->status];
            $p->paid_amount = Fin::money(Payout::where('payable_id', $p->id)->sum('amount'));
            $p->status = $this->deriveStatus($p);
            if ($p->status === Payable::PAID) {
                $p->forceFill(['scheduled_date' => null, 'scheduled_bank_account_id' => null, 'scheduled_by' => null, 'paid_at' => now()]);
            }
            $p->save();
            $this->audit->log('Cuenta por pagar', $p->code, 'pagar', $before,
                "{$payout->code} · {$p->currency} {$payout->amount} · ".Payout::METHODS[$method]." · op. {$ref} · ".Payable::STATUS_LABELS[$p->status], actor: $actor);
            if ($email = $p->provider?->email) {
                $this->notify->push('pago.proveedor', 'email', $email, "Pago {$payout->code} — {$p->currency} {$payout->amount}",
                    "{$p->code} · {$p->concept} · operación {$ref}. Saldo: {$p->currency} {$p->balance()}.", ['related_type' => 'payable', 'related_id' => $p->id]);
            }

            return $payout;
        });
    }

    /** `POST /costs/preview` (FIN-BR-24): margen antes y después de aprobar el costo. */
    public function previewCost(Reservation $reservation, float $amount, string $currency, ?float $fxRate = null): array
    {
        $fx = $currency === 'PEN' ? 1.0 : ($fxRate ?: $this->fx->rate($currency));
        $before = $this->profitability->figures($reservation);
        $after = $this->profitability->figures($reservation, Fin::r2($amount) * $fx);

        return ['before' => $before, 'after' => $after, 'amount_pen' => Fin::money(Fin::r2($amount) * $fx)];
    }

    /** Registrar costo adicional (PROC-045, FIN-BR-23). */
    public function registerCost(array $data, User $actor): Payable
    {
        $key = trim((string) ($data['reservation_id'] ?? ''));
        $reservation = ($key === '' ? null : Reservation::where(fn ($q) => Str::isUuid($key) ? $q->where('id', $key) : $q->where('code', mb_strtoupper($key)))->first())
            ?? throw ApiException::invalid('reservation_id', 'Seleccione la reserva.');
        if (in_array($reservation->status, [Reservation::CANCELLED, Reservation::DRAFT], true)) {
            throw ApiException::unprocessable('FIN-BR-23', "La reserva {$reservation->code} está ".mb_strtolower(Reservation::STATUS_LABELS[$reservation->status]).': no admite costos.');
        }
        $service = ! empty($data['reservation_service_id']) ? BookedService::where('reservation_id', $reservation->id)->find($data['reservation_service_id']) : null;
        $provider = Provider::find($data['provider_id'] ?? $service?->provider_id) ?? throw ApiException::invalid('provider_id', 'Seleccione el proveedor.');
        if ($provider->status !== 'activo') {
            throw ApiException::unprocessable('FIN-BR-23', "El proveedor {$provider->name} no está activo.");
        }
        if (! isset(Payable::COST_TYPES[$data['cost_type'] ?? ''])) {
            throw ApiException::invalid('cost_type', 'Seleccione el tipo de costo.');
        }
        $amount = Fin::r2($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw ApiException::invalid('amount', 'El monto debe ser mayor a 0.');
        }
        $concept = trim((string) ($data['concept'] ?? ''));
        if ($concept === '') {
            throw ApiException::invalid('concept', 'Describa el costo.');
        }
        $currency = in_array($data['currency'] ?? null, ['PEN', 'USD'], true) ? $data['currency'] : $reservation->currency;

        return DB::transaction(function () use ($data, $actor, $reservation, $service, $provider, $amount, $concept, $currency) {
            $p = Payable::create(['code' => $this->sequences->next('payable'), 'provider_id' => $provider->id, 'reservation_id' => $reservation->id,
                'reservation_service_id' => $service?->id, 'kind' => 'costo_adicional', 'cost_type' => $data['cost_type'], 'concept' => mb_substr($concept, 0, 255),
                'amount' => Fin::money($amount), 'currency' => $currency, 'fx_rate' => $currency === 'PEN' ? null : (string) ($data['fx_rate'] ?? $this->fx->rate($currency)),
                'due_date' => $data['due_date'] ?? $this->clock->today()->addDays(15)->toDateString(), 'status' => Payable::OPEN,
                'supplier_doc_number' => trim((string) ($data['supplier_doc_number'] ?? '')) ?: null, 'evidence_note' => trim((string) ($data['evidence_note'] ?? '')) ?: null]);
            $p->status = $this->deriveStatus($p);
            $p->save();
            $this->audit->log('Costo', $p->code, 'registrar', null, Payable::COST_TYPES[$p->cost_type]." · {$currency} {$p->amount} · {$provider->name} · {$reservation->code}"
                .($service ? " · {$service->code}" : ' · costo general'), actor: $actor);

            return $p;
        });
    }

    /** `fin:mark-overdue` (T21): CxP con saldo y vencimiento pasado → vencida. */
    public function markOverdue(): int
    {
        $count = 0;
        foreach (Payable::whereIn('status', ['abierta', 'aprobada', 'parcial'])->whereDate('due_date', '<', $this->clock->today()->toDateString())->get() as $p) {
            $before = $p->status;
            $p->forceFill(['status' => 'vencida'])->save();
            $this->audit->log('Cuenta por pagar', $p->code, 'vencer', $before, 'vencida', system: true);
            $count++;
        }

        return $count;
    }

    private function lock(Payable $payable, bool $ifMatch = true): Payable
    {
        $p = Payable::whereKey($payable->id)->lockForUpdate()->firstOrFail();
        if ($ifMatch && request()->hasHeader('If-Match')) {
            $p->assertIfMatch(request());
        }

        return $p;
    }

    private function assertApproved(Payable $p): void
    {
        if ($p->approved_at === null) {
            throw ApiException::unprocessable('PROC-046', "PROC-046: Obligación → Aprobación → Pago. Apruebe la CxP {$p->code} antes de programar o pagar.");
        }
        if (! in_array($p->status, Payable::PAYABLE, true)) {
            throw ApiException::rule('FIN-BR-19', "La CxP {$p->code} está ".mb_strtolower(Payable::STATUS_LABELS[$p->status]).'.');
        }
    }

    private function account(?string $id, string $currency, bool $required): ?BankAccount
    {
        $account = $id ? BankAccount::where('active', true)->find($id) : null;
        if (! $account) {
            if ($required) {
                throw ApiException::invalid('bank_account_id', 'Seleccione la cuenta de origen.');
            }

            return null;
        }
        if ($account->currency !== $currency) {
            throw ApiException::invalid('bank_account_id', "La cuenta de origen debe ser en {$currency}.");
        }

        return $account;
    }
}
