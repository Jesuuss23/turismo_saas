<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Bookings\Reservation;
use App\Models\Ops\TripMessage;
use App\Models\Ops\Voucher;
use App\Models\Sys\NotifMessage;
use App\Services\Ops\OpsAccess;
use App\Services\Ops\PassengerApp;
use App\Services\Ops\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Spec 05 §6.8: app del pasajero (PROC-057), solo su reserva (PAX-BR-01); internos previsualizan con `?reservation=`. */
class PassengerController extends Controller
{
    public function __construct(private readonly PassengerApp $app) {}

    public function trip(Request $request): JsonResponse
    {
        $r = $this->reservation($request);

        return response()->json(['data' => $this->app->trip($r), 'meta' => $this->previewMeta($request)]);
    }

    public function itinerary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->app->itinerary($this->reservation($request))]);
    }

    public function vouchers(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->app->vouchers($this->reservation($request))->map(fn (Voucher $v) => [
            'id' => $v->id, 'code' => $v->code, 'service' => $v->service_label, 'date' => $v->service_date->toDateString(), 'provider' => $v->provider?->name,
            'status' => $v->status, 'status_label' => Voucher::STATUS_LABELS[$v->status], 'version' => $v->version, 'pax' => $v->pax,
        ])->values()->all()]);
    }

    public function voucher(Request $request, VoucherService $vouchers, string $voucher): JsonResponse
    {
        $r = $this->reservation($request);
        $v = Voucher::where('reservation_id', $r->id)->where('status', '<>', Voucher::VOIDED)->where(fn ($q) => $q->where('code', $voucher)->orWhere('id', preg_match('/^[0-9a-f-]{36}$/i', $voucher) ? $voucher : null))
            ->with('provider:id,name')->first() ?? throw ApiException::notFound();

        return response()->json(['data' => ['id' => $v->id, 'code' => $v->code, 'status' => $v->status, 'status_label' => Voucher::STATUS_LABELS[$v->status],
            'version' => $v->version, 'qr_token' => $v->qr_token, 'document' => $vouchers->snapshot($v)]]);
    }

    public function documents(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->app->documents($this->reservation($request))]);
    }

    /** Documentos generados al vuelo (§12-23): itinerario, confirmación y políticas como texto imprimible. */
    public function download(Request $request, string $document): JsonResponse
    {
        $r = $this->reservation($request);
        $doc = collect($this->app->documents($r))->firstWhere('key', $document);
        if (! $doc || ! $doc['available']) {
            throw ApiException::notFound();
        }

        return response()->json(['data' => $doc + ['reservation' => $r->code, 'url' => "/mobile/pasajero?tab=docs&doc={$document}",
            'content' => match ($document) {
                'itinerary' => $this->app->itinerary($r),
                'confirmation' => ['code' => $r->code, 'title' => $r->title, 'travel_from' => $r->travel_from->toDateString(), 'travel_to' => $r->travel_to->toDateString(),
                    'status' => Reservation::STATUS_LABELS[$r->status] ?? $r->status],
                'policies' => ['text' => $r->cancellation_policy ?? 'Cambios y cancelaciones según las condiciones de su confirmación de reserva.'],
                default => null,
            }]]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $r = $this->reservation($request);
        $alerts = $this->app->alerts($request->user(), $r);

        return response()->json(['data' => $alerts->map(fn (NotifMessage $n) => ['id' => $n->id, 'event' => $n->event, 'subject' => $n->subject, 'body' => $n->body,
            'at' => $n->created_at->toIso8601String(), 'read' => (bool) $n->read_at])->values()->all(),
            'meta' => ['unread' => $alerts->whereNull('read_at')->count()]]);
    }

    public function readAlert(Request $request, string $alert): JsonResponse
    {
        $this->app->markRead($request->user(), $this->reservation($request), $alert);

        return response()->json(['data' => null]);
    }

    public function messages(Request $request): JsonResponse
    {
        $r = $this->reservation($request);

        return response()->json(['data' => $this->app->messages($r, $request->user())->map(fn (TripMessage $m) => $this->message($m))->values()->all(),
            'meta' => ['quick_replies' => PassengerApp::QUICK_REPLIES]]);
    }

    public function postMessage(Request $request): JsonResponse
    {
        $r = $this->reservation($request);
        $data = $request->validate(['body' => 'required|string|max:2000', 'quick_reply' => 'boolean'], ['body.required' => 'Escriba su mensaje.']);

        return response()->json(['data' => $this->message($this->app->postMessage($r, $request->user(), $data['body'], (bool) ($data['quick_reply'] ?? false)))], 201);
    }

    public function support(Request $request): JsonResponse
    {
        $r = $this->reservation($request);

        return response()->json(['data' => ['emergency_phone' => $this->app->emergencyNumber(), 'reservation' => $r->code,
            'hours' => '24/7', 'whatsapp' => preg_replace('/\D/', '', $this->app->emergencyNumber())]]);
    }

    private function reservation(Request $request): Reservation
    {
        return $this->app->reservationFor($request->user(), $request->query('reservation'));
    }

    private function message(TripMessage $m): array
    {
        return ['id' => $m->id, 'sender' => $m->sender_type, 'body' => $m->body, 'quick' => $m->quick_reply, 'at' => $m->created_at->toIso8601String(), 'read' => (bool) $m->read_at];
    }

    private function previewMeta(Request $request): array
    {
        $user = $request->user();
        if ($user->isExternal() || ! $user->hasPermissionTo(OpsAccess::ASSIGN)) {
            return ['preview' => false];
        }

        return ['preview' => true, 'reservations' => Reservation::whereIn('status', [Reservation::CONFIRMED, Reservation::IN_OPERATION, Reservation::COMPLETED])
            ->orderByDesc('travel_from')->limit(30)->get(['code', 'title'])->map(fn ($r) => ['code' => $r->code, 'title' => $r->title])->all()];
    }
}
