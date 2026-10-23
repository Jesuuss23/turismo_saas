<?php

namespace App\Services\Crm\Linked;

/**
 * Datos de otros módulos que el CRM lee (spec 01 §3.12): cotizaciones, reservas, pasajeros,
 * CxC, pagos, comprobantes, vouchers y comisiones. Mientras esos módulos no existan se usa
 * NullLinkedData; cada módulo reemplazará el binding con su implementación real.
 *
 * Todas las filas son arrays planos listos para la API. `$ownerId` aplica el alcance de Ventas.
 */
interface LinkedData
{
    /** @return list<string> ids de clientes con cotización o reserva del asesor (cartera, CRM-BR-33). */
    public function clientIdsWithRecordsOf(string $userId): array;

    /** @return list<array> cotizaciones de la oportunidad con total de la versión vigente. */
    public function quotesForOpportunity(string $opportunityId): array;

    /** ¿Hay una cotización aprobada o convertida vinculada? (CRM-BR-23). */
    public function hasApprovedQuote(string $opportunityId): bool;

    /** @return list<array> filas de la pestaña `$tab` del 360 (quotes, reservations, passengers, receivables, payments, invoices, vouchers). */
    public function clientTab(string $clientId, string $tab, ?string $ownerId): array;

    /**
     * Agregados de compras por cliente (CRM-BR-34): reservations_count, total_pen, balance_pen,
     * overdue_pen, last_purchase_date, visited_destinations.
     *
     * @param  list<string>  $clientIds
     * @return array<string, array>
     */
    public function purchaseStats(array $clientIds, ?string $ownerId = null): array;

    /** Reserva para encuestas: [id, code, client_id, status, owner_id, end_date] o null. */
    public function reservation(string $reservationId): ?array;

    /** @return list<array{id: string, code: string, client_id: string, client_code: string, client_name: string, destination: ?string, start_date: string, end_date: string, status: string}> viajes por cerrar (CRM-BR-37). */
    public function tripsToClose(?string $ownerId): array;

    /** @return list<array> eventos de línea de tiempo del 360 de otros módulos (cotizaciones, reservas, pagos). */
    public function clientTimeline(string $clientId, ?string $ownerId): array;

    /** CRM-BR-06 / PRD-BR-002: producto existente en estado ACTIVE o APPROVED. */
    public function productSelectable(string $productId): bool;

    /** Destino de la jerarquía de Catálogo (Departamento, Ciudad, Zona, Lugar, Atracción): [id, label] o null. */
    public function destination(string $destinationId): ?array;

    /** Bloques del panel de Ventas que dependen de otros módulos (§7.4). */
    public function salesPanel(string $userId): array;
}
