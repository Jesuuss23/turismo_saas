# Canales y portales — B2B, E-commerce, CMS, Portal del cliente, Portal del pasajero

Fuente: `proto/assets/js/modules/canales.js` (+ `core.js`, `data.js`). Documento de referencia inicial: DOC-PROC-001 §17–18 (PROC-051 a PROC-055), §30 (reglas transversales, en adelante **RT-n**) y Especificación PRD (§24 B2B, §25 Promociones, §27 Contenido, §28 Publicación en canales).

> **Relación con el sitio web público (especificación 15, `sitio.js`).** Otro equipo construye el sitio web público completo, que **reemplaza como escaparate** a la "Tienda (vista pública)" de este módulo (`#/canales/ecommerce/tienda…`). Este documento:
> - especifica la **gestión e-commerce** (pedidos, publicación, promociones, embudo) que sigue viviendo en el back-office;
> - documenta la tienda actual **como flujo de checkout de referencia** (reglas de precio, disponibilidad, promociones, pago, creación de pedido/reserva/voucher). La especificación 15 debe consumir los mismos endpoints públicos `/api/v1/public/shop/*` definidos aquí (sección 6.3) y no duplicar reglas (RT-18). Las pantallas de catálogo/detalle/checkout de la tienda del prototipo **no deben construirse en Next.js dentro del back-office**; su equivalente público es responsabilidad de la especificación 15.
> - El contenido del CMS (banners, páginas, legales) es la fuente de contenido del sitio público y de los portales.

---

## 1. Propósito y procesos cubiertos (PROC-xxx)

| Proceso | Submódulo | Qué cubre el prototipo |
|---|---|---|
| **PROC-053** B2B | Administración B2B (interno) + Portal de agencias (externo) | Alta/edición/bloqueo de agencias asociadas, listas de precios y tarifas netas por agencia/producto, línea de crédito con bloqueo automático, catálogo autorizado, reserva con crédito o prepago y consumo de cupos, voucher automático, estado de cuenta con comisiones, informe de pagos y solicitud de liquidación de comisiones. |
| **PROC-054** E-commerce | Gestión del canal (interno) + Tienda de referencia (pública) | KPIs y embudo, pedidos web con intentos de pago, reenvío de enlace de pago y anulación, publicación de productos, promociones y códigos. Checkout de 7 pasos: Selección → Configuración → Disponibilidad → Precio → Datos del pasajero → Pago → Confirmación, que crea cliente, reserva, CxC, pago, voucher. |
| **PROC-055** CMS | CMS (interno) | Contenidos (página, artículo, banner, legal, landing) con flujo editorial Borrador → En revisión → Aprobado → Publicado → Despublicado, bitácora, SEO, vista previa y slug único. |
| **PROC-051** Portal cliente | Portal del cliente (externo; previsualización interna) | Inicio, cotizaciones (ver/aprobar con evidencia/rechazar con motivo/solicitar actualización), mis viajes, documentos, pagos en línea (parciales), notificaciones, soporte (tickets). |
| **PROC-052** Portal pasajero | Portal del pasajero (externo; previsualización interna) | Mi viaje, itinerario día a día, hoteles, transporte, actividades, vouchers, documentos, alertas con confirmación de lectura, contacto 24/7 y mensaje a operaciones. |

Dependencias (fuente de verdad en otros dominios, RT-3): Catálogo (productos, tarifas, salidas/cupos, promociones, precios B2B por producto), Reservas (reserva, servicios, pasajeros), Finanzas (CxC, pagos, comisiones, comprobantes), Operaciones (vouchers, asignaciones, incidencias, rooming), Cotizaciones (versiones, aprobación), CRM (clientes), Notificaciones, Auditoría. Este módulo **no** posee esas tablas: invoca sus servicios de dominio.

---

## 2. Pantallas

Las rutas externas se resuelven por la experiencia del rol (`ROLE_UX`, core.js). Para usuarios externos el `clientId`/`agencyId`/`resId` se toma **del token**, nunca de la URL.

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/canales/b2b` → `/admin` (por defecto con permiso) | `(backoffice)/canales/b2b/admin` | KPIs (agencias activas, crédito otorgado/utilizado, ventas B2B, comisiones por liquidar), alerta de agencias con restricción, tabla de agencias (buscar, filtros Estado/Lista, export), drawer de agencia, alta/edición, bloquear/desbloquear, "Ver portal como esta agencia". | `channels.b2b.manage` (Tenant Admin, Manager) |
| `#/canales/b2b/precios` | `(backoffice)/canales/b2b/precios` | Productos con indicador B2B o tarifas B2B asignadas: precio público, tarifa B2B general, asignaciones por agencia; modal "Gestionar" (asignar/actualizar/quitar tarifa por agencia). | `channels.b2b.manage` |
| `#/canales/b2b/portal` (sin sesión simulada) | `(backoffice)/canales/b2b/portal` | Solo interno: login simulado de agencia (elige agencia) para previsualizar el portal. | `channels.b2b.manage` |
| `#/canales/b2b/portal/catalogo` | Externo: `(b2b)/portal/catalogo`; interno: `(backoffice)/canales/b2b/portal/catalogo` | Línea de crédito, avisos de bloqueo/vencimiento, catálogo autorizado con PVP sugerido, neto B2B, comisión, salidas y cupos; modal Reservar. | Usuario B2B (su agencia); interno con `channels.b2b.manage` (previsualización) |
| `#/canales/b2b/portal/reservas` | `(b2b)/portal/reservas` | "Mis reservas" de la agencia; drawer de reserva (servicios, pasajeros, obligaciones, vouchers); abrir voucher. | Idem |
| `#/canales/b2b/portal/cuenta` | `(b2b)/portal/cuenta` | KPIs (facturado, pagado, por pagar, comisiones a favor), estado de cuenta y liquidación por obligación, "Informar pago", "Solicitar liquidación de comisiones", pagos registrados. | Idem |
| `#/canales/ecommerce` → `/gestion` | `(backoffice)/canales/ecommerce/gestion` | KPIs (pedidos, confirmados, ingresos, conversión, rechazos), embudo (sesiones y vistas simuladas), pestañas Pedidos web / Productos publicados / Promociones y códigos. | `channels.ecommerce.manage` |
| `#/canales/ecommerce/tienda` | **No se construye en back-office** → reemplazado por sitio público (espec. 15) | Catálogo público (búsqueda, categoría, orden por precio/destacados, banner CMS). | Hoy: `channels.ecommerce.manage` (vista previa) |
| `#/canales/ecommerce/tienda/producto/{productId}` | Espec. 15 | Detalle de producto (galería, incluye/no incluye, itinerario, requisitos, políticas, FAQ) + configurador "Arma tu reserva". | Idem |
| `#/canales/ecommerce/tienda/checkout` | Espec. 15 | Checkout de 7 pasos con resumen lateral. | Idem |
| `#/canales/cms` | `(backoffice)/canales/cms` | KPIs por estado, flujo editorial, tabla de contenidos (filtros Estado/Tipo), "Nuevo contenido". | `channels.cms.publish` (ver 7 y 12) |
| `#/canales/cms/{contentId}` | `(backoffice)/canales/cms/[id]` | Abre el editor (drawer) del contenido: formulario, SEO, bitácora, acciones por estado, vista previa. | Idem |
| `#/canales/portal-cliente/{clientId}/inicio` | Externo: `(portal-cliente)/inicio`; interno: `(backoffice)/canales/portal-cliente/[clientId]/inicio` | Saludo, KPIs (próximo viaje, cotizaciones por responder, saldo pendiente, documentos), propuestas pendientes, próximo viaje, asesor, últimas notificaciones. | Cliente (propio `clientId`); interno con `channels.b2b.manage` \| `channels.cms.publish` \| `crm.clients.manage` (previsualización; Ventas solo su cartera) |
| `…/{clientId}/cotizaciones` | `(portal-cliente)/cotizaciones` | Lista de cotizaciones no borrador, versión vigente, total, vencimiento. | Idem |
| `…/{clientId}/cotizaciones/{quoteId}` | `(portal-cliente)/cotizaciones/[code]` | Detalle, marca "vista", aprobar (con evidencia) / rechazar (motivo) / solicitar actualización (vencida), PDF. | Idem |
| `…/{clientId}/reservas` y `/reservas/{resId}` | `(portal-cliente)/reservas`, `/reservas/[code]` | Mis viajes (progreso de servicios confirmados), detalle con servicios, pasajeros, vouchers, itinerario PDF. | Idem |
| `…/{clientId}/documentos` | `(portal-cliente)/documentos` | Vouchers, comprobantes, propuestas aprobadas, itinerarios. | Idem |
| `…/{clientId}/pagos` | `(portal-cliente)/pagos` | Obligaciones con saldo, "Pagar en línea" (parcial permitido), historial. | Idem |
| `…/{clientId}/notificaciones` | `(portal-cliente)/notificaciones` | Notificaciones del cliente + eventos de sus cotizaciones. | Idem (no está en la nav externa de `ROLE_UX.customer`, sí en la del portal) |
| `…/{clientId}/soporte` | `(portal-cliente)/soporte` | Crear solicitud (ticket) y ver las propias. | Idem |
| `#/canales/portal-pasajero/viajes` (y login simulado interno) | Externo: `(portal-pasajero)/viajes`; interno: `(backoffice)/canales/portal-pasajero/viajes` | Tarjeta del viaje (día n de N), servicios de hoy, próximo servicio, accesos rápidos, emergencias. | Pasajero (su reserva); interno con `channels.b2b.manage` \| `channels.cms.publish` \| `operations.assign` |
| `…/portal-pasajero/itinerario` | `(portal-pasajero)/itinerario` | Día a día con estado por servicio y asignaciones; descarga itinerario. | Idem |
| `…/hoteles` · `/transporte` · `/actividades` | `(portal-pasajero)/hoteles` etc. | Hotel (dirección, check-in/out, habitación, confirmación, voucher); traslados con vehículo/conductor; actividades con guía y requisitos. | Idem |
| `…/vouchers` · `/documentos` | `(portal-pasajero)/vouchers`, `/documentos` | Vouchers (última versión); itinerario, certificado de asistencia, comprobantes, política de cancelación (CMS-05). | Idem |
| `…/alertas` | `(portal-pasajero)/alertas` | Alertas activas (incidencias de Transporte/Horario abiertas) con "Confirmar lectura"; mensajes recibidos. | Idem |
| `…/contacto` | `(portal-pasajero)/contacto` | Emergencias 24/7 (copiar), contactos (coordinadora, guía próximo), mensaje a operaciones (crea ticket). | Idem |

Rutas de "Vista del canal": para usuarios internos cada portal se muestra envuelto en una barra "Vista del canal · …" + marco del portal; para externos se muestra solo el cuerpo (el shell externo aporta la navegación).

---

### 2.1 v2.1 — Portales del pasajero y del cliente
| Ruta prototipo | Ruta Next.js | Qué hace | Regla | Roles |
|---|---|---|---|---|
| `#/canales/portal-pasajero/mensajes` | `/(portal-pasajero)/mensajes` | Bandeja de `paxMessages` enviados/entregados/leídos de su reserva: asunto, canal, fecha, estado; filtro por canal; abrir = marcar leído (`readAt`); «Marcar todos como leídos» | R-PAX-MESSAGES | passenger (su reserva), internos con previsualización |
| `#/canales/portal-pasajero/comentarios` | `/(portal-pasajero)/comentarios` | Formulario: tipo (comentario, sugerencia, felicitación, reclamo, incidencia), servicio relacionado opcional, calificación 1–5 opcional, texto (≤ 1000). Historial con estado y respuesta de la agencia. Reclamo → sugiere Libro de Reclamaciones | R-PAX-FEEDBACK | ídem |
| `#/canales/portal-pasajero/acompanantes` | `/(portal-pasajero)/acompanantes` | Solo si R-COMPANIONS `when = antes_de_viaje`: completar/editar nombre, parentesco, documento, nacimiento, nacionalidad y dieta de acompañantes, y punto de recojo/vuelo (R-PICKUP) hasta `travelFrom − daysBeforeIfLater` | R-COMPANIONS, R-PICKUP | ídem |
| `#/canales/portal-cliente/{cli}/reservas/{res}` | `/(portal-cliente)/viajes/[code]` | «Resumen de su reserva» (alojamiento por ciudad con totales de habitaciones/noches, adicionales, recojo, comentarios) y «Plan de pago» (total, adelanto, saldo, vencimiento, CxC abierta con **Pagar saldo**); aviso «Completar acompañantes» si faltan datos | R-LODGING, R-ADDONS, R-PICKUP, R-COMMENTS, R-DEPOSIT, R-MULTICURRENCY, R-COMPANIONS | customer (su cliente), internos |

