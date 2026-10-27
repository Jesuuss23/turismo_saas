<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora (PROC-063, spec 09 §6.7, §9): filtros, agregados y eventos relacionados por correlación. Append-only (AUD-BR-01).
 * La vista de plataforma usa la conexión de la consola y solo devuelve eventos técnicos (AUD-BR-02).
 */
class AuditQuery
{
    /** AUD-BR-02: entidades visibles para el Super Admin. */
    public const PLATFORM_ENTITIES = ['Tenant', 'Sesión', 'Job', 'Integración', 'Plan', 'Rol', 'Permiso', 'Exportación'];

    public const PERIODS = ['hoy' => 'Hoy', '7' => 'Últimos 7 días', '30' => 'Últimos 30 días', '90' => 'Últimos 90 días'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function list(array $filters, bool $platform = false, int $limit = 200): array
    {
        $base = $this->filtered($filters, $platform);
        $rows = (clone $base)->orderByDesc('occurred_at')->limit($limit)->get();
        $actors = $this->actorNames($rows, $platform);
        $tenants = $platform ? $this->connection(true)->table('tenants')->pluck('trade_name', 'id') : collect();

        return [
            'data' => $rows->map(fn ($e) => $this->row($e, $actors, $tenants))->all(),
            'meta' => [
                'total' => (clone $base)->count(), 'critical' => (clone $base)->where('is_critical', true)->count(),
                'by_action' => $this->top($base, 'action'), 'by_entity' => $this->top($base, 'entity'), 'by_actor' => $this->topActors($base, $platform),
                'entities' => (clone $this->scope($platform))->distinct()->orderBy('entity')->pluck('entity')->all(),
                'actions' => (clone $this->scope($platform))->distinct()->orderBy('action')->pluck('action')->all(),
                'users' => $platform ? [] : User::orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->all(),
                'tenants' => $platform ? $tenants->map(fn ($n, $id) => ['value' => $id, 'label' => $n])->values()->all() : [],
                'periods' => self::PERIODS, 'platform' => $platform,
            ],
        ];
    }

    /** Evento + relacionados (misma correlación o misma entidad). */
    public function show(string $code, bool $platform = false): array
    {
        $number = (int) preg_replace('/\D/', '', $code);
        $event = (clone $this->scope($platform))->where('code', $number)->first() ?? throw ApiException::notFound();
        $related = (clone $this->scope($platform))->where('code', '<>', $number)
            ->where(fn ($q) => $q->where('correlation_id', $event->correlation_id)->orWhere(fn ($q) => $q->where('entity', $event->entity)->where('entity_id', $event->entity_id)))
            ->orderByDesc('occurred_at')->limit(10)->get();
        $all = collect([$event])->merge($related);
        $actors = $this->actorNames($all, $platform);
        $tenants = $platform ? $this->connection(true)->table('tenants')->pluck('trade_name', 'id') : collect();

        return $this->row($event, $actors, $tenants) + [
            'diff' => $event->diff ? json_decode($event->diff, true) : null, 'evidence' => $event->evidence ? json_decode($event->evidence, true) : null,
            'user_agent' => $event->user_agent,
            'related' => $related->map(fn ($e) => $this->row($e, $actors, $tenants) + ['same_correlation' => $e->correlation_id === $event->correlation_id])->all(),
        ];
    }

    /** Filas para exportar la bitácora (PROC-061). */
    public function exportRows(array $filters): array
    {
        $rows = $this->filtered($filters, false)->orderByDesc('occurred_at')->limit(5000)->get();
        $actors = $this->actorNames($rows, false);

        return [
            ['Evento', 'Fecha', 'Usuario', 'Rol', 'Entidad', 'ID', 'Acción', 'Antes', 'Después', 'Crítico', 'IP', 'Correlación'],
            $rows->map(fn ($e) => ['AUD-'.$e->code, (string) $e->occurred_at, $actors[$e->actor_id] ?? ($e->actor_type === 'system' ? 'Sistema' : $e->actor_role), $e->actor_role,
                $e->entity, $e->entity_id, $e->action, $e->before_value, $e->after_value, $e->is_critical ? 'sí' : 'no', $e->ip, $e->correlation_id])->all(),
        ];
    }

    /** AUD-BR-01: los accesos a la bitácora también quedan registrados. */
    public function logAccess(?string $detail = null): void
    {
        $this->audit->log('Auditoría', 'bitácora', 'consultar', null, $detail);
    }

    private function filtered(array $f, bool $platform): Builder
    {
        $q = clone $this->scope($platform);
        $days = $f['period'] ?? '30';
        $q->where('occurred_at', '>=', $days === 'hoy' ? now()->startOfDay() : now()->subDays((int) (isset(self::PERIODS[$days]) ? $days : 30)));
        foreach (['entity', 'action'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if (! empty($f['entities'])) {
            $q->whereIn('entity', (array) $f['entities']);
        }
        if (! empty($f['user'])) {
            $q->where('actor_id', $f['user']);
        }
        if (! empty($f['tenant']) && $platform) {
            $q->where('tenant_id', $f['tenant']);
        }
        if (($f['critical'] ?? null) === '1' || ($f['critical'] ?? null) === true) {
            $q->where('is_critical', true);
        }
        if (! empty($f['q'])) {
            $q->where(fn ($w) => $w->where('entity_id', 'ilike', '%'.$f['q'].'%')->orWhere('after_value', 'ilike', '%'.$f['q'].'%')->orWhere('correlation_id', $f['q']));
        }

        return $q;
    }

    private function scope(bool $platform): Builder
    {
        $q = $this->connection($platform)->table('audit_events');
        if ($platform) {
            $q->where(fn ($w) => $w->whereNull('tenant_id')->orWhereIn('entity', self::PLATFORM_ENTITIES)->orWhere('action', 'like', '%denegad%'))
                ->whereIn('entity', [...self::PLATFORM_ENTITIES, 'Acceso', 'Auditoría', 'Reserva']);
        }

        return $q;
    }

    private function connection(bool $platform): ConnectionInterface
    {
        return DB::connection($platform ? 'pgsql_platform' : null);
    }

    private function row(object $e, Collection $actors, Collection $tenants): array
    {
        return [
            'code' => 'AUD-'.$e->code, 'occurred_at' => (string) $e->occurred_at, 'actor' => $actors[$e->actor_id] ?? ($e->actor_type === 'system' ? 'Sistema' : ($e->actor_role ?? '—')),
            'actor_role' => $e->actor_role, 'actor_type' => $e->actor_type, 'entity' => $e->entity, 'entity_id' => $e->entity_id, 'action' => $e->action,
            'before' => $e->before_value, 'after' => $e->after_value, 'critical' => (bool) $e->is_critical, 'ip' => $e->ip, 'correlation_id' => $e->correlation_id,
            'tenant' => $e->tenant_id ? ($tenants[$e->tenant_id] ?? null) : null,
        ];
    }

    private function actorNames(Collection $rows, bool $platform): Collection
    {
        $ids = $rows->pluck('actor_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $platformUsers = DB::connection('pgsql_platform')->table('platform_users')->whereIn('id', $ids)->pluck('name', 'id');

        return $platform ? $platformUsers : User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->union($platformUsers);
    }

    private function top(Builder $base, string $col): array
    {
        return (clone $base)->selectRaw("{$col} as label, count(*) as value")->groupBy($col)->orderByDesc('value')->limit(8)->get()
            ->map(fn ($r) => ['label' => $r->label, 'value' => (int) $r->value])->all();
    }

    private function topActors(Builder $base, bool $platform): array
    {
        $rows = (clone $base)->selectRaw("actor_id, coalesce(actor_role, 'Sistema') as role, count(*) as value")->groupBy('actor_id', 'actor_role')->orderByDesc('value')->limit(8)->get();
        $names = $this->actorNames($rows, $platform);

        return $rows->map(fn ($r) => ['label' => $names[$r->actor_id] ?? $r->role, 'value' => (int) $r->value])->all();
    }
}
