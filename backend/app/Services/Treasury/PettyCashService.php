<?php

namespace App\Services\Treasury;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Models\Treasury\Account;
use App\Models\Treasury\CashCount;
use App\Models\Treasury\CashSession;
use App\Models\Treasury\ExpenseReceipt;
use App\Models\Treasury\Movement;
use App\Models\Treasury\Replenishment;
use App\Models\Treasury\ReplenishmentEvent;
use App\Models\Treasury\Transfer;
use App\Services\AuditLogger;
use App\Services\Notify;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Caja chica (spec 12 §4): apertura y cierre diario, gastos con comprobante, actas de arqueo con aprobación de diferencias y
 * reposición del fondo fijo. TES-BR-05/06/07/08/10/11/12/13/14/15.
 */
class PettyCashService
{
    private const MIME = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(
        private readonly TreasuryLedger $ledger,
        private readonly TreasuryService $treasury,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
        private readonly TenantContext $tenant,
    ) {}

    /** POST /petty-cash/{account}/sessions: TES-BR-06 (una abierta) y TES-BR-07 (el conteo iguala el saldo del último cierre). */
    public function open(Account $account, array $data, User $actor): CashSession
    {
        $this->assertPetty($account);
        $counted = $data['opening_amount'] ?? null;
        if (! is_numeric($counted) || (float) $counted < 0) {
            throw ApiException::invalid('opening_amount', 'Indique el efectivo contado al aperturar.');
        }
        $today = $this->ledger->today()->toDateString();

        $session = DB::transaction(function () use ($account, $counted, $today, $actor) {
            $account = $this->ledger->lock($account->id)->first();
            $open = CashSession::where('account_id', $account->id)->where('status', 'open')->first();
            if ($open) {
                throw ApiException::conflict('TES-BR-06', "La caja ya tiene la sesión {$open->code} abierta (".$open->session_date->format('d/m/Y').'): ciérrela con un arqueo.');
            }
            if (CashSession::where('account_id', $account->id)->whereDate('session_date', $today)->exists()) {
                throw ApiException::conflict('TES-BR-06', 'La caja ya se aperturó y cerró hoy.');
            }
            $balance = $this->ledger->balance($account);
            if (abs((float) $counted - $balance) > 0.004) {
                throw ApiException::unprocessable('TES-BR-07', sprintf('El conteo (S/ %s) no iguala el saldo del último cierre (S/ %s): registre primero un arqueo.',
                    number_format((float) $counted, 2), number_format($balance, 2)), ['balance' => $balance]);
            }
            try {
                return CashSession::create(['code' => $this->sequences->next('cash_session'), 'account_id' => $account->id, 'session_date' => $today,
                    'opened_at' => now(), 'opened_by' => $actor->id, 'opening_amount' => round((float) $counted, 2), 'status' => 'open']);
            } catch (QueryException) {
                throw ApiException::conflict('TES-BR-06', 'La caja ya tiene una sesión abierta.');
            }
        });
        $this->audit->log('Caja', $account->code, 'aperturar', null, "{$session->code} · S/ {$session->opening_amount}", actor: $actor);

        return $session;
    }