El shell externo del pasajero tiene navegación fija (core); las secciones v2.1 aparecen como sub-navegación dentro del portal y como accesos rápidos en «Mi viaje». En la vista interna, el login de pasajero acepta `RES-2026-000347` y cualquier reserva con `booking` (Ventas solo de su cartera).

## 3. Modelo de datos PostgreSQL

Convenciones comunes (no se repiten en cada tabla): `id uuid PK` (UUID v7), `tenant_id uuid not null FK tenants(id)` + RLS `tenant_isolation`, `created_at/updated_at timestamptz not null`, `created_by/updated_by uuid null FK iam_users(id)`, índice en toda FK. Dinero `numeric(14,2)` + `currency char(3)`.

### 3.1 `channel_b2b_agencies` (agencia asociada)

| columna | tipo | null | notas/FK/índice |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| code | varchar(30) | no | `AGB-01`; `UNIQUE(tenant_id, code)`; `sys_sequences` |
| legal_name | varchar(200) | no | Razón social (`name`) |
| ruc | char(11) | no | `CHECK (ruc ~ '^\d{11}$')`; `UNIQUE(tenant_id, ruc) WHERE deleted_at IS NULL` |
| contact_name | varchar(150) | no | `contact` |
| booking_email | varchar(190) | sí | `email`; destino de notificaciones de la agencia |
| crm_client_id | uuid | sí | FK `crm_clients(id)`; cliente CRM titular de reservas y CxC (`clientId`) |
| price_list_id | uuid | no | FK `channel_b2b_price_lists(id)` (prototipo: texto 'Mayorista 2026' \| 'Minorista 2026' \| 'Corporativo 2026') |
| commission_pct | numeric(5,2) | no | Comisión base; `CHECK (commission_pct BETWEEN 0 AND 30)` |
| credit_currency | char(3) | no | Moneda de la línea (PEN/USD) |
| credit_limit | numeric(14,2) | no | default 0; `CHECK (credit_limit >= 0)`; 0 = solo prepago |
| credit_used | numeric(14,2) | no | default 0; **saldo derivado** del libro `channel_b2b_credit_movements` (ver B2B-BR-12); `CHECK (credit_used >= 0)` |
| valid_from | date | no | `since` |
| valid_to | date | no | `CHECK (valid_to >= valid_from)` |
| status | varchar(30) | no | `CHECK (status IN ('activa','bloqueada','inactiva'))`. `vencido` es **derivado** (valid_to < hoy), no se persiste |
| block_reason | varchar(30) | sí | `CHECK (block_reason IN ('manual','credito_excedido'))` |
| lock_version | integer | no | default 0 (crédito concurrente) |
| deleted_at | timestamptz | sí | SoftDeletes |

Índices: `(tenant_id, status)`, `(tenant_id, price_list_id)`, `(tenant_id, crm_client_id)`.

### 3.2 `channel_b2b_price_lists`

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| name | varchar(80) | no | `UNIQUE(tenant_id, name)` |
| year | smallint | sí | — |
| status | varchar(30) | no | `CHECK (status IN ('activa','inactiva'))` |

### 3.3 `catalog_product_b2b_rates` (tarifa neta por agencia y producto — tabla del dominio Catálogo, PRD §24/§33; usada aquí)

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| product_id | uuid | no | FK `catalog_products(id)` |
| agency_id | uuid | no | FK `channel_b2b_agencies(id)`; `UNIQUE(tenant_id, product_id, agency_id) WHERE deleted_at IS NULL` |
| price_list_id | uuid | no | FK; se copia de la agencia al asignar |
| net_price | numeric(14,2) | no | Precio neto B2B por pasajero; `CHECK (net_price >= 0)` |
| currency | char(3) | no | Moneda de la tarifa B2B general o de la pública (prototipo: `b2bTerms.cur`) |
| commission_pct | numeric(5,2) | no | `CHECK (commission_pct >= 0)` |
| credit_allowed | boolean | no | default true (prototipo `credit`) |
| valid_to | date | sí | null = según vigencia del producto/tarifa |
| conditions | varchar(300) | sí | Condición comercial (Catálogo) |
| restrictions | varchar(300) | sí | Restricciones (Catálogo) |
| deleted_at | timestamptz | sí | "Quitar" = soft delete |

Índice `(tenant_id, agency_id)`.

### 3.4 `channel_b2b_credit_movements` (libro de la línea de crédito — agregado)

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| agency_id | uuid | no | FK |
| movement_type | varchar(30) | no | `CHECK (movement_type IN ('cargo_reserva','pago_aplicado','reversion_pago','anulacion_reserva','ajuste'))` |
| reservation_id | uuid | sí | FK `booking_reservations(id)` |
| payment_id | uuid | sí | FK `fin_payments(id)` |
| amount | numeric(14,2) | no | En `credit_currency`; positivo consume, negativo libera |
| currency | char(3) | no | — |
| source_amount / source_currency / fx_rate | numeric(14,2) / char(3) / numeric(12,6) | sí | Monto original y TC usado (RT-15) |
| balance_after | numeric(14,2) | no | `credit_used` resultante |
| note | varchar(250) | sí | — |

Índice `(tenant_id, agency_id, created_at desc)`. Inmutable (sin update/delete).

### 3.5 `channel_b2b_settlement_requests` (solicitud de liquidación de comisiones — agregado)

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| code | varchar(30) | no | `LIQ-2026-00001`; `UNIQUE(tenant_id, code)` |
| agency_id | uuid | no | FK |
| amount_pen | numeric(14,2) | no | Consolidado en PEN (TC del día) |
| fx_rate | numeric(12,6) | no | — |
| commission_ids | uuid[] | no | Comisiones incluidas (no pagadas) |
| status | varchar(30) | no | `CHECK (status IN ('solicitada','aprobada','rechazada','pagada'))` |
| requested_by | uuid | no | FK iam_users |

### 3.6 `channel_ecom_orders` (pedido web)

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| code | varchar(30) | no | `WEB-10434`; `UNIQUE(tenant_id, code)` |
| ordered_at | timestamptz | no | `date` |
| crm_client_id | uuid | no | FK `crm_clients(id)` (creado o reutilizado por email) |
| customer_name | varchar(150) | no | `customer` |
| customer_email | varchar(190) | no | `email` |
| customer_phone | varchar(40) | sí | — |
| customer_country | varchar(60) | sí | — |
| product_id | uuid | no | FK `catalog_products(id)` |
| departure_id | uuid | sí | FK `catalog_product_departures(id)`; null = producto bajo solicitud |
| service_date | date | no | Fecha de la salida o elegida |
| pax_adults | smallint | no | `CHECK (pax_adults >= 1)` |
| pax_children | smallint | no | default 0 |
| pax_total | smallint | no | generado `pax_adults + pax_children` |
| config_snapshot | jsonb | no | `[{group, option, delta_pp}]` |
| price_snapshot | jsonb | no | `{rate_id, channel, adult_unit, child_unit, delta_pp, subtotal, discount, discount_label, total}` (PRD-BR-009) |
| promo_code | varchar(40) | sí | Código aplicado |
| promotion_id | uuid | sí | FK `catalog_product_promotions(id)` |
| discount_amount | numeric(14,2) | no | default 0 |
| total | numeric(14,2) | no | — |
| currency | char(3) | no | — |
| cost_total | numeric(14,2) | no | Costo tarifa × pax (no se expone al comprador) |
| reservation_id | uuid | sí | FK `booking_reservations(id)`; `UNIQUE` |
| receivable_id | uuid | sí | FK `fin_receivables(id)` |
| status | varchar(30) | no | `CHECK (status IN ('pago_pendiente','pago_rechazado','confirmado','cancelado','expirado'))` (`expirado` agregado, ver ECO-BR-11) |
| hold_expires_at | timestamptz | sí | `ordered_at + tenant.settings.hold_minutes` |
| cancel_reason | varchar(300) | sí | — |
| buyer_snapshot | jsonb | no | Titular: nombre, tipo/número de documento, email, celular, país |
| utm | jsonb | sí | Atribución (espec. 15) |
| lock_version | integer | no | default 0 |

Índices: `(tenant_id, status)`, `(tenant_id, ordered_at desc)`, `(tenant_id, customer_email)`, parcial `(hold_expires_at) WHERE status IN ('pago_pendiente','pago_rechazado')`.

### 3.7 `channel_ecom_passengers`

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| order_id | uuid | no | FK `channel_ecom_orders(id)` ON DELETE RESTRICT |
| position | smallint | no | 1 = titular |
| full_name | varchar(150) | no | — |
| doc_type | varchar(20) | sí | DNI \| Pasaporte \| Carné de extranjería (solo titular en el prototipo) |
| doc_number | varchar(30) | no | — |
| pax_type | char(3) | no | `CHECK (pax_type IN ('ADT','CHD'))` |

### 3.8 `channel_ecom_payment_attempts`

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| order_id | uuid | no | FK |
| attempted_at | timestamptz | no | `ts` |
| card_masked | varchar(20) | no | `•••• 1111` (nunca PAN completo ni CVV; tokenizado en la pasarela) |
| result | varchar(20) | no | `CHECK (result IN ('aprobado','rechazado'))` |
| gateway_code | varchar(20) | sí | p. ej. `51` |
| message | varchar(200) | sí | "Fondos insuficientes (código 51)" / "Autorización 123456" |
| gateway_txn_id | varchar(80) | sí | `UNIQUE(tenant_id, gateway_txn_id)` (idempotencia de webhook) |
| payment_id | uuid | sí | FK `fin_payments(id)` |

### 3.9 `catalog_product_promotions` (dominio Catálogo, PRD §25; usada por e-commerce)

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| code_internal | varchar(30) | no | `PROMO-03`; `UNIQUE(tenant_id, code_internal)` |
| product_id | uuid | no | FK |
| name | varchar(120) | no | — |
| kind | varchar(40) | no | E-commerce crea solo `'Descuento %'` (con código) o `'Descuento por cantidad'` (sin código); Catálogo admite el resto de PRD §25 |
| value | numeric(12,2) | no | % en los tipos porcentuales; `CHECK (value > 0)`; e-commerce valida 1–50 |
| channel | varchar(30) | no | `CHECK (channel IN ('Todos','Directo','E-commerce','B2B','Corporativo'))` |
| promo_code | varchar(40) | sí | Mayúsculas sin espacios; `UNIQUE(tenant_id, promo_code) WHERE promo_code IS NOT NULL AND deleted_at IS NULL` |
| min_pax | smallint | sí | Para "Descuento por cantidad" (prototipo fijo: 4) |
| valid_from / valid_to | date | no | `CHECK (valid_to >= valid_from)` |
| status | varchar(30) | no | `CHECK (status IN ('activa','inactiva'))`; `vencida` derivado |
| deleted_at | timestamptz | sí | — |

### 3.10 `cms_contents`

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| code | varchar(30) | no | `CMS-07`; `UNIQUE(tenant_id, code)` |
| title | varchar(200) | no | — |
| type | varchar(30) | no | `CHECK (type IN ('Página','Artículo','Banner','Página legal','Landing'))` |
| channel | varchar(40) | no | `CHECK (channel IN ('Web','Web / E-commerce','Web / Portal','Portal cliente','Portal pasajero','App pasajero'))` (ver 12: normalizar a tabla N:M) |
| slug | varchar(60) | no | `UNIQUE(tenant_id, slug) WHERE deleted_at IS NULL`; generado del título si vacío |
| body | text | no | Markdown restringido (`## `, `- `); sanitizar al renderizar |
| seo_title | varchar(60) | sí | Obligatorio para publicar |
| seo_description | varchar(160) | sí | — |
| status | varchar(30) | no | `CHECK (status IN ('borrador','en_revision','aprobado','publicado','despublicado'))` |
| author_id | uuid | no | FK iam_users |
| published_at / unpublished_at | timestamptz | sí | — |
| placement | varchar(40) | sí | Agregado: ubicación del banner (`home_hero`, `shop_top`…) para que el sitio lo consuma sin IDs fijos |
| valid_from / valid_to | date | sí | Agregado: vigencia de banners promocionales |
| lock_version | integer | no | default 0 |
| deleted_at | timestamptz | sí | — |

