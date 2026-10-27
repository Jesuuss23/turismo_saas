<?php

namespace App\Services\Supplier;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Catalog\Provider;
use App\Models\Iam\User;
use App\Models\Ops\Incident;
use App\Models\Ops\RoomingList;
use App\Models\Ops\RoomingRoom;
use App\Models\Ops\Voucher;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\Ops\IncidentService;
use App\Services\Ops\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Lado del proveedor de rooming (§4.2), vouchers (§4.3) e incidencias (§4.5). */
class SupplierOperations
{
    public const OBSERVATION_KINDS = ['Habitación no disponible', 'Tipo de habitación distinto', 'Capacidad excedida', 'Fechas no coinciden', 'Otro'];

    public const IMPACTS = ['baja', 'media', 'alta'];

    /** SUP-BR-51: tipos que el proveedor puede reportar. */
    public const INCIDENT_TYPES = ['Proveedor', 'Horario', 'Capacidad', 'Pasajero', 'Otro'];

    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly IncidentService $incidents,
        private readonly SupplierRecipients $recipients,
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** SUP-BR-20: solo listas de su hotel ya enviadas por Operaciones. */
    public function list(Provider $provider, string $id): RoomingList
    {
        return RoomingList::whereKey($id)->where('hotel_provider_id', $provider->id)->whereNotNull('sent_at')->first() ?? throw ApiException::notFound();
    }

    public function room(Provider $provider, string $id): RoomingRoom
    {
        $room = RoomingRoom::whereKey($id)->where('hotel_provider_id', $provider->id)->first() ?? throw ApiException::notFound();
        $this->list($provider, $room->rooming_list_id);

        return $room;
    }

    public function acknowledge(RoomingList $list, User $actor): RoomingList
    {
        if ($list->hotel_ack_at) {
            throw ApiException::rule('SUP-BR-20', 'Ya acusó recibo de esta rooming list el '.$list->hotel_ack_at->timezone($this->clock->timezone())->format('d/m/Y H:i').'.');
        }
        $list->forceFill(['hotel_ack_at' => now(), 'hotel_ack_by' => $actor->id])->save();
        $reservation = $list->reservation;
        $this->audit->log('Rooming', $reservation->code, 'acuse_recibo_hotel', null, "{$list->hotel?->name} · versión {$list->sent_version}", actor: $actor);
        $this->recipients->toOperations('rooming.acuse_hotel', $list->reservation_id, "{$list->hotel?->name} recibió la rooming list {$reservation->code}",
            "Acuse de recibo de la versión {$list->sent_version}.", ['related_type' => 'ops_rooming', 'related_id' => $list->id]);

        return $list->fresh();
    }

    /** SUP-BR-21: confirma las habitaciones no observadas; las observadas siguen pendientes de la incidencia. */
    public function confirmRooms(RoomingList $list, User $actor): RoomingList
    {
        if ($list->hotel_confirmed_at) {
            throw ApiException::rule('SUP-BR-21', 'El hotel ya confirmó esta rooming list.');
        }

        return DB::transaction(function () use ($list, $actor) {
            $count = RoomingRoom::where('rooming_list_id', $list->id)->where('status', '<>', 'observada')->update(['status' => 'confirmada', 'updated_at' => now()]);
            $list->forceFill(['hotel_ack_at' => $list->hotel_ack_at ?? now(), 'hotel_ack_by' => $list->hotel_ack_by ?? $actor->id,
                'hotel_confirmed_at' => now(), 'hotel_confirmed_by' => $actor->id])->save();
            $observed = RoomingRoom::where('rooming_list_id', $list->id)->where('status', 'observada')->count();
            $reservation = $list->reservation;
            $this->audit->log('Rooming', $reservation->code, 'confirmacion_hotel', null, "{$count} habitación(es) confirmadas".($observed ? " · {$observed} observada(s)" : ''), actor: $actor);
            $this->recipients->toOperations('rooming.confirmada_hotel', $list->reservation_id, "{$list->hotel?->name} confirmó la rooming list {$reservation->code}",
                "{$count} habitación(es) confirmadas".($observed ? "; {$observed} con observación." : '.'), ['related_type' => 'ops_rooming', 'related_id' => $list->id]);

            return $list->fresh();
        });
    }

