# Transversal: seguridad, multi-tenancy, RBAC y servicios del núcleo

> Fuente: `proto/assets/js/core.js` (Store, Auth, ACCESS, ROLE_UX, Access, Scope, router con 403, Shell, Audit, Notify, Seq, Pricing, `UI.dataTable` con exportación) y `proto/assets/js/data.js` (`roles`, `permissionGroups`, `rolePerms`, `users`, `settings.series`).
> Aplica a **todos** los módulos. Cada especificación de módulo referencia este documento para autenticación, tenant, permisos, ownership, auditoría, notificaciones, correlativos e idempotencia. La administración de estas piezas (pantallas de usuarios, roles, configuración, notificaciones, auditoría) está en `09-administracion-reportes.md`.

---

## 1. Propósito y procesos cubiertos

| Proceso / regla | Qué define este documento |
|---|---|
| PROC-062 Autenticación/autorización | Credenciales → Autenticación → Sesión/Token → Tenant → Rol → Permiso → Policy → Recurso (Sanctum + MFA TOTP, resolución de tenant, spatie/permission con teams, policies) |
| PROC-063 Auditoría | Servicio `AuditLogger`, tabla append-only, campos obligatorios, acciones críticas |
| PROC-059 Notificaciones (servicio) | Servicio `Notify` que usan todos los módulos (reglas y plantillas en 09) |
| Reglas transversales 1, 2, 5, 15, 16, 17, 22, 24 | Tenant obligatorio, sin acceso cruzado, precio en backend, moneda/TC conservados, auditoría, backend valida permisos, idempotencia, eliminación lógica |
| E2E-08 | Tenant A no accede a Tenant B |
| PRD-BR-010 / 011 / 015 | Precio calculado en backend; el frontend nunca es autoridad; aislamiento multi-tenant |
| Experiencias por rol | Back-office, portales (cliente, pasajero, B2B, proveedor), app operador, consola de plataforma |

---

## 2. Pantallas transversales

| Ruta prototipo | Ruta Next.js | Qué hace | Roles |
|---|---|---|---|
| `#/login` | `app/(auth)/login` | Selección de tenant, correo, contraseña; en el prototipo, perfiles de demostración. Producción: + paso MFA | Todos (sin sesión) |
| (paso MFA) | `app/(auth)/mfa` | Código TOTP / código de recuperación | Usuarios con MFA |
| Pantalla 403 (cualquier ruta no permitida) | `app/(…)/403` o `forbidden.tsx` | “Error 403 · Acceso restringido”, motivo, permisos requeridos (cualquiera de), ruta solicitada, “El intento quedó registrado en auditoría”, botón “Ir a mi inicio”; en back-office botón “Solicitar acceso al administrador” (notifica `acceso.solicitud` al Tenant Admin) | Todos |
| Ruta inexistente | `not-found.tsx` | “La pantalla solicitada no existe” | Todos |
| Drawer “Mi perfil” | `app/(…)/perfil` | Rol, experiencia (back-office / portal externo), alcance de datos, sede, tenant, MFA, último acceso, permisos efectivos | Todos |
| Drawer “Tenant activo” (chip del menú) | componente | Razón social, RUC, ID, plan, moneda/TC, zona, IGV, estado, sedes; recordatorio E2E-08 | Back-office |
| Campana / drawer de notificaciones | componente + Reverb | Últimas 25 notificaciones del usuario, marca todas como leídas, enlace al log (si puede) | Back-office |
| Búsqueda global (`/`) | componente | Clientes, leads, oportunidades, cotizaciones, reservas, productos, proveedores, órdenes; filtra por ruta permitida y ownership | Back-office |
| “Cambiar de rol (demo)” | **No se implementa en producción** | Solo prototipo | — |
| Shell back-office | `app/(backoffice)/layout.tsx` | Menú lateral filtrado por pantalla, barra superior | Roles internos |
| Shell externo | `app/(portal-*)/layout.tsx`, `app/(b2b)`, `app/(extranet-proveedor)`, app móvil | Barra superior azul con navegación propia, sin menú lateral, pie “Solo se muestran datos vinculados a su cuenta” | Externos |
| Sitio público | `app/(sitio)` | Sin login | Anónimos |

---

## 3. Modelo de datos PostgreSQL

### 3.1 `platform_users` (plataforma, sin tenant)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| name | varchar(150) | no | |
| email | varchar(190) | no | UNIQUE |
| password | varchar(255) | no | bcrypt/argon2id |
| mfa_secret | text | no | cifrado (`encrypted` cast); MFA obligatorio |
| status | varchar(20) | no | CHECK (`activo`,`inactivo`) |
| last_login_at | timestamptz | sí | |
| created_at, updated_at | timestamptz | no | |
Guard `platform`, rol `super_admin`. Nunca tiene filas en `iam_users`.

### 3.2 `iam_users`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK (código visible `U01` opcional en `code`) |
| tenant_id | uuid | no | FK `tenants(id)`; RLS |
| code | varchar(20) | sí | UNIQUE (tenant_id, code) |
| name | varchar(150) | no | |
| email | varchar(190) | no | UNIQUE (tenant_id, lower(email)) |
| password | varchar(255) | sí | NULL mientras `pendiente` |
| phone | varchar(30) | sí | |
| sede_id | uuid | no | FK `iam_sedes(id)` |
| status | varchar(20) | no | CHECK (`pendiente`,`activo`,`inactivo`) |
| mfa_enabled | boolean | no | default false |
| mfa_required | boolean | no | default false (invitación / reset) |
| last_login_at | timestamptz | sí | |
| last_login_ip | inet | sí | |
| avatar_color | char(7) | sí | |
| client_id | uuid | sí | FK `crm_clients(id)` — rol `customer`, `passenger`, y `b2b` (cliente contable de la agencia) |
| agency_id | uuid | sí | FK `channel_b2b_agencies(id)` — rol `b2b` |
| provider_id | uuid | sí | FK `catalog_providers(id)` — rol `provider` |
| guide_id | uuid | sí | FK `ops_guides(id)` — rol `guide` |
| driver_id | uuid | sí | FK `ops_drivers(id)` — rol `driver` |
| reservation_id | uuid | sí | FK `booking_reservations(id)` — rol `passenger` (su viaje) |
| invited_at | timestamptz | sí | |
| invited_by | uuid | sí | FK self |
| deactivated_at | timestamptz | sí | |
| deactivated_reason | text | sí | obligatorio si `inactivo` por baja |
| locale | varchar(10) | sí | |
| created_by, updated_by | uuid | sí | |
| created_at, updated_at, deleted_at | timestamptz | | nunca borrado físico (regla 24) |
Índices: `(tenant_id, status)`, `(tenant_id, sede_id)`, FKs de vínculo. Validación de servicio: cada rol externo exige su vínculo (SEC-BR-12).

### 3.3 `iam_mfa_factors`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | RLS |
| user_id | uuid | no | FK `iam_users(id)` UNIQUE (un TOTP activo) |
| type | varchar(10) | no | CHECK (`totp`) |
| secret | text | no | cifrado con `APP_KEY` / KMS |
| confirmed_at | timestamptz | sí | |
| recovery_codes | jsonb | no | hashes (8 códigos) |
| last_used_step | bigint | sí | anti-replay TOTP |
| created_at | timestamptz | no | |

### 3.4 `personal_access_tokens` (Sanctum, extendida)
Columnas estándar (`id`, `tokenable_type`, `tokenable_id`, `name`, `token` UNIQUE sha256, `abilities`, `last_used_at`, `expires_at`, timestamps) **más**:
| columna | tipo | null | notas |
|---|---|---|---|
| tenant_id | uuid | sí | NULL solo para `platform_users`; es la **única fuente** del tenant de la solicitud |
| experience | varchar(20) | no | `backoffice`, `portal`, `mobile`, `platform` (de ROLE_UX) |
| mfa_verified_at | timestamptz | sí | tokens sin MFA verificado solo tienen ability `mfa:verify` |
| device_name | varchar(120) | sí | app móvil / navegador |
| ip | inet | sí | |
Expiración: back-office 12 h deslizante; apps móviles 30 días con refresh; portales 7 días. Revocación masiva por usuario (baja, cambio de rol, reset MFA) y por tenant (suspensión).

