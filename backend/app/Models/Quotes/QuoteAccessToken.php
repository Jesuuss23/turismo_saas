<?php

namespace App\Models\Quotes;

use App\Models\TenantModel;

/** Enlace de acceso del cliente (§3): se guarda el hash y una copia cifrada, nunca el token en claro. */
class QuoteAccessToken extends TenantModel
{
    public const AUDIT_COLUMNS = ['created_by'];

    public $timestamps = false;

    protected $table = 'quote_access_tokens';

    protected $hidden = ['token_cipher'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'expires_at' => 'date', 'revoked_at' => 'datetime'];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function plain(): string
    {
        return decrypt($this->token_cipher);
    }
}
