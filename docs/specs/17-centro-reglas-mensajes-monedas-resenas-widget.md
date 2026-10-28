# Centro de reglas, mensajes al pasajero, monedas, reseñas externas e integración web

> Fuente: `proto/assets/js/modules/experiencia.js`. Motor: `proto/assets/js/booking-engine.js` (`window.BookingEngine`, «BE»), que es la **fuente única** de disponibilidad, pasajeros, precio, alojamiento, adicionales, pagos y monedas. Este módulo no duplica ningún cálculo: lee y configura las reglas que BE aplica.
> Datos del núcleo usados: `DB.settings.bookingRules`, `DB.settings.ecommerce`, `DB.products` (`availability`, `paxRules`, `addons`, `accommodation`, `payment`, `ruleOverrides`, `ruleParams`), `DB.reservations`, `DB.payments`, `DB.clients`, `DB.webhooks` (espec. 13), `DB.conversations` (espec. 10), `DB.audit`, `DB.notifications`.
> Colecciones propias del módulo: `DB.paxMessageTemplates`, `DB.paxMessages` (canales.js también escribe respuestas), `DB.reviewIntegrations`, `DB.externalReviews` (**de ejemplo**), `DB.fxRateHistory`, `DB.widgetConfig`, `DB.widgetHandoff`.
> Transversal (tenant, RBAC, auditoría, idempotencia): `00-transversal-seguridad-rbac.md`. Sitio público y checkout: `15-sitio-web-publico.md`. API y webhooks: `13-plataforma-saas-api.md`.

---

## 1. Propósito y procesos cubiertos

Responde a los pedidos 1, 8, 10, 12 y 14 del cliente (brief v2.1) y da soporte configurable a los pedidos 2–7, 9, 11 y 13:

| Pedido del cliente | Cobertura | Regla que lo gobierna |
|---|---|---|
| 1. Integrable con cualquier CMS | `canales/integracion-web`: Web Component, iframe, plugin WordPress, API headless; dominios autorizados; ruta pública `sitio/widget` | Reglas con scope «Widget» |
| 2. Disponibilidad por rangos con días intermedios | Calendario del widget y simulador (`BE.month`, `BE.rangesSummary`) | R-AVAIL-RANGES, R-CUTOFF, R-WEEKDAYS |
| 3. Niños que pagan (4–10) y que no pagan (<3), límite por adulto | Pasos de pasajeros del widget; parámetros editables | R-CHILD-PER-ADULT, R-INFANT-FREE, R-MIN-ADULTS, R-MAX-PAX |
| 4. Alojamiento opcional, habitaciones y noches, 4 ciudades | Paso «Alojamiento» del widget (PRD-011) y simulador | R-LODGING, R-LODGING-CAPACITY, R-SINGLE-SUPP |
| 6. Adicionales (museos, etc.) | Paso «Adicionales» del widget | R-ADDONS |
| 7. Modalidad de pago y % de adelanto | Resumen del widget; simulador | R-DEPOSIT, R-PAY-LATER, R-PAY-FEE |
| 8. Administrar mensajes al pasajero | `admin/comunicaciones` | R-PAX-MESSAGES (+ R-COMPANIONS, R-DEPOSIT, R-REVIEW-INVITE como condiciones) |
| 10. Varias monedas seleccionables | `admin/monedas`; selector del widget | R-MULTICURRENCY |
| 11. Previsualización con opción de quitar ítems | Paso «Resumen» del widget; simulador | R-PREVIEW-REMOVE |
| 12. API con TripAdvisor, Trustpilot y otros | `canales/resenas` | R-REVIEW-INVITE (invitaciones) |
| 14. Toda regla activable/desactivable; se pueden añadir reglas | `admin/reglas` (interruptores, parámetros, excepciones por producto, plantillas, reglas personalizadas) | Todas |

Procesos DOC-PROC-001: PROC-054 (e-commerce), PROC-059 (notificaciones), PROC-018/019 (precio y temporadas), PROC-020 (disponibilidad), PROC-061 (auditoría), PROC-062 (seguridad).