Índices `(tenant_id, status)`, `(tenant_id, type)`, `(tenant_id, updated_at desc)`.

### 3.11 `cms_content_events` (bitácora editorial)

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| content_id | uuid | no | FK |
| occurred_at | timestamptz | no | `ts` |
| user_id | uuid | no | `u` |
| from_status / to_status | varchar(30) | sí | null en ediciones |
| message | varchar(300) | no | `m` ("Enviado a revisión", "Devuelto a borrador — motivo"…) |
| reason | varchar(300) | sí | Obligatorio en devolver/despublicar |

### 3.12 `channel_support_tickets` (solicitudes desde portales)

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| code | varchar(30) | no | `TCK-0001`; `UNIQUE(tenant_id, code)` |
| crm_client_id | uuid | no | FK |
| reservation_id | uuid | sí | FK |
| source | varchar(30) | no | `CHECK (source IN ('portal_cliente','portal_pasajero'))` |
| subject | varchar(200) | no | — |
| category | varchar(40) | no | Cliente: Cambio de fecha, Documentos, Facturación, Pagos, Cotizaciones, Reclamo, Consulta general. Pasajero: `Operaciones` |
| topic | varchar(40) | sí | Pasajero: Cambio de horario, Recojo en hotel, Salud, Objetos perdidos, Otro |
| priority | varchar(10) | no | `CHECK (priority IN ('baja','media','alta'))` |
| message | text | no | — |
| status | varchar(30) | no | `CHECK (status IN ('abierta','en_proceso','resuelta','cerrada'))` (prototipo solo crea `abierta`) |
| owner_id | uuid | no | Asesor del cliente (`cl.owner`) o coordinador de la operación (default U05 en prototipo) |
| created_by_user_id | uuid | no | Usuario externo |
| first_response_due_at | timestamptz | sí | SLA 4 h hábiles (texto del portal) |

`channel_support_ticket_events` (ticket_id, occurred_at, user_id, message) para el `log`. Si el módulo Colaboración/Inbox gestiona tickets, estas tablas pasan a `collab_` (ver 12).

### 3.13 `channel_portal_access_links` (acceso de pasajero por enlace/código — agregado)

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| reservation_id | uuid | no | FK |
| passenger_id | uuid | sí | FK `booking_passengers(id)` |
| email | varchar(190) | no | Debe coincidir con el email del cliente titular (prototipo) o del pasajero |
| token_hash | char(64) | no | SHA-256 del token del enlace; `UNIQUE` |
| expires_at | timestamptz | no | — |
| used_at | timestamptz | sí | — |

### 3.14 `ops_incident_pax_acks` (confirmación de lectura de alertas — agregado)

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | — |
| incident_id | uuid | no | FK `ops_incidents(id)` |
| user_id | uuid | no | Pasajero que confirma |
| acknowledged_at | timestamptz | no | — |
| | | | `UNIQUE(incident_id, user_id)` |

### 3.15 Columnas requeridas en tablas de otros dominios (coordinar con sus especificaciones)

| Tabla (dominio) | Columnas que este módulo escribe/lee |
|---|---|
| `booking_reservations` | `channel varchar(30) CHECK IN ('Directo','B2B','E-commerce','Corporativo',…)`, `agency_id uuid null FK`, `ecom_order_id uuid null FK`, `b2b_terms jsonb null` (`{price_list, commission_pct, pay_mode:'credito'\|'prepago', agency_ref}`), `quote_id/quote_version null` (canales crea reservas sin cotización), `owner_id` (ver 12: owner por canal), `cancel_reason` |
| `booking_reservation_services` | `departure_id uuid null FK` (hoy el prototipo lo codifica en el nombre "(salida SAL-0101)"; **usar FK**), `confirmation_code`, `status`, `cost`, `price` |
| `catalog_product_departures` | `capacity`, `sold`, `status CHECK IN ('abierta','completa','cerrada')`, `lock_version`, `meeting_point`, `time` |
| `catalog_products` | `is_b2b`, `is_ecommerce`, `is_featured`, `status`, `advance_min_days`, `advance_max_days`, `min_pax`, `max_pax`, `min_age`, `availability_model`, `seo_*`, `slug`, `faqs`; `catalog_product_channels` (publicación por canal) |
| `fin_receivables` | `concept`, `amount`, `paid`, `due_date`, `status CHECK IN ('abierta','parcial','pagada','vencida','cancelada')`, `crm_client_id`, `reservation_id` |
| `fin_payments` | `receivable_id`, `reservation_id`, `method` ('Tarjeta (e-commerce)', 'Tarjeta (portal cliente)', transferencias informadas), `status CHECK IN ('pendiente','rechazado','confirmado','aplicado','revertido')`, `reference`, `reported_by_agency boolean` |
| `fin_commissions` | `beneficiary_type ('agencia_b2b','vendedor')`, `beneficiary_id`, `base`, `pct`, `amount`, `currency`, `status CHECK IN ('calculada','aprobada','pagada')`, `settlement_request_id null` |
| `ops_vouchers` | Emisión v1 desde canales (`issued_by` = usuario o `Sistema`) |
| `ops_incidents` | `public_title`, `public_message` (agregado: texto para pasajero, hoy hard-coded en `paxAlertText`), `type`, `status` |
| `quote_quotes` / `quote_versions` / `quote_approvals` | `viewed_at`; `quote_approvals`: `quote_id, version, approved_at, approver_name, channel ('Portal cliente'), ip inet, token, total, currency, note, evidence_hash char(71) 'sha256:…'`; rechazo: `rejected_at`, `rejected_reason` |
| `iam_users` | `agency_id`, `crm_client_id`, `reservation_id` (pasajero) — vínculo del usuario externo (Auth.agencyId/clientId/resId) |
| `tenants.settings` | `hold_minutes` (30), `fx` (3.74), `emergency_phone`, `b2b_credit_days` (15), `web_default_owner_id` |

---

### 3.16 v2.1 — `channel_pax_feedback` (comentarios del pasajero)
Misma definición que `booking_pax_feedback` en 04-reservas (tabla única; dueño Canales). Campos del alta desde portal/app: `type, rating, reservation_service_id, text, channel ('Portal pasajero'|'App pasajero'), contact_email, contact_phone`. Si `type IN ('reclamo','incidencia')` se crea `collab_conversations` (canal `Portal`, SLA 240 min, prioridad alta para incidencia, `assignee` = coordinador de la orden para incidencia o asesor de la reserva para reclamo, `feedback_id`).
`notif_pax_messages` (dueño experiencia/comunicaciones) — el portal solo actualiza `status='leido'`, `read_at`.

## 4. Máquinas de estado

### 4.1 Agencia B2B (`status` persistido + estado efectivo derivado)
Estado efectivo (`agStatus`): `vencido` si `valid_to < hoy`; si no, `bloqueada` si `credit_limit > 0 AND credit_used > credit_limit`; si no, `status`.

| De | A | Disparador | Condición | Efectos |
|---|---|---|---|---|
| — | `activa` \| `bloqueada` \| `inactiva` | Alta (`channels.b2b.manage`) | RUC 11 díg., vigencia válida | Si `credit_used > credit_limit > 0` → `bloqueada` automático. Audit `crear` |
| cualquiera | cualquiera | Editar (select Estado) | Idem | Audit `editar`; bloqueo automático si excede |
| `activa` (efectivo) | `bloqueada` | "Bloquear" | Motivo obligatorio | Audit `bloquear`; email a la agencia (`b2b.estado`) |
| `bloqueada` | `activa` | "Desbloquear" | **Prohibido** si `credit_used > credit_limit` (B2B-BR-07) | Audit `desbloquear`; email |
| — | `bloqueada` (efectivo) | Sistema | `credit_used > credit_limit` | Crédito deshabilitado, prepago permitido |
| — | `vencido` (efectivo) | Tiempo | `valid_to < hoy` | No puede reservar (ni prepago) |

### 4.2 Pedido web (`channel_ecom_orders.status`)

| De | A | Disparador | Efectos |
|---|---|---|---|
| — | `pago_pendiente` | Primer "Pagar" del checkout (pedido se crea justo antes del cargo) | Crea/reutiliza cliente, crea reserva `pendiente_pago`, consume cupos (hold), CxC con vencimiento hoy. Audit `crear`, `crear_ecommerce`, `retener_cupos` |
| `pago_pendiente` \| `pago_rechazado` | `pago_rechazado` | Cargo rechazado | Intento `rechazado`, `fin_payments` `rechazado`; cupos y obligación se mantienen; email `pago.rechazado` |
| `pago_pendiente` \| `pago_rechazado` | `confirmado` | Cargo aprobado (webhook) | Pago `aplicado`, CxC `pagada`, reserva `confirmada` (con salida) o `pendiente_proveedor` (bajo solicitud), servicios `confirmado`+código / `solicitado`, voucher v1 si confirmada; emails |
| `pago_pendiente` \| `pago_rechazado` | `cancelado` | "Anular pedido" (`channels.ecommerce.manage`, motivo) | Libera cupos, reserva `cancelada` + servicios `cancelado`, CxC sin pagos → `cancelada`. Audit `anular` |
| `pago_pendiente` \| `pago_rechazado` | `expirado` | **Agregado**: job al vencer `hold_expires_at` | Igual que anular con motivo "Hold expirado" |
| `confirmado`, `cancelado`, `expirado` | — | Terminales en este módulo (cancelación posterior = PROC-028 en Reservas) | — |

### 4.3 Contenido CMS

| De | A | Quién | Condición | Efectos |
|---|---|---|---|---|
| — (nuevo) | `borrador` | "Guardar" | Título; slug único | Evento "Contenido creado"; audit `crear` |
| — (nuevo) \| `borrador` \| `despublicado` | `en_revision` | "Enviar a revisión" (cualquier usuario con acceso) | Título; slug único | Guarda cambios; notifica a gerente (`cms.revision`) |
| `en_revision` | `borrador` | "Devolver a borrador" (`channels.cms.publish`) | Motivo obligatorio | Notifica al autor (`cms.devuelto`); **no guarda** ediciones pendientes del formulario |
| `en_revision` | `aprobado` | "Aprobar" (`channels.cms.publish`) | — | Guarda cambios |
| `aprobado` | `publicado` | "Publicar" (`channels.cms.publish`) | `seo_title` no vacío | Notifica al autor (`cms.publicado`); visible en el canal |
| `publicado` | `despublicado` | "Despublicar" (`channels.cms.publish`) | Motivo obligatorio | Deja de mostrarse |
| misma | misma | "Guardar" (edición) | Permitido si `canPub` o estado ∈ {borrador, despublicado}. En `publicado` sin `canPub` → bloqueo | Audit `editar`; en `publicado` el cambio es inmediato |

No hay transición `aprobado → borrador` ni `aprobado → en_revision` en el prototipo (ver 12).

### 4.4 Cotización desde el portal del cliente (subconjunto de la máquina de Cotizaciones)

| De | A | Disparador | Condición | Efectos |
|---|---|---|---|---|
| `enviada` | `vista` | Cliente abre el detalle | No vencida | `viewed_at`; audit `vista_portal`; notifica al asesor (`cotizacion.vista`) |
| `enviada` \| `vista` \| `negociacion` | `aprobada` | "Aprobar propuesta" | No vencida (PCL-BR-03), nombre, aceptación de condiciones | Versión vigente `aprobada` (inmutable, RT-7), `quote_approvals` con evidencia; notifica asesor y email al cliente |
| `enviada` \| `vista` \| `negociacion` | `rechazada` | "Rechazar" | Motivo + comentario | Versión `rechazada`; notifica asesor |
| vencida (derivada) | — | "Solicitar actualización" | — | Crea ticket categoría Cotizaciones |

Vencida (`qExpired`): `status='vencida'` o (`valid_until < hoy` y estado ∉ {aprobada, convertida, rechazada, cancelada}).

### 4.5 Reserva creada por canales