    /** SUP-BR-22: motivo (lista cerrada) + propuesta del hotel → incidencia `Proveedor` desde la extranet. */
    public function observe(Provider $provider, RoomingRoom $room, array $data, User $actor): Incident
    {
        $kind = (string) ($data['kind'] ?? '');
        $impact = (string) ($data['impact'] ?? '');
        $proposal = trim((string) ($data['proposal'] ?? ''));
        if (! in_array($kind, self::OBSERVATION_KINDS, true)) {
            throw ApiException::invalid('kind', 'Elija el motivo de la observación.');
        }
        if (! in_array($impact, self::IMPACTS, true)) {
            throw ApiException::invalid('impact', 'Elija el impacto.');
        }
        if ($proposal === '') {
            throw ApiException::invalid('proposal', 'Indique la propuesta del hotel.');
        }
        if ($room->status === 'observada') {
            throw ApiException::rule('SUP-BR-22', "La habitación {$room->room_number} ya tiene una observación registrada.");
        }

        return DB::transaction(function () use ($provider, $room, $actor, $kind, $impact, $proposal) {
            $list = RoomingList::findOrFail($room->rooming_list_id);
            $service = BookedService::where('reservation_id', $list->reservation_id)->where('provider_id', $provider->id)->where('service_kind', 'alojamiento')->orderBy('service_date')->first();
            $incident = $this->incidents->create([
                'reservation_service_id' => $service?->id, 'provider_id' => $provider->id, 'type' => 'Proveedor', 'severity' => $impact,
                'title' => "Rooming {$list->reservation->code} · hab. {$room->room_number}: {$kind}", 'description' => $proposal,
            ], $actor, 'extranet');
            if (! $incident->reservation_id) {
                $incident->forceFill(['reservation_id' => $list->reservation_id])->save();
            }
            $room->forceFill(['status' => 'observada', 'hotel_note' => "{$kind}: {$proposal}", 'incident_id' => $incident->id])->save();
            $this->audit->log('Rooming', $list->reservation->code, 'observacion_hotel', "Hab. {$room->room_number}", $kind, actor: $actor);
            $this->notifyIncident($incident, $provider);

            return $incident->fresh();
        });
    }

    /**
     * SUP-BR-30…33: el código debe ser de un voucher a su nombre; anulado/utilizado → 409; uso solo el día del servicio o el anterior
     * (§12-7) y, si es futuro, con `confirm_future`. El bloqueo de fila hace que dos validaciones simultáneas apliquen una sola vez.
     */
    public function redeem(Provider $provider, string $code, bool $confirmFuture, User $actor, ?Request $request = null): Voucher
    {
        $code = mb_strtoupper(trim($code));
        if ($code === '') {
            throw ApiException::invalid('code', 'Ingrese el código del voucher.');
        }

        return DB::transaction(function () use ($provider, $code, $confirmFuture, $actor, $request) {
            $voucher = Voucher::where('code', $code)->where('provider_id', $provider->id)->lockForUpdate()->first()
                ?? throw new ApiException(404, 'NOT_FOUND', 'SUP-BR-30', "El código {$code} no corresponde a un voucher emitido a su establecimiento.");
            if ($request?->hasHeader('If-Match')) {
                $voucher->assertIfMatch($request);
            }
            if ($voucher->status === Voucher::VOIDED) {
                throw ApiException::rule('SUP-BR-31', "El voucher {$code} fue anulado: no es válido para el servicio.");
            }
            if ($voucher->status === Voucher::USED) {
                $at = $voucher->used_at?->timezone($this->clock->timezone())->format('d/m/Y H:i');
                throw ApiException::rule('SUP-BR-31', "El voucher {$code} ya fue utilizado el {$at}: no se puede reutilizar.", ['used_at' => $voucher->used_at?->toIso8601String()]);
            }
            $today = $this->clock->today();
            $date = $voucher->service_date->toDateString();
            if ($date > $today->copy()->addDay()->toDateString()) {
                throw ApiException::unprocessable('SUP-BR-32', "El servicio es el {$voucher->service_date->format('d/m/Y')}: solo puede registrar el uso el día del servicio o el día anterior (check-in anticipado).");
            }
            if ($date > $today->toDateString() && ! $confirmFuture) {
                throw ApiException::unprocessable('SUP-BR-32', "El servicio es el {$voucher->service_date->format('d/m/Y')}. Registre el uso solo si el huésped ya se presentó.", ['requires_confirmation' => true]);
            }
            $used = $this->vouchers->redeem($voucher, $actor, 'extranet');
            $this->audit->log('Voucher', $used->code, 'check_in_proveedor', 'Emitido', 'Utilizado', actor: $actor);
            $this->recipients->toOperations('voucher.utilizado', $used->reservation_id, "Voucher {$used->code} utilizado",
                "{$provider->name} registró el uso de {$used->service_label} ({$used->reservation?->code}).", ['related_type' => 'ops_voucher', 'related_id' => $used->id]);

            return $used;
        });
    }

