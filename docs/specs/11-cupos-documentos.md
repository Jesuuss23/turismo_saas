# Cupos y bloqueos (allotments) · Documentos de viaje del pasajero

> Fuente: `proto/assets/js/modules/inventario.js` (rutas `#/catalogo/cupos*` y `#/operaciones/documentos-viaje*`), datos semilla `DB.allotments`, `DB.travelDocs`, `DB.travelDocsCfg` y jobs `JOB-10`/`JOB-11` creados por el módulo; permisos `catalog.allotments.manage` y `operations.travel_docs` en `data.js`; `ACCESS` en `core.js`.
> Transversal (tenant, RLS, Sanctum, spatie/permission, auditoría, Notify, idempotencia): `00-transversal-seguridad-rbac.md`. Tablas referenciadas: `catalog_providers`, `catalog_services`, `catalog_products` (02), `booking_reservations`, `booking_passengers`, `booking_services` (04), `ops_vouchers` (05), `fin_payables` (07), `sys_scheduled_jobs` (09).

---

## 1. Propósito y procesos cubiertos

| Proceso / regla | Qué cubre |
|---|---|
| PROC-020 Disponibilidad | Inventario **contratado** con proveedores (cupos garantizados por fecha), distinto de las salidas propias (`catalog_departures`) y de los holds de reserva |
| PROC-012 Proveedores / PROC-032 Solicitud a proveedor | Solicitud de ampliación de cupo y notificación de liberaciones al proveedor |
| PROC-045 Costos / PROC-044 CxP | Penalidad por no-uso → cargo que genera cuenta por pagar al proveedor |
| PROC-031 Orden operativa (pasajeros / requerimientos) | Checklist documental por pasajero antes del viaje |
| PROC-035 Voucher / Regla transversal 11 | Bloqueo de emisión de entradas nominativas (MAPI) si falta documento validado |
| PROC-059 Notificaciones | Release, ampliación, recordatorios y observaciones de documentos |
| PROC-060 Reportes | Pick-up report (ritmo de ventas vs cupo) |
| Reglas transversales 4, 10, 15, 16, 17 | No confirmar sin cupo, auditoría, moneda conservada, backend valida permisos |

---

## 2. Pantallas

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/catalogo/cupos` (pestaña Grilla) | `/catalogo/cupos` | KPIs (contratos activos, utilización 30 d, release ≤ 3 d, exposición a penalidad, penalidades devengadas en S/), alertas de release vencido y sobreventa, grilla 14 días fecha × contrato (disponible, usado/cupo vigente, liberado; colores por estado), navegación ±7 días, filtro tipo, "incluir liberados y vencidos"; clic en celda → drawer | Admin, Manager, Operaciones (gestión); Finanzas (lectura) |
| Drawer de celda | componente | Bloqueado, liberado, usado, en trámite, disponible, utilización, fecha de release, retención, cargo; reservas que consumen (enlace a reserva); acciones Liberar N / Retener (motivo) / Quitar retención | Idem (acciones solo con `catalog.allotments.manage`) |
| `#/catalogo/cupos/contratos` | `/catalogo/cupos?tab=contratos` | Tabla de contratos: vigencia, bloqueo base, release, tarifa neta, penalidad %, utilización 30 d, penalidad devengada, estado; exportación CSV | Idem |
| `#/catalogo/cupos/release` | `/catalogo/cupos?tab=release` | Fechas con release vencido sin liberar y release que vence en ≤ 3 días, penalidad si se retiene, retenciones activas, estado del job `JOB-10`; botón "Liberar cupos en release" | Idem |
| `#/catalogo/cupos/pickup` | `/catalogo/cupos?tab=pickup` | Pick-up report 30 días por contrato (cupo vigente, vendido, en trámite, utilización, pick-up 7/30 d, recomendación) y curva semanal vendido acumulado vs cupo vs ritmo objetivo 85 % | Idem |
| `#/catalogo/cupos/<CUP-…>` | `/catalogo/cupos/[code]` | Detalle: KPIs, ciclo de estados, pestañas Calendario (28 días), Condiciones, Consumo, Ampliaciones (aceptar/rechazar respuesta del proveedor), Penalidades, Historial (bitácora + auditoría); acciones Solicitar ampliación, Editar, Liberar contrato | Idem |
| Modal "Nuevo contrato" / "Editar" | componente | Proveedor (Hotel/Tren/Entrada activos), servicio, nombre, referencia, vigencia, bloqueo base, días de release, tarifa neta, moneda, penalidad % | `catalog.allotments.manage` |
| `#/operaciones/documentos-viaje` | `/operaciones/documentos-viaje` | KPIs (reservas próximas, pasajeros pendientes, por validar, observados/vencidos, bloqueos MAPI), alerta de bloqueo; pestañas Por reserva, Por pasajero, Bloqueo entradas MAPI, Reglas y recordatorios; botón "Ejecutar recordatorios" | Operaciones, Manager, Admin (todo); Ventas (solo sus reservas) |
| `#/operaciones/documentos-viaje/<RES-…>` | `/operaciones/documentos-viaje/[reservationCode]` | Checklist por pasajero (ítems aplicables; los no aplicables en una línea con el motivo), estado del bloqueo MAPI, KPIs, contacto del cliente, solicitudes/recordatorios enviados, bitácora; acciones Cargar/Reemplazar, Validar, Observar, Registrar contacto, Declarar atención especial, Solicitar documentos al cliente | Idem; Ventas: consulta + solicitar (solo si es el asesor) |

