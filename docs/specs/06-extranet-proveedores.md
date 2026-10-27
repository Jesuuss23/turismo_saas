# Extranet de proveedores

> Fuente: `proto/assets/js/modules/proveedor.js` (portal del proveedor), `core.js` (`ROLE_UX.provider`, `ACCESS['proveedor'] = '__solo_extranet__'`, `Auth.providerId`) y `data.js` (`providers`, `services`, `reservations[].services`, `rooming`, `vouchers`, `payables`, `incidents`, usuario `U17 → PRV-001`). Las entidades operativas compartidas (solicitudes, rooming, vouchers, incidencias) están definidas en `05-operaciones-movil.md`; aquí se documenta lo que el proveedor ve y hace, y las tablas propias de la extranet.
>
> Los códigos de estado se conservan como en el prototipo; tablas, columnas y endpoints en inglés.

---

## 1. Propósito y procesos cubiertos

Portal externo (supplier extranet) para que cada proveedor (hotel, transportista, operador, restaurante…) gestione **solo sus** datos con la agencia: responder solicitudes de servicio, recibir y confirmar rooming lists, validar vouchers en recepción, consultar pagos y subir facturas, reportar y seguir incidencias, y consultar su contrato y tarifas netas solicitando actualizaciones. El proveedor **nunca** ve precios de venta, márgenes, ni datos de contacto del cliente final: solo grupo/titular, nº de pasajeros y su tarifa neta.

| Proceso | Cobertura en la extranet |
|---|---|
| PROC-032 Solicitud a proveedor | Confirmar (con código), rechazar (motivo) o proponer alternativa |
| PROC-034 Rooming list | Acusar recibo, confirmar habitaciones, reportar observación (→ incidencia) |
| PROC-035 Voucher | Consultar vouchers a su nombre y registrarlos como utilizados (validación por código) |
| PROC-037 Incidencia | Reportar, comentar y seguir incidencias vinculadas a sus servicios |
| PROC-044 / PROC-046 Cuenta por pagar / Pago a proveedor | Ver saldos, fechas programadas y estado; subir factura electrónica |
| PROC-012 Proveedores (contrato, vigencia, costos) | Ver condiciones y tarifas netas; solicitar actualización de tarifa |
| PROC-062 Autenticación | Usuario externo vinculado a un proveedor (`users.provider_id`) |

---

## 2. Pantallas

Shell externo (barra superior azul, sin menú lateral) con navegación: Solicitudes · Rooming lists · Vouchers · Pagos · Incidencias · Mi contrato. Pie: "Solo se muestran datos vinculados a su cuenta". Todas las pantallas comparten un marco con 4 KPIs.

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/proveedor` | `/proveedor` → redirige a `/proveedor/solicitudes` | Home del rol | Proveedor |
| (marco común) | layout `app/(extranet-proveedor)/layout.tsx` | KPIs: **Solicitudes por responder** (+ nº con alternativa en revisión; "Responder en máx. 24 h"), **Llegadas próximos 7 días** (servicios y pax), **Saldo por cobrar a la agencia** (por moneda, sin conversión) y **Incidencias abiertas**. Alerta si el usuario no está vinculado a un proveedor | Proveedor |
| `#/proveedor/solicitudes` | `/proveedor/solicitudes` | Alerta de pendientes ("bloquea la emisión del voucher al pasajero, RT-11"); tabla ordenada (pendientes primero, luego por fecha) con fecha/hora y aviso "en N d" si ≤ 2 días, servicio + reserva, grupo/titular, pax, **tarifa neta**, estado + código; filtros (Por responder, Alternativa propuesta, Confirmado, Rechazado, Completado, Cancelado); acciones Confirmar / Alternativa / Rechazar o Ver detalle; drawer de detalle con rooming y voucher vinculados y "Su respuesta" | Proveedor |
| `#/proveedor/rooming` | `/proveedor/rooming` | Tarjeta por rooming list de su hotel: check-in/out, noches, habitaciones, huéspedes, "Llega en N d" / "En casa", estado (Por acusar recibo / Recibida / Confirmada por el hotel), habitaciones con huéspedes y nota; acciones Acusar recibo, Confirmar habitaciones, Reportar observación por habitación | Proveedor |
| `#/proveedor/vouchers` | `/proveedor/vouchers` | "Validar voucher en recepción" (código → marcar utilizado); tabla de vouchers a su nombre (versión, emisión, servicio, reserva, fecha, pax, estado, fecha de uso) con "Marcar utilizado" | Proveedor |
| `#/proveedor/pagos` | `/proveedor/pagos` | Condición de pago del contrato; alerta de documentos sin factura; tabla de cuentas por pagar de la agencia (documento, reserva, concepto, importe, saldo, fecha programada con vencido/en N d, estado, su factura) con total por moneda; "Subir factura" | Proveedor |
| `#/proveedor/incidencias` | `/proveedor/incidencias` | Tabla de sus incidencias (asunto, reserva, impacto, estado, última actualización) + "Reportar incidencia"; drawer con seguimiento (timeline) y comentario | Proveedor |
| `#/proveedor/contrato` | `/proveedor/contrato` | Alerta de vencimiento (< 60 días o vencido); Condiciones (razón social, RUC, tipo/estrellas, vigencia, estado, moneda, condición de pago, datos de facturación de la agencia); Contactos (suyos y de la agencia: Operaciones, Pagos, Emergencias 24 h); **Tarifas netas contratadas** (servicio, unidad, tarifa, vigencia, estado, "Actualizar tarifa"); historial de solicitudes de actualización | Proveedor |

