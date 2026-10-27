continuemos con 09:
# Administración, reportes y panel ejecutivo

> Fuente: `proto/assets/js/modules/admin.js` (1 951 líneas), `dashboard.js` (panel ejecutivo/Manager, l. 34–134), `core.js` (servicios transversales usados) y `data.js` (semillas `tenants`, `plans`, `sedes`, `roles`, `permissionGroups`, `rolePerms`, `users`, `settings`, `notifications`, `audit`, `jobs`, `integrations`, `monthly`).
> Documento hermano: **`00-transversal-seguridad-rbac.md`** (autenticación, tenant, RLS, spatie/permission, matriz completa de permisos, ROLE_UX, ACCESS, ownership, auditoría, secuencias, idempotencia). Aquí solo se referencian esas piezas.
> Fuera de alcance (otros módulos nuevos, con su propia especificación): `admin/suscripcion`, `admin/api`, `admin/plataforma` (plataforma.js), `aprobaciones`/`inbox` (colaboracion.js). Están en el menú y en `ACCESS` pero no los registra admin.js.

---

## 1. Propósito y procesos cubiertos

| Proceso | Qué cubre el módulo | Pantalla |
|---|---|---|
| PROC-001 Alta de tenant | Consola de plataforma: tenants, planes, entitlements, asistente de alta en 11 pasos, suspensión/reactivación, cambio de plan, prueba de aislamiento E2E-08 | `admin/tenants` |
| PROC-002 Usuarios, roles y permisos | Ciclo de vida de identidad (invitación → activación → baja lógica), MFA, sedes, roles base y personalizados, matriz módulo × permiso × rol, mapa de acceso rol × pantalla | `admin/usuarios`, `admin/roles` |
| PROC-003 Configuración | General, moneda/TC/IGV/redondeo, series y correlativos, políticas comerciales, parámetros operativos, canales habilitados por plan | `admin/configuracion` |
| PROC-059 Notificaciones | Matriz evento × canal, reglas (destinatarios, momento, condición), plantillas versionadas por canal, log de envíos con reintento y canal alterno | `admin/notificaciones` |
| PROC-064 / PROC-065 Integraciones y errores | Adapters, prueba de conexión, configuración (entorno, reintentos, backoff, timeout), scheduler de jobs, errores con clasificación y backoff | `admin/integraciones` |
| PROC-063 Auditoría | Bitácora append-only, filtros, drawer con eventos relacionados, exportación como job; vista de plataforma para Super Admin | `admin/auditoria` |
| PROC-060 Reportes | 7 reportes (comercial, cotizaciones, reservas, operaciones, finanzas, proveedores, clientes) sobre read models, filtros periodo/sede, pestañas por rol | `reportes` |
| PROC-061 Exportación | Solicitud → Permiso → Job → Procesar → Archivo → Disponible → Descarga → Auditoría; envíos programados (crean job en scheduler) | modal en `reportes` y `admin/auditoria` |
| §25–26 Asíncronos / programados | 9 jobs del scheduler + jobs de reportes programados | `admin/integraciones/jobs` |
| Panel ejecutivo | Dashboard de Tenant Admin y Manager (KPIs, aprobaciones pendientes, desempeño por asesor) | `dashboard` |

---

## 2. Pantallas

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso (router + permiso) |
|---|---|---|---|
| `#/admin/tenants` | `app/(plataforma)/tenants` | KPIs de plataforma (activos, usuarios, MRR/ARR, almacenamiento), tabla de tenants, tarjetas de planes, matriz de entitlements, prueba de aislamiento con log de API | Super Admin (`admin.tenant.manage`) |
| `#/admin/tenants/{TNT-id}` | `…/tenants/[id]` (drawer) | Detalle, consumo usuarios/almacenamiento vs plan, entitlements, historial; acciones Cambiar plan, Suspender/Reactivar | Super Admin |
| `#/admin/tenants/nuevo` | `…/tenants/new` | Asistente de alta PROC-001 (11 pasos) | Super Admin |
| `#/admin/usuarios` | `app/(backoffice)/admin/usuarios` | KPIs (activos internos/canal, sin MFA, invitaciones, sedes), ciclo de vida, pestañas Usuarios / Sedes | Tenant Admin (`admin.users.manage`) |
| `#/admin/usuarios/{U-id}` | `…/usuarios/[id]` (drawer) | Detalle, permisos efectivos del rol, actividad reciente, Editar / Resetear MFA / Activar-Desactivar | Tenant Admin |
| `#/admin/usuarios/sedes` | `…/usuarios/sedes` | CRUD de sedes con límites de plan | Tenant Admin |
| `#/admin/roles` · `/{roleId}` | `…/admin/roles/[role]` | Lista de roles, detalle del rol, matriz de permisos editable, auditoría de cambios de permisos | Tenant Admin (`admin.roles.manage`) |
| `#/admin/roles/mapa` | `…/admin/roles/mapa` | Mapa de acceso Rol × Pantalla + resumen por rol (qué puede / no puede / alcance) | Tenant Admin |
| `#/admin/configuracion/{general\|moneda\|series\|politicas\|operativos\|canales}` | `…/admin/configuracion/[tab]` | Configuración del tenant con guardado por diferencia y auditoría por campo | Tenant Admin (`admin.settings.manage`) |
| `#/admin/notificaciones/{matriz\|plantillas\|log}` | `…/admin/notificaciones/[tab]` | Matriz evento × canal, editor de plantillas, log de envíos | Tenant Admin (`admin.settings.manage`) |
| `#/admin/integraciones/{adapters\|jobs\|errores}` | `…/admin/integraciones/[tab]` (tenant) · `app/(plataforma)/integraciones` (SA) | Adapters, scheduler, errores. Super Admin ve además “Salud por tenant” | Tenant Admin (`admin.settings.manage`), Super Admin (`admin.tenant.manage`) |
| `#/admin/auditoria` · `/{AUD-id}` | `…/admin/auditoria` · `app/(plataforma)/auditoria` | Bitácora, gráficos, filtros, drawer de evento, exportar | Tenant Admin, Manager, Super Admin (`admin.audit.view`; SA solo eventos de plataforma) |
| `#/reportes/{comercial\|cotizaciones\|reservas\|operaciones\|finanzas\|proveedores\|clientes}` | `app/(backoffice)/reportes/[tab]` | Centro de reportes con filtros periodo/sede, KPIs, gráficos, tabla detalle, exportar/programar | TA, MG, Ventas, Operaciones, Finanzas, Contabilidad (`reports.view`); pestañas según rol (§7.3) |
| `#/dashboard` (TA/MG) | `app/(backoffice)/dashboard` | Panel ejecutivo; Manager añade bloque de aprobaciones, metas y desempeño por asesor | Tenant Admin, Manager (otros roles tienen su propio panel, documentado en sus módulos) |

Menú lateral: grupo “Administración” visible si el rol puede abrir al menos una de sus pantallas (`Access.canRoute` por ítem). Manager solo ve “Auditoría”. Super Admin ve la consola de plataforma y su migas apuntan a `#/admin/tenants` (“Consola de plataforma”).

---

## 3. Modelo de datos PostgreSQL

Convenciones de `CONVENCIONES.md` §1–2. Tablas de plataforma sin `tenant_id`. Usuarios, roles/permisos (spatie), `audit_events`, `sys_sequences`, `sys_idempotency_keys` y `sys_outbox` se definen en `00-transversal-seguridad-rbac.md` §3.

### 3.1 `plans` (plataforma)
| columna | tipo | null | notas |
|---|---|---|---|
| id | varchar(30) | no | PK: `starter`, `professional`, `enterprise` (semilla `Starter`/`Professional`/`Enterprise`) |
| name | varchar(60) | no | |
| rank | smallint | no | 1/2/3 (`PLAN_RANK`); usado para entitlements de canales `>=` |
| price_monthly | numeric(14,2) | no | 490 / 1190 / 2890 |
| currency | char(3) | no | `PEN`; precio “+ IGV” |
| max_users | integer | sí | 5 / 15 / NULL = ilimitado |
| max_storage_gb | integer | no | 5 / 25 / 100 |
| max_sedes | integer | sí | 1 / 2 / NULL = ilimitado |
| support_level | varchar(80) | no | “Correo, horario laboral” / “Correo y chat, 12 h” / “Dedicado 24/7 · SLA 99.9%” |
| module_count | smallint | no | 6 / 12 / 18 (se copia a `tenants.modules` en alta y cambio de plan) |
| status | varchar(20) | no | CHECK (`activo`,`inactivo`) |
| created_at, updated_at | timestamptz | no | |

### 3.2 `plan_features` (plataforma) — matriz de entitlements (`FEATURES`)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| feature_key | varchar(60) | no | p. ej. `crm`, `catalog`, `quotes`, `reservations`, `finance_basic`, `portal_client`, `operations`, `vouchers_rooming`, `profitability_commissions`, `b2b`, `reports_export`, `portal_passenger`, `multi_sede`, `ecommerce`, `cms`, `mobile_apps`, `integrations`, `audit_advanced` |
| label | varchar(120) | no | Texto de la matriz |
| plan_id | varchar(30) | no | FK `plans(id)` |
| value | varchar(30) | no | `included` \| `not_included` \| valor textual (`basico`, `completo` para B2B) |
| UNIQUE (plan_id, feature_key) | | | |

Valores exactos del prototipo: Starter incluye las 6 primeras; Professional agrega operaciones, vouchers/rooming, rentabilidad/comisiones, B2B “Básico”, reportes/exportación, portal pasajero; Enterprise agrega multi-sede, e-commerce, CMS, apps móviles, integraciones, auditoría avanzada/exportación de bitácora y B2B “Completo”.

### 3.3 `plan_channels` (plataforma) — canal mínimo por plan (`CHANNELS`)
| columna | tipo | null | notas |
|---|---|---|---|
| channel_key | varchar(30) | no | PK: `portal_cliente`(Starter), `portal_pasajero`, `b2b`, `whatsapp` (Professional), `web`, `ecommerce`, `app_operador`, `app_pasajero` (Enterprise) |
| label | varchar(80) | no | |
| min_plan_id | varchar(30) | no | FK `plans(id)` |
| description | varchar(200) | no | |
| depends_on_integration | varchar(30) | sí | `whatsapp`→INT-04, `ecommerce`→INT-02 |

