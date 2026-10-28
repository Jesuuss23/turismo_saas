# Sitio web público de la agencia (Andes Horizonte Travel)

> Fuente: `proto/assets/js/modules/sitio.js` (rutas `#/sitio/*`, sin login). Usa `core.js` (`Pricing.seasonFor`, `Seq`, `Audit`, `Notify`, `Auth`), `data.js` (productos, tarifas, temporadas, salidas, destinos, CMS, clientes, reservas, CxC, pagos, vouchers, `settings.fx`, `settings.holdMinutes`) y servicios de otros módulos: holds de disponibilidad (`DB.catalogHolds`, espec. 02), leads (`DB.leads`, 01), pedidos web (`DB.ecomOrders`, 08), conversaciones del inbox (`DB.conversations`, 10), Libro de Reclamaciones (`window.ClaimBook` / `DB.claims`, 12), suscriptores y consentimientos (`DB.subscribers` / `DB.consents`, 14).
> Transversal (tenant, RBAC, auditoría, idempotencia, notificaciones): `00-transversal-seguridad-rbac.md`. Reglas e-commerce heredadas: `08-canales-portales.md` §5.2 (ECO-BR-xx) — este sitio **no duplica** esas reglas (RT-18): las consume vía los mismos servicios de dominio.
> **v2.1:** todo precio, disponibilidad, regla de pasajeros, alojamiento, adicional, modalidad de pago y conversión de moneda se obtiene de `assets/js/booking-engine.js` (`window.BookingEngine`, «BE»; en producción `App\Domain\Bookings\BookingEngine`). Cada comportamiento nuevo consulta `BE.on('<regla>', producto)` sobre `DB.settings.bookingRules` y desaparece si la regla está apagada (ver §5.1 y §11.7).
> Prueba E2E del prototipo: `node e2e_sitio.js` (anónimo), `node e2e_sitio2.js` (sesión U14 + back-office), `node sweep_sitio.js` (400/1440 px, sin desborde ni errores).

---

## 1. Propósito y procesos cubiertos

Sitio de venta directa (B2C) del tenant con el nivel de un tour operador / OTA de actividades moderno: descubrimiento (destinos, tours, paquetes, experiencias, ofertas, blog), ficha rica con calendario de precios por temporada, carrito multiproducto con bloqueo temporal de cupos, checkout por pasos con anticipo o pago total, cuenta del cliente, captación de leads (viajes a medida, contacto, chat, newsletter) y cumplimiento legal peruano (Ley 29733, Libro de Reclamaciones, comprobantes).

| Proceso / regla | Cobertura en el sitio |
|---|---|
| PROC-054 E-commerce | Catálogo → Selección → Configuración → Disponibilidad → Precio → Checkout → Pago → Reserva → Confirmación → Voucher (multiproducto, carrito persistente) |
| PROC-020 Disponibilidad | Hold automático al añadir al carrito (30 min, `settings.holdMinutes`), renovación, expiración, consumo al pagar |
| PROC-018 / PROC-019 Precio y temporadas | Precio por fecha = tarifa vigente del canal × factor de temporada; calendario con precios y marcas de temporada |
| PROC-001 Captura de leads | Viajes a medida, contacto “cotizar”, chat web → `crm_leads` fuente Web, **sin asignar** (cola comercial) |
| PROC-045/046 Cobros | CxC de anticipo/saldo o total, pago con tarjeta (pasarela), transferencia/Yape por validar, pago del saldo desde Mi cuenta |
| PROC-050 Facturación | Captura boleta/factura (DNI/RUC, razón social, dirección fiscal); emisión por Finanzas/OSE al aplicar el pago |
| PROC-051 Portal cliente | “Mi cuenta” liviana dentro del sitio + enlace al portal completo |
| PROC-055 CMS | Blog (artículos `publicado`), banner de anuncio (CMS-02), política de cancelación (CMS-05) |
| PROC-059 Notificaciones | Confirmaciones, vouchers, acuses de lead, reclamos, doble opt-in |
| Reglas transversales | 4 (no confirmar sin disponibilidad), 5 (precio en backend), 9 (pago asociado a obligación), 10 (datos fiscales válidos), 11 (voucher solo con servicio autorizado), 15 (moneda y TC conservados), 16 (auditoría), 18 (canales no duplican reglas), 22 (idempotencia) |
| Legal Perú | Ley 29733 (consentimiento granular y versionado), Ley 29571 + D.S. 011-2011-PCM (Libro de Reclamaciones, 15 días hábiles) |

---

## 2. Pantallas

Todas sin login (`App.render` trata `sitio/*` como pública; el core dibuja un shell vacío y el módulo pinta cabecera, contenido, pie y chat). Si hay sesión con `clientId` se muestra “Mi cuenta” con el nombre; si la sesión es interna, botón “Back-office”.

| Ruta prototipo | Ruta Next.js (`app/(sitio)/[locale]/…`) | Render | Qué hace | Roles |
|---|---|---|---|---|
| `#/sitio` | `/` | ISR 10 min | Hero azul marino con montañas SVG y **buscador** (destino, fecha, viajeros); destinos destacados; tours y paquetes destacados (`is_featured`); ofertas con **cuenta regresiva**; **salidas grupales garantizadas** con cupos y barra de ocupación; por qué elegirnos; **reseñas de ejemplo** rotuladas como demo; certificaciones y garantías (texto genérico); newsletter con consentimiento Ley 29733 | Público |
| `#/sitio/destinos` | `/destinos` | SSG + ISR 1 h | Grilla de destinos con altitud y nº de experiencias | Público |
| `#/sitio/destinos/{slug}` | `/destinos/[slug]` | SSG + ISR 1 h | Hero, descripción, ubicación (mapa esquemático + coordenadas), **mejor época** (franja de 12 meses derivada de `seasons` + fechas especiales con factor), clima, productos del destino (subárbol de destinos, destino principal o secundario), FAQ, CTA cotizar/WhatsApp; si no hay productos → CTA “viaje a medida” | Público |
| `#/sitio/tours` · `/paquetes` · `/experiencias` · `/traslados` · `/buscar?…` | `/tours`, `/paquetes`, `/experiencias`, `/buscar` | SSR (filtros en query) + cache de API | Listado con **filtros**: destino, tipo, fecha (disponible ese día para N pax), viajeros, duración (≤1 d, 2–4 d, 5+ d), precio mín./máx. en la moneda elegida, categoría, condición física, idioma del guía, privado/compartido; **orden**: recomendados, precio ↑/↓, duración, más vendidos, mejor valorados; tarjetas con imagen, etiquetas (Más vendido, Oferta, Cancelación gratis), rating, duración, modalidad, idiomas, próxima salida o “todos los días · confirmación en 24 h”, precio **desde** (tachado si hay precio web) y ♥ lista de deseos. Estado de filtros en la URL (compartible). Sin resultados → limpiar filtros / cotizar a medida | Público |
| `#/sitio/tour/{slug}?fecha=&pax=` | `/tours/[slug]` | SSG + ISR 5 min (contenido) + fetch cliente (precio/disponibilidad) | **Ficha**: galería (3 imágenes), rating, ruta del destino, guardar, compartir; datos rápidos (duración, grupo, idioma, recojo, modalidad, condición física); resumen; itinerario día a día; incluye / no incluye; requisitos (+ edad mínima); **calendario de disponibilidad con precio adulto por día** y marca de temporada (alta/baja/especial), solo días vendibles; próximas salidas con cupos y “salida garantizada”; punto de encuentro + mapa + línea de operaciones; políticas (cancelación, cambios, no-show, reembolsos, anticipación); FAQ (producto + generales + destino); reseñas de ejemplo; relacionados. **Panel de reserva** (sticky; barra inferior en móvil): fecha, adultos/niños/infantes, opciones de personalización (`config`, delta por persona), extras (seguro de asistencia por persona/día, traslado privado aeropuerto → hotel), desglose y total, “Reservar ahora”, “Añadir al carrito”, “Consultar por WhatsApp” (mensaje prellenado), “Cotizar a medida” (prellena el formulario) | Público |
| `#/sitio/carrito` | `/carrito` | CSR | Líneas (producto, fecha/hora, pax, opciones, extras, descuento, estado del hold con código `HLD-…`), **temporizador del bloqueo**, “Volver a bloquear” si expiró, quitar; código promocional; resumen (subtotal, extras, descuento, total en moneda elegida); nota “precio final calculado por el servidor”; venta cruzada | Público |
| `#/sitio/checkout` | `/checkout` (paso en estado/URL `?paso=`) | CSR, `noindex` | 5 pasos (`UI.flow`): **1 Comprador** (nombre, email, celular, país, documento; boleta o factura con RUC/razón social/dirección fiscal) → **2 Pasajeros** (por cada viajero: nombres, apellidos, fecha de nacimiento, tipo y nº de documento, nacionalidad, restricciones alimentarias; “el comprador también viaja”) → **3 Revisión** (resumen + aceptación de términos, políticas de cancelación por producto, tratamiento de datos; marketing opcional) → **4 Pago** (total o anticipo; tarjeta / transferencia / Yape-Plin) → **5 Confirmación** (página propia). Resumen lateral con temporizador del hold. Invita a iniciar sesión para autocompletar | Público |
| `#/sitio/confirmacion/{WEB-…}` | `/confirmacion/[code]` | SSR con token del pedido, `noindex` | Número de reserva y pedido, estado, servicios con código de confirmación, **vouchers** (vista del documento), pago (total, pagado, saldo y vencimiento, modalidad, comprobante), **“Qué pasa ahora”** (línea de tiempo según pago confirmado / por validar / anticipo / bajo solicitud), crear cuenta con el mismo correo, WhatsApp. Solo visible desde el navegador que compró o por el cliente dueño | Comprador |
| `#/sitio/cuenta` (sin sesión) | `/cuenta/ingresar`, `/cuenta/registro` | CSR | Ingresar (correo + contraseña; demo: “Ingresar como Gabriela Ríos (U14)”) y crear cuenta (nombre, correo, celular, documento, contraseña ≥ 8, consentimiento) que crea/vincula `crm_clients` y un usuario `customer` | Público |
| `#/sitio/cuenta/reserva` | `/cuenta/mi-reserva` | CSR | “Gestionar mi reserva” sin cuenta: correo + RES-/WEB- → confirmación | Público |
| `#/sitio/cuenta/{reservas\|vouchers\|pagos\|deseos\|datos}` | `/cuenta/…` | CSR | **Mis reservas** (todas las del cliente, estado, total, saldo) + enlace al portal del cliente; **Vouchers** (ver); **Pagos pendientes** (CxC abiertas/vencidas, montos en validación, **Pagar** con tarjeta → aplica pago y emite vouchers al completar); **Lista de deseos**; **Datos personales** (editar, boletín on/off, derechos ARCO); cerrar sesión | `customer`/`passenger` (cualquiera con `clientId`) |
| `#/sitio/ofertas` | `/ofertas` | ISR 5 min | Promociones con código y automáticas + precios web (tarifa canal E-commerce vs. Directo), cuenta regresiva a `valid_to`, copiar código; salidas garantizadas | Público |
| `#/sitio/a-medida?destino=&producto=&fecha=&pax=` | `/viajes-a-medida` | SSG + form | Formulario de cotización (destinos múltiples, inicio, regreso, flexibilidad, adultos, niños, presupuesto p.p., intereses, hotel, tipo de servicio, comentarios, datos de contacto, canal preferido, consentimiento) → lead + confirmación con código y **SLA 24 h hábiles (4 h en horario)** | Público |
| `#/sitio/blog` · `/blog/{slug}` | `/blog`, `/blog/[slug]` | ISR 10 min | Artículos `cms_contents` tipo Artículo `publicado` + artículos de ejemplo rotulados; detalle con tours relacionados y CTA | Público |
| `#/sitio/nosotros` | `/nosotros` | SSG | Historia, cifras (ejemplo), oficinas (`iam_sedes`), garantías, datos legales | Público |
| `#/sitio/contacto` | `/contacto` | SSG + form | Formulario (motivo, nº de reserva opcional, consentimiento) → conversación del inbox canal Formulario (+ lead si motivo “cotizar”); canales de atención y oficinas | Público |
| `#/sitio/legal/{terminos\|privacidad\|cancelacion\|cookies}` | `/legal/[slug]` | SSG | Términos (anticipo/saldo, responsabilidad, reclamos), privacidad (Ley 29733, ARCO, finalidades, transferencia a proveedores), cancelación (CMS-05 publicado + política por producto), cookies | Público |
| `#/sitio/libro-reclamaciones` | `/libro-reclamaciones` | SSG + form | Hoja de reclamación pública equivalente a `#/libro-reclamaciones` (esa ruta requiere sesión y la usan cliente/pasajero desde su portal; el pie enlaza a ella si hay sesión externa). Consumidor (o menor + apoderado), establecimiento, bien contratado, tipo reclamo/queja, detalle, pedido, declaración → constancia con código y fecha límite | Público |
| Chat flotante | componente global | CSR + WebSocket | Formulario inicial (nombre, email/WhatsApp, consulta, consentimiento) → conversación `Web chat` en el inbox; respuesta automática; muestra respuestas de asesores (nunca notas internas) | Público |
| Pie de página | layout | — | Razón social, RUC, direcciones de sedes, WhatsApp/teléfono/emergencias (texto seleccionable), horario, redes (texto), medios de pago (texto, sin logos), enlaces legales, Libro de Reclamaciones, TC del día | Público |

