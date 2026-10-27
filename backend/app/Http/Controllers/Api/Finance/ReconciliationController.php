<?php

namespace App\Http\Controllers\Api\Finance;

use App\Exceptions\ApiException;
use App\Models\Finance\BankAccount;
use App\Models\Finance\BankLine;
use App\Models\Finance\Payment;
use App\Models\Finance\ReconciliationClosure;
use App\Models\Iam\User;
use App\Services\Finance\Reconciliation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** §6.7 Conciliación bancaria (PROC-049). */
class ReconciliationController extends FinanceController
{
    public function lines(Request $request, Reconciliation $reconciliation): JsonResponse
    {
        $scope = $request->input('filter.scope', 'abiertas');
        $rows = BankLine::with(['payment', 'payable.provider:id,name'])
            ->when($scope === 'abiertas', fn ($q) => $q->whereNull('closure_id'), fn ($q) => $scope === 'cerradas' ? $q->whereNotNull('closure_id') : $q)
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('value_date')->orderBy('created_at')->limit(500)->get();
        $open = BankLine::whereNull('closure_id')->get();

        return response()->json(['data' => $rows->map(fn (BankLine $l) => $this->present->bankLine($l) + ($l->status === 'excepcion' && ! $l->closure_id ? [
            'candidates' => $reconciliation->candidates($l)->take(15)->map(fn ($c) => ['id' => $c->id, 'code' => $c->code,
                'label' => $c instanceof Payment ? "{$c->method} · {$c->reference}" : (string) $c->provider?->name,
                'amount_pen' => number_format($reconciliation->candidateAmount($c), 2, '.', '')])->values()->all()] : []))->all(),
            'meta' => ['counts' => ['pendiente' => $open->where('status', 'pendiente')->count(), 'sugerido' => $open->where('status', 'sugerido')->count(),
                'excepcion' => $open->where('status', 'excepcion')->count(), 'conciliado' => $open->where('status', 'conciliado')->count()],
                'banks' => Reconciliation::BANKS, 'accounts' => BankAccount::where('active', true)->orderBy('bank')->get(['id', 'bank', 'label', 'currency'])]]);
    }

    public function import(Request $request, Reconciliation $reconciliation): JsonResponse
    {
        $request->validate(['bank' => 'required|string', 'bank_account_id' => 'nullable|uuid'], ['bank.required' => 'Seleccione el banco.']);
        // Archivo multipart o, desde el back-office, JSON `{file_name, content_base64}` como el resto de adjuntos.
        $upload = $request->file('file');
        $json = is_array($request->input('file')) ? $request->input('file') : null;
        $file = match (true) {
            $upload !== null => ['name' => $upload->getClientOriginalName(), 'content' => (string) file_get_contents($upload->getRealPath())],
            $json !== null => ['name' => (string) ($json['file_name'] ?? 'extracto.csv'), 'content' => (string) base64_decode((string) ($json['content_base64'] ?? ''), true)],
            default => null,
        };
        if ($file && (! preg_match('/\.(csv|txt)$/i', $file['name']) || strlen($file['content']) > 5 * 1024 * 1024)) {
            throw ApiException::invalid('file', 'El extracto debe ser CSV o TXT de hasta 5 MB.');
        }
        $statement = $reconciliation->import($request->input('bank'), $request->input('bank_account_id'), $file, $this->actor($request));

        return response()->json(['data' => ['filename' => $statement->filename, 'lines' => $statement->lines_count]], 201);
    }

    public function match(Request $request, Reconciliation $reconciliation): JsonResponse
    {
        return response()->json(['data' => $reconciliation->match($this->actor($request))]);
    }

    public function confirm(Request $request, Reconciliation $reconciliation, string $code): JsonResponse
    {
        return $this->line($reconciliation->confirm($this->byCode(BankLine::class, $code), $this->actor($request)));
    }

    public function reject(Request $request, Reconciliation $reconciliation, string $code): JsonResponse
    {
        return $this->line($reconciliation->reject($this->byCode(BankLine::class, $code), $this->actor($request)));
    }

    public function resolve(Request $request, Reconciliation $reconciliation, string $code): JsonResponse
    {
        return $this->line($reconciliation->resolve($this->byCode(BankLine::class, $code), $request->all(), $this->actor($request)));
    }

    public function closures(): JsonResponse
    {
        return response()->json(['data' => ReconciliationClosure::orderByDesc('closed_at')->get()->map(fn (ReconciliationClosure $c) => ['code' => $c->code,
            'period' => $c->period, 'closed_at' => $c->closed_at?->toIso8601String(), 'closed_by' => User::find($c->closed_by)?->name, 'lines' => $c->lines_count,
            'credits' => (string) $c->credits, 'debits' => (string) $c->debits, 'net' => (string) $c->net, 'matched' => $c->matched_count, 'others' => $c->others_count])->all()]);
    }

    public function close(Request $request, Reconciliation $reconciliation): JsonResponse
    {
        $c = $reconciliation->close($this->actor($request));

        return response()->json(['data' => ['code' => $c->code, 'lines' => $c->lines_count, 'net' => (string) $c->net]], 201);
    }

    private function line(BankLine $l): JsonResponse
    {
        return response()->json(['data' => $this->present->bankLine($l->load(['payment', 'payable.provider']))]);
    }
}
