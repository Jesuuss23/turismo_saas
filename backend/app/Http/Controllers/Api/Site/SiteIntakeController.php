<?php

namespace App\Http\Controllers\Api\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\SiteIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Captación y analítica del sitio (spec 15 §6.4): leads, contacto, chat web, newsletter y eventos del embudo. */
class SiteIntakeController extends Controller
{
    public function __construct(private readonly SiteIntake $intake) {}

    public function lead(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->intake->lead($request->all())], 201);
    }

    public function contact(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->intake->contact($request->all())], 201);
    }

    public function chatStart(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->intake->chatStart($request->all())], 201);
    }

    public function chatMessages(string $session): JsonResponse
    {
        return response()->json(['data' => $this->intake->chatMessages($session)]);
    }

    public function chatPost(Request $request, string $session): JsonResponse
    {
        return response()->json(['data' => $this->intake->chatPost($session, (string) $request->input('message', ''))], 201);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $result = $this->intake->subscribe($request->all(), $request->ip());

        return response()->json(['data' => $result], $result['status'] === 'ya_suscrito' ? 200 : 201);
    }

    public function confirm(string $token): JsonResponse
    {
        return response()->json(['data' => $this->intake->confirmSubscription($token)]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->intake->unsubscribe((string) $request->input('email', ''))]);
    }

    public function events(Request $request): JsonResponse
    {
        $user = $request->bearerToken() ? auth('sanctum')->user() : null;

        return response()->json(['data' => ['stored' => $this->intake->events((array) $request->input('events', []), $user?->client_id)]], 202);
    }

    public function recent(string $session): JsonResponse
    {
        return response()->json(['data' => $this->intake->recentEvents($session)]);
    }
}