---

## 3. Modelo de datos PostgreSQL

El sitio reutiliza tablas de otros dominios (no las redefine): `catalog_products`, `catalog_product_rates`, `catalog_seasons`, `catalog_product_departures`, `catalog_availability_holds` (02), `catalog_product_promotions`, `channel_ecom_orders`, `channel_ecom_payment_attempts` (08), `booking_reservations`, `booking_reservation_services`, `booking_passengers` (04), `fin_receivables`, `fin_payments` (07), `ops_vouchers` (05), `crm_clients`, `crm_leads` (01), `collab_conversations`, `collab_messages` (10), `crm_claims` (12), `mkt_contacts_subscribers`, `mkt_consents` (14), `cms_contents` (08), `iam_users` (00). Abajo: tablas **nuevas** y **columnas agregadas**.

### 3.1 `channel_web_carts` (prototipo: `DB.webCarts`) — nueva
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK; RLS |
| code | varchar(20) | no | `CART-00001`; `UNIQUE (tenant_id, code)` |
| token_hash | char(64) | no | SHA-256 del token opaco guardado en cookie `ah_cart` (HttpOnly, SameSite=Lax, 30 días); `UNIQUE (tenant_id, token_hash)` |
| crm_client_id | uuid | sí | FK `crm_clients`; se asigna al iniciar sesión (fusión de carritos) |
| promo_code | varchar(40) | sí | código ingresado (se valida en cada cálculo) |
| currency | char(3) | no | moneda de visualización/cobro elegida (`PEN`,`USD`) |
| locale | varchar(5) | no | `es`,`en` |
| status | varchar(20) | no | `CHECK (status IN ('activo','convertido','abandonado','expirado'))` |
| order_id | uuid | sí | FK `channel_ecom_orders` al convertirse; `UNIQUE` |
| utm | jsonb | sí | `{source, medium, campaign, term, content, gclid, fbclid}` capturado en la primera visita |
| last_activity_at | timestamptz | no | índice parcial `WHERE status='activo'` (job de abandono) |
| created_at, updated_at | timestamptz | no | |
| lock_version | integer | no | default 0 |

### 3.2 `channel_web_cart_items` (prototipo: `webCarts[].items`) — nueva
| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | |
| cart_id | uuid | no | FK ON DELETE CASCADE; índice |
| line_no | smallint | no | `L001`… |
| product_id | uuid | no | FK; debe cumplir ECO-BR-02 al añadir y al pagar |
| service_date | date | no | |
| departure_id | uuid | sí | FK salidas; obligatorio si el producto tiene modelo `POR_SALIDA` |
| pax_adults / pax_children / pax_infants | smallint | no | `CHECK (pax_adults >= 1 AND pax_children >= 0 AND pax_infants >= 0 AND pax_infants <= pax_adults)` |
| options | jsonb | no | `{group_index: option_index}` de `catalog_product_configs` |
| extras | jsonb | no | `{insurance: bool, airport_transfer: bool}` (ver SIT-BR-10) |
| hold_id | uuid | sí | FK `catalog_availability_holds`; `UNIQUE` |
| hold_expires_at | timestamptz | sí | copia para el temporizador |
| added_at | timestamptz | no | |
El precio **no** se guarda en la línea: se recalcula en cada lectura (RT-5); el snapshot se congela en el pedido.

### 3.3 `channel_ecom_orders` — columnas agregadas (tabla definida en 08 §3.6)
| columna | tipo | null | notas |
|---|---|---|---|
| cart_id | uuid | sí | FK `channel_web_carts` |
| sales_channel | varchar(20) | no | default `sitio_web` (`sitio_web`,`tienda_backoffice`) |
| locale | varchar(5) | no | idioma de la compra (plantillas de email) |
| fx_rate | numeric(12,6) | no | TC usado para convertir líneas a la moneda del pedido (RT-15) |
| pay_mode | varchar(10) | no | `CHECK (pay_mode IN ('total','anticipo'))` |
| deposit_pct | numeric(5,2) | no | 100, 30 o 40 |
| amount_due_now | numeric(14,2) | no | total o anticipo |
| balance_due_date | date | sí | obligatorio si `pay_mode='anticipo'` |
| billing | jsonb | no | `{type:'Boleta'|'Factura', doc_type, doc_number, name, fiscal_address}`; CHECK en Service: Factura ⇒ RUC válido |
| consents | jsonb | no | `{terms_at, cancellation_at, privacy_at, marketing_at, policy_version}` |
| access_token_hash | char(64) | no | token de la página de confirmación / “gestionar mi reserva” |
`product_id`, `departure_id`, `service_date`, `pax_*` pasan a ser **nulos** para pedidos multiproducto (se mantienen para compatibilidad con la tienda de 08): el detalle vive en `channel_ecom_order_lines`. `status` agrega el valor `pago_por_validar` (ver §4.2).

### 3.4 `channel_ecom_order_lines` — nueva
| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | |
| order_id | uuid | no | FK; índice |
| line_no | smallint | no | |
| product_id | uuid | no | FK |
| product_version | varchar(10) | no | snapshot (PRD-BR-009) |
| service_date | date | no | |
| departure_id | uuid | sí | FK |
| pax_adults/children/infants | smallint | no | |
| rate_id | uuid | no | tarifa aplicada (canal E-commerce o Directo) |
| season_id | uuid | sí | temporada aplicada |
| season_factor | numeric(6,4) | no | factor relativo a la temporada base de la tarifa |
| unit_adult / unit_child | numeric(14,2) | no | incluye deltas de opciones |
| units | smallint | sí | vehículos (productos `TRASLADO`, precio por servicio) |
| config_snapshot | jsonb | no | `[{group, option, delta_pp}]` |
| extras_snapshot | jsonb | no | `[{code:'INS'|'TRF', label, amount, currency}]` |
| subtotal / discount / total | numeric(14,2) | no | en la moneda de la tarifa |
| currency | char(3) | no | moneda de la tarifa |
| promotion_id | uuid | sí | promo aplicada (no acumulable) |
| cost_total | numeric(14,2) | no | nunca expuesto al comprador |
| hold_id | uuid | sí | FK hold consumido |
| reservation_service_id | uuid | sí | FK `booking_reservation_services` |

### 3.5 `channel_ecom_passengers` — columnas agregadas (08 §3.7)
`birth_date date NOT NULL`, `nationality varchar(60) NOT NULL`, `diet varchar(120)`, `pax_type` agrega `'INF'` (`CHECK (pax_type IN ('ADT','CHD','INF'))`), `first_name`/`last_name varchar(80)` (reemplazan `full_name`, que queda generado). Documento obligatorio para todos los pasajeros (entradas y trenes nominativos). `UNIQUE (order_id, doc_type, doc_number)`.

### 3.6 `channel_ecom_payment_attempts` — columnas agregadas (08 §3.8)
`method varchar(20) NOT NULL CHECK (method IN ('tarjeta','transferencia','yape','plin'))`, `operation_ref varchar(60)` (nº de operación bancaria / código Yape), `result` agrega `'por_validar'`, `amount numeric(14,2)`, `currency char(3)`, `idempotency_key varchar(80)` `UNIQUE (tenant_id, idempotency_key)`.

### 3.7 `channel_wishlist_items` (prototipo: `DB.wishlists[clientId]`) — nueva
| columna | tipo | null | notas |
|---|---|---|---|
| tenant_id, crm_client_id | uuid | no | PK compuesta con `product_id` |
| product_id | uuid | no | FK |
| created_at | timestamptz | no | |
Anónimos: lista en `localStorage` (`ah_site_wish`); se fusiona al iniciar sesión.

### 3.8 `channel_web_events` (prototipo: `DB.webEvents`, últimos 300) — nueva, particionada por mes
| columna | tipo | null | notas |
|---|---|---|---|
| id | bigint identity | no | PK (volumen alto; no uuid) |
| tenant_id | uuid | no | |
| occurred_at | timestamptz | no | partición `RANGE (occurred_at)` mensual |
| session_id | varchar(40) | no | cookie de analítica (solo con consentimiento) |
| client_id | uuid | sí | |
| event | varchar(40) | no | ver §8.4 |
| params | jsonb | no | `item_id`, `value`, `currency`, `transaction_id`, `filters`… |
| locale, currency | varchar(5), char(3) | no | |
| utm | jsonb | sí | |
Retención 13 meses; agregados diarios en `channel_web_funnel_daily` (materializada) para el embudo de 08.

