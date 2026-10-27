<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\Finance\BankAccount;
use App\Models\Finance\BankLine;
use App\Models\Finance\BankStatement;
use App\Models\Finance\Payable;
use App\Models\Finance\Payment;
use App\Models\Finance\ReconciliationClosure;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\SequenceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Conciliación bancaria (PROC-049, FIN-BR-51…55): importar → normalizar → matching → confirmar/rechazar/resolver → cerrar. */
class Reconciliation
{
    public const BANKS = ['BCP', 'Interbank', 'BBVA', 'Scotiabank'];

    public function __construct(
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
        private readonly SequenceService $sequences,
        private readonly PaymentService $payments,
        private readonly PayableManager $payables,
    ) {}

    /**
     * Importar extracto. Con archivo (`name`, `content`): CSV/TXT `fecha;descripción;importe` (cabecera opcional, `,` o `;`). Sin archivo:
     * extracto simulado del prototipo con 3 líneas (un abono, un cargo de proveedor y una comisión bancaria) tomadas de los pendientes reales.
     *
     * @param  array{name: string, content: string}|null  $file
     */
    public function import(string $bank, ?string $accountId, ?array $file, User $actor): BankStatement
    {
        if (! in_array($bank, self::BANKS, true)) {
            throw ApiException::invalid('bank', 'Seleccione el banco.');
        }
        $account = $accountId ? BankAccount::find($accountId) : null;
        $rows = $file ? $this->parse($file['content']) : $this->simulated();
        if ($rows === []) {
            throw ApiException::invalid('file', 'El extracto no tiene líneas válidas (fecha;descripción;importe).');
        }

        return DB::transaction(function () use ($bank, $account, $file, $rows, $actor) {
            $statement = BankStatement::create(['bank' => $bank, 'bank_account_id' => $account?->id, 'filename' => mb_substr($file['name'] ?? "extracto-{$bank}-simulado.csv", 0, 200),
                'format' => $file ? (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'txt' ? 'txt' : 'csv') : 'csv', 'lines_count' => count($rows), 'imported_by' => $actor->id, 'imported_at' => now()]);
            foreach ($rows as $row) {
                BankLine::create(['code' => $this->sequences->next('bank_line'), 'statement_id' => $statement->id, 'bank' => $bank, 'value_date' => $row['date'],
                    'description' => mb_strtoupper(mb_substr(trim($row['description']), 0, 255)), 'amount' => Fin::money($row['amount']), 'currency' => 'PEN', 'status' => 'pendiente']);
            }
            $this->audit->log('Conciliación', $statement->filename, 'importar', null, "{$bank} · ".count($rows).' línea(s)', actor: $actor);

            return $statement;
        });
    }

    /** Ejecutar matching sobre las líneas pendientes (FIN-BR-51/52). @return array{suggested: int, exceptions: int} */
    public function match(User $actor): array
    {
        $counts = ['suggested' => 0, 'exceptions' => 0];
        DB::transaction(function () use (&$counts, $actor) {
            foreach (BankLine::where('status', 'pendiente')->whereNull('closure_id')->orderBy('value_date')->lockForUpdate()->get() as $line) {
                $target = $this->bestCandidate($line);
                $line->forceFill(['status' => $target ? 'sugerido' : 'excepcion',
                    'matched_payment_id' => $target instanceof Payment ? $target->id : null, 'matched_payable_id' => $target instanceof Payable ? $target->id : null])->save();
                $counts[$target ? 'suggested' : 'exceptions']++;
            }
            $this->audit->log('Conciliación', 'matching', 'matching', null, "{$counts['suggested']} sugerencia(s) · {$counts['exceptions']} excepción(es)", actor: $actor);
        });

        return $counts;
    }

    public function confirm(BankLine $line, User $actor): BankLine
    {
        return DB::transaction(function () use ($line, $actor) {
            $l = $this->lock($line);
            if ($l->status !== 'sugerido') {
                throw ApiException::rule('FIN-BR-53', "La línea {$l->code} no tiene una sugerencia por confirmar.");
            }
            $target = $l->matched_payment_id ? Payment::findOrFail($l->matched_payment_id) : Payable::findOrFail($l->matched_payable_id);
            $this->settle($l, $target, $actor);
            $l->forceFill(['status' => 'conciliado', 'resolution' => 'match', 'resolved_by' => $actor->id])->save();
            $this->audit->log('Conciliación', $l->code, 'confirmar_match', 'Sugerido', 'Conciliado con '.($target->code), actor: $actor);

            return $l;
        });
    }

    public function reject(BankLine $line, User $actor): BankLine
    {
        return DB::transaction(function () use ($line, $actor) {
            $l = $this->lock($line);
            if ($l->status !== 'sugerido') {
                throw ApiException::rule('FIN-BR-53', "La línea {$l->code} no tiene una sugerencia por rechazar.");
            }
            $l->forceFill(['status' => 'excepcion', 'matched_payment_id' => null, 'matched_payable_id' => null])->save();
            $this->audit->log('Conciliación', $l->code, 'rechazar_match', 'Sugerido', 'Excepción', actor: $actor);

            return $l;
        });
    }