Portal del cliente/pasajero (fuera de este módulo, a implementar en 08): la solicitud enviada incluye enlace para cargar los archivos; lo cargado entra como `recibido`.

---

## 3. Modelo de datos PostgreSQL

Todas las tablas: `tenant_id uuid not null` FK `tenants(id)` + RLS `tenant_isolation`, `created_by/updated_by uuid`, `created_at/updated_at timestamptz`.

### 3.1 `catalog_allotments` (contrato de cupo)
| columna | tipo | null | notas/FK/índice |
|---|---|---|---|
| id | uuid | no | PK (v7) |
| code | varchar(30) | no | `CUP-2026-001`; `UNIQUE (tenant_id, code)`; `sys_sequences` doc `Cupo` |
| provider_id | uuid | no | FK `catalog_providers(id)`; índice |
| service_id | uuid | no | FK `catalog_services(id)`; índice; el servicio debe pertenecer al proveedor (CUP-BR-002) |
| kind | varchar(20) | no | `CHECK IN ('hotel','tren','entrada')` (derivado del tipo de servicio) |
| name | varchar(150) | no | |
| contract_ref | varchar(80) | sí | adenda/convenio |
| valid_from, valid_to | date | no | `CHECK (valid_to >= valid_from)` |
| base_qty | integer | no | `CHECK (base_qty > 0)`; unidad: hab./noche, asiento/día, cupo/día |
| release_days | smallint | no | `CHECK (release_days BETWEEN 0 AND 120)` |
| net_rate | numeric(14,2) | no | `CHECK (net_rate >= 0)` |
| currency | char(3) | no | PEN/USD |
| penalty_pct | numeric(5,2) | no | `CHECK (penalty_pct BETWEEN 0 AND 100)` sobre tarifa neta de lo retenido y no usado |
| status | varchar(20) | no | `CHECK IN ('activo','en_release','liberado','vencido')`; índice `(tenant_id, status)` |
| owner_id | uuid | no | FK `iam_users` (responsable de Operaciones) |
| notes | text | sí | |
| released_at, release_reason | timestamptz, text | sí | liberación total |
| lock_version | integer | no | default 0 (concurrencia optimista) |
| deleted_at | timestamptz | sí | SoftDeletes (prohibido borrar si tiene consumo o cargos) |

Restricción anti-solape (CUP-BR-003): `EXCLUDE USING gist (tenant_id WITH =, service_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&) WHERE (status IN ('activo','en_release') AND deleted_at IS NULL)` (extensión `btree_gist`).

