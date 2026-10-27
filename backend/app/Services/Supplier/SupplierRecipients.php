<?php

namespace App\Services\Supplier;

use App\Models\Catalog\Provider;
use App\Models\Iam\User;
use App\Models\Ops\Order;
use App\Services\Catalog\CatalogRecipients;
use App\Services\Notify;
use Illuminate\Support\Collection;

/**
 * Destinatarios de la extranet (spec 06 §8, §11-13): internos = coordinador de la orden o, sin orden, el grupo de Operaciones;
 * hacia el proveedor = sus usuarios con el permiso correspondiente + el email del contrato.
 */
class SupplierRecipients
{
    public function __construct(
        private readonly Notify $notify,
        private readonly SupplierAccess $access,
    ) {}

    /** @return Collection<int, User> */
    public function operations(?string $reservationId): Collection
    {
        $coordinator = $reservationId ? Order::where('reservation_id', $reservationId)->with('coordinator')->first()?->coordinator : null;

        return $coordinator ? collect([$coordinator]) : CatalogRecipients::operations();
    }

    public function toOperations(string $event, ?string $reservationId, string $subject, string $body, array $data = []): void
    {
        foreach ($this->operations($reservationId) as $user) {
            $this->notify->push($event, 'portal', $user, $subject, $body, $data);
        }
    }

    public function toRoles(array $roles, string $event, string $subject, string $body, array $data = []): void
    {
        foreach (CatalogRecipients::role($roles) as $user) {
            $this->notify->push($event, 'portal', $user, $subject, $body, $data);
        }
    }

    /** Usuarios del proveedor con el permiso (portal) y email del contrato. */
    public function toProvider(Provider $provider, string $permission, string $event, string $subject, string $body, array $data = []): void
    {
        $users = User::where('provider_id', $provider->id)->where('status', User::ACTIVE)->get()->filter(fn (User $u) => $this->access->allows($u, $permission));
        foreach ($users as $user) {
            $this->notify->push($event, 'portal', $user, $subject, $body, $data);
        }
        if ($provider->email) {
            $this->notify->push($event, 'email', $provider->email, $subject, $body, $data);
        }
    }
}