    /** FIN-BR-54: asignar a un pago/CxP candidato u "otro ingreso"/"gasto bancario", con sustento obligatorio. */
    public function resolve(BankLine $line, array $data, User $actor): BankLine
    {
        $note = trim((string) ($data['note'] ?? ''));
        if ($note === '') {
            throw ApiException::invalid('note', 'Indique el sustento de la resolución.');
        }

        return DB::transaction(function () use ($line, $data, $note, $actor) {
            $l = $this->lock($line);
            if ($l->status !== 'excepcion') {
                throw ApiException::rule('FIN-BR-54', "La línea {$l->code} no está en excepción.");
            }
            if (($data['mode'] ?? '') === 'assign') {
                $candidates = $this->candidates($l);
                $target = $candidates->first(fn ($c) => $c->id === ($data['target_id'] ?? null) || $c->code === ($data['target_id'] ?? null))
                    ?? throw ApiException::invalid('target_id', 'Seleccione un '.((float) $l->amount > 0 ? 'pago' : 'documento por pagar').' candidato.');
                $this->settle($l, $target, $actor);
                $l->forceFill(['matched_payment_id' => $target instanceof Payment ? $target->id : null, 'matched_payable_id' => $target instanceof Payable ? $target->id : null,
                    'resolution' => 'asignado'])->save();
                $result = "Asignado a {$target->code}";
            } else {
                $l->forceFill(['resolution' => (float) $l->amount > 0 ? 'otro_ingreso' : 'gasto_bancario'])->save();
                $result = (float) $l->amount > 0 ? 'Otro ingreso' : 'Gasto / comisión bancaria';
            }
            $l->forceFill(['status' => 'conciliado', 'resolution_note' => $note, 'resolved_by' => $actor->id])->save();
            $this->audit->log('Conciliación', $l->code, 'resolver_excepcion', 'Excepción', "{$result} · {$note}", actor: $actor);

            return $l;
        });
    }

