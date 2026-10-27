# Operaciones y apps móviles (operador y pasajero)

> Fuente: `proto/assets/js/modules/operaciones.js` (órdenes, calendario, recursos, rooming, vouchers, incidencias, app operador, app pasajero), `core.js` (STATUS, ACCESS, ROLE_UX, Audit, Notify, Seq) y `data.js` (`operations`, `rooming`, `vouchers`, `incidents`, `guides`, `drivers`, `vehicles`, `reservations[].services`, `rolePerms`). La extranet de proveedores está en `06-extranet-proveedores.md`. Documentos de viaje (`#/operaciones/documentos-viaje`) no pertenece a este módulo.
>
> Convención de este documento: los **códigos de estado** se conservan como en el prototipo (español, snake_case) para trazabilidad UI ↔ API ↔ BD; tablas, columnas y endpoints en inglés.

---

## 1. Propósito y procesos cubiertos

Gestiona la **ejecución** de reservas confirmadas: orden operativa, solicitud/confirmación a proveedores, asignación de guías/conductores/vehículos con validación de conflictos, rooming list, vouchers, ejecución en campo (app del operador con modo offline), incidencias con SLA, evidencias y cierre operativo que habilita finanzas. Incluye la app del pasajero (consumo de itinerario, vouchers, alertas y soporte).

| Proceso | Nombre | Cobertura en prototipo |
|---|---|---|
| PROC-031 | Orden operativa | Crear desde reserva confirmada, replicar servicios, tareas, coordinador, máquina de estados |
| PROC-032 | Solicitud a proveedor | Enviar solicitud, registrar confirmación (código) o rechazo (motivo); desde extranet también alternativa |
| PROC-033 | Asignación | Guía/conductor/vehículo con validación de cruces, capacidad, licencias, SOAT, estado del recurso e idioma |
| PROC-034 | Rooming list | Distribución, validación de conflictos, confirmar, enviar al hotel (acuse/confirmación del hotel en extranet) |
| PROC-035 | Voucher | Validar, emitir, versionar, reenviar, marcar utilizado, anular; publicado en app/portal del pasajero |
| PROC-036 | Ejecución | Confirmar → iniciar → check-in por pasajero → evidencia → fin (app operador); completar desde web |
| PROC-037 | Incidencia | Registrar, clasificar, priorizar (SLA), asignar, resolver, evidencia, cerrar, reabrir; alerta crítica |
| PROC-038 | Evidencia | Registro web y móvil, asociación a servicio/incidencia, retiro lógico con motivo |
| PROC-039 | Cierre operativo | 4 precondiciones verificadas; cierra orden y completa la reserva |
| PROC-056 | App operador | Hoy, detalle/ejecución, calendario 14 días, incidencias, perfil + sincronización |
| PROC-057 | App pasajero | Mi viaje, itinerario, vouchers con QR, documentos, alertas, soporte/chat y emergencia |
| PROC-058 | Offline | Outbox local, estados Pending → Syncing → Synced/Conflict/Failed, idempotencia, reglas de conflicto |

Casos E2E relacionados: E2E-03, E2E-04, E2E-07, E2E-10.

---

## 2. Pantallas

| Ruta prototipo | Ruta Next.js / app | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/operaciones` | `/operaciones` → redirige a `/operaciones/ordenes` | Alias de la lista de órdenes | `operations.view` (Tenant Admin, Manager, Operaciones) |
| `#/operaciones/ordenes` | `/operaciones/ordenes` | KPIs (en ejecución, servicios pendientes de asignación, servicios hoy/mañana, incidencias abiertas/críticas), alerta de incidencias críticas, tabla con búsqueda, filtros Estado/Coordinador, % de asignación, nº de incidencias abiertas, exportar CSV. Botón **Nueva orden** | Ver: `operations.view`. Crear: `operations.assign` |
| `#/operaciones/ordenes/{OP}` | `/operaciones/ordenes/[code]` | Orden 360: flujo de estados, mini-KPIs (servicios, confirmados proveedor, con recursos, vouchers vigentes, incidencias abiertas, evidencias), botones Ver reserva, Coordinador, avanzar estado, Ir a cierre. Pestañas: **Servicios y asignaciones, Tareas, Pasajeros, Rooming, Vouchers, Incidencias, Evidencias, Cierre** (pestaña recordada por orden) | Ver: `operations.view` |
| `#/operaciones/calendario` | `/operaciones/calendario` | Mes / semana / lista de todos los servicios de reservas no canceladas y salidas programadas; filtro por tipo; KPIs del mes; drawer de evento con asignación y enlace a la orden | `operations.view`; botón "Asignar recursos" del drawer: `operations.assign` |
| `#/operaciones/asignaciones` | `/operaciones/asignaciones` | Tarjetas de guías, conductores y vehículos; alertas documentarias (licencia/SOAT ≤ 30 días); matriz de disponibilidad 7 días (órdenes + salidas); lista de "asignación rápida" de servicios con recursos incompletos; menú de estado del recurso; drawer agenda del recurso | Ver: `operations.view`. Cambiar estado/asignar: `operations.assign` |
| `#/operaciones/rooming[/{RES}]` | `/operaciones/rooming/[reservationCode]` | Selector de rooming lists (pills), tabla de habitaciones con ocupación/capacidad, validación de conflictos, datos, vista previa, agregar/editar/eliminar habitación, confirmar, enviar al hotel, nueva rooming list | Ver: `operations.view`. Modificar: `reservations.modify` |
| `#/operaciones/vouchers` | `/operaciones/vouchers` | KPIs (vigentes, utilizados, anulados, confirmados sin voucher), lista de servicios confirmados pendientes de voucher, tabla filtrable por estado/proveedor | Ver: `operations.view`. Emitir: `operations.vouchers` |
| `#/operaciones/vouchers/{VOU}` | `/operaciones/vouchers/[code]` | Documento del voucher (QR, proveedor, servicio, punto de encuentro, código de confirmación, pasajeros, guía/transporte, incluye/no incluye, dietas, contacto 24/7), datos, historial de versiones, notificaciones. Acciones: Descargar PDF, Reenviar, Nueva versión, Marcar utilizado, Anular | Ver: `operations.view`. Acciones (salvo PDF): `operations.vouchers` |
| `#/operaciones/incidencias` | `/operaciones/incidencias` | KPIs (abiertas, críticas abiertas, en proceso, SLA vencido), alerta críticas, tabla filtrable (estado, severidad, tipo) con barra SLA | Ver: `operations.view`. Crear: `operations.incidents` |
| `#/operaciones/incidencias/{INC}` | `/operaciones/incidencias/[code]` | Flujo PROC-037 (8 pasos), detalle, bitácora + comentario, SLA, evidencias + adjuntar, notificaciones enviadas. Acciones: Pasar a en proceso, Asignar, Reclasificar, Registrar resolución, Cerrar, Reabrir | Ver: `operations.view`. Acciones: `operations.incidents` |
| `#/mobile`, `#/mobile/operador` | App RN **Operador** (Expo). Web: `/mobile/operador` solo como vista previa interna | App del operador (pantallas §2.1). Internos ven maqueta + panel PROC-056 + selector "Ver como recurso" + switch offline; guía/conductor ven solo el teléfono con SU recurso | Internos: `operations.assign`. Externos: rol `guide`/`driver` (ROLE_UX) |
| `#/mobile/pasajero` | App RN **Pasajero** (Expo). Web: `/mobile/pasajero` vista previa interna | App del pasajero (pantallas §2.2). Internos ven reserva demo `RES-2026-000347` + panel PROC-057; pasajero ve SU reserva (`users.resId`) | Internos: `operations.assign`. Externo: rol `passenger` |

### 2.1 App operador (PROC-056)

| Pantalla | Contenido | Acciones |
|---|---|---|
| Hoy (`hoy`) | Saludo, contadores hoy/próximos/por confirmar, estado de red y última sincronización, alerta si hay incidencia crítica abierta en sus órdenes futuras, tarjetas Hoy / Próximos / Recientes (2 últimas) | Abrir servicio |
| Detalle (`detalle`) | Hora/fecha/estado, barra de pasos `Asignado → Confirmado → Inicio → Check-in → Evidencia → Fin`, punto de encuentro, vehículo, compañero (guía↔conductor con teléfono), coordinador con botón Llamar, notas de reserva, dietas, incidencias abiertas, lista de pasajeros con check-in, evidencias del servicio | Según estado: Confirmar asignación · Iniciar servicio · Check-in individual/grupal · Evidencia · Reportar incidencia · Finalizar servicio |
| Nueva incidencia (`incForm`) | Servicio (sus asignaciones no completadas o de ayer en adelante), tipo, severidad (radio), título, descripción, "Adjuntar foto" | Enviar incidencia |
| Calendario (`cal`) | Tira de 7 días con indicador y agenda 14 días | Abrir servicio |
| Incidencias (`inc`) | Incidencias de sus órdenes o reportadas por él | Reportar incidencia |
| Perfil (`perfil`) | Datos del recurso (carné/licencia y vencimiento), switch "Sin conexión", **Bandeja de salida** con estado por acción y conflicto, "Sincronizadas recientemente" (6 últimas) | Cambiar conectividad (demo) |

### 2.2 App pasajero (PROC-057)

| Pestaña | Contenido |
|---|---|
| Mi viaje | Estado (faltan N días / en curso día X de N / finalizado), alerta crítica destacada, próxima actividad con cuenta regresiva, punto de encuentro y guía, hotel y habitación (de la rooming list), accesos rápidos Itinerario/Vouchers/Chat/Emergencia |
| Itinerario | Día por día: servicios (sin alojamiento) con estado; al tocar: punto de encuentro, proveedor y código, guía/conductor, "Incluye"; noche en hotel y habitación |
| Vouchers | Vouchers no anulados; detalle con QR (`{voucher}|{reserva}|{versión}`), proveedor, confirmación, encuentro, pasajeros |
| Docs | Itinerario, comprobante, póliza, pasaportes registrados, políticas |
| Alertas | Alertas del viaje (cambio de horario por incidencia, recordatorios, voucher actualizado), marca de leída, badge de no leídas |
| Soporte | Número de emergencia 24/7 con botón Llamar, chat con la agencia, respuestas rápidas |

---

### 2.3 v2.1 — Motor de reservas en Operaciones
| Ruta prototipo | Qué cambia | Regla |
|---|---|---|
| `#/operaciones/ordenes/{code}` | Tarjeta «Datos del cliente para la operación»: punto y hora de recojo (servicio de recojo), vuelo y llegada, comentarios especiales del cliente, dietas por pasajero y etiquetas (dieta/alergia, celebración, accesibilidad, infante). En Servicios, la fila del servicio de recojo muestra «Recojo: …». Pasajeros con parentesco, edad y titular | R-PICKUP, R-COMMENTS (dato histórico con etiqueta si la regla se desactivó) |
| `#/operaciones/ordenes/{code}` › Rooming | Si la reserva trae `booking.lodging`, botón «Prellenar rooming desde la reserva web»; con varias ciudades muestra una tabla de rooming lists por ciudad/hotel | R-LODGING |
| `#/operaciones/rooming/{resId}~{legId}` | Rooming por ciudad (clave reserva + leg). Aviso de reservas web sin rooming con botón de prellenado. Ocupación excluye infantes («3/3 +1 INF») | R-LODGING, R-INFANT-FREE |
| `#/operaciones/vouchers/{code}` | «Punto de recojo» (en lugar de punto de encuentro) con vuelo y cartel a nombre del titular; pasajeros con titular/acompañante, parentesco y tipo; observaciones con el pedido del cliente | R-PICKUP, R-COMPANIONS |
| `#/mobile/operador` | Tarjeta de servicio: «Recojo: …» + vuelo y comentario del cliente. Detalle: bloque «Recojo del cliente (reserva web)» y «Comentarios del cliente» con etiquetas | R-PICKUP, R-COMMENTS |
| `#/mobile/pasajero` | Botones «Mensajes (n)» y «Comentar» en Mi viaje; pantallas de bandeja (tocar = leer) y formulario de comentarios (misma lógica que el portal, `window.PaxExp`) | R-PAX-MESSAGES, R-PAX-FEEDBACK |

## 3. Modelo de datos PostgreSQL

Todas las tablas: `tenant_id uuid not null` FK `tenants(id)` + RLS `tenant_isolation`; `id uuid` v7; `created_by`, `updated_by`, `created_at`, `updated_at` (se omiten en las tablas por brevedad salvo que tengan semántica propia). Extensión requerida: `btree_gist` (restricciones de exclusión de rooming).