## 2. Pantallas

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/admin/reglas` | `/admin/reglas` | **v2.2:** rótulo «Desde plan X»; fuera del plan: candado, «Disponible desde el plan X» y «Mejorar plan»; «Previsualizar como plan…» (solo visual); simulador con «Plan a simular». Pestañas: **Reglas** (31 reglas en 8 grupos; interruptor; parámetros con validación; «Aplica en» (scope); si admite excepción por producto y cuántos productos la sobrescriben, con drawer para ver/agregar/quitar excepciones y enlace al producto; indicación de qué lee el motor y dependencias, p. ej. R-LODGING-CAPACITY sin efecto si R-LODGING está inactiva), **Simulador** (producto + fecha + pasajeros + moneda + modalidad/medio de pago + promo + adicionales + hotel por ciudad → estado del día, calendario del mes, líneas de `BE.quote` con casilla «quitar», ajustes, total, adelanto/saldo, habitaciones/noches, advertencias y estado de cada regla para ese producto), **Plantillas por tipo de negocio** (4 plantillas con diferencia +/− contra la configuración actual y confirmación), **Historial de cambios** (auditoría filtrable). Acciones: «Nueva regla» (personalizada), «Restablecer valores por defecto» (con motivo) | Tenant Admin (`admin.settings.manage`). Otros roles: 403 por ACCESS |
| `#/admin/comunicaciones` | `/admin/comunicaciones` | Pestañas **Plantillas** (10 sembradas; evento, desfase, canales, idioma, condiciones, activa; vista previa con reserva real y canal; envío de prueba; editor en drawer con variables insertables, condiciones por regla/producto/canal de venta/saldo/datos faltantes; duplicar en otro idioma; eliminar si no tiene historial), **Próximos envíos** (programación calculada por reserva; enviar ahora; cancelar; recalcular), **Historial** (estados programado → enviado → entregado → leído / fallido / cancelado; reintentar; actualizar acuses; detalle con trazabilidad). Aviso visible y sin programación si R-PAX-MESSAGES está apagada. «Envío manual» a una reserva | Admin: todo. Operaciones y Manager (`operations.assign`): envío manual, enviar ahora, cancelar, reintentar, recalcular, ver; **no editan plantillas** |
| `#/admin/monedas` | `/admin/monedas` | **v2.2 (DEMO):** proveedores con estado y proveedor activo, historial 30 días con gráfico y tabla, «Actualizar ahora desde proveedor (demo)», umbral de alerta de variación diaria. Tabla de 10 monedas: TC (soles por unidad) editable, TC al cliente con margen, equivalencia de S/ 100, activa, moneda de cobro, historial por moneda; margen de conversión y fuente; «Actualizar desde la fuente» (demo); conversor `BE.convert` con y sin margen. Aviso si R-MULTICURRENCY está apagada | Admin: todo. Finanzas / Contabilidad / Manager (`finance.treasury`): solo tipos de cambio |
| `#/canales/resenas` | `/canales/resenas` | **v2.2:** conectores priorizados (Google, TripAdvisor, Trustpilot) y de fase posterior (Facebook, GetYourGuide, Viator); enlace a `#/canales/resenas-propias`. Nota de cumplimiento (APIs con aprobación, sin scraping, sin reseñas propias, sin gating ni incentivos). KPIs rotulados «Datos de ejemplo». Pestañas **Bandeja** (fuente, autor, fecha, estrellas, texto, producto vinculado, estado de respuesta; drawer con respuesta sugerida, «Guardar respuesta (simulado)» → pendiente de publicar, o «Guardar y copiar» cuando la API es de solo lectura; «Marcar como publicada»; «Derivar a postventa» crea conversación en el inbox para ≤3★), **Conectores** (6 plataformas: estado, «Conectar (demo)» con credencial enmascarada, alcance y declaración de contrato; sincronizar; desconectar), **Invitaciones postviaje** (R-REVIEW-INVITE; reservas finalizadas; invitar), **Widget para el sitio** (fuentes, mínimo de estrellas, cantidad, diseño; código y vista previa) | Admin, Manager (`channels.ecommerce.manage`), Ventas (`crm.clients.manage`): ver, responder, invitar. Conectar/desconectar/sincronizar: solo Admin |
| `#/canales/integracion-web` | `/canales/integracion-web` | Pestañas **Configurador y vista previa** (método, producto o catálogo por categoría, idioma, moneda, color, radio, reseñas, dominio; código generado con copiar (`navigator.clipboard` + alternativa de selección); **vista previa en vivo** del widget real dentro de un marco simulado de página WordPress; clave pública y regeneración), **Métodos de integración** (a–d con código y guía por CMS), **Dominios y seguridad** (alta con validación, verificación DNS TXT, CORS/CSP resultantes), **Eventos y webhooks** (webhooks relevantes con suscripciones de `DB.webhooks`, eventos del widget a la página anfitriona, últimos traspasos) | Admin y Manager (`channels.ecommerce.manage`). Regenerar clave: `admin.api.manage` |
| `#/sitio/widget/{PRD}?lang=&currency=&theme=&radius=&reviews=&domain=` | `/widget/[code]` (app `(sitio)`, sin sesión) | Widget a pantalla completa, **anónimo**: calendario mensual (`BE.month`) con leyenda y resumen de rangos, pasajeros con edades y límites del producto, alojamiento opcional por ciudad con habitaciones por tipo y totales de habitaciones/noches, adicionales, **resumen con quitar/restaurar ítems**, modalidad de pago y adelanto/saldo, selector de moneda e idioma ES/EN, «Continuar reserva». Sin producto válido: lista de productos vendibles | Público |

## 3. Modelo de datos PostgreSQL

Todas las tablas llevan `tenant_id uuid not null` FK `tenants(id)`, RLS `tenant_isolation`, `created_at/updated_at/created_by/updated_by`, y `id uuid` v7.

### 3.1 `sys_booking_rules` (centro de reglas del tenant)
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| code | varchar(40) | no | `R-AVAIL-RANGES`…; `UNIQUE (tenant_id, code)`; CHECK `code ~ '^R-[A-Z0-9-]{2,38}$'` |
| rule_group | varchar(40) | no | CHECK IN ('Disponibilidad','Pasajeros','Alojamiento','Adicionales','Precios','Monedas y pagos','Checkout','Comunicación') o libre si `is_custom` |
| name | varchar(160) | no | |
| description | text | no | |
| enabled | boolean | no | default según catálogo |
| params | jsonb | no | default `'{}'`; validado contra `params_schema` |
| params_schema | jsonb | no | JSON Schema por parámetro (tipo, mín., máx., unidad) — espejo de `PM` en el prototipo |
| scope | text[] | no | pantallas donde aplica (`Sitio web`, `Widget`, `Cotizaciones`, `B2B`, …); índice GIN |
| product_override | boolean | no | si admite excepción por producto |
| is_custom | boolean | no | default false; las personalizadas no se restablecen |
| depends_on | varchar(40) | sí | p. ej. R-LODGING-CAPACITY → R-LODGING |
| min_plan | varchar(20) | no | **v2.2 (DEMO)**. Copia de `sys_booking_rule_catalog.min_plan` al aprovisionar; CHECK IN ('Starter','Professional','Enterprise'); personalizadas = 'Starter'. La fuente de verdad es `plan_rule_entitlements` (especificación 13 §3.4) |
| lock_version | integer | no | default 0 |

Catálogo estándar en `sys_booking_rule_catalog` (tabla de plataforma, sin tenant): `code, rule_group, name, description, default_enabled, default_params, params_schema, scope, product_override, min_plan` (v2.2). «Restablecer» copia desde ahí (no cambia `min_plan`, que gobierna el Super Admin).

**Regla efectiva (v2.2):** `effective = enabled ∧ plan_allows(code, tenant.plan) ∧ ¬override_producto`. En el prototipo: `BE.on(id, p)` devuelve `false` si `BE.planAllows(id)` es falso, aunque `enabled = true`; la configuración (enabled, params) se conserva para cuando el tenant mejore de plan. `PATCH enabled=true` de una regla fuera del plan → 403 `RULE_NOT_IN_PLAN`.