### 3.4 `tenants` (plataforma)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK (UUID v7) |
| code | varchar(20) | no | UNIQUE, `TNT-0001` (correlativo de plataforma) |
| legal_name | varchar(200) | no | Razón social (`name`) |
| commercial_name | varchar(120) | no | `short` / `commercialName` |
| ruc | char(11) | no | UNIQUE; CHECK `ruc ~ '^(10\|15\|17\|20)[0-9]{9}$'` |
| fiscal_address | varchar(250) | no | paso 2 del asistente |
| business_type | varchar(40) | no | CHECK (`Agencia de viajes minorista`,`Agencia mayorista`,`Operador turístico`,`DMC / receptivo`,`Agencia corporativa`) |
| legal_rep | varchar(150) | sí | |
| subdomain | varchar(30) | no | UNIQUE; CHECK `subdomain ~ '^[a-z0-9-]{3,30}$'` |
| custom_domain | varchar(190) | sí | UNIQUE (dominio propio, CONVENCIONES §6) |
| country | varchar(40) | no | Perú, Bolivia, Chile, Ecuador, Colombia |
| base_currency | char(3) | no | PEN/USD/BOB/CLP; inmutable con transacciones (CFG-BR-05) |
| alt_currency | char(3) | no | USD/PEN/EUR |
| timezone | varchar(40) | no | `America/Lima` … |
| locale | varchar(10) | no | `es-PE`, `es-419`, `en-US` |
| logo_initials | varchar(3) | no | `AH` |
| logo_path | varchar(255) | sí | S3 |
| plan_id | varchar(30) | no | FK `plans(id)` |
| status | varchar(20) | no | CHECK (`prueba`,`activo`,`suspendido`) |
| trial_ends_at | timestamptz | sí | alta en `prueba` = +14 días |
| suspend_reason | text | sí | obligatorio si `suspendido` (CHECK `status <> 'suspendido' OR suspend_reason IS NOT NULL`) |
| suspended_at | timestamptz | sí | |
| mrr | numeric(14,2) | no | default 0; = precio del plan si `activo` |
| modules | smallint | no | 6/12/18 |
| storage_used_bytes | bigint | no | default 0; actualizado por job de uso |
| admin_email | varchar(190) | no | contacto del administrador inicial |
| created_by | uuid | sí | FK `platform_users(id)` |
| created_at, updated_at | timestamptz | no | |
| lock_version | integer | no | default 0 |
Índices: `(status)`, `(plan_id)`. Contador `users` = `count(iam_users where status <> 'inactivo')` (vista o columna cacheada).

### 3.5 `tenant_onboarding` (plataforma) — snapshot del asistente PROC-001
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK `tenants(id)` UNIQUE |
| payload | jsonb | no | Todos los campos del asistente (roles elegidos, plantilla de permisos, canales, config comercial y financiera) |
| permission_template | varchar(40) | no | CHECK (`estandar`,`minimo_privilegio`,`copiar_tenant`) |
| copied_from_tenant_id | uuid | sí | FK `tenants(id)` |
| idempotency_key | varchar(80) | no | UNIQUE |
| created_at | timestamptz | no | |

### 3.6 `iam_sedes`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK `tenants(id)`; RLS |
| code | varchar(20) | no | `SED-01`; UNIQUE (tenant_id, code) |
| name | varchar(120) | no | UNIQUE (tenant_id, lower(name)) |
| city | varchar(80) | sí | (asistente de alta) |
| address | varchar(250) | no | |
| phone | varchar(30) | sí | |
| manager_user_id | uuid | sí | FK `iam_users(id)`; usuario activo de rol TA/MG/Ventas/Operaciones/Finanzas |
| status | varchar(20) | no | CHECK (`activa`,`inactiva`) |
| is_main | boolean | no | default false; exactamente una por tenant (índice parcial UNIQUE (tenant_id) WHERE is_main) — `settings.general.mainSede` |
| created_by, updated_by | uuid | sí | |
| created_at, updated_at, deleted_at | timestamptz | | borrado físico solo sin referencias (SED-BR-04) |

### 3.7 `iam_user_invitations`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK; RLS |
| user_id | uuid | no | FK `iam_users(id)` (usuario creado en estado `pendiente`) |
| token_hash | char(64) | no | SHA-256 del token de un solo uso; UNIQUE |
| expires_at | timestamptz | no | `created_at + 72 h` |
| accepted_at | timestamptz | sí | |
| require_mfa | boolean | no | “Exigir MFA en el primer acceso” (default true) |
| invited_by | uuid | no | FK `iam_users(id)` |
| resent_count | smallint | no | default 0 |
| created_at | timestamptz | no | |

### 3.8 `sys_settings` — configuración por tenant (PROC-003)
Una fila por tenant y sección; valores tipados en JSONB validados por FormRequest.
| columna | tipo | null | notas |
|---|---|---|---|
| tenant_id | uuid | no | PK compuesta con `section`; FK; RLS |
| section | varchar(30) | no | CHECK (`general`,`currency`,`policies`,`operations`,`channels`,`notifications`) |
| values | jsonb | no | ver claves abajo |
| updated_by | uuid | sí | |
| updated_at | timestamptz | no | |
| lock_version | integer | no | default 0 (edición concurrente) |

Claves por sección (nombre prototipo → clave backend, tipo, validación):

| Sección | Claves |
|---|---|
| `general` | `email` (email), `phone`, `web`, `address` (domicilio fiscal), `legal_rep`, `mincetur_registry`, `lang` (`es-PE`/`en-US`), `date_format` (`DD/MM/AAAA`/`AAAA-MM-DD`), `main_sede_id` (uuid sede activa). `legal_name`, `commercial_name`, `logo_initials` (≤3, mayúsculas), `timezone` se guardan en `tenants`. `ruc` y `country` no editables |
| `currency` | `base_currency` (bloqueada si existen reservas o pagos), `alt_currency` (USD/EUR), `price_with_tax` (bool, default true), `fx_rate` (numeric(12,6), 0.5 < fx < 20), `fx_source` (`Manual diario (SBS referencial)`,`Manual semanal (tesorería)`,`Automático SBS (integración por definir)`), `tax_pct` (0–30, default 18), `rounding` (`0.01`,`0.10`,`1`), `round_mode` (`nearest`,`up`,`down`) |
| `policies` | `quote_validity_days` (1–60, default 10), `hold_minutes` (5–1440, default 30), `markup_pct` (default 25), `max_discount_pct` (0–30, default 5), `advance_pct` (0–100, default 40), `balance_days_before` (≥0, default 15), `cancel_text` (obligatorio), `modify_text`, `noshow_text`, `refund_text` |
| `operations` | `voucher_hours_before` (72), `assign_expiry_min` (120), `checkin_tolerance_min` (15), `modify_min_hours` (48), `max_pax_per_guide` (1–40, default 16), `offline_sync_min` (10), `pay_reminder_days` (3), `trip_notice_hours` (48), `incident_escalate_min` (30); todos enteros ≥ 0, obligatorios |
| `channels` | `portal_cliente`, `portal_pasajero`, `b2b`, `whatsapp`, `web`, `ecommerce`, `app_operador`, `app_pasajero` (bool); solo editable si `plan.rank >= plan_channels.min_plan.rank` |

### 3.9 `sys_fx_rates` — historial de tipo de cambio
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK; RLS |
| rate_date | date | no | UNIQUE (tenant_id, rate_date, from_currency, to_currency) |
| from_currency / to_currency | char(3) | no | USD → PEN |
| rate | numeric(12,6) | no | CHECK rate > 0 |
| source | varchar(60) | no | |
| created_by | uuid | no | |
| created_at | timestamptz | no | |
Se inserta una fila al guardar un TC distinto (CFG-BR-06). Documentos conservan su propio `fx_rate` (regla transversal 15).

### 3.10 `notif_event_rules` (PROC-059)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK; RLS |
| event_code | varchar(50) | no | UNIQUE (tenant_id, event_code); ver catálogo §8.3 |
| label | varchar(80) | no | “Cotización enviada”… |
| ch_email, ch_whatsapp, ch_push, ch_portal | boolean | no | matriz evento × canal |
| recipients | varchar(200) | no | texto de regla: “Cliente”, “Vendedor + Gerencia”… (resolución en backend por evento) |
| timing | varchar(20) | no | CHECK (`immediate`,`batch_15m`,`business_hours`) — “Inmediato”, “Agrupado cada 15 min”, “Solo horario laboral (08:00–19:00)” |
| condition | varchar(30) | no | CHECK (`always`,`marketing_consent`,`amount_gt_5000`) |
| updated_by | uuid | sí | |
| updated_at | timestamptz | no | |
CHECK para `incidencia.critica`: `ch_email OR ch_whatsapp OR ch_push OR ch_portal` (NTF-BR-01).

### 3.11 `notif_templates`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | sí | NULL = plantilla predeterminada del sistema |
| event_code | varchar(50) | no | |
| channel | varchar(20) | no | CHECK (`email`,`whatsapp`,`push`) — Portal usa la de email |
| version | integer | no | ≥1; histórico conservado |
| subject | varchar(200) | no | “Título” en push |
| body | text | no | push ≤ 140 caracteres (CHECK `channel <> 'push' OR char_length(body) <= 140`) |
| is_current | boolean | no | índice UNIQUE parcial (tenant_id, event_code, channel) WHERE is_current |
| provider_template_id | varchar(120) | sí | ID de plantilla aprobada por Meta (WhatsApp) |
| created_by | uuid | sí | |
| created_at | timestamptz | no | |
“Restaurar predeterminada” = marcar `is_current=false` en la del tenant (se usa la de sistema). Variables permitidas en §5 (NTF-BR-03).

### 3.12 `notif_messages` (log de envíos)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK; código visible `NTF-9922` (secuencia) |
| tenant_id | uuid | no | FK; RLS |
| event_code | varchar(50) | no | |
| channel | varchar(20) | no | CHECK (`email`,`whatsapp`,`push`,`portal`) |
| recipient_user_id | uuid | sí | FK `iam_users` (portal/push) |
| recipient_address | varchar(190) | sí | email o teléfono |
| subject | varchar(200) | no | |
| body_rendered | text | sí | |
| template_id | uuid | sí | FK `notif_templates` (versión usada) |
| source_type / source_id | varchar(40) / uuid | sí | entidad que originó el evento (cotización, reserva…) |
| status | varchar(20) | no | CHECK (`pendiente`,`enviado`,`fallido`) — prototipo usa `enviado`/`fallido` |
| retries | smallint | no | default 0 |
| last_error | text | sí | p. ej. “Código 131026: número no registrado en el entorno sandbox” |
| is_fallback | boolean | no | default false (reenvío por canal alterno) |
| fallback_of_id | uuid | sí | FK self |
| read_at | timestamptz | sí | campana del usuario |
| provider_message_id | varchar(120) | sí | |
| created_at, sent_at | timestamptz | | |
Índices: `(tenant_id, status)`, `(tenant_id, created_at desc)`, `(tenant_id, recipient_user_id, read_at)`.

