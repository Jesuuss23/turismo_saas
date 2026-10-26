<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\ProductItem;
use App\Models\Catalog\Provider;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\SequenceService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Servicios de proveedores, componentes operativos de los productos (PROC-013, §4.7, CAT-BR-37). */
class SupplierServices
{
    public const EDITABLE = ['provider_id', 'name', 'type', 'unit', 'cost', 'currency', 'capacity', 'valid_from', 'valid_to'];

    public function __construct(
        private readonly CrmClock $clock,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{service: SupplierService, warnings: list<string>} */
    public function create(array $data, User $actor): array
    {
        $provider = $this->provider($data['provider_id']);
        $this->validate($data);
        $wanted = $data['status'] ?? 'activo';
        $warnings = [];
        if ($wanted === 'activo' && ! $provider->isUsable()) {
            $wanted = 'inactivo';
            $warnings[] = 'El proveedor no está activo: el servicio se guardó como inactivo (PRD-BR-007).';
        }

        return DB::transaction(function () use ($data, $wanted, $warnings, $actor) {
            $service = SupplierService::create(Arr::only($data, self::EDITABLE) + [
                'ref' => $this->sequences->next('catalog_service'),
                'status' => $wanted === 'activo' ? 'activo' : 'inactivo',
            ]);
            $this->audit->log('Servicio', $service->ref, 'crear', null, "{$service->name} · {$service->currency} {$service->cost}", actor: $actor);

            return ['service' => $service, 'warnings' => $warnings];
        });
    }

    /** @return array{service: SupplierService, warnings: list<string>} */
    public function update(SupplierService $service, array $data, User $actor): array
    {
        $data = Arr::only($data, self::EDITABLE);
        $merged = $data + $service->only(self::EDITABLE);
        $provider = $this->provider($merged['provider_id']);
        $this->validate($merged);

        return DB::transaction(function () use ($service, $data, $provider, $actor) {
            $before = ['cost' => (string) $service->cost, 'currency' => $service->currency];
            $service->fill($data);
            $warnings = [];
            if ($service->status === 'activo' && ! $provider->isUsable()) {
                $service->status = 'inactivo';
                $warnings[] = 'El proveedor no está activo: el servicio se guardó como inactivo (PRD-BR-007).';
            }
            $dirty = array_keys(Arr::except($service->getDirty(), ['lock_version']));
            if ($dirty === []) {
                return ['service' => $service, 'warnings' => []];
            }
            $service->save();
            $costChanged = array_intersect($dirty, ['cost', 'currency']) !== [];
            if ($costChanged) {
                $uses = $this->productCount($service);
                $this->audit->log('Servicio', $service->ref, 'cambio_costo', "{$before['currency']} {$before['cost']}", "{$service->currency} {$service->cost}",
                    ['products_using' => $uses], actor: $actor);
                if ($uses > 0) {
                    $warnings[] = "El cambio de costo afecta a {$uses} producto(s) que usan este servicio.";
                }
            }
            $other = array_values(array_diff($dirty, ['cost', 'currency']));
            if ($other !== []) {
                $this->audit->log('Servicio', $service->ref, 'modificar', null, implode(', ', $other), ['fields' => $other], actor: $actor);
            }

            return ['service' => $service, 'warnings' => $warnings];
        });
    }

    public function activate(SupplierService $service, User $actor): SupplierService
    {
        if (! $service->provider?->isUsable()) {
            throw ApiException::unprocessable('PRD-BR-007', 'No se puede activar: el proveedor no está activo o su contrato está vencido.');
        }
        if ($service->valid_to->toDateString() < $this->clock->today()->toDateString()) {
            throw ApiException::unprocessable('PRD-BR-003', 'No se puede activar: la vigencia del servicio terminó. Actualice la vigencia.');
        }

        return $this->setStatus($service, 'activo', null, $actor);
    }

    /** @return array{service: SupplierService, products_using: int} */
    public function deactivate(SupplierService $service, string $reason, User $actor): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ApiException::invalid('reason', 'Indique el motivo.');
        }

        return ['service' => $this->setStatus($service, 'inactivo', $reason, $actor), 'products_using' => $this->productCount($service)];
    }

    public function productCount(SupplierService $service): int
    {
        return ProductItem::where('service_id', $service->id)->distinct()->count('product_id');
    }

    private function setStatus(SupplierService $service, string $status, ?string $reason, User $actor): SupplierService
    {
        return DB::transaction(function () use ($service, $status, $reason, $actor) {
            $before = $service->effectiveStatus();
            $service->update(['status' => $status]);
            $this->audit->log('Servicio', $service->ref, 'cambio_estado', $before, $status.($reason ? " — {$reason}" : ''), actor: $actor);

            return $service;
        });
    }

    private function provider(?string $id): Provider
    {
        return Provider::whereKey($id)->first() ?? throw ApiException::invalid('provider_id', 'Seleccione un proveedor válido.');
    }

    private function validate(array $data): void
    {
        $date = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v;
        if ($date($data['valid_to']) < $date($data['valid_from'])) {
            throw ApiException::invalid('valid_to', 'La vigencia “hasta” debe ser igual o posterior a “desde”.');
        }
        if ((float) $data['cost'] < 0) {
            throw ApiException::invalid('cost', 'El costo no puede ser negativo.');
        }
    }
}