Internos: `#/proveedor/*` no es accesible (ACCESS `__solo_extranet__` → 403); si un usuario sin `provider_id` llegara a la ruta ve "La extranet está disponible solo para usuarios de proveedores… El personal interno gestiona proveedores desde Catálogo › Proveedores".

---

## 3. Modelo de datos PostgreSQL

Todas las tablas con `tenant_id` + RLS; auditoría de fila estándar. Tablas compartidas definidas en doc 05: `ops_supplier_requests`, `ops_rooming_lists` (`hotel_ack_*`, `hotel_confirmed_*`), `ops_rooming_rooms` (`hotel_note`, `incident_id`), `ops_vouchers` (`used_*`, `used_via`), `ops_incidents` (`provider_id`, `source='extranet'`), `ops_incident_logs` (`visibility`). Otras dependencias: `catalog_providers`, `catalog_services`, `catalog_service_rates`, `fin_payables`, `iam_users`, `sys_documents`.

### 3.1 Vínculo usuario ↔ proveedor

| Tabla.columna | Tipo | Null | Notas |
|---|---|---|---|
| `iam_users.provider_id` | uuid | sí | FK catalog_providers. Obligatorio si el rol es `provider`; índice `(tenant_id, provider_id)`. Un proveedor puede tener varios usuarios (recepción, ventas, contabilidad) |
| `iam_users.provider_role` | varchar(20) | sí | **Nuevo** CHECK IN (`admin`,`reservas`,`recepcion`,`contabilidad`) para permisos granulares dentro del proveedor (§7, §12) |

### 3.2 `supplier_request_responses` (respuesta del proveedor a una solicitud)

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| supplier_request_id | uuid | no | FK ops_supplier_requests; índice |
| reservation_service_id | uuid | no | FK booking_reservation_services (desnormalizado) |
| provider_id | uuid | no | FK catalog_providers; índice `(tenant_id, provider_id, created_at desc)` |
| type | varchar(20) | no | CHECK IN (`confirmacion`,`rechazo`,`alternativa`) |
| responded_by_user_id | uuid | no | FK iam_users (usuario del proveedor) |
| responded_at | timestamptz | no | |
| confirmed_by_name | varchar(120) | sí | "Confirmado por" (texto libre; por defecto el contacto del proveedor) |
| confirmation_code | varchar(40) | sí | Obligatorio si `confirmacion`; ≥ 4 caracteres; se guarda en mayúsculas |
| rejection_reason | varchar(60) | sí | CHECK IN (`Sin disponibilidad (sold out)`,`Fecha bloqueada por mantenimiento`,`Tarifa no vigente para la fecha`,`Cupo de grupo excedido`,`Otro`); obligatorio si `rechazo` |
| alt_kind | varchar(40) | sí | CHECK IN (`Otra fecha`,`Otro tipo de habitación`,`Otro horario`,`Upgrade sin costo`,`Otro`); obligatorio si `alternativa` |
| alt_date | date | sí | Fecha propuesta |
| alt_service_id | uuid | sí | FK catalog_services (servicio/tarifa propia del proveedor; null = mismo servicio) |
| alt_net_cost | numeric(14,2) | sí | Tarifa neta propuesta |
| currency | char(3) | sí | Moneda de la reserva (prototipo) — ver §12 |
| hold_until | date | sí | "Mantener bloqueo hasta" |
| note | text | sí | Observación / detalle (obligatorio en rechazo y alternativa) |
| review_status | varchar(20) | sí | Solo alternativas: CHECK IN (`pendiente`,`aceptada`,`rechazada`,`expirada`); default `pendiente` |
| reviewed_by / reviewed_at / review_note | uuid / timestamptz / text | sí | Decisión de Operaciones (no existe en el prototipo) |

CHECK por tipo: `type='confirmacion' ⇒ confirmation_code IS NOT NULL AND length(confirmation_code) >= 4`; `type='rechazo' ⇒ rejection_reason IS NOT NULL AND note IS NOT NULL`; `type='alternativa' ⇒ alt_kind IS NOT NULL AND note IS NOT NULL`.

### 3.3 `supplier_invoices` (factura del proveedor asociada a una CxP)

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| payable_id | uuid | no | FK fin_payables; índice |
| provider_id | uuid | no | FK catalog_providers |
| number | varchar(20) | no | Serie-correlativo `^[A-Z0-9]{4}-\d{1,8}$`, mayúsculas. UNIQUE (tenant_id, provider_id, number) |
| issue_date | date | no | |
| amount | numeric(14,2) | no | CHECK (amount > 0) |
| currency | char(3) | no | = moneda de la CxP |
| amount_difference | numeric(14,2) | no | amount − saldo de la CxP al registrar (≠ 0 → revisión de Finanzas) |
| document_id | uuid | no | FK sys_documents (PDF/XML/ZIP en S3 `tenants/{t}/supplier/invoices/{uuid}`) |
| original_file_name | varchar(200) | no | |
| status | varchar(20) | no | CHECK IN (`registrada`,`validada`,`observada`,`rechazada`); default `registrada` |
| uploaded_by / uploaded_at | uuid / timestamptz | no | Usuario del proveedor |
| validated_by / validated_at / validation_note | uuid / timestamptz / text | sí | Finanzas (no existe en el prototipo) |
| deleted_at | timestamptz | sí | |

