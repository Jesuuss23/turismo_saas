# 16 · Motor de reservas y centro de reglas (v2.1)

Documento para desarrollo. Describe el **BookingEngine** (servicio de dominio único de disponibilidad, reglas de pasajeros, precio, alojamiento, adicionales y pago), el **centro de reglas configurables** por tenant con overrides por producto, y la **configuración comercial por producto** que se edita en el Product 360 (pestaña *Reservas online*). Fuente de verdad del comportamiento: `proto/assets/js/booking-engine.js` (motor) y `proto/assets/js/modules/catalogo.js` (funciones `tabBooking`, `bkAvail`, `bkPax`, `bkAddons`, `bkLodging`, `bkPayment`, `bkPickup`, `bkRules`).

Leyenda de reglas del centro: `R-*` (ids de `DB.settings.bookingRules`). Reglas propias de este módulo: `CAT-AV-*` (disponibilidad), `CAT-PX-*` (pasajeros), `CAT-AD-*` (adicionales), `CAT-LG-*` (alojamiento), `CAT-PY-*` (pago), `CAT-PK-*` (recojo), `CAT-RL-*` (reglas).

---

## 1. Propósito y procesos cubiertos (PROC-xxx)

| Pedido del cliente (BRIEF v2.1) | Cubierto por | Regla que lo gobierna |
|---|---|---|
| 2. Disponibilidad por rangos con días intermedios («disponible 02–06 oct, ocupado 07–09, disponible 10–14») | `catalog_availability_ranges` + `BookingEngine::dayStatus()` | `R-AVAIL-RANGES`, `R-WEEKDAYS`, `R-CUTOFF` |
| 3. Límite de niños por adulto (4–10 años pagan) e infantes que no pagan (< 3 años) | `catalog_pax_rules` + `validatePax()` + `quote()` | `R-CHILD-PER-ADULT`, `R-INFANT-FREE`, `R-MIN-ADULTS`, `R-MAX-PAX` |
| 4. Alojamiento opcional; total de habitaciones y noches; tour por 4 ciudades con hoteles por ciudad | `catalog_accommodation_legs/hotels/room_types` | `R-LODGING`, `R-LODGING-CAPACITY`, `R-SINGLE-SUPP` |
| 6. Adicionales (museos, entradas…) | `catalog_product_addons` | `R-ADDONS` |
| 7. Modalidad de pago, % de adelanto | `catalog_payment_policies` | `R-DEPOSIT`, `R-PAY-LATER`, `R-PAY-FEE` |
| 13. Puntos de partida | `catalog_pickup_points` | `R-PICKUP`, `R-COMMENTS` |
| 14. Toda regla activable/desactivable desde el administrador; se pueden añadir reglas | `sys_booking_rules` (tenant) + `catalog_product_rule_overrides` | Todas |

Procesos: **PROC-017** (tarifas, aquí extendido con reglas de pasajeros y adicionales), **PROC-018** (motor de precios: el BookingEngine es su implementación para canales de venta directa), **PROC-020** (disponibilidad y holds: los rangos alimentan el mismo servicio de disponibilidad; los holds siguen siendo `catalog_availability_holds`). Consumidores: sitio web público (spec 15), widget embebible (spec 08), portal B2B, cotizador (spec 03), creación de reservas (spec 04).

Principio: **el navegador nunca es autoridad** del precio ni de la disponibilidad. El front llama a `/api/v1/public/products/{id}/availability` y `/api/v1/public/quote`; al confirmar, el backend recalcula con `BookingEngine::quote()` dentro de la transacción con lock (§6, §8).

---

## 2. Pantallas

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso (edición) |
|---|---|---|---|
| `#/catalogo/productos/{id}/reservas` | `/catalogo/productos/[code]/reservas` | Resumen comercial (leyenda de rangos, próxima fecha vendible, pasajeros, adicionales, alojamiento, pago, recojo, ajustes de reglas) + sub-sección Disponibilidad: calendario mensual por estado (`BE.month`), selector de pax, detalle de día (`BE.dayStatus`), modo «Cerrar fechas», días de operación, horas de anticipación, CRUD de rangos | Ver: todo rol con `catalog.products.view`. Editar: `manage_availability` (Admin, Manager, Operaciones) |
| `…/reservas/pasajeros` | `…/reservas/pasajeros` | Bandas de edad, niño paga (% o tarifa Niño), infantes, máximos por adulto, mínimo de adultos, nota; simulador `validatePax` + líneas base de `quote` + clasificador de edad | `catalog.products.update` (Admin, Manager) |
| `…/reservas/adicionales` | `…/reservas/adicionales` | CRUD de adicionales con categoría, precio, moneda, por pax/por reserva, aplica a ADT/CHD/INF, stock/nota, vínculo a proveedor/servicio | `catalog.products.manage_rates` (Admin, Manager) |
| `…/reservas/alojamiento` | `…/reservas/alojamiento` | Activar/retirar alojamiento, opcional/incluido, moneda, tramos (ciudad, destino, noches, orden), hoteles por tramo con categoría y tipos de habitación; resumen «4 ciudades · 9 noches» con control vs duración; ejemplo calculado por el motor | `manage_rates` |
| `…/reservas/pago` | `…/reservas/pago` | Modalidades (total, adelanto, pagar después), % de adelanto, días antes del viaje para el saldo, nota; vista previa `quote` por modalidad | `manage_rates` |
| `…/reservas/recojo` | `…/reservas/recojo` | Puntos de partida/recojo (alta, edición, baja), recojo obligatorio, pedir vuelo | `update` **o** `manage_availability` (Operaciones gestiona puntos) |
| `…/reservas/reglas` | `…/reservas/reglas` | Reglas del centro con `productOverride=true`: estado del tenant, switch «Aplicar» por producto, parámetros propios; reglas apagadas en el tenant en gris con enlace a `#/admin/reglas`; reglas solo-tenant listadas al pie | `update` |
| `#/admin/reglas` | `/admin/reglas` | Centro de reglas del tenant (módulo Administración, fuera de este archivo) | `admin.settings.manage` |

Comportamiento común: cada sub-sección consulta su regla; si **todas** sus reglas están apagadas en el tenant muestra «Regla desactivada para este tenant» (+ enlace al centro si el rol puede abrirlo) y la pestaña se rotula «(desactivada)»; si está apagada solo por el producto muestra «Desactivada solo para este producto» con acción *Reactivar* (`update`). La configuración guardada **no se borra** al apagar una regla. La pestaña *Disponibilidad* existente (modelo de disponibilidad, PRD §20) enlaza a esta sub-sección.

---

