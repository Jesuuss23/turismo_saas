# Colaboración: Bandeja de aprobaciones unificada y Bandeja omnicanal

Módulo prototipo: `proto/assets/js/modules/colaboracion.js` · Rutas `#/aprobaciones`, `#/inbox` · Prefijo de tablas `collab_` · Dominio Laravel `App\Domain\Collaboration`.

## 1. Propósito y procesos cubiertos

| Submódulo | Propósito | Procesos (DOC-PROC-001) |
|---|---|---|
| Bandeja de aprobaciones | Motor de workflow de aprobación (tipo ERP) que consolida en una sola bandeja todas las excepciones que hoy viven dispersas en los módulos: cotizaciones fuera de política, anulaciones de reserva, reversiones de pago, cuentas por pagar, comisiones, cambios de tarifa de proveedores y solicitudes genéricas (crédito B2B, reembolsos, otras). Enruta por una **matriz de reglas** configurable (tipo → permiso, nivel 1/2, umbral de monto, SLA), controla SLA, delegación, pedido de información y ejecuta el **mismo efecto de estado** que el módulo de origen. | PROC-021/024 (aprobación interna de cotización), PROC-028 (cancelación), PROC-043 (reversión), PROC-044/045/046 (CxP y costos), PROC-047 (comisiones), PROC-012/017 (proveedores/tarifas), PROC-053 (B2B), PROC-059 (notificaciones), PROC-062/063 (autorización y auditoría), Reglas transversales 8, 16, 17 |
| Bandeja omnicanal (inbox) | Atención centralizada de conversaciones de WhatsApp, email, chat web, formulario de contacto y tickets del portal (cliente/pasajero), con asignación, SLA de primera respuesta, plantillas con variables, notas internas, adjuntos y ficha CRM vinculada (cliente/lead/reservas/cotizaciones). Acciones hacia CRM y ventas: crear lead, vincular cliente/reserva, crear cotización, crear actividad. | PROC-004 (captación de lead), PROC-007 (actividades), PROC-008 (Customer 360), PROC-051/052 (soporte en portales), PROC-059 (canales), PROC-064/065 (integraciones y errores externos), Regla transversal 20/21 |

## 2. Pantallas

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/aprobaciones` | `/aprobaciones` | KPIs (pendientes para mí, SLA vencido, enviadas por mí en curso, resueltas 7 días con tiempo medio) + pestañas **Pendientes para mí**, **Enviadas por mí**, **Todas (según rol)**, **Historial** (tabla + últimas decisiones auditadas) y **Reglas de aprobación**. Botón **Nueva solicitud** (tipos genéricos). Tabla con tipo, solicitante, fecha, monto/impacto, SLA con semáforo, estado y nivel. | `approvals.view`: Tenant Admin, Manager, Ventas, Operaciones, Finanzas |
| `#/aprobaciones/{APR-…}` | `/aprobaciones/[code]` (drawer sobre la lista) | Detalle: flujo de niveles, datos (tipo, origen con enlace al registro, solicitante, fecha, monto, SLA, regla que la originó, aprobador requerido y usuarios elegibles, delegación, aprobaciones previas), justificación, **detalle del registro de origen** (líneas y margen de la cotización, impacto de cancelación, saldo de CxC, etc.), consultas, efecto al aprobar y trazabilidad. Acciones: **Aprobar / Rechazar (motivo obligatorio) / Pedir información / Delegar**; el solicitante: **Responder consulta / Retirar**; CxP/comisión rechazada: **Reenviar a aprobación**. | Igual; el detalle solo si la solicitud es visible para el rol |
| `#/aprobaciones` › Reglas | `/aprobaciones/reglas` | Matriz de reglas: tipo, nivel, permiso, aprobadores actuales, monto desde (S/), SLA, autoaprobación, estado. Crear/editar/eliminar (nivel 2), restaurar por defecto. | Consulta: todos con `approvals.view`. Edición: `admin.settings.manage` (Tenant Admin) |
| `#/inbox` | `/inbox` | Diseño de 3 columnas: (1) lista con búsqueda y filtros canal / estado (abierta, pendiente, resuelta) / asignación (a mí, sin asignar, todas); (2) hilo con caja de respuesta, modo nota interna, plantillas con variables, adjuntos simulados, enviar / enviar y resolver; (3) ficha del contacto con vínculos CRM, reservas y cotizaciones del cliente y acciones. Cabecera con contadores, alcance del rol, **Canales e integración** y **Simular entrante** (demo del webhook). | `inbox.view` (lectura) / `inbox.manage` (acciones): Tenant Admin, Manager, Ventas, Operaciones |
| `#/inbox/{CNV-…}` | `/inbox/[code]` | Conversación seleccionada. < 1180 px la ficha se abre como drawer (botón **Ficha**); < 760 px una sola columna (lista o hilo con botón **Bandeja**). | Igual, sujeto al alcance del rol (sección 7) |

## 3. Modelo de datos PostgreSQL

Todas las tablas llevan `tenant_id uuid not null references tenants(id)`, RLS `tenant_isolation`, `created_at/updated_at timestamptz`, `created_by/updated_by uuid references iam_users(id)` salvo indicación.

### 3.1 `collab_approval_rules` — matriz de enrutamiento (prototipo: `DB.approvalRules`)

| Columna | Tipo | Null | Notas / FK / índice |
|---|---|---|---|
| id | uuid | no | PK (UUID v7) |
| tenant_id | uuid | no | FK `tenants` |
| code | varchar(30) | no | `RA-01`… `UNIQUE (tenant_id, code)` |
| request_type | varchar(30) | no | `CHECK IN ('quote','cancel','reversal','payable','commission','provider_rate','credit_b2b','refund','other')` |
| level | smallint | no | `CHECK (level IN (1,2))` |
| permission | varchar(80) | no | Nombre de permiso spatie (ej. `quotes.approve_internal`). Validar existencia en `permissions` (FK lógica, FormRequest) |
| min_amount_pen | numeric(14,2) | no | Default 0. Umbral en moneda base del tenant (equivalente PEN con `fx_rate` de la solicitud) |
| sla_hours | smallint | no | `CHECK (sla_hours BETWEEN 1 AND 720)` |
| forbid_self_approval | boolean | no | Default true (segregación de funciones) |
| is_active | boolean | no | Default true |
| is_origin_locked | boolean | no | true para nivel 1 de tipos de origen: permiso no editable |
| deleted_at | timestamptz | sí | SoftDeletes |

