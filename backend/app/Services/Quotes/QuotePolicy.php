<?php

namespace App\Services\Quotes;

use App\Models\Quotes\InternalApproval;
use App\Models\Quotes\QuoteVersion;

/**
 * Política comercial de la cotización (QTE-BR-08/11/14, §12-1: umbrales fijos en código por ahora).
 * `QuotePolicyEvaluator` de la spec.
 */
class QuotePolicy
{
    /** Descuento a partir del cual se requiere aprobación interna. */
    public const DISCOUNT_INTERNAL = 5;

    /** Margen mínimo sin aprobación interna. */
    public const MARGIN_MIN = 15;

    /** Tope de descuento sin `quotes.discount_override` (MAX_DISC). */
    public const MAX_DISCOUNT = 10;

    /** Máximo absoluto con override. */
    public const OVERRIDE_MAX = 50;

    /**
     * QTE-BR-11: motivos por los que la versión necesita aprobación interna (vacío = dentro de política).
     *
     * @return list<string>
     */
    public static function reasons(string|float $discountPct, string|float $marginPct): array
    {
        $reasons = [];
        if ((float) $discountPct > self::DISCOUNT_INTERNAL) {
            $reasons[] = sprintf('descuento %s%% > %d%%', number_format((float) $discountPct, 1, '.', ''), self::DISCOUNT_INTERNAL);
        }
        if ((float) $marginPct < self::MARGIN_MIN) {
            $reasons[] = sprintf('margen %s%% < %d%%', number_format((float) $marginPct, 1, '.', ''), self::MARGIN_MIN);
        }

        return $reasons;
    }

    public static function overCap(string|float $discountPct): bool
    {
        return (float) $discountPct > self::MAX_DISCOUNT;
    }

    public static function versionReasons(QuoteVersion $version): array
    {
        return self::reasons((string) $version->discount_pct, (string) $version->margin_pct);
    }

    /**
     * Estado de la aprobación interna de la versión: `no_requerida`, `requerida`, `pendiente`, `aprobada` o `rechazada`.
     */
    public static function state(QuoteVersion $version): string
    {
        if ($version->internal_approval_id !== null && $version->internalApproval?->status === InternalApproval::APPROVED) {
            return 'aprobada';
        }
        if (self::versionReasons($version) === []) {
            return 'no_requerida';
        }
        $latest = $version->approvals()->whereIn('status', [InternalApproval::PENDING, InternalApproval::REJECTED])->first();

        return match ($latest?->status) {
            InternalApproval::PENDING => 'pendiente',
            InternalApproval::REJECTED => 'rechazada',
            default => 'requerida',
        };
    }

    /** Bloqueo de envío / aprobación del cliente (QTE-BR-12/13): requiere y no está aprobada. */
    public static function blocks(QuoteVersion $version): bool
    {
        return ! in_array(self::state($version), ['no_requerida', 'aprobada'], true);
    }

    public static function blockedMessage(QuoteVersion $version): string
    {
        return 'Bloqueado: requiere aprobación de gerencia ('.implode('; ', self::versionReasons($version)).')';
    }
}
