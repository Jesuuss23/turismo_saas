# Catálogo turístico (Productos, servicios, proveedores, destinos, tarifas, temporadas, pricing, salidas y disponibilidad)

> Fuente: `proto/assets/js/modules/catalogo.js` (1 652 líneas), `core.js` (Access/ACCESS, STATUS, Pricing, Audit, Notify, Scope) y `data.js` (semilla, `rolePerms`). Contrastado con **DOC-PROC-001 v1.0** (PROC-011 a PROC-020, reglas transversales) y con la **Especificación Funcional y Técnica — Módulo PRD Productos Turísticos** (secciones 1–47, en adelante “Spec PRD”).
> Fuera de alcance de este documento: `#/catalogo/cupos` (Cupos y bloqueos, módulo `inventario.js`), cotizador (`ventas.js`) y portales B2B/e‑commerce (`canales.js`), salvo en los puntos de integración.

---

## 1. Propósito y procesos cubiertos

Dominio central del ERP turístico: define la **oferta comercial** (producto) a partir de **componentes operativos** (servicios de proveedores), con su precio (tarifas, temporadas, reglas de pricing), su capacidad real (modelo de disponibilidad, salidas, holds) y su publicación por canal. Es consumido por CRM, Cotizaciones, Reservas, Operaciones, Finanzas, B2B, CMS, E‑commerce y Mobile.

| Proceso | Flujo (DOC-PROC-001) | Dónde lo implementa el prototipo |
|---|---|---|
| PROC-011 Destinos | País → Región → Departamento/Provincia → Ciudad/Zona → Lugar → Atracción/Ruta; jerarquía válida, sin ciclos, coordenadas válidas | `#/catalogo/destinos[/{id}]` (árbol + ficha + CRUD) |
| PROC-012 Proveedores | Crear → Tipo → Contactos → Contrato → Vigencia → Costos → Activar → Utilizar → Desactivar | `#/catalogo/proveedores[/{id}]` (flujo visual de 9 pasos) |
| PROC-013 Servicios | Proveedor → Servicio → Costo → Vigencia → Disponibilidad → Producto → Cotización → Reserva → Operación | `#/catalogo/servicios[/{id}]` |
| PROC-014 Productos | Definir → Categorizar → Destino → Componentes → Itinerario → Tarifas → Temporadas → Disponibilidad → Contenido → Publicar | Asistente de 10 pasos `#/catalogo/productos/nuevo` + Product 360 |
| PROC-015 Paquetes | Crear → Agregar componentes → Ordenar → Itinerario → Precio → Reglas → Disponibilidad → Publicar | Product 360 › Componentes (servicios **y productos** como componentes, orden ↑↓) |
| PROC-016 Itinerario | Días → Horarios → Actividades → Servicios → Hoteles → Transporte → Alimentación → Guías → Ubicaciones → Notas → Imágenes → Costos/Precios | Product 360 › Itinerario |
| PROC-017 Tarifas | Producto → Moneda → Costo → Venta → Vigencia → Pasajero → Segmento → Canal → Temporada → Activar | Product 360 › Tarifas + `#/catalogo/tarifas` (consolidado) |
| PROC-018 Pricing | Tarifa → Reglas → Temporada → Segmento → Ocupación → Canal → Descuento → Impuesto → Redondeo → Precio | `#/catalogo/tarifas/reglas` (reglas + simulador) y Product 360 › Costos y rentabilidad (simulador) |
| PROC-019 Temporadas | Crear → Rango → Prioridad → Asociar reglas → Validar solapamientos → Activar | `#/catalogo/tarifas/temporadas` (línea de tiempo, solapamientos, resolución por fecha) |
| PROC-020 Disponibilidad | Solicitud → Capacidad → Restricciones → Hold → Confirmar/Liberar | `#/catalogo/salidas[/calendario|/holds]` + Product 360 › Disponibilidad / Salidas |

Tipos y categorías configurables (Spec PRD §3–§4): `#/catalogo/categorias`.

---

## 2. Pantallas

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso (permiso de ruta en `ACCESS`) |
|---|---|---|---|
| `#/catalogo` | `/catalogo` → redirect | Redirige a Productos | `catalog.products.view` |
| `#/catalogo/productos` | `/catalogo/productos` | Listado en **tarjetas o tabla** (preferencia por usuario en `localStorage` `qhapaq_cat_view`), búsqueda (nombre, código, SKU, id, ruta de destino), filtros Tipo / Categoría / Estado / Destino (incluye descendientes y secundarios) / Canal; 4 KPI (activos, en revisión + borradores, con salidas futuras, **margen promedio** solo si ve rentabilidad, si no “Reservables hoy”); exportar CSV (vista tabla) | Tenant Admin, Manager, Ventas, Operaciones, Finanzas |
| `#/catalogo/productos/nuevo` | `/catalogo/productos/nuevo` | Asistente PROC-014 de 10 pasos con validación por paso; crea en DRAFT | Ruta: `catalog.products.view`; contenido: `catalog.products.create` (si no, alerta 403 en página) → Tenant Admin, Manager |
| `#/catalogo/productos/{id}[/{tab}]` | `/catalogo/productos/[ref]/[[...tab]]` | **Product 360**: cabecera (código, SKU, tipo, categoría, estado, versión vigente), acciones de ciclo de vida, flujo visual de estados, 6 indicadores, 19 pestañas (§7). La pestaña activa se refleja en la URL | Igual que listado; pestañas según rol |
| `#/catalogo/categorias` | `/catalogo/categorias` | Tipos de producto y categorías/subcategorías con contadores de uso y CRUD | `catalog.products.update` → Tenant Admin, Manager |
| `#/catalogo/salidas[/tabla|/calendario|/holds]` | `/catalogo/salidas/[[...tab]]` | Programación (tabla), calendario mensual de ocupación, holds; KPIs (salidas futuras, capacidad, vendidos, en hold, disponibles); alerta de holds vencidos no procesados; acciones Nueva salida / Nuevo hold / Expirar holds | `catalog.products.view` (acciones: `manage_availability`) |
| `#/catalogo/tarifas[/tarifas|/temporadas|/reglas]` | `/catalogo/tarifas/[[...tab]]` | Tarifas consolidadas (filtros tipo, canal, estado, moneda), temporadas (línea de tiempo ene‑2026→mar‑2027, tabla, solapamientos, resolución por fecha), reglas de pricing + simulador | `catalog.products.view`; pestaña Reglas solo con `view_profitability` o `manage_rates` |
| `#/catalogo/servicios` | `/catalogo/servicios` | Listado de servicios con KPIs (activos, vencidos/inactivos, vencen en 90 días, proveedor no activo), filtros Tipo / Proveedor / Estado | `catalog.products.view` (gestión: `catalog.providers.manage`) |
| `#/catalogo/servicios/{id}` | `/catalogo/servicios/[ref]` | Ficha, cadena PROC-013, productos que lo usan, proveedor, historial de auditoría | ídem |
| `#/catalogo/proveedores` | `/catalogo/proveedores` | Listado con KPIs (activos, contratos por vencer ≤95 días, vencidos/inactivos, **CxP abiertas** solo con `finance.payables`) | `catalog.providers.manage` **o** `finance.payables` **o** `catalog.products.update` → Tenant Admin, Manager, Operaciones, Finanzas |
| `#/catalogo/proveedores/{id}` | `/catalogo/proveedores/[ref]` | Ficha, ciclo PROC-012, contactos, calificación, servicios, productos, reservas en que participa, cuentas por pagar (solo `finance.payables`) | ídem |
| `#/catalogo/destinos[/{id}]` | `/catalogo/destinos/[[...ref]]` | Árbol jerárquico + ficha del nodo + productos (incluye subdestinos) + CRUD | `catalog.products.view` (gestión: `catalog.products.update`) |
| `#/catalogo/cupos` | `/catalogo/cupos` | Cupos y bloqueos (otro módulo, `inventario.js`) | `catalog.allotments.manage` o `view_profitability` |

Roles externos (guía, conductor, B2B, cliente, pasajero, proveedor) y Super Admin **no** acceden a ninguna ruta del catálogo del back-office (403 auditado `acceso_denegado`). Contabilidad no tiene `catalog.products.view`: no ve el grupo de menú “Catálogo turístico”.

---

## 3. Modelo de datos PostgreSQL

Convenciones aplicadas a todas las tablas (no se repiten en cada tabla): `id uuid PK` (UUIDv7), `tenant_id uuid not null FK tenants(id)` + RLS `tenant_isolation`, `created_by/updated_by uuid FK iam_users(id)`, `created_at/updated_at timestamptz not null default now()`. Índice en toda FK.
Correlativos legibles: columna **`ref varchar(20)`** (`PRD-001`, `TAR-001`, `SAL-0101`, `HLD-0001`, `SRV-001`, `PRV-001`, `TMP-01`, `PR-01`, `PROMO-01`, `D01`, `CAT-1`) generada con `sys_sequences` (`SELECT … FOR UPDATE`), `UNIQUE (tenant_id, ref)`. En productos, `code` y `sku` son los códigos **de negocio** de la Spec PRD §5 (no el correlativo).
Extensiones requeridas: `btree_gist` (exclusión de solapamiento de temporadas), `pg_trgm` (búsqueda).

### 3.1 `catalog_product_types`
| columna | tipo | null | notas |
|---|---|---|---|
| name | varchar(60) | no | En mayúsculas (`TOUR`, `EXCURSIÓN`…). `UNIQUE (tenant_id, lower(name))` |
| requires_itinerary | boolean | no | default false. Semilla true para TOUR, EXCURSIÓN, PAQUETE, CIRCUITO, PROGRAMA TURÍSTICO (checklist §42) |
| requires_components | boolean | no | default true; false para PRODUCTO PERSONALIZADO |
| sort_order | smallint | no | default 0 |
| deleted_at | timestamptz | sí | Solo si no hay productos que lo usen (CAT-BR-37) |

### 3.2 `catalog_product_categories` / `catalog_product_subcategories`
| tabla.columna | tipo | null | notas |
|---|---|---|---|
| categories.ref | varchar(20) | no | `CAT-n` |
| categories.name | varchar(80) | no | `UNIQUE (tenant_id, lower(name))` |
| categories.deleted_at | timestamptz | sí | |
| subcategories.category_id | uuid | no | FK categories ON DELETE RESTRICT |
| subcategories.name | varchar(80) | no | `UNIQUE (category_id, lower(name))` |
| subcategories.deleted_at | timestamptz | sí | |

### 3.3 `catalog_destinations`
| columna | tipo | null | notas |
|---|---|---|---|
| ref | varchar(20) | no | `D01`… |
| parent_id | uuid | sí | FK self. null solo si `type='País'` |
| name | varchar(120) | no | `UNIQUE (tenant_id, coalesce(parent_id,'0…0'), lower(name))` |
| type | varchar(20) | no | `CHECK (type IN ('País','Región','Departamento','Provincia','Ciudad','Zona','Lugar','Atracción','Ruta'))` |
| level | smallint | no | Derivado del tipo: País 0, Región 1, Departamento 2, Provincia 3, Ciudad/Zona 4, Lugar 5, Atracción/Ruta 6. `CHECK (level BETWEEN 0 AND 6)` |
| lat | numeric(9,6) | sí | `CHECK (lat BETWEEN -90 AND 90)` |
| lng | numeric(9,6) | sí | `CHECK (lng BETWEEN -180 AND 180)`; `CHECK ((lat IS NULL) = (lng IS NULL))` |
| description | text | sí | |
| path | ltree o text | no | Materializado para consultas de descendientes (filtro “Destino” incluye subdestinos). Índice GiST/btree |
| deleted_at | timestamptz | sí | |
Restricción de ciclo y de nivel (hijo.level > padre.level): en `DestinationService` + trigger de verificación.

### 3.4 `catalog_providers`
| columna | tipo | null | notas |
|---|---|---|---|
| ref | varchar(20) | no | `PRV-001` |
| name | varchar(160) | no | Razón social |
| type | varchar(20) | no | `CHECK (type IN ('Hotel','Transporte','Tren','Guía','Conductor','Restaurante','Operador','Actividad','Entrada','Otros'))` |
| ruc | char(11) | no | `CHECK (ruc ~ '^(10|15|17|20)[0-9]{9}$')`; `UNIQUE (tenant_id, ruc)` |
| city | varchar(80) | sí | |
| stars | smallint | sí | `CHECK (stars BETWEEN 0 AND 5)` |
| contact_name | varchar(120) | no | Contacto principal |
| phone | varchar(40) | sí | |
| email | varchar(160) | sí | formato validado |
| contract_start | date | no | |
| contract_end | date | no | `CHECK (contract_end >= contract_start)` |
| currency | char(3) | no | `CHECK (currency IN ('PEN','USD'))` |
| payment_terms | varchar(120) | sí | |
| status | varchar(20) | no | `CHECK (status IN ('activo','inactivo','vencido'))`, default `inactivo`. `vencido` lo fija el job diario (§8); el estado efectivo se calcula también por `contract_end < today` |
| rating | numeric(2,1) | no | default 0; `CHECK (rating = 0 OR rating BETWEEN 1 AND 5)` |
| rating_note | text | sí | |
| deleted_at | timestamptz | sí | Nunca físico si tiene servicios/reservas/CxP |
Índices: `(tenant_id, status)`, `(tenant_id, contract_end)`, trigram en `name`.

