<?php

namespace App\Services\Ops;

use App\Exceptions\ApiException;
use App\Models\Bookings\Passenger;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Provider;
use App\Models\Iam\User;
use App\Models\Ops\RoomingGuest;
use App\Models\Ops\RoomingList;
use App\Models\Ops\RoomingRoom;
use App\Services\AuditLogger;
use App\Services\Notify;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rooming list (PROC-034, §4.6): distribución por habitación con conflictos bloqueantes (OPS-BR-30..32, RT-13),
 * advertencias (OPS-BR-33), estados (OPS-BR-34/35) y envío al hotel.
 */
class RoomingService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** OPS-BR-36. */
    public function create(Reservation $reservation, string $hotelId, string $checkIn, string $checkOut, User $actor): RoomingList
    {
        if ($reservation->isClosed()) {
            throw ApiException::unprocessable('OPS-BR-36', "La reserva {$reservation->code} está ".mb_strtolower(Reservation::STATUS_LABELS[$reservation->status]).'.');
        }
        $hotel = Provider::find($hotelId);
        if (! $hotel || $hotel->type !== 'Hotel' || ! $hotel->isUsable()) {
            throw ApiException::invalid('hotel_provider_id', 'Elija un hotel activo (proveedor de tipo Hotel, OPS-BR-36).');
        }
        if ($checkOut <= $checkIn) {
            throw ApiException::invalid('check_out', 'El check-out debe ser posterior al check-in (OPS-BR-36).');
        }
        if (RoomingList::where('reservation_id', $reservation->id)->where('hotel_provider_id', $hotel->id)->exists()) {
            throw ApiException::rule('OPS-BR-36', "La reserva ya tiene rooming list en {$hotel->name}.");
        }
        $list = RoomingList::create([
            'reservation_id' => $reservation->id, 'hotel_provider_id' => $hotel->id, 'check_in' => $checkIn, 'check_out' => $checkOut,
            'status' => RoomingList::DRAFT,
        ]);
        $this->audit->log('Rooming', $reservation->code, 'crear', null, "{$hotel->name} · {$checkIn} → {$checkOut}", actor: $actor);

        return $list->fresh();
    }

    /**
     * Valida la distribución (actual o propuesta). Cada habitación: [room_number, room_type, status, passenger_ids[], id?].
     *
     * @return array{conflicts: list<array{code: string, text: string}>, warnings: list<array{code: string, text: string}>}
     */
    public function validate(RoomingList $list, ?array $rooms = null): array
    {
        $rooms ??= $this->currentRooms($list);
        $passengers = Passenger::where('reservation_id', $list->reservation_id)->orderBy('seq')->get()->keyBy('id');
        $conflicts = [];
        $warnings = [];
        $seen = [];
        $numbers = [];
        foreach ($rooms as $room) {
            $number = trim((string) $room['room_number']);
            $label = "Hab. {$number}";
            if (isset($numbers[$number])) {
                $conflicts[] = ['code' => 'OPS-BR-31', 'text' => "{$label}: número repetido en la lista."];
            }
            $numbers[$number] = true;
            $ids = array_values(array_unique((array) ($room['passenger_ids'] ?? [])));
            $capacity = RoomingRoom::CAPACITY[$room['room_type']] ?? 0;
            // OPS-V21-03: los infantes no ocupan cama.
            $beds = collect($ids)->filter(fn ($id) => ($passengers[$id]->pax_type ?? null) !== 'INF')->count();
            if ($beds > $capacity) {
                $conflicts[] = ['code' => 'OPS-BR-32', 'text' => "{$label} ({$room['room_type']}): {$beds} ocupante(s) para capacidad {$capacity}."];
            }
            if (! $ids) {
                $warnings[] = ['code' => 'OPS-BR-33', 'text' => "{$label} está vacía."];
            }
            if (($room['status'] ?? null) === 'observada') {
                $warnings[] = ['code' => 'OPS-BR-33', 'text' => "{$label} tiene observación del hotel."];
            }
            foreach ($ids as $id) {
                if (! isset($passengers[$id])) {
                    $conflicts[] = ['code' => 'OPS-BR-30', 'text' => "{$label}: el pasajero no pertenece a la reserva."];

                    continue;
                }
                if (isset($seen[$id])) {
                    $conflicts[] = ['code' => 'OPS-BR-30', 'text' => "{$passengers[$id]->full_name} figura en {$seen[$id]} y en {$label}."];
                }
                $seen[$id] = $label;
            }
            // OPS-BR-31: misma habitación del mismo hotel en otra lista con fechas superpuestas.
            $clash = RoomingRoom::where('hotel_provider_id', $list->hotel_provider_id)->where('room_number', $number)
                ->where('rooming_list_id', '<>', $list->id)->whereRaw('stay && ?::daterange', [$list->stayLiteral()])->first();
            if ($clash) {
                $other = RoomingList::with('reservation:id,code')->find($clash->rooming_list_id);
                $conflicts[] = ['code' => 'OPS-BR-31', 'text' => "{$label} ya está asignada en {$other?->reservation?->code} en fechas superpuestas."];
            }
        }
        // OPS-BR-30 / RT-13: el pasajero en otra lista con estadía superpuesta.
        if ($seen) {
            $others = RoomingGuest::whereIn('passenger_id', array_keys($seen))->where('rooming_list_id', '<>', $list->id)
                ->whereRaw('stay && ?::daterange', [$list->stayLiteral()])->get();
            foreach ($others as $guest) {
                $conflicts[] = ['code' => 'OPS-BR-30', 'text' => ($passengers[$guest->passenger_id]->full_name ?? 'Pasajero').' ya tiene habitación en otra rooming list en fechas superpuestas.'];
            }
        }
        $without = $passengers->keys()->diff(array_keys($seen));
        if ($without->isNotEmpty()) {
            $warnings[] = ['code' => 'OPS-BR-33', 'text' => $without->count().' pasajero(s) sin habitación: '.$without->map(fn ($id) => $passengers[$id]->full_name)->implode(', ').'.'];
        }

        return ['conflicts' => array_values(array_unique($conflicts, SORT_REGULAR)), 'warnings' => $warnings];
    }

    public function addRoom(RoomingList $list, array $data, User $actor): RoomingRoom
    {
        return $this->mutate($list, $actor, function (array $rooms) use ($data) {
            $rooms[] = $data;

            return $rooms;
        }, function (RoomingList $list) use ($data, $actor) {
            $room = $this->persistRoom($list, new RoomingRoom, $data);
            $this->audit->log('Rooming', $list->reservation->code, 'agregar_habitacion', null, "Hab. {$room->room_number} · {$room->room_type} · ".count($data['passenger_ids'] ?? []).' pax', actor: $actor);

            return $room;
        });
    }

    public function updateRoom(RoomingRoom $room, array $data, User $actor): RoomingRoom
    {
        $list = RoomingList::findOrFail($room->rooming_list_id);
        $before = json_encode($this->roomRow($room), JSON_UNESCAPED_UNICODE);

        return $this->mutate($list, $actor, function (array $rooms) use ($room, $data) {
            return array_map(fn ($r) => ($r['id'] ?? null) === $room->id ? $data + ['id' => $room->id] : $r, $rooms);
        }, function (RoomingList $list) use ($room, $data, $actor, $before) {
            $room = $this->persistRoom($list, $room, $data);
            $this->audit->log('Rooming', $list->reservation->code, 'editar_habitacion', $before, json_encode($this->roomRow($room), JSON_UNESCAPED_UNICODE), actor: $actor);

            return $room;
        });
    }

    public function deleteRoom(RoomingRoom $room, User $actor): RoomingList
    {
        $list = RoomingList::findOrFail($room->rooming_list_id);

        return DB::transaction(function () use ($list, $room, $actor) {
            $list = RoomingList::whereKey($list->id)->lockForUpdate()->firstOrFail();
            $room->guests()->delete();
            $room->delete();
            $this->markModified($list);
            $this->audit->log('Rooming', $list->reservation->code, 'eliminar_habitacion', "Hab. {$room->room_number}", null, actor: $actor);

            return $list->fresh();
        });
    }

    public function confirm(RoomingList $list, User $actor): RoomingList
    {
        $this->assertNoConflicts($list);
        $before = RoomingList::STATUS_LABELS[$list->status];
        $list->forceFill(['status' => RoomingList::CONFIRMED])->save();
        $this->audit->log('Rooming', $list->reservation->code, 'confirmar', $before, 'Confirmada', actor: $actor);

        return $list->fresh();
    }

    /** OPS-BR-34: sin conflictos y en `confirmada`/`enviada`; email al hotel con el documento. */
    public function send(RoomingList $list, User $actor): RoomingList
    {
        if (! in_array($list->status, [RoomingList::CONFIRMED, RoomingList::SENT], true)) {
            throw ApiException::rule('OPS-BR-34', 'Confirme la rooming list antes de enviarla al hotel.');
        }
        $this->assertNoConflicts($list);
        // Spec 06 §12-8: cada nueva versión enviada exige que el hotel vuelva a acusar recibo y confirmar.
        $list->forceFill(['status' => RoomingList::SENT, 'sent_at' => now(), 'sent_by' => $actor->id, 'sent_version' => $list->sent_version + 1,
            'hotel_ack_at' => null, 'hotel_ack_by' => null, 'hotel_confirmed_at' => null, 'hotel_confirmed_by' => null])->save();
        $hotel = $list->hotel;
        $reservation = $list->reservation;
        if ($hotel?->email) {
            $this->notify->push('rooming.enviado', 'email', $hotel->email, "Rooming list {$reservation->code} · v{$list->sent_version}",
                "{$list->rooms()->count()} habitación(es) · {$list->check_in->toDateString()} → {$list->check_out->toDateString()}",
                ['related_type' => 'ops_rooming', 'related_id' => $list->id, 'document' => "/operaciones/rooming/{$reservation->code}?doc=1"]);
        }
        $this->audit->log('Rooming', $reservation->code, 'enviar_hotel', null, "{$hotel?->name} · versión {$list->sent_version}", actor: $actor);

        return $list->fresh();
    }

    public function currentRooms(RoomingList $list): array
    {
        return $list->rooms()->with('guests')->get()->map(fn (RoomingRoom $r) => $this->roomRow($r))->all();
    }

    private function roomRow(RoomingRoom $room): array
    {
        return ['id' => $room->id, 'room_number' => $room->room_number, 'room_type' => $room->room_type, 'status' => $room->status,
            'passenger_ids' => $room->guests()->pluck('passenger_id')->all()];
    }

    private function mutate(RoomingList $list, User $actor, callable $propose, callable $apply): RoomingRoom
    {
        return DB::transaction(function () use ($list, $propose, $apply) {
            $list = RoomingList::whereKey($list->id)->lockForUpdate()->firstOrFail();
            if ($list->reservation->isClosed()) {
                throw ApiException::rule('OPS-BR-36', 'La reserva está cerrada: la rooming list no admite cambios.');
            }
            $check = $this->validate($list, $propose($this->currentRooms($list)));
            if ($check['conflicts']) {
                throw ApiException::rule($check['conflicts'][0]['code'], $check['conflicts'][0]['text'], $check);
            }
            try {
                $room = $apply($list);
            } catch (QueryException $e) {
                // EXCLUDE gist (carrera con otra lista): mensaje legible.
                throw ApiException::rule('OPS-BR-30', 'Conflicto de rooming: la habitación o el pasajero ya están ocupados en fechas superpuestas.');
            }
            $this->markModified($list);

            return $room;
        });
    }

    private function persistRoom(RoomingList $list, RoomingRoom $room, array $data): RoomingRoom
    {
        $room->fill([
            'rooming_list_id' => $list->id, 'hotel_provider_id' => $list->hotel_provider_id, 'stay' => $list->stayLiteral(),
            'room_number' => trim((string) $data['room_number']), 'room_type' => $data['room_type'],
            'capacity' => RoomingRoom::CAPACITY[$data['room_type']], 'status' => $data['status'] ?? 'pendiente',
        ])->save();
        $room->guests()->delete();
        foreach (array_values(array_unique((array) ($data['passenger_ids'] ?? []))) as $passengerId) {
            RoomingGuest::create(['room_id' => $room->id, 'rooming_list_id' => $list->id, 'passenger_id' => $passengerId, 'stay' => $list->stayLiteral()]);
        }

        return $room->fresh();
    }

    /** OPS-BR-35: toda modificación de una lista confirmada/enviada la pasa a `modificada`. */
    private function markModified(RoomingList $list): void
    {
        if (in_array($list->status, [RoomingList::CONFIRMED, RoomingList::SENT], true)) {
            $list->forceFill(['status' => RoomingList::MODIFIED])->save();
        }
    }

    private function assertNoConflicts(RoomingList $list): void
    {
        $check = $this->validate($list);
        if ($check['conflicts']) {
            throw ApiException::rule('OPS-BR-34', 'Resuelva los conflictos antes de continuar: '.$check['conflicts'][0]['text'], $check);
        }
    }

    /** Listas con estado y nº de habitaciones. */
    public function index(): Collection
    {
        return RoomingList::with(['reservation:id,code,title', 'hotel:id,name'])->withCount('rooms')->orderBy('check_in')->get();
    }
}