Índice único parcial: `(tenant_id, payable_id) WHERE status IN ('registrada','validada') AND deleted_at IS NULL` (una factura activa por CxP, como en el prototipo).

### 3.4 `supplier_rate_requests` (solicitud de actualización de tarifa)

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| code | varchar(20) | no | `SOL-TAR-0101` vía sys_sequences. UNIQUE (tenant_id, code) |
| provider_id | uuid | no | FK catalog_providers; índice `(tenant_id, provider_id, status)` |
| service_id | uuid | no | FK catalog_services (del mismo proveedor) |
| current_cost | numeric(14,2) | no | Tarifa vigente al solicitar |
| proposed_cost | numeric(14,2) | no | CHECK (proposed_cost > 0) |
| currency | char(3) | no | Moneda del servicio |
| effective_from | date | no | ≥ fecha de solicitud + 30 días |
| reason_type | varchar(40) | no | CHECK IN (`Ajuste por inflación / costos`,`Cambio de temporada`,`Mejora del servicio`,`Tarifa promocional`,`Otro`) |
| reason_detail | text | sí | Sustento |
| status | varchar(20) | no | CHECK IN (`pendiente`,`aprobada`,`rechazada`,`retirada`) |
| requested_by / requested_at | uuid / timestamptz | no | |
| reviewed_by / reviewed_at / review_note | uuid / timestamptz / text | sí | |
| resulting_rate_id | uuid | sí | FK catalog_service_rates (nueva vigencia creada al aprobar) |

Índice único parcial: `(tenant_id, service_id) WHERE status = 'pendiente'` (el prototipo oculta "Actualizar tarifa" si hay una pendiente).

### 3.5 Vistas de lectura (read models) para la extranet

| Vista | Definición | Columnas expuestas (whitelist) |
|---|---|---|
| `supplier_v_requests` | `booking_reservation_services` con `provider_id` = proveedor, reserva ≠ `borrador` + última respuesta | reservation_code, service_code, service_label, service_date/time, **holder_label**, pax, **unit_cost** (tarifa neta), currency, status, confirmation_code, requested_at, response (tipo, fecha, datos) |
| `supplier_v_rooming` | `ops_rooming_lists` con `hotel_provider_id` = proveedor | reservation_code, holder_label, check_in/out, nights, rooms (número, tipo, estado, huéspedes por nombre, hotel_note), ack/confirm |
| `supplier_v_vouchers` | `ops_vouchers` con `provider_id` = proveedor | code, version, issued_at, service_label, reservation_code, service_date, pax, status, used_at |
| `supplier_v_payables` | `fin_payables` con `provider_id` = proveedor | code, reservation_code, concept, amount, paid, balance, currency, due_date, status, invoice |
| `supplier_v_incidents` | `ops_incidents` con `provider_id` = proveedor **o** `reported_by` ∈ usuarios del proveedor **o** (`type='Proveedor'` y reserva con servicios del proveedor) + logs `visibility='proveedor'` | code, title, description, reservation_code, severity, status, type, created_at, logs (autor anonimizado) |

`holder_label` = "Grupo: {título de reserva antes de ' — '}" si pax ≥ 6; si no "Titular: {primer pasajero}". **Nunca** se exponen `unit_price`, margen, `client_id`, email/teléfono del cliente, documentos de pasajeros ni otros proveedores.

---

## 4. Máquinas de estado

### 4.1 Solicitud vista por el proveedor

Etiquetas de la extranet sobre `booking_reservation_services.status` + última respuesta:

| Estado mostrado | Condición |
|---|---|
| Por responder | status ∈ (`solicitado`,`pendiente`) sin respuesta `alternativa` |
| Alternativa propuesta | status ∈ (`solicitado`,`pendiente`) con respuesta `alternativa` |
| Confirmado / Rechazado / Completado / Cancelado / En curso | status del servicio |

| Desde | Hacia | Quién | Condición | Efectos |
|---|---|---|---|---|
| Por responder / Alternativa propuesta | `confirmado` | Proveedor "Confirmar" | Pertenece al proveedor; código ≥ 4 caracteres | `confirmation_code` en mayúsculas; respuesta `confirmacion`; notifica a Operaciones (portal); audit `proveedor_confirma`; habilita voucher |
| Por responder / Alternativa propuesta | `rechazado` | Proveedor "Rechazar" | Motivo + detalle | Respuesta `rechazo`; notifica a Operaciones; audit `proveedor_rechaza` |
| Por responder | Alternativa propuesta | Proveedor "Alternativa" | Tipo + detalle | Respuesta `alternativa` (`review_status=pendiente`); notifica a Operaciones; audit `proveedor_alternativa` |
| Alternativa propuesta | `confirmado` / Por responder | Operaciones acepta / rechaza la alternativa | **No implementado en el prototipo** (§12) | — |
| `confirmado`/`rechazado`/… | — | Proveedor | Bloqueado: "La solicitud ya fue respondida (…). Para cambios contacte a Operaciones." | — |

### 4.2 Rooming list (lado hotel)

