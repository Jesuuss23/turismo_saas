<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Catalog\ProductSeason;
use App\Models\Catalog\Rate;
use App\Models\Catalog\Season;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\SequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Temporadas (PROC-019, CAT-BR-17/18): solapamientos solo con prioridad distinta; prevalece la menor. */
class SeasonService
{
    public const EDITABLE = ['name', 'type', 'date_from', 'date_to', 'priority', 'factor', 'status'];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{season: Season, warnings: list<string>} */
    public function create(array $data, User $actor): array
    {
        $data = Arr::only($data, self::EDITABLE) + ['status' => 'activa'];
        $warnings = $this->validate($data, null);

        return DB::transaction(function () use ($data, $warnings, $actor) {
            $season = $this->guarded(fn () => Season::create($data + ['ref' => $this->sequences->next('catalog_season')]));
            $this->audit->log('Temporada', $season->ref, 'crear', null, $this->label($season), actor: $actor);

            return ['season' => $season, 'warnings' => $warnings];
        });
    }

    /** @return array{season: Season, warnings: list<string>} */
    public function update(Season $season, array $data, User $actor): array
    {
        $data = Arr::only($data, self::EDITABLE);
        $warnings = $this->validate($data + $season->only(self::EDITABLE), $season);

        return DB::transaction(function () use ($season, $data, $warnings, $actor) {
            $before = $this->label($season);
            $statusBefore = $season->status;
            $season->fill($data);
            if (! $season->isDirty()) {
                return ['season' => $season, 'warnings' => []];
            }
            $this->guarded(fn () => $season->save());
            $action = $season->wasChanged('status') && count($season->getChanges()) <= 3 ? 'cambio_estado' : 'modificar';
            $this->audit->log('Temporada', $season->ref, $action, $action === 'cambio_estado' ? $statusBefore : $before,
                $action === 'cambio_estado' ? $season->status : $this->label($season), actor: $actor);

            return ['season' => $season, 'warnings' => $warnings];
        });
    }

    /** Borrar solo si nada la usa; si no, 409 sugiriendo inactivar (CAT-BR-17). */
    public function delete(Season $season, User $actor): void
    {
        $rates = Rate::where('season_id', $season->id)->count();
        $products = ProductSeason::where('season_id', $season->id)->count();
        if ($rates + $products > 0) {
            throw ApiException::rule('CAT-BR-17', "No se puede eliminar: la usan {$rates} tarifa(s) y {$products} producto(s). Puede inactivarla.",
                ['rates' => $rates, 'products' => $products, 'suggest' => 'inactivar']);
        }
        DB::transaction(function () use ($season, $actor) {
            $season->delete();
            $this->audit->log('Temporada', $season->ref, 'eliminar', $this->label($season), null, actor: $actor);
        });
    }

    /** CAT-BR-18: activas que contienen la fecha, por prioridad ascendente. @return Collection<int, Season> */
    public function matching(string $date): Collection
    {
        return Season::where('status', 'activa')->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date)
            ->orderBy('priority')->orderBy('ref')->get();
    }

    public function resolve(string $date): ?Season
    {
        return $this->matching($date)->first();
    }

    /** Pares de temporadas activas que se solapan (pantalla de temporadas). */
    public function overlaps(): array
    {
        $active = Season::where('status', 'activa')->orderBy('date_from')->get()->values();
        $pairs = [];
        foreach ($active as $i => $a) {
            foreach ($active->slice($i + 1) as $b) {
                if ($a->date_from->lte($b->date_to) && $b->date_from->lte($a->date_to)) {
                    $pairs[] = [
                        'a' => $a->ref, 'b' => $b->ref,
                        'from' => max($a->date_from->toDateString(), $b->date_from->toDateString()),
                        'to' => min($a->date_to->toDateString(), $b->date_to->toDateString()),
                        'prevails' => $a->priority <= $b->priority ? $a->ref : $b->ref,
                        'conflict' => $a->priority === $b->priority,
                    ];
                }
            }
        }

        return $pairs;
    }

    /** @return list<string> advertencias */
    private function validate(array $data, ?Season $current): array
    {
        $date = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v;
        $from = $date($data['date_from']);
        $to = $date($data['date_to']);
        if ($to < $from) {
            throw ApiException::invalid('date_to', 'La fecha “hasta” debe ser igual o posterior a “desde”.');
        }
        $factor = (float) $data['factor'];
        if ($factor < 0.01 || $factor > 3) {
            throw ApiException::invalid('factor', 'El factor debe estar entre 0.01 y 3.00.');
        }
        if ((int) $data['priority'] < 0) {
            throw ApiException::invalid('priority', 'La prioridad no puede ser negativa.');
        }
        if (($data['status'] ?? 'activa') !== 'activa') {
            return [];
        }
        $overlapping = Season::where('status', 'activa')->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from)
            ->when($current, fn ($q) => $q->where('id', '<>', $current->id))->get();
        $same = $overlapping->firstWhere('priority', (int) $data['priority']);
        if ($same) {
            throw ApiException::unprocessable('PROC-019', "PROC-019 · solapamiento no resoluble con {$same->ref} {$same->name}: tienen la misma prioridad ({$same->priority}). Cambie la prioridad o las fechas.", ['conflict' => $same->ref]);
        }

        return $overlapping->map(fn (Season $s) => "Se solapa con {$s->ref} {$s->name} (prioridad {$s->priority}); prevalece la de menor número.")->values()->all();
    }

    /** La restricción EXCLUDE es la última barrera ante carreras: se traduce al mismo 422. */
    private function guarded(callable $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw ApiException::unprocessable('PROC-019', 'PROC-019 · solapamiento no resoluble: otra temporada activa con la misma prioridad cubre esas fechas.');
            }
            throw $e;
        }
    }

    private function label(Season $s): string
    {
        return "{$s->name} · {$s->date_from->toDateString()}→{$s->date_to->toDateString()} · prioridad {$s->priority} · factor {$s->factor} · {$s->status}";
    }
}