### 3.13 `integration_adapters`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | sí | NULL = adapter de plataforma compartido |
| code | varchar(20) | no | `INT-01`…`INT-07`; UNIQUE (tenant_id, code) |
| name | varchar(120) | no | |
| kind | varchar(30) | no | CHECK (`fiscal`,`payments`,`email`,`messaging`,`maps`,`suppliers`,`distribution`) |
| status | varchar(20) | no | CHECK (`activo`,`por_validar`,`sandbox`,`parcial`,`futuro`) |
| environment | varchar(20) | no | CHECK (`sandbox`,`production`) |
| owner_user_id | uuid | sí | responsable (TA/MG/Finanzas/Operaciones activo) |
| max_retries | smallint | no | 0–10, default 5 |
| backoff_base_s | integer | no | ≥5, default 60 |
| timeout_s | integer | no | 5–120, default 30 |
| secret_ref | varchar(200) | sí | referencia a bóveda (AWS Secrets Manager / Vault); nunca el secreto |
| secret_rotated_at | timestamptz | sí | rotación 90 días |
| note | text | sí | |
| last_test_at | timestamptz | sí | |
| last_test_ok | boolean | sí | |
| last_test_result | varchar(250) | sí | |
| updated_by | uuid | sí | |
| created_at, updated_at | timestamptz | no | |

### 3.14 `sys_scheduled_jobs` y `sys_job_runs`
`sys_scheduled_jobs`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | sí | NULL = job de plataforma |
| code | varchar(20) | no | `JOB-01`…; UNIQUE (tenant_id, code) |
| name | varchar(150) | no | |
| job_class | varchar(150) | no | clase Laravel |
| cron | varchar(60) | no | expresión cron; `cron_label` humano (“cada 5 min”, “diario 00:05”, “lunes 07:00”, “mensual día 1 07:00”) |
| cron_label | varchar(60) | no | |
| queue | varchar(20) | no | `critical`/`default`/`notifications`/`documents`/`integrations`/`reports`/`low` |
| paused | boolean | no | default false |
| last_run_at, next_run_at | timestamptz | sí | |
| last_status | varchar(20) | sí | CHECK (`ok`,`advertencia`,`error`,`en_curso`) |
| last_result | varchar(250) | sí | |
| report_key | varchar(60) | sí | solo envíos programados de reportes |
| report_format | varchar(10) | sí | CHECK (`PDF`,`XLSX`,`CSV`) |
| recipients | text | sí | correos separados por coma |
| owner_user_id | uuid | sí | quien programó |
| is_system | boolean | no | true para JOB-01…09 (no eliminables) |
| created_at, updated_at | timestamptz | no | |

`sys_job_runs` (CONVENCIONES §4): `id uuid`, `tenant_id uuid null`, `scheduled_job_id uuid FK`, `trigger` CHECK (`schedule`,`manual`,`retry`), `triggered_by uuid null`, `status` CHECK (`en_curso`,`ok`,`advertencia`,`error`), `result varchar(250)`, `started_at`, `finished_at timestamptz`, `attempt smallint`, `correlation_id varchar(20)`. Índice `(scheduled_job_id, started_at desc)`.

### 3.15 `integration_errors` (jobLog del prototipo)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK; código `ERR-0418` |
| tenant_id | uuid | sí | |
| source | varchar(20) | no | CHECK (`job`,`notification`,`integration`) |
| ref_code | varchar(30) | no | `JOB-07`, `NTF-9916`, `INT-03` |
| target | varchar(150) | no | “Adapter tren (Ferrovía Sur Andina)” |
| error | text | no | |
| error_class | varchar(20) | no | CHECK (`transitorio`,`permanente`) |
| attempt | smallint | no | |
| max_attempts | smallint | no | |
| next_retry_at | timestamptz | sí | |
| status | varchar(20) | no | CHECK (`reintentando`,`fallo_definitivo`,`resuelto`) |
| resolution | text | sí | obligatorio al resolver manualmente |
| resolved_by | uuid | sí | |
| created_at, updated_at | timestamptz | no | |
Índice `(tenant_id, status)`.

### 3.16 `report_exports` (PROC-061)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK; código `EXP-0031` |
| tenant_id | uuid | no | FK; RLS |
| report_name | varchar(200) | no | “Finanzas y rentabilidad · Abr – Set 2026 · Lima — Miraflores” |
| report_key | varchar(40) | no | `comercial`, `cotizaciones`, `reservas`, `operaciones`, `finanzas`, `proveedores`, `clientes`, `auditoria`, `<modulo>.<tabla>` |
| filters | jsonb | no | periodo, sede, filtros de tabla |
| scope | varchar(20) | no | CHECK (`filtered`,`detailed`) |
| format | varchar(10) | no | CHECK (`CSV`,`XLSX`,`PDF`) |
| rows_count | integer | sí | |
| size_bytes | bigint | sí | |
| file_path | varchar(255) | sí | `tenants/{tenant}/exports/{uuid}.{ext}` |
| status | varchar(20) | no | CHECK (`en_cola`,`procesando`,`disponible`,`fallido`,`expirado`) |
| requested_by | uuid | sí | NULL = Sistema (reporte programado) |
| scheduled_job_id | uuid | sí | FK |
| idempotency_key | varchar(80) | sí | UNIQUE (tenant_id, idempotency_key) |
| expires_at | timestamptz | no | `created_at + 7 días` |
| created_at, finished_at | timestamptz | | |

### 3.17 Read models de reportes y dashboard (PROC-060)
Vistas materializadas por tenant refrescadas por job (`reports` queue, cada 15 min y on-demand):
| vista | granularidad | columnas |
|---|---|---|
| `rpt_monthly_sales` | tenant × mes × sede | `sales_net_pen`, `cost_pen`, `reservations`, `leads`, `quotes`, `conversion_pct` (hoy `DB.monthly` fijo) |
| `rpt_seller_performance` | tenant × usuario × mes | leads activos, oportunidades abiertas y pipeline PEN, ganado PEN, win rate, cotizaciones abiertas, margen medio, actividades vencidas |
| `rpt_funnel` | tenant × mes | leads, calificados, con cotización, aprobadas, convertidas (hoy valores fijos 128/71/49/24/22) |
| `rpt_sales_by_channel` | tenant × mes × canal | ventas netas (hoy donut fijo 41/27/18/14 %) |
Todas con `tenant_id` y RLS; montos convertidos a PEN con el `fx_rate` del documento.

### 3.18 Restricciones clave
- `tenants.ruc`, `tenants.subdomain` UNIQUE globales; `iam_users (tenant_id, lower(email))` UNIQUE (ver 00).
- Límite de usuarios / sedes del plan: validado en servicio con `SELECT … FOR UPDATE` sobre `tenants` (evita carrera entre dos invitaciones).
- Al menos un Tenant Admin activo y al menos una sede activa por tenant: validación en servicio + trigger diferido de defensa.
- `sys_sequences.next_number` no decrece (trigger `BEFORE UPDATE`: `NEW.next_number >= OLD.next_number`).
- Bitácora `audit_events` append-only (00 §3).

---

## 4. Máquinas de estado

### 4.1 Tenant
```
[alta] ──► prueba ──(suspender)──► suspendido ──(reactivar)──► activo
   └────► activo ──(suspender)──►  suspendido
```
| Transición | Quién | Validaciones | Efectos |
|---|---|---|---|
| alta → `prueba` \| `activo` | Super Admin | Asistente completo (§5 TEN-BR) | `mrr` = precio si `activo`, 0 si `prueba`; `modules` según plan; crea sede, administrador (invitación), roles y permisos desde plantilla, settings; audita `alta`; notifica `tenant.activado` al administrador |
| `prueba`/`activo` → `suspendido` | Super Admin | Motivo obligatorio; no se puede suspender el tenant de la sesión activa (TEN-BR-07) | Bloquea login de todos sus usuarios y revoca tokens; datos intactos; jobs del tenant pausados; audita `suspender`; notifica `tenant.suspendido` |
| `suspendido` → `activo` | Super Admin | Motivo obligatorio | `mrr` = precio del plan si era 0; audita `reactivar`; notifica `tenant.reactivado` |
| `prueba` → `activo` | **No existe en el prototipo** (ver §12) | | |
| cambio de plan (cualquier estado) | Super Admin | usuarios ≤ límite y almacenamiento ≤ límite del plan destino | `modules`, `mrr` (si `activo`); vigencia “Inmediata (prorrateo)” o “Próximo ciclo”; audita `cambio_plan` |

### 4.2 Usuario (identidad del tenant)
```
[invitar] ──► pendiente ──(acepta invitación)──► activo ──(desactivar)──► inactivo ──(activar)──► activo
                 └──────────(desactivar)──────────────────────────────►  inactivo
```
| Transición | Quién | Validaciones | Efectos |
|---|---|---|---|
| invitar → `pendiente` | Tenant Admin | email válido y único en tenant; límite de usuarios del plan (cuenta los no inactivos) | Crea invitación 72 h; `mfa_required` según switch; audita `crear`; notifica `usuario.invitado` |
| `pendiente` → `activo` | El invitado (backend) | token válido y no expirado; enrolamiento MFA si `mfa_required` | `accepted_at`; audita `activar` |
| reenviar invitación | Tenant Admin | estado `pendiente` | nuevo token (invalida el anterior); audita `reenviar_invitacion` |
| `activo`/`pendiente` → `inactivo` | Tenant Admin | no a sí mismo; no al último Tenant Admin activo; motivo obligatorio. Aviso (no bloqueo) si tiene oportunidades/actividades abiertas | Revoca tokens; `deactivated_at`, `deactivated_reason`; audita `desactivar` |
| `inactivo` → `activo` | Tenant Admin | límite del plan (ver §12) | audita `activar` |
| cambio de rol/sede | Tenant Admin | no cambiar su propio rol; no quitar el último Tenant Admin | Revalida sesiones con el nuevo rol (flush de caché de permisos); audita `cambio_rol` o `editar` |
| resetear MFA | Tenant Admin | motivo obligatorio | `mfa=false`, `mfa_required=true`; elimina factor; audita `resetear_mfa`; notifica `seguridad.mfa_reset` |

### 4.3 Sede
`activa` ⇄ `inactiva`; eliminación física solo sin referencias.
| Transición | Validaciones | Efecto |
|---|---|---|
| crear (`activa`) | nombre único; sedes activas < límite del plan | audita `crear` |
| `activa` → `inactiva` | sin usuarios activos en la sede; no es la última activa | audita `desactivar` |
| `inactiva` → `activa` | (el prototipo no valida límite de plan al reactivar; ver §12) | audita `activar` |
| eliminar | 0 usuarios y 0 reservas referencian la sede | audita `eliminar` |

