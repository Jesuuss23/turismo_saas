# Reservas

> Fuente de verdad del comportamiento: `proto/assets/js/modules/ventas.js` (conversión PROC-025 y sección RESERVAS), `core.js` (`STATUS`, `Q.resPaid`, `Q.resCost`, `Scope`, `Access`), `data.js` (`reservations`, `receivables`, `payments`, `products.departures`, `products.policies`, `rolePerms`, `settings`). Cotizaciones: ver `03-cotizaciones.md`. Tablas financieras (`fin_*`) y de catálogo (`catalog_departures`) pertenecen a sus módulos; aquí se indica qué columnas escribe Reservas.

## 1. Propósito y procesos cubiertos (PROC-xxx)

| Proceso | Qué cubre el módulo |
|---|---|
| PROC-025 Conversión cotización → reserva | Precondiciones, verificación de disponibilidad por ítem, conversión idempotente, copia de snapshot y políticas, servicios desglosados por componente, pasajeros por completar, CxC 40/60, oportunidad ganada |
| PROC-026 Reserva | Reserva directa (sin cotización), confirmación con verificación de disponibilidad, Reserva 360 (resumen, pasajeros, servicios, pagos, documentos, operación, historial), inicio y cierre de operación |
| PROC-027 Modificación | Agregar pasajeros, servicio adicional, upgrade u otro ajuste con análisis de impacto, disponibilidad, aprobación de supervisor, ajuste de CxC |
| PROC-028 Cancelación | Penalidad por servicio según política snapshot, reembolso / penalidad por cobrar, liberación de cupos, anulación de servicios, vouchers, CxC y orden operativa, nota de crédito; solicitud de anulación Ventas → Gerencia |
| PROC-029 Reprogramación | Desplazamiento de fechas con disponibilidad, diferencia tarifaria por temporada, reinicio de servicios, orden operativa y vouchers |
| PROC-030 Grupal/B2B/corporativa | Canal (`Directo`, `Corporativo`, `B2B`, `E-commerce`) y selección de tarifa por canal; sin reglas adicionales de crédito (ver §12) |
| PROC-032 (parcial) | Solicitud, confirmación y rechazo de servicios a proveedor con alternativa, desde la reserva |
| PROC-042 (atajo) | Registro rápido de pago aplicado a una CxC desde la reserva |

## 2. Pantallas

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/reservas` (vista Lista) | `/reservas?view=list` | 4 KPIs (confirmadas, en operación, pendientes pago/proveedor, pasajeros en viaje hoy), filtro Asesor / chip Mi cartera, tabla con filtros Estado y Canal, exportación CSV. Operaciones ve columnas "Servicios conf." y "Costo proveedores" en lugar de Total/Saldo | `reservations.view`: tenant_admin, manager, sales (propias), operations, finance, accounting |
| `#/reservas` (vista Ocupación por fecha) | `/reservas?view=occupancy&month=YYYY-MM` | Calendario mensual de reservas no canceladas (pax por día), barras de pasajeros próximos 14 días, salidas con cupos 14 días | ídem |
| `#/reservas/nueva` | `/reservas/nueva` | Reserva directa: cliente, canal, vendedor, producto, tarifa, salida (POR_SALIDA) o fecha, pax; panel de disponibilidad y precio; "Guardar borrador" o "Crear y confirmar" | `reservations.create`: tenant_admin, manager, sales |
| `#/reservas/{code}` | `/reservas/[code]` | Reserva 360: flujo de estados, hechos (cliente, viaje, pax, total/saldo o costo/servicios, origen), alertas (anulación solicitada, cancelada, pendiente de disponibilidad, reprogramada, modificación pendiente, servicios sin confirmar); pestañas Resumen, Pasajeros, Servicios, Pagos, Documentos, Operación, Historial | `reservations.view` + ownership Ventas |
| `#/reservas/{code}` › pestaña **Detalle de la reserva web** (v2.1) | `/reservas/[code]?tab=web` | Solo si la reserva tiene snapshot `booking`: moneda mostrada vs cobrada y TC, líneas y ajustes aceptados en la previsualización, alojamiento por ciudad (ciudad/hotel/tipo/habitaciones/noches + totales), adicionales, recojo y vuelo, comentarios del cliente destacados con etiquetas (dieta/alergia, celebración, accesibilidad, infante), plan de pago con CxC y reglas aplicadas. Operaciones no ve importes | `reservations.view` + ownership |
| `#/reservas/{code}` › pestaña **Comentarios del pasajero** (v2.1) | `/reservas/[code]?tab=feedback` | `booking_pax_feedback` de la reserva: marcar leído, **Responder** (mensaje saliente + respuesta en el feedback; cierra la conversación del inbox si existe) y **Derivar a incidencia**; panel con los mensajes al pasajero | ver: `reservations.view`; responder `inbox.manage`; derivar `operations.incidents` |
| Modales del detalle | `Dialog` | Confirmar reserva, Modificar, Reprogramar, Cancelar, Solicitar anulación, Rechazar solicitud, Pasajero, Solicitar/Confirmar/Rechazar servicio, Registrar pago, Confirmación de reserva (documento), Reasignar | Según §7 |
| Modal "Convertir en reserva" en `#/cotizaciones/{code}` | `/cotizaciones/[code]` → dialog | Precondiciones + disponibilidad + botón "Convertir y confirmar reserva" o "Crear nueva versión con fecha alternativa" | `reservations.create` |

## 3. Modelo de datos PostgreSQL

Todas las tablas con `tenant_id uuid not null`, RLS, `created_by/updated_by`, `created_at/updated_at`, PK `uuid` v7.

### booking_reservations
| columna | tipo | null | notas/FK/índice |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| code | varchar(30) | no | `RES-2026-000350` (serie `Reserva`, 6 dígitos). `UNIQUE(tenant_id, code)` |
| quote_id | uuid | sí | FK quote_quotes. **`UNIQUE (tenant_id, quote_id) WHERE quote_id IS NOT NULL`** (garantía de idempotencia en DB) |
| quote_version_id | uuid | sí | FK quote_versions (versión exacta aprobada) |
| quote_version_no | smallint | sí | |
| client_id | uuid | no | FK crm_clients; índice |
| agency_id | uuid | sí | FK channel_b2b_agencies (reservas B2B) |
| owner_id | uuid | no | FK iam_users; índice `(tenant_id, owner_id)` |
| sede_id | uuid | no | FK iam_sedes (sede del vendedor) |
| title | varchar(200) | no | |
| status | varchar(30) | no | `CHECK IN ('borrador','pendiente_disponibilidad','confirmada','pendiente_pago','pendiente_proveedor','reprogramada','parcial','incidencia','en_operacion','completada','cancelada')`; índice `(tenant_id, status)` |
| channel | varchar(15) | no | `CHECK IN ('Directo','Corporativo','B2B','E-commerce')` |
| travel_from / travel_to | date | no | `CHECK (travel_to >= travel_from)`; índice `(tenant_id, travel_from)` (calendario) |
| currency | char(3) | no | |
| fx_rate | numeric(12,6) | no | copiado de la versión |
| total_amount | numeric(14,2) | no | total con IGV; varía por modificación/reprogramación |
| snapshot_label | varchar(300) | no | `PRD-001 v2.0 / PRD-002 v1.1` |
| catalog_snapshot | jsonb | sí | copiado de la versión |
| policies_snapshot | jsonb | no | políticas por producto (de la versión o del producto en reserva directa) |
| notes | text | sí | "Generada desde COT-… vN. …" |
| rescheduled_at | timestamptz | sí | |
| cancelled_at | timestamptz | sí | |
| cancel_reason | text | sí | `motivo: detalle` |
| penalty_amount / refund_amount | numeric(14,2) | sí | resultado de la cancelación |
| lock_version | integer | no | default 0 |
| deleted_at | timestamptz | sí | nunca borrado físico |

### booking_passengers
| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id, reservation_id | uuid | no | FK; índice |
| seq | smallint | no | orden |
| full_name | varchar(150) | no | placeholder "Pasajero ADT 1 (por completar)" |
| doc_type | varchar(12) | sí | `CHECK IN ('DNI','Pasaporte','CE','Passport')` |
| doc_number | varchar(30) | sí | |
| pax_type | char(3) | no | `CHECK IN ('ADT','CHD','INF')` |
| nationality | varchar(60) | sí | |
| birth_date | date | sí | |
| phone | varchar(30) | sí | |
| diet_notes | varchar(200) | sí | dieta / requerimientos |
| is_placeholder | boolean | no | true hasta completar datos nominativos |