| Origen | Estado inicial | Transición en este módulo |
|---|---|---|
| B2B crédito + producto con salida | `confirmada` (servicio `confirmado`, código `AH-xxxxxx`, voucher v1) | — |
| B2B crédito + bajo solicitud | `pendiente_proveedor` (servicio `solicitado`) | — |
| B2B prepago | `pendiente_pago` (servicio `solicitado`) | Confirmación posterior en Reservas/Finanzas |
| E-commerce | `pendiente_pago` | → `confirmada` / `pendiente_proveedor` al aprobar pago; → `cancelada` al anular/expirar pedido |

### 4.6 Salida (cupo)
`abierta` → `completa` cuando `sold >= capacity` al consumir; `completa` → `abierta` al liberar si `sold < capacity`.

### 4.7 Otros
- **Pago informado por agencia**: se crea `fin_payments.status='pendiente'` (se muestra "Por validar"); la validación/aplicación la hace Finanzas (PROC-042/049).
- **Pago en portal cliente**: `rechazado` o `aplicado` directo; CxC → `pagada` si `paid >= amount`, `vencida` si `due < hoy`, si no `parcial`.
- **Promoción**: `activa` ⇄ `inactiva` (botón); `vencida` derivada si `valid_to < hoy`.
- **Tarifa B2B por agencia**: asignada ⇄ quitada (soft delete); vencida derivada por `valid_to`.
- **Ticket**: prototipo solo `abierta` (resto agregado).
- **Alerta pasajero**: no leída → leída (`ops_incident_pax_acks`).

---

## 5. Reglas de negocio

### 5.1 B2B (PROC-053)

| ID | Regla (comportamiento del prototipo) | Dónde se valida |
|---|---|---|
| RT-14 / PRD-BR-016 | B2B por agencia/producto/vigencia: una agencia solo ve y reserva productos con tarifa asignada a ella y no vencida. Mensajes: "Sin tarifa B2B asignada a su lista de precios (Regla 14…)" / "Tarifa B2B vencida el dd mmm aaaa (Regla 14)". El botón Reservar queda deshabilitado. | Service `B2bTermsResolver` + Policy |
| B2B-BR-01 | Catálogo autorizado = productos `status='ACTIVE'` **y** `is_b2b=true` (agregado: y canal B2B publicado en `catalog_product_channels`, como exige Catálogo). Orden: primero los reservables. | Query/Service |
| B2B-BR-02 | Agencia `vencido` o `inactiva` no puede reservar (ni prepago): "Regla 14: convenio vencido/inactiva. No es posible reservar." Login de agencia `inactiva` denegado. | Policy `reserve` + Auth |
| B2B-BR-03 | Crédito solo si: tarifa `credit_allowed` **y** `credit_limit > 0` **y** estado efectivo `activa`. Si no, única forma de pago = prepago. | FormRequest + Service |
| B2B-BR-04 | Reserva a crédito rechazada si el total (convertido a PEN) > crédito disponible: "Crédito insuficiente… el sistema bloquea la reserva a crédito" + opción "Cambiar a prepago". Se audita `credito_excedido` aun sin crear reserva. | Service (con `SELECT … FOR UPDATE` sobre la agencia) |
| B2B-BR-05 | Pasajeros entre `min_pax` y `max_pax` del producto. | FormRequest |
| B2B-BR-06 | Anticipación mínima `advance_min_days`: salida o fecha con menos días → rechazo. Sin salidas: fecha mínima = hoy + advMin (sugerida hoy + max(advMin,7)). | FormRequest + Service |
| RT-4 / PRD-BR-012 | Sin disponibilidad no se confirma: si la salida tiene menos cupos libres que pax → "Sin disponibilidad… Elija otra salida." | Service + lock Redis de salida |
| B2B-BR-07 | No se puede desbloquear una agencia cuyo crédito utilizado supera el límite: "Amplíe el límite o registre pagos (Regla 14)". | Service |
| B2B-BR-08 | Bloqueo automático al guardar agencia si `credit_limit > 0` y `credit_used > credit_limit` (toast de advertencia). | Service (observer) |
| B2B-BR-09 | Alta/edición: razón social, RUC (11 dígitos), contacto, comisión (0–30), vigencias obligatorias; `valid_to >= valid_from`. | FormRequest |
| B2B-BR-10 | Tarifa B2B por agencia: precio neto ≤ precio público vigente ("El precio B2B no puede superar el precio público"); al asignar se marca el producto `is_b2b=true`. Agregado para unificar con Catálogo: precio neto ≥ costo (bloquea), crédito permitido solo si la agencia tiene línea > 0, no asignable a agencias no activas. | FormRequest + Service (misma regla en Catálogo y Canales) |
| B2B-BR-11 | Estado inicial de la reserva: crédito+salida → `confirmada` + voucher v1; crédito sin salida → `pendiente_proveedor`; prepago → `pendiente_pago` ("Prepago requerido antes de emitir voucher"). | Service |
| B2B-BR-12 | Efectos de la reserva: CxC por el total neto (crédito: vence hoy + 15 d; prepago: hoy + 1 d); comisión `calculada` = total × comisión% (2 decimales); si crédito, `credit_used += total en PEN`. **Precisado**: `credit_used` se mueve solo vía `channel_b2b_credit_movements` (cargo al reservar, liberación al aplicar pago o anular) — evita el doble conteo actual con el método de pago "Crédito B2B" de Finanzas. | Service + listener `PaymentApplied` |
| B2B-BR-13 | Nombres de pasajeros opcionales (una línea "Nombre — Documento"); faltantes quedan "Pasajero n (por nominar)" / "Por registrar". Nominación completa exigible antes del voucher en productos nominativos (ver 12). | Service |
| B2B-BR-14 | "Informar pago": monto > 0 y ≤ saldo; N.° de operación obligatorio; crea pago `pendiente` (por validar) y notifica a Finanzas. El crédito se libera al **aplicar** el pago (Finanzas). | FormRequest + Service |
| B2B-BR-15 | "Solicitar liquidación de comisiones": solo si hay comisiones no pagadas ("No hay comisiones pendientes de liquidar"). Crea solicitud (agregado) y notifica a Finanzas. | Service |
| B2B-BR-16 | La agencia solo ve reservas `channel='B2B'` de su agencia (`agency_id`, o `crm_client_id` de la agencia) y sus CxC/pagos/comisiones. Ve "Total neto", su referencia; no ve notas internas, costos ni márgenes. | Policy + Resource |
| B2B-BR-17 | Usuario B2B sin agencia vinculada → "Su usuario no está vinculado a una agencia con convenio." | Middleware |
| RT-11 | Voucher solo con servicio confirmado: prepago muestra "Tras el pago". | Service Operaciones |

### 5.2 E-commerce (PROC-054)

| ID | Regla | Dónde |
|---|---|---|
| ECO-BR-01 | Publicar en tienda solo si producto `ACTIVE` ("Solo productos en estado ACTIVE pueden publicarse… Regla transversal 18") y con tarifa web o pública vigente ("El producto no tiene tarifa vigente"). Publicar/despublicar actualiza `is_ecommerce` y el canal `ecommerce`. | FormRequest + Service |
| ECO-BR-02 | Catálogo público = `ACTIVE` ∧ `is_ecommerce` ∧ tarifa vigente. Detalle de producto no publicado → "Este producto no está disponible para la venta en línea." | Query público |
| ECO-BR-03 | Precio adulto: tarifa vigente canal `E-commerce` segmento Adulto/Pasajero; si no existe, tarifa pública `Directo` (Adulto/Pasajero → cualquiera Directo → primera vigente). Precio niño: tarifa `Niño`/`Directo`; si no existe, precio adulto. Se muestra precio tachado si la pública > web. | `PricingService` (PRD-BR-010, RT-5) |
| ECO-BR-04 | Personalización: cada opción suma `delta` por persona (adulto y niño); opción por defecto = la de delta 0. | PricingService |
| ECO-BR-05 | Promociones aplicables: `activa`, vigentes hoy, canal `E-commerce` o `Todos`. Código (case-insensitive) inválido → "Código no válido… o fuera de vigencia" y **bloquea continuar** en el paso Precio. Automática "Descuento por cantidad" (sin código) cuando pax ≥ 4. **No acumulables**: se aplica el mayor descuento. Descuento = subtotal × %; total redondeado a 2 decimales. | PricingService |
| ECO-BR-06 | Pasajeros: adultos ≥ 1; total ≤ `max_pax` ("Máximo n pasajeros por compra"); al continuar desde Configuración, total entre `min_pax` y `max_pax`. Niños solo si existe tarifa niño o `min_age < 12` (rango "2–11" o "minAge–11"). | FormRequest |
| ECO-BR-07 | Disponibilidad con salidas: salida obligatoria; no agotada; cumple anticipación mínima ("Venta cerrada" en el selector); cupos ≥ pax (RT-4). Sin salidas: fecha obligatoria entre hoy+advMin y hoy+advMax; bajo solicitud ("confirmación del proveedor en máximo 24 h; el cobro se realiza ahora y se reembolsa si no hay confirmación"). | Service |
| ECO-BR-08 | Datos del comprador: nombre, documento, número, email válido (`^[^@\s]+@[^@\s]+\.[a-z]{2,}$`), celular, país; acompañantes con nombre y documento obligatorios. Tratamiento Ley 29733. | FormRequest |
| ECO-BR-09 | Revalidación de disponibilidad al pagar si el pedido aún no existe; si falla → vuelve al paso Disponibilidad. | Service |
| ECO-BR-10 | Pedido creado al primer intento de pago: cliente por email (reutiliza si existe; si no crea Persona, segmento Retail, tags Web/E-commerce), reserva `pendiente_pago` (canal E-commerce), CxC vence hoy, consumo de cupos. | Service (transacción + idempotencia) |
| ECO-BR-11 | Hold: los cupos quedan retenidos `hold_minutes` (30). **Agregado**: al expirar sin pago aprobado, el pedido pasa a `expirado` y se liberan cupos/reserva/CxC (el prototipo lo anuncia pero no lo ejecuta). | Job `ExpireEcomHolds` |
| ECO-BR-12 | Pago rechazado: la obligación y los cupos se mantienen; se puede reintentar con otra tarjeta; desde "Pago" no se puede volver atrás si el pedido existe ("complete el pago o anúlelo desde gestión"). | Service |
| ECO-BR-13 | Pago aprobado: pago `aplicado` por el total, CxC `pagada`, reserva/servicios según ECO-BR (4.2), voucher v1 solo si hay salida confirmada (RT-11). | Service (webhook idempotente) |
| ECO-BR-14 | Anular pedido solo en `pago_pendiente`/`pago_rechazado`, motivo obligatorio; libera cupos, cancela reserva y CxC sin pagos. Enviar enlace de pago solo en esos estados. | Policy + Service |
| ECO-BR-15 | Promoción nueva: producto ACTIVE, nombre, % 1–50, vigencia `hasta ≥ desde`, código único en el tenant (mayúsculas, sin espacios); con código → tipo "Descuento %", sin código → "Descuento por cantidad". | FormRequest |
| ECO-BR-16 | Nunca se almacena PAN/CVV; solo últimos 4 dígitos y token/ID de la pasarela (INT-02 por definir). | Adapter de pagos |

### 5.3 CMS (PROC-055)

| ID | Regla | Dónde |
|---|---|---|
| CMS-BR-01 | Aprobar, publicar, despublicar y devolver requieren `channels.cms.publish` ("solo puede redactar y enviar a revisión"). | Policy |
| CMS-BR-02 | Editar un contenido `publicado` requiere `channels.cms.publish` ("Solicite a un editor que lo despublique"). En `en_revision`/`aprobado` sin permiso no hay botón Guardar. | Policy |
| CMS-BR-03 | Slug único por tenant ("La URL /x ya está en uso"); si vacío se genera del título (minúsculas, sin tildes, `-`, máx. 60). | FormRequest + UNIQUE |
| CMS-BR-04 | Publicar exige título SEO ("Complete el título SEO antes de publicar"). Recomendado: SEO title ≤ 60, meta ≤ 160 (solo placeholder en prototipo; agregado como validación dura de longitud). | FormRequest |
| CMS-BR-05 | Devolver y despublicar exigen motivo; queda en bitácora y auditoría. | FormRequest |
| CMS-BR-06 | Cada transición registra evento en bitácora (usuario, fecha, mensaje, motivo). | Service |
| CMS-BR-07 | Solo contenido `publicado` (y dentro de vigencia, agregado) se entrega a canales (sitio, tienda, portales). | Query pública |