### 3.5 spatie/laravel-permission con *teams*
Config: `'teams' => true`, `'team_foreign_key' => 'tenant_id'`, guard `api`, caché Redis por tenant.
| tabla | columnas relevantes | notas |
|---|---|---|
| `permissions` | `id`, `name` (UNIQUE, p. ej. `quotes.approve_internal`), `guard_name`, **`module`** varchar(40), **`description`** varchar(200) | Global (sin tenant); catálogo §7.1 sembrado por seeder |
| `roles` | `id`, **`tenant_id`** (team; NULL = plantilla de sistema), `name` (slug: `tenant_admin`, `manager`, `custom_counter_cusco`), `guard_name`, **`display_name`**, **`description`**, **`scope`** CHECK (`tenant`,`mobile`,`canal`), **`is_custom`** bool, **`experience`** CHECK (`backoffice`,`portal`,`mobile`), timestamps | UNIQUE (tenant_id, name, guard_name). Los 12 roles base se copian al tenant en el alta (PROC-001) para que su matriz sea editable por tenant |
| `role_has_permissions` | `permission_id`, `role_id` | |
| `model_has_roles` | `role_id`, `model_type`, `model_id`, **`tenant_id`** | Un rol por usuario (el prototipo asigna uno); PK incluye tenant_id |
| `model_has_permissions` | — | **No se usa**: prohibidos permisos directos a usuario (todo por rol, auditable) |
Resolución: middleware llama `setPermissionsTeamId($tenantId)` antes de cualquier `can()`.

### 3.6 `audit_events` (append-only, PROC-063)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK (v7); código visible `AUD-55121` en `code` bigint (secuencia) |
| code | bigint | no | secuencia global por tenant |
| tenant_id | uuid | sí | NULL = evento de plataforma sin tenant |
| actor_type | varchar(20) | no | CHECK (`user`,`platform_user`,`system`,`api_client`) |
| actor_id | uuid | sí | NULL para `system` (“Sistema”) |
| actor_role | varchar(60) | sí | rol al momento del evento |
| entity | varchar(40) | no | “Cotización”, “Reserva”, “Permiso”, “Acceso”… (catálogo cerrado) |
| entity_id | varchar(80) | no | código legible o ruta (`/admin/usuarios`) |
| action | varchar(40) | no | `login`, `acceso_denegado`, `otorgar_permiso`… |
| is_critical | boolean | no | según lista §9 |
| before_value | text | sí | |
| after_value | text | sí | |
| diff | jsonb | sí | cambios estructurados (opcional) |
| ip | inet | sí | |
| user_agent | varchar(250) | sí | |
| correlation_id | varchar(40) | no | = header `X-Request-Id` / trace id (`c-8812a` en prototipo) |
| evidence | jsonb | sí | referencias a archivos (incidencias), hash del payload firmado |
| occurred_at | timestamptz | no | |
Particionada por mes (`PARTITION BY RANGE (occurred_at)`). Índices por partición: `(tenant_id, occurred_at desc)`, `(tenant_id, entity, entity_id)`, `(tenant_id, actor_id)`, `(correlation_id)`, `(tenant_id, action)`.
Inmutabilidad: `REVOKE UPDATE, DELETE, TRUNCATE ON audit_events FROM app_role;` + trigger `BEFORE UPDATE OR DELETE … RAISE EXCEPTION`. Retención propuesta 5 años (ver 09 §12).

### 3.7 `sys_sequences` (correlativos)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | RLS |
| doc_type | varchar(40) | no | `quote`, `reservation`, `ops_order`, `voucher`, `invoice`, `receipt` (boleta), `credit_note`, `debit_note`, `commission_settlement`, `cash_receipt`, `purchase_order`… UNIQUE (tenant_id, doc_type, series) |
| label | varchar(60) | no | “Cotización”, “Reserva”, “Orden operativa”, “Voucher”, “Factura”, “Boleta”… |
| series | varchar(10) | no | `F001`, `B001` para comprobantes; `default` para internos |
| prefix | varchar(30) | no | `COT-2026-`, `RES-2026-`, `OP-2026-`, `VOU-2026-`, `F001-`, `B001-` |
| pad_width | smallint | no | Reserva 6, Voucher 5, Factura/Boleta 8, Orden operativa 4, resto 5 (tal como `Seq.next`) |
| next_number | bigint | no | CHECK > 0; trigger: no decrece |
| year_reset | boolean | no | true para documentos internos (prefijo con año); false para SUNAT |
| updated_at | timestamptz | no | |
Semilla del prototipo: Cotización `COT-2026-` 192, Reserva `RES-2026-` 350, Orden operativa `OP-2026-` 415, Voucher `VOU-2026-` 1205, Factura `F001-` 1484, Boleta `B001-` 8813.

### 3.8 `sys_idempotency_keys`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | sí | NULL para plataforma |
| key | varchar(80) | no | header `Idempotency-Key` (UUID recomendado) |
| user_id | uuid | sí | |
| method | varchar(10) | no | |
| path | varchar(250) | no | ruta normalizada |
| request_hash | char(64) | no | sha256 del body canónico |
| status | varchar(20) | no | CHECK (`processing`,`completed`,`failed`) |
| response_code | smallint | sí | |
| response_body | jsonb | sí | |
| locked_until | timestamptz | sí | |
| created_at | timestamptz | no | |
| expires_at | timestamptz | no | `created_at + 24 h` |
UNIQUE (tenant_id, key). Copia en Redis `idem:{tenant}:{key}` (TTL 24 h) para respuesta rápida; job diario purga expirados.

### 3.9 `sys_outbox`
`id uuid`, `tenant_id uuid null`, `aggregate_type varchar(40)`, `aggregate_id uuid`, `event varchar(80)`, `payload jsonb`, `status` CHECK (`pending`,`dispatched`,`failed`), `attempts smallint`, `available_at`, `dispatched_at timestamptz`, `created_at`. Insertado en la misma transacción que el cambio de negocio; un worker lo publica a la cola `integrations`.

### 3.10 `iam_access_requests` (solicitud desde la pantalla 403)
`id uuid`, `tenant_id`, `user_id`, `path varchar(250)`, `required_permissions text[]`, `status` CHECK (`pendiente`,`atendida`,`rechazada`), `resolved_by`, `resolved_at`, `created_at`. Evento `acceso.solicitud` al Tenant Admin.

### 3.11 RLS (todas las tablas con `tenant_id`)
```sql
ALTER TABLE <t> ENABLE ROW LEVEL SECURITY;
ALTER TABLE <t> FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON <t>
  USING (tenant_id = current_setting('app.tenant_id', true)::uuid)
  WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);
```
- `current_setting(..., true)` devuelve NULL si no hay contexto → **cero filas** (falla cerrado).
- Rol de conexión de la app `app_role` sin `BYPASSRLS`. Conexión de plataforma (`platform_role`) separada, con políticas propias solo sobre tablas de plataforma y agregados técnicos.
- Por request: dentro de la transacción `SELECT set_config('app.tenant_id', :tenant, true)` (equivalente a `SET LOCAL`), compatible con PgBouncer en modo transacción y con Octane.
- Jobs: middleware `TenantAware` que serializa `tenant_id` en el payload y fija el contexto antes de `handle()`.

---

## 4. Máquinas de estado

| Objeto | Estados y transiciones | Quién | Efectos |
|---|---|---|---|
| Sesión / token | `sin_sesión` → (login OK) `mfa_pendiente` → (TOTP OK) `activa` → (logout / expiración / revocación) `revocada` | Usuario; sistema (baja, cambio de rol, reset MFA, suspensión de tenant) | Audita `login` (after `OK` o `MFA OK`), `login_fallido`, `logout`; tokens revocados → 401 |
| Usuario | `pendiente` → `activo` ⇄ `inactivo`; `pendiente` → `inactivo` (detalle en 09 §4.2) | Tenant Admin | Revocación de tokens al pasar a `inactivo` |
| Factor MFA | `no_habilitado` → (enrolar) `pendiente_confirmación` → (código OK) `habilitado` → (reset) `pendiente_enrolamiento` (`mfa_required=true`) | Usuario / Tenant Admin | Audita `resetear_mfa` |
| Idempotency key | `processing` → `completed` \| `failed` (failed permite reintento con la misma key) | Middleware | Respuesta cacheada 24 h |
| Outbox | `pending` → `dispatched` \| `failed` (reintentos con backoff) | Worker | |
| Solicitud de acceso | `pendiente` → `atendida` \| `rechazada` | Tenant Admin | |
| Tenant (efecto en auth) | `suspendido` → login y todas las solicitudes 403 `TENANT_SUSPENDED` | Super Admin | Ver 09 §4.1 |