### booking_services
| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id, reservation_id | uuid | no | FK; índice `(reservation_id, service_date)` |
| code | varchar(20) | no | `RS-xxxxx` |
| product_id | uuid | sí | FK catalog_products |
| catalog_service_id | uuid | sí | FK catalog_services (componente) |
| quote_item_id | uuid | sí | FK quote_items (trazabilidad) |
| departure_id | uuid | sí | FK catalog_departures (cupo retenido) |
| provider_id | uuid | sí | FK supplier_providers |
| name | varchar(200) | no | |
| service_date | date | no | |
| service_time | time | sí | |
| pax | integer | sí | |
| status | varchar(20) | no | `CHECK IN ('pendiente','solicitado','confirmado','rechazado','cancelado','completado')` |
| cost_amount / price_amount | numeric(14,2) | no | moneda de la reserva; `price` sin IGV y neto de descuento |
| is_required | boolean | no | default true (`component.required`) |
| confirmation_code | varchar(40) | sí | obligatorio al confirmar |
| confirmed_by_name | varchar(120) | sí | |
| requested_at / confirmed_at | timestamptz | sí | |
| reject_reason | text | sí | `motivo: detalle` |
| alt_of_id / replaced_by_id | uuid | sí | FK self (alternativa por rechazo) |

### booking_inventory_holds (cupos retenidos)
| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | |
| departure_id | uuid | no | FK catalog_departures; índice |
| reservation_id | uuid | no | FK |
| booking_service_id | uuid | sí | |
| pax | integer | no | `CHECK (pax > 0)` |
| status | varchar(15) | no | `CHECK IN ('hold','confirmada','liberada','expirada','consumida')` |
| expires_at | timestamptz | sí | solo holds temporales (`settings.holdMinutes` = 30) |
| released_at | timestamptz | sí | |

`catalog_departures.sold` = Σ pax de holds `hold`/`confirmada`/`consumida` (se mantiene materializado en la misma transacción); `CHECK (sold <= capacity)`; `status` = `completa` si `sold ≥ capacity`, `abierta` si no, salvo `cerrada`.

### booking_changes (modificaciones y reprogramaciones)
| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id, reservation_id | uuid | no | |
| type | varchar(20) | no | `CHECK IN ('pax','servicio','upgrade','otro','reprogramacion')` |
| description | text | no | obligatoria |
| extra_pax | integer | sí | tipo `pax` |
| product_id / service_date | uuid / date | sí | tipo `servicio` |
| delta_amount | numeric(14,2) | no | + cargo, − saldo a favor |
| credit_amount | numeric(14,2) | sí | saldo a favor no absorbido por CxC |
| status | varchar(15) | no | `CHECK IN ('pendiente','aplicada','rechazada')` |
| requested_by / requested_at | uuid / timestamptz | no | |
| decided_by / decided_at / applied_at | | sí | |
| payload | jsonb | sí | antes/después (fechas, precios por servicio, cupos) |

### booking_cancel_requests
| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id, reservation_id | uuid | no | `UNIQUE (reservation_id) WHERE status='pendiente'` |
| reason | text | no | `motivo: detalle` |
| requested_by / requested_at | uuid / timestamptz | no | |
| status | varchar(15) | no | `CHECK IN ('pendiente','aprobada','rechazada')` |
| decided_by / decided_at / decision_note | | sí | |

### booking_cancellations
| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id, reservation_id | uuid | no | `UNIQUE(reservation_id)` |
| total_at_cancel, paid_at_cancel | numeric(14,2) | no | |
| penalty_services, consumed_amount | numeric(14,2) | no | antes de factor k |
| factor_k | numeric(12,6) | no | |
| penalty_amount, refund_amount, owed_amount | numeric(14,2) | no | |
| lines | jsonb | no | por servicio: días antes, texto de política, tramos, %, penalidad |
| credit_note_id | uuid | sí | FK fin_invoices |
| refund_payment_id | uuid | sí | FK fin_payments |
| penalty_receivable_id | uuid | sí | FK fin_receivables |
| reason, cancelled_by, cancelled_at | | no | |

### Columnas que Reservas escribe en tablas de otros módulos
| Tabla | Columnas / valores |
|---|---|
| fin_receivables | `code CXC-00715`, `reservation_id`, `client_id`, `concept`, `amount`, `currency`, `due_date`, `paid_amount`, `status ∈ (abierta, parcial, pagada, vencida, cancelada)` |
| fin_payments | `code PAG-xxxxx`, `receivable_id` (null en reembolso), `reservation_id`, `method`, `amount`, `currency`, `paid_on`, `status ∈ (pendiente, confirmado, aplicado, revertido)`, `reference`, `kind ∈ (cobro, reembolso)`, `registered_by` |
| fin_invoices | Nota de crédito por reembolso (`type='Nota de crédito'`, `status='emitido'`) |
| ops_vouchers | `status → anulado` al reprogramar/cancelar |
| ops_orders / ops_assignments | fechas, `status` (`pendiente_asignacion`, `cancelada`), asignaciones `pendiente`/`cancelada` |
| crm_opportunities | `stage='ganada'`, `probability=100`, `close_date` |

### v2.1 — Snapshot de la reserva web y datos de pasajeros

**`booking_reservation_web_snapshots`** (1:1 con la reserva; inmutable salvo `pickup_*` y acompañantes, que el cliente/pasajero puede completar dentro del plazo de R-COMPANIONS). En el prototipo: `reservations[].booking`.

| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id | uuid | no | PK / FK tenants |
| reservation_id | uuid | no | FK `booking_reservations`, `UNIQUE (tenant_id, reservation_id)` |
| product_id | uuid | no | FK `catalog_products` |
| service_date | date | no | fecha elegida en el calendario de rangos |
| pax_adt / pax_chd / pax_inf | smallint | no | `CHECK (pax_adt >= 0 …)` |
| currency_display | char(3) | no | moneda que vio el cliente (R-MULTICURRENCY) |
| charge_currency | char(3) | no | moneda cobrada (`settings.ecommerce.chargeCurrencies`) |
| fx_rate | numeric(12,6) | no | unidades de `currency_display` por 1 `charge_currency`, conservado (regla transversal 15) |
| lines | jsonb | no | copia de `BookingEngine.quote().lines` sin removidas: `key, kind (base/addon/lodging/surcharge/info), label, qty, unit, amount` |
| adjustments | jsonb | no | descuentos/recargos aplicados (grupo, anticipada, última hora, promo, tarjeta) |
| total | numeric(14,2) | no | en `charge_currency`; debe igualar `booking_reservations.total` |
| rooms_total / nights_total | smallint | sí | totales del motor (R-LODGING) |
| pickup_point / pickup_address | varchar(200) | sí | R-PICKUP |
| pickup_flight | varchar(40) | sí | vuelo de llegada (param `askFlight`) |
| pickup_arrival | timestamptz | sí | |
| comments | varchar(1000) | sí | R-COMMENTS (`maxChars`) |
| payment_mode | varchar(20) | no | `CHECK IN ('total','adelanto','pagar_despues')` |
| deposit_pct | numeric(5,2) | sí | |
| deposit / balance | numeric(14,2) | sí | |
| balance_due | date | sí | vencimiento del saldo (genera la CxC de saldo) |
| rules_applied | text[] | no | ids de reglas activas usadas (auditoría de precio) |
| source / order_code | varchar(30) | sí | `Sitio web` / `Widget` · `WEB-…` |

**`booking_reservation_web_lodging`** (N por snapshot): `id, tenant_id, snapshot_id FK, leg_id varchar(20), city varchar(80), hotel_id uuid null FK catalog_accommodation_hotels, hotel_name varchar(150), room_type varchar(30), rooms smallint CHECK (rooms > 0), nights smallint CHECK (nights > 0)`. Índice `(tenant_id, snapshot_id)`.

**`booking_reservation_web_addons`**: `id, tenant_id, snapshot_id FK, addon_id uuid FK catalog_product_addons, name varchar(150), qty smallint, amount numeric(14,2)`.

**Columnas nuevas en `booking_passengers`**: `relationship varchar(30) CHECK IN ('Titular','Cónyuge / pareja','Hijo/a','Padre / madre','Hermano/a','Amigo/a','Otro')`, `age smallint null` (edad a la fecha del viaje, recalculada al guardar `birth_date`), `email varchar(150) null`, `is_lead boolean not null default false` con índice único parcial `UNIQUE (reservation_id) WHERE is_lead`. El tipo ADT/CHD/INF se valida contra `BookingEngine.ageType(producto, edad)`; un cambio de tipo por edad exige modificación (PROC-027) porque altera el precio.

