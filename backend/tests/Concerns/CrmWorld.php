<?php

namespace Tests\Concerns;

use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Models\Tenant;
use App\Services\Crm\ClientService;
use App\Services\Crm\LeadService;
use App\Services\Crm\OpportunityService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

/**
 * Escenario CRM para pruebas: tenant "andes" con un usuario por rol interno
 * (admin, manager, sales U03, sales U04, operaciones, finanzas, contabilidad).
 */
trait CrmWorld
{
    protected Tenant $tenant;

    /** @var array<string, User> */
    protected array $users = [];

    /** @var array<string, string> */
    protected array $tokens = [];

    public function crmWorld(): void
    {
        $this->tenant = $this->makeTenant();
        foreach ([
            'admin' => 'tenant_admin', 'manager' => 'manager', 'u03' => 'sales', 'u04' => 'sales',
            'ops' => 'operations', 'finance' => 'finance', 'accounting' => 'accounting',
        ] as $key => $role) {
            $this->users[$key] = $this->makeUser($this->tenant, $role, ['code' => strtoupper($key), 'name' => ucfirst($key)]);
            $this->tokens[$key] = $this->tokenFor($this->users[$key]);
        }
    }

    public function as(string $who): static
    {
        return $this->api($this->tokens[$who]);
    }

    /** Ejecuta con contexto de tenant y usuario autenticado (para preparar datos con los servicios). */
    public function inTenant(callable $fn, string $as = 'admin'): mixed
    {
        Auth::setUser($this->users[$as]);
        try {
            return app(TenantContext::class)->run($this->tenant, $fn);
        } finally {
            Auth::forgetUser();
            $this->app['auth']->forgetGuards();
        }
    }

    public function leadData(array $overrides = []): array
    {
        return $overrides + [
            'full_name' => 'Valeria Campos',
            'email' => 'valeria'.random_int(1000, 999999).'@correo.test',
            'phone' => null,
            'channel_code' => 'web',
            'source_code' => 'formulario_web',
            'interest_code' => 'paquete',
            'destination_label' => 'Cusco',
            'pax' => 2,
        ];
    }

    public function makeLead(?string $owner, array $overrides = [], string $resolution = 'create_new'): Lead
    {
        return $this->inTenant(fn () => app(LeadService::class)->create(
            $this->leadData($overrides) + ['owner_id' => $owner ? $this->users[$owner]->id : null],
            $owner ? $this->users['admin'] : null,
            $resolution,
        )['lead']);
    }

    public function makeClient(string $owner, array $overrides = []): Client
    {
        return $this->inTenant(fn () => app(ClientService::class)->create($overrides + [
            'kind' => 'persona', 'name' => 'Ana Gutiérrez', 'doc_type' => 'DNI', 'doc_number' => (string) random_int(10000000, 99999999),
            'email' => 'ana'.random_int(1000, 999999).'@correo.test', 'segment_code' => 'retail', 'owner_id' => $this->users[$owner]->id,
        ], $this->users['admin'], true));
    }

    public function makeOpportunity(string $owner, Client $client, array $overrides = []): Opportunity
    {
        return $this->inTenant(fn () => app(OpportunityService::class)->create($overrides + [
            'client_id' => $client->id, 'owner_id' => $this->users[$owner]->id, 'title' => 'Viaje a Cusco', 'stage' => 'descubrimiento',
            'amount' => 5000, 'currency' => 'PEN', 'expected_close_date' => now()->addDays(20)->toDateString(),
        ], $this->users['admin']));
    }

    /** Cliente mínimo insertado fuera de RLS (para vincular usuarios externos en pruebas de otros módulos). */
    public function clientRow(Tenant $tenant, ?string $code = null): string
    {
        $owner = $this->makeUser($tenant, 'sales');

        return $this->admin()->table('crm_clients')->insertGetId([
            'tenant_id' => $tenant->id, 'code' => $code ?? 'CLI-'.random_int(1000, 9999), 'kind' => 'persona', 'name' => 'Cliente de prueba',
            'doc_type' => 'DNI', 'doc_number' => (string) random_int(10000000, 99999999), 'email' => 'cliente'.random_int(1, 99999).'@correo.test',
            'segment_code' => 'retail', 'owner_id' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Lee un modelo fuera de RLS para verificar el estado persistido. */
    public function fresh(string $table, string $id): object
    {
        return $this->admin()->table($table)->where('id', $id)->first();
    }

    public function auditRows(string $entity, string $entityId): array
    {
        return $this->admin()->table('audit_events')->where('entity', $entity)->where('entity_id', $entityId)->orderBy('occurred_at')->get()->all();
    }

    public function ifMatch(object $model): array
    {
        return ['If-Match' => (string) $model->lock_version];
    }

    public function idem(?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? bin2hex(random_bytes(8))];
    }

    public function crmPost(string $who, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->as($who)->withHeaders($headers)->postJson('/api/v1/crm/'.ltrim($uri, '/'), $data);
    }
}