### 3.9 `crm_leads` — columnas agregadas (01)
`web_request jsonb` (`{budget, return_date, flexibility, interests[], hotel, service_mode, country, preferred_contact, adults, children}`), `matched_client_id uuid` (cliente existente con el mismo email), `sla_due_at timestamptz` (created_at + 24 h hábiles), `consent jsonb` (`{data_processing, ts, policy_version}`), `product_id` (ya existente) y `source` con valores `Formulario web`, `Formulario de contacto`. `owner_id` **NULL** (sin asignar).

### 3.10 `iam_users` / `crm_clients` — columnas agregadas
`iam_users.created_via varchar(20)` (`backoffice`, `sitio_web`), `email_verified_at`. `crm_clients.consents jsonb` (snapshot; el registro vigente está en `mkt_consents`).

### 3.11 `catalog_products` — contenido del sitio (columnas / tablas satélite agregadas)
| elemento | tipo | notas |
|---|---|---|
| `difficulty` | varchar(30) | `CHECK IN ('facil','moderada','moderada_exigente','exigente')` (prototipo: mapa `PX`) |
| `guide_languages` | char(2)[] | `{ES,EN,DE,FR}` — filtro “idioma” |
| `pickup_text` | varchar(200) | texto de recojo / punto de encuentro público |
| `catalog_product_media` | tabla | `(product_id, position, storage_key, alt_i18n jsonb, caption_i18n jsonb, width, height, blurhash)`; mínimo 3 para publicar en el sitio (galería) |
| `catalog_product_translations` | tabla | `(product_id, locale, name, short, long, itinerary jsonb, inclusions jsonb, exclusions jsonb, requirements jsonb, faqs jsonb, seo_title, seo_description, slug)`; `UNIQUE (tenant_id, locale, slug)` |
| `rating_avg`, `rating_count` | numeric(3,2), integer | read model desde encuestas post-viaje verificadas (reemplaza las reseñas de ejemplo) |
| `catalog_destination_contents` | tabla | `(destination_id, locale, slug, tagline, description, climate, altitude_text, faqs jsonb, hero_media_id)` — contenido de las páginas de destino (prototipo: `DEST`) |

### 3.12 `cms_contents` — uso
Blog = `type='Artículo' AND status='publicado'` (+ `slug`, `seo_*`); anuncio superior = `type='Banner' AND status='publicado' AND channel ILIKE '%Web%'`; legales = `type='Página legal'`. Los artículos de ejemplo del prototipo (`POSTS`) no se migran.

### 3.13 Restricciones clave
- Todas las tablas con `tenant_id` + RLS; el tenant del sitio se resuelve **por host** (`{slug}.dominio` o dominio propio → `tenants.domain`), nunca por parámetro.
- `catalog_availability_holds.reference_type='cart'` y `reference_id=cart_id` para holds de carrito.
- Un hold vivo por línea (`UNIQUE (hold_id)` en cart_items).
- `channel_ecom_orders.reservation_id UNIQUE`: un pedido ⇒ una reserva con N servicios.

### 3.14 v2.1 — Snapshot del motor en la reserva web (`booking_reservations.booking jsonb` + tablas normalizadas)
Desde v2.1 **cada línea del carrito genera una reserva** (un pedido ⇒ N reservas; `channel_ecom_orders.reservation_ids uuid[]`, `reservation_id` = primera, se elimina el `UNIQUE` de §3.13). Contrato compartido del prototipo: `DB.reservations[].booking` y `passengers[]`.

| Columna / clave | Tipo | Null | Notas |
|---|---|---|---|
| `booking.productId` | uuid → `catalog_products` | no | Producto de la línea |
| `booking.date` | date | no | Fecha de inicio |
| `booking.pax` | jsonb `{adt,chd,inf}` | no | Composición validada con `BE.validatePax` |
| `booking.currencyDisplay` | char(3) | no | Moneda que vio el cliente (cualquiera activa) |
| `booking.chargeCurrency` | char(3) | no | `CHECK IN (settings.chargeCurrencies)` (PEN/USD); = `booking_reservations.currency` |
| `booking.fxRate` | numeric(12,6) | no | Unidades de `currencyDisplay` por 1 unidad de `chargeCurrency` al confirmar (RT-15) |
| `booking.lines[]` | jsonb | no | Copia de `BE.quote().lines` **sin** removidas: `key, kind(base/addon/lodging/surcharge/info), label, qty, unit, amount, removable` (montos en `chargeCurrency`) |
| `booking.adjustments[]` | jsonb | no | Descuentos/recargos del motor (grupo, anticipada, última hora, promo, recargo tarjeta) |
| `booking.total` / `totalDisplay` | numeric(14,2) | no | Total cobrado / total mostrado |
| `booking.lodging[]` | jsonb | sí | `{legId, city, hotel, roomType, rooms, nights}` → normalizado en `booking_lodging_rooms` (FK hotel/proveedor) |
| `booking.rooms` / `nights` | smallint | no | Totales de `BE.quote()` (sin tramos quitados) |
| `booking.addons[]` | jsonb | sí | `{id, name, qty, amount, byType{ADT,CHD,INF}}` → `booking_addons` |
| `booking.removedLines[]` | jsonb | sí | Ítems que el cliente quitó en la previsualización (auditoría comercial) |
| `booking.pickup` | jsonb | sí | `{point, address, flight, arrival}`; null si R-PICKUP apagada |
| `booking.comments` | varchar(≤ maxChars) | sí | R-COMMENTS |
| `booking.payment` | jsonb | no | `{mode: total|adelanto|pagar_despues, depositPct, deposit, balance, balanceDue, holdHours, holdUntil, method}` |
| `booking.companionsPending` / `companionsDue` | boolean / date | sí | R-COMPANIONS con `when = antes_de_viaje` |
| `booking.altitudeDeclaration` | jsonb | sí | `{accepted, at, maxAltitude}` — sin datos de salud (R-ALTITUDE) |
| `booking.rulesApplied[]` | varchar[] | no | Ids de reglas activas usadas (trazabilidad de precio) |

`booking_passengers` (columnas agregadas): `relationship varchar(20) CHECK IN ('Titular','Cónyuge / pareja','Hijo/a','Padre / madre','Hermano/a','Amigo/a','Otro')`, `age smallint` (a la fecha del servicio), `email`, `phone`, `is_lead boolean` (índice único parcial `(reservation_id) WHERE is_lead`), `pending boolean` (datos por completar). Servicios web: `pax integer` (asientos) + `pax_detail jsonb`, `kind` (`adicional|alojamiento|recargo`), `addon_id`, `rooms`, `nights`.
`fin_receivables.kind varchar(20) CHECK IN ('total','adelanto','saldo','pago_posterior')`: el cobro inmediato aplica siempre a `total|adelanto|pago_posterior`, nunca al saldo aunque venza hoy.
`catalog_availability_holds`: además de `departure_id`, `product_id` + `service_date` (disponibilidad por rangos, `BE.heldOn`). `channel_web_cart_items`: `addons jsonb`, `lodging jsonb`, `no_lodging`, `removed_line_keys text[]`, `source` (`sitio|widget`).

---

## 4. Máquinas de estado

### 4.1 Carrito (`channel_web_carts.status`)
| De | A | Disparo | Efecto |
|---|---|---|---|
| — | activo | Primer “Añadir al carrito” | Token en cookie; evento `add_to_cart` |
| activo | activo | Añadir/quitar línea, código, cambio de moneda | Quitar línea ⇒ hold `liberada` |
| activo | convertido | Pedido creado y pago aprobado o registrado (transferencia/Yape) | `order_id`; cookie eliminada |
| activo | abandonado | Job: sin actividad 24 h y con email conocido | Email de recuperación (con consentimiento de marketing) |
| activo | expirado | Job: sin actividad 30 días | Holds ya expirados; se purga |

### 4.2 Pedido web (extiende 08 §4.2)
| De | A | Disparo | Efectos |
|---|---|---|---|
| — | pago_pendiente | Primer “Pagar/Registrar pago” | Cliente (reutiliza por email o sesión), reserva `pendiente_pago` con N servicios `solicitado`, CxC (total o anticipo + saldo), pedido y líneas; Audit `crear`, `crear_ecommerce` |
| pago_pendiente \| pago_rechazado | pago_rechazado | Tarjeta rechazada | Intento `rechazado`; `fin_payments` `rechazado`; **holds se mantienen hasta su vencimiento**; email `pago.rechazado` |
| pago_pendiente \| pago_rechazado | confirmado | Tarjeta aprobada (webhook) | Pago `aplicado` a la CxC del día; **consume cupos** (hold → `confirmada`, `sold += pax`); servicios con salida y seguro → `confirmado` + código; reserva `confirmada` si todos confirmados, si no `pendiente_proveedor`; vouchers solo si `pay_mode='total'` |
| pago_pendiente | pago_pendiente (sub-estado `por_validar`) | Transferencia / Yape registrado | Pago `pendiente` (ref. operación); **consume cupos** (retenidos para la reserva); reserva `pendiente_pago`; notifica Finanzas |
| por_validar | confirmado | Finanzas valida el abono (Conciliación, 07) | Pago `aplicado`; igual que aprobado con tarjeta |
| por_validar | cancelado | Finanzas rechaza el abono o vence plazo de validación (4 h hábiles, **propuesto**) | Libera cupos, reserva `cancelada` |
| pago_pendiente \| pago_rechazado | expirado | Job al vencer el hold sin pago (ECO-BR-11) | Libera, cancela reserva y CxC |

### 4.3 Hold de carrito (`catalog_availability_holds`, 02 §4.5)
`hold` (al añadir, 30 min) → `confirmada` (al pagar/registrar pago) · `liberada` (quitar línea) · `expirada` (vence: job JOB-01 o al renderizar el sitio). “Volver a bloquear” crea un **hold nuevo** si hay cupos. Antes de cobrar se revalida: si el hold venció y no hay cupos → bloqueo (RT-4).

### 4.4 Reserva creada por el sitio
`pendiente_pago` → `confirmada` | `pendiente_proveedor` (tarjeta aprobada) → flujo normal de 04. Con anticipo: `confirmada` con CxC de saldo `abierta`; al pagar el saldo en Mi cuenta y quedar todas las CxC `pagada` → emisión de vouchers.

### 4.5 CxC del pedido
Total: 1 CxC `Pago total — pedido web WEB-…` (vence hoy). Anticipo: `Anticipo 30|40 % — …` (vence hoy) + `Saldo 70|60 % — …` (vence `balance_due_date`). Estados de 07 (`abierta` → `pagada` / `vencida`).