**`booking_pax_feedback`** (dueño: Canales; se gestiona desde Reserva 360): `id, tenant_id, code varchar(12) UNIQUE (tenant_id, code)` (`FB-00001`), `reservation_id FK`, `passenger_name varchar(150)`, `type varchar(20) CHECK IN ('comentario','sugerencia','felicitacion','reclamo','incidencia')`, `rating smallint null CHECK (rating BETWEEN 1 AND 5)`, `reservation_service_id uuid null FK`, `text varchar(1000) not null`, `status varchar(20) CHECK IN ('nuevo','leido','respondido','derivado')`, `reply text null`, `replied_at timestamptz`, `replied_by uuid FK iam_users`, `conversation_id uuid null FK collab_conversations`, `incident_id uuid null FK ops_incidents`, `channel varchar(30)`. Índices `(tenant_id, reservation_id)`, `(tenant_id, status)`.

**`notif_pax_messages`** (dueño: Comunicaciones/experiencia): Reserva 360 inserta el mensaje saliente de cada respuesta (`template_id null`, `feedback_id` FK, `direction='saliente'`, `status='enviado'`).

### v2.1 — Siembra de la demo
`ventas.js` crea, si no existe ninguna reserva con `booking`, **RES-2026-000350** (Familia Castañeda, PRD-011, 10–19 oct 2026, 2 ADT + 1 CHD + 1 INF, alojamiento Lima/Arequipa/Puno/Cusco = 5 hab. · 9 noches, 2 adicionales, recojo en el aeropuerto con vuelo LA2047, comentarios «Aniversario de bodas; niño alérgico a los frutos secos», adelanto 30 %), calculada con `BookingEngine.quote()`, con CxC de adelanto (pagada, pago e-commerce) y saldo (vence 10 set, vencida), orden operativa OP pendiente de asignación, 2 comentarios (FB-00001 respondido, FB-00002 nuevo) y 3 mensajes (se agregan en `DOMContentLoaded` porque `paxMessages` lo siembra experiencia.js).

## 4. Máquinas de estado

### 4.1 Reserva (`booking_reservations.status`)

| Desde | Hacia | Acción · permiso | Guardas | Efectos |
|---|---|---|---|---|
| — | `confirmada` | Convertir cotización · `reservations.create` | §4.3 | Servicios `pendiente`, CxC 40/60, cotización `convertida` |
| — | `confirmada` | Reserva directa "Crear y confirmar" · `reservations.create` | Disponibilidad OK (RES-BR-05) | Hold de salida, CxC 40/60 |
| — | `borrador` | Reserva directa "Guardar borrador" con disponibilidad OK | — | **Retiene cupo igualmente** y crea CxC (ver §12) |
| — | `pendiente_disponibilidad` | Reserva directa "Guardar borrador" sin disponibilidad | — | Sin hold; CxC creadas |
| `borrador`/`pendiente_disponibilidad`/`pendiente_proveedor`/`reprogramada` | `confirmada` | Confirmar reserva · `reservations.modify` | Todos los servicios activos **requeridos** con disponibilidad (`svcAvailability`) y ≥ 1 pasajero | Retiene cupo de servicios POR_SALIDA sin salida (por fecha); crea CxC si no existen |
| `pendiente_proveedor` | `pendiente_proveedor` | Confirmar reserva con servicios `pendiente/solicitado` | — | Solo audita `verificar_disponibilidad` |
| `confirmada` | `pendiente_proveedor` | Registrar rechazo de proveedor en un servicio | — | Crea alternativa opcional |
| `pendiente_proveedor` | `confirmada` | Confirmar el último servicio pendiente (automático) | Todos los activos `confirmado/completado` | Audita |
| `pendiente_pago` | `confirmada` | Registrar pago que deja saldo ≤ 0.50 (automático) | — | Audita |
| `confirmada`/`reprogramada`/`pendiente_pago`/`pendiente_proveedor` | `en_operacion` | "Iniciar operación" · `reservations.modify` | `travel_from ≤ hoy + 1` | — |
| `en_operacion` | `completada` | "Completar viaje" · `reservations.modify` | — | — |
| no cerrada (≠ `cancelada`,`completada`) | `reprogramada` | Reprogramar · `reservations.modify` | §5 RES-BR-20…24 | §4.5 |
| no cerrada | `cancelada` | Cancelar · `reservations.cancel` | — | §4.6 |
| cualquiera | (sin cambio) | Modificar · `reservations.modify` | — | §4.4 |

`pendiente_pago`, `parcial` e `incidencia` existen en `STATUS` pero este módulo no los asigna (los usan B2B/e-commerce/operaciones). Estados terminales: `completada`, `cancelada` (se ocultan Modificar/Reprogramar/Cancelar y la edición de pasajeros/servicios).

### 4.2 Servicio de reserva (`booking_services.status`)
`pendiente` → `solicitado` (Solicitar; proveedor debe estar `activo`, PRD-BR-007) → `confirmado` (código obligatorio, hora y "confirmado por" opcionales) → `completado` (operaciones). `solicitado`/`confirmado` → `rechazado` (motivo + detalle obligatorios; alternativa opcional: otro proveedor activo del mismo tipo o "mismo proveedor, otra fecha" → nuevo servicio `pendiente` con `alt_of`, el rechazado queda con `replaced_by`). Cualquier no completado → `cancelado` (cancelación de reserva). Reprogramación → todos los activos vuelven a `pendiente` sin código.

### 4.3 Conversión idempotente cotización → reserva (PROC-025), paso a paso

Endpoint `POST /api/v1/quotes/{id}/convert` (header `Idempotency-Key` obligatorio, `If-Match` con `lock_version` de la cotización). Pasos del prototipo (`convertModal` + `doConvert`) llevados a backend:

