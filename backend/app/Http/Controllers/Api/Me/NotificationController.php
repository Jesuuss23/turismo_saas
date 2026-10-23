<?php

namespace App\Http\Controllers\Api\Me;

use App\Http\Controllers\Controller;
use App\Models\Sys\NotifMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Campana: últimas 25 notificaciones del usuario (canal portal). */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $mine = NotifMessage::where('user_id', $request->user()->getKey())->where('channel', 'portal');

        return response()->json([
            'data' => (clone $mine)->latest()->limit(25)->get()->map(fn (NotifMessage $n) => [
                'id' => $n->id,
                'code' => $n->displayCode(),
                'event' => $n->event,
                'subject' => $n->subject,
                'body' => $n->body,
                'data' => $n->data,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at,
            ]),
            'unread_count' => (clone $mine)->whereNull('read_at')->count(),
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = NotifMessage::where('user_id', $request->user()->getKey())->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['marked' => $updated]);
    }
}