---

## 5. Reglas de negocio transversales

| ID | Regla | Dónde se valida |
|---|---|---|
| SEC-BR-01 (Regla transversal 1, PRD-BR-001) | Toda solicitud autenticada pertenece a un tenant válido y activo; sin tenant → 401/403 | Middleware `ResolveTenant` + `EnsureTenantActive` |
| SEC-BR-02 (RT 2, E2E-08, PRD-BR-015) | El tenant se resuelve **solo** desde el token (y se contrasta con el host `{slug}.dominio`); se ignora cualquier `tenant_id`/`X-Tenant-Id` del cliente. Recurso de otro tenant → **404** `RESOURCE_NOT_FOUND`; ruta que intenta fijar otro tenant → **403** `TENANT_SCOPE_VIOLATION`; ambos se auditan como `acceso_denegado` | Middleware + global scope `BelongsToTenant` + RLS |
| SEC-BR-03 (RT 17, PRD-BR-011) | El backend valida permisos en cada endpoint (Policy + permiso spatie). El frontend solo refleja (oculta/deshabilita); ocultar un botón no concede ni retira acceso | Policy |
| SEC-BR-04 | Cada pantalla exige “cualquiera de” los permisos de `ACCESS`; se busca el prefijo de ruta más largo definido; `*` = cualquier rol interno | Middleware Next + endpoint `GET /me/access` |
| SEC-BR-05 | Roles externos (guide, driver, b2b, customer, passenger, provider) nunca acceden al back-office: solo a las rutas de su experiencia (`ROLE_UX.routes`). Mensaje: “Esta pantalla pertenece al back-office interno de la agencia y no está disponible para usuarios externos (rol)” | Middleware `EnsureExperience` (ability del token) |
| SEC-BR-06 | Super Admin SaaS solo accede a `admin/tenants`, `admin/plataforma`, `admin/integraciones`, `admin/auditoria`; no opera datos comerciales de tenants | Guard `platform` + rutas `/api/v1/platform/*` |
| SEC-BR-07 | Cada acceso denegado (403 de pantalla o endpoint) se audita: entidad `Acceso`, id = ruta, before = rol, after = `requiere p1 \| p2` o `fuera del alcance del rol` | Exception handler |
| SEC-BR-08 | MFA TOTP para roles internos (obligatorio según política 09 §12-4); tokens sin MFA verificado no acceden a recursos | Middleware `EnsureMfaVerified` |
| SEC-BR-09 (Ownership Ventas) | Rol `sales`: listados, KPIs, tablero, búsquedas y reportes limitados a registros con `owner_id = auth.id` (leads también sin asignar en contadores); detalle ajeno → 403 con mensaje “pertenece a la cartera de otro asesor… solicite a su gerente la reasignación” | Policy `view` + scope `OwnedBy` + filtro `owner=me` forzado |
| SEC-BR-10 | Alcance de clientes de Ventas = clientes propios ∪ clientes de sus oportunidades, cotizaciones y reservas | Scope `Scope.clientIds()` |
| SEC-BR-11 | Gerente y Tenant Admin ven todo el tenant y disponen de filtro “Asesor” | Policy |
| SEC-BR-12 | Usuario externo solo ve sus datos: cliente → `client_id`; pasajero → `reservation_id`; B2B → `agency_id`; proveedor → `provider_id`; guía/conductor → `guide_id`/`driver_id`. Un cliente que abre `portal-cliente/{otro}` es redirigido al suyo | Policy por recurso + scope |
| SEC-BR-13 (datos sensibles) | Costos de proveedor, márgenes y utilidad solo con `finance.profitability`, `catalog.products.view_profitability` o `quotes.approve_internal`; Ventas ve margen solo de sus cotizaciones; Operaciones ve costos de proveedor pero no márgenes ni utilidad; externos nunca ven costos | `JsonResource` condicional (`when(can)`) |
| SEC-BR-14 | Acción no permitida: no se muestra activa (oculta o deshabilitada con “Requiere permiso x.y”); si llega por otra vía → 403 y sin cambios | Frontend + Policy |
| SEC-BR-15 (RT 16, PRD-BR-014) | Acciones críticas auditadas (lista §9) | `AuditLogger` |
| SEC-BR-16 (RT 22) | Operaciones críticas exigen `Idempotency-Key`; misma key + mismo cuerpo → misma respuesta; misma key + cuerpo distinto → 422 `IDEMPOTENCY_KEY_REUSED`; key en proceso → 409 `IDEMPOTENCY_IN_PROGRESS` | Middleware `Idempotent` |
| SEC-BR-17 | Recursos con `lock_version` requieren `If-Match`; desfase → 409 `STALE_VERSION` | Trait `OptimisticLock` |
| SEC-BR-18 (RT 5, PRD-BR-010) | Precios calculados en backend con `PricingService` (§5.1); el frontend solo previsualiza | Service |
| SEC-BR-19 (RT 15) | Moneda y `fx_rate` se congelan en cada documento; conversión a PEN = `monto × fx` si USD | Service |
| SEC-BR-20 | Correlativos: asignación atómica (`SELECT … FOR UPDATE` en `sys_sequences`), sin huecos por rollback en documentos fiscales (se asigna al confirmar), nunca retroceden | Service + trigger |
| SEC-BR-21 (RT 24) | Sin borrado físico de registros con historia: `deleted_at` / baja lógica | Modelos `SoftDeletes` |
| SEC-BR-22 | Una notificación fallida no revierte la transacción de negocio (listener en cola tras commit: `afterCommit`) | Listener |
| SEC-BR-23 | Exportar datos requiere permiso de exportación y se audita (`exportar`, `exportar_denegado`, `descargar`) | Policy |
| SEC-BR-24 | Tenant suspendido → 403 `TENANT_SUSPENDED` en login y solicitudes | Middleware |
| SEC-BR-25 | Rate limit: login 5/min por IP+email; API 120/min por usuario; exportaciones 10/h por usuario (Redis) | `RateLimiter` |

### 5.1 Motor de precios (referencia para Catálogo y Ventas)
`PricingService::calc` (idéntico a `Pricing.calc`):
```
base      = cost + extra
markup    = base × markupPct/100 + markupFixed
subtotal  = base + markup
discount  = subtotal × discountPct/100 + discountFixed
net       = subtotal − discount
tax       = net × taxPct/100
total     = round(net + tax, rounding)      // rounding por defecto 0.10
commission= net × commissionPct/100
profit    = net − base − commission
marginPct = profit / net × 100
```
`quoteTotals(version)`: por línea `costTotal = unitCost × qty × pax`, `saleTotal = unitPrice × qty × pax`; `discount = sale × discountPct/100`; `net = sale − discount`; `tax = net × taxPct/100`; `total = net + tax`; `profit = net − cost`; `marginPct = profit/net × 100`.
`seasonFor(date)`: temporadas `activa` que contienen la fecha, ordenadas por `priority` ascendente (0 = mayor prioridad: feriados/fechas especiales) → la primera.
Todo con `numeric` (brick/math `BigDecimal` en PHP), nunca float.

---

## 6. API REST transversal

| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| POST | `/api/v1/auth/login` | público | `{email, password, tenant_slug?}` (tenant por host) → token con ability `mfa:verify` si requiere MFA | rate limit |
| POST | `/api/v1/auth/mfa/verify` | token `mfa:verify` | `{code}` TOTP o recovery → token completo | rate limit |
| POST | `/api/v1/auth/logout` | autenticado | Revoca token actual; audita `logout` | — |
| POST | `/api/v1/auth/password/forgot` · `/reset` | público | Enlace de un solo uso | rate limit |
| POST | `/api/v1/auth/invitations/{token}/accept` | público | Ver 09 | Idempotency-Key |
| POST | `/api/v1/platform/auth/login` · `/mfa/verify` | público | Guard `platform` (MFA siempre) | rate limit |
| GET | `/api/v1/me` | autenticado | Usuario, rol, experiencia, sede, tenant, MFA, alcance (`own` \| `tenant` \| `linked`) | — |
| GET | `/api/v1/me/permissions` | autenticado | Permisos efectivos (lista) | — |
| GET | `/api/v1/me/access` | autenticado | `{experience, home, routes[], nav[], screens: {ruta: bool}}` calculado con ACCESS + ROLE_UX (misma regla que `Access.check`) | ETag |
| POST | `/api/v1/me/mfa/enroll` · `/confirm` · `DELETE /me/mfa` | autenticado | Enrolamiento TOTP (secret + QR) | — |
| GET | `/api/v1/me/tenant` | autenticado back-office | Datos del drawer “Tenant activo” (sin datos de plataforma) | — |
| GET | `/api/v1/search?q=` | autenticado back-office | ≥ 2 caracteres; máx. 12 resultados; filtra por pantallas permitidas y ownership (SEC-BR-09) | — |
| GET | `/api/v1/me/notifications` | autenticado | Últimas 25 + `unread_count` | — |
| POST | `/api/v1/me/notifications/read-all` | autenticado | Marca leídas | — |
| POST | `/api/v1/access-requests` | autenticado back-office | `{path}` desde pantalla 403 → notifica al Tenant Admin | — |
| Canal | `private-tenant.{tenantId}.user.{userId}` (Reverb) | autenticado | Notificaciones, cambios de permisos (refresca menú) | — |

Endpoints que **deben** exigir `Idempotency-Key` (lista consolidada; cada módulo lo repite en su tabla): alta de tenant, invitar usuario, aceptar invitación, convertir cotización en reserva (`POST /quotes/{id}/convert`), crear reserva (`POST /reservations`, B2B, e-commerce checkout), registrar pago (`POST /payments`), revertir pago, emitir voucher, emitir comprobante (factura/boleta/nota), pagos a proveedor, liquidar comisiones, webhooks entrantes (pasarela, OSE, WhatsApp), `POST /jobs/{id}/run`, `POST /reports/exports`.

Formato de error (todas las API):
```json
{ "error": "FORBIDDEN", "code": "SEC-BR-09", "message": "…", "required_permissions": ["quotes.view"], "correlation_id": "c-8812a" }
```

---

## 7. Permisos y comportamiento por rol

### 7.1 Catálogo de permisos (57, `DB.permissionGroups`)
| Módulo | Permisos |
|---|---|
| Administración | `admin.tenant.manage`, `admin.users.manage`, `admin.roles.manage`, `admin.settings.manage`, `admin.audit.view`, `admin.billing.manage`, `admin.api.manage` |
| Colaboración | `approvals.view`, `inbox.view`, `inbox.manage` |
| CRM | `crm.leads.view`, `crm.leads.manage`, `crm.opportunities.manage`, `crm.activities.manage`, `crm.clients.manage`, `crm.targets.view`, `crm.targets.manage`, `crm.claims.manage` |
| Marketing | `marketing.campaigns.manage` |
| Catálogo | `catalog.products.view`, `catalog.products.create`, `catalog.products.update`, `catalog.products.activate`, `catalog.products.manage_rates`, `catalog.products.manage_availability`, `catalog.products.view_profitability`, `catalog.products.publish`, `catalog.providers.manage`, `catalog.allotments.manage` |
| Cotizaciones | `quotes.view`, `quotes.create`, `quotes.approve_internal`, `quotes.send`, `quotes.discount_override` |
| Reservas | `reservations.view`, `reservations.create`, `reservations.modify`, `reservations.cancel` |
| Operaciones | `operations.view`, `operations.assign`, `operations.vouchers`, `operations.incidents`, `operations.close`, `operations.travel_docs` |
| Finanzas | `finance.receivables`, `finance.payments.register`, `finance.payments.revert`, `finance.payables`, `finance.commissions`, `finance.profitability`, `finance.invoicing`, `finance.treasury` |
| Canales | `channels.b2b.manage`, `channels.ecommerce.manage`, `channels.cms.publish` |
| Reportes | `reports.view`, `reports.export` |
Comodín: `Auth.can` acepta permisos terminados en `.` como prefijo (p. ej. `finance.`); ningún rol semilla lo usa. En backend **no** se admiten comodines (permisos explícitos).

### 7.2 Roles (`DB.roles`)
| id | Nombre | Descripción | scope | Experiencia |
|---|---|---|---|---|
| `super_admin` | Super Admin SaaS | Plataforma, planes y tenants | plataforma | Consola de plataforma |
| `tenant_admin` | Tenant Admin | Administración completa del tenant | tenant | Back-office |
| `manager` | Manager | Supervisión, aprobaciones y reportes | tenant | Back-office |
| `sales` | Ventas (Sales) | CRM, clientes y cotizaciones | tenant | Back-office (su cartera) |
| `operations` | Operaciones | Reservas, proveedores y ejecución | tenant | Back-office |
| `finance` | Finanzas | Cobros, pagos, costos y rentabilidad | tenant | Back-office |
| `accounting` | Contabilidad | Facturación y procesos contables | tenant | Back-office |
| `guide` | Guía | Ejecución de servicios (app operador) | mobile | App del operador |
| `driver` | Conductor | Transporte (app operador) | mobile | App del operador |
| `b2b` | Usuario B2B | Agencia asociada — canal B2B | canal | Portal de agencias |
| `customer` | Cliente | Portal del cliente | canal | Portal del cliente |
| `passenger` | Pasajero | Portal / app del pasajero | canal | Portal del pasajero |
| `provider` | Proveedor | Confirmación de solicitudes | canal | Extranet de proveedores |

### 7.3 Matriz completa Permiso × Rol (`DB.rolePerms`, semilla)
SA = Super Admin · TA = Tenant Admin · MG = Manager · VT = Ventas · OP = Operaciones · FI = Finanzas · CO = Contabilidad · GU = Guía · CD = Conductor · B2B · CL = Cliente · PA = Pasajero · PR = Proveedor