Restricciones: `UNIQUE (tenant_id, request_type, level, min_amount_pen) WHERE deleted_at IS NULL`; índice `(tenant_id, request_type, is_active)`. CHECK de negocio en servicio: cada `request_type` conserva ≥ 1 regla activa de nivel 1. Semilla por tenant (sección 11) al crear el tenant.

### 3.2 `collab_approval_requests` — solicitud unificada (prototipo: `DB.approvals`)

| Columna | Tipo | Null | Notas / FK / índice |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK |
| code | varchar(30) | no | `APR-2026-0001` por `sys_sequences`; `UNIQUE (tenant_id, code)` |
| request_type | varchar(30) | no | Mismo CHECK que reglas |
| is_generic | boolean | no | true = creada en la bandeja (credit_b2b/refund/other) |
| subject_type | varchar(40) | sí | Morph: `quote_versions`, `booking_reservations`, `fin_payments`, `fin_payables`, `fin_commissions`, `supplier_rate_requests`, `channel_b2b_agencies`. `CHECK` de valores |
| subject_id | uuid | sí | Id del registro de origen (sin FK física por ser polimórfica; integridad en servicio + job de consistencia) |
| subject_code | varchar(40) | sí | Código legible para listados (ej. `COT-2026-00189`) |
| subject_version | integer | sí | Versión de cotización aprobada (`v`) |
| dedupe_key | varchar(120) | no | `quote:{id}:v{n}`, `cancel:{res}:{ts}`, `payable:{id}:{round}`… |
| title | varchar(200) | no | |
| justification | text | sí | Nota del solicitante |
| requester_id | uuid | sí | FK `iam_users` (null si solicitante es proveedor o sistema) |
| requester_kind | varchar(20) | no | `CHECK IN ('user','supplier','system')` |
| requester_supplier_id | uuid | sí | FK `catalog_providers` |
| amount | numeric(14,2) | no | Monto/impacto |
| currency | char(3) | no | `CHECK IN ('PEN','USD')` |
| fx_rate | numeric(12,6) | no | TC al crear (Regla 15) para el umbral |
| amount_base | numeric(14,2) | no | `amount × fx_rate` (columna generada `STORED`) |
| status | varchar(30) | no | `CHECK IN ('pendiente','info','aprobada','rechazada','anulada')` |
| current_level | smallint | no | 1..2 |
| total_levels | smallint | no | Calculado al crear según reglas (recalculable mientras está pendiente) |
| delegated_to | uuid | sí | FK `iam_users` |
| due_at | timestamptz | sí | SLA del nivel actual |
| paused_at | timestamptz | sí | Inicio de pausa por pedido de información |
| decided_by | uuid | sí | FK `iam_users` |
| decided_at | timestamptz | sí | |
| decision_note | text | sí | Obligatorio si `rechazada` (CHECK) |
| closed_in_origin | boolean | no | true si se resolvió en el módulo de origen |
| origin_snapshot | jsonb | sí | Snapshot del registro al solicitar (margen, líneas, saldo) para auditoría |
| lock_version | integer | no | Concurrencia optimista (dos aprobadores simultáneos) |

Restricciones e índices: `UNIQUE (tenant_id, dedupe_key) WHERE status IN ('pendiente','info')` (evita duplicados vivos); `CHECK (status <> 'rechazada' OR decision_note IS NOT NULL)`; índices `(tenant_id, status, due_at)`, `(tenant_id, requester_id, created_at desc)`, `(tenant_id, request_type, status)`, `(tenant_id, delegated_to) WHERE delegated_to IS NOT NULL`, `(tenant_id, subject_type, subject_id)`.

### 3.3 `collab_approval_steps` — decisiones por nivel

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK |
| request_id | uuid | no | FK `collab_approval_requests` ON DELETE RESTRICT, índice |
| level | smallint | no | |
| rule_id | uuid | no | FK `collab_approval_rules` (regla vigente al decidir) |
| decision | varchar(20) | no | `CHECK IN ('aprobada','rechazada')` |
| decided_by | uuid | no | FK `iam_users` |
| note | text | sí | |
| decided_at | timestamptz | no | |

`UNIQUE (request_id, level)`; `UNIQUE (request_id, decided_by)` (un usuario no aprueba dos niveles).

### 3.4 `collab_approval_info_requests` — pedir información

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id / request_id | uuid | no | FK, índice `(request_id)` |
| asked_by | uuid | no | FK usuarios |
| question | text | no | |
| asked_at | timestamptz | no | |
| answer | text | sí | |
| answered_by | uuid | sí | FK usuarios |
| answered_at | timestamptz | sí | |

### 3.5 `collab_approval_events` — trazabilidad de la solicitud

`id, tenant_id, request_id (FK, índice), event varchar(40) CHECK IN ('creada','aprobada_nivel','aprobada','rechazada','info_solicitada','info_respondida','delegada','retirada','reenviada','cerrada_en_origen','sla_advertencia','sla_vencido'), actor_id uuid null, note text, payload jsonb, occurred_at timestamptz`. Solo inserción (sin update/delete; política RLS + trigger que bloquea UPDATE/DELETE).

### 3.6 `collab_conversations` (prototipo: `DB.conversations`)