### 3.2 `catalog_product_rule_overrides`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| product_id | uuid | no | FK `catalog_products(id)` |
| rule_code | varchar(40) | no | FK lógica a `sys_booking_rules (tenant_id, code)` |
| disabled | boolean | no | true = `ruleOverrides[code] = false` |
| params | jsonb | sí | `ruleParams[code]` |
| | | | `UNIQUE (tenant_id, product_id, rule_code)`; CHECK: la regla debe tener `product_override = true` (trigger) |

### 3.3 `sys_rule_templates` (plantillas por tipo de negocio, plataforma)
`id, code ('receptivo','minorista','aventura','b2b'), name, description, enabled_codes text[]`. Aplicación registrada en `sys_settings.rule_template_applied jsonb {code, at, by}`.

### 3.4 `notif_pax_message_templates`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| code | varchar(20) | no | `PMT-001`; `UNIQUE (tenant_id, code)` |
| name | varchar(120) | no | |
| event | varchar(30) | no | CHECK IN ('reserva_confirmada','saldo_vence','viaje','fin_viaje','cambio_horario','cumpleanos') |
| offset_hours | integer | no | CHECK BETWEEN −720 AND 720 |
| offset_from_rule | boolean | no | true → `R-REVIEW-INVITE.daysAfter × 24` |
| channels | text[] | no | subconjunto de ('email','whatsapp','portal','push'); CHECK cardinality ≥ 1 |
| subject | varchar(200) | sí | obligatorio si hay canal ≠ whatsapp |
| body | text | no | variables `{{pasajero}}`…; CHECK length ≤ 1024 si incluye whatsapp (validado en FormRequest) |
| lang | char(2) | no | CHECK IN ('ES','EN') |
| group_code | varchar(20) | sí | agrupa variantes de idioma de la misma plantilla |
| conditions | jsonb | no | `{products:[uuid], channels:[text], requiresRule:text, onlyIfBalance:bool, onlyIfMissingDocs:bool}` |
| whatsapp_template_name | varchar(120) | sí | nombre aprobado en Meta (HSM) |
| active | boolean | no | |

### 3.5 `notif_pax_messages`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| code | varchar(20) | no | `MSG-…` |
| reservation_id | uuid | sí | FK `booking_reservations`; null = envío de prueba |
| template_id | uuid | sí | FK plantillas; null = mensaje libre |
| channel | varchar(10) | no | CHECK IN ('email','whatsapp','portal','push') |
| recipient | varchar(200) | no | email, E.164, `portal:{res}` o device token ref |
| subject / body | text | | snapshot renderizado |
| status | varchar(15) | no | CHECK IN ('programado','enviado','entregado','leido','fallido','cancelado') |
| direction | varchar(10) | no | 'saliente' (canales.js registra también respuestas) |
| origin | varchar(10) | no | CHECK IN ('auto','manual','prueba') |
| scheduled_at, sent_at, delivered_at, read_at | timestamptz | sí | |
| attempts | smallint | no | default 0 |
| provider_message_id | varchar(120) | sí | id de SES/WhatsApp Cloud/FCM |
| error | text | sí | |
| lang | char(2) | no | |
| | | | Índices: `(tenant_id, status, scheduled_at)`, `(tenant_id, reservation_id)`, `UNIQUE (tenant_id, reservation_id, template_id, channel) WHERE origin='auto' AND status <> 'cancelado'` (evita duplicados al recalcular) |

### 3.6 `fin_currencies` y `fin_fx_rates`
`fin_currencies`: `code char(3)` (ISO 4217, `UNIQUE (tenant_id, code)`), `name, symbol, active bool, is_base bool (UNIQUE parcial por tenant), is_charge bool`, CHECK `is_charge → active`. `sys_settings.ecommerce`: `fx_margin_pct numeric(5,2) CHECK 0–15`, `rate_source varchar(120)`, `rate_updated_at timestamptz`.
`fin_fx_rates` (histórico, append-only): `currency_code char(3), rate_pen numeric(14,6) CHECK > 0, previous_rate numeric(14,6), rate_date date not null` (v2.2: un valor vigente por día), `provider_id uuid null FK fx_rate_providers`, `source varchar(80)`, `daily_change_pct numeric(7,3)` (v2.2), `alert_raised bool default false` (v2.2), `is_demo bool default false` (v2.2), `effective_at timestamptz, created_by`. Índices `(tenant_id, currency_code, effective_at desc)` y `(tenant_id, currency_code, rate_date desc)`. El historial de pantalla (30 días) es `SELECT DISTINCT ON (rate_date)` del último valor de cada día. La reserva guarda `fx_rate` y `currency_display` al confirmar (regla transversal 15).

`fx_rate_providers` (v2.2, tenant, RLS): `code varchar(20)` (`UNIQUE (tenant_id, code)`), `name varchar(120)`, `kind varchar(20) CHECK IN ('Simulado','Oficial','API','Manual')`, `status varchar(20) CHECK IN ('activo','por_validar','inactivo','error')`, `schedule_cron varchar(40)`, `secret_ref varchar(200) null` (vault), `last_run_at timestamptz`, `last_error text`, `note text`. En `sys_settings.ecommerce`: `rate_provider_id uuid FK` (solo uno activo; debe tener `status='activo'`), `fx_alert_pct numeric(5,2) default 3 CHECK 0.1–20`, `is_demo_rates bool`. Prototipo: `ecommerce.fxProviders[]`, `rateProvider`, `rateHistory{CODE:[{date, rate, source}]}` (30 días DEMO), `fxAlertPct`, `isDemoRates`.