### 4.4 Rol / permisos
Rol base (`is_custom=false`): no se elimina ni renombra; su matriz sí es editable por tenant. Rol personalizado: crear → editar (nombre/descr.; alcance inmutable) → eliminar (sin usuarios). Cada casilla: otorgar/revocar sujeto a RBAC-BR (§5).

### 4.5 Notificación (`notif_messages`)
`pendiente` → `enviado` | `fallido`; `fallido` → (reintentar) → `enviado` | `fallido` (retries+1); `fallido` → (canal alterno) crea un nuevo mensaje Email con `is_fallback`. Error permanente (p. ej. WhatsApp sandbox 131026) no cambia con reintento y genera `integration_errors` `fallo_definitivo`.

### 4.6 Error de integración (`integration_errors`)
`reintentando` → (reintento OK) `resuelto`; `reintentando` → (attempt ≥ max) `fallo_definitivo` + alerta; `fallo_definitivo` → (reintentar JOB-07) `reintentando` con attempt 0; cualquiera no resuelto → (resolver con motivo) `resuelto`. Permanente: reintento solo incrementa `attempt` (INT-BR-05).

### 4.7 Job programado
`ok|advertencia|error` → (ejecutar ahora) `en_curso` → `ok|advertencia|error`; `paused` es bandera independiente (pausar → `next_run_at = null`; reanudar → recalcula). Solo los jobs de reportes programados se eliminan.

### 4.8 Exportación (`report_exports`)
`en_cola` → `procesando` → `disponible` → (`expires_at`) `expirado`; `procesando` → `fallido`. Sin permiso: no se crea el job; se audita `exportar_denegado` (403).

### 4.9 Integración (`integration_adapters.status`)
Estados de gestión de plataforma, no transicionables desde la UI del tenant. Solo cambia `environment`: `sandbox` → `production` bloqueado si `status IN (por_validar, futuro)` o si `sandbox` (WhatsApp sin plantillas aprobadas).

---

## 5. Reglas de negocio

| ID | Regla (tal como la aplica el prototipo) | Dónde se valida |
|---|---|---|
| TEN-BR-01 | Subdominio 3–30 caracteres `[a-z0-9-]`, se normaliza a minúsculas; único en la plataforma. Mensajes: “Subdominio inválido…”, “El subdominio ya está en uso por otro tenant.” | FormRequest + UNIQUE |
| TEN-BR-02 | RUC 11 dígitos que inicia con 10, 15, 17 o 20; único (“Ya existe un tenant con ese RUC”) | FormRequest + CHECK + UNIQUE |
| TEN-BR-03 | Obligatorios: nombre comercial, subdominio, razón social, RUC, domicilio fiscal, sede (nombre, ciudad, dirección), administrador (nombre, correo válido), validez de cotización, minutos de hold, IGV, tipo de cambio > 0 | FormRequest por paso (`POST /platform/tenants/validate-step`) y completo al crear |
| TEN-BR-04 | Rol Tenant Admin siempre incluido en el alta (casilla deshabilitada) | Service |
| TEN-BR-05 | Descuento máximo sin aprobación ≤ 30 % (también en configuración) | FormRequest |
| TEN-BR-06 | Canales no incluidos en el plan quedan bloqueados (entitlements) | Service (`plan_channels`) |
| TEN-BR-07 (PROC-001) | No se puede suspender el tenant de la sesión activa (“solicítelo desde otra cuenta de plataforma”) | Policy |
| TEN-BR-08 | Suspender/Reactivar exige motivo, que queda en auditoría | FormRequest |
| TEN-BR-09 | Cambio de plan: bloquea si usuarios > límite (“Desactive usuarios antes del downgrade”) o almacenamiento > límite; mismo plan → aviso sin cambios | Service (409 `PLAN_LIMIT_EXCEEDED`) |
| TEN-BR-10 | Tenant suspendido: sus usuarios no pueden iniciar sesión; los datos se conservan | Middleware `EnsureTenantActive` (403 `TENANT_SUSPENDED`) |
| TEN-BR-11 | La moneda base no puede cambiarse una vez registradas transacciones | Service |
| TEN-BR-12 | Plantilla “Mínimo privilegio”: roles no-admin reciben solo permisos `*.view`, `*.receivables`, `manage_availability`, `*.create` de su matriz estándar | Service |
| USR-BR-01 | Correo válido y único en el tenant (insensible a mayúsculas): “Ya existe una identidad con ese correo… Reactive el usuario existente” | FormRequest + UNIQUE |
| USR-BR-02 | Límite de usuarios del plan (usuarios no inactivos ≥ límite → bloqueo “Plan/entitlements”) | Service con lock |
| USR-BR-03 | El correo no se edita después de la invitación | FormRequest (campo prohibido) |
| USR-BR-04 | No puede cambiar su propio rol | Policy |
| USR-BR-05 | El tenant debe conservar al menos un Tenant Admin activo (al cambiar rol o desactivar) | Service |
| USR-BR-06 | No puede desactivar su propia cuenta | Policy |
| USR-BR-07 | Baja lógica con motivo; se conservan historial, auditoría y registros | Service (sin DELETE) |
| USR-BR-08 | Aviso de oportunidades/actividades abiertas al desactivar (no bloquea) | Service (respuesta incluye `warnings`) |
| USR-BR-09 | Selector de rol excluye `super_admin` salvo que el actor sea Super Admin | FormRequest |
| USR-BR-10 | Reset de MFA con motivo; obliga a re-enrolar en el próximo acceso y notifica al usuario | Service |
| USR-BR-11 | Invitación de un solo uso válida 72 h; la contraseña nunca viaja por correo | Service |
| SED-BR-01 | Nombre de sede único (insensible a mayúsculas); dirección obligatoria | FormRequest + UNIQUE |
| SED-BR-02 | Nuevas sedes limitadas por plan (Starter 1, Professional 2, Enterprise ilimitadas) | Service |
| SED-BR-03 | No se desactiva una sede con usuarios activos ni la última sede activa | Service |
| SED-BR-04 | No se elimina una sede con usuarios o reservas asociadas (“Desactívela para conservar el historial”) | Service + FK `RESTRICT` |
| SED-BR-05 | Responsable: usuario activo con rol TA, Manager, Ventas, Operaciones o Finanzas | FormRequest |
| RBAC-BR-01 | El rol Super Admin SaaS no se edita desde un tenant | Policy |
| RBAC-BR-02 | Anti-bloqueo: Tenant Admin no pierde `admin.roles.manage` ni `admin.users.manage` | Service |
| RBAC-BR-03 | Roles de alcance `canal` o `mobile` no reciben permisos `admin.*` | Service |
| RBAC-BR-04 | Roles `canal`/`mobile` no reciben `finance.*`, `*profitability*` ni `quotes.discount_override` | Service |
| RBAC-BR-05 | `admin.tenant.manage` no es otorgable a roles del tenant (prototipo: “reservado al Tenant Admin”; ver §11) | Service |
| RBAC-BR-06 | Rol personalizado: nombre único, descripción obligatoria, alcance inmutable tras crearlo; al copiar permisos a un rol no-tenant se eliminan los prohibidos por RBAC-BR-03/04 | FormRequest + Service |
| RBAC-BR-07 | Rol personalizado con usuarios asignados no se elimina | Service |
| RBAC-BR-08 | Cambio masivo por módulo: aplica los permitidos e informa “N bloqueado(s) por reglas de seguridad” | Service (respuesta `applied`, `blocked`) |
| RBAC-BR-09 | Cada otorgamiento/revocación se audita y aplica en la siguiente solicitud del usuario (flush caché spatie) | Service + evento `PermissionsChanged` |
| CFG-BR-01 | Guardado por diferencia: solo se persisten y auditan los campos modificados; sin cambios → “No hay cambios que guardar” | Service |
| CFG-BR-02 | Razón social, nombre comercial e iniciales del logo obligatorios; iniciales en mayúsculas, máx. 3 | FormRequest |
| CFG-BR-03 | RUC y país no editables desde el tenant (“requiere validación de plataforma”) | FormRequest (prohibited) |
| CFG-BR-04 | Tipo de cambio 0.5 < TC < 20 (“fuera de rango razonable”); IGV 0–30 % | FormRequest |
| CFG-BR-05 | Moneda base bloqueada si existen reservas o pagos | Service |
| CFG-BR-06 | Cada cambio de TC agrega fila a historial con fecha, fuente y usuario; el TC se congela en snapshot de cotizaciones y pagos | Service |
| CFG-BR-07 | Correlativos: no se reutilizan ni retroceden; solo avanzar (migración); prefijo no vacío y único | FormRequest + trigger |
| CFG-BR-08 | Nuevas series solo para tipos no existentes: Nota de crédito, Nota de débito, Liquidación de comisión, Recibo de caja, Orden de compra a proveedor | FormRequest |
| CFG-BR-09 | Validez de cotización 1–60 días; hold 5–1440 min (“El hold mínimo es 5 minutos”); descuento máx. ≤ 30 %; política de cancelación obligatoria | FormRequest |
| CFG-BR-10 | Máx. pasajeros por guía 1–40; parámetros operativos obligatorios ≥ 0 | FormRequest |
| CFG-BR-11 | Cambios de configuración aplican a documentos nuevos; los existentes conservan su snapshot | Service |
| CFG-BR-12 | Canales: solo los del plan son editables; habilitar e-commerce con pasarela INT-02 `por_validar` → aviso “los pagos quedarán como pendientes” (no bloquea) | Service |
| NTF-BR-01 | La incidencia crítica debe notificarse por al menos un canal | FormRequest + CHECK |
| NTF-BR-02 | “Probar” un evento sin canales activos → aviso | Service |
| NTF-BR-03 | Variables permitidas: `{{cliente}}`, `{{reserva}}`, `{{cotizacion}}`, `{{fecha_viaje}}`, `{{monto}}`, `{{servicio}}`, `{{hora}}`, `{{enlace}}`, `{{agencia}}`, `{{vendedor}}`; cualquier otra bloquea guardar y enviar prueba | FormRequest |
| NTF-BR-04 | Push: cuerpo ≤ 140 caracteres | FormRequest + CHECK |
| NTF-BR-05 | Guardar plantilla sin cambios → aviso; cada guardado incrementa versión | Service |
| NTF-BR-06 | Un fallo de canal no revierte la transacción de negocio que originó el evento (PROC-065) | Listener en cola, fuera de la transacción |
| NTF-BR-07 | Error permanente (WhatsApp sandbox 131026) no se resuelve reintentando: sugiere canal alterno | Service |
| NTF-BR-08 | Canal alterno solo para envíos no-Email y que no sean ya alternos | Service |
| INT-BR-01 (PROC-064) | No se inventan APIs, credenciales, costos ni límites: integraciones sin proveedor quedan “Por validar” y la prueba no envía credenciales | Service |
| INT-BR-02 | No pasar a Producción una integración `por_validar`/`futuro`; WhatsApp (`sandbox`) requiere plantillas aprobadas | Service |
| INT-BR-03 | Credenciales solo en bóveda; nunca se muestran; rotación cada 90 días | Infra |
| INT-BR-04 | Transitorios (timeout, 5xx, 429) se reintentan con backoff exponencial `base × 2^intento` (UI: 1, 2, 4, 8, 16 min ± 20 % jitter), máx. 5; luego fallo definitivo + alerta + auditoría | Job middleware |
| INT-BR-05 | Permanentes (4xx de validación, destinatario inválido) pasan directo a fallo definitivo; reintentar no cambia el resultado | Service |
| INT-BR-06 | Resolver manualmente un error exige descripción de la resolución | FormRequest |
| JOB-BR-01 | “Ejecutar ahora” encola una ejecución sin alterar la programación; ejecución idempotente (`ShouldBeUnique`) | Job |
| JOB-BR-02 | Pausar deja `next_run_at` vacío; reanudar recalcula | Service |
| JOB-BR-03 | Solo se eliminan los envíos programados de reportes (los archivos generados se conservan) | Policy |
| AUD-BR-01 (PROC-063) | Bitácora append-only: ningún rol edita ni borra eventos; los accesos a la bitácora también se auditan | DB (REVOKE + trigger) |
| AUD-BR-02 | Super Admin solo ve eventos de plataforma: entidades Tenant, Sesión, Job, Integración, Plan, Rol, Permiso, Exportación, o acciones `*denegad*` | Policy / scope de consulta |
| AUD-BR-03 | Exportar bitácora requiere `admin.audit.view` y se procesa como job PROC-061 | Policy |
| REP-BR-01 (PROC-060) | Pestañas por rol (§7.3); si se solicita una no permitida: “El reporte solicitado no está disponible para su rol” | Policy por endpoint |
| REP-BR-02 | Ventas: todos los reportes filtrados a su cartera (`owner = me`), series mensuales recalculadas con sus registros | Scope en query |
| REP-BR-03 | Sin `finance.profitability` el reporte de finanzas omite costos, márgenes y utilidad (variante contable) | Resource condicional |
| REP-BR-04 | Columna/gráfico de CxP solo con `finance.payables` | Resource condicional |
| REP-BR-05 | Exportar y programar requieren `reports.export`; sin él los botones se muestran deshabilitados con “Requiere permiso reports.export” | Policy |
| REP-BR-06 | Reservas canceladas no suman ventas; cifras en PEN al TC vigente del documento | Read model |
| EXP-BR-01 (PROC-061) | Flujo Solicitud → Permiso → Job → Procesar → Archivo → Disponible; sin permiso → 403, se audita `exportar_denegado` | Service + Job |
| EXP-BR-02 | Archivo disponible 7 días vía URL firmada; cada descarga se audita (`descargar`) | Controller |
| EXP-BR-03 | Programar envío: destinatarios obligatorios, cada correo válido; frecuencia diario/lunes/viernes/mensual día 1 y hora | FormRequest |
| DSH-BR-01 | Cotización fuera de política = descuento > 5 % o margen < 15 % de la versión vigente, en estados borrador/enviada/vista/negociación y sin aprobación interna | Query (usar `policies.max_discount_pct`, ver §11) |
| DSH-BR-02 (PROC-021) | Aprobación interna exige `quotes.approve_internal` y justificación; queda auditada y notifica al vendedor; habilita el envío al cliente | Policy + Service (módulo Ventas) |

