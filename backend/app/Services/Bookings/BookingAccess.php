<?php

namespace App\Services\Bookings;

use App\Exceptions\ApiException;
use App\Models\Bookings\Reservation;
use App\Models\Iam\User;
use App\Services\Crm\CrmAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alcance y datos sensibles por rol (§7, RES-BR-50): Ventas solo lo propio; Operaciones ve costos sin venta
 * ni margen (`opsView`); Contabilidad ve montos sin costos (`acc`).
 */
class BookingAccess
{
    public const VIEW = 'reservations.view';

    public const CREATE = 'reservations.create';

    public const MODIFY = 'reservations.modify';

    public const CANCEL = 'reservations.cancel';

    public const REASSIGN = 'reservations.reassign';

    /** RES-BR-14: aprueba modificaciones (propuesto reservations.approve_changes, §12-11). */
    public const APPROVE_CHANGES = 'quotes.approve_internal';

    public const RECEIVABLES = 'finance.receivables';

    public const PAYMENTS = 'finance.payments.register';

    public function ownOnly(User $user): bool
    {
        return $user->dataScope() === 'own';
    }

    public function scope(Builder $query, User $user): Builder
    {
        return match (true) {
            $user->isExternal() => $query->where('client_id', $user->client_id),
            $this->ownOnly($user) => $query->where('owner_id', $user->id),
            default => $query,
        };
    }

    public function ensureVisible(Reservation $reservation, User $user): void
    {
        if (! $this->scope(Reservation::whereKey($reservation->id), $user)->exists()) {
            throw CrmAccess::outOfScope();
        }
    }

    /** `seeMargin`: rentabilidad o Ventas en lo propio. */
    public function seeMargin(User $user, ?Reservation $reservation = null): bool
    {
        return $user->hasAnyPermission(['finance.profitability', 'catalog.products.view_profitability', self::APPROVE_CHANGES])
            || ($this->ownOnly($user) && ($reservation === null || $reservation->owner_id === $user->id));
    }

    /** `opsView = operations.view ∧ ¬seeMargin`. */
    public function opsView(User $user, ?Reservation $reservation = null): bool
    {
        return $user->hasPermissionTo('operations.view') && ! $this->seeMargin($user, $reservation);
    }

    /** `seeCost = seeMargin ∨ operations.view`. */
    public function seeCost(User $user, ?Reservation $reservation = null): bool
    {
        return $this->seeMargin($user, $reservation) || $user->hasPermissionTo('operations.view');
    }

    /** `seePrice = seeMargin ∨ ¬operations.view`. */
    public function seePrice(User $user, ?Reservation $reservation = null): bool
    {
        return $this->seeMargin($user, $reservation) || ! $user->hasPermissionTo('operations.view');
    }

    public function require(User $user, string $permission, string $what): void
    {
        if (! $user->hasPermissionTo($permission)) {
            throw ApiException::forbidden('FORBIDDEN', "Su rol no puede {$what}", [$permission]);
        }
    }
}
