# Cotizaciones

> Fuente de verdad del comportamiento: `proto/assets/js/modules/ventas.js` (sección COTIZACIONES), `core.js` (`Pricing`, `STATUS`, `Access`, `Scope`), `data.js` (`quotes`, `rolePerms`, `settings`, `products`, `seasons`). Integraciones que tocan cotizaciones desde otros módulos: `canales.js` (portal del cliente: vista/aprobación/rechazo), `admin.js` (JOB-02 vencimiento), `dashboard.js` (bandeja de aprobaciones de gerencia). Conversión a reserva y todo lo posterior: ver `04-reservas.md`.

## 1. Propósito y procesos cubiertos (PROC-xxx)

| Proceso | Qué cubre el módulo | Resultado |
|---|---|---|
| PROC-021 Crear cotización | Constructor: cliente, oportunidad, pasajeros ADT/CHD/INF, fechas, moneda, ítems de catálogo con tarifa/segmento/temporada, costo y precio por línea, descuento global, IGV, notas y condiciones. Aprobación interna (gerencia) previa al envío cuando el pricing sale de política. | Cotización `borrador` v1 |
| PROC-022 Versionamiento | v1 → v2 → v3; la versión anterior pasa a `reemplazada` y queda de solo lectura; comparación de versiones; snapshot de catálogo y de políticas por versión. | Historial inmutable |
| PROC-023 Envío | Generación de token/enlace, envío por Email/WhatsApp/Portal, registro de visualización, documento (vista de impresión / PDF por job). | `enviada` → `vista` |
| PROC-024 Aprobación/rechazo | Negociación, aprobación del cliente con timestamp + evidencia, rechazo con motivo obligatorio, bloqueo por vencimiento. | `aprobada` / `rechazada` |
| PROC-025 (entrada) | Botón "Convertir en reserva" y chequeo idempotente. El proceso completo se especifica en `04-reservas.md §4.3`. | `convertida` |
| PROC-018 Pricing (consumo) | Precio sugerido = tarifa vigente × factor de temporada, convertido a la moneda de la cotización; totales con `Pricing.quoteTotals`. | Totales de versión |

Excepciones del DOC-PROC-001 §24 implementadas: "Cotización vencida → Bloquear aprobación → Nueva versión" y "Cambio de catálogo posterior a cotización → la cotización conserva snapshot; la nueva versión recalcula".

## 2. Pantallas

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/cotizaciones` | `/cotizaciones` | Lista con 5 KPIs (abiertas, monto en seguimiento S/, tasa de aprobación, por vencer ≤ 3 d, pendientes de gerencia), alerta de solicitudes pendientes (solo quien tiene `quotes.approve_internal`), filtro "Asesor" (Admin/Gerente) o chip "Mi cartera" (Ventas), tabla con búsqueda, filtros Estado y Aprobación interna, exportación CSV | `quotes.view`: tenant_admin, manager, sales (solo propias), finance |
| `#/cotizaciones/nueva` y `#/cotizaciones/nueva/{OPP}` | `/cotizaciones/nueva?opp={code}` | Constructor (nueva). Precarga desde oportunidad (cliente, título, pax, moneda, fecha del lead) | `quotes.create`: tenant_admin, manager, sales. Otros ven aviso "no tiene quotes.create" |
| `#/cotizaciones/{code}` | `/cotizaciones/[code]` | Detalle 360: flujo de estados, hechos clave, banner de aprobación interna, acciones por estado; pestañas Versiones, Detalle e ítems, Pricing (solo quien ve margen), Documento, Envío y seguimiento, Actividad | `quotes.view` + ownership para Ventas |
| `#/cotizaciones/{code}/editar` | `/cotizaciones/[code]/editar` | Constructor en modo edición; solo si la versión vigente está en `borrador` (si no, toast y redirección al detalle) | `quotes.create` + ownership |
| Modales/drawers del detalle | Rutas interceptadas o `Dialog` | Enviar, Registrar negociación, Registrar aprobación del cliente, Rechazar, Solicitar/decidir aprobación interna, Nueva versión, Comparar versiones, Ver versión (drawer), Vista de impresión, Reasignar, Convertir (ver 04) | Según permiso de cada acción (§7) |
| Portal del cliente `#/canales/portal-cliente/{CLI}/cotizaciones/{code}` (módulo canales) | `/(portal-cliente)/cotizaciones/[code]` | El cliente abre (→ `vista`), aprueba (con aceptación de condiciones, IP y hash) o rechaza | customer (solo sus cotizaciones) |

## 3. Modelo de datos PostgreSQL

Todas las tablas: `tenant_id uuid not null references tenants(id)`, RLS `tenant_isolation`, `created_by/updated_by uuid`, `created_at/updated_at timestamptz`. PK `id uuid` v7.

