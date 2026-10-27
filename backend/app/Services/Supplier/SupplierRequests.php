<?php

namespace App\Services\Supplier;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\Reservation;
use App\Models\Catalog\Provider;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Models\Ops\Assignment;
use App\Models\Ops\SupplierRequest;
use App\Models\Supplier\RequestResponse;
use App\Services\AuditLogger;
use App\Services\Bookings\SupplierFlow;
use App\Services\Crm\CrmClock;
use Illuminate\Support\Facades\DB;

/**
 * Respuesta del proveedor a una solicitud (spec 06 §4.1, PROC-032): confirmar con código, rechazar con motivo o proponer
 * una alternativa que Operaciones acepta o rechaza (§12-1). La reserva se actualiza con SupplierFlow (doc 04/05).
 */
class SupplierRequests
{
    public const OPEN = [BookedService::PENDING, BookedService::REQUESTED];

    public function __construct(
        private readonly SupplierFlow $flow,
        private readonly SupplierRecipients $recipients,
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
    ) {}

    /** SUP-BR-05: la solicitud debe ser del proveedor (404 para no revelar servicios ajenos). */
    public function serviceOf(Provider $provider, string $id): BookedService
    {
        return BookedService::whereKey($id)->where('provider_id', $provider->id)
            ->whereHas('reservation', fn ($q) => $q->where('status', '<>', Reservation::DRAFT))->first()
            ?? throw ApiException::notFound();
    }

    public static function pendingAlternative(string $serviceId): ?RequestResponse
    {
        return RequestResponse::where('reservation_service_id', $serviceId)->where('type', RequestResponse::ALTERNATIVE)->where('review_status', 'pendiente')->latest('responded_at')->first();
    }

    /** SUP-BR-06: código ≥ 4 caracteres en mayúsculas; Por responder / Alternativa propuesta → confirmado. */
    public function confirm(Provider $provider, BookedService $service, array $data, User $actor): BookedService
    {
        $code = mb_strtoupper(trim((string) ($data['confirmation_code'] ?? '')));
        if (mb_strlen($code) < 4) {
            throw ApiException::invalid('confirmation_code', 'Ingrese el código de confirmación (mínimo 4 caracteres): se imprime en el voucher del pasajero.');
        }

        return DB::transaction(function () use ($provider, $service, $data, $actor, $code) {
            $service = $this->lockOpen($service);
            $before = BookedService::STATUS_LABELS[$service->status];
            $name = trim((string) ($data['confirmed_by_name'] ?? '')) ?: $provider->contact_name;
            $confirmed = $this->flow->confirm(Reservation::findOrFail($service->reservation_id), $service,
                ['confirmation_code' => $code, 'confirmed_by' => $name, 'note' => $data['note'] ?? null], $actor, 'extranet');
            $request = SupplierRequest::where('reservation_service_id', $service->id)->latest('responded_at')->first();
            $this->closeAlternatives($service, 'expirada', 'El proveedor confirmó la solicitud original.');
            RequestResponse::create([
                'supplier_request_id' => $request->id, 'reservation_service_id' => $service->id, 'provider_id' => $provider->id,
                'type' => RequestResponse::CONFIRMATION, 'responded_by_user_id' => $actor->id, 'responded_at' => now(),
                'confirmed_by_name' => mb_substr($name, 0, 120), 'confirmation_code' => $code, 'note' => $data['note'] ?? null,
            ]);
            $reservation = $confirmed->reservation;
            $this->audit->log('Servicio', $service->code, 'proveedor_confirma', $before, "confirmado {$code}", actor: $actor);
            $this->recipients->toOperations('proveedor.confirmacion', $service->reservation_id, "{$provider->name} confirmó {$service->name}",
                "{$provider->name} confirmó {$service->name} ({$reservation?->code}) · código {$code}", ['related_type' => 'reservation', 'related_id' => $service->reservation_id]);

            return $confirmed;
        });
    }