### 4.6 Lead web, conversación, hoja de reclamación, suscriptor
- Lead: `nuevo` (o `duplicado` si ya hay un lead abierto con el mismo email, con `duplicateOf`) → flujo CRM (01).
- Conversación: `abierta` → flujo inbox (10). Canal `Web chat` (SLA 15 min) o `Formulario` (SLA 120 min).
- Hoja: `recibido` → `en_evaluacion` → `respondido` → `cerrado` (12).
- Suscriptor: `activo` (con doble opt-in pendiente) ↔ `baja` (desde Mi cuenta o enlace); consentimiento `otorgado` ↔ `revocado` (14).

---

## 5. Reglas de negocio

Reglas ECO-BR-01…16 (08 §5.2) aplican tal cual. Nuevas:

| ID | Regla | Dónde se valida |
|---|---|---|
| SIT-BR-01 | Catálogo público = `status='ACTIVE'` ∧ publicado en canal e-commerce ∧ ≥ 1 tarifa vigente (ECO-BR-02). Ficha de producto no publicado → “Este producto no está disponible para la venta en línea (PRD-BR-002)” con CTA a cotizar. | Query `PublicCatalogService` |
| SIT-BR-02 | **Precio por fecha** = tarifa vigente en esa fecha (canal `E-commerce` si existe, si no `Directo`; segmento Adulto/Pasajero; niño: `Niño` o adulto) × `factor(temporada de la fecha) / factor(temporada base de la tarifa)`, redondeo 0.10. Temporada = `Pricing.seasonFor`: activa que contiene la fecha con menor `priority` (fechas especiales priority 0 ganan). Sin tarifa vigente en la fecha → día no vendible (“Tarifa por publicar”). | `PricingService` (RT-5) |
| SIT-BR-03 | “Desde” = mínimo precio adulto sobre los días vendibles (salidas con cupo o próximos 150 días). Precio tachado cuando la tarifa Directo > tarifa web en la misma fecha. | `PricingService` (read model cacheado) |
| SIT-BR-04 | Día vendible: `advance_min ≤ días hasta la fecha ≤ advance_max`; con salidas: existe salida no cancelada ese día y `disponibles ≥ adultos + niños` (disponibles = capacidad − vendidos − holds vivos); sin salidas: servicio diario “confirmación en 24 h”. Infantes (< 2) no ocupan cupo ni pagan. | `AvailabilityService` (RT-4, PRD-BR-012) |
| SIT-BR-05 | Pasajeros por línea: adultos ≥ 1; `min_pax ≤ adultos + niños ≤ max_pax`; infantes ≤ adultos; niños solo si `min_age < 12`; infantes solo si `min_age = 0`; aviso si `min_age ≥ 2` y hay niños. | FormRequest + panel |
| SIT-BR-06 | **Hold al añadir al carrito**: productos con salida bloquean `adultos + niños` durante `settings.hold_minutes` (30). Temporizador visible en carrito y checkout; al vencer → `expirada`, toast y opción “Volver a bloquear”. Un hold por línea; quitar línea libera. | `CartService` + job |
| SIT-BR-07 | Revalidación antes de cobrar: si el hold no está vivo, se reintenta bloquear; si no hay cupos → 409 “La salida del … ya no tiene cupos. Actualice su carrito.” | `CheckoutService` |
| SIT-BR-08 | Código promocional a nivel de carrito: se aplica a cada línea cuyo producto tenga esa promo activa (canal E-commerce/Todos, vigente hoy). Descuento por cantidad automático (≥ 4 pax por línea). No acumulables: gana el mayor por línea. Código que no aplica a ninguna línea → “El código no aplica a los productos de su carrito o no está vigente” (no bloquea, se ignora). Los extras no se descuentan. | `PricingService` |
| SIT-BR-09 | **Anticipo**: solo si la primera fecha del carrito está a ≥ 15 días: 30 % si ≥ 30 días, 40 % entre 15 y 29 días; si < 15 días → solo pago total (“No disponible: su viaje inicia en menos de 15 días”). Saldo vence 15 días antes del inicio (o hoy si ya pasó). Modalidad inmutable una vez creado el pedido. | `CheckoutService` |
| SIT-BR-10 | Extras: seguro de asistencia US$ 6 por pasajero (incl. infantes) por día de duración (proveedor PRV-014, póliza inmediata al confirmar el pago); traslado privado aeropuerto → hotel (precio del producto PRD-005 por vehículo de hasta `max_pax`) solo para productos de Cusco que no sean paquetes; no se ofrecen en el propio traslado. Se crean como servicios separados de la reserva. | `PricingService` / `CheckoutService` |
| SIT-BR-11 | Productos `TRASLADO`: precio por vehículo = tarifa × ⌈pax totales / `max_pax`⌉. | `PricingService` |
| SIT-BR-12 | Multimoneda: cada línea se calcula en la moneda de su tarifa; el carrito y el pedido se expresan en la moneda elegida (PEN/USD) con `settings.fx` del día; el TC se guarda en pedido y reserva (RT-15). Yape/Plin cobra en PEN (conversión mostrada). | `PricingService` |
| SIT-BR-13 | Comprador: nombres y apellidos (≥ 2 palabras), email válido, celular ≥ 7 dígitos, documento válido: DNI 8 dígitos; CE 9–12 alfanum.; pasaporte 6–15 alfanum. Factura: RUC 11 dígitos que inicia en 10/15/17/20, razón social y dirección fiscal obligatorias (RT-10). | FormRequest |
| SIT-BR-14 | Pasajeros = grupo más grande del carrito (máximo de adultos, niños e infantes entre líneas). Cada uno: nombres, apellidos, nacimiento, documento válido y único en el pedido, nacionalidad. Edad a la primera fecha: ADT ≥ 12, CHD 2–11, INF < 2; además ≥ `min_age` del producto. | FormRequest |
| SIT-BR-15 | Consentimientos obligatorios para pagar: términos, políticas de cancelación de cada producto (se listan), tratamiento de datos incl. transferencia a proveedores. Marketing opcional (crea/reactiva suscriptor + consentimiento email). Se guarda fecha/hora y `policy_version` (PDP-2026.1). | FormRequest + `ConsentService` |
| SIT-BR-16 | Tarjeta: sandbox aprobadas 4111 1111 1111 1111, 5555 5555 5555 4444, 3782 822463 10005, 3622 720627 1667; rechazada 4000 0000 0000 0002 (código 51); otras → código 14. Nunca PAN/CVV en el backend (tokenización de la pasarela, ECO-BR-16). Rechazo no libera cupos ni anula la obligación; se puede reintentar. | Adapter de pagos |
| SIT-BR-17 | Transferencia/Yape: exige nº/código de operación; el pago queda `pendiente` (“por validar”), los cupos se consumen y la reserva queda `pendiente_pago`; Finanzas valida en ≤ 4 h hábiles (conciliación). Vouchers recién al aplicarse. | `CheckoutService` + Finanzas |
| SIT-BR-18 | Vouchers solo para servicios `confirmado` y cuando todas las CxC del pedido están pagadas (pago total o saldo completado) (RT-11). El seguro genera su voucher/certificado. | `VoucherService` |
| SIT-BR-19 | Página de confirmación y “gestionar mi reserva”: acceso con token del pedido (cookie del navegador que compró), sesión del cliente dueño, o correo + código RES/WEB exactos. Nunca expone costos, márgenes ni datos de otros clientes. Consultas fallidas se auditan y tienen rate limit (5/min/IP). | Policy pública |
| SIT-BR-20 | Mi cuenta: solo usuarios con `client_id` (customer/passenger/B2B con cliente). Pagar saldo = monto de la CxC − pagado − pagos pendientes de validación; si hay un pago por validar que cubre la CxC, no se muestra “Pagar”. | Policy + Service |
| SIT-BR-21 | Registro: email único en `iam_users`; si existe `crm_clients` con ese email se vincula (no se duplica), si no se crea (Persona, Retail, tags Web). Contraseña ≥ 8 (producción: política del tenant, hash Argon2id, verificación de email). Login fallido auditado; rate limit 5/min. Al iniciar sesión se fusiona la lista de deseos local y el carrito se vincula al cliente. | `AuthController` público |
| SIT-BR-22 | **Lead web** (viajes a medida / contacto “cotizar”): ≥ 1 destino, fecha futura (≥ 2 días) salvo “Aún no defino fechas”, regreso ≥ inicio, ≥ 1 adulto, nombre, email, teléfono, consentimiento. Se crea `nuevo` **sin asignar** (fuente Web) o `duplicado` si hay lead abierto con el mismo email; `score` inicial = 40 + fecha 12 + pax≥4 10 + teléfono 6 + presupuesto ≥ US$ 2 000 12 + producto 8 (máx. 95); `sla_due_at` = +24 h hábiles. Notifica a gerencia (cola) y acuse al cliente. | FormRequest + `LeadIntakeService` |
| SIT-BR-23 | Contacto: nombre, email, mensaje ≥ 10, consentimiento → conversación canal `Formulario` (inbox) vinculada a cliente si el email existe; motivo “Quiero cotizar un viaje” también crea lead. | Service |
| SIT-BR-24 | Chat web: nombre, email o WhatsApp, consulta, consentimiento → conversación `Web chat` sin asignar; respuesta automática inmediata (no cuenta como primera respuesta del SLA); el widget muestra mensajes del contacto, del asesor y del sistema, **nunca** notas internas ni eventos. | Service + Reverb |
| SIT-BR-25 | Newsletter: email válido + consentimiento explícito (casilla no premarcada). Existente activo → “ya está suscrito”; en baja → reactiva. Doble opt-in por email. | `ConsentService` (14) |
| SIT-BR-26 | Libro de Reclamaciones público: consumidor (o menor + apoderado), documento válido, email, teléfono, domicilio, establecimiento (sede), bien contratado, tipo Reclamo/Queja, detalle ≥ 15, pedido, declaración. Correlativo por sede `LR-{LIM|CUS|AQP}-AAAA-000001`, plazo 15 días hábiles con feriados (`crm_claim_book_settings`), constancia por email. Mismo servicio que el portal y el registro presencial (12). | `ClaimBookService` |
| SIT-BR-27 | Reseñas: en el prototipo son **de ejemplo** y se rotulan; en producción solo reseñas verificadas (pasajero con reserva completada que respondió la encuesta post-viaje), moderadas; el rating mostrado es el read model. | Job + moderación |
| SIT-BR-28 | Idioma y moneda: preferencia en cookie; contenidos traducidos cuando existan (fallback a ES con aviso); precios siempre convertidos con el TC del día y leyenda del TC en el pie. | Middleware Next.js |