| # | Paso | Detalle exacto |
|---|---|---|
| 0 | Idempotencia de request | Buscar `Idempotency-Key` en `sys_idempotency_keys` (Redis cache 24 h). Si existe con el mismo hash de body → devolver la respuesta almacenada (201 original). Si existe con body distinto → 422 |
| 1 | Lock distribuido | `Cache::lock("tenant:{t}:quote:{id}:convert", 30)->block(5)`; si no se obtiene → 409 "Conversión en curso" |
| 2 | Transacción | `DB::transaction(..., attempts: 3)` con `SET LOCAL app.tenant_id` |
| 3 | Bloqueo de la cotización | `SELECT … FROM quote_quotes WHERE id=? FOR UPDATE`; comparar `lock_version` con `If-Match` (409 si difiere) |
| 4 | Idempotencia de negocio | Si `status='convertida'` o existe `booking_reservations` con ese `quote_id` (no cancelada, o la referenciada en `reservation_id` aunque esté cancelada) → **200** con la reserva existente, auditar `convertir_idempotente` ("solicitud repetida") y no crear nada (mensaje prototipo: "ya fue convertida en la reserva RES-… No se creará una reserva duplicada") |
| 5 | Precondiciones (RT-3) | `status='aprobada'` (si no: 409 `RT-3` "solo una cotización aprobada puede generar reserva (estado actual: …)"); versión vigente `aprobada`; ≥ 1 ítem; pax > 0; fechas; total > 0; cliente existente |
| 6 | Bloqueo de inventario | Para cada ítem de producto `POR_SALIDA`: localizar salida por `service_date` (no `cerrada`, fecha ≥ hoy). Ordenar los `departure_id` y aplicar `Cache::lock("departure:{id}")` + `SELECT … FROM catalog_departures WHERE id IN (…) ORDER BY id FOR UPDATE` (orden fijo evita deadlocks) |
| 7 | Re-verificar disponibilidad dentro de la transacción | `availFor(product, date, pax)`: producto `ACTIVE` (si no, PRD-BR-002); POR_SALIDA: debe existir salida ese día y `capacity − sold ≥ pax` (si no, 409 `RT-4/PRD-BR-012` con alternativas = otras salidas futuras no cerradas con cupo ≥ pax). Otros modelos (`POR_HABITACION`, `CUPOS`, `CAPACIDAD`, `POR_TRANSPORTE`, `SIN_CONTROL`): OK "hold solicitado al proveedor, sujeto a confirmación (PRD-BR-013)". **No hay conversión parcial**: un ítem sin cupo bloquea todo y se sugiere nueva versión con fecha alternativa (requiere nueva aprobación). El prototipo verifica al abrir el modal; el backend debe re-verificar aquí |
| 8 | Código | `sys_sequences` serie `Reserva` FOR UPDATE → `RES-2026-000350` |
| 9 | Holds | Por cada ítem POR_SALIDA: `sold += pax` en la salida, `status` recalculado (`completa` si llena), `booking_inventory_holds(status='confirmada')`. Para modelos sin control por salida: registrar `booking_inventory_holds`/solicitud a proveedor en estado `hold` sin descontar inventario interno (ver §12) |
| 10 | Servicios (`servicesFromItem`) | Por ítem: `costT = unit_cost×qty×pax`; `saleT = unit_price×qty×pax×(1 − discount_pct/100)` (sin IGV). Componentes del producto con `included ≠ false` y servicio existente. Sin componentes → 1 servicio "Producto (N pax)" con costT/saleT y proveedor principal. Con componentes: peso `w_k = conv(service.cost, service.currency→moneda) × (component.qty‖1) × (service.unit='pax' ? pax : 1)`; `cost_k = round2(costT × w_k / Σw)`, `price_k = round2(saleT × w_k / Σw)`; nombre del servicio (+ "×qty" si qty > 1); hora = primera del itinerario día 1 para el primer componente, o la hora `HH:MM` encontrada en las notas del componente; `provider_id` del servicio; `is_required = component.required ≠ false`; `status='pendiente'`; `departure_id` si hubo hold |
| 11 | Snapshot y políticas | `policies_snapshot[p] = version.policies_snapshot[p]` (o políticas actuales del producto si faltan); `snapshot_label`, `catalog_snapshot`, `quote_id`, `quote_version_id/no` |
| 12 | Cabecera | `status='confirmada'`, `channel` = canal del segmento del cliente (Corporativo/B2B; Grupos→Corporativo; resto Directo), fechas y moneda de la cotización, `total_amount = round2(total de la versión con IGV)`, `owner_id` = dueño de la cotización, `sede_id` = sede del dueño, `notes = "Generada desde COT-… vN. " + notas` |
| 13 | Pasajeros | Fichas placeholder por ADT/CHD/INF: "Pasajero ADT 1 (por completar)", `is_placeholder=true` |
| 14 | CxC 40/60 (`createReceivables`) | `a = round2(total × 0.40)`; `b = round2(total − a)`. Vencimientos: `due_a = min(hoy + 3, max(hoy, travel_from))`; `due_b = max(hoy, travel_from − 15)`. Conceptos "Anticipo 40% — {título}" y "Saldo 60% — {título}", `status='abierta'`. Audita `Cuenta por cobrar · crear` (`COT vN`) |
| 15 | Cotización | `status='convertida'`, `reservation_id`, `converted_at`, `lock_version+1` |
| 16 | Oportunidad | Si existe y ≠ `ganada`: `ganada`, probabilidad 100, `close_date=hoy`; audita `cambio_etapa` |
| 17 | Auditoría | `Reserva · crear_desde_cotizacion` (COT vN → confirmada · total); `Cotización · convertir` (aprobada → convertida → RES) |
| 18 | Outbox / eventos | `QuoteConverted`, `ReservationCreated`, `ReservationConfirmed` en `sys_outbox` dentro de la transacción |
| 19 | Commit y respuesta | 201 `{reservation}`; guardar respuesta en `sys_idempotency_keys`; liberar locks. Después del commit (cola `critical`/`notifications`): `reserva.confirmada` Email → cliente y Push → dueño |

Ejemplo CxC: total 243 144.90, hoy 2026-09-29, viaje 2026-11-18 → anticipo 97 257.96 vence 2026-10-02; saldo 145 886.94 vence 2026-11-03.
Ejemplo desglose (PRD-001, 2 pax, costo 268 / precio 345 USD, sin descuento): pesos USD SRV-002 18.717, SRV-003 300, SRV-004 48, SRV-005 81.283, SRV-006 58.824, SRV-015 8 (Σ 514.824) → costos 19.49 / 312.34 / 49.97 / 84.63 / 61.24 / 8.33 (Σ 536.00) y precios 25.09 / 402.08 / 64.33 / 108.94 / 78.84 / 10.72 (Σ 690.00). El backend debe asignar el residuo de redondeo a la última línea para que Σ = costT/saleT exacto.

### 4.4 Modificación (PROC-027)
1. Solicitud (`reservations.modify`): tipo `pax` (n ≥ 1), `servicio` (producto `ACTIVE` y `bookable`, fecha), `upgrade`, `otro`; impacto sugerido: pax → `total/pax_actuales × n`; servicio → precio sugerido de la tarifa por defecto × pasajeros; editable (positivo cargo, negativo saldo a favor); descripción obligatoria.
2. Disponibilidad: `pax` → cada salida retenida debe tener `capacity − sold ≥ n` (si no: "Sin disponibilidad: la salida SAL-… no tiene n cupo(s) libre(s) (PRD-BR-012)"); `servicio` → `availFor(producto, fecha, pax)`.
3. Registro `booking_changes(status='pendiente')`, audita `solicitar_modificacion`. Si el usuario tiene `quotes.approve_internal` y marca "Aprobar como supervisor y aplicar ahora" → aplica; si no, notifica Push a gerencia (prototipo: U02 fijo) y muestra alerta "Modificación pendiente de aprobación" con Aprobar/Rechazar para quien tiene `quotes.approve_internal`.
4. Aplicar: `pax` → n fichas ADT placeholder, `sold += n` en cada salida retenida, `pax += n` en servicios activos con pax; `servicio` → nuevo(s) servicio(s) vía `servicesFromItem` (precio unitario = delta / pax, costo = costo sugerido de tarifa), hold si POR_SALIDA, amplía `travel_from/to`; `upgrade/otro` → solo monto. `total += delta`. `delta > 0` → CxC "Ajuste por modificación — …" vence `max(hoy, travel_from − 7)`. `delta < 0` → reduce CxC abiertas en orden (las que quedan cubiertas pasan a `pagada`); resto → `credit_amount`. Estado `aplicada`, audita `modificar`, Email al cliente `reserva.modificada`.
5. Rechazar (`quotes.approve_internal`): `rechazada`, audita `rechazar_modificacion`.

### 4.5 Reprogramación (PROC-029)
- Bloqueada si algún servicio está `completado` ("use Modificar").
- Fecha propuesta: próxima salida con cupo del primer servicio con salida; si no, `travel_from + 7`. `shift = días(nueva − travel_from)`; 0 → error.
- Por servicio activo: `nueva = service_date + shift`. POR_SALIDA → `availFor(p, nueva, pax, salida_actual)` (los pax de la salida actual cuentan como libres si es la misma); producto no `ACTIVE` → bloqueado; resto → OK "Hold con proveedor". Cualquier bloqueo impide aplicar (RT-4).
- Diferencia tarifaria por servicio: `fo = factor temporada(fecha actual)`, `fn = factor temporada(nueva)` (1 si no hay); `dp = round2(price × (fn/fo − 1))`; `diff = round2(Σ dp)`. Ej.: servicio de 1 000 del 28-nov (media ×1.00) al 05-dic (baja ×0.88) → −120.00.
- Aplicar: libera todas las salidas retenidas (`sold −= pax` máx. por salida), retiene la salida de la nueva fecha una vez por salida y renombra `SAL-xxxx` en el nombre; `price = round2(price×fn/fo)`, `cost = round2(cost×fn/fo)`; servicios → `pendiente` sin código; fechas de la reserva desplazadas; `total += diff`; `status='reprogramada'`, `rescheduled_at`; `booking_changes(type='reprogramacion', status='aplicada', approved_by=actor)`; orden operativa: fechas, asignaciones no canceladas → `pendiente`, orden → `pendiente_asignacion`; vouchers `emitido` → `anulado` (auditado "requiere reemisión"); `diff > 0` → CxC "Diferencia tarifaria por reprogramación" vence `max(hoy, nuevo travel_from − 7)`; `diff < 0` → no genera crédito (ver §12). Notifica `cambio.horario` Email cliente y Push coordinador (o U05).

### 4.6 Cancelación y cálculo de penalidad (PROC-028 · E2E-09)

**Tramos por política** (`tiersFor(texto de policies_snapshot[p].cancel)`, tramos `[díasMínimos, %]` en orden descendente):
| Texto de la política contiene | Tramos |
|---|---|
| "48 h" y "sin penalidad" | `[[2,0],[0,100]]` |
| "24 h" y "sin penalidad" | `[[1,0],[0,100]]` |
| "Tren:" | `[[15,0],[7,50],[2,50],[0,100]]` |
| cualquier otro (estándar "Sin penalidad hasta 15 días antes. 50% entre 14 y 7 días. 100% dentro de 7 días o no-show.") | `[[15,0],[7,50],[0,100]]` |