Dependencias externas a este módulo (definidas en otras especificaciones): `booking_reservations`, `booking_reservation_services`, `booking_passengers`, `catalog_providers`, `catalog_services`, `catalog_products`, `catalog_departures`, `iam_users`, `fin_payables`, `notif_notifications`, `sys_documents`, `sys_sequences`, `audit_logs`.

### 3.1 `ops_orders` (orden operativa)

| Columna | Tipo | Null | Notas / FK / índice |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK tenants |
| code | varchar(30) | no | `OP-2026-0412` (serie "Orden operativa", ancho 4). UNIQUE (tenant_id, code) |
| reservation_id | uuid | no | FK booking_reservations. UNIQUE (tenant_id, reservation_id) WHERE deleted_at IS NULL (una orden por reserva) |
| title | varchar(200) | no | Copiado de la reserva al crear |
| status | varchar(30) | no | CHECK IN (`planificada`,`pendiente_asignacion`,`asignada`,`confirmada`,`en_ejecucion`,`completada`,`cancelada`) |
| coordinator_id | uuid | no | FK iam_users (roles operations/manager/tenant_admin activos) |
| date_from | date | no | = reservation.travel_from |
| date_to | date | no | = reservation.travel_to. CHECK (date_to >= date_from) |
| closed_at | timestamptz | sí | Cierre PROC-039 |
| closed_by | uuid | sí | FK iam_users |
| cancelled_at / cancel_reason | timestamptz / text | sí | Estado `cancelada` (ver §12) |
| lock_version | integer | no | default 0 |
| deleted_at | timestamptz | sí | SoftDeletes |

Índices: `(tenant_id, status)`, `(tenant_id, coordinator_id)`, `(tenant_id, date_from)`, `(tenant_id, created_at desc)`.

### 3.2 `ops_order_tasks` (checklist)

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| order_id | uuid | no | FK ops_orders, índice |
| title | varchar(250) | no | |
| position | smallint | no | Orden de visualización |
| done | boolean | no | default false |
| done_by | uuid | sí | FK iam_users (prototipo `t.by`) |
| done_at | timestamptz | sí | (prototipo `t.at`) |
| deleted_at | timestamptz | sí | El prototipo elimina físicamente y audita; aquí eliminación lógica (RT-24) |

Tareas por defecto al crear la orden (5): *Enviar solicitudes a proveedores · Asignar guías, conductores y vehículos · Emitir vouchers de servicios confirmados · Llamada de bienvenida al cliente · Encuesta de cierre*.

### 3.3 Columnas operativas de `booking_reservation_services` (tabla de Reservas)

Operaciones modifica estas columnas del servicio de la reserva (la tabla es propiedad de la especificación de Reservas; aquí se listan las que este módulo necesita):

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| status | varchar(30) | no | CHECK IN (`pendiente`,`solicitado`,`confirmado`,`rechazado`,`en_curso`,`completado`,`cancelado`) |
| provider_id | uuid | sí | FK catalog_providers (null = día libre / sin proveedor) |
| service_date / service_time | date / time | no / sí | `time` null cuando el prototipo muestra "—" |
| confirmation_code | varchar(40) | sí | Obligatorio para pasar a `confirmado` |
| confirmed_at / confirmed_by | timestamptz / uuid | sí | |
| requested_at / requested_by | timestamptz / uuid | sí | Al enviar solicitud (el prototipo solo lo tiene en datos de la extranet) |
| unit_cost, currency, fx_rate | numeric(14,2), char(3), numeric(12,6) | no | Costo de proveedor (visible a Operaciones) |
| unit_price | numeric(14,2) | no | Precio de venta (NO visible a Operaciones; §7) |
| requires_assignment | boolean | no | **Nuevo**: reemplaza la heurística por nombre (ver OPS-BR-03) |
| service_kind | varchar(20) | no | **Nuevo**: CHECK IN (`tour`,`traslado`,`alojamiento`,`tren_entrada`,`libre`) — reemplaza `svcClass()` por regex |
| lock_version | integer | no | |

### 3.4 `ops_supplier_requests` (PROC-032)

Historial de cada solicitud enviada a un proveedor por servicio (el prototipo solo cambia `status`; se normaliza para trazabilidad y para la extranet).

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| reservation_service_id | uuid | no | FK booking_reservation_services, índice |
| provider_id | uuid | no | FK catalog_providers; índice `(tenant_id, provider_id, status)` |
| status | varchar(30) | no | CHECK IN (`solicitado`,`confirmado`,`rechazado`,`alternativa_propuesta`,`anulada`) |
| channel | varchar(20) | no | CHECK IN (`email`,`extranet`,`telefono`,`integracion`) |
| requested_at / requested_by | timestamptz / uuid | no | |
| responded_at | timestamptz | sí | |
| responded_by_user_id | uuid | sí | Usuario proveedor (extranet) o interno que registró la respuesta |
| response_source | varchar(20) | sí | CHECK IN (`web_interno`,`extranet`) |
| confirmation_code | varchar(40) | sí | |
| rejection_reason | varchar(120) | sí | Obligatorio si `rechazado` |
| note | text | sí | |
| deleted_at | timestamptz | sí | |

Detalle de la respuesta de extranet (alternativas, "confirmado por") en `supplier_request_responses` (doc 06).

### 3.5 `ops_assignments` (asignación por servicio)

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| order_id | uuid | no | FK ops_orders, índice |
| reservation_service_id | uuid | no | FK booking_reservation_services. UNIQUE (tenant_id, order_id, reservation_service_id) |
| service_date | date | no | Copia desnormalizada (se actualiza si Reservas cambia la hora al confirmar proveedor) |
| service_time | time | sí | |
| service_label | varchar(200) | no | |
| guide_id | uuid | sí | FK ops_guides; índice `(tenant_id, guide_id, service_date)` |
| driver_id | uuid | sí | FK ops_drivers; índice `(tenant_id, driver_id, service_date)` |
| vehicle_id | uuid | sí | FK ops_vehicles; índice `(tenant_id, vehicle_id, service_date)` |
| status | varchar(30) | no | CHECK IN (`pendiente`,`asignada`,`confirmada`,`en_curso`,`completada`) |
| confirmed_at / confirmed_via | timestamptz / varchar(10) | sí | `web` o `app` |
| started_at | timestamptz | sí | Hora real de inicio (hecho de campo) |
| first_checkin_at | timestamptz | sí | Prototipo `checkin` = menor marca de check-in |
| finished_at | timestamptz | sí | |
| warnings_ack | jsonb | sí | Advertencias asumidas al asignar `[{code,text}]` + usuario/fecha |
| lock_version | integer | no | |

CHECK: `status = 'pendiente' OR guide_id IS NOT NULL OR driver_id IS NOT NULL OR vehicle_id IS NOT NULL`.

### 3.6 `ops_assignment_checkins` (check-in por pasajero)

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| assignment_id | uuid | no | FK ops_assignments |
| passenger_id | uuid | no | FK booking_passengers |
| checked_at | timestamptz | no | Marca del dispositivo (hecho de campo) |
| source | varchar(10) | no | CHECK IN (`app`,`web`) |
| client_action_id | uuid | sí | FK lógica a ops_sync_actions |
| device_id | uuid | sí | FK ops_mobile_devices |
| undone_at / undone_by | timestamptz / uuid | sí | "Quitar check-in" (el prototipo borra la marca) |

UNIQUE parcial `(assignment_id, passenger_id) WHERE undone_at IS NULL` → idempotencia pasajero + servicio (MOB-BR-06).

### 3.7 Recursos: `ops_guides`, `ops_drivers`, `ops_vehicles`

`ops_guides`

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| code | varchar(20) | no | `G01`. UNIQUE (tenant_id, code) |
| user_id | uuid | sí | FK iam_users (rol guide). UNIQUE parcial (tenant_id, user_id) |
| provider_id | uuid | sí | FK catalog_providers (p. ej. asociación de guías) |
| full_name | varchar(150) | no | |
| languages | char(2)[] | no | ISO 639-1 (`ES`,`EN`,`QU`,`DE`,`FR`,`PT`); índice GIN |
| license_number | varchar(40) | no | Carné oficial |
| rating | numeric(2,1) | sí | 0–5 |
| status | varchar(20) | no | CHECK IN (`disponible`,`asignado`,`descanso`,`vacaciones`) |
| phone | varchar(30) | sí | |
| deleted_at | timestamptz | sí | |

`ops_drivers`: igual estructura base con `license_number varchar(40)`, `license_class varchar(10)` (A-IIb, A-IIIb), `license_expires_on date not null`, `status` CHECK IN (`disponible`,`asignado`,`descanso`,`vacaciones`), `user_id` (rol driver). Índice `(tenant_id, license_expires_on)`.

`ops_vehicles`

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| plate | varchar(12) | no | UNIQUE (tenant_id, plate) |
| vehicle_type | varchar(60) | no | "Sprinter 19 pax" |
| capacity | smallint | no | CHECK (capacity > 0) |
| provider_id | uuid | sí | FK catalog_providers (transportista) |
| soat_expires_on | date | no | índice `(tenant_id, soat_expires_on)` |
| status | varchar(20) | no | CHECK IN (`disponible`,`asignado`,`mantenimiento`) |
| deleted_at | timestamptz | sí | |

Ocupación de recursos: **vista** `ops_resource_occupations` = UNION de `ops_assignments` (órdenes no canceladas, asignación ≠ `completada`) y `catalog_departures` (guía/vehículo de salidas programadas), columnas `(tenant_id, resource_type, resource_id, service_date, service_time, label, source_type, source_id, order_id)`. Se usa para validar PROC-033 y para la matriz de disponibilidad.

### 3.8 `ops_rooming_lists`

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| reservation_id | uuid | no | FK booking_reservations |
| hotel_provider_id | uuid | no | FK catalog_providers (type = Hotel, activo). UNIQUE (tenant_id, reservation_id, hotel_provider_id) |
| check_in | date | no | |
| check_out | date | no | CHECK (check_out > check_in) |
| stay | daterange | no | GENERATED ALWAYS AS (daterange(check_in, check_out, '[)')) STORED |
| status | varchar(20) | no | CHECK IN (`borrador`,`confirmada`,`modificada`,`enviada`) (prototipo: `enviado`) |
| sent_at / sent_by | timestamptz / uuid | sí | |
| sent_version | integer | no | default 0; se incrementa en cada envío |
| hotel_ack_at / hotel_ack_by | timestamptz / uuid | sí | Acuse de recibo desde extranet |
| hotel_confirmed_at / hotel_confirmed_by | timestamptz / uuid | sí | Confirmación del hotel desde extranet |
| document_id | uuid | sí | FK sys_documents (PDF enviado) |
| lock_version | integer | no | |
| deleted_at | timestamptz | sí | |

### 3.9 `ops_rooming_rooms` y `ops_rooming_guests`

`ops_rooming_rooms`

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| rooming_list_id | uuid | no | FK ops_rooming_lists |
| hotel_provider_id | uuid | no | Desnormalizado para exclusión |
| stay | daterange | no | Desnormalizado de la lista |
| room_number | varchar(10) | no | UNIQUE (rooming_list_id, room_number) |
| room_type | varchar(20) | no | CHECK IN (`Simple`,`Doble`,`Doble twin`,`Matrimonial`,`Triple`,`Junior suite`) |
| capacity | smallint | no | Derivada del tipo: 1/2/2/2/3/2 |
| status | varchar(20) | no | CHECK IN (`confirmada`,`pendiente`,`observada`) |
| hotel_note | text | sí | Observación del hotel (extranet) |
| incident_id | uuid | sí | FK ops_incidents (observación → incidencia) |

EXCLUDE USING gist `(tenant_id WITH =, hotel_provider_id WITH =, room_number WITH =, stay WITH &&)` → misma habitación del mismo hotel en fechas superpuestas (OPS-BR-31).

`ops_rooming_guests`

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| room_id | uuid | no | FK ops_rooming_rooms ON DELETE CASCADE |
| rooming_list_id | uuid | no | Desnormalizado. UNIQUE (rooming_list_id, passenger_id) |
| passenger_id | uuid | no | FK booking_passengers |
| stay | daterange | no | Desnormalizado |

EXCLUDE USING gist `(tenant_id WITH =, passenger_id WITH =, stay WITH &&)` → un pasajero no puede tener dos habitaciones activas en intervalos superpuestos (PROC-034, RT-13). La capacidad por tipo se valida en Service (trigger opcional).

### 3.10 `ops_vouchers` y `ops_voucher_versions`