| Estado mostrado | Condición |
|---|---|
| Por acusar recibo | `hotel_ack_at IS NULL` |
| Recibida | `hotel_ack_at` y no `hotel_confirmed_at` |
| Confirmada por el hotel | `hotel_confirmed_at` |

| Acción | Efectos |
|---|---|
| Acusar recibo | `hotel_ack_at/by`; notifica a Operaciones; audit `acuse_recibo_hotel` |
| Confirmar habitaciones | Habitaciones no observadas → `confirmada`; si no había acuse, lo registra; `hotel_confirmed_at`; notifica; audit `confirmacion_hotel` |
| Reportar observación (habitación no observada) | Crea incidencia `Proveedor` (`source=extranet`, `provider_id`), habitación → `observada` + `hotel_note`; notifica; audit incidencia `crear` + rooming `observacion_hotel` |

Si Operaciones modifica la lista después de la confirmación del hotel, la lista pasa a `modificada` y se reenvía (doc 05); el hotel debe volver a acusar/confirmar (ver §12).

### 4.3 Voucher (lado proveedor)

`emitido → utilizado` por validación de código o botón "Marcar utilizado". `anulado` y `utilizado` son finales y se rechazan con mensaje.

### 4.4 Cuenta por pagar (vista del proveedor)

Etiquetas sobre `fin_payables.status`: `abierta` = "En revisión", `aprobada` = "Programado", `parcial` = "Pago parcial", `pagada` = "Pagado", `vencida` = "Vencido". La extranet no cambia el estado de la CxP; solo agrega la factura (`supplier_invoices.status`: `registrada → validada | observada | rechazada` por Finanzas).

### 4.5 Incidencia (lado proveedor)

Crea en `abierta`; solo puede comentar mientras no esté `resuelta`/`cerrada`. El resto del ciclo lo gestiona Operaciones (doc 05 §4.5).

### 4.6 Solicitud de tarifa

`pendiente → aprobada | rechazada` (Compras/Operaciones, no implementado) · `pendiente → retirada` (proveedor, propuesto).

---

## 5. Reglas de negocio

| ID | Regla | Dónde se valida |
|---|---|---|
| SUP-BR-01 | El usuario proveedor solo accede a datos de SU `provider_id`; sin vínculo no ve datos ("Su usuario no está vinculado a un proveedor") | Policy + global scope `SupplierScope` + RLS |
| SUP-BR-02 | La extranet no expone precios de venta, márgenes, datos de contacto del cliente final ni documentos de pasajeros; solo grupo/titular, pax y tarifa neta | API Resources con whitelist (§3.5) |
| SUP-BR-03 | Solo se listan servicios de reservas que no están en `borrador` | Query |
| SUP-BR-04 | Solo se responde una solicitud en estado `solicitado`/`pendiente`; otra → "La solicitud ya fue respondida…" | Service → 409 |
| SUP-BR-05 | La solicitud debe pertenecer al proveedor ("La solicitud no corresponde a su establecimiento") | Policy → 403/404 |
| SUP-BR-06 | Confirmar exige código de confirmación ≥ 4 caracteres (se imprime en el voucher del pasajero); se normaliza a mayúsculas | FormRequest |
| SUP-BR-07 | Rechazar exige motivo (lista cerrada) y detalle | FormRequest |
| SUP-BR-08 | Alternativa exige tipo y detalle; el servicio alternativo solo puede ser un servicio activo del propio proveedor; no cambia el estado del servicio hasta que Operaciones decida | FormRequest + Service |
| SUP-BR-09 | Una solicitud sin respuesta bloquea la emisión del voucher (RT-11) | Doc 05 OPS-BR-40 |
| SUP-BR-10 | Tiempo objetivo de respuesta: 24 h (mostrado en KPI) | UI + Job de recordatorio (§8) |
| SUP-BR-20 | Rooming: solo listas cuyo hotel es el proveedor | Policy |
| SUP-BR-21 | Confirmar habitaciones deja las observadas pendientes hasta que Operaciones resuelva la incidencia | Service |
| SUP-BR-22 | Observación de habitación exige motivo (lista cerrada) y propuesta del hotel; impacto `media`/`alta`/`baja`; genera incidencia asignada al coordinador de la orden (o Operaciones por defecto) | FormRequest + Service |
| SUP-BR-30 | Validar voucher: el código debe existir y estar emitido a nombre del proveedor ("no corresponde a un voucher emitido a su establecimiento") | Service → 404 |
| SUP-BR-31 | Voucher `anulado` → rechazo "no es válido para el servicio"; `utilizado` → aviso con fecha de uso previa (no se reutiliza) | Service → 409 |
| SUP-BR-32 | Si la fecha del servicio es futura se advierte "Registre el uso solo si el huésped ya se presentó" y requiere confirmación | UI + flag `confirm_future=true` |
| SUP-BR-33 | Canje idempotente: dos validaciones simultáneas del mismo código → una sola aplica | `Idempotency-Key` + `lock_version` |
| SUP-BR-40 | Factura: número con formato serie-correlativo `^[A-Z0-9]{4}-\d{1,8}$`, fecha de emisión y archivo PDF/XML/ZIP obligatorios | FormRequest |
| SUP-BR-41 | Importe distinto del saldo de la CxP (> 0.01) no bloquea: se registra con advertencia "Finanzas revisará la diferencia" | Service (`amount_difference`) |
| SUP-BR-42 | Solo CxP del proveedor y no `pagada`; una factura activa por CxP | Policy + constraint |
| SUP-BR-43 | Finanzas programa el pago solo con factura registrada | Doc Finanzas (regla de pago) |
| SUP-BR-44 | Saldos por moneda sin conversión (el proveedor factura en su moneda de contrato) | Resource |
| SUP-BR-50 | Incidencias visibles: `provider_id` = proveedor, reportadas por usuarios del proveedor, o de tipo Proveedor en reservas con servicios del proveedor | Scope |
| SUP-BR-51 | Nueva incidencia solo sobre servicios del proveedor no cancelados/rechazados con fecha ≥ hoy − 7 días; asunto y descripción obligatorios; tipos: Proveedor, Horario, Capacidad, Pasajero, Otro | FormRequest |
| SUP-BR-52 | Incidencia crítica desde la extranet notifica a Operaciones como `incidencia.critica` (y debe disparar la alerta a gerencia de PROC-037, §11) | Listener |
| SUP-BR-53 | Comentarios solo en incidencias abiertas/en proceso; en el seguimiento los autores internos se muestran como "Operaciones {agencia}" | Service + Resource |
| SUP-BR-60 | Contrato: alerta si vence en < 60 días; vencido → "La agencia no puede solicitar nuevos servicios hasta renovarlo" (PRD-BR-007: proveedor inactivo no disponible para nuevas ventas) | Resource + regla catálogo |
| SUP-BR-61 | Actualización de tarifa: importe > 0; vigencia desde ≥ hoy + 30 días (cláusula de contrato); una solicitud pendiente por servicio | FormRequest + constraint |
| SUP-BR-62 | La nueva tarifa no se aplica automáticamente: requiere aprobación y crea una nueva vigencia; reservas confirmadas conservan la tarifa pactada (PRD-BR-008/009) | Service catálogo |
| SUP-BR-63 | Tarifas mostradas sin IGV; la aplicada en cada solicitud es la vigente a la fecha del servicio | Resource |
| RT-16 / SUP-BR-70 | Toda acción del proveedor queda auditada con actor externo | Listener audit |

