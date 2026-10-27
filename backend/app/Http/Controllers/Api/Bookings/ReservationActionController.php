<?php

namespace App\Http\Controllers\Api\Bookings;

use App\Exceptions\ApiException;
use App\Models\Bookings\BookedService;
use App\Models\Bookings\BookingChange;
use App\Models\Bookings\CancelRequest;
use App\Models\Bookings\Reservation;
use App\Services\Bookings\CancellationService;
use App\Services\Bookings\ChangeService;
use App\Services\Bookings\ReceivableService;
use App\Services\Bookings\SupplierFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Spec 04 §6: confirmación, operación, pasajeros, servicios, modificación, reprogramación, cancelación, CxC y reasignación. */
class ReservationActionController extends ReservationController
{
    public function confirm(Request $request, string $code): JsonResponse
    {
        $r = $this->find($code, $request)->assertIfMatch($request);

        return $this->detail($request, $this->reservations->confirm($r, $this->actor($request)));
    }

    public function startOperation(Request $request, string $code): JsonResponse
    {
        $r = $this->find($code, $request)->assertIfMatch($request);

        return $this->detail($request, $this->reservations->startOperation($r, $this->actor($request)));
    }

    public function complete(Request $request, string $code): JsonResponse
    {
        $r = $this->find($code, $request)->assertIfMatch($request);

        return $this->detail($request, $this->reservations->complete($r, $this->actor($request)));
    }

    // ── Pasajeros ──────────────────────────────────────────────────────

    public function storePassenger(Request $request, string $code): JsonResponse
    {
        $r = $this->find($code, $request)->assertIfMatch($request);
        [$p, $warnings] = $this->reservations->savePassenger($r, null, $this->passengerData($request), $this->actor($request));

        return response()->json(['data' => $this->present->passenger($p), 'meta' => ['warnings' => $warnings, 'lock_version' => $r->fresh()->lock_version]], 201);
    }

    public function updatePassenger(Request $request, string $code, string $passenger): JsonResponse
    {
        $r = $this->find($code, $request)->assertIfMatch($request);
        $p = $r->passengers()->whereKey($passenger)->first() ?? throw ApiException::notFound();
        [$p, $warnings] = $this->reservations->savePassenger($r, $p, $this->passengerData($request), $this->actor($request));

        return response()->json(['data' => $this->present->passenger($p), 'meta' => ['warnings' => $warnings, 'lock_version' => $r->fresh()->lock_version]]);
    }

    public function destroyPassenger(Request $request, string $code, string $passenger): JsonResponse
    {
        $r = $this->find($code, $request)->assertIfMatch($request);
        $p = $r->passengers()->whereKey($passenger)->first() ?? throw ApiException::notFound();
        $this->reservations->deletePassenger($r, $p, $this->actor($request));

        return response()->json(['data' => null, 'meta' => ['lock_version' => $r->fresh()->lock_version]]);
    }

    private function passengerData(Request $request): array
    {
        return $request->validate([
            'full_name' => 'nullable|string|max:150', 'doc_type' => ['nullable', Rule::in(['DNI', 'Pasaporte', 'CE', 'Passport'])], 'doc_number' => 'nullable|string|max:30',
            'pax_type' => ['nullable', Rule::in(['ADT', 'CHD', 'INF'])], 'nationality' => 'nullable|string|max:60', 'birth_date' => 'nullable|date|before_or_equal:today',
            'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:150', 'diet_notes' => 'nullable|string|max:200', 'is_lead' => 'boolean',
            'relationship' => ['nullable', Rule::in(['Titular', 'Cónyuge / pareja', 'Hijo/a', 'Padre / madre', 'Hermano/a', 'Amigo/a', 'Otro'])],
        ], ['birth_date.before_or_equal' => 'La fecha de nacimiento no puede ser futura.']);
    }

    // ── Servicios y proveedores ────────────────────────────────────────