`ops_vouchers`

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| code | varchar(30) | no | `VOU-2026-01201` (serie "Voucher", ancho 5). UNIQUE (tenant_id, code) |
| reservation_id | uuid | no | FK booking_reservations; índice |
| reservation_service_id | uuid | no | FK booking_reservation_services |
| provider_id | uuid | no | FK catalog_providers; índice `(tenant_id, provider_id, status)` |
| service_label | varchar(200) | no | |
| service_date | date | no | |
| pax | smallint | no | = nº pasajeros de la reserva al emitir |
| status | varchar(20) | no | CHECK IN (`emitido`,`utilizado`,`anulado`) |
| version | integer | no | default 1; CHECK (version >= 1) |
| issued_at / issued_by | timestamptz / uuid | no | Última versión |
| note | text | sí | Nota para el pasajero |
| used_at / used_by | timestamptz / uuid | sí | |
| used_via | varchar(20) | sí | CHECK IN (`web`,`extranet`,`app_operador`) |
| void_reason / void_at / void_by | text / timestamptz / uuid | sí | Obligatorio si `anulado` |
| origin | varchar(20) | no | CHECK IN (`operaciones`,`ecommerce`) (canales.js también emite) |
| qr_token | varchar(128) | no | Token firmado (HMAC) `code|reserva|versión`; UNIQUE |
| document_id | uuid | sí | FK sys_documents (PDF vigente) |
| lock_version | integer | no | |

Índice único parcial: `(tenant_id, reservation_service_id) WHERE status = 'emitido'` (un voucher vigente por servicio, OPS-BR-41).

`ops_voucher_versions`: `id`, `tenant_id`, `voucher_id` FK, `version integer`, `issued_at`, `issued_by`, `reason text not null` ("Emisión inicial" en v1), `snapshot jsonb not null` (servicio, fecha/hora, proveedor, pasajeros, código de confirmación, recursos, punto de encuentro), `document_id`. UNIQUE `(voucher_id, version)`.

### 3.11 `ops_incidents` y `ops_incident_logs`

`ops_incidents`

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| code | varchar(20) | no | `INC-0088` vía `sys_sequences` (el prototipo usa máx+1). UNIQUE (tenant_id, code) |
| order_id | uuid | sí | FK ops_orders |
| reservation_id | uuid | sí | FK booking_reservations |
| reservation_service_id | uuid | sí | FK booking_reservation_services |
| provider_id | uuid | sí | FK catalog_providers (reportada por/para proveedor) |
| type | varchar(30) | no | CHECK IN (`Transporte`,`Proveedor`,`Salud del pasajero`,`Horario`,`Clima`,`Documentación`,`Equipaje`,`Seguridad`,`Capacidad`,`Pasajero`,`Otro`) |
| severity | varchar(10) | no | CHECK IN (`baja`,`media`,`alta`,`critica`) |
| title | varchar(200) | no | |
| description | text | no | |
| status | varchar(20) | no | CHECK IN (`abierta`,`en_proceso`,`resuelta`,`cerrada`) |
| assignee_id | uuid | sí | FK iam_users (operations/manager/tenant_admin) |
| reported_by | uuid | no | FK iam_users (incluye guía, conductor, proveedor) |
| source | varchar(20) | no | CHECK IN (`web`,`app_operador`,`extranet`,`sistema`) |
| sla_hours | smallint | no | 4/8/24/72 según severidad vigente |
| sla_due_at | timestamptz | no | created_at + sla_hours (recalcular al reclasificar) |
| resolution | text | sí | |
| resolved_at / resolved_by | timestamptz / uuid | sí | |
| closed_at / closed_by | timestamptz / uuid | sí | |
| related_incident_id | uuid | sí | Duplicado probable creado desde móvil (MOB-BR-11) |
| client_action_id | uuid | sí | Idempotencia móvil; UNIQUE parcial (tenant_id, client_action_id) |
| lock_version | integer | no | |

Índices: `(tenant_id, status, severity)`, `(tenant_id, order_id)`, `(tenant_id, assignee_id)`, `(tenant_id, provider_id)`, `(tenant_id, sla_due_at) WHERE status IN ('abierta','en_proceso')`.

`ops_incident_logs`: `id`, `tenant_id`, `incident_id` FK (índice), `user_id` (null = Sistema), `kind` CHECK IN (`registro`,`comentario`,`estado`,`asignacion`,`reclasificacion`,`alerta`,`evidencia`,`resolucion`), `message text not null`, `visibility` CHECK IN (`interna`,`proveedor`) default `proveedor` (doc 06), `created_at`. Sin update/delete (append-only).