## 3. Modelo de datos PostgreSQL

Todas las tablas: `id uuid pk` (UUID v7), `tenant_id uuid not null → tenants(id)` con RLS, `created_by`, `updated_by`, `created_at`, `updated_at`. Las tablas por producto llevan `product_id uuid not null → catalog_products(id) on delete cascade` indexada. Dinero `numeric(14,2)` + `currency char(3)`.

### 3.1 `sys_booking_rules` (centro de reglas por tenant)
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| tenant_id | uuid | no | FK tenants |
| rule_key | varchar(40) | no | `R-AVAIL-RANGES`… `UNIQUE (tenant_id, rule_key)` |
| group_name | varchar(40) | no | Disponibilidad, Pasajeros, Alojamiento, Adicionales, Precios, Monedas y pagos, Checkout, Comunicación |
| name | varchar(120) | no | |
| description | text | no | |
| enabled | boolean | no | default según catálogo de plataforma |
| params | jsonb | no | default `'{}'`; validado contra `params_schema` |
| scope | text[] | no | canales donde aplica (Sitio web, Widget, Cotizaciones, B2B, Portal cliente, Operaciones…) |
| product_override | boolean | no | si el producto puede desactivarla o cambiar parámetros |
| is_system | boolean | no | true = regla del catálogo de plataforma (no se borra); false = regla agregada por el tenant |
| params_schema | jsonb | no | JSON Schema de `params` (tipos, mínimos, máximos) |
| sort_order | smallint | no | |

Semilla: las 31 reglas de `data.js` se insertan por tenant al crearlo desde un catálogo de plataforma `platform_booking_rule_templates` (misma estructura sin tenant). «Se pueden añadir otras reglas» = nuevas filas `is_system=false` que el código solo evalúa si existe un *handler* registrado (`RuleRegistry`), ver §12.

### 3.2 `catalog_product_rule_overrides`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| product_id | uuid | no | FK |
| rule_key | varchar(40) | no | FK lógica a `sys_booking_rules(tenant_id, rule_key)`; `UNIQUE (tenant_id, product_id, rule_key)` |
| enabled | boolean | sí | `false` = desactivada para el producto; `null` = usa tenant (el prototipo solo guarda `false`) |
| params | jsonb | no | default `'{}'`; solo claves distintas al tenant (`p.ruleParams[id]`) |

CHECK en servicio: no se puede crear override si `sys_booking_rules.product_override = false` (422 `CAT-RL-01`). `enabled = true` sobre regla apagada en tenant **no** la activa (CAT-RL-02).

### 3.3 `catalog_availability_ranges`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| product_id | uuid | no | |
| code | varchar(20) | no | `RG-1101`; `UNIQUE (tenant_id, code)` |
| date_from | date | no | |
| date_to | date | no | `CHECK (date_to >= date_from)`, `CHECK (date_to - date_from <= 730)` |
| status | varchar(20) | no | `CHECK (status IN ('disponible','cerrado','agotado'))` |
| capacity_per_day | integer | no | `CHECK (status <> 'disponible' OR capacity_per_day BETWEEN 1 AND 500)`; 0 en cerrado/agotado |
| note | varchar(200) | sí | visible en calendario |
| deleted_at | timestamptz | sí | soft delete |

Índices: `(tenant_id, product_id, date_from, date_to)`; GiST `daterange(date_from, date_to, '[]')` para búsquedas de solape. Restricción de solape (CAT-AV-01), dos *exclusion constraints* parciales con `btree_gist`:
```sql
ALTER TABLE catalog_availability_ranges ADD CONSTRAINT ex_avail_open
  EXCLUDE USING gist (product_id WITH =, daterange(date_from, date_to, '[]') WITH &&)
  WHERE (status = 'disponible' AND deleted_at IS NULL);
ALTER TABLE catalog_availability_ranges ADD CONSTRAINT ex_avail_closed
  EXCLUDE USING gist (product_id WITH =, daterange(date_from, date_to, '[]') WITH &&)
  WHERE (status IN ('cerrado','agotado') AND deleted_at IS NULL);
```
Así un rango cerrado puede superponerse a uno disponible (excepción, caso 07–09 oct) pero no dos del mismo tipo.

Configuración de operación en `catalog_products`: `operating_weekdays smallint[] not null default '{0,1,2,3,4,5,6}'` (0 = domingo, `CHECK (cardinality >= 1)`), `cutoff_hours integer null CHECK (cutoff_hours BETWEEN 0 AND 8760)`.

### 3.4 `catalog_pax_rules` (1:1 con producto; si no existe se usan los defaults del tenant `sys_settings.ecommerce.default_pax_rules`)
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| product_id | uuid | no | `UNIQUE (tenant_id, product_id)` |
| adult_min_age | smallint | no | |
| child_min_age / child_max_age | smallint | no | |
| infant_max_age | smallint | no | `CHECK (infant_max_age < child_min_age AND child_min_age <= child_max_age AND child_max_age < adult_min_age)` (CAT-PX-01) |
| child_pays | boolean | no | |
| child_price_pct | numeric(5,2) | no | 0–100; respaldo si no hay tarifa `segment='Niño'` vigente |
| infant_allowed | boolean | no | default true |
| infant_pays | boolean | no | default false |
| max_children_per_adult | smallint | no | 0–10 |
| max_infants_per_adult | smallint | no | 0–5 |
| min_adults | smallint | no | 0–10 |
| note | text | sí | nota al cliente |

### 3.5 `catalog_product_addons`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| product_id | uuid | no | |
| code | varchar(20) | no | `ADD-1101`; `UNIQUE (tenant_id, code)`; `UNIQUE (tenant_id, product_id, lower(name))` |
| name | varchar(150) | no | |
| category | varchar(20) | no | `CHECK IN ('Museo','Entrada','Alimentación','Transporte','Extensión','Seguro','Experiencia','Servicio','Otro')` |
| price | numeric(14,2) | no | `CHECK (price >= 0)` precio de venta |
| currency | char(3) | no | moneda activa del tenant |
| charge_per | varchar(10) | no | `CHECK IN ('pax','reserva')` |
| applies_to | varchar(3)[] | no | subconjunto de `{ADT,CHD,INF}`, `CHECK (cardinality(applies_to) >= 1)` |
| stock_note | varchar(200) | sí | |
| provider_id | uuid | sí | FK `catalog_providers` |
| service_id | uuid | sí | FK `catalog_services`; si hay ambos, `service.provider_id = provider_id` (CAT-AD-02) |
| is_optional | boolean | no | default true |
| deleted_at | timestamptz | sí | |

