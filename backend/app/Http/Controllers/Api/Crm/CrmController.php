<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Http\Resources\Crm\CrmPresenter;
use App\Models\Iam\User;
use App\Models\Sys\AuditEvent;
use App\Services\Crm\CrmAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Utilidades comunes de los controladores CRM: alcance, paginación por cursor e historial. */
abstract class CrmController extends Controller
{
    public function __construct(
        protected readonly CrmAccess $access,
        protected readonly CrmPresenter $present,
    ) {}

    protected function actor(Request $request): User
    {
        return $request->user();
    }

    /** Filtro `owner` = me | none | any | {userId}; Ventas siempre queda en su alcance. */
    protected function ownerFilter(Builder $query, Request $request, string $column = 'owner_id'): Builder
    {
        $owner = $request->input('filter.owner');

        return match ($owner) {
            null, '', 'any' => $query,
            'me' => $query->where($column, $request->user()->id),
            'none' => $query->whereNull($column),
            default => $query->where($column, $owner),
        };
    }

    /** Paginación por cursor: `{data, meta: {next_cursor, prev_cursor, per_page, ...extra}}`. */
    protected function paginate(Builder $query, Request $request, callable $map, array $meta = []): array
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $page = $query->cursorPaginate($perPage);
        $items = collect($page->items());
        $this->present->preloadUsers($items->flatMap(fn ($r) => [$r->owner_id ?? null, $r->created_by ?? null]));

        return [
            'data' => $items->map($map)->values()->all(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'prev_cursor' => $page->previousCursor()?->encode(), 'per_page' => $perPage] + $meta,
        ];
    }

    /**
     * Historial de auditoría de varias entidades (§9: "Consultas de auditoría en UI").
     *
     * @param  array<string, list<string>>  $entities  entidad => códigos
     */
    protected function history(array $entities, int $limit = 200): array
    {
        $query = AuditEvent::query()->where(function ($q) use ($entities) {
            foreach ($entities as $entity => $codes) {
                $codes = array_values(array_filter($codes));
                if ($codes !== []) {
                    $q->orWhere(fn ($q) => $q->where('entity', $entity)->whereIn('entity_id', $codes));
                }
            }
        });

        return $query->orderByDesc('occurred_at')->orderByDesc('code')->limit($limit)->get()->map(fn (AuditEvent $e) => [
            'entity' => $e->entity,
            'entity_id' => $e->entity_id,
            'action' => $e->action,
            'before' => $e->before_value,
            'after' => $e->after_value,
            'actor' => $e->actor_id ? $this->present->user($e->actor_id) : ['name' => 'Sistema'],
            'actor_role' => $e->actor_role,
            'at' => $e->occurred_at,
        ])->all();
    }

    /** Orden descendente por fecha conservando el orden previo en empates (usort es estable). */
    protected static function sortByDateDesc(array &$rows): void
    {
        $key = fn ($at) => $at instanceof \DateTimeInterface ? $at->format('Y-m-d H:i:s.u') : (string) $at;
        usort($rows, fn ($a, $b) => strcmp($key($b['at']), $key($a['at'])));
    }

    protected function like(string $term): string
    {
        return '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
    }
}