| Columna | Tipo | Null | Notas / FK / índice |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK |
| code | varchar(30) | no | `CNV-0101`, `UNIQUE (tenant_id, code)` |
| channel | varchar(20) | no | `CHECK IN ('whatsapp','email','webchat','form','portal')` |
| channel_account_id | uuid | sí | FK `collab_channel_accounts` |
| external_thread_id | varchar(190) | sí | wa_id / Message-ID raíz / session id. `UNIQUE (tenant_id, channel, external_thread_id)` |
| contact_name | varchar(160) | no | |
| contact_email | citext | sí | índice |
| contact_phone | varchar(30) | sí | E.164 normalizado; índice |
| subject | varchar(200) | no | |
| status | varchar(20) | no | `CHECK IN ('abierta','pendiente','resuelta')` |
| priority | varchar(10) | no | `CHECK IN ('baja','media','alta','critica')` default `media` |
| tags | text[] | no | default `{}`; índice GIN |
| assignee_id | uuid | sí | FK `iam_users`; índice `(tenant_id, assignee_id, status)` |
| client_id | uuid | sí | FK `crm_clients` |
| lead_id | uuid | sí | FK `crm_leads` |
| reservation_id | uuid | sí | FK `booking_reservations` |
| quote_id | uuid | sí | FK `quote_quotes` |
| support_ticket_id | uuid | sí | FK `channel_support_tickets`, `UNIQUE` (1 conversación por ticket) |
| first_response_due_at | timestamptz | no | `created_at + SLA del canal` |
| first_response_at | timestamptz | sí | |
| last_inbound_at | timestamptz | sí | Ventana 24 h de WhatsApp; índice |
| last_message_at | timestamptz | no | Orden de la lista; índice `(tenant_id, status, last_message_at desc)` |
| unread_count | integer | no | default 0 (por asignado; en multiusuario ver `collab_conversation_reads`) |
| resolved_at / resolved_by | timestamptz / uuid | sí | |
| lock_version | integer | no | |

### 3.7 `collab_messages`

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id / conversation_id | uuid | no | FK, índice `(conversation_id, sent_at)` |
| direction | varchar(10) | no | `CHECK IN ('in','out','internal','system')` (internal = nota interna) |
| author_user_id | uuid | sí | FK usuarios (out/internal) |
| body | text | no | |
| template_id | uuid | sí | FK `collab_reply_templates` |
| external_message_id | varchar(190) | sí | `UNIQUE (tenant_id, channel, external_message_id)` = idempotencia de webhook |
| delivery_status | varchar(20) | sí | `CHECK IN ('encolado','enviado','entregado','leido','fallido')` |
| failure_reason | text | sí | |
| sent_at | timestamptz | no | |

### 3.8 `collab_message_attachments`

`id, tenant_id, message_id (FK, índice), file_name varchar(200), mime varchar(100), size_bytes integer CHECK (size_bytes <= 16777216), storage_path varchar(300)` (`tenants/{tenant}/inbox/{uuid}.{ext}`), `sha256 char(64), scan_status varchar(15) CHECK IN ('pendiente','limpio','infectado')`.

### 3.9 `collab_reply_templates` (prototipo: `DB.inboxTemplates`)

`id, tenant_id, code, name varchar(120), body text, channels text[], is_whatsapp_approved boolean, whatsapp_template_name varchar(120) null, whatsapp_language char(5) null, is_active boolean, deleted_at`. Variables soportadas: `{{nombre}}, {{asesor}}, {{empresa}}, {{cotizacion}}, {{reserva}}, {{telefono_emergencia}}` (validadas en FormRequest con regex).

### 3.10 `collab_channel_accounts` y `collab_inbound_events`

- `collab_channel_accounts`: `id, tenant_id, channel, name, credentials_encrypted text` (cast `encrypted:array`), `webhook_secret_encrypted`, `phone_number_id`, `mailbox`, `first_response_sla_minutes smallint`, `status CHECK IN ('activo','sandbox','inactivo')`.
- `collab_inbound_events`: `id, tenant_id, channel, external_event_id varchar(190), payload jsonb, received_at, processed_at, status CHECK IN ('recibido','procesado','duplicado','error'), error text`. `UNIQUE (channel, external_event_id)`; retención 30 días.

### 3.11 Campos que el prototipo agrega en tablas de otros módulos

| Tabla | Columna | Uso |
|---|---|---|
| `quote_versions` | `approval_request jsonb` → reemplazar por FK `approval_request_id uuid` | Solicitud del asesor (`v.approvalRequest`) |
| `booking_reservations` | `cancel_request_id uuid` | Solicitud de anulación (`r.cancelRequest`); estados `pendiente/aprobada/rechazada/retirada` |
| `fin_payments` | `reversal_request_id uuid` | Solicitud de reversión (`p.reversalRequest`, historial `reversalHistory`) |
| `fin_payables`, `fin_commissions` | `approval_round smallint default 1`, `observation text` | Rechazo = «observada»; reenvío incrementa ronda |
| `catalog_services` | `scheduled_cost numeric(14,2)`, `scheduled_cost_from date`, historial en `catalog_service_cost_history` | Tarifa de proveedor aprobada con vigencia futura |
| `channel_b2b_agencies` | historial `channel_b2b_credit_exceptions (agency_id, approval_request_id, amount, valid_until)` | Ampliación de crédito |

## 4. Máquinas de estado

### 4.1 Solicitud de aprobación