### 3.12 `ops_evidences` (PROC-038)

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| order_id | uuid | sí | FK ops_orders; índice |
| reservation_service_id | uuid | sí | null = "General (sin servicio)" |
| incident_id | uuid | sí | FK ops_incidents; CHECK (order_id IS NOT NULL OR incident_id IS NOT NULL) |
| file_name | varchar(200) | no | |
| storage_path | varchar(400) | no | `tenants/{tenant}/ops/evidences/{uuid}.{ext}` |
| mime_type | varchar(80) | no | CHECK image/* o application/pdf |
| size_bytes | integer | no | |
| sha256 | char(64) | no | UNIQUE parcial (tenant_id, order_id, sha256) WHERE removed_at IS NULL |
| source | varchar(20) | no | CHECK IN (`web`,`app_operador`,`extranet`) |
| captured_at | timestamptz | no | Hora del dispositivo |
| captured_offline | boolean | no | default false |
| uploaded_by | uuid | no | |
| client_action_id | uuid | sí | |
| removed_at / removed_by / removed_reason | timestamptz / uuid / text | sí | Retiro lógico con motivo obligatorio |

### 3.13 Móvil: `ops_mobile_devices`, `ops_sync_actions`

`ops_mobile_devices`

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK (generado por el dispositivo al instalar, persistido en SecureStore) |
| tenant_id | uuid | no | |
| user_id | uuid | no | FK iam_users |
| app | varchar(20) | no | CHECK IN (`operador`,`pasajero`) |
| platform | varchar(10) | no | CHECK IN (`ios`,`android`) |
| app_version | varchar(20) | no | Bloqueo de versiones mínimas |
| expo_push_token | varchar(200) | sí | UNIQUE parcial |
| last_seen_at | timestamptz | sí | |
| last_sync_at | timestamptz | sí | Mostrado como "Última sincronización" |
| sync_cursor | varchar(64) | sí | Cursor de delta sync |
| revoked_at | timestamptz | sí | Revocación remota (borra caché local en próximo arranque) |

`ops_sync_actions` (registro servidor de cada acción recibida del outbox; base de idempotencia)

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK = `client_action_id` generado en el dispositivo (UUID v7). UNIQUE (tenant_id, id) |
| tenant_id | uuid | no | |
| user_id | uuid | no | |
| device_id | uuid | no | FK ops_mobile_devices |
| resource_type / resource_id | varchar(10) / uuid | no | `guide`/`driver` + id del recurso autenticado |
| action_type | varchar(20) | no | CHECK IN (`confirm`,`start`,`checkin`,`checkin_undo`,`checkin_all`,`evidence`,`incident`,`finish`) |
| assignment_id | uuid | no | FK ops_assignments |
| coalesce_key | varchar(120) | no | `type:order:service:passenger` (dedupe local, trazabilidad) |
| payload | jsonb | no | |
| occurred_at | timestamptz | no | Hora del dispositivo |
| captured_offline | boolean | no | |
| received_at | timestamptz | no | |
| attempts | smallint | no | Reportado por el cliente |
| result | varchar(20) | no | CHECK IN (`applied`,`duplicate`,`conflict_resolved`,`rejected`) |
| conflict_rule | varchar(40) | sí | Código de regla aplicada (MOB-BR-xx) |
| conflict_message | text | sí | Texto mostrado al usuario |
| response | jsonb | no | Respuesta cacheada que se devuelve ante reintentos |

Índice `(tenant_id, assignment_id, occurred_at)`.

### 3.14 App pasajero: `channel_trip_messages`, `notif_reads`

`channel_trip_messages` (chat pasajero ↔ agencia; puede unificarse con la Bandeja omnicanal `collab_`):

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| reservation_id | uuid | no | FK booking_reservations; índice `(tenant_id, reservation_id, created_at)` |
| sender_type | varchar(10) | no | CHECK IN (`passenger`,`agency`,`system`) |
| sender_user_id | uuid | sí | FK iam_users |
| body | text | no | |
| quick_reply | boolean | no | Mensaje de respuesta rápida |
| read_at | timestamptz | sí | |

`notif_reads`: `(tenant_id, notification_id, user_id, read_at)` UNIQUE (notification_id, user_id). Las alertas de la app pasajero son `notif_notifications` con `reservation_id` y `audience = passenger`.

### 3.14b v2.1 — Rooming por ciudad
`ops_rooming_lists`: nuevas columnas `leg_id varchar(20) null`, `city varchar(80) null`, `hotel_name varchar(150) null` (cuando el hotel del circuito no existe como proveedor, `hotel_provider_id` es null), `source varchar(30) CHECK IN ('manual','booking.lodging')`. Unicidad `UNIQUE (tenant_id, reservation_id, COALESCE(leg_id, hotel_provider_id::text))`. La validación de conflictos compara `COALESCE(hotel_provider_id, hotel_name)`. `ops_rooming_guests` no cuenta a los infantes (pax_type INF) en la capacidad de la habitación.

### 3.15 Restricciones clave (resumen)

| Restricción | Implementación |
|---|---|
| Una orden por reserva | UNIQUE parcial `ops_orders (tenant_id, reservation_id)` |
| Una asignación por servicio y orden | UNIQUE `ops_assignments (tenant_id, order_id, reservation_service_id)` |
| Check-in idempotente pasajero+servicio | UNIQUE parcial `ops_assignment_checkins` |
| Rooming sin conflictos (RT-13) | 2 EXCLUDE gist + UNIQUE `(rooming_list_id, passenger_id)` y `(rooming_list_id, room_number)` |
| Un voucher vigente por servicio | UNIQUE parcial `ops_vouchers WHERE status='emitido'` |
| Idempotencia móvil | PK `ops_sync_actions.id` = client_action_id |
| Evidencia deduplicada por hash | UNIQUE parcial `ops_evidences (tenant_id, order_id, sha256)` |

---

## 4. Máquinas de estado

### 4.1 Orden operativa (`ops_orders.status`)

Flujo visual: `Planificada → Pend. asignación → Asignada → Confirmada → En ejecución → Completada`.

| Desde | Hacia | Disparador / quién | Condición (bloqueo) | Efectos |
|---|---|---|---|---|
| — | `pendiente_asignacion` | Crear orden (`operations.assign`) | Reserva `confirmada` o `en_operacion`; sin orden previa | Replica servicios que requieren asignación; 5 tareas; email al coordinador; audit `crear` |
| — | `planificada` | Crear orden | Igual; ningún servicio requiere asignación | Igual |
| `planificada` | `pendiente_asignacion` | Botón "Pasar a asignación" (`operations.assign`) | — | Audit `cambio_estado` |
| `pendiente_asignacion` | `asignada` | Botón "Marcar como asignada" | Ninguna asignación en `pendiente` (toast "Hay N servicio(s) sin recursos…") | Audit |
| `asignada` | `confirmada` | Botón "Confirmar operación" | Todas las asignaciones en `confirmada`/`en_curso`/`completada` **y** ningún servicio en `pendiente`/`solicitado` | Audit |
| `confirmada` | `en_ejecucion` | Botón "Iniciar ejecución" **o** acción móvil `start` | — | Reserva `confirmada → en_operacion`; audit |
| cualquiera ≠ `en_ejecucion`,`completada`,`cancelada` | recalculado | Automático tras asignar, confirmar recurso o liberar (`recomputeOp`) | Si hay alguna `pendiente` → `pendiente_asignacion`; si todas `confirmada/en_curso/completada` → `confirmada`; si no → `asignada` | — |
| `en_ejecucion` | `completada` | "Cerrar operación" (`operations.close`) | 4 precondiciones PROC-039 (§5) | `closed_at/by`; reserva → `completada`; email coordinador + notificación portal cliente; audit orden y reserva; evento `OperationClosed` |
| activo | `cancelada` | Cancelación de la reserva (Reservas) | — | Libera recursos; ver §12 |

Nota: el prototipo habilita el botón de cierre con precondiciones cumplidas **sin verificar el estado actual** de la orden; la especificación exige `en_ejecucion` (§11, §12).

### 4.2 Asignación (`ops_assignments.status`)

| Desde | Hacia | Quién | Condición | Efectos |
|---|---|---|---|---|
| `pendiente` / `asignada` / `confirmada` | `asignada` | Web "Asignar / Reasignar" (`operations.assign`) | Validación PROC-033 sin bloqueos; advertencias asumidas con casilla | Push y/o WhatsApp al guía/conductor (opcionales); audit `asignar` con antes/después y nº advertencias; recalcula orden |
| `asignada` (o `pendiente` con recurso) | `confirmada` | Web "Registrar confirmación del recurso" (`operations.assign`) **o** móvil `confirm` (recurso asignado) | — | Push al coordinador (móvil); audit |
| `confirmada` | `en_curso` | Móvil `start` | Solo el recurso asignado | `started_at`; servicio → `en_curso`; orden → `en_ejecucion`; reserva → `en_operacion`; push coordinador |
| `en_curso` | `completada` | Móvil `finish` | ≥1 check-in y ≥1 evidencia del servicio | `finished_at`; servicio → `completado`; pasajeros sin check-in = no-show; push coordinador |
| `confirmado`/`en_curso` (servicio) | `completada` | Web "Marcar servicio completado" (`operations.assign`) | Fecha del servicio ≤ hoy | Servicio → `completado` |
| ≠ `pendiente`,`completada` | `pendiente` | Web "Liberar recursos" (`operations.assign`) | Confirmación | Quita guía/conductor/vehículo; WhatsApp "Asignación liberada" a cada recurso; audit `liberar`; recalcula orden |

### 4.3 Servicio de reserva — estado de proveedor (PROC-032/036)

| Desde | Hacia | Quién | Condición / efectos |
|---|---|---|---|
| `pendiente` | `solicitado` | "Enviar solicitud al proveedor" (`operations.assign`) | Email al proveedor; crea `ops_supplier_requests`; audit `solicitar_proveedor` |
| `pendiente`/`solicitado`/`rechazado` | `confirmado` | "Registrar confirmación del proveedor" (`operations.assign`) o extranet | Código de confirmación obligatorio (extranet: ≥ 4 caracteres, mayúsculas); audit `confirmacion_proveedor` / `proveedor_confirma` |
| `solicitado` | `rechazado` | "Registrar rechazo" (`operations.assign`, motivo obligatorio) o extranet | Email al coordinador; toast "Busque alternativa" |
| `pendiente`/`solicitado` | (sin cambio) + respuesta `alternativa` | Extranet | Queda "Alternativa propuesta"; Operaciones decide (§12) |
| `confirmado` | `en_curso` | Móvil `start` | — |
| `confirmado`/`en_curso` | `completado` | Web (fecha ≤ hoy) o móvil `finish` | — |
| cualquiera | `cancelado` | Reservas | Fuera de este módulo |

### 4.4 Voucher (`ops_vouchers.status`)

| Desde | Hacia | Quién (`operations.vouchers`) | Condición / efectos |
|---|---|---|---|
| — | `emitido` v1 | "Emitir y publicar" | OPS-BR-40..43; email cliente, push pasajeros, email proveedor; audit `emitir`; PDF por job |
| `emitido` vN | `emitido` vN+1 | "Nueva versión" (motivo obligatorio) | Servicio aún `confirmado`/`completado`; versión anterior reemplazada; email cliente + push pasajeros; audit `versionar` |
| `emitido` | `emitido` | "Reenviar" | Email al cliente; audit `reenviar` |
| `emitido` | `utilizado` | Web "Marcar utilizado" o extranet (validación de código) | `used_at/by/via`; notificación a Operaciones si viene de extranet |
| `emitido` | `anulado` | "Anular" (motivo obligatorio) | Email al proveedor; audit `anular`; deja de mostrarse en app pasajero |
| `utilizado`/`anulado` | — | — | Estados finales; acciones deshabilitadas ("Solo vouchers vigentes (emitidos)") |

### 4.5 Incidencia (`ops_incidents.status`)

Flujo visual: `Detectar → Registrar → Clasificar → Priorizar → Asignar → Resolver → Evidencia → Cerrar` (paso actual: 4 al crear; 5 con responsable; 6 con resolución; 7 con resolución + evidencia; 8 cerrada).

| Desde | Hacia | Quién (`operations.incidents`) | Condición / efectos |
|---|---|---|---|
| — | `abierta` | Web, app operador (guía/conductor), extranet | Email al coordinador; si `critica`: push + WhatsApp al Manager y log "Alerta crítica enviada…" |
| `abierta` | `en_proceso` | "Pasar a en proceso" | Si no tiene responsable, se asigna al usuario actual |
| `abierta`/`en_proceso` | `resuelta` | "Registrar resolución" (texto obligatorio) | `resolution`, `resolved_at` |
| ≠ `cerrada` | `cerrada` | "Cerrar incidencia" | `alta`/`critica` exigen resolución; `critica` exige ≥1 evidencia; `baja`/`media` sin resolución exigen comentario de cierre (se guarda como resolución); si `critica`: push al Manager |
| `cerrada` | `en_proceso` | "Reabrir" (motivo obligatorio) | `closed_at = null`; audit `reabrir` |
| abierta/en proceso | (misma) | Asignar, Reclasificar, Comentar, Adjuntar evidencia | Escalar a `critica` dispara alerta a gerencia; Asignar/Reclasificar deshabilitados si resuelta o cerrada |

### 4.6 Rooming list (`ops_rooming_lists.status`) y habitación

| Desde | Hacia | Quién (`reservations.modify`) | Condición / efectos |
|---|---|---|---|
| — | `borrador` | "Nueva rooming list" | Reserva sin rooming, no cancelada/completada; hotel activo tipo Hotel; check-out > check-in |
| `borrador` | `borrador` | Agregar/editar habitación | Sin conflictos bloqueantes |
| `confirmada`/`enviada` | `modificada` | Agregar/editar/eliminar habitación | Toast "Reconfirme y reenvíe al hotel" |
| cualquiera | `confirmada` | "Confirmar rooming" | Sin conflictos |
| `confirmada`/`enviada` | `enviada` | "Enviar al hotel" | Sin conflictos; si está en `borrador`/`modificada` → aviso "Confirme… antes de enviarla"; email al hotel con PDF; `sent_at` |
| `enviada` | (ack) | Extranet "Acusar recibo" | `hotel_ack_at` |
| `enviada` | (confirmada por hotel) | Extranet "Confirmar habitaciones" | Habitaciones no observadas → `confirmada`; `hotel_confirmed_at` |

Habitación: `pendiente`/`confirmada`/`observada` (editable en web; extranet pasa a `observada` al reportar observación y crea incidencia).

### 4.7 Recurso (guía/conductor/vehículo)

Cambio manual desde "Asignaciones y recursos" (`operations.assign`): guía/conductor `disponible ↔ asignado ↔ descanso ↔ vacaciones`; vehículo `disponible ↔ asignado ↔ mantenimiento`. Audit `cambio_estado`. Ver §12 (derivar `asignado`).

### 4.8 Acción móvil en outbox (PROC-058)

| Estado | Significado | Transición |
|---|---|---|
| `pending` | Guardada localmente sin conexión (ya aplicada en UI local) | → `syncing` al reconectar |
| `syncing` | Enviándose (incrementa `attempts`) | → `synced` / `conflict` / `failed` |
| `conflict` | El servidor aplicó una regla de conflicto; se muestra el mensaje | → `synced` (resuelto automáticamente por regla, marcado "resuelto") |
| `failed` | Rechazo definitivo (4xx de negocio) o reintentos agotados | → `pending` si el usuario reintenta; si 4xx de negocio, se revierte el cambio local |
| `synced` | Confirmada por el servidor | Se mueve al historial (máx. 30) |

---

## 5. Reglas de negocio

| ID | Regla | Dónde se valida |
|---|---|---|
| PROC-031 / OPS-BR-01 | Solo una reserva en estado `confirmada` o `en_operacion` genera orden operativa ("Solo una reserva confirmada genera orden operativa") | FormRequest + Service `CreateOperationOrder` |
| OPS-BR-02 | Una reserva tiene como máximo una orden operativa; candidatas = sin orden, no `cancelada`/`completada` | Constraint DB + Service |
| OPS-BR-03 | Al crear la orden se replican como asignaciones los servicios no cancelados, con proveedor y que requieren recursos (prototipo: excluye por nombre hotel/"hab."/entrada; producción: `requires_assignment`) | Service |
| OPS-BR-04 | Coordinador obligatorio, usuario activo con rol operations/manager/tenant_admin; por defecto el usuario actual si es Operaciones | FormRequest |
| OPS-BR-05 | Transiciones de orden según §4.1; "Marcar como asignada" bloqueada con asignaciones pendientes; "Confirmar operación" bloqueada con asignaciones sin confirmar por el recurso o servicios `pendiente`/`solicitado` | Service (state machine) → 409 |
| OPS-BR-06 | Iniciar ejecución (web o móvil) pasa la reserva de `confirmada` a `en_operacion` | Service + listener |
| OPS-BR-07 | Las tareas no bloquean el cierre; se registra quién y cuándo las completó | Service |
| PROC-032 / OPS-BR-10 | Solo servicios `pendiente` pueden enviarse al proveedor; registra solicitud y notifica por email | Service |
| OPS-BR-11 | Confirmación de proveedor exige código de confirmación | FormRequest (`required`) |
| OPS-BR-12 | Rechazo de proveedor exige motivo; solo desde `solicitado` | FormRequest + Service |
| OPS-BR-13 | Un servicio no puede marcarse completado antes de su fecha ("no puede completarse antes de su fecha (PROC-036)") | Service |
| PROC-033 / RT-12 / OPS-BR-20 | Asignación requiere al menos un recurso y servicio no cancelado | FormRequest + Service |
| OPS-BR-21 | **Bloqueo**: mismo recurso con otro servicio de **otra orden o salida programada** el mismo día con diferencia < 6 h (360 min) | Service `AssignmentValidator` + lock Redis por recurso+fecha |
| OPS-BR-22 | **Advertencia**: mismo recurso en la misma orden < 6 h ("verifique el encadenamiento") o cualquier otro servicio el mismo día ≥ 6 h ("verifique tiempos de retorno") | Service |
| OPS-BR-23 | Se ignoran ocupaciones de asignaciones `completada` y de órdenes `cancelada` | Vista `ops_resource_occupations` |
| OPS-BR-24 | Guía/conductor en `vacaciones` → bloqueo; en `descanso` → advertencia | Service |
| OPS-BR-25 | Idiomas requeridos por los pasajeros no cubiertos por el guía → advertencia (prototipo deriva DE/FR/PT de la nacionalidad y "alemán" de las notas) | Service |
| OPS-BR-26 | Licencia del conductor vencida a la fecha del servicio → bloqueo; vence en ≤ 30 días desde hoy → advertencia | Service |
| OPS-BR-27 | Vehículo en `mantenimiento` → bloqueo; capacidad < nº pasajeros → bloqueo; SOAT vencido a la fecha del servicio → bloqueo; SOAT ≤ 30 días → advertencia | Service |
| OPS-BR-28 | Con advertencias, la asignación exige aceptación explícita ("Asumo las advertencias…") que se guarda en `warnings_ack` y en auditoría | FormRequest (`ack_warnings=true`) |
| OPS-BR-29 | Liberar recursos notifica por WhatsApp a cada recurso y vuelve la asignación a `pendiente` | Service + listener |
| PROC-034 / RT-13 / OPS-BR-30 | Un pasajero no puede figurar en más de una habitación del mismo intervalo (misma lista u otra lista del mismo o distinto hotel con fechas superpuestas) | Constraint EXCLUDE + Service (mensaje legible) |
| OPS-BR-31 | Número de habitación único en la lista y no asignado a otra reserva del mismo hotel en fechas superpuestas | Constraint UNIQUE/EXCLUDE |
| OPS-BR-32 | Ocupación ≤ capacidad por tipo (Simple 1, Doble 2, Doble twin 2, Matrimonial 2, Triple 3, Junior suite 2) | Service |
| OPS-BR-33 | Advertencias no bloqueantes: habitación vacía, pasajeros sin habitación, habitaciones observadas por el hotel | Service (respuesta `warnings`) |
| OPS-BR-34 | Guardar/confirmar/enviar bloqueados si hay conflictos; enviar exige estado `confirmada` o `enviada` | Service → 409/422 |
| OPS-BR-35 | Toda modificación de una lista `confirmada`/`enviada` la pasa a `modificada` (requiere reconfirmar y reenviar) | Service |
| OPS-BR-36 | Nueva rooming list: hotel activo de tipo Hotel, check-out > check-in, reserva sin rooming y no cancelada/completada | FormRequest |
| PROC-035 / RT-11 / OPS-BR-40 | Solo servicios `confirmado` o `completado` emiten voucher | Service |
| OPS-BR-41 | Un único voucher vigente (`emitido`) por servicio; para cambios usar "Nueva versión" | Constraint + Service |
| OPS-BR-42 | No se emite voucher si el proveedor no está `activo` (contrato no vigente) | Service |
| OPS-BR-43 | Advertencias: servicio confirmado sin código de confirmación; servicio ya ejecutado (voucher como respaldo) | Service |
| OPS-BR-44 | Nueva versión exige motivo y servicio aún confirmado/completado; incrementa `version`, conserva el código y guarda snapshot | Service |
| OPS-BR-45 | Reenviar, versionar, marcar utilizado y anular solo en vouchers `emitido` | Policy/Service |
| OPS-BR-46 | Anular exige motivo; el voucher queda en historial (sin borrado) y se notifica al proveedor | FormRequest + Service |
| OPS-BR-47 | Emisión solo para reservas no `cancelada`/`borrador`; `pax` = nº de pasajeros de la reserva | Service |
| PROC-036 / OPS-BR-50 | En la app, el check-in se habilita solo con el servicio `en_curso` | Service (móvil valida también en backend) |
| OPS-BR-51 | Finalizar exige ≥ 1 check-in y ≥ 1 evidencia del servicio; los pasajeros sin check-in quedan como no-show (se informa antes de confirmar) | Service |
| OPS-BR-52 | El recurso solo ve y opera asignaciones donde es guía o conductor (acceso a otra asignación → vuelve a "Hoy") | Policy `AssignmentPolicy::operate` |
| PROC-037 / OPS-BR-60 | SLA por severidad: crítica 4 h, alta 8 h, media 24 h, baja 72 h (horas corridas desde el registro) | Service + Job SLA |
| OPS-BR-61 | Incidencia `critica` (al crear o al reclasificar) alerta a gerencia por push y WhatsApp y queda registrado en bitácora | Listener `notifications` |
| OPS-BR-62 | Cierre: `alta`/`critica` requieren resolución; `critica` requiere ≥ 1 evidencia; `baja`/`media` sin resolución requieren comentario de cierre | Service → 422 con code |
| OPS-BR-63 | Reabrir exige motivo y vuelve a `en_proceso` | FormRequest |
| OPS-BR-64 | Título y descripción obligatorios (web); en móvil el título es obligatorio y la descripción por defecto = título | FormRequest |
| OPS-BR-65 | Responsable por defecto: coordinador de la orden | Service |
| PROC-038 / OPS-BR-70 | Evidencias: imagen o PDF, asociadas a servicio (opcional) o incidencia; retiro lógico con motivo obligatorio y auditoría | FormRequest + Service |
| OPS-BR-71 | Deduplicación por hash SHA-256 por orden | Constraint |
| PROC-039 / OPS-BR-80 | Precondición 1: todos los servicios no cancelados con proveedor en `completado` | Service `CloseOperation` |
| OPS-BR-81 | Precondición 2: ninguna incidencia de la orden en `abierta`/`en_proceso` (resuelta o cerrada = gestionada) | Service |
| OPS-BR-82 | Precondición 3: la orden tiene ≥ 1 evidencia y ninguna incidencia `alta`/`critica` sin evidencia | Service |
| OPS-BR-83 | Precondición 4: todos los servicios con proveedor tienen costo > 0 | Service |
| OPS-BR-84 | Al cerrar: orden `completada`, reserva `completada`, notificar equipo y cliente, costos a liquidación/rentabilidad | Service + eventos |
| PROC-058 / RT-19 / RT-22 / MOB-BR-01 | Toda acción móvil lleva `client_action_id` (UUID v7) y es idempotente en servidor | Middleware + `ops_sync_actions` |
| MOB-BR-02 | La app aplica la acción localmente (optimista) y la encola si no hay red; los efectos secundarios (auditoría, notificaciones) ocurren solo al sincronizar | App + Service |
| MOB-BR-03 | Coalescencia local: para `confirm` y `checkin` con la misma clave `tipo:orden:servicio:pasajero` solo se conserva la última acción pendiente | App (SQLite) |
| MOB-BR-04 | Reintentos con backoff exponencial y jitter; tras el máximo se marca `failed` | App |
| MOB-BR-05 | Conflicto `confirm`: si ya estaba confirmada en web, se descarta el duplicado sin error | Service |
| MOB-BR-06 | Conflicto `checkin`/`checkin_all`: idempotencia por pasajero + servicio; se conserva la marca de tiempo más temprana | Service + constraint |
| MOB-BR-07 | Conflicto `start`: el backend es fuente de verdad de la planificación, el dispositivo de los hechos de campo; se conserva la hora real de inicio aunque la hora planificada haya cambiado | Service |
| MOB-BR-08 | Conflicto `finish`: la transición de campo prevalece si es válida en la máquina de estados (PROC-036) | Service |
| MOB-BR-09 | Conflicto `evidence`: mismo hash ya existente → se asocia la evidencia existente | Service + constraint |
| MOB-BR-10 | Acción inválida en la máquina de estados (p. ej. `finish` sobre asignación liberada o reasignada a otro recurso) → `rejected`, la app revierte el cambio local y muestra el motivo | Service |
| MOB-BR-11 | Conflicto `incident`: si el coordinador registró una incidencia similar (misma orden y tipo) en los últimos 10 min, no se fusiona: se crea vinculada (`related_incident_id`) y se notifica para revisión | Service |
| MOB-BR-12 | El móvil no replica reglas de negocio: valida UX (botones por estado) pero la autoridad es la API | Arquitectura |
| MOB-BR-13 | Usuario externo sin recurso vinculado ve "Su usuario no está vinculado a un recurso operativo…" | API 403 + app |
| PAX-BR-01 | El pasajero solo ve su reserva (`users.reservation_id`/vínculo pasajero); sin vínculo: "No encontramos un viaje vinculado a su cuenta" | Policy |
| PAX-BR-02 | La app del pasajero muestra solo vouchers no anulados y servicios no cancelados; el itinerario excluye alojamiento como actividad y muestra la noche en hotel y habitación | API Resource |
| PAX-BR-03 | Próxima actividad = primer servicio con proveedor, con hora, no completado, posterior a ahora | API |
| RT-16 / OPS-BR-90 | Todas las acciones críticas (§9) se auditan | Listener audit |
| RT-17 / OPS-BR-91 | Botones sin permiso se muestran deshabilitados con `title="Requiere permiso x.y"`; si llega por otra vía: toast "Su rol (…) no tiene el permiso …" y no modifica datos | Next.js + API 403 |

---

### v2.1 — Reglas
| ID | Regla | Dónde |
|---|---|---|
| OPS-V21-01 | El recojo aplica al servicio con `is_pickup=true`; si ninguno lo tiene, al primer servicio del primer día. `meetingPoint` devuelve el punto/dirección del cliente para ese servicio | Service `MeetingPointResolver` |
| OPS-V21-02 | Prellenado de rooming: por cada leg de `booking.lodging` sin rooming, una lista `borrador` con check-in = fecha de salida + noches acumuladas de legs anteriores; habitaciones numeradas provisionalmente (`LIM-1`…), estado `pendiente`; adultos repartidos entre habitaciones, luego niños, infantes con el adulto de la habitación menos ocupada | `RoomingPrefillService` (idempotente por leg) |
| OPS-V21-03 | Infantes no ocupan cama (R-INFANT-FREE/R-LODGING-CAPACITY) | `validateRooming` |
| OPS-V21-04 | Alertas operativas derivadas de `comments` y `diet_notes` (dieta/alergia, celebración, accesibilidad, infante) se muestran en orden, app y voucher; no se almacenan diagnósticos (solo el texto del cliente) | Resource |

## 6. API REST

Prefijo `/api/v1`. Recursos con `lock_version` requieren `If-Match`. "Idem" = requiere `Idempotency-Key`.

### 6.1 Órdenes y tareas

| Método | Endpoint | Permiso | Descripción | Idem/lock |
|---|---|---|---|---|
| GET | `/ops/orders` | operations.view | Lista (`filter[status]`, `filter[coordinator]`, `q`, `sort=date_from`) con agregados (pax, % asignación, incidencias abiertas) | — |
| GET | `/ops/orders/kpis` | operations.view | En ejecución, servicios pendientes de asignación, servicios hoy/mañana, incidencias abiertas/críticas | — |
| GET | `/ops/orders/candidates` | operations.assign | Reservas sin orden, no canceladas/completadas, con estado y validación | — |
| POST | `/ops/orders` | operations.assign | Crear `{reservation_id, coordinator_id}` | Idem |
| GET | `/ops/orders/{id}` | operations.view | Orden 360 (`include=assignments,services,tasks,passengers,rooming,vouchers,incidents,evidences`) | — |
| PATCH | `/ops/orders/{id}/coordinator` | operations.assign | Cambiar coordinador (email al nuevo) | lock |
| POST | `/ops/orders/{id}/to-assignment` | operations.assign | `planificada → pendiente_asignacion` | lock |
| POST | `/ops/orders/{id}/mark-assigned` | operations.assign | `→ asignada` (409 si hay pendientes) | lock |
| POST | `/ops/orders/{id}/confirm` | operations.assign | `→ confirmada` (409 con detalle) | lock |
| POST | `/ops/orders/{id}/start` | operations.assign | `→ en_ejecucion` (+ reserva `en_operacion`) | lock |
| GET | `/ops/orders/{id}/close-checks` | operations.view | 4 precondiciones + tareas pendientes | — |
| POST | `/ops/orders/{id}/close` | operations.close | Cierre PROC-039 | Idem + lock |
| GET | `/ops/orders/{id}/cost-summary` | operations.view | Costos de proveedor; `sale`/`margin` solo si `finance.profitability` \| `catalog.products.view_profitability` \| `quotes.approve_internal` | — |
| POST | `/ops/orders/{id}/tasks` | operations.assign | Agregar tarea | — |
| PATCH | `/ops/order-tasks/{id}` | operations.assign | `{done}` (registra by/at) | — |
| DELETE | `/ops/order-tasks/{id}` | operations.assign | Eliminación lógica + audit | — |

### 6.2 Servicios y solicitudes a proveedor

| Método | Endpoint | Permiso | Descripción | Idem/lock |
|---|---|---|---|---|
| POST | `/ops/reservation-services/{id}/supplier-request` | operations.assign | Enviar solicitud (`pendiente → solicitado`), email proveedor | Idem |
| POST | `/ops/reservation-services/{id}/supplier-confirmation` | operations.assign | `{confirmation_code, note, time?}` | lock |
| POST | `/ops/reservation-services/{id}/supplier-rejection` | operations.assign | `{reason}` | lock |
| POST | `/ops/reservation-services/{id}/complete` | operations.assign | Completar (fecha ≤ hoy) | lock |
| GET | `/ops/supplier-requests` | operations.view | Solicitudes y respuestas (incluye alternativas de extranet) | — |

### 6.3 Asignaciones y recursos

| Método | Endpoint | Permiso | Descripción | Idem/lock |
|---|---|---|---|---|
| GET | `/ops/assignments` | operations.view | `filter[incomplete]=true&filter[from]=today` (asignación rápida), `filter[resource]` | — |
| POST | `/ops/orders/{id}/assignments` | operations.assign | Crear asignación para un servicio que no la tenía (`ensureAssignment`) | — |
| POST | `/ops/assignments/{id}/validate` | operations.assign | Dry-run `{guide_id, driver_id, vehicle_id}` → `{blocks[], warnings[]}` (para validación en vivo del modal) | — |
| PUT | `/ops/assignments/{id}/resources` | operations.assign | Asignar/reasignar `{guide_id, driver_id, vehicle_id, ack_warnings, notify:{push, whatsapp}}`; 409 con `blocks` | lock + lock Redis |
| POST | `/ops/assignments/{id}/confirm` | operations.assign | Registrar confirmación del recurso | lock |
| POST | `/ops/assignments/{id}/release` | operations.assign | Liberar recursos | lock |
| GET | `/ops/resources` | operations.view | `filter[type]=guide|driver|vehicle`, con ocupación 7 días | — |
| GET | `/ops/resources/availability` | operations.view | `from`, `days=7` → matriz recurso × día | — |
| GET | `/ops/resources/document-alerts` | operations.view | Licencias y SOAT ≤ 30 días | — |
| GET | `/ops/resources/{type}/{id}/agenda` | operations.view | Ocupaciones desde hoy | — |
| PATCH | `/ops/resources/{type}/{id}/status` | operations.assign | Cambiar estado | — |
| GET | `/ops/calendar` | operations.view | `from`, `to`, `filter[kind]` → eventos (servicios + salidas) con flag `pending` | — |

CRUD de guías/conductores/vehículos: no existe en el prototipo (solo cambio de estado); ver §12.

### 6.4 Rooming

| Método | Endpoint | Permiso | Descripción | Idem/lock |
|---|---|---|---|---|
| GET | `/ops/rooming-lists` | operations.view | Listas con estado y nº habitaciones | — |
| POST | `/ops/rooming-lists` | reservations.modify | Crear `{reservation_id, hotel_provider_id, check_in, check_out}` | — |
| GET | `/ops/rooming-lists/{id}` | operations.view | Detalle + validación | — |
| POST | `/ops/rooming-lists/{id}/validate` | reservations.modify | Dry-run de una distribución propuesta | — |
| POST | `/ops/rooming-lists/{id}/rooms` | reservations.modify | Agregar habitación `{room_number, room_type, status, passenger_ids[]}` | lock (lista) |
| PUT | `/ops/rooming-rooms/{id}` | reservations.modify | Editar habitación | lock (lista) |
| DELETE | `/ops/rooming-rooms/{id}` | reservations.modify | Eliminar habitación (lista → `modificada`) | lock (lista) |
| POST | `/ops/rooming-lists/{id}/confirm` | reservations.modify | Confirmar | lock |
| POST | `/ops/rooming-lists/{id}/send` | reservations.modify | Enviar al hotel (email + PDF) | Idem + lock |
| GET | `/ops/rooming-lists/{id}/document` | operations.view | Vista previa / PDF | — |

### 6.5 Vouchers

| Método | Endpoint | Permiso | Descripción | Idem/lock |
|---|---|---|---|---|
| GET | `/ops/vouchers` | operations.view | Lista (`filter[status]`, `filter[provider]`) + KPIs | — |
| GET | `/ops/vouchers/pending-issue` | operations.view | Servicios confirmados sin voucher vigente | — |
| POST | `/ops/vouchers/check` | operations.vouchers | Dry-run `{reservation_service_id}` → `{blocks, warnings}` | — |
| POST | `/ops/vouchers` | operations.vouchers | Emitir `{reservation_service_id, note}` | Idem |
| GET | `/ops/vouchers/{id}` | operations.view | Detalle + versiones + notificaciones | — |
| GET | `/ops/vouchers/{id}/pdf` | operations.view | URL firmada del PDF vigente | — |
| POST | `/ops/vouchers/{id}/resend` | operations.vouchers | Reenviar al cliente | Idem |
| POST | `/ops/vouchers/{id}/versions` | operations.vouchers | Nueva versión `{reason}` | Idem + lock |
| POST | `/ops/vouchers/{id}/redeem` | operations.vouchers | Marcar utilizado | Idem + lock |
| POST | `/ops/vouchers/{id}/void` | operations.vouchers | Anular `{reason}` | Idem + lock |

### 6.6 Incidencias y evidencias

| Método | Endpoint | Permiso | Descripción | Idem/lock |
|---|---|---|---|---|
| GET | `/ops/incidents` | operations.view | Lista (`filter[status|severity|type]`) + KPIs (SLA vencido) | — |
| POST | `/ops/incidents` | operations.incidents | Crear `{order_id, type, severity, assignee_id, title, description, evidences[]}` | Idem |
| GET | `/ops/incidents/{id}` | operations.view | Detalle + bitácora + SLA + notificaciones | — |
| POST | `/ops/incidents/{id}/take` | operations.incidents | `abierta → en_proceso` | lock |
| PATCH | `/ops/incidents/{id}/assignee` | operations.incidents | Asignar (push al responsable) | lock |
| PATCH | `/ops/incidents/{id}/classification` | operations.incidents | `{severity, type}` (escalada a crítica → alerta) | lock |
| POST | `/ops/incidents/{id}/resolve` | operations.incidents | `{resolution}` | lock |
| POST | `/ops/incidents/{id}/close` | operations.incidents | `{closing_comment?}` | lock |
| POST | `/ops/incidents/{id}/reopen` | operations.incidents | `{reason}` | lock |
| POST | `/ops/incidents/{id}/comments` | operations.incidents | Comentario en bitácora | — |
| POST | `/ops/incidents/{id}/evidences` | operations.incidents | Asociar evidencias subidas | — |
| POST | `/ops/evidences/presign` | operations.assign \| operations.incidents \| rol guide/driver | URL S3 prefirmada `{file_name, mime, size, sha256}` | — |
| POST | `/ops/orders/{id}/evidences` | operations.assign | Registrar evidencias subidas `{reservation_service_id?, uploads[]}` | Idem |
| DELETE | `/ops/evidences/{id}` | operations.assign | Retiro lógico `{reason}` | — |

### 6.7 App operador (`/mobile/operator`, token Sanctum de guía/conductor; Policy por recurso)

| Método | Endpoint | Permiso | Descripción | Idem/lock |
|---|---|---|---|---|
| POST | `/mobile/devices` | autenticado | Registrar dispositivo y `expo_push_token` | — |
| DELETE | `/mobile/devices/{id}` | autenticado | Cerrar sesión del dispositivo | — |
| GET | `/mobile/operator/me` | operations.view + vínculo recurso | Perfil del recurso (licencia/carné, vencimientos) | — |
| GET | `/mobile/operator/bootstrap` | ídem | Delta sync `?since={cursor}`: asignaciones del recurso en ventana [-2 d, +14 d], pasajeros, punto de encuentro, compañero, coordinador, notas/dietas, incidencias abiertas, evidencias; tombstones de asignaciones quitadas | — |
| GET | `/mobile/operator/assignments/{id}` | ídem + AssignmentPolicy | Detalle | — |
| POST | `/mobile/operator/actions` | ídem | Una acción `{client_action_id, type, assignment_id, occurred_at, offline, payload}` → `{result, conflict_rule?, message?, assignment}` | Idempotente por `client_action_id` |
| POST | `/mobile/operator/sync` | ídem | Lote ordenado de acciones del outbox (máx. 50) → resultado por acción | Idempotente por acción |
| GET | `/mobile/operator/incidents` | ídem | Incidencias de sus órdenes o reportadas por él | — |
| POST | `/mobile/operator/incidents` | operations.incidents + vínculo | Alternativa directa a `actions` (type=`incident`) | Idem |

Acciones (`type`): `confirm`, `start`, `checkin {passenger_id, undo}`, `checkin_all`, `evidence {upload_id, sha256}`, `incident {type, severity, title, description, upload_ids[]}`, `finish`.

### 6.8 App pasajero (`/mobile/passenger`, rol passenger; también la consume el portal del pasajero)

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/mobile/passenger/trip` | reservations.view + vínculo | Resumen, estado del viaje, próxima actividad, hotel/habitación |
| GET | `/mobile/passenger/itinerary` | ídem | Días y servicios (punto de encuentro, proveedor, código, guía/conductor, incluye) |
| GET | `/mobile/passenger/vouchers` | ídem | Vouchers no anulados |
| GET | `/mobile/passenger/vouchers/{id}` | ídem | Detalle + `qr_token` |
| GET | `/mobile/passenger/documents` | ídem | Documentos vinculados (RT-23) |
| GET | `/mobile/passenger/documents/{id}/download` | ídem | URL firmada |
| GET | `/mobile/passenger/alerts` | ídem | Alertas + leídas |
| POST | `/mobile/passenger/alerts/{id}/read` | ídem | Marcar leída |
| GET/POST | `/mobile/passenger/messages` | ídem | Chat con la agencia |
| GET | `/mobile/passenger/support` | ídem | Número de emergencia 24/7 del tenant/sede |

---

### 6.9 v2.1
| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| POST | `/api/v1/operations/rooming/prefill` | reservations.modify | `{reservation}` → crea las rooming lists faltantes desde `booking.lodging` | `Idempotency-Key`; único por leg |
| GET | `/api/v1/operations/orders/{code}` | operations.view | Ahora incluye `client_pickup {point, address, flight, arrival, service_id}`, `client_comments`, `alert_tags[]` | — |
| GET | `/mobile/operator/assignments/{id}` | guía/conductor | Incluye recojo del cliente y comentarios | — |
| GET | `/mobile/passenger/messages` · POST `/mobile/passenger/messages/{id}/read` | passenger | Bandeja del pasajero (R-PAX-MESSAGES) | — |
| POST | `/mobile/passenger/feedback` | passenger | Crea comentario (R-PAX-FEEDBACK); reclamo/incidencia abre conversación | `Idempotency-Key` |

## 7. Permisos y comportamiento por rol

Permisos de módulo (`data.js`): `operations.view`, `operations.assign`, `operations.vouchers`, `operations.incidents`, `operations.close`; más `reservations.modify` (rooming) y `finance.profitability` / `catalog.products.view_profitability` / `quotes.approve_internal` (ver margen).

| Rol | Permisos relevantes | Qué ve | Qué hace | Alcance |
|---|---|---|---|---|
| Tenant Admin | Todos | Todo el módulo, resumen económico con venta y margen en Cierre | Todas las acciones; vista previa de apps con selector "Ver como recurso" | Todo el tenant |
| Manager | Todos los `operations.*`, `reservations.modify`, `finance.profitability` | Igual que Tenant Admin | Todas las acciones; recibe alertas críticas (push + WhatsApp) | Todo el tenant |
| Operaciones | `operations.*` (view, assign, vouchers, incidents, close, travel_docs), `reservations.modify`, `catalog.providers.manage` | Todo el módulo; **costos de proveedor sí, precios de venta y márgenes no** (Cierre muestra tabla "Costos de proveedores" y nota "Precios de venta y márgenes visibles solo para Gerencia y Finanzas") | Todas las acciones | Todo el tenant (no aplica cartera) |
| Ventas, Finanzas, Contabilidad | Sin `operations.view` | Nada del módulo (menú oculto; URL → 403 auditado). Enlaces a órdenes/incidencias desde otros módulos se muestran como texto plano (`lnk()`) | — | — |
| Guía / Conductor | `operations.view`, `operations.incidents` (externos) | Solo `#/mobile/operador` con SU recurso (`users.guide_id`/`driver_id`); sin panel explicativo ni selector; chip de red lleva a Perfil | Confirmar, iniciar, check-in, evidencia, incidencia, finalizar **solo en sus asignaciones** | Asignaciones donde es guía o conductor; incidencias de esas órdenes o reportadas por él |
| Pasajero | `reservations.view` (externo) | `#/mobile/pasajero` y portal del pasajero con SU reserva | Leer alertas, chatear, llamar emergencia | Su reserva |
| Proveedor | `operations.view` (externo) | Solo extranet (doc 06); `#/proveedor` interno bloqueado (`__solo_extranet__`) | — | Su proveedor |
| Super Admin | — | Bloqueado (403, aislamiento multi-tenant) | — | — |

Controles por botón (UI deshabilitada con `title="Requiere permiso …"`; API 403):

| Elemento | Permiso |
|---|---|
| Nueva orden, Coordinador, avanzar estado, Asignar/Reasignar, menú "…" (solicitud, confirmación/rechazo proveedor, confirmación recurso, liberar, completar), tareas (check, agregar, eliminar), registrar/quitar evidencia, estado de recurso, asignación rápida, "Asignar recursos" en calendario | `operations.assign` |
| Emitir voucher, Reenviar, Nueva versión, Marcar utilizado, Anular (y opción "Emitir voucher" del menú) | `operations.vouchers` (sin él: alerta "Su rol puede consultar el voucher pero no emitir…") |
| Nueva incidencia, Pasar a en proceso, Asignar, Reclasificar, Resolución, Cerrar, Reabrir, Comentar, Adjuntar evidencia | `operations.incidents` |
| Ir a cierre / Cerrar operación | `operations.close` (sin él: alerta "Su rol no tiene el permiso operations.close") |
| Nueva rooming list, Agregar/Editar/Eliminar habitación, Confirmar, Enviar al hotel | `reservations.modify` ("Vista previa" siempre visible) |
| Resumen económico (venta + margen) vs tabla de costos | `finance.profitability` \| `catalog.products.view_profitability` \| `quotes.approve_internal` |
| Menú del servicio sin acciones permitidas | Solo "Ver en calendario", "Abrir producto"/"Abrir proveedor" si puede abrir la ruta |
| Rutas `#/mobile/*` internas | `operations.assign` (el grupo de menú "Móvil" se muestra con `operations.view` pero cada ítem se filtra por ruta) |

---

## 8. Eventos, jobs y notificaciones

### 8.1 Eventos de dominio

| Evento | Origen | Listeners (cola) |
|---|---|---|
| `OperationOrderCreated` | Crear orden | Email coordinador (`notifications`); audit |
| `OperationStatusChanged` | Transiciones §4.1 | Reserva `en_operacion`/`completada` (`critical`); Reverb tablero ops |
| `SupplierRequestSent` / `SupplierConfirmed` / `SupplierRejected` / `SupplierAlternativeProposed` | PROC-032 | Email proveedor / coordinador; portal extranet; Reverb |
| `ServiceAssigned` (`ResourcesAssigned`) | Asignar | Push (Expo) + WhatsApp al recurso según casillas; invalidación de caché de ocupación |
| `AssignmentConfirmed`, `AssignmentReleased` | Web/móvil | Push coordinador / WhatsApp recurso |
| `ServiceStarted`, `PassengerCheckedIn`, `ServiceFinished` | Móvil | Push coordinador; Reverb orden 360; alerta al pasajero (opcional, §12) |
| `EvidenceUploaded` | Web/móvil/extranet | Job de procesamiento (`documents`) |
| `VoucherIssued`, `VoucherVersioned`, `VoucherVoided`, `VoucherRedeemed` | PROC-035 | PDF (`documents`); email cliente, push pasajeros, email proveedor; portal |
| `IncidentCreated`, `IncidentEscalatedCritical`, `IncidentAssigned`, `IncidentResolved`, `IncidentClosed`, `IncidentReopened` | PROC-037 | Email coordinador; crítica → push + WhatsApp Manager (cola `critical`); push responsable |
| `RoomingListConfirmed`, `RoomingListSent` | PROC-034 | PDF + email hotel |
| `OperationClosed` | PROC-039 | Finanzas: habilita liquidación de CxP y rentabilidad; email coordinador; notificación portal cliente ("Su viaje … ha finalizado. Cuéntenos su experiencia"); CRM postventa |
| `MobileSyncConflictResolved` | Sync | Audit + métrica |

### 8.2 Notificaciones (canal → destinatario) según prototipo

| Evento (`notif.event`) | Canal | Destinatario |
|---|---|---|
| `orden.creada`, `orden.responsable` | Email | Coordinador |
| `proveedor.solicitud` | Email | Proveedor |
| `proveedor.rechazo` | Email | Coordinador |
| `asignacion.nueva` | Push + WhatsApp (casillas) | Guía y conductor |
| `asignacion.liberada` | WhatsApp | Guía y conductor |
| `asignacion.confirmada`, `servicio.iniciado`, `servicio.finalizado` | Push | Coordinador |
| `incidencia.creada` | Email | Coordinador |
| `incidencia.critica` | Push + WhatsApp | Manager |
| `incidencia.asignada` | Push | Responsable |
| `incidencia.cerrada` (crítica) | Push | Manager |
| `voucher.emitido` | Email cliente, Push pasajeros, Email proveedor | |
| `voucher.reenviado` | Email | Cliente |
| `voucher.actualizado` | Email cliente + Push pasajeros | |
| `voucher.anulado` | Email | Proveedor |
| `rooming.enviado` | Email (PDF adjunto) | Hotel |
| `operacion.cerrada` | Email coordinador + Portal cliente | |

La matriz evento × canal es configurable (`admin/notificaciones`: Asignación = WhatsApp+Push; Incidencia crítica = Email+WhatsApp+Push; Voucher emitido = Email+Push+Portal; Cambio de horario = todos).

### 8.3 Jobs y scheduler

| Job | Frecuencia | Cola | Descripción |
|---|---|---|---|
| `ExpireUnconfirmedAssignments` (JOB-09) | cada 30 min | default | Asignaciones `asignada` no confirmadas dentro de X h antes del servicio → alerta al coordinador (regla a definir, §12) |
| `DetectPendingSupplierServices` (JOB-06) | cada 2 h | default | Servicios `pendiente`/`solicitado` próximos → alerta/tarea |
| `NotifyUpcomingTrips` (JOB-05) | diario 05:00 | notifications | Recordatorios 48 h a pasajeros (alertas app) |
| `IncidentSlaMonitor` | cada 5 min | critical | Marca SLA vencido, notifica responsable y Manager |
| `ResourceDocumentAlerts` | diario | notifications | Licencias y SOAT ≤ 30 días |
| `GenerateVoucherPdf`, `GenerateRoomingPdf` | on-demand | documents | Browsershot; versionado en `sys_documents` con hash |
| `ProcessEvidence` | on-demand | documents | Validar MIME/tamaño, EXIF/rotación, miniatura, hash |
| `SendExpoPush` | on-demand | notifications | Expo Push API; manejo de tickets/receipts y tokens inválidos (desactiva `expo_push_token`) |
| `PurgeMobileSyncLog` | diario | low | Retención de `ops_sync_actions` (90 días propuesto) |

Tiempo real (Reverb): canal `tenant.{t}.ops` para tablero de órdenes, calendario y orden 360; `tenant.{t}.user.{u}` para notificaciones.

### 8.4 Apps móviles: arquitectura, almacenamiento local, outbox, idempotencia, conflictos y push (React Native / Expo)

#### 8.4.1 Stack

| Elemento | Decisión |
|---|---|
| Framework | React Native + **Expo** (SDK estable vigente, Expo Router, TypeScript), EAS Build/Submit, EAS Update para parches JS (respetando `app_version` mínima) |
| Apps | Dos binarios o un binario con dos "experiencias" según rol: **Operador** (guía/conductor) y **Pasajero**. Recomendado: dos apps (permisos de cámara/background distintos, tiendas y branding por tenant vía `app.config.ts`) |
| Estado servidor | TanStack Query (persistencia en SQLite para lecturas offline) |
| Almacenamiento local | **expo-sqlite** (SQLite, WAL). Migraciones versionadas en la app |
| Secretos | **expo-secure-store**: token Sanctum, `device_id` |
| Archivos | expo-file-system (cola de fotos), expo-image-picker / expo-camera, expo-image-manipulator (máx. 1920 px, JPEG q=0.7), expo-crypto (SHA-256) |
| Red | @react-native-community/netinfo (detección online/offline) |
| Background | expo-background-fetch + expo-task-manager (intento de sync cada ≥ 15 min y al recuperar red) |
| Push | **expo-notifications** (FCM/APNs) + Expo Push API desde Laravel (job `SendExpoPush`); deep links `qhapaq-ops://assignments/{id}`, `qhapaq-trip://alerts/{id}` |
| Validación | Zod (esquemas espejo de FormRequest) |
| Autenticación | Login email + contraseña (y enlace mágico para pasajero), token Sanctum con abilities `mobile:operator` / `mobile:passenger`; refresh por re-login; revocación remota |

#### 8.4.2 Esquema SQLite local (app operador)

| Tabla | Columnas clave | Uso |
|---|---|---|
| `assignments` | id, order_id, order_code, service_id, date, time, label, status, guide_id, driver_id, vehicle_label, meeting_point, partner_json, coordinator_json, notes, updated_at, local_status | Caché de la ventana [-2 d, +14 d]; `local_status` refleja acciones aún no sincronizadas |
| `passengers` | id, assignment_id, name, doc, diet | Lista de check-in |
| `checkins` | assignment_id, passenger_id, checked_at, pending | Estado local |
| `evidences` | id, assignment_id, local_uri, sha256, size, upload_id, uploaded | Fotos pendientes de subir |
| `incidents` | id?, client_action_id, assignment_id, type, severity, title, status | Propias y de sus órdenes |
| `outbox` | client_action_id PK, type, assignment_id, coalesce_key, payload_json, occurred_at, offline, state (`pending/syncing/synced/conflict/failed`), attempts, next_attempt_at, last_error, conflict_message | Bandeja de salida |
| `sync_history` | client_action_id, type, synced_at, resolved, conflict_message | Últimas 30 |
| `sync_state` | key, value | `cursor`, `last_sync_at`, `device_id` |

#### 8.4.3 Flujo de acción y sincronización

1. Usuario toca una acción → la app valida precondiciones de UX (misma lógica que la API: botón según estado; check-in solo `en_curso`; fin con check-in + evidencia).
2. Se genera `client_action_id` (UUID v7) y `coalesce_key`; en transacción SQLite se aplica el cambio local y se inserta en `outbox` (`pending`). Si existe un `pending` con la misma `coalesce_key` para `confirm`/`checkin`, se reemplaza.
3. Si hay red: se envía de inmediato (`POST /mobile/operator/actions`); si no: queda en cola y el chip muestra "Sin conexión · N".
4. Al reconectar (NetInfo, foreground o background fetch): las acciones `pending` pasan a `syncing` y se envían **en orden** en lotes (`POST /mobile/operator/sync`, máx. 50). Evidencias: primero `POST /ops/evidences/presign` → PUT a S3 → acción `evidence` con `upload_id` y `sha256`.
5. Respuesta por acción: `applied` → `synced`; `duplicate` → `synced` (respuesta cacheada); `conflict_resolved` → mostrar `conflict` con mensaje de la regla y luego `synced` "· resuelto"; `rejected` → `failed`, revertir estado local, mostrar motivo.
6. Errores de red/5xx: backoff exponencial con jitter (2 s, 4 s, 8 s … máx. 5 min), máx. 8 intentos → `failed` con botón "Reintentar".
7. Tras sincronizar: `GET /mobile/operator/bootstrap?since={cursor}` para traer cambios del servidor (reasignaciones, cambios de hora, incidencias) y tombstones; actualizar `last_sync_at`.

#### 8.4.4 Idempotencia y resolución de conflictos (servidor)

| Mecanismo | Detalle |
|---|---|
| Idempotencia | `ops_sync_actions.id = client_action_id`; si existe → devolver `response` guardada (sin reejecutar efectos) |
| Orden causal | El servidor procesa el lote en orden de `occurred_at`; cada acción en su propia transacción con `SELECT … FOR UPDATE` de la asignación |
| Fuente de verdad | Planificación (hora, recursos, servicios) = backend; hechos de campo (inicio, check-in, fin, evidencias) = dispositivo con su marca de tiempo |
| Reglas | MOB-BR-05 a MOB-BR-11 (§5) con `conflict_rule` y mensaje devuelto al cliente |
| Autorización | Cada acción revalida que el recurso autenticado siga asignado; si fue reasignado → `rejected` |
| Efectos | Auditoría (con marca `offline {ts}`) y notificaciones se emiten al aplicar en servidor, nunca desde el dispositivo |

#### 8.4.5 Push

| Evento | Destinatario | Deep link |
|---|---|---|
| Nueva asignación / liberación / cambio de hora | Guía, conductor | Detalle de la asignación |
| Alerta operativa (incidencia crítica en sus órdenes) | Guía, conductor | Pantalla Hoy |
| Voucher emitido/actualizado, cambio de horario, alerta de viaje, respuesta de chat | Pasajero | Vouchers / Alertas / Soporte |
| Confirmación, inicio, fin de servicio | Coordinador (web: Reverb; móvil del coordinador si aplica) | Orden 360 |

Tokens inválidos (receipts `DeviceNotRegistered`) → se anula `expo_push_token`. Si push falla, el canal alternativo es WhatsApp según matriz de notificaciones.

---

## 9. Auditoría

`audit_logs` con actor, tenant, entidad, acción, estado anterior/nuevo, fecha/hora, IP, correlación (PROC-063). Acciones registradas por el prototipo:

| Entidad | Acciones (`action`) |
|---|---|
| Orden operativa | `crear` (reserva / nº servicios), `cambio_estado`, `cambio_responsable`, `eliminar_tarea`, `cierre` |
| Reserva | `cambio_estado` (→ completada al cerrar; → en_operacion al iniciar: agregar) |
| Servicio | `solicitar_proveedor`, `confirmacion_proveedor`, `rechazo_proveedor` (con motivo), `completar` |
| Asignación | `asignar` (antes/después + nº advertencias), `confirmar`, `liberar` |
| Recurso | `cambio_estado` |
| Rooming | `crear`, `agregar_habitacion`, `editar_habitacion` (JSON antes/después), `eliminar_habitacion`, `confirmar`, `enviar_hotel` |
| Voucher | `emitir`, `versionar` (motivo), `reenviar`, `utilizar`, `anular` (motivo) |
| Incidencia | `crear`, `cambio_estado`, `asignar`, `reclasificar`, `resolver`, `cerrar`, `reabrir` |
| Evidencia | `upload`, `retirar` (motivo) |
| App operador | una entrada por acción sincronizada: `confirm`, `start`, `checkin`, `checkinAll`, `evidence`, `incident`, `finish` con marca `offline {ts}` y recurso |
| Acceso | `acceso_denegado` (403 por ruta) |
| Exportación | `exportar` (CSV de tablas) |

---

## 10. Casos de prueba mínimos

### Pest (feature)

| # | Caso |
|---|---|
| T-01 | Tenant A no lista ni abre órdenes/vouchers/incidencias de Tenant B (RLS + scope) |
| T-02 | Ventas/Finanzas/Contabilidad → 403 en `/ops/*`; Operaciones sin `finance.profitability` no recibe `sale`/`margin` en `cost-summary` |
| T-03 | Crear orden con reserva `pendiente_pago` → 422 `PROC-031`; segunda orden de la misma reserva → 409 |
| T-04 | Crear orden replica solo servicios con `requires_assignment`; sin ninguno → `planificada` |
| T-05 | `mark-assigned` con asignaciones pendientes → 409; `confirm` con servicio `solicitado` → 409 |
| T-06 | Asignar guía con otro servicio de otra orden a < 6 h → 409 bloqueo; en la misma orden → warning; sin `ack_warnings` → 422 |
| T-07 | Conductor con licencia vencida a la fecha / vehículo con capacidad < pax / SOAT vencido / mantenimiento / guía en vacaciones → bloqueo |
| T-08 | Asignaciones concurrentes del mismo recurso (2 requests paralelas) → solo una se aplica (lock Redis) |
| T-09 | Liberar → asignación `pendiente`, orden recalculada, WhatsApp encolado |
| T-10 | Rooming: pasajero en dos habitaciones / habitación sobre capacidad / número duplicado / solapamiento con otra reserva del mismo hotel → error; edición de lista enviada → `modificada`; enviar sin confirmar → 409 |
| T-11 | Voucher: servicio `solicitado` → 422 RT-11; segundo voucher vigente → 409; proveedor inactivo → 422; nueva versión incrementa versión y guarda snapshot; anular sin motivo → 422; acciones sobre anulado → 409 |
| T-12 | Idempotencia `POST /ops/vouchers` con misma `Idempotency-Key` → mismo voucher |
| T-13 | Incidencia crítica → notificaciones push + WhatsApp al Manager; cerrar crítica sin evidencia → 422; alta sin resolución → 422; reabrir exige motivo |
| T-14 | SLA: `sla_due_at` recalculado al reclasificar; job marca vencidas |
| T-15 | Cierre: cada una de las 4 precondiciones falla por separado → 422; éxito → orden y reserva `completada` + evento `OperationClosed` |
| T-16 | Móvil: guía no puede actuar sobre asignación de otro recurso → 403 |
| T-17 | Móvil: misma `client_action_id` dos veces → `duplicate` con misma respuesta |
| T-18 | Móvil: `checkin` offline con marca anterior a la existente → se conserva la más temprana (`conflict_resolved`) |
| T-19 | Móvil: `finish` sin check-in o sin evidencia → `rejected`; `finish` sobre asignación liberada → `rejected` |
| T-20 | Móvil: `start` pasa orden a `en_ejecucion` y reserva a `en_operacion` |
| T-21 | Pasajero solo accede a su reserva; vouchers anulados no se devuelven |

### Playwright (E2E)

| Caso | Flujo |
|---|---|
| E2E-03 | Reserva confirmada → crear orden → enviar solicitud → confirmar proveedor → asignar recursos (con advertencia asumida) → emitir voucher → ver en app pasajero |
| E2E-04 | Asignación → app operador: confirmar → iniciar → check-in → evidencia → finalizar → orden 360 muestra completado |
| E2E-07 | App operador offline: 3 acciones en outbox → reconectar → Synced, conflicto resuelto mostrado |
| E2E-10 | Incidencia crítica desde app → alerta gerencia → resolución + evidencia → cierre → cierre operativo habilitado |
| RBAC | Operaciones no ve margen; Guía (U09) no abre `#/operaciones/ordenes` (403) |

---

## 11. Cambios y decisiones respecto a la documentación inicial

| # | Tipo | Tema | Documentación inicial | Prototipo / especificación | Motivo |
|---|---|---|---|---|---|
| 1 | Precisado | Estados de operación | Planificada → Pend. asignación → Asignada → Confirmada → En ejecución → Completada | Mismos códigos + `cancelada`; recálculo automático entre pendiente/asignada/confirmada según asignaciones; condiciones exactas de cada botón | Evitar estados manuales incoherentes con las asignaciones |
| 2 | Agregado | Estado por asignación | No existía | `pendiente → asignada → confirmada → en_curso → completada` por servicio | La operación agrega múltiples servicios con recursos distintos |
| 3 | Precisado | Estado de servicio frente al proveedor | "Confirmación/Rechazo" | `pendiente/solicitado/confirmado/rechazado/en_curso/completado/cancelado`; código de confirmación obligatorio; rechazo con motivo | Trazabilidad y RT-11 (voucher solo con servicio confirmado) |
| 4 | Agregado | Alternativa del proveedor | "Proveedor rechaza → Alternativa" (acción de la agencia) | El proveedor puede proponer alternativa desde la extranet (fecha, servicio, tarifa, bloqueo) | Práctica de extranets de mercado; reduce idas y vueltas |
| 5 | Agregado | Tabla `ops_supplier_requests` | No existía | Historial de solicitudes/respuestas por servicio | Auditar PROC-032 y alimentar la extranet |
| 6 | Precisado | Validación de conflicto (PROC-033) | "Validar conflicto" | Ventana de 6 h (bloqueo en otra orden/salida, advertencia en la misma orden), estado del recurso, idioma, licencia, SOAT, capacidad; advertencias con aceptación explícita | Reglas operativas concretas y auditables |
| 7 | Agregado | Salidas programadas en la ocupación | No mencionado | Guía y vehículo de `catalog_departures` cuentan como ocupación | Evitar doble asignación entre operación privada y salida regular |
| 8 | Agregado | Notificación al recurso | "Notificar" | Push (app operador) y WhatsApp configurables en el modal; WhatsApp al liberar | PROC-059 aplicado a asignaciones |
| 9 | Precisado | Rooming (PROC-034) | "No doble habitación activa incompatible" | Además: capacidad por tipo, número de habitación único y sin solapamiento en el hotel, estados de lista (`borrador/confirmada/modificada/enviada`) y habitación (`confirmada/pendiente/observada`); restricciones EXCLUDE en BD | Llevar RT-13 a la base de datos |
| 10 | Cambiado | Permiso de rooming | Implícito en operaciones | Se exige `reservations.modify` (no `operations.assign`) | Decisión del prototipo: rooming modifica datos de la reserva (pasajeros/alojamiento) |
| 11 | Agregado | Acuse y confirmación del hotel | "Enviar" | Hotel acusa recibo, confirma habitaciones u observa (→ incidencia) desde la extranet | Cerrar el ciclo sin correo |
| 12 | Precisado | Voucher (PROC-035) | Validar → Generar → Versionar → Publicar → Notificar | Un voucher vigente por servicio; versión incrementa en el mismo código con motivo y snapshot; estados `emitido/utilizado/anulado`; bloqueo por proveedor inactivo; QR firmado; notificación a cliente, pasajeros y proveedor | Control documental y antifraude |
| 13 | Agregado | Canje de voucher | No existía | "Marcar utilizado" en web y validación por código en extranet | Evidencia de prestación para liquidar al proveedor |
| 14 | Precisado | Ejecución (PROC-036) | Asignado → Confirmado → Inicio → Check-in → Ejecución → Evidencia → Fin | Check-in por pasajero solo con servicio iniciado; fin exige ≥1 check-in y ≥1 evidencia; sin check-in = no-show; la web no puede completar antes de la fecha | Calidad del dato de campo |
| 15 | Precisado | Incidencias (PROC-037) | Crítica genera alerta y queda abierta hasta resolución | SLA 4/8/24/72 h; estados `abierta/en_proceso/resuelta/cerrada`; reglas de cierre por severidad; reabrir con motivo; escalamiento a crítica por reclasificación; alerta a Manager por push + WhatsApp | Operación 24/7 medible |
| 16 | Precisado | Evidencias (PROC-038) | Captura → Validación → Compresión → Upload → Asociación → Auditoría | Imagen/PDF, asociada a servicio o incidencia, retiro lógico con motivo, deduplicación por hash | RT-24 y conflicto `evidence` offline |
| 17 | Precisado | Cierre (PROC-039) | 4 precondiciones genéricas | Reglas exactas (§5 OPS-BR-80..83); "evidencias requeridas" = ≥1 por orden + alta/crítica con evidencia; "incidencias gestionadas" = resuelta o cerrada; "costos disponibles" = costo > 0 en servicios con proveedor; tareas no bloquean; cerrar completa la reserva | Criterio verificable por backend |
| 18 | Agregado | Visibilidad económica en cierre | No definido | Operaciones ve costos; venta y margen solo con permisos de rentabilidad | Principio de datos sensibles por rol |
| 19 | Agregado | Tareas de la orden | "Tareas → Responsables" | Checklist con 5 tareas por defecto, marca de quién/cuándo | PROC-031 operativo |
| 20 | Precisado | App operador (PROC-056) | Login → … → Sync | Pantallas Hoy/Detalle/Calendario 14 d/Incidencias/Perfil; el recurso solo ve sus asignaciones; internos tienen vista previa con "Ver como recurso" | Usabilidad en campo y demo |
| 21 | Precisado | Offline (PROC-058) | Estados y "resolución de conflictos por reglas de negocio" | Reglas por tipo de acción (MOB-BR-05..11), coalescencia local, efectos secundarios solo al sincronizar, `ops_sync_actions` como registro idempotente | Hacer implementable la resolución de conflictos |
| 22 | Agregado | App pasajero (PROC-057) | Login → Viaje → Itinerario → Reservas → Documentos → Notificaciones → Soporte | Pestañas Mi viaje (cuenta regresiva, hotel/habitación), Vouchers con QR, Alertas con leído, chat con la agencia y botón de emergencia | Experiencia de viaje de mercado |
| 23 | Precisado | Stack móvil | "React Native" | Expo + SQLite + Expo Notifications (§8.4) | Ver apartado técnico |
| 24 | Cambiado | Identificador de servicio a asignar | — | Heurística por nombre del prototipo (hotel/hab./entrada; tipo tour/traslado/…) se reemplaza por `requires_assignment` y `service_kind` | Las regex no son fiables en producción |
| 25 | Agregado | Emisión de vouchers desde e-commerce | — | `ops_vouchers.origin = ecommerce` (canales.js emite automáticamente) | Mismo modelo para ambos orígenes |

---

## 12. Supuestos y puntos a validar con negocio

| # | Punto | Propuesta por defecto |
|---|---|---|
| 1 | ¿Se puede cerrar una operación que no está `en_ejecucion`? (el prototipo lo permite si se cumplen las precondiciones) | Solo desde `en_ejecucion` |
| 2 | Regla de JOB-09 "Expirar asignaciones no confirmadas": ¿plazo? ¿se liberan o solo se alerta? | Alertar al coordinador 24 h antes; no liberar automáticamente |
| 3 | Ventana de cruce de 6 h fija | Parámetro por tenant y por tipo de servicio (full day vs traslado) |
| 4 | Derivación de idioma requerido (nacionalidad y notas) | Campo `language` en pasajero/reserva |
| 5 | No-show: ¿genera incidencia, cargo o notificación comercial? | Registrar no-show por pasajero; sin cargo automático |
| 6 | ¿Evidencia obligatoria por cada servicio o basta 1 por orden (prototipo)? | Configurable por `service_kind` |
| 7 | Estado de recurso `asignado` es manual en el prototipo | Derivarlo de la ocupación; mantener manual solo `descanso/vacaciones/mantenimiento` |
| 8 | CRUD de guías, conductores y vehículos (no existe en el prototipo) | Pantalla en Operaciones › Recursos con `operations.assign` |
| 9 | Una rooming list por reserva (prototipo) vs varias por hotel en circuitos | Varias (UNIQUE por reserva + hotel) |
| 10 | Cancelación de reserva con orden activa | Orden → `cancelada`, liberar recursos, anular vouchers, notificar proveedores |
| 11 | ¿Quién aprueba alternativas del proveedor? (sin pantalla en back-office) | Operaciones desde orden 360 (aceptar = actualizar servicio; rechazar = notificar) |
| 12 | SLA en horas corridas o laborables | Corridas (operación 24/7) |
| 13 | Retención de datos personales en el teléfono (pasaportes, teléfonos) | Ventana [-2 d, +14 d]; purga 7 días después del servicio; borrado remoto al revocar dispositivo |
| 14 | GPS "cuando exista finalidad operativa" (DOC-PROC-001) | Fuera de alcance del prototipo; fase posterior con consentimiento |
| 15 | Documentos del pasajero (itinerario, póliza, políticas) son fijos en el prototipo | Generarlos desde `sys_documents` vinculados a la reserva |
| 16 | Alertas del pasajero hardcodeadas (INC-0085) | Derivar de `notif_notifications` con `reservation_id` y `audience=passenger` |
| 17 | Chat del pasajero con respuestas automáticas simuladas | Integrar con Bandeja omnicanal (colaboración) |
| 18 | ¿Se notifica al pasajero el inicio/fin de servicio? | Solo cambios de horario, vouchers e incidencias que le afecten |
| 19 | Correlativo de incidencias (prototipo máx+1) | `sys_sequences` serie "Incidencia" |
| 20 | Emergencia 24/7 fija en el prototipo (+51 984 410 663) | Configurable por sede |