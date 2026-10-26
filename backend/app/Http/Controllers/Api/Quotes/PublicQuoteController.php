<?php

namespace App\Http\Controllers\Api\Quotes;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Quotes\QuotePresenter;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteAccessToken;
use App\Services\Quotes\QuoteWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Enlace público del cliente (`/c/{token}`, §6 public): sin sesión. El token se busca por hash fuera de RLS
 * (solo para conocer el tenant) y todo lo demás corre dentro del contexto de ese tenant.
 */
class PublicQuoteController extends Controller
{
    public function __construct(
        private readonly QuotePresenter $present,
        private readonly QuoteWorkflow $workflow,
        private readonly TenantContext $tenant,
    ) {}

    public function show(Request $request, string $token): JsonResponse
    {
        return $this->withQuote($token, function (Quote $quote) use ($request) {
            if (in_array($quote->status, Quote::FOLLOW_UP, true) && ! $quote->views()->where('version_no', $quote->current_version_no)->where('source', 'portal')->exists()) {
                $quote = $this->workflow->registerView($quote, 'portal', null, $request->ip(), $request->userAgent());
            }

            return response()->json(['data' => $this->present->portal($quote) + ['agency' => $this->tenant->tenant()?->trade_name]]);
        });
    }

    public function approve(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['accept_conditions' => 'accepted', 'approved_by_name' => 'required|string|max:150'], [
            'accept_conditions.accepted' => 'Debe aceptar las condiciones para aprobar.', 'approved_by_name.required' => 'Indique su nombre.',
        ]);

        return $this->withQuote($token, fn (Quote $quote) => response()->json(['data' => $this->present->portal($this->workflow->approve($quote, [
            'approved_by_name' => $data['approved_by_name'], 'channel' => 'Aceptación en portal (enlace con token)', 'ref' => 'Enlace '.substr(QuoteAccessToken::hash($token), 0, 12), 'ip' => $request->ip(),
        ], null, portal: true))]));
    }

    public function reject(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:2000'], ['reason.required' => 'Cuéntenos el motivo del rechazo.']);

        return $this->withQuote($token, fn (Quote $quote) => response()->json(['data' => $this->present->portal($this->workflow->reject($quote, $data['reason'], null, portal: true))]));
    }

    private function withQuote(string $token, callable $fn): JsonResponse
    {
        $row = DB::connection('pgsql_admin')->table('quote_access_tokens')->where('token_hash', QuoteAccessToken::hash($token))->whereNull('revoked_at')->first(['tenant_id', 'quote_id']);
        if ($row === null) {
            throw new ApiException(404, 'RESOURCE_NOT_FOUND', 'QTE-TOKEN', 'El enlace no es válido o fue reemplazado por uno nuevo');
        }

        return $this->tenant->run($row->tenant_id, function () use ($row, $fn) {
            $quote = Quote::find($row->quote_id) ?? throw ApiException::notFound();

            return $fn($quote);
        });
    }
}