### 3.6 `catalog_accommodation_legs` / `catalog_accommodation_hotels` / `catalog_accommodation_room_types`
Cabecera en `catalog_products`: `accommodation_enabled boolean not null default false`, `accommodation_optional boolean not null default true`, `accommodation_currency char(3)`, `accommodation_note text`.

`catalog_accommodation_legs`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| product_id | uuid | no | |
| code | varchar(20) | no | `LEG-1`; `UNIQUE (tenant_id, product_id, code)` |
| sequence | smallint | no | orden del itinerario; `UNIQUE (product_id, sequence) DEFERRABLE` |
| city | varchar(80) | no | |
| destination_id | uuid | sí | FK `catalog_destinations` |
| nights | smallint | no | `CHECK (nights BETWEEN 1 AND 30)` |

`catalog_accommodation_hotels`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| leg_id | uuid | no | FK legs on delete cascade |
| code | varchar(20) | no | `H-LIM-3` |
| name | varchar(150) | no | `UNIQUE (leg_id, lower(name))` |
| category | varchar(30) | no | 3/4/5 estrellas, Hostal, Lodge, Boutique |
| provider_id | uuid | sí | FK proveedor tipo Hotel (contrato) |

`catalog_accommodation_room_types`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| hotel_id | uuid | no | FK hotels on delete cascade |
| room_type | varchar(20) | no | Simple, Doble, Matrimonial, Triple, Familiar, Suite; `UNIQUE (hotel_id, room_type)` |
| capacity | smallint | no | `CHECK (capacity BETWEEN 1 AND 8)` |
| price_per_night | numeric(14,2) | no | `CHECK (> 0)`; por habitación y noche, en `accommodation_currency` |

### 3.7 `catalog_payment_policies` (1:1 con producto)
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| product_id | uuid | no | `UNIQUE` |
| modes | varchar(15)[] | no | subconjunto de `{total,adelanto}`, `CHECK (cardinality >= 1)`. `pagar_despues` **no** se guarda aquí: lo gobierna `R-PAY-LATER` + override del producto |
| deposit_pct | numeric(5,2) | sí | 1–99; null = `R-DEPOSIT.params.pct` |
| balance_due_days | smallint | sí | 0–180; null = `R-DEPOSIT.params.balanceDueDays` |
| note | text | sí | |

### 3.8 `catalog_pickup_points`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| product_id | uuid | no | |
| sequence | smallint | no | |
| label | varchar(150) | no | `UNIQUE (product_id, lower(label))` |
| kind | varchar(20) | no | `aeropuerto`, `hotel`, `punto_encuentro`, `terminal`, `por_cuenta` (derivable; el prototipo guarda solo texto) |
| requires_address | boolean | no | true para «Hotel en…» |

Cabecera en `catalog_products`: `pickup_required boolean not null default false`, `pickup_ask_flight boolean not null default false`. CAT-PK-01: `pickup_required` ⇒ ≥ 1 punto.

### 3.9 Snapshots
`booking_reservations.booking jsonb` guarda el contrato de BRIEF v2.1 (líneas de `quote` sin removidas, `adjustments`, `lodging[]`, `addons[]`, `pickup`, `payment`, `rulesApplied[]`, `fxRate`). Los cambios de configuración posteriores **no** alteran reservas (PRD-BR-008/009).

---

## 4. Máquinas de estado

### 4.1 Día de un producto (calculado, no persistido) — `BookingEngine::dayStatus()`
| Estado | Condición (en este orden) |
|---|---|
| `pasado` | fecha < hoy (zona del tenant) |
| `sin_configurar` | sin rangos (o `R-AVAIL-RANGES` apagada con producto sin rangos) y sin salida programada → «Consultar» |
| `disponible` (sin control) | `R-AVAIL-RANGES` apagada y el producto tiene rangos → el motor no restringe (se vende todos los días) |
| `fuera_de_plazo` | `R-CUTOFF` activa y `(fecha − hoy) × 24 < cutoff_hours` |
| `no_opera` | `R-WEEKDAYS` activa y el día de semana no está en `operating_weekdays` |
| `cerrado` | rango efectivo `cerrado`, o hay rangos pero ninguno cubre la fecha y no hay salida («Sin salida programada»; el prototipo lo rotula «Sin rango») |
| `agotado` | rango efectivo `agotado`, o `capacidad − vendidos − holds ≤ 0` |
| `sin_cupo` | quedan cupos pero menos que los pedidos (`seats`) |
| `disponible` | resto; `left ≤ 4` → etiqueta «Últimos N cupos» |

### 4.2 Rango
`activo` (fila viva) → `eliminado` (soft delete). Cambios de estado disponible ↔ cerrado ↔ agotado por edición, con validación de solape y ventas (CAT-AV-01/03/04).

### 4.3 Override de regla por producto
`usa_tenant` ⇄ `desactivada_producto` (switch *Aplicar*); `parametros_propios` ⇄ `usa_tenant` (*Guardar parámetros* / *Restablecer*). Estado efectivo = `BE.on(id, p)`.

### 4.4 Hold / reserva
Sin cambios respecto a PROC-020 (spec 02 §4.5): Disponible → Hold → Confirmada → Liberada/Expirada/Consumida. `R-HOLD.params.minutes` fija la vigencia del hold de checkout; `R-PAY-LATER.params.holdHours` la del «pagar después».

---

## 5. Reglas de negocio