### 3.7 `integration_review_sources` y `channel_external_reviews`
`integration_review_sources`: `provider varchar(20)` CHECK IN ('tripadvisor','trustpilot','google','facebook','getyourguide','viator'), `status` CHECK IN ('no_conectado','pendiente','conectado','error'), `account_ref varchar(120)`, `secret_ref varchar(200)` (referencia al vault; **nunca** el secreto), `secret_last4 char(4)`, `scopes text[]`, `can_reply bool`, `supports_invites bool`, `rating numeric(3,2)`, `review_count int`, `last_sync_at`, `connected_at/by`, `contract_ack_at/by` (declaración de contrato). `UNIQUE (tenant_id, provider)`.
`channel_external_reviews`: `source_id FK`, `external_id varchar(120)` (`UNIQUE (tenant_id, source_id, external_id)`), `author_display varchar(120)`, `review_date date`, `rating smallint CHECK 1–5`, `title, body text`, `lang char(2)`, `product_id uuid null FK`, `url text`, `response_status` CHECK IN ('sin_responder','pendiente_publicar','publicada','no_admite'), `reply text`, `replied_at/by`, `conversation_id uuid null FK collab_conversations`, `raw jsonb`. Texto de la reseña **inmutable** (no se edita).

### 3.8 `channel_widget_config`, `channel_widget_domains`, `channel_widget_handoffs`
`channel_widget_config` (1 por tenant): `tenant_slug, public_key varchar(40) UNIQUE global, public_key_prev varchar(40), prev_expires_at, defaults jsonb, reviews_widget jsonb`.
`channel_widget_domains`: `domain varchar(253)` (`UNIQUE (tenant_id, domain)`, CHECK regex hostname), `cms varchar(40)`, `status` CHECK IN ('pendiente','verificado'), `verify_token varchar(60)`, `verified_at`.
`channel_widget_handoffs` (traspaso al checkout, TTL 24 h): `code varchar(20)`, `product_id`, `service_date date`, `pax jsonb {adt,chd,inf}`, `addons jsonb`, `lodging jsonb`, `removed text[]`, `currency_display char(3)`, `fx_rate numeric(12,6)`, `payment_mode varchar(15)`, `lang char(2)`, `origin_domain varchar(253)`, `quote_snapshot jsonb` (solo referencial), `rules_applied text[]`, `status` CHECK IN ('pendiente','en_carrito','convertido','expirado'), `cart_id uuid null`, `expires_at`.

## 4. Máquinas de estado

| Entidad | Estados y transiciones | Quién | Efectos |
|---|---|---|---|
| Regla | `activa ⇄ inactiva` | Tenant Admin (interruptor, plantilla, restablecer) | Invalida cache Redis `rules:{tenant}`; si cambia R-PAX-MESSAGES / R-REVIEW-INVITE / R-COMPANIONS / R-DEPOSIT se recalcula la programación de mensajes; auditoría |
| Mensaje | `programado → enviado → entregado → leido`; `enviado/programado → fallido → (reintento) enviado`; `programado → cancelado` | Scheduler (auto), Operaciones/Admin (enviar ahora, cancelar, reintentar), webhooks del proveedor (entregado/leído/fallido) | Notificación, auditoría, `attempts++` |
| Plantilla | `activa ⇄ inactiva`; eliminar solo sin historial | Admin | Recalcula programación |
| Conector de reseñas | `no_conectado → conectado`; `pendiente → conectado`; `conectado → error → conectado`; `conectado → no_conectado` | Admin; job de sincronización (→ error si 401/403) | Revoca token y borra secreto al desconectar |
| Respuesta a reseña | `sin_responder → pendiente_publicar → publicada`; `sin_responder → no_admite → publicada` (manual) | Admin/Manager/Ventas; job de publicación | Llamada a la API (Google `updateReply`, Trustpilot `reply`); si la API es solo lectura se publica manualmente |
| Dominio | `pendiente → verificado`; eliminar | Admin/Manager; job DNS | Regenera CORS y CSP `frame-ancestors` |
| Traspaso del widget | `pendiente → en_carrito → convertido`; `pendiente → expirado` (24 h) | Widget, checkout, scheduler | Evento `widget.checkout_started` |

## 5. Reglas de negocio

Regla de oro: cada comportamiento consulta `BE.on('<regla>', producto)` y **desaparece limpiamente** si está inactiva (sin campos vacíos). Qué gobierna cada regla en estas pantallas:

| ID | Regla | Comportamiento en este módulo | Dónde se valida (producción) |
|---|---|---|---|
| R-AVAIL-RANGES | Rangos disponibles/cerrados/agotados | Calendario del widget y del simulador; resumen «Disponible del 02 al 06 oct · Ocupado del 07 al 09 oct…». Inactiva: no se listan rangos | `BookingEngine::dayStatus` (Service) + `availability` API |
| R-CUTOFF | Anticipación mínima | Días «fuera de plazo» no seleccionables. Parámetro `defaultHours` usado si el producto no define `cutoffHours` ni `advMin` | Service |
| R-WEEKDAYS | Días de operación | Días «no opera» deshabilitados | Service |
| R-HOLD | Bloqueo de cupos | Parámetro `minutes` sincroniza `settings.holdMinutes` (carrito/checkout) | Service + lock Redis |
| R-MIN-ADULTS / R-CHILD-PER-ADULT / R-INFANT-FREE / R-MAX-PAX | Composición del grupo | Widget: rótulos de edades, «no paga», ocultar infantes si `infantAllowed=false`, errores de `BE.validatePax` bloquean «Continuar». Parámetros sincronizan `ecommerce.defaultPaxRules` (productos sin reglas propias). Inactivas: sin límite y pagan tarifa completa | FormRequest + Service |
| R-COMPANIONS | Datos de acompañantes | Condición de la plantilla PMT-003 (solo si faltan documentos) | Job de programación |
| R-LODGING / R-LODGING-CAPACITY / R-SINGLE-SUPP | Alojamiento por ciudad | Paso «Alojamiento» (se oculta si inactiva), totales de habitaciones y noches, advertencia de camas, suplemento simple | Service |
| R-ADDONS | Adicionales | Paso «Adicionales» (se oculta si inactiva) | Service |
| R-PROMO, R-GROUP-DISC, R-EARLY-BIRD, R-LAST-MINUTE, R-SEASON | Precios | Ajustes en el resumen y en el simulador; campo promo del simulador se oculta si R-PROMO inactiva | Service |
| R-MULTICURRENCY | Varias monedas | Selector de moneda del widget, simulador y configurador; inactiva → solo moneda base y aviso en `admin/monedas`. Parámetro `fxMarginPct` ⇄ `ecommerce.fxMarginPct` | Service |
| R-DEPOSIT / R-PAY-LATER / R-PAY-FEE | Pagos | Modalidades del resumen (adelanto, pagar después), adelanto/saldo/vencimiento, recargo con tarjeta en simulador. R-DEPOSIT también condiciona PMT-002 | Service + FormRequest checkout |
| R-PREVIEW-REMOVE | Quitar ítems | Botones «Quitar/Restaurar» en líneas `removable` (adicionales y alojamiento). Inactiva: el resumen se muestra igual, sin botones | Service (`removed[]` validado en servidor) |
| R-PAX-MESSAGES | Mensajes automáticos | Apagada: aviso, se retiran los `programado` automáticos y no se programa; envíos manuales siguen disponibles | Job `SchedulePaxMessages` |
| R-REVIEW-INVITE | Invitación a reseña | Plantilla PMT-009 (desfase `daysAfter × 24 h`) y pestaña «Invitaciones». Inactiva: aviso y sin invitaciones | Job + Policy |
| CR-01 | Validación de parámetros | Rango por parámetro (`PM`); cruzadas: niño mín. ≤ máx.; infante máx. < niño mín.; niño máx. < adulto mín.; adelanto 1–99 % | FormRequest `UpdateBookingRuleRequest` |
| CR-02 | Excepción por producto | Solo si `product_override`; solo puede **desactivar** o cambiar parámetros, nunca activar una regla inactiva del tenant | Service + trigger |
| CR-03 | Plantillas de negocio | Cambian solo `enabled` de reglas estándar; no tocan parámetros, excepciones ni personalizadas | Service |
| CR-04 | Reglas personalizadas | Se crean **inactivas**; son banderas consultables (`BE.on`) hasta que desarrollo implemente su efecto | Policy |
| MSG-01 | Plantilla válida | ≥1 canal; asunto obligatorio si hay canal ≠ WhatsApp; variables solo de la lista; WhatsApp ≤ 1 024 caracteres y plantilla aprobada por Meta fuera de la ventana de 24 h | FormRequest |
| MSG-02 | Programación | Por reserva viva (no cancelada/borrador), plantilla del idioma de la reserva (EN si el cliente no es de Perú) o ES; hora = base del evento + desfase; no se reenvían eventos pasados al recalcular; unicidad (reserva, plantilla, canal) | Job + índice único parcial |
| MSG-03 | Envío manual | Solo `operations.assign` o `admin.settings.manage`; queda con usuario y origen `manual` | Policy |
| FX-01 | Tipo de cambio | > 0; variación mayor que `fx_alert_pct` (demo 3 %, antes 5 % fijo) pide confirmación; cada cambio crea fila histórica; USD sincroniza `settings.fx`; reservas existentes conservan su TC | FormRequest + Service |
| FX-02 | Monedas | La base no se desactiva; una moneda de cobro debe estar activa; la base siempre es de cobro | FormRequest + CHECK |
| FX-04 | Proveedor y alerta (v2.2) | Solo un proveedor activo y con `status='activo'`; la actualización (job o «Actualizar ahora») registra un valor por día y moneda (reemplaza el del día); variación diaria > `fx_alert_pct` → `alert_raised` + notificación a Finanzas; si el proveedor falla se conserva el último valor | Service + Job |
| PLN-R-01 | Regla por plan (v2.2) | Una regla fuera del plan del tenant no aplica (BE.on = false) y no puede activarse (403 `RULE_NOT_IN_PLAN`); la vista previa por plan es solo visual | Policy + Service |
| FX-03 | TC al cliente | `TC × (1 − margen)` al mostrar precios en moneda extranjera (el margen absorbe la variación) | Service |
| RV-01 | Cumplimiento | Solo APIs oficiales con contrato/aprobación declarada; sin scraping; sin publicar reseñas propias; texto de reseña inmutable; invitaciones a todos (sin gating ni incentivos); `externalReviews` del prototipo son **de ejemplo** y se rotulan en toda pantalla y widget | Policy + revisión legal |
| RV-02 | Respuesta | ≥ 20 caracteres; si la API es solo lectura (TripAdvisor Content API, GetYourGuide, Viator) se guarda y copia para publicar manualmente | FormRequest |
| RV-03 | Credenciales | Solo se muestran últimos 4 caracteres; secreto en vault (AWS Secrets Manager / Vault) | Service |
| WG-01 | Dominios | Solo dominios verificados (DNS TXT) reciben CORS y `frame-ancestors` | Middleware |
| WG-02 | Clave pública | Solo lectura de catálogo/disponibilidad/cotización y creación de carrito; rotación con 24 h de gracia | Middleware + rate limit |
| WG-03 | Precio | El total del widget es referencial; el checkout recalcula con BE en servidor (regla transversal 5) | Service |

## 6. API REST

| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| GET | `/api/v1/admin/booking-rules` | admin.settings.manage | Reglas con conteo de excepciones | — |
| PATCH | `/api/v1/admin/booking-rules/{code}` | admin.settings.manage | `enabled`, `params` (valida CR-01) | `If-Match` lock_version |
| POST | `/api/v1/admin/booking-rules` | admin.settings.manage | Regla personalizada | Idempotency-Key |
| DELETE | `/api/v1/admin/booking-rules/{code}` | admin.settings.manage | Solo `is_custom` | — |
| POST | `/api/v1/admin/booking-rules/apply-template` | admin.settings.manage | `{template}` | Idempotency-Key |
| POST | `/api/v1/admin/booking-rules/reset` | admin.settings.manage | `{reason}` | Idempotency-Key |
| GET/PUT/DELETE | `/api/v1/catalog/products/{id}/rule-overrides/{code}` | admin.settings.manage | Excepciones por producto | lock |
| POST | `/api/v1/admin/booking-rules/simulate` | admin.settings.manage | `{product, selection}` → `dayStatus`, `quote`, estado de reglas | — |
| GET | `/api/v1/admin/booking-rules/audit` | admin.settings.manage | Historial | — |
| GET/POST/PATCH/DELETE | `/api/v1/notifications/pax-templates[/{id}]` | admin.settings.manage (GET también operations.assign) | CRUD plantillas | lock |
| POST | `/api/v1/notifications/pax-templates/{id}/preview` | admin.settings.manage \| operations.assign | `{reservation, channel}` → render | — |
| POST | `/api/v1/notifications/pax-templates/{id}/test` | admin.settings.manage | Envío de prueba al usuario | Idempotency-Key |
| GET | `/api/v1/notifications/pax-messages?filter[status]=&filter[channel]=` | admin.settings.manage \| operations.assign | Programados e historial | — |
| POST | `/api/v1/reservations/{id}/pax-messages` | admin.settings.manage \| operations.assign | Envío manual | Idempotency-Key |
| POST | `/api/v1/notifications/pax-messages/{id}/send-now` · `/cancel` · `/retry` | idem | Acciones sobre un mensaje | Idempotency-Key |
| POST | `/api/v1/notifications/pax-messages/reschedule` | idem | Recalcular programación | ShouldBeUnique |
| POST | `/api/v1/webhooks/providers/{ses\|whatsapp\|fcm}` | firma del proveedor | Acuses entregado/leído/fallido | dedupe por provider_message_id |
| GET/PATCH | `/api/v1/finance/currencies[/{code}]` | admin.settings.manage (rate: + finance.treasury) | Activar, cobro, TC | lock |
| GET | `/api/v1/finance/currencies/{code}/rates` | idem | Historial | — |
| POST | `/api/v1/finance/currencies/refresh` | admin.settings.manage \| finance.treasury | Actualizar ahora desde el proveedor activo (registra el valor del día) | ShouldBeUnique |
| GET | `/api/v1/finance/fx-rates?currency=USD&days=30` | admin.settings.manage \| finance.treasury | Historial diario (v2.2) con `daily_change_pct` y `alert_raised` | — |
| GET | `/api/v1/finance/fx-providers` | idem | Proveedores y estado (v2.2) | — |
| PATCH | `/api/v1/finance/fx-settings` | admin.settings.manage | `{rate_provider_id, fx_alert_pct, fx_margin_pct}` (v2.2) | lock |
| POST | `/api/v1/finance/fx-providers/{code}/test` | admin.settings.manage | Probar conexión (v2.2) | — |
| GET | `/api/v1/admin/booking-rules?plan=Starter` | admin.settings.manage | Vista previa por plan (v2.2): `effective` calculado con el plan indicado, sin cambiar la suscripción | — |
| GET | `/api/v1/finance/currencies/convert?amount=&from=&to=` | idem | Conversor | — |
| GET/POST/DELETE | `/api/v1/integrations/review-sources[/{provider}]` | admin.settings.manage | Conectar (OAuth/clave), desconectar | Idempotency-Key |
| POST | `/api/v1/integrations/review-sources/{provider}/sync` | admin.settings.manage | Sincronizar | ShouldBeUnique |
| GET | `/api/v1/channels/external-reviews` | channels.ecommerce.manage \| crm.clients.manage | Bandeja | — |
| POST | `/api/v1/channels/external-reviews/{id}/reply` · `/mark-published` · `/escalate` | idem | Responder / derivar a postventa | Idempotency-Key |
| POST | `/api/v1/reservations/{id}/review-invitations` | idem | Invitar (requiere R-REVIEW-INVITE) | Idempotency-Key |
| GET/PUT | `/api/v1/channels/widget` | channels.ecommerce.manage | Config y predeterminados | lock |
| POST | `/api/v1/channels/widget/rotate-key` | admin.api.manage | Regenerar clave | Idempotency-Key |
| GET/POST/DELETE | `/api/v1/channels/widget/domains[/{id}]` · POST `/{id}/verify` | channels.ecommerce.manage | Dominios | — |
| GET | `/api/v1/public/products?category=&lang=` · `/public/products/{code}` | `X-Qhapaq-Key` + Origin verificado | Catálogo | cache CDN 5 min |
| GET | `/api/v1/public/products/{code}/availability?month=&seats=` | idem | `BE.month` | cache 60 s |
| POST | `/api/v1/public/quote` | idem | `BE.quote` (referencial) | rate limit 60/min/IP |
| POST | `/api/v1/public/carts` · `/public/widget-handoffs` | idem | Traspaso al checkout → `checkout_url` | Idempotency-Key |
| GET | `/api/v1/public/reviews?sources=&min_rating=&count=` | idem | Widget de reseñas (con atribución) | cache 10 min |

## 7. Permisos y comportamiento por rol

| Rol | admin/reglas | admin/comunicaciones | admin/monedas | canales/resenas | canales/integracion-web |
|---|---|---|---|---|---|
| Tenant Admin (U01) | Todo | Todo | Todo | Todo | Todo |
| Manager (U02) | 403 | Envío manual, enviar ahora, cancelar, reintentar, recalcular; plantillas solo lectura/vista previa | Solo TC (tiene `finance.treasury`) | Ver, responder, invitar, derivar; sin conectar | Configurar, dominios; sin regenerar clave (sin `admin.api.manage`) |
| Ventas (U03/U04) | 403 | 403 | 403 | Ver, responder, invitar, derivar (reseñas no tienen dueño) | 403 |
| Operaciones (U05/U06) | 403 | Igual que Manager | 403 | 403 | 403 |
| Finanzas / Contabilidad (U07/U08) | 403 | 403 | Solo TC; activar, cobro y margen deshabilitados | 403 | 403 |
| Externos | 403 | 403 | 403 | 403 | 403 |
| Anónimo | — | — | — | — | `sitio/widget` público |

Botones sin permiso se muestran deshabilitados con `title="Requiere permiso x.y"`; si se invoca por otra vía, toast `bad` sin mutar datos.

## 8. Eventos, jobs y notificaciones