`catalog_provider_contacts`: `provider_id` FK, `name varchar(120) not null`, `role varchar(80)`, `phone`, `email`.

### 3.5 `catalog_services`
| columna | tipo | null | notas |
|---|---|---|---|
| ref | varchar(20) | no | `SRV-001` |
| provider_id | uuid | no | FK catalog_providers |
| name | varchar(200) | no | |
| type | varchar(20) | no | `CHECK (type IN ('Traslado','Transporte','Tren','Entrada','Guía','Alimentación','Alojamiento','Actividad','Tour','Seguro','Otro'))` |
| unit | varchar(20) | no | `CHECK (unit IN ('pax','servicio','habitación','noche','vehículo','unidad'))` |
| cost | numeric(14,2) | no | `CHECK (cost >= 0)` |
| currency | char(3) | no | PEN/USD |
| capacity | integer | sí | null = sin límite propio |
| valid_from | date | no | |
| valid_to | date | no | `CHECK (valid_to >= valid_from)` |
| status | varchar(20) | no | `CHECK (status IN ('activo','inactivo','vencido'))`; efectivo `vencido` si `valid_to < today` |
| deleted_at | timestamptz | sí | |
Índices: `(tenant_id, provider_id)`, `(tenant_id, status)`, `(tenant_id, valid_to)`.

### 3.6 `catalog_products` (ficha maestra §5 + configuración)
| columna | tipo | null | notas |
|---|---|---|---|
| ref | varchar(20) | no | `PRD-001` (correlativo) |
| code | varchar(30) | no | Código interno. `UNIQUE (tenant_id, lower(code))` |
| sku | varchar(40) | no | Código comercial; default `<prefijo tenant>-<code>` |
| name | varchar(160) | no | `CHECK (char_length(trim(name)) >= 3)` en activación |
| internal_name | varchar(160) | no | default = name |
| product_type_id | uuid | no | FK catalog_product_types |
| category_id | uuid | no | FK categories |
| subcategory_id | uuid | sí | FK subcategories (debe pertenecer a category_id) |
| primary_destination_id | uuid | no | FK catalog_destinations |
| status | varchar(20) | no | `CHECK (status IN ('DRAFT','REVIEW','APPROVED','ACTIVE','SUSPENDED','INACTIVE','ARCHIVED'))` default DRAFT |
| short_description | text | sí | recomendado ≤160 |
| long_description | text | sí | |
| duration_value | numeric(6,1) | no | `CHECK (duration_value >= 1)` |
| duration_unit | varchar(10) | no | `CHECK (duration_unit IN ('horas','días','noches'))` |
| modality | varchar(15) | no | `CHECK (modality IN ('Privado','Compartido','Grupal'))` |
| main_provider_id | uuid | sí | FK catalog_providers (obligatorio para activar) |
| minimum_passengers / maximum_passengers | smallint | no | `CHECK (minimum_passengers >= 1 AND maximum_passengers >= minimum_passengers)` |
| minimum_age / maximum_age | smallint | no | `CHECK (minimum_age >= 0 AND maximum_age >= minimum_age)` |
| booking_advance_min_days / booking_advance_max_days | smallint | no | `CHECK (… max >= min)` |
| is_bookable, is_quoteable, is_b2b, is_ecommerce, is_featured | boolean | no | defaults true, true, false, false, false |
| markup_pct | numeric(6,2) | no | Markup de referencia (default 25) |
| availability_model | varchar(20) | no | `CHECK (availability_model IN ('SIN_CONTROL','CUPOS','CAPACIDAD','POR_PROVEEDOR','POR_ACTIVIDAD','POR_TRANSPORTE','POR_HABITACION','POR_SALIDA','POR_FECHA'))` default SIN_CONTROL |
| availability_capacity | integer | sí | Capacidad/cupo de referencia |
| availability_source | varchar(10) | no | `CHECK (availability_source IN ('interna','externa'))` default interna |
| features | jsonb | no | default `[]` (características destacadas) |
| video_url | varchar(500) | sí | |
| seo_title | varchar(120) | sí | recomendado ≤60 |
| seo_description | varchar(320) | sí | recomendado ≤160 |
| slug | varchar(160) | sí | `CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')`; `UNIQUE (tenant_id, slug) WHERE slug IS NOT NULL` |
| current_version_id | uuid | sí | FK catalog_product_versions (versión `vigente`) |
| attributes | jsonb | no | default `{}` — atributos configurables (§5 “ampliar sin romper”) |
| lock_version | integer | no | default 0 (ediciones concurrentes en 360) |
| deleted_at | timestamptz | sí | Soft delete (CAT-BR-08) |
Índices: `(tenant_id, status)`, `(tenant_id, product_type_id)`, `(tenant_id, category_id)`, `(tenant_id, primary_destination_id)`, `(tenant_id, created_at desc)`, GIN trigram sobre `name || code || sku`.

### 3.7 Tablas satélite del producto
| tabla | columnas (además de tenant_id, product_id FK ON DELETE CASCADE) | restricciones |
|---|---|---|
| `catalog_product_destinations` | destination_id FK, role `varchar(10)` `CHECK (role='secondary')` | `UNIQUE (product_id, destination_id)`; no puede ser el destino principal |
| `catalog_product_items` (componentes §9/§10) | service_id uuid null FK catalog_services, component_product_id uuid null FK catalog_products, provider_id uuid null (denormalizado del servicio al agregar, trazabilidad §9), quantity numeric(8,2) not null `CHECK (>=1)`, unit varchar(20) not null (mismo CHECK que servicios), sort_order smallint not null, is_required bool not null default true, is_included bool not null default true, unit_cost_override numeric(14,2) null, notes text | `CHECK (num_nonnulls(service_id, component_product_id) = 1)`; `CHECK (component_product_id <> product_id)`; índice `(product_id, sort_order)` |
| `catalog_itineraries` | name varchar(120) default 'Principal', is_default bool | `UNIQUE (product_id) WHERE is_default` (prototipo: un itinerario por producto) |
| `catalog_itinerary_days` | itinerary_id FK, day_number smallint not null, title varchar(160) not null | `UNIQUE (itinerary_id, day_number)` |
| `catalog_itinerary_items` | day_id FK, start_time time null (null = “sin hora fija”, se muestra “—”), activity varchar(200) not null, item_type varchar(20) `CHECK IN ('Traslado','Transporte','Tren','Hotel','Alimentación','Guía','Actividad','Entrada','Libre','Otro')`, location varchar(160), service_id uuid null FK catalog_services (debe ser componente del producto), note text, image_ref varchar(300), cost numeric(14,2) default 0, price numeric(14,2) default 0 | orden por `start_time NULLS LAST` |
| `catalog_product_inclusions` | kind varchar(10) `CHECK IN ('include','exclude')`, text varchar(200) not null, sort_order | `UNIQUE (product_id, kind, lower(text))` |
| `catalog_product_requirements` | category varchar(30) `CHECK IN ('Condición física','Documentos','Nacionalidad','Restricción','Recomendación','Equipamiento','Requisito especial')` null (semilla sin categoría), text varchar(300) not null | |
| `catalog_product_policies` (1:1) | cancellation text not null, modification text not null, no_show text not null, penalties text, refund text not null, date_change text, advance_min varchar(40), advance_max varchar(40) | `UNIQUE (product_id)`; valores por defecto del prototipo (`defaultPolicies()`) al crear |
| `catalog_product_related` | related_product_id FK, relation varchar(20) `CHECK IN ('Complementario','Alternativo','Upgrade','Cross-sell','Upsell')` | `UNIQUE (product_id, related_product_id)`; `CHECK (related_product_id <> product_id)` |
| `catalog_product_b2b_rates` | agency_id FK channel_b2b_agencies, price_list varchar(80) not null, price numeric(14,2) not null, currency char(3) not null (= moneda de la tarifa pública al crear), commission_pct numeric(5,2) not null default 10, is_credit bool not null default false, valid_to date not null, conditions varchar(200), restrictions varchar(200) | `UNIQUE (product_id, agency_id)` |
| `catalog_product_promotions` | ref `PROMO-nn`, name varchar(120) not null, kind varchar(30) `CHECK IN ('Precio promocional','Descuento fijo','Descuento %','Promoción por fecha','Descuento por cantidad','Promoción por temporada','Promoción por canal','Código promocional')`, value numeric(12,2) not null, channel varchar(20) `CHECK IN ('Todos','Directo','E-commerce','B2B','Corporativo')`, promo_code varchar(30) (mayúsculas), date_from date not null, date_to date not null, status varchar(10) `CHECK IN ('activa','inactiva')` | `CHECK (date_to >= date_from)`; `UNIQUE (tenant_id, promo_code) WHERE promo_code <> ''` (a validar §12) |
| `catalog_product_media` | kind `CHECK IN ('image')`, storage_path varchar(400), alt_text varchar(200) not null, is_main bool, sort_order | `UNIQUE (product_id) WHERE is_main` |
| `catalog_product_faqs` | question varchar(300) not null, answer text not null, sort_order | |
| `catalog_product_channels` | channel varchar(12) `CHECK IN ('erp','crm','quotes','b2b','portal','portal_pax','cms','ecommerce','mobile')`, is_published bool not null, published_at, published_by | `UNIQUE (product_id, channel)`; `erp` siempre true |
| `catalog_product_seasons` (asociación directa) | season_id FK catalog_seasons | `UNIQUE (product_id, season_id)` |
| `catalog_product_config_groups` / `catalog_product_config_options` (personalización §23) | groups: name varchar(60), sort_order — options: group_id FK, label varchar(80), price_delta numeric(14,2), sort_order | `UNIQUE (product_id, lower(name))`; ≥2 opciones por grupo (servicio) |
| `catalog_product_versions` | version varchar(10) not null (`1.0`, `1.1`, `2.0`, `0.1`), status varchar(15) `CHECK IN ('borrador','en_revision','vigente','historica')`, valid_from date not null, reason text not null, snapshot jsonb not null, created_by | `UNIQUE (product_id, version)`; `UNIQUE (product_id) WHERE status='vigente'`; **inmutable** (sin UPDATE salvo `status`) |

Contenido del `snapshot` (igual que `snap()` del prototipo): name, type, category, subcategory, primary_destination, duration_value/unit, modality, main_provider, **components[]**, **itinerary[]**, **rates[]**, **policies**, inclusions[], exclusions[], markup_pct. Recomendado añadir requirements, b2b_rates, promotions y channels (§12).

### 3.8 Tarifas, temporadas y reglas
**`catalog_rates`**
| columna | tipo | null | notas |
|---|---|---|---|
| ref | varchar(20) | no | `TAR-001` (secuencia única del tenant) |
| product_id | uuid | no | FK catalog_products |
| rate_type | varchar(15) | no | `CHECK IN ('Proveedor','Costo','Venta','Retail','B2B','Mayorista','Corporativa','VIP','Promocional','Especial','Grupal')` |
| segment | varchar(10) | no | `CHECK IN ('Adulto','Niño','Infante','Pasajero','Grupo')` |
| channel | varchar(12) | no | `CHECK IN ('Directo','B2B','Corporativo','E-commerce','Mayorista','Todos')` |
| currency | char(3) | **no** | PEN/USD (PRD-BR-004, PRD-T08 → 422) |
| price_basis | varchar(15) | no | `CHECK IN ('Por persona','Precio fijo','Por grupo','Por habitación','Por unidad')` default 'Por persona' |
| cost | numeric(14,2) | no | `CHECK (cost >= 0)` |
| price | numeric(14,2) | no | `CHECK (price > 0)` |
| min_price / max_price | numeric(14,2) | sí | `CHECK (min_price IS NULL OR price >= min_price)`, `CHECK (max_price IS NULL OR price <= max_price)` |
| valid_from / valid_to | date | **no** | `CHECK (valid_to >= valid_from)` |
| season_id | uuid | sí | FK catalog_seasons (solo activas al crear) |
| conditions | varchar(300) | sí | Texto libre en el prototipo (“Mín. 4 pax; anticipación ≥ 7 días; lun–vie”); ver `catalog_rate_conditions` |
| status | varchar(10) | no | `CHECK IN ('vigente','inactiva')`. Estado **efectivo** calculado: `inactiva` → `vencida` (`valid_to < today`) → `programada` (`valid_from > today`) → `vigente` |
| lock_version | integer | no | default 0 (cambio de precio concurrente) |
Índices: `(tenant_id, product_id)`, `(tenant_id, valid_to)`, `(product_id, segment, channel, valid_from, valid_to)`. Sin DELETE (ver §12).