`penaltyPct(tiers, d)` = % del primer tramo con `d ≥ díasMínimos`; si ninguno, 100.

**Fórmula exacta** (`cancelModal`):
```
paid     = Σ pagos de la reserva con status ∈ {aplicado, confirmado}
svcs     = servicios con status ∉ {cancelado, completado, rechazado}
used     = servicios con status = completado
por s ∈ svcs: d_s = días calendario (service_date − hoy)
              pct_s = penaltyPct(tiersFor(policy(s.product).cancel), d_s)
              pen_s = round2(s.price × pct_s / 100)
penSvc   = Σ pen_s
consumed = Σ price(used)                     (servicios ya ejecutados, no reembolsables)
allTot   = Σ price(svcs ∪ used)  (si 0 → 1)
k        = total_amount / allTot             (lleva precios de servicio —netos, sin IGV— al total con IGV)
penalty  = round2( min(total_amount, (penSvc + consumed) × k) )
refund   = round2( max(0, paid − penalty) )
owed     = round2( max(0, penalty − paid) )
```
Ejemplo RES-2026-000348 (hoy 2026-09-29; total 58 610; pagado 23 444): traslados PRD-005 (24 h) 2 d y 6 d → 0 %; Humantay PRD-004 (48 h) 5 d → 0 %; 5 servicios PRD-002 (estándar) a 2–4 d → 100 % = 23 900 + 1 660 + 5 120 + 17 930 + 4 870 = 53 480; allTot 58 610 → k = 1; **penalidad 53 480; reembolso 0; penalidad por cobrar 30 036**.

**Efectos al confirmar** (motivo de lista + detalle obligatorio; transacción + `If-Match` + locks de salidas):
1. Liberar cupos: por salida única (máx. pax por salida) `sold −= pax`, estado recalculado, holds → `liberada`; audita `Disponibilidad · liberar`.
2. Servicios de `svcs` → `cancelado`.
3. Vouchers `emitido` → `anulado` (audita).
4. CxC no `pagada`/`cancelada` → `cancelada` (audita cada una).
5. `owed > 0` → CxC "Penalidad por cancelación — RES-…" vence hoy + 7.
6. `refund > 0` → Nota de crédito (monto = refund; detalle "Anulación por cancelación … (penalidad …)") y pago `kind='reembolso'`, `status='pendiente'`, método "Reembolso (mismo medio de pago)", ref "Reembolso {NC} · 7–15 días hábiles".
7. Orden operativa → `cancelada`, asignaciones no completadas → `cancelada`.
8. Reserva: `cancelada`, `cancel_reason`, `cancelled_at`, `penalty_amount`, `refund_amount`; `booking_cancellations` con el detalle; solicitud pendiente → `aprobada` y notificación al solicitante.
9. Notificaciones `reserva.cancelada`: Email cliente (con reembolso), Push coordinador (o U05), Portal Finanzas (U07 en prototipo → rol finance).

**Solicitud de anulación** (usuario con `reservations.create` sin `reservations.cancel`, p. ej. Ventas): solo el dueño, reserva no cerrada, sin otra pendiente; motivo (lista) + detalle obligatorio; notifica a managers; la reserva sigue vigente. Gerencia: "Procesar cancelación" (abre la cancelación con el detalle precargado) o "Rechazar solicitud" (motivo obligatorio, notifica al solicitante).

### 4.7 Reserva directa (sin cotización)
Producto `ACTIVE` y `bookable` (deshabilitados con motivo en el selector); tarifa vigente por canal (no Niño) o cualquiera vigente; POR_SALIDA exige salida (opciones deshabilitadas si `cerrada` o cupo < ADT+CHD); otros modelos validan anticipación mínima `advMin` días y `maxPax`. Precio: `sa = suggest(tarifa adulto, fecha, moneda de la tarifa)`; niño = tarifa Niño vigente del mismo canal (o precio adulto); infante sin cargo; `total = ADT×sa.price + CHD×sc.price`; costo análogo; **sin IGV**; moneda = moneda de la tarifa; `travel_to = fecha + (duración − 1)` si la duración es en días. Crea 1 ítem sintético → servicios por componentes, pasajeros placeholder, políticas del producto, CxC 40/60.

### v2.1 — Comentario del pasajero (`booking_pax_feedback.status`)
`nuevo` → `leido` (Marcar leído, back-office) → `respondido` (Responder con estado «Respondido») · `nuevo|leido` → `leido` (Responder «En seguimiento») · `nuevo|leido|respondido` → `derivado` (Derivar a incidencia; crea `ops_incidents` con `feedback_id`). Responder genera siempre un `notif_pax_messages` saliente; si existe `conversation_id` agrega el mensaje, marca `first_response_at` y, si queda «respondido», resuelve la conversación.

## 5. Reglas de negocio

| ID | Regla | Dónde se valida |
|---|---|---|
| RT-3 | Solo una cotización `aprobada` genera reserva | Service (409) |
| RT-4 / PRD-BR-012 | No se confirma, convierte, reprograma ni agrega pax/servicio sin disponibilidad; POR_SALIDA con cupo `capacity − sold ≥ pax` | Service + lock + `CHECK (sold <= capacity)` |
| PRD-BR-013 | Modelos sin salida propia se tratan como hold sujeto a confirmación del proveedor (advertencia, no bloqueo) | Service |
| RT-22 | Conversión idempotente: una cotización genera como máximo una reserva (aunque se cancele) | UNIQUE parcial + Idempotency-Key + FOR UPDATE |
| RES-BR-01 | Conversión sin ítems con cupo insuficiente; no hay conversión parcial | Service |
| RES-BR-02 | La reserva conserva versión exacta, snapshot y políticas de la cotización | Service |
| RES-BR-03 | CxC automáticas 40 % anticipo / 60 % saldo con vencimientos §4.3 paso 14; si no existen al confirmar, se crean | Service |
| RES-BR-04 | Reserva directa: cliente y producto obligatorios; producto ACTIVE + bookable (PRD-BR-002); tarifa vigente (PRD-BR-005); ADT+CHD > 0 | FormRequest |
| RES-BR-05 | "Crear y confirmar" requiere disponibilidad OK; sin ella solo borrador `pendiente_disponibilidad` | Service |
| RES-BR-06 | POR_SALIDA requiere salida; anticipación mínima y máximo de pax del producto para otros modelos | FormRequest |
| RES-BR-07 | Confirmar exige disponibilidad de todos los servicios activos requeridos y ≥ 1 pasajero; servicio `rechazado` sin alternativa bloquea | Service |
| RES-BR-08 | Iniciar operación solo desde confirmada/reprogramada/pend. pago/pend. proveedor y cuando `travel_from ≤ hoy + 1`; completar solo desde `en_operacion` | Service |
| RES-BR-09 | Pasajero: nombre, número de documento, nacionalidad y fecha de nacimiento obligatorios; tipo sugerido por edad a la fecha de viaje `floor(días/365.25)`: < 2 INF, < 12 CHD, si no ADT (advertencia, no bloqueo) | FormRequest (warning en respuesta) |
| RES-BR-10 | Agregar/quitar pasajeros no recalcula precio ni cupos; para impacto se usa Modificación | Service (ver §12) |
| RES-BR-11 / PRD-BR-007 | Solo se solicita a proveedores `activo` | Service |
| RES-BR-12 | Confirmar servicio exige código de confirmación | FormRequest |
| RES-BR-13 | Rechazo de proveedor: motivo + detalle obligatorios; alternativa como nuevo servicio `pendiente`; reserva `confirmada` → `pendiente_proveedor` | Service |
| RES-BR-14 | Modificación: descripción obligatoria; disponibilidad según tipo; aplica solo con aprobación de `quotes.approve_internal` (propuesto `reservations.approve_changes`) | Policy + Service |
| RES-BR-15 | Delta positivo genera CxC; delta negativo reduce CxC abiertas y el exceso queda como crédito | Service |
| RES-BR-20 | Reprogramación bloqueada si hay servicios `completado` | Service |
| RES-BR-21 | Nueva fecha distinta de la actual y ≥ hoy; motivo obligatorio | FormRequest |
| RES-BR-22 | Diferencia tarifaria por factor de temporada por servicio (§4.5) | Service |
| RES-BR-23 | Reprogramar anula vouchers emitidos y reinicia servicios y asignaciones | Service |
| RES-BR-24 | Diferencia positiva genera CxC | Service |
| RES-BR-30 / RT-8 | Cancelación: motivo obligatorio, penalidad por política snapshot (§4.6), auditada | FormRequest + Service |
| RES-BR-31 | Penalidad nunca supera el total; servicios ejecutados se retienen al 100 % | Service |
| RES-BR-32 | Reembolso genera nota de crédito + pago de reembolso pendiente; penalidad no cubierta genera CxC a 7 días | Service |
| RES-BR-33 | Solicitud de anulación: solo el dueño, una pendiente por reserva, reserva no cerrada | Policy + UNIQUE parcial |
| RES-BR-40 / RT-9 | Pago rápido: requiere CxC abierta; monto > 0 y ≤ saldo de la CxC (+0.01); se registra `aplicado`; CxC → `pagada` si cubre, si no `parcial` | FormRequest + Service |
| RES-BR-41 | Generar anticipo/saldo manual solo si la reserva no tiene CxC y no está cancelada | Service |
| RES-BR-42 | Orden operativa solo para reservas confirmada/pend. pago/pend. proveedor/reprogramada/en operación | Service |
| RES-BR-50 | Ventas solo ve/gestiona reservas propias (lista, KPIs, calendario, detalle, búsqueda); clientes de su cartera en reserva directa; vendedor fijo = él mismo | Policy + scope |
| RES-BR-51 | Reasignar asesor solo tenant_admin/manager | Policy |
| PRD-BR-014 | Toda modificación crítica auditada | Service |

