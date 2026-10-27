<?php

namespace App\Http\Controllers\Api\Channels;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Bookings\Reservation;
use App\Models\Channel\SupportTicket;
use App\Models\Ops\Voucher;
use App\Services\Channels\CmsService;
use App\Services\Channels\PassengerPortal;
use App\Services\Channels\SupportTickets;
use App\Services\Ops\PassengerApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal del pasajero (spec 08 §6.7): acceso por enlace, su viaje y secciones. Internos con `operations.assign` previsualizan con
 * `?reservation=` (como la app del pasajero); las escrituras quedan para el pasajero.
 */
class PassengerPortalController extends Controller
{
    public function __construct(private readonly PassengerPortal $portal, private readonly PassengerApp $app) {}

    public function requestLink(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->requestLink((string) $request->input('email', ''), (string) $request->input('code', ''))]);
    }

    public function verify(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->verify((string) $request->input('token', ''), $request->ip())]);
    }

    public function trip(Request $request, CmsService $cms): JsonResponse
    {
        $r = $this->reservation($request);

        return response()->json(['data' => $this->portal->trip($r, $request->user()) + [
            'banners' => $cms->forChannel(['Portal pasajero', 'App pasajero', 'Web / Portal'])->map(fn ($c) => ['code' => $c->code, 'title' => $c->title,
                'placement' => $c->placement, 'html' => CmsService::render((string) $c->body)])->values()->all(),
        ], 'meta' => $this->meta($request)]);
    }

    public function itinerary(Request $request): JsonResponse
    {
        $days = $this->app->itinerary($this->reservation($request));
        foreach ($days as &$day) {
            foreach ($day['services'] as &$s) {
                $s['visual'] = $this->portal->visual($s['status'], $s['date'], null);
            }
        }

        return response()->json(['data' => $days, 'meta' => $this->meta($request)]);
    }

    public function hotels(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->hotels($this->reservation($request)), 'meta' => $this->meta($request)]);
    }

    public function transport(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->transport($this->reservation($request)), 'meta' => $this->meta($request)]);
    }

    public function activities(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->activities($this->reservation($request)), 'meta' => $this->meta($request)]);
    }

    public function vouchers(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->app->vouchers($this->reservation($request))->map(fn (Voucher $v) => [
            'code' => $v->code, 'service' => $v->service_label, 'date' => $v->service_date->toDateString(), 'provider' => $v->provider?->name,
            'status' => $v->status, 'status_label' => Voucher::STATUS_LABELS[$v->status], 'version' => $v->version, 'pax' => $v->pax,
        ])->values()->all(), 'meta' => $this->meta($request)]);
    }

    public function voucher(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->portal->voucher($this->reservation($request), $code, $request->user())]);
    }

    public function documents(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->app->documents($this->reservation($request)), 'meta' => $this->meta($request)]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $r = $this->reservation($request);

        return response()->json(['data' => $this->portal->alerts($r, $request->user()), 'meta' => $this->meta($request) + [
            'messages' => $this->portal->inbox($r, $request->user())]]);
    }

    public function ack(Request $request, string $incident): JsonResponse
    {
        $r = $this->reservation($request, write: true);

        return response()->json(['data' => $this->portal->ack($r, $incident, $request->user())]);
    }

    public function contacts(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->contacts($this->reservation($request)), 'meta' => $this->meta($request)]);
    }

    public function inbox(Request $request): JsonResponse
    {
        $this->portal->ensureRule('R-PAX-MESSAGES');
        $r = $this->reservation($request);

        return response()->json(['data' => $this->portal->inbox($r, $request->user(), $request->query('channel')), 'meta' => $this->meta($request) + [
            'tickets' => SupportTicket::where('reservation_id', $r->id)->where('source', 'portal_pasajero')->orderByDesc('created_at')->get()
                ->map(fn (SupportTicket $t) => SupportTickets::row($t))->all(), 'topics' => SupportTicket::PAX_TOPICS]]);
    }

    /** PPX-BR-06: mensaje a operaciones → solicitud asignada al coordinador. */
    public function message(Request $request, SupportTickets $tickets): JsonResponse
    {
        $r = $this->reservation($request, write: true);

        return response()->json(['data' => SupportTickets::row($tickets->forPassenger($r, $request->all(), $request->user()))], 201);
    }

    public function read(Request $request, ?string $message = null): JsonResponse
    {
        $this->portal->ensureRule('R-PAX-MESSAGES');

        return response()->json(['data' => ['updated' => $this->portal->markRead($this->reservation($request, write: true), $request->user(), $message)]]);
    }

    public function feedbackList(Request $request): JsonResponse
    {
        $this->portal->ensureRule('R-PAX-FEEDBACK');
        $r = $this->reservation($request);

        return response()->json(['data' => $this->portal->feedbackList($r, $request->user()), 'meta' => $this->meta($request) + [
            'services' => $this->app->services($r)->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'date' => $s->service_date->toDateString()])->values()->all()]]);
    }

    public function feedback(Request $request): JsonResponse
    {
        $this->portal->ensureRule('R-PAX-FEEDBACK');
        $f = $this->portal->feedback($this->reservation($request, write: true), $request->all(), $request->user());

        return response()->json(['data' => ['id' => $f->id, 'code' => $f->code, 'status' => $f->status, 'ticket' => $f->ticket_id !== null]], 201);
    }

    public function companions(Request $request): JsonResponse
    {
        $this->portal->ensureRule('R-COMPANIONS');

        return response()->json(['data' => $this->portal->companions($this->reservation($request)), 'meta' => $this->meta($request)]);
    }

    public function updateCompanions(Request $request): JsonResponse
    {
        $this->portal->ensureRule('R-COMPANIONS');
        $r = $this->reservation($request, write: true)->assertIfMatch($request);

        return response()->json(['data' => $this->portal->updateCompanions($r, (array) $request->input('passengers', []), $request->user())]);
    }

    private function reservation(Request $request, bool $write = false): Reservation
    {
        $user = $request->user();
        if ($write && ! $user->isExternal()) {
            throw new ApiException(403, 'PREVIEW_READ_ONLY', 'PREVIEW_READ_ONLY', 'Vista previa de solo lectura: estas acciones las realiza el pasajero.');
        }

        return $this->portal->reservation($user, $request->query('reservation'));
    }

    private function meta(Request $request): array
    {
        return ['preview' => ! $request->user()->isExternal(), 'rules' => $this->portal->rules()];
    }
}