### 3.2 `catalog_allotment_days` (una fila por contrato × fecha)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| allotment_id | uuid | no | FK `catalog_allotments(id)` ON DELETE CASCADE |
| service_date | date | no | `UNIQUE (allotment_id, service_date)`; índice `(tenant_id, service_date)` |
| blocked_qty | integer | no | base o ampliado; `CHECK (blocked_qty >= 0)` |
| released_qty | integer | no | default 0; `CHECK (released_qty BETWEEN 0 AND blocked_qty)` |
| used_qty | integer | no | default 0; **proyección** mantenida por listener (ver §8), `CHECK (used_qty >= 0)` |
| pending_qty | integer | no | default 0; servicios solicitados/pendientes (no consumen) |
| release_deadline | date | no | generada: `service_date - release_days` (columna calculada en servicio, se recalcula si cambia `release_days`) |
| retained_reason | varchar(200) | sí | si no nulo, el job no libera |
| retained_by, retained_at | uuid, timestamptz | sí | |
| closed_at | timestamptz | sí | fecha pasada procesada por el job (liberada o con cargo) |
| lock_version | integer | no | |

Filas generadas al crear/editar el contrato (vigencia × 1 fila). `available = blocked_qty - released_qty - used_qty` (vista `catalog_allotment_days_v`); sobreventa si `< 0`.

### 3.3 `catalog_allotment_consumptions` (trazabilidad del consumo)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| allotment_day_id | uuid | no | FK; índice |
| booking_service_id | uuid | no | FK `booking_services(id)`; índice |
| reservation_id | uuid | no | FK `booking_reservations(id)` |
| qty | integer | no | `CHECK (qty > 0)` |
| state | varchar(15) | no | `CHECK IN ('consumido','en_tramite','revertido')` |
| `UNIQUE (allotment_day_id, booking_service_id)` | | | idempotencia del listener |

Unidades por servicio (prototipo `svcUnits`): hotel → habitaciones × noches desde `service_date` (en producción desde `booking_services.rooms`/`nights`, no parseando el nombre); tren → pasajeros no INF en la fecha; entrada → pasajeros no INF en la fecha de ingreso (día del itinerario con la visita, p. ej. día 2 en MAPI 2D/1N).

### 3.4 `catalog_allotment_requests` (ampliaciones)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| code | varchar(20) | no | `AMP-0014`; `UNIQUE (tenant_id, code)` |
| allotment_id | uuid | no | FK; índice |
| date_from, date_to | date | no | dentro de la vigencia (CUP-BR-004); `CHECK (date_to >= date_from)` |
| extra_qty | integer | no | `CHECK (extra_qty > 0)` |
| note | text | no | motivo enviado al proveedor |
| status | varchar(15) | no | `CHECK IN ('solicitada','aceptada','rechazada')` |
| response, responded_at, responded_by | text, timestamptz, uuid | sí | respuesta registrada por Operaciones o por el proveedor en la extranet (fase 2) |

### 3.5 `catalog_allotment_charges` (penalidades por no-uso)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| allotment_day_id | uuid | no | FK; `UNIQUE (allotment_day_id)` (un cargo por fecha) |
| qty | integer | no | unidades no usadas y no liberadas |
| amount | numeric(14,2) | no | `qty × net_rate × penalty_pct/100` |
| currency | char(3) | no | del contrato; `fx_rate numeric(12,6)` al registrar |
| reason | varchar(200) | no | |
| payable_id | uuid | sí | FK `fin_payables(id)` generado (monto > 0) |
| created_by | uuid | sí | null = job |

### 3.6 `catalog_allotment_events` (bitácora)
`id, allotment_id FK, occurred_at timestamptz, user_id uuid null (null = sistema), message varchar(300)`; índice `(allotment_id, occurred_at desc)`.