### v2.1 — Reglas del motor de reservas que gobiernan Reserva 360

| ID | Regla | Dónde |
|---|---|---|
| RES-V21-01 | La pestaña «Detalle de la reserva web» existe solo si hay snapshot `booking`; muestra el dato histórico aunque la regla se haya desactivado después, con etiqueta «R-x inactiva · dato histórico» (R-MULTICURRENCY, R-LODGING, R-ADDONS, R-PICKUP, R-COMMENTS, R-DEPOSIT/R-PAY-LATER) | Resource / UI |
| RES-V21-02 | Operaciones (sin permisos de rentabilidad) no recibe `lines`, `adjustments`, `total`, `payment` del snapshot | `ReservationWebSnapshotResource` según Policy |
| RES-V21-03 | Totales: habitaciones = Σ habitaciones por ciudad; noches = Σ noches de las ciudades con alojamiento (no se multiplican por habitaciones) | `BookingEngine` (servidor) |
| RES-V21-04 | Un solo titular por reserva; edad a la fecha del viaje; tipo ADT/CHD/INF según `paxRules` del producto (R-CHILD-PER-ADULT, R-INFANT-FREE) | FormRequest + índice parcial |
| RES-V21-05 | Pestaña «Comentarios del pasajero» visible si hay booking o comentarios; si R-PAX-FEEDBACK está desactivada se avisa y solo se ven registros previos | UI / Policy |
| RES-V21-06 | Responder requiere `inbox.manage`; derivar requiere `operations.incidents`; Ventas solo sobre su cartera | Policy |

## 6. API REST

| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| GET | `/api/v1/reservations` | reservations.view | Lista; `filter[status]`, `filter[channel]`, `filter[owner]`, `filter[q]`; campos de venta omitidos para operaciones | — |
| GET | `/api/v1/reservations/kpis` | reservations.view | 4 KPIs | — |
| GET | `/api/v1/reservations/occupancy?month=` | reservations.view | Calendario, próximos 14 días, salidas 14 días | — |
| POST | `/api/v1/reservations/availability-check` | reservations.create | Previsualiza disponibilidad y precio de reserva directa | — |
| POST | `/api/v1/reservations` | reservations.create | Reserva directa `{client_id, channel, owner_id, product_id, rate_id, departure_id|date, adt, chd, inf, notes, confirm}` | **Idempotency-Key**, lock salida |
| GET | `/api/v1/quotes/{id}/conversion-preview` | reservations.create | Precondiciones + disponibilidad por ítem + alternativas | — |
| POST | `/api/v1/quotes/{id}/convert` | reservations.create | §4.3 | **Idempotency-Key obligatorio**, If-Match, Redis lock, FOR UPDATE |
| GET | `/api/v1/reservations/{id}` | reservations.view | Reserva 360 filtrada por rol | — |
| POST | `/api/v1/reservations/{id}/confirm` | reservations.modify | §4.1 | If-Match, lock salidas |
| POST | `/api/v1/reservations/{id}/start-operation` · `/complete` | reservations.modify | Cambio de estado | If-Match |
| POST · PUT · DELETE | `/api/v1/reservations/{id}/passengers[/{pid}]` | reservations.modify | Alta/edición/baja de pasajero | If-Match |
| POST | `/api/v1/reservations/{id}/services/{sid}/request` · `/services/request-pending` | reservations.modify | Solicitud a proveedor (Email) | — |
| POST | `/api/v1/reservations/{id}/services/{sid}/confirm` | reservations.modify | `{confirmation_code, time, confirmed_by}` | — |
| POST | `/api/v1/reservations/{id}/services/{sid}/reject` | reservations.modify | `{reason, detail, alternative_provider_id|same, alternative_date}` | — |
| POST | `/api/v1/reservations/{id}/changes/preview` | reservations.modify | Impacto y disponibilidad | — |
| POST | `/api/v1/reservations/{id}/changes` | reservations.modify | Solicitud (`apply_now` si tiene aprobación) | If-Match |
| POST | `/api/v1/reservations/{id}/changes/{cid}/approve` · `/reject` | quotes.approve_internal (propuesto reservations.approve_changes) | Aplica o rechaza | If-Match, lock salidas |
| POST | `/api/v1/reservations/{id}/reschedule/preview` | reservations.modify | Tabla por servicio, diff, bloqueos | — |
| POST | `/api/v1/reservations/{id}/reschedule` | reservations.modify | `{new_start, reason}` | **Idempotency-Key**, If-Match, lock salidas |
| POST | `/api/v1/reservations/{id}/cancellation/preview` | reservations.cancel | Líneas, penalidad, reembolso, efectos | — |
| POST | `/api/v1/reservations/{id}/cancel` | reservations.cancel | `{reason, detail}` | **Idempotency-Key**, If-Match, lock salidas |
| POST | `/api/v1/reservations/{id}/cancel-requests` | reservations.create (dueño) | Solicitud de anulación | UNIQUE pendiente |
| POST | `/api/v1/reservations/{id}/cancel-requests/{rid}/reject` | reservations.cancel | `{note}` | — |
| POST | `/api/v1/reservations/{id}/receivables/generate` | finance.receivables | CxC 40/60 manual | Idempotency-Key |
| POST | `/api/v1/finance/payments` | finance.payments.register | Pago aplicado a CxC (desde la reserva) | **Idempotency-Key** |
| POST | `/api/v1/reservations/{id}/operation-order` | operations.view | Genera orden operativa (módulo operaciones) | Idempotency-Key |
| GET | `/api/v1/reservations/{id}/documents/confirmation` | reservations.view | PDF confirmación (job `documents`) | — |
| POST | `/api/v1/reservations/{id}/reassign` | reservations.reassign (propuesto) | `{owner_id, note}` | If-Match |
| GET | `/api/v1/reservations/{id}/history` | reservations.view | Auditoría de reserva, servicios y CxC | — |

### v2.1 — Endpoints

| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| GET | `/api/v1/reservations/{code}/web-snapshot` | reservations.view | Snapshot `booking` + lodging + addons; importes omitidos para operaciones | — |
| PATCH | `/api/v1/reservations/{code}/passengers/{id}` | reservations.modify | Ahora acepta `relationship`, `email`, `is_lead`; recalcula `age` | `lock_version` |
| GET | `/api/v1/reservations/{code}/pax-feedback` | reservations.view | Comentarios del pasajero | — |
| POST | `/api/v1/pax-feedback/{code}/read` | inbox.manage | `nuevo → leido` | — |
| POST | `/api/v1/pax-feedback/{code}/reply` | inbox.manage | `{channel: portal|email|whatsapp, text, status}` → crea `notif_pax_messages` saliente y actualiza la conversación | `Idempotency-Key` |
| POST | `/api/v1/pax-feedback/{code}/incident` | operations.incidents | `{title, type, severity, description}` → crea `ops_incidents` y marca `derivado` | `Idempotency-Key` |
| GET | `/api/v1/reservations/{code}/pax-messages` | reservations.view | Mensajes al pasajero (plantillas y respuestas) | — |

## 7. Permisos y comportamiento por rol

