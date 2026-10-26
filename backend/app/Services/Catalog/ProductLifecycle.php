<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\Departure;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductItem;
use App\Models\Catalog\Rate;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Catalog\Links\CatalogLinks;
use App\Services\Crm\CrmClock;
use App\Services\Notify;
use Illuminate\Support\Facades\DB;

/** Máquina de estados del producto (§4.1, CAT-BR-05..08) y checklist de activación de 12 ítems (CAT-BR-06). */
class ProductLifecycle
{
    public const CHECKLIST = [
        'type' => 'Tipo de producto',
        'name' => 'Nombre',
        'destination' => 'Destino principal',
        'status' => 'Estado',
        'components' => 'Componentes',
        'providers' => 'Proveedor',
        'rate_current' => 'Tarifa vigente',
        'rate_currency' => 'Moneda',
        'rate_validity' => 'Vigencia',
        'policies' => 'Políticas',
        'availability' => 'Disponibilidad',
        'itinerary' => 'Itinerario',
    ];

    public function __construct(
        private readonly CrmClock $clock,
        private readonly ProductVersioning $versions,
        private readonly CatalogLinks $links,
        private readonly AuditLogger $audit,
        private readonly Notify $notify,
    ) {}

    /** @return list<array{key: string, label: string, ok: bool, detail: string}> */
    public function checklist(Product $product): array
    {
        $product->loadMissing(['type', 'primaryDestination', 'mainProvider', 'items.service.provider', 'items.componentProduct', 'rates', 'policies', 'itinerary.days.items']);
        $today = $this->clock->today();
        $todayStr = $today->toDateString();
        $type = $product->type;
        $rows = [];
        $add = function (string $key, bool $ok, string $detail) use (&$rows) {
            $rows[] = ['key' => $key, 'label' => self::CHECKLIST[$key], 'ok' => $ok, 'detail' => $detail];
        };

        $add('type', $type !== null && ! $type->trashed(), $type ? $type->name : 'Sin tipo');
        $add('name', mb_strlen(trim((string) $product->name)) >= 3, mb_strlen(trim((string) $product->name)) >= 3 ? 'Correcto' : 'Mínimo 3 caracteres');
        $add('destination', $product->primaryDestination !== null && ! $product->primaryDestination->trashed(), $product->primaryDestination?->name ?? 'Sin destino');
        $statusOk = in_array($product->status, Product::ACTIVATABLE, true);
        $add('status', $statusOk, $statusOk ? Product::STATUS_LABELS[$product->status] : 'Debe estar Aprobado (estado actual: '.Product::STATUS_LABELS[$product->status].')');

        $needsComponents = $type?->requires_components ?? true;
        $count = $product->items->count();
        $add('components', ! $needsComponents || $count > 0, $needsComponents ? ($count > 0 ? "{$count} componente(s)" : 'Sin componentes') : 'No requerido para el tipo');

        $problems = [];
        if (! $product->mainProvider) {
            $problems[] = 'sin proveedor principal';
        } elseif (! $product->mainProvider->isUsable()) {
            $problems[] = "proveedor principal {$product->mainProvider->name} no activo o con contrato vencido";
        }
        foreach ($product->items as $item) {
            if ($item->service_id && ! $item->service?->isSelectable()) {
                $problems[] = "servicio {$item->service?->ref} no vigente/activo o con proveedor inactivo";
            }
            if ($item->component_product_id && $item->componentProduct?->status !== Product::ACTIVE) {
                $problems[] = "producto componente {$item->componentProduct?->ref} no activo";
            }
        }
        $add('providers', $problems === [], $problems === [] ? ($product->mainProvider?->name ?? '') : ucfirst(implode('; ', $problems)).' (PRD-BR-007)');

        $current = $product->rates->filter(fn (Rate $r) => $r->effectiveStatus($today) === 'vigente');
        $add('rate_current', $current->isNotEmpty(), $current->isNotEmpty() ? "{$current->count()} tarifa(s) vigente(s)" : 'Sin tarifa vigente hoy');
        $noCurrency = $product->rates->filter(fn (Rate $r) => empty($r->currency))->count();
        $add('rate_currency', $product->rates->isNotEmpty() && $noCurrency === 0, $product->rates->isEmpty() ? 'Sin tarifas' : ($noCurrency ? "{$noCurrency} tarifa(s) sin moneda" : 'Todas con moneda'));
        $noValidity = $product->rates->filter(fn (Rate $r) => ! $r->valid_from || ! $r->valid_to)->count();
        $add('rate_validity', $product->rates->isNotEmpty() && $noValidity === 0 && $current->isNotEmpty(),
            $product->rates->isEmpty() ? 'Sin tarifas' : ($noValidity ? "{$noValidity} tarifa(s) sin vigencia" : ($current->isNotEmpty() ? 'Vigencias completas' : 'Ninguna vigente hoy')));

        $policies = $product->policies;
        $missing = collect(['cancellation' => 'cancelación', 'modification' => 'modificación', 'no_show' => 'no-show', 'refund' => 'reembolso'])
            ->filter(fn ($label, $field) => trim((string) $policies?->{$field}) === '')->values()->all();
        $add('policies', $missing === [], $missing === [] ? 'Completas' : 'Falta: '.implode(', ', $missing));

        $model = $product->availability_model;
        if (! in_array($model, Product::AVAILABILITY_MODELS, true)) {
            $add('availability', false, 'Modelo no definido');
        } elseif ($model === 'POR_SALIDA') {
            $future = Departure::where('product_id', $product->id)->where('status', 'abierta')->whereDate('departure_date', '>=', $todayStr)->count();
            $add('availability', $future > 0, $future > 0 ? "POR_SALIDA · {$future} salida(s) futura(s) abierta(s)" : 'POR_SALIDA sin salidas futuras abiertas');
        } else {
            $add('availability', true, $model);
        }

        if ($type?->requires_itinerary) {
            $days = $product->itinerary?->days ?? collect();
            $empty = $days->filter(fn ($d) => $d->items->isEmpty())->pluck('day_number')->all();
            $ok = $days->isNotEmpty() && $empty === [];
            $add('itinerary', $ok, $days->isEmpty() ? 'Requerido: sin días' : ($empty ? 'Días sin actividades: '.implode(', ', $empty) : "{$days->count()} día(s)"));
        } else {
            $add('itinerary', true, 'No requerido para el tipo');
        }

        return $rows;
    }