    /** POST /petty-cash/{account}/expenses: gasto con comprobante (TES-BR-05/11/12) y alerta de umbral (TES-BR-08). */
    public function expense(Account $account, array $data, User $actor): Movement
    {
        $this->assertPetty($account);
        $settings = $this->ledger->settings();
        $amount = $this->treasury->amount($data['amount'] ?? null);
        if ($amount > (float) $settings->max_petty_expense) {
            throw ApiException::unprocessable('TES-BR-11', "El gasto supera el tope de caja chica (S/ {$settings->max_petty_expense}): regístrelo como cuenta por pagar.");
        }
        $type = (string) ($data['receipt_type'] ?? '');
        if (! isset(ExpenseReceipt::TYPES[$type])) {
            throw ApiException::invalid('receipt_type', 'Seleccione el tipo de comprobante.');
        }
        $series = $this->treasury->str($data['series_number'] ?? null, 30);
        $ruc = $this->treasury->str($data['issuer_ruc'] ?? null, 11);
        $supplier = $this->treasury->str($data['supplier_name'] ?? null, 160);
        $description = $this->treasury->str($data['description'] ?? null, 255);
        if (! in_array($type, ['mobility_sheet', 'sworn_statement'], true) && ! $series) {
            throw ApiException::unprocessable('TES-BR-12', 'Indique la serie y el número del comprobante.');
        }
        if (in_array($type, ['invoice', 'fee_receipt'], true) && ! $ruc) {
            throw ApiException::unprocessable('TES-BR-12', 'La factura y el recibo por honorarios exigen el RUC del emisor.');
        }
        if ($ruc !== null && ! preg_match('/^\d{11}$/', $ruc)) {
            throw ApiException::unprocessable('TES-BR-12', 'El RUC tiene 11 dígitos.');
        }
        if ($type === 'sworn_statement' && $amount > (float) $settings->sworn_statement_max) {
            throw ApiException::unprocessable('TES-BR-12', "La declaración jurada solo sustenta gastos de hasta S/ {$settings->sworn_statement_max}.");
        }
        if (! $supplier) {
            throw ApiException::invalid('supplier_name', 'Indique el proveedor o beneficiario.');
        }
        if (! $description) {
            throw ApiException::invalid('description', 'Describa el gasto.');
        }
        $date = $this->treasury->date($data['movement_date'] ?? null);
        $reservation = ! empty($data['reservation_code']) ? Reservation::where('code', $data['reservation_code'])->first()
            ?? throw ApiException::invalid('reservation_code', 'La reserva no existe.') : null;
        $file = $this->file($data);
        if ($series && $ruc && ExpenseReceipt::where('issuer_ruc', $ruc)->where('series_number', $series)->exists()) {
            throw ApiException::conflict('TES-BR-12', "El comprobante {$series} del RUC {$ruc} ya se rindió.");
        }

        $movement = DB::transaction(function () use ($account, $amount, $type, $series, $ruc, $supplier, $description, $date, $reservation, $file, $data) {
            $account = $this->ledger->lock($account->id)->first();
            $this->treasury->assertSessionOpen($account);
            $this->ledger->assertCovers($account, $amount, 'El gasto');
            $m = $this->ledger->post($account, ['movement_date' => $date, 'direction' => 'out', 'type' => 'petty_expense', 'amount' => $amount,
                'source_type' => 'expense', 'category' => $this->treasury->str($data['category'] ?? null, 60), 'description' => $description,
                'reference' => $series, 'counterparty' => $supplier, 'reservation_id' => $reservation?->id]);
            $path = null;
            if ($file) {
                $path = "tenants/{$this->tenant->id()}/treasury/".Str::uuid7().'.'.self::MIME[$file['mime']];
                Storage::disk((string) config('turismo.evidence_disk', 'local'))->put($path, $file['content']);
            }
            try {
                ExpenseReceipt::create(['movement_id' => $m->id, 'receipt_type' => $type, 'series_number' => $series, 'issuer_ruc' => $ruc,
                    'supplier_name' => $supplier, 'file_path' => $path, 'file_name' => $file['name'] ?? null]);
            } catch (QueryException) {
                throw ApiException::conflict('TES-BR-12', "El comprobante {$series} del RUC {$ruc} ya se rindió.");
            }

            return $m;
        });
        $this->audit->log('Movimiento de tesorería', $movement->code, 'gasto_caja', null,
            "{$account->code} · S/ {$amount} · ".ExpenseReceipt::TYPES[$type].($series ? " {$series}" : '')." · {$supplier}", actor: $actor);
        $this->thresholdAlert($account);

        return $movement;
    }