---

## 6. API REST

Autenticación y middleware según 00 §1. Plataforma bajo `/api/v1/platform/*` con guard `platform` (solo Super Admin).

### 6.1 Plataforma (PROC-001)
| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| GET | `/api/v1/platform/tenants` | `admin.tenant.manage` | Listado con filtros `plan`, `status`, búsqueda; KPIs en `meta` | — |
| POST | `/api/v1/platform/tenants/validate-step` | `admin.tenant.manage` | Valida un paso del asistente (subdominio, RUC, email…) | — |
| POST | `/api/v1/platform/tenants` | `admin.tenant.manage` | Alta completa (crea tenant, sede, invitación admin, roles, permisos, settings, series) en una transacción | **Idempotency-Key** |
| GET | `/api/v1/platform/tenants/{id}` | `admin.tenant.manage` | Detalle, consumo, entitlements, historial | — |
| POST | `/api/v1/platform/tenants/{id}/suspend` | `admin.tenant.manage` | `{reason}` | `lock_version` |
| POST | `/api/v1/platform/tenants/{id}/reactivate` | `admin.tenant.manage` | `{reason}` | `lock_version` |
| POST | `/api/v1/platform/tenants/{id}/change-plan` | `admin.tenant.manage` | `{plan_id, effective: immediate\|next_cycle}` | `lock_version` |
| GET | `/api/v1/platform/plans` | `admin.tenant.manage` | Planes, límites, features, canales | — |
| GET | `/api/v1/platform/tenants/health` | `admin.tenant.manage` | Salud por tenant: plan, estado, integraciones, jobs, errores abiertos, último heartbeat | — |
| GET | `/api/v1/platform/audit-events` | `admin.audit.view` (guard platform) | Solo eventos de plataforma, filtro `tenant` | — |

### 6.2 Usuarios y sedes (PROC-002)
| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| GET | `/api/v1/iam/users` | `admin.users.manage` | Filtros `role`, `sede`, `status`, `mfa`; KPIs | — |
| POST | `/api/v1/iam/users` | `admin.users.manage` | Invitar `{name,email,role,sede_id,phone,require_mfa}` | Idempotency-Key |
| GET | `/api/v1/iam/users/{id}` | `admin.users.manage` | Detalle + permisos efectivos + actividad (últimos 8 eventos) | — |
| PATCH | `/api/v1/iam/users/{id}` | `admin.users.manage` | `{name, role, sede_id, phone}` (email prohibido) | `lock_version` |
| POST | `/api/v1/iam/users/{id}/deactivate` | `admin.users.manage` | `{reason}`; respuesta con `warnings` de registros abiertos | — |
| POST | `/api/v1/iam/users/{id}/activate` | `admin.users.manage` | | — |
| POST | `/api/v1/iam/users/{id}/reset-mfa` | `admin.users.manage` | `{reason}` | — |
| POST | `/api/v1/iam/users/{id}/resend-invitation` | `admin.users.manage` | Solo `pendiente` | — |
| POST | `/api/v1/auth/invitations/{token}/accept` | público (token) | Define contraseña, enrola MFA si aplica | Idempotency-Key |
| GET/POST | `/api/v1/iam/sedes` | `admin.users.manage` | Listado (con conteo de usuarios activos y reservas) / crear | — |
| PATCH | `/api/v1/iam/sedes/{id}` | `admin.users.manage` | Editar | `lock_version` |
| POST | `/api/v1/iam/sedes/{id}/activate` · `/deactivate` | `admin.users.manage` | | — |
| DELETE | `/api/v1/iam/sedes/{id}` | `admin.users.manage` | Solo sin referencias (409 `SED-BR-04`) | — |

### 6.3 Roles y permisos
| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/v1/iam/roles` | `admin.roles.manage` | Roles con nº permisos y usuarios activos |
| POST | `/api/v1/iam/roles` | `admin.roles.manage` | Crear personalizado `{name, description, scope, copy_from_role?}` |
| PATCH | `/api/v1/iam/roles/{id}` | `admin.roles.manage` | Solo personalizados: `{name, description}` |
| DELETE | `/api/v1/iam/roles/{id}` | `admin.roles.manage` | Solo personalizados sin usuarios |
| GET | `/api/v1/iam/permissions` | `admin.roles.manage` | Catálogo agrupado por módulo (`permissionGroups`) |
| GET | `/api/v1/iam/role-permissions` | `admin.roles.manage` | Matriz completa rol × permiso |
| PUT | `/api/v1/iam/roles/{id}/permissions/{permission}` | `admin.roles.manage` | Otorgar (422 con código RBAC-BR-xx si bloqueado) |
| DELETE | `/api/v1/iam/roles/{id}/permissions/{permission}` | `admin.roles.manage` | Revocar |
| POST | `/api/v1/iam/roles/{id}/permissions/bulk` | `admin.roles.manage` | `{module, grant}` → `{applied, blocked[]}` |
| GET | `/api/v1/iam/access-map` | `admin.roles.manage` | Rol × pantalla calculado con la misma regla del router (00 §7.4) + resumen por rol |
| GET | `/api/v1/audit-events?entity=Permiso,Rol` | `admin.roles.manage` | Auditoría de cambios de permisos (12 últimos) |

### 6.4 Configuración (PROC-003)
| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/v1/settings` | `admin.settings.manage` (lectura parcial pública para el resto vía `/me/tenant`) | Todas las secciones |
| PATCH | `/api/v1/settings/{section}` | `admin.settings.manage` | `general`, `currency`, `policies`, `operations`, `channels`; responde `changed[]`; `lock_version` |
| GET | `/api/v1/settings/fx-rates` | `admin.settings.manage`, `finance.treasury` | Historial de TC |
| GET | `/api/v1/settings/sequences` | `admin.settings.manage` | Series con “próximo documento” |
| PATCH | `/api/v1/settings/sequences` | `admin.settings.manage` | Lote `{doc_type, prefix, next_number}[]` |
| POST | `/api/v1/settings/sequences` | `admin.settings.manage` | Agregar serie |