**`catalog_rate_conditions`** (estructuración propuesta de `conditions`; el prototipo no la usa): `rate_id` FK, `kind` `CHECK IN ('weekday','min_pax','max_pax','min_age','max_age','advance_days_min','advance_days_max','customer_type','occupancy','duration')`, `operator` varchar(4), `value` jsonb.

**`catalog_seasons`**
| columna | tipo | null | notas |
|---|---|---|---|
| ref | varchar(20) | no | `TMP-01` |
| name | varchar(120) | no | |
| type | varchar(20) | no | `CHECK IN ('Alta','Media','Baja','Feriado','Fecha especial','Personalizada')` |
| date_from / date_to | date | no | `CHECK (date_to >= date_from)` |
| priority | smallint | no | `CHECK (priority >= 0)`; 0 = máxima; menor número prevalece |
| factor | numeric(4,2) | no | `CHECK (factor > 0 AND factor <= 3)` |
| status | varchar(10) | no | `CHECK IN ('activa','inactiva')` |
| deleted_at | timestamptz | sí | |
Restricción clave: `EXCLUDE USING gist (tenant_id WITH =, priority WITH =, daterange(date_from, date_to, '[]') WITH &&) WHERE (status = 'activa' AND deleted_at IS NULL)` — solapamiento con **igual prioridad** prohibido (PROC-019).

**`catalog_price_rules`** (a nivel tenant)
| columna | tipo | null | notas |
|---|---|---|---|
| ref | varchar(20) | no | `PR-01` |
| name | varchar(120) | no | |
| kind | varchar(20) | no | `CHECK IN ('Markup %','Markup fijo','Descuento %','Descuento fijo','Impuesto %','Comisión %','Redondeo','Precio mínimo','Precio máximo')` |
| value | numeric(12,4) | no | |
| scope | varchar(200) | no | Texto descriptivo (prototipo) |
| channel | varchar(12) | sí | **Propuesto** (el simulador lo codifica en duro: PR-01 Directo/E-commerce, PR-02 B2B) |
| min_pax | smallint | sí | Propuesto (PR-05 ≥10 pax) |
| customer_residency | varchar(10) | sí | Propuesto `CHECK IN ('dom','nodom')` (PR-03 / PR-04) |
| product_id / currency | uuid / char(3) | sí | Propuesto (PR-08 PRD-001 USD) |
| priority | smallint | no | default 100 |
| status | varchar(10) | no | `CHECK IN ('activa','inactiva')` |

### 3.9 Salidas y disponibilidad
**`catalog_product_departures`**
| columna | tipo | null | notas |
|---|---|---|---|
| ref | varchar(20) | no | `SAL-0101` |
| product_id | uuid | no | FK |
| departure_date | date | no | ≥ hoy al crear |
| departure_time | time | no | |
| capacity | smallint | no | `CHECK (capacity BETWEEN 1 AND 60)` |
| sold | smallint | no | default 0; `CHECK (sold >= 0 AND sold <= capacity)` |
| status | varchar(12) | no | `CHECK IN ('abierta','completa','cerrada','cancelada')` |
| provider_id | uuid | sí | FK providers (solo activos) — null = proveedor principal del producto |
| guide_id | uuid | sí | FK ops_guides (no en vacaciones) |
| vehicle_id | uuid | sí | FK ops_vehicles (no en mantenimiento) |
| meeting_point | varchar(160) | no | |
| return_point | varchar(160) | sí | |
| notes | text | sí | |
| lock_version | integer | no | default 0 |
Índices: `(tenant_id, departure_date)`, `(product_id, departure_date, departure_time)`, `(tenant_id, status)`.
`catalog_product_departure_assignments` (Spec PRD §33): no la usa el prototipo (1 guía + 1 vehículo por salida); reservar para múltiples recursos.

**`catalog_availability_holds`** (Spec PRD §33 lo llama `catalog_availabilities`)
| columna | tipo | null | notas |
|---|---|---|---|
| ref | varchar(20) | no | `HLD-0001` |
| departure_id | uuid | no | FK departures |
| quantity | smallint | no | `CHECK (quantity >= 1)` |
| reference | varchar(60) | no | `COT-…`, `RES-…`, `WEB-…`, texto B2B |
| reference_type / reference_id | varchar(20) / uuid | sí | Propuesto: vínculo polimórfico a quote/reservation/order |
| status | varchar(12) | no | `CHECK IN ('hold','confirmada','liberada','expirada','consumida')` |
| expires_at | timestamptz | no | = created_at + minutos (default `sys_settings.hold_minutes` = 30, mínimo 5). Para confirmación directa = created_at |
| released_reason | text | sí | obligatorio al liberar |
| notes | text | sí | |
| lock_version | integer | no | default 0 |
Índices: `(departure_id, status)`, `(tenant_id, status, expires_at)` (job de expiración).
Disponibles de una salida = `0` si `status IN ('cancelada','cerrada')`; si no `max(0, capacity − sold − Σ quantity de holds en estado 'hold')` (los holds vencidos aún no procesados siguen restando).

---

## 4. Máquinas de estado

### 4.1 Producto (`catalog_products.status`)
| Desde | Hacia | Acción (botón) | Permiso | Validación | Efectos |
|---|---|---|---|---|---|
| (nuevo) | DRAFT | Asistente “Crear producto en borrador” / Duplicar | `create` | CAT-BR-01..04 | versión `0.1 borrador` con snapshot; canal ERP publicado; audit `crear` |
| DRAFT | REVIEW | Enviar a revisión | `update` | name, type, destination, category presentes (CAT-BR-05) | versiones `borrador` → `en_revision`; notificación `producto.revision` (Portal → Manager); audit `cambio_estado` |
| DRAFT, REVIEW | ACTIVE | Activar (el botón se muestra) | `activate` | Checklist §42: el ítem **Estado** exige APPROVED/SUSPENDED/INACTIVE/ACTIVE → **siempre rechazado** | 422 + audit `activacion_rechazada` con ítems fallidos; estado no cambia |
| REVIEW | DRAFT | Devolver | `activate` | motivo obligatorio | audit `cambio_estado` con motivo |
| REVIEW | APPROVED | Aprobar | `activate` | confirmación | audit |
| APPROVED, SUSPENDED, INACTIVE | ACTIVE | Activar | `activate` | Checklist §42 completo (12/12, CAT-BR-06) | versiones `borrador`/`en_revision` → `vigente`; notificación `producto.activado` (Email → equipo de ventas); audit |
| ACTIVE | SUSPENDED | Suspender | `activate` | motivo | notificación `producto.no_vendible` (Push → ventas); audit |
| ACTIVE, SUSPENDED | INACTIVE | Inactivar | `activate` | motivo | ídem `producto.no_vendible` |
| INACTIVE | ARCHIVED | Archivar | `activate` | motivo | audit |
| cualquiera ≠ ARCHIVED | ARCHIVED | Más acciones › Archivar; también desde el modal “Eliminación física bloqueada” | `activate` | motivo | audit |
| ARCHIVED | — | (terminal) | — | — | — |

“Comercializable” = `status = ACTIVE AND is_bookable`. Solo ACTIVE es seleccionable en cotizador/reservas/B2B/e‑commerce (PRD-BR-002, validado también por `ventas.js` y `canales.js`).

### 4.2 Versión de producto (`catalog_product_versions.status`)
`borrador` → `en_revision` (producto pasa a REVIEW) → `vigente` (producto pasa a ACTIVE) → `historica` (al crear una nueva versión, todas las no históricas pasan a `historica`). La nueva versión nace `vigente` si el producto está ACTIVE/APPROVED/SUSPENDED/INACTIVE, `en_revision` si REVIEW, `borrador` si DRAFT. Las históricas son inmutables y consultables (PRD-BR-017).

### 4.3 Tarifa
Almacenado: `vigente` ⇄ `inactiva` (Activar/Desactivar, permiso `manage_rates`, no permitido si está vencida). Efectivo calculado: `inactiva` | `vencida` (valid_to < hoy) | `programada` (valid_from > hoy) | `vigente`. Solo `vigente` es seleccionable (PRD-BR-005). Cambio de precio solo si no está vencida ni inactiva.

### 4.4 Salida
| Desde | Hacia | Disparo | Efecto |
|---|---|---|---|
| (nueva) | abierta/cerrada/cancelada | Nueva salida (`manage_availability`) | audit `Salida.crear` |
| abierta | completa | Automático cuando `sold >= capacity` (al confirmar cupos o editar) | — |
| completa | abierta | Automático al liberar cupos confirmados; o al editar (el formulario no ofrece “completa”) | — |
| abierta/completa | cerrada / cancelada | Editar salida | Si pasa a `cancelada` con `sold > 0`: notificación `salida.cancelada` (Email → Operaciones) “reprogramar pasajeros” |
| cerrada/cancelada | abierta | Editar salida (el prototipo lo permite) | ver §12 |

### 4.5 Hold de disponibilidad (PROC-020: Disponible → Hold → Confirmada → Liberada/Expirada/Consumida)
| Desde | Hacia | Disparo | Validación | Efecto en salida |
|---|---|---|---|---|
| (disponible) | hold | Nuevo hold | CAT-BR-21 | resta de disponibles (no de `sold`) |
| (disponible) | confirmada | Confirmar cupos (venta directa) | CAT-BR-23 | `sold += qty`; completa si llena |
| hold | confirmada | Confirmar | CAT-BR-22 | `sold += qty`; completa si llena; notificación `disponibilidad.confirmada` al creador del hold |
| hold | liberada | Liberar (motivo) | — | devuelve cupos retenidos |
| confirmada | liberada | Liberar (motivo; cancelación) | — | `sold -= qty`; completa → abierta |
| hold | expirada | Job JOB-01 (cada 5 min) o botón “Expirar holds vencidos” | `expires_at < now` | devuelve cupos |
| confirmada | consumida | Consumir (servicio ejecutado) | — | `sold` no cambia |
Estados terminales: liberada, expirada, consumida.

### 4.6 Proveedor
`inactivo` (al crear) → `activo` (Activar: contrato no vencido y RUC de 11 dígitos) → `inactivo` (Desactivar con motivo). `activo` → `vencido` (contrato vencido; job diario / cálculo). `vencido` → `inactivo` (Renovar contrato con fecha futura, o editar fin de contrato ≥ hoy) → `activo`.

### 4.7 Servicio
`activo` ⇄ `inactivo` (Activar: proveedor activo y `valid_to ≥ hoy`; Desactivar: motivo). `vencido` efectivo cuando `valid_to < hoy`. Al guardar con proveedor no activo, se fuerza `inactivo`.

### 4.8 Temporada, promoción, regla de pricing, publicación
| Entidad | Estados | Transición |
|---|---|---|
| Temporada | activa ⇄ inactiva | Formulario de edición o “Inactivar” desde el modal de borrado bloqueado |
| Promoción | activa ⇄ inactiva (Pausar/Activar); `vencida` efectiva si `date_to < hoy` (no se puede alternar) | `update` |
| Regla de pricing | activa ⇄ inactiva (switch) | `manage_rates` |
| Canal del producto | no publicado → publicado (solo si producto ACTIVE) → no publicado. Si está publicado y el producto deja de estar ACTIVE, se muestra “Pendiente de activación” | `publish` |

---

## 5. Reglas de negocio

Validación: **FR** = FormRequest, **P** = Policy, **S** = Service de dominio, **DB** = constraint, **J** = Job.

### 5.1 Reglas de la documentación inicial (aplicación en el prototipo)
| ID | Regla | Cómo la aplica el prototipo | Dónde |
|---|---|---|---|
| PRD-BR-001 / PRD-BR-015 / RT-1/2 | Tenant válido, aislamiento | Producto inexistente → “No existe en este tenant (PRD-T02)” | P + RLS; 404 |
| PRD-BR-002 | Producto no ACTIVE no se usa en nuevas ventas | Alerta en 360; bloqueo en hold/confirmación/canales; componente-producto no ACTIVE no seleccionable; relacionado no ACTIVE “No se ofrecerá” | S (catálogo, cotizador, reservas, canales) |
| PRD-BR-003 | Fuera de vigencia no seleccionable | Servicios con `valid_to < hoy` no seleccionables como componente y bloquean activación | S |
| PRD-BR-004 | Tarifa con moneda y vigencia | Moneda, desde y hasta obligatorios; checklist “Moneda”/“Vigencia” | FR + DB |
| PRD-BR-005 | Tarifa vencida no aplicable | No se registra una tarifa con `valid_to < hoy`; vencidas deshabilitadas en simuladores, resolución y personalización; sin edición de precio | FR + S |
| PRD-BR-006 | Trazabilidad de componentes | Componente conserva id de servicio/producto origen, proveedor, cantidad, unidad, orden, requerido, incluido, notas; vigencia y costo tomados del servicio | S + DB |
| PRD-BR-007 | Proveedor inactivo no disponible | Proveedor inactivo/vencido y sus servicios deshabilitados en selects; checklist “Proveedor”; servicio se guarda inactivo | S |
| PRD-BR-008/009 | Snapshot en cotizaciones | Versión con snapshot; textos de advertencia en ficha, tarifas, políticas | S (Cotizaciones copia snapshot al quote item) |
| PRD-BR-010/011 | Precio y permisos en backend | Simuladores marcados “respuesta simulada”; guard + 403 | S + P |
| PRD-BR-012 / RT-4 | No confirmar sin disponibilidad | Hold y confirmación bloqueados si `qty > disponibles` o excede capacidad | S + lock Redis + DB CHECK `sold <= capacity` |
| PRD-BR-013 | Disponibilidad externa informativa | `availability_source='externa'` → aviso “adaptador INT-06 parcial, confirmar con proveedor” | S |
| PRD-BR-014 / RT-16 | Modificación crítica auditada | Ver §9 | S (listener) |
| PRD-BR-016 | Tarifas por segmento/canal | Tipo × segmento × canal | DB |
| PRD-BR-017 | Versiones consultables | Snapshot y comparación A/B | S |
| PRD-BR-018 / §41 / RT-24 | Sin borrado físico con historia | Eliminar bloqueado si hay cotizaciones, reservas, operaciones o paquetes que lo contienen; si no, soft delete | S → 409 |
| PROC-011 | Jerarquía válida, sin ciclos, coordenadas | CAT-BR-40 | S + DB |
| PROC-019 | Validar solapamientos | CAT-BR-17 | S + DB EXCLUDE |
| RT-14 | B2B por agencia/producto/vigencia | CAT-BR-28 | S |
| RT-15 | Moneda y TC conservados | Costos de componentes en USD convertidos a PEN con `settings.fx` (3.74) | S (guardar fx en snapshot) |