| Permiso | SA | TA | MG | VT | OP | FI | CO | GU | CD | B2B | CL | PA | PR |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| admin.tenant.manage | ✓ | | | | | | | | | | | | |
| admin.users.manage | | ✓ | | | | | | | | | | | |
| admin.roles.manage | | ✓ | | | | | | | | | | | |
| admin.settings.manage | | ✓ | | | | | | | | | | | |
| admin.audit.view | ✓ | ✓ | ✓ | | | | | | | | | | |
| admin.billing.manage | | ✓ | | | | | | | | | | | |
| admin.api.manage | | ✓ | | | | | | | | | | | |
| approvals.view | | ✓ | ✓ | ✓ | ✓ | ✓ | | | | | | | |
| inbox.view | | ✓ | ✓ | ✓ | ✓ | | | | | | | | |
| inbox.manage | | ✓ | ✓ | ✓ | ✓ | | | | | | | | |
| crm.leads.view | | ✓ | ✓ | ✓ | | | | | | | | | |
| crm.leads.manage | | ✓ | ✓ | ✓ | | | | | | | | | |
| crm.opportunities.manage | | ✓ | ✓ | ✓ | | | | | | | | | |
| crm.activities.manage | | ✓ | ✓ | ✓ | | | | | | | | | |
| crm.clients.manage | | ✓ | ✓ | ✓ | | | | | | | | | |
| crm.targets.view | | ✓ | ✓ | ✓ | | | | | | | | | |
| crm.targets.manage | | ✓ | ✓ | | | | | | | | | | |
| crm.claims.manage | | ✓ | ✓ | | | | | | | | | | |
| marketing.campaigns.manage | | ✓ | ✓ | | | | | | | | | | |
| catalog.products.view | | ✓ | ✓ | ✓ | ✓ | ✓ | | | | ✓ | | | |
| catalog.products.create | | ✓ | ✓ | | | | | | | | | | |
| catalog.products.update | | ✓ | ✓ | | | | | | | | | | |
| catalog.products.activate | | ✓ | ✓ | | | | | | | | | | |
| catalog.products.manage_rates | | ✓ | ✓ | | | | | | | | | | |
| catalog.products.manage_availability | | ✓ | ✓ | | ✓ | | | | | | | | |
| catalog.products.view_profitability | | ✓ | ✓ | | | ✓ | | | | | | | |
| catalog.products.publish | | ✓ | ✓ | | | | | | | | | | |
| catalog.providers.manage | | ✓ | | | ✓ | | | | | | | | |
| catalog.allotments.manage | | ✓ | ✓ | | ✓ | | | | | | | | |
| quotes.view | | ✓ | ✓ | ✓ | | ✓ | | | | | ✓ | | |
| quotes.create | | ✓ | ✓ | ✓ | | | | | | ✓ | | | |
| quotes.approve_internal | | ✓ | ✓ | | | | | | | | | | |
| quotes.send | | ✓ | ✓ | ✓ | | | | | | | | | |
| quotes.discount_override | | ✓ | ✓ | | | | | | | | | | |
| reservations.view | | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | | ✓ | ✓ | ✓ | |
| reservations.create | | ✓ | ✓ | ✓ | | | | | | ✓ | | | |
| reservations.modify | | ✓ | ✓ | | ✓ | | | | | | | | |
| reservations.cancel | | ✓ | ✓ | | | | | | | | | | |
| operations.view | | ✓ | ✓ | | ✓ | | | ✓ | ✓ | | | | ✓ |
| operations.assign | | ✓ | ✓ | | ✓ | | | | | | | | |
| operations.vouchers | | ✓ | ✓ | | ✓ | | | | | | | | |
| operations.incidents | | ✓ | ✓ | | ✓ | | | ✓ | ✓ | | | | |
| operations.close | | ✓ | ✓ | | ✓ | | | | | | | | |
| operations.travel_docs | | ✓ | ✓ | | ✓ | | | | | | | | |
| finance.receivables | | ✓ | ✓ | | | ✓ | ✓ | | | | | | |
| finance.payments.register | | ✓ | | | | ✓ | | | | | | | |
| finance.payments.revert | | ✓ | | | | ✓ | | | | | | | |
| finance.payables | | ✓ | ✓ | | | ✓ | | | | | | | |
| finance.commissions | | ✓ | ✓ | | | ✓ | | | | | | | |
| finance.profitability | | ✓ | ✓ | | | ✓ | | | | | | | |
| finance.invoicing | | ✓ | | | | ✓ | ✓ | | | | | | |
| finance.treasury | | ✓ | ✓ | | | ✓ | ✓ | | | | | | |
| channels.b2b.manage | | ✓ | ✓ | | | | | | | | | | |
| channels.ecommerce.manage | | ✓ | ✓ | | | | | | | | | | |
| channels.cms.publish | | ✓ | ✓ | | | | | | | | | | |
| reports.view | | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | | | | | |
| reports.export | | ✓ | ✓ | | | ✓ | ✓ | | | | | | |
| **Total** | **2** | **56** | **47** | **16** | **16** | **15** | **6** | **2** | **2** | **4** | **2** | **1** | **1** |

Construcción en data.js: Tenant Admin = todos excepto `admin.tenant.*`; Manager = todos excepto `admin.tenant/roles/settings/users/billing/api`, `finance.payments.register`, `finance.payments.revert`, `finance.invoicing`, `catalog.providers.manage`; el resto, listas explícitas. El seeder debe cargar exactamente esta matriz como plantilla “Estándar”.

Lista por rol (para el seeder):
- **super_admin (2)**: admin.tenant.manage, admin.audit.view
- **tenant_admin (56)**: todos menos admin.tenant.manage
- **manager (47)**: admin.audit.view, approvals.view, inbox.view, inbox.manage, crm.* (8), marketing.campaigns.manage, catalog.products.view/create/update/activate/manage_rates/manage_availability/view_profitability/publish, catalog.allotments.manage, quotes.* (5), reservations.* (4), operations.* (6), finance.receivables, finance.payables, finance.commissions, finance.profitability, finance.treasury, channels.* (3), reports.view, reports.export
- **sales (16)**: crm.leads.view, crm.leads.manage, crm.opportunities.manage, crm.activities.manage, crm.clients.manage, catalog.products.view, quotes.view, quotes.create, quotes.send, reservations.view, reservations.create, reports.view, approvals.view, inbox.view, inbox.manage, crm.targets.view
- **operations (16)**: catalog.products.view, catalog.products.manage_availability, catalog.providers.manage, reservations.view, reservations.modify, operations.view, operations.assign, operations.vouchers, operations.incidents, operations.close, reports.view, approvals.view, inbox.view, inbox.manage, catalog.allotments.manage, operations.travel_docs
- **finance (15)**: finance.receivables, finance.payments.register, finance.payments.revert, finance.payables, finance.commissions, finance.profitability, finance.invoicing, reservations.view, quotes.view, catalog.products.view, catalog.products.view_profitability, reports.view, reports.export, finance.treasury, approvals.view
- **accounting (6)**: finance.invoicing, finance.receivables, reservations.view, reports.view, reports.export, finance.treasury
- **guide (2)** / **driver (2)**: operations.view, operations.incidents
- **b2b (4)**: catalog.products.view, quotes.create, reservations.create, reservations.view
- **customer (2)**: quotes.view, reservations.view
- **passenger (1)**: reservations.view
- **provider (1)**: operations.view

Nota: en roles externos los permisos se interpretan **siempre** con el alcance de su vínculo (SEC-BR-12); p. ej. `reservations.view` de un cliente = sus reservas.

### 7.4 ROLE_UX: experiencia por rol
| Rol | Layout | Etiqueta | Inicio | Rutas permitidas (prefijos) | Navegación del portal |
|---|---|---|---|---|---|
| super_admin | backoffice (consola) | Consola de plataforma | `#/admin/tenants` | `admin/tenants`, `admin/plataforma`, `admin/integraciones`, `admin/auditoria` (regla fija) | menú lateral filtrado |
| tenant_admin, manager, sales, operations, finance, accounting | backoffice | Back-office interno | `#/dashboard` | según ACCESS | menú lateral filtrado |
| guide, driver | mobile | App del operador | `#/mobile/operador` | `mobile/operador` | — |
| passenger | portal | Portal del pasajero | `#/canales/portal-pasajero/viajes` | `canales/portal-pasajero`, `mobile/pasajero`, `libro-reclamaciones` | Mi viaje, Itinerario, Vouchers, Alertas, Contacto, App móvil |
| b2b | portal | Portal de agencias | `#/canales/b2b/portal/catalogo` | `canales/b2b/portal` | Catálogo y tarifas, Mis reservas, Estado de cuenta |
| customer | portal | Portal del cliente | `#/canales/portal-cliente/{clientId}/inicio` | `canales/portal-cliente/{clientId}`, `libro-reclamaciones` | Inicio, Cotizaciones, Mis viajes, Documentos, Pagos, Soporte, Libro de reclamaciones |
| provider | portal | Extranet de proveedores | `#/proveedor/solicitudes` | `proveedor` | Solicitudes, Rooming lists, Vouchers, Pagos, Incidencias, Mi contrato |
Roles personalizados: heredan la experiencia de su `scope` (`tenant` → back-office; `mobile` → app operador; `canal` → requiere definir portal, ver §12). Un rol desconocido cae en back-office con sus permisos.

Vínculos de los usuarios de prueba: U13 (B2B) → agencia AGB-01 y cliente CLI-0007; U14 (Cliente) → CLI-0001; U15 (Pasajero) → CLI-0003 y reserva RES-2026-000347; U09/U10 (Guías) → G01/G02; U11 (Conductor) → C01; U17 (Proveedor) → PRV-001.

### 7.5 ACCESS: permiso requerido por pantalla y resultado por rol
Regla (`Access.check`): (1) sin sesión → login; (2) experiencia externa → solo sus prefijos; (3) Super Admin → solo sus 4 rutas; (4) resto: se busca en ACCESS el prefijo **más largo** de la ruta; `*` o sin entrada → permitido; si no, requiere **cualquiera** de los permisos. El menú y la URL usan la misma regla.

