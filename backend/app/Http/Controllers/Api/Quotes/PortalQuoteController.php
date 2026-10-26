<?php

namespace App\Http\Controllers\Api\Quotes;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Quotes\QuotePresenter;
use App\Models\Iam\User;
use App\Models\Quotes\Quote;
use App\Services\Quotes\QuoteAccess;
use App\Services\Quotes\QuoteWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Portal del cliente (§2, canales): sus cotizaciones enviadas; abrir (→ vista), aprobar o rechazar. */
class PortalQuoteController extends Controller
{
    public function __construct(
        private readonly QuoteAccess $access,
        private readonly QuotePresenter $present,
        private readonly QuoteWorkflow $workflow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $quotes = $this->access->scope(Quote::query(), $user)->with('currentVersion')->orderByDesc('created_at')->get();

        return response()->json(['data' => $quotes->map(fn (Quote $q) => [
            'code' => $q->code, 'title' => $q->title, ...$this->present->status($q), 'currency' => $q->currency,
            'total' => (string) $q->currentVersion?->grand_total, 'valid_until' => $q->valid_until->toDateString(), 'travel_from' => $q->travel_from->toDateString(),
        ])->all()]);
    }

    /** Abrir registra la vista (QTE-BR-22) la primera vez que se ve cada versión. */
    public function show(Request $request, string $code): JsonResponse
    {
        $quote = $this->find($request, $code);
        if (! $quote->views()->where('version_no', $quote->current_version_no)->where('source', 'portal')->exists()) {
            try {
                $quote = $this->workflow->registerView($quote, 'portal', null, $request->ip(), $request->userAgent());
            } catch (Throwable) {
                // Sin enlace generado no se registra la vista; el cliente igual ve su cotización.
            }
        }

        return response()->json(['data' => $this->present->portal($quote)]);
    }

    public function approve(Request $request, string $code): JsonResponse
    {
        $quote = $this->find($request, $code);
        $data = $request->validate(['accept_conditions' => 'accepted', 'approved_by_name' => 'nullable|string|max:150'],
            ['accept_conditions.accepted' => 'Debe aceptar las condiciones para aprobar.']);
        $user = $this->customer($request);
        $quote = $this->workflow->approve($quote, [
            'approved_by_name' => $data['approved_by_name'] ?? $user->name, 'channel' => 'Portal cliente', 'ref' => "Usuario {$user->email}", 'ip' => $request->ip(),
        ], null, portal: true);

        return response()->json(['data' => $this->present->portal($quote)]);
    }

    public function reject(Request $request, string $code): JsonResponse
    {
        $quote = $this->find($request, $code);
        $data = $request->validate(['reason' => 'required|string|max:2000'], ['reason.required' => 'Cuéntenos el motivo del rechazo.']);

        return response()->json(['data' => $this->present->portal($this->workflow->reject($quote, $data['reason'], null, portal: true))]);
    }

    private function customer(Request $request): User
    {
        $user = $request->user();
        if (! $user->client_id) {
            throw ApiException::forbidden('SEC-BR-05', 'Esta sección es solo para clientes');
        }

        return $user;
    }

    private function find(Request $request, string $code): Quote
    {
        $user = $this->customer($request);

        return $this->access->scope(Quote::where('code', $code), $user)->first() ?? throw ApiException::notFound();
    }
}