| Elemento | Cola | Descripción |
|---|---|---|
| `BookingRuleChanged` | default | Invalida `rules:{tenant}` en Redis; si afecta comunicaciones despacha `SchedulePaxMessages` |
| `SchedulePaxMessages` (scheduler cada 15 min + tras `ReservationConfirmed`, `PaymentApplied`, cambio de plantilla/regla) | notifications | Calcula (reserva × plantilla × canal) y hace upsert de `programado` futuros; retira pendientes si R-PAX-MESSAGES está apagada |
| `DispatchDuePaxMessages` (cada minuto) | notifications | Envía `programado` con `scheduled_at ≤ now()`; `tries=5`, backoff exponencial; `fallido` al agotar |
| `ServiceScheduleChanged` / `IncidentCreated` | notifications | Dispara PMT-007 (cambio de horario) |
| Webhooks de proveedores (SES, WhatsApp Cloud API, FCM) | notifications | Actualizan entregado/leído/fallido |
| `SyncExternalReviews` (cada 6 h por fuente) | integrations | Importa reseñas nuevas (upsert por `external_id`), actualiza rating/cantidad; 401/403 → estado `error` |
| `PublishReviewReply` | integrations | Publica respuestas `pendiente_publicar` en APIs que lo permiten |
| `fx:update-daily` → `RefreshFxRates` (v2.2; diario 08:00 America/Lima por tenant; `ShouldBeUnique` por tenant) | integrations | Consulta el proveedor activo, inserta `fin_fx_rates` del día por moneda activa, calcula `daily_change_pct`; si supera `fx_alert_pct` marca `alert_raised` y notifica a Finanzas (`fx.variacion_alta`); fallo → conserva el último valor, `fx_rate_providers.last_error` y aviso |
| `VerifyWidgetDomain` (cada 10 min, 72 h) | low | Busca TXT; al verificar publica CORS/CSP |
| `ExpireWidgetHandoffs` (hora) | low | `pendiente → expirado` a las 24 h |
| Webhooks salientes | integrations (outbox) | `reservation.created/confirmed/cancelled`, `payment.applied`, `widget.checkout_started`, `review.received`, `passenger.message.failed` (HMAC-SHA256) |
| Eventos del widget en el navegador | — | `qhapaq:ready`, `qhapaq:resize`, `qhapaq:date_selected`, `qhapaq:quote`, `qhapaq:checkout` (CustomEvent / postMessage a orígenes verificados) |

## 9. Auditoría

Entidad y acción registradas (prototipo: `Audit.log`):
- `Regla de reserva`: activar, desactivar, editar_parametros (antes/después JSON), crear, eliminar, aplicar_plantilla, restablecer (con motivo). `Producto`: excepcion_regla, quitar_excepcion_regla.
- `Plantilla de mensaje`: crear, editar, activar/desactivar, duplicar, eliminar, envio_prueba. `Mensaje al pasajero`: enviar, reintentar, cancelar, recalcular, sincronizar_estados.
- `Tipo de cambio`: actualizar (antes/después, fuente), actualizar_desde_proveedor, cambiar_proveedor, probar_conexion (v2.2). `Moneda`: activar/desactivar, agregar_cobro/quitar_cobro, editar (margen y umbral de alerta).
- `Integración de reseñas`: conectar (alcance), desconectar, sincronizar. `Reseña externa`: responder, marcar_publicada, derivar_postventa, invitar. `Widget de reseñas`: editar.
- `Widget web`: editar, regenerar_clave (con motivo), agregar/verificar/quitar dominio.

## 10. Casos de prueba mínimos

Pest:
1. Aislamiento: tenant A no lee reglas, plantillas, mensajes, monedas, reseñas ni dominios de B; la clave pública de A no cotiza productos de B.
2. RBAC: Operaciones → 403 en `PATCH booking-rules`, 200 en `POST reservations/{id}/pax-messages`; Finanzas → 200 en `PATCH currencies/USD {rate}`, 403 en `{active:false}`; Manager → 403 en `rotate-key`.
3. CR-01: `childMinAge=12, childMaxAge=10` → 422; `infantMaxAge=4, childMinAge=4` → 422; `R-DEPOSIT pct=100` → 422.
4. CR-02: override en regla con `product_override=false` → 422; override no activa una regla inactiva del tenant.
5. Regla de oro: con R-LODGING inactiva `quote` ignora `lodging`; con R-PREVIEW-REMOVE inactiva `removed[]` no altera el total; con R-MULTICURRENCY inactiva `currency≠PEN` → 422.
6. MSG-02: recalcular dos veces no duplica (índice único); con R-PAX-MESSAGES apagada no quedan `programado` auto; plantilla EN elegida para cliente extranjero.
7. MSG-01: variable desconocida → 422; WhatsApp > 1 024 → 422.
8. FX-01/02: rate ≤ 0 → 422; desactivar moneda de cobro → 422; reserva creada conserva `fx_rate` tras cambiar el TC.
9. RV-03: la respuesta de `review-sources` nunca incluye el secreto.
10. WG-01: `Origin` no verificado → sin cabecera CORS; idempotencia de `widget-handoffs`.

Playwright (prototipo verificado con scripts equivalentes):
- E2E-W1 (anónimo): `#/sitio/widget/PRD-011?lang=es&currency=USD` → elegir 12 oct, 2 ADT + 1 CHD + 1 INF, incluir alojamiento, sumar Museo Larco, quitar Lima (total baja, habitaciones 6, noches 8), elegir adelanto (30 %), «Continuar reserva» → resumen con referencia `WHO-…` → `#/sitio/checkout`.
- E2E-W2: días 07–09 oct no seleccionables y rotulados «Ocupado».
- E2E-R1 (U01): desactivar R-PAX-MESSAGES → aviso en comunicaciones y 0 programados; aplicar «Tour operador receptivo» → R-REVIEW-INVITE activa y programación recalculada.
- E2E-M1 (U05): envío manual desde comunicaciones; «Nueva plantilla» deshabilitado; `#/admin/reglas` → 403.
- E2E-F1 (U07): cambiar USD a 3,77 → historial y `settings.fx` actualizados; interruptores de activación deshabilitados.
- E2E-V1 (U02): responder reseña → «Pendiente de publicar»; configurador genera shortcodes WordPress y catálogo.
- Barrido 400/1440 px sin desborde ni errores (`node sweepRoles.js`: 446 rutas, 0 incidencias).