### 5.1 v2.1 — Reglas configurables que gobiernan el sitio (`DB.settings.bookingRules`)
| Regla | Comportamiento en el sitio | Si está apagada |
|---|---|---|
| R-AVAIL-RANGES | Calendario con estados por día (`BE.month`: disponible, ocupado/cerrado, agotado, sin cupo para el grupo, fuera de plazo, no opera), leyenda de rangos (`BE.rangesSummary`) y «próxima fecha disponible» (`BE.nextAvailable`) | Sin leyenda de rangos; el motor trata el producto como disponible (salidas programadas siguen mandando) |
| R-CUTOFF / R-WEEKDAYS | Días fuera de plazo / no operativos deshabilitados; nota «reserve con X h» | Días habilitados |
| R-HOLD | Bloqueo de cupos `minutes` al añadir al carrito (`catalogHolds` con `productId/date`) | Sin bloqueo ni temporizador |
| R-MIN-ADULTS, R-CHILD-PER-ADULT, R-INFANT-FREE, R-MAX-PAX | Selector por tipo con edades del producto (`BE.paxRules`), chips de límites, validación en vivo (`BE.validatePax`), «+» deshabilitado al alcanzar el límite por adulto | Chip y límite desaparecen; niños a tarifa adulto e infantes pagan (lo decide BE) |
| R-COMPANIONS (`when`, `requireDocument`, `daysBeforeIfLater`) | Paso «Acompañantes»: nombre, documento, nacionalidad, nacimiento (edad validada con `BE.ageType` en cada línea), parentesco, email/teléfono opcionales; con `antes_de_viaje` casilla «completar después» y botón «Completar acompañantes» en Mi cuenta | Paso omitido; acompañantes como «Adulto 2 (por completar)» |
| R-NOMINATIVE | Documento obligatorio de acompañantes en productos con entradas nominativas (Machu Picchu) | Según `requireDocument` |
| R-ALTITUDE (`minAltitude`) | Declaración obligatoria en el pago si el itinerario supera la altitud (Puno 3 812 m, Humantay 4 200 m); solo se guarda la aceptación | Sin declaración |
| R-LODGING, R-LODGING-CAPACITY, R-SINGLE-SUPP | «Sin alojamiento / Incluir hoteles»; por ciudad: hotel, habitaciones por tipo, camas vs. personas, «Sugerir habitaciones», totales «X habitaciones · Y noches» (`quote.rooms/nights`) | Sección oculta; líneas de hotel no se cotizan |
| R-ADDONS | Adicionales con cantidad por tipo de pasajero (máx. = pasajeros de ese tipo) | Sección oculta |
| R-PROMO, R-GROUP-DISC, R-EARLY-BIRD, R-LAST-MINUTE, R-SEASON | Ajustes calculados solo por `BE.quote`; código promocional en el carrito | Campo de código oculto / ajuste no aplicado |
| R-MULTICURRENCY | Selector con todas las monedas activas; aviso «precio referencial; se cobra en PEN o USD» y TC aplicado | Solo monedas de cobro |
| R-DEPOSIT, R-PAY-LATER (`holdHours`), R-PAY-FEE (`cardPct`) | Modalidades total / adelanto (% del producto o regla, saldo y vencimiento) / pagar después (bloqueo N h); recargo por tarjeta en el total | Modalidad o recargo desaparecen |
| R-PREVIEW-REMOVE | Botón «Quitar/Restaurar» en adicionales y alojamiento de la previsualización (siempre obligatoria); ítems base no removibles | Previsualización sin botones |
| R-PICKUP (`askFlight`), R-COMMENTS (`maxChars`) | Paso «Recojo y comentarios»: puntos del producto u «otra dirección / hotel», vuelo y llegada (obligatorios si el punto es aeropuerto), comentarios con contador | Campo/paso omitido |
| R-PAX-MESSAGES | Al confirmar, plantillas activas del evento (`reserva_confirmada`; `reserva_creada`/`reserva_pendiente_pago` si existen) → `DB.paxMessages` enviado/programado según `offsetHours`, respetando `conditions` | No se generan mensajes |

La multiplicidad por carrito: la modalidad de pago ofrecida es la **intersección** de las modalidades de todas las líneas; el cobro se hace en una sola moneda de cobro (la elegida si es de cobro; si no, USD).

---

### 5.2 v2.2 — Reseñas propias verificadas en el sitio (R-OWN-REVIEWS)
| Lugar | Comportamiento (`sitio.js`, datos de `window.OwnReviews`) |
|---|---|
| Tarjeta de producto | Si hay reseñas propias publicadas: estrellas, promedio, (n) y sello «Viaje verificado»; si no, la calificación de catálogo existente |
| Cabecera de la ficha | Chip «Viaje verificado · promedio (n)» que desplaza al bloque |
| Ficha: «Reseñas de viajeros verificados» | Promedio, % recomienda, distribución 5→1, subcalificaciones activas, texto de política (sin moderar por nota), filtro por estrellas (Todas / 5…1 con conteo), 4 reseñas y «Ver las N reseñas»; cada reseña con sello, título, texto, subcalificaciones, respuesta de «Andes Horizonte», autor abreviado, país y fecha de viaje; rótulo «ejemplo (demo)» en las semilla. Va **antes** del bloque de reseñas externas, que sigue rotulado «Reseñas de ejemplo (demo)» |
| Inicio | Sección «Opiniones de viajeros verificados» (3 últimas y promedio global) antes de las reseñas externas |
| Idioma EN | Textos traducidos (Verified trip, Reviews from verified travelers, etc.) |
| Apagado | Regla inactiva, plan sin la regla, producto con `ruleOverrides['R-OWN-REVIEWS']=false` o `show_on_site=false` → no se muestra nada ni se emite `aggregateRating` |

## 6. API REST

Namespace público `/api/v1/public/*`: sin Sanctum de usuario; tenant por `Host`; **rate limit** (Redis) por IP y por token de carrito; CORS solo para dominios del tenant; respuestas cacheables con `Cache-Control` + `ETag` y tags de cache por producto. Operaciones que crean o cobran: `Idempotency-Key` obligatorio (RT-22). Endpoints de cuenta: Sanctum con token de cliente (`abilities: customer`). Los endpoints `/api/v1/public/shop/*` de 08 §6.3 quedan como alias de estos (mismos servicios).

### 6.1 Catálogo y contenido (GET, cache)
| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/public/site` | público | Datos del tenant para cabecera/pie (razón social, RUC, sedes, horarios, redes, WhatsApp, medios de pago, TC del día, idiomas, monedas, banner CMS) | cache 10 min |
| GET | `/public/destinations` · `/public/destinations/{slug}` | público | Destinos con contenido traducido, nº de productos, temporadas (franja 12 meses) | cache 1 h |
| GET | `/public/products?destination=&type=&date=&pax=&duration=&price_min=&price_max=&currency=&category=&difficulty=&language=&modality=&sort=&page[cursor]=` | público | Listado con `from_price`, `list_price`, etiquetas (`best_seller`, `offer`, `free_cancellation`, `instant_confirmation`), próxima salida, rating | cache por query 5 min |
| GET | `/public/products/{slug}` | público | Ficha completa traducida (media, itinerario, incluye, requisitos, políticas, FAQ, config, relacionados, punto de encuentro, JSON-LD) | ISR/cache |
| GET | `/public/products/{slug}/calendar?month=YYYY-MM&pax=&currency=` | público | Por día: vendible, motivo, precio adulto, temporada, salida, cupos | cache 60 s |
| GET | `/public/products/{slug}/departures?from=&to=` | público | Salidas con cupos y `guaranteed` | cache 60 s |
| GET | `/public/departures/guaranteed?limit=` | público | Salidas garantizadas próximas (home/ofertas) | cache 60 s |
| GET | `/public/offers` | público | Promociones vigentes + precios web con `ends_at` | cache 5 min |
| GET | `/public/reviews?product=` | público | Reseñas verificadas | cache 1 h |
| GET | `/public/posts` · `/public/posts/{slug}` · `/public/pages/{slug}` | público | CMS publicado (blog, legales) | ISR |

### 6.2 Precio, disponibilidad, carrito
| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| POST | `/public/price-quote` | público | `{product, date, departure?, adults, children, infants, options, extras, promo_code, currency}` → líneas, descuento, extras, total, `season`, `valid_until` (SIT-BR-02/08/10/11/12) | rate limit 60/min |
| POST | `/public/availability` | público | `{product, date, pax}` → vendible, cupos, motivo (SIT-BR-04) | — |
| POST | `/public/carts` | público | Crea carrito; devuelve cookie/token | Idempotency-Key |
| GET | `/public/carts/current` | token carrito | Carrito con precios recalculados, holds y `hold_expires_at` | — |
| POST | `/public/carts/current/items` | token carrito | Añade línea; crea hold (409 `RT-4` si no hay cupos) | Idempotency-Key; `Cache::lock("dep:{id}")` + `SELECT … FOR UPDATE` |
| PATCH | `/public/carts/current/items/{line}` | token carrito | Cambia pax/opciones/extras (reajusta hold) | lock salida |
| DELETE | `/public/carts/current/items/{line}` | token carrito | Quita línea; libera hold | — |
| POST | `/public/carts/current/items/{line}/hold` | token carrito | Volver a bloquear (SIT-BR-06) | lock salida |
| PUT | `/public/carts/current/promo` · DELETE | token carrito | Aplica/quita código (SIT-BR-08) | rate limit 10/min |
| PUT | `/public/carts/current/preferences` | token carrito | Moneda/idioma | — |

### 6.3 Checkout y pago
| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| POST | `/public/checkout/validate` | token carrito | Valida paso (comprador / pasajeros / consentimientos) sin crear nada → 422 por campo | — |
| POST | `/public/checkout/orders` | token carrito | Crea cliente/pedido/líneas/pasajeros/reserva/servicios/CxC (transacción única) con `pay_mode`; revalida holds (SIT-BR-07) | **Idempotency-Key**; locks de salidas; `lock_version` del carrito |
| POST | `/public/orders/{code}/payments` | token del pedido | `{method:'card', gateway_token}` → intento; `{method:'transfer'|'yape'|'plin', operation_ref}` → pago por validar | **Idempotency-Key** (`sys_idempotency_keys`, TTL 24 h) |
| POST | `/public/orders/{code}/payment-session` | token del pedido | Crea sesión 3-D Secure / checkout embebido de la pasarela (INT-02) | Idempotency-Key |
| GET | `/public/orders/{code}` | token del pedido o cliente dueño | Confirmación: estado, servicios, vouchers, pagos, saldo | — |
| GET | `/public/orders/{code}/vouchers/{id}.pdf` | idem | URL firmada temporal del PDF (cola `documents`) | — |
| POST | `/public/orders/lookup` | público | `{email, code}` → token de acceso (SIT-BR-19) | rate limit 5/min/IP; auditado |
| POST | `/webhooks/payments/{provider}` | firma HMAC del proveedor | Aprobado/rechazado/contracargo; aplica pago y confirma (4.2) | idempotente por `gateway_txn_id` + outbox |

### 6.4 Captación y legal
| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| POST | `/public/leads` | público + captcha invisible | Viaje a medida (SIT-BR-22) → 201 `{code, sla_due_at}` | Idempotency-Key; rate limit 5/h/IP |
| POST | `/public/contact` | público + captcha | Conversación `Formulario` (+ lead) (SIT-BR-23) | Idempotency-Key |
| POST | `/public/chat/sessions` · `/public/chat/sessions/{id}/messages` · WS `private-webchat.{session}` | token de sesión de chat | Chat web (SIT-BR-24) vía Reverb | rate limit |
| POST | `/public/newsletter` · GET `/public/newsletter/confirm/{token}` · POST `/public/newsletter/unsubscribe` | público | Alta con doble opt-in, confirmación y baja (SIT-BR-25) | Idempotency-Key |
| POST | `/public/claims` | público | Hoja de reclamación (SIT-BR-26) → constancia | Idempotency-Key |
| POST | `/public/analytics/events` | público (con consentimiento) | Lote de eventos del embudo (§8.4) | beacon; rate limit |

### 6.5 Cuenta del cliente (Sanctum, `abilities: customer`)
| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| POST | `/public/auth/register` · `/public/auth/login` · `/public/auth/logout` · `/public/auth/password/forgot` · `/public/auth/email/verify` | público / token | SIT-BR-21 | rate limit |
| GET | `/public/me` · PATCH `/public/me` | customer | Datos personales (auditado), preferencia boletín | `lock_version` |
| GET | `/public/me/reservations` · `/public/me/reservations/{code}` | customer (`reservations.view`, solo su `client_id`) | Mis reservas | — |
| GET | `/public/me/vouchers` | customer | Vouchers | — |
| GET | `/public/me/receivables?status=open` | customer | Pagos pendientes con pagos por validar | — |
| POST | `/public/me/receivables/{id}/payments` | customer | Pagar saldo (tarjeta); emite vouchers si queda todo pagado (SIT-BR-18/20) | **Idempotency-Key** |
| GET/PUT/DELETE | `/public/me/wishlist` · `/public/me/wishlist/{product}` | customer | Lista de deseos (fusión al login) | — |
| POST | `/public/me/arco-requests` | customer | Solicitud ARCO (acceso, rectificación, cancelación, oposición) | Idempotency-Key |

---

### 6.6 v2.1 — Motor de reservas público
| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/public/booking-rules` | público | Reglas activas del tenant (id, params) para pintar/ocultar secciones | cache 5 min, tag `rules` |
| GET | `/public/currencies` | público | Monedas activas, `rate`, `chargeCurrencies`, `rateUpdatedAt` | cache 10 min |
| GET | `/public/products/{slug}/availability?month=&pax=` | público | `BE.month` + `rangesSummary` + `nextAvailable` | cache 60 s |
| POST | `/public/quote` | público | `sel` completo (`date, pax, addons[], lodging[], options[], removed[], currency, paymentMode, paymentMethod, promo`) → `BE.quote` | rate limit 60/min |
| POST | `/public/carts/current/items` | token carrito | Acepta `addons`, `lodging`, `no_lodging`, `source=widget` (mismo contrato que `window.SiteCart.add`) | Idempotency-Key; lock producto/fecha |
| PATCH | `/public/carts/current/items/{line}/removed-lines` | token carrito | Quitar/restaurar ítem removible en la previsualización (422 si `removable=false` o R-PREVIEW-REMOVE apagada) | lock_version carrito |
| POST | `/public/checkout/orders` | token carrito | Añade `lead`, `companions[]` (+`complete_later`), `pickup{}`/`comments` por línea, `pay_mode`, `altitude_accepted`; recotiza en servidor y rechaza si difiere | Idempotency-Key |
| PUT | `/customer/reservations/{code}/companions` | cliente dueño | Completar acompañantes pendientes (valida edad por tipo) | lock_version reserva |

