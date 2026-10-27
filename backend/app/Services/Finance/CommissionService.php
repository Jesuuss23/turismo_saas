<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Channel\B2bAgency;
use App\Models\Crm\Client;
use App\Models\Finance\Commission;
use App\Models\Finance\CommissionRule;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Comisiones de vendedores y agencias B2B (PROC-047, FIN-BR-26…30). */
class CommissionService
{
    /** FIN-BR-27: estados de reserva que generan comisión. */
    public const ELIGIBLE = [Reservation::CONFIRMED, Reservation::IN_OPERATION, Reservation::COMPLETED, Reservation::PENDING_PAYMENT,
        Reservation::PENDING_PROVIDER, Reservation::PENDING_AVAILABILITY, Reservation::RESCHEDULED];

    public function __construct(
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly SequenceService $sequences,
    ) {}

    /**
     * FIN-BR-26: regla vigente para la reserva.
     *
     * @return array{kind: string, user_id: ?string, agency: ?B2bAgency, rule: ?CommissionRule, description: string, base: string, pct: string, amount: string}
     */
    public function ruleFor(Reservation $r): array
    {
        $today = $this->clock->today()->toDateString();
        $rules = CommissionRule::where('active', true)->whereDate('valid_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today))->get()->keyBy('condition');
        $client = $r->client;
        $agency = $r->channel === 'B2B' && $client ? B2bAgency::where('client_id', $client->id)->orWhere('id', $client->b2b_agency_id)->first() : null;
        $total = (float) $r->total_amount;
        if ($agency) {
            $rule = $rules['b2b_convenio'] ?? null;
            $pct = (float) $agency->commission_pct;

            return $this->result('agencia_b2b', null, $agency, $rule, "Agencia B2B {$agency->name}: {$pct} % del convenio sobre el total", $total, $pct);
        }
        $corporate = in_array($r->channel, ['Corporativo', 'Grupos'], true) || in_array($client?->segment_code, ['corporativo', 'grupos'], true);
        $rule = $rules[$corporate ? 'corporativo_grupos' : 'directo_ecommerce'] ?? null;
        $pct = (float) ($rule?->pct ?? ($corporate ? 2.5 : 3));
        $base = $r->currency === 'PEN' ? $total / 1.18 : $total;
        $label = ($corporate ? 'Corporativo / grupos' : 'Venta directa / e-commerce')." {$pct} % sobre ".($r->currency === 'PEN' ? 'neto sin IGV' : 'total (exportación, sin IGV)');

        return $this->result('vendedor', $r->owner_id, null, $rule, $label, $base, $pct);
    }

    /** "Calcular comisiones pendientes": una por (reserva, tipo); idempotente (FIN-BR-27, T14). @return list<Commission> */
    public function calculatePending(User $actor): array
    {
        $created = [];
        $reservations = Reservation::whereIn('status', self::ELIGIBLE)->with('client')->orderBy('created_at')->get();
        foreach ($reservations as $r) {
            $rule = $this->ruleFor($r);
            if (Commission::where('reservation_id', $r->id)->where('kind', $rule['kind'])->exists()) {
                continue;
            }
            try {
                $created[] = DB::transaction(function () use ($r, $rule, $actor) {
                    $c = Commission::create(['code' => $this->sequences->next('commission'), 'reservation_id' => $r->id, 'kind' => $rule['kind'],
                        'beneficiary_user_id' => $rule['user_id'], 'beneficiary_agency_id' => $rule['agency']?->id, 'rule_id' => $rule['rule']?->id,
                        'rule_description' => $rule['description'], 'base_amount' => $rule['base'], 'pct' => $rule['pct'], 'amount' => $rule['amount'],
                        'currency' => $r->currency, 'fx_rate' => $r->currency === 'PEN' ? null : $r->fx_rate, 'status' => 'calculada']);
                    Fin::history('commission', $c->id, 'calculada', $rule['description'], $actor);
                    $this->audit->log('Comisión', $c->code, 'calcular', null, "{$r->code} · {$c->beneficiaryName()} · {$c->currency} {$c->amount} ({$c->pct} %)", actor: $actor);

                    return $c;
                });
            } catch (UniqueConstraintViolationException) {
                continue; // Otro cálculo simultáneo ya la creó.
            }
        }

        return $created;
    }

    public function approve(Commission $commission, User $actor): Commission
    {
        return DB::transaction(function () use ($commission, $actor) {
            $c = $this->lock($commission);
            if ($c->status !== 'calculada') {
                throw ApiException::rule('FIN-BR-28', "Solo se aprueba una comisión calculada ({$c->code} está ".mb_strtolower(Commission::STATUS_LABELS[$c->status]).').');
            }
            $c->forceFill(['status' => 'aprobada', 'approved_at' => now(), 'approved_by' => $actor->id])->save();
            Fin::history('commission', $c->id, 'aprobada', null, $actor);
            $this->audit->log('Comisión', $c->code, 'aprobar', 'Calculada', "Aprobada · {$c->currency} {$c->amount}", actor: $actor);

            return $c;
        });
    }

    public function pay(Commission $commission, array $data, User $actor): Commission
    {
        return DB::transaction(function () use ($commission, $data, $actor) {
            $c = $this->lock($commission);
            if ($c->status !== 'aprobada') {
                throw ApiException::rule('FIN-BR-28', "Solo se paga una comisión aprobada ({$c->code} está ".mb_strtolower(Commission::STATUS_LABELS[$c->status]).').');
            }
            $method = (string) ($data['method'] ?? '');
            $reference = trim((string) ($data['reference'] ?? ''));
            if (! in_array($method, Commission::PAY_METHODS[$c->kind], true)) {
                throw ApiException::invalid('method', 'Forma de pago no válida para '.($c->kind === 'vendedor' ? 'un vendedor' : 'una agencia B2B').'.');
            }
            if ($reference === '') {
                throw ApiException::invalid('reference', 'Indique la referencia del pago.');
            }
            $c->forceFill(['status' => 'pagada', 'paid_at' => now(), 'payment_method' => $method, 'payment_ref' => $reference])->save();
            Fin::history('commission', $c->id, 'pagada', "{$method} · {$reference}", $actor);
            $this->audit->log('Comisión', $c->code, 'pagar', 'Aprobada', "Pagada · {$method} · {$reference}", actor: $actor);
            $email = $c->kind === 'vendedor' ? $c->user?->email : Client::find($c->agency?->client_id)?->email;
            if ($email) {
                $this->notify->push('comision.pagada', 'email', $email, "Comisión {$c->code} pagada — {$c->currency} {$c->amount}",
                    "Reserva {$c->reservation?->code} · {$method} · {$reference}", ['related_type' => 'commission', 'related_id' => $c->id]);
            }

            return $c;
        });
    }

    /** FIN-BR-29: recalcular con la regla vigente; prohibido si pagada; si estaba aprobada vuelve a calculada. */
    public function recalculate(Commission $commission, User $actor): Commission
    {
        return DB::transaction(function () use ($commission, $actor) {
            $c = $this->lock($commission);
            if ($c->status === 'pagada') {
                throw ApiException::unprocessable('FIN-BR-29', "La comisión {$c->code} ya está pagada: registre un ajuste en el siguiente periodo.");
            }
            $rule = $this->ruleFor($c->reservation);
            $before = "{$c->currency} {$c->amount} ({$c->pct} %) · ".Commission::STATUS_LABELS[$c->status];
            $c->forceFill(['rule_id' => $rule['rule']?->id, 'rule_description' => $rule['description'], 'base_amount' => $rule['base'], 'pct' => $rule['pct'],
                'amount' => $rule['amount'], 'status' => 'calculada', 'approved_at' => null, 'approved_by' => null])->save();
            Fin::history('commission', $c->id, 'calculada', 'Recalculada con la regla vigente', $actor);
            $this->audit->log('Comisión', $c->code, 'recalcular', $before, "{$c->currency} {$c->amount} ({$c->pct} %) · Calculada", actor: $actor);

            return $c;
        });
    }

    /** FIN-BR-30: diferencia contra la regla vigente (alerta si > 0,05). */
    public function drift(Commission $c): array
    {
        $rule = $this->ruleFor($c->reservation);
        $diff = Fin::r2((float) $rule['amount'] - (float) $c->amount);

        return ['expected' => $rule['amount'], 'expected_pct' => $rule['pct'], 'rule' => $rule['description'], 'difference' => Fin::money($diff), 'alert' => abs($diff) > 0.05];
    }

    private function result(string $kind, ?string $userId, ?B2bAgency $agency, ?CommissionRule $rule, string $description, float $base, float $pct): array
    {
        $base = Fin::r2($base);

        return ['kind' => $kind, 'user_id' => $userId, 'agency' => $agency, 'rule' => $rule, 'description' => $description,
            'base' => Fin::money($base), 'pct' => number_format($pct, 2, '.', ''), 'amount' => Fin::money($base * $pct / 100)];
    }

    private function lock(Commission $commission): Commission
    {
        $c = Commission::whereKey($commission->id)->lockForUpdate()->firstOrFail();
        if (request()->hasHeader('If-Match')) {
            $c->assertIfMatch(request());
        }

        return $c;
    }
}