### 5.2 Reglas del módulo (nuevas)
| ID | Regla | Mensaje/comportamiento del prototipo | Dónde |
|---|---|---|---|
| CAT-BR-01 | `code` único por tenant (sin distinguir mayúsculas) | “El código interno ya existe en el tenant.” | FR + DB |
| CAT-BR-02 | min ≤ max en pasajeros, edades y anticipación | Mensajes por campo; en edición “Revise los rangos…” | FR + DB |
| CAT-BR-03 | SKU por defecto `<prefijo tenant>-<code>`; nombre interno por defecto = nombre | — | S |
| CAT-BR-04 | Producto nuevo siempre DRAFT, versión 0.1 `borrador`, canal ERP siempre publicado; los canales elegidos en el asistente quedan “solicitados” y solo son efectivos en ACTIVE | — | S |
| CAT-BR-05 | Enviar a revisión exige nombre, tipo, destino y categoría | “No se puede enviar a revisión: falta …” | S → 422 |
| CAT-BR-06 | **Checklist de activación (12 ítems)**: Tipo existente; Nombre ≥3 caracteres; Destino existente; Estado ∈ {APPROVED, SUSPENDED, INACTIVE, ACTIVE}; Componentes ≥1 (salvo tipo sin `requires_components`); Proveedor principal activo con contrato vigente **y** todos los servicios de componentes vigentes/activos con proveedor activo **y** productos-componente ACTIVE; ≥1 tarifa vigente hoy; todas las tarifas con moneda; todas con vigencia y ≥1 vigente; políticas cancelación, modificación, no-show y reembolso; modelo de disponibilidad definido y, si POR_SALIDA, ≥1 salida futura `abierta`; itinerario (si el tipo lo requiere) con ≥1 día y **todos los días con ≥1 actividad** | Modal con ✓/✗ y detalle; activación rechazada → auditada | S → 422 `{code:'PRD-ACT-CHECKLIST', failed:[…]}` |
| CAT-BR-07 | Transiciones solo según §4.1; motivo obligatorio para Devolver, Suspender, Inactivar, Archivar | — | S (state machine) |
| CAT-BR-08 | Eliminar: solo sin cotizaciones, reservas, operaciones ni paquetes que lo incluyan; motivo obligatorio; soft delete | Modal “Eliminación física bloqueada” con listas y botón Archivar; audit `eliminacion_bloqueada` | S → 409 `PRD-BR-018` |
| CAT-BR-09 | Duplicar: nuevo DRAFT “(copia)”, `code` + `-C<nn>`, `sku` + `-C`, sin salidas ni promociones, tarifas con nuevos ref, versión 0.1 | — | S |
| CAT-BR-10 | Componente-servicio: servicio activo y vigente con proveedor activo y contrato vigente; componente-producto: ACTIVE y distinto del propio; cantidad ≥1; orden por defecto = último+1; reordenar ↑↓; quitar con confirmación. En el asistente no se repite servicio; en 360 sí (§12) | Opciones deshabilitadas con “[… · PRD-BR-007]” | FR + S |
| CAT-BR-11 | Si el producto no tiene proveedor principal, se asigna el del primer servicio agregado. Cambiar proveedor principal: solo activos con contrato vigente, motivo obligatorio, distinto del actual | audit `cambio_proveedor` antes/después | FR + S |
| CAT-BR-12 | Itinerario: título de día y actividad obligatorios; ítems ordenados por hora (vacía al final como “—”); al quitar un día se renumeran; `service_id` solo entre componentes del producto | — | FR + S |
| CAT-BR-13 | Alta de tarifa: moneda, desde, hasta; hasta ≥ desde; hasta ≥ hoy; precio > 0; min ≤ precio ≤ max; precio < costo → **advertencia** (no bloquea); temporada solo activa | Toasts con PRD-BR-004/005 | FR → 422 |
| CAT-BR-14 | Cambio de precio: solo tarifa no vencida ni inactiva; nuevo costo y precio + motivo obligatorios; precio > 0 y ≥ precio mínimo | audit `Tarifa.cambio_precio` con antes/después y motivo | FR + S + `lock_version` |
| CAT-BR-15 | Resolver tarifa aplicable (fecha, segmento, canal): descarta inactivas, vencidas, fecha fuera de vigencia, otro segmento, otro canal (salvo “Todos”); entre las válidas prefiere la de la temporada vigente en la fecha | Tabla “Seleccionable / No seleccionable · motivo” | S |
| CAT-BR-16 | Temporadas del producto: asociables solo activas; las usadas por tarifas se asocian automáticamente y no se pueden desasociar | — | S |
| CAT-BR-17 | Temporada: hasta ≥ desde; factor 0.01–3.00; solapamiento entre activas permitido solo con prioridad distinta (igual prioridad → rechazo); prevalece menor número; eliminar solo si ninguna tarifa/producto la usa, si no se ofrece inactivar | “PROC-019 · solapamiento no resoluble…”; advertencia si solapa con otra prioridad | FR + DB EXCLUDE |
| CAT-BR-18 | Resolución de temporada por fecha: activas que contienen la fecha, orden por prioridad ascendente; sin temporada → tarifa base | — | S (`SeasonResolver`) |
| CAT-BR-19 | Configuración de disponibilidad: modelo ∈ 9 valores; capacidad de referencia; fuente interna/externa | — | FR |
| CAT-BR-20 | Salida: fecha ≥ hoy al crear; capacidad 1–60; en edición capacidad ≥ vendidos + en hold; proveedor solo activo; guías de vacaciones y vehículos en mantenimiento no seleccionables; vehículo con menos asientos que la capacidad → advertencia; alta desde listado global no admite productos INACTIVE/ARCHIVED | Mensajes específicos | FR + S |
| CAT-BR-21 | Hold: salida `abierta`, futura, con disponibles > 0, producto ACTIVE; `qty ≤ disponibles` (si no, rechazo listando hasta 3 salidas alternativas del mismo producto con cupo suficiente); vigencia en minutos (≥5, default 30); referencia obligatoria | “Regla transversal 4 / PRD-BR-012: cupo insuficiente… Alternativas: …” | S + `Cache::lock("departure:{id}")` + transacción |
| CAT-BR-22 | Confirmar hold: estado `hold`, no expirado, producto ACTIVE, `sold + qty ≤ capacity` | “El hold … expiró…”, “PRD-BR-012: … excede la capacidad” | S + lock |
| CAT-BR-23 | Confirmación directa de cupos: producto ACTIVE, salida `abierta`, `qty ≤ disponibles`, referencia obligatoria | “Sobreventa bloqueada” | S + lock |
| CAT-BR-24 | Liberar: motivo obligatorio; si estaba confirmada devuelve `sold` y reabre salida completa | — | S |
| CAT-BR-25 | Expiración: holds `hold` con `expires_at < now` → `expirada` | Aviso en la pantalla si hay holds vencidos no procesados | J |
| CAT-BR-26 | Precio B2B por agencia: agencia `activa`, una lista por agencia/producto, precio ≥ costo de la tarifa pública (si no, rechazo), precio > público → advertencia, venta a crédito exige línea de crédito de la agencia > 0, vigencia obligatoria. Aviso si `is_b2b` falso o canal B2B no publicado | Mensajes específicos | FR + S |
| CAT-BR-27 | Promoción: hasta ≥ desde y ≥ hoy; en tipos porcentuales (%, cantidad, temporada, canal, código, fecha) 0 < valor < 60; código en mayúsculas; precio resultante sobre la tarifa pública visible | “Los descuentos porcentuales deben estar entre 0 y 60%.” | FR |
| CAT-BR-28 | Publicación por canal: solo si producto ACTIVE; ERP no se puede despublicar; al (des)publicar B2B, E-commerce y Cotizaciones se sincronizan `is_b2b`, `is_ecommerce`, `is_quoteable` | “PRD-BR-002: active el producto antes de publicarlo en …” | S |
| CAT-BR-29 | Contenido: slug normalizado (minúsculas, sin tildes, guiones) y único en el tenant; una sola imagen principal, que no puede quitarse | “El slug ya está en uso por otro producto.” | FR + DB |
| CAT-BR-30 | Personalización: grupo con nombre único y ≥2 opciones “Etiqueta \| diferencia”; precio = tarifa base vigente visible + Σ deltas; cada selección se recalcula en backend | — | FR + S (`POST …/price-quote`) |
| CAT-BR-31 | Nueva versión: menor (`a.b+1`) o mayor (`a+1.0`) sobre la última no histórica; no repetir número; motivo y “vigente desde” obligatorios; snapshot completo | “La versión X ya existe.” | FR + S |
| CAT-BR-32 | Políticas: cancelación, modificación, no-show y reembolso obligatorias | “Campo obligatorio” | FR |
| CAT-BR-33 | Inclusiones/exclusiones: texto no vacío y sin duplicados en la lista | “Ya está en la lista.” | FR |
| CAT-BR-34 | Relacionados: distinto del propio, no repetido; se muestra también “Referenciado desde” (relación inversa) | — | FR + DB |
| CAT-BR-35 | Requisitos: `<categoría>: <detalle>`; detalle obligatorio | — | FR |
| CAT-BR-36 | Tipos (en mayúsculas) y categorías/subcategorías únicos; no se eliminan si hay productos que los usan | “No se puede eliminar: N producto(s) usan…” | FR + S |
| CAT-BR-37 | Servicio: hasta ≥ desde; costo ≥ 0; proveedor no activo → se guarda `inactivo` (aviso); cambio de costo/moneda se audita como `cambio_costo` indicando en cuántos productos se usa; activar requiere proveedor activo y vigencia; desactivar con motivo (avisa productos que lo usan) | — | FR + S |
| CAT-BR-38 | Proveedor: RUC `^(10|15|17|20)\d{9}$` y único; fin de contrato ≥ inicio; email válido; nace `inactivo`; activar requiere contrato vigente; renovar exige fecha futura (vencido → inactivo); desactivar con motivo y notifica a gerencia si hay productos ACTIVE afectados; calificación 1–5 | Mensajes específicos | FR + DB + S |
| CAT-BR-39 | Destino: raíz solo `País`; nivel hijo > nivel padre; sin ciclos (el nuevo padre no puede ser descendiente); al cambiar tipo, ningún hijo puede quedar en nivel ≥; lat/lng juntas y en rango; advertencia fuera del territorio peruano (lat −18.4..0, lng −81.4..−68.6); nombre único entre hermanos; Atracción/Ruta (nivel 6) no admite hijos; eliminar solo sin subdestinos y sin productos que lo referencien (principal o secundario) | “PROC-011 · referencia circular…”, “Jerarquía inválida…” | FR + S + DB |
| CAT-BR-40 | Datos sensibles por rol (§7): costo, margen, tarifas netas (tipos Proveedor/Costo/B2B/Mayorista o canal B2B/Mayorista), historial | Columnas/pestañas ocultas | P + `JsonResource` condicional |
| CAT-BR-41 | Rentabilidad: utilidad = venta − costo proveedor − otros costos − comisión (por pax × n); margen = utilidad / venta. Guardar markup de referencia requiere `manage_rates` | — | S |
| CAT-BR-42 | Motor de precios: `base = costo×pax + ajuste temporada (costo×(factor−1))`; `+ adicionales`; `+ markup (%, fijo)`; `− descuento (%, fijo)`; `+ impuesto`; redondeo; comisión sobre neto; utilidad = neto − base − comisión. Reglas: markup PR-01 (Directo/E-commerce) o PR-02 (B2B) salvo override; descuento PR-05 si ≥10 pax; IGV PR-03 (domiciliado) / PR-04 0% (no domiciliado); redondeo PR-06; comisión = agencia B2B o PR-07; PR-08 precio mínimo (el backend aplica el mínimo) | Desglose paso a paso | S (`PricingEngine`) |
| CAT-BR-43 | Filtro por destino incluye descendientes del destino principal y de los secundarios | — | S (query por `path`) |
| CAT-BR-44 | “Precio desde” = tarifa vigente Directo y Adulto/Pasajero; si no existe, Directo; si no, la primera vigente — siempre entre las visibles para el rol | — | S |
| CAT-BR-45 | Código de salida, hold, tarifa y promoción se generan por secuencia del tenant (no por máximo+1 como el prototipo) | — | S (`sys_sequences`) |