    /** SUP-BR-07: motivo de la lista cerrada y detalle. */
    public function reject(Provider $provider, BookedService $service, array $data, User $actor): BookedService
    {
        $reason = (string) ($data['reason'] ?? '');
        $note = trim((string) ($data['note'] ?? ''));
        if (! in_array($reason, RequestResponse::REJECT_REASONS, true)) {
            throw ApiException::invalid('reason', 'Elija el motivo del rechazo.');
        }
        if ($note === '') {
            throw ApiException::invalid('note', 'Detalle el motivo del rechazo.');
        }

        return DB::transaction(function () use ($provider, $service, $actor, $reason, $note) {
            $service = $this->lockOpen($service);
            $reservation = Reservation::findOrFail($service->reservation_id);
            if ($service->status === BookedService::PENDING) {
                // Respuesta a un servicio aún no solicitado formalmente: queda registrado como solicitado por la extranet.
                $service->forceFill(['status' => BookedService::REQUESTED, 'requested_at' => now()])->save();
            }
            [$rejected] = $this->flow->reject($reservation, $service, ['reason' => $reason, 'detail' => $note], $actor, 'extranet');
            $request = SupplierRequest::where('reservation_service_id', $service->id)->latest('responded_at')->first();
            $this->closeAlternatives($service, 'expirada', 'El proveedor rechazó la solicitud.');
            RequestResponse::create([
                'supplier_request_id' => $request->id, 'reservation_service_id' => $service->id, 'provider_id' => $provider->id,
                'type' => RequestResponse::REJECTION, 'responded_by_user_id' => $actor->id, 'responded_at' => now(),
                'rejection_reason' => $reason, 'note' => $note,
            ]);
            $this->audit->log('Servicio', $service->code, 'proveedor_rechaza', 'Solicitado', "rechazado: {$reason}", actor: $actor);
            $this->recipients->toOperations('proveedor.rechazo', $service->reservation_id, "{$provider->name} rechazó {$service->name}",
                "{$provider->name} rechazó {$service->name} ({$reservation->code}): {$reason}. Busque alternativa.", ['related_type' => 'reservation', 'related_id' => $reservation->id]);

            return $rejected;
        });
    }

    /** SUP-BR-08: tipo y detalle; el servicio alternativo debe ser un servicio activo del propio proveedor; el estado no cambia. */
    public function propose(Provider $provider, BookedService $service, array $data, User $actor): RequestResponse
    {
        $kind = (string) ($data['kind'] ?? '');
        $note = trim((string) ($data['note'] ?? ''));
        if (! in_array($kind, RequestResponse::ALT_KINDS, true)) {
            throw ApiException::invalid('kind', 'Elija el tipo de alternativa.');
        }
        if ($note === '') {
            throw ApiException::invalid('note', 'Describa la alternativa propuesta.');
        }
        $today = $this->clock->today()->toDateString();
        $date = ($data['date'] ?? null) ?: null;
        if ($date && $date < $today) {
            throw ApiException::invalid('date', 'La fecha propuesta no puede ser pasada.');
        }
        $hold = ($data['hold_until'] ?? null) ?: null;
        if ($hold && $hold < $today) {
            throw ApiException::invalid('hold_until', 'El bloqueo debe mantenerse hasta hoy o una fecha posterior.');
        }
        $alt = null;
        if (! empty($data['service_id'])) {
            $alt = SupplierService::whereKey($data['service_id'])->where('provider_id', $provider->id)->first();
            if (! $alt || $alt->effectiveStatus() !== 'activo') {
                throw ApiException::invalid('service_id', 'El servicio alternativo debe ser un servicio activo de su establecimiento.');
            }
        }
        if (isset($data['net_cost']) && $data['net_cost'] !== '' && (float) $data['net_cost'] < 0) {
            throw ApiException::invalid('net_cost', 'La tarifa neta no puede ser negativa.');
        }

        return DB::transaction(function () use ($provider, $service, $data, $actor, $kind, $note, $date, $hold, $alt) {
            $service = $this->lockOpen($service);
            if (self::pendingAlternative($service->id)) {
                throw ApiException::rule('SUP-BR-04', 'Ya propuso una alternativa para esta solicitud; Operaciones la está revisando.');
            }
            $reservation = Reservation::findOrFail($service->reservation_id);
            $request = $this->flow->respond($service, ['status' => 'alternativa_propuesta', 'note' => $note], $actor, 'extranet');
            $cost = isset($data['net_cost']) && $data['net_cost'] !== '' ? round((float) $data['net_cost'], 2) : null;
            $response = RequestResponse::create([
                'supplier_request_id' => $request->id, 'reservation_service_id' => $service->id, 'provider_id' => $provider->id,
                'type' => RequestResponse::ALTERNATIVE, 'responded_by_user_id' => $actor->id, 'responded_at' => now(),
                'alt_kind' => $kind, 'alt_date' => $date, 'alt_service_id' => $alt?->id, 'alt_net_cost' => $cost, 'currency' => $reservation->currency,
                'hold_until' => $hold, 'note' => $note, 'review_status' => 'pendiente',
            ]);
            $summary = implode(' · ', array_filter([$kind, $date, $cost !== null ? "{$reservation->currency} {$cost}" : null]));
            $this->audit->log('Servicio', $service->code, 'proveedor_alternativa', BookedService::STATUS_LABELS[$service->status], $summary, actor: $actor);
            $this->recipients->toOperations('proveedor.alternativa', $service->reservation_id, "{$provider->name} propone una alternativa",
                "{$service->name} ({$reservation->code}): {$summary}. {$note}", ['related_type' => 'reservation', 'related_id' => $reservation->id]);

            return $response;
        });
    }