### 3.7 `ops_travel_doc_items` (checklist por pasajero)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| reservation_id | uuid | no | FK `booking_reservations(id)`; índice `(tenant_id, reservation_id)` |
| passenger_id | uuid | no | FK `booking_passengers(id)` ON DELETE CASCADE |
| doc_type | varchar(20) | no | `CHECK IN ('id_doc','visa','insurance','yellow_fever','minor_auth','migration','emergency','special')`; `UNIQUE (passenger_id, doc_type)` |
| status | varchar(15) | no | `CHECK IN ('pendiente','recibido','completo','observado')` (estado **almacenado**; `vencido` y `no_aplica` se calculan, §5) |
| doc_number | varchar(40) | sí | N.º documento, póliza, permiso |
| expiry_date | date | sí | vencimiento / cobertura |
| file_id | uuid | sí | FK `sys_documents(id)`; S3 `tenants/{tenant}/travel-docs/{uuid}.{ext}`, URL firmada, cifrado SSE |
| file_name | varchar(200) | sí | |
| uploaded_by_type | varchar(10) | sí | `CHECK IN ('user','client','agency')` |
| observation | text | sí | motivo de observación |
| validated_by, validated_at | uuid, timestamptz | sí | solo Operaciones |
| note | varchar(300) | sí | |
| lock_version | integer | no | |

Filas creadas por listener `ReservationConfirmed`/`PassengerUpdated` para los 8 tipos (aplicabilidad calculada al leer).

### 3.8 `ops_travel_doc_contacts` y `ops_travel_doc_special`
- `ops_travel_doc_contacts`: `passenger_id` (UNIQUE), `name varchar(150)`, `phone varchar(30)`, `relation varchar(20) CHECK IN ('Familiar','Pareja','Amigo(a)','Empresa','Otro')`.
- `ops_travel_doc_special`: `passenger_id` (UNIQUE), `requires_attention boolean not null`, `operational_note varchar(300)`. **Sin columnas clínicas**; validación de términos clínicos en FormRequest (DOC-BR-009). Tabla con acceso restringido (policy: `operations.travel_docs` o asesor dueño, lectura).

### 3.9 `ops_travel_doc_reminders`
`id, reservation_id FK, sent_at timestamptz, channels varchar(40), recipient varchar(190), trigger varchar(20) CHECK IN ('auto','manual'), days_before smallint null, items_count smallint, sent_by uuid null, notification_ids uuid[]`; índice `(reservation_id, sent_at desc)`. Idempotencia del job: `UNIQUE (reservation_id, (sent_at::date)) WHERE trigger='auto'`.

### 3.10 Configuración (`sys_settings`, clave `travel_docs`)
`jsonb`: `{ reminder_days:[15,7,3], channels:['Email','WhatsApp'], passport_months:6, visa_nationalities:['China','India','Vietnamita'], insurance_trekking:true }`.

---

## 4. Máquinas de estado

### 4.1 Contrato de cupo (`catalog_allotments.status`)
| De → A | Disparador | Efecto |
|---|---|---|
| (nuevo) → activo | Operaciones "Registrar contrato" | Genera `catalog_allotment_days` |
| activo → en_release | Automático (servicio `AllotmentStatusResolver`, tras cada cambio o job): existe ≥ 1 fecha futura con `release_deadline <= hoy`, disponible > 0 y sin retención | Alerta en tablero |
| en_release → activo | Job `ReleaseAllotments` o liberación manual resuelve las fechas pendientes | Notifica proveedor |
| activo/en_release → liberado | Operaciones "Liberar contrato" (motivo obligatorio) | Libera todo lo disponible futuro, borra retenciones, deja de aceptar consumo nuevo; notifica proveedor |
| cualquiera → vencido | Job al superar `valid_to` | Cierre; las penalidades pendientes se calculan |
| vencido → activo | Edición que extiende `valid_to` a futuro | Regenera días |

### 4.2 Fecha de cupo (derivado, usado por la grilla)
`holgado` · `≥50 % usado` · `completo` · `release próximo (≤3 d)` o `release vencido` · `retenido` (tras release, con disponible) · `sobreventa` · `liberado` (cupo vigente 0) · `pasada`.

### 4.3 Ampliación
`solicitada → aceptada` (suma `extra_qty` a `blocked_qty` de cada fecha) · `solicitada → rechazada`. Ambos con respuesta obligatoria.