### 6.7 v2.2 — Reseñas públicas
| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/api/v1/public/reviews?product=&stars=&lang=&page[cursor]=` | Reseñas propias publicadas + agregado (promedio, conteo, distribución, subcalificaciones); cache Redis 10 min invalidada al moderar |
| POST | `/api/v1/public/reviews/by-token` | Alta desde el enlace firmado de la invitación (Idempotency-Key) |

## 7. Permisos y comportamiento por rol

| Actor | Qué ve y hace |
|---|---|
| Anónimo | Todo el sitio; carrito en cookie; lista de deseos local; checkout como invitado; confirmación solo desde el navegador que compró o con correo + código |
| Cliente (`customer`, p. ej. U14 → CLI-0001) / pasajero | Cabecera “Mi cuenta” con su nombre; checkout autocompletado (nombre, documento, email, celular, nacimiento); Mi cuenta con **solo** sus reservas, vouchers, CxC y deseos; enlace a su portal completo; el pie enlaza el Libro de Reclamaciones del portal (`#/libro-reclamaciones`) |
| Usuario B2B con `clientId` (U13) | Igual que cliente sobre CLI-0007 (compra como cliente directo; tarifas B2B **no** se aplican en el sitio) |
| Usuario interno (U01…U08) | Navega el sitio como visitante; botón “Back-office” en la cabecera; no ve costos ni márgenes; si ingresa en Mi cuenta con un cliente, la sesión interna se reemplaza (solo demo) |
| Back-office relacionado | Pedidos web y embudo: `channels.ecommerce.manage` (08); leads web: cola sin asignar `crm.leads.manage` (01); conversaciones: `inbox.view/manage` (10); hojas: `crm.claims.manage` (12); suscriptores/consentimientos: `marketing.campaigns.manage` (14); validar transferencias/Yape: `finance.payments.register` (07); holds: `catalog.products.manage_availability` (02) |
Datos sensibles nunca expuestos en la API pública: `cost`, `markup`, margen, proveedor de costo, notas internas, IDs internos de otros clientes, datos de guías.

---

## 8. Eventos, jobs (colas Redis) y notificaciones

### 8.1 Eventos de dominio
`CartItemAdded`, `CartHoldCreated`, `CartHoldExpired`, `WebOrderCreated`, `WebPaymentAttempted`, `WebPaymentApproved`, `WebPaymentPendingValidation`, `ReservationConfirmed` (04), `VouchersIssued` (05), `WebLeadCaptured`, `WebContactReceived`, `WebChatStarted`, `NewsletterSubscribed`, `ClaimBookSheetRegistered` (12), `CustomerRegistered`.

### 8.2 Jobs y scheduler
| Job | Cola | Frecuencia | Qué hace |
|---|---|---|---|
| `ExpireAvailabilityHolds` (JOB-01, 02) | critical | cada 1 min | Holds `hold` vencidos → `expirada` (incluye carritos); emite `CartHoldExpired` al socket del carrito |
| `ExpireEcomOrders` (08 ECO-BR-11) | critical | cada 5 min | Pedidos `pago_pendiente/rechazado` sin hold vivo → `expirado` |
| `ExpireUnvalidatedTransfers` (propuesto) | default | cada 30 min | Pagos por validar > 4 h hábiles → alerta a Finanzas; > 24 h → cancelar y liberar |
| `RemindBalanceDue` | notifications | diario 09:00 | Saldo que vence en 7 y 2 días (email + WhatsApp plantilla) |
| `AbandonedCartRecovery` | low | cada hora | Carritos activos 24 h con email y consentimiento de marketing |
| `RefreshCatalogReadModels` | default | al cambiar tarifas/salidas/promos + cada 15 min | `from_price`, etiquetas, rating, `best_seller`; invalida cache/ISR (`revalidateTag`) |
| `GenerateSitemaps` | low | diario 03:00 + al publicar | Sitemaps por idioma |
| `GenerateVoucherPdf` | documents | al emitir | PDF con QR |
| `AggregateWebFunnel` | reports | cada hora | `channel_web_funnel_daily` |
| `SendNewsletterDoubleOptIn` | notifications | al alta | Email de confirmación |

### 8.3 Notificaciones que emite el prototipo (`Notify.push`)
| Evento | Canal | Destino |
|---|---|---|
| `pago.rechazado` | Email | comprador |
| `pago.recibido` | Email | comprador (checkout y pago de saldo) |
| `reserva.confirmada` | Email + WhatsApp | comprador |
| `reserva.recibida` | Email | comprador (transferencia/Yape) |
| `voucher.emitido` | Email | comprador |
| `pedido.web` | Portal | asesor e-commerce (U03) |
| `reserva.pendiente_proveedor` | Portal | Operaciones (U05) |
| `pago.por_validar` | Portal | Finanzas (U07) |
| `lead.nuevo` / `lead.acuse` | Portal / Email | Gerencia (cola) / cliente |
| `inbox.nueva` | Portal | asesor |
| `newsletter.doble_optin` | Email | suscriptor |
| `cuenta.bienvenida` | Email | nuevo cliente |
| `reclamacion.constancia` / `reclamacion.nueva` | Email / Portal | consumidor / responsable (vía ClaimBook) |

### 8.4 Analítica del embudo (prototipo: `DB.webEvents`, modal “Eventos de analítica (demo)” en el pie)
Eventos con nombres GA4: `page_view`, `search`, `view_item_list`, `view_item`, `add_to_wishlist`/`remove_from_wishlist`, `add_to_cart`, `remove_from_cart`, `view_cart`, `apply_promo`, `select_promotion`, `begin_checkout`, `add_shipping_info` (consentimientos), `add_payment_info`, `payment_failed`, `purchase`, `purchase_pending`, `purchase_balance`, `generate_lead` (tailor_made / contact_form / web_chat), `sign_up`, `sign_up_newsletter`, `login`, `share`, `contact_whatsapp`, `select_language`, `select_currency`, `claim_submitted`. Parámetros: `item_id`, `value`, `currency`, `transaction_id`, `items[]`, `payment_type`, `pay_mode`. Producción: GTM + GA4 **solo con consentimiento de cookies analíticas**; `purchase` se envía también server-side (Measurement Protocol) desde el webhook para no perder conversiones; UTM guardado en carrito y pedido para atribución (14).

---

## 9. Auditoría