### 6.5 Notificaciones (PROC-059)
| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/v1/notifications/rules` | `admin.settings.manage` | Matriz evento × canal + regla |
| PATCH | `/api/v1/notifications/rules/{event}` | `admin.settings.manage` | Canales, `recipients`, `timing`, `condition` |
| POST | `/api/v1/notifications/rules/{event}/test` | `admin.settings.manage` | Envía prueba por los canales activos al usuario actual |
| GET | `/api/v1/notifications/templates/{event}/{channel}` | `admin.settings.manage` | Plantilla vigente (tenant o sistema) + versión |
| PUT | `/api/v1/notifications/templates/{event}/{channel}` | `admin.settings.manage` | Nueva versión |
| DELETE | `/api/v1/notifications/templates/{event}/{channel}` | `admin.settings.manage` | Restaurar predeterminada |
| POST | `/api/v1/notifications/templates/{event}/{channel}/test` | `admin.settings.manage` | Prueba al usuario actual |
| GET | `/api/v1/notifications/messages` | `admin.settings.manage` | Log con filtros canal/estado/evento |
| POST | `/api/v1/notifications/messages/{id}/retry` | `admin.settings.manage` | Reintento |
| POST | `/api/v1/notifications/messages/retry-failed` | `admin.settings.manage` | Reintenta todos los fallidos (encola) |
| POST | `/api/v1/notifications/messages/{id}/fallback` | `admin.settings.manage` | Reenvío por Email |

### 6.6 Integraciones, jobs y errores (PROC-064/065)
| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/v1/integrations` | `admin.settings.manage` \| SA | Adapters + contrato de interfaz |
| POST | `/api/v1/integrations/{id}/test` | ídem | Healthcheck vía adapter; persiste `last_test_*`; puede crear `integration_errors` |
| PATCH | `/api/v1/integrations/{id}/config` | ídem | `{environment, owner_user_id, max_retries, backoff_base_s, timeout_s}` (INT-BR-02) |
| GET | `/api/v1/jobs` | ídem | Scheduler |
| POST | `/api/v1/jobs/{id}/run` | ídem | Encola ejecución manual (**Idempotency-Key**; 409 si `en_curso`) |
| POST | `/api/v1/jobs/{id}/pause` · `/resume` | ídem | |
| DELETE | `/api/v1/jobs/{id}` | ídem o dueño con `reports.export` | Solo `report_key` no nulo |
| GET | `/api/v1/integration-errors` | ídem | Filtros estado/clase |
| POST | `/api/v1/integration-errors/{id}/retry` | ídem | |
| POST | `/api/v1/integration-errors/{id}/resolve` | ídem | `{resolution}` |

### 6.7 Auditoría, reportes, exportación y dashboard
| Método | Endpoint | Permiso | Descripción | Idempotente |
|---|---|---|---|---|
| GET | `/api/v1/audit-events` | `admin.audit.view` | Filtros usuario, entidad, acción, fecha (hoy/7/30/90), criticidad; agregados por acción/entidad/actor | — |
| GET | `/api/v1/audit-events/{id}` | `admin.audit.view` | Evento + relacionados (misma correlación o entidad) | — |
| POST | `/api/v1/audit-events/exports` | `admin.audit.view` | Exportar bitácora (job) | Idempotency-Key |
| GET | `/api/v1/reports/{report}` | `reports.view` + pestaña permitida | `report` ∈ comercial, cotizaciones, reservas, operaciones, finanzas, proveedores, clientes; `?period=set\|q3\|6m\|all&sede=` | — |
| POST | `/api/v1/reports/exports` | `reports.export` (o `admin.audit.view` para auditoría) | `{report_key, filters, format, scope}` → 202 con `export_id` | **Idempotency-Key** |
| GET | `/api/v1/reports/exports` | `reports.export` | Historial (15 últimos) + envíos programados | — |
| GET | `/api/v1/reports/exports/{id}/download` | `reports.export` | 302 a URL firmada; audita `descargar` | — |
| POST | `/api/v1/reports/schedules` | `reports.export` | `{report_key, frequency, time, format, recipients}` → crea `sys_scheduled_jobs` | — |
| GET | `/api/v1/dashboard/executive` | TA/MG (`reports.view`) | KPIs, series, servicios 7 días, salidas, pendientes, cotizaciones en seguimiento, incidencias | — |
| GET | `/api/v1/dashboard/manager` | `quotes.approve_internal` \| `approvals.view` | Aprobaciones pendientes, anulaciones/reversiones solicitadas, metas, desempeño por asesor | — |
| POST | `/api/v1/quotes/{id}/versions/{v}/internal-approval` | `quotes.approve_internal` | `{reason}` (definido en spec de Ventas; se invoca desde el dashboard) | `lock_version` |

Errores de negocio: 409 con `code` (`TEN-BR-09`, `USR-BR-05`, `SED-BR-03`, `RBAC-BR-02`…) o 422 con `errors`.

---

## 7. Permisos y comportamiento por rol

### 7.1 Administración
| Rol | Qué ve / hace | Qué se oculta o bloquea |
|---|---|---|
| Super Admin SaaS | Home `#/admin/tenants` (“Consola de plataforma”). Tenants: alta, cambiar plan, suspender/reactivar, prueba E2E-08. Integraciones: vista de plataforma con “Salud por tenant” + adapters/jobs/errores. Auditoría: solo eventos de plataforma, filtro y columna Tenant. | Cualquier otra ruta → 403 (“no opera los datos comerciales de las agencias”). En Usuarios no puede entrar. Aviso: soporte sobre datos de negocio requiere sesión delegada autorizada por el Tenant Admin |
| Tenant Admin | Usuarios y sedes, Roles y permisos (matriz editable), Configuración, Notificaciones, Integraciones y jobs, Auditoría completa del tenant, Suscripción, API | Tenants y planes (403). No puede cambiar su propio rol, desactivarse, ni quitarse `admin.users.manage`/`admin.roles.manage` |
| Manager | Solo Auditoría (lectura + exportar bitácora) | Resto de Administración: 403 y fuera del menú |
| Ventas, Operaciones, Finanzas, Contabilidad | — | Administración completa: 403. Pueden “Solicitar acceso al administrador” desde la pantalla 403 |
| Externos | — | Nunca ven back-office |

Botones con permiso: `Alta de tenant`, `Cambiar plan`, `Suspender/Reactivar` → `admin.tenant.manage` (sin él el drawer queda en solo lectura con aviso). `Invitar usuario`, menú de usuario, `Nueva sede`, editar/activar/eliminar sede → `admin.users.manage` (la pestaña Sedes sin permiso no muestra acciones). `Crear rol personalizado`, `Duplicar`, `Editar`, `Eliminar`, casillas de la matriz y contadores de módulo → `admin.roles.manage` (sin él: casillas `disabled` y aviso “Vista de solo lectura”). Columna Super Admin de la matriz siempre deshabilitada. Configuración/Notificaciones → `admin.settings.manage`.

### 7.2 Panel de control (dashboard.js)
| Rol | Panel |
|---|---|
| Tenant Admin | Panel ejecutivo (`execPanel(el,false)`): 5 KPIs (ventas del mes, margen bruto vs meta 28 %, pipeline abierto, reservas activas y pasajeros, por cobrar con vencidas), alerta de incidencia crítica (enlace si tiene `operations.view`), Ventas vs costo 6 meses, embudo, servicios 7 días, salidas y cupos, pendientes del equipo, cotizaciones en seguimiento, incidencias abiertas, leads/conversión, ventas por canal. **No** muestra el bloque de aprobaciones |
| Manager | Lo anterior + bloque de gerencia: “Aprobaciones pendientes” (cotizaciones fuera de política con botón Aprobar; anulaciones de reserva y reversiones de pago solicitadas con enlace), “Metas del mes” (ventas vs S/ 300k, margen vs 28 %, actividades vencidas, leads nuevos sin contactar, leads sin asignar) y “Desempeño por asesor” (usuarios activos Ventas/Manager: leads activos, pipeline, cotizaciones abiertas, ganado, conversión, margen medio < 15 % en rojo, actividades vencidas) |
| Otros | Paneles propios (Ventas, Operaciones, Finanzas, Contabilidad) documentados en sus módulos |
Botones: `Reportes` si `reports.view`; `Nueva cotización` activo si `quotes.create`, si no deshabilitado con “Requiere permiso quotes.create”; `Aprobar` activo si `quotes.approve_internal`; enlaces a rentabilidad/calendario/oportunidades solo si `Access.canRoute`.

### 7.3 Reportes: pestañas y datos por rol (`repTabsFor`)
| Rol | Pestañas | Alcance / datos sensibles | Exportar |
|---|---|---|---|
| Tenant Admin, Manager | Las 7 | Todo el tenant; filtro sede; márgenes y utilidad visibles | Sí |
| Ventas | Comercial, Cotizaciones | Solo su cartera; KPIs “Mis leads”, “Mis ventas”; margen visible (solo sus cotizaciones) | No (botones deshabilitados) |
| Operaciones | Reservas, Operaciones, Proveedores | Costos de proveedor sí; márgenes/utilidad no; CxP oculto (sin `finance.payables`) | No |
| Finanzas | Finanzas | Ventas, costos, utilidad, margen por reserva, aging CxC, CxP | Sí |
| Contabilidad | Finanzas (variante contable) | Ventas facturables, cobranza, aging; sin costos/márgenes/utilidad | Sí |
| Rol personalizado | Según permisos: comercial ← `crm.leads.view`; cotizaciones ← `quotes.view`; reservas ← `reservations.view`; operaciones ← `operations.view`; finanzas ← `finance.receivables` \| `finance.profitability`; proveedores ← `catalog.providers.manage` \| `finance.payables`; clientes ← `crm.clients.manage` | | según `reports.export` |
Enlaces a detalle (cotización, cliente, proveedor) solo si el rol puede abrir la ruta (`rlk`, `goIf`); si no, texto plano. Historial “Exportaciones (n)” solo con `reports.export`.

### 7.4 Contenido de cada reporte (lo que el endpoint debe devolver)
| Reporte | KPIs | Gráficos | Tabla detalle (columnas exportables) |
|---|---|---|---|
| Comercial / CRM | Leads recibidos (o “Mis leads”), conversión lead→reserva, pipeline abierto, win rate, ticket promedio ganado | Leads/cotizaciones/reservas por mes; embudo (Leads, Calificados/convertidos, Oportunidades, Cotizaciones, Aprobadas, Reservas); conversión por fuente; ventas por vendedor | Vendedor, Oport., Pipeline, Ganado, Win rate, Cotiz., Aprob., Reservas, Ventas + totales |
| Cotizaciones | Emitidas (y versiones), tasa de aprobación (aprobadas+convertidas / decididas), tiempo a primera vista (h), ciclo hasta aprobación (días), valor cotizado y margen medio | Donut por estado; motivos de rechazo/pérdida (rechazadas + vencidas + oportunidades perdidas sin cotización rechazada); horas hasta apertura | Cotización, Cliente, Vendedor, Estado, Vers., Total S/, Margen (<15 % en ámbar), Vista en, Vence |
| Reservas | Reservas/vigentes, pasajeros, ventas, ticket, canceladas | Por canal, destino, estado | Detalle por reserva |
| Operaciones | Servicios, confirmación, puntualidad de check-in (tolerancia `checkin_tolerance_min`), incidencias | Servicios por estado, incidencias por severidad y tipo | Órdenes/servicios |
| Finanzas y rentabilidad | Ventas netas, costo, utilidad, margen, CxC | Ventas vs costo, aging CxC (Por vencer, 1–30, 31–60, > 60), CxP por estado (con `finance.payables`) | Reserva, Cliente, Venta, Costo, Utilidad, Margen, % cobrado |
| Proveedores | Utilizados, servicios y % confirmados, costo, calificación media, contratos por vencer (≤ 60 días) | Costo por proveedor, volumen, costo por tipo | Proveedor, Servicios, Costo, Confirmación, Calificación, Por pagar (condicional), Contrato hasta, Estado |
| Clientes | Clientes (empresas/personas), con reservas, venta por cliente, NPS (promotores ≥ 9, detractores ≤ 6), extranjeros | Segmentos, top clientes, país de origen | Cliente, Segmento, País, Cotiz., Reservas, Ventas, NPS, Responsable |
Periodos: `set` (2026-09), `q3` (jul–set), `6m` (abr–set), `all`. Filtro sede: por `reservation.sede_id` o sede del owner.