## 11. Cambios y decisiones respecto a la documentación inicial

**Módulo nuevo: no estaba en la documentación inicial**; motivo: pedidos 1, 8, 10, 12 y 14 del cliente (v2.1). Decisiones:
- Las reglas del motor pasan a ser **datos por tenant** (`sys_booking_rules`) con interruptor, parámetros validados, excepción por producto y plantillas por tipo de negocio, porque no todos los clientes del SaaS las necesitan.
- Parámetros de pasajeros, bloqueo, margen y adelanto se **sincronizan** con los valores por defecto de `ecommerce` que BE ya lee (una sola fuente efectiva); los productos con configuración propia la conservan.
- Reglas personalizadas: se permiten como banderas; su comportamiento requiere desarrollo (no hay motor de reglas genérico en el prototipo; evaluar un DSL solo si la demanda lo justifica).
- Mensajes al pasajero separados de `admin/notificaciones` (eventos internos): son comunicaciones al viajero, por reserva, multicanal e idioma, con programación y acuses.
- Reseñas: se adopta un enfoque de cumplimiento estricto (APIs oficiales, sin scraping, sin gating). TripAdvisor Content API y los portales de GetYourGuide/Viator no permiten responder por API → flujo «guardar y copiar».
- El widget reutiliza BookingEngine; el traspaso al checkout usa `window.SiteCart.add()` si sitio.js lo expone y, si no, `DB.widgetHandoff` + clave `qhapaq_widget_handoff` en localStorage (en producción: `POST /public/widget-handoffs` → `checkout_url`).
- El TC al cliente aplica el margen como `TC × (1 − margen)`.

### v2.2 — Decisiones del cliente (30/09/2026)

| # | Decisión del cliente | Cómo quedó en el prototipo | Estado |
|---|---|---|---|
| 1 | «Aún no tenemos reglas ni planes; crea unas de prueba para luego modificarlas.» | Cada regla tiene `minPlan` (Starter 15 · Professional +14 · Enterprise +3 = 32). `admin/reglas`: rótulo «Desde X» por regla; fuera del plan → interruptor bloqueado con candado, «Disponible desde el plan X» y botón «Mejorar plan» (`#/admin/suscripcion`); selector «Previsualizar como plan…» (solo visual) y filtro «No incluidas en el plan». El simulador admite «Plan a simular» (sustituye `BE.tenantPlan` solo durante el cálculo; las reglas muestran «Fuera del plan X» con `BE.planAllows(id, plan)`). La matriz editable está en `admin/plataforma` (especificación 13). | DEMO — editable |
| 2 | «Aún no tenemos tipo de cambio específico; añade unos de demo.» | `admin/monedas` rotulada «Tipos de cambio de demostración» + «Demo — editable»: proveedores (`fxProviders`) con estado y proveedor activo seleccionable (solo los `activo`; «Probar conexión (demo)» para los por validar), historial de 30 días por moneda con gráfico de línea y tabla (var. diaria), «Actualizar ahora desde proveedor (demo)» (valor del día con variación simulada ±0,6 %, auditado), umbral de alerta configurable (3 %) con aviso en pantalla y notificación. Nota explícita: el prototipo no consulta proveedores reales. | DEMO — editable |
| 3 | «Cada tour genera su propia reserva.» | Sin cambios: un producto = una reserva (`booking.productId`). | Decisión cerrada |
| 4 | «Considera algunas plataformas de reseñas, como también su propio módulo de reseñas.» | `canales/resenas`: **priorizados (fase 1)** Google Business Profile, TripAdvisor y Trustpilot; **fase posterior** Facebook, GetYourGuide y Viator (conectores agrupados y rotulados; siguen disponibles en demo). Tarjeta «Decisión del cliente» y enlace al módulo propio `#/canales/resenas-propias` («Reseñas de clientes», regla R-OWN-REVIEWS, Professional). | Decisión cerrada |

Todo valor DEMO se reemplazará por el definitivo sin cambios de modelo: basta editar `plan_rule_entitlements`/`min_plan`, precios de `plans` y el proveedor/valores de `fin_fx_rates`.

## 12. Supuestos y puntos a validar con negocio

1. Plantillas por tipo de negocio: confirmar el conjunto exacto de reglas de cada perfil.
2. Idioma de la reserva: hoy se deriva del país del cliente (≠ Perú → EN); ¿agregar idioma explícito en la reserva y más idiomas (PT, FR)?
3. WhatsApp: cada plantilla debe aprobarse en Meta; definir quién gestiona la cuenta de WhatsApp Business y los costos por conversación.
4. Hora de envío de eventos sin hora (saldo 09:00, fin de viaje 18:00, cumpleaños 08:00) y ventana de silencio nocturna.
5. Recargo por tarjeta (R-PAY-FEE): verificar normativa de protección al consumidor y contrato con la pasarela antes de activarlo.
6. Fuente oficial del TC (SBS, BCRP o proveedor comercial) y frecuencia; margen máximo 15 %; umbral de alerta definitivo (demo 3 %). Los tipos de cambio, historial y proveedores actuales son **DEMO**.
7. Reseñas: estado real de contratos con Google, TripAdvisor y Trustpilot (fase 1) y luego Meta, GetYourGuide y Viator; los datos mostrados en el prototipo son **de ejemplo**.
8. Widget: dominio del CDN (`cdn.qhapaq.app`) y del host de reservas por tenant; política de cookies/consentimiento en sitios de terceros.
9. Integración final con el checkout de sitio.js (carrito unificado basado en BE) — pendiente de alinear con el responsable de `sitio.js`.
10. Plan mínimo de cada regla (matriz DEMO): validar con negocio antes de publicar precios.