---

## 6. API REST (`/api/v1`)

Permisos abreviados: `view`=`catalog.products.view`, `create`, `update`, `activate`, `rates`=`manage_rates`, `avail`=`manage_availability`, `profit`=`view_profitability`, `publish`, `prov`=`catalog.providers.manage`. Errores de negocio: 422 con `code` de regla o 409 (conflicto de estado/disponibilidad/uso).

### 6.1 Productos y ciclo de vida
| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| GET | `/catalog/products` | view | Listado: `filter[type|category|status|destination|channel]`, `filter[q]`, `sort`, cursor. Incluye `from_price` visible al rol, `margin_pct` solo con profit, `future_departures`, `channels_count` | — |
| GET | `/catalog/products/kpis` | view | KPIs del listado (margen promedio solo con profit) | — |
| GET | `/catalog/products/export` | view (+ ver §12 `export`) | CSV como job (`reports`), auditado `exportar` | — |
| POST | `/catalog/products` | create | Alta del asistente en un solo payload (ficha, flags, destinos secundarios, componentes, itinerario, tarifas, temporadas, disponibilidad, contenido, canales solicitados). Crea DRAFT + versión 0.1 | `Idempotency-Key` |
| GET | `/catalog/products/{id}` | view | Cabecera 360 + ficha + `sellable`, `checklist_summary`, `current_version` | — |
| PATCH | `/catalog/products/{id}` | update | Editar ficha maestra (campos §5 + flags + markup). Cambio de `main_provider_id` genera audit separado | `If-Match: lock_version` |
| DELETE | `/catalog/products/{id}` | activate | Soft delete con `reason`; 409 `PRD-BR-018` con `{quotes, reservations, operations, packages}` | — |
| POST | `/catalog/products/{id}/duplicate` | create | Nuevo DRAFT (CAT-BR-09) | `Idempotency-Key` |
| GET | `/catalog/products/{id}/activation-checklist` | view | 12 ítems `{key, ok, detail}` | — |
| POST | `/catalog/products/{id}/submit-review` | update | DRAFT→REVIEW | lock_version |
| POST | `/catalog/products/{id}/approve` | activate | REVIEW→APPROVED | lock_version |
| POST | `/catalog/products/{id}/return-to-draft` | activate | REVIEW→DRAFT `{reason}` | lock_version |
| POST | `/catalog/products/{id}/activate` | activate | →ACTIVE; 422 `PRD-ACT-CHECKLIST` con fallidos (y audit) | lock_version |
| POST | `/catalog/products/{id}/suspend` · `/deactivate` · `/archive` | activate | `{reason}` | lock_version |
| GET | `/catalog/products/{id}/usage` | view | Cotizaciones/reservas que lo usan (Ventas: solo las propias) | — |
| POST | `/catalog/products/{id}/sheet` | view | Genera ficha comercial PDF (cola `documents`) → `sys_documents` | `Idempotency-Key` |
| GET | `/catalog/products/{id}/audit` | update ∨ profit ∨ `admin.audit.view` | Historial filtrable por acción/entidad (producto, sus tarifas y salidas) | — |

### 6.2 Sub-recursos del producto
| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| POST / DELETE | `/catalog/products/{id}/requirements[/{reqId}]` | update | Requisitos §12 |
| GET / POST | `/catalog/products/{id}/components` | view / update | Componentes (servicio o producto) |
| PATCH / DELETE | `/catalog/products/{id}/components/{componentId}` | update | Editar / quitar |
| POST | `/catalog/products/{id}/components/reorder` | update | `{ids:[…]}` |
| GET | `/catalog/products/{id}/itineraries` | view | Itinerario (días + ítems); costo/precio solo si ve costos |
| POST / PATCH / DELETE | `/catalog/products/{id}/itineraries/{itId}/days[/{dayId}]` | update | Días (renumeración al borrar) |
| POST / PATCH / DELETE | `/catalog/products/{id}/itineraries/{itId}/days/{dayId}/items[/{itemId}]` | update | Actividades |
| GET / POST | `/catalog/products/{id}/rates` | view / rates | Tarifas visibles al rol / alta (422 PRD-BR-004/005) |
| PATCH | `/catalog/products/{id}/rates/{rateId}/price` | rates | `{cost, price, reason}` | 
| POST | `/catalog/products/{id}/rates/{rateId}/activate` · `/deactivate` | rates | Alterna estado (no vencidas) |
| GET | `/catalog/products/{id}/rates/resolve?date&segment&channel` | view | Tarifa aplicable + motivos de descarte |
| GET / POST / DELETE | `/catalog/products/{id}/seasons[/{seasonId}]` | view / rates / rates | Asociación directa |
| GET / PUT | `/catalog/products/{id}/availability` | view / avail | Modelo, capacidad, fuente + situación (disponibles, holds activos) |
| GET / POST | `/catalog/products/{id}/departures` | view / avail | Salidas del producto |
| PATCH | `/catalog/products/{id}/departures/{departureId}` | avail | Editar (lock_version) |
| GET | `/catalog/products/{id}/providers` | view + ver costos | Proveedores involucrados (principal + de componentes) |
| PUT | `/catalog/products/{id}/main-provider` | update | `{provider_id, reason}` |
| GET / PUT | `/catalog/products/{id}/policies` | view / update | Políticas |
| GET / POST / DELETE | `/catalog/products/{id}/inclusions[/{incId}]` | view / update | `kind=include|exclude` |
| GET / POST / DELETE | `/catalog/products/{id}/related[/{relId}]` | view / update | Incluye `referenced_by` |
| GET / POST / PATCH / DELETE | `/catalog/products/{id}/b2b-rates[/{agencyId}]` | net-visible / update | Precios B2B por agencia |
| GET / POST / DELETE | `/catalog/products/{id}/promotions[/{promoId}]` | view / update | |
| POST | `/catalog/products/{id}/promotions/{promoId}/pause` · `/activate` | update | |
| GET | `/catalog/products/{id}/profitability?rate_id&pax&other_cost&commission_pct` | profit | Desglose §26 + margen por tarifa |
| PUT | `/catalog/products/{id}/markup` | rates | Markup de referencia |
| GET / POST / DELETE | `/catalog/products/{id}/config-groups[/{groupId}]` | view / update | Personalización §23 |
| POST | `/catalog/products/{id}/price-quote` | view | `{rate_id, selections:{group:option}, date, pax, channel}` → precio calculado en backend (PRD-T15) |
| GET / PUT | `/catalog/products/{id}/content` | view / update | Descripciones, características, video, SEO, slug |
| POST / PATCH / DELETE | `/catalog/products/{id}/media[/{mediaId}]` | update | Subida S3 (URL firmada), `is_main` |
| POST / DELETE | `/catalog/products/{id}/faqs[/{faqId}]` | update | |
| GET | `/catalog/products/{id}/channels` | view | 9 canales con estado |
| PUT | `/catalog/products/{id}/channels/{channel}` | publish | `{published:bool}` (422 si no ACTIVE) |
| GET / POST | `/catalog/products/{id}/versions` | update ∨ profit / update | Historial / nueva versión `{kind:minor|major, valid_from, reason}` |
| GET | `/catalog/products/{id}/versions/{versionId}` | update ∨ profit | Snapshot inmutable |
| GET | `/catalog/products/{id}/versions/compare?a&b` | update ∨ profit | Diferencias campo a campo |

### 6.3 Salidas y holds (globales)
| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| GET | `/catalog/departures?filter[product]&filter[status]&filter[period]=future|past&month=YYYY-MM` | view | Programación y calendario; KPIs | — |
| GET | `/catalog/departures/{id}` | view | Detalle + holds | — |
| POST | `/catalog/departures` | avail | Alta desde el listado global (`product_id`) | `Idempotency-Key` |
| POST | `/catalog/departures/{id}/confirm-seats` | avail | Venta directa `{quantity, reference}` | `Idempotency-Key` + lock Redis + `lock_version` |
| GET | `/catalog/holds?filter[status]` | view | Holds | — |
| POST | `/catalog/holds` | avail | `{departure_id, quantity, minutes, reference, notes}`; 409 con `alternatives[]` | `Idempotency-Key` + lock |
| POST | `/catalog/holds/{id}/confirm` | avail | hold→confirmada | lock |
| POST | `/catalog/holds/{id}/release` | avail | `{reason}` | lock |
| POST | `/catalog/holds/{id}/consume` | avail | confirmada→consumida | lock |
| POST | `/catalog/holds/expire` | avail | Ejecuta JOB-01 manualmente; devuelve holds expirados | `ShouldBeUnique` |

