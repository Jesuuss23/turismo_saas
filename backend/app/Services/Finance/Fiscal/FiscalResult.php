<?php

namespace App\Services\Finance\Fiscal;

/** Respuesta normalizada del adaptador fiscal (§3.16): `status` técnico del intento y `outcome` del comprobante. */
final class FiscalResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $outcome = null,
        public readonly ?string $ticket = null,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
    ) {}

    public static function ok(string $outcome, ?string $ticket, ?string $code, ?string $message): self
    {
        return new self('ok', $outcome, $ticket, $code, $message);
    }

    public static function temporary(string $message): self
    {
        return new self('error_temporal', null, null, null, $message);
    }

    public static function definitive(string $message): self
    {
        return new self('error_definitivo', null, null, null, $message);
    }
}
