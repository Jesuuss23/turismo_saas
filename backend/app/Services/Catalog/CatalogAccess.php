<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Models\Iam\User;

/**
 * Visibilidad de datos sensibles del catálogo (spec 02 §7.2, función `SEE`) y pestañas del
 * Product 360 por rol (§7.3). La API omite los campos; Next.js solo refleja (CAT-BR-40).
 */
class CatalogAccess
{
    public const VIEW = 'catalog.products.view';

    public const CREATE = 'catalog.products.create';

    public const UPDATE = 'catalog.products.update';

    public const ACTIVATE = 'catalog.products.activate';

    public const RATES = 'catalog.products.manage_rates';

    public const AVAIL = 'catalog.products.manage_availability';

    public const PROFIT = 'catalog.products.view_profitability';

    public const PUBLISH = 'catalog.products.publish';

    public const PROVIDERS = 'catalog.providers.manage';

    /** Pestañas del 360: clave de URL => [etiqueta, condición de visibilidad]. */
    public const TABS = [
        'general' => 'General', 'componentes' => 'Componentes', 'itinerario' => 'Itinerario', 'tarifas' => 'Tarifas',
        'temporadas' => 'Temporadas', 'disponibilidad' => 'Disponibilidad', 'salidas' => 'Salidas', 'reservas' => 'Reservas online', 'proveedores' => 'Proveedores',
        'politicas' => 'Políticas', 'inclusiones' => 'Inclusiones', 'relacionados' => 'Relacionados', 'b2b' => 'B2B',
        'promociones' => 'Promociones', 'rentabilidad' => 'Costos y rentabilidad', 'personalizacion' => 'Personalización',
        'contenido' => 'Contenido', 'canales' => 'Canales', 'versiones' => 'Versiones', 'historial' => 'Historial',
    ];

    public function can(User $user, string $permission): bool
    {
        return $user->hasPermissionTo($permission);
    }

    private function any(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->hasPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }

    /** Costo de proveedor: profit ∨ avail ∨ prov ∨ update ∨ rates. */
    public function seeCosts(User $user): bool
    {
        return $this->any($user, [self::PROFIT, self::AVAIL, self::PROVIDERS, self::UPDATE, self::RATES]);
    }

    /** Margen, utilidad, markup de referencia. */
    public function seeMargin(User $user): bool
    {
        return $this->can($user, self::PROFIT);
    }

    /** Tarifas netas y pestaña B2B: profit ∨ rates ∨ channels.b2b.manage. */
    public function seeNetRates(User $user): bool
    {
        return $this->any($user, [self::PROFIT, self::RATES, 'channels.b2b.manage']);
    }

    public function seeHistory(User $user): bool
    {
        return $this->any($user, [self::UPDATE, self::PROFIT, 'admin.audit.view']);
    }

    public function seeVersions(User $user): bool
    {
        return $this->any($user, [self::UPDATE, self::PROFIT]);
    }

    public function seePricingRules(User $user): bool
    {
        return $this->any($user, [self::PROFIT, self::RATES]);
    }

    public function seePayables(User $user): bool
    {
        return $this->can($user, 'finance.payables');
    }

    /** "Ruta proveedores" = prov ∨ finance.payables ∨ update (igual que ACCESS). */
    public function seeProviders(User $user): bool
    {
        return $this->any($user, [self::PROVIDERS, 'finance.payables', self::UPDATE]);
    }

    /** @return array<string, string> pestañas visibles (clave => etiqueta) en orden. */
    public function tabsFor(User $user): array
    {
        $hidden = [];
        if (! $this->seeCosts($user)) {
            $hidden[] = 'proveedores';
        }
        if (! $this->seeNetRates($user)) {
            $hidden[] = 'b2b';
        }
        if (! $this->seeMargin($user)) {
            $hidden[] = 'rentabilidad';
        }
        if (! $this->seeVersions($user)) {
            $hidden[] = 'versiones';
        }
        if (! $this->seeHistory($user)) {
            $hidden[] = 'historial';
        }
        // Operaciones ve costos pero no B2B (§7.3): B2B exige además tarifas netas, ya cubierto.

        return array_diff_key(self::TABS, array_flip($hidden));
    }

    /** Acciones de edición del 360 para pintar botones (la API vuelve a validar cada una). */
    public function actions(User $user): array
    {
        return [
            'create' => $this->can($user, self::CREATE),
            'update' => $this->can($user, self::UPDATE),
            'activate' => $this->can($user, self::ACTIVATE),
            'rates' => $this->can($user, self::RATES),
            'availability' => $this->can($user, self::AVAIL),
            'publish' => $this->can($user, self::PUBLISH),
            'providers' => $this->can($user, self::PROVIDERS),
            'see_costs' => $this->seeCosts($user),
            'see_margin' => $this->seeMargin($user),
            'see_net_rates' => $this->seeNetRates($user),
            'see_payables' => $this->seePayables($user),
            'see_pricing_rules' => $this->seePricingRules($user),
            'see_providers' => $this->seeProviders($user),
        ];
    }

    public function ensure(User $user, string|array $permissions): void
    {
        $permissions = (array) $permissions;
        if (! $this->any($user, $permissions)) {
            throw ApiException::forbidden('PRD-T13', 'Su rol no tiene el permiso requerido', $permissions);
        }
    }
}