### 4.4 Ítem documental
| De → A | Quién | Efecto |
|---|---|---|
| pendiente/observado/completo → recibido | Operaciones (carga) o cliente/agencia desde portal | Guarda archivo, limpia validación |
| recibido/pendiente/observado → completo | Operaciones "Validar" | Requiere archivo; `id_doc` requiere vencimiento y cumplir DOC-BR-001/002; puede habilitar MAPI |
| cualquiera (≠ observado) → observado | Operaciones "Observar" (motivo) | Notifica al cliente |
| **vencido** (calculado) | — | `id_doc` con `expiry_date` < mínimo exigido |
| **no_aplica** (calculado) | — | Regla de aplicabilidad no se cumple |
Estado por pasajero y por reserva = peor estado de sus ítems aplicables: vencido > observado > pendiente (incluye recibido) > completo.

---

## 5. Reglas de negocio

| ID | Regla | Dónde |
|---|---|---|
| CUP-BR-001 | `valid_to >= valid_from` | FormRequest + CHECK |
| CUP-BR-002 | El servicio del contrato pertenece al proveedor; tipo Alojamiento/Tren/Entrada | FormRequest |
| CUP-BR-003 | Sin dos contratos vigentes solapados para el mismo servicio | EXCLUDE constraint + Service |
| CUP-BR-004 | Ampliación futura y dentro de la vigencia | FormRequest |
| CUP-BR-005 | Solo se libera lo disponible (no lo consumido): `released_qty <= blocked_qty - used_qty` | Service + CHECK + lock Redis |
| CUP-BR-006 | Confirmar un servicio que excede el disponible del cupo → sobreventa marcada y servicio queda *on request* (o 409 si el tenant configura bloqueo estricto) | Service (`AllotmentConsumer`) + Regla transversal 4 |
| CUP-BR-007 | Retener después del release expone a penalidad `disponible × tarifa neta × penalidad %`; la UI muestra el monto antes de confirmar | Service |
| CUP-BR-008 | Consume solo el servicio **confirmado/completado/en curso** de reserva no cancelada ni borrador; solicitado/pendiente = "en trámite" | Listener |
| CUP-BR-009 | Job diario: libera fechas con release ≤ hoy no retenidas; registra cargo por fechas pasadas con disponible > 0 no liberado; vence contratos | Job `ReleaseAllotments` |
| CUP-BR-010 | Cargo > 0 genera `fin_payables` al proveedor (moneda y TC conservados) | Listener `AllotmentChargeRecorded` |
| DOC-BR-001 | Extranjero: pasaporte con vencimiento ≥ fin de viaje + `passport_months` (6) | Service + validación al validar |
| DOC-BR-002 | Peruano: DNI/pasaporte vigente al fin del viaje | Idem |
| DOC-BR-003 | Visa aplica si la nacionalidad está en `visa_nationalities` | Service (aplicabilidad) |
| DOC-BR-004 | Seguro obligatorio para extranjeros y para programas de categoría Aventura (trekking/altura) si `insurance_trekking` | Idem |
| DOC-BR-005 | Vacuna fiebre amarilla si algún producto está en Amazonía (Loreto/Madre de Dios) o su requisito la menciona | Idem |
| DOC-BR-006 | Menor (CHD/INF o < 18 al inicio): autorización; permiso notarial si no viaja con ambos padres, o acreditación de filiación; validar requiere archivo | Service |
| DOC-BR-007 | Extranjero: registro migratorio (TAM virtual) | Idem |
| DOC-BR-008 | Contacto de emergencia (nombre y teléfono) obligatorio | FormRequest |
| DOC-BR-009 | Atención especial: solo indicador + nota operativa; rechazo de términos clínicos (lista configurable) | FormRequest |
| DOC-BR-010 | **Bloqueo operativo**: no se emiten entradas nominativas MAPI (servicio con proveedor Ministerio de Cultura o producto no-paquete que incluye la entrada) ni su voucher si algún pasajero no tiene `id_doc` = completo y, si es menor, `minor_auth` = completo | Service de emisión de vouchers (05) consulta `TravelDocsGate::assertCanIssue()` → 422 `DOC-BR-010` |
| DOC-BR-011 | Solo `operations.travel_docs` carga, valida, observa y edita | Policy |
| DOC-BR-012 | Ventas solo consulta y solicita documentos de reservas propias (`owner_id = me`) | Policy + filtro servidor |
| DOC-BR-013 | Archivos PDF/JPG/PNG ≤ 10 MB, antivirus, URL firmada 10 min | FormRequest + job |