Variables del prototipo: `seeMargin` (rentabilidad o Ventas en lo propio), `opsView = operations.view ∧ ¬seeMargin`, `acc = ¬seeMargin ∧ ¬opsView`, `seeCost = seeMargin ∨ operations.view`, `seePrice = seeMargin ∨ ¬operations.view`. Botones sin permiso: visibles deshabilitados con candado y tooltip; al pulsar, toast y sin cambios.

| Rol | Alcance | Crear / convertir | Confirmar, modificar, reprogramar, pasajeros, servicios | Cancelar | Pagos | Qué ve / oculta |
|---|---|---|---|---|---|---|
| tenant_admin | Todo; filtro Asesor | Sí | Sí; aplica modificaciones (tiene `quotes.approve_internal`) | Sí; procesa/rechaza solicitudes | Registrar pago, generar CxC | Todo, margen incluido; reasigna |
| manager | Todo; filtro Asesor | Sí | Sí; aprueba modificaciones | Sí | Generar CxC sí; **Registrar pago deshabilitado** (sin `finance.payments.register`) | Todo; reasigna |
| sales | Solo propias; chip Mi cartera; ajena → "fuera de su cartera" | Sí (clientes de su cartera; vendedor fijo) | **No**: Confirmar/Modificar/Reprogramar deshabilitados; sin edición de pasajeros ni gestión de servicios | "Solicitar anulación" (o "Anulación solicitada" deshabilitado con fecha) | Botones deshabilitados | Margen de lo propio; pestañas completas |
| operations | Todo | No (sin botón "Nueva reserva directa"; no convierte) | Sí; modificaciones quedan **pendientes** de supervisor; reprogramación se aplica directo | Sin botón (no tiene cancel ni create) | Pestaña Pagos oculta | `opsView`: aviso "Vista de Operaciones", costo de proveedores en hechos y lista, sin total/saldo/margen; precios de servicio ocultos; enlace a cotización como texto |
| finance | Todo | No | No (aviso "Consulta enfocada en montos…") | No | Registrar pago y generar CxC | Margen visible |
| accounting | Todo | No | No | No | Generar CxC (tiene `finance.receivables`); registrar pago deshabilitado | `acc`: pestaña inicial Pagos, pestaña Operación oculta, card "Montos y pagos" sin costos |
| b2b, customer, passenger, guide, driver, provider | Sin acceso a `#/reservas` (portales propios en canales/proveedor/mobile) | — | — | — | — | — |
| super_admin | 403 | — | — | — | — | — |

Menú "Más" del detalle: Documento de confirmación; Solicitar todos los servicios pendientes (`reservations.modify`); Registrar pago (`finance.payments.register`); Iniciar operación / Completar viaje (`reservations.modify`); Reasignar asesor (admin/manager).

### v2.1 — Por rol
| Rol | Detalle web | Comentarios del pasajero |
|---|---|---|
| Tenant Admin / Gerente | Todo, con importes | Ver, responder, derivar |
| Ventas | Solo reservas propias (otra → «Registro fuera de su cartera»); con importes | Ver y responder (`inbox.manage`); derivar deshabilitado con tooltip |
| Operaciones | Sin importes (aviso); recojo, vuelo, comentarios, alojamiento, adicionales | Ver, responder, derivar |
| Finanzas / Contabilidad | Con importes y plan de pago | Solo lectura (botones deshabilitados con «Requiere permiso …») |

## 8. Eventos, jobs (colas Redis) y notificaciones

| Evento | Cola | Notificación (evento · canal → destinatario) |
|---|---|---|
| `QuoteConverted`, `ReservationCreated`, `ReservationConfirmed` | critical → notifications | `reserva.confirmada` · Email → cliente; Push → dueño (conversión) |
| `BookingServiceRequested` | integrations/notifications | `proveedor.solicitud` · Email → proveedor (reserva, servicio, fecha, pax) |
| `BookingServiceRejected` | notifications | `proveedor.rechazo` · Push → dueño |
| `ReservationModificationRequested` | notifications + Reverb bandeja | `reserva.modificacion` · Push → gerencia (prototipo U02 fijo) |
| `ReservationModified` | notifications | `reserva.modificada` · Email → cliente |
| `ReservationRescheduled` | notifications, documents (reemisión vouchers) | `cambio.horario` · Email → cliente; Push → coordinador de la orden (o U05) |
| `ReservationCancellationRequested` / `…Rejected` / `…Approved` | notifications | `reserva.anulacion_solicitada` · Portal → managers; `reserva.anulacion_rechazada|aprobada` · Portal → solicitante |
| `ReservationCancelled` | critical (finanzas), notifications | `reserva.cancelada` · Email → cliente; Push → coordinador; Portal → Finanzas |
| `AvailabilityReleased` | default | — (sincroniza portales/e-commerce) |
| `PaymentApplied` | critical | `pago.recibido` · Email → cliente |
| `ReservationReassigned` | notifications | `reserva.reasignada` · Portal → nuevo dueño |
| `OperationOrderCreated` | default | `operacion.creada` · Push → coordinador |

Jobs: **JOB-01 `holds:expire`** cada 5 min (`holdMinutes` 30) para holds temporales `hold` vencidos → `expirada` y `sold` recalculado; **`GenerateReservationConfirmationPdf`** (documents); propuestos: `reservations:detect-pending` (pendientes de proveedor/pago próximos a viajar), recordatorio de CxC por vencer (finanzas), `reservations:auto-start` opcional el día de inicio.

### v2.1 — Eventos
`PaxFeedbackReplied` (cola `notifications`: envía por el canal elegido y registra entrega/lectura en `notif_pax_messages`), `PaxFeedbackEscalated` → `IncidentCreated` (notifica al coordinador; crítica → gerencia por push/WhatsApp). Auditoría: `Comentario pasajero · leido/responder/derivar`, `Reserva · editar_pasajero` con parentesco/titular.

## 9. Auditoría

`Reserva`: `crear`, `crear_desde_cotizacion`, `confirmar`, `verificar_disponibilidad`, `cambio_estado` (incl. automáticos por servicios/pago), `agregar_pasajero`, `editar_pasajero`, `eliminar_pasajero`, `solicitar_modificacion`, `modificar`, `rechazar_modificacion`, `reprogramar`, `solicitar_anulacion`, `rechazar_solicitud_anulacion`, `cancelar` (penalidad, reembolso, motivo), `reasignar`. `Servicio reserva`: `solicitar_proveedor`, `confirmar_proveedor`, `rechazo_proveedor`. `Disponibilidad`: `liberar`. `Cuenta por cobrar`: `crear`, `cancelar`. `Pago`: `registrar_aplicar`. `Nota de crédito`: `emitir`. `Voucher`: `anular`. `Orden operativa`: `crear`, `cancelar`. `Oportunidad`: `cambio_etapa`. `Cotización`: `convertir`, `convertir_idempotente`.

## 10. Casos de prueba mínimos

| # | Caso | Tipo |
|---|---|---|
| T-R01 | E2E-02: cotización aprobada → convertir → reserva `confirmada`, servicios desglosados (Σ = costT/saleT), CxC 40/60 con vencimientos exactos, oportunidad ganada, cotización `convertida` | Pest feature + Playwright |
| T-R02 | Idempotencia: 2 POST convert con la misma key → misma respuesta; con distinta key → 200 con la reserva existente; 10 requests concurrentes → 1 reserva (UNIQUE parcial) | Pest feature (paralelo) |
| T-R03 | Carrera de cupos: dos conversiones sobre la misma salida con cupo para una sola → una 201, otra 409 `PRD-BR-012`; `sold ≤ capacity` | Pest feature |
| T-R04 | Convertir cotización `enviada` → 409 `RT-3` | Pest feature |
| T-R05 | Reserva directa sin cupo con confirm=true → 409; confirm=false → `pendiente_disponibilidad` | Pest feature |
| T-R06 | Confirmar con servicio rechazado sin alternativa → 409 | Pest feature |
| T-R07 | Rechazo de proveedor con alternativa → `pendiente_proveedor`; confirmar alternativa → `confirmada` automático | Pest feature |
| T-R08 | Modificación por Operaciones queda `pendiente`; Manager aprueba → total, CxC y cupos ajustados; delta negativo reduce CxC | Pest feature |
| T-R09 | Reprogramación: diff de temporada exacto, salidas liberadas/retenidas, vouchers anulados, servicios `pendiente`; bloqueada con servicio completado | Pest feature |
| T-R10 | E2E-09: cancelación RES-000348 → penalidad 53 480, reembolso 0, CxC penalidad 30 036, cupos liberados, CxC abiertas canceladas, orden cancelada, auditoría | Pest feature |
| T-R11 | Cancelación con reembolso → nota de crédito + pago `reembolso` pendiente | Pest feature |
| T-R12 | Tramos: 48 h/24 h/Tren/estándar con días 0,1,2,6,7,14,15 | Pest unit |
| T-R13 | Ventas: no ve reservas ajenas; 403 en confirm/modify/reschedule/cancel; puede crear solicitud de anulación solo en propias | Pest feature |
| T-R14 | Operaciones: respuesta sin `total_amount`/`price` ni margen; 403 en cancel y create | Pest feature |
| T-R15 | Pago rápido: monto > saldo → 422; pago que salda `pendiente_pago` → `confirmada` | Pest feature |
| T-R16 | Aislamiento tenant (E2E-08) en reservas, holds y CxC | Pest feature |