### quote_quotes (cabecera)
| columna | tipo | null | notas/FK/índice |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK tenants; índice compuesto en todos los índices |
| code | varchar(30) | no | `COT-2026-00192` (serie `Cotización`, prefijo `COT-{YYYY}-`, 5 dígitos, `sys_sequences` FOR UPDATE). `UNIQUE(tenant_id, code)` |
| client_id | uuid | no | FK `crm_clients` |
| opportunity_id | uuid | sí | FK `crm_opportunities` |
| owner_id | uuid | no | FK `iam_users` (vendedor). Índice `(tenant_id, owner_id)` |
| title | varchar(200) | no | |
| status | varchar(30) | no | `CHECK (status IN ('borrador','enviada','vista','negociacion','aprobada','rechazada','vencida','convertida','cancelada'))`. Índice `(tenant_id, status)` |
| current_version_no | smallint | no | default 1 |
| current_version_id | uuid | sí | FK `quote_versions` (deferrable) |
| travel_from | date | no | |
| travel_to | date | no | `CHECK (travel_to >= travel_from)` |
| valid_until | date | no | índice `(tenant_id, valid_until)` para el job de vencimiento |
| currency | char(3) | no | `CHECK (currency IN ('PEN','USD'))` |
| fx_rate | numeric(12,6) | no | TC vigente al crear/versionar (settings.fx 3.74) |
| pax_adt / pax_chd / pax_inf | smallint | no | default 0; `CHECK (pax_adt+pax_chd+pax_inf > 0)` |
| viewed_at | timestamptz | sí | última apertura del enlace |
| approved_at | timestamptz | sí | |
| approved_by_name | varchar(150) | sí | nombre de quien aprueba por el cliente |
| approval_channel | varchar(60) | sí | evidencia: `Aceptación en portal (enlace con token)`, `Correo electrónico del cliente`, `Mensaje de WhatsApp`, `Firma de conformidad`, `Orden de compra / servicio`, `Portal cliente` |
| approval_ref | varchar(200) | sí | referencia de la evidencia |
| approval_recorded_by | uuid | sí | usuario interno que registró (null si fue el cliente en portal) |
| approval_ip | inet | sí | portal |
| approval_hash | varchar(80) | sí | `sha256:` del snapshot aprobado (portal) |
| approval_version_no | smallint | sí | versión aprobada |
| rejected_at | timestamptz | sí | |
| rejected_reason | text | sí | obligatorio al rechazar |
| reservation_id | uuid | sí | FK `booking_reservations`; set al convertir |
| converted_at | timestamptz | sí | |
| duplicated_from_id | uuid | sí | FK self (acción Duplicar) |
| lock_version | integer | no | default 0 |
| deleted_at | timestamptz | sí | SoftDeletes; prohibido borrar si tiene versiones enviadas |

Índices extra: `(tenant_id, created_at desc)`, `(tenant_id, client_id)`, `(tenant_id, opportunity_id)`, trigram opcional sobre `title`.

### quote_versions
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| quote_id | uuid | no | FK quote_quotes ON DELETE RESTRICT |
| version_no | smallint | no | `UNIQUE(tenant_id, quote_id, version_no)` |
| status | varchar(30) | no | `CHECK (status IN ('borrador','enviada','aprobada','rechazada','reemplazada','vencida'))` |
| issued_on | date | no | `v.date` |
| author_id | uuid | no | `v.user` |
| discount_pct | numeric(5,2) | no | default 0; `CHECK (discount_pct BETWEEN 0 AND 50)` |
| tax_pct | numeric(5,2) | no | 18 (Perú) o 0 (cliente no domiciliado) |
| markup_pct | numeric(7,2) | no | derivado: `(sale/cost − 1)×100` redondeado a 2 |
| notes | text | sí | |
| conditions | text | sí | default "Precios sujetos a disponibilidad… 40% de anticipo; saldo 15 días antes…" |
| snapshot_label | varchar(300) | no | `PRD-002 v1.1 / PRD-007 v1.0` |
| catalog_snapshot | jsonb | no | producto+versión, componentes, tarifa aplicada, temporada, inclusiones/exclusiones por ítem (PRD-BR-009) |
| policies_snapshot | jsonb | no | `{productId: {cancel, modify, noshow, refund, advanceMin, advanceMax, tiers?}}` clonado del producto al guardar/versionar |
| fx_rate | numeric(12,6) | no | |
| cost_total, sale_total, discount_total, net_total, tax_total, grand_total, profit_total | numeric(14,2) | no | resultado de `quoteTotals` calculado en backend; recalculados mientras `borrador`, congelados al enviar |
| margin_pct | numeric(7,2) | no | |
| internal_approval_id | uuid | sí | FK quote_internal_approvals (aprobación vigente de la versión) |
| frozen_at | timestamptz | sí | set al pasar de `borrador` a `enviada`; trigger impide UPDATE de ítems/montos si no es null |
| content_hash | varchar(80) | sí | hash del snapshot congelado |
| pdf_document_id | uuid | sí | FK `sys_documents` |
| lock_version | integer | no | default 0 |

### quote_items
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| quote_version_id | uuid | no | FK quote_versions ON DELETE CASCADE (solo borrador) ; índice |
| line_no | smallint | no | orden |
| product_id | uuid | no | FK `catalog_products` |
| product_version | varchar(10) | no | versión `vigente` del producto al guardar (`prodVer`) |
| rate_id | uuid | sí | FK `catalog_rates`; null = precio manual |
| segment | varchar(15) | no | `CHECK (segment IN ('Adulto','Niño','Infante','Grupo','Pasajero'))` |
| service_date | date | no | |
| qty | integer | no | `CHECK (qty > 0)` |
| pax | integer | no | `CHECK (pax > 0)` |
| unit_cost | numeric(14,2) | no | `CHECK (unit_cost >= 0)` |
| unit_price | numeric(14,2) | no | `CHECK (unit_price > 0)` |
| cost_total | numeric(14,2) | no | generated `unit_cost*qty*pax` |
| sale_total | numeric(14,2) | no | generated `unit_price*qty*pax` |
| season_id | uuid | sí | temporada aplicada a `service_date` |
| season_factor | numeric(6,3) | sí | factor efectivo `f` usado (ver §5 PRC) |
| price_source | varchar(10) | no | `CHECK IN ('rate','manual')` |
| rate_snapshot | jsonb | sí | tarifa usada (tipo, canal, moneda, costo, precio, vigencia, temporada base) |

### quote_internal_approvals (solicitud y decisión de gerencia)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| quote_version_id | uuid | no | FK; índice `(tenant_id, status)` para la bandeja |
| reasons | jsonb | no | `["descuento 8.0% > 5%","margen 12.4% < 15%"]` |
| over_cap | boolean | no | descuento > 10 % |
| requested_by | uuid | sí | null si el aprobador aprobó directo sin solicitud |
| requested_at | timestamptz | sí | |
| request_note | text | sí | justificación comercial (obligatoria al solicitar) |
| status | varchar(20) | no | `CHECK (status IN ('pendiente','aprobada','rechazada','anulada'))` |
| decided_by | uuid | sí | |
| decided_at | timestamptz | sí | |
| decision_note | text | sí | obligatoria al decidir |
| is_override | boolean | no | true siempre que se aprueba (override de política comercial) |