### 5.4 Portal del cliente (PROC-051)

| ID | Regla | Dónde |
|---|---|---|
| PCL-BR-01 | El cliente solo accede a su `crm_client_id`; cualquier otro id en la URL redirige al propio. Sin vínculo → "Su usuario no está vinculado a una cuenta de cliente." | Middleware + Policy |
| PCL-BR-02 | Solo se muestran cotizaciones ≠ `borrador` (versión vigente), reservas ≠ `borrador`, CxC ≠ `cancelada`. | Query |
| PCL-BR-03 | Cotización vencida no puede aprobarse (Excepción "Cotización vencida", PROC-024). | Service |
| PCL-BR-04 | Aprobar exige nombre de quien aprueba y aceptación de condiciones; registra evidencia (fecha-hora, IP, token, versión, total, moneda, comentario, hash). | FormRequest + Service |
| PCL-BR-05 | Rechazar exige motivo (Precio, Cambio de fechas, Eligió otra agencia, Cambio de destino, Viaje cancelado, Otro) y comentario. | FormRequest |
| PCL-BR-06 | Abrir una cotización `enviada` la marca `vista` **solo si el actor es el cliente** (precisado: el prototipo también la marca en la previsualización interna). | Service |
| RT-9 | Pago en línea: monto > 0 y ≤ saldo ("Regla 9: pago asociado a obligación"); pagos parciales permitidos. | FormRequest |
| PCL-BR-07 | Pago rechazado: registra pago `rechazado`, mantiene obligación, permite reintento. | Service |
| PCL-BR-08 | Documentos visibles: vouchers no anulados de sus reservas, comprobantes del cliente, propuestas aprobadas/convertidas, itinerarios de reservas confirmada/en_operacion/completada. | Query |
| PCL-BR-09 | Ticket: asunto y mensaje obligatorios; se asigna al asesor del cliente. | FormRequest |
| PCL-BR-10 | Ventas previsualiza solo clientes de su cartera (`Scope.clientIds`) con cotizaciones no borrador o reservas; cliente ajeno → "Registro fuera de su cartera". | Policy |

### 5.5 Portal del pasajero (PROC-052)

| ID | Regla | Dónde |
|---|---|---|
| PPX-BR-01 | El pasajero ve solo la reserva vinculada a su usuario (`reservation_id`); sin reserva → "No encontramos un viaje vinculado a su cuenta." | Middleware + Policy |
| PPX-BR-02 | Acceso por enlace/código: email + código de reserva deben coincidir con el email del cliente titular ("Correo o código de reserva no coinciden"). | Service auth |
| PPX-BR-03 | Alertas = incidencias de la reserva tipo Transporte u Horario, no cerradas/resueltas; se muestran con el texto público de la incidencia. | Query |
| PPX-BR-04 | Confirmar lectura registra fecha, usuario, bitácora de la incidencia y notifica al responsable. | Service |
| PPX-BR-05 | Vouchers: solo no anulados, última versión; cada apertura se audita. | Service |
| PPX-BR-06 | Mensaje a operaciones: mensaje obligatorio; tema "Salud" → prioridad alta; se asigna al coordinador de la operación. | FormRequest + Service |
| PPX-BR-07 | Estado visual de servicio: completado si `status='completado'` o fecha pasada; "Hoy"; hotel "En curso" durante la estadía; "Próximo". | Resource (derivado) |

---

### 5.6 v2.1 — Reglas de portales
| ID | Regla | Dónde |
|---|---|---|
| CAN-V21-01 | Cada sección desaparece (nav, accesos, ruta → «Mi viaje») cuando su regla está desactivada: Mensajes ↔ R-PAX-MESSAGES, Comentarios ↔ R-PAX-FEEDBACK, Acompañantes ↔ R-COMPANIONS en modo `antes_de_viaje`; bloques del resumen ↔ R-LODGING/R-ADDONS/R-PICKUP/R-COMMENTS/R-DEPOSIT/R-MULTICURRENCY | Policy + Resource |
| CAN-V21-02 | El pasajero solo ve mensajes `enviado|entregado|leido|fallido` (no programados ni cancelados) de su reserva | Query scope |
| CAN-V21-03 | Comentario: texto 5–1000 caracteres, calificación 1–5 o nula, servicio de la misma reserva | FormRequest |
| CAN-V21-04 | Reclamo/incidencia → conversación en el Inbox; reclamo muestra enlace al Libro de Reclamaciones (Ley 29571) sin registrarlo automáticamente | Service |
| CAN-V21-05 | Acompañantes: editable hasta `travelFrom − daysBeforeIfLater`; titular no editable (solo documento/nacimiento); edad a la fecha de viaje; si la edad cambia el tipo ADT/CHD/INF se rechaza (cambia precio → modificación PROC-027) | FormRequest + `BookingEngine.ageType` |
| CAN-V21-06 | Pagar saldo: aplica a la CxC abierta más próxima; internos sin `finance.payments.register` solo previsualizan | Policy |
| CAN-V21-07 | Importes del resumen en moneda cobrada con equivalente en la moneda mostrada (TC referencial) si R-MULTICURRENCY | Resource |

### 5.7 v2.2 — Califica tu viaje (R-OWN-REVIEWS)
Detalle completo en la especificación 18 (reseñas propias verificadas). En el portal del pasajero (`canales.js`, datos en `window.OwnReviews`):

| Elemento | Comportamiento |
|---|---|
| Sub-navegación «Califica tu viaje (n)» y ruta `#/canales/portal-pasajero/resena` | Solo si `effective(R-OWN-REVIEWS)` para el producto de la reserva y hay servicios calificables o reseñas previas; `n` = productos pendientes de calificar |
| Aviso en «Mi viaje» | «Califica tu viaje. Ya puede calificar n servicio(s)…» con enlace |
| Elegibilidad | Reserva `completada` → todos sus productos; `en_operacion` → servicios `completado` o con fecha pasada (demo: Thomas Keller, RES-2026-000347, hasta 02/10: Traslado aeropuerto y Cusco Mágico); nota «Su viaje sigue en curso…» |
| Formulario | Producto a calificar, estrellas 1–5 (obligatorio), subcalificaciones activas (guía, transporte, alojamiento, organización, calidad-precio; opcionales), título, texto (20–2000), fotos (`input file`, en la demo solo se registra el nombre), idioma (por nacionalidad: alemana → alemán), casilla de consentimiento sin marcar |
| Resultado | Modal según estado: publicada (autopublicación), en revisión (moderación / alertas) o solo para la agencia (sin consentimiento) |
| Mis reseñas | Estado para el pasajero («En revisión», «Publicada», «No publicada», «Solo para la agencia») y respuesta de la agencia; la respuesta también llega a Mensajes |
| Regla apagada / plan Starter | Desaparecen sub-navegación, aviso y sección (URL directa → Mi viaje) |

## 6. API REST

Guardas: `sanctum` + contexto tenant. Externos usan el mismo API con policies por vínculo (agency/client/reservation). Todas las mutaciones auditadas.

### 6.1 Back-office B2B

| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/api/v1/channels/b2b/summary` | channels.b2b.manage | KPIs del canal | — |
| GET | `/api/v1/channels/b2b/agencies` | channels.b2b.manage | Listado (`filter[status]` efectivo, `filter[price_list]`, `filter[q]`) | — |
| POST | `/api/v1/channels/b2b/agencies` | channels.b2b.manage | Alta | — |
| GET | `/api/v1/channels/b2b/agencies/{id}` | channels.b2b.manage | Detalle + tarifas asignadas + reservas | — |
| PATCH | `/api/v1/channels/b2b/agencies/{id}` | channels.b2b.manage | Editar (auto-bloqueo) | `lock_version` |
| POST | `/api/v1/channels/b2b/agencies/{id}/block` | channels.b2b.manage | Bloquear (`reason`) | `lock_version` |
| POST | `/api/v1/channels/b2b/agencies/{id}/unblock` | channels.b2b.manage | Desbloquear (`reason`; 409 `B2B-BR-07`) | `lock_version` |
| GET | `/api/v1/channels/b2b/agencies/{id}/credit-movements` | channels.b2b.manage | Libro de crédito | — |
| POST | `/api/v1/channels/b2b/agencies/{id}/impersonation` | channels.b2b.manage | Token de previsualización de solo lectura "Ver portal como esta agencia" (ver 12) | — |
| GET | `/api/v1/channels/b2b/price-lists` | channels.b2b.manage | Listas de precios | — |
| GET | `/api/v1/channels/b2b/product-rates` | channels.b2b.manage | Productos B2B con precio público, tarifa general y asignaciones | — |
| PUT | `/api/v1/catalog/products/{id}/b2b-rates/{agencyId}` | channels.b2b.manage \| catalog.products.manage_b2b | Asignar/actualizar tarifa (upsert) | — |
| DELETE | `/api/v1/catalog/products/{id}/b2b-rates/{agencyId}` | idem | Quitar (soft delete) | — |
| GET | `/api/v1/channels/b2b/settlement-requests` | channels.b2b.manage \| finance.commissions | Solicitudes de liquidación | — |

### 6.2 Portal B2B (usuario `b2b`; agencia del token)

| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/api/v1/b2b/me` | rol b2b | Agencia, estado efectivo, crédito disponible, comisión, vigencia | — |
| GET | `/api/v1/b2b/catalog` | rol b2b | Catálogo autorizado con términos (`ok`, `reason`, neto, PVP, comisión, crédito, salidas próximas) | — |
| GET | `/api/v1/b2b/catalog/{productId}/departures` | rol b2b | Salidas futuras con cupos y marca "fuera de anticipación" | — |
| POST | `/api/v1/b2b/reservations/quote-price` | rol b2b | Simulación: total, comisión, equivalente PEN, crédito tras reserva | — |
| POST | `/api/v1/b2b/reservations` | rol b2b | Crear reserva (`product_id, departure_id\|date, pax, pay_mode, passengers[], agency_ref`) | **Idempotency-Key**; lock Redis salida + `FOR UPDATE` agencia |
| GET | `/api/v1/b2b/reservations` | rol b2b | Mis reservas (`filter[status]`, `q`) | — |
| GET | `/api/v1/b2b/reservations/{code}` | rol b2b | Detalle (servicios, pasajeros, obligaciones, vouchers) | — |
| GET | `/api/v1/b2b/vouchers/{code}/pdf` | rol b2b | URL firmada del voucher | — |
| GET | `/api/v1/b2b/statement` | rol b2b | Estado de cuenta: CxC, comisiones, pagos, KPIs | — |
| POST | `/api/v1/b2b/receivables/{id}/payment-reports` | rol b2b | Informar pago (`amount, method, operation_number`) → pago `pendiente` | **Idempotency-Key** |
| POST | `/api/v1/b2b/settlement-requests` | rol b2b | Solicitar liquidación de comisiones | **Idempotency-Key** |
| POST | `/api/v1/b2b/auth/login` · `/logout` | público / rol b2b | Sesión del usuario B2B (PROC-062) | rate limit |

### 6.3 E-commerce

| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/api/v1/channels/ecommerce/summary` | channels.ecommerce.manage | KPIs y embudo (sesiones/vistas desde analítica) | — |
| GET | `/api/v1/channels/ecommerce/orders` | channels.ecommerce.manage | Pedidos (`filter[status]`, `q`, `sort=-ordered_at`) | — |
| GET | `/api/v1/channels/ecommerce/orders/{code}` | channels.ecommerce.manage | Detalle + intentos | — |
| POST | `/api/v1/channels/ecommerce/orders/{code}/payment-link` | channels.ecommerce.manage | Enviar enlace de pago (solo pago_pendiente/rechazado) | Idempotency-Key |
| POST | `/api/v1/channels/ecommerce/orders/{code}/cancel` | channels.ecommerce.manage | Anular (`reason`) | `lock_version` |
| GET | `/api/v1/channels/ecommerce/products` | channels.ecommerce.manage | Productos con precio web, promo, publicado | — |
| POST | `/api/v1/channels/ecommerce/products/{id}/publish` · `/unpublish` | channels.ecommerce.manage | Publicar/retirar (422 `ECO-BR-01`) | — |
| GET | `/api/v1/channels/ecommerce/promotions` | channels.ecommerce.manage | Promociones de todos los productos | — |
| POST | `/api/v1/catalog/products/{id}/promotions` | channels.ecommerce.manage \| catalog.products.manage_promotions | Crear | — |
| POST | `/api/v1/catalog/promotions/{id}/activate` · `/deactivate` | idem | Cambiar estado | — |
| GET | `/api/v1/public/shop/products` | público (tenant por host) | Catálogo publicado (q, category, sort) — **consumido por espec. 15** | cache Redis |
| GET | `/api/v1/public/shop/products/{slug}` | público | Detalle (contenido, config, salidas, promos visibles) | cache |
| POST | `/api/v1/public/shop/price` | público | Cálculo de precio (pax, opciones, fecha/salida, código) → líneas, descuento, total, `promo_state` | rate limit |
| POST | `/api/v1/public/shop/availability` | público | Valida disponibilidad (ECO-BR-07) | — |
| POST | `/api/v1/public/shop/orders` | público | Crea pedido + hold (comprador, pasajeros, snapshot) | **Idempotency-Key**; lock salida |
| POST | `/api/v1/public/shop/orders/{code}/payments` | público (token del pedido) | Intento de pago con token de pasarela | **Idempotency-Key** |
| GET | `/api/v1/public/shop/orders/{code}` | público (token del pedido) | Estado, reserva, voucher | — |
| POST | `/api/v1/webhooks/payments/{provider}` | firma del proveedor | Confirmación de pago (aprobado/rechazado) | Idempotente por `gateway_txn_id` |

### 6.4 CMS

| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/api/v1/cms/contents` | channels.cms.publish (+ `channels.cms.edit` propuesto) | Listado (`filter[status]`, `filter[type]`, `q`) + conteos | — |
| POST | `/api/v1/cms/contents` | idem | Crear (borrador) | — |
| GET | `/api/v1/cms/contents/{id}` | idem | Detalle + bitácora | — |
| PATCH | `/api/v1/cms/contents/{id}` | idem (publicado → publish) | Guardar cambios | `lock_version` |
| POST | `/api/v1/cms/contents/{id}/submit` | idem | → en_revision | `lock_version` |
| POST | `/api/v1/cms/contents/{id}/return` | channels.cms.publish | → borrador (`reason`) | `lock_version` |
| POST | `/api/v1/cms/contents/{id}/approve` | channels.cms.publish | → aprobado | `lock_version` |
| POST | `/api/v1/cms/contents/{id}/publish` | channels.cms.publish | → publicado | `lock_version` |
| POST | `/api/v1/cms/contents/{id}/unpublish` | channels.cms.publish | → despublicado (`reason`) | `lock_version` |
| POST | `/api/v1/cms/contents/{id}/preview` | idem | Render HTML + SERP con datos no guardados | — |
| GET | `/api/v1/public/cms/contents/{slug}` · `/api/v1/public/cms/placements/{placement}` | público / portal | Contenido publicado por canal | cache Redis, invalidación al publicar |

### 6.5 Portal del cliente (rol `customer`; interno con previsualización)

| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/api/v1/portal/client/home` | customer | KPIs, pendientes, próximo viaje, asesor, notificaciones | — |
| GET | `/api/v1/portal/client/quotes` · `/{code}` | customer | Listado / detalle (versión vigente, líneas, inclusiones, condiciones) | — |
| POST | `/api/v1/portal/client/quotes/{code}/view` | customer | Marca `vista` (solo si enviada y no vencida) | idempotente |
| POST | `/api/v1/portal/client/quotes/{code}/approve` | customer | Aprobar (`approver_name, accept_terms, note`) | **Idempotency-Key**; `lock_version` |
| POST | `/api/v1/portal/client/quotes/{code}/reject` | customer | Rechazar (`reason, comment`) | `lock_version` |
| POST | `/api/v1/portal/client/quotes/{code}/update-request` | customer | Solicitar actualización (crea ticket) | — |
| GET | `/api/v1/portal/client/quotes/{code}/pdf` | customer | PDF (job `documents`) | — |
| GET | `/api/v1/portal/client/reservations` · `/{code}` | customer | Mis viajes / detalle | — |
| GET | `/api/v1/portal/client/reservations/{code}/itinerary.pdf` | customer | Itinerario | — |
| GET | `/api/v1/portal/client/documents` | customer | Vouchers, comprobantes, propuestas, itinerarios | — |
| GET | `/api/v1/portal/client/receivables` · `/payments` | customer | Obligaciones / historial | — |
| POST | `/api/v1/portal/client/receivables/{id}/payments` | customer | Pago en línea (token pasarela, monto) | **Idempotency-Key** |
| GET | `/api/v1/portal/client/notifications` | customer | Notificaciones | — |
| GET · POST | `/api/v1/portal/client/tickets` | customer | Mis solicitudes / crear | — |
| GET | `/api/v1/channels/portal-preview/clients` | channels.b2b.manage \| channels.cms.publish \| crm.clients.manage | Clientes previsualizables (Ventas: cartera) | — |
| GET | `/api/v1/channels/portal-preview/clients/{clientId}/…` | idem | Mismos GET del portal en modo solo lectura (no marca vista, no aprueba, no paga) | — |

### 6.6 Portal del pasajero (rol `passenger`)

| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| POST | `/api/v1/portal/passenger/auth/link` | público | Email + código de reserva → envía enlace (magic link) | rate limit |
| POST | `/api/v1/portal/passenger/auth/verify` | público | Canjea token → sesión Sanctum | un uso |
| GET | `/api/v1/portal/passenger/trip` | passenger | Viaje, día n/N, servicios de hoy, próximo | — |
| GET | `/api/v1/portal/passenger/itinerary` | passenger | Días con servicios + asignaciones (guía, conductor, vehículo) | — |
| GET | `/api/v1/portal/passenger/hotels` · `/transport` · `/activities` | passenger | Secciones | — |
| GET | `/api/v1/portal/passenger/vouchers` · `/{code}` | passenger | Vouchers (apertura auditada) | — |
| GET | `/api/v1/portal/passenger/documents` | passenger | Itinerario, certificado, comprobantes, política | — |
| GET | `/api/v1/portal/passenger/alerts` | passenger | Alertas + mensajes recibidos | — |
| POST | `/api/v1/portal/passenger/alerts/{incidentId}/ack` | passenger | Confirmar lectura | idempotente (UNIQUE) |
| GET | `/api/v1/portal/passenger/contacts` | passenger | Emergencias, coordinador, guía próximo | — |
| POST | `/api/v1/portal/passenger/messages` | passenger | Mensaje a operaciones (crea ticket) | — |

Errores de regla: `422 {code:'B2B-BR-04', message}`; conflictos de cupo/estado: `409`.

---

### 6.7 v2.1 — Endpoints de portales
| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| GET | `/api/v1/portal/passenger/messages` | passenger | Bandeja (filtro `channel`) | — |
| POST | `/api/v1/portal/passenger/messages/{id}/read` · `/read-all` | passenger | Marca leído | idempotente |
| GET · POST | `/api/v1/portal/passenger/feedback` | passenger | Historial / alta de comentario | POST con `Idempotency-Key` |
| GET · PUT | `/api/v1/portal/passenger/companions` | passenger | Acompañantes y recojo (R-COMPANIONS `antes_de_viaje`) | `lock_version` de la reserva |
| GET | `/api/v1/portal/customer/reservations/{code}/web-summary` | customer | Resumen web + plan de pago + CxC abiertas | — |
| PUT | `/api/v1/portal/customer/reservations/{code}/companions` | customer | Completar acompañantes (R-COMPANIONS cualquier modo) | `lock_version` |
| POST | `/api/v1/portal/customer/receivables/{code}/pay` | customer | Pago del saldo (pasarela) | `Idempotency-Key` |

### 6.8 v2.2 — Endpoints de reseñas del pasajero
| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/v1/passenger/reviews/eligibility` | passenger (reserva del token) | Productos calificables, reseñas propias y respuestas |
| POST | `/api/v1/passenger/reviews` | passenger | Alta multipart (Idempotency-Key); 422 si no verificable, 409 si ya calificó, 403 `RULE_NOT_IN_PLAN` si la regla no aplica |

## 7. Permisos y comportamiento por rol

### 7.1 Matriz de acceso (prototipo, `ACCESS` + `ROLE_UX`)

| Rol | B2B admin/precios | Portal B2B | E-commerce gestión | Tienda | CMS | Portal cliente | Portal pasajero |
|---|---|---|---|---|---|---|---|
| Super Admin | ✗ (403 plataforma) | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Tenant Admin | ✓ | ✓ previsualiza cualquier agencia | ✓ | ✓ vista previa | ✓ publica | ✓ todos | ✓ |
| Manager | ✓ | ✓ | ✓ | ✓ | ✓ publica | ✓ todos | ✓ |
| Ventas | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ solo su cartera (`crm.clients.manage`) | ✗ |
| Operaciones | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ (`operations.assign`) |
| Finanzas / Contabilidad | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Guía / Conductor / Proveedor | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Usuario B2B | ✗ | ✓ **solo su agencia**, rutas `canales/b2b/portal/*` | ✗ | ✗ | ✗ | ✗ | ✗ |
| Cliente | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ **solo su `clientId`** + libro de reclamaciones | ✗ |
| Pasajero | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ **solo su reserva** + `mobile/pasajero` |

El menú "Canales" muestra por ítem solo las rutas permitidas (Ventas ve únicamente "Portal del cliente"; Operaciones, "Portal del pasajero").

### 7.2 Botones y acciones

| Pantalla / acción | Habilitado por | Si falta |
|---|---|---|
| Pestañas B2B "Administración" y "Listas de precios" | channels.b2b.manage | Pestañas ocultas; aviso "solo puede ver la experiencia del portal" (inalcanzable hoy porque la ruta exige el permiso) |
| "Nueva agencia", "Editar", "Bloquear/Desbloquear" (drawer) | channels.b2b.manage | Ocultos; si se invoca: toast "Requiere permiso channels.b2b.manage." sin cambios |
| "Gestionar" tarifas B2B | channels.b2b.manage | Oculto; toast idem |
| "Ver portal como esta agencia" | Acceso a la pantalla | — |
| Portal B2B: Reservar | Tarifa OK (RT-14) | Deshabilitado con aviso de la regla |
| E-commerce: pestaña "Gestión del canal" | channels.ecommerce.manage | Oculta (solo tienda) |
| Switch "Publicado", "Nueva promoción", "Activar/Desactivar" | channels.ecommerce.manage | `disabled` + `title="Requiere permiso channels.ecommerce.manage"`; toast si se fuerza |
| Pedido: "Enviar enlace de pago", "Anular pedido" | channels.ecommerce.manage y estado pago_pendiente/pago_rechazado | Ocultos |
| Confirmación de compra: "Ver reserva en el ERP" / "Ver pedido en gestión" | reservations.view / channels.ecommerce.manage | Ocultos |
| CMS: Guardar | channels.cms.publish o estado ∈ {nuevo, borrador, despublicado} | Oculto; aviso "La siguiente transición requiere… channels.cms.publish" |
| CMS: Enviar a revisión | Estado borrador/despublicado | — |
| CMS: Devolver, Aprobar, Publicar, Despublicar | channels.cms.publish + estado | Ocultos |
| Portal cliente: selector "Ver como cliente" y barra "Vista del canal" | Solo internos | Nunca para externos |
| Portal cliente: Aprobar/Rechazar | Cotización abierta y no vencida | Ocultos; alerta de vencida con "Solicitar actualización" |
| Portal cliente: "Pagar en línea" | Saldo > 0 | Oculto |
| Portal B2B / cliente — drawer de reserva: "Abrir en ERP" | Solo internos con acceso a `reservas/{id}` | Oculto a externos |
| Portal pasajero: login simulado y "Cerrar sesión del pasajero" | Solo internos | Externo entra directo a su reserva |

### 7.3 Alcance y datos sensibles

