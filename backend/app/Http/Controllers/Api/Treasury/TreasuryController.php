<?php

namespace App\Http\Controllers\Api\Treasury;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Finance\BankAccount;
use App\Models\Finance\PaymentMethod;
use App\Models\Iam\Sede;
use App\Models\Iam\User;
use App\Models\Treasury\Account;
use App\Models\Treasury\CashCount;
use App\Models\Treasury\ExpenseReceipt;
use App\Models\Treasury\Movement;
use App\Models\Treasury\Replenishment;
use App\Services\AuditLogger;
use App\Services\Treasury\CashFlowService;
use App\Services\Treasury\FxValuationService;
use App\Services\Treasury\PettyCashService;
use App\Services\Treasury\TreasuryLedger;
use App\Services\Treasury\TreasuryPresenter;
use App\Services\Treasury\TreasuryService;
use App\Services\Treasury\TreasurySync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Spec 12 §6: tesorería. Lectura con `finance.treasury`; registro con `finance.payments.register` (ver rutas). */
class TreasuryController extends Controller
{
    public function __construct(
        private readonly TreasuryService $service,
        private readonly PettyCashService $petty,
        private readonly TreasuryPresenter $present,
        private readonly TreasuryLedger $ledger,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present->summary($request->user())]);
    }

    public function accounts(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present->accounts(), 'meta' => ['can' => $this->present->can($request->user()),
            'sedes' => Sede::orderBy('name')->get(['id', 'name'])->all(), 'custodians' => User::where('status', User::ACTIVE)->orderBy('name')->get()->reject(fn (User $u) => $u->isExternal())->map(fn ($u) => $u->only(['id', 'name']))->values()->all(),
            'bank_accounts' => BankAccount::orderBy('label')->get(['id', 'label'])->all(), 'methods' => PaymentMethod::orderBy('label')->pluck('label', 'code')->all(),
            'kinds' => Account::KINDS]]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $a = $this->service->createAccount($request->all(), $request->user());

        return response()->json(['data' => ['id' => $a->id, 'code' => $a->code], 'message' => "Cuenta {$a->code} registrada"], 201);
    }

    public function updateAccount(Request $request, string $account): JsonResponse
    {
        $a = $this->account($account);
        $a->assertIfMatch($request);
        $a = $this->service->updateAccount($a, $request->all(), $request->user());

        return response()->json(['data' => ['id' => $a->id, 'code' => $a->code, 'lock_version' => $a->lock_version], 'message' => "Cuenta {$a->code} actualizada"]);
    }

    /** GET /treasury/accounts/{id}/movements: libro de la cuenta con saldo acumulado. */
    public function accountMovements(Request $request, string $account): JsonResponse
    {
        $a = $this->account($account);

        return response()->json(['data' => $this->present->ledger($a, $request->only(['from', 'to'])) + ['can' => $this->present->can($request->user()),
            'accounts' => Account::where('status', 'active')->where('id', '<>', $a->id)->where('kind', '<>', 'gateway')->orderBy('code')->get(['id', 'code', 'name', 'currency', 'kind'])->all(),
            'receipt_types' => ExpenseReceipt::TYPES]]);
    }

    public function movements(Request $request): JsonResponse
    {
        $filters = (array) $request->input('filter', []) + $request->only(['from', 'to', 'q', 'page']);

        return response()->json($this->present->movements($filters) + ['options' => ['types' => Movement::TYPES,
            'accounts' => Account::orderBy('code')->get(['id', 'code', 'name', 'currency', 'kind'])->all(), 'can' => $this->present->can($request->user())]]);
    }

    public function movement(string $movement): JsonResponse
    {
        $m = Movement::with(['account', 'receipt', 'creator', 'voider'])->where(fn ($q) => Str::isUuid($movement) ? $q->whereKey($movement) : $q->where('code', $movement))->first()
            ?? throw ApiException::notFound();

        return response()->json(['data' => $this->present->movement($m, true)]);
    }

    public function storeMovement(Request $request): JsonResponse
    {
        $m = $this->service->manual($request->all(), $request->user());

        return response()->json(['data' => $this->present->movement($m), 'message' => "Movimiento {$m->code} registrado"], 201);
    }

    public function voidMovement(Request $request, string $movement): JsonResponse
    {
        $m = Movement::where(fn ($q) => Str::isUuid($movement) ? $q->whereKey($movement) : $q->where('code', $movement))->first() ?? throw ApiException::notFound();
        $m = $this->service->void($m, (string) $request->input('reason'), $request->user());

        return response()->json(['data' => $this->present->movement($m), 'message' => "Movimiento {$m->code} anulado"]);
    }

    public function transfer(Request $request): JsonResponse
    {
        $t = $this->service->transfer($request->all(), $request->user());

        return response()->json(['data' => ['code' => $t->code], 'message' => ($t->kind === 'cash_deposit' ? 'Depósito ' : 'Transferencia ')."{$t->code} registrada"], 201);
    }

    public function settle(Request $request, string $account): JsonResponse
    {
        $t = $this->service->settleGateway($this->account($account), $request->all(), $request->user());

        return response()->json(['data' => ['code' => $t->code, 'net' => $t->amount_to, 'fee' => $t->fee_amount], 'message' => "Liquidación {$t->code}: neto {$t->amount_to} al banco"], 201);
    }

    public function pettyCash(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present->pettyCash($request->user())]);
    }

    public function openSession(Request $request, string $account): JsonResponse
    {
        $s = $this->petty->open($this->account($account), $request->all(), $request->user());

        return response()->json(['data' => ['code' => $s->code], 'message' => "Caja aperturada ({$s->code})"], 201);
    }

    public function expense(Request $request, string $account): JsonResponse
    {
        $m = $this->petty->expense($this->account($account), $request->all(), $request->user());

        return response()->json(['data' => $this->present->movement($m), 'message' => "Gasto {$m->code} registrado"], 201);
    }

    public function receiptFile(Request $request, string $movement, AuditLogger $audit): Response
    {
        $m = Movement::where('code', $movement)->orWhere(fn ($q) => Str::isUuid($movement) ? $q->whereKey($movement) : $q->whereRaw('false'))->first() ?? throw ApiException::notFound();
        $receipt = ExpenseReceipt::where('movement_id', $m->id)->first();
        if (! $receipt?->file_path) {
            throw ApiException::notFound();
        }
        $disk = Storage::disk((string) config('turismo.evidence_disk', 'local'));
        $audit->log('Movimiento de tesorería', $m->code, 'ver_comprobante', null, $receipt->file_name, actor: $request->user());

        return response($disk->get($receipt->file_path), 200, ['Content-Type' => $disk->mimeType($receipt->file_path) ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes((string) $receipt->file_name).'"']);
    }

    public function count(Request $request, string $account): JsonResponse
    {
        $c = $this->petty->count($this->account($account), $request->all(), $request->user(), $request->ip());
        $message = match ($c->status) {
            'pending_approval' => "Acta {$c->code} firmada: la diferencia de S/ {$c->difference} requiere aprobación",
            default => "Acta {$c->code} firmada".($c->kind === 'closing' ? ' y caja cerrada' : '').((float) $c->difference !== 0.0 ? " · ajuste S/ {$c->difference}" : ' · cuadrada'),
        };

        return response()->json(['data' => $this->present->count($c), 'message' => $message], 201);
    }

    public function approveCount(Request $request, string $count): JsonResponse
    {
        $c = $this->countModel($count);
        $c->assertIfMatch($request);
        $c = $this->petty->approveCount($c, $request->input('note'), $request->user());

        return response()->json(['data' => $this->present->count($c), 'message' => "Diferencia del arqueo {$c->code} aprobada"]);
    }

    public function validateCount(Request $request, string $count): JsonResponse
    {
        $c = $this->petty->validateCount($this->countModel($count), $request->user());

        return response()->json(['data' => $this->present->count($c), 'message' => "Arqueo {$c->code} validado"]);
    }

    public function requestReplenishment(Request $request, string $account): JsonResponse
    {
        $r = $this->petty->request($this->account($account), $request->input('note'), $request->user());

        return response()->json(['data' => ['code' => $r->code, 'amount' => $r->amount], 'message' => "Reposición {$r->code} solicitada por S/ {$r->amount}"], 201);
    }

    public function approveReplenishment(Request $request, string $replenishment): JsonResponse
    {
        $r = $this->replenishment($replenishment);
        $r->assertIfMatch($request);
        $r = $this->petty->resolve($r, true, null, $request->user());

        return response()->json(['data' => ['code' => $r->code, 'status' => $r->status], 'message' => "Reposición {$r->code} aprobada"]);
    }

    public function rejectReplenishment(Request $request, string $replenishment): JsonResponse
    {
        $r = $this->replenishment($replenishment);
        $r->assertIfMatch($request);
        $r = $this->petty->resolve($r, false, $request->input('reason'), $request->user());

        return response()->json(['data' => ['code' => $r->code, 'status' => $r->status], 'message' => "Reposición {$r->code} rechazada"]);
    }

    public function payReplenishment(Request $request, string $replenishment): JsonResponse
    {
        $r = $this->petty->pay($this->replenishment($replenishment), $request->all(), $request->user());

        return response()->json(['data' => ['code' => $r->code, 'status' => $r->status], 'message' => "Reposición {$r->code} pagada"]);
    }

    public function cashFlow(Request $request, CashFlowService $flow): JsonResponse
    {
        return response()->json(['data' => $flow->cached((int) $request->query('horizon', 90), $request->query('include_overdue', '1') !== '0',
            $request->query('include_fixed', '1') !== '0') + ['recurring' => $this->ledger->settings()->recurring_outflows]]);
    }

    public function fxPreview(Request $request, FxValuationService $fx): JsonResponse
    {
        $close = $request->query('close_rate');

        return response()->json(['data' => $fx->preview($close !== null && $close !== '' ? (float) $close : null, $request->query('period')) + [
            'history' => $this->present->fxHistory(), 'can_register' => $request->user()->hasPermissionTo('finance.invoicing'),
            'today_rate' => $this->ledger->rate('USD')]]);
    }

    public function fxRegister(Request $request, FxValuationService $fx): JsonResponse
    {
        $result = $fx->register($request->all(), $request->user());

        return response()->json(['data' => $result, 'message' => "Diferencia cambiaria de {$result['period']} registrada (S/ {$result['diff_pen']})"], 201);
    }

    public function sync(Request $request, TreasurySync $sync): JsonResponse
    {
        $result = $sync->run($request->user());
        app(CashFlowService::class)->forget();

        return response()->json(['data' => $result, 'message' => $result['created'] ? "{$result['created']} movimiento(s) sincronizados" : 'Tesorería al día con Finanzas']);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->ledger->settings()->only(['petty_tolerance', 'max_petty_expense', 'sworn_statement_max', 'replenish_threshold_pct',
            'gateway_fee_pct', 'cash_deposit_hours', 'recurring_outflows', 'lock_version']) + ['can_edit' => $request->user()->hasPermissionTo('admin.settings.manage')]]);
    }

    public function saveSettings(Request $request, AuditLogger $audit): JsonResponse
    {
        $s = $this->ledger->settings();
        $before = json_encode($s->only(['petty_tolerance', 'max_petty_expense', 'sworn_statement_max', 'replenish_threshold_pct', 'gateway_fee_pct', 'cash_deposit_hours']));
        $num = function (string $key, float $min, float $max) use ($request, $s) {
            $v = $request->input($key, $s->{$key});
            if (! is_numeric($v) || (float) $v < $min || (float) $v > $max) {
                throw ApiException::invalid($key, "Valor fuera de rango ({$min}–{$max}).");
            }

            return $v;
        };
        $recurring = collect((array) $request->input('recurring_outflows', $s->recurring_outflows))->map(fn ($r) => ['concept' => mb_substr(trim((string) ($r['concept'] ?? '')), 0, 80),
            'amount' => round((float) ($r['amount'] ?? 0), 2), 'day' => max(1, min(28, (int) ($r['day'] ?? 1)))])->filter(fn ($r) => $r['concept'] !== '' && $r['amount'] > 0)->values()->all();
        $s->fill(['petty_tolerance' => $num('petty_tolerance', 0, 1000), 'max_petty_expense' => $num('max_petty_expense', 1, 10000), 'sworn_statement_max' => $num('sworn_statement_max', 0, 1000),
            'replenish_threshold_pct' => (int) $num('replenish_threshold_pct', 0, 100), 'gateway_fee_pct' => $num('gateway_fee_pct', 0, 20), 'cash_deposit_hours' => (int) $num('cash_deposit_hours', 1, 168),
            'recurring_outflows' => $recurring])->save();
        $audit->log('Configuración/treasury', 'treasury', 'actualizar', $before, json_encode($s->only(['petty_tolerance', 'max_petty_expense', 'sworn_statement_max', 'replenish_threshold_pct',
            'gateway_fee_pct', 'cash_deposit_hours'])), actor: $request->user());
        app(CashFlowService::class)->forget();

        return response()->json(['data' => $s->fresh(), 'message' => 'Parámetros de tesorería guardados']);
    }

    private function account(string $key): Account
    {
        return (Str::isUuid($key) ? Account::find($key) : Account::where('code', mb_strtoupper($key))->first()) ?? throw ApiException::notFound();
    }

    private function countModel(string $key): CashCount
    {
        return (Str::isUuid($key) ? CashCount::find($key) : CashCount::where('code', mb_strtoupper($key))->first()) ?? throw ApiException::notFound();
    }

    private function replenishment(string $key): Replenishment
    {
        return (Str::isUuid($key) ? Replenishment::find($key) : Replenishment::where('code', mb_strtoupper($key))->first()) ?? throw ApiException::notFound();
    }
}