### 6.4 Tarifas, temporadas y pricing (tenant)
| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/catalog/rates` | view | Consolidado (filtros tipo, canal, estado efectivo, moneda); columnas costo/margen según rol |
| GET / POST | `/catalog/seasons` | view / rates | Listado + solapamientos detectados / alta (422 si solapa con igual prioridad) |
| PATCH / DELETE | `/catalog/seasons/{id}` | rates | Edición / borrado (409 si en uso → sugerir inactivar) |
| GET | `/catalog/seasons/resolve?date=` | view | Temporada aplicable y otras vigentes |
| GET / POST | `/catalog/price-rules` | profit ∨ rates / rates | Reglas |
| POST | `/catalog/price-rules/{id}/activate` · `/deactivate` | rates | |
| POST | `/catalog/pricing/simulate` | profit ∨ rates | `{product_id, rate_id, date, pax, channel, residency, extra, discount_fixed, markup_override?, discount_override?}` → desglose CAT-BR-42 |

### 6.5 Tipos, categorías, destinos, servicios, proveedores
| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET / POST / DELETE | `/catalog/product-types[/{id}]` | view / update / update | `requires_itinerary` en el alta; 409 si en uso |
| GET / POST / DELETE | `/catalog/categories[/{id}]` | view / update / update | Con `subcategories[]` |
| POST / DELETE | `/catalog/categories/{id}/subcategories[/{subId}]` | update | 409 si en uso |
| GET | `/catalog/destinations?tree=1` | view | Árbol con contadores de productos |
| GET | `/catalog/destinations/{id}` | view | Ficha + ancestros + hijos + productos (incluye subdestinos) |
| POST / PATCH / DELETE | `/catalog/destinations[/{id}]` | update | CAT-BR-39 (409 si tiene hijos o productos) |
| GET / POST | `/catalog/services` | view / prov | Listado (costo solo si ve costos) / alta |
| GET / PATCH | `/catalog/services/{id}` | view / prov | Ficha + productos + historial |
| POST | `/catalog/services/{id}/activate` · `/deactivate` | prov | `deactivate {reason}` |
| GET / POST | `/catalog/providers` | ruta proveedores / prov | Listado + KPIs / alta (nace inactivo) |
| GET / PATCH | `/catalog/providers/{id}` | ruta proveedores / prov | Ficha, servicios, productos, reservas; `payables` solo con `finance.payables` |
| POST | `/catalog/providers/{id}/activate` · `/deactivate` · `/renew-contract` · `/rating` | prov | `deactivate {reason}`, `renew {contract_end, payment_terms}`, `rating {rating, note}` |
| POST / DELETE | `/catalog/providers/{id}/contacts[/{contactId}]` | prov | |

“Ruta proveedores” = `prov ∨ finance.payables ∨ update` (igual que `ACCESS`).

---

## 7. Permisos y comportamiento por rol

### 7.1 Permisos del catálogo por rol (`DB.rolePerms`)
| Permiso | Tenant Admin | Manager | Ventas | Operaciones | Finanzas | Contabilidad | Externos / Super Admin |
|---|---|---|---|---|---|---|---|
| catalog.products.view | ✓ | ✓ | ✓ | ✓ | ✓ | — | (B2B lo tiene pero su shell no abre el back-office) |
| catalog.products.create | ✓ | ✓ | — | — | — | — | — |
| catalog.products.update | ✓ | ✓ | — | — | — | — | — |
| catalog.products.activate | ✓ | ✓ | — | — | — | — | — |
| catalog.products.manage_rates | ✓ | ✓ | — | — | — | — | — |
| catalog.products.manage_availability | ✓ | ✓ | — | ✓ | — | — | — |
| catalog.products.view_profitability | ✓ | ✓ | — | — | ✓ | — | — |
| catalog.products.publish | ✓ | ✓ | — | — | — | — | — |
| catalog.providers.manage | ✓ | **—** | — | ✓ | — | — | — |
| catalog.allotments.manage | ✓ | ✓ | — | ✓ | — | — | — |

### 7.2 Visibilidad de datos sensibles (función `SEE`)
| Dato | Regla | Quién lo ve |
|---|---|---|
| Costo de proveedor (columnas costo, costo de componentes, pestaña Proveedores, costo/precio de ítems de itinerario, contrato y condiciones de pago en ficha de servicio) | profit ∨ avail ∨ prov ∨ update ∨ rates | Admin, Manager, Operaciones, Finanzas |
| Margen / utilidad (KPI margen, columna margen, pestaña Costos y rentabilidad, markup de referencia) | profit | Admin, Manager, Finanzas |
| Tarifas netas (tipos Proveedor/Costo/B2B/Mayorista o canal B2B/Mayorista) y pestaña B2B | profit ∨ rates ∨ `channels.b2b.manage` | Admin, Manager, Finanzas |
| Pestaña Historial (auditoría) | update ∨ profit ∨ `admin.audit.view` | Admin, Manager, Finanzas |
| Pestaña Versiones | update ∨ profit | Admin, Manager, Finanzas |
| Pestaña Reglas de pricing | profit ∨ rates | Admin, Manager, Finanzas |
| KPI CxP y tarjeta Cuentas por pagar del proveedor | `finance.payables` | Admin, Manager, Finanzas |
| Uso en ventas (cotizaciones/reservas del producto) | Ventas: solo registros propios (`Scope.canSee`), título “Uso en mi cartera”; lista de cotizaciones solo si puede abrir `cotizaciones` | Todos los internos con view |

La API debe omitir esos campos en el `JsonResource` (no solo ocultarlos en Next.js).

### 7.3 Pestañas del Product 360 por rol
| Pestaña | Admin | Manager | Ventas | Operaciones | Finanzas | Acción de edición (permiso) |
|---|---|---|---|---|---|---|
| General | ✓ | ✓ | ✓ | ✓ | ✓ | Editar ficha, requisitos (update) |
| Componentes | ✓ | ✓ | ✓ sin costos | ✓ | ✓ | update |
| Itinerario | ✓ | ✓ | ✓ sin costo/precio | ✓ | ✓ | update |
| Tarifas / “Tarifas de venta” | ✓ | ✓ | solo venta, sin costo ni margen | venta + costo, sin margen | ✓ | rates |
| Temporadas | ✓ | ✓ | ✓ | ✓ | ✓ | rates |
| Disponibilidad | ✓ | ✓ | ✓ lectura | ✓ | ✓ lectura | avail |
| Salidas | ✓ | ✓ | ✓ lectura | ✓ | ✓ lectura | avail |
| Proveedores | ✓ | ✓ | oculta | ✓ | ✓ | update (cambiar principal) |
| Políticas / Inclusiones / Relacionados / Promociones / Personalización / Contenido | ✓ | ✓ | lectura | lectura | lectura | update |
| B2B | ✓ | ✓ | oculta | oculta | ✓ lectura | update |
| Costos y rentabilidad | ✓ | ✓ | oculta | oculta | ✓ (markup: rates → no) | rates (markup) |
| Canales | ✓ | ✓ | lectura | lectura | lectura | publish |
| Versiones | ✓ | ✓ | oculta | oculta | ✓ lectura | update |
| Historial | ✓ | ✓ | oculta | oculta | ✓ | — |
Si se llega por URL a una pestaña oculta se abre General y se muestra toast “La pestaña solicitada no está disponible para su rol”.

### 7.4 Comportamiento por rol
| Rol | Qué ve | Qué hace | Qué se oculta |
|---|---|---|---|
| Tenant Admin (U01) | Todo el catálogo del tenant | Todo (incluye proveedores/servicios) | — |
| Manager (U02) | Todo | Crear, editar, aprobar, activar, suspender, archivar, eliminar, tarifas, temporadas, reglas, disponibilidad, salidas, holds, publicar, tipos/categorías, destinos | Gestión de **proveedores y servicios** (solo lectura: no tiene `catalog.providers.manage`) |
| Ventas (U03/U04) | Productos, salidas, tarifas de venta, temporadas, servicios (sin costo), destinos; alerta “Modo consulta · Ventas · consulta de oferta y tarifas de venta” | Resolver tarifa aplicable, simular personalización, ver ficha PDF, exportar listados | Costos, márgenes, tarifas netas/B2B, pestañas Proveedores/B2B/Rentabilidad/Versiones/Historial, rutas Proveedores y Categorías (403), todos los botones de edición |
| Operaciones (U05/U06) | Productos con costos (sin márgenes), salidas, holds, servicios y proveedores | Disponibilidad, salidas, holds (crear/confirmar/liberar/consumir/expirar), CRUD de servicios y proveedores | Márgenes, tarifas netas, B2B, Rentabilidad, Versiones, Historial, edición de producto/tarifas |
| Finanzas (U07) | Todo en lectura incluido costos, márgenes, B2B, versiones, historial, reglas de pricing; proveedores (vía `finance.payables`) con CxP | Simuladores; exportar | Todas las acciones de edición (alerta “Finanzas · costos y rentabilidad en solo lectura”); switch de reglas oculto |
| Contabilidad (U08) | — (sin menú Catálogo; URL → 403) | — | — |
| Externos / Super Admin | — (403) | — | — |

Reglas de UI (BRIEF_ROLES §1): acciones sin permiso se renderizan con `data-noperm` y se **ocultan** (`display:none`); si se invocan por otra vía, `guard()` muestra toast “Su rol no tiene el permiso … (PRD-T13 → 403)” y no modifica datos. Menú “Más acciones” lista solo lo permitido (Validar checklist y Ficha PDF para todos; Nueva versión con update; Duplicar con create; Archivar/Eliminar con activate). Defecto a corregir: el botón “Tipos y categorías” del listado se muestra a todos aunque la ruta exige `update` (Ventas/Operaciones/Finanzas reciben 403).

---

## 8. Eventos, jobs (colas Redis) y notificaciones

### 8.1 Eventos de dominio → listeners
| Evento | Disparo | Listener (cola) | Notificación del prototipo |
|---|---|---|---|
| `ProductCreated` | Alta/duplicado | Auditoría (`default`) | — |
| `ProductSubmittedForReview` | DRAFT→REVIEW | Notificar aprobadores; ítem en bandeja de aprobaciones (`notifications`) | `producto.revision` · Portal → Manager (U02) |
| `ProductActivated` | →ACTIVE | Notificar ventas; invalidar caché de catálogo/canales; reindexar búsqueda (`notifications`, `low`) | `producto.activado` · Email → Ventas (U03) |
| `ProductBecameUnsellable` | →SUSPENDED/INACTIVE | Notificar ventas y canales; despublicar de e‑commerce/B2B en caché (`notifications`) | `producto.no_vendible` · Push → Ventas (U04) |
| `ProductStatusChanged` | Cualquier transición | Auditoría | — |
| `ProductVersionCreated` | Nueva versión | Auditoría; `current_version_id` | — |
| `RatePriceChanged` | Cambio de precio | Auditoría (PRD-T11); recalcular `from_price` (`low`) | — |
| `ProductPublished` / `ProductUnpublished` | Canal | Sincronizar CMS/e‑commerce/B2B (`integrations`, outbox) | — |
| `DepartureCancelled` (con vendidos) | Edición de salida | Notificar operaciones para reprogramar (`notifications`) | `salida.cancelada` · Email → Operaciones (U05) |
| `AvailabilityHoldCreated/Confirmed/Released/Expired/Consumed` | Holds | Auditoría; broadcast ocupación (Reverb canal `tenant.{id}.catalog.departures`) | `disponibilidad.confirmada` · Portal → creador del hold |
| `ProviderActivated` | Activar proveedor | Notificar operaciones | `proveedor.activado` · Email → Operaciones (U05) |
| `ProviderDeactivated` (con productos ACTIVE) | Desactivar | Notificar gerencia | `proveedor.desactivado` · Email → Manager (U02) |
En producción los destinatarios se resuelven por rol/suscripción (el prototipo usa usuarios fijos).

### 8.2 Jobs y scheduler
| Job | Programación | Cola | Qué hace |
|---|---|---|---|
| `ExpireAvailabilityHolds` (JOB-01) | cada 5 min (`routes/console.php`) + manual | `critical` | holds `hold` con `expires_at < now` → `expirada`; `ShouldBeUnique`; registra en `sys_job_runs` (`last`, `lastResult` “N hold(s) expirado(s)”) |
| `MarkExpiredProviderContracts` | diario 00:10 | `default` | proveedores `activo` con `contract_end < today` → `vencido`; notifica productos ACTIVE afectados |
| `ProviderContractExpiryAlerts` | diario | `notifications` | contratos que vencen en ≤95 días (umbral del KPI) |
| `ServiceValidityAlerts` | diario | `notifications` | servicios que vencen en ≤90 días (KPI) o ≤60 (alerta en ficha) |
| `RateValidityAlerts` | diario | `notifications` | productos ACTIVE sin tarifa vigente (KPI “Productos sin tarifa vigente”) |
| `GenerateProductSheetPdf` | on demand | `documents` | Ficha comercial PDF (itinerario, incluye/no incluye, requisitos, políticas, precio desde) |
| `ExportCatalogCsv` | on demand | `reports` | Exportaciones de tablas (auditadas) |
Locks Redis: `Cache::lock("catalog:departure:{id}", 10)` en hold/confirmación/liberación/edición de capacidad; `Cache::lock("catalog:product:{id}:version")` en nueva versión.

---

## 9. Auditoría

Todas en `audit_logs` con usuario, fecha, IP, correlación, entidad, `before`/`after` (Spec PRD §29).

| Entidad | Acciones registradas por el prototipo |
|---|---|
| Producto | `crear`, `modificar` (lista de campos cambiados; requisitos, inclusiones/exclusiones), `cambio_proveedor`, `cambio_estado` (antes → después — motivo), `activacion_rechazada` (ítems fallidos), `eliminacion_bloqueada`, `eliminar` (soft delete — motivo), `cambio_componentes`, `cambio_itinerario`, `asociar_temporada`, `desasociar_temporada`, `cambio_disponibilidad`, `cambio_politicas`, `relacionar`, `desrelacionar`, `crear_precio_b2b`, `cambio_precio_b2b`, `eliminar_precio_b2b`, `crear_promocion`, `cambio_promocion`, `eliminar_promocion`, `cambio_markup`, `cambio_personalizacion`, `cambio_contenido`, `publicacion`, `despublicacion`, `nueva_version` |
| Tarifa | `crear`, `cambio_precio` (antes/después + motivo), `cambio_estado` |
| Salida | `crear`, `modificar` |
| Disponibilidad (hold) | `hold`, `confirmar`, `liberar` (motivo), `consumir`, `expirar` |
| Temporada | `crear`, `modificar`, `eliminar`, `cambio_estado` |
| Regla de precio | `crear`, `cambio_estado` |
| Servicio | `crear`, `modificar`, `cambio_costo`, `cambio_estado` |
| Proveedor | `crear`, `modificar`, `renovar_contrato`, `activar`, `desactivar`, `agregar_contacto`, `quitar_contacto`, `calificar` |
| Destino | `crear`, `modificar`, `eliminar` |
| Catálogo (tipos/categorías) | `crear_tipo`, `eliminar_tipo`, `crear_categoria`, `crear_subcategoria`, `eliminar_subcategoria`, `eliminar_categoria` |
| Exportación | `exportar` (n filas) |
| Acceso | `acceso_denegado` (ruta y permiso requerido) |
Faltantes a agregar en backend: quitar imagen de galería, marcar imagen principal (el prototipo lo registra genéricamente), cambios de `is_*` por sincronización de canales, cambio de temporada asociada a tarifa.

---

## 10. Casos de prueba mínimos

### 10.1 Pest (feature) — casos de la Spec PRD §43
| ID | Caso | Resultado esperado |
|---|---|---|
| PRD-T01 | GET producto propio | 200 |
| PRD-T02 | GET producto de otro tenant | 404 (RLS) |
| PRD-T03 | POST producto válido (Manager) | 201, status DRAFT, versión 0.1 borrador, audit `crear` |
| PRD-T04 | Agregar producto SUSPENDED a cotización / hold / confirmación | 422 `PRD-BR-002` |
| PRD-T05 | Tarifa vencida en `rates/resolve` y en `price-quote` | No seleccionable; 422 al forzarla |
| PRD-T06 | Cambiar precio de tarifa usada | Quote item conserva snapshot |
| PRD-T07 | Componente de proveedor inactivo / servicio vencido | 422 `PRD-BR-007`; activación bloqueada |
| PRD-T08 | Tarifa sin moneda | 422 `errors.currency` |
| PRD-T09 | Hold/confirmación con qty > disponibles | 409 con `alternatives` |
| PRD-T10 | Modificar producto usado por cotización | Snapshot de versión intacto |
| PRD-T11 | Cambio de precio | audit `cambio_precio` con antes/después y motivo |
| PRD-T12 | Nueva versión | anterior `historica`, nueva `vigente`, ambas consultables |
| PRD-T13 | Ventas: PATCH producto / POST tarifa / PUT canal | 403 |
| PRD-T14 | ID de otro tenant en sub-recursos (`/components`, `/rates`) | 404 |
| PRD-T15 | `price-quote` con precio enviado por el cliente | Ignorado; precio del motor |

### 10.2 Pest — reglas del prototipo
| Caso | Esperado |
|---|---|
| Activar desde DRAFT o REVIEW | 422 checklist (ítem Estado) + audit `activacion_rechazada` |
| Activar APPROVED con día de itinerario sin actividades (tipo CIRCUITO) | 422 ítem Itinerario |
| Activar POR_SALIDA sin salidas futuras abiertas | 422 ítem Disponibilidad |
| Enviar a revisión sin categoría | 422 CAT-BR-05 |
| Suspender/Inactivar/Archivar/Devolver sin motivo | 422 |
| DELETE producto con cotizaciones | 409 `PRD-BR-018`, fila intacta |
| DELETE producto sin uso | soft delete (`deleted_at`) |
| Código interno duplicado (mayúsculas distintas) | 422 |
| Temporada solapada con misma prioridad | 422 (y violación EXCLUDE si se salta el servicio) |
| Borrar temporada usada por tarifa | 409 |
| Salida con capacidad 61 / fecha pasada / capacidad < vendidos+hold | 422 |
| Dos holds concurrentes por el último cupo | uno 201, otro 409 (lock Redis) |
| Confirmar hold expirado | 422 |
| Liberar confirmada | `sold` decrementa; completa → abierta |
| JOB-01 | holds vencidos → expirada; idempotente |
| Publicar canal con producto DRAFT | 422; ERP no despublicable |
| Precio B2B < costo / agencia bloqueada / crédito sin línea | 422 |
| Promoción 60 % | 422 |
| Slug duplicado | 422 |
| Proveedor RUC inválido / duplicado | 422 |
| Activar proveedor con contrato vencido | 422 |
| Servicio con proveedor inactivo guardado como activo | se persiste `inactivo` |
| Destino con padre descendiente / Ciudad bajo Atracción / raíz no País | 422 |
| Borrar destino con hijos o productos | 409 |
| Tipo/categoría en uso | 409 |
| RBAC: Operaciones crea hold ✓, cambia precio ✗; Manager crea proveedor ✗; Finanzas GET profitability ✓, PATCH ✗ | 201/403 |
| Serialización: Ventas no recibe `cost`, `margin_pct`, tarifas B2B, `b2b_rates`; Operaciones recibe `cost` pero no `margin_pct` | campos ausentes |

### 10.3 Playwright
| Caso | Flujo |
|---|---|
| CAT-E2E-01 | Manager: asistente 10 pasos → DRAFT → enviar a revisión → aprobar → checklist → activar → publicar e‑commerce |
| CAT-E2E-02 | Manager: nueva versión 2.1, comparar con 2.0, ver snapshot |
| CAT-E2E-03 | Operaciones: nueva salida → hold 2 cupos → confirmar → calendario muestra ocupación; hold insuficiente muestra alternativas |
| CAT-E2E-04 | Ventas: 360 sin pestañas Proveedores/B2B/Rentabilidad/Versiones/Historial; URL `/catalogo/productos/PRD-001/rentabilidad` abre General con aviso; `/catalogo/proveedores` → 403 |
| CAT-E2E-05 | Tarifas: resolver tarifa por fecha/segmento/canal; simulador de pricing ≥10 pax aplica PR-05 |
| E2E-01/02 (DOC-PROC-001) | Producto ACTIVE seleccionable en cotización y convertido a reserva con snapshot |

---

## 11. Cambios y decisiones respecto a la documentación inicial

Leyenda: **=** implementado igual · **P** precisado · **C** cambiado · **A** agregado · **F** faltante/parcial (a completar en desarrollo).

### 11.1 Contraste con la Spec PRD, sección por sección
| § | Spec PRD | Prototipo | Tipo | Detalle / motivo |
|---|---|---|---|---|
| 1 | Objetivo: crear, versionar, publicar, cotizar | Todo presente; consumido por ventas/canales | = | Producto (oferta) vs servicio (componente) separados en pantallas y tablas |
| 2 | Cadena Destino→…→Finanzas; lógica en backend | Simuladores etiquetados “respuesta simulada del backend” | = / P | Se define `PricingEngine`, `SeasonResolver`, `AvailabilityService`, `ProductLifecycle` como servicios únicos (reutilizados por cotizador, B2B y e‑commerce) |
| 3 | 13 tipos, configurables por tenant | 13 tipos exactos; alta/baja por tenant | = / A | Se agrega atributo **“requiere itinerario”** por tipo (usado por el checklist) y `requires_components` (PRODUCTO PERSONALIZADO exento). En el prototipo la marca no persiste (lista en memoria): en backend es columna |
| 4 | Categoría → subcategoría configurable | CRUD con contadores de uso | = / A | Se agrega bloqueo de borrado si está en uso |
| 5 | Ficha maestra (30 campos) | Todos presentes | = / P | `booking_advance_*` en **días**; `duration_value` ≥1; `modality` Privado/Compartido/Grupal; defaults: SKU `<prefijo>-<code>`, nombre interno = nombre. Se agregan `markup_pct` (referencia), `availability_*`, SEO/slug/video/features (de §27) en la misma tabla |
| 6 | 7 estados + reglas | 7 estados con flujo visual | P | Transiciones exactas en §4.1. Decisiones: la activación **exige** pasar por APPROVED (el ítem “Estado” del checklist falla en DRAFT/REVIEW); REVIEW→DRAFT “Devolver”; motivo obligatorio para salidas de venta; ARCHIVED terminal y alcanzable desde cualquier estado vía “Más acciones” |
| 7 | Destino principal, secundarios y geografía | Principal + secundarios; jerarquía de 9 tipos con coordenadas | = / P | País, Región, Departamento, Provincia, Ciudad, Zona, Lugar, Atracción, Ruta con niveles 0–6; filtro por destino incluye descendientes |
| 8 | 1..n proveedores; separar comercial/contractual | Proveedor principal + proveedores de componentes (vista derivada); datos contractuales solo en Proveedores | = / C | No hay tabla N:M producto‑proveedor: los proveedores del producto se **derivan** de los componentes + principal. Tipos: se agrega **Tren**; “Conductor” existe como tipo pero los conductores se gestionan en Operaciones |
| 9 | Componentes con service_id, provider_id, qty, unit, order, required, included, cost, notes, validity | qty, unit, order, required, included, notes; costo y vigencia **leídos del servicio** | P / F | Costo y vigencia del componente = los del servicio vigente (conversión USD→PEN con TC del tenant). Se agrega `unit_cost_override` opcional y `provider_id` denormalizado para trazabilidad. Unidades: pax, servicio, habitación, noche, vehículo, unidad |
| 10 | Paquetes con productos y/o servicios | Componente puede ser **producto** (solo ACTIVE, no el propio) | = | Se usa la misma tabla `catalog_product_items` (no `catalog_packages`/`catalog_package_items`): un paquete es un producto de tipo PAQUETE |
| 11 | Incluye / No incluye en cotización, voucher, portal, e‑commerce, PDF | Listas editables; se muestran en ficha PDF | = | El asistente propone “Incluye” desde componentes incluidos y “No incluye” por defecto (Propinas, Bebidas, Gastos personales, Servicios no especificados) |
| 12 | Requisitos (edad, condición física, documentos, nacionalidad…) | Edad y pax en ficha; requisitos como lista `categoría: detalle` | P | 7 categorías de requisito |
| 13 | Políticas configurables + snapshot | 8 campos; 4 obligatorios; texto libre | P | Obligatorias: cancelación, modificación, no-show, reembolso. Anticipación de políticas es texto (“48 horas”) además de los días de la ficha (ver §12) |
| 14 | Uno o varios itinerarios; constructor completo | **Un** itinerario por producto; día, hora, actividad, tipo (servicio/hotel/transporte/alimentación/guía…), ubicación, servicio vinculado, nota, imagen, costo, precio | P / F | Modelo preparado para varios (`catalog_itineraries.is_default`); UI con uno. Imagen = referencia de texto (sin subida) |
| 15 | Versiones con snapshot, usuario, motivo, vigencia | Versión menor/mayor, motivo, vigente desde, usuario, snapshot, comparación A/B | = / C | **Cambio**: la nueva versión queda vigente de inmediato (no hay paso “activar versión”); la anterior pasa a histórica. Estados de versión: borrador, en revisión, vigente, histórica |
| 16 | Tipos de tarifa y segmentos | 11 tipos y 5 segmentos exactos; + canal y base de precio | = / A | Canales de tarifa: Directo, B2B, Corporativo, E-commerce, Mayorista, Todos |
| 17 | Motor: costo+adicionales+markup−descuentos+impuestos; modalidades | Simulador con todas las piezas + comisión y redondeo | = / P | Orden exacto y fórmula de utilidad en CAT-BR-42. Base de precio (por persona, fijo, grupo, habitación, unidad) se registra en la tarifa pero el simulador multiplica siempre por pax (ver §12) |
| 18 | Temporadas y vigencia por fecha | 6 tipos; prioridad; factor; línea de tiempo; resolución por fecha | = / A | Se agrega **factor** multiplicador y prioridad con validación de solapamientos |
| 19 | Reglas de tarifa (13 dimensiones) | Fecha, temporada, segmento, canal por campos; resto en **texto libre** `conditions` | F | Se propone `catalog_rate_conditions` estructurada; el prototipo no evalúa día de semana, edad, anticipación, duración ni ocupación salvo PR-05 (≥10 pax) |
| 20 | 9 modelos de disponibilidad | 9 modelos exactos + capacidad ref. + fuente interna/externa | = / A | Solo POR_SALIDA tiene control efectivo de cupos en este módulo; CUPOS/allotments en `#/catalogo/cupos` |
| 21 | Salidas con fecha, hora, capacidad, cupos, estado, proveedor, guía, transporte, puntos, observaciones | Todo presente + holds + calendario | = / A | Estados abierta/completa/cerrada/cancelada; capacidad máx. 60; holds con expiración |
| 22 | Relacionados 5 tipos | 5 tipos + “referenciado desde” | = | |
| 23 | Personalización con recálculo en backend | Grupos de opciones con delta de precio; endpoint `price-quote` | = / P | Delta fijo por opción sumado a la tarifa base |
| 24 | B2B: agencia, lista, comisión, condición, crédito, vigencia, restricciones | Todo presente | = / A | Validaciones CAT-BR-26 (precio ≥ costo, crédito con línea aprobada, agencia activa) |
| 25 | Promociones preparadas, independientes del precio base | 8 tipos, canal, código, vigencia, pausa; precio resultante de vista | = / P | Tope 60 % para porcentuales; motor las aplica sobre tarifa vigente |
| 26 | Costos y rentabilidad con ejemplo | Desglose por tarifa y pax + margen por tarifa | = | Visible solo con `view_profitability` |
| 27 | Contenido comercial (13 elementos) | Todos (galería simulada) | = / P | Slug normalizado y único; recomendaciones 60/160 caracteres con vista SERP |
| 28 | 9 canales | 9 canales (ERP, CRM, Cotizaciones, B2B, Portal cliente, **Portal pasajero**, CMS, E-commerce, Mobile) | = / C | Publicar solo con producto ACTIVE; sincroniza `is_b2b`, `is_ecommerce`, `is_quoteable` |
| 29 | Auditoría de 13 eventos | Todos + holds, salidas, temporadas, reglas, proveedores, servicios, destinos | = / A | Ver §9 |
| 30 | Product 360 con 20 pestañas | 19 pestañas | C | Unificadas: Inclusiones+Exclusiones en una; Costos+Rentabilidad en una. Agregada: **Personalización**. Pestañas ocultas por rol (§7.3) |
| 31 | Rutas `/products…` | `/catalogo/productos/[ref]/[tab]`, `/catalogo/categorias` | C | Convención del proyecto: ruta Next = ruta del prototipo en español. `edit` es un drawer, no ruta. `/products/types` y `/categories` fusionadas en `/catalogo/categorias` |
| 32 | API mínima | Cubierta y ampliada (§6) | = / A | Transiciones como acciones `POST …/activate` etc. `versions/{id}/activate` **no** se implementa (ver §12, punto 5) |
| 33 | Tablas núcleo + extensiones | §3 | C | No se crean `catalog_packages`/`catalog_package_items` (paquete = producto con componentes) ni `catalog_product_tags`. `catalog_availabilities` → `catalog_availability_holds`; se agregan `catalog_product_config_*`, `catalog_product_faqs`, `catalog_provider_contacts`, `catalog_price_rules` a nivel tenant |
| 34 | PRD-BR-001…018 | Todas aplicadas (§5.1) | = | |
| 35 | 19 permisos granulares | **10** permisos | C | El prototipo agrupa: `update` cubre components, itineraries, versions, policies, promotions, b2b, content; `activate` cubre suspend, archive y delete; no existe `export` (todas las tablas exportan). Se agregan `catalog.providers.manage` y `catalog.allotments.manage`. Ver decisión en §12 |
| 36 | Integración con cotizaciones (versión, tarifa, componentes, condiciones, snapshot) | Cotizaciones guardan `snapshot: 'PRD-002 v1.1'` (texto) | P / F | Backend: quote item debe guardar `product_version_id` + copia JSON del snapshot + tarifa aplicada + fx |
| 37 | Integración con reservas; Availability Engine | Holds referencian COT/RES/WEB | P | `AvailabilityService` único; reservas/B2B/e‑commerce lo invocan |
| 38 | Componentes → servicios operativos | Texto explicativo; conversión la hace Reservas/Operaciones | F | A implementar en el módulo Reservas usando `catalog_product_items` |
| 39 | Integración financiera sin duplicar | Rentabilidad de catálogo es estimada; CxP del proveedor se leen de Finanzas | = | |
| 40 | Multi-tenant | RLS + `tenant_id` | = | |
| 41 | Soft delete / historial | Eliminación bloqueada con referencias; si no, soft delete | = | Se agrega verificación de paquetes que lo contienen y operaciones |
| 42 | Validaciones antes de activar | 12 ítems | = / P | Exactitud de cada ítem en CAT-BR-06 (p. ej. “todos los días con actividades”, “salida futura abierta si POR_SALIDA”) |
| 43 | QA PRD-T01…T15 | §10.1 | = | |
| 44 | Criterios de aceptación | Todos demostrables en el prototipo salvo §38 | = | |
| 45 | Definition of Done | Aplica | = | |
| 46 | Orden de implementación | Se sugiere: Destinos → Proveedores → Servicios antes de Productos (dependencia de FK) | P | Tipos/Categorías → Destinos → Proveedores → Servicios → Productos → Componentes → Versiones → Políticas → Itinerario → Tarifas → Temporadas → Reglas → Disponibilidad/Salidas/Holds → B2B → Promociones → Contenido → Canales → Rentabilidad → integraciones |
| 47 | Regla fundamental | Respetada | = | |