Restricción: `UNIQUE (tenant_id, quote_version_id) WHERE status = 'pendiente'` (una solicitud pendiente por versión).

### quote_sends
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id, quote_id | uuid | no | FK; índice `(tenant_id, quote_id)` |
| version_no | smallint | no | |
| channel | varchar(15) | no | `CHECK IN ('Email','WhatsApp','Portal')` |
| recipient | varchar(200) | no | email, teléfono o nombre (portal) |
| message | text | sí | mensaje editable del modal |
| sent_by | uuid | no | |
| sent_at | timestamptz | no | |
| notification_id | uuid | sí | FK `notif_messages` (estado de entrega) |

### quote_access_tokens
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id, quote_id | uuid | no | FK |
| token_hash | varchar(80) | no | se guarda hash; el token en claro solo en la URL `https://portal.{dominio}/c/{token}`. `UNIQUE(token_hash)` |
| created_by / created_at | uuid / timestamptz | no | |
| expires_at | date | no | = `valid_until` |
| revoked_at | timestamptz | sí | al regenerar se revoca el anterior |

### quote_views
| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id, quote_id | uuid | no | |
| version_no | smallint | no | |
| viewed_at | timestamptz | no | |
| source | varchar(15) | no | `CHECK IN ('portal','manual')` (manual = "Registrar vista" interno) |
| ip / user_agent | inet / text | sí | portal |
| recorded_by | uuid | sí | manual |

### quote_negotiations
| columna | tipo | null | notas |
|---|---|---|---|
| id, tenant_id, quote_id | uuid | no | |
| channel | varchar(15) | no | `CHECK IN ('Llamada','Reunión','Email','WhatsApp')` |
| note | text | no | contrapropuesta del cliente (obligatoria) |
| created_version_no | smallint | sí | si se marcó "Crear nueva versión" |
| by / at | uuid / timestamptz | no | |

Restricciones clave: una sola versión vigente por cotización (`current_version_no = max(version_no)`); una versión con `frozen_at` no admite cambios de ítems (trigger + policy); `quote_quotes.reservation_id` único por tenant cuando no es null.

## 4. Máquinas de estado

### 4.1 Cotización (`quote_quotes.status`)

| Desde | Hacia | Acción / quién | Guardas (el prototipo las aplica) | Efectos |
|---|---|---|---|---|
| — | `borrador` | Guardar en constructor · `quotes.create` | Validaciones QTE-BR-01…10 | Crea v1 `borrador`; si la oportunidad está en `calificacion`/`descubrimiento` → `cotizacion`, probabilidad 55; audita `crear` (y `descuento_sobre_tope` si > 10 %) |
| `borrador` | `enviada` | Enviar · `quotes.send` | Versión con ítems; sin aprobación interna pendiente (QTE-BR-12); al menos un canal | Genera token si no existe; `v.status='enviada'`; si `valid_until` < hoy la renueva a hoy + 10 d; 1 registro en `quote_sends` y 1 notificación por canal; congela la versión |
| `enviada`/`vista`/`negociacion` | (mismo) | Reenviar · `quotes.send` | No vencida; no `aprobada/convertida/rechazada` | Solo registra envío y notificación |
| `enviada` | `vista` | Cliente abre el enlace (portal) o "Registrar vista" · `quotes.send` | Debe tener token y no ser `borrador`. Desde `vista`/`negociacion` solo actualiza `viewed_at` | `viewed_at`; notifica al vendedor |
| `enviada`/`vista`/`negociacion` | `negociacion` | Registrar negociación · `quotes.send` | Nota obligatoria (botón solo si no vencida) | Oportunidad → `negociacion` (prob. 70) si no está ganada/perdida/negociación; opcional crear nueva versión |
| `enviada`/`vista`/`negociacion` | `aprobada` | Registrar aprobación del cliente · `reservations.create`; o el cliente desde el portal | No vencida (QTE-BR-16); aprobación interna resuelta o registrada en el mismo paso por quien tiene `quotes.approve_internal` (QTE-BR-13) | `v.status='aprobada'` (inmutable); `approved_at`, `approved_by_name`, evidencia; notifica Push vendedor + Email cliente |
| `enviada`/`vista`/`negociacion`/`vencida` | `rechazada` | Rechazar · `quotes.send`; o el cliente desde el portal | Motivo obligatorio | `v.status='rechazada'`; notifica al vendedor |
| cualquier estado salvo `convertida` | `borrador` | Crear nueva versión · `quotes.create` | QTE-BR-18 | Copia la versión vigente → v(n+1) `borrador`; la anterior → `reemplazada`; borra aprobación del cliente y aprobación interna; recalcula snapshot y políticas; renueva validez si quedan < 10 d |
| `borrador`/`enviada`/`vista`/`negociacion` | `vencida` | Job JOB-02 (scheduler) cuando `valid_until < hoy` | — | Audita `vencer` (actor Sistema). La UI además trata como vencida cualquier `enviada/vista/negociacion` con validez pasada aunque el job no haya corrido |
| `aprobada` | `convertida` | Convertir en reserva · `reservations.create` | Ver `04-reservas.md §4.3` | `reservation_id`, oportunidad `ganada` |
| — | `cancelada` | No implementado en el prototipo (estado existe en `STATUS`) | — | Ver §12 |

Estados de versión (`quote_versions.status`): `borrador` (editable) → `enviada` (congelada) → `aprobada` (inmutable, candado) / `rechazada` / `vencida` (solo seed) ; cualquier versión no vigente → `reemplazada`. Una versión en `negociacion` conserva `enviada`.

### 4.2 Solicitud de aprobación interna (`quote_internal_approvals.status`)