### 5.1 Centro de reglas: qué gobierna cada una en el motor y en el Product 360
| Regla | Efecto exacto en `BookingEngine` | Sección del Product 360 |
|---|---|---|
| R-AVAIL-RANGES | Si está apagada, `dayStatus` ignora `availability` | Disponibilidad (oculta) |
| R-CUTOFF | Horas = `product.cutoff_hours` ‖ `advMin×24` ‖ `params.defaultHours` | Disponibilidad › Operación |
| R-WEEKDAYS | Filtra por día de semana | Disponibilidad › Operación |
| R-HOLD | Minutos de hold del checkout (solo tenant) | — |
| R-MIN-GROUP | No evaluada por el motor (job de operaciones, §8) | Reglas (parámetros) |
| R-MIN-ADULTS | `minAdults = 0` si apagada | Pasajeros |
| R-CHILD-PER-ADULT | Apagada ⇒ sin máximo, `childPricePct=100` | Pasajeros (sección oculta si también R-INFANT-FREE) |
| R-INFANT-FREE | Apagada ⇒ sin máximo, `infantPays=true` | Pasajeros |
| R-MAX-PAX | Máximo = `product.max_pax` | Pasajeros (lectura) |
| R-COMPANIONS / R-ALTITUDE / R-NOMINATIVE | Checkout (spec 15); no afectan precio | Reglas (parámetros) / Recojo (estado) |
| R-LODGING | Apagada ⇒ `sel.lodging` se ignora | Alojamiento |
| R-LODGING-CAPACITY | Advierte si camas < adultos+niños por tramo | Alojamiento (ejemplo) |
| R-SINGLE-SUPP | Recargo `pct` sobre líneas de habitación Simple | Reglas (parámetros) |
| R-ADDONS | Apagada ⇒ `sel.addons` se ignora | Adicionales |
| R-PROMO, R-SEASON, R-MULTICURRENCY, R-PREVIEW-REMOVE, R-PAY-FEE | Solo tenant | — |
| R-GROUP-DISC, R-EARLY-BIRD, R-LAST-MINUTE | Ajustes sobre subtotal | Reglas (parámetros) |
| R-DEPOSIT | Apagada ⇒ se quita `adelanto` de `modes` | Pago |
| R-PAY-LATER | Encendida ⇒ se **agrega** `pagar_despues` a `modes` | Pago (checkbox = override de la regla) |
| R-PICKUP | Checkout pide punto/vuelo | Recojo |
| R-COMMENTS, R-PAX-MESSAGES, R-PAX-FEEDBACK, R-REVIEW-INVITE | Otros módulos | — |

### 5.2 Reglas del módulo
| ID | Regla | Dónde se valida |
|---|---|---|
| CAT-AV-01 | Dos rangos del mismo tipo (disponible/disponible o no-disponible/no-disponible) no pueden solaparse; un rango cerrado/agotado **sí** puede superponerse a uno disponible y prevalece como excepción | FormRequest + `AvailabilityRangeService` + exclusion constraints |
| CAT-AV-02 | Rango disponible: capacidad entera 1–500; cerrado/agotado: capacidad 0 | FormRequest + CHECK |
| CAT-AV-03 | La capacidad por día de un rango disponible no puede quedar por debajo de lo vendido en cualquiera de sus fechas futuras | Service (query a `booking_reservation_services`) |
| CAT-AV-04 | Cerrar fechas con ventas se permite con confirmación explícita; las reservas no se anulan y se notifica a Operaciones | Service + evento `AvailabilityClosedWithSales` |
| CAT-AV-05 | Un rango no puede terminar en el pasado ni durar más de 730 días | FormRequest + CHECK |
| CAT-AV-06 | Al menos un día de operación; anticipación entera 0–8760 h | FormRequest |
| CAT-AV-07 | Una salida programada en la fecha prevalece sobre la capacidad del rango | `BookingEngine::dayStatus` |
| CAT-PX-01 | `infante_hasta < niño_desde ≤ niño_hasta < adulto_desde`; edades sin banda (brecha) generan advertencia porque `ageType` devuelve `NA` y el checkout las rechaza | FormRequest (error) + respuesta con `warnings` |
| CAT-PX-02 | % niño 0–100; máx. niños/adulto ≤ 10, infantes/adulto ≤ 5, mín. adultos ≤ 10 | FormRequest |
| CAT-PX-03 | Si existe tarifa `Niño` vigente para el canal, el motor la usa; el % es respaldo | `BookingEngine::quote` |
| CAT-AD-01 | Nombre único por producto; precio ≥ 0; aplica a ≥ 1 tipo de pasajero; moneda activa | FormRequest |
| CAT-AD-02 | Si se vincula servicio y proveedor, el servicio debe pertenecer al proveedor; si solo servicio, se hereda su proveedor | Service |
| CAT-AD-03 | Eliminar un adicional no altera reservas (snapshot) | Soft delete |
| CAT-LG-01 | Tramos con 1–30 noches; hoteles con ≥ 1 tipo de habitación; capacidad 1–8; precio > 0; hotel único por tramo | FormRequest |
| CAT-LG-02 | Advertencia si Σ noches ≠ duración (días − 1, o noches) | Resource (`warnings`) |
| CAT-LG-03 | Las camas elegidas por tramo deben alojar adultos + niños (infantes comparten) cuando `R-LODGING-CAPACITY` está activa | `BookingEngine::quote` (warning bloqueante en checkout) |
| CAT-PY-01 | Al menos una modalidad con cobro (`total` o `adelanto`); % adelanto 1–99; días de saldo 0–180 | FormRequest |
| CAT-PY-02 | Si el saldo vence antes de hoy, se cobra de inmediato (warning) | `BookingEngine::quote` |
| CAT-PK-01 | Recojo obligatorio ⇒ al menos un punto; puntos únicos | FormRequest |
| CAT-RL-01 | Solo reglas con `product_override=true` admiten override | Policy + FormRequest |
| CAT-RL-02 | Un producto no puede activar una regla apagada en el tenant | `BookingEngine::on` |
| CAT-RL-03 | Parámetros del override: mismos tipos/rangos que `params_schema`; % ≤ 100; valores iguales al tenant no se guardan | FormRequest |

---

## 6. API REST

### 6.1 Públicas (sitio, widget, CMS externo) — autenticación por *publishable key* del tenant + rate limit Redis
| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| GET | `/api/v1/public/products/{id}/availability?from=YYYY-MM-DD&to=YYYY-MM-DD&seats=N` | pública | Días `[{date, status, label, note, left}]` (máx. 92 días por llamada), `legend` (texto de segmentos), `next_available`, `rules_applied` | Cacheado (§8.3) |
| POST | `/api/v1/public/quote` | pública | Cuerpo = `sel` (`product_id, date, pax{adt,chd,inf}, addons[{id,qty}], lodging[{leg_id, hotel_id, rooms[{type,qty}]}], options[], currency, payment_mode, payment_method, promo_code, removed[], channel`). Respuesta = contrato `quote` (lines, adjustments, subtotal, total, modes, deposit_pct, deposit, balance, balance_due, hold_hours, rooms, nights, warnings, ok, currency, fx_rate) + `quote_token` firmado (TTL 30 min) | Sin estado; `quote_token` = HMAC del resultado |
| POST | `/api/v1/public/bookings` | pública | Crea hold + reserva `pendiente_pago` a partir de `quote_token`; **recalcula** con `BookingEngine::quote` y rechaza si cambia el total (409 `QUOTE_CHANGED`) o la disponibilidad (409 `AVAIL_CHANGED`) | `Idempotency-Key` obligatorio; lock §8.4 |

