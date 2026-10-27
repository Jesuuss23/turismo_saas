<?php

namespace App\Services\Marketing;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Models\Marketing\Segment;
use App\Services\AuditLogger;
use App\Services\SequenceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Segmentos (spec 14 §2, MKT-BR-12/13): nombre único, reglas del catálogo; un segmento usado por campañas no se elimina. */
class SegmentService
{
    public function __construct(
        private readonly SegmentRules $rules,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data, User $actor): Segment
    {
        $fields = $this->fields($data);

        return $this->unique(fn () => DB::transaction(function () use ($fields, $actor) {
            $s = Segment::create($fields + ['code' => $this->sequences->next('mkt_segment'), 'owner_id' => $actor->id]);
            $this->audit->log('Segmento', $s->code, 'crear', null, "{$s->name} · {$this->rules->describe($s->match, $s->rules)}", actor: $actor);

            return $s;
        }));
    }

    public function update(Segment $segment, array $data, User $actor): Segment
    {
        $fields = $this->fields($data + $segment->only(['name', 'description', 'match', 'rules']));
        $before = $this->rules->describe($segment->match, $segment->rules ?? []);

        return $this->unique(function () use ($segment, $fields, $before, $actor) {
            $segment->forceFill($fields)->save();
            $this->audit->log('Segmento', $segment->code, 'editar', $before, "{$segment->name} · {$this->rules->describe($segment->match, $segment->rules)}", actor: $actor);

            return $segment;
        });
    }

    public function duplicate(Segment $segment, User $actor): Segment
    {
        $name = mb_substr("{$segment->name} (copia)", 0, 150);
        for ($n = 2; Segment::whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists(); $n++) {
            $name = mb_substr("{$segment->name} (copia {$n})", 0, 150);
        }
        $copy = $this->create(['name' => $name, 'description' => $segment->description, 'match' => $segment->match, 'rules' => $segment->rules], $actor);
        $this->audit->log('Segmento', $copy->code, 'duplicar', $segment->code, $copy->name, actor: $actor);

        return $copy;
    }

    /** MKT-BR-12: evidencia de audiencia de campañas → no se elimina (409). */
    public function delete(Segment $segment, User $actor): void
    {
        $used = $segment->campaigns()->pluck('code');
        if ($used->isNotEmpty()) {
            throw ApiException::rule('MKT-BR-12', "El segmento lo usa(n) {$used->implode(', ')}: duplíquelo en lugar de eliminarlo.", ['campaigns' => $used->all()]);
        }
        $segment->delete();
        $this->audit->log('Segmento', $segment->code, 'eliminar', $segment->name, null, actor: $actor);
    }

    private function fields(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            throw ApiException::invalid('name', 'Indique el nombre del segmento (máx. 160 caracteres).');
        }
        $match = (string) ($data['match'] ?? 'all');
        if (! in_array($match, ['all', 'any'], true)) {
            throw ApiException::invalid('match', 'La coincidencia debe ser TODAS o ALGUNA.');
        }

        return ['name' => $name, 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'match' => $match, 'rules' => $this->rules->validate($data['rules'] ?? [])];
    }

    private function unique(callable $fn): Segment
    {
        try {
            return $fn();
        } catch (UniqueConstraintViolationException) {
            throw ApiException::invalid('name', 'Ya existe un segmento con ese nombre.');
        }
    }
}
