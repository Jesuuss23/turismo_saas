<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\Crm\CatalogItem;
use App\Models\Crm\PipelineStage;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/** Catálogos configurables y etapas del tenant (spec 01 §3.1, §3.6). Memo por solicitud. */
class CrmCatalog
{
    /** @var array<string, Collection<string, CatalogItem>> memo por tenant|kind */
    private array $items = [];

    /** @var array<string, Collection<string, PipelineStage>> */
    private array $stages = [];

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return Collection<string, CatalogItem> code => item (activos e inactivos, para etiquetas históricas) */
    public function items(string $kind): Collection
    {
        return $this->items[$this->tenant->id().'|'.$kind] ??= CatalogItem::where('kind', $kind)->orderBy('sort_order')->get()->keyBy('code');
    }

    public function label(string $kind, ?string $code): ?string
    {
        return $code === null ? null : ($this->items($kind)->get($code)?->label ?? $code);
    }

    /** Valida que el código exista y esté activo (422 si no). */
    public function assertActive(string $kind, ?string $code, string $field): CatalogItem
    {
        $item = $code === null ? null : $this->items($kind)->get($code);
        if ($item === null || ! $item->is_active) {
            throw ApiException::invalid($field, "El valor seleccionado para {$field} no es válido");
        }

        return $item;
    }

    /** @return Collection<string, PipelineStage> */
    public function stages(): Collection
    {
        return $this->stages[(string) $this->tenant->id()] ??= PipelineStage::orderBy('sort_order')->get()->keyBy('code');
    }

    public function probability(string $stage): int
    {
        return (int) ($this->stages()->get($stage)?->default_probability ?? CrmProvisioner::STAGES[$stage][1]);
    }

    public function stageName(string $stage): string
    {
        return $this->stages()->get($stage)?->name ?? CrmProvisioner::STAGES[$stage][0] ?? $stage;
    }

    /** Para respuestas de la API: kind => [{code,label,requires_detail}]. */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(CrmProvisioner::CATALOGS) as $kind) {
            $out[$kind] = $this->items($kind)->where('is_active', true)->values()
                ->map(fn (CatalogItem $i) => ['code' => $i->code, 'label' => $i->label, 'requires_detail' => $i->requires_detail])->all();
        }

        return $out;
    }
}