    public function summary(Product $product): array
    {
        $rows = $this->checklist($product);

        return ['ok' => count(array_filter($rows, fn ($r) => $r['ok'])), 'total' => count($rows)];
    }

    /** CAT-BR-05: DRAFT → REVIEW. */
    public function submitReview(Product $product, User $actor): Product
    {
        $this->expect($product, [Product::DRAFT], 'enviar a revisión');
        $missing = [];
        foreach (['name' => 'nombre', 'product_type_id' => 'tipo', 'primary_destination_id' => 'destino', 'category_id' => 'categoría'] as $field => $label) {
            if (empty($product->{$field}) || ($field === 'name' && trim($product->name) === '')) {
                $missing[] = $label;
            }
        }
        if ($missing) {
            throw ApiException::unprocessable('CAT-BR-05', 'No se puede enviar a revisión: falta '.implode(', ', $missing).'.', ['missing' => $missing]);
        }

        return $this->transition($product, Product::REVIEW, null, $actor, function (Product $product) {
            foreach (CatalogRecipients::managers() as $user) {
                $this->notify->push('producto.revision', 'portal', $user, "Producto en revisión: {$product->name}", "{$product->ref} espera su aprobación.", $this->ref($product));
            }
        });
    }

    public function approve(Product $product, User $actor): Product
    {
        $this->expect($product, [Product::REVIEW], 'aprobar');

        return $this->transition($product, Product::APPROVED, null, $actor);
    }

    public function returnToDraft(Product $product, ?string $reason, User $actor): Product
    {
        $this->expect($product, [Product::REVIEW], 'devolver a borrador');

        return $this->transition($product, Product::DRAFT, $this->reason($reason), $actor);
    }

    /** →ACTIVE con checklist 12/12; si falla: 422 PRD-ACT-CHECKLIST + audit `activacion_rechazada`. */
    public function activate(Product $product, User $actor): Product
    {
        if ($product->status === Product::ACTIVE) {
            throw ApiException::unprocessable('CAT-BR-07', 'El producto ya está activo.');
        }
        $this->expect($product, [Product::DRAFT, Product::REVIEW, Product::APPROVED, Product::SUSPENDED, Product::INACTIVE], 'activar');
        $rows = $this->checklist($product);
        $failed = array_values(array_filter($rows, fn ($r) => ! $r['ok']));
        if ($failed !== []) {
            // Fuera de la transacción de negocio: el rechazo queda auditado aunque la respuesta sea 422.
            $this->audit->log('Producto', $product->ref, 'activacion_rechazada', $product->status, implode('; ', array_map(fn ($r) => "{$r['label']}: {$r['detail']}", $failed)),
                ['failed' => array_column($failed, 'key')], actor: $actor);
            throw ApiException::unprocessable('PRD-ACT-CHECKLIST', 'No se puede activar: el checklist tiene '.count($failed).' ítem(s) pendiente(s).', ['failed' => $failed, 'checklist' => $rows]);
        }

        return $this->transition($product, Product::ACTIVE, null, $actor, function (Product $product) {
            foreach (CatalogRecipients::sales() as $user) {
                $this->notify->push('producto.activado', 'email', $user, "Producto activado: {$product->name}", "{$product->ref} ya puede cotizarse y venderse.", $this->ref($product));
            }
        });
    }

