# Plataforma SaaS: suscripción, facturación de plataforma, entitlements y API pública

Prototipo: `proto/assets/js/modules/plataforma.js` · Rutas `#/admin/suscripcion`, `#/admin/api`, `#/admin/plataforma`.
Stack y convenciones generales: `proto/docs/CONVENCIONES.md`.

## 1. Propósito y procesos cubiertos

Este módulo es lo que permite vender el producto como SaaS. Cubre:

- **Suscripción del tenant**: plan, ciclo (mensual/anual), add-ons, consumo frente a límites, cambio de plan con prorrateo, cupones, método de pago, datos de facturación, renovación y cancelación.
- **Facturación de la plataforma**: facturas electrónicas que la plataforma (Qhapaq Cloud S.A.C.) emite a cada tenant, cobro recurrente con tarjeta tokenizada, dunning (reintentos 1/3/7 días y suspensión automática), trials, cupones y métricas MRR/ARR/churn.
- **Entitlements**: feature flags por plan, add-on y excepción por tenant, validados en backend por middleware.
- **Medición de uso**: contadores en Redis consolidados cada día en PostgreSQL. Alimentan los límites y los avisos al 80 y 100 %.
- **API pública del tenant**: API keys con scopes, rate limiting por API key, webhooks salientes firmados con HMAC-SHA256 con reintentos y log de entregas, documentación OpenAPI 3.1 y aplicaciones OAuth 2.0.

| Proceso | Relación |
|---|---|
| PROC-001 Alta de tenant | Control "plan/entitlements". El alta crea `subscriptions` en `trialing` y la conversión de trial la cierra. |
| PROC-059 Notificaciones | Fallos de cobro, cambios de plan, webhook deshabilitado, avisos de consumo y de trial. |
| PROC-062 Autenticación/autorización | API keys, OAuth, `EnsureFeature` y rate limit forman parte de la cadena Credenciales → Tenant → Rol → Permiso → **Feature** → Policy. |
| PROC-063 Auditoría | Todas las acciones del módulo se auditan (sección 9). |
| PROC-064/065 Integración y error externo | Pasarela de pagos, OSE/SUNAT y entrega de webhooks: registrar, clasificar, reintentar con backoff, fallo definitivo y alerta. |

## 2. Pantallas

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/admin/suscripcion` | `app/(backoffice)/admin/suscripcion/page.tsx` | **v2.2:** sección «Qué incluye su plan» (reglas incluidas/bloqueadas) y comparativo de planes con «Mejorar a X». KPIs (plan, renovación, próximo cargo, tarjeta). Medidores de consumo con aviso al 80/100 %. Plan y periodo, renovación automática, módulos habilitados (con su origen: plan / add-on / override). Cambio de plan, cupón y cancelación con encuesta. | Tenant Admin (`admin.billing.manage`) |
| `#/admin/suscripcion/facturas` | `…/suscripcion/facturas` | Facturas de la plataforma del tenant. Vista `.doc`, pagar ahora, reenviar por correo. | Tenant Admin |
| `#/admin/suscripcion/pago` | `…/suscripcion/pago` | Tarjeta tokenizada (formulario embebido de la pasarela) y datos de facturación. | Tenant Admin |
| `#/admin/suscripcion/historial` | `…/suscripcion/historial` | Consumo mensual: WhatsApp, email, API, reservas y almacenamiento. | Tenant Admin |
| `#/admin/api` | `app/(backoffice)/admin/api/page.tsx` | API keys: crear con scopes, entorno, vencimiento e IP allowlist. El token se muestra una sola vez. También revocar y ver el último uso. | Tenant Admin (`admin.api.manage`) |
| `#/admin/api/webhooks` | `…/api/webhooks` | Crear/editar webhooks (URL HTTPS con anti-SSRF), eventos suscritos, ping, rotar secreto (24 h de gracia), pausar/reanudar, eliminar. | Tenant Admin |
| `#/admin/api/entregas` | `…/api/entregas` | Log de entregas con HTTP, ms, intento y próximo reintento. Detalle con headers, firma, payload y respuesta. Reenviar. | Tenant Admin |
| `#/admin/api/limites` | `…/api/limites` | Rate limit y cuota por plan, headers `X-RateLimit-*`, consumo por API key y 429. | Tenant Admin |
| `#/admin/api/docs` | `…/api/docs` | Endpoints públicos, extracto OpenAPI 3.1 y guía de verificación de firmas. | Tenant Admin |
| `#/admin/api/oauth` | `…/api/oauth` | Aplicaciones OAuth: registrar (el secreto se muestra una vez), rotar secreto, revocar. | Tenant Admin (entitlement `oauth_apps`) |
| `#/admin/plataforma` | `app/(plataforma)/facturacion/page.tsx` | MRR, ARR, ARPA, churn de 90 días y cobros fallidos. Evolución de MRR, altas/bajas, MRR por plan y eventos del ciclo de vida. | Super Admin SaaS (`admin.tenant.manage` + `role=super_admin`) |
| `#/admin/plataforma/facturas` | `…/facturacion/facturas` | Todas las facturas por tenant: reintentar cobro, pago manual, anular con nota de crédito. | Super Admin |
| `#/admin/plataforma/cobranza` | `…/facturacion/cobranza` | Dunning: calendario D+1/D+3/D+7, reintentar ahora, enlace de pago, prórroga de 7 días, pago manual, suspender. Editar la política. Ejecutar el job. | Super Admin |
| `#/admin/plataforma/trials` | `…/facturacion/trials` | Trials por vencer: extender 7 días (máx. 14) y convertir a suscripción con cupón. | Super Admin |
| `#/admin/plataforma/cupones` | `…/facturacion/cupones` | Crear, activar y desactivar cupones. Canjes frente al máximo. | Super Admin |
| `#/admin/plataforma/planes` | `…/facturacion/planes` | **v2.2 (DEMO — editable):** matriz Planes × reglas de reserva (plan mínimo por regla, tenants afectados, restablecer matriz demo). Catálogo de planes: precio mensual/anual, días de prueba y límites. | Super Admin |
| `#/admin/plataforma/flags` | `…/facturacion/flags` | Matriz de feature flags por plan y excepciones (overrides) por tenant con vigencia. | Super Admin |
| `#/admin/plataforma/uso` | `…/facturacion/uso` | Uso por tenant (solo contadores) con el % del límite más consumido. | Super Admin |

Restricción de datos: el Super Admin **no** ve clientes, reservas, montos de venta ni documentos comerciales de los tenants. Solo ve contadores agregados.

## 3. Modelo de datos PostgreSQL

### 3.1 Tablas de plataforma y tablas de tenant

| Tabla | Ámbito | `tenant_id` | RLS | Motivo |
|---|---|---|---|---|
| `plans`, `plan_limits`, `plan_features` | Plataforma | No | No | Catálogo global. |
| `features` | Plataforma | No | No | Catálogo de flags. |
| `feature_overrides` | Plataforma | Columna FK `tenant_id` (dato, no aislamiento) | No | La gestiona el Super Admin. El tenant la lee vía servicio de entitlements. |
| `addons` | Plataforma | No | No | Catálogo. |
| `subscriptions`, `subscription_items`, `subscription_changes` | Plataforma | FK `tenant_id` | No (acceso solo por `BillingService`) | La plataforma factura. El tenant consulta vía endpoints `/billing/*`, que filtran por el tenant autenticado. |
| `payment_methods` | Plataforma | FK | No | Solo token de pasarela. |
| `platform_invoices`, `platform_invoice_lines`, `platform_credit_notes`, `payment_attempts` | Plataforma | FK | No | Documentos del emisor Qhapaq Cloud. |
| `coupons`, `coupon_redemptions` | Plataforma | FK en redemptions | No | |
| `usage_counters` (diario), `usage_monthly` | Plataforma | FK | No | Medición para facturación. |
| `platform_metrics_daily` | Plataforma | No | No | Snapshot de MRR/ARR/churn. |
| `integration_api_keys` | **Tenant** | Sí | Sí | Pertenecen al tenant y las gestiona el Tenant Admin. |
| `integration_webhooks`, `integration_webhook_deliveries` | **Tenant** | Sí | Sí | |
| `oauth_clients` (Passport) + `integration_oauth_apps` | **Tenant** | Sí | Sí | Tablas Passport extendidas con `tenant_id`. |
| `sys_outbox` | Tenant | Sí | Sí | Fuente de eventos para webhooks (ya definida en CONVENCIONES). |