### 6.2 Internas (back-office)
| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/v1/catalog/products/{id}/booking-config` | `catalog.products.view` | Resumen (lo de la cabecera de la pestaña) |
| GET/POST | `/api/v1/catalog/products/{id}/availability-ranges` | view / `manage_availability` | Listar / crear rango (CAT-AV-01…05); `?confirm_sales=true` requerido si hay ventas (si no, 409 con fechas afectadas) |
| PATCH/DELETE | `/api/v1/catalog/products/{id}/availability-ranges/{rangeId}` | `manage_availability` | Editar / soft delete; `lock_version` |
| POST | `/api/v1/catalog/products/{id}/availability-ranges/close-dates` | `manage_availability` | `{dates[], status: cerrado|agotado, note}` → agrupa fechas contiguas y crea N rangos en una transacción |
| PUT | `/api/v1/catalog/products/{id}/operation` | `manage_availability` | `{operating_weekdays[], cutoff_hours}` |
| GET | `/api/v1/catalog/products/{id}/availability?from&to&seats` | view | Igual que la pública, con vendidos, holds y rango por día |
| GET/PUT | `/api/v1/catalog/products/{id}/pax-rules` | view / `update` | Upsert 1:1; `DELETE` = volver a defaults del tenant |
| POST | `/api/v1/catalog/products/{id}/pax-rules/simulate` | view | `{adt,chd,inf,ages[]}` → `validatePax` + líneas base |
| GET/POST/PATCH/DELETE | `/api/v1/catalog/products/{id}/addons[/{addonId}]` | view / `manage_rates` | CRUD adicionales |
| PUT | `/api/v1/catalog/products/{id}/accommodation` | `manage_rates` | `{enabled, optional, currency, note}` |
| POST/PATCH/DELETE | `/api/v1/catalog/products/{id}/accommodation/legs[/{legId}]` | `manage_rates` | Tramos; `POST …/legs/reorder {ids[]}` |
| POST/PATCH/DELETE | `/api/v1/catalog/products/{id}/accommodation/legs/{legId}/hotels[/{hotelId}]` | `manage_rates` | Hotel + `room_types[]` en el mismo payload (reemplazo completo) |
| GET/PUT | `/api/v1/catalog/products/{id}/payment-policy` | view / `manage_rates` | `{modes[], deposit_pct, balance_due_days, note, pay_later_enabled}` (este último escribe el override de `R-PAY-LATER`) |
| GET/PUT | `/api/v1/catalog/products/{id}/pickup` | view / `update` ∨ `manage_availability` | `{required, ask_flight, points[]}` |
| GET | `/api/v1/catalog/products/{id}/rule-overrides` | view | Reglas con estado tenant, override y efectivo |
| PUT/DELETE | `/api/v1/catalog/products/{id}/rule-overrides/{ruleKey}` | `update` | `{enabled?, params?}` / restablecer |
| GET/PATCH | `/api/v1/admin/booking-rules[/{ruleKey}]` | `admin.settings.manage` | Centro de reglas (spec 09) |

Errores de negocio: `422 {code:'CAT-AV-01', message, conflicts:[…]}`; `409` para ventas afectadas o conflicto de `lock_version`.

---

## 7. Permisos y comportamiento por rol

| Rol (usuario demo) | Ve | Edita |
|---|---|---|
| Tenant Admin (U01) | Todo | Todas las sub-secciones; enlace a `#/admin/reglas` |
| Manager (U02) | Todo | Disponibilidad, Pasajeros, Adicionales, Alojamiento, Pago, Recojo, Reglas del producto (no el centro del tenant: sin `admin.settings.manage`, ve el texto «La administra el Administrador del tenant») |
| Operaciones (U05) | Todo (precios de venta; enlaces a proveedores) | **Disponibilidad** (rangos, cierre rápido, operación) y **Recojo**; resto en modo consulta |
| Ventas (U03) | Todo en solo lectura (precios de venta, simulador de pasajeros usable) | Nada: sin botones de edición, inputs deshabilitados, aviso «Modo consulta» |
| Finanzas (U07) | Solo lectura | — |
| Externos (B2B, cliente, pasajero, proveedor) | No acceden al back-office; consumen la configuración vía sitio/widget/portal | — |

Toda acción invocada sin permiso por otra vía se bloquea con toast «Su rol no tiene el permiso …» y no muta datos (`guard()`); en backend, Policy → 403.

---

## 8. Eventos, jobs (colas Redis) y notificaciones

### 8.1 BookingEngine — servicio de dominio Laravel
Namespace `App\Domain\Bookings`:

| Clase | Responsabilidad | Métodos |
|---|---|---|
| `BookingEngine` | Fachada única (sitio, widget, B2B, cotizador, reservas) | `dayStatus(Product $p, CarbonImmutable $date, int $seats = 0): DayStatus`; `month(Product $p, int $y, int $m, int $seats): Collection`; `nextAvailable(Product $p, int $seats, ?CarbonImmutable $from = null): ?CarbonImmutable` (máx. 400 días); `validatePax(Product $p, PaxCount $pax): PaxValidation`; `ageType(Product $p, ?int $age): PaxType`; `quote(Product $p, QuoteSelection $sel): QuoteResult` |
| `RuleResolver` | `on(string $key, ?Product $p): bool`, `param(string $key, string $name, ?Product $p): mixed` con caché por request | |
| `PaxRulesResolver` | `resolve(Product $p): PaxRules` (defaults tenant → `catalog_pax_rules` → efectos de reglas apagadas) | |
| `AvailabilityCalculator` | Rango efectivo, capacidad, vendidos (`booking_reservation_services` no cancelados de reservas no borrador/cancelada) y holds vigentes | `effectiveRange()`, `sold()`, `held()` |
| `PriceCalculator` | Líneas base/adicionales/alojamiento/suplementos, ajustes, pago | |
| `FxConverter` | `convert(Money $m, string $to)` con `rate` del día + `fxMarginPct` (`R-MULTICURRENCY`) | |
| `RuleRegistry` | Mapa `rule_key → handler` para reglas agregadas por el tenant | |

DTOs inmutables: `QuoteSelection`, `QuoteResult`, `QuoteLine{key, kind, label, qty, unit, amount, removable, removed, meta}`, `Adjustment{key,label,amount}`, `DayStatus{status,label,note,left,cap,range,departure}`. Aritmética con `brick/money` (escala 2, `RoundingMode::HALF_UP`, equivalente a `round2`).

