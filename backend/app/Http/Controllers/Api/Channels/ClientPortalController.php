<?php

namespace App\Http\Controllers\Api\Channels;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Channel\SupportTicket;
use App\Models\Crm\Client;
use App\Services\Channels\ClientPortal;
use App\Services\Channels\CmsService;
use App\Services\Channels\SupportTickets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal del cliente (spec 08 §6.6): `/portal/client/*` para el cliente (PCL-BR-01) y `/channels/portal-preview/clients/{client}/*`
 * para la previsualización interna de solo lectura (PCL-BR-10: no marca vista, no aprueba, no paga).
 */
class ClientPortalController extends Controller
{
    public function __construct(private readonly ClientPortal $portal) {}

    public function previewClients(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->previewable($request->user())->orderBy('name')->limit(200)->get(['id', 'code', 'name', 'email'])
            ->map(fn (Client $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'email' => $c->email])->all()]);
    }

    public function home(Request $request, CmsService $cms): JsonResponse
    {
        $client = $this->client($request);

        return response()->json(['data' => $this->portal->home($client) + [
            'banners' => $cms->forChannel(['Portal cliente', 'Web / Portal'])->map(fn ($c) => ['code' => $c->code, 'title' => $c->title, 'type' => $c->type,
                'placement' => $c->placement, 'html' => CmsService::render((string) $c->body)])->values()->all(),
        ], 'meta' => $this->meta($request)]);
    }

    public function quotes(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->quotes($this->client($request)), 'meta' => $this->meta($request)]);
    }

    public function quote(Request $request): JsonResponse
    {
        // En la vista previa la ruta también lleva {client}: el código se toma por nombre.
        $quote = $this->portal->quote($this->client($request), (string) $request->route('code'));
        // PCL-BR-06: abrir el detalle marca `vista` solo si el actor es el cliente.
        $quote = $this->portal->view($quote, $request->user(), $request->ip(), $request->userAgent());

        return response()->json(['data' => $this->portal->presentQuote($quote), 'meta' => $this->meta($request)]);
    }

    public function view(Request $request, string $code): JsonResponse
    {
        $quote = $this->portal->view($this->portal->quote($this->client($request), $code), $request->user(), $request->ip(), $request->userAgent());

        return response()->json(['data' => $this->portal->presentQuote($quote)]);
    }

    public function approve(Request $request, string $code): JsonResponse
    {
        $quote = $this->portal->quote($this->client($request), $code)->assertIfMatch($request);

        return response()->json(['data' => $this->portal->presentQuote($this->portal->approve($quote, $request->all(), $request->user(), $request->ip()))]);
    }

    public function reject(Request $request, string $code): JsonResponse
    {
        $quote = $this->portal->quote($this->client($request), $code)->assertIfMatch($request);

        return response()->json(['data' => $this->portal->presentQuote($this->portal->reject($quote, $request->all()))]);
    }

    public function updateRequest(Request $request, string $code): JsonResponse
    {
        $client = $this->client($request);
        $ticket = $this->portal->requestUpdate($client, $this->portal->quote($client, $code), $request->all(), $request->user());

        return response()->json(['data' => SupportTickets::row($ticket)], 201);
    }

    public function reservations(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->reservations($this->client($request)), 'meta' => $this->meta($request)]);
    }

    public function reservation(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->reservation($this->client($request), (string) $request->route('code')), 'meta' => $this->meta($request)]);
    }

    public function documents(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->documents($this->client($request)), 'meta' => $this->meta($request)]);
    }

    public function receivables(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->receivables($this->client($request)), 'meta' => $this->meta($request)]);
    }

    public function payments(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->paymentHistory($this->client($request)), 'meta' => $this->meta($request)]);
    }

    public function pay(Request $request, string $receivable): JsonResponse
    {
        $result = $this->portal->pay($this->client($request), $receivable, $request->all(), $request->user());

        return response()->json(['data' => $result], $result['result'] === 'aprobado' ? 201 : 200);
    }

    public function notifications(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->notifications($this->client($request))->values()->all(), 'meta' => $this->meta($request)]);
    }

    public function tickets(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->ticketList($this->client($request)), 'meta' => $this->meta($request) + [
            'categories' => SupportTicket::CLIENT_CATEGORIES]]);
    }

    public function storeTicket(Request $request): JsonResponse
    {
        return response()->json(['data' => SupportTickets::row($this->portal->createTicket($this->client($request), $request->all(), $request->user()))], 201);
    }

    /** Cliente: su cuenta (cualquier otro id se ignora). Interno: el cliente de la ruta, dentro de su cartera (solo GET). */
    private function client(Request $request): Client
    {
        $user = $request->user();
        $id = $request->route('client');
        if ($user->isExternal() || $id === null) {
            if (! $user->isExternal()) {
                throw ApiException::forbidden('PREVIEW_READ_ONLY', 'Vista previa de solo lectura: el cliente responde y paga desde su propio usuario.');
            }

            return $this->portal->clientOf($user);
        }

        return $this->portal->previewClient($user, (string) $id);
    }

    private function meta(Request $request): array
    {
        return ['preview' => ! $request->user()->isExternal()];
    }
}