Las tablas de plataforma se acceden con la conexión `platform` (rol PG `app_platform`, sin RLS) solo desde `App\Domain\Platform\*`. Los controladores del tenant nunca usan esa conexión directamente.

### 3.2 `plans`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| code | varchar(30) | no | UNIQUE (`starter`, `professional`, `enterprise`) |
| name | varchar(60) | no | |
| rank | smallint | no | Orden para decidir upgrade o downgrade. UNIQUE |
| price_month | numeric(14,2) | no | Sin IGV. CHECK ≥ 0 |
| price_year | numeric(14,2) | no | CHECK `price_year <= price_month*12` |
| currency | char(3) | no | `PEN` |
| trial_days | smallint | no | Default 14. CHECK 0–60 |
| status | varchar(30) | no | CHECK IN (`active`,`hidden`,`retired`) |
| gateway_price_ids | jsonb | sí | IDs de precio en la pasarela (Cashier/Stripe) |
| created_at, updated_at | timestamptz | no | |

Regla PLN-BR-01: el precio respeta el orden de `rank` (validada en `UpdatePlanRequest`).

### 3.3 `plan_limits`
| columna | tipo | null | notas |
|---|---|---|---|
| plan_id | uuid | no | FK plans. PK (plan_id, metric) |
| metric | varchar(30) | no | CHECK IN (`users`,`sedes`,`storage_gb`,`reservations_month`,`whatsapp_month`,`email_month`,`api_calls_month`,`api_rate_per_min`,`webhooks`,`api_keys`) |
| value | bigint | sí | NULL = ilimitado. 0 = no incluido |
| hard | boolean | no | true = bloquea al 100 %. false = solo avisa |

Valores semilla (prototipo):

| Métrica | Starter | Professional | Enterprise |
|---|---|---|---|
| Precio mes / año | 490 / 4 900 | 1 190 / 11 900 | 2 890 / 28 900 |
| users / sedes | 5 / 1 | 15 / 2 | ∞ / ∞ |
| storage_gb | 5 | 25 | 100 |
| reservations_month | 150 | 600 | 3 000 |
| whatsapp_month / email_month | 500 / 5 000 | 2 000 / 20 000 | 5 000 / 100 000 |
| api_calls_month / api_rate_per_min | 0 / 0 | 100 000 / 60 | 1 000 000 / 600 |
| webhooks / api_keys | 0 / 0 | 3 / 5 | 20 / 25 |

### 3.4 `features`, `plan_features`, `feature_overrides`
`features`: `key varchar(50) PK`, `label`, `module varchar(40)`, `kind varchar(20) CHECK IN ('module','limit','addon','beta')`, `addon_code varchar(40) null FK addons(code)`, `routes text[]` (prefijos API/Next que protege), `created_at`.

`plan_features`: `plan_id uuid FK`, `feature_key FK`, `value varchar(20) not null` (`on`, `off` o un nivel, p. ej. `basic`). PK (plan_id, feature_key).

`feature_overrides`:
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK tenants. Índice |
| feature_key | varchar(50) | no | FK features |
| value | boolean | no | |
| reason | varchar(300) | no | |
| valid_until | date | sí | CHECK `valid_until <= created_at::date + 180` (PLN-BR-03) |
| granted_by | uuid | no | FK platform_users |
| created_at | timestamptz | no | |

UNIQUE (tenant_id, feature_key).

Claves semilla: `crm, catalog, quotes, reservations, finance_basic, portal_client, operations, vouchers, travel_docs, profitability, treasury, reports, portal_passenger, inbox_omnichannel, marketing_campaigns, b2b (Professional=basic, add-on b2b_full), multi_sede, ecommerce, cms, mobile_apps (add-on), integrations, audit_advanced, api_access, webhooks, oauth_apps, custom_domain (add-on), ai_quote_assistant (beta)`.

**v2.2 · `plan_rule_entitlements` (plataforma, sin RLS; DEMO editable):** qué reglas del motor de reservas incluye cada plan.
| columna | tipo | null | notas |
|---|---|---|---|
| rule_code | varchar(40) | no | FK `sys_booking_rule_catalog(code)` |
| min_plan | varchar(20) | no | CHECK IN ('Starter','Professional','Enterprise'); los planes de `rank` ≥ lo incluyen. PK (rule_code) |
| is_demo | boolean | no | default true hasta que negocio apruebe la matriz |
| updated_by / updated_at | uuid / timestamptz | no | FK platform_users |

Se modela como «plan mínimo» (no fila por plan) para garantizar que un plan superior siempre herede las reglas del inferior. `sys_booking_rules.min_plan` (especificación 17) es una copia desnormalizada por tenant, refrescada por `BookingRulePlanChanged`. La vista `plan_rules_v (plan_id, rule_code)` se expone al frontend. Prototipo: `rule.minPlan` en `DB.settings.bookingRules`, `DB.plans[].rules` (derivado) y `DB.planCatalogDemo {isDemo, note, order}`; `BE.planAllows(ruleId, plan?)`.

### 3.5 `addons`
`code varchar(40) PK` (`extra_users`, `whatsapp_pack`, `storage_pack`, `b2b_full`, `mobile_apps`, `custom_domain`), `label`, `unit`, `quantity_allowed boolean`, `price_month numeric(14,2)` (en `extra_users` depende del plan: tabla `addon_prices(addon_code, plan_id, price)`), `available_plans uuid[]`, `included_plans uuid[]`, `grants jsonb` (p. ej. `{"whatsapp_month":1000}` o `{"feature":"b2b"}`).

### 3.6 `subscriptions`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| code | varchar(30) | no | UNIQUE (`SUB-2025-0001`) |
| tenant_id | uuid | no | FK tenants. **UNIQUE parcial** `WHERE status <> 'canceled'` (una suscripción viva por tenant) |
| plan_id | uuid | no | FK plans |
| cycle | varchar(10) | no | CHECK IN (`monthly`,`annual`) |
| status | varchar(30) | no | CHECK IN (`trialing`,`active`,`past_due`,`cancel_scheduled`,`suspended`,`canceled`) |
| trial_starts_at, trial_ends_at | date | sí | |
| trial_extended_days | smallint | no | Default 0. CHECK ≤ 14 (SUB-BR-10) |
| current_period_start, current_period_end | date | no | CHECK end > start |
| billing_anchor_day | smallint | no | 1–28 |
| auto_renew | boolean | no | |
| cancel_at | date | sí | |
| cancel_survey | jsonb | sí | `{reason, to, score, stay, comment, export_requested}` |
| credit_balance | numeric(14,2) | no | Default 0. Saldo a favor por prorrateo negativo |
| coupon_redemption_id | uuid | sí | FK coupon_redemptions |
| gateway | varchar(20) | no | `cashier_stripe` \| `local_gateway` |
| gateway_customer_id, gateway_subscription_id | varchar(100) | sí | |
| billing_ruc | char(11) | no | CHECK `~ '^(10\|15\|17\|20)\d{9}$'` |
| billing_name, billing_address | varchar(200) | no | |
| billing_ubigeo | char(6) | sí | |
| billing_email | varchar(150) | no | |
| lock_version | integer | no | Concurrencia optimista |
| created_at, updated_at | timestamptz | no | |

