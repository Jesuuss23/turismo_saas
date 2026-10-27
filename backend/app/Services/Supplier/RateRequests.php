<?php

namespace App\Services\Supplier;

use App\Exceptions\ApiException;
use App\Models\Catalog\Provider;
use App\Models\Catalog\ServiceRate;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Models\Supplier\RateRequest;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\SequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Solicitudes de actualización de tarifa neta (spec 06 §4.6, SUP-BR-61…63). La aprobación crea una nueva vigencia en
 * `catalog_service_rates`, que el job diario aplica al servicio al llegar su fecha; las reservas confirmadas conservan su costo.
 */
class RateRequests
{
    /** Cláusula de anticipación del contrato (§12-12, parámetro por defecto). */
    public const NOTICE_DAYS = 30;

    public function __construct(
        private readonly SupplierRecipients $recipients,
        private readonly SequenceService $sequences,
        private readonly CrmClock $clock,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Provider $provider, array $data, User $actor): RateRequest
    {
        $service = ! empty($data['service_id']) ? SupplierService::whereKey($data['service_id'])->where('provider_id', $provider->id)->first() : null;
        if (! $service) {
            throw ApiException::invalid('service_id', 'Elija uno de sus servicios contratados.');
        }
        $cost = round((float) ($data['proposed_cost'] ?? 0), 2);
        if ($cost <= 0) {
            throw ApiException::invalid('proposed_cost', 'La nueva tarifa debe ser mayor que cero.');
        }
        $min = $this->clock->today()->addDays(self::NOTICE_DAYS)->toDateString();
        if (empty($data['effective_from']) || $data['effective_from'] < $min) {
            throw ApiException::invalid('effective_from', 'La vigencia debe empezar al menos '.self::NOTICE_DAYS." días después de hoy (desde el {$min}, SUP-BR-61).");
        }
        if (! in_array($data['reason_type'] ?? null, RateRequest::REASONS, true)) {
            throw ApiException::invalid('reason_type', 'Elija el motivo.');
        }
        if (RateRequest::where('service_id', $service->id)->where('status', RateRequest::PENDING)->exists()) {
            throw ApiException::rule('SUP-BR-61', 'Ya hay una solicitud de actualización pendiente para este servicio.');
        }

        try {
            return DB::transaction(function () use ($provider, $service, $data, $actor, $cost) {
                $request = RateRequest::create([
                    'code' => $this->sequences->next('rate_request'), 'provider_id' => $provider->id, 'service_id' => $service->id,
                    'current_cost' => $service->cost, 'proposed_cost' => $cost, 'currency' => $service->currency, 'effective_from' => $data['effective_from'],
                    'reason_type' => $data['reason_type'], 'reason_detail' => trim((string) ($data['reason_detail'] ?? '')) ?: null,
                    'status' => RateRequest::PENDING, 'requested_by' => $actor->id, 'requested_at' => now(),
                ]);
                $this->audit->log('Tarifa proveedor', $service->ref, 'solicitud_actualizacion', "{$service->currency} {$service->cost}",
                    "{$service->currency} {$cost} desde {$data['effective_from']}", actor: $actor);
                $this->recipients->toRoles(['operations', 'manager'], 'proveedor.tarifa', "{$provider->name} solicita actualizar una tarifa ({$request->code})",
                    "{$service->name}: {$service->currency} {$service->cost} → {$cost} desde {$data['effective_from']}", ['related_type' => 'rate_request', 'related_id' => $request->id]);

                return $request;
            });
        } catch (QueryException $e) {
            throw str_contains($e->getMessage(), 'supplier_rate_requests_pending_unique')
                ? ApiException::rule('SUP-BR-61', 'Ya hay una solicitud de actualización pendiente para este servicio.') : $e;
        }
    }

    public function withdraw(RateRequest $request, User $actor): RateRequest
    {
        $this->assertPending($request);
        $request->forceFill(['status' => 'retirada', 'reviewed_at' => now()])->save();
        $this->audit->log('Tarifa proveedor', $request->code, 'retirar', 'Pendiente', 'Retirada por el proveedor', actor: $actor);

        return $request->fresh();
    }

    /** SUP-BR-62: crea la nueva vigencia; si ya empezó, se aplica de inmediato. */
    public function approve(RateRequest $request, ?string $note, User $actor): RateRequest
    {
        return DB::transaction(function () use ($request, $note, $actor) {
            $request = RateRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($request);
            $rate = ServiceRate::create(['service_id' => $request->service_id, 'cost' => $request->proposed_cost, 'currency' => $request->currency,
                'valid_from' => $request->effective_from->toDateString(), 'source' => 'solicitud_proveedor']);
            $request->forceFill(['status' => 'aprobada', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => trim((string) $note) ?: null,
                'resulting_rate_id' => $rate->id])->save();
            $this->audit->log('Tarifa proveedor', $request->code, 'aprobar', 'Pendiente', "Nueva vigencia {$request->currency} {$request->proposed_cost} desde {$request->effective_from->toDateString()}", actor: $actor);
            $this->applyDue($actor);
            $this->notifyProvider($request, 'aprobada', "Su nueva tarifa de {$request->currency} {$request->proposed_cost} rige desde el {$request->effective_from->format('d/m/Y')}. Las reservas ya confirmadas conservan la tarifa pactada.");

            return $request->fresh();
        });
    }

    public function reject(RateRequest $request, string $note, User $actor): RateRequest
    {
        $this->assertPending($request);
        if (trim($note) === '') {
            throw ApiException::invalid('note', 'Indique el motivo para el proveedor.');
        }
        $request->forceFill(['status' => 'rechazada', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => trim($note)])->save();
        $this->audit->log('Tarifa proveedor', $request->code, 'rechazar', 'Pendiente', 'Rechazada · '.trim($note), actor: $actor);
        $this->notifyProvider($request, 'rechazada', trim($note));

        return $request->fresh();
    }

    /** Vigencias aprobadas cuya fecha llegó: actualizan el costo del servicio (no las reservas confirmadas). */
    public function applyDue(?User $actor = null): int
    {
        $count = 0;
        $today = $this->clock->today()->toDateString();
        foreach (ServiceRate::whereNull('applied_at')->whereDate('valid_from', '<=', $today)->orderBy('valid_from')->get() as $rate) {
            $service = SupplierService::find($rate->service_id);
            if ($service) {
                $before = "{$service->currency} {$service->cost}";
                $service->forceFill(['cost' => $rate->cost, 'currency' => $rate->currency])->save();
                $this->audit->log('Servicio', $service->ref, 'cambio_costo', $before, "{$rate->currency} {$rate->cost} · vigencia desde {$rate->valid_from->toDateString()}",
                    actor: $actor, system: $actor === null);
            }
            $rate->forceFill(['applied_at' => now()])->save();
            $count++;
        }

        return $count;
    }

    private function assertPending(RateRequest $request): void
    {
        if ($request->status !== RateRequest::PENDING) {
            throw ApiException::rule('SUP-BR-62', "La solicitud {$request->code} ya está ".mb_strtolower(RateRequest::STATUS_LABELS[$request->status]).'.');
        }
    }

    private function notifyProvider(RateRequest $request, string $status, string $body): void
    {
        $this->recipients->toProvider(Provider::withTrashed()->findOrFail($request->provider_id), 'supplier.rates.request', "proveedor.tarifa_{$status}",
            "Solicitud {$request->code} {$status}", $body, ['related_type' => 'rate_request', 'related_id' => $request->id]);
    }
}