### 11.2 Contraste con DOC-PROC-001 (PROC-011 a PROC-020)
| Proceso | Tipo | Detalle |
|---|---|---|
| PROC-011 | = / P | Tipos Zona y Ruta agregados; regla “raíz = País”; coordenadas con advertencia fuera de Perú; nombres únicos por nivel |
| PROC-012 | = / P | Proveedor nace **inactivo**; activación exige contrato vigente; estado `vencido`; renovación; calificación; contactos múltiples |
| PROC-013 | = / A | Servicios gestionados con `catalog.providers.manage` (no con permisos de producto); capacidad propia opcional |
| PROC-014 | = | Asistente de 10 pasos idéntico al flujo |
| PROC-015 | P | Paquete = producto tipo PAQUETE con componentes (servicios y productos) |
| PROC-016 | = / P | Un itinerario por producto |
| PROC-017 | = / A | Se agregan canal, base de precio, precio mín./máx., condiciones |
| PROC-018 | = / F | Cadena completa en el simulador; reglas con ámbito textual (estructurar en backend) |
| PROC-019 | = | Solapamiento solo con prioridad distinta |
| PROC-020 | = / A | Estados Disponible → Hold → Confirmada → Liberada/Expirada/Consumida; expiración por job; alternativas sugeridas |
| Actores (§4) | P | Contabilidad sin acceso al catálogo; Manager sin gestión de proveedores |


