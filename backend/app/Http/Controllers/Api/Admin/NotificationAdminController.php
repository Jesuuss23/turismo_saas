<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Sys\NotifMessage;
use App\Services\Admin\NotificationAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Matriz, plantillas y log de notificaciones (PROC-059, spec 09 §6.5). */
class NotificationAdminController extends Controller
{
    public function __construct(private readonly NotificationAdmin $notifications) {}

    public function rules(): JsonResponse
    {
        return response()->json($this->notifications->rules());
    }

    public function updateRule(Request $request, string $event): JsonResponse
    {
        return response()->json($this->notifications->updateRule($event, $request->all(), $request->user()));
    }

    public function testRule(Request $request, string $event): JsonResponse
    {
        return response()->json($this->notifications->testRule($event, $request->user()));
    }

    public function template(string $event, string $channel): JsonResponse
    {
        return response()->json(['data' => $this->notifications->template($event, $channel)]);
    }

    public function saveTemplate(Request $request, string $event, string $channel): JsonResponse
    {
        return response()->json($this->notifications->saveTemplate($event, $channel, $request->all(), $request->user()));
    }

    public function restore(Request $request, string $event, string $channel): JsonResponse
    {
        return response()->json($this->notifications->restore($event, $channel, $request->user()));
    }

    public function testTemplate(Request $request, string $event, string $channel): JsonResponse
    {
        return response()->json($this->notifications->testTemplate($event, $channel, $request->all(), $request->user()));
    }

    public function messages(Request $request): JsonResponse
    {
        return response()->json($this->notifications->messages($request->only(['channel', 'status', 'event', 'q'])));
    }

    public function retry(Request $request, string $message): JsonResponse
    {
        return response()->json($this->notifications->retry($this->message($message), $request->user()));
    }

    public function retryFailed(Request $request): JsonResponse
    {
        return response()->json($this->notifications->retryFailed($request->user()));
    }

    public function fallback(Request $request, string $message): JsonResponse
    {
        return response()->json($this->notifications->fallback($this->message($message), $request->user()));
    }

    private function message(string $id): NotifMessage
    {
        return NotifMessage::find($id) ?? throw ApiException::notFound();
    }
}