| Desde | Evento | Hacia | Quién | Efectos |
|---|---|---|---|---|
| — | Origen emite evento (o «Nueva solicitud») | `pendiente` (nivel 1) | Sistema / solicitante | Calcula niveles por regla y `amount_base`; `due_at = now + sla_hours(N1)`; notifica aprobadores N1 |
| `pendiente` | Aprobar (no último nivel) | `pendiente` (nivel+1) | Usuario con permiso del nivel, ≠ solicitante, ≠ aprobador previo | Step; nuevo `due_at`; limpia delegación; notifica N+1 y solicitante |
| `pendiente` | Aprobar (último nivel) | `aprobada` | Idem | **Ejecuta efecto de origen** (4.3) en la misma transacción; notifica |
| `pendiente` | Rechazar (motivo obligatorio) | `rechazada` | Idem | Efecto de rechazo en origen; notifica |
| `pendiente` | Pedir información | `info` | Idem | `paused_at`; notifica al solicitante (o al proveedor) |
| `info` | Responder | `pendiente` | Solicitante (o Manager/Admin) | `due_at += now − paused_at` |
| `pendiente` | Delegar | `pendiente` | Aprobador del nivel | `delegated_to` = usuario con el permiso del nivel |
| `pendiente`/`info` | Retirar | `anulada` | Solicitante (tipos quote/cancel/reversal/genéricos) | Marca la solicitud de origen `anulada/retirada` |
| `pendiente`/`info` | Resuelto en el módulo de origen | `aprobada`/`rechazada`/`anulada` | Listener | `closed_in_origin = true` |
| `rechazada` (payable/commission) | Reenviar | nueva solicitud `pendiente` (ronda+1) | Solicitante o `finance.payables`/`finance.commissions` | Limpia observación del registro |

Flujo visible: `Solicitud → Nivel 1 → [Nivel 2] → Efecto en origen`.

### 4.2 Conversación

| Desde | Evento | Hacia | Efectos |
|---|---|---|---|
| — | Mensaje entrante (webhook / formulario / ticket) | `abierta` | `first_response_due_at`; reconoce cliente por email/teléfono; sin asignar |
| `abierta` | Respuesta del agente | `pendiente` (espera cliente) | 1.ª respuesta registra `first_response_at`; si no tenía responsable se asigna al que responde |
| `abierta`/`pendiente` | Enviar y resolver / Resolver | `resuelta` | Ticket del portal → `resuelta` |
| `pendiente`/`resuelta` | Mensaje entrante | `abierta` | Reapertura automática + evento de sistema |
| `resuelta` | Reabrir | `abierta` | Ticket → `en_proceso` |
| cualquiera | Nota interna | sin cambio | No se envía al cliente |

### 4.3 Efecto por tipo (idéntico al módulo de origen)

| Tipo | Aprobar (último nivel) | Rechazar |
|---|---|---|
| `quote` | `internalApproval {by, ts, reason, override, overCap}` en la versión; `approvalRequest.status = aprobada`; audita `Cotización/aprobacion_interna`; notifica al asesor. Habilita envío. | `approvalRequest.status = rechazada` (se crea si no existía); audita `rechazar_aprobacion_interna`; notifica |
| `cancel` | Pantalla de impacto PROC-028 y efecto completo de `cancelModal`: libera cupos por salida, servicios `cancelado`, vouchers `anulado`, CxC abiertas `cancelada`, CxC de penalidad o nota de crédito + pago de reembolso `pendiente`, orden operativa `cancelada`, `cancelRequest.aprobada`, notificaciones cliente/operaciones/finanzas | `cancelRequest.rechazada` + notificación (reserva sigue vigente) |
| `reversal` | Pago `revertido` (+`revertReason/At/By`, historial), crédito B2B devuelto si método «Crédito B2B», `recalcCxC` y auditoría de saldo, email al cliente | Solicitud a `reversalHistory`, se elimina la solicitud viva; historial del pago |
| `payable` | `approved = true`, `approvedBy/At`, estado recalculado; si costo adicional audita impacto en rentabilidad | `observation` + `approvalRejected` (queda «observada», reenvío genera ronda 2) |
| `commission` | `calculada → aprobada` (+historial) | `observation`, sigue `calculada` hasta reenvío |
| `provider_rate` | Solicitud `aprobada`; costo del servicio actualizado si la vigencia ya empezó o `scheduledCost` con fecha; historial de costos; notificación al proveedor (extranet) | Solicitud `rechazada` + notificación al proveedor |
| `credit_b2b` | Línea de crédito += monto; reactiva agencia bloqueada por consumo; historial de excepciones; email a la agencia | Sin efecto |
| `refund` | Crea pago `reembolso` en `pendiente` (Finanzas lo procesa) | Sin efecto |
| `other` | Solo registro/evidencia | Sin efecto |

## 5. Reglas de negocio

