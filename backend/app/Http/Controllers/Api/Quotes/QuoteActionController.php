<?php

namespace App\Http\Controllers\Api\Quotes;

use App\Models\Quotes\QuoteNegotiation;
use App\Services\Quotes\QuoteWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Spec 03 §6: aprobación interna, enlace, envío, vista, negociación y respuesta del cliente registrada por un interno. */
class QuoteActionController extends QuoteController
{
    public function requestApproval(Request $request, string $code, QuoteWorkflow $workflow): JsonResponse
    {
        $quote = $this->find($code, $request);
        $data = $request->validate(['note' => 'required|string|max:2000'], ['note.required' => 'La justificación comercial es obligatoria.']);
        $workflow->requestApproval($quote, $data['note'], $this->actor($request));

        return $this->detail($request, $quote->code, 201);
    }

    public function decideApproval(Request $request, string $code, int $versionNo, QuoteWorkflow $workflow): JsonResponse
    {
        $quote = $this->find($code, $request);
        $quote->assertIfMatch($request);
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'required|string|max:2000'],
            ['note.required' => 'La justificación de la decisión es obligatoria.']);
        $workflow->decideApproval($quote, $this->version($quote, $versionNo), $data['decision'], $data['note'], $this->actor($request));

        return $this->detail($request, $quote->code);
    }

    public function token(Request $request, string $code, QuoteWorkflow $workflow): JsonResponse
    {
        $quote = $this->find($code, $request);
        [$token, $plain] = $workflow->token($quote, $this->actor($request));

        return response()->json(['data' => ['url' => QuoteWorkflow::portalUrl($plain), 'expires_at' => $token->expires_at->toDateString()]], 201);
    }

    public function send(Request $request, string $code, QuoteWorkflow $workflow): JsonResponse
    {
        $quote = $this->find($code, $request);
        $quote->assertIfMatch($request);
        $data = $request->validate([
            'channels' => 'required|array|min:1', 'channels.*' => ['required', Rule::in(QuoteWorkflow::CHANNELS)], 'message' => 'nullable|string|max:2000',
        ], ['channels.required' => 'Elija al menos un canal de envío.', 'channels.min' => 'Elija al menos un canal de envío.']);
        $workflow->send($quote, $data['channels'], $data['message'] ?? null, $this->actor($request));

        return $this->detail($request, $quote->code);
    }

    public function registerView(Request $request, string $code, QuoteWorkflow $workflow): JsonResponse
    {
        $quote = $this->find($code, $request);
        $workflow->registerView($quote, 'manual', $this->actor($request));

        return $this->detail($request, $quote->code, 201);
    }

    public function negotiate(Request $request, string $code, QuoteWorkflow $workflow): JsonResponse
    {
        $quote = $this->find($code, $request);
        $data = $request->validate([
            'channel' => ['required', Rule::in(QuoteNegotiation::CHANNELS)], 'note' => 'required|string|max:5000', 'create_version' => 'boolean',
        ], ['note.required' => 'Registre la contrapropuesta del cliente.']);
        $workflow->negotiate($quote, $data['channel'], $data['note'], (bool) ($data['create_version'] ?? false), $this->actor($request));

        return $this->detail($request, $quote->code, 201);
    }

    /** Registrar la aprobación del cliente (`reservations.create`, §11-3). QTE-BR-13 admite registrar la interna en el mismo paso. */
    public function approve(Request $request, string $code, QuoteWorkflow $workflow): JsonResponse
    {
        $quote = $this->find($code, $request);
        $quote->assertIfMatch($request);
        $data = $request->validate([
            'approved_by_name' => 'required|string|max:150',
            'channel' => ['required', Rule::in(QuoteWorkflow::EVIDENCE)],
            'ref' => 'required|string|max:200',
            'internal_approval' => 'nullable|array',
            'internal_approval.note' => 'required_with:internal_approval|string|max:2000',
        ], ['ref.required' => 'Indique la referencia de la evidencia (asunto del correo, N.º de OC, etc.).']);
        $workflow->approve($quote, $data + ['internal_note' => $data['internal_approval']['note'] ?? null], $this->actor($request));

        return $this->detail($request, $quote->code);
    }

    public function reject(Request $request, string $code, QuoteWorkflow $workflow): JsonResponse
    {
        $quote = $this->find($code, $request);
        $quote->assertIfMatch($request);
        $data = $request->validate(['reason' => 'required|string|max:2000'], ['reason.required' => 'El motivo del rechazo es obligatorio.']);
        $workflow->reject($quote, $data['reason'], $this->actor($request));

        return $this->detail($request, $quote->code);
    }

    private function detail(Request $request, string $code, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->present->detail($this->find($code, $request), $this->actor($request))], $status);
    }
}