**Algoritmo de disponibilidad** (`dayStatus`, idéntico al prototipo):
1. `av = on(R-AVAIL-RANGES) ? product.availability : null`.
2. `date < today` → `pasado`.
3. `dep = salida programada en date`.
4. `!av && !dep` → si el producto tiene rangos y la regla está apagada ⇒ `disponible` sin control (`left=null`); si no ⇒ `sin_configurar`.
5. `cutoffH = on(R-CUTOFF) ? (cutoff_hours ‖ adv_min×24 ‖ param(R-CUTOFF.defaultHours)) : 0`; si `cutoffH` y `diffDays(today,date)×24 < cutoffH` ⇒ `fuera_de_plazo`.
6. `av && on(R-WEEKDAYS) && weekday ∉ operating_weekdays` ⇒ `no_opera`.
7. `hits = rangos con from ≤ date ≤ to`; `range = primero de hits con status ≠ disponible` ‖ `hits[0]` (**precedencia de la excepción**).
8. `range.status = cerrado` ⇒ `cerrado`; `agotado` ⇒ `agotado`.
9. `cap = dep ? dep.capacity : range?.capacity_per_day`; si `null` ⇒ hay rangos ? `cerrado` («Sin salida programada») : `sin_configurar`.
10. `sold = dep ? dep.sold : Σ pax de servicios del producto en date`; `held = Σ qty holds vigentes`; `left = max(0, cap − sold − held)`.
11. `left = 0` ⇒ `agotado`; `seats > left` ⇒ `sin_cupo`; si no ⇒ `disponible` («Últimos N cupos» si `left ≤ 4`).

**Algoritmo de precio y orden de aplicación** (`quote`):
1. `r = PaxRulesResolver`; `validatePax` → errores a `warnings`.
2. Temporada: si `on(R-SEASON)` y hay fecha ⇒ `factor = SeasonResolver::for(date).factor` (si no, 1).
3. Tarifa base: vigente `segment=Adulto` (o `Pasajero`) del canal o `Directo`; sin tarifa ⇒ warning PRD-BR-005.
4. `adtUnit = (adt.price + Σ delta opciones de personalización) × factor`.
5. `chdUnit = childPays ? (tarifa Niño × factor ‖ adtUnit × childPricePct/100) : 0`; `infUnit = infantPays ? chdUnit : 0`.
6. Líneas **base** ADT/CHD/INF (no removibles) en moneda de la tarifa, convertidas a `sel.currency`.
7. Línea **info** de temporada si `factor ≠ 1`.
8. **Adicionales** (si `on(R-ADDONS)`): `price × qty`, removibles si `on(R-PREVIEW-REMOVE)`.
9. **Alojamiento** (si `on(R-LODGING)`): por tramo/hotel/tipo `price × qty × nights`; `rooms` y `nights` totalizan solo tramos no removidos; `R-LODGING-CAPACITY` ⇒ warning si camas < adt+chd.
10. **Suplemento simple** (si `on(R-SINGLE-SUPP)`): `pct` sobre cada línea Simple no removida.
11. `subtotal = Σ líneas no removidas`.
12. Ajustes sobre `subtotal` (en este orden, todos calculados sobre el mismo subtotal, no en cascada): `R-GROUP-DISC` (pagantes = adt + chd si pagan ≥ fromPax), `R-EARLY-BIRD` (días ≥ days), `R-LAST-MINUTE` (días < days, recargo), promo (`R-PROMO`).
13. `total = subtotal + Σ ajustes`; luego `R-PAY-FEE` (tarjeta) sobre ese total.
14. Modalidades: `modes = payment.modes ‖ tenant.paymentModes`; quitar `adelanto` si `!on(R-DEPOSIT)`; agregar `pagar_despues` si `on(R-PAY-LATER)`; vacío ⇒ `['total']`.
15. `pct = adelanto ? (deposit_pct ‖ R-DEPOSIT.pct ‖ depositPctDefault) : pagar_despues ? 0 : 100`; `deposit = total × pct`; `balance = total − deposit`; `balanceDue = date − balance_due_days` (si < hoy ⇒ hoy + warning); `holdHours = R-PAY-LATER.holdHours` si pagar después.
16. `ok = no hay warnings bloqueantes` (mínimos/máximos de pax, infantes no admitidos, sin tarifa).

### 8.2 Eventos y jobs
| Evento | Listener / job | Cola |
|---|---|---|
| `AvailabilityRangeChanged` (crear/editar/eliminar/cerrar fechas/operación) | `InvalidateAvailabilityCache` | `critical` (síncrono tras commit) |
| `AvailabilityClosedWithSales` | `NotifyOperationsAffectedBookings` (email + inbox a Operaciones con reservas afectadas) | `notifications` |
| `BookingRuleChanged` (tenant u override) | `InvalidateRulesCache`, `InvalidateAvailabilityCache` (todo el tenant o el producto) | `critical` |
| `ProductBookingConfigChanged` (pax, adicionales, alojamiento, pago, recojo) | `InvalidateQuoteCache`, `ReindexPublicCatalog` | `default` |
| Scheduler cada 5 min | `ExpireCheckoutHolds` (R-HOLD) y `ReleasePayLaterHolds` (R-PAY-LATER) | `critical` |
| Scheduler diario | `EvaluateMinimumGroup` (R-MIN-GROUP: si no se alcanza `minPax` a `decisionDays`, propone reprogramar) | `default` |

### 8.3 Caché Redis de disponibilidad
- Clave: `avail:{tenant}:{product}:{yyyy-mm}:v{version}` → JSON del mes (`BE.month` con `seats=0`) + `left` por día. TTL 10 min. `seats` se aplica en memoria sobre `left`.
- `version` = contador `avail:ver:{tenant}:{product}` (INCR) → invalidación O(1) sin `KEYS`.
- Invalidan (INCR): rango/operación, override o regla del tenant (`rules:ver:{tenant}` global), creación/cancelación de reserva o servicio, hold creado/liberado/expirado, salida programada modificada, cambio de fecha (medianoche del tenant ⇒ TTL natural).
- Reglas: `rules:{tenant}:v{ver}` (todas las reglas + overrides) TTL 1 h.
- La respuesta pública lleva `ETag` = versión; el widget usa `If-None-Match`.

### 8.4 Locks al confirmar
1. `Cache::lock("avail:{tenant}:{product}:{date}", 10)->block(5)` (varias fechas ⇒ locks en orden ascendente para evitar deadlock).
2. Dentro de `DB::transaction`: `SELECT … FOR UPDATE` de los rangos efectivos (y de la salida si existe); recalcular `dayStatus` y `quote` **sin caché**.
3. Si `left < seats` ⇒ 409 `AVAIL_CHANGED` con alternativas (`nextAvailable`). Si total ≠ `quote_token` ⇒ 409 `QUOTE_CHANGED` con nuevo quote.
4. Insertar hold/reserva, snapshot `booking`, `rulesApplied`, `fx_rate`; INCR versión; commit; liberar lock.
5. Idempotencia por `Idempotency-Key` (spec 13).