| ID | Regla | Dónde se valida |
|---|---|---|
| APR-BR-01 | Toda solicitud se enruta por `collab_approval_rules`: niveles aplicables = reglas activas del tipo con `min_amount_pen ≤ amount_base`; por nivel se usa la de mayor umbral. | `ApprovalRouter` (Service) |
| APR-BR-02 | El nivel 1 de un tipo de origen usa el permiso del módulo (`quotes.approve_internal`, `reservations.cancel`, `finance.payments.revert`, `finance.payables`, `finance.commissions`, `catalog.providers.manage`) y no es editable. | FormRequest `UpdateApprovalRuleRequest` + constraint `is_origin_locked` |
| APR-BR-03 | Segregación de funciones: el solicitante no aprueba su propia solicitud (configurable por regla, por defecto prohibido) y un usuario no aprueba dos niveles. | Policy `ApprovalRequestPolicy@decide` + `UNIQUE (request_id, decided_by)` |
| APR-BR-04 | Aprobador elegible = usuario activo con `approvals.view` + permiso del nivel; si hay delegación, solo el delegado. | Policy |
| APR-BR-05 | Cotización con descuento > 10 % (tope) solo se aprueba con `quotes.discount_override` (se puede rechazar sin él). | Service + Policy |
| APR-BR-06 | Disparador de cotización: versión vigente en estado abierto con descuento > 5 % o margen < 15 % y sin `internalApproval`; si está en borrador solo con solicitud del asesor; si ya fue enviada sin aprobación se crea «detectada por regla». | Listener `QuoteVersionSaved` |
| APR-BR-07 | Rechazo con motivo obligatorio. | FormRequest + CHECK DB |
| APR-BR-08 | El efecto en origen y el cierre de la solicitud ocurren en la misma transacción con `lock_version` del registro de origen; si el origen cambió de estado (p. ej. reserva ya completada, pago no aplicado) → 409 y la solicitud no cambia. | `ApprovalDecisionService` (DB::transaction + `If-Match`) |
| APR-BR-09 | Reversión solo sobre pagos `confirmado`/`aplicado` (PROC-043). Anulación bloqueada si la reserva está `cancelada`/`completada`. | Service |
| APR-BR-10 | Pedir información pausa el SLA; al responder, `due_at` se extiende por el tiempo en pausa. | Service |
| APR-BR-11 | Una sola solicitud viva por `dedupe_key`. | Índice único parcial |
| APR-BR-12 | Si la solicitud se resuelve en el módulo de origen (p. ej. gerente aprueba desde la cotización), la bandeja la cierra como `closed_in_origin`. | Listener de eventos de origen |
| APR-BR-13 | Tarifa de proveedor: vigencia con ≥ 30 días de anticipación (validado en extranet); si la fecha ya pasó al aprobar, se aplica de inmediato. | FormRequest extranet + Service |
| APR-BR-14 | Umbrales en moneda base con TC de la solicitud (Regla 15). | Columna generada `amount_base` |
| APR-BR-15 | Cada tipo conserva al menos una regla activa de nivel 1; el permiso de una regla debe tener al menos un usuario activo. | Service de reglas |
| INB-BR-01 | SLA de 1.ª respuesta por canal: web chat 15 min, WhatsApp 30 min, formulario 2 h, email 4 h, portal 4 h (configurable en `collab_channel_accounts`). | Scheduler + Service |
| INB-BR-02 | WhatsApp: fuera de 24 h desde el último mensaje del cliente solo se envían plantillas aprobadas por Meta. | `WhatsAppAdapter` + FormRequest `SendMessageRequest` |
| INB-BR-03 | Responder una conversación sin responsable la asigna al agente que responde. | Service |
| INB-BR-04 | Ventas: ve y actúa sobre conversaciones asignadas a él o sin asignar; solo puede tomarlas (no reasignar a otro). Operaciones: asignadas + pasajeros en viaje (reserva `en_operacion` o hoy dentro de las fechas del viaje). Manager/Admin: todas. | Policy `ConversationPolicy` + scope de consulta |
| INB-BR-05 | Crear lead aplica PROC-004 (detección de duplicados por email/teléfono contra leads y clientes) y guarda el origen (`conversation_id`). | `CreateLeadFromConversation` |
| INB-BR-06 | Vincular a cliente solo a clientes dentro del alcance (Ventas: su cartera). | Policy |
| INB-BR-07 | Crear actividad requiere vínculo a lead o cliente. | FormRequest |
| INB-BR-08 | Mensajes entrantes idempotentes por `external_message_id`; un fallo del proveedor no bloquea la conversación (Regla 21). | Webhook controller + job |
| INB-BR-09 | Tickets de portal se reflejan como conversación de canal `portal`; responder/resolver actualiza el ticket (`en_proceso`/`resuelta`) y su log. | Listener `SupportTicketCreated` + Service |
| INB-BR-10 | Notas internas nunca se envían ni se exponen en portales/API públicas. | Resource + Policy |
| INB-BR-11 | Adjuntos ≤ 16 MB, tipos permitidos, escaneo antivirus antes de enviar. | FormRequest + job `ScanAttachment` |

## 6. API REST

| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| GET | `/api/v1/approvals?filter[box]=mine\|sent\|all\|history&filter[type]=&filter[status]=&filter[sla]=overdue\|warning\|ok\|paused&sort=due_at` | `approvals.view` | Listados por pestaña (alcance por rol en servidor) | — |
| GET | `/api/v1/approvals/counters` | `approvals.view` | Contadores (para mí, vencidas, enviadas en curso) para menú y Reverb | — |
| GET | `/api/v1/approvals/{code}?include=steps,events,info_requests,subject` | `approvals.view` + visibilidad | Detalle con snapshot del origen | — |
| POST | `/api/v1/approvals` | `approvals.view` | Crear solicitud genérica (`credit_b2b`, `refund`, `other`) | `Idempotency-Key` |
| POST | `/api/v1/approvals/{code}/approve` | Regla del nivel | Aprobar nivel actual / último nivel con efecto (body `note`, datos extra p. ej. motivo de cancelación) | `Idempotency-Key` + `If-Match: lock_version` |
| POST | `/api/v1/approvals/{code}/reject` | Regla del nivel | Rechazar (`note` obligatorio) | `Idempotency-Key` + `If-Match` |
| POST | `/api/v1/approvals/{code}/info-requests` | Regla del nivel | Pedir información | `If-Match` |
| POST | `/api/v1/approvals/{code}/info-requests/{id}/answer` | Solicitante / Manager | Responder | — |
| POST | `/api/v1/approvals/{code}/delegate` | Regla del nivel | Delegar (`user_id` con permiso) | `If-Match` |
| POST | `/api/v1/approvals/{code}/withdraw` | Solicitante | Retirar | `If-Match` |
| POST | `/api/v1/approvals/{code}/resubmit` | Solicitante / `finance.payables` / `finance.commissions` | Nueva ronda para CxP/comisión observada | `Idempotency-Key` |
| GET | `/api/v1/approvals/cancel-preview/{reservation}` | `reservations.cancel` o regla N | Cálculo de penalidad/reembolso (misma función que PROC-028) | — |
| GET/POST/PATCH/DELETE | `/api/v1/approval-rules[/{id}]` | GET `approvals.view`; escritura `admin.settings.manage` | Matriz de reglas; DELETE solo nivel 2 | `If-Match` |
| POST | `/api/v1/approval-rules/reset` | `admin.settings.manage` | Restaurar matriz por defecto | — |
| GET | `/api/v1/inbox/conversations?filter[channel]=&filter[status]=&filter[assignee]=me\|none&filter[q]=&sort=-last_message_at&page[cursor]=` | `inbox.view` | Lista acotada por rol | — |
| GET | `/api/v1/inbox/conversations/{code}?include=messages,attachments,client,lead,reservation` | `inbox.view` + Policy | Hilo + ficha | — |
| POST | `/api/v1/inbox/conversations/{code}/messages` | `inbox.manage` | Responder (`body`, `template_id`, `attachments[]`, `resolve`) → encola envío | `Idempotency-Key` |
| POST | `/api/v1/inbox/conversations/{code}/notes` | `inbox.manage` | Nota interna | — |
| POST | `/api/v1/inbox/conversations/{code}/assign` | `inbox.manage` (Ventas solo `me`) | Asignar / tomar | `If-Match` |
| POST | `/api/v1/inbox/conversations/{code}/resolve` · `/reopen` | `inbox.manage` | Cambiar estado | `If-Match` |
| PATCH | `/api/v1/inbox/conversations/{code}` | `inbox.manage` | Prioridad, etiquetas | `If-Match` |
| POST | `/api/v1/inbox/conversations/{code}/link` | `inbox.manage` + `crm.clients.manage` | Vincular cliente/reserva | — |
| POST | `/api/v1/inbox/conversations/{code}/lead` | `crm.leads.manage` | Crear lead (PROC-004) | `Idempotency-Key` |
| POST | `/api/v1/inbox/conversations/{code}/activities` | `crm.activities.manage` | Crear actividad vinculada | — |
| POST | `/api/v1/inbox/attachments` | `inbox.manage` | URL firmada de subida S3 | — |
| GET/POST/PATCH | `/api/v1/inbox/templates[/{id}]` | lectura `inbox.view`; escritura `admin.settings.manage` | Plantillas | — |
| POST | `/api/v1/integrations/whatsapp/webhook` · `/email/inbound` · `/public/contact` | Firma HMAC / token de canal / rate limit | Entradas de canal → `collab_inbound_events` → job | Idempotente por `external_event_id` |
| GET | `/api/v1/integrations/whatsapp/webhook` | Verify token | Verificación de suscripción Meta | — |