| Rol | Alcance de datos | Oculto |
|---|---|---|
| Usuario B2B | Su agencia: reservas B2B propias, CxC, pagos, comisiones; catálogo con neto, PVP sugerido y comisión | Costos de proveedor, márgenes, notas internas, otras agencias, IDs de clientes |
| Cliente | Su cliente: cotizaciones no borrador (versión vigente), reservas, documentos, CxC, pagos, notificaciones, tickets | Costos, márgenes, versiones no vigentes, datos de otros clientes |
| Pasajero | Su reserva: servicios, asignaciones (nombres/teléfonos de guía y conductor, placa), hotel, vouchers, comprobantes de la reserva, alertas | Costos, precios de proveedor, otras reservas |
| Ventas (previsualización cliente) | Clientes de su cartera | Clientes ajenos → pantalla "Registro fuera de su cartera" |
| Admin/Manager | Todo el tenant | — |

**Precisado para producción:** la previsualización interna de portales es **de solo lectura** (no marca "vista", no aprueba/rechaza, no paga, no crea tickets ni reservas). En el prototipo la previsualización ejecuta acciones reales en nombre del cliente/agencia (p. ej. reserva B2B con owner U13).

---

### 7.x v2.1 — Por rol
Pasajero (U15): solo su reserva; mensajes, comentarios y acompañantes. Cliente (U14): solo sus reservas; resumen web, pagar saldo, completar acompañantes. Internos: previsualizan con barra de canal; Ventas solo clientes/reservas de su cartera (login de pasajero bloquea reservas ajenas). Las acciones del pasajero notifican al coordinador de la orden (`Notify` Portal) y al asesor; se auditan `Comentario pasajero · crear`, `Conversación · crear`, `Mensaje al pasajero · leido`, `Reserva · acompanantes_portal_*`.

## 8. Eventos, jobs (colas Redis) y notificaciones

### 8.1 Eventos de dominio

| Evento | Emisor | Listeners (cola) |
|---|---|---|
| `B2bAgencyCreated/Updated/Blocked/Unblocked` | Admin B2B | Auditoría; email a agencia (`notifications`) |
| `B2bRateAssigned/Removed` | Tarifas B2B | Invalida caché catálogo B2B (`low`) |
| `B2bReservationCreated` | Portal B2B | Reservas (`ReservationCreated`), Finanzas (CxC, comisión `calculada`), crédito (movimiento), Operaciones (orden si confirmada), notificaciones (`critical` para la transacción, `notifications` para avisos) |
| `B2bCreditExceededAttempt` | Portal B2B | Auditoría; alerta a ejecutivo B2B |
| `B2bPaymentReported` | Portal B2B | Bandeja de Finanzas (validación, PROC-049) |
| `PaymentApplied` (Finanzas) | Finanzas | Libera crédito B2B (`credit_movements`) |
| `B2bSettlementRequested` | Portal B2B | Finanzas comisiones |
| `EcomOrderCreated` | Checkout | Hold de cupos; `hold_expires_at` |
| `EcomPaymentFailed` / `EcomPaymentApproved` | Webhook pasarela (`critical`) | Pago, CxC, reserva, voucher, emails |
| `EcomOrderCancelled` / `EcomOrderExpired` | Gestión / job | Libera cupos, cancela reserva y CxC |
| `ProductPublishedToChannel` / `Unpublished` | E-commerce | Invalida caché pública; sitemap (espec. 15) |
| `CmsContentStatusChanged` | CMS | Invalida caché pública; notificaciones |
| `QuoteViewed`, `QuoteApproved`, `QuoteRejected` (canal portal) | Portal cliente | Cotizaciones/CRM; notificaciones al asesor |
| `ClientPortalPaymentApplied` / `Failed` | Portal cliente | Finanzas; emails |
| `SupportTicketCreated` | Portales | Inbox/asesor/coordinador |
| `IncidentAlertAcknowledged` | Portal pasajero | Operaciones |

### 8.2 Jobs y scheduler

| Job | Cola | Frecuencia | Qué hace |
|---|---|---|---|
| `ExpireEcomHolds` (agregado) | critical | cada 5 min (junto con JOB-01 "Expirar holds") | Pedidos `pago_pendiente/pago_rechazado` con `hold_expires_at < now` → `expirado`, libera cupos, cancela reserva/CxC |
| `MarkB2bAgreementsExpired` (derivado, opcional) | low | diario 00:10 | Notifica agencias con convenio por vencer (30/7 días) y vencidos |
| `RecalculateB2bCredit` (control) | low | diario | Verifica `credit_used` = suma de movimientos; alerta si difiere |
| `GenerateDocumentPdf` | documents | on demand | Propuesta, voucher, itinerario, comprobante, certificado |
| `SendNotification` | notifications | on event | Email/WhatsApp/Portal/Push con reintentos (PROC-059) |
| `SyncWebAnalytics` (agregado) | integrations | cada hora | Sesiones/vistas/inicio de checkout para el embudo (hoy simulados) |

### 8.3 Notificaciones que emite el prototipo

| Evento (`Notify.push`) | Canal | Destinatario |
|---|---|---|
| `b2b.estado` | Email | Agencia (bloqueo/desbloqueo) |
| `reserva.confirmada` | Email | Agencia / comprador web |
| `reserva.b2b` | Portal | Gerente (U02) |
| `voucher.emitido` | Email | Agencia / comprador web |
| `pago.informado` | Portal | Finanzas (U07) |
| `comision.liquidacion` | Email | Finanzas (pleon@…) |
| `pago.enlace` | Email | Comprador web |
| `pago.rechazado` | Email | Comprador web / cliente |
| `pago.recibido` | Email / Portal | Comprador/cliente; Finanzas (U07) en portal cliente |
| `cms.revision` / `cms.publicado` / `cms.devuelto` | Portal | Gerente (U02) / autor / autor |
| `cotizacion.vista` / `cotizacion.aprobada` / `cotizacion.rechazada` | Portal (+ Email al cliente en aprobada) | Asesor dueño de la cotización |
| `soporte.ticket` | Portal | Asesor del cliente |
| `alerta.leida` | Portal | Responsable de la incidencia |
| `pasajero.mensaje` | WhatsApp | Coordinador de la operación |

En producción los destinatarios fijos (U02, U07, pleon@) se reemplazan por reglas de notificación del tenant (rol/responsable).

---

## 9. Auditoría

`audit_logs` con actor, tenant, entidad, acción, antes, después, IP, correlación (PROC-063). Acciones registradas por el prototipo:

| Entidad | Acción | Antes → después |
|---|---|---|
| Agencia B2B | `crear`, `editar` | lista / comisión / límite |
| Agencia B2B | `bloquear`, `desbloquear` | estado → estado · motivo |
| Agencia B2B | `credito_excedido` | disponible → intento (monto, producto) |
| Tarifa B2B | `asignar`, `actualizar`, `quitar` | precio/comisión por agencia |
| Sesión B2B / Sesión pasajero | `login`, `logout` | — |
| Reserva | `crear_b2b`, `crear_ecommerce`, `cambio_estado`, `cancelar` | estado · canal · forma de pago |
| Disponibilidad | `consumir_cupos`, `retener_cupos` | sold/capacity |
| Pago | `informar_b2b`, `aplicar`, `registrar_portal`, `pago_rechazado` | estado · monto |
| Comisión | `solicitar_liquidacion` | monto |
| Pedido web | `crear`, `pago_rechazado`, `enviar_enlace_pago`, `anular` | estado · motivo |
| Voucher | `emitir`, `ver_portal_pasajero` | v1 / pasajero |
| Cliente | `crear_desde_ecommerce` | email |
| Producto | `publicar_ecommerce`, `despublicar_ecommerce` | flag |
| Promoción | `crear`, `cambio_estado` | estado |
| Contenido CMS | `crear`, `editar`, `cambio_estado` | estado · motivo |
| Cotización | `vista_portal`, `aprobar_portal` (versión, nombre, IP), `rechazar_portal` (motivo) | estado |
| Ticket soporte | `crear` | asunto / origen |
| Incidencia | `lectura_pasajero` | pasajero |

Agregados: `impersonar_agencia` / `previsualizar_portal` (quién previsualizó qué), cambios de `credit_limit` con antes/después explícito, expiración automática de pedidos (actor `Sistema`).

---

## 10. Casos de prueba mínimos

### Pest (feature)

| # | Caso |
|---|---|
| T01 | Aislamiento: agencia/cliente/pasajero del tenant A no ve datos del tenant B (E2E-08). |
| T02 | Usuario B2B de AGB-01 no puede leer reservas/estado de cuenta de AGB-02 (403/404). Cliente CLI-0001 no puede abrir `/quotes/{code}` de otro cliente. Pasajero solo su reserva. |
| T03 | RBAC: Ventas/Operaciones/Finanzas → 403 en `/channels/b2b/*`, `/channels/ecommerce/*`, `/cms/*`; Ventas previsualiza solo clientes de su cartera. |
| T04 | RT-14: producto sin tarifa para la agencia o con tarifa vencida → 422 `RT-14`. |
| T05 | B2B-BR-04: reserva a crédito con total > disponible → 422, sin reserva, audit `credito_excedido`; con prepago → 201 `pendiente_pago`. |
| T06 | B2B-BR-11/12: crédito + salida → `confirmada`, voucher v1, CxC vence +15 d, comisión `calculada`, crédito incrementado (movimiento `cargo_reserva`). |
| T07 | RT-4: salida con cupos < pax → 409; concurrencia: dos reservas simultáneas por el último cupo → solo una 201 (lock). |
| T08 | B2B-BR-07/08: desbloqueo con crédito excedido → 409; editar límite por debajo del usado → `bloqueada`. |
| T09 | Convenio vencido o agencia inactiva → no puede reservar ni prepago. |
| T10 | Pago aplicado por Finanzas a CxC B2B → libera crédito; no hay doble conteo con método "Crédito B2B". |
| T11 | ECO-BR-01: publicar producto REVIEW o sin tarifa → 422. |
| T12 | ECO-BR-05: código inválido → `promo_state=bad`; 4 pax + código 8 % + automática 10 % → aplica 10 %, no acumula. |
| T13 | ECO-BR-10/13: pedido con tarjeta rechazada → `pago_rechazado`, cupos retenidos; reintento aprobado → `confirmado`, reserva `confirmada`, CxC `pagada`, voucher. |
| T14 | Webhook duplicado (mismo `gateway_txn_id`) no duplica pago ni voucher (idempotencia). |
| T15 | ECO-BR-11: pedido sin pago tras `hold_minutes` → `expirado`, cupos liberados. |
| T16 | ECO-BR-14: anular pedido `confirmado` → 409; anular `pago_pendiente` → cupos liberados, reserva `cancelada`. |
| T17 | CMS: sin `channels.cms.publish` no aprueba/publica/despublica (403); editar publicado → 403; publicar sin SEO title → 422; slug duplicado → 422; devolver sin motivo → 422. |
| T18 | Portal cliente: aprobar cotización vencida → 422; aprobar sin aceptar condiciones → 422; aprobación guarda evidencia y versión inmutable; rechazo exige motivo. |
| T19 | Previsualización interna del portal no cambia `enviada → vista` ni permite mutaciones. |
| T20 | Pago en portal cliente: monto > saldo → 422 `RT-9`; pago parcial → CxC `parcial`. |
| T21 | Pasajero: `ack` dos veces → una sola fila; login con email/código incorrecto → 422. |
| T22 | Idempotency-Key repetida en `POST /b2b/reservations` devuelve la misma respuesta sin duplicar reserva. |

### Playwright

| # | Flujo |
|---|---|
| P01 | Usuario B2B: login → catálogo → reservar con crédito (salida con cupo) → ver voucher en "Mis reservas" → estado de cuenta muestra cargo y comisión. |
| P02 | Agencia bloqueada: solo ve prepago, aviso de bloqueo, reserva queda `pendiente_pago`. |
| P03 | Checkout web (en el sitio público de espec. 15, mismo API): selección → config → disponibilidad → código PRIMAVERA8 → datos → tarjeta rechazada → reintento aprobado → confirmación con voucher. |
| P04 | CMS: redactor envía a revisión → gerente aprueba → publica → banner visible en sitio → despublica con motivo. |
| P05 | Cliente: abre cotización enviada (pasa a vista) → aprueba → aparece en Documentos como "Propuesta aprobada" (E2E-01 parcial). |
| P06 | Pasajero: mi viaje → alerta visible → confirmar lectura → itinerario → abrir voucher (E2E-06). |
| P07 | A 400 px: portales sin scroll horizontal; externos no ven "Vista del canal" ni "Ver como…". |

---