| Ruta | Permiso requerido (cualquiera) | SA | TA | MG | VT | OP | FI | CO | GU | CD | B2B | CL | PA | PR |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `dashboard` | * (internos) | · | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | · | · | · | · | · | · |
| `flujo` | * (internos) | · | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | · | · | · | · | · | · |
| `crm` | `crm.leads.view` | · | ✓ | ✓ | ✓ | · | · | · | · | · | · | · | · | · |
| `crm/leads` | `crm.leads.view` | · | ✓ | ✓ | ✓ | · | · | · | · | · | · | · | · | · |
| `crm/oportunidades` | `crm.opportunities.manage` | · | ✓ | ✓ | ✓ | · | · | · | · | · | · | · | · | · |
| `crm/actividades` | `crm.activities.manage` | · | ✓ | ✓ | ✓ | · | · | · | · | · | · | · | · | · |
| `crm/clientes` | `crm.clients.manage`, `finance.receivables` | · | ✓ | ✓ | ✓ | · | ✓ | ✓ | · | · | · | · | · | · |
| `crm/postventa` | `crm.clients.manage` | · | ✓ | ✓ | ✓ | · | · | · | · | · | · | · | · | · |
| `crm/metas` | `crm.targets.view`, `crm.targets.manage` | · | ✓ | ✓ | ✓ | · | · | · | · | · | · | · | · | · |
| `crm/reclamaciones` | `crm.claims.manage` | · | ✓ | ✓ | · | · | · | · | · | · | · | · | · | · |
| `libro-reclamaciones` | `crm.claims.manage` (+ ROLE_UX cliente/pasajero) | · | ✓ | ✓ | · | · | · | · | · | · | · | ✓ | ✓ | · |
| `marketing` | `marketing.campaigns.manage` | · | ✓ | ✓ | · | · | · | · | · | · | · | · | · | · |
| `catalogo` | `catalog.products.view` | · | ✓ | ✓ | ✓ | ✓ | ✓ | · | · | · | · | · | · | · |
| `catalogo/productos` | `catalog.products.view` | · | ✓ | ✓ | ✓ | ✓ | ✓ | · | · | · | · | · | · | · |
| `catalogo/salidas` | `catalog.products.view` | · | ✓ | ✓ | ✓ | ✓ | ✓ | · | · | · | · | · | · | · |
| `catalogo/tarifas` | `catalog.products.view` | · | ✓ | ✓ | ✓ | ✓ | ✓ | · | · | · | · | · | · | · |
| `catalogo/servicios` | `catalog.products.view` | · | ✓ | ✓ | ✓ | ✓ | ✓ | · | · | · | · | · | · | · |
| `catalogo/destinos` | `catalog.products.view` | · | ✓ | ✓ | ✓ | ✓ | ✓ | · | · | · | · | · | · | · |
| `catalogo/proveedores` | `catalog.providers.manage`, `finance.payables`, `catalog.products.update` | · | ✓ | ✓ | · | ✓ | ✓ | · | · | · | · | · | · | · |
| `catalogo/categorias` | `catalog.products.update` | · | ✓ | ✓ | · | · | · | · | · | · | · | · | · | · |
| `catalogo/cupos` | `catalog.allotments.manage`, `catalog.products.view_profitability` | · | ✓ | ✓ | · | ✓ | ✓ | · | · | · | · | · | · | · |
| `cotizaciones` | `quotes.view` | · | ✓ | ✓ | ✓ | · | ✓ | · | · | · | · | · | · | · |
| `reservas` | `reservations.view` | · | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | · | · | · | · | · | · |
| `operaciones` | `operations.view` | · | ✓ | ✓ | · | ✓ | · | · | · | · | · | · | · | · |
| `operaciones/documentos-viaje` | `operations.travel_docs`, `reservations.create` | · | ✓ | ✓ | ✓ | ✓ | · | · | · | · | · | · | · | · |
| `finanzas` | `finance.receivables`, `finance.invoicing` | · | ✓ | ✓ | · | · | ✓ | ✓ | · | · | · | · | · | · |
| `finanzas/cobros` | `finance.receivables` | · | ✓ | ✓ | · | · | ✓ | ✓ | · | · | · | · | · | · |
| `finanzas/pagos` | `finance.payments.register`, `finance.receivables` | · | ✓ | ✓ | · | · | ✓ | ✓ | · | · | · | · | · | · |
| `finanzas/cuentas-por-pagar` | `finance.payables` | · | ✓ | ✓ | · | · | ✓ | · | · | · | · | · | · | · |
| `finanzas/comisiones` | `finance.commissions` | · | ✓ | ✓ | · | · | ✓ | · | · | · | · | · | · | · |
| `finanzas/rentabilidad` | `finance.profitability` | · | ✓ | ✓ | · | · | ✓ | · | · | · | · | · | · | · |
| `finanzas/facturacion` | `finance.invoicing` | · | ✓ | · | · | · | ✓ | ✓ | · | · | · | · | · | · |
| `finanzas/tesoreria` | `finance.treasury` | · | ✓ | ✓ | · | · | ✓ | ✓ | · | · | · | · | · | · |
| `finanzas/conciliacion` | `finance.payments.register` | · | ✓ | · | · | · | ✓ | · | · | · | · | · | · | · |
| `canales/b2b` | `channels.b2b.manage` | · | ✓ | ✓ | · | · | · | · | · | · | · | · | · | · |
| `canales/b2b/portal/*` | (ACCESS `canales/b2b`) + ROLE_UX B2B | · | ✓ | ✓ | · | · | · | · | · | · | ✓ | · | · | · |
| `canales/ecommerce` | `channels.ecommerce.manage` | · | ✓ | ✓ | · | · | · | · | · | · | · | · | · | · |
| `canales/cms` | `channels.cms.publish` | · | ✓ | ✓ | · | · | · | · | · | · | · | · | · | · |
| `canales/portal-cliente` | `channels.b2b.manage`, `channels.cms.publish`, `crm.clients.manage` | · | ✓ | ✓ | ✓ | · | · | · | · | · | · | ✓ (solo su `clientId`) | · | · |
| `canales/portal-pasajero` | `channels.b2b.manage`, `channels.cms.publish`, `operations.assign` | · | ✓ | ✓ | · | ✓ | · | · | · | · | · | · | ✓ | · |
| `mobile` | `operations.assign` | · | ✓ | ✓ | · | ✓ | · | · | · | · | · | · | · | · |
| `mobile/operador` | `operations.assign` + ROLE_UX guía/conductor | · | ✓ | ✓ | · | ✓ | · | · | ✓ | ✓ | · | · | · | · |
| `mobile/pasajero` | `operations.assign` + ROLE_UX pasajero | · | ✓ | ✓ | · | ✓ | · | · | · | · | · | · | ✓ | · |
| `proveedor` | `__solo_extranet__` (ningún permiso interno) | · | · | · | · | · | · | · | · | · | · | · | · | ✓ |
| `reportes` | `reports.view` | · | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | · | · | · | · | · | · |
| `aprobaciones` | `approvals.view` | · | ✓ | ✓ | ✓ | ✓ | ✓ | · | · | · | · | · | · | · |
| `inbox` | `inbox.view` | · | ✓ | ✓ | ✓ | ✓ | · | · | · | · | · | · | · | · |
| `admin` | `admin.users.manage`, `admin.tenant.manage` | · | ✓ | · | · | · | · | · | · | · | · | · | · | · |
| `admin/tenants` | `admin.tenant.manage` | ✓ | · | · | · | · | · | · | · | · | · | · | · | · |
| `admin/plataforma` | `admin.tenant.manage` | ✓ | · | · | · | · | · | · | · | · | · | · | · | · |
| `admin/usuarios` | `admin.users.manage` | · | ✓ | · | · | · | · | · | · | · | · | · | · | · |
| `admin/roles` | `admin.roles.manage` | · | ✓ | · | · | · | · | · | · | · | · | · | · | · |
| `admin/configuracion` | `admin.settings.manage` | · | ✓ | · | · | · | · | · | · | · | · | · | · | · |
| `admin/notificaciones` | `admin.settings.manage` | · | ✓ | · | · | · | · | · | · | · | · | · | · | · |
| `admin/integraciones` | `admin.settings.manage`, `admin.tenant.manage` | ✓ | ✓ | · | · | · | · | · | · | · | · | · | · | · |
| `admin/auditoria` | `admin.audit.view` | ✓ | ✓ | ✓ | · | · | · | · | · | · | · | · | · | · |
| `admin/suscripcion` | `admin.billing.manage` | · | ✓ | · | · | · | · | · | · | · | · | · | · | · |
| `admin/api` | `admin.api.manage` | · | ✓ | · | · | · | · | · | · | · | · | · | · | · |
| `sitio/*` | público (sin login) | — | — | — | — | — | — | — | — | — | — | — | — | — |
Observaciones que el backend debe replicar: `operaciones/documentos-viaje` es accesible a Ventas (por `reservations.create`) aunque el resto de `operaciones` no; `crm/clientes` y `finanzas/pagos` son accesibles a Contabilidad en lectura (por `finance.receivables`); `catalogo/cupos` a Finanzas (por `view_profitability`); `canales/portal-pasajero` a Operaciones (por `operations.assign`). Las vistas internas del portal (“Vista del canal”, “Ver como…”) solo existen para roles internos. Spec 07: `finanzas/proveedores` pasa a `finanzas/cuentas-por-pagar` y `finanzas/facturacion`/`finanzas/conciliacion` ya no admiten `finance.profitability` (P-12), así que Gerencia no ve esos menús.