Crear cotización desde el inbox no tiene endpoint propio: el frontend navega a `/cotizaciones/nueva/[oppCode]` (o sin oportunidad) y registra el evento en la conversación.

## 7. Permisos y comportamiento por rol

| Rol | Aprobaciones | Inbox |
|---|---|---|
| Tenant Admin (U01) | Ve todo. Aprueba todo tipo para el que tiene permiso (todos), excepto sus propias solicitudes. Único que edita la matriz (`admin.settings.manage`). Aprueba las cotizaciones del Manager (autoaprobación prohibida). | Todas las conversaciones, todas las acciones, reasigna a cualquiera. |
| Manager (U02) | Ve todo el tenant. Aprueba cotizaciones, anulaciones, crédito B2B N1, reembolsos N1, otras excepciones, CxP (`finance.payables`), comisiones y nivel 2 de reversiones ≥ S/ 10 000. No aprueba reversiones N1 ni tarifas de proveedor (sin `finance.payments.revert` / `catalog.providers.manage`). Matriz en solo lectura. | Todas; reasigna; no configura plantillas. |
| Ventas (U03) | Solo **sus** solicitudes (Enviadas por mí / Todas según rol = propias). Crea solicitudes genéricas y responde consultas; puede retirar. Sin botones de decisión. | Asignadas a ella + sin asignar; puede tomar (no reasignar a otro asesor); crear lead, vincular a clientes de su cartera, crear cotización y actividad. Conversación ajena → pantalla «fuera de su alcance». |
| Operaciones (U05) | Aprueba tarifas de proveedor; sigue las CxP que validó (solicitante). No ve cotizaciones ni anulaciones. | Asignadas + pasajeros en viaje (ej. Keller/huelga). Responde, notas, asigna entre Operaciones/Gerencia. No crea cotización/actividad/lead (botones bloqueados con motivo). |
| Finanzas (U07) | Aprueba reversiones (N1), CxP, comisiones, nivel 2 de crédito B2B (`finance.receivables`), reembolsos ≥ S/ 1 000 y anulaciones ≥ S/ 20 000. | Sin acceso (`inbox.view` no asignado). |
| Contabilidad (U08) | Sin acceso (403). Aunque tiene `finance.receivables`, no es aprobador elegible por no tener `approvals.view`. | Sin acceso. |
| Externos | Sin acceso. El proveedor es solicitante de `provider_rate` y recibe el resultado/consultas en la extranet; cliente/pasajero originan tickets de portal. | Sin acceso; ven sus tickets en el portal. |

Datos sensibles: el margen y costos de una cotización en el detalle solo se muestran con `finance.profitability`, `catalog.products.view_profitability` o `quotes.approve_internal`, o si es el dueño (Ventas).

## 8. Eventos, jobs (colas Redis) y notificaciones

