<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductItem;
use App\Models\Catalog\Provider;
use App\Models\Catalog\ProviderContact;
use App\Models\Catalog\SupplierService;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use App\Services\SequenceService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Proveedores (PROC-012, §4.6, CAT-BR-38). */
class ProviderService
{
    public const EDITABLE = ['name', 'type', 'ruc', 'city', 'stars', 'contact_name', 'phone', 'email', 'contract_start', 'contract_end', 'currency', 'payment_terms'];

    public function __construct(
        private readonly CrmClock $clock,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** Alta: siempre nace inactivo (PROC-012). */
    public function create(array $data, User $actor): Provider
    {
        $this->validate($data);

        return DB::transaction(function () use ($data, $actor) {
            $provider = Provider::create(Arr::only($data, self::EDITABLE) + [
                'ref' => $this->sequences->next('catalog_provider'),
                'status' => Provider::INACTIVE,
            ]);
            $this->audit->log('Proveedor', $provider->ref, 'crear', null, "{$provider->name} · {$provider->type} · inactivo", actor: $actor);

            return $provider;
        });
    }

    public function update(Provider $provider, array $data, User $actor): Provider
    {
        $data = Arr::only($data, self::EDITABLE);
        $this->validate($data + $provider->only(self::EDITABLE), $provider);

        return DB::transaction(function () use ($provider, $data, $actor) {
            $provider->fill($data);
            // Editar el fin de contrato a una fecha vigente saca al proveedor de "vencido" (§4.6).
            if ($provider->status === Provider::EXPIRED && $provider->contract_end->toDateString() >= $this->today()) {
                $provider->status = Provider::INACTIVE;
            }
            $changed = array_keys(Arr::except($provider->getDirty(), ['lock_version']));
            if ($changed === []) {
                return $provider;
            }
            $provider->save();
            $this->audit->log('Proveedor', $provider->ref, 'modificar', null, implode(', ', $changed), ['fields' => $changed], actor: $actor);

            return $provider;
        });
    }

    public function activate(Provider $provider, User $actor): Provider
    {
        if ($provider->status === Provider::ACTIVE && $provider->isUsable()) {
            return $provider;
        }
        if ($provider->contract_end->toDateString() < $this->today()) {
            throw ApiException::unprocessable('CAT-BR-38', 'No se puede activar: el contrato está vencido. Renueve el contrato primero.');
        }
        if (! preg_match('/^(10|15|17|20)\d{9}$/', (string) $provider->ruc)) {
            throw ApiException::unprocessable('CAT-BR-38', 'No se puede activar: el RUC no es válido.');
        }

        return DB::transaction(function () use ($provider, $actor) {
            $before = $provider->status;
            $provider->update(['status' => Provider::ACTIVE]);
            $this->audit->log('Proveedor', $provider->ref, 'activar', $before, Provider::ACTIVE, actor: $actor);
            foreach (CatalogRecipients::operations() as $user) {
                $this->notify->push('proveedor.activado', 'email', $user, "Proveedor activado: {$provider->name}", "{$provider->ref} ya puede usarse en servicios y salidas.", ['related_type' => 'catalog_provider', 'related_id' => $provider->id]);
            }

            return $provider;
        });
    }

    /** @return array{provider: Provider, affected_products: list<string>} */
    public function deactivate(Provider $provider, string $reason, User $actor): array
    {
        $reason = $this->reason($reason);
        $affected = $this->activeProductsUsing($provider);

        return DB::transaction(function () use ($provider, $reason, $actor, $affected) {
            $before = $provider->effectiveStatus();
            $provider->update(['status' => Provider::INACTIVE]);
            $this->audit->log('Proveedor', $provider->ref, 'desactivar', $before, Provider::INACTIVE." — {$reason}", ['affected_products' => $affected], actor: $actor);
            if ($affected !== []) {
                foreach (CatalogRecipients::managers() as $user) {
                    $this->notify->push('proveedor.desactivado', 'email', $user, "Proveedor desactivado: {$provider->name}",
                        'Productos activos afectados: '.implode(', ', $affected).". Motivo: {$reason}", ['related_type' => 'catalog_provider', 'related_id' => $provider->id]);
                }
            }

            return ['provider' => $provider, 'affected_products' => $affected];
        });
    }

    /** Renovar contrato: fecha futura; un proveedor vencido pasa a inactivo (§4.6). */
    public function renew(Provider $provider, string $contractEnd, ?string $paymentTerms, User $actor): Provider
    {
        if ($contractEnd <= $this->today()) {
            throw ApiException::invalid('contract_end', 'La nueva fecha de fin de contrato debe ser futura.');
        }

        return DB::transaction(function () use ($provider, $contractEnd, $paymentTerms, $actor) {
            $before = $provider->contract_end->toDateString();
            $provider->contract_end = $contractEnd;
            if ($paymentTerms !== null && $paymentTerms !== '') {
                $provider->payment_terms = $paymentTerms;
            }
            if ($provider->status === Provider::EXPIRED) {
                $provider->status = Provider::INACTIVE;
            }
            $provider->save();
            $this->audit->log('Proveedor', $provider->ref, 'renovar_contrato', $before, $contractEnd, actor: $actor);

            return $provider;
        });
    }

    public function rate(Provider $provider, float $rating, ?string $note, User $actor): Provider
    {
        if ($rating < 1 || $rating > 5) {
            throw ApiException::invalid('rating', 'La calificación debe estar entre 1 y 5.');
        }

        return DB::transaction(function () use ($provider, $rating, $note, $actor) {
            $before = (string) $provider->rating;
            $provider->update(['rating' => round($rating, 1), 'rating_note' => $note]);
            $this->audit->log('Proveedor', $provider->ref, 'calificar', $before, (string) $provider->rating, actor: $actor);

            return $provider;
        });
    }

    public function addContact(Provider $provider, array $data, User $actor): ProviderContact
    {
        if (! empty($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw ApiException::invalid('email', 'Correo electrónico inválido.');
        }

        return DB::transaction(function () use ($provider, $data, $actor) {
            $contact = ProviderContact::create(Arr::only($data, ['name', 'role', 'phone', 'email']) + ['provider_id' => $provider->id]);
            $this->audit->log('Proveedor', $provider->ref, 'agregar_contacto', null, $contact->name, actor: $actor);

            return $contact;
        });
    }

    public function removeContact(Provider $provider, ProviderContact $contact, User $actor): void
    {
        DB::transaction(function () use ($provider, $contact, $actor) {
            $contact->delete();
            $this->audit->log('Proveedor', $provider->ref, 'quitar_contacto', $contact->name, null, actor: $actor);
        });
    }

    /** JOB diario: activos con contrato vencido → vencido (§8.2). @return list<string> refs */
    public function markExpired(): array
    {
        $expired = Provider::where('status', Provider::ACTIVE)->whereDate('contract_end', '<', $this->today())->get();
        foreach ($expired as $provider) {
            DB::transaction(function () use ($provider) {
                $provider->update(['status' => Provider::EXPIRED]);
                $this->audit->log('Proveedor', $provider->ref, 'cambio_estado', Provider::ACTIVE, Provider::EXPIRED.' — contrato vencido', system: true);
                $affected = $this->activeProductsUsing($provider);
                if ($affected !== []) {
                    foreach (CatalogRecipients::managers() as $user) {
                        $this->notify->push('proveedor.vencido', 'email', $user, "Contrato vencido: {$provider->name}", 'Productos activos afectados: '.implode(', ', $affected), ['related_type' => 'catalog_provider', 'related_id' => $provider->id]);
                    }
                }
            });
        }

        return $expired->pluck('ref')->all();
    }

    /** Productos ACTIVE que usan al proveedor como principal o en componentes. @return list<string> */
    public function activeProductsUsing(Provider $provider): array
    {
        $serviceIds = SupplierService::where('provider_id', $provider->id)->pluck('id');
        $viaItems = ProductItem::whereIn('service_id', $serviceIds)->orWhere('provider_id', $provider->id)->pluck('product_id');

        return Product::where('status', Product::ACTIVE)
            ->where(fn ($q) => $q->where('main_provider_id', $provider->id)->orWhereIn('id', $viaItems))
            ->orderBy('ref')->pluck('ref')->all();
    }

    private function validate(array $data, ?Provider $current = null): void
    {
        if (! preg_match('/^(10|15|17|20)\d{9}$/', (string) ($data['ruc'] ?? ''))) {
            throw ApiException::invalid('ruc', 'RUC inválido: 11 dígitos que empiezan por 10, 15, 17 o 20.');
        }
        $duplicate = Provider::withTrashed()->where('ruc', $data['ruc'])->when($current, fn ($q) => $q->where('id', '<>', $current->id))->exists();
        if ($duplicate) {
            throw ApiException::invalid('ruc', 'Ya existe un proveedor con ese RUC.');
        }
        $start = $data['contract_start'] instanceof \DateTimeInterface ? $data['contract_start']->format('Y-m-d') : (string) $data['contract_start'];
        $end = $data['contract_end'] instanceof \DateTimeInterface ? $data['contract_end']->format('Y-m-d') : (string) $data['contract_end'];
        if ($end < $start) {
            throw ApiException::invalid('contract_end', 'El fin de contrato debe ser igual o posterior al inicio.');
        }
        if (! empty($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw ApiException::invalid('email', 'Correo electrónico inválido.');
        }
    }

    private function reason(?string $reason): string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ApiException::invalid('reason', 'Indique el motivo.');
        }

        return $reason;
    }

    private function today(): string
    {
        return $this->clock->today()->toDateString();
    }
}