### 8.5 Notificaciones del prototipo
`Notify.push('disponibilidad.cerrada_con_ventas', 'Email', 'U05', …)` al cerrar fechas con ventas (rango o cierre rápido).

---

## 9. Auditoría

`Audit.log('Producto', p.id, acción, antes, después)` — visible en la pestaña *Historial* del producto. Backend: `audit_logs` con `entity_type='catalog_product'`, diff JSON.

| Acción | Antes / después registrados |
|---|---|
| `disponibilidad_rangos` | inicialización de la configuración |
| `rango_crear` / `rango_modificar` / `rango_eliminar` | código, fechas, estado, capacidad, nota |
| `cerrar_fechas` | estado, rangos creados, motivo |
| `disponibilidad_operacion` | días de operación y horas de anticipación |
| `reglas_pasajeros` | resumen de bandas, % y máximos (o «hereda tenant») |
| `adicional_crear` / `adicional_modificar` / `adicional_eliminar` | código, nombre, moneda, precio, cobro, aplica a |
| `alojamiento_activar` / `alojamiento_config` / `alojamiento_desactivar` | opcional/incluido, moneda, resumen «N ciudades · N noches» |
| `tramo_crear` / `tramo_modificar` / `tramo_eliminar` / `tramo_reordenar` | ciudad, noches, orden |
| `hotel_crear` / `hotel_modificar` / `hotel_eliminar` | hotel y tipos con capacidad y precio |
| `politica_pago` | modos, %, días de saldo, estado de pagar después |
| `recojo` | obligatorio, vuelo, puntos |
| `regla_producto` / `regla_parametros` / `regla_restablecer` | regla, estado efectivo antes/después, parámetros JSON |

---

## 10. Casos de prueba mínimos

### 10.1 Pest — caso del cliente (PRD-011)
```php
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-29 09:00', 'America/Lima');
    $this->p = Product::factory()->for($this->tenant)->create(['code' => 'PER-CIR-10D', 'cutoff_hours' => 48]);
    AvailabilityRange::factory()->for($this->p)->createMany([
        ['code' => 'RG-1101', 'date_from' => '2026-10-02', 'date_to' => '2026-10-06', 'status' => 'disponible', 'capacity_per_day' => 12],
        ['code' => 'RG-1102', 'date_from' => '2026-10-07', 'date_to' => '2026-10-09', 'status' => 'cerrado',    'capacity_per_day' => 0],
        ['code' => 'RG-1103', 'date_from' => '2026-10-10', 'date_to' => '2026-10-14', 'status' => 'disponible', 'capacity_per_day' => 12],
    ]);
});

it('devuelve disponible 02–06, cerrado 07–09 y disponible 10–14 oct', function () {
    $engine = app(BookingEngine::class);
    foreach (['2026-10-02','2026-10-06','2026-10-10','2026-10-14'] as $d) expect($engine->dayStatus($this->p, CarbonImmutable::parse($d))->status)->toBe('disponible');
    foreach (['2026-10-07','2026-10-08','2026-10-09'] as $d) expect($engine->dayStatus($this->p, CarbonImmutable::parse($d))->status)->toBe('cerrado');
    expect($engine->dayStatus($this->p, CarbonImmutable::parse('2026-10-15'))->status)->toBe('cerrado'); // sin rango
});

it('genera la leyenda textual del cliente', function () {
    $this->getJson("/api/v1/public/products/{$this->p->id}/availability?from=2026-10-01&to=2026-10-31&seats=2")
        ->assertOk()->assertJsonPath('legend', 'Disponible del 02 al 06 oct (12 cupos/día) · Ocupado del 07 al 09 oct · Disponible del 10 al 14 oct (12 cupos/día)');
});

it('permite un rango cerrado sobre uno disponible (excepción) y rechaza dos disponibles solapados', function () {
    actingAsRole('operations');
    $this->postJson(route('products.availability-ranges.store', $this->p), ['date_from' => '2026-10-12', 'date_to' => '2026-10-12', 'status' => 'cerrado', 'note' => 'Feriado'])->assertCreated();
    $this->postJson(route('products.availability-ranges.store', $this->p), ['date_from' => '2026-10-05', 'date_to' => '2026-10-11', 'status' => 'disponible', 'capacity_per_day' => 10])
        ->assertStatus(422)->assertJsonPath('code', 'CAT-AV-01');
    $this->postJson(route('products.availability-ranges.store', $this->p), ['date_from' => '2026-10-08', 'date_to' => '2026-10-10', 'status' => 'cerrado'])
        ->assertStatus(422)->assertJsonPath('code', 'CAT-AV-01');
});

it('respeta la anticipación, los días de operación y los cupos', function () {
    $this->p->update(['cutoff_hours' => 96]);
    expect(app(BookingEngine::class)->dayStatus($this->p, CarbonImmutable::parse('2026-10-02'))->status)->toBe('fuera_de_plazo');
    $this->p->update(['cutoff_hours' => 48, 'operating_weekdays' => [1,2,3,4,5]]);
    expect(app(BookingEngine::class)->dayStatus($this->p, CarbonImmutable::parse('2026-10-03'))->status)->toBe('no_opera'); // sábado
    ReservationService::factory()->for($this->p)->create(['service_date' => '2026-10-13', 'pax' => 11]);
    expect(app(BookingEngine::class)->dayStatus($this->p->fresh(), CarbonImmutable::parse('2026-10-13'), 2)->status)->toBe('sin_cupo');
});

it('bloquea la sobreventa concurrente al confirmar', function () {
    // dos confirmaciones paralelas de 7 pax sobre 12 cupos el 03 oct: una 201, la otra 409 AVAIL_CHANGED
});
```