| Evento / job | Cola | Disparo | Efecto |
|---|---|---|---|
| Listeners `QuoteApprovalRequested`, `QuoteVersionSaved`, `ReservationCancelRequested`, `PaymentReversalRequested`, `PayableRegistered`, `CommissionCalculated`, `SupplierRateRequested` | `default` | Eventos de dominio de cada módulo | `ApprovalRequestFactory::fromSubject()` (reemplaza el `sync()` del prototipo) |
| Listeners `QuoteInternallyApproved`, `ReservationCancelled`, `PaymentReverted`, `PayableApproved`… | `default` | Resolución en origen | Cierra solicitud `closed_in_origin` |
| `ApprovalRequested`, `ApprovalLevelApproved`, `ApprovalDecided`, `ApprovalInfoRequested`, `ApprovalDelegated` | `notifications` | Servicio de decisión | Notificación portal/email a aprobadores, solicitante, delegado; broadcast Reverb `tenant.{t}.user.{u}` (contador de menú) |
| `approvals:sla-check` (scheduler cada 5 min, `ShouldBeUnique`) | `low` | — | 75 % del SLA: advertencia al aprobador; vencido: evento `sla_vencido`, notificación al aprobador y al Manager (escalamiento) |
| `ExecuteApprovalEffect` | `critical` | Aprobación final (síncrono en transacción; job solo para efectos externos: emails, PDF NC) | Outbox `sys_outbox` para notificaciones de cliente/proveedor |
| `ProcessInboundMessage` | `integrations` | Webhook / formulario / email inbound | Idempotencia, resolución de contacto, crea/actualiza conversación, reabre, `MessageReceived` Reverb |
| `SendOutboundMessage` (tries 5, backoff exponencial) | `integrations` | Respuesta del agente | Envío por adaptador (`WhatsAppCloudAdapter`, `SmtpAdapter`, `PortalAdapter`); estado de entrega |
| `ProcessDeliveryStatus` | `integrations` | Webhook de estado | `delivery_status` (entregado/leído/fallido) |
| `inbox:first-response-sla` (cada minuto) | `low` | — | Alertas por conversaciones sin respuesta próximas a vencer / vencidas; notificación a Manager |
| `ScanAttachment` | `default` | Subida | Antivirus; bloquea envío si infectado |
| `SupportTicketCreated` listener | `default` | Portal cliente/pasajero | Crea conversación canal `portal` |

Notificaciones (PROC-059): `aprobacion.solicitada`, `aprobacion.resultado`, `aprobacion.informacion`, `aprobacion.delegada`, `cotizacion.aprobacion_aprobada/rechazada`, `reserva.anulacion_aprobada/rechazada`, `reserva.cancelada`, `pago.revertido`, `pago.reembolso`, `proveedor.tarifa`, `b2b.credito`, `inbox.asignada`, `inbox.nueva`, `inbox.mensaje`, `inbox.respuesta`, `soporte.resuelto`, `lead.asignado`, `actividad.asignada`.

## 9. Auditoría

| Entidad | Acciones registradas |
|---|---|
| Aprobación | `crear`, `aprobar_nivel_N`, `aprobar`, `rechazar`, `pedir_informacion`, `responder_informacion`, `delegar`, `retirar` (antes/después: estado y nivel, nota) |
| Regla de aprobación | `crear`, `editar`, `eliminar`, `restaurar` |
| Efectos en origen (mismas acciones que los módulos) | Cotización `aprobacion_interna`/`rechazar_aprobacion_interna`/`anular_solicitud_aprobacion`; Reserva `cancelar`/`rechazar_solicitud_anulacion`/`retirar_solicitud_anulacion`; Disponibilidad `liberar`; Voucher `anular`; Cuenta por cobrar `cancelar`/`recalcular_saldo`; Nota de crédito `emitir`; Orden operativa `cancelar`; Pago `revertir`/`rechazar_solicitud_reversion`/`crear_reembolso`; Cuenta por pagar `aprobar`/`observar`/`reenviar_aprobacion`; Costo/Rentabilidad `impacto_costo`; Comisión `aprobar`/`observar`; Tarifa proveedor `aprobar_actualizacion`/`rechazar_actualizacion`; Agencia B2B `ampliar_credito` |
| Conversación | `crear` (webhook), `asignar`, `responder` (con cumplimiento de SLA de 1.ª respuesta), `responder_y_resolver`, `resolver`, `reabrir`, `mensaje_entrante` (reapertura), `clasificar`, `vincular_cliente`, `vincular_lead`, `crear_cotizacion` |
| Lead / Actividad | `crear` (con origen `conversation_id`) |
| Acceso | 403 por ruta (core) |

`collab_approval_events` y `audit_logs` son solo inserción; cada entrada lleva `correlation_id` del request.

## 10. Casos de prueba mínimos

**Pest (feature):**
1. Aislamiento: usuario del tenant A no lista ni decide solicitudes/conversaciones del tenant B (404).
2. RBAC: Ventas `POST /approve` → 403; Contabilidad `GET /approvals` → 403; Finanzas `GET /inbox` → 403.
3. Autoaprobación: Manager dueño de COT-00191 no puede aprobarla (403 `APR-BR-03`); Tenant Admin sí.
4. Doble nivel: crédito B2B S/ 10 000 → N1 Manager, N2 Finanzas; el mismo usuario no puede aprobar ambos niveles.
5. Tope: descuento 12 % sin `quotes.discount_override` → 422 `APR-BR-05`; con override aprobado.
6. Efecto cotización: aprobar crea `internalApproval` y la versión puede enviarse; rechazar marca `approvalRequest.rechazada`.
7. Efecto anulación: aprobar cancela reserva con mismas penalidades que `POST /reservations/{id}/cancel` (comparar resultados), libera cupos, anula vouchers, crea NC/reembolso.
8. Efecto reversión: pago `revertido`, CxC recalculada; reversión sobre pago `pendiente` → 409.
9. CxP rechazada → observada; reenvío crea ronda 2 con nuevo `dedupe_key`.
10. Idempotencia: dos `approve` con la misma `Idempotency-Key` ejecutan un solo efecto; dos aprobadores concurrentes → uno recibe 409 (`lock_version`).
11. SLA: pedir información pausa y extiende `due_at`; `approvals:sla-check` emite advertencia y vencimiento.
12. Cierre en origen: aprobar desde `/quotes/{id}/internal-approval` cierra la solicitud de la bandeja.
13. Reglas: no se puede desactivar el único nivel 1; no se puede editar permiso de nivel 1 de origen.
14. Inbox ownership: Ventas lista solo asignadas + sin asignar; no puede reasignar a otro asesor; Operaciones ve pasajeros en viaje.
15. Webhook WhatsApp: firma inválida → 401; mismo `message_id` dos veces → un solo mensaje; mensaje en conversación resuelta la reabre.
16. Ventana 24 h: texto libre fuera de ventana → 422 `INB-BR-02`; plantilla aprobada → 202.
17. 1.ª respuesta: registra `first_response_at`, auto-asigna, audita cumplimiento de SLA.
18. Crear lead desde conversación detecta duplicado por email/teléfono.
19. Ticket de portal → conversación; resolver actualiza ticket a `resuelta`.
20. Nota interna no aparece en endpoints de portal.

