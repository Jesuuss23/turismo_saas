<?php

namespace App\Http\Controllers\Api\Experience;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Bookings\Reservation;
use App\Models\Experience\ExternalReview;
use App\Services\Experience\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reseñas externas (spec 17 §6): conectores (`admin.settings.manage`), bandeja, respuestas, derivación e invitaciones
 * (`channels.ecommerce.manage | crm.clients.manage`) y widget de reseñas.
 */
class ReviewsController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function sources(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reviews->sources(), 'meta' => ['can_connect' => $request->user()->hasPermissionTo('admin.settings.manage')]]);
    }

    public function connect(Request $request, string $provider): JsonResponse
    {
        return response()->json(['data' => $this->reviews->connect($provider, $request->only(['account_ref', 'secret', 'api_key', 'contract_ack']), $request->user())]);
    }

    public function disconnect(Request $request, string $provider): JsonResponse
    {
        return response()->json(['data' => $this->reviews->disconnect($provider, $request->user())]);
    }

    public function sync(Request $request, string $provider): JsonResponse
    {
        return response()->json(['data' => $this->reviews->sync($provider, $request->user())]);
    }

    public function inbox(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reviews->inbox((array) $request->query('filter', []) + $request->only(['provider', 'status', 'max_rating']))]);
    }

    public function action(Request $request, string $id, string $action): JsonResponse
    {
        $review = ExternalReview::whereKey($id)->first() ?? throw ApiException::notFound();

        return response()->json(['data' => match ($action) {
            'reply' => $this->reviews->reply($review, (string) $request->input('reply'), $request->user()),
            'mark-published' => $this->reviews->markPublished($review, $request->user()),
            default => $this->reviews->escalate($review, $request->user()),
        }]);
    }

    public function invitations(): JsonResponse
    {
        return response()->json(['data' => $this->reviews->invitations()]);
    }

    public function invite(Request $request, string $reservation): JsonResponse
    {
        $r = Reservation::where(fn ($q) => $q->where('code', $reservation)->orWhere('id', preg_match('/^[0-9a-f-]{36}$/', $reservation) ? $reservation : null))->first()
            ?? throw ApiException::notFound();

        return response()->json(['data' => $this->reviews->invite($r, $request->user())], 201);
    }

    public function widget(): JsonResponse
    {
        return response()->json(['data' => $this->reviews->widgetConfig()]);
    }

    public function saveWidget(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reviews->saveWidgetConfig($request->all(), $request->user())]);
    }

    public function public(Request $request): JsonResponse
    {
        $sources = $request->query('sources');

        return response()->json(['data' => $this->reviews->publicReviews(array_filter([
            'sources' => is_string($sources) ? explode(',', $sources) : null, 'min_rating' => $request->query('min_rating'), 'count' => $request->query('count'),
        ], fn ($v) => $v !== null))]);
    }
}
