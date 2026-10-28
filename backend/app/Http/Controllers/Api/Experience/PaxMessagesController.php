<?php

namespace App\Http\Controllers\Api\Experience;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Bookings\Reservation;
use App\Models\Experience\PaxMessage;
use App\Models\Experience\PaxMessageTemplate;
use App\Services\AuditLogger;
use App\Services\Experience\PaxMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mensajes al pasajero (spec 17 §6): plantillas (`admin.settings.manage`; lectura y vista previa también `operations.assign`),
 * programación, historial y acciones (`admin.settings.manage | operations.assign`, MSG-03).
 */
class PaxMessagesController extends Controller
{
    public function __construct(private readonly PaxMessageService $messages) {}

    public function templates(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->messages->templates(), 'meta' => $this->messages->meta() + [
            'can_edit' => $request->user()->hasPermissionTo('admin.settings.manage'),
        ]]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->messages->saveTemplate($request->all(), $request->user())], 201);
    }

    public function updateTemplate(Request $request, string $id): JsonResponse
    {
        $template = $this->template($id)->assertIfMatch($request);

        return response()->json(['data' => $this->messages->saveTemplate($request->all(), $request->user(), $template)]);
    }

    public function destroyTemplate(Request $request, string $id): JsonResponse
    {
        $this->messages->deleteTemplate($this->template($id), $request->user());

        return response()->json(null, 204);
    }

    public function duplicate(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->messages->duplicate($this->template($id), (string) $request->input('lang'), $request->user())], 201);
    }

    public function preview(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->messages->preview($this->template($id), $request->input('reservation'), (string) $request->input('channel', 'email'))]);
    }

    public function test(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->messages->test($this->template($id), $request->user(), $request->input('channel'))]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = (array) $request->query('filter', []) + $request->only(['view', 'status', 'channel', 'origin', 'reservation']);

        return response()->json(['data' => $this->messages->messages($filters)]);
    }

    public function reservations(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->messages->reservations($request->query('q'))]);
    }

    public function reschedule(Request $request): JsonResponse
    {
        $stats = $this->messages->schedule();
        app(AuditLogger::class)->log('Mensaje al pasajero', 'programacion', 'recalcular', null,
            "{$stats['scheduled']} nuevos · {$stats['updated']} actualizados · {$stats['cancelled']} retirados", actor: $request->user());

        return response()->json(['data' => $stats]);
    }

    public function syncAcks(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->messages->syncAcks($request->user())]);
    }

    public function action(Request $request, string $id, string $action): JsonResponse
    {
        $msg = PaxMessage::whereKey($id)->first() ?? throw ApiException::notFound();

        return response()->json(['data' => match ($action) {
            'send-now' => $this->messages->sendNow($msg, $request->user()),
            'cancel' => $this->messages->cancel($msg, $request->user()),
            default => $this->messages->retry($msg, $request->user()),
        }]);
    }

    public function manual(Request $request, string $reservation): JsonResponse
    {
        $r = Reservation::where(fn ($q) => $q->where('code', $reservation)->orWhere('id', preg_match('/^[0-9a-f-]{36}$/', $reservation) ? $reservation : null))->first()
            ?? throw ApiException::notFound();

        return response()->json(['data' => $this->messages->manual($r, $request->all(), $request->user())], 201);
    }

    private function template(string $id): PaxMessageTemplate
    {
        return PaxMessageTemplate::where(fn ($q) => $q->where('code', $id)->orWhere('id', preg_match('/^[0-9a-f-]{36}$/', $id) ? $id : null))->first() ?? throw ApiException::notFound();
    }
}
