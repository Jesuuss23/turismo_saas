<?php

namespace App\Services\Finance;

use App\Models\Finance\StatusHistory;
use App\Models\Iam\User;

/** Utilidades del módulo (spec 07): redondeo `r2`, tolerancia de montos y el historial de estados (§3.5). */
final class Fin
{
    /** Tolerancia de redondeo en comparaciones de montos. */
    public const EPS = 0.005;

    /** Diferencia aceptada al casar montos (conciliación, cobrado por facturar). */
    public const MATCH = 0.50;

    public static function r2(float|string|null $v): float
    {
        return round((float) $v + 0.0, 2);
    }

    public static function money(float|string|null $v): string
    {
        return number_format(self::r2($v), 2, '.', '');
    }

    public static function history(string $type, string $id, string $status, ?string $note, ?User $user): void
    {
        StatusHistory::create(['entity_type' => $type, 'entity_id' => $id, 'status' => $status, 'note' => $note, 'user_id' => $user?->id, 'created_at' => now()]);
    }

    /** Historial de una entidad para su detalle. @return list<array<string, mixed>> */
    public static function historyOf(string $type, string $id): array
    {
        return StatusHistory::where('entity_type', $type)->where('entity_id', $id)->orderBy('created_at')->get()
            ->map(fn (StatusHistory $h) => ['status' => $h->status, 'note' => $h->note, 'at' => $h->created_at?->toIso8601String(),
                'user' => $h->user_id ? User::find($h->user_id)?->name : 'Sistema'])->all();
    }
}