**Playwright (E2E sobre Next.js):**
- E2E-AP-01 Ventas envía descuento 8 % (COT-00189 v2) → Manager aprueba desde la bandeja → Ventas ve «Aprobada» y puede enviar al cliente.
- E2E-AP-02 Ventas solicita anulación → Manager pide información → Ventas responde → Manager aprueba y la reserva queda cancelada con NC.
- E2E-AP-03 Crédito B2B dos niveles (Manager → Finanzas) y agencia reactivada.
- E2E-AP-04 Operaciones aprueba tarifa del proveedor; el proveedor ve «aprobada» en la extranet.
- E2E-IB-01 Chat web nuevo → Ventas lo toma con plantilla, crea lead y actividad, resuelve.
- E2E-IB-02 Keller (WhatsApp, en viaje) visible para Operaciones; nota interna no visible en portal del pasajero.
- Responsive 400 px sin scroll horizontal en `/aprobaciones` y `/inbox/[code]`.

## 11. Cambios y decisiones respecto a la documentación inicial

**Módulo nuevo: no estaba en la documentación inicial.** Motivo: las aprobaciones estaban dispersas (banner en la cotización, alerta en la reserva, botones en Finanzas, solicitudes de tarifa sin circuito interno de aprobación), sin SLA, sin delegación ni vista consolidada para el aprobador; y la atención al cliente (WhatsApp, email, chat, formularios, tickets del portal) no tenía bandeja, por lo que los mensajes no quedaban vinculados al CRM ni medidos. Los ERP y CRM de mercado (workflows de aprobación, bandejas de atención) resuelven ambos con un módulo de colaboración transversal; el cliente pidió un SaaS completo.

| Decisión | Detalle |
|---|---|
| Bandeja como **orquestador**, no duplicado | La solicitud es polimórfica sobre el registro de origen; el efecto se ejecuta con los mismos servicios de dominio que usan los módulos (en el prototipo: lógica portada literalmente de `ventas.js › cancelModal/decideApproval`, `finanzas.js › revertPayment/approveCxP/approveComm`). Regla 18: los canales no duplican reglas core. |
| Matriz configurable con nivel 2 por umbral | Valores por defecto: cotización N1 `quotes.approve_internal` SLA 8 h; anulación N1 `reservations.cancel` + N2 `finance.payments.revert` ≥ S/ 20 000; reversión N1 `finance.payments.revert` + N2 `quotes.approve_internal` ≥ S/ 10 000; CxP N1 `finance.payables` + N2 `finance.profitability` ≥ S/ 10 000; comisión N1 `finance.commissions`; tarifa proveedor N1 `catalog.providers.manage`; crédito B2B N1 `quotes.approve_internal` + N2 `finance.receivables` ≥ S/ 5 000; reembolso N1 `quotes.approve_internal` + N2 `finance.payments.revert` ≥ S/ 1 000; otras N1 `quotes.approve_internal`. |
| Rechazo de CxP y comisión | Los módulos de origen no tenían «rechazar»: se define como **observar** (queda en su estado, con observación) + reenvío en nueva ronda. |
| Solicitud de reversión | `payments[].reversalRequest` solo existía como lectura en el dashboard; ahora tiene ciclo completo y al rechazarse se mueve a `reversalHistory` para que el dashboard deje de contarla. |
| Tarifa de proveedor | `DB.providerRequests` no tenía aprobación interna: se agrega con vigencia programada (`scheduledCost`) y notificación al proveedor. |
| Tickets de portal en el inbox | `DB.supportTickets` (portal cliente/pasajero) se reflejan como conversaciones de canal `portal`, manteniendo el ticket como fuente para el portal. |
| Datos semilla | COT-2026-00189 v2 (8 % de descuento, solicitud de Lucía), anulación del borrador RES-2026-000349 (Emily consolida en COT-00188), reversión PAG-01921 (Yape en soles, BNK-2), 2 solicitudes de tarifa, 4 solicitudes genéricas (2 históricas), 7 conversaciones + 1 ticket coherentes con clientes, reservas e incidencias existentes. |
| Contador en menú | El prototipo lo muestra en la vista (título y pestaña); en producción, `GET /approvals/counters` + Reverb alimentan el badge del menú. |

## 12. Supuestos y puntos a validar con negocio

1. Umbrales y SLA por defecto (tabla sección 11) y si el SLA debe contarse en horas hábiles (calendario laboral y feriados del tenant) en lugar de horas corridas.
2. Escalamiento automático al vencer el SLA: ¿solo notificar al Manager o reasignar la solicitud?
3. ¿El Tenant Admin puede aprobar cualquier tipo (superusuario) o debe respetar estrictamente la matriz? El prototipo lo trata como un usuario más con todos los permisos.
4. Anulaciones de reservas con servicios ya ejecutados y reembolsos fuera de política: ¿exigir siempre nivel financiero?
5. ¿El proveedor puede responder «Pedir información» desde la extranet? (prototipo: se le notifica; la respuesta la registra Operaciones).
6. Proveedor de WhatsApp Business (Cloud API de Meta vs. BSP), números por sede, y plantillas a aprobar; buzón de email (inbound parse vs. IMAP).
7. Cierre automático de conversaciones «pendiente» sin respuesta del cliente (propuesta: 72 h) y encuesta de satisfacción al resolver.
8. Retención de mensajes y adjuntos (Ley 29733 de protección de datos personales) y consentimiento para WhatsApp.
9. Asignación automática (round-robin por sede/idioma) para conversaciones sin asignar: no incluida en el prototipo.
10. Horario de atención por canal y respuesta automática fuera de horario.