### 10.1 v2.2 — Pruebas del portal
Playwright (`e2e_resenas.js`): U15 ve el aviso, califica PRD-002 → pendiente; reseña con correo → retenida; tras la respuesta de U01 el pasajero la ve en «Mis reseñas»; con R-OWN-REVIEWS apagada o plan Starter la sección no aparece.

## 11. Cambios y decisiones respecto a la documentación inicial

| # | Tipo | Cambio | Motivo |
|---|---|---|---|
| 1 | Agregado | PROC-053 incorpora **administración B2B**: alta/edición de agencia con RUC, contacto, lista de precios, comisión base, moneda y límite de crédito, vigencia y estado. | DOC-PROC-001 solo describe el recorrido de la agencia; se necesita la ficha de convenio para aplicar RT-14. |
| 2 | Precisado | RT-14 se implementa en tres niveles: agencia (vigencia/estado), producto (ACTIVE + is_b2b), tarifa por agencia (existencia y `valid_to`). | Hacer verificable "B2B por agencia/producto/vigencia". |
| 3 | Agregado | Estados de agencia `activa/bloqueada/inactiva` + derivados `vencido` y bloqueo automático por crédito excedido; desbloqueo prohibido mientras exceda. | Control de riesgo de crédito (PRD §24 "Crédito"). |
| 4 | Precisado | "Pago/Crédito" de PROC-053: crédito solo con tarifa que lo permita, línea > 0 y agencia activa; exceso bloquea la reserva a crédito y se ofrece prepago. Estados resultantes de reserva por forma de pago (B2B-BR-11). | La documentación no definía la regla de decisión. |
| 5 | Agregado | Libro `channel_b2b_credit_movements` y `credit_used` derivado. | El prototipo suma crédito al reservar (canales) **y** al registrar pago con método "Crédito B2B" (finanzas), y no libera al aplicar transferencias: riesgo de doble conteo. Se unifica en un solo mecanismo. |
| 6 | Agregado | "Liquidación" de PROC-053 = estado de cuenta con comisiones a favor, saldo neto, "Informar pago" (pago `pendiente` que valida Finanzas) y solicitud de liquidación de comisiones (`channel_b2b_settlement_requests`). | El prototipo solo notifica por email; se requiere entidad para trazabilidad (PROC-047). |
| 7 | Precisado | Tarifa B2B por agencia ≤ precio público (canales) y ≥ costo, crédito solo con línea, agencias no activas no asignables (catálogo). Se unifican en una sola validación de dominio. | Hoy Canales bloquea > público y Catálogo solo advierte; Catálogo bloquea < costo y Canales no. |
| 8 | Agregado | "Ver portal como esta agencia" y previsualización interna de portales del cliente y pasajero. | Soporte y QA comercial; en producción será solo lectura y auditado. |
| 9 | Precisado | PROC-054: el pedido se crea al primer intento de pago, con reserva `pendiente_pago`, CxC y retención de cupos; pago aprobado confirma reserva o la deja `pendiente_proveedor` si el producto es bajo solicitud; voucher solo con salida confirmada (RT-11). | Definir el momento exacto de creación de entidades. |
| 10 | Agregado | Estados del pedido web `pago_pendiente/pago_rechazado/confirmado/cancelado` + `expirado` (job). | El prototipo anuncia hold de 30 min pero no lo expira; excepción "Pago rechazado" de DOC-PROC-001 §24 implementada (obligación se mantiene, reintento). |
| 11 | Precisado | Pricing web: tarifa canal E-commerce con fallback a Directo; niño con tarifa propia o precio adulto; deltas de personalización por persona; promociones no acumulables (mayor descuento), código o automática por cantidad (≥4). | PRD §23/§25: "cada selección recalcula el precio mediante backend"; se fija el algoritmo. |
| 12 | Agregado | Cliente CRM creado/reutilizado por email desde el checkout; owner por defecto configurable (prototipo: U03 fijo). | Integración e-commerce → CRM/Customer 360. |
| 13 | Cambiado | La "Tienda (vista pública)" deja de ser pantalla del back-office: el escaparate lo construye la **especificación 15 (sitio público)**, que consume `/api/v1/public/shop/*` y `/api/v1/public/cms/*` definidos aquí. | Evitar dos tiendas y duplicación de reglas (RT-18). |
| 14 | Agregado | Gestión e-commerce: KPIs, embudo, pedidos con intentos de pago, enviar enlace de pago, anular; publicación por producto; promociones y códigos. | PROC-054 solo describía el recorrido del comprador. |
| 15 | Precisado | PROC-055: estados `borrador → en_revision → aprobado → publicado → despublicado`, devolver a borrador con motivo, republicación vía reenvío a revisión; publicar exige SEO title; slug único; bitácora. | "Contenido → Revisión → Aprobación → Publicación → Canal → Despublicación" sin reglas. |
| 16 | Agregado | Contenido CMS con canal destino (Web, Web/E-commerce, Web/Portal, Portal cliente, Portal pasajero, App pasajero), tipo, SEO y vista previa SERP; agregados `placement` y vigencia para banners. | El prototipo enlaza contenidos por ID fijo (CMS-02 banner en tienda, CMS-05 política en portal pasajero). |
| 17 | Precisado | PROC-051: aprobación con evidencia (nombre, aceptación de condiciones, fecha-hora, IP, token, versión, total, hash) y bloqueo de versiones vencidas; rechazo con motivo tipificado; "Solicitar actualización" crea ticket. | PROC-024 pide timestamp y evidencia. |
| 18 | Agregado | Portal cliente: pagos en línea parciales con tarjeta (aplicados directo), soporte con tickets (`channel_support_tickets`), notificaciones consolidadas. | "Pagos" y "Soporte" de PROC-051 sin detalle. |
| 19 | Precisado | Marcado "Vista" solo cuando el actor es el cliente (no en previsualización interna). | Evitar falsos positivos en el seguimiento comercial. |
| 20 | Agregado | Portal pasajero: acceso por email + código de reserva / enlace seguro; alertas derivadas de incidencias Transporte/Horario con confirmación de lectura (`ops_incident_pax_acks`); mensaje a operaciones como ticket; auditoría de apertura de vouchers. | PROC-052 "Alertas" y "Contacto" sin mecanismo. |
| 21 | Agregado | Campos `public_title/public_message` en incidencias, `emergency_phone` en tenant, dirección de hotel desde proveedor. | El prototipo usa textos y direcciones fijos (`paxAlertText`, `HOTEL_ADDR`, `EMERGENCY`). |
| 22 | Precisado | Usuarios externos nunca ven barras de demo, selectores "Ver como…", costos, márgenes ni IDs ajenos; su identidad (agencia/cliente/reserva) viene del token. | Principios de BRIEF_ROLES y RT-2/RT-17. |
| 23 | Cambiado | La salida de un servicio se referencia por FK `departure_id` (el prototipo la codifica en el nombre del servicio "(salida SAL-xxxx)"). | Integridad y liberación de cupos fiable. |

---

## 12. Supuestos y puntos a validar con negocio

| # | Punto | Propuesta / supuesto |
|---|---|---|
| 1 | **CMS inaccesible para redactores**: la ruta exige `channels.cms.publish`, por lo que el flujo "redacta y envía a revisión sin publicar" nunca se ve (solo Admin y Manager entran). | Crear `channels.cms.edit` (acceso y edición) y dejar `channels.cms.publish` para aprobar/publicar. ¿Qué roles redactan (Ventas, Marketing)? |
| 2 | Permisos gruesos (`channels.b2b.manage`, `channels.ecommerce.manage`). | Proponer granularidad: `channels.b2b.view`, `.agencies.manage`, `.credit.manage`, `.rates.manage`, `.impersonate`; `channels.ecommerce.view`, `.orders.manage`, `.publish`, `.promotions.manage`; `channels.portals.preview`. |
| 3 | Semántica del precio B2B: el portal muestra "Precio neto" **y** "Su comisión" sobre ese neto, y la CxC se emite por el neto. ¿Tarifa neta (sin comisión) o comisionable (la agencia cobra PVP y liquida comisión)? | Definir modelo: neto puro (comisión = 0) o comisionable (CxC por PVP − comisión o CxC por PVP y nota de crédito por comisión). Impacta facturación. |
| 4 | Moneda del crédito: el prototipo compara el total convertido a PEN contra el disponible en la moneda de la línea (incorrecto si la línea es USD). | Convertir a `credit_currency` con TC del día guardado en el movimiento. |
| 5 | Plazo de crédito B2B fijo 15 días y prepago a 1 día. | Parametrizar por agencia (`credit_days`) y por tenant. |
| 6 | IGV: B2B y e-commerce no desglosan impuesto (precio final). | Confirmar que tarifas web/B2B son finales con IGV y cómo se desglosa en comprobante (exportación de servicios a no domiciliados). |
| 7 | Nominación B2B: se permite reservar con pasajeros "por nominar". | Definir plazo de nominación y bloqueo de voucher para productos nominativos (Machu Picchu, tren). |
| 8 | Owner de reservas/clientes creados por canal: B2B usa el usuario B2B (o U13 en previsualización) y e-commerce fija U03. | Parametrizar ejecutivo B2B por agencia y asesor web por defecto (o round-robin). |
| 9 | Previsualización interna ejecuta acciones reales (reservas B2B, aprobaciones, pagos, tickets). | Producción: solo lectura. Confirmar si soporte necesita "actuar en nombre de" (con doble registro de actor). |
| 10 | Hold e-commerce: 30 min de `settings.holdMinutes`; productos bajo solicitud cobran por adelantado y "se reembolsa si no hay confirmación". | Confirmar política de reembolso automático y SLA de 24 h; job de expiración y job de reembolso. |
| 11 | Pasarela de pagos (INT-02) por definir: el prototipo usa tarjetas sandbox (4111…/5555… aprobadas, 4000…0002 rechazada). | Elegir proveedor (tokenización, 3-D Secure, webhooks); no almacenar datos de tarjeta (PCI DSS SAQ-A). |
| 12 | Pago en portal cliente se aplica directo y no cambia el estado de la reserva; pago informado por agencia queda por validar. | Confirmar si un pago total en portal debe disparar confirmación/voucher como en e-commerce. |
| 13 | Embudo e-commerce: sesiones y vistas son simuladas. | Definir fuente de analítica (GA4 u otra) y eventos que emitirá el sitio de la espec. 15. |
| 14 | Promociones creadas desde e-commerce solo porcentuales y "cantidad ≥ 4" fijo. | ¿Se exponen aquí los demás tipos de PRD §25 (fijo, precio promocional, por fecha/temporada) o solo en Catálogo? Umbral configurable (`min_pax`). |
| 15 | Canal de contenido CMS es un valor único combinado ("Web / Portal"). | Normalizar a N:M `cms_content_channels`. |
| 16 | Aprobado no puede volver a borrador ni editarse sin permiso; publicado se edita en caliente por quien tiene permiso. | ¿Requerir nueva revisión para cambios mayores en publicado (versionado de contenido)? |
| 17 | Tickets de soporte: el prototipo solo los crea (`abierta`); no hay pantalla interna de gestión en este módulo. | Confirmar si se gestionan en Inbox/Colaboración (tablas `collab_`) y SLA de 4 h hábiles. |
| 18 | Pasajero ve comprobantes de la reserva (del titular pagador). | Validar si debe ver comprobantes o solo documentos de viaje. |
| 19 | Portal del pasajero en la demo solo acepta RES-2026-000347 y usa email del titular. | Producción: acceso por pasajero (email propio) y multi-reserva ("Mis viajes"). |
| 20 | Textos de alerta al pasajero redactados por operaciones. | Agregar `public_message` en incidencias con aprobación antes de publicar al pasajero. |
| 21 | Idiomas: el portal del pasajero menciona inglés/alemán. | Definir i18n de portales y de contenidos CMS (versión por idioma). |
| 22 | Relación con espec. 15: el sitio público reemplaza la tienda. | Confirmar que la espec. 15 usa exactamente `/api/v1/public/shop/*` y `/api/v1/public/cms/*`; la ruta `#/canales/ecommerce/tienda` del prototipo queda como referencia funcional, no como pantalla a construir. |
| 23 | B2B sin API ni XML para agencias. | ¿Se requiere API B2B (disponibilidad/reserva) para agencias integradas? (P1/Futuro según DOC-PROC-001 §34–35.) |