### 11.3 v2.1 — Motor de reservas y configuración comercial por producto
Detalle completo (BookingEngine, centro de reglas, tablas, endpoints, caché y locks) en **`16-motor-de-reservas-y-reglas.md`**. Cambios en este módulo:

| Área | Tipo | Cambio |
|---|---|---|
| Product 360 | A | Nueva pestaña **Reservas online** (`#/catalogo/productos/{id}/reservas[/sub]`) con resumen comercial y 7 sub-secciones: Disponibilidad, Pasajeros, Adicionales, Alojamiento, Pago, Recojo y datos, Reglas del producto. Pestañas existentes sin cambios; *Disponibilidad* (modelo PRD §20) enlaza a la nueva sub-sección |
| Disponibilidad por rangos | A | Calendario mensual por estado (`BE.month`: disponible, ocupado/cerrado, agotado, cupo insuficiente, fuera de plazo, no opera), detalle por día (`BE.dayStatus`), selector de pax, CRUD de rangos (desde, hasta, estado, cupo/día, nota), acción rápida «Cerrar fechas» seleccionando días, días de operación y horas de anticipación; leyenda textual «Disponible del 02 al 06 oct · Ocupado del 07 al 09 oct · Disponible del 10 al 14 oct». Solapes validados con `BE.overlaps`: se permite cerrado/agotado sobre disponible (excepción), no dos del mismo tipo (CAT-AV-01); cupo no menor a lo vendido (CAT-AV-03); cierre con ventas pide confirmación y notifica a Operaciones (CAT-AV-04). Regla: `R-AVAIL-RANGES`, `R-WEEKDAYS`, `R-CUTOFF` |
| Reglas de pasajeros | A | Edades adulto/niño/infante, niño paga (% o tarifa Niño vigente, que prevalece), infante no paga/admitido, máximos por adulto, mínimo de adultos, nota; simulador con `BE.validatePax` + líneas base de `BE.quote` y clasificador `BE.ageType`. Validación de bandas CAT-PX-01. Reglas: `R-CHILD-PER-ADULT`, `R-INFANT-FREE`, `R-MIN-ADULTS`, `R-MAX-PAX` |
| Adicionales | A | CRUD (nombre, categoría, precio, moneda activa, por pax/por reserva, aplica a ADT/CHD/INF, stock/nota, proveedor/servicio opcional). Regla: `R-ADDONS` |
| Alojamiento por ciudad | A | Activar/retirar, opcional/incluido, moneda, tramos ordenables (ciudad, destino, noches), hoteles con categoría, proveedor y tipos de habitación (capacidad, precio/noche); resumen «4 ciudades · 9 noches» con control contra la duración (CAT-LG-02) y ejemplo calculado por el motor. Reglas: `R-LODGING`, `R-LODGING-CAPACITY`, `R-SINGLE-SUPP` |
| Pago | A | Modalidades total / adelanto / pagar después (este último como override de `R-PAY-LATER`), % de adelanto, días antes del viaje para el saldo, vista previa por modalidad. Reglas: `R-DEPOSIT`, `R-PAY-LATER` |
| Recojo | A | Puntos de partida/recojo, obligatorio, pedir vuelo. Regla: `R-PICKUP` |
| Reglas del producto | A | Reglas con `productOverride=true`: estado del tenant, switch por producto (`p.ruleOverrides`), parámetros propios (`p.ruleParams`), restablecer; las apagadas en el tenant se muestran en gris con enlace a `#/admin/reglas` |
| Gating | A | Cada sub-sección muestra «Regla desactivada para este tenant» o «Desactivada solo para este producto» (con *Reactivar*) y no ofrece campos vacíos; la configuración guardada se conserva |
| Permisos | P | Disponibilidad: `manage_availability` (Admin, Manager, **Operaciones**); Recojo: `update` o `manage_availability`; Adicionales, Alojamiento, Pago: `manage_rates`; Pasajeros y Reglas: `update`. Ventas/Finanzas: solo lectura |
| Auditoría | A | Acciones `rango_*`, `cerrar_fechas`, `disponibilidad_*`, `reglas_pasajeros`, `adicional_*`, `alojamiento_*`, `tramo_*`, `hotel_*`, `politica_pago`, `recojo`, `regla_producto`, `regla_parametros`, `regla_restablecer` (pestaña Historial) |
| Datos | A | Producto semilla **PRD-011** (Perú Clásico 10D/9N) con el caso del cliente y alojamiento en 4 ciudades; configuración en PRD-001, PRD-002, PRD-004 |

---

## 12. Supuestos y puntos a validar con negocio

| # | Punto | Estado actual del prototipo | Propuesta / pregunta |
|---|---|---|---|
| 1 | Permisos granulares de la Spec PRD §35 (19) vs 10 del prototipo | Agrupados | ¿Implementar los 19 (recomendado, con los 10 como roles por defecto) o mantener 10? Afecta seeders de spatie |
| 2 | Eliminar producto con permiso `activate` | Sí | ¿Crear `catalog.products.delete` solo para Admin? |
| 3 | Botón “Activar” visible en DRAFT/REVIEW aunque siempre falla | Visible | Ocultarlo o permitir activación directa desde REVIEW con aprobación implícita |
| 4 | Manager sin `catalog.providers.manage` | Lectura de proveedores y servicios | Confirmar que gerencia no edita proveedores |
| 5 | Activar versión (Spec §32) | La nueva versión es vigente al crearla | ¿Se requiere aprobación de versión antes de vigencia (usar `valid_from` futuro)? |
| 6 | Un solo itinerario por producto | Sí | ¿Se necesitan itinerarios alternativos (p. ej. por idioma o por temporada)? |
| 7 | Condiciones de tarifa (§19) | Texto libre | Priorizar dimensiones a estructurar: día de semana, pax, edad, anticipación |
| 8 | Base de precio (fijo/grupo/habitación/unidad) | Guardada pero el cálculo multiplica por pax | Definir fórmula por base |
| 9 | Aplicación del factor de temporada | Pestaña Temporadas: **precio × factor**; simulador: **costo × factor** | Definir si el factor afecta costo, precio o ambos |
| 10 | Reglas de pricing con ámbito en texto y PR-08 codificada para PRD-001/USD | Codificado | Estructurar ámbito (canal, pax, residencia, producto, moneda) y prioridad |
| 11 | Capacidad máxima de salida 60 pax | Fijo | ¿Parámetro por tenant o por tipo de producto? |
| 12 | Tope de promociones porcentuales 60 % | Fijo | ¿Parámetro? ¿Requiere aprobación sobre cierto %? |
| 13 | Vigencia del hold | 30 min por defecto (`settings.holdMinutes`), mínimo 5, sin máximo | Definir máximo y valores por canal (B2B, e‑commerce) |
| 14 | Reapertura de salida cancelada/cerrada | Permitida por edición | ¿Cancelada debe ser terminal? |
| 15 | Holds vencidos siguen restando cupos hasta que corre el job | Sí (hasta 5 min) | Calcular disponibles ignorando holds vencidos |
| 16 | Tarifas: no se pueden eliminar ni editar campos distintos a costo/precio | Sí | Confirmar (solo desactivar + nueva tarifa) |
| 17 | Cambio de precio no valida precio máximo | Solo mínimo | Validar ambos |
| 18 | Componentes repetidos en 360 | Permitido (en el asistente no) | Unificar: ¿se permite el mismo servicio dos veces? |
| 19 | Moneda del precio B2B | Hereda la de la tarifa pública | ¿Listas B2B en otra moneda? |
| 20 | Monedas | Solo PEN y USD; TC único del tenant (3.74) para convertir costos | Guardar TC en snapshot; ¿más monedas? |
| 21 | Sincronía canal ↔ flags (`is_b2b`, `is_ecommerce`, `is_quoteable`) | Doble fuente (ficha y canales) | Unificar en `catalog_product_channels` y derivar flags |
| 22 | Anticipación de reserva en días (ficha) y en texto (políticas) | Duplicado | Mantener solo la de la ficha como regla; la de políticas como texto comercial |
| 23 | Tarifas visibles a Ventas | Excluye tipos Proveedor/Costo/B2B/Mayorista y canales B2B/Mayorista; incluye Corporativa | Confirmar que Ventas puede ver tarifa Corporativa y Grupal |
| 24 | Operaciones ve costos pero crea/edita servicios con costo | Sí | Confirmar que Operaciones negocia costos con proveedores |
| 25 | Destinatarios de notificaciones | Usuarios fijos | Definir por rol/suscripción (ventas del tenant, gerente de la sede, coordinador de operaciones) |
| 26 | Validación de coordenadas limitada a Perú (advertencia) | Advertencia | ¿Tenants con destinos fuera de Perú? |
| 27 | Promo con código: unicidad | No validada | ¿Código único por tenant? |
| 28 | Snapshot de versión | No incluye requisitos, B2B, promociones, canales, contenido | ¿Ampliarlo? (recomendado para PDF histórico) |
| 29 | Galería | Simulada (sin archivo) | Tamaños, formatos, variantes y límite por plan |
| 30 | Exportación | Toda tabla exporta sin permiso específico | ¿Exigir `catalog.products.export` / `reports.export`? |
| 31 | Botón “Tipos y categorías” visible a roles sin `update` | Muestra 403 | Ocultar (corrección de UI) |
| 32 | Integración §38 (componentes → servicios operativos) | Solo descrita | Definir mapeo tipo de componente → tipo de servicio operativo en el módulo Reservas |