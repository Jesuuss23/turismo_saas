<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\Crm\Activity;
use App\Models\Crm\AfterSalesCase;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\Opportunity;
use App\Models\Iam\User;
use App\Services\Crm\Linked\LinkedData;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alcance por rol del CRM (CRM-BR-33/47, `Scope.ownOnly()` del prototipo): Ventas ve "Mi cartera";
 * supervisores (Tenant Admin, Manager) y lectores de 360 (Finanzas, Contabilidad) ven todo el tenant.
 * Se aplica en listas, KPIs, búsquedas, selects de relación y exportaciones.
 */
class CrmAccess
{
    public const REASSIGN = 'crm.records.reassign';

    public function __construct(private readonly LinkedData $linked) {}

    public function ownOnly(User $user): bool
    {
        return $user->dataScope() === 'own';
    }

    /** Supervisor = puede reasignar (CRM-BR-09, §11-24: permiso explícito en lugar del nombre del rol). */
    public function isSupervisor(User $user): bool
    {
        return $user->hasPermissionTo(self::REASSIGN);
    }

    // ── Scopes de consulta ─────────────────────────────────────────────

    public function leads(Builder $query, User $user): Builder
    {
        return $this->ownOnly($user)
            ? $query->where(fn ($q) => $q->where('owner_id', $user->id)->orWhereNull('owner_id'))
            : $query;
    }

    public function opportunities(Builder $query, User $user): Builder
    {
        return $this->ownOnly($user) ? $query->where('owner_id', $user->id) : $query;
    }

    public function activities(Builder $query, User $user): Builder
    {
        return $this->ownOnly($user) ? $query->where('owner_id', $user->id) : $query;
    }

    /** Cartera: clientes propios o con oportunidad/cotización/reserva mía (CRM-BR-33). */
    public function clients(Builder $query, User $user): Builder
    {
        if (! $this->ownOnly($user)) {
            return $query;
        }

        $extra = $this->linked->clientIdsWithRecordsOf($user->id);

        return $query->where(function ($q) use ($user, $extra) {
            $q->where('crm_clients.owner_id', $user->id)
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('crm_opportunities as o')
                    ->whereColumn('o.client_id', 'crm_clients.id')->where('o.owner_id', $user->id)->whereNull('o.deleted_at'));
            if ($extra !== []) {
                $q->orWhereIn('crm_clients.id', $extra);
            }
        });
    }

    /** Casos: owner del caso o cliente en cartera (CRM-BR-44). */
    public function cases(Builder $query, User $user): Builder
    {
        if (! $this->ownOnly($user)) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where('crm_cases.owner_id', $user->id)
            ->orWhereIn('crm_cases.client_id', $this->clients(Client::query(), $user)->select('crm_clients.id')));
    }

    // ── Verificaciones por registro (403 SCOPE_OWNERSHIP) ─────────────

    public function ensureLeadVisible(Lead $lead, User $user): void
    {
        if ($this->ownOnly($user) && $lead->owner_id !== null && $lead->owner_id !== $user->id) {
            throw self::outOfScope();
        }
    }

    /** Gestionar exige ser propietario (Ventas) o supervisor; un lead en cola hay que tomarlo primero. */
    public function ensureLeadManageable(Lead $lead, User $user): void
    {
        $this->ensureLeadVisible($lead, $user);
        if ($this->ownOnly($user) && $lead->owner_id === null) {
            throw ApiException::forbidden('SCOPE_OWNERSHIP', 'Tome el lead para gestionarlo');
        }
    }

    public function ensureOpportunity(Opportunity $opportunity, User $user): void
    {
        if ($this->ownOnly($user) && $opportunity->owner_id !== $user->id) {
            throw self::outOfScope();
        }
    }

    public function ensureActivity(Activity $activity, User $user): void
    {
        if ($this->ownOnly($user) && $activity->owner_id !== $user->id) {
            throw self::outOfScope();
        }
    }

    public function ensureClient(Client $client, User $user): void
    {
        if ($this->ownOnly($user) && ! $this->clients(Client::whereKey($client->id), $user)->exists()) {
            throw self::outOfScope();
        }
    }

    public function ensureCase(AfterSalesCase $case, User $user): void
    {
        if ($this->ownOnly($user) && ! $this->cases(AfterSalesCase::whereKey($case->id), $user)->exists()) {
            throw ApiException::forbidden('SCOPE_OWNERSHIP', 'Su rol no puede gestionar casos de postventa de este cliente');
        }
    }

    /** Vista parcial del 360: Ventas viendo un cliente cuyo responsable es otro asesor. */
    public function partialView(Client $client, User $user): bool
    {
        return $this->ownOnly($user) && $client->owner_id !== $user->id;
    }

    public function ensureSupervisor(User $user, string $what = 'reasignar registros'): void
    {
        if (! $this->isSupervisor($user)) {
            throw ApiException::forbidden('CRM-BR-09', "Solo Gerencia o el Administrador pueden {$what}", [self::REASSIGN]);
        }
    }

    public static function outOfScope(): ApiException
    {
        return ApiException::forbidden('SCOPE_OWNERSHIP', 'Registro fuera de su cartera');
    }
}