---

## 8. Eventos, jobs (colas Redis) y notificaciones

### 8.1 Eventos de dominio emitidos por el módulo
| Evento | Disparador | Listeners (cola) |
|---|---|---|
| `TenantProvisioned` | alta | enviar invitación admin (`notifications`), crear bucket/rutas S3, sembrar jobs del tenant (`default`) |
| `TenantSuspended` / `TenantReactivated` | suspender/reactivar | revocar tokens (`critical`), pausar/reanudar jobs, notificar admin |
| `TenantPlanChanged` | cambio de plan | recalcular entitlements, facturación de plataforma |
| `UserInvited` / `UserActivated` / `UserDeactivated` / `UserRoleChanged` / `UserMfaReset` | PROC-002 | revocar tokens en baja/cambio de rol; flush caché permisos; notificaciones |
| `PermissionsChanged` | matriz de roles | `app(PermissionRegistrar::class)->forgetCachedPermissions()` por tenant; broadcast Reverb para refrescar menú de usuarios con ese rol |
| `SettingsUpdated` | configuración | invalidar caché `settings:{tenant}` en Redis |
| `NotificationRequested` | cualquier módulo vía `Notify` | resolver regla → plantilla → canales → `SendNotification` (`notifications`) |
| `ExportRequested` | exportar | `GenerateExport` (`reports`) |
| `InternalApprovalGranted` | dashboard Manager | notificación Portal al vendedor |

### 8.2 Jobs programados (semilla `DB.jobs`)
| Código | Nombre | Cron | Cola | Qué hace en el prototipo (“Ejecutar ahora”) |
|---|---|---|---|---|
| JOB-01 | Expirar holds de disponibilidad | cada 5 min | `critical` | Libera holds vencidos según `hold_minutes` |
| JOB-02 | Marcar cotizaciones vencidas | diario 00:05 | `default` | Cotizaciones `borrador/enviada/vista/negociacion` con `validUntil < hoy` → `vencida`; audita `vencer` por cotización |
| JOB-03 | Recordatorios de pago | diario 09:00 | `notifications` | CxC no pagadas con vencimiento ≤ `pay_reminder_days` → email `pago.recordatorio` |
| JOB-04 | Recordatorio de actividades CRM | cada hora | `notifications` | Cuenta actividades no completadas con vencimiento ≤ hoy |
| JOB-05 | Notificar próximos viajes | diario 05:00 | `notifications` | Reservas `confirmada/pendiente_pago` con inicio ≤ `trip_notice_hours` → email `viaje.proximo` |
| JOB-06 | Detectar reservas pendientes de proveedor | cada 2 h | `default` | Servicios `solicitado/pendiente`; si hay → estado `advertencia` |
| JOB-07 | Sincronizar proveedores (adapters) | cada 6 h | `integrations` | Reintento con backoff `2^attempt` min; al llegar a `max` → `fallo_definitivo`, email `integracion.fallo` a Operaciones, audita |
| JOB-08 | Reportes programados (gerencia) | lunes 07:00 | `reports` | Genera y envía PDF a destinatarios; crea `report_exports` |
| JOB-09 | Expirar asignaciones no confirmadas | cada 30 min | `default` | Asignaciones `pendiente` con más de `assign_expiry_min` |
| JOB-nn | Reporte programado: {reporte} | definido por usuario | `reports` | Igual que JOB-08 con formato y destinatarios propios |
Adicionales de plataforma recomendados: refresco de read models (15 min), cálculo de almacenamiento por tenant (diario), expiración de exportaciones (diario), vencimiento de periodo de prueba (diario) — ver §12.

### 8.3 Catálogo de eventos de notificación (configurables)
| Evento (label) | Código | Canales por defecto (E/W/P/Po) | Destinatarios por defecto |
|---|---|---|---|
| Cotización enviada | `cotizacion.enviada` | ✓ ✓ – ✓ | Cliente |
| Cotización aprobada | `cotizacion.aprobada` | ✓ – ✓ ✓ | Vendedor + Gerencia |
| Reserva confirmada | `reserva.confirmada` | ✓ ✓ ✓ ✓ | Cliente + Operaciones |
| Pago recibido | `pago.recibido` | ✓ ✓ – ✓ | Cliente + Finanzas |
| Asignación | `asignacion.creada` | – ✓ ✓ – | Guía / Conductor |
| Cambio de horario | `servicio.cambio_horario` | ✓ ✓ ✓ ✓ | Pasajeros + Guía |
| Incidencia crítica | `incidencia.critica` | ✓ ✓ ✓ – | Gerencia + Operaciones |
| Voucher emitido | `voucher.emitido` | ✓ – ✓ ✓ | Cliente + Proveedor |
| Inicio/fin de servicio | `servicio.inicio` | – ✓ ✓ ✓ | Pasajeros |
| Cierre de viaje | `viaje.cierre` | ✓ – – ✓ | Cliente (encuesta NPS) |
Eventos de sistema emitidos por este módulo (no configurables, siempre Email/Portal): `tenant.activado`, `tenant.suspendido`, `tenant.reactivado`, `usuario.invitado`, `seguridad.mfa_reset`, `acceso.solicitud`, `exportacion.lista` (Portal al solicitante), `reporte.programado`, `integracion.fallo`, `pago.recordatorio`, `viaje.proximo`, `cotizacion.aprobacion_interna`.
Plantillas predeterminadas: texto exacto en `admin.js` `DEF_TPL` (asunto + cuerpo por evento); WhatsApp = primeras 4 líneas sin líneas vacías; Push = cuerpo en una línea recortado a 140.

---

## 9. Auditoría

Todas vía `AuditLogger` (00 §9). Entidad · acción · antes → después:
| Entidad | Acciones |
|---|---|
| Tenant | `alta` (estado · plan · nº roles), `suspender`/`reactivar` (estado · motivo), `cambio_plan` (plan anterior → nuevo · vigencia), `acceso_denegado` (prueba E2E-08: 403 `TENANT_SCOPE_VIOLATION`) |
| Reserva | `acceso_denegado` (E2E-08: 404 recurso de otro tenant) |
| Usuario | `crear` (invitado · rol · sede), `editar`, `cambio_rol`, `desactivar` (motivo), `activar`, `resetear_mfa` (motivo), `reenviar_invitacion` |
| Sede | `crear`, `editar`, `activar`, `desactivar`, `eliminar` |
| Rol | `crear_rol` (copia de · nº permisos), `editar_rol`, `eliminar_rol` |
| Permiso | `otorgar_permiso`, `revocar_permiso` (entityId = rol; antes/después = permiso) |
| Configuración | `actualizar` por campo (`general.tz`, `moneda.fx`, `politicas.maxDiscountPct`, `series.Cotización`…), `crear` (nueva serie) |
| Notificación | `actualizar` (canal on/off), `actualizar_regla`, `reintentar`, `canal_alterno` |
| Plantilla | `actualizar` (vN → vN+1), `restaurar` |
| Integración | `probar_conexion`, `configurar`, `reintentar`, `resolver_error` |
| Job | `ejecutar_manual`, `pausar`, `reanudar`, `programar`, `eliminar`, `fallo_definitivo` |
| Exportación | `exportar`, `descargar`, `exportar_denegado` |
| Cotización | `aprobacion_interna` (desde dashboard), `vencer` (JOB-02) |
Acciones marcadas como críticas en la UI (`CRIT`): login, asignar, aprobar, rechazar, cancelar, crear_desde_cotizacion, revertir, cambio_precio, descuento_override, emitir, otorgar_permiso, revocar_permiso, suspender, reactivar, alta, desactivar, resetear_mfa, exportar, acceso_denegado, cambio_plan, crear_rol, cambio_rol, facturar, exportar_denegado.

---

## 10. Casos de prueba mínimos