    public function requestService(Request $request, string $code, string $service, SupplierFlow $flow): JsonResponse
    {
        $r = $this->find($code, $request);
        $flow->request($r, $this->service($r, $service), $this->actor($request));

        return $this->detail($request, $r);
    }

    public function requestPending(Request $request, string $code, SupplierFlow $flow): JsonResponse
    {
        $r = $this->find($code, $request);
        $result = $flow->requestPending($r, $this->actor($request));

        return $this->detail($request, $r, meta: $result);
    }

    public function confirmService(Request $request, string $code, string $service, SupplierFlow $flow): JsonResponse
    {
        $r = $this->find($code, $request);
        $data = $request->validate(['confirmation_code' => 'required|string|max:40', 'time' => 'nullable|date_format:H:i', 'confirmed_by' => 'nullable|string|max:120'],
            ['confirmation_code.required' => 'Indique el código de confirmación del proveedor (RES-BR-12).']);
        $flow->confirm($r, $this->service($r, $service), $data, $this->actor($request));

        return $this->detail($request, $r);
    }

    public function rejectService(Request $request, string $code, string $service, SupplierFlow $flow): JsonResponse
    {
        $r = $this->find($code, $request);
        $data = $request->validate([
            'reason' => 'required|string|max:120', 'detail' => 'required|string|max:2000',
            'alternative_provider_id' => 'nullable|string|max:40', 'alternative_date' => 'nullable|date',
        ], ['reason.required' => 'Indique el motivo del rechazo (RES-BR-13).', 'detail.required' => 'Indique el detalle del rechazo (RES-BR-13).']);
        $flow->reject($r, $this->service($r, $service), $data, $this->actor($request));

        return $this->detail($request, $r);
    }

    private function service(Reservation $r, string $id): BookedService
    {
        return $r->services()->where(fn ($q) => Str::isUuid($id) ? $q->whereKey($id) : $q->where('code', $id))->first() ?? throw ApiException::notFound();
    }

    // ── Modificación (§4.4) ────────────────────────────────────────────

    public function previewChange(Request $request, string $code, ChangeService $changes): JsonResponse
    {
        $r = $this->find($code, $request);

        return response()->json(['data' => $changes->preview($r, $this->changeData($request))]);
    }

    public function storeChange(Request $request, string $code, ChangeService $changes): JsonResponse
    {
        $r = $this->find($code, $request)->assertIfMatch($request);
        $change = $changes->request($r, $this->changeData($request) + $request->validate([
            'description' => 'required|string|max:2000', 'delta_amount' => 'nullable|numeric|min:-9999999|max:9999999', 'apply_now' => 'boolean',
        ], ['description.required' => 'Describa la modificación (RES-BR-14).']), $this->actor($request));

        return $this->detail($request, $r, 201, ['change' => ['id' => $change->id, 'status' => $change->status]]);
    }

    public function approveChange(Request $request, string $code, string $change, ChangeService $changes): JsonResponse
    {
        $r = $this->find($code, $request)->assertIfMatch($request);
        $changes->approve($r, $this->change($r, $change), $this->actor($request));

        return $this->detail($request, $r);
    }

    public function rejectChange(Request $request, string $code, string $change, ChangeService $changes): JsonResponse
    {
        $r = $this->find($code, $request)->assertIfMatch($request);
        $data = $request->validate(['note' => 'nullable|string|max:2000']);
        $changes->reject($r, $this->change($r, $change), $data['note'] ?? null, $this->actor($request));

        return $this->detail($request, $r);
    }