    /** POST /petty-cash/{account}/counts: acta de arqueo sorpresa o de cierre (TES-BR-13). */
    public function count(Account $account, array $data, User $actor, ?string $ip = null): CashCount
    {
        $this->assertPetty($account);
        $kind = in_array($data['kind'] ?? null, ['surprise', 'closing'], true) ? $data['kind'] : throw ApiException::invalid('kind', 'Indique si es sorpresa o de cierre.');
        $denominations = $this->denominations($data['denominations'] ?? null);
        $counted = round(collect($denominations)->sum(fn ($qty, $value) => (float) $value * $qty), 2);
        $note = $this->treasury->str($data['note'] ?? null, 2000);
        $witness = Str::isUuid((string) ($data['witness_id'] ?? '')) ? User::find($data['witness_id']) : null;
        $tolerance = (float) $this->ledger->settings()->petty_tolerance;

        $count = DB::transaction(function () use ($account, $kind, $denominations, $counted, $note, $witness, $tolerance, $actor, $ip) {
            $account = $this->ledger->lock($account->id)->first();
            $session = $kind === 'closing' ? CashSession::where('account_id', $account->id)->where('status', 'open')->first() : null;
            if ($kind === 'closing' && ! $session) {
                throw ApiException::unprocessable('TES-BR-05', 'La caja no tiene una sesión abierta para cerrar.');
            }
            $expected = $this->ledger->balance($account);
            $diff = round($counted - $expected, 2);
            if (abs($diff) > 0.004 && ! $note) {
                throw ApiException::unprocessable('TES-BR-13', sprintf('Hay una diferencia de S/ %s: justifíquela en la observación.', number_format($diff, 2)));
            }
            $over = abs($diff) > $tolerance + 0.004;
            $c = CashCount::create(['code' => $this->sequences->next('cash_count'), 'account_id' => $account->id, 'session_id' => $session?->id, 'kind' => $kind,
                'counted_at' => now(), 'denominations' => $denominations, 'counted_amount' => $counted, 'expected_amount' => $expected, 'difference' => $diff,
                'result' => abs($diff) < 0.005 ? 'balanced' : ($diff > 0 ? 'over' : 'short'), 'note' => $note, 'signed_by' => $actor->id, 'signed_at' => now(),
                'signed_ip' => $ip, 'witness_id' => $witness?->id, 'status' => $over ? 'pending_approval' : 'signed']);
            // Dentro de la tolerancia el ajuste nace al firmar; por encima, al aprobarse.
            if (abs($diff) > 0.004 && ! $over) {
                $this->adjust($c, $account);
            }
            if ($session) {
                $session->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $actor->id, 'closing_count_id' => $c->id])->save();
            }

            return $c;
        });
        $this->audit->log('Arqueo', $count->code, $kind === 'closing' ? 'arqueo_cierre' : 'arqueo', null,
            "{$account->code} · contado S/ {$count->counted_amount} · esperado S/ {$count->expected_amount} · diferencia {$count->difference} · ".CashCount::STATUS_LABELS[$count->status].($ip ? " · IP {$ip}" : ''),
            evidence: ['signed_by' => $actor->id, 'signed_at' => $count->signed_at?->toIso8601String(), 'ip' => $ip], actor: $actor);
        if ($count->status === 'pending_approval') {
            foreach ($this->approvers($actor) as $u) {
                $this->notify->push('arqueo.diferencia', 'portal', $u, "Arqueo {$count->code}: diferencia de S/ {$count->difference}",
                    "{$account->name} · supera la tolerancia de S/ {$tolerance}. Requiere su aprobación.", ['related_type' => 'cash_count', 'related_id' => $count->id, 'link' => '/finanzas/tesoreria/cajas']);
            }
        }

        return $count;
    }

    /** POST /counts/{id}/approve: Manager o Finanzas distinto del firmante (TES-BR-14); genera el ajuste. */
    public function approveCount(CashCount $count, ?string $note, User $actor): CashCount
    {
        if ($count->status !== 'pending_approval') {
            throw ApiException::conflict('INVALID_STATE', 'El arqueo no está pendiente de aprobación.');
        }
        if ($count->signed_by === $actor->id) {
            throw ApiException::forbidden('TES-BR-14', 'Quien firma el arqueo no puede aprobar su diferencia.');
        }
        DB::transaction(function () use ($count, $note, $actor) {
            $account = $this->ledger->lock($count->account_id)->first();
            $count->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'approve_note' => $this->treasury->str($note, 2000)])->save();
            $this->adjust($count, $account);
        });
        $this->audit->log('Arqueo', $count->code, 'aprobar_diferencia', 'Pendiente de aprobación', "Aprobada · ajuste S/ {$count->difference}", actor: $actor);

        return $count->fresh();
    }

    /** POST /counts/{id}/validate: visto bueno contable. */
    public function validateCount(CashCount $count, User $actor): CashCount
    {
        if (! in_array($count->status, ['signed', 'approved'], true)) {
            throw ApiException::conflict('INVALID_STATE', $count->status === 'validated' ? 'El arqueo ya está validado.' : 'Primero se aprueba la diferencia.');
        }
        $before = CashCount::STATUS_LABELS[$count->status];
        $count->forceFill(['status' => 'validated', 'validated_by' => $actor->id, 'validated_at' => now()])->save();
        $this->audit->log('Arqueo', $count->code, 'validar_contable', $before, 'Validada', actor: $actor);

        return $count;
    }

    /** POST /petty-cash/{account}/replenishments: rinde los gastos pendientes (TES-BR-15: una en curso). */
    public function request(Account $account, ?string $note, User $actor): Replenishment
    {
        $this->assertPetty($account);
        $rep = DB::transaction(function () use ($account, $note, $actor) {
            $account = $this->ledger->lock($account->id)->first();
            if ($open = Replenishment::where('account_id', $account->id)->whereIn('status', ['requested', 'approved'])->first()) {
                throw ApiException::conflict('TES-BR-15', "La caja ya tiene la reposición {$open->code} en curso.");
            }
            $expenses = $this->pendingExpenses($account);
            if ($expenses->isEmpty()) {
                throw ApiException::unprocessable('TES-REP', 'No hay gastos pendientes de rendir.');
            }
            $rep = Replenishment::create(['code' => $this->sequences->next('cash_replenishment'), 'account_id' => $account->id,
                'amount' => round((float) $expenses->sum('amount'), 2), 'status' => 'requested', 'requested_by' => $actor->id, 'requested_at' => now(),
                'note' => $this->treasury->str($note, 2000)]);
            Movement::whereIn('id', $expenses->pluck('id'))->update(['replenishment_id' => $rep->id]);
            $this->event($rep, 'requested', "Solicitada por {$actor->name}: {$expenses->count()} gasto(s)", $actor);

            return $rep;
        });
        $this->audit->log('Reposición de caja', $rep->code, 'solicitar', null, "{$account->code} · S/ {$rep->amount}", actor: $actor);
        foreach ($this->approvers($actor) as $u) {
            $this->notify->push('caja.reposicion', 'portal', $u, "Reposición {$rep->code} por aprobar", "{$account->name} · S/ {$rep->amount}",
                ['related_type' => 'cash_replenishment', 'related_id' => $rep->id, 'link' => '/finanzas/tesoreria/cajas']);
        }

        return $rep;
    }

    /** POST /replenishments/{id}/approve | reject: distinto del solicitante (TES-BR-14). El rechazo libera los gastos. */
    public function resolve(Replenishment $rep, bool $approve, ?string $reason, User $actor): Replenishment
    {
        if ($rep->status !== 'requested') {
            throw ApiException::conflict('INVALID_STATE', 'La reposición no está pendiente de aprobación.');
        }
        if ($rep->requested_by === $actor->id) {
            throw ApiException::forbidden('TES-BR-14', 'Quien solicita la reposición no puede aprobarla ni rechazarla.');
        }
        $reason = $this->treasury->str($reason, 2000);
        if (! $approve && ! $reason) {
            throw ApiException::invalid('reason', 'Indique el motivo del rechazo.');
        }
        DB::transaction(function () use ($rep, $approve, $reason, $actor) {
            if ($approve) {
                $rep->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()])->save();
            } else {
                $rep->forceFill(['status' => 'rejected', 'approved_by' => $actor->id, 'approved_at' => now(), 'reject_reason' => $reason])->save();
                Movement::where('replenishment_id', $rep->id)->update(['replenishment_id' => null]);
            }
            $this->event($rep, $rep->status, ($approve ? 'Aprobada' : "Rechazada: {$reason}")." por {$actor->name}", $actor);
        });
        $this->audit->log('Reposición de caja', $rep->code, $approve ? 'aprobar' : 'rechazar', 'Solicitada', $approve ? 'Aprobada' : "Rechazada: {$reason}", actor: $actor);
        if ($requester = User::find($rep->requested_by)) {
            $this->notify->push($approve ? 'caja.reposicion_aprobada' : 'caja.reposicion_rechazada', 'portal', $requester,
                "Reposición {$rep->code} ".($approve ? 'aprobada' : 'rechazada'), $approve ? "S/ {$rep->amount} lista para pagar." : $reason,
                ['related_type' => 'cash_replenishment', 'related_id' => $rep->id, 'link' => '/finanzas/tesoreria/cajas']);
        }

        return $rep->fresh();
    }

    /** POST /replenishments/{id}/pay: transferencia banco → caja; la caja vuelve a su fondo fijo. */
    public function pay(Replenishment $rep, array $data, User $actor): Replenishment
    {
        if ($rep->status !== 'approved') {
            throw ApiException::conflict('INVALID_STATE', $rep->status === 'paid' ? 'La reposición ya está pagada.' : 'La reposición no está aprobada.');
        }
        $from = $this->treasury->account($data['from_account_id'] ?? null, 'from_account_id');
        $caja = Account::findOrFail($rep->account_id);
        if ($from->kind !== 'bank' || $from->currency !== $caja->currency) {
            throw ApiException::invalid('from_account_id', "Pague desde una cuenta bancaria en {$caja->currency}.");
        }
        $reference = $this->treasury->str($data['reference'] ?? null, 80) ?? throw ApiException::invalid('reference', 'Indique el N° de operación.');
        $date = $this->treasury->date($data['transfer_date'] ?? null);

        DB::transaction(function () use ($rep, $from, $caja, $reference, $date, $actor) {
            $locked = $this->ledger->lock($from->id, $caja->id);
            $from = $locked[$from->id];
            $caja = $locked[$caja->id];
            $this->ledger->assertCovers($from, (float) $rep->amount, 'La reposición');
            $t = Transfer::create(['code' => $this->sequences->next('treasury_transfer'), 'from_account_id' => $from->id, 'to_account_id' => $caja->id,
                'amount_from' => $rep->amount, 'amount_to' => $rep->amount, 'kind' => 'replenishment', 'transfer_date' => $date, 'reference' => $reference]);
            $base = ['movement_date' => $date, 'type' => 'replenishment', 'amount' => $rep->amount, 'source_type' => 'replenishment', 'source_id' => $rep->id,
                'source_code' => $rep->code, 'transfer_id' => $t->id, 'reference' => $reference];
            $this->ledger->post($from, $base + ['direction' => 'out', 'counterparty' => $caja->name, 'description' => "Reposición {$rep->code} a {$caja->code}"]);
            $this->ledger->post($caja, $base + ['direction' => 'in', 'counterparty' => $from->name, 'description' => "Reposición {$rep->code} desde {$from->code}"]);
            $rep->forceFill(['status' => 'paid', 'paid_by' => $actor->id, 'paid_at' => now(), 'paid_from_account_id' => $from->id, 'transfer_id' => $t->id])->save();
            $this->event($rep, 'paid', "Pagada con {$t->code} desde {$from->code} · op. {$reference}", $actor);
        });
        $this->audit->log('Reposición de caja', $rep->code, 'pagar', 'Aprobada', "Pagada desde {$from->code} · S/ {$rep->amount} · op. {$reference}", actor: $actor);
        if ($caja->custodian_id && ($custodian = User::find($caja->custodian_id))) {
            $this->notify->push('caja.reposicion_pagada', 'portal', $custodian, "Reposición {$rep->code} pagada", "{$caja->name} recibió S/ {$rep->amount}.",
                ['related_type' => 'cash_replenishment', 'related_id' => $rep->id]);
        }

        return $rep->fresh();
    }

    /** Gastos registrados aún no rendidos en una reposición. */
    public function pendingExpenses(Account $account): Collection
    {
        return Movement::where('account_id', $account->id)->where('type', 'petty_expense')->where('status', 'posted')->whereNull('replenishment_id')
            ->orderBy('movement_date')->get();
    }

    /** TES-BR-08: aviso a Finanzas cuando el saldo baja del umbral del fondo fijo. */
    public function thresholdAlert(Account $account): bool
    {
        $threshold = (float) $account->fund_amount * $this->ledger->settings()->replenish_threshold_pct / 100;
        $balance = $this->ledger->balance($account);
        if ($balance >= $threshold) {
            return false;
        }
        foreach (User::permission('finance.payments.register')->where('status', User::ACTIVE)->get() as $u) {
            $this->notify->push('caja.umbral', 'portal', $u, "{$account->name} bajo el umbral", sprintf('Saldo S/ %s de un fondo de S/ %s (umbral %d %%). Solicite la reposición.',
                number_format($balance, 2), number_format((float) $account->fund_amount, 2), $this->ledger->settings()->replenish_threshold_pct),
                ['related_type' => 'treasury_account', 'related_id' => $account->id, 'link' => '/finanzas/tesoreria/cajas']);
        }

        return true;
    }

    /** Aprobadores: Manager/Finanzas/Admin (finance.profitability + approvals.view) distintos de quien origina. */
    public function approvers(?User $except = null): Collection
    {
        return User::permission('finance.profitability')->where('status', User::ACTIVE)->get()
            ->filter(fn (User $u) => $u->hasPermissionTo('approvals.view') && $u->id !== $except?->id)->values();
    }

    private function adjust(CashCount $count, Account $account): void
    {
        $diff = (float) $count->difference;
        $m = $this->ledger->post($account, ['direction' => $diff > 0 ? 'in' : 'out', 'type' => 'count_adjustment', 'amount' => abs($diff),
            'source_type' => 'cash_count', 'source_id' => $count->id, 'source_code' => $count->code, 'category' => $diff > 0 ? 'Sobrante de caja' : 'Faltante de caja',
            'description' => ($diff > 0 ? 'Sobrante' : 'Faltante')." del arqueo {$count->code}: ".Str::limit((string) $count->note, 160)]);
        $count->forceFill(['adjustment_movement_id' => $m->id])->save();
    }

    private function denominations(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            throw ApiException::invalid('denominations', 'Registre el conteo por denominación.');
        }
        $out = [];
        foreach ($value as $denomination => $qty) {
            $key = rtrim(rtrim(number_format((float) $denomination, 2, '.', ''), '0'), '.');
            if (! in_array($key, CashCount::DENOMINATIONS, true)) {
                throw ApiException::invalid('denominations', "Denominación no válida: {$denomination}.");
            }
            if ($qty === null || $qty === '') {
                continue;
            }
            if (! is_numeric($qty) || (int) $qty != $qty || (int) $qty < 0) {
                throw ApiException::invalid('denominations', "La cantidad de S/ {$key} debe ser un entero mayor o igual a 0.");
            }
            if ((int) $qty > 0) {
                $out[$key] = (int) $qty;
            }
        }

        return $out;
    }

    private function file(array $data): ?array
    {
        if (empty($data['content_base64'])) {
            return null;
        }
        $mime = (string) ($data['mime_type'] ?? '');
        if (! isset(self::MIME[$mime])) {
            throw ApiException::invalid('file', 'El comprobante debe ser PDF, JPG o PNG.');
        }
        $content = base64_decode((string) $data['content_base64'], true);
        if ($content === false || $content === '' || strlen($content) > 10 * 1024 * 1024) {
            throw ApiException::invalid('file', 'El archivo no es válido o supera 10 MB.');
        }

        return ['mime' => $mime, 'content' => $content, 'name' => mb_substr((string) ($data['file_name'] ?? 'comprobante.'.self::MIME[$mime]), 0, 160)];
    }

    private function assertPetty(Account $account): void
    {
        if (! $account->isPetty()) {
            throw ApiException::invalid('account', 'La operación es solo para cajas chicas.');
        }
        if ($account->status !== 'active') {
            throw ApiException::invalid('account', "La caja {$account->code} está inactiva.");
        }
    }

    private function event(Replenishment $rep, string $status, string $message, User $actor): void
    {
        ReplenishmentEvent::create(['replenishment_id' => $rep->id, 'status' => $status, 'message' => $message, 'user_id' => $actor->id, 'created_at' => now()]);
    }
}