### 7.6 Ownership (rol Ventas)
| Recurso | Clave | Lista | Detalle ajeno | Contadores de menú |
|---|---|---|---|---|
| Leads | `owner_id` | propios (en contador “nuevos”: también sin asignar) | 403 `Scope.denied` “El lead LEAD-… pertenece a la cartera de otro asesor” | Leads nuevos: propios + sin asignar |
| Oportunidades | `owner_id` | propias | 403 | — |
| Actividades | `owner_id` | propias | 403 | pendientes propias |
| Cotizaciones | `owner_id` | propias | 403 | enviadas/vistas/negociación propias |
| Reservas | `owner_id` | propias | 403 | — |
| Clientes | derivado | `clientIds()` = propios ∪ de sus oportunidades/cotizaciones/reservas | 403 | — |
| Búsqueda global | — | Lead/Oportunidad/Cotización/Reserva solo propios; demás tipos solo si puede abrir la ruta | — | — |
| Reportes / dashboard | `owner_id` | “Mi cartera” | — | — |
Implementación: scope `OwnedBy` aplicado en `index` cuando `auth()->user()->hasRole('sales')`; Policy `view/update` compara `owner_id`; en listados `filter[owner]=me` se fuerza en servidor (el cliente no puede ampliarlo). Gerente/Admin: filtro “Asesor”. Etiqueta de alcance: “Mi cartera” vs “Todo el equipo”.

### 7.7 Experiencias externas (reglas comunes)
- Shell propio sin menú lateral, sin búsqueda global ni campana de back-office, sin “Ver documentación de procesos”.
- Nunca ven: barras “Vista del canal”, selectores “Ver como…”, pestañas internas, IDs de otros clientes, costos, márgenes ni textos de demo.
- En la pantalla 403 externa no aparece “Solicitar acceso al administrador”.
- Cada endpoint de portal filtra por el vínculo del usuario (SEC-BR-12) además del permiso.
- Rutas API separadas por experiencia: `/api/v1/portal/client/*`, `/api/v1/portal/passenger/*`, `/api/v1/b2b/*`, `/api/v1/supplier/*`, `/api/v1/mobile/operator/*` (abilities del token).

---

## 8. Eventos, jobs (colas Redis) y notificaciones

### 8.1 Servicio `Notify` (usado por todos los módulos)
Firma del prototipo: `Notify.push(event, channel, to, subject, status)` → fila en notificaciones con `id NTF-n`, `ts`, `event`, `channel` (Email/WhatsApp/Push/Portal), `to` (usuario, email o teléfono), `subject`, `status` (`enviado` por defecto), `retries 0`, y refresca la campana.
Producción:
1. El módulo emite un evento de dominio (`QuoteSent`, `ReservationConfirmed`…) **después del commit**.
2. `NotificationDispatcher` (cola `notifications`) busca `notif_event_rules` del tenant, resuelve destinatarios, aplica condición y momento (inmediato / lote 15 min / horario laboral), elige plantilla vigente por canal y crea `notif_messages` (`pendiente`).
3. Un job por canal envía vía adapter (`MailSender`, `MessagingChannel`, push FCM/APNs, Portal = registro + broadcast Reverb) y actualiza estado; fallos según política de reintentos (09 §5 INT-BR-04/05).
4. Canal Portal/Push → broadcast `private-tenant.{t}.user.{u}` para la campana.

### 8.2 Colas
`critical` (pagos, conversión, revocación de tokens), `default`, `notifications`, `documents` (PDF), `integrations` (outbox, adapters), `reports` (exportaciones, read models), `low`. Horizon con supervisores por cola; jobs `TenantAware`, `tries`, `backoff` exponencial, `ShouldBeUnique` donde aplique, registro en `sys_job_runs`.

### 8.3 Eventos transversales
| Evento | Efecto |
|---|---|
| `UserLoggedIn` / `LoginFailed` / `UserLoggedOut` | Auditoría (`login`, `login_fallido`, `logout`); `last_login_at/ip` |
| `AccessDenied` | Auditoría `acceso_denegado`; métrica de seguridad; alerta si > 20/min por usuario |
| `CrossTenantAttempt` | Auditoría + alerta de seguridad a plataforma |
| `PermissionsChanged` / `UserRoleChanged` | Flush caché spatie del tenant; broadcast para refrescar `/me/access` |
| `TokensRevoked` | Baja, reset MFA, suspensión de tenant |

---

## 9. Auditoría

Servicio `AuditLogger::log(entity, entityId, action, before, after, evidence?)` (equivale a `Audit.log`). Completa automáticamente: `tenant_id` (contexto), actor (usuario, plataforma o `Sistema`), rol, IP, user agent, `correlation_id` (header `X-Request-Id`; generado si falta y devuelto en la respuesta), `occurred_at`, `is_critical`. Escritura síncrona dentro de la transacción de negocio (si la transacción falla, no hay evento), salvo `acceso_denegado` y `login_fallido` que se escriben en transacción propia.

Campos mínimos PROC-063 → columnas: actor → `actor_*`; tenant → `tenant_id`; entidad → `entity`/`entity_id`; acción → `action`; estado anterior/nuevo → `before_value`/`after_value`; fecha/hora → `occurred_at`; correlación → `correlation_id`; evidencia → `evidence`.

Acciones críticas (`is_critical = true`): `login`, `asignar`, `aprobar`, `rechazar`, `cancelar`, `crear_desde_cotizacion`, `revertir`, `cambio_precio`, `descuento_override`, `emitir`, `otorgar_permiso`, `revocar_permiso`, `suspender`, `reactivar`, `alta`, `desactivar`, `resetear_mfa`, `exportar`, `acceso_denegado`, `cambio_plan`, `crear_rol`, `cambio_rol`, `facturar`, `exportar_denegado`. Cada módulo debe mapear además sus acciones de pago, costo, incidencia y documentos a esta lista.

Eventos transversales registrados por el núcleo:
| Entidad | Acción | Antes → después |
|---|---|---|
| Sesión | `login` | `''` → `OK` / `MFA OK` |
| Sesión | `logout` | |
| Acceso | `acceso_denegado` | rol → `requiere p1 \| p2` o `fuera del alcance del rol` (id = `/ruta`) |
| Exportación | `exportar` | `''` → `N filas` (tabla genérica) |
| Reserva / Tenant | `acceso_denegado` | intentos cross-tenant (404/403) |

---

## 10. Casos de prueba mínimos