| Entidad | Acción | Cuándo |
|---|---|---|
| Disponibilidad | `hold`, `liberar_hold`, `expirar_hold`, `consumir_cupos` | Añadir/quitar/expirar/pagar |
| Cliente | `crear_desde_sitio_web`, `crear_desde_registro_web`, `actualizar_datos_sitio` | Checkout, registro, Mi cuenta |
| Usuario | `registro_cliente_web` | Registro |
| Sesión | `login_sitio_web`, `login_fallido_sitio_web`, `logout` | Mi cuenta |
| Pedido web | `crear`, `pago_rechazado` | Checkout |
| Reserva | `crear_ecommerce`, `cambio_estado` | Checkout |
| Pago | `aplicar`, `registrar` (por validar), `pago_rechazado` | Checkout / saldo |
| Voucher | `emitir` | Pago total o saldo |
| Lead | `crear_desde_sitio_web` | A medida / contacto |
| Conversación | `crear` | Contacto / chat |
| Suscriptor | `crear`, `reactivar`, `baja` | Newsletter / checkout / Mi cuenta |
| Libro de reclamaciones | `registrar` | Hoja pública |
| Consulta pública | `buscar_reserva` | Gestionar mi reserva |
Usuario de auditoría = cliente autenticado o `web` (anónimo) + IP + user agent + `session_id`; nunca datos de tarjeta.

---

## 10. Casos de prueba mínimos

### Pest (feature)
1. Catálogo público solo devuelve ACTIVE + e-commerce + tarifa vigente; producto SUSPENDED/DRAFT → 404 con código `PRD-BR-002`; tenant resuelto por host (E2E-08: host de B no ve productos de A).
2. Precio por fecha: 2026-10-09 (media ×1.00), 2026-12-09 (baja ×0.88), 2026-12-30 (Año Nuevo ×1.40) sobre la misma tarifa; sin tarifa en 2027 → no vendible.
3. Tarifa E-commerce vigente (Humantay S/ 129) gana a Directo (S/ 140) y devuelve `list_price`.
4. Añadir al carrito con salida crea hold de adultos+niños; dos carritos concurrentes por los últimos cupos → uno 409 (lock).
5. Hold vencido: job lo expira; checkout lo re-bloquea si hay cupos; sin cupos → 409 SIT-BR-07.
6. PRIMAVERA8 aplica solo a Machu Picchu Full Day (−8 %); con Humantay 4 pax aplica “Grupo 4+” (10 %) sin acumular; código inexistente no bloquea.
7. Anticipo: primera fecha a 10 días → 422 SIT-BR-09; a 20 días → 40 %; a 60 días → 30 % + CxC saldo con vencimiento −15 días.
8. Factura con RUC inválido → 422; DNI de 7 dígitos → 422; pasajero CHD con 12 años → 422.
9. Pago rechazado (4000…0002): pedido `pago_rechazado`, pago `rechazado`, cupos sin consumir, hold vivo; reintento aprobado → confirmado, `sold` +pax, vouchers.
10. Transferencia: pago `pendiente`, cupos consumidos, reserva `pendiente_pago`, sin vouchers; validación de Finanzas → confirmado.
11. Idempotencia: repetir `POST /public/checkout/orders` y `/payments` con la misma `Idempotency-Key` no duplica pedido/pago; webhook repetido con el mismo `gateway_txn_id` no aplica dos veces.
12. Mi cuenta: U14 solo ve CLI-0001; `GET /public/me/reservations/{otra}` → 404; pagar saldo CXC-00714 aplica pago y no emite vouchers mientras CXC-00713 no esté pagada.
13. Lead web: `owner_id` null, `status` nuevo/duplicado, `sla_due_at`, consentimiento registrado.
14. Libro de reclamaciones público: correlativo por sede, `due_date` = +15 hábiles con feriados (p. ej. 2026-10-08 feriado).
15. Newsletter sin casilla → 422; alta con casilla → suscriptor + consentimiento `otorgado` con `policy_version`.
16. API pública nunca serializa `cost`, `markup`, `margin`.

### Playwright (sitio Next.js) — equivalentes a `e2e_sitio.js` / `e2e_sitio2.js` / `sweep_sitio.js`
- **E2E-SIT-01** Anónimo: buscar Cusco 2 pax → ficha MAPI → fecha → añadir; Humantay + seguro → carrito con 2 holds y temporizador; PRIMAVERA8; checkout con factura; pasajeros; consentimientos; tarjeta rechazada → reintento aprobado → confirmación con RES-, 3 servicios confirmados y 3 vouchers.
- **E2E-SIT-02** Anónimo: paquete Cusco Mágico → anticipo (si ≥ 15 días) + Yape → “pago en validación”, CxC anticipo/saldo.
- **E2E-SIT-03** Viaje a medida → lead sin asignar visible en la cola del CRM; contacto → conversación en el inbox; chat → respuesta automática; newsletter; hoja de reclamación con constancia.
- **E2E-SIT-04** Sesión U14: Mi cuenta (reservas, pagos pendientes con monto en validación, pagar saldo, deseos, datos), checkout autocompletado, logout; registro de cliente nuevo.
- **E2E-SIT-05** EN + USD: títulos, cabecera y fichas en inglés; precios convertidos con TC; `document.title`/`meta description`/JSON-LD por página.
- **E2E-SIT-06** Responsive 400 px y 1440 px en todas las rutas: sin scroll horizontal, sin errores de consola; Lighthouse ≥ 90 (performance, SEO, accesibilidad) en home, listado y ficha.

- **E2E-SIT-07 (v2.1)** PRD-011 anónimo en EUR: calendario 02–06 oct disponible, 07–09 ocupado, 10–14 disponible; 2 adultos + niño de 7 + infante de 2; hoteles en Lima y Cusco (2 habitaciones · 5 noches); Museo Larco ×3; niño con edad 11 rechazado; recojo en aeropuerto con vuelo; previsualización quitando «Cena show» (total recalculado); adelanto 30 % con tarjeta → reserva con `booking`, CxC adelanto pagada + saldo abierta, mensajes de plantilla.
- **E2E-SIT-08 (v2.1)** Mismo caso con U14 a 400 px (titular autocompletado, `clientId` CLI-0001, sin desborde).
- **E2E-SIT-09 (v2.1)** Reglas apagadas por script (R-LODGING, R-ADDONS, R-COMPANIONS, R-PICKUP, R-COMMENTS, R-PREVIEW-REMOVE, R-DEPOSIT, R-MULTICURRENCY, R-AVAIL-RANGES, R-CHILD-PER-ADULT, R-INFANT-FREE, R-HOLD, R-PAX-MESSAGES): solo Fecha/Pasajeros, pasos Titular → Previsualización → Pago, sin «Quitar», solo pago total, monedas PEN/USD, sin errores. Reglas opcionales encendidas (R-PAY-LATER, R-PAY-FEE, R-ALTITUDE, R-COMPANIONS `antes_de_viaje`): pagar después con bloqueo 24 h, recargo 3.5 %, declaración de altura obligatoria, completar acompañantes desde Mi cuenta.
- **Pest v2.1:** `QuoteParityTest` (el total del checkout = `BookingEngine::quote` del servidor; divergencia → 409), `RemovedLineTest` (línea `base` no removible → 422), `CompanionAgeTest`, `ChargeCurrencyTest` (EUR mostrado → cobro USD con `fx_rate` guardado), `RuleToggleTest` (cada regla apagada elimina campo/validación).

---

## 11. Cambios y decisiones respecto a la documentación inicial

**Módulo nuevo: no estaba en la documentación inicial.** DOC-PROC-001 solo define PROC-054 E-commerce como flujo lineal de un producto y el prototipo tenía una “tienda” dentro de Canales. **Motivo:** el cliente pidió “la vista pública la veo muy simple, añade más funcionalidades que otras agencias trabajan, documenta todo”. Se construye el sitio público completo con prácticas de tour operadores y OTAs de actividades.

| Tipo | Cambio | Motivo |
|---|---|---|
| Agregado | Sitio público independiente del back-office (`app/(sitio)`, rutas `sitio/*` sin login) con cabecera, menú, idioma, moneda, carrito, cuenta, WhatsApp, pie legal | Canal B2C real; la tienda de 08 queda como herramienta interna de referencia |
| Agregado | Carrito **multiproducto** y multifecha (`channel_web_carts/_items`, `channel_ecom_order_lines`) | PROC-054 asumía un producto por compra |
| Agregado | Hold automático al añadir al carrito con temporizador y renovación | PROC-020 aplicado al canal web; evita sobreventa durante el checkout |
| Precisado | Precio por fecha = tarifa × factor de temporada (calendario de precios) | PROC-018/019 no definían cómo se ve el precio por día en canales |
| Agregado | Pago **anticipo 30/40 %** con saldo a −15 días y pago del saldo en Mi cuenta; vouchers al completar | Práctica estándar de operadores en Perú; no estaba en PROC-054 |
| Agregado | Transferencia bancaria y Yape/Plin “por validar” | Medios de pago locales frecuentes; enlaza con Conciliación (07) |
| Agregado | Extras (seguro de asistencia, traslado) como servicios separados | Upsell típico de OTAs |
| Agregado | Infantes (< 2) sin costo y sin cupo; validación de edad por fecha de nacimiento | Entradas y trenes nominativos |
| Agregado | Datos de facturación (boleta/factura) en el checkout | RT-10; PROC-050 |
| Agregado | Mi cuenta (registro/login, reservas, vouchers, pagos, deseos, datos, ARCO) y “gestionar mi reserva” sin cuenta | Autoservicio; portal del cliente sigue siendo el completo (08) |
| Agregado | Leads web sin asignar desde “Viajes a medida” y contacto, con SLA 24 h hábiles | Captura de demanda no reservable en línea (PROC-001) |
| Agregado | Chat web y formulario de contacto conectados al inbox omnicanal (10) | Atención unificada |
| Agregado | Newsletter con consentimiento Ley 29733 y doble opt-in conectado a Marketing (14) | Cumplimiento y captación |
| Agregado | Libro de Reclamaciones público dentro del sitio usando el mismo servicio que el portal (12) | Obligación legal; la ruta interna exige sesión |
| Agregado | Páginas de destino con mejor época y FAQ; blog desde CMS; ofertas con cuenta regresiva; salidas garantizadas | Contenido que usan los operadores para SEO y conversión |
| Agregado | Salidas programadas oct–dic 2026 para PRD-001 y PRD-004 (semilla `siteSeedV1`, `SAL-0106…`, `SAL-0405…`) | El seed tenía solo 5 salidas por producto; necesarias para calendario y salidas garantizadas |
| Agregado | Contenido editorial por producto (dificultad, idiomas del guía, recojo, galería, traducciones) y por destino | Necesario para filtros y fichas; hoy en constantes `PX`/`DEST` del módulo |
| Agregado | Multidioma ES/EN y multimoneda PEN/USD | Turismo receptivo |
| Agregado | SEO, datos estructurados, sitemaps, SSR/ISR, analítica de embudo | Canal de adquisición orgánica y medición |
| Cambiado | Endpoints públicos: se consolida `/api/v1/public/*` (este documento); `/api/v1/public/shop/*` de 08 queda como alias | Un solo contrato para Next.js y apps |
| Cambiado | `channel_ecom_orders` admite pedidos multiproducto y estado “por validar” | Ver §3.3 |
| Precisado | Conversaciones creadas por el sitio usan el formato del inbox (`from:'contact'`, `type:'msg'`, canal `Web chat`/`Formulario`, `slaDueAt`) | Compatibilidad con 10 (la instrucción inicial indicaba `cliente/agente`) |