Índices: (status), (current_period_end) para el scheduler de renovación, (trial_ends_at) WHERE status='trialing'.

`subscription_items`: `id`, `subscription_id FK`, `addon_code FK`, `quantity smallint CHECK ≥1`, `meta jsonb` (dominio, estado SSL), `starts_at date`, `ends_at date null` (retiro programado), `unit_price numeric(14,2)`. UNIQUE (subscription_id, addon_code) WHERE ends_at IS NULL.

`subscription_changes`: `id`, `subscription_id`, `kind CHECK IN ('upgrade','downgrade','cycle','addon_add','addon_update','addon_remove','cancel','resume')`, `from_plan_id`, `to_plan_id`, `from_cycle`, `to_cycle`, `effective_at date`, `status CHECK IN ('scheduled','applied','voided')`, `proration jsonb` (`{days_left, period_days, charge, credit, net}`), `invoice_id null`, `requested_by`, `created_at`. Un solo `scheduled` por suscripción (índice UNIQUE parcial).

### 3.7 `payment_methods`
`id`, `tenant_id FK`, `gateway_token varchar(120) not null` (nunca PAN ni CVV: PCI DSS SAQ-A), `brand varchar(20)`, `last4 char(4)`, `exp_month smallint`, `exp_year smallint`, `holder varchar(120)`, `is_default boolean`, `status CHECK IN ('active','expired','removed')`, `created_by`, `created_at`. UNIQUE (tenant_id) WHERE is_default.

### 3.8 `platform_invoices` y `platform_invoice_lines`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| series | char(4) | no | `FQ01` (factura), `NQ01` (nota de crédito) |
| number | integer | no | UNIQUE (series, number). Correlativo global de la plataforma con `SELECT … FOR UPDATE` en `platform_sequences` |
| tenant_id | uuid | no | FK. Índice (tenant_id, issue_date desc) |
| subscription_id | uuid | no | FK |
| kind | varchar(20) | no | CHECK IN (`subscription`,`proration`,`addon`,`manual`) |
| period_from, period_to | date | no | |
| issue_date, due_date | date | no | |
| currency | char(3) | no | |
| subtotal, igv, total | numeric(14,2) | no | CHECK `total = subtotal + igv` |
| igv_pct | numeric(5,2) | no | Snapshot (18.00) |
| status | varchar(20) | no | CHECK IN (`draft`,`open`,`paid`,`failed`,`uncollectible`,`void`) |
| paid_at | timestamptz | sí | |
| payment_ref | varchar(120) | sí | Transacción de la pasarela u operación bancaria |
| bill_to | jsonb | no | Snapshot `{ruc, name, address, ubigeo}` (SUB-BR-09) |
| sunat_status | varchar(20) | no | CHECK IN (`pending`,`accepted`,`observed`,`rejected`) |
| sunat_hash, sunat_cdr_path, pdf_path, xml_path | varchar | sí | S3 `platform/invoices/{yyyy}/{series-number}.*` |
| dunning_failed_at | date | sí | |
| dunning_retries_done | smallint | no | Default 0 |
| dunning_next_retry_at | timestamptz | sí | Índice parcial WHERE status='failed' |
| dunning_extended_days | smallint | no | Default 0 |
| void_reason | varchar(300) | sí | |
| created_at, updated_at | timestamptz | no | |

Estados del prototipo: `pagada`=paid, `pendiente`=open, `fallida`=failed, `vencida`=uncollectible, `anulada`=void.

`platform_invoice_lines`: `id`, `invoice_id FK ON DELETE CASCADE`, `description varchar(250)`, `quantity numeric(10,2)`, `unit_price numeric(14,2)`, `amount numeric(14,2)` (negativo para créditos o cupón), `line_type CHECK IN ('plan','addon','proration_charge','proration_credit','discount','usage_overage')`.

`platform_credit_notes`: `id`, `series 'NQ01'`, `number`, `invoice_id FK UNIQUE`, `reason`, `amount`, `sunat_status`, `refund_ref`, `created_by`, `created_at`.

`payment_attempts`: `id`, `invoice_id FK`, `attempt_no smallint`, `source CHECK IN ('initial','dunning','manual_retry','portal','manual_payment')`, `result CHECK IN ('succeeded','declined','error')`, `gateway_code varchar(40)` (`insufficient_funds`, `card_declined`, `expired_card`…), `message`, `idempotency_key varchar(80) UNIQUE`, `created_at`. Índice (invoice_id, created_at).

### 3.9 `coupons` y `coupon_redemptions`
`coupons`: `id`, `code varchar(20) UNIQUE CHECK ~ '^[A-Z0-9_-]{4,20}$'`, `type CHECK IN ('percent','fixed')`, `value numeric(10,2)` (CHECK percent ≤ 50: SUB-BR-08), `duration_cycles smallint 1–12`, `plan_ids uuid[]`, `max_redemptions integer`, `redeemed_count integer CHECK redeemed_count <= max_redemptions`, `valid_to date`, `status CHECK IN ('active','inactive')`, `note`, `created_by`, `created_at`.

`coupon_redemptions`: `id`, `coupon_id FK`, `tenant_id FK`, `subscription_id FK`, `remaining_cycles smallint`, `created_at`. UNIQUE (coupon_id, tenant_id).

### 3.10 Medición de uso
`usage_counters`: `tenant_id`, `metric` (mismo CHECK que `plan_limits`), `day date`, `value bigint`. PK (tenant_id, metric, day). Particionada por mes (`PARTITION BY RANGE (day)`).

`usage_monthly`: `tenant_id`, `metric`, `month date` (día 1), `value bigint`, `limit_value bigint null`, `alert_80_sent_at`, `alert_100_sent_at`. PK (tenant_id, metric, month).

Métricas gauge (`users`, `sedes`, `storage_gb`) se toman como snapshot diario; el resto son acumuladores.

Usuarios facturables: usuarios `active` con rol interno o guía/conductor. Los roles de portal (cliente, pasajero, B2B, proveedor) no consumen licencia.

### 3.11 `integration_api_keys` (tenant, RLS)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK. RLS |
| code | varchar(30) | no | UNIQUE (tenant_id, code) (`KEY-0003`) |
| name | varchar(120) | no | |
| environment | varchar(10) | no | CHECK IN (`live`,`test`) |
| prefix | varchar(16) | no | UNIQUE global. Primeros 13 caracteres (`qts_live_4fZp`), para lookup |
| last4 | char(4) | no | |
| token_hash | char(64) | no | SHA-256 del token completo. Nunca se guarda el token |
| scopes | text[] | no | CHECK `scopes <@ ARRAY[...catálogo]` |
| ip_allowlist | cidr[] | sí | |
| expires_at | date | sí | |
| last_used_at | timestamptz | sí | Se actualiza como máximo cada 60 s (throttle en Redis) |
| last_used_ip | inet | sí | |
| status | varchar(20) | no | CHECK IN (`active`,`revoked`,`expired`) |
| revoked_at, revoked_by, revoke_reason | | sí | |
| created_by | uuid | no | |
| created_at | timestamptz | no | |

