<?php

namespace App\Security;

/**
 * Port de `Access.check` del prototipo (§7.5). La misma regla decide el menú y la URL,
 * y la usa GET /me/access para que el frontend no sea autoridad.
 */
final class Access
{
    public static function check(?AccessSubject $subject, string $path): AccessDecision
    {
        $path = AccessMap::normalize($path);

        foreach (AccessMap::PUBLIC_PREFIXES as $public) {
            if (AccessMap::matches($path, $public)) {
                return AccessDecision::allow();
            }
        }

        // (1) Sin sesión → login.
        if ($subject === null) {
            return AccessDecision::login();
        }

        $ux = $subject->ux();

        // (2) Experiencias externas → solo sus prefijos (SEC-BR-05, SEC-BR-12).
        if ($ux['experience'] === 'portal' || $ux['experience'] === 'mobile') {
            foreach ($ux['routes'] as $prefix) {
                if (AccessMap::matches($path, $prefix)) {
                    return AccessDecision::allow();
                }
            }

            // Un cliente que abre el portal de otro cliente vuelve al suyo.
            if ($subject->role === 'customer' && AccessMap::matches($path, 'canales/portal-cliente')) {
                return new AccessDecision(false, 'Solo puede ver los datos vinculados a su cuenta', redirect: $ux['home']);
            }

            return new AccessDecision(
                false,
                sprintf('Esta pantalla pertenece al back-office interno de la agencia y no está disponible para usuarios externos (%s)', $subject->roleLabel ?? $subject->role),
            );
        }

        // (3) Super Admin → solo sus 4 rutas (SEC-BR-06).
        if ($ux['experience'] === 'platform') {
            foreach (RoleUx::PLATFORM_ROUTES as $prefix) {
                if (AccessMap::matches($path, $prefix)) {
                    return AccessDecision::allow();
                }
            }

            return new AccessDecision(false, 'fuera del alcance del rol');
        }

        // (4) Internos: prefijo más largo; `*` o sin entrada → permitido; si no, cualquiera de.
        $prefix = AccessMap::longestPrefix($path);
        if ($prefix === null) {
            return AccessDecision::allow();
        }

        $required = AccessMap::SCREENS[$prefix][0];
        if ($required === [AccessMap::ANY_INTERNAL] || $subject->canAny($required)) {
            return AccessDecision::allow();
        }

        if ($required === [AccessMap::EXTRANET_ONLY]) {
            return new AccessDecision(false, 'fuera del alcance del rol');
        }

        return new AccessDecision(false, 'requiere '.implode(' | ', $required), $required);
    }

    /**
     * Payload de GET /me/access.
     *
     * @return array{experience: string, label: string, home: string, routes: list<string>, nav: list<array>, screens: array<string, bool>}
     */
    public static function describe(AccessSubject $subject): array
    {
        $ux = $subject->ux();

        $screens = [];
        foreach (array_keys(AccessMap::SCREENS) as $route) {
            $probe = $subject->role === 'customer' && $route === 'canales/portal-cliente'
                ? "{$route}/{$subject->clientId}"
                : $route;
            $screens[$route] = self::check($subject, $probe)->allowed;
        }

        $routes = $ux['routes'] ?? array_keys(array_filter($screens));

        $nav = $ux['nav'];
        if ($ux['experience'] === 'backoffice' || $ux['experience'] === 'platform') {
            foreach (AccessMap::SCREENS as $route => [, $label, $group]) {
                if ($group !== null && $screens[$route]) {
                    $nav[] = ['route' => $route, 'label' => $label, 'group' => $group];
                }
            }
        }

        return [
            'experience' => $ux['experience'],
            'label' => $ux['label'],
            'home' => $ux['home'],
            'routes' => array_values($routes),
            'nav' => $nav,
            'screens' => $screens,
        ];
    }
}
