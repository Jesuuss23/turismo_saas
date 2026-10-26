<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\Destination;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDestination;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\SequenceService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Jerarquía de destinos (PROC-011, CAT-BR-39). `path` materializa la cadena de ids
 * (`/raíz/…/propio/`) para filtrar descendientes (CAT-BR-43).
 */
class DestinationService
{
    /** Territorio peruano aproximado: fuera de él solo se advierte (§12-26). */
    private const PERU = ['lat' => [-18.4, 0.0], 'lng' => [-81.4, -68.6]];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{destination: Destination, warnings: list<string>} */
    public function create(array $data, User $actor): array
    {
        $parent = $this->parent($data['parent_id'] ?? null);
        $level = $this->validate($data, $parent, null);

        return DB::transaction(function () use ($data, $parent, $level, $actor) {
            $id = (string) Str::uuid7();
            $destination = (new Destination)->forceFill(Arr::only($data, ['name', 'type', 'lat', 'lng', 'description']) + [
                'id' => $id,
                'ref' => $this->sequences->next('catalog_destination'),
                'parent_id' => $parent?->id,
                'level' => $level,
                'path' => ($parent?->path ?? '/').$id.'/',
            ]);
            $destination->save();
            $this->audit->log('Destino', $destination->ref, 'crear', null, $this->label($destination), actor: $actor);

            return ['destination' => $destination, 'warnings' => $this->warnings($data)];
        });
    }

    /** @return array{destination: Destination, warnings: list<string>} */
    public function update(Destination $destination, array $data, User $actor): array
    {
        $data += $destination->only(['name', 'type', 'lat', 'lng', 'description', 'parent_id']);
        $parent = $this->parent($data['parent_id'] ?? null);
        if ($parent && str_contains($parent->path, "/{$destination->id}/")) {
            throw ApiException::invalid('parent_id', 'PROC-011 · referencia circular: el nuevo padre es el mismo destino o uno de sus descendientes.');
        }
        $level = $this->validate($data, $parent, $destination);

        return DB::transaction(function () use ($destination, $data, $parent, $level, $actor) {
            $before = $this->label($destination);
            $oldPath = $destination->path;
            $newPath = ($parent?->path ?? '/').$destination->id.'/';
            $destination->fill(Arr::only($data, ['name', 'type', 'lat', 'lng', 'description']) + ['parent_id' => $parent?->id, 'level' => $level, 'path' => $newPath]);
            $changed = array_keys($destination->getDirty());
            $destination->save();
            if ($oldPath !== $newPath) {
                // Re-materializa la ruta de los descendientes (padres antes que hijos para el trigger).
                Destination::where('path', 'like', $oldPath.'%')->where('id', '<>', $destination->id)->orderBy('level')->get()
                    ->each(fn (Destination $d) => $d->update(['path' => $newPath.substr($d->path, strlen($oldPath))]));
            }
            if ($changed !== []) {
                $this->audit->log('Destino', $destination->ref, 'modificar', $before, $this->label($destination), ['fields' => $changed], actor: $actor);
            }

            return ['destination' => $destination->fresh(), 'warnings' => $this->warnings($data)];
        });
    }

    public function delete(Destination $destination, User $actor): void
    {
        $children = Destination::where('parent_id', $destination->id)->count();
        $products = Product::where('primary_destination_id', $destination->id)->count()
            + ProductDestination::where('destination_id', $destination->id)->count();
        if ($children > 0 || $products > 0) {
            throw ApiException::rule('CAT-BR-39', "No se puede eliminar: tiene {$children} subdestino(s) y {$products} producto(s) que lo referencian.", ['children' => $children, 'products' => $products]);
        }
        DB::transaction(function () use ($destination, $actor) {
            $destination->delete();
            $this->audit->log('Destino', $destination->ref, 'eliminar', $this->label($destination), null, actor: $actor);
        });
    }

    /** Ids del destino y de todos sus descendientes (CAT-BR-43). */
    public function descendantIds(Destination $destination): array
    {
        return Destination::where('path', 'like', $destination->path.'%')->pluck('id')->all();
    }

    /** @return list<array{id: string, ref: string, name: string, type: string}> */
    public function ancestors(Destination $destination): array
    {
        $ids = array_values(array_filter(explode('/', $destination->path)));
        array_pop($ids);
        $nodes = Destination::withTrashed()->whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn ($id) => $nodes[$id] ?? null, $ids)));
    }

    /** Ruta legible "Perú › Cusco › Machu Picchu". */
    public function pathLabel(?Destination $destination): ?string
    {
        if (! $destination) {
            return null;
        }

        return implode(' › ', [...array_map(fn ($d) => $d->name, $this->ancestors($destination)), $destination->name]);
    }

    private function parent(?string $parentId): ?Destination
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        return Destination::whereKey($parentId)->first() ?? throw ApiException::invalid('parent_id', 'El destino padre no existe.');
    }

    private function validate(array $data, ?Destination $parent, ?Destination $current): int
    {
        $type = $data['type'];
        $level = Destination::LEVELS[$type] ?? throw ApiException::invalid('type', 'Tipo de destino inválido.');
        if (! $parent && $type !== 'País') {
            throw ApiException::invalid('parent_id', 'Jerarquía inválida: solo un País puede ser raíz.');
        }
        if ($parent && $type === 'País') {
            throw ApiException::invalid('type', 'Jerarquía inválida: un País no puede tener padre.');
        }
        if ($parent && $parent->level >= 6) {
            throw ApiException::invalid('parent_id', "Jerarquía inválida: {$parent->type} no admite subdestinos.");
        }
        if ($parent && $level <= $parent->level) {
            throw ApiException::invalid('type', "Jerarquía inválida: {$type} no puede depender de {$parent->type}.");
        }
        if ($current) {
            $minChild = Destination::where('parent_id', $current->id)->min('level');
            if ($minChild !== null && (int) $minChild <= $level) {
                throw ApiException::invalid('type', "Jerarquía inválida: con el tipo {$type} algún subdestino quedaría en un nivel igual o superior.");
            }
        }
        $hasLat = isset($data['lat']) && $data['lat'] !== '';
        $hasLng = isset($data['lng']) && $data['lng'] !== '';
        if ($hasLat !== $hasLng) {
            throw ApiException::invalid($hasLat ? 'lng' : 'lat', 'Latitud y longitud deben registrarse juntas.');
        }
        $siblings = Destination::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim($data['name']))])
            ->when($parent, fn ($q) => $q->where('parent_id', $parent->id), fn ($q) => $q->whereNull('parent_id'))
            ->when($current, fn ($q) => $q->where('id', '<>', $current->id));
        if ($siblings->exists()) {
            throw ApiException::invalid('name', 'Ya existe un destino con ese nombre en el mismo nivel.');
        }

        return $level;
    }

    private function warnings(array $data): array
    {
        if (! isset($data['lat'], $data['lng']) || $data['lat'] === '' || $data['lng'] === '') {
            return [];
        }
        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $inside = $lat >= self::PERU['lat'][0] && $lat <= self::PERU['lat'][1] && $lng >= self::PERU['lng'][0] && $lng <= self::PERU['lng'][1];

        return $inside ? [] : ['Las coordenadas están fuera del territorio peruano; verifique que sean correctas.'];
    }

    private function label(Destination $d): string
    {
        return "{$d->name} ({$d->type})";
    }
}