    private function changeData(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(['pax', 'servicio', 'upgrade', 'otro'])], 'extra_pax' => 'nullable|integer|min:1|max:99',
            'product_id' => 'nullable|uuid', 'service_date' => 'nullable|date',
        ], ['type.required' => 'Elija el tipo de modificación.']);
    }

    private function change(Reservation $r, string $id): BookingChange
    {
        return $r->changes()->whereKey($id)->where('type', '<>', 'reprogramacion')->first() ?? throw ApiException::notFound();
    }

    // ── Reprogramación (§4.5) ──────────────────────────────────────────

    public function previewReschedule(Request $request, string $code, ChangeService $changes): JsonResponse
    {
        $r = $this->find($code, $request);
        $data = $request->validate(['new_start' => 'nullable|date']);

        return response()->json(['data' => $changes->reschedulePreview($r, $data['new_start'] ?? null)]);
    }

    public function reschedule(Request $request, string $code, ChangeService $changes): JsonResponse
    {
        $r = $this->find($code, $request)->assertIfMatch($request);
        $data = $request->validate(['new_start' => 'required|date', 'reason' => 'required|string|max:2000'],
            ['new_start.required' => 'Indique la nueva fecha de inicio (RES-BR-21).', 'reason.required' => 'Indique el motivo de la reprogramación (RES-BR-21).']);

        return $this->detail($request, $changes->reschedule($r, $data['new_start'], $data['reason'], $this->actor($request)));
    }

    // ── Cancelación (§4.6) ─────────────────────────────────────────────

    public function previewCancel(Request $request, string $code, CancellationService $cancellations): JsonResponse
    {
        return response()->json(['data' => $cancellations->preview($this->find($code, $request))]);
    }

    public function cancel(Request $request, string $code, CancellationService $cancellations): JsonResponse
    {
        $r = $this->find($code, $request)->assertIfMatch($request);
        $data = $this->cancelData($request);

        return $this->detail($request, $cancellations->cancel($r, $data['reason'], $data['detail'], $this->actor($request)));
    }

    public function requestCancel(Request $request, string $code, CancellationService $cancellations): JsonResponse
    {
        $r = $this->find($code, $request);
        $data = $this->cancelData($request);
        $cancellations->request($r, $data['reason'], $data['detail'], $this->actor($request));

        return $this->detail($request, $r, 201);
    }

    public function rejectCancelRequest(Request $request, string $code, string $cancelRequest, CancellationService $cancellations): JsonResponse
    {
        $r = $this->find($code, $request);
        $data = $request->validate(['note' => 'required|string|max:2000'], ['note.required' => 'Indique el motivo del rechazo.']);
        $req = CancelRequest::where('reservation_id', $r->id)->whereKey($cancelRequest)->first() ?? throw ApiException::notFound();
        $cancellations->rejectRequest($r, $req, $data['note'], $this->actor($request));

        return $this->detail($request, $r);
    }

    private function cancelData(Request $request): array
    {
        return $request->validate(['reason' => 'required|string|max:120', 'detail' => 'required|string|max:2000'],
            ['reason.required' => 'Seleccione el motivo (RES-BR-30).', 'detail.required' => 'Indique el detalle (RES-BR-30).']);
    }

    // ── CxC y reasignación ─────────────────────────────────────────────

    public function generateReceivables(Request $request, string $code, ReceivableService $receivables): JsonResponse
    {
        $r = $this->find($code, $request);
        $receivables->generate($r, $this->actor($request));

        return $this->detail($request, $r, 201);
    }

    public function reassign(Request $request, string $code): JsonResponse
    {
        $r = $this->find($code, $request)->assertIfMatch($request);
        $data = $request->validate(['owner_id' => 'required|uuid', 'note' => 'nullable|string|max:500'], ['owner_id.required' => 'Seleccione el nuevo asesor.']);

        return $this->detail($request, $this->reservations->reassign($r, $data['owner_id'], $data['note'] ?? null, $this->actor($request)));
    }

    private function detail(Request $request, Reservation $r, int $status = 200, array $meta = []): JsonResponse
    {
        $fresh = Reservation::withTrashed()->findOrFail($r->id);

        return response()->json(['data' => $this->present->detail($fresh, $this->actor($request)), ...($meta ? ['meta' => $meta] : [])], $status);
    }
}