    public function suspend(Product $product, ?string $reason, User $actor): Product
    {
        $this->expect($product, [Product::ACTIVE], 'suspender');

        return $this->transition($product, Product::SUSPENDED, $this->reason($reason), $actor, fn (Product $p) => $this->notifyUnsellable($p));
    }

    public function deactivate(Product $product, ?string $reason, User $actor): Product
    {
        $this->expect($product, [Product::ACTIVE, Product::SUSPENDED], 'inactivar');

        return $this->transition($product, Product::INACTIVE, $this->reason($reason), $actor, fn (Product $p) => $this->notifyUnsellable($p));
    }

    public function archive(Product $product, ?string $reason, User $actor): Product
    {
        if ($product->status === Product::ARCHIVED) {
            throw ApiException::unprocessable('CAT-BR-07', 'El producto ya está archivado.');
        }

        return $this->transition($product, Product::ARCHIVED, $this->reason($reason), $actor);
    }

    /** Referencias que bloquean la eliminación física (CAT-BR-08, PRD-BR-018). */
    public function references(Product $product): array
    {
        $usage = $this->links->productUsage($product->id);
        $packages = ProductItem::where('component_product_id', $product->id)->with('product:id,ref,name')->get()
            ->map(fn ($i) => ['ref' => $i->product?->ref, 'name' => $i->product?->name])->unique('ref')->values()->all();

        return [
            'quotes' => $usage['quotes'] ?? [],
            'reservations' => $usage['reservations'] ?? [],
            'operations' => $usage['operations'] ?? [],
            'packages' => $packages,
        ];
    }

    /** CAT-BR-08: soft delete con motivo, o 409 con las referencias. */
    public function delete(Product $product, ?string $reason, User $actor): void
    {
        $reason = $this->reason($reason);
        $refs = $this->references($product);
        if (array_filter($refs) !== []) {
            $this->audit->log('Producto', $product->ref, 'eliminacion_bloqueada', null,
                collect($refs)->map(fn ($rows, $k) => count($rows)." {$k}")->implode(', '), $refs, actor: $actor);
            throw ApiException::rule('PRD-BR-018', 'Eliminación física bloqueada: el producto tiene historia comercial u operativa. Puede archivarlo.', $refs);
        }
        DB::transaction(function () use ($product, $reason, $actor) {
            $product->delete();
            $this->audit->log('Producto', $product->ref, 'eliminar', $product->status, "eliminado — {$reason}", actor: $actor);
        });
    }

    private function transition(Product $product, string $to, ?string $reason, User $actor, ?callable $after = null): Product
    {
        return DB::transaction(function () use ($product, $to, $reason, $actor, $after) {
            $from = $product->status;
            $product->status = $to;
            $product->save();
            $this->versions->follow($product, $from, $to);
            $this->audit->log('Producto', $product->ref, 'cambio_estado', $from, $to.($reason ? " — {$reason}" : ''), $reason ? ['reason' => $reason] : null, actor: $actor);
            if ($after) {
                $after($product);
            }

            return $product;
        });
    }

    private function notifyUnsellable(Product $product): void
    {
        foreach (CatalogRecipients::sales() as $user) {
            $this->notify->push('producto.no_vendible', 'portal', $user, "Producto no vendible: {$product->name}",
                "{$product->ref} pasó a ".Product::STATUS_LABELS[$product->status].'. No lo use en nuevas cotizaciones.', $this->ref($product));
        }
    }

    private function expect(Product $product, array $from, string $action): void
    {
        if (! in_array($product->status, $from, true)) {
            throw ApiException::unprocessable('CAT-BR-07', "No se puede {$action} un producto en estado ".Product::STATUS_LABELS[$product->status].'.');
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

    private function ref(Product $product): array
    {
        return ['related_type' => 'catalog_product', 'related_id' => $product->id, 'product_ref' => $product->ref];
    }
}
