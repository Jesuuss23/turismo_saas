<?php

namespace App\Services\Integrations;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Services\AuditLogger;
use App\Services\Platform\PlatformCatalog;
use App\Services\SequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aplicaciones OAuth 2.0 del tenant (spec 13 §3.14, entitlement `oauth_apps`): registro del cliente (Authorization Code + PKCE),
 * secreto mostrado una sola vez y guardado como SHA-256, rotación y revocación. El servidor de autorización (Passport) queda
 * fuera de esta entrega (§12-7): aquí se administra el registro de clientes.
 */
class OAuthAppService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    public function list(): array
    {
        return DB::table('integration_oauth_apps')->orderByDesc('created_at')->get()->map(fn ($a) => $this->row($a))->all();
    }

    public function row(object $a): array
    {
        return ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'vendor' => $a->vendor, 'client_id' => $a->client_id, 'secret_prefix' => $a->secret_prefix,
            'redirect_uris' => json_decode($a->redirect_uris, true), 'allowed_scopes' => json_decode($a->allowed_scopes, true), 'status' => $a->status,
            'status_label' => $a->status === 'active' ? 'Activa' : 'Revocada', 'grant' => 'authorization_code + PKCE', 'created_at' => $a->created_at, 'revoked_at' => $a->revoked_at];
    }

    public function create(array $data, User $actor): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ApiException::invalid('name', 'Indique el nombre de la aplicación.');
        }
        $uris = array_values(array_filter(array_map('trim', is_array($data['redirect_uris'] ?? null) ? $data['redirect_uris'] : preg_split('/[\s,]+/', (string) ($data['redirect_uris'] ?? '')))));
        if ($uris === []) {
            throw ApiException::invalid('redirect_uris', 'Indique al menos una URL de redirección.');
        }
        foreach ($uris as $uri) {
            if (! preg_match('#^https://[^\s/$.?\#].[^\s]*$#i', $uri)) {
                throw ApiException::invalid('redirect_uris', "La URL de redirección debe ser HTTPS: {$uri}.");
            }
        }
        $scopes = array_values(array_unique(array_filter((array) ($data['allowed_scopes'] ?? []), 'is_string')));
        if ($scopes === [] || array_diff($scopes, array_keys(PlatformCatalog::SCOPES))) {
            throw ApiException::invalid('allowed_scopes', 'Elija al menos un scope válido.');
        }
        $secret = 'qos_'.Str::random(40);
        $id = (string) Str::uuid7();
        $code = $this->sequences->next('oauth_app');
        DB::table('integration_oauth_apps')->insert(['id' => $id, 'tenant_id' => $this->context->id(), 'code' => $code, 'name' => Str::limit($name, 115),
            'vendor' => Str::limit(trim((string) ($data['vendor'] ?? '')) ?: $name, 115), 'client_id' => 'qoa_'.Str::lower(Str::random(24)), 'secret_hash' => hash('sha256', $secret),
            'secret_prefix' => substr($secret, 0, 10), 'redirect_uris' => json_encode($uris), 'allowed_scopes' => json_encode($scopes), 'status' => 'active',
            'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->log('App OAuth', $code, 'crear', null, "{$name} · ".implode(', ', $scopes), actor: $actor);

        return $this->row($this->find($id)) + ['client_secret' => $secret];
    }

    public function rotate(string $id, User $actor): array
    {
        $app = $this->find($id);
        if ($app->status !== 'active') {
            throw ApiException::conflict('OAUTH_APP_REVOKED', 'La aplicación está revocada.');
        }
        $secret = 'qos_'.Str::random(40);
        DB::table('integration_oauth_apps')->where('id', $id)->update(['secret_hash' => hash('sha256', $secret), 'secret_prefix' => substr($secret, 0, 10), 'updated_at' => now()]);
        $this->audit->log('App OAuth', $app->code, 'rotar_secreto', "{$app->secret_prefix}…", substr($secret, 0, 10).'…', actor: $actor);

        return $this->row($this->find($id)) + ['client_secret' => $secret];
    }

    public function revoke(string $id, User $actor): array
    {
        $app = $this->find($id);
        if ($app->status !== 'active') {
            throw ApiException::conflict('OAUTH_APP_REVOKED', 'La aplicación ya está revocada.');
        }
        DB::table('integration_oauth_apps')->where('id', $id)->update(['status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
        $this->audit->log('App OAuth', $app->code, 'revocar', 'Activa', 'Revocada', actor: $actor);

        return $this->row($this->find($id));
    }

    private function find(string $id): object
    {
        return (Str::isUuid($id) ? DB::table('integration_oauth_apps')->where('id', $id)->first() : null) ?? throw ApiException::notFound();
    }
}