Índices: (tenant_id, status), prefix.

### 3.12 `integration_webhooks` (tenant, RLS)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | RLS |
| code | varchar(30) | no | UNIQUE (tenant_id, code) |
| url | varchar(500) | no | CHECK `url ~ '^https://'` |
| description | varchar(200) | sí | |
| events | text[] | no | Índice GIN |
| secret_encrypted | text | no | Cifrado con `Crypt` (APP_KEY) |
| previous_secret_encrypted | text | sí | Válido hasta `previous_secret_expires_at` (24 h tras rotar) |
| previous_secret_expires_at | timestamptz | sí | |
| secret_prefix | varchar(12) | no | Para mostrar |
| api_version | varchar(10) | no | Versión fijada del payload (`2026-09-01`) |
| status | varchar(20) | no | CHECK IN (`active`,`paused`,`disabled`,`deleted`) |
| consecutive_failures | smallint | no | Default 0 |
| disabled_reason | varchar(200) | sí | |
| created_by, created_at, updated_at, deleted_at | | | SoftDeletes |

### 3.13 `integration_webhook_deliveries` (tenant, RLS)
`id uuid`, `tenant_id`, `code varchar(30)` (`WHD-004821`), `webhook_id FK`, `event_type varchar(50)`, `event_id varchar(40)` (`evt_…`, igual al id de `sys_outbox`), `entity_code varchar(40)`, `payload jsonb`, `attempt smallint`, `status CHECK IN ('pending','delivered','retrying','failed')`, `http_status smallint null`, `duration_ms integer`, `response_excerpt varchar(2000)`, `error varchar(300)`, `next_retry_at timestamptz null`, `manual boolean`, `created_at`.

Índices: (tenant_id, webhook_id, created_at desc), (status, next_retry_at) WHERE status='retrying'. Retención de 30 días (particionada por mes y purga con `DROP PARTITION`).

### 3.14 OAuth
Laravel Passport con `oauth_clients.tenant_id` (RLS), más `integration_oauth_apps`: `client_id FK oauth_clients`, `vendor`, `allowed_scopes text[]`, `status`, `created_by`. Solo se admite Authorization Code + PKCE.

## 4. Máquinas de estado

### 4.1 Suscripción
| Desde | Hacia | Disparador | Efectos |
|---|---|---|---|
| — | trialing | Alta de tenant (PROC-001) | trial_ends_at = hoy + plan.trial_days. Avisos a 7, 3 y 1 día. |
| trialing | trialing | Super Admin extiende 7 días (máx. 14) | Auditoría y email. |
| trialing | active | Conversión (tenant registra tarjeta o Super Admin convierte) | Primera factura `open` (vence en 7 días) o cobro inmediato. |
| trialing | suspended | Job `billing:trials` al vencer sin método de pago | Solo lectura 7 días. Luego pasa a `canceled`. |
| active | active | Upgrade / add-on / cupón | Factura de prorrateo cobrada al instante. |
| active | active (cambio programado) | Downgrade válido | `subscription_changes.scheduled`. Se aplica en la renovación. |
| active | past_due | Cobro de renovación rechazado | Inicia el dunning. Banner en el portal. |
| past_due | active | Reintento aprobado, pago manual o pago en el portal | Limpia el dunning. |
| past_due | suspended | Último reintento fallido (D+7 + prórroga) y `suspend_after_last` | `tenants.status = suspended`. Login bloqueado. Datos intactos. |
| suspended | active | Pago de toda la deuda | Reactivación automática. |
| active | cancel_scheduled | Tenant Admin cancela (encuesta obligatoria) | auto_renew = false. cancel_at = fin de periodo. |
| cancel_scheduled | active | Reanudar antes de cancel_at | |
| cancel_scheduled | canceled | Job de renovación en cancel_at | Solo lectura 30 días, exportación y purga según retención. |

### 4.2 Factura de plataforma
`draft → open → paid`. Desde `open` también: `failed → (reintentos) → paid | uncollectible`. Desde cualquier estado distinto de `void`: `void` (nota de crédito). `paid → void` genera un reembolso a la pasarela.

### 4.3 Dunning (por factura)
D0 = fallo inicial. Los reintentos se programan en `failed_at + retry_days[i]` (por defecto 1, 3, 7) a las 10:00, y la prórroga se suma al último. Tras cada rechazo se registra un `payment_attempt`, se agenda el siguiente reintento y se envía email. Tras el último rechazo: la factura pasa a `uncollectible` y la suscripción a `suspended`.

Un reintento manual no mueve el calendario.

### 4.4 API key
`active → revoked` (manual, irreversible). `active → expired` (job diario). Una key revocada responde 401 de inmediato, porque la caché de autenticación se invalida.

### 4.5 Webhook y entrega
Webhook:
- `active ⇄ paused` (manual).
- `active → disabled` automático con `consecutive_failures ≥ 10`, con email al Tenant Admin.
- `disabled → active` (manual, pone el contador en 0).
- Cualquier estado → `deleted`.

Cambiar la URL resetea el contador de fallos.

Entrega:
- `pending → delivered` (2xx en menos de 10 s).
- `pending/retrying → retrying` con backoff 1 min, 5 min, 30 min, 2 h, 6 h, 24 h.
- Al agotar los 6 intentos → `failed`.

Un reenvío manual crea una nueva entrega con `manual=true`.

## 5. Reglas de negocio