---

## 6. API REST

| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/api/v1/catalog/allotments` | `catalog.allotments.manage` \| `catalog.products.view_profitability` | Lista (`filter[status]`, `filter[kind]`, `include=provider`) con utilización 30 d | — |
| POST | `/api/v1/catalog/allotments` | `catalog.allotments.manage` | Crea contrato y días | Idempotency-Key |
| GET | `/api/v1/catalog/allotments/{id}` | lectura | Detalle + KPIs + penalidad devengada/exposición | — |
| PATCH | `/api/v1/catalog/allotments/{id}` | manage | Edita condiciones (regenera días) | `lock_version` |
| POST | `/api/v1/catalog/allotments/{id}/release` | manage | Libera todo el contrato (`reason`) | Idempotency-Key, lock |
| GET | `/api/v1/catalog/allotments/grid?from=&days=14&kind=` | lectura | Matriz fecha × contrato (blocked, released, used, pending, available, state, deadline) | — |
| GET | `/api/v1/catalog/allotments/{id}/days/{date}` | lectura | Detalle de celda con consumos | — |
| POST | `/api/v1/catalog/allotments/{id}/days/{date}/release` | manage | Libera `qty` (CUP-BR-005) | `Cache::lock("allot:{id}:{date}")`, lock_version |
| PUT / DELETE | `/api/v1/catalog/allotments/{id}/days/{date}/retention` | manage | Retiene (`reason`) / quita retención | lock |
| GET/POST | `/api/v1/catalog/allotments/{id}/requests` | lectura / manage | Ampliaciones | Idempotency-Key |
| POST | `/api/v1/catalog/allotments/{id}/requests/{rid}/respond` | manage | `{accepted, response}` | lock |
| GET | `/api/v1/catalog/allotments/{id}/charges` | lectura | Cargos por no-uso | — |
| GET | `/api/v1/catalog/allotments/pickup?days=30&weeks=8` | lectura | Pick-up por contrato y serie semanal | — |
| POST | `/api/v1/catalog/allotments/jobs/release` | manage | Ejecuta job ahora (encola) | ShouldBeUnique |
| GET | `/api/v1/ops/travel-docs?filter[status]=&filter[period]=upcoming&filter[mapi]=blocked` | `operations.travel_docs` \| `reservations.create` (owner=me forzado) | Resumen por reserva | — |
| GET | `/api/v1/ops/travel-docs/passengers` | idem | Vista por pasajero | — |
| GET | `/api/v1/reservations/{id}/travel-docs` | idem + Policy ownership | Checklist completo + gate MAPI | — |
| POST | `/api/v1/reservations/{id}/travel-docs/{passengerId}/{type}/files` | `operations.travel_docs` (portal cliente: token de portal) | Carga archivo → `recibido` | Idempotency-Key |
| POST | `…/{type}/validate` | `operations.travel_docs` | → `completo` (DOC-BR-001/002/006) | lock_version |
| POST | `…/{type}/observe` | `operations.travel_docs` | → `observado` (`reason`) | lock_version |
| PUT | `/api/v1/reservations/{id}/travel-docs/{passengerId}/emergency-contact` | `operations.travel_docs` | Contacto | — |
| PUT | `…/{passengerId}/special-attention` | `operations.travel_docs` | Indicador + nota (DOC-BR-009) | — |
| POST | `/api/v1/reservations/{id}/travel-docs/requests` | `operations.travel_docs` \| asesor dueño | Solicitud al cliente (Notify) | Idempotency-Key |
| GET | `/api/v1/reservations/{id}/travel-docs/gate` | `operations.view` | `{blocked, missing[]}` para emisión MAPI | — |
| GET/PUT | `/api/v1/settings/travel-docs` | lectura / `operations.travel_docs` | Reglas y recordatorios | — |
| POST | `/api/v1/ops/travel-docs/jobs/reminders` | `operations.travel_docs` | Ejecuta recordatorios | ShouldBeUnique |

---

## 7. Permisos y comportamiento por rol

| Rol | Cupos (`catalogo/cupos`) | Documentos de viaje |
|---|---|---|
| Tenant Admin (U01) | Todo | Todo |
| Manager (U02) | Todo (`catalog.allotments.manage`) | Todo (`operations.travel_docs`) |
| Operaciones (U05/U06) | Todo; ve tarifas netas y penalidades (costos de proveedor), no márgenes | Todo: cargar, validar, observar, contactos, atención especial, recordatorios, reglas |
| Finanzas (U07) | **Solo lectura** (`catalog.products.view_profitability`): grilla, contratos, penalidades devengadas y exposición; botones deshabilitados con "Requiere permiso catalog.allotments.manage" | Sin acceso (403) |
| Ventas (U03/U04) | Sin acceso (403) | `reservations.create`: solo reservas propias (`Scope`), tablero y detalle en lectura, **Solicitar documentos al cliente**; detalle ajeno → "Registro fuera de su cartera"; acciones de ítem deshabilitadas |
| Contabilidad | Sin acceso | Sin acceso |
| Externos | Sin acceso (portales: carga de documentos vía portal cliente, fase 08) | Proveedor: ve ampliaciones/liberaciones en extranet (fase 2) |

Datos probados en el prototipo: U03 ve 3 reservas (Keller, Martin, Johnson); U05 libera, amplía, valida; U07 lectura; U02/U01 completos.

---

## 8. Eventos, jobs y notificaciones

| Elemento | Cola | Detalle |
|---|---|---|
| Listener `ConsumeAllotmentOnServiceChange` (eventos `BookingServiceConfirmed`, `BookingServiceCancelled`, `ReservationCancelled`, `ReservationRescheduled`) | `critical` | Recalcula `catalog_allotment_consumptions` y `used_qty/pending_qty` con `Cache::lock("allot:{id}:{date}")`; si queda disponible < 0 emite `AllotmentOverbooked` |
| Job `ReleaseAllotments` (`JOB-10`, diario 00:15, ShouldBeUnique) | `default` | CUP-BR-009; registra en `sys_job_runs`; resultado "N unidades liberadas en M fechas" |
| Job `ReleaseDueAlerts` (diario 07:00) | `notifications` | Fechas con release en ≤ 3 días y disponible > 0 → push/email a Operaciones |
| Job `TravelDocReminders` (`JOB-11`, diario 08:00) | `notifications` | Reservas no completas con días al viaje ∈ `reminder_days` o ≤ mínimo; un envío automático por día |
| Listener `CreateTravelDocChecklist` (`ReservationConfirmed`, `PassengerUpdated`) | `default` | Crea/ajusta ítems |
| `TravelDocsGate` | síncrono | Consultado por emisión de vouchers/entradas (05) |
| Notificaciones (PROC-059) | `notifications` | `cupo.release`, `cupo.liberacion` → proveedor (Email); `cupo.ampliacion` → proveedor; `cupo.release_proximo` → Operaciones; `documentos.recordatorio` / `documentos.solicitud` → cliente (Email + WhatsApp); `documentos.observado` → cliente |
| Reverb | — | Canal `tenant.{id}.ops` actualiza grilla y tablero |

---

## 9. Auditoría (`audit_events`)

Cupo: `crear`, `editar_contrato`, `liberar` (fecha, antes/después), `retener` (motivo y exposición), `quitar_retencion`, `release_automatico`, `vencer`, `solicitar_ampliacion`, `ampliacion_aceptada/rechazada`, `liberar_contrato`, exportaciones. Documento de viaje: `cargar`, `validar`, `observar`, `registrar_contacto`, `declarar_atencion`, `solicitar_documentos`, `ejecutar_recordatorios`, `editar_reglas`. Además, lectura/descarga de archivos de identidad se audita (`ver_documento`) por ser dato personal (Ley 29733).

---

## 10. Casos de prueba mínimos

**Pest**
1. Tenant A no ve contratos ni checklists de B (RLS).
2. Ventas → `GET /catalog/allotments` 403; Finanzas GET 200 y POST/PATCH 403.
3. Ventas lista travel-docs solo con `owner=me`; detalle ajeno 403; puede `POST requests` solo en propia.
4. Crear contrato con servicio de otro proveedor → 422 CUP-BR-002; solapado → 409 CUP-BR-003.
5. Liberar más que disponible → 422 CUP-BR-005; concurrencia de dos liberaciones → una 409 (lock).
6. Confirmar servicio de reserva incrementa `used_qty`; cancelar lo revierte; solicitado → `pending_qty`.
7. Job: libera fechas con release vencido (no retenidas), cambia `en_release → activo`, crea cargo en fecha pasada retenida y `fin_payables`; segunda ejecución no duplica.
8. Aceptar ampliación suma `extra_qty` al rango; rechazar no altera.
9. Validar pasaporte de extranjero con vencimiento < fin + 6 meses → 422 DOC-BR-001 (caso Johnson 10/03/2027 vs 23/04/2027).
10. Menor sin `minor_auth` completo → gate MAPI bloqueado; tras cargar y validar → habilitado.
11. Nota de atención especial con término clínico → 422 DOC-BR-009.
12. Job de recordatorios: envía a reservas a 2–3 días (mineros, Wayra) y no reenvía el mismo día.

**Playwright**
- E2E-CUP-01: Operaciones abre grilla, clic en celda Tambo 02/10, libera 2, ve toast y celda actualizada.
- E2E-CUP-02: Solicita ampliación en Ferrovía, registra aceptación, cupo del rango aumenta.
- E2E-CUP-03: Finanzas ve penalidades devengadas y botones deshabilitados.
- E2E-DOC-01: Operaciones valida DNI de menor, carga permiso notarial y valida; el aviso de bloqueo MAPI desaparece.
- E2E-DOC-02: Ventas (U03) ve 3 reservas, 403/cartera ajena en RES-2026-000344, envía solicitud en RES-2026-000349.
- Responsive 400 px sin scroll horizontal de página (grilla con scroll interno).

---

## 11. Cambios y decisiones respecto a la documentación inicial

**Módulo nuevo: no estaba en la documentación inicial; motivo:** el cliente pidió completar el SaaS con funciones estándar de tour operadores. (a) Los *allotments* (cupos garantizados con release y penalidad) son la forma habitual de contratar hoteles, tren y entradas en temporada; PROC-020 solo contemplaba capacidad propia y holds. (b) La verificación documental del pasajero es condición operativa real (entradas nominativas a Machu Picchu, pasaporte con 6 meses de vigencia, menores de edad) que DOC-PROC-001 solo mencionaba como tarea de la orden ("Verificar pasaportes para entradas nominativas").

Decisiones:
- El consumo se **deriva** de `booking_services` (proyección con tabla de consumos) en lugar de reservar manualmente desde el cupo, para no duplicar reglas (Regla transversal 3).
- `vencido` y `no_aplica` de documentos son estados **calculados**, no persistidos, para reflejar cambios de fechas de viaje o de configuración sin migraciones.
- El bloqueo MAPI se implementa como *gate* consultado por el servicio de vouchers (05); el prototipo lo muestra sin modificar `operaciones.js`.
- Atención especial sin diagnósticos (minimización de datos, Ley 29733).
- La penalidad genera CxP por job en lugar de cálculo manual de Finanzas.

---

## 12. Supuestos y puntos a validar con negocio

1. Lista de nacionalidades con visa (prototipo: China, India, Vietnamita) es referencial: validar con normativa vigente de Migraciones y mantenerla configurable.
2. Regla de menores: el brief indica permiso notarial cuando el menor no viaja con ambos padres; confirmar tratamiento de viajes nacionales con un solo padre y documentación aceptada (partida, DNI con filiación).
3. Seguro obligatorio en trekking para nacionales: política de la agencia, confirmar.
4. Entradas del Ministerio de Cultura: se asume penalidad 0 % (el cupo no nominado se pierde); confirmar condiciones del convenio.
5. Modo estricto de sobreventa (409) vs *on request* por tenant.
6. Unidades de hotel: en producción se toman de campos estructurados (`rooms`, `nights`), no del nombre del servicio como en el prototipo.
7. Respuesta de ampliaciones desde la extranet del proveedor (fase 2) y adapters de tren/entradas para liberación automática real.
8. Retención de archivos de identidad: plazo de conservación y borrado tras el viaje.