---

## 6. API REST

Prefijo `/api/v1/supplier` (token Sanctum con ability `supplier`, usuario con `provider_id`). El proveedor se resuelve del token, nunca del request.

| Método | Endpoint | Permiso | Descripción | Idem/lock |
|---|---|---|---|---|
| GET | `/supplier/me` | supplier.portal.view | Proveedor, usuario, moneda, condición de pago | — |
| GET | `/supplier/dashboard` | supplier.portal.view | 4 KPIs del marco | — |
| GET | `/supplier/requests` | supplier.portal.view | `filter[state]=pending|alternative|confirmado|rechazado|completado|cancelado`, `q`, `sort` | — |
| GET | `/supplier/requests/{serviceId}` | supplier.portal.view | Detalle + rooming + voucher + su respuesta | — |
| POST | `/supplier/requests/{serviceId}/confirm` | supplier.requests.respond | `{confirmation_code, confirmed_by_name, note}` | Idem + lock |
| POST | `/supplier/requests/{serviceId}/reject` | supplier.requests.respond | `{reason, note}` | Idem + lock |
| POST | `/supplier/requests/{serviceId}/alternative` | supplier.requests.respond | `{kind, date, service_id?, net_cost, hold_until, note}` | Idem |
| GET | `/supplier/rooming-lists` | supplier.portal.view | Listas de su hotel | — |
| POST | `/supplier/rooming-lists/{id}/acknowledge` | supplier.rooming.manage | Acuse de recibo | Idem |
| POST | `/supplier/rooming-lists/{id}/confirm` | supplier.rooming.manage | Confirmar habitaciones no observadas | Idem + lock |
| POST | `/supplier/rooming-rooms/{id}/observations` | supplier.rooming.manage | `{kind, impact, proposal}` → crea incidencia | Idem |
| GET | `/supplier/vouchers` | supplier.portal.view | `filter[status]`, `q` | — |
| POST | `/supplier/vouchers/redeem` | supplier.vouchers.redeem | `{code, confirm_future?}` → valida y marca utilizado | Idem + lock |
| GET | `/supplier/payables` | supplier.payables.view | CxP con saldo y total por moneda | — |
| POST | `/supplier/payables/{id}/invoices` | supplier.invoices.upload | Multipart `{number, issue_date, amount, file}` (o presign + `document_id`) | Idem |
| GET | `/supplier/incidents` | supplier.portal.view | `filter[status]` | — |
| GET | `/supplier/incidents/{id}` | supplier.portal.view | Detalle + logs visibles | — |
| POST | `/supplier/incidents` | supplier.incidents.report | `{reservation_service_id, type, severity, title, description}` | Idem |
| POST | `/supplier/incidents/{id}/comments` | supplier.incidents.report | `{message}` | — |
| GET | `/supplier/contract` | supplier.portal.view | Condiciones, contactos, alerta de vencimiento | — |
| GET | `/supplier/rates` | supplier.portal.view | Tarifas netas contratadas | — |
| GET | `/supplier/rate-requests` | supplier.portal.view | Historial | — |
| POST | `/supplier/rate-requests` | supplier.rates.request | `{service_id, proposed_cost, effective_from, reason_type, reason_detail}` | Idem |
| POST | `/supplier/rate-requests/{id}/withdraw` | supplier.rates.request | Retirar (propuesto) | — |