| ID | Regla | Dónde se valida |
|---|---|---|
| SUB-BR-01 | Upgrade (rank mayor o ciclo mensual→anual): inmediato. Se cobra `precio_nuevo × días_restantes/días_periodo − crédito del plan actual`. Pasar a anual inicia un nuevo periodo de 12 meses y cobra el precio anual menos el crédito. Un neto negativo va a `credit_balance`. | `ChangePlanService` + `ProrationCalculator` (unit tests) |
| SUB-BR-02 | Downgrade (rank menor o anual→mensual): programado al cierre del periodo. Sin reembolso. Un solo cambio programado. | `ChangePlanService`, índice único parcial |
| SUB-BR-03 | Un downgrade se bloquea si el consumo actual supera un límite del plan destino (usuarios, sedes, almacenamiento, API keys, webhooks) o si hay módulos en uso que el destino no incluye (e-commerce con pedidos, CMS publicado, apps con guías/conductores, multi-sede, OAuth, integraciones). 422 `SUB_DOWNGRADE_BLOCKED` con la lista. Se revalida al aplicarlo en la renovación; si falla, no se aplica y se notifica. | `DowngradeValidator` (Service) + job `billing:renewals` |
| SUB-BR-04 | Límites `hard` al 100 %: users/sedes impiden crear más (422 `PLAN_LIMIT_REACHED`). WhatsApp/email se encolan diferidos. API responde 429 `QUOTA_EXCEEDED`. Reservas: aviso sin bloqueo (límite `soft`). | Policies de alta + `NotificationThrottler` + middleware `api.quota` |
| SUB-BR-05 | Avisos de consumo al 80 % y 100 % por métrica, una vez por mes (`usage_monthly.alert_*_sent_at`). | Job `usage:consolidate` |
| SUB-BR-06 | No se puede reducir un paquete add-on por debajo de lo ya consumido en el periodo. Un add-on retirado sigue vigente hasta fin de periodo. | `AddonService` |
| SUB-BR-07 | Cupón: activo, vigente, con canjes disponibles, aplicable al plan y no acumulable. Canje con `lockForUpdate` sobre `coupons`. | `RedeemCouponRequest` + `CouponService` |
| SUB-BR-08 | Descuento porcentual máximo del 50 %. | CHECK + FormRequest |
| SUB-BR-09 | RUC válido (11 dígitos: 10/15/17/20). Los datos de facturación se copian (snapshot) al emitir. Las emitidas no cambian. | FormRequest + `bill_to jsonb` |
| SUB-BR-10 | Extensión de trial máxima de 14 días acumulados. | CHECK + Service |
| SUB-BR-11 | Cancelación con encuesta obligatoria (motivo) y aceptación de las condiciones de cierre. Efectiva al fin de periodo. | `CancelSubscriptionRequest` |
| SUB-BR-12 | Cobros y pagos idempotentes (`Idempotency-Key` → `payment_attempts.idempotency_key`). Un doble clic no genera doble cargo. | Middleware idempotencia + UNIQUE |
| PLN-BR-01 | El precio de los planes respeta el orden de rank. El anual no supera 12 mensualidades. | `UpdatePlanRequest` |
| PLN-BR-02 | Cambio de precio: aplica a nuevas suscripciones de inmediato y a las existentes desde la renovación, con aviso de 30 días (grandfathering: `subscriptions.price_locked_until`). | `PlanPriceChangeService` + notificación |
| PLN-BR-04 | (v2.2) Matriz regla × plan: el plan mínimo de una regla lo cambia solo el Super Admin; se informa y audita el número de tenants afectados; los tenants que pierden la regla conservan su configuración (enabled/params) y el motor deja de aplicarla (`RULE_NOT_IN_PLAN`); aviso por email a los afectados. | `PlanRuleService` + Policy |
| PLN-BR-03 | Un override por tenant dura como máximo 180 días. Un override vencido deja de regir sin job (se evalúa `valid_until`). | CHECK + `EntitlementService` |
| ENT-BR-01 | Resolución de entitlement: override vigente > add-on activo disponible en el plan > `plan_features`. El frontend nunca es autoridad. | `EntitlementService` |
| API-BR-01 | La API key autentica al **tenant** (no a un usuario). Scope requerido por ruta. IP allowlist opcional. Key vencida o revocada → 401. | Guard `api-key` + middleware `scope:x` |
| API-BR-02 | Máximo de API keys activas según el plan. | `CreateApiKeyRequest` |
| API-BR-03 | El token (`qts_{env}_` + 32 caracteres base62) se muestra una sola vez. Se guarda como SHA-256. | `ApiKeyService` |
| API-BR-04 | Máximo de webhooks según el plan. Entitlement `webhooks`. | FormRequest |
| API-BR-05 | La URL del webhook debe ser HTTPS. | FormRequest + CHECK |
| API-BR-06 | Anti-SSRF: se resuelve el DNS y se rechazan loopback, RFC1918, link-local, `.local`/`.internal`. Se revalida en cada envío (DNS rebinding). Sin seguir redirecciones. | `SafeUrlValidator` + cliente HTTP del job |
| API-BR-07 | Webhook deshabilitado tras 10 fallos consecutivos. | `DeliverWebhookJob` |
| API-BR-08 | Rotación del secreto: el anterior se acepta 24 h (se envían ambas firmas `v1`). | `WebhookService` |

## 6. API REST

Autenticación de la consola: Sanctum. Autenticación de la API pública: guard `api-key` (Bearer) u OAuth (Passport).

| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/api/v1/billing/subscription` | admin.billing.manage | Suscripción, plan, periodo, cambio programado y cupón | — |
| GET | `/api/v1/billing/usage?month=2026-09` | admin.billing.manage | Consumo vs límites efectivos (plan + add-ons) | — |
| GET | `/api/v1/billing/entitlements` | autenticado | Features efectivas y su origen (para el menú de Next) | Cache 5 min |
| POST | `/api/v1/billing/subscription/preview-change` | admin.billing.manage | `{plan, cycle}` → `{kind, proration, blockers[]}` | — |
| POST | `/api/v1/billing/subscription/change` | admin.billing.manage | Aplica el upgrade o programa el downgrade | Idempotency-Key + lock_version |
| DELETE | `/api/v1/billing/subscription/scheduled-change` | admin.billing.manage | Anula el cambio programado | lock_version |
| POST | `/api/v1/billing/subscription/cancel` | admin.billing.manage | Encuesta + cancelación a fin de periodo | lock_version |
| POST | `/api/v1/billing/subscription/resume` | admin.billing.manage | Reanudar | lock_version |
| PATCH | `/api/v1/billing/subscription` | admin.billing.manage | `auto_renew`, datos de facturación | lock_version |
| POST/PATCH/DELETE | `/api/v1/billing/addons/{code}` | admin.billing.manage | Contratar, ajustar cantidad, retiro programado | Idempotency-Key |
| POST | `/api/v1/billing/coupon` | admin.billing.manage | Canjear cupón | Lock sobre el cupón |
| GET | `/api/v1/billing/invoices` · `/{number}` · `/{number}/pdf` | admin.billing.manage | Facturas del tenant. El PDF es una URL firmada | — |
| POST | `/api/v1/billing/invoices/{number}/pay` | admin.billing.manage | Paga con la tarjeta registrada | Idempotency-Key |
| POST | `/api/v1/billing/payment-methods/setup-intent` | admin.billing.manage | Inicia la tokenización (iframe de la pasarela) | — |
| PUT | `/api/v1/billing/payment-methods/default` | admin.billing.manage | Guarda el token devuelto por la pasarela | Idempotency-Key |
| POST | `/api/v1/webhooks/payments/{gateway}` | firma de la pasarela | Webhook entrante de la pasarela (cobro, rechazo, 3DS) | Idempotente por event id |
| GET/POST | `/api/v1/integrations/api-keys` | admin.api.manage | Listar / crear (el token solo va en la respuesta 201) | — |
| DELETE | `/api/v1/integrations/api-keys/{id}` | admin.api.manage | Revocar (`reason` obligatorio) | — |
| GET/POST | `/api/v1/integrations/webhooks` | admin.api.manage o scope `webhooks:manage` | Listar / crear (el secreto solo va en la respuesta 201) | — |
| PATCH/DELETE | `/api/v1/integrations/webhooks/{id}` | admin.api.manage | Editar, pausar/reanudar, eliminar | — |
| POST | `/api/v1/integrations/webhooks/{id}/rotate-secret` | admin.api.manage | Nuevo secreto con 24 h de gracia | — |
| POST | `/api/v1/integrations/webhooks/{id}/ping` | admin.api.manage | Evento `ping` síncrono | — |
| GET | `/api/v1/integrations/webhook-deliveries?filter[status]=…` | admin.api.manage | Log de entregas | — |
| POST | `/api/v1/integrations/webhook-deliveries/{id}/redeliver` | admin.api.manage | Reenvío manual | Idempotency-Key |
| GET/POST/DELETE | `/api/v1/integrations/oauth-apps` (+ `/{id}/secret`) | admin.api.manage + feature oauth_apps | Registro, rotación y revocación de clientes OAuth | — |
| GET | `/api/v1/docs/openapi.json` | público | OpenAPI 3.1 (dedoc/scramble) | — |
| GET | `/api/v1/platform/metrics?from=&to=` | super_admin | MRR, ARR, ARPA, altas, bajas, churn | — |
| GET | `/api/v1/platform/invoices` | super_admin | Todas las facturas (filtros tenant, status, period) | — |
| POST | `/api/v1/platform/invoices/{id}/retry` | super_admin | Reintento manual (no mueve el calendario) | Idempotency-Key |
| POST | `/api/v1/platform/invoices/{id}/manual-payment` | super_admin | Transferencia/depósito. Reactiva el tenant si no queda deuda | Idempotency-Key |
| POST | `/api/v1/platform/invoices/{id}/void` | super_admin | Nota de crédito NQ01 (+ reembolso si estaba pagada) | Idempotency-Key |
| POST | `/api/v1/platform/invoices/{id}/dunning/extend` | super_admin | Prórroga de 7 días | — |
| POST | `/api/v1/platform/tenants/{id}/suspend` · `/reactivate` | super_admin | Manual. `reason` obligatorio | — |
| GET/PATCH | `/api/v1/platform/dunning-policy` | super_admin | `retry_days[]`, `suspend_after_last` | — |
| GET/POST | `/api/v1/platform/trials` · `/{tenant}/extend` · `/{tenant}/convert` | super_admin | Trials | — |
| GET/POST/PATCH | `/api/v1/platform/coupons` | super_admin | Cupones | — |
| GET/PATCH | `/api/v1/platform/plans/{code}` | super_admin | Precios y límites | — |
| GET/PUT | `/api/v1/platform/features/{key}/plans/{plan}` | super_admin | Entitlement por plan | Invalida `ent:*` |
| GET | `/api/v1/platform/plan-rules` | super_admin | Matriz regla × plan (v2.2, DEMO) con tenants con acceso por regla | — |
| PUT | `/api/v1/platform/plan-rules/{rule_code}` | super_admin | `{min_plan}` → devuelve `affected_tenants[]` (ganan/pierden); con `?dry_run=1` solo calcula | `If-Match`; invalida `rules:*` |
| POST | `/api/v1/platform/plan-rules/reset-demo` | super_admin | Restablece la matriz de demostración | Idempotency-Key |
| GET | `/api/v1/billing/plan-rules` | admin.billing.manage | «Qué incluye su plan»: reglas incluidas, bloqueadas y lo que suma cada upgrade (v2.2) | Cache 5 min |
| GET/POST/DELETE | `/api/v1/platform/feature-overrides` | super_admin | Excepciones por tenant | Invalida `ent:{tenant}` |
| GET | `/api/v1/platform/usage?filter[plan]=` | super_admin | Uso agregado por tenant (solo contadores) | — |

Errores específicos:
- 402 `PAYMENT_REQUIRED`: tenant suspendido; solo se permiten `/billing/*` y el login.
- 403 `FEATURE_NOT_IN_PLAN`.
- 422 `PLAN_LIMIT_REACHED` / `SUB_DOWNGRADE_BLOCKED` / `COUPON_INVALID`.
- 429 `RATE_LIMITED` / `QUOTA_EXCEEDED` con `Retry-After`.

## 7. Permisos y comportamiento por rol

| Rol | Suscripción | API y webhooks | Facturación de plataforma |
|---|---|---|---|
| Super Admin SaaS (U16) | 403 (no opera el tenant) | 403 | Acceso completo. Solo métricas y contadores, nunca datos comerciales. No puede suspender el tenant de su sesión (PROC-001). |
| Tenant Admin (U01) | Acceso completo a su tenant | Acceso completo a su tenant | 403 (no tiene `admin.tenant.manage`) |
| Manager (U02) | 403: no tiene `admin.billing.manage` (excluido en `rolePerms`) | 403 | 403 |
| Ventas, Operaciones, Finanzas, Contabilidad | 403 | 403 | 403 |
| Externos (B2B, cliente, pasajero, guía, conductor, proveedor) | Fuera de su portal | — | — |

El entitlement es independiente del rol. Aunque el Tenant Admin tenga `admin.api.manage`, si el plan no incluye `api_access` la pantalla muestra el bloqueo y deshabilita "Nueva API key", y la API responde 403.

Entitlements, en tres capas:
1. **Backend**: middleware `EnsureFeature:{key}` en grupos de rutas (`Route::middleware('feature:ecommerce')`) y en jobs (`$this->ensureFeature()`). Resuelve con `EntitlementService::for($tenant)`, cacheado en Redis `ent:{tenant_id}` (TTL 10 min, invalidación por evento `EntitlementsChanged`).
2. **Next.js**: `GET /billing/entitlements` alimenta el menú y los guards de ruta (UX, no seguridad).
3. **Datos**: nunca se borran al perder un feature. Solo se deshabilita el acceso.

## 8. Eventos, jobs (colas Redis) y notificaciones

### 8.1 Modelo de facturación
- **Opción A: Laravel Cashier (Stripe)**. Si la pasarela elegida es Stripe (cobra en PEN), se usan `Billable` en `Tenant`, `newSubscription()->create()`, `swap()` (upgrade con `prorate()`/`alwaysInvoice()`), `swapAndInvoice`, `noProrate()->swap()` a fin de periodo para el downgrade, y los webhooks `invoice.payment_failed` y `customer.subscription.updated`. El dunning de Stripe (Smart Retries) se desactiva y se usa el propio para controlar la suspensión y el calendario 1/3/7.
- **Opción B: pasarela local** (Niubiz, Izipay, Culqi, Mercado Pago; por definir, ver INT-02). Se usa un motor de suscripción propio (`BillingService`) con tokenización en iframe, cargos recurrentes vía API de la pasarela usando el token (`payment_methods.gateway_token`) y el job de renovación. Es la opción recomendada para Perú (menores comisiones y liquidación local), detrás de la interfaz `PaymentGateway` con adapters (`charge`, `refund`, `createSetupIntent`, `parseWebhook`).
- En ambos casos, **la factura electrónica la emite la plataforma** con su OSE (Nubefact, Efact u otro), serie `FQ01` y notas de crédito `NQ01`, por el job `EmitPlatformInvoiceJob` (cola `documents`). La factura comercial de la pasarela no sustituye al comprobante SUNAT. Precios sin IGV más 18 % de IGV. El tenant es el adquirente (RUC 20).

### 8.2 Jobs y scheduler
| Job / comando | Cola | Programación | Qué hace |
|---|---|---|---|
| `billing:renewals` | critical | Diario 06:00 | Suscripciones con `current_period_end < hoy`: aplica el cambio programado (revalida SUB-BR-03), aplica el cupón, genera la factura, cobra (`ChargeInvoiceJob`), avanza el periodo y procesa `cancel_scheduled → canceled`. Usa `ShouldBeUnique` por suscripción. |
| `ChargeInvoiceJob` | critical | Evento | Cobra con Idempotency-Key = `inv:{id}:attempt:{n}` y registra el `payment_attempt`. Si se aprueba, pasa a `paid`. Si se rechaza, pasa a `failed` y se programa el dunning. |
| `billing:dunning` | critical | Diario 10:00 | Facturas `failed` con `dunning_next_retry_at <= now()`: reintenta, reprograma según `retry_days` o suspende (sección 4.3). Usa `Cache::lock('dunning:'.$invoiceId)`. |
| `billing:trials` | default | Diario 08:00 | Avisos a 7, 3 y 1 día. Suspensión o baja al vencer sin tarjeta. |
| `billing:expire-cards` | low | Mensual | Avisa de tarjetas que vencen en 30 días. |
| `EmitPlatformInvoiceJob` / `EmitCreditNoteJob` | documents | Evento | XML UBL 2.1 → OSE → CDR → PDF (S3). Reintentos con backoff (PROC-065). |
| `usage:consolidate` | reports | Diario 00:15 | Vuelca los contadores Redis `usage:{tenant}:{metric}:{yyyymmdd}` (INCRBY en tiempo real) a `usage_counters` con UPSERT, recalcula `usage_monthly`, envía avisos 80/100 % y borra claves de más de 2 días. Toma snapshots de gauges (usuarios, sedes, `SUM(size)` de `sys_documents`). |
| `platform:metrics-snapshot` | reports | Diario 01:00 | MRR = Σ suscripciones activas (precio mensualizado + add-ons − cupón). ARR = MRR×12. ARPA. Altas, bajas, logo churn y revenue churn en `platform_metrics_daily`. |
| `api-keys:expire` | low | Diario | Pasa a `expired` las keys vencidas y avisa 7 días antes. |
| `DeliverWebhookJob` | integrations | Evento | Sección 8.4. |
| `webhooks:purge` | low | Diario | Retención de 30 días de las entregas. |
| `BookingRulePlanChanged` (v2.2) | default | Evento | Refresca `sys_booking_rules.min_plan` de todos los tenants e invalida `rules:{tenant}`; notifica `plan.cambio_reglas` a los tenants afectados. |

### 8.3 Medición de uso y rate limiting (Redis)
- **Contador de uso**: `INCRBY usage:{t}:{metric}:{yyyymmdd} n` en el punto de consumo (listener de `ReservationCreated`, `NotificationSent{channel}`, middleware de API). Si Redis falla, se escribe en la tabla fallback `usage_events` (degradación segura).
- **Cuota mensual**: `GET usage:{t}:api_calls:{yyyymm}`, comparada con el límite efectivo cacheado. Si se excede → 429 `QUOTA_EXCEEDED`.
- **Rate limit por API key**: `RateLimiter::for('api-key', fn($r) => Limit::perMinute($plan->api_rate_per_min)->by($apiKeyId))` más una ráfaga `Limit::perSecond(ceil(rate/6))`, con ventana deslizante en Redis `rl:{tenant}:{key}`. Headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`, `X-Quota-Limit`, `X-Quota-Remaining`, `Retry-After`. Los 429 se cuentan en `apikey:{id}:429:{yyyymmdd}` para la pantalla de límites.
- `last_used_at` e IP se escriben en PostgreSQL como máximo una vez por minuto por key (`SET NX EX 60`).

### 8.4 Webhooks: firma HMAC y entrega
1. Los eventos de dominio (`QuoteSent`, `QuoteApproved`, `ReservationCreated/Confirmed/Cancelled`, `PaymentApplied/Reversed`, `InvoiceIssued`, `VoucherIssued`, `IncidentCreated/Resolved`, `LeadCreated`) se escriben en `sys_outbox` en la misma transacción.
2. `OutboxRelay` publica y, por cada webhook `active` suscrito (consulta GIN `events @> ARRAY[type]`), crea una entrega `pending` y encola `DeliverWebhookJob`.
3. Cuerpo: `{id: evt_…, type, api_version, created_at, tenant_id, data}`. `data` contiene los campos públicos del recurso (sin costos ni márgenes).
4. Firma: `t = unix_time`, `v1 = hex(HMAC_SHA256(secret, t + "." + raw_body))`. Header `Qhapaq-Signature: t=…,v1=…[,v1=…]` (dos v1 durante la gracia de rotación). Además: `Qhapaq-Event-Id`, `Qhapaq-Event-Type`, `Qhapaq-Delivery-Id`, `User-Agent: Qhapaq-Webhooks/1.0`.
5. El receptor valida con comparación en tiempo constante y rechaza firmas con más de 5 minutos. Debe deduplicar por `Qhapaq-Event-Id`.
6. Envío HTTP: timeout de 10 s (30 s máximo de conexión), sin redirecciones, validación SSRF antes de conectar. 2xx = entregado. 410 = deshabilitar. Otro código o timeout = reintento con `backoff: [60, 300, 1800, 7200, 21600, 86400]` y `tries = 6`.
7. Tras 10 fallos consecutivos: `disabled` y notificación.

### 8.5 Notificaciones (PROC-059)
`suscripcion.plan_cambiado`, `suscripcion.cambio_programado`, `suscripcion.addon`, `suscripcion.cancelacion` (al tenant y a ops@), `suscripcion.reanudada`, `suscripcion.pago_recibido`, `suscripcion.metodo_pago`, `plataforma.cobro_fallido` (en cada reintento), `plataforma.enlace_pago`, `tenant.suspendido`, `trial.extendido`, `trial.convertido`, avisos de trial a 7/3/1 día, `uso.aviso_80` y `uso.aviso_100`, `api.key_creada`, `webhook.deshabilitado`, `plan.cambio_condiciones` (30 días antes).

## 9. Auditoría
Todas las acciones se registran con actor, tenant, entidad, acción, antes, después, IP y correlation id (PROC-063). Entidades usadas en el prototipo:

| Entidad | Acciones |
|---|---|
| Suscripción | `cambio_plan`, `cambio_plan_programado`, `downgrade_bloqueado`, `anular_cambio_programado`, `addon_contratar`, `addon_ajustar`, `addon_retirar`, `addon_mantener`, `cupon_aplicar`, `cupon_rechazado`, `cancelar`, `reanudar`, `renovacion_automatica`, `metodo_pago` (solo marca •••• last4), `datos_facturacion` |
| Factura plataforma | `pagar`, `reenviar`, `cobro_exitoso`, `cobro_rechazado`, `pago_manual`, `anular` (con NC), `prorroga_dunning`, `enviar_enlace_pago` |
| Tenant | `suspender` (manual o dunning), `reactivar`, `extender_trial`, `convertir_trial` |
| Plan × regla (v2.2) | `cambiar_plan_minimo` (antes → después + nº de tenants que ganan/pierden), `restablecer_demo` |
| Plan / Feature flag / Cupón | `editar_plan`, `editar_politica` (dunning), `plan_entitlement`, `override`, `quitar_override`, `crear`, `activar`, `desactivar` |
| API key / Webhook / App OAuth | `crear`, `revocar`, `editar`, `pausar`, `reactivar`, `rotar_secreto`, `eliminar`, `ping`, `reenviar`, `deshabilitar_automatico` |
| Job | `billing:dunning` `ejecutar` |

Los tokens, secretos y números de tarjeta **nunca** se auditan ni se registran en logs (máscara en `Log::` y en Sentry).

## 10. Casos de prueba mínimos

**Pest (feature/unit)**
1. `ProrationCalculator`: Enterprise mensual a anual con 4 de 30 días restantes → cargo 28 900, crédito 385.33, neto 28 514.67 (+ IGV 33 647.31). Un neto negativo va a `credit_balance`.
2. Downgrade Enterprise → Professional con 3 sedes y 38.2 GB → 422 `SUB_DOWNGRADE_BLOCKED` con 2 o más bloqueos. Con consumo dentro del límite → `subscription_changes.scheduled` con `effective_at` = fin de periodo + 1.
3. Dunning: factura fallida el 26/09 → reintentos 27/09, 29/09 y 03/10. El tercer rechazo suspende el tenant. Un pago manual posterior lo reactiva si no queda deuda. El reintento manual no altera `dunning_next_retry_at`.
4. Idempotencia: dos `POST /billing/invoices/{n}/pay` con la misma Idempotency-Key → un solo cargo y un solo `payment_attempt`.
5. Cupón: vencido, agotado, de otro plan o acumulado → 422. Concurrencia: 2 canjes simultáneos del último cupo → solo 1 prospera.
6. Entitlements: la precedencia override > add-on > plan. Override vencido → rige el plan. `feature:ecommerce` en el plan Professional → 403 `FEATURE_NOT_IN_PLAN`.
7. Límites: crear el usuario 6 en Starter → 422 `PLAN_LIMIT_REACHED`. La API al 100 % de la cuota → 429 `QUOTA_EXCEEDED`. Exceder el rate por minuto → 429 con `Retry-After`.
8. API key: token solo en la respuesta 201. En BD solo hay hash. Key revocada → 401. Key sin scope → 403. IP fuera de la allowlist → 403. Key del tenant A no lee datos de B (E2E-08).
9. Webhooks: URL `http://` o de red privada → 422. Firma verificable con el secreto. Rotación: ambas firmas durante 24 h. 10 fallos → `disabled`. Reenvío crea `manual=true`.
10. RBAC: Manager → 403 en `/billing/*` y `/integrations/*`. Tenant Admin → 403 en `/platform/*`. Super Admin → 403 en endpoints de negocio del tenant.
11. `usage:consolidate`: UPSERT idempotente (ejecutar 2 veces no duplica). Aviso 80 % enviado una sola vez por mes.
12. Emisión SUNAT: `EmitPlatformInvoiceJob` con OSE caída → reintentos. La factura queda `sunat_status=pending` sin bloquear el cobro.

**Playwright**
- PW-SUB-01: U01 abre Suscripción → ve avisos de WhatsApp (83 %) y API (85 %) → intenta pasar a Professional → ve los bloqueos → pasa a anual → ve la factura FQ01 en la pestaña Facturas.
- PW-SUB-02: U01 contrata "Almacenamiento +50 GB" → el medidor pasa a 150 GB. Cancela con encuesta → banner "Cancelación programada" → reanuda.
- PW-API-01: U01 crea una API key → el token se muestra una vez → la tabla solo muestra el prefijo. Crea un webhook con URL privada (error) y luego una válida (secreto visible una vez). Reenvía entregas de WH-0002 hasta que se deshabilita.
- PW-PLAT-01: U16 ejecuta el job de cobranza → TNT-0004 reprogramado al 03/10. Extiende y convierte el trial de TNT-0005. Crea el cupón VERANO27 (60 % rechazado, 25 % aceptado). Habilita e-commerce en Professional.
- PW-RBAC-01: U02 recibe 403 en las 3 rutas y no las ve en el menú. U16 recibe 403 en suscripción y API. Ninguna pantalla tiene scroll horizontal a 400 px.

## 11. Cambios y decisiones respecto a la documentación inicial

**Módulo nuevo: no estaba en la documentación inicial.**

Motivo: la documentación inicial (DOC-PROC-001) solo menciona "plan/entitlements" como control de PROC-001 y deja la pasarela como integración por definir (INT-02). No describe cómo la plataforma cobra a las agencias, cómo limita el uso por plan, ni cómo un tenant integra sus propios sistemas. El cliente pidió que el producto fuera "SaaS completo". Sin suscripción, facturación recurrente, dunning, entitlements, medición y API pública, el producto no se puede comercializar ni operar a escala.

Cambios y precisiones:
- Se separan explícitamente las **tablas de plataforma** (sin RLS, conexión `platform`) de las **tablas del tenant** (con `tenant_id` + RLS). Las API keys, webhooks y apps OAuth pertenecen al tenant. CONVENCIONES §1 listaba `subscriptions`, `platform_invoices` y `plan_features`; aquí se amplía la lista.
- Nuevos permisos `admin.billing.manage` y `admin.api.manage` (solo Tenant Admin). El Manager no gestiona facturación ni API.
- `DB.plans` (prototipo) se amplía con `limits`, `priceYear` y `trialDays`. Los límites coinciden con `PLAN_LIMITS` de `admin.js` (usuarios/almacenamiento/sedes) y añaden mensajería, API y webhooks.
- La matriz de funcionalidades de `admin/tenants` (FEATURES estática) pasa a ser configurable: `DB.featureFlags` con overrides por tenant y add-ons.
- El precio de TNT-0004 (S/ 3 150, Enterprise negociado) se trata como precio pactado de la suscripción, no del plan.
- La facturación de la plataforma es un documento SUNAT del emisor Qhapaq Cloud, independiente de la facturación de cada agencia (`finanzas/facturacion`).

### v2.2 — Planes × reglas (DEMO, decisión del cliente 30/09/2026)
El cliente indicó: «Aún no tenemos reglas ni planes; crea unas de prueba para luego modificarlas». Se implementó una **matriz de demostración editable**:
- `#/admin/plataforma/planes` (Super Admin): aviso y rótulo «Demo — editable» en el catálogo y en los precios; fila «Reglas de reserva incluidas»; tarjeta **Planes × reglas de reserva** agrupada como el centro de reglas (8 grupos, 32 reglas) con un radio por plan (= plan mínimo; los superiores heredan), conteo por plan y «Tenants con acceso». Al cambiar el plan mínimo se pide confirmación con la lista de tenants afectados (ganan/pierden), se actualizan `rule.minPlan` y `DB.plans[].rules`, se audita y se notifica. Botón «Restablecer matriz demo». El modal «Editar plan» se rotula como demo.
- `#/admin/suscripcion` (Tenant Admin): sección **Qué incluye su plan** (reglas incluidas por grupo, no incluidas con candado y plan mínimo) y **comparativo de planes** (precio, nº de reglas y qué suma cada plan) con «Mejorar a X», que abre el cambio de plan preseleccionado. El modal de cambio de plan lista las reglas que se habilitan (upgrade) o dejan de aplicarse (downgrade).
- Matriz demo inicial: Starter 15 reglas; Professional +14 (grupo mínimo, altura, alojamiento, adicionales, descuentos, multimoneda, nominativas, mensajes, comentarios, reseñas propias); Enterprise +3 (pagar después, recargo por medio de pago, invitación a reseña externa).
- Tenant demo TNT-0001 está en Enterprise: para ver bloqueos se usa «Previsualizar como plan…» en `admin/reglas` (especificación 17).

Datos del prototipo creados por el módulo:
- `DB.platformConfig`: emisor, IGV, política de dunning, billing por tenant.
- `DB.platformInvoices`: 28 facturas abr–set 2026, con dunning en TNT-0004, recuperación en TNT-0003 y vencidas en TNT-0006.
- `DB.subscription`: TNT-0001.
- `DB.featureFlags`, `DB.coupons`, `DB.platformMetrics`, `DB.apiKeys`, `DB.webhooks`, `DB.webhookDeliveries`, `DB.oauthApps`.

## 12. Supuestos y puntos a validar con negocio
1. Pasarela de pagos (Cashier con Stripe o pasarela local) y OSE de la plataforma: por definir. El diseño las aísla tras interfaces.
2. Precios del prototipo sin IGV. Confirmar si se publican con o sin IGV y si hay precios en USD para agencias exportadoras.
3. Política de dunning (1/3/7 y suspensión), días de solo lectura tras cancelación (30) o trial vencido (7), y retención de datos antes de la purga.
4. Definición de "usuario facturable": el prototipo incluye guías y conductores con app y excluye los portales. Validar.
5. Si los excesos de consumo (overage) se facturan por unidad o solo se bloquean. El prototipo avisa y bloquea; `line_type usage_overage` queda previsto.
6. Si el Manager debe tener acceso de solo lectura a Suscripción (hoy tiene 403).
7. Soporte OAuth (Passport) en la fase 1 o posterior. El prototipo lo incluye como opcional (Enterprise).
8. Catálogo de eventos de webhook y versionado del payload (`api_version` fijada por webhook). Validar los campos expuestos en `data` (nunca costos ni márgenes).
9. (v2.2) Matriz regla × plan y precios de planes: son **DEMO**; validar con negocio el plan mínimo de cada regla y si alguna regla se vende como add-on.