    /** SUP-BR-51: servicio propio no cancelado/rechazado y con fecha ≥ hoy − 7 días. */
    public function report(Provider $provider, array $data, User $actor): Incident
    {
        $service = ! empty($data['reservation_service_id'])
            ? BookedService::whereKey($data['reservation_service_id'])->where('provider_id', $provider->id)->first() : null;
        if (! $service) {
            throw ApiException::invalid('reservation_service_id', 'Elija uno de sus servicios.');
        }
        if (in_array($service->status, [BookedService::CANCELLED, BookedService::REJECTED], true)) {
            throw ApiException::invalid('reservation_service_id', 'El servicio está '.mb_strtolower(BookedService::STATUS_LABELS[$service->status]).': no admite incidencias.');
        }
        if ($service->service_date->toDateString() < $this->clock->today()->subDays(7)->toDateString()) {
            throw ApiException::unprocessable('SUP-BR-51', 'Solo puede reportar incidencias de servicios de los últimos 7 días o futuros.');
        }
        if (! in_array($data['type'] ?? null, self::INCIDENT_TYPES, true)) {
            throw ApiException::invalid('type', 'Elija el tipo de incidencia.');
        }

        return DB::transaction(function () use ($provider, $service, $data, $actor) {
            $incident = $this->incidents->create([
                'reservation_service_id' => $service->id, 'provider_id' => $provider->id, 'type' => $data['type'], 'severity' => $data['severity'] ?? '',
                'title' => $data['title'] ?? '', 'description' => $data['description'] ?? '',
            ], $actor, 'extranet');
            $this->notifyIncident($incident, $provider);

            return $incident;
        });
    }

    /** SUP-BR-53: solo en incidencias abiertas o en proceso. */
    public function comment(Incident $incident, string $message, User $actor): void
    {
        if (! $incident->isActive()) {
            throw ApiException::rule('SUP-BR-53', 'La incidencia está '.mb_strtolower(Incident::STATUS_LABELS[$incident->status]).': ya no admite comentarios.');
        }
        $this->incidents->comment($incident, $message, $actor, 'proveedor');
        $this->audit->log('Incidencia', $incident->code, 'comentario_proveedor', null, mb_substr(trim($message), 0, 60), actor: $actor);
        $to = $incident->assignee ? collect([$incident->assignee]) : $this->recipients->operations($incident->reservation_id);
        foreach ($to as $user) {
            $this->notify->push('incidencia.comentario', 'portal', $user, "Comentario del proveedor · {$incident->code}", mb_substr(trim($message), 0, 200),
                ['related_type' => 'ops_incident', 'related_id' => $incident->id]);
        }
    }

    /** SUP-BR-52: aviso a Operaciones (crítica → `incidencia.critica`; la alerta a gerencia la envía IncidentService, como PROC-037). */
    private function notifyIncident(Incident $incident, Provider $provider): void
    {
        $event = $incident->severity === 'critica' ? 'incidencia.critica' : 'incidencia.creada';
        $this->recipients->toOperations($event, $incident->reservation_id, "Incidencia {$incident->code} reportada por {$provider->name}", $incident->title,
            ['related_type' => 'ops_incident', 'related_id' => $incident->id]);
    }
}
