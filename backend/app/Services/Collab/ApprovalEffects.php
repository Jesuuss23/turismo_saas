<?php

namespace App\Services\Collab;

use App\Exceptions\ApiException;
use App\Models\Bookings\CancelRequest;
use App\Models\Bookings\Reservation;
use App\Models\Channel\B2bAgency;
use App\Models\Collab\ApprovalRequest;
use App\Models\Collab\B2bCreditException;
use App\Models\Finance\Commission;
use App\Models\Finance\Payable;
use App\Models\Finance\Payment;
use App\Models\Iam\User;
use App\Models\Quotes\InternalApproval;
use App\Models\Quotes\QuoteVersion;
use App\Models\Supplier\RateRequest;
use App\Services\AuditLogger;
use App\Services\Bookings\CancellationService;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Channels\B2bCredit;
use App\Services\Crm\CrmClock;
use App\Services\Finance\CommissionService;
use App\Services\Finance\Fin;
use App\Services\Finance\PayableManager;
use App\Services\Finance\PaymentService;
use App\Services\Notify;
use App\Services\Quotes\QuoteAccess;
use App\Services\Quotes\QuotePolicy;
use App\Services\Quotes\QuoteWorkflow;
use App\Services\SequenceService;
use App\Services\Supplier\RateRequests;
use Closure;

/**
 * Efecto en el registro de origen (§4.3) con los mismos servicios de dominio que usan los módulos (Regla 18).
 * Mientras corre, `OriginSync` no cierra la solicitud como «resuelta en origen»: la cierra la propia bandeja.
 */
