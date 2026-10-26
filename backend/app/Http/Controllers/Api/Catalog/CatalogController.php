<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\CatalogPresenter;
use App\Models\Catalog\Product;
use App\Models\Iam\User;
use App\Models\Sys\AuditEvent;
use App\Services\Catalog\CatalogAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Utilidades comunes de los controladores del catálogo (spec 02 §6). */
abstract class CatalogController extends Controller
{
    public function __construct(
        protected readonly CatalogAccess $access,
        protected readonly CatalogPresenter $present,
    ) {}

    protected function actor(Request $request): User
    {
        return $request->user();
    }

    /** Producto por correlativo (PRD-001) o uuid; otro tenant → 404 por RLS (PRD-T02/T14). */
    protected function product(string $ref, bool $withTrashed = false): Product
    {
        return $this->find(Product::query()->when($withTrashed, fn ($q) => $q->withTrashed()), $ref);
    }

    /** @template T of Model @param Builder<T> $query @return T */
    protected function find(Builder $query, string $ref, string $column = 'ref'): Model
    {
        $query->where(fn ($q) => Str::isUuid($ref) ? $q->whereKey($ref) : $q->where($column, $ref));

        return $query->first() ?? throw ApiException::notFound();
    }

    /** Sub-recurso que debe pertenecer al producto (PRD-T14). @template T of Model @param class-string<T> $class @return T */
    protected function child(string $class, string $id, string $foreignKey, string $parentId): Model
    {
        if (! Str::isUuid($id)) {
            throw ApiException::notFound();
        }

        return $class::query()->whereKey($id)->where($foreignKey, $parentId)->first() ?? throw ApiException::notFound();
    }

    protected function ok(array $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data] + ($meta ? ['meta' => $meta] : []), $status);
    }

    protected function created(array $data, array $warnings = []): JsonResponse
    {
        return $this->ok($data, $warnings ? ['warnings' => $warnings] : [], 201);
    }

    /** Historial de auditoría de varias entidades (§9). @param array<string, list<string>> $entities */
    protected function history(array $entities, ?string $action = null, int $limit = 300): array
    {
        $query = AuditEvent::query()->where(function ($q) use ($entities) {
            foreach ($entities as $entity => $codes) {
                $codes = array_values(array_filter($codes));
                if ($codes !== []) {
                    $q->orWhere(fn ($q) => $q->where('entity', $entity)->whereIn('entity_id', $codes));
                }
            }
        })->when($action, fn ($q) => $q->where('action', $action));

        $rows = $query->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get();
        $this->present->users->preloadUsers($rows->pluck('actor_id'));

        return $rows->map(fn (AuditEvent $e) => [
            'entity' => $e->entity,
            'entity_id' => $e->entity_id,
            'action' => $e->action,
            'before' => $e->before_value,
            'after' => $e->after_value,
            'actor' => $e->actor_id ? $this->present->users->user($e->actor_id) : ['name' => 'Sistema'],
            'actor_role' => $e->actor_role,
            'at' => $e->occurred_at,
        ])->all();
    }

    protected function like(string $term): string
    {
        return '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
    }

    /** Asegura If-Match antes de modificar un recurso con lock_version (SEC-BR-17). */
    protected function ifMatch(Request $request, Model $model): void
    {
        $model->assertIfMatch($request);
    }
}