    /** FIN-BR-55: cerrar exige todas las líneas abiertas conciliadas; quedan bloqueadas. */
    public function close(User $actor): ReconciliationClosure
    {
        return DB::transaction(function () use ($actor) {
            $lines = BankLine::whereNull('closure_id')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw ApiException::unprocessable('FIN-BR-55', 'No hay líneas abiertas por cerrar.');
            }
            $open = $lines->where('status', '<>', 'conciliado');
            if ($open->isNotEmpty()) {
                throw ApiException::unprocessable('FIN-BR-55', 'Hay líneas sin conciliar: '.$open->pluck('code')->join(', ').'. Confirme o resuelva antes de cerrar.');
            }
            $credits = $lines->filter(fn ($l) => (float) $l->amount > 0)->sum(fn ($l) => (float) $l->amount);
            $debits = $lines->filter(fn ($l) => (float) $l->amount < 0)->sum(fn ($l) => (float) $l->amount);
            $today = $this->clock->today();
            $code = preg_replace('/^CON-/', "CON-{$today->year}-", $this->sequences->next('reconciliation'));
            $closure = ReconciliationClosure::create(['code' => $code, 'period' => $today->format('Y-m'), 'closed_at' => now(), 'closed_by' => $actor->id,
                'lines_count' => $lines->count(), 'credits' => Fin::money($credits), 'debits' => Fin::money($debits), 'net' => Fin::money($credits + $debits),
                'matched_count' => $lines->whereIn('resolution', ['match', 'asignado'])->count(), 'others_count' => $lines->whereIn('resolution', ['otro_ingreso', 'gasto_bancario'])->count()]);
            BankLine::whereIn('id', $lines->pluck('id'))->update(['closure_id' => $closure->id]);
            $this->audit->log('Conciliación', $closure->code, 'cerrar', null, "{$lines->count()} línea(s) · abonos PEN ".Fin::money($credits).' · cargos '.Fin::money($debits), actor: $actor);

            return $closure;
        });
    }

    /** FIN-BR-51: candidatos de la línea (sin los ya vinculados a otra línea no-excepción). @return Collection<int, Payment|Payable> */
    public function candidates(BankLine $line): Collection
    {
        $taken = fn (string $col) => BankLine::where('id', '<>', $line->id)->where('status', '<>', 'excepcion')->whereNotNull($col)->pluck($col);
        if ((float) $line->amount > 0) {
            return Payment::whereNotIn('status', ['revertido', 'rechazado'])->where('kind', '<>', 'reembolso')->whereNotIn('id', $taken('matched_payment_id'))
                ->orderByDesc('paid_on')->get();
        }

        return Payable::where(fn ($q) => $q->whereNotNull('approved_at')->orWhere('status', Payable::PAID))->where('status', '<>', Payable::CANCELLED)
            ->whereNotIn('id', $taken('matched_payable_id'))->with('provider:id,name,ruc')->orderBy('due_date')->get();
    }

    /** Importe PEN del candidato (FIN-BR-52): pagos al TC conservado; CxP por saldo (o importe si pagada). */
    public function candidateAmount(Payment|Payable $c): float
    {
        if ($c instanceof Payment) {
            return Fin::r2((float) $c->amount * ($c->currency === 'PEN' ? 1 : (float) $c->fx_rate));
        }
        $base = (float) $c->balance() > Fin::EPS ? (float) $c->balance() : (float) $c->amount;

        return Fin::r2($base * ($c->currency === 'PEN' ? 1 : (float) $c->fx_rate));
    }

    private function bestCandidate(BankLine $line): Payment|Payable|null
    {
        $amount = abs((float) $line->amount);
        $matches = $this->candidates($line)->filter(fn ($c) => abs($this->candidateAmount($c) - $amount) < Fin::MATCH);
        preg_match_all('/\d{5,}/', $line->description, $m);
        $numbers = $m[0];

        return $matches->sortByDesc(function ($c) use ($numbers) {
            $haystack = $c instanceof Payment ? (string) $c->reference : (string) $c->provider?->ruc;
            foreach ($numbers as $n) {
                if ($haystack !== '' && (str_contains($haystack, $n) || str_contains($n, $haystack))) {
                    return 1;
                }
            }

            return 0;
        })->first();
    }

    /** FIN-BR-53: pago pendiente → confirmado; CxP con saldo → payout `cargo_bancario` por min(|cargo|/TC, saldo). */
    private function settle(BankLine $line, Payment|Payable $target, User $actor): void
    {
        if ($target instanceof Payment) {
            if ($target->status === 'pendiente') {
                $this->payments->confirmFromBank($target, $actor, "Confirmado con la línea {$line->code} del extracto");
            }

            return;
        }
        if ((float) $target->balance() > Fin::EPS) {
            $fx = $target->currency === 'PEN' ? 1 : (float) $target->fx_rate;
            $amount = min(abs((float) $line->amount) / $fx, (float) $target->balance());
            $this->payables->payout($target, ['amount' => Fin::money($amount), 'method' => 'cargo_bancario', 'operation_ref' => $line->code,
                'fx_rate' => $target->currency === 'PEN' ? null : $target->fx_rate, 'paid_on' => $line->value_date->toDateString()], $actor, $line->id);
        }
    }

    private function lock(BankLine $line): BankLine
    {
        $l = BankLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
        if ($l->closure_id) {
            throw ApiException::rule('FIN-BR-55', "La línea {$l->code} pertenece a una conciliación cerrada: no se modifica.");
        }
        if (request()->hasHeader('If-Match')) {
            $l->assertIfMatch(request());
        }

        return $l;
    }

    /** @return list<array{date: string, description: string, amount: float}> */
    private function parse(string $content): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $content) as $raw) {
            $cols = str_getcsv($raw, str_contains($raw, ';') ? ';' : ',');
            if (count($cols) < 3) {
                continue;
            }
            [$date, $description, $amount] = array_map('trim', array_slice($cols, 0, 3));
            if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $d)) {
                $date = "{$d[3]}-{$d[2]}-{$d[1]}";
            }
            $amount = (float) str_replace([' ', ','], ['', ''], $amount);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $amount == 0.0 || $description === '') {
                continue; // cabecera o fila inválida
            }
            $rows[] = ['date' => $date, 'description' => $description, 'amount' => $amount];
        }

        return $rows;
    }

    /** Extracto simulado (§4.7): abono de un pago pendiente, cargo de una CxP aprobada y una comisión bancaria. */
    private function simulated(): array
    {
        $today = $this->clock->today()->toDateString();
        $rows = [];
        $payment = Payment::where('status', 'pendiente')->where('kind', '<>', 'reembolso')->orderBy('paid_on')->first();
        if ($payment) {
            $rows[] = ['date' => $today, 'description' => "ABONO TRANSF {$payment->reference}", 'amount' => (float) $payment->amount * ($payment->currency === 'PEN' ? 1 : (float) $payment->fx_rate)];
        }
        $payable = Payable::whereNotNull('approved_at')->whereIn('status', Payable::PAYABLE)->with('provider')->orderBy('due_date')->first();
        if ($payable) {
            $rows[] = ['date' => $today, 'description' => "CARGO PAGO PROV RUC {$payable->provider?->ruc}", 'amount' => -1 * (float) $payable->balance() * ($payable->currency === 'PEN' ? 1 : (float) $payable->fx_rate)];
        }
        $rows[] = ['date' => $today, 'description' => 'COMISION MANTENIMIENTO CUENTA', 'amount' => -15.00];

        return array_map(fn ($r) => ['amount' => Fin::r2($r['amount'])] + $r, $rows);
    }
}