| Desde | Hacia | Quién | Efectos |
|---|---|---|---|
| — | `pendiente` | Dueño de la cotización con `quotes.create` o `quotes.send` | Notifica (Portal) a todos los usuarios `manager` activos |
| — / `pendiente` | `aprobada` | `quotes.approve_internal` (+ `quotes.discount_override` si descuento > 10 %) | `internal_approval` de la versión; justificación obligatoria; notifica al dueño si decide otro usuario |
| — / `pendiente` | `rechazada` | `quotes.approve_internal` | Motivo obligatorio; notifica al dueño. Si no había solicitud se crea una ya rechazada |
| `pendiente` | `anulada` | Sistema al editar el borrador | La edición también elimina la aprobación interna ya otorgada (se debe volver a evaluar) |

## 5. Reglas de negocio

### 5.1 Algoritmo exacto de pricing

**PRC-01 Precio sugerido por línea** (`suggest(rate, date, cur)`), se ejecuta al agregar un producto y al cambiar tarifa o fecha de la línea (sobrescribe costo y precio unitarios):
1. `baseFactor = season(rate.season).factor` (temporada con la que se cargó la tarifa; 1 si no tiene).
2. `s = Pricing.seasonFor(date)`: temporadas `activa` con `from ≤ date ≤ to`, ordenadas por `priority` ascendente (0 = mayor prioridad); se toma la primera. `seasonFactor = s ? s.factor : 1`.
3. `f = seasonFactor / baseFactor`.
4. `unit_cost = round2(conv(rate.cost × f, rate.currency, cur))`; `unit_price = round2(conv(rate.price × f, rate.currency, cur))`.
5. `conv(a, from, to)`: igual moneda → `a`; USD→PEN `a × fx`; PEN→USD `a ÷ fx` (`fx = settings.fx`, 3.74). `round2 = Math.round(x×100)/100` (backend: `ROUND_HALF_UP` a 2 decimales con `brick/math`).

Ejemplo: TAR-001 (USD, costo 268, precio 345, temporada base TMP-02 factor 1.00), fecha 2026-12-30, cotización en PEN. Temporadas activas ese día: TMP-03 Baja (prio 3, ×0.88) y TMP-05 Año Nuevo (prio 0, ×1.40) → gana TMP-05. `f = 1.40`. Precio = round2(345 × 1.40 × 3.74) = **1 806.42**; costo = round2(268 × 1.40 × 3.74) = **1 403.25**.

**PRC-02 Tarifa por defecto** (`defaultRate(p, date, client)`): tarifas válidas = `status ≠ 'vencida'` y `validFrom ≤ date ≤ validTo`. Preferencia: (1) canal = canal del segmento del cliente (`Corporativo`→Corporativo, `B2B`→B2B, `Grupos`→Corporativo, resto→Directo) y segmento ≠ Niño; (2) canal Directo y segmento ≠ Niño; (3) la primera válida; (4) ninguna → precio manual (toast de aviso). Tarifas no vigentes aparecen deshabilitadas "— no vigente (PRD-BR-005)".

**PRC-03 Defaults de línea**: `qty = 1`, `pax = max(1, ADT + CHD)`, `segment='Adulto'`; producto tipo `TRASLADO`: `pax = 1`, `qty = 2`, `segment='Pasajero'`. Si la tarifa elegida es de segmento Niño, la línea pasa a `Niño`.

**PRC-04 Totales de versión** (`Pricing.quoteTotals(v)`), autoridad backend (PRD-BR-010):
```
por línea:  costTotal = unit_cost × qty × pax
            saleTotal = unit_price × qty × pax
cost     = Σ costTotal
sale     = Σ saleTotal                     (venta bruta)
discount = sale × discount_pct / 100       (descuento global, sobre la venta)
net      = sale − discount                 (valor de venta)
tax      = net × tax_pct / 100             (IGV)
total    = net + tax
profit   = net − cost                      (utilidad bruta, sin IGV)
marginPct= net ≠ 0 ? profit / net × 100 : 0
markup_pct (guardado) = cost ≠ 0 ? (sale / cost − 1) × 100 : 0   (round2)
```
Sin redondeo intermedio en el prototipo (se redondea solo al mostrar). Backend: calcular con `numeric`/`brick/math` a escala 6 y persistir cada total `ROUND_HALF_UP` a 2. Margen de línea mostrado en constructor: `(unit_price − unit_cost)/unit_price × 100` (rojo < 15 %, ámbar < 22 %, verde ≥ 22 %).

Ejemplo COT-2026-00191 v3: PRD-002 1×60×(2 900 / 3 450) y PRD-007 1×60×(110 / 165), desc. 5 %, IGV 18 %. cost 180 600; sale 216 900; discount 10 845; net 206 055; tax 37 089.90; **total 243 144.90**; profit 25 455; **margen 12.35 % → requiere aprobación interna (margen < 15 %)**.

**PRC-05 Motor unitario `Pricing.calc`** (usado por el simulador de Catálogo y Configuración; el backend debe exponerlo como servicio único `PricingEngine::calc`, y la línea de cotización debe poder derivarse de él cuando la tarifa defina markup en vez de precio):
```
base     = cost + extra
markup   = base × markupPct/100 + markupFixed
subtotal = base + markup
discount = subtotal × discountPct/100 + discountFixed
net      = subtotal − discount
tax      = net × taxPct/100
total    = net + tax ; si round>0: total = round(total / round) × round   (settings.rounding = 0.10)
commission = net × commissionPct/100
profit   = net − base − commission
marginPct= net ≠ 0 ? profit/net×100 : 0
```
Diferencia a respetar: `quoteTotals` no aplica redondeo a 0.10 ni comisión (ver §12).

**PRC-06 Moneda**: cambiar la moneda en el constructor convierte todos los `unit_cost/unit_price` con `conv` y `round2`. Cotización en USD muestra "Equivalente en soles" `total × fx`. `fx_rate` se persiste por versión (regla transversal 15).

**PRC-07 IGV por defecto**: `tax_pct = 0` si `client.country ≠ 'Perú'` (exportación de servicios, cliente no domiciliado), si no `settings.taxPct` (18). Editable 0–18.

### 5.2 Reglas

| ID | Regla | Dónde se valida |
|---|---|---|
| QTE-BR-01 | Cliente, título, viaje desde, viaje hasta y válida hasta son obligatorios | FormRequest `StoreQuoteRequest` |
| QTE-BR-02 | `travel_to ≥ travel_from` ("La fecha de fin del viaje no puede ser anterior al inicio.") | FormRequest + CHECK |
| QTE-BR-03 | ADT + CHD + INF > 0 | FormRequest + CHECK |
| QTE-BR-04 | Al menos un ítem | FormRequest |
| QTE-BR-05 / PRD-BR-002 | Solo productos `ACTIVE` y `flags.quoteable ≠ false`; inactivos se listan deshabilitados con el motivo | FormRequest (rule `QuoteableProduct`) + Service |
| QTE-BR-06 / PRD-BR-005 | Tarifa del ítem vigente para la fecha del ítem; si al cambiar la fecha deja de serlo se quita la tarifa | FormRequest + Service |
| QTE-BR-07 | `qty > 0`, `pax > 0`, `unit_price > 0`, `unit_cost ≥ 0` | FormRequest + CHECK |
| QTE-BR-08 | Tope de descuento 10 % (`MAX_DISC`). Superarlo solo con `quotes.discount_override` (máx. 50 %); se audita `descuento_sobre_tope` | FormRequest + Policy |
| QTE-BR-09 | Ventas solo puede usar clientes de su cartera (`Scope.clientIds`: clientes propios + clientes de sus oportunidades, cotizaciones y reservas) y oportunidades propias no ganadas/perdidas | FormRequest + Policy |
| QTE-BR-10 | Solo se edita la versión vigente en `borrador`; editarla anula la solicitud de aprobación pendiente y borra la aprobación interna | Policy + Service |
| QTE-BR-11 | Requiere aprobación interna si `discount_pct > 5` o `marginPct < 15` (`needsInternal`) | Service `QuotePolicyEvaluator` |
| QTE-BR-12 | No se envía al cliente una versión con QTE-BR-11 sin aprobación interna | Service (409 `QTE-BR-12`) |
| QTE-BR-13 | No se registra la aprobación del cliente con QTE-BR-11 pendiente, salvo que el usuario tenga `quotes.approve_internal` y registre la aprobación interna en el mismo paso (checkbox + justificación) | Service |
| QTE-BR-14 | Aprobar internamente con descuento > 10 % exige además `quotes.discount_override` | Policy |
| QTE-BR-15 | Solo el dueño (si es Ventas) solicita aprobación; una sola solicitud pendiente por versión; justificación obligatoria | Policy + UNIQUE parcial |
| QTE-BR-16 | Cotización vencida (`vencida` o `enviada/vista/negociacion` con `valid_until < hoy`): no se reenvía ni se aprueba; se ofrece crear nueva versión (excepción DOC-PROC §24) | Service |
| QTE-BR-17 | Rechazo con motivo obligatorio; solo desde `enviada/vista/negociacion/vencida` | FormRequest + Service |
| QTE-BR-18 | Nueva versión permitida en cualquier estado salvo `convertida`; desde `aprobada` invalida la aprobación del cliente | Service |
| QTE-BR-19 / RT-7 | Versión `aprobada` inmutable; versiones no vigentes consultables y no editables (PRD-BR-017) | Trigger `frozen_at` + Policy |
| QTE-BR-20 / PRD-BR-008-009 | Cada versión guarda snapshot de productos (versión vigente) y copia de políticas; cambios posteriores del catálogo no la alteran | Service |
| QTE-BR-21 | Solo se aprueba (cliente) desde `enviada/vista/negociacion` | Service |
| QTE-BR-22 | Registrar vista requiere token y estado ≠ `borrador`; solo `enviada` cambia a `vista` | Service |
| QTE-BR-23 | Reenvío prohibido en `aprobada/convertida/rechazada` | Service |
| QTE-BR-24 | Al enviar una cotización con validez vencida en `borrador` se renueva `valid_until = hoy + quoteValidityDays` (10) | Service |
| QTE-BR-25 | Reasignar vendedor solo Tenant Admin / Manager; nuevo dueño distinto al actual; motivo opcional | Policy (`quotes.reassign`, ver §11) |
| QTE-BR-26 | Ventas no ve ni abre cotizaciones ajenas (pantalla "Registro fuera de su cartera"); tampoco en búsqueda global | Policy + global scope `owner=me` |
| QTE-BR-27 | Validez por defecto: hoy + `settings.quoteValidityDays` (10) | Service |
| RT-5 / PRD-BR-010-011 | El precio se recalcula en backend; el frontend solo previsualiza (endpoint `pricing/preview`) | Service |

## 6. API REST

| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| GET | `/api/v1/quotes` | quotes.view | Lista; `filter[status]` (incl. `vencida` calculada), `filter[internal_approval]=pend|req|ok`, `filter[owner]`, `filter[q]`, `sort`, cursor. Ventas: `owner=me` forzado | — |
| GET | `/api/v1/quotes/kpis` | quotes.view | 5 KPIs de la lista, con el mismo alcance | — |
| POST | `/api/v1/quotes/pricing/preview` | quotes.create | Recibe ítems/descuento/IGV/moneda; devuelve líneas, totales, margen y motivos de aprobación | — |
| GET | `/api/v1/catalog/products/quoteable` | quotes.create | Picker: todos los productos con `blocked_reason`, tarifas vigentes a la fecha y "desde" en la moneda | — |
| GET | `/api/v1/catalog/products/{id}/rate-suggestion?date=&currency=&client_id=&rate_id=` | quotes.create | PRC-01/02 | — |
| POST | `/api/v1/quotes` | quotes.create | Crea cotización + v1 borrador (+ efecto en oportunidad) | Idempotency-Key opcional |
| GET | `/api/v1/quotes/{id}` | quotes.view | Detalle con versión vigente; oculta costos/margen según §7 | — |
| PUT | `/api/v1/quotes/{id}/versions/{v}` | quotes.create | Edita borrador vigente | `If-Match: lock_version` |
| GET | `/api/v1/quotes/{id}/versions` · `/versions/{v}` | quotes.view | Historial / versión | — |
| GET | `/api/v1/quotes/{id}/versions/compare?a=&b=` | quotes.view | Diff por línea (clave `productId|segment#n`) y resumen | — |
| POST | `/api/v1/quotes/{id}/versions` | quotes.create | Nueva versión desde la vigente | If-Match |
| POST | `/api/v1/quotes/{id}/duplicate` | quotes.create | Nueva cotización v1 borrador, dueño = usuario actual, validez renovada | Idempotency-Key |
| POST | `/api/v1/quotes/{id}/internal-approval-requests` | quotes.create o quotes.send (+ dueño) | Solicitar aprobación a gerencia | UNIQUE pendiente |
| POST | `/api/v1/quotes/{id}/versions/{v}/internal-approval` | quotes.approve_internal (+ quotes.discount_override si > 10 %) | `{decision: approve|reject, note}` | If-Match |
| POST | `/api/v1/quotes/{id}/token` | quotes.send | Genera/regenera token (revoca el anterior) | — |
| POST | `/api/v1/quotes/{id}/send` | quotes.send | `{channels[], message}` | **Idempotency-Key**, If-Match |
| POST | `/api/v1/quotes/{id}/views` | quotes.send | Registrar vista manual | — |
| GET | `/api/v1/public/quotes/{token}` | público (token) | Portal: devuelve la versión vigente sin costos; registra vista (`source=portal`) | rate limit |
| POST | `/api/v1/public/quotes/{token}/approve` · `/reject` | público (token) + login cliente | Aprobación/rechazo del cliente (IP, hash, aceptación de condiciones) | **Idempotency-Key** |
| POST | `/api/v1/quotes/{id}/negotiations` | quotes.send | `{channel, note, create_version}` | — |
| POST | `/api/v1/quotes/{id}/approve` | reservations.create | Registrar aprobación del cliente `{approved_by_name, channel, ref, internal_approval?: {note}}` | **Idempotency-Key**, If-Match |
| POST | `/api/v1/quotes/{id}/reject` | quotes.send | `{reason}` | If-Match |
| GET | `/api/v1/quotes/{id}/conversion-preview` | reservations.create | Precondiciones + disponibilidad por ítem (ver 04) | — |
| POST | `/api/v1/quotes/{id}/convert` | reservations.create | Conversión (ver `04-reservas.md §4.3`) | **Idempotency-Key obligatorio**, lock Redis + FOR UPDATE |
| POST | `/api/v1/quotes/{id}/reassign` | quotes.reassign (nuevo) | `{owner_id, note}` | If-Match |
| POST | `/api/v1/quotes/{id}/versions/{v}/pdf` | quotes.view | Encola PDF (cola `documents`) | ShouldBeUnique por versión |
| GET | `/api/v1/quotes/{id}/activity` | quotes.view | Auditoría de la cotización | — |

Errores de negocio: `409` con `code` (`QTE-BR-12`, `QTE-BR-16`, `RT-3`…) y mensaje en español igual al toast del prototipo; `422` para validación de campos.

## 7. Permisos y comportamiento por rol

Principio de botones (`pbtn`): si falta el permiso o hay un bloqueo de negocio, el botón se muestra **deshabilitado con candado y tooltip** ("Requiere permiso x.y" o el motivo, p. ej. "Bloqueado: requiere aprobación de gerencia (margen 12.4% < 15%)"); si se pulsa, toast `bad` y no se modifica nada. Márgenes/costos: `seeMargin = finance.profitability ∨ catalog.products.view_profitability ∨ quotes.approve_internal ∨ (Ventas ∧ dueño)`.

| Rol (usuario demo) | Alcance | Crear/editar | Enviar, vista, negociar, rechazar | Aprobación interna | Registrar aprobación cliente / convertir | Ve costo, margen, pestaña Pricing | Otros |
|---|---|---|---|---|---|---|---|
| tenant_admin (U01) | Todo el tenant; filtro "Asesor" (sessionStorage) + aviso "Mostrando solo la cartera de…" | Sí | Sí | Aprueba/rechaza (tiene `discount_override`) | Sí | Sí | Reasignar; alerta de pendientes en la lista |
| manager (U02) | Igual que admin | Sí | Sí | Sí (con `discount_override`) | Sí | Sí | Reasignar; recibe notificaciones de solicitudes |
| sales (U03, U04) | Solo `owner = yo` (lista, KPIs, detalle, búsqueda); ajena → "Registro fuera de su cartera"; columna Vendedor oculta; chip "Mi cartera" | Sí (clientes de su cartera) | Sí | Solo **solicitar**; ve "Solicitar aprobación a gerencia"; descuento máx. 10 % | Sí (tiene `reservations.create`), bloqueado si falta aprobación interna | Sí en sus cotizaciones | No reasigna |
| finance (U07) | Todo | No (aviso "Modo consulta") | No (card "Su rol no envía cotizaciones") | No | No | Sí | Consulta y documento |
| operations, accounting | Sin `quotes.view`: ruta 403 y enlaces a cotización en reservas se muestran como texto | — | — | — | — | — | — |
| customer | Solo portal propio (módulo canales) | — | Abre (vista), aprueba, rechaza | — | Aprueba desde portal | No (sin costos) | — |
| b2b, guide, driver, passenger, provider, super_admin | Sin acceso a `#/cotizaciones` (layout externo o consola de plataforma) | — | — | — | — | — | — |

Acciones visibles en el detalle según estado: `borrador` → Editar ítems, Aprobar internamente (si aplica y puede) o Solicitar aprobación (si aplica y no hay pendiente), Enviar; `enviada/vista/negociacion` no vencida → Registrar negociación, Rechazar, Aprobar (cliente); vencida → Aprobar (deshabilitado "Cotización vencida"), Crear nueva versión; `aprobada` → Convertir en reserva; `convertida` → Ver reserva (si puede abrir reservas). Menú "Más": Vista de impresión, Duplicar y Crear nueva versión (`quotes.create`), Comparar versiones, Registrar apertura del enlace y Rechazar (`quotes.send`), Reasignar asesor (admin/manager).

## 8. Eventos, jobs (colas Redis) y notificaciones

| Evento de dominio | Disparador | Listener / cola | Notificación (evento · canal → destinatario) |
|---|---|---|---|
| `QuoteCreated` | POST quotes | default: actualizar etapa de oportunidad | — |
| `QuoteVersionCreated` | nueva versión / duplicar | default | — |
| `QuoteInternalApprovalRequested` | solicitud | notifications + Reverb bandeja | `cotizacion.aprobacion_solicitada` · Portal → cada manager activo |
| `QuoteInternalApprovalDecided` | aprobar/rechazar | notifications | `cotizacion.aprobacion_aprobada|rechazada` · Portal → dueño (si decide otro) |
| `QuoteSent` | send | notifications (1 job por canal, adapter Email/WhatsApp/Portal), documents (PDF adjunto) | `cotizacion.enviada` · canal elegido → cliente |
| `QuoteViewed` | portal / manual | notifications | `cotizacion.vista` · Portal → dueño |
| `QuoteNegotiationRecorded` | negociación | default: oportunidad → negociación | — |
| `QuoteApproved` | approve (interno o portal) | notifications | `cotizacion.aprobada` · Push → dueño; Email → cliente |
| `QuoteRejected` | reject | notifications | `cotizacion.rechazada` · Portal → dueño |
| `QuoteReassigned` | reassign | notifications | `cotizacion.reasignada` · Portal → nuevo dueño |
| `QuoteExpired` | JOB-02 | low | (propuesto) aviso al dueño |
| `QuoteConverted` | convert | critical | ver 04 |

Jobs: **JOB-02 `quotes:expire`** (scheduler diario 00:05 tz tenant; en el prototipo se ejecuta manualmente desde Integraciones y jobs) → `borrador/enviada/vista/negociacion` con `valid_until < hoy` pasan a `vencida`. **`GenerateQuotePdf`** (cola `documents`, `ShouldBeUnique` por versión, guarda en `sys_documents` con hash). Propuesto: `quotes:expiring-reminder` diario para las que vencen en ≤ 3 días (hoy solo es KPI).

## 9. Auditoría

Entidad `Cotización` (antes → después): `crear`, `descuento_sobre_tope`, `editar_version` (total antes/después), `anular_solicitud_aprobacion`, `solicitar_aprobacion_interna`, `aprobacion_interna` (override + justificación), `rechazar_aprobacion_interna`, `generar_token`, `regenerar_token`, `enviar` (estado, versión, canales), `registrar_vista`, `vista_portal`, `negociacion`, `aprobar` (versión, aprobado por, evidencia), `aprobar_portal` (IP), `rechazar`, `rechazar_portal`, `nueva_version`, `duplicar`, `generar_pdf`, `reasignar`, `convertir`, `convertir_idempotente` (solicitud repetida), `vencer` (job). Entidad `Oportunidad`: `cambio_etapa`. Entidad `Exportación`: `exportar` (CSV de la lista). Cada registro: actor, tenant, IP, correlación, timestamp (PROC-063).

## 10. Casos de prueba mínimos

| # | Caso | Tipo |
|---|---|---|
| T-Q01 | `quoteTotals` reproduce el ejemplo COT-00191 v3 (243 144.90; margen 12.35 %) y PRC-01 (1 806.42 / 1 403.25) | Pest unit |
| T-Q02 | Tenant A no lista ni abre cotizaciones de tenant B (RLS + 404) | Pest feature |
| T-Q03 | Ventas U03 no ve COT de U04 (lista, detalle 403/"fuera de cartera", búsqueda) | Pest feature |
| T-Q04 | Finance recibe 403 en POST send/approve; operations 403 en GET quotes | Pest feature |
| T-Q05 | Producto `SUSPENDED` o `quoteable=false` → 422 `PRD-BR-002`; tarifa fuera de vigencia → 422 `PRD-BR-005` | Pest feature |
| T-Q06 | Descuento 12 % por Ventas → 422 `QTE-BR-08`; por Manager → OK y auditado | Pest feature |
| T-Q07 | Margen < 15 % → send 409 `QTE-BR-12`; solicitud → manager aprueba → send OK | Pest feature |
| T-Q08 | Editar borrador tras aprobación interna → solicitud `anulada` y aprobación eliminada | Pest feature |
| T-Q09 | PUT sobre versión `enviada`/`aprobada` → 409; trigger DB impide UPDATE de ítems congelados | Pest feature |
| T-Q10 | Vencida: approve 409 `QTE-BR-16`; nueva versión → borrador con validez +10 d | Pest feature |
| T-Q11 | Rechazo sin motivo → 422 | Pest feature |
| T-Q12 | Snapshot: cambiar precio del producto después no altera versión enviada (PRD-T06/T10) | Pest feature |
| T-Q13 | Doble POST send con misma Idempotency-Key → un solo registro de envío | Pest feature |
| T-Q14 | `If-Match` desactualizado → 409 | Pest feature |
| T-Q15 | E2E-01: Lead → oportunidad → cotización → aprobación interna → envío → vista (portal) → aprobación | Playwright |
| T-Q16 | UI: Ventas ve "Solicitar aprobación a gerencia" y botón Enviar deshabilitado con tooltip; Finance ve "Modo consulta" sin pestaña de edición | Playwright |

## 11. Cambios y decisiones respecto a la documentación inicial

| # | Tema | Documentación inicial | Prototipo / esta especificación | Tipo | Motivo |
|---|---|---|---|---|---|
| 1 | Aprobación interna (PROC-021) | Paso "Aprobación interna" sin criterios | Obligatoria si descuento > 5 % o margen < 15 %; tope de descuento 10 % solo superable con `quotes.discount_override`; flujo solicitud → decisión con justificación, notificación a gerencia y bloqueo de envío/aprobación | Precisado + agregado | Control de margen habitual en ERP turísticos; evitar ventas bajo costo |
| 2 | Permisos nuevos | No listados para cotizaciones | `quotes.view`, `quotes.create`, `quotes.send`, `quotes.approve_internal`, `quotes.discount_override`; propuesto `quotes.reassign` (hoy se basa en rol admin/manager) | Agregado | Matriz RBAC del prototipo |
| 3 | Quién registra la aprobación del cliente | No definido | Usuario interno con `reservations.create`, o el cliente en el portal | Precisado | Aprobación = compromiso de reservar |
| 4 | Evidencia de aprobación (PROC-024) | "Registrar timestamp → Evidencia" | Lista cerrada de tipos de evidencia + referencia obligatoria + nombre de quien aprueba; portal agrega IP, token y hash | Precisado | Trazabilidad legal |
| 5 | Motivo de rechazo | "cuando corresponda" | Siempre obligatorio (alimenta análisis de pérdidas) | Cambiado | Reportes comerciales |
| 6 | Estados | Borrador → Enviada → Vista → Negociación → Aprobada/Rechazada/Vencida → Convertida/Cancelada | Mismos + estado de versión `reemplazada`; `cancelada` sin acción en el prototipo; `vencida` calculada en tiempo real además del job | Precisado | Versionado explícito |
| 7 | Rechazo de cotización vencida | No definido | Permitido (registrar el motivo de pérdida) | Precisado | Cerrar el embudo |
| 8 | Nueva versión | V1 → V2 → V3 | Permitida en cualquier estado salvo `convertida`; desde `aprobada` invalida la aprobación; el borrador se edita en sitio (no genera versión por cada guardado); renueva validez | Precisado | Evitar versiones basura; excepción "vencida → nueva versión" |
| 9 | Modelo de pricing de la cotización | Costo + adicionales + markup − descuentos + impuestos | Precio unitario por línea desde tarifa × factor temporada (o manual) ; descuento **global %** sobre la venta; IGV sobre neto; margen sobre neto; markup se deriva. Sin comisión ni redondeo 0.10 en la cotización | Cambiado | Así opera el vendedor; ver §12 para unificar con `Pricing.calc` |
| 10 | IGV | "impuestos" | 18 % por defecto, 0 % automático para cliente no domiciliado (exportación de servicios) | Precisado | Normativa peruana |
| 11 | Snapshot (PRD-BR-009) | "snapshot de catálogo" | Prototipo guarda etiqueta `PRD v x` + copia de políticas; backend debe guardar JSON completo (`catalog_snapshot`, `policies_snapshot`, `rate_snapshot`) | Precisado | Reproducibilidad del documento |
| 12 | Envío (PROC-023) | Documento → token → enviar → visualización | Multicanal simultáneo (Email/WhatsApp/Portal), mensaje editable, token regenerable, reenvío registrado | Agregado | Operación real por WhatsApp |
| 13 | Automatización CRM | "CRM conserva contexto" | Crear cotización → oportunidad `cotizacion` (55 %); negociación → `negociacion` (70 %); conversión → `ganada` (100 %) | Agregado | Embudo coherente sin doble digitación |
| 14 | Ownership | No definido | Ventas solo su cartera; Admin/Gerente filtro por asesor y reasignación | Agregado | Referencia CRM (Salesforce/HubSpot) |
| 15 | Duplicar / comparar versiones | No definido | Duplicar cotización; comparar dos versiones con diff por línea y totales | Agregado | Productividad comercial |
| 16 | Datos sensibles | No definido | Costos, margen y pestaña Pricing ocultos a quien no tiene permiso de rentabilidad (salvo Ventas en lo propio) | Agregado | Principio 2 de roles |

## 12. Supuestos y puntos a validar con negocio

1. Umbrales de política (descuento > 5 %, margen < 15 %, tope 10 %, override hasta 50 %) están fijos en código: ¿se parametrizan por tenant/segmento en `sys_settings`?
2. Unificar motor: ¿la línea de cotización debe calcularse con `Pricing.calc` (markup %/fijo, comisión, redondeo 0.10, precio mín./máx.) cuando la tarifa lo defina, o se mantiene precio unitario directo? Hoy el total de la cotización no se redondea a 0.10.
3. Comisión (PROC-021 "comisión") no se calcula en la cotización; ¿aplica para B2B/corporativo?
4. El portal del cliente permite aprobar sin verificar la aprobación interna pendiente (módulo canales). El backend debe bloquearlo (QTE-BR-13): confirmar.
5. Estado `cancelada` de cotización: ¿quién y cuándo? (p. ej. oportunidad perdida). No hay acción en el prototipo.
6. El job de vencimiento incluye `borrador`; la UI solo considera vencidas las enviadas. Confirmar si un borrador puede vencer.
7. Validez al enviar borrador vencido se renueva automáticamente a +10 días: confirmar.
8. Descuento solo global en %; ¿se requiere descuento por línea o monto fijo?
9. No se valida que las fechas de ítems caigan dentro de `travel_from–travel_to`, ni que `pax` de la línea coincida con los pasajeros; ¿validar?
10. Pax CHD/INF no generan líneas automáticas con tarifa de niño; el vendedor las agrega manualmente. ¿Automatizar?
11. `quotes.reassign`: hoy depende del rol (tenant_admin/manager); propuesta: permiso explícito.
12. Evidencia de aprobación registrada por un interno no adjunta archivo; ¿se exige adjunto (correo/OC) en S3?
13. Un solo tipo de cambio manual (`settings.fx`); ¿TC por fecha/fuente SBS automática?
14. Productos `TRASLADO` usan `qty=2` (ida/vuelta) por defecto: confirmar.
