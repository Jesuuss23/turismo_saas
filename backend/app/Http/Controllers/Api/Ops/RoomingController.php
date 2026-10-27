<?php

namespace App\Http\Controllers\Api\Ops;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Provider;
use App\Models\Ops\RoomingList;
use App\Models\Ops\RoomingRoom;
use App\Services\Ops\OpsAccess;
use App\Services\Ops\RoomingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Spec 05 §6.4: rooming lists por hotel con validación de capacidad y solapes (OPS-BR-30..36). */
class RoomingController extends OpsController
{
    public function index(Request $request, RoomingService $rooming): JsonResponse
    {
        $reservation = $request->input('filter.reservation');

        return response()->json(['data' => $rooming->index()
            ->when($reservation, fn ($c) => $c->filter(fn (RoomingList $l) => $l->reservation?->code === $reservation || $l->reservation_id === $reservation))
            ->map(fn (RoomingList $l) => $this->present->roomingRow($l))->values()->all(),
            'meta' => [
                'can_modify' => $this->access->can($this->actor($request), OpsAccess::ROOMING),
                'hotels' => Provider::where('type', 'Hotel')->orderBy('name')->get()->filter->isUsable()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
                'room_types' => RoomingRoom::CAPACITY, 'room_statuses' => RoomingRoom::STATUSES,
            ]]);
    }

    public function store(Request $request, RoomingService $rooming): JsonResponse
    {
        $data = $request->validate([
            'reservation_id' => 'required|string', 'hotel_provider_id' => 'required|uuid',
            'check_in' => 'required|date_format:Y-m-d', 'check_out' => 'required|date_format:Y-m-d',
        ]);
        $reservation = Reservation::where(fn ($q) => Str::isUuid($data['reservation_id']) ? $q->whereKey($data['reservation_id']) : $q->where('code', $data['reservation_id']))->first()
            ?? throw ApiException::invalid('reservation_id', 'Seleccione una reserva válida.');
        $list = $rooming->create($reservation, $data['hotel_provider_id'], $data['check_in'], $data['check_out'], $this->actor($request));

        return $this->detail($request, $rooming, $list, 201);
    }

    public function show(Request $request, RoomingService $rooming, string $list): JsonResponse
    {
        return $this->detail($request, $rooming, $this->list($list));
    }

    /** Dry-run de una distribución propuesta (validación en vivo). */
    public function validateRooms(Request $request, RoomingService $rooming, string $list): JsonResponse
    {
        $data = $request->validate(['rooms' => 'present|array'] + $this->roomRules('rooms.*.'));

        return response()->json(['data' => $rooming->validate($this->list($list), $data['rooms'])]);
    }

    public function storeRoom(Request $request, RoomingService $rooming, string $list): JsonResponse
    {
        $l = $this->list($list)->assertIfMatch($request);
        $rooming->addRoom($l, $request->validate($this->roomRules()), $this->actor($request));

        return $this->detail($request, $rooming, $l, 201);
    }

    public function updateRoom(Request $request, RoomingService $rooming, string $room): JsonResponse
    {
        $r = RoomingRoom::find($room) ?? throw ApiException::notFound();
        $l = RoomingList::findOrFail($r->rooming_list_id)->assertIfMatch($request);
        $rooming->updateRoom($r, $request->validate($this->roomRules()) + ['id' => $r->id], $this->actor($request));

        return $this->detail($request, $rooming, $l);
    }

    public function destroyRoom(Request $request, RoomingService $rooming, string $room): JsonResponse
    {
        $r = RoomingRoom::find($room) ?? throw ApiException::notFound();
        $l = RoomingList::findOrFail($r->rooming_list_id)->assertIfMatch($request);
        $rooming->deleteRoom($r, $this->actor($request));

        return $this->detail($request, $rooming, $l);
    }

    public function transition(Request $request, RoomingService $rooming, string $list, string $action): JsonResponse
    {
        $l = $this->list($list)->assertIfMatch($request);
        $l = $action === 'confirm' ? $rooming->confirm($l, $this->actor($request)) : $rooming->send($l, $this->actor($request));

        return $this->detail($request, $rooming, $l);
    }

    /** Vista previa del documento para el hotel (el mismo que se adjunta al enviar). */
    public function document(Request $request, RoomingService $rooming, string $list): JsonResponse
    {
        $l = $this->list($list);
        $detail = $this->present->roomingDetail($l->load(['reservation', 'hotel']), $rooming->validate($l), $this->actor($request));

        return response()->json(['data' => [
            'title' => "Rooming list · {$detail['reservation']['code']}", 'hotel' => $detail['hotel']['name'] ?? null,
            'check_in' => $detail['check_in'], 'check_out' => $detail['check_out'], 'version' => max(1, (int) $l->sent_version),
            'rooms' => collect($detail['rooms'])->map(fn ($r) => ['room_number' => $r['room_number'], 'room_type' => $r['room_type'],
                'guests' => collect($r['guests'])->pluck('name')->all(), 'note' => $r['hotel_note']])->all(),
        ]]);
    }

    private function list(string $key): RoomingList
    {
        $list = Str::isUuid($key) ? RoomingList::find($key) : null;
        // `/operaciones/rooming/{code}`: primera lista de la reserva (o la del hotel `?hotel=`).
        $list ??= RoomingList::whereHas('reservation', fn ($q) => $q->where('code', $key))->when(request('hotel'), fn ($q, $h) => $q->where('hotel_provider_id', $h))
            ->orderBy('check_in')->first();

        return $list ?? throw ApiException::notFound();
    }

    private function roomRules(string $prefix = ''): array
    {
        return [
            "{$prefix}room_number" => 'required|string|max:20', "{$prefix}room_type" => ['required', Rule::in(array_keys(RoomingRoom::CAPACITY))],
            "{$prefix}status" => ['nullable', Rule::in(RoomingRoom::STATUSES)], "{$prefix}passenger_ids" => 'nullable|array', "{$prefix}passenger_ids.*" => 'uuid',
            "{$prefix}id" => 'nullable|uuid',
        ];
    }

    private function detail(Request $request, RoomingService $rooming, RoomingList $list, int $status = 200): JsonResponse
    {
        $list = RoomingList::with(['reservation', 'hotel'])->findOrFail($list->id);
        $siblings = RoomingList::where('reservation_id', $list->reservation_id)->with('hotel:id,name')->orderBy('check_in')->get()
            ->map(fn ($l) => ['id' => $l->id, 'hotel' => $l->hotel?->name, 'check_in' => $l->check_in->toDateString(), 'status' => $l->status])->all();

        return response()->json(['data' => $this->present->roomingDetail($list, $rooming->validate($list), $this->actor($request)) + ['lists' => $siblings]], $status);
    }
}
