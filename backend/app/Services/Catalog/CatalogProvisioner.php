<?php

namespace App\Services\Catalog;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Datos base del catálogo por tenant (spec 02 §3): tipos, categorías, reglas de pricing y correlativos. */
class CatalogProvisioner
{
    /** Tipos de producto (Spec PRD §3): nombre => [requiere itinerario, requiere componentes]. */
    public const TYPES = [
        'TOUR' => [true, true],
        'EXCURSIÓN' => [true, true],
        'PAQUETE' => [true, true],
        'CIRCUITO' => [true, true],
        'PROGRAMA TURÍSTICO' => [true, true],
        'TRASLADO' => [false, true],
        'ALOJAMIENTO' => [false, true],
        'ACTIVIDAD' => [false, true],
        'ENTRADA' => [false, true],
        'TRANSPORTE' => [false, true],
        'EXPERIENCIA' => [false, true],
        'SERVICIO ADICIONAL' => [false, true],
        'PRODUCTO PERSONALIZADO' => [false, false],
    ];

    /** Categorías => subcategorías. */
    public const CATEGORIES = [
        'Cultural' => ['Arqueología', 'Ciudad', 'Museos'],
        'Aventura' => ['Trekking', 'Canotaje', 'Ciclismo'],
        'Naturaleza' => ['Amazonía', 'Lagos y montañas', 'Observación de aves'],
        'Gastronomía' => ['Clases de cocina', 'Rutas gastronómicas'],
        'Mística' => ['Ceremonias andinas'],
        'Corporativo' => ['Incentivos', 'Eventos'],
    ];

    /**
     * Reglas de pricing por defecto (CAT-BR-42): ref => [nombre, tipo, valor, ámbito, canal, min_pax, residencia, prioridad].
     * PR-08 (precio mínimo de un producto) se crea por producto, no aquí.
     */
    public const PRICE_RULES = [
        'PR-01' => ['Markup venta directa', 'Markup %', 25, 'Canal Directo y E-commerce', 'Directo', null, null, 10],
        'PR-02' => ['Markup canal B2B', 'Markup %', 15, 'Canal B2B (agencias)', 'B2B', null, null, 10],
        'PR-03' => ['IGV domiciliados', 'Impuesto %', 18, 'Clientes domiciliados en Perú', null, null, 'dom', 50],
        'PR-04' => ['IGV no domiciliados (exportación de servicios)', 'Impuesto %', 0, 'Clientes no domiciliados', null, null, 'nodom', 50],
        'PR-05' => ['Descuento grupos', 'Descuento %', 8, 'Grupos de 10 pasajeros o más', null, 10, null, 20],
        'PR-06' => ['Redondeo comercial', 'Redondeo', 1, 'Total redondeado a la unidad', null, null, null, 90],
        'PR-07' => ['Comisión estándar', 'Comisión %', 10, 'Comisión de venta por defecto', null, null, null, 60],
    ];

    /** doc_type => [etiqueta, prefijo, relleno, siguiente]. */
    public const SEQUENCES = [
        'catalog_product' => ['Producto', 'PRD-', 3, 1],
        'catalog_rate' => ['Tarifa', 'TAR-', 3, 1],
        'catalog_departure' => ['Salida', 'SAL-', 4, 101],
        'catalog_hold' => ['Hold de disponibilidad', 'HLD-', 4, 1],
        'catalog_service' => ['Servicio', 'SRV-', 3, 1],
        'catalog_provider' => ['Proveedor', 'PRV-', 3, 1],
        'catalog_season' => ['Temporada', 'TMP-', 2, 1],
        'catalog_price_rule' => ['Regla de precio', 'PR-', 2, 8],
        'catalog_promotion' => ['Promoción', 'PROMO-', 2, 1],
        'catalog_destination' => ['Destino', 'D', 2, 1],
        'catalog_category' => ['Categoría', 'CAT-', 1, 1],
    ];

    public static function seedTenant(string $tenantId, ConnectionInterface $db): void
    {
        $now = now();
        $row = fn (array $data) => ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now] + $data;

        if ($db->table('catalog_product_types')->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $order = 0;
        foreach (self::TYPES as $name => [$itinerary, $components]) {
            $db->table('catalog_product_types')->insert($row(['name' => $name, 'requires_itinerary' => $itinerary, 'requires_components' => $components, 'sort_order' => ++$order]));
        }

        $n = 0;
        foreach (self::CATEGORIES as $name => $subs) {
            $category = $row(['ref' => 'CAT-'.++$n, 'name' => $name]);
            $db->table('catalog_product_categories')->insert($category);
            foreach ($subs as $sub) {
                $db->table('catalog_product_subcategories')->insert($row(['category_id' => $category['id'], 'name' => $sub]));
            }
        }

        foreach (self::PRICE_RULES as $ref => [$name, $kind, $value, $scope, $channel, $minPax, $residency, $priority]) {
            $db->table('catalog_price_rules')->insert($row([
                'ref' => $ref, 'name' => $name, 'kind' => $kind, 'value' => $value, 'scope' => $scope, 'channel' => $channel,
                'min_pax' => $minPax, 'customer_residency' => $residency, 'priority' => $priority, 'status' => 'activa',
            ]));
        }

        foreach (self::SEQUENCES as $docType => [$label, $prefix, $pad, $next]) {
            $start = match ($docType) {
                'catalog_category' => count(self::CATEGORIES) + 1,
                default => $next,
            };
            $db->table('sys_sequences')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'doc_type' => $docType, 'label' => $label,
                'series' => 'default', 'prefix' => $prefix, 'pad_width' => $pad, 'next_number' => $start, 'year_reset' => false,
                'updated_at' => $now,
            ]);
        }
    }
}
