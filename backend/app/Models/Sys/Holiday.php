<?php

namespace App\Models\Sys;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Feriado para el cómputo de días hábiles (spec 12 §3.11): `tenant_id` NULL = nacional; la RLS muestra los nacionales y los del tenant. */
class Holiday extends Model
{
    use HasUuids;

    /** Feriados nacionales (Perú) 2026–2027 para el plazo en días hábiles (LR-BR-02). */
    public const NATIONAL = [
        '2026-01-01' => 'Año Nuevo', '2026-04-02' => 'Jueves Santo', '2026-04-03' => 'Viernes Santo', '2026-05-01' => 'Día del Trabajo',
        '2026-06-07' => 'Batalla de Arica y Día de la Bandera', '2026-06-29' => 'San Pedro y San Pablo', '2026-07-23' => 'Día de la Fuerza Aérea',
        '2026-07-28' => 'Fiestas Patrias', '2026-07-29' => 'Fiestas Patrias', '2026-08-06' => 'Batalla de Junín', '2026-08-30' => 'Santa Rosa de Lima',
        '2026-10-08' => 'Combate de Angamos', '2026-11-01' => 'Todos los Santos', '2026-12-08' => 'Inmaculada Concepción',
        '2026-12-09' => 'Batalla de Ayacucho', '2026-12-25' => 'Navidad',
        '2027-01-01' => 'Año Nuevo', '2027-03-25' => 'Jueves Santo', '2027-03-26' => 'Viernes Santo', '2027-05-01' => 'Día del Trabajo',
        '2027-06-07' => 'Batalla de Arica y Día de la Bandera', '2027-06-29' => 'San Pedro y San Pablo', '2027-07-23' => 'Día de la Fuerza Aérea',
        '2027-07-28' => 'Fiestas Patrias', '2027-07-29' => 'Fiestas Patrias', '2027-08-06' => 'Batalla de Junín', '2027-08-30' => 'Santa Rosa de Lima',
        '2027-10-08' => 'Combate de Angamos', '2027-11-01' => 'Todos los Santos', '2027-12-08' => 'Inmaculada Concepción',
        '2027-12-09' => 'Batalla de Ayacucho', '2027-12-25' => 'Navidad',
    ];

    protected $table = 'sys_holidays';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** Inserta los feriados nacionales que falten (migración y pruebas, que vacían la tabla con TRUNCATE tenants CASCADE). */
    public static function seedNational(ConnectionInterface $db): void
    {
        $now = now();
        $db->table('sys_holidays')->insertOrIgnore(collect(self::NATIONAL)->map(fn ($name, $date) => ['id' => (string) Str::uuid7(), 'date' => $date, 'name' => $name,
            'created_at' => $now, 'updated_at' => $now])->values()->all());
    }
}
