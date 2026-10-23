<?php

namespace App\Services;

use App\Models\Sys\OutboxMessage;
use App\Tenancy\TenantContext;

/**
 * Outbox transaccional (§3.9): llamar dentro de la misma transacción que el cambio de negocio.
 * El comando outbox:dispatch publica los pendientes en la cola `integrations`.
 */
class Outbox
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function record(string $aggregateType, string $aggregateId, string $event, array $payload): OutboxMessage
    {
        return OutboxMessage::create([
            'tenant_id' => $this->tenant->id(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event' => $event,
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
        ]);
    }
}