### Pest
| # | Caso | Esperado |
|---|---|---|
| X-01 | E2E-08: token de A, `GET` recurso de B | 404; RLS devuelve 0 filas aun con consulta directa sin scope |
| X-02 | `X-Tenant-Id: B` o `?tenant_id=B` con token de A | Datos de A; si la ruta fija tenant → 403 `TENANT_SCOPE_VIOLATION` |
| X-03 | Conexión sin `app.tenant_id` | `SELECT` en tabla de negocio → 0 filas; `INSERT` → violación de política |
| X-04 | Job de cola sin contexto de tenant | Falla explícita (no procesa datos) |
| X-05 | Matriz RBAC: para cada rol semilla y cada permiso de §7.3, un endpoint representativo responde 200/403 según la matriz (test parametrizado desde el seeder) | |
| X-06 | ACCESS: `GET /me/access` por rol coincide con la tabla §7.5 (snapshot) | |
| X-07 | Externos: cliente U14 pide cotización de otro cliente → 403/404; pasajero pide otra reserva → 404; proveedor pide solicitud de otro proveedor → 404; guía pide asignación de otro guía → 404 | SEC-BR-12 |
| X-08 | Ownership: Ventas U03 lista cotizaciones → solo `owner=U03`; `filter[owner]=U04` → ignorado; detalle de COT de U04 → 403 | SEC-BR-09 |
| X-09 | Datos sensibles: Operaciones `GET /quotes/{id}` sin `margin/profit`; Ventas ve margen de su cotización y no de ajenas; Contabilidad sin costos | SEC-BR-13 |
| X-10 | Super Admin `GET /api/v1/crm/leads` | 403 |
| X-11 | Login sin MFA verificado accede a recurso | 403 `MFA_REQUIRED` |
| X-12 | Token de usuario desactivado / tenant suspendido | 401 / 403 |
| X-13 | Idempotencia: `POST /payments` dos veces misma key → 1 pago, misma respuesta; misma key cuerpo distinto → 422; concurrente → 409 | SEC-BR-16 |
| X-14 | Concurrencia: dos `PATCH` con mismo `lock_version` → segundo 409 | SEC-BR-17 |
| X-15 | Correlativos: 50 creaciones concurrentes → 50 números consecutivos sin duplicados | SEC-BR-20 |
| X-16 | Auditoría: `UPDATE/DELETE audit_events` → error; acceso denegado genera evento con correlación igual al header de respuesta | |
| X-17 | Notificación fallida no revierte la reserva | SEC-BR-22 |
| X-18 | Pricing: casos de `calc` y `quoteTotals` con redondeo 0.10 y moneda USD→PEN | valores idénticos al prototipo |

### Playwright
- Para cada usuario de prueba (U01…U17): login → aterriza en su `home`; nº de ítems de menú igual al del prototipo (`node roles.js`); abrir una ruta prohibida muestra 403 con permisos requeridos.
- Externos (U09, U13, U14, U15, U17): no existe menú lateral; navegación propia; pie “Solo se muestran datos vinculados a su cuenta”.
- U14 abre `/canales/portal-cliente/CLI-0002/inicio` → redirige a su propio portal.
- Búsqueda global con U03: no aparecen cotizaciones de otros asesores.

---

## 11. Cambios y decisiones respecto a la documentación inicial

| # | Tipo | Cambio | Motivo |
|---|---|---|---|
| 1 | Precisado | PROC-062 implementado como cadena de middleware: `auth:sanctum` → `ResolveTenant` (desde token + host) → `EnsureTenantActive` → `EnsureMfaVerified` → `SetDbTenant` (RLS) → `SetPermissionsTeam` → `EnsureExperience` → Policy | Traducir la secuencia documental a componentes Laravel verificables |
| 2 | Agregado | Doble aislamiento: global scope Eloquent **y** RLS de PostgreSQL con falla cerrada | CONVENCIONES §1; defensa en profundidad para E2E-08 |
| 3 | Precisado | E2E-08: recurso de otro tenant → 404 (no revela existencia); intento explícito de fijar otro tenant → 403 `TENANT_SCOPE_VIOLATION`; ambos auditados | El documento solo decía “no puede acceder” |
| 4 | Agregado | Matriz de permisos realista (57 permisos, 13 roles) basada en ERP turísticos y CRM de mercado, con Manager sin configuración ni caja, Ventas limitado a su cartera, Operaciones sin rentabilidad, Finanzas/Contabilidad sin catálogo ni CRM | El cliente reportó que los roles no respetaban funcionalidades (BRIEF_ROLES) |
| 5 | Agregado | Permiso requerido por pantalla (ACCESS) y pantalla 403 auditada con “Solicitar acceso” | Coherencia menú/URL; evidencia de seguridad |
| 6 | Agregado | Experiencias por rol (ROLE_UX): externos con shell propio, rutas acotadas y vínculo obligatorio a sus datos | “Portal cliente/pasajero, B2B, proveedor” no definían aislamiento de UI ni de datos |
| 7 | Agregado | Ownership del rol Ventas (cartera propia) y regla de visibilidad de datos sensibles (costos/márgenes) | Práctica de CRM de mercado; confidencialidad comercial |
| 8 | Cambiado | Super Admin SaaS reducido a consola de plataforma (tenants, planes, jobs, integraciones, auditoría técnica) sin datos comerciales; usuario de plataforma separado (`platform_users`, guard `platform`) | Aislamiento y mínimo privilegio |
| 9 | Agregado | MFA TOTP, códigos de recuperación, revocación de tokens por baja/cambio de rol/reset MFA/suspensión | “MFA para roles internos” (CONVENCIONES) sin detalle |
| 10 | Precisado | Auditoría: tabla particionada append-only con `correlation_id` = `X-Request-Id`, lista de acciones críticas del prototipo, accesos denegados incluidos | PROC-063 listaba campos, no mecanismo |
| 11 | Precisado | Idempotencia (RT 22): header, tabla + Redis 24 h, códigos 409/422 y lista de endpoints obligatorios | Regla transversal sin mecanismo definido |
| 12 | Precisado | Correlativos por tipo de documento con prefijo, ancho y regla “no retroceder”; reinicio anual para internos | Series mencionadas en PROC-003 sin reglas |
| 13 | Precisado | Motor de precios: fórmula exacta (markup, descuento, IGV, redondeo, comisión, utilidad, margen) y selección de temporada por prioridad | PROC-018/RT 5 sin fórmula |
| 14 | Cambiado | Catálogo de permisos de catálogo reducido a 10 frente a los 19 de la especificación de Productos §35 | Simplificación del prototipo (pendiente de confirmar, §12) |
| 15 | Agregado | Permisos nuevos: `approvals.view`, `inbox.view`, `inbox.manage`, `crm.targets.view/manage`, `crm.claims.manage`, `marketing.campaigns.manage`, `catalog.allotments.manage`, `operations.travel_docs`, `finance.treasury`, `admin.billing.manage`, `admin.api.manage` | Módulos nuevos del SaaS (BRIEF_NUEVOS) |

---

## 12. Supuestos y puntos a validar con negocio

| # | Punto | Propuesta |
|---|---|---|
| 1 | La exportación CSV genérica de `UI.dataTable` (todas las tablas) no verifica permiso; solo audita | En producción toda exportación pasa por `POST /reports/exports` y exige `reports.export` (o permiso `*.export` por módulo si se adopta). Ventas y Operaciones hoy no lo tienen |
| 2 | La búsqueda global de Ventas filtra por ownership leads/oportunidades/cotizaciones/reservas, pero **no clientes** ni productos | Aplicar `clientIds()` también a clientes en la búsqueda |
| 3 | `admin/roles` en el módulo acepta `admin.users.manage` como lectura, pero ACCESS exige `admin.roles.manage` | Unificar: lectura de roles con `admin.users.manage` o `admin.roles.manage` |
| 4 | Roles personalizados de alcance `canal` no tienen experiencia/portal definido | Exigir elegir experiencia (portal existente) al crear el rol, o limitar personalizados a `tenant`/`mobile` |
| 5 | Un usuario = un rol | Confirmar; si se requieren varios roles, la unión de permisos y la experiencia “más interna” gana |
| 6 | Política de MFA por rol y de expiración de tokens (12 h / 7 d / 30 d) | Validar con seguridad del cliente |
| 7 | Sesión delegada de soporte de plataforma | Definir autorización y duración |
| 8 | Contabilidad accede a `finanzas/pagos` y `crm/clientes` en lectura por `finance.receivables` | Confirmar que es deseado (hoy sí) |
| 9 | Ventas accede a `operaciones/documentos-viaje` por `reservations.create` | Confirmar alcance (solo documentos de sus reservas) |
| 10 | Reinicio anual de correlativos internos (prefijo `-2026-`) | Confirmar; comprobantes SUNAT no reinician |
| 11 | Retención de auditoría (5 años) y de tokens de idempotencia (24 h) | Confirmar |
| 12 | Rate limits propuestos (§5 SEC-BR-25) | Ajustar con volumen real |
| 13 | Comodín de permisos (`finance.`) del prototipo | No se implementa en backend |