class ApprovalEffects
{
    private static bool $applying = false;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly SequenceService $sequences,
        private readonly CrmClock $clock,
    ) {}

    public static function applying(): bool
    {
        return self::$applying;
    }

    /** Ejecuta `$fn` con la sincronización desde el origen en pausa. */
    public static function guarded(Closure $fn): mixed
    {
        $previous = self::$applying;
        self::$applying = true;
        // El If-Match del request es el lock_version de la solicitud, no el del registro de origen (que se bloquea en su servicio).
        $headers = app()->bound('request') ? request()->headers : null;
        $ifMatch = $headers?->get('If-Match');
        $headers?->remove('If-Match');
        try {
            return $fn();
        } finally {
            self::$applying = $previous;
            if ($ifMatch !== null) {
                $headers->set('If-Match', $ifMatch);
            }
        }
    }

    /** APR-BR-05: validaciones previas que no dependen del nivel (se ejecutan antes de registrar el paso). */
    public function precheckApprove(ApprovalRequest $r, User $actor): void
    {
        if ($r->request_type === 'quote') {
            $version = $this->version($r);
            if (QuotePolicy::overCap((string) $version->discount_pct) && ! $actor->checkPermissionTo(QuoteAccess::DISCOUNT_OVERRIDE)) {
                throw ApiException::unprocessable('APR-BR-05', 'Un descuento mayor a '.QuotePolicy::MAX_DISCOUNT.' % solo se aprueba con quotes.discount_override');
            }
        }
    }

    /** Aprobación del último nivel. */
    public function approve(ApprovalRequest $r, User $actor, ?string $note): void
    {
        $note = trim((string) $note) ?: 'Aprobada desde la bandeja de aprobaciones';
        self::guarded(fn () => match ($r->request_type) {
            'quote' => $this->quote($r, 'approve', $note, $actor),
            'cancel' => $this->cancelApprove($r, $actor),
            'reversal' => $this->reversalApprove($r, $actor),
            'payable' => $this->payableApprove($r, $actor),
            'commission' => $this->commissionApprove($r, $actor),
            'provider_rate' => app(RateRequests::class)->approve($this->rateRequest($r), $note, $actor),
            'credit_b2b' => $this->creditApprove($r, $actor),
            'refund' => $this->refundApprove($r, $actor),
            default => null,
        });
    }

    public function reject(ApprovalRequest $r, User $actor, string $note): void
    {
        self::guarded(fn () => match ($r->request_type) {
            'quote' => $this->quote($r, 'reject', $note, $actor),
            'cancel' => app(CancellationService::class)->rejectRequest($this->reservation($r), $this->cancelRequest($r), $note, $actor),
            'reversal' => $this->reversalClose($r, $actor, 'rechazar_solicitud_reversion', "Solicitud de reversión rechazada · {$note}"),
            'payable' => $this->observe(Payable::class, 'Cuenta por pagar', $r, $actor, $note),
            'commission' => $this->observe(Commission::class, 'Comisión', $r, $actor, $note),
            'provider_rate' => app(RateRequests::class)->reject($this->rateRequest($r), $note, $actor),
            default => null,
        });
    }

    /** Retiro del solicitante: marca la solicitud de origen anulada/retirada. */
    public function withdraw(ApprovalRequest $r, User $actor): void
    {
        self::guarded(function () use ($r, $actor) {
            switch ($r->request_type) {
                case 'quote':
                    $approval = InternalApproval::where('quote_version_id', $r->subject_id)->where('status', InternalApproval::PENDING)->lockForUpdate()->first();
                    $approval?->forceFill(['status' => InternalApproval::VOIDED, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => 'Retirada por el solicitante'])->save();
                    $this->audit->log('Cotización', (string) $r->subject_code, 'anular_solicitud_aprobacion', 'Pendiente', "Retirada · v{$r->subject_version}", actor: $actor);
                    break;
                case 'cancel':
                    $request = $this->cancelRequest($r);
                    if ($request->status === 'pendiente') {
                        $request->forceFill(['status' => 'retirada', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => 'Retirada por el solicitante'])->save();
                    }
                    $this->audit->log('Reserva', (string) $r->subject_code, 'retirar_solicitud_anulacion', $request->reason, 'Retirada', actor: $actor);
                    break;
                case 'reversal':
                    $this->reversalClose($r, $actor, 'retirar_solicitud_reversion', 'Solicitud de reversión retirada');
                    break;
            }
        });
    }

    /** Reenvío de CxP/comisión observada: nueva ronda y sin observación. @return int ronda nueva */
    public function resubmit(ApprovalRequest $r, User $actor): int
    {
        $model = $r->request_type === 'payable' ? Payable::class : Commission::class;
        $record = $model::whereKey($r->subject_id)->lockForUpdate()->first() ?? throw ApiException::rule('APR-BR-08', 'El registro de origen ya no existe');
        $pending = $record instanceof Payable ? $record->approved_at === null && in_array($record->status, Payable::PAYABLE, true) : $record->status === 'calculada';
        if (! $pending) {
            throw ApiException::rule('APR-BR-08', "{$record->code} ya no está pendiente de aprobación");
        }
        $round = (int) $record->approval_round + 1;
        self::guarded(fn () => $record->forceFill(['approval_round' => $round, 'observation' => null])->save());
        $this->audit->log($record instanceof Payable ? 'Cuenta por pagar' : 'Comisión', $record->code, 'reenviar_aprobacion', 'Observada', "Ronda {$round}", actor: $actor);

        return $round;
    }

    // ── Por tipo ────────────────────────────────────────────────────────

    private function quote(ApprovalRequest $r, string $decision, string $note, User $actor): void
    {
        $version = $this->version($r);
        app(QuoteWorkflow::class)->decideApproval($version->quote, $version, $decision, $note, $actor);
    }

    private function cancelApprove(ApprovalRequest $r, User $actor): void
    {
        $request = $this->cancelRequest($r);
        if ($request->status !== 'pendiente') {
            throw ApiException::rule('APR-BR-08', 'La solicitud de anulación ya fue resuelta en la reserva');
        }
        [$reason, $detail] = array_pad(explode(': ', (string) $request->reason, 2), 2, null);
        app(CancellationService::class)->cancel($this->reservation($r), $reason ?: 'Solicitud de anulación', $detail ?: (string) $request->reason, $actor);
    }

    private function reversalApprove(ApprovalRequest $r, User $actor): void
    {
        $payment = $this->payment($r);
        $reverted = app(PaymentService::class)->revert($payment, (string) ($r->justification ?: $r->title), $actor);
        $reverted->forceFill(['reversal_request_id' => null])->save();
    }

    private function reversalClose(ApprovalRequest $r, User $actor, string $action, string $note): void
    {
        $payment = $this->payment($r);
        if ($payment->reversal_request_id === $r->id) {
            $payment->forceFill(['reversal_request_id' => null])->save();
        }
        Fin::history('payment', $payment->id, $payment->status, $note, $actor);
        $this->audit->log('Pago', $payment->code, $action, 'Reversión solicitada', $note, actor: $actor);
        $this->notifyRequester($r, 'pago.reversion', "{$payment->code}: {$note}");
    }

    private function payableApprove(ApprovalRequest $r, User $actor): void
    {
        $payable = Payable::find($r->subject_id) ?? throw ApiException::rule('APR-BR-08', 'La cuenta por pagar ya no existe');
        $result = app(PayableManager::class)->approve($payable, $actor);
        $result['payable']->forceFill(['observation' => null])->save();
    }

    private function commissionApprove(ApprovalRequest $r, User $actor): void
    {
        $commission = Commission::find($r->subject_id) ?? throw ApiException::rule('APR-BR-08', 'La comisión ya no existe');
        app(CommissionService::class)->approve($commission, $actor)->forceFill(['observation' => null])->save();
    }

    /** CxP/comisión: los módulos no tenían «rechazar» → queda observada (§11). */
    private function observe(string $model, string $entity, ApprovalRequest $r, User $actor, string $note): void
    {
        $record = $model::whereKey($r->subject_id)->lockForUpdate()->first() ?? throw ApiException::rule('APR-BR-08', 'El registro de origen ya no existe');
        $record->forceFill(['observation' => $note])->save();
        $this->audit->log($entity, $record->code, 'observar', null, "Observada (ronda {$record->approval_round}) · {$note}", actor: $actor);
    }

    private function creditApprove(ApprovalRequest $r, User $actor): void
    {
        $credit = app(B2bCredit::class);
        $agency = $credit->lock(B2bAgency::find($r->subject_id) ?? throw ApiException::rule('APR-BR-08', 'La agencia ya no existe'));
        if ($agency->credit_currency !== $r->currency) {
            throw ApiException::rule('APR-BR-08', "La línea de crédito de {$agency->name} está en {$agency->credit_currency}");
        }
        $before = (string) $agency->credit_limit;
        $agency->credit_limit = Fin::money((float) $agency->credit_limit + (float) $r->amount);
        $reactivated = $agency->block_reason === 'credito_excedido' && ! $agency->exceeded();
        if ($reactivated) {
            $agency->forceFill(['status' => 'activa', 'block_reason' => null]);
        }
        $agency->save();
        B2bCreditException::create(['agency_id' => $agency->id, 'approval_request_id' => $r->id, 'amount' => $r->amount, 'limit_before' => $before,
            'limit_after' => $agency->credit_limit, 'valid_until' => $r->origin_snapshot['valid_until'] ?? null, 'created_by' => $actor->id, 'created_at' => now()]);
        $this->audit->log('Agencia B2B', $agency->code, 'ampliar_credito', "{$agency->credit_currency} {$before}",
            "{$agency->credit_currency} {$agency->credit_limit} · {$r->code}".($reactivated ? ' · agencia reactivada' : ''), actor: $actor);
        if ($agency->booking_email) {
            $this->notify->push('b2b.credito', 'email', $agency->booking_email, "Ampliación de crédito aprobada · {$agency->name}",
                "Su nueva línea de crédito es {$agency->credit_currency} {$agency->credit_limit}.", ['related_type' => 'b2b_agency', 'related_id' => $agency->id, 'client_id' => $agency->client_id]);
        }
    }

    private function refundApprove(ApprovalRequest $r, User $actor): void
    {
        $reservation = $this->reservation($r);
        $payment = Payment::create([
            'code' => $this->sequences->next('payment'), 'receivable_id' => null, 'reservation_id' => $reservation->id, 'client_id' => $reservation->client_id,
            'method' => 'Reembolso (mismo medio de pago)', 'amount' => $r->amount, 'currency' => $r->currency,
            'fx_rate' => $r->currency === 'PEN' ? null : (string) $r->fx_rate, 'paid_on' => $this->clock->today()->toDateString(),
            'status' => 'pendiente', 'reference' => "Reembolso aprobado {$r->code}", 'kind' => 'reembolso', 'source' => 'sistema', 'registered_by' => $actor->id,
        ]);
        Fin::history('payment', $payment->id, 'pendiente', "Reembolso aprobado en {$r->code}", $actor);
        $this->audit->log('Pago', $payment->code, 'crear_reembolso', null, "{$reservation->code} · {$payment->currency} {$payment->amount} · {$r->code}", actor: $actor);
        foreach (CatalogRecipients::role('finance') as $user) {
            $this->notify->push('pago.reembolso', 'portal', $user, "Reembolso por procesar {$payment->code}", "{$reservation->code} · {$payment->currency} {$payment->amount}",
                ['client_id' => $reservation->client_id, 'related_type' => 'reservation', 'related_id' => $reservation->id]);
        }
    }

    // ── Registros de origen ─────────────────────────────────────────────

    public function version(ApprovalRequest $r): QuoteVersion
    {
        return QuoteVersion::with('quote')->find($r->subject_id) ?? throw ApiException::rule('APR-BR-08', 'La versión de la cotización ya no existe');
    }

    public function reservation(ApprovalRequest $r): Reservation
    {
        return Reservation::find($r->subject_id) ?? throw ApiException::rule('APR-BR-08', 'La reserva ya no existe');
    }

    public function cancelRequest(ApprovalRequest $r): CancelRequest
    {
        return CancelRequest::find($r->origin_snapshot['cancel_request_id'] ?? null) ?? throw ApiException::rule('APR-BR-08', 'La solicitud de anulación ya no existe');
    }

    public function payment(ApprovalRequest $r): Payment
    {
        return Payment::find($r->subject_id) ?? throw ApiException::rule('APR-BR-08', 'El pago ya no existe');
    }

    public function rateRequest(ApprovalRequest $r): RateRequest
    {
        return RateRequest::find($r->subject_id) ?? throw ApiException::rule('APR-BR-08', 'La solicitud de tarifa ya no existe');
    }

    private function notifyRequester(ApprovalRequest $r, string $event, string $subject): void
    {
        if ($r->requester_id && ($user = User::find($r->requester_id))) {
            $this->notify->push($event, 'portal', $user, $subject, null, ['related_type' => 'approval', 'related_id' => $r->id]);
        }
    }
}