Contraparte en back-office (no existe en el prototipo; necesaria para cerrar los flujos):

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/ops/supplier-responses?filter[type]=alternativa&filter[review_status]=pendiente` | operations.view | Bandeja de alternativas |
| POST | `/ops/supplier-responses/{id}/accept` | operations.assign | Aplica la alternativa (fecha/servicio/costo) y confirma o reenvía la solicitud |
| POST | `/ops/supplier-responses/{id}/reject` | operations.assign | Rechaza la alternativa y notifica al proveedor |
| GET | `/catalog/rate-requests` | catalog.providers.manage | Solicitudes de tarifa |
| POST | `/catalog/rate-requests/{id}/approve` | catalog.products.manage_rates | Crea nueva vigencia en `catalog_service_rates` |
| POST | `/catalog/rate-requests/{id}/reject` | catalog.products.manage_rates | Rechaza con nota |
| POST | `/fin/supplier-invoices/{id}/validate` \| `/observe` \| `/reject` | finance.payables | Validación de factura |
| POST | `/catalog/providers/{id}/users` | catalog.providers.manage + admin.users.manage | Invitar usuario del proveedor |

---

## 7. Permisos y comportamiento por rol

En el prototipo el rol `provider` solo tiene `operations.view` y las acciones se protegen por **pertenencia** (`guardOwn`, `providerId`). Se proponen permisos específicos (grupo "Extranet") asignables por `provider_role`:

| Permiso propuesto | admin | reservas | recepcion | contabilidad |
|---|---|---|---|---|
| `supplier.portal.view` | ✓ | ✓ | ✓ | ✓ |
| `supplier.requests.respond` | ✓ | ✓ | — | — |
| `supplier.rooming.manage` | ✓ | ✓ | ✓ | — |
| `supplier.vouchers.redeem` | ✓ | ✓ | ✓ | — |
| `supplier.payables.view` | ✓ | — | — | ✓ |
| `supplier.invoices.upload` | ✓ | — | — | ✓ |
| `supplier.incidents.report` | ✓ | ✓ | ✓ | — |
| `supplier.rates.request` | ✓ | — | — | — |
| `supplier.users.manage` (invitar colegas) | ✓ | — | — | — |

Default MVP (equivalente al prototipo): un único `provider_role = admin` con todos los permisos.

| Rol | Qué ve / hace | Alcance |
|---|---|---|
| Proveedor (U17 → PRV-001) | Las 6 pantallas; todas las acciones de §2 | Solo su proveedor. Sin barras de demo ni selectores; mensajes orientados al proveedor |
| Tenant Admin, Manager, Operaciones, Finanzas, Contabilidad, Ventas | No acceden a `/proveedor/*` (403 "pertenece a la extranet"). Ven la información equivalente en back-office: Operaciones (orden 360, rooming, vouchers, incidencias), Finanzas (CxP con factura del proveedor), Catálogo › Proveedores | Tenant |
| Guía, Conductor, B2B, Cliente, Pasajero | Sin acceso | — |
| Super Admin | Sin acceso (aislamiento) | — |

Ocultamientos específicos: no se muestran botones de acción en solicitudes ya respondidas (solo "Ver detalle"); "Reportar observación" no aparece en habitaciones ya observadas; "Acusar recibo"/"Confirmar habitaciones" se reemplazan por la fecha una vez hechos; "Subir factura" no aparece en documentos pagados o con factura; "Actualizar tarifa" se reemplaza por "Actualización enviada" si hay solicitud pendiente; el comentario de incidencias no aparece en resueltas/cerradas.

---

## 8. Eventos, jobs (colas Redis) y notificaciones

| Evento | Disparador | Notificación (prototipo) | Listeners adicionales |
|---|---|---|---|
| `SupplierConfirmed` | Confirmar solicitud | Portal → Operaciones (U05, U06): "{proveedor} confirmó {servicio} ({reserva}) · código …" | Actualiza orden 360 (Reverb); desbloquea voucher; cierra tarea "Enviar solicitudes…" si todas respondidas (propuesto) |
| `SupplierRejected` | Rechazar | Portal → Operaciones: "… rechazó …: motivo" | Tarea/alerta de búsqueda de alternativa |
| `SupplierAlternativeProposed` | Alternativa | Portal → Operaciones | Bandeja de alternativas; job de expiración de `hold_until` |
| `RoomingAcknowledged` / `RoomingConfirmedByHotel` | Rooming | Portal → Operaciones | — |
| `RoomingObservationReported` → `IncidentCreated` | Observación | Portal → Operaciones `incidencia.creada` | SLA de incidencia |
| `VoucherRedeemed` | Canje | Portal → Operaciones `voucher.utilizado` | Evidencia de prestación para liquidación (Finanzas) |
| `SupplierInvoiceUploaded` | Factura | Portal → Finanzas (U07) `proveedor.factura` | Tarea de validación en CxP |
| `IncidentCreated` (extranet) | Nueva incidencia | Portal → Operaciones `incidencia.creada` o `incidencia.critica` | Crítica → alerta a gerencia push + WhatsApp (alinear con PROC-037) |
| `IncidentCommentAdded` | Comentario | Portal → Operaciones y responsable | — |
| `RateUpdateRequested` | Solicitud de tarifa | Portal → Operaciones (U05) y Manager (U02) `proveedor.tarifa` | Tarea en Catálogo › Proveedores |

Hacia el proveedor (desde doc 05): `proveedor.solicitud` (email + portal), `voucher.emitido` / `voucher.anulado` (email), `rooming.enviado` (email con PDF). En producción, destinatarios = usuarios del proveedor con el permiso correspondiente + email del contrato; destinatarios internos = coordinador de la orden + grupo Operaciones de la sede (no IDs fijos).

| Job | Frecuencia | Cola | Descripción |
|---|---|---|---|
| `RemindPendingSupplierRequests` | cada 2 h (integrado a JOB-06) | notifications | Solicitudes sin respuesta > 24 h o servicio en ≤ 48 h → email/WhatsApp al proveedor y alerta a Operaciones |
| `ExpireSupplierAlternativeHolds` | diario | default | Alternativas con `hold_until` vencido y sin revisión → `expirada` |
| `ProviderContractExpiryAlerts` | diario | notifications | Contratos con vencimiento < 60 días → proveedor y Catálogo |
| `ProcessSupplierInvoice` | on-demand | documents | Validar XML UBL (RUC emisor = proveedor, receptor = tenant, importe), guardar hash |

Tiempo real: canal Reverb `tenant.{t}.supplier.{providerId}` para refrescar KPIs y listas de la extranet.

---

## 9. Auditoría

| Entidad | Acción | Antes → después |
|---|---|---|
| Servicio | `proveedor_confirma` | estado → `confirmado {código}` |
| Servicio | `proveedor_rechaza` | estado → `rechazado: {motivo}` |
| Servicio | `proveedor_alternativa` | estado → `{tipo} · {fecha} · {costo}` |
| Rooming | `acuse_recibo_hotel`, `confirmacion_hotel` (nº hab. confirmadas), `observacion_hotel` (hab. → motivo) | |
| Incidencia | `crear` (`abierta / {severidad} (proveedor)`), `comentario_proveedor` (primeros 60 caracteres) | |
| Voucher | `check_in_proveedor` | `emitido → utilizado` |
| Cuenta por pagar | `factura_proveedor` | → `{número} ({archivo})` |
| Tarifa proveedor | `solicitud_actualizacion` | `{moneda} {actual}` → `{moneda} {propuesta} desde {fecha}` |
| Sesión / Acceso | `login`, `logout`, `acceso_denegado` | |

Todas con actor = usuario del proveedor, `provider_id`, IP y correlación.

---

## 10. Casos de prueba mínimos

### Pest

| # | Caso |
|---|---|
| S-01 | Proveedor A no ve ni opera solicitudes, rooming, vouchers, CxP, incidencias ni tarifas del proveedor B (mismo tenant) → 404/403 |
| S-02 | Proveedor de Tenant A no accede a datos de Tenant B |
| S-03 | Respuesta JSON de solicitudes no contiene `unit_price`, margen, email/teléfono del cliente ni documentos de pasajeros |
| S-04 | Confirmar con código de 3 caracteres → 422; confirmar solicitud ya confirmada → 409; código se guarda en mayúsculas y el servicio queda `confirmado` |
| S-05 | Rechazar sin motivo o detalle → 422; alternativa no cambia estado del servicio y queda `review_status=pendiente` |
| S-06 | Alternativa con `service_id` de otro proveedor → 422 |
| S-07 | Rooming: confirmar deja habitaciones observadas sin cambios; observación crea incidencia con `provider_id` y `source=extranet` |
| S-08 | Canje: voucher de otro proveedor → 404; anulado → 409; utilizado → 409 con fecha; futuro sin `confirm_future` → 422; doble canje concurrente → uno solo |
| S-09 | Factura: formato inválido → 422; sin archivo → 422; importe distinto → 201 con `amount_difference ≠ 0`; segunda factura activa en la misma CxP → 409 |
| S-10 | Tarifa: `effective_from` < hoy + 30 → 422; segunda solicitud pendiente del mismo servicio → 409 |
| S-11 | Incidencia sobre servicio de hace > 7 días → 422; comentario en incidencia cerrada → 409 |
| S-12 | Usuario interno (Operaciones) → 403 en `/supplier/*`; usuario proveedor → 403 en `/ops/*` |

### Playwright

| Caso | Flujo |
|---|---|
| SUP-E2E-01 | Operaciones envía solicitud → proveedor (U17) confirma con código → Operaciones emite voucher → proveedor valida el voucher en recepción → Finanzas ve el uso |
| SUP-E2E-02 | Operaciones envía rooming → hotel acusa recibo → reporta observación (incidencia) → confirma el resto → Operaciones ve incidencia y rooming "confirmada por hotel" |
| SUP-E2E-03 | Proveedor sube factura → Finanzas la ve en CxP |
| SUP-E2E-04 | Proveedor en 400 px: sin scroll horizontal, acciones accesibles |

---

## 11. Cambios y decisiones respecto a la documentación inicial

| # | Tipo | Tema | Documentación inicial | Prototipo / especificación | Motivo |
|---|---|---|---|---|---|
| 1 | Agregado | Extranet de proveedores | Actor "Provider: proveedor turístico" sin pantallas ni proceso | Portal completo con 6 secciones | Referencia de mercado (Tourplan, Lemax: supplier extranet); reduce correo/teléfono |
| 2 | Agregado | Respuesta del proveedor en línea | PROC-032 "Confirmación/Rechazo → Registrar" (registro interno) | El proveedor confirma, rechaza o propone alternativa él mismo; Operaciones es notificada | Trazabilidad y tiempo de respuesta |
| 3 | Agregado | Alternativa con bloqueo | Excepción "Proveedor rechaza → Alternativa" | Propuesta estructurada (tipo, fecha, servicio, tarifa neta, bloqueo) | Resolver sin renegociar por correo |
| 4 | Precisado | Código de confirmación | No especificado | Obligatorio, ≥ 4 caracteres, se imprime en el voucher | Validación en recepción |
| 5 | Agregado | Acuse y confirmación de rooming por el hotel | PROC-034 termina en "Enviar" | Acuse de recibo, confirmación por habitación y observación → incidencia | Cierre del ciclo con el hotel |
| 6 | Agregado | Canje de vouchers por el proveedor | PROC-035 termina en "Notificar" | Validación por código; estado `utilizado`, `used_via=extranet` | Prueba de prestación para pagar al proveedor |
| 7 | Agregado | Factura del proveedor | PROC-044/046 internos | Proveedor sube factura (PDF/XML) a su CxP; Finanzas programa pago solo con factura | Flujo de cuentas por pagar |
| 8 | Agregado | Solicitud de actualización de tarifa | PROC-012 "Costos" internos | Solicitud con 30 días de anticipación; no se aplica sin aprobación; reservas confirmadas conservan tarifa | PRD-BR-008/009 (snapshot) y contrato |
| 9 | Precisado | Confidencialidad comercial | PRD: "No mezclar descripción comercial con información contractual del proveedor" | El proveedor ve solo tarifa neta, grupo/titular y pax; nunca precio de venta, margen ni contacto del cliente | Principio 2 de roles; práctica de mercado |
| 10 | Agregado | Permisos de extranet | Rol Provider con permiso genérico | Permisos `supplier.*` por sub-rol del proveedor (§7) | En el prototipo solo había control por pertenencia |
| 11 | Agregado | Vista de pagos del proveedor | — | Etiquetas en lenguaje del proveedor ("En revisión", "Programado", …) y saldo por moneda sin conversión | Transparencia y menos consultas a Finanzas |
| 12 | Precisado | Incidencias desde el proveedor | PROC-037 genérico | Tipos propios (Proveedor, Horario, Capacidad, Pasajero, Otro), ventana de 7 días, seguimiento con autores internos anonimizados | Canal formal y privacidad del personal |
| 13 | Cambiado | Destinatarios de notificación | — | Prototipo usa IDs fijos (U05, U06, U07, U02); producción: coordinador de la orden + grupo por rol/sede | Multi-tenant real |

---

## 12. Supuestos y puntos a validar con negocio

| # | Punto | Propuesta por defecto |
|---|---|---|
| 1 | ¿Quién y cómo acepta/rechaza una alternativa del proveedor? (no hay pantalla interna; solo badge en el dashboard) | Bandeja en Operaciones (orden 360 + dashboard) con aceptar/rechazar (§6 contraparte) |
| 2 | ¿Quién aprueba solicitudes de tarifa y dónde? (prototipo notifica a U05 y U02) | Catálogo › Proveedores con `catalog.products.manage_rates`; aprobación crea nueva vigencia |
| 3 | La tarifa neta se muestra en la **moneda de la reserva** (p. ej. PEN) aunque el contrato del hotel sea USD | Mostrar en moneda de contrato con el `fx_rate` conservado de la reserva |
| 4 | ¿Puede el proveedor modificar una respuesta ya enviada? | No; cambios vía Operaciones (como el prototipo) |
| 5 | Sub-roles del proveedor y autogestión de usuarios | MVP: un usuario admin por proveedor; fase 2: invitaciones y sub-roles |
| 6 | Incidencia crítica desde la extranet no dispara hoy la alerta push/WhatsApp a gerencia (solo portal a Operaciones) | Unificar con PROC-037: misma alerta que en back-office |
| 7 | Canje de voucher con fecha futura: ¿permitido con advertencia (prototipo) o bloqueado? | Permitido solo el día del servicio o el anterior (check-in anticipado); fuera de eso, bloquear |
| 8 | Reconfirmación del hotel tras `modificada` | Reset de `hotel_ack_at`/`hotel_confirmed_at` al reenviar una nueva versión |
| 9 | Validación del XML de la factura contra SUNAT/OSE | Validación estructural en MVP; consulta de validez con la integración fiscal (INT-01) cuando se defina |
| 10 | Tiempo máximo de respuesta (24 h mostrado) ¿contractual? | Parámetro por proveedor (`response_sla_hours`) |
| 11 | ¿El proveedor ve datos de huéspedes (nombres) en rooming? (sí en el prototipo) | Sí, nombres y tipo de habitación; no documentos ni teléfonos (salvo necesidad legal del hotel: definir) |
| 12 | Cláusula de 30 días para cambios de tarifa | Parámetro del contrato del proveedor |
| 13 | MFA para usuarios de proveedores | Opcional; obligatorio para `contabilidad` (acceso a pagos) |
| 14 | Extranet para proveedores sin usuario (entradas, trenes con integración) | No aplica; respuesta por integración (adapter) o registro interno |