## 11. Cambios y decisiones respecto a la documentación inicial

| # | Tema | Documentación inicial | Prototipo / esta especificación | Tipo | Motivo |
|---|---|---|---|---|---|
| 1 | Estado tras conversión (PROC-025) | "… Vincular quote/version → Confirmar" | La reserva nace `confirmada` con servicios `pendiente` de solicitud a proveedor; pasa a `pendiente_proveedor` solo si un proveedor rechaza | Precisado | La confirmación comercial precede a la confirmación de proveedores |
| 2 | Idempotencia | "Idempotencia" | Doble barrera: `Idempotency-Key` + regla de negocio "una cotización → una reserva para siempre" (incluso si la reserva se cancela) + UNIQUE parcial en DB | Precisado | Evitar reservas duplicadas por doble clic o reintentos |
| 3 | Disponibilidad en conversión | "Disponibilidad" | Sin conversión parcial; con un ítem sin cupo se ofrece crear nueva versión con fecha alternativa (requiere nueva aprobación) y se listan salidas alternativas | Precisado | La reserva debe respetar la versión exacta aprobada |
| 4 | Obligaciones de cobro | PROC-040/041 genéricos | CxC automáticas 40 % anticipo (vence ≤ 3 días) / 60 % saldo (15 días antes del viaje) al convertir, crear directa o confirmar | Agregado | Condición comercial estándar del tenant ("40% de anticipo… saldo 15 días antes") |
| 5 | Desglose de servicios | "Copiar snapshot" | Cada ítem se descompone en servicios por componente del producto con prorrateo de costo y precio por peso de costo; proveedor por componente | Agregado | Base para PROC-031/032 y cuentas por pagar |
| 6 | Pasajeros | "pasajeros" como precondición | Se crean fichas "por completar" según ADT/CHD/INF; datos nominativos se completan después con validación de tipo por edad | Precisado | La cotización no captura datos nominativos |
| 7 | Reserva directa | PROC-026 sin origen explícito | Reserva sin cotización con estados `borrador`/`pendiente_disponibilidad`/`confirmada` | Agregado | Ventas de mostrador y B2B |
| 8 | Modificación (PROC-027) | Solicitud → … → Aprobación | Tipos cerrados (pax, servicio, upgrade, otro), impacto sugerido editable, aprobación por supervisor con `quotes.approve_internal`, ajuste automático de CxC y crédito | Precisado | Control de cambios de precio |
| 9 | Reprogramación (PROC-029) | Nueva fecha → Disponibilidad → Tarifa → … | Desplazamiento uniforme de todas las fechas; tarifa = factor de temporada nuevo/antiguo; sin aprobación; reinicia servicios, asignaciones y vouchers; diferencia positiva → CxC | Precisado | Implementación mínima trazable |
| 10 | Cancelación (PROC-028) | Política → impacto económico | Penalidad por servicio según tramos derivados de la política snapshot; ejecutados al 100 %; factor k para incluir IGV; nota de crédito + reembolso pendiente; CxC de penalidad | Precisado | E2E-09 |
| 11 | Quién cancela | No definido | Solo `reservations.cancel` (Admin/Gerencia); Ventas solicita anulación con flujo de aprobación | Agregado | Segregación de funciones |
| 12 | Estados | Borrador → Pend. disponibilidad → Confirmada → En operación → Completada (+ alternativos) | Este módulo usa todos salvo `parcial` e `incidencia`; `pendiente_pago` lo asignan otros canales | Precisado | — |
| 13 | Pago (PROC-042) | Pendiente → Confirmado → Aplicado | Pago rápido desde la reserva se registra directamente `aplicado` | Cambiado | Atajo operativo; ver §12 |
| 14 | PROC-030 | Reglas por canal, crédito, autorizaciones | Solo canal y tarifa por canal | Pendiente | Ver §12 |
| 15 | Datos sensibles por rol | No definido | Operaciones ve costos sin venta/margen; Contabilidad ve montos sin costos; Ventas solo lo propio | Agregado | Principio 2 de roles |
| 16 | Orden operativa desde la reserva | PROC-031 en Operaciones | Botón "Generar orden operativa" en la pestaña Operación | Agregado | Handoff Reserva → Operaciones |

### v2.1 — Cambios
Se agregó el snapshot de la reserva web (pedido del cliente puntos 2–7, 10, 11, 13), parentesco/edad/titular de pasajeros (punto 5) y la gestión de comentarios del pasajero desde Reserva 360 (punto 9). Decisión: en el back-office el snapshot no se oculta al desactivar una regla (es evidencia de lo cobrado); en portales sí se oculta. Pruebas: Pest — snapshot inmutable, Operaciones sin importes (403 de campos), respuesta crea mensaje saliente, derivar crea incidencia; Playwright — U01 pestañas web/comentarios, U03 reserva ajena bloqueada, U05 sin importes.

## 12. Supuestos y puntos a validar con negocio

1. Tramos de penalidad derivados de texto libre (`tiersFor` por regex). El backend debe guardar tramos estructurados `[{min_days, pct}]` en la política del producto y en el snapshot; confirmar la regla "Tren" (el texto dice "50 % hasta 7 días" pero el tramo aplicado da 0 % con ≥ 15 días).
2. `refund`/`owed` usan `paid` = pagos `aplicado|confirmado`; pagos `pendiente` no cuentan. Confirmar.
3. Factor `k` excluye servicios `rechazado`; si uno quedó rechazado sin alternativa, `k` > 1 infla la penalidad. ¿Excluir su precio del total antes de calcular?
4. Nota de crédito usa la serie de Factura (`F001-`); debe tener serie propia (FC01/BC01 según comprobante original) y referenciar el comprobante. Validar con Contabilidad.
5. Reprogramación: no aplica la política de cambios ("sin costo hasta 10 días antes"), no pide aprobación y no genera crédito si la diferencia es negativa. ¿Se agrega cargo por cambio y crédito?
6. Reserva directa: no aplica IGV y la moneda es la de la tarifa; la conversión sí incluye IGV. Unificar criterio.
7. Reserva directa guardada como `borrador` con disponibilidad **retiene cupo** y crea CxC; ¿el borrador debería ser un hold temporal con expiración (`holdMinutes`) y sin CxC?
8. Modelos sin salida (`CUPOS`, `POR_HABITACION`, `CAPACIDAD`, `POR_TRANSPORTE`, `SIN_CONTROL`) no descuentan inventario interno. ¿Se integran con Cupos y bloqueos (allotments) del catálogo?
9. Tras convertir con ítems en hold de proveedor, la reserva queda `confirmada`; ¿debería quedar `pendiente_proveedor` hasta confirmar?
10. Una cotización cancelada tras convertir no puede volver a convertirse; ¿se permite re-conversión con nueva versión?
11. Aprobación de modificaciones con `quotes.approve_internal`; propuesta: permiso `reservations.approve_changes`. Destinatario de la notificación hoy fijo (U02); debe ir a todos los aprobadores.
12. Agregar/eliminar pasajeros en la pestaña Pasajeros no ajusta precio ni cupos (solo avisa). ¿Bloquear o forzar Modificación?
13. Pago rápido `aplicado` directo vs. flujo Pendiente → Confirmado → Aplicado de Finanzas; ¿solo Finanzas y con validación?
14. PROC-030: reglas de crédito B2B/corporativo (límite, días), liberados de grupo, autorizaciones corporativas: no implementadas.
15. Vencimientos 40/60 fijos; ¿parametrizar por cliente/segmento (p. ej. crédito 30 días corporativo)?
16. Iniciar operación manual (día −1) y completar viaje manual; ¿automatizar por scheduler con fin de servicios?
17. Destinatarios fijos del prototipo (U05 coordinador por defecto, U07 finanzas) deben resolverse por rol/sede.
