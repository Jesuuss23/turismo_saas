<?php

namespace App\Http\Controllers\Api\Experience;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Experience\OwnReview;
use App\Models\Experience\ReviewRequest;
use App\Services\Channels\PassengerPortal;
use App\Services\Experience\OwnReviewService;
use App\Services\Experience\ReviewRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reseñas propias verificadas (spec 18 §6): bandeja y moderación (`channels.ecommerce.manage`; Ventas en solo lectura y con su cartera),
 * solicitudes, ajustes, feed del widget, formulario del pasajero (portal y app) y salida pública.
 */
class OwnReviewsController extends Controller
{
    public function __construct(private readonly OwnReviewService $reviews, private readonly ReviewRequestService $requests) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reviews->list($request->only(['status', 'stars', 'product', 'q', 'tab', 'flagged']), $request->user()),
            'meta' => $this->reviews->config($request->user())]);
    }

    public function stats(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reviews->stats($request->user()), 'meta' => $this->reviews->config($request->user())]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->reviews->show($this->reviews->find($code, $request->user()))]);
    }

    public function publish(Request $request, string $code): JsonResponse
    {
        $review = $this->locked($request, $code);

        return response()->json(['data' => $this->reviews->publish($review, filter_var($request->input('confirm_flags', false), FILTER_VALIDATE_BOOLEAN), $request->user())]);
    }

    public function reject(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->reviews->reject($this->locked($request, $code), (string) $request->input('reason', ''), $request->user())]);
    }

    public function hide(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->reviews->hide($this->locked($request, $code), (string) $request->input('reason', ''), $request->user())]);
    }

    public function reply(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->reviews->reply($this->locked($request, $code), (string) $request->input('text', ''), $request->user())]);
    }

    public function exportFeed(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reviews->exportFeed($request->user())]);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reviews->config($request->user())]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reviews->saveSettings($request->all(), $request->user())]);
    }

    // --- solicitudes ---

    public function requests(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->requests->list($request->only(['status']), $request->user()), 'meta' => $this->reviews->config($request->user())]);
    }

    public function schedulePending(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->requests->schedulePending($request->user())]);
    }

    public function requestAction(Request $request, string $code, string $action): JsonResponse
    {
        $req = ReviewRequest::where('code', $code)->first() ?? throw ApiException::notFound();

        return response()->json(['data' => match ($action) {
            'send' => $this->requests->send($req, $request->user()),
            'cancel' => $this->requests->cancel($req, $request->user()),
            default => $this->requests->preview($req),
        }]);
    }

    // --- pasajero (portal y app) ---

    public function eligibility(Request $request, PassengerPortal $portal): JsonResponse
    {
        $r = $portal->reservation($request->user(), $request->query('reservation'));

        return response()->json(['data' => $this->reviews->eligibility($r, $this->reviews->passengerFor($r, $request->user())),
            'meta' => ['preview' => ! $request->user()->isExternal()]]);
    }

    public function store(Request $request, PassengerPortal $portal): JsonResponse
    {
        $user = $request->user();
        if (! $user->isExternal()) {
            throw new ApiException(403, 'PREVIEW_READ_ONLY', 'PREVIEW_READ_ONLY', 'Vista previa de solo lectura: la reseña la escribe el pasajero.');
        }
        $r = $portal->reservation($user);
        $review = $this->reviews->submit($r, $this->reviews->passengerFor($r, $user), $this->payload($request), $this->photos($request), 'portal', null, $user);

        return response()->json(['data' => ['code' => $review->code, 'status' => $review->status, 'status_label' => OwnReview::PAX_LABELS[$review->status]]], 201);
    }

    // --- público ---

    public function publicList(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reviews->publicList($request->only(['product', 'stars', 'lang', 'page', 'limit']))]);
    }

    public function tokenForm(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->requests->open($code, (string) $request->query('t', ''))]);
    }

    public function byToken(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->requests->submit((string) $request->input('request', ''), (string) $request->input('t', ''), $this->payload($request), $this->photos($request))], 201);
    }

    private function locked(Request $request, string $code): OwnReview
    {
        return $this->reviews->find($code, $request->user())->assertIfMatch($request);
    }

    private function payload(Request $request): array
    {
        return $request->only(['product', 'stars', 'subratings', 'title', 'body', 'lang', 'consent_publish']);
    }

    /** Multipart `photos[]` o JSON `photos: [{name, content_base64}]`. */
    private function photos(Request $request): array
    {
        $files = $request->file('photos');
        if ($files) {
            return is_array($files) ? $files : [$files];
        }

        return array_values(array_filter((array) $request->input('photos', []), 'is_array'));
    }
}