### 11.1 SSR/ISR de Next.js para el sitio
- Grupo `app/(sitio)/[locale]/…` en el **mismo** proyecto Next.js pero con layout propio y middleware que resuelve tenant por host y `locale` (`/es`, `/en`; `es` por defecto sin prefijo opcional).
- **SSG + ISR** (`revalidate`) para home (10 min), destinos (1 h), fichas (5 min), blog/legales (on-demand). **Revalidación on-demand** por tags (`product:{id}`, `destination:{id}`, `offers`) disparada por el backend al publicar/cambiar tarifas, salidas o promociones (webhook interno a `/api/revalidate` con secreto).
- **Precio, calendario, cupos y carrito siempre en vivo** (fetch cliente/Route Handlers con `cache: 'no-store'` o SWR 30–60 s): nunca se sirve disponibilidad desde HTML estático.
- Server Components para contenido; Client Components solo para buscador, filtros, calendario, panel de reserva, carrito, checkout, chat.
- BFF: Route Handlers de Next que llaman a Laravel con clave de servicio del tenant; el token de carrito viaja en cookie HttpOnly del dominio del sitio.

### 11.2 Sitemap y robots
`/sitemap.xml` (índice) → `sitemap-pages`, `sitemap-products-{locale}`, `sitemap-destinations-{locale}`, `sitemap-blog-{locale}` con `lastmod` y `hreflang` alternos (`es`, `en`, `x-default`). `robots.txt`: permite todo salvo `/carrito`, `/checkout`, `/confirmacion`, `/cuenta`; enlaza el sitemap. Canonical por página; parámetros de filtro con `noindex, follow` salvo combinaciones SEO definidas (p. ej. `/tours?destino=cusco` → `/destinos/cusco`).

### 11.3 Datos estructurados (schema.org, JSON-LD)
| Página | Tipo | Campos |
|---|---|---|
| Home / Nosotros | `TravelAgency` | name, legalName, taxID (RUC), address, telephone, email, openingHours, sameAs (redes) |
| Ficha | `TouristTrip` + `Product` | name, description, sku, brand, touristType, itinerary (`ItemList`), `offers` (`Offer`/`AggregateOffer`: price, priceCurrency, availability, validFrom, url), `aggregateRating` **solo con reseñas verificadas** |
| Destino | `TouristDestination` | name, description, geo |
| Blog | `BlogPosting` | headline, datePublished, author |
| Todas | `BreadcrumbList` | migas |
| FAQ | `FAQPage` | cuando la página tiene FAQ visible |
El prototipo inyecta `<script type="application/ld+json" id="ws-ld">` por página y actualiza `document.title` (“… | Andes Horizonte Travel”), `meta description` y `html lang`; al salir del sitio restaura los valores del back-office.

### 11.4 Rendimiento
Objetivos Core Web Vitals (p75 móvil): LCP < 2.5 s, INP < 200 ms, CLS < 0.1. `next/image` con AVIF/WebP, `sizes` y `blurhash`; fuentes con `next/font` (self-hosted, `display: swap`); JS de terceros (GTM, chat) diferido hasta interacción/consentimiento; CDN con cache de HTML ISR y assets inmutables; API pública con cache Redis por tag y `ETag`; paginación por cursor; imágenes de galería lazy salvo la primera (priority). Presupuesto: < 170 KB JS inicial en home.

### 11.5 Multidioma y multimoneda
- Idiomas: `es` (base) y `en`; textos de interfaz en diccionarios (`next-intl`), contenidos en `*_translations` con fallback a `es` marcado. El prototipo traduce cabecera, pie, home, listado, fichas principales y carrito; checkout/legales en español.
- Monedas: PEN y USD; cada tarifa conserva su moneda; conversión con `settings.fx` (TC del día, fuente SBS referencial) mostrada en el pie; el pedido guarda `currency` y `fx_rate`. Cobro en la moneda elegida; Yape/Plin siempre en PEN.

### 11.6 Seguridad y cumplimiento del sitio
reCAPTCHA/Turnstile invisible en formularios públicos; rate limit por IP; CSP estricta; cookies `Secure`/`HttpOnly`; banner de cookies con consentimiento granular (técnicas, analíticas, marketing); PCI DSS SAQ-A (campos de tarjeta en iframe/redirect de la pasarela); consentimientos Ley 29733 versionados; Libro de Reclamaciones accesible desde el pie de todas las páginas.

### 11.7 v2.1 — Motor de reservas, reglas configurables y experiencia del pasajero
| Tipo | Cambio | Motivo (pedido del cliente) |
|---|---|---|
| Cambiado | El sitio deja de calcular precios/disponibilidad localmente: usa `BookingEngine` (quote, dayStatus, month, validatePax, convert/fmt) | Fuente única; widget y back-office dan la misma cifra |
| Agregado | Calendario con estados por día y leyenda de rangos (02–06 disponible, 07–09 ocupado, 10–14 disponible) | Punto 2 |
| Agregado | Pasajeros por tipo con edades del producto (adultos 11+, niños 4–10 pagan, infantes 0–3 no pagan) y límite por adulto | Punto 3 |
| Agregado | Alojamiento opcional por ciudad con total de habitaciones y noches | Punto 4 |
| Agregado | Datos del titular y de cada acompañante con parentesco; opción de completarlos después | Punto 5 |
| Agregado | Adicionales (museos, extensiones, cenas) por tipo de pasajero; se retiran los extras fijos de v1 (seguro/traslado) | Punto 6 |
| Cambiado | Modalidades de pago del producto/regla (total, adelanto %, pagar después) sustituyen la regla fija 30/40 % de v1 (SIT-BR-09) | Punto 7 |
| Agregado | Mensajes al pasajero desde plantillas al confirmar | Punto 8 |
| Agregado | Selector con todas las monedas activas; cobro en PEN/USD con aviso y TC | Punto 10 |
| Agregado | Previsualización obligatoria con «Quitar» | Punto 11 |
| Cambiado | Reseñas: solo de integraciones conectadas (TripAdvisor, Google, GetYourGuide…) con resumen por fuente, rotuladas «de ejemplo (demo)»; sin integraciones el bloque no se muestra | Punto 12 |
| Agregado | Formulario de punto de partida, vuelo y comentarios | Punto 13 |
| Agregado | Cada comportamiento condicionado a su regla (§5.1) | Punto 14 |
| Cambiado | Un pedido web genera una reserva por línea del carrito (`resIds`) | El contrato `booking` es por producto |
| Agregado | `window.SiteCart.add(sel)` para que el widget embebible (`sitio/widget`, experiencia.js) traspase la selección al carrito | Punto 1 (integración CMS) |
| Precisado | Traslado privado: el motor se invoca con unidades = vehículos (pax / capacidad) | BE cotiza por persona |

---

### 11.8 v2.2 — Datos estructurados de reseñas
La ficha agrega al JSON-LD `TouristTrip`+`Product` (solo si hay reseñas propias publicadas y la regla aplica):
```json
"aggregateRating": { "@type": "AggregateRating", "ratingValue": 4.3, "reviewCount": 3, "bestRating": 5, "worstRating": 1 },
"review": [{ "@type": "Review", "author": { "@type": "Person", "name": "Sophie M." }, "datePublished": "2026-09-28",
  "name": "Machu Picchu sin estrés", "reviewBody": "…", "inLanguage": "es",
  "reviewRating": { "@type": "Rating", "ratingValue": 5, "bestRating": 5 } }]
```
Máximo 5 `review`. Nunca se usan reseñas externas ni la calificación de catálogo para `aggregateRating` (guías de Google para fragmentos de reseñas de terceros). Ver especificación 18.

## 12. Supuestos y puntos a validar con negocio

1. **Porcentajes y plazos del anticipo** (30 % ≥ 30 días; 40 % 15–29 días; saldo −15 días; solo total < 15 días) y penalidad si no se paga el saldo.
2. **Plazo de validación de transferencias/Yape** (4 h hábiles) y liberación automática de cupos si no se valida (propuesto 24 h).
3. Precio y proveedor de **extras** (seguro US$ 6 p/p/día con PRV-014; traslado con tarifa de PRD-005) y si se agregan más (Huayna Picchu, tren Vistadome, hotel extra).
4. **Infantes** gratis y sin cupo en todos los productos (validar trenes y entradas con costo para < 3 años).
5. Pasarela de pagos definitiva (INT-02), 3-D Secure, cuotas sin intereses y moneda de liquidación; comisión de la pasarela en rentabilidad.
6. Tratamiento tributario automático (exportación de servicios para no domiciliados) al emitir el comprobante desde el pedido web.
7. Fuente del **TC** (manual diario vs. API SBS/banco) y margen cambiario en ventas web.
8. Política de **reseñas** (encuesta post-viaje, moderación, respuesta pública) y si se integran reseñas de plataformas externas (sin marcas en el sitio).
9. Idiomas adicionales (PT, FR, DE) y quién traduce contenidos (agencia vs. proveedor externo).
10. Si los usuarios B2B pueden comprar en el sitio y con qué tarifa (hoy: tarifa pública).
11. SLA de respuesta de leads web (24 h hábiles / 4 h en horario) y reglas de asignación automática (round-robin por destino/idioma) — hoy quedan sin asignar para el gerente.
12. Textos legales definitivos (términos, privacidad, cookies, cancelación) y número de inscripción del banco de datos ante la ANPD; confirmar plazo de respuesta del Libro de Reclamaciones con asesoría legal.
13. Dominio propio por tenant, certificados y proceso de alta de dominio en la plataforma (13).
14. Carritos abandonados: autorización para enviar recordatorios (requiere consentimiento de marketing o interés legítimo) y frecuencia.
15. Las cifras de “Nosotros”, certificaciones y reseñas del prototipo son de ejemplo y deben reemplazarse por datos reales del tenant.
16. **v2.1:** con R-AVAIL-RANGES apagada el motor considera disponible cualquier fecha futura (ignora `advMin` del producto); confirmar si debe aplicarse la anticipación mínima como respaldo.
17. **v2.1:** saldo con vencimiento pasado (salida cercana) → el motor lo fija hoy y lo advierte; validar si en ese caso debe ocultarse la modalidad adelanto.
18. **v2.1:** moneda de cobro cuando se muestra una moneda no cobrable (hoy USD) y margen cambiario (`fxMarginPct`) aún no aplicado al importe.
19. **v2.1:** altitudes por destino (tabla `ALT` del módulo) deben pasar a `catalog_destinations.altitude_m`.