### 10.2 Pest — pasajeros, precio, pago y reglas
| Caso | Esperado |
|---|---|
| PRD-011: 2 ADT + 1 CHD (5 años) + 1 INF (2 años) | válido; líneas ADT 2 × 1 490, CHD 1 × 1 090 (tarifa Niño prevalece sobre 73 %), INF 0 |
| 1 ADT + 3 CHD + 2 INF | 2 errores (máx. 2 niños y 1 infante por adulto) |
| `R-CHILD-PER-ADULT` apagada en tenant | sin error de máximo; niño 100 % (o tarifa Niño) |
| Override `R-ADDONS=false` en PRD-011 | `quote` ignora `addons`; GET config marca la sección desactivada |
| Override sobre regla con `product_override=false` | 422 `CAT-RL-01` |
| Override `enabled=true` sobre regla apagada en tenant | sigue apagada (`on=false`) |
| Pax rules con `infant_max_age=4, child_min_age=4` | 422 `CAT-PX-01` |
| Alojamiento PRD-011 con doble más económica en 4 tramos, 2 ADT | `rooms=4`, `nights=9`, total US$ 585 |
| 3 ADT en 1 doble | warning `R-LODGING-CAPACITY` |
| Pago adelanto 30 % salida 2026-10-10, saldo 30 días | `balanceDue = hoy` + warning |
| `R-DEPOSIT` apagada | `modes` sin `adelanto` |
| Aislamiento tenant | tenant B no ve rangos/overrides de A (404) |
| RBAC | Ventas 403 en cualquier escritura; Operaciones 403 en pax/adicionales/alojamiento/pago/reglas y 2xx en rangos/recojo |
| Idempotencia | reintento de `POST /public/bookings` con la misma `Idempotency-Key` devuelve la misma reserva |
| Caché | tras crear un rango cerrado, `GET availability` refleja el cambio sin esperar TTL |

### 10.3 Playwright (sobre el prototipo, script usado en la verificación)
1. U01 · `#/catalogo/productos/PRD-011/reservas`: leyenda «Disponible del 02 al 06 oct (12/día) · Ocupado del 07 al 09 oct · Disponible del 10 al 14 oct…».
2. Nuevo rango disponible 05–11 oct → toast CAT-AV-01; cerrado 12 oct → «Rango RG-1107 creado»; cerrado 08–10 oct → CAT-AV-01.
3. *Cerrar fechas* 20, 21 y 23 oct → «3 fecha(s) cerradas en 2 rango(s)»; leyenda actualizada.
4. Pasajeros: simulador 1/3/2 → dos errores; niño desde 2 → CAT-PX-01; guardar válido → auditado.
5. Adicionales, hotel nuevo en Lima, pago 40 %, override R-ADDONS → sección «Desactivada solo para este producto» → *Reactivar*.
6. `R-LODGING` apagada en el tenant → «Regla desactivada para este tenant».
7. U05: solo *Cerrar fechas*, *Guardar operación*, *Nuevo rango* y *Guardar recojo*. U02: todas menos el centro de reglas. U03: ningún botón de edición. 400 px sin scroll horizontal; sin errores de consola.

---

## 11. Cambios y decisiones respecto a la documentación inicial

**Módulo nuevo (v2.1):** no estaba en la documentación inicial (Spec PRD ni DOC-PROC-001). Motivo: pedido del cliente de disponibilidad por rangos, reglas de niños/infantes, alojamiento opcional por ciudad, adicionales, modalidades de pago con adelanto y, sobre todo, que **toda regla sea activable/desactivable por tenant** para un SaaS multi-cliente.

| Tema | Decisión | Motivo |
|---|---|---|
| Motor único | `BookingEngine` reemplaza a los cálculos sueltos del e-commerce y del simulador de catálogo para canales de venta; el `PricingEngine` de spec 02 queda para la cadena costo→precio del catálogo | Una sola fuente de verdad para sitio, widget, B2B y cotizador |
| Disponibilidad | Rangos por producto conviven con el modelo PRD §20 y con salidas programadas; la salida prevalece si existe en la fecha | No romper POR_SALIDA (PRD-001) |
| Excepciones | Solape permitido solo cerrado/agotado sobre disponible | Caso literal 02–06 / 07–09 / 10–14 oct sin partir rangos |
| Días sin rango | Se tratan como «cerrado / sin salida programada» si el producto tiene rangos | Evita vender fechas no configuradas |
| Pax | Tarifa Niño vigente prevalece sobre el % | Coherencia con PRD §16 (segmentos de tarifa) |
| Pagar después | No es un campo del producto: lo decide `R-PAY-LATER` + override | El motor lo agrega automáticamente cuando la regla está activa |
| Permisos | Disponibilidad y recojo con `manage_availability` (Operaciones); precios (adicionales, alojamiento, pago) con `manage_rates`; pasajeros y reglas con `update` | Separación Operaciones vs Comercial de BRIEF_ROLES |
| Leyenda | Segmenta por estado **y** capacidad, desde hoy | «10–14 oct (12/día)» no se funde con «15–31 oct (8/día)» |

---

## 12. Supuestos y puntos a validar con negocio

| # | Punto | Prototipo | Pregunta / propuesta |
|---|---|---|---|
| 1 | Reglas nuevas agregadas por el tenant | El centro las lista; el motor solo evalúa las 31 conocidas | ¿Catálogo cerrado de *handlers* (recomendado) o motor de expresiones? |
| 2 | Parámetros de `R-CHILD-PER-ADULT`/`R-INFANT-FREE` en el centro | Se muestran, pero manda `catalog_pax_rules` / defaults del tenant | Unificar: usar los params de la regla como default del tenant |
| 3 | `R-DEPOSIT.param` sin producto | El motor lee `pct`/`balanceDueDays` del tenant (no del override) | Confirmar que el override de pago va solo en `catalog_payment_policies` |
| 4 | Precio de adicional «por reserva» | Se guarda; el motor multiplica por la `qty` que envía el checkout | El checkout debe enviar `qty=1` para «por reserva» y `qty=pax aplicables` para «por pasajero» |
| 5 | Alojamiento: niños en cama | Infantes comparten; niños ocupan cama | ¿Niños < N años comparten cama? |
| 6 | Tramos repetidos (vuelta a la misma ciudad) | Advertencia, se permite | Confirmar |
| 7 | Precio de habitación sin costo de proveedor | Solo precio de venta | Agregar `cost_per_night` visible con `view_profitability` |
| 8 | Inventario de habitaciones | No se controla (solo precio) | ¿Cupos por hotel/noche (allotment spec 11)? |
| 9 | Cierre de fechas con ventas | Se permite con confirmación y aviso a Operaciones | ¿Exigir reprogramación previa o aprobación de gerencia? |
| 10 | Capacidad 500/día y 2 años por rango | Límites fijos | ¿Parámetros por tenant? |
| 11 | Cutoff en horas vs `advMin` de la ficha | Prevalece `cutoff_hours`; si falta, `advMin×24` | Unificar en un solo campo |
| 12 | Ajustes no acumulativos | Todos sobre el subtotal | Confirmar orden y si descuentos se acumulan (grupo + anticipada + promo) |
| 13 | Recargo por tarjeta (`R-PAY-FEE`) | Solo tenant | Validar normativa (Indecopi) y contrato con pasarela |