    /**
     * Operaciones acepta la alternativa (§6 contraparte): aplica fecha / servicio / costo y, con el código que ya dio el proveedor,
     * confirma; si no, reenvía la solicitud para que el proveedor confirme en la extranet.
     */
    public function accept(RequestResponse $response, array $data, User $actor): RequestResponse
    {
        return DB::transaction(function () use ($response, $data, $actor) {
            $response = $this->lockPending($response);
            $service = BookedService::whereKey($response->reservation_service_id)->lockForUpdate()->firstOrFail();
            if (! in_array($service->status, self::OPEN, true)) {
                throw ApiException::rule('SUP-BR-04', "El servicio {$service->code} ya no está pendiente de respuesta (".mb_strtolower(BookedService::STATUS_LABELS[$service->status]).').');
            }
            $reservation = Reservation::findOrFail($service->reservation_id);
            $changes = [];
            if ($response->alt_date) {
                $changes['service_date'] = $response->alt_date->toDateString();
            }
            if ($response->alt_service_id && ($alt = $response->altService)) {
                $changes += ['catalog_service_id' => $alt->id, 'name' => $alt->name];
            }
            if ($response->alt_net_cost !== null) {
                $changes['cost_amount'] = $response->alt_net_cost;
            }
            if ($changes) {
                $before = "{$service->name} · {$service->service_date->toDateString()} · {$reservation->currency} {$service->cost_amount}";
                $service->forceFill($changes)->save();
                $service->refresh();
                Assignment::where('reservation_service_id', $service->id)->update(['service_date' => $service->service_date->toDateString(), 'service_label' => $service->name]);
                $this->audit->log('Servicio reserva', $service->code, 'aplicar_alternativa', $before,
                    "{$service->name} · {$service->service_date->toDateString()} · {$reservation->currency} {$service->cost_amount}", actor: $actor);
            }
            $response->forceFill(['review_status' => 'aceptada', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null])->save();
            $provider = Provider::withTrashed()->findOrFail($response->provider_id);
            $code = trim((string) ($data['confirmation_code'] ?? ''));
            if ($code !== '') {
                $this->flow->confirm($reservation, $service, ['confirmation_code' => mb_strtoupper($code), 'confirmed_by' => $provider->contact_name, 'note' => 'Alternativa aceptada'], $actor);
                $body = "Operaciones aceptó su alternativa para {$service->name} ({$reservation->code}) y la registró como confirmada · código ".mb_strtoupper($code).'.';
            } else {
                SupplierRequest::where('reservation_service_id', $service->id)->where('status', 'alternativa_propuesta')->update(['status' => 'anulada']);
                SupplierRequest::create(['reservation_service_id' => $service->id, 'provider_id' => $provider->id, 'status' => 'solicitado', 'channel' => 'extranet',
                    'requested_at' => now(), 'requested_by' => $actor->id, 'note' => 'Alternativa aceptada: confirme con su código.']);
                $service->forceFill(['status' => BookedService::REQUESTED, 'requested_at' => now(), 'requested_by' => $actor->id])->save();
                $body = "Operaciones aceptó su alternativa para {$service->name} ({$reservation->code}). Confirme la solicitud con su código en la extranet.";
            }
            $this->audit->log('Respuesta proveedor', $service->code, 'aceptar_alternativa', 'Alternativa propuesta', $code !== '' ? 'Confirmada' : 'Reenviada al proveedor', actor: $actor);
            $this->recipients->toProvider($provider, 'supplier.requests.respond', 'proveedor.alternativa_aceptada', "Alternativa aceptada · {$reservation->code}", $body,
                ['related_type' => 'reservation', 'related_id' => $reservation->id]);

            return $response->fresh();
        });
    }

    /** Operaciones rechaza la alternativa: la solicitud vuelve a "Por responder" y se avisa al proveedor. */
    public function decline(RequestResponse $response, string $note, User $actor): RequestResponse
    {
        if (trim($note) === '') {
            throw ApiException::invalid('note', 'Indique el motivo para el proveedor.');
        }

        return DB::transaction(function () use ($response, $note, $actor) {
            $response = $this->lockPending($response);
            $service = BookedService::findOrFail($response->reservation_service_id);
            $response->forceFill(['review_status' => 'rechazada', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => trim($note)])->save();
            SupplierRequest::whereKey($response->supplier_request_id)->where('status', 'alternativa_propuesta')->update(['status' => 'solicitado', 'responded_at' => null]);
            $reservation = Reservation::find($service->reservation_id);
            $this->audit->log('Respuesta proveedor', $service->code, 'rechazar_alternativa', 'Alternativa propuesta', 'Rechazada · '.trim($note), actor: $actor);
            $this->recipients->toProvider(Provider::withTrashed()->findOrFail($response->provider_id), 'supplier.requests.respond', 'proveedor.alternativa_rechazada',
                "Alternativa no aceptada · {$reservation?->code}", "{$service->name}: ".trim($note).' Responda la solicitud original en la extranet.',
                ['related_type' => 'reservation', 'related_id' => $service->reservation_id]);

            return $response->fresh();
        });
    }

    /** ExpireSupplierAlternativeHolds (§8): alternativas con bloqueo vencido y sin revisión → `expirada`. */
    public function expireHolds(): int
    {
        $count = 0;
        $today = $this->clock->today()->toDateString();
        foreach (RequestResponse::where('type', RequestResponse::ALTERNATIVE)->where('review_status', 'pendiente')->whereNotNull('hold_until')->whereDate('hold_until', '<', $today)->get() as $response) {
            $response->forceFill(['review_status' => 'expirada', 'review_note' => 'Venció el bloqueo ofrecido por el proveedor.'])->save();
            SupplierRequest::whereKey($response->supplier_request_id)->where('status', 'alternativa_propuesta')->update(['status' => 'solicitado', 'responded_at' => null]);
            $service = BookedService::find($response->reservation_service_id);
            $this->recipients->toOperations('proveedor.alternativa_expirada', $service?->reservation_id, "Alternativa expirada · {$service?->code}",
                "{$service?->name}: venció el bloqueo hasta {$response->hold_until->toDateString()}.", ['related_type' => 'reservation', 'related_id' => $service?->reservation_id]);
            $count++;
        }

        return $count;
    }

    /** SUP-BR-04: solo `pendiente`/`solicitado`; otra → 409 con el estado actual. */
    private function lockOpen(BookedService $service): BookedService
    {
        $service = BookedService::whereKey($service->id)->lockForUpdate()->firstOrFail();
        if (! in_array($service->status, self::OPEN, true)) {
            $label = mb_strtolower(BookedService::STATUS_LABELS[$service->status] ?? $service->status).($service->confirmation_code ? " {$service->confirmation_code}" : '');
            throw ApiException::rule('SUP-BR-04', "La solicitud ya fue respondida ({$label}). Para cambios contacte a Operaciones.");
        }

        return $service;
    }

    private function lockPending(RequestResponse $response): RequestResponse
    {
        $response = RequestResponse::whereKey($response->id)->lockForUpdate()->firstOrFail();
        if ($response->type !== RequestResponse::ALTERNATIVE || $response->review_status !== 'pendiente') {
            throw ApiException::rule('SUP-BR-08', 'La alternativa ya fue revisada ('.(RequestResponse::REVIEW_LABELS[$response->review_status] ?? '—').').');
        }

        return $response;
    }

    private function closeAlternatives(BookedService $service, string $status, string $note): void
    {
        RequestResponse::where('reservation_service_id', $service->id)->where('type', RequestResponse::ALTERNATIVE)->where('review_status', 'pendiente')
            ->update(['review_status' => $status, 'review_note' => $note, 'updated_at' => now()]);
    }
}
