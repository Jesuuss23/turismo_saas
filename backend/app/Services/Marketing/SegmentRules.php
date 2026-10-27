<?php

namespace App\Services\Marketing;

use App\Exceptions\ApiException;
use Illuminate\Support\Carbon;

/**
 * Catálogo y evaluación de reglas de segmento (MKT-BR-13). Una regla es `{f, op, v}`; la coincidencia es TODAS (`all`) o ALGUNA (`any`).
 * El frontend usa el mismo catálogo (GET /marketing/segments → meta.fields) para el constructor.
 */
class SegmentRules
{
    /** campo => [etiqueta, operadores (op => etiqueta), tipo de valor, opciones]. */
    public const FIELDS = [
        'type' => ['Tipo de contacto', ['eq' => 'es'], 'select', ContactDirectory::TYPES],
        'segment' => ['Segmento CRM', ['eq' => 'es', 'neq' => 'no es'], 'select', ['retail' => 'Retail', 'vip' => 'VIP', 'corporativo' => 'Corporativo', 'grupos' => 'Grupos', 'b2b' => 'B2B']],
        'country' => ['País', ['eq' => 'es', 'neq' => 'no es'], 'text', null],
        'destination' => ['Destino de interés', ['contains' => 'contiene'], 'text', null],
        'last_purchase' => ['Última compra', ['within' => 'en los últimos N días', 'older' => 'hace más de N días o nunca', 'never' => 'sin compras'], 'days', null],
        'spend' => ['Gasto total S/', ['gte' => '≥', 'lte' => '≤'], 'number', null],
        'nps' => ['NPS', ['gte' => '≥', 'lte' => '≤', 'none' => 'sin encuesta'], 'number', null],
        'source' => ['Canal de origen', ['eq' => 'es'], 'select', ['web' => 'Web', 'whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'instagram' => 'Instagram',
            'email' => 'Email', 'telefono' => 'Teléfono', 'referido' => 'Referido', 'feria' => 'Feria', 'newsletter' => 'Newsletter', 'directo' => 'Directo']],
        'tag' => ['Etiqueta', ['has' => 'tiene'], 'text', null],
        'consent' => ['Consentimiento', ['granted' => 'otorgado para', 'none' => 'sin consentimiento para'], 'select', ['email' => 'Email', 'whatsapp' => 'WhatsApp']],
    ];

    /** Operadores que no llevan valor. */
    private const NO_VALUE = ['never', 'none'];

    /**
     * Valida y normaliza las reglas (422 VALIDATION_ERROR con el índice de la regla).
     *
     * @return list<array{f: string, op: string, v: mixed}>
     */
    public function validate(mixed $rules): array
    {
        if (! is_array($rules)) {
            throw ApiException::invalid('rules', 'Las reglas deben ser una lista.');
        }
        $out = [];
        foreach (array_values($rules) as $i => $rule) {
            $f = (string) ($rule['f'] ?? '');
            $op = (string) ($rule['op'] ?? '');
            $field = self::FIELDS[$f] ?? throw ApiException::invalid("rules.{$i}.f", 'Campo de regla no permitido (MKT-BR-13).');
            if (! isset($field[1][$op])) {
                throw ApiException::invalid("rules.{$i}.op", "Operador no permitido para «{$field[0]}».");
            }
            $v = $rule['v'] ?? null;
            if (! in_array($op, self::NO_VALUE, true)) {
                $v = is_string($v) ? trim($v) : $v;
                $valid = match ($field[2]) {
                    'select' => is_string($v) && isset($field[3][$v]),
                    'days' => is_numeric($v) && (int) $v >= 1 && (int) $v <= 3650,
                    'number' => is_numeric($v) && (float) $v >= 0,
                    default => is_string($v) && $v !== '' && mb_strlen($v) <= 120,
                };
                if (! $valid) {
                    throw ApiException::invalid("rules.{$i}.v", "Indique un valor válido para «{$field[0]}».");
                }
                $v = in_array($field[2], ['days'], true) ? (int) $v : ($field[2] === 'number' ? (float) $v : $v);
            } else {
                $v = null;
            }
            $out[] = ['f' => $f, 'op' => $op, 'v' => $v];
        }

        return $out;
    }

    /** @param array<string, ?string> $consent estado por canal del contacto */
    public function matches(array $contact, string $match, array $rules, array $consent, Carbon $at): bool
    {
        if ($rules === []) {
            return true;
        }
        foreach ($rules as $rule) {
            $ok = $this->rule($contact, $rule, $consent, $at);
            if ($match === 'any' && $ok) {
                return true;
            }
            if ($match === 'all' && ! $ok) {
                return false;
            }
        }

        return $match === 'all';
    }

    /** Reglas en texto para listados y auditoría (“País es Perú · NPS ≥ 9”). */
    public function describe(string $match, array $rules): string
    {
        if ($rules === []) {
            return 'Todos los contactos';
        }
        $parts = array_map(function (array $r) {
            [$label, $ops, $type, $options] = self::FIELDS[$r['f']];
            $value = $r['v'] === null ? '' : ' '.($options[$r['v']] ?? ($type === 'number' && $r['f'] === 'spend' ? 'S/ '.number_format((float) $r['v'], 0, '.', ',') : $r['v']));
            $op = str_replace('N días', "{$r['v']} días", $ops[$r['op']]);

            return $r['f'] === 'last_purchase' ? "{$label} {$op}" : "{$label} {$op}{$value}";
        }, $rules);

        return implode($match === 'all' ? ' · ' : ' o ', $parts);
    }

    private function rule(array $c, array $r, array $consent, Carbon $at): bool
    {
        $v = $r['v'];

        return match ($r['f']) {
            'type' => $c['type'] === $v,
            'segment' => $r['op'] === 'eq' ? $c['segment'] === $v : $c['segment'] !== $v,
            'country' => $r['op'] === 'eq' ? self::same($c['country'], $v) : ! self::same($c['country'], $v),
            'destination' => $c['destination'] !== null && str_contains(self::fold($c['destination']), self::fold((string) $v)),
            'last_purchase' => match ($r['op']) {
                'within' => $c['last_purchase_at'] !== null && Carbon::parse($c['last_purchase_at'])->greaterThanOrEqualTo($at->copy()->subDays($v)),
                'older' => $c['last_purchase_at'] === null || Carbon::parse($c['last_purchase_at'])->lessThan($at->copy()->subDays($v)),
                default => $c['last_purchase_at'] === null,
            },
            'spend' => $r['op'] === 'gte' ? $c['spend_pen'] >= $v : $c['spend_pen'] <= $v,
            'nps' => match ($r['op']) {
                'gte' => $c['nps'] !== null && $c['nps'] >= $v,
                'lte' => $c['nps'] !== null && $c['nps'] <= $v,
                default => $c['nps'] === null,
            },
            'source' => $c['source'] === $v,
            'tag' => collect($c['tags'])->contains(fn ($t) => self::same($t, $v)),
            'consent' => $r['op'] === 'granted' ? ($consent[$v] ?? null) === 'otorgado' : ($consent[$v] ?? null) !== 'otorgado',
            default => false,
        };
    }

    private static function same(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && self::fold($a) === self::fold($b);
    }

    private static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