### Pest (feature)
| # | Caso | Esperado |
|---|---|---|
| T-01 | E2E-08: usuario de TNT-A hace `GET /reservations/{id-de-B}` | 404 `RESOURCE_NOT_FOUND`; evento `acceso_denegado` |
| T-02 | Enviar `X-Tenant-Id` de otro tenant o `tenant_id` en body | Ignorado; datos del tenant del token; si la ruta lo fuerza → 403 `TENANT_SCOPE_VIOLATION` |
| T-03 | Alta de tenant con RUC `30123456789` / subdominio `AB` / subdominio duplicado | 422 por TEN-BR-01/02 |
| T-04 | Alta repetida con misma Idempotency-Key | Un solo tenant; segunda respuesta idéntica |
| T-05 | Suspender tenant propio de la sesión | 409 TEN-BR-07 |
| T-06 | Usuario de tenant suspendido intenta login | 403 `TENANT_SUSPENDED` |
| T-07 | Downgrade a Starter con 9 usuarios | 409 TEN-BR-09 |
| T-08 | Invitar con plan lleno / correo duplicado (mayúsculas) | 409 USR-BR-02 / 422 USR-BR-01 |
| T-09 | Tenant Admin cambia su propio rol / se desactiva / desactiva al último TA | 403/409 USR-BR-04/06/05 |
| T-10 | Desactivar usuario revoca sus tokens | request siguiente con su token → 401 |
| T-11 | Crear sede sobre límite; desactivar sede con usuarios; eliminar sede con reservas | 409 SED-BR-02/03/04 |
| T-12 | Revocar `admin.users.manage` a tenant_admin; otorgar `finance.receivables` a rol `canal`; otorgar `admin.tenant.manage` a manager | 422 RBAC-BR-02/04/05 |
| T-13 | Otorgar permiso y en la siguiente solicitud el usuario accede | 200 sin re-login |
| T-14 | RBAC 403: Manager `PATCH /settings/policies`; Ventas `GET /iam/users`; Super Admin `GET /crm/leads` | 403 |
| T-15 | Series: bajar `next_number` | 422 CFG-BR-07 y trigger bloquea update directo |
| T-16 | Cambiar moneda base con reservas existentes | 409 CFG-BR-05 |
| T-17 | Guardar config sin cambios → sin auditoría; con 2 cambios → 2 eventos | |
| T-18 | Desactivar todos los canales de incidencia crítica | 422 NTF-BR-01 |
| T-19 | Plantilla con `{{precio}}` o push de 141 caracteres | 422 NTF-BR-03/04 |
| T-20 | Envío WhatsApp falla → reserva que lo originó sigue confirmada | NTF-BR-06 |
| T-21 | Integración `por_validar` a Producción | 422 INT-BR-02 |
| T-22 | Error transitorio: 5 intentos con backoff y luego `fallo_definitivo` + alerta | |
| T-23 | Exportar sin `reports.export` (Ventas) | 403 + `exportar_denegado`; con permiso → 202 y `report_exports` `disponible` |
| T-24 | Ventas `GET /reports/comercial` solo devuelve su cartera; `GET /reports/finanzas` → 403 | REP-BR-01/02 |
| T-25 | Contabilidad `GET /reports/finanzas` sin campos `cost`, `profit`, `margin` | REP-BR-03 |
| T-26 | Super Admin `GET /platform/audit-events` no incluye entidades de negocio | AUD-BR-02 |
| T-27 | `UPDATE audit_events` o `DELETE` con el rol de la app | error de permisos DB |
| T-28 | Manager aprueba cotización fuera de política sin motivo → 422; con motivo → auditada y notificada | DSH-BR-02 |

### Playwright
- Super Admin: login → aterriza en Consola de plataforma; asistente de alta completo → tenant visible con estado “En prueba”; suspender/reactivar.
- Tenant Admin: invitar usuario → aparece “Invitación enviada”; editar rol; desactivar con motivo.
- Tenant Admin: matriz → desmarcar `reports.export` de Finanzas → mapa de acceso y pestañas de reportes de Finanzas reflejan el cambio.
- Manager: menú de Administración muestra solo Auditoría; `/admin/usuarios` → pantalla 403 con “Solicitar acceso”.
- Ventas: `/reportes` solo pestañas Comercial y Cotizaciones; botón Exportar deshabilitado con tooltip.
- 400 px: `/admin/roles/mapa` y `/reportes/finanzas` sin scroll horizontal de página (tablas dentro de `.tbl-wrap`).

---

## 11. Cambios y decisiones respecto a la documentación inicial

| # | Tipo | Cambio | Motivo |
|---|---|---|---|
| 1 | Precisado | PROC-001 se implementa como asistente de 11 pasos exactamente en el orden del documento (crear tenant → … → activar) con validaciones por paso y revisión final con checklist de controles | Hacer verificable “aislamiento, datos obligatorios, plan/entitlements, auditoría” |
| 2 | Agregado | Estados de tenant `prueba`/`activo`/`suspendido`, motivo obligatorio de suspensión, MRR/ARR y consumo (usuarios, almacenamiento) | La documentación no definía ciclo de vida del tenant ni métricas de plataforma |
| 3 | Agregado | Planes Starter/Professional/Enterprise con precios, límites (usuarios 5/15/∞, almacenamiento 5/25/100 GB, sedes 1/2/∞), matriz de 18 entitlements y canal mínimo por plan | “Plan/entitlements” se mencionaba sin contenido |
| 4 | Agregado | Prueba de aislamiento E2E-08 con dos variantes: recurso ajeno → **404** (no revela existencia) y cabecera `X-Tenant-Id` forzada → **403 `TENANT_SCOPE_VIOLATION`** | Precisa la respuesta esperada del caso E2E-08 |
| 5 | Precisado | PROC-002: invitación por enlace de un solo uso (72 h), estado `pendiente`, MFA exigible, baja lógica con motivo, reset de MFA, anti-auto-escalamiento y “al menos un Tenant Admin activo” | La documentación solo listaba la secuencia |
| 6 | Agregado | Sedes con límite por plan, responsable, no eliminación con referencias | “Sede” aparecía solo como paso |
| 7 | Agregado | Roles personalizados (crear/duplicar/editar/eliminar) y reglas de mínimo privilegio RBAC-BR-01…09 | Necesario para operar un RBAC editable de forma segura |
| 8 | Agregado | Mapa de acceso Rol × Pantalla calculado con la misma regla del router | Respuesta al reporte del cliente sobre roles que no respetaban funcionalidades |
| 9 | Cambiado | La matriz de permisos usa **57 permisos** en 11 módulos (ver 00 §7.1). En catálogo se usan 10 (`view`, `create`, `update`, `activate`, `manage_rates`, `manage_availability`, `view_profitability`, `publish`, `providers.manage`, `allotments.manage`) en lugar de los 19 de la especificación de Productos §35 | Simplificación para el prototipo; ver §12 si se requiere granularidad completa |
| 10 | Inconsistencia a resolver | `RBAC-BR-05` en el prototipo dice “`admin.tenant.manage` reservado al Tenant Admin”, pero la matriz base solo lo da a Super Admin y excluye a Tenant Admin | Decisión propuesta: permiso exclusivo de plataforma, no asignable dentro de un tenant |
| 11 | Precisado | PROC-003: seis secciones con claves, rangos y valores por defecto; guardado por diferencia con auditoría por campo; historial de TC; bloqueo de moneda base con transacciones; correlativos que no retroceden | La documentación listaba solo los temas |
| 12 | Agregado | Series adicionales configurables: Nota de crédito, Nota de débito, Liquidación de comisión, Recibo de caja, Orden de compra a proveedor | Documentos financieros que la operación necesita |
| 13 | Precisado | PROC-059: 10 eventos con código, regla (destinatarios, momento, condición), plantillas versionadas por canal con lista cerrada de variables, límite push 140, WhatsApp con plantillas aprobadas, reintento y canal alterno | La documentación solo indicaba la secuencia y los canales |
| 14 | Precisado | PROC-064/065: contratos de interfaz por tipo de adapter, estados de integración, política de backoff (1-2-4-8-16 min ± 20 %, 5 intentos), clasificación transitorio/permanente, resolución manual con motivo | Para implementación homogénea en jobs |
| 15 | Precisado | Scheduler §26: 9 jobs con cron concreto, cola y resultado; “Ejecutar ahora”, pausar/reanudar | Mapear §26 a jobs reales |
| 16 | Precisado | PROC-063: lista de acciones críticas del prototipo, drawer con eventos relacionados por correlación, exportación como job, vista de plataforma restringida a eventos técnicos | Requisito de “evidencia” y separación plataforma/tenant |
| 17 | Precisado | PROC-060: 7 reportes con KPIs, gráficos y columnas definidos; pestañas y datos sensibles por rol; variante contable sin márgenes | Aplicar principio “datos sensibles por rol” (BRIEF_ROLES) |
| 18 | Precisado | PROC-061: archivo disponible 7 días, URL firmada, auditoría de descarga, envíos programados como jobs | La secuencia no fijaba retención ni programación |
| 19 | Agregado | Panel ejecutivo para Tenant Admin/Manager y bloque de gerencia (aprobaciones fuera de política, anulaciones/reversiones, metas, desempeño por asesor) | “Manager: supervisión y reportes” sin pantalla definida |
| 20 | Cambiado | Super Admin SaaS NO accede a datos comerciales de tenants; su auditoría e integraciones muestran solo metadatos técnicos | Aislamiento multi-tenant y mínimo privilegio |

---

## 12. Supuestos y puntos a validar con negocio

| # | Punto | Propuesta |
|---|---|---|
| 1 | Transición `prueba` → `activo` y vencimiento del periodo de prueba (14 días) no existen en el prototipo | Job diario: al vencer sin plan pagado → `suspendido` con motivo “Fin de periodo de prueba”; botón “Activar” para Super Admin |
| 2 | Reactivar usuario o sede no valida el límite del plan | Validar límite también al reactivar |
| 3 | Aceptación de invitación (pantalla pública) no está prototipada | Pantalla `/(auth)/invitacion/[token]` con contraseña + enrolamiento TOTP |
| 4 | MFA obligatorio por rol | Obligatorio para Super Admin, Tenant Admin, Manager, Finanzas y Contabilidad; opcional resto (hoy es por usuario) |
| 5 | Sesión delegada de soporte para Super Admin (mencionada en la UI) | Definir flujo: solicitud → autorización del Tenant Admin → token temporal auditado de solo lectura |
| 6 | Umbrales de aprobación del dashboard (5 % / 15 %) están fijos en código | Leer `policies.max_discount_pct` y agregar `policies.min_margin_pct` (15) |
| 7 | Meta mensual (S/ 300k) y meta de margen (28 %) fijas | Tomarlas de `crm/metas` (módulo Metas comerciales) |
| 8 | Embudo y “ventas por canal” del panel ejecutivo son valores fijos | Alimentar desde `rpt_funnel` y `rpt_sales_by_channel` |
| 9 | Granularidad de permisos de catálogo (10 vs 19 de la especificación de Productos) | Confirmar si se implementan los 19 (`delete`, `suspend`, `archive`, `manage_components`, `manage_itineraries`, `manage_versions`, `manage_policies`, `manage_promotions`, `manage_b2b`, `manage_content`, `export`) |
| 10 | Proveedores reales de OSE/SUNAT, pasarela de pagos, WhatsApp (plantillas Meta), SBS automático | Pendiente de contrato del cliente (INT-01, INT-02, INT-04) |
| 11 | Vigencia del cambio de plan “Inmediata (prorrateo)” | Definir cálculo de prorrateo en facturación de plataforma (spec `plataforma`) |
| 12 | Retención de bitácora (“payload firmado conservado 5 años”) y de exportaciones (7 días) | Confirmar plazos legales |
| 13 | El botón “Exportar” genérico de todas las tablas (`UI.dataTable`) no verifica permiso en el prototipo | Backend: toda exportación pasa por `POST /reports/exports` con permiso `reports.export` o el `*.export` del módulo (00 §12) |
| 14 | Destinatarios de reglas de notificación son texto libre | Reemplazar por selector de roles/contactos (`recipients jsonb`) |
| 15 | Formatos XLSX/PDF de exportación: diseño de plantillas | Definir con negocio (logo, encabezado, pie) |