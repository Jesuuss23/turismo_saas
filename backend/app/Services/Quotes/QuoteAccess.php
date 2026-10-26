<?php

namespace App\Services\Quotes;

use App\Exceptions\ApiException;
use App\Models\Iam\User;
use App\Models\Quotes\Quote;
use App\Services\Crm\CrmAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alcance y visibilidad de cotizaciones (spec 03 §7): Ventas solo `owner = yo` (QTE-BR-26),
 * el cliente solo las suyas ya enviadas; costos y margen según `seeMargin`.
 */
class QuoteAccess
{
    public const VIEW = 'quotes.view';

    public const CREATE = 'quotes.create';

    public const SEND = 'quotes.send';

    public const APPROVE_INTERNAL = 'quotes.approve_internal';

    public const DISCOUNT_OVERRIDE = 'quotes.discount_override';

    public const REASSIGN = 'quotes.reassign';

    /** Registrar la aprobación del cliente (§11-3): aprobar = compromiso de reservar. */
    public const CLIENT_APPROVAL = 'reservations.create';

    public function can(User $user, string $permission): bool
    {
        return $user->hasPermissionTo($permission);
    }

    public function ownOnly(User $user): bool
    {
        return $user->dataScope() === 'own';
    }

    public function scope(Builder $query, User $user): Builder
    {
        return match (true) {
            $user->isExternal() => $query->where('client_id', $user->client_id)->where('status', '<>', Quote::DRAFT),
            $this->ownOnly($user) => $query->where('owner_id', $user->id),
            default => $query,
        };
    }

    public function ensureVisible(Quote $quote, User $user): void
    {
        if (! $this->scope(Quote::whereKey($quote->id), $user)->exists()) {
            throw CrmAccess::outOfScope();
        }
    }

    /** §7: finance.profitability ∨ catalog.products.view_profitability ∨ quotes.approve_internal ∨ (Ventas ∧ dueño). */
    public function seeMargin(User $user, ?Quote $quote = null): bool
    {
        if ($user->isExternal()) {
            return false;
        }

        return $user->hasAnyPermission(['finance.profitability', 'catalog.products.view_profitability', self::APPROVE_INTERNAL])
            || ($this->ownOnly($user) && ($quote === null || $quote->owner_id === $user->id));
    }

    /** QTE-BR-15: solicita el dueño con quotes.create o quotes.send (los supervisores aprueban directo). */
    public function canRequestApproval(Quote $quote, User $user): bool
    {
        return $user->hasAnyPermission([self::CREATE, self::SEND]) && (! $this->ownOnly($user) || $quote->owner_id === $user->id);
    }

    public function require(User $user, string $permission, string $what): void
    {
        if (! $user->hasPermissionTo($permission)) {
            throw ApiException::forbidden('FORBIDDEN', "Su rol no puede {$what}", [$permission]);
        }
    }
}
