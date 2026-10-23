<?php

namespace App\Services\Search;

use App\Models\Iam\User;

/**
 * Cada módulo registra su proveedor de búsqueda global (clientes, leads, cotizaciones…).
 * El proveedor aplica ownership (SEC-BR-09) y devuelve la ruta de pantalla del resultado,
 * que GlobalSearch filtra con Access::check.
 */
interface SearchProvider
{
    /** @return list<array{type: string, id: string, code: string, title: string, subtitle?: string, route: string}> */
    public function search(User $user, string $term, int $limit): array;
}
