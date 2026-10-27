# Marketing (campañas, segmentos, automatizaciones, consentimientos) · Metas comerciales

> Fuente: `proto/assets/js/modules/crecimiento.js` (rutas `#/marketing/campanas*`, `#/marketing/segmentos/*`, `#/crm/metas*`). Colecciones creadas por el módulo: `DB.segments`, `DB.campaigns`, `DB.automations`, `DB.consents`, `DB.mktTemplates`, `DB.subscribers`, `DB.salesTargets`, `DB.salesTeams`, `DB.salesTargetPolicy`; además agrega promociones con código a `DB.products[].promotions` y comisiones de tipo «Bono por meta» a `DB.commissions` (sin modificar `finanzas.js`).
> Permisos (`data.js`): `marketing.campaigns.manage` (Tenant Admin, Manager), `crm.targets.manage` (Tenant Admin, Manager), `crm.targets.view` (Tenant Admin, Manager, Ventas). Rutas en `ACCESS` (`core.js`): `marketing` → `marketing.campaigns.manage`; `crm/metas` → `crm.targets.view | crm.targets.manage`.
> Transversal (tenant, RLS, Sanctum, spatie/permission, auditoría, Notify, idempotencia): `00-transversal-seguridad-rbac.md`. Tablas referenciadas: `crm_clients`, `crm_leads`, `crm_opportunities`, `crm_surveys` (01), `catalog_products`, `catalog_promotions` (02), `quote_quotes` (03), `booking_reservations`, `booking_services` (04), `fin_commissions` (07), `channel_ecom_orders` (08), `iam_users`, `iam_branches` (00/09), `notif_messages` (09).

---

## 1. Propósito y procesos cubiertos

| Proceso / regla | Qué cubre |
|---|---|
| PROC-010 Fidelización | Segmentación de la cartera → acción comercial (campaña o automatización) → lead / oportunidad / reserva atribuida |
| PROC-009 Postventa | Automatización «Encuesta post-viaje (NPS)» sobre reservas completadas sin encuesta |
| PROC-059 Notificaciones | Envío por email/WhatsApp mediante los mismos adaptadores (plantilla → canal → estado → reintento → log); confirmación de baja |
| PROC-047 Comisiones | Bono por cumplimiento de meta → comisión «Bono por meta» en estado Calculada, que Finanzas aprueba y paga |
| PROC-060 Reportes | Avance, proyección, ranking e historial de metas; métricas y atribución de campañas; exportación CSV |
| PROC-061/063 | Exportaciones y acciones críticas auditadas |
| Reglas transversales 1, 2, 15, 16, 17, 18, 20, 22, 24 | Tenant, aislamiento, TC conservado, auditoría, backend valida, canales sin reglas propias, adaptadores, idempotencia, historial no destructivo |
| Ley 29733 (Protección de Datos Personales) y su Reglamento D.S. 016-2024-JUS; Ley 29571 art. 58 (métodos comerciales agresivos); Ley 28493 (correo comercial no solicitado) | Consentimiento por canal con fecha/origen/evidencia, oposición (baja) siempre respetada, supresión de no consentidos en cada envío |

---

## 2. Pantallas

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/marketing/campanas` (pestaña Campañas) | `/marketing/campanas` | KPIs (campañas enviadas 90 d, tasa de apertura/lectura, CTR, ingresos atribuidos S/, contactables email/WhatsApp); tabla de campañas con estado, envío, enviados, apertura, conversiones (leads/reservas) e ingresos; filtros estado/canal; export CSV | Tenant Admin, Manager |
| `#/marketing/campanas/segmentos` | `/marketing/campanas?tab=segmentos` | Segmentos con reglas en texto, conteo en vivo, contactables por email y WhatsApp, campañas que lo usan | Idem |
| `#/marketing/campanas/plantillas` | `/marketing/campanas?tab=plantillas` | Plantillas email/WhatsApp, categoría (MARKETING/UTILITY/SERVICIO), estado Meta; modal editor con variables clicables, vista previa en vivo con contacto real, validación de baja; «Registrar respuesta de Meta: aprobada» | Idem |
| `#/marketing/campanas/automatizaciones` | `/marketing/campanas?tab=automatizaciones` | 6 automatizaciones (tarjetas): disparador, espera, plantilla, cola actual calculada con datos reales y motivo de supresión, acumulados, última ejecución; Activar/Pausar, Ejecutar ahora, Configurar (plantilla/espera + historial) | Idem |
| `#/marketing/campanas/consentimientos` | `/marketing/campanas?tab=consentimientos` | Aviso legal; KPIs (email autorizado, WhatsApp autorizado, bajas 30 d, sin consentimiento); tabla de todos los contactos con estado por canal, fecha y origen; filtros; drawer «Gestionar» | Idem |
| Drawer de consentimiento | componente | Por canal: estado, fecha, origen, evidencia, versión de política, baja (fecha, origen, campaña); Registrar consentimiento / Registrar baja; historial unificado | Idem |
| `#/marketing/segmentos/nuevo` · `#/marketing/segmentos/<SEG-…>` | `/marketing/segmentos/[code]` | Constructor: nombre, coincidencia TODAS/ALGUNA, descripción, reglas (campo, operador, valor); conteo en vivo con desglose por tipo y tabla de supresión email/WhatsApp; vista previa de 10 contactos; Guardar, Duplicar, Eliminar, Crear campaña | Idem |
| `#/marketing/campanas/nueva` · `#/marketing/campanas/<CMP-…>/editar` | `/marketing/campanas/nueva`, `/marketing/campanas/[code]/editar` | Editor: nombre, canal, segmento, código promocional (existente o crear nuevo inline), plantilla (WA no aprobadas deshabilitadas), asunto y cuerpo (solo email), variables; audiencia estimada con supresión; vista previa con contacto de ejemplo elegible | Idem; solo campañas en Borrador |
| `#/marketing/campanas/<CMP-…>` | `/marketing/campanas/[code]` | Flujo de estados; acciones según estado; si enviada: KPIs, embudo, conversiones atribuidas (reservas/leads con enlace), mensaje, destinatarios con estado y «Registrar baja»; resumen (segmento, código, UTM, programación, pruebas); audiencia (snapshot al envío o estimación actual con lista de suprimidos); historial | Idem |
| `#/crm/metas` (Manager/Admin) | `/crm/metas` | Selector de período (mes/trimestre) y asesor, «Nueva meta»; KPIs (meta asesores, real, proyección, asesores en riesgo, bonos estimados); tabla de metas del período (meta, real, avance, proyección, reservas, margen, conversión, estado, Ajustar); ranking con montos; metas de sede/equipo con cobertura; historial de metas cerradas/por cerrar | Tenant Admin, Manager |
| `#/crm/metas` (Ventas) | `/crm/metas` | Solo su meta del período: KPIs (cumplimiento, proyección, días, bono estimado), 4 indicadores con barra y proyección, reservas propias que suman (con margen propio), pipeline propio del período, ranking (posición y % sin montos de otros), bono por tramos, otros períodos propios | Ventas (`crm.targets.view`) |
| `#/crm/metas/<MET-…>` | `/crm/metas/[code]` | Flujo Borrador → Vigente → Cerrada; indicadores; reservas y pipeline que componen el avance; historial de ajustes; definición; bono y comisión vinculada (enlace a Comisiones si tiene `finance.commissions`); ranking; acciones Activar, Anular, Ajustar, Cerrar período | Manager/Admin: todas; Ventas: solo las propias no borrador (otra → pantalla «fuera de su cartera») |

---

## 3. Modelo de datos PostgreSQL

Todas las tablas: `tenant_id uuid not null` FK `tenants(id)`, RLS `tenant_isolation`, `created_by/updated_by uuid`, `created_at/updated_at timestamptz`. `id uuid` v7.

### 3.1 `mkt_contacts_subscribers` (suscriptores del newsletter; `DB.subscribers`)
| columna | tipo | null | notas |
|---|---|---|---|
| code | varchar(20) | no | `SUB-0001`, UNIQUE (tenant_id, code) |
| full_name | varchar(160) | no | |
| email | citext | no | UNIQUE (tenant_id, email) |
| phone | varchar(30) | sí | E.164 normalizado en `phone_e164` |
| phone_e164 | varchar(20) | sí | calculado; índice |
| country | varchar(60) | sí | |
| interest_destination | varchar(120) | sí | texto libre del formulario |
| source | varchar(60) | no | Newsletter web, Pop-up del blog, Feria… |
| client_id | uuid | sí | FK `crm_clients` al convertirse (el suscriptor deja de listarse como contacto separado) |
| lead_id | uuid | sí | FK `crm_leads` |
| double_opt_in_at | timestamptz | sí | confirmación del doble opt-in |
| deleted_at | timestamptz | sí | supresión por derecho de cancelación (ARCO) |

### 3.2 `mkt_consents` (`DB.consents`) — un registro vigente por titular y canal
| columna | tipo | null | notas |
|---|---|---|---|
| code | varchar(20) | no | `CNS-0001` |
| subject_type | varchar(20) | no | CHECK IN ('client','lead','subscriber') |
| subject_id | uuid | no | polimórfico; índice (tenant_id, subject_type, subject_id) |
| channel | varchar(20) | no | CHECK IN ('email','whatsapp') (extensible: sms, push) |
| status | varchar(20) | no | CHECK IN ('otorgado','revocado') |
| granted_at | timestamptz | no | |
| source | varchar(120) | no | catálogo de orígenes (§5 MKT-BR-11) |
| evidence | text | no | IP, ID de formulario, grabación, ficha, wamid |
| policy_version | varchar(20) | no | `PDP-2026.1` (versión de la política informada) |
| revoked_at | timestamptz | sí | |
| revoke_source | varchar(120) | sí | |
| revoke_campaign_id | uuid | sí | FK `mkt_campaigns` (baja desde un envío) |
| lock_version | int | no | default 0 |

Restricciones: `UNIQUE (tenant_id, subject_type, subject_id, channel)`; `CHECK ((status='revocado') = (revoked_at IS NOT NULL))`. Índice parcial `(tenant_id, channel) WHERE status='otorgado'`.

### 3.3 `mkt_consent_events` (historial inmutable; `consents[].history`)
| columna | tipo | null | notas |
|---|---|---|---|
| consent_id | uuid | no | FK `mkt_consents` ON DELETE RESTRICT |
| event | varchar(20) | no | CHECK IN ('otorgado','revocado') |
| source | varchar(120) | no | |
| evidence | text | sí | |
| actor_id | uuid | sí | NULL = sistema/titular (enlace público) |
| occurred_at | timestamptz | no | índice |
| ip | inet | sí | |
Solo INSERT (trigger que rechaza UPDATE/DELETE). Evidencia ante la ANPD.

### 3.4 `mkt_segments` (`DB.segments`)
| columna | tipo | null | notas |
|---|---|---|---|
| code | varchar(20) | no | `SEG-001`, UNIQUE |
| name | varchar(160) | no | UNIQUE (tenant_id, lower(name)) |
| description | text | sí | |
| match | varchar(5) | no | CHECK IN ('all','any') |
| rules | jsonb | no | array `[{f, op, v}]` validado contra el catálogo §5 MKT-BR-13; índice GIN no necesario |
| owner_id | uuid | no | FK `iam_users` |
| deleted_at | timestamptz | sí | |

### 3.5 `mkt_templates` (`DB.mktTemplates`)
| columna | tipo | null | notas |
|---|---|---|---|
| code | varchar(20) | no | `TPL-01` |
| name | varchar(120) | no | WhatsApp: `^[a-z0-9_]+$` (formato Meta) |
| channel | varchar(20) | no | CHECK IN ('email','whatsapp') |
| category | varchar(20) | no | CHECK IN ('MARKETING','UTILITY','SERVICIO') |
| status | varchar(20) | no | CHECK IN ('activa','en_revision','aprobada','rechazada') (email: activa; WA: en_revision/aprobada/rechazada) |
| provider_template_id | varchar(60) | sí | ID de Meta (`wa_tpl_…`) |
| language | varchar(10) | no | default 'es' |
| subject | varchar(200) | sí | obligatorio si email |
| body | text | no | variables `{{nombre}} {{destino}} {{asesor}} {{codigo_promo}} {{fecha_viaje}} {{agencia}} {{enlace_baja}}` |
| reviewed_at | timestamptz | sí | respuesta de Meta |

### 3.6 `mkt_campaigns` (`DB.campaigns`)
| columna | tipo | null | notas |
|---|---|---|---|
| code | varchar(20) | no | `CMP-0014` (sys_sequences) |
| name | varchar(160) | no | |
| channel | varchar(20) | no | CHECK IN ('email','whatsapp') |
| segment_id | uuid | no | FK `mkt_segments` RESTRICT |
| template_id | uuid | no | FK `mkt_templates` |
| subject | varchar(200) | sí | copia editable (email) |
| body | text | no | copia editable (email) / copia de la plantilla (WA) |
| promotion_id | uuid | sí | FK `catalog_promotions` (código vinculado) |
| promo_code | varchar(20) | sí | desnormalizado para atribución |
| utm | varchar(200) | no | `utm_source=…&utm_medium=…&utm_campaign=cmp-0014` |
| status | varchar(20) | no | CHECK IN ('borrador','programada','enviando','enviada','cancelada'); índice (tenant_id, status) |
| scheduled_at | timestamptz | sí | |
| sent_at | timestamptz | sí | inicio del envío |
| completed_at | timestamptz | sí | |
| cancel_reason | text | sí | |
| audience_snapshot | jsonb | sí | `{matched, eligible, optout, nodata, consent, fatigue, at}` congelado al enviar |
| owner_id | uuid | no | FK `iam_users` |
| lock_version | int | no | default 0 |
Índices: (tenant_id, scheduled_at) WHERE status='programada'; (tenant_id, sent_at desc).

### 3.7 `mkt_campaign_tests` (`campaigns[].tests`)
| columna | tipo | null | notas |
|---|---|---|---|
| campaign_id | uuid | no | FK |
| to_user_id | uuid | no | FK `iam_users` (solo usuarios internos activos) |
| to_address | varchar(160) | no | |
| sample_subject_type / sample_subject_id | varchar(20) / uuid | sí | contacto usado para variables |
| sent_at | timestamptz | no | |
Se invalidan (DELETE lógico `invalidated_at`) al editar el contenido.

### 3.8 `mkt_campaign_recipients` (`campaigns[].recipients`)
| columna | tipo | null | notas |
|---|---|---|---|
| campaign_id | uuid | no | FK; índice |
| subject_type / subject_id | varchar(20) / uuid | no | UNIQUE (campaign_id, subject_type, subject_id) |
| address | varchar(160) | no | email o E.164 |
| provider_message_id | varchar(120) | sí | id del ESP / `wamid` |
| status | varchar(20) | no | CHECK IN ('pendiente','enviado','entregado','rebotado','fallido') |
| delivered_at, opened_at, clicked_at, replied_at, unsubscribed_at | timestamptz | sí | eventos de webhook (apertura = lectura en WA) |
| error | text | sí | |
Índice (tenant_id, subject_type, subject_id, campaign_id) para fatiga y last-touch.

### 3.9 `mkt_campaign_suppressions`
| columna | tipo | null | notas |
|---|---|---|---|
| campaign_id | uuid | no | FK |
| subject_type / subject_id | varchar(20) / uuid | no | |
| reason | varchar(20) | no | CHECK IN ('optout','nodata','consent','fatigue') |
Evidencia de que no se envió a quien no correspondía.

### 3.10 `mkt_attributions` (read model recalculado por job; en el prototipo se calcula en vivo)
| columna | tipo | null | notas |
|---|---|---|---|
| campaign_id | uuid | no | FK |
| kind | varchar(20) | no | CHECK IN ('reservation','lead','promo_use') |
| reservation_id / lead_id / ecom_order_id | uuid | sí | uno obligatorio según kind (CHECK) |
| revenue_pen | numeric(14,2) | sí | total reserva × fx de la reserva (regla 15) |
| attributed_at | timestamptz | no | |
UNIQUE (tenant_id, kind, reservation_id) y (tenant_id, kind, lead_id): una conversión se atribuye a una sola campaña.

### 3.11 `mkt_automations` (`DB.automations`)
| columna | tipo | null | notas |
|---|---|---|---|
| code | varchar(20) | no | `AUT-01` |
| key | varchar(30) | no | CHECK IN ('welcome','cart','pretrip','survey','birthday','reactivation'); UNIQUE (tenant_id, key) |
| name | varchar(120) | no | |
| trigger_desc | varchar(200) | no | |
| delay_desc | varchar(80) | no | (en producción: `delay_minutes int` + `schedule_cron` según tipo) |
| channel | varchar(20) | no | |
| template_id | uuid | no | FK |
| purpose | varchar(20) | no | CHECK IN ('comercial','servicio') |
| status | varchar(20) | no | CHECK IN ('activa','pausada') |
| sent_total, suppressed_total, conversions_total | int | no | default 0 |

### 3.12 `mkt_automation_runs` y `mkt_automation_enrollments`
| tabla | columnas clave |
|---|---|
| `mkt_automation_runs` | automation_id FK, started_at, finished_at, queued int, sent int, suppressed int, triggered_by (NULL = scheduler) |
| `mkt_automation_enrollments` (`automations[].done`) | automation_id FK, subject_type, subject_id, ref_type ('subscriber','ecom_order','reservation','client'), ref_id, status CHECK IN ('enviado','suprimido'), reason, processed_at; UNIQUE (automation_id, subject_type, subject_id, ref_type, ref_id) → idempotencia: nunca se envía dos veces el mismo disparador |

### 3.13 `crm_sales_teams` y `crm_sales_team_members` (`DB.salesTeams`)
| tabla | columnas |
|---|---|
| `crm_sales_teams` | code `EQ-01`, name, leader_id FK `iam_users` |
| `crm_sales_team_members` | team_id FK, user_id FK, valid_from date, valid_to date null; UNIQUE (team_id, user_id, valid_from) |

### 3.14 `crm_sales_targets` (`DB.salesTargets`)
| columna | tipo | null | notas |
|---|---|---|---|
| code | varchar(20) | no | `MET-0018` |
| scope_type | varchar(10) | no | CHECK IN ('asesor','sede','equipo') |
| scope_id | uuid | no | FK lógica a `iam_users` / `iam_branches` / `crm_sales_teams` (validada en servicio) |
| period_type | varchar(10) | no | CHECK IN ('mes','trimestre') |
| period_key | varchar(7) | no | `2026-09` o `2026-Q3`; CHECK formato |
| period_from / period_to | date | no | calculados |
| goal_sales_pen | numeric(14,2) | no | CHECK > 0 |
| goal_bookings | int | sí | CHECK ≥ 0 |
| goal_margin_pen | numeric(14,2) | sí | |
| goal_conversion_pct | numeric(5,2) | sí | CHECK 0–100 |
| bonus_amount | numeric(14,2) | sí | solo scope_type='asesor' (CHECK) |
| bonus_currency | char(3) | sí | 'PEN' |
| status | varchar(20) | no | CHECK IN ('borrador','vigente','cerrada','anulada') |
| result | jsonb | sí | `{sales, bookings, margin, conversion, reservation_ids[], closed_at, closed_by}` congelado al cierre |
| bonus_commission_id | uuid | sí | FK `fin_commissions` |
| lock_version | int | no | |
Restricción: índice único parcial `UNIQUE (tenant_id, scope_type, scope_id, period_key) WHERE status <> 'anulada'` (MET-BR-01). Índices (tenant_id, period_key), (tenant_id, scope_type, scope_id).

### 3.15 `crm_sales_target_adjustments` (`salesTargets[].history`)
| columna | tipo | null | notas |
|---|---|---|---|
| target_id | uuid | no | FK |
| action | varchar(20) | no | CHECK IN ('crear','activar','ajustar','anular','cerrar','bono') |
| before / after | jsonb | sí | metas antes/después |
| reason | text | sí | obligatorio si action='ajustar' o 'anular' (CHECK) |
| actor_id | uuid | no | |
| occurred_at | timestamptz | no | |

### 3.16 `crm_sales_target_policies` (`DB.salesTargetPolicy`, 1 fila por tenant)
| columna | tipo | notas |
|---|---|---|
| tiers | jsonb | `[{min:90,pay:50},{min:100,pay:100},{min:120,pay:130}]` |
| primary_metric | varchar(20) | 'sales' |
| lock_decrease_after_pct | int | 50 |
| attribution_window_days | int | 30 (compartido con marketing) |

### 3.17 Cambios en tablas de otros módulos
| tabla | cambio |
|---|---|
| `catalog_promotions` (02) | + `campaign_id uuid null` FK `mkt_campaigns` (código creado desde el editor de campaña) |
| `fin_commissions` (07) | `reservation_id` pasa a NULLABLE; + `sales_target_id uuid null` FK; `kind` admite 'Bono por meta'; CHECK `(kind='Bono por meta') = (sales_target_id IS NOT NULL)`; UNIQUE (tenant_id, sales_target_id) |
| `channel_ecom_orders` (08) | + `promo_code varchar(20)`, `utm_campaign varchar(40)` para atribución |
| `crm_leads` (01) | + `utm_campaign varchar(40)` |

---

## 4. Máquinas de estado

### 4.1 Campaña
| Desde | Hacia | Acción / quién | Validaciones | Efectos |
|---|---|---|---|---|
| — | borrador | Crear (MK) | nombre, segmento, plantilla; email: asunto + `{{enlace_baja}}` | historial, auditoría `crear` |
| borrador | borrador | Editar (MK) | solo en borrador (MKT-BR-08) | invalida pruebas previas (MKT-BR-04) |
| borrador | programada | Programar (MK) | MKT-BR-03, 04, 05, 07, 09 | notificación al responsable; auditoría |
| programada | borrador | Desprogramar (MK) | — | auditoría |
| borrador/programada | enviando | Enviar ahora (MK) o scheduler al llegar `scheduled_at` | MKT-BR-03, 04, 07, 09; audiencia recalculada con supresión | snapshot de audiencia, recipients `pendiente`, suppressions; job `SendCampaign` |
| enviando | enviada | Job al procesar todos los lotes (prototipo: botón «Procesar envío (job)») | — | métricas por webhooks; notificación al responsable |
| borrador/programada | cancelada | Cancelar (MK) con motivo | — | auditoría con motivo |
| cualquiera | (nueva) borrador | Duplicar | — | copia sin pruebas ni destinatarios |
`enviada` y `cancelada` son finales e inmutables (solo métricas/atribución y bajas).

### 4.2 Consentimiento (por titular y canal)
| Desde | Hacia | Disparador |
|---|---|---|
| (sin registro) | otorgado | Formulario/checkout/newsletter con casilla, ficha firmada, llamada grabada, opt-in WhatsApp, portal, convenio; o registro manual con origen + evidencia |
| otorgado | revocado | Enlace de baja, respuesta BAJA en WhatsApp, solicitud ARCO, portal, solicitud verbal; «Registrar baja» en destinatario de campaña |
| revocado | otorgado | Solo con un **nuevo** consentimiento expreso (nuevo origen y evidencia) |
Sin borrado: los eventos quedan en `mkt_consent_events`.

### 4.3 Plantilla WhatsApp
`en_revision` → `aprobada` | `rechazada` (webhook de Meta; prototipo: «Registrar respuesta de Meta: aprobada»). Editar contenido/categoría de una `aprobada` → `en_revision`. Email: siempre `activa`.

### 4.4 Automatización
`activa` ⇄ `pausada` (MK). Activar bloqueado si la plantilla WA no está aprobada.

### 4.5 Meta comercial
| Desde | Hacia | Quién | Validaciones | Efectos |
|---|---|---|---|---|
| — | borrador / vigente | Nueva meta (TM) | MET-BR-01, 02, 03; período no terminado | si vigente: notificación Portal + Email al asesor |
| borrador | vigente | Activar (TM) | — | notificación al asesor |
| borrador | anulada | Anular (TM) con motivo | — | auditoría |
| borrador/vigente | = | Ajustar (TM) con motivo | MET-BR-04, 05 | historial antes/después; notificación si vigente |
| vigente | cerrada | Cerrar período (TM) | MET-BR-06: `period_to < hoy` | congela `result`; si asesor con bono y tramo alcanzado → comisión «Bono por meta» `calculada`; notifica a Finanzas y al asesor |
Estados derivados de visualización (no persistidos): `Por iniciar` (vigente, período futuro), `En camino` (proyección ≥ 100 %), `En riesgo` (80–99 %), `Crítico` (< 80 %), `Por cerrar` (vigente con período terminado), `Cumplida`/`No cumplida` (cerrada, ventas ≥/< 100 %).

---

## 5. Reglas de negocio

| ID | Regla | Dónde se valida |
|---|---|---|
| MKT-BR-01 | Solo se envía a contactos con consentimiento **vigente para el canal** a la fecha del envío (finalidad publicitaria = finalidad adicional, consentimiento previo, expreso e inequívoco — Ley 29733) | Service `AudienceResolver` + job (re-evaluación en cada lote) |
| MKT-BR-02 | La baja se aplica de inmediato y prevalece sobre cualquier regla o segmento; plantillas comerciales de email deben contener `{{enlace_baja}}` y las de WhatsApp indicar «Responde BAJA»; se envía confirmación de baja al titular | FormRequest plantilla/campaña + Service + webhook de respuesta WA |
| MKT-BR-03 | WhatsApp solo con plantilla aprobada por Meta; el texto no se edita en la campaña; editar una aprobada la devuelve a revisión; no se permite envío de prueba de una no aprobada | Policy + Service |
| MKT-BR-04 | Envío de prueba obligatorio (a usuario interno activo) antes de programar o enviar; editar el contenido invalida las pruebas | Service `CampaignStateMachine` |
| MKT-BR-05 | Programación ≥ 15 min en el futuro; WhatsApp comercial solo entre 08:00 y 20:00 hora del tenant (Ley 29571 art. 58 — prácticas no agresivas) | FormRequest `ScheduleCampaignRequest` |
| MKT-BR-06 | Supresión por falta de dato: email vacío; WhatsApp requiere móvil (9 dígitos iniciando en 9 en Perú o número internacional E.164). Fijos corporativos se suprimen | Service |
| MKT-BR-07 | No se programa ni envía una campaña con 0 destinatarios elegibles | Service (422 `MKT-BR-07`) |
| MKT-BR-08 | Solo se editan campañas en borrador; programada → desprogramar; enviada/cancelada inmutables | Policy + máquina de estados (409) |
| MKT-BR-09 | Código promocional único en el tenant (4–20 alfanuméricos, mayúsculas), activo y vigente al enviar | FormRequest + UNIQUE `catalog_promotions(tenant_id, code)` |
| MKT-BR-10 | Atribución last-touch 30 días: reserva (no borrador/cancelada) de un destinatario creada en (sent_at, sent_at+30 d], siempre que la campaña sea la última enviada a ese contacto antes de la reserva y que la reserva **no provenga de una cotización creada antes del envío**; leads nuevos cuyo email coincide con un destinatario; usos del código en pedidos web en la ventana. Ingreso = total × TC de la reserva (regla 15) | Job `RecomputeAttribution` (read model) |
| MKT-BR-11 | Un consentimiento exige origen (catálogo), evidencia y versión de política; no se admiten casillas pre-marcadas; los registros no se eliminan | FormRequest + trigger append-only |
| MKT-BR-12 | Un segmento usado por alguna campaña no se elimina (evidencia de audiencia); se puede duplicar | Policy (409) |
| MKT-BR-13 | Reglas de segmento permitidas: tipo de contacto (eq), segmento (eq/neq), país (eq/neq), destino de interés (contains), última compra (en ≤ N días / > N días o nunca / sin compras), gasto total S/ (≥/≤), NPS (≥/≤/sin encuesta), canal de origen (eq), etiqueta (has), consentimiento (otorgado / sin consentimiento para canal); coincidencia TODAS/ALGUNA; nombre único | FormRequest (esquema Zod espejo) |
| MKT-BR-14 | Fatiga: máximo 1 campaña por contacto cada 72 h (se suprime con motivo `fatigue`) | Service |
| MKT-BR-15 | Contactos = clientes + leads abiertos (nuevo/contactado/calificado) no duplicados por email con clientes + suscriptores no vinculados; deduplicación por email | Read model `mkt_contacts_v` (vista) |
| MKT-BR-16 | Automatizaciones comerciales (bienvenida, carrito, cumpleaños, reactivación) requieren consentimiento; las de servicio (pre-viaje, encuesta) se basan en la ejecución del contrato (Ley 29733 art. 14) pero respetan la baja | Job `RunAutomations` |
| MKT-BR-17 | Idempotencia de automatizaciones: un mismo disparador (contacto + referencia) se procesa una sola vez | UNIQUE `mkt_automation_enrollments` |
| MKT-BR-18 | Envíos de prueba solo a usuarios internos del tenant; no cuentan en métricas | Service |
| MET-BR-01 | Una meta por alcance (asesor/sede/equipo) y período, salvo anuladas | Índice único parcial + FormRequest (409) |
| MET-BR-02 | Meta de ventas obligatoria > 0; reservas, margen y conversión opcionales; conversión 0–100 % | FormRequest |
| MET-BR-03 | No se crean metas para períodos terminados | FormRequest |
| MET-BR-04 | Todo ajuste exige motivo y registra antes/después | FormRequest + tabla de ajustes + auditoría |
| MET-BR-05 | Con más del 50 % del período transcurrido, **reducir** cualquier indicador de una meta vigente requiere Tenant Admin (anti-manipulación del bono); aumentar está permitido a Manager | Policy `SalesTargetPolicy::decrease` |
| MET-BR-06 | Cierre solo después del fin del período; congela el resultado (cambios posteriores en reservas no lo alteran) | Service + `result` jsonb |
| MET-BR-07 | Ventas ve solo sus metas (no borradores). En el ranking ve posición, nombre y % de cumplimiento de los demás, **sin montos ni bonos** ajenos | Policy + Resource que omite montos para rol sales |
| MET-BR-08 | Avance real: ventas = Σ total (PEN, TC de la reserva) de reservas no borrador/canceladas **creadas** en el período del asesor (owner), de la sede (`branch_id`) o de los integrantes del equipo; margen = venta − costo de servicios no cancelados; reservas = conteo; conversión = ganadas / (ganadas + perdidas) de oportunidades con fecha de cierre en el período | Read model `crm_target_progress` |
| MET-BR-09 | Proyección al cierre = real + pipeline ponderado (Σ monto × probabilidad de oportunidades abiertas con cierre entre hoy y fin del período); margen proyectado con el ratio de margen real (22 % si no hay ventas). Se muestra además el ritmo lineal real/días × total | Read model |
| MET-BR-10 | Bono (solo asesor): tramos de la política sobre el % de ventas: ≥ 90 % → 50 %, ≥ 100 % → 100 %, ≥ 120 % → 130 % del bono base. Al cerrar, si hay monto > 0, se crea UNA comisión «Bono por meta» `calculada` (Finanzas aprueba y paga — PROC-047) | Service + UNIQUE `fin_commissions(sales_target_id)` |

---

## 6. API REST

| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| GET | `/api/v1/marketing/contacts?filter[type]&filter[consent]` | marketing.campaigns.manage | Contactos unificados con consentimiento por canal | — |
| GET | `/api/v1/marketing/segments` | idem | Lista con conteos | — |
| POST | `/api/v1/marketing/segments` | idem | Crear | — |
| GET/PUT/DELETE | `/api/v1/marketing/segments/{code}` | idem | Ver / editar / eliminar (409 MKT-BR-12) | lock_version |
| POST | `/api/v1/marketing/segments/{code}/duplicate` | idem | Duplicar | — |
| POST | `/api/v1/marketing/segments/preview` | idem | Body `{match, rules}` → `{matched, by_type, email:{…supresión}, whatsapp:{…}, sample[10]}` (conteo en vivo, debounce 300 ms) | — |
| GET/POST | `/api/v1/marketing/templates` | idem | Listar / crear (WA → en_revision y envío a Meta por adapter) | — |
| PUT | `/api/v1/marketing/templates/{code}` | idem | Editar (WA aprobada → en_revision) | lock_version |
| POST | `/api/v1/integrations/whatsapp/webhooks/templates` | firma Meta | Estado de plantilla (aprobada/rechazada) | Idempotency por event id |
| GET/POST | `/api/v1/marketing/campaigns` | idem | Listar (`filter[status]`, `filter[channel]`) / crear | — |
| GET/PUT | `/api/v1/marketing/campaigns/{code}` | idem | Ver (incluye `metrics`, `attribution`, `audience`) / editar (solo borrador) | lock_version |
| GET | `/api/v1/marketing/campaigns/{code}/audience` | idem | Audiencia estimada y suprimidos (`?at=`) | — |
| POST | `/api/v1/marketing/campaigns/{code}/test-send` | idem | `{to_user_id, sample}` | Idempotency-Key |
| POST | `/api/v1/marketing/campaigns/{code}/schedule` | idem | `{scheduled_at}` | lock_version |
| POST | `/api/v1/marketing/campaigns/{code}/unschedule` | idem | → borrador | lock_version |
| POST | `/api/v1/marketing/campaigns/{code}/send` | idem | Envío inmediato → enviando | **Idempotency-Key** + lock_version |
| POST | `/api/v1/marketing/campaigns/{code}/cancel` | idem | `{reason}` | lock_version |
| POST | `/api/v1/marketing/campaigns/{code}/duplicate` | idem | Copia en borrador | — |
| GET | `/api/v1/marketing/campaigns/{code}/recipients` | idem | Paginado, `filter[status]` | — |
| POST | `/api/v1/marketing/campaigns/{code}/recipients/{id}/unsubscribe` | idem | Baja manual desde destinatario | idempotente |
| POST | `/api/v1/integrations/email/webhooks` · `/whatsapp/webhooks/messages` | firma proveedor | delivered/opened/clicked/bounced/replied; respuesta «BAJA» → revocar | Idempotency por message event id |
| GET | `/api/v1/public/unsubscribe/{token}` · POST | público (token firmado, sin login) | Página de baja en un clic y confirmación | idempotente |
| GET | `/api/v1/marketing/automations` | idem | Con `queue` calculada | — |
| POST | `/api/v1/marketing/automations/{code}/activate` · `/pause` | idem | Cambiar estado | lock_version |
| PUT | `/api/v1/marketing/automations/{code}` | idem | Plantilla y espera | — |
| POST | `/api/v1/marketing/automations/{code}/run` | idem | Ejecución manual de la cola | Idempotency-Key + enrollments únicos |
| GET | `/api/v1/marketing/consents?filter[channel]&filter[status]` | idem | Registros y evidencia (export ARCO) | — |
| POST | `/api/v1/marketing/consents` | idem | `{subject_type, subject_id, channel, source, evidence, policy_version}` | UNIQUE → upsert |
| POST | `/api/v1/marketing/consents/{code}/revoke` | idem | `{source}` | idempotente |
| GET | `/api/v1/marketing/consents/{code}/events` | idem | Historial | — |
| GET | `/api/v1/crm/sales-targets?filter[period]&filter[scope_type]&filter[owner]=me` | crm.targets.view \| manage | Metas con `progress`, `projection`, `health`; Ventas: `owner=me` forzado en servidor, sin borradores | — |
| POST | `/api/v1/crm/sales-targets` | crm.targets.manage | Crear (`activate: bool`) | — |
| GET | `/api/v1/crm/sales-targets/{code}` | view (propia) \| manage | Detalle con reservas, pipeline, historial, bono | — |
| POST | `/api/v1/crm/sales-targets/{code}/activate` | manage | borrador → vigente | lock_version |
| POST | `/api/v1/crm/sales-targets/{code}/adjust` | manage | `{goals, bonus_amount, reason}`; 403 `MET-BR-05` si reduce y no es Tenant Admin | lock_version |
| POST | `/api/v1/crm/sales-targets/{code}/void` | manage | `{reason}` | lock_version |
| POST | `/api/v1/crm/sales-targets/{code}/close` | manage | Congela resultado y crea comisión | **Idempotency-Key** + lock_version |
| GET | `/api/v1/crm/sales-targets/ranking?period=` | view \| manage | Ranking; para Ventas sin `sales`/`goal`/`bonus` ajenos | — |
| GET/PUT | `/api/v1/crm/sales-teams`, `/api/v1/crm/sales-target-policy` | manage (policy: admin.settings.manage) | Equipos y política de bonos | — |

---

## 7. Permisos y comportamiento por rol

| Rol | Marketing | Metas |
|---|---|---|
| Tenant Admin (U01) | Todo: segmentos, plantillas, campañas (crear, probar, programar, enviar, cancelar), automatizaciones, consentimientos y bajas | Todo; único que puede **reducir** metas con > 50 % del período (MET-BR-05) |
| Manager (U02) | Igual que Tenant Admin (`marketing.campaigns.manage`) | Crear, activar, ajustar (solo aumentos tras 50 %), anular borradores, cerrar períodos; ve todas las metas con montos, ranking con montos, bonos estimados, filtro por asesor |
| Ventas (U03, U04) | Sin acceso (menú oculto; URL → 403 auditado). Los consentimientos que captan se registran desde CRM/formularios, no aquí | Solo lectura de **sus** metas vigentes/cerradas (no borradores): KPIs, indicadores, reservas propias con margen, su pipeline, bono propio, otros períodos propios; ranking con posición y % de todos, sin montos ajenos; meta ajena → «Registro fuera de su cartera». Sin botones de gestión |
| Operaciones, Finanzas, Contabilidad | Sin acceso (403) | Sin acceso a la pantalla. Finanzas ve el bono en **Comisiones** (tipo «Bono por meta») y lo aprueba/paga |
| Externos y Super Admin | Sin acceso | Sin acceso |
Frontend: los botones sin permiso se ocultan; toda acción revalida con `Auth.can` (toast «Requiere permiso …» sin modificar datos). El backend es la autoridad (regla 17).

---

## 8. Eventos, jobs (colas Redis) y notificaciones

| Evento / job | Cola | Programación | Qué hace |
|---|---|---|---|
| `DispatchScheduledCampaigns` | default | cada minuto | Campañas `programada` con `scheduled_at ≤ now` → `CampaignSending` |
| `SendCampaignBatch` (ShouldBeUnique por campaña+lote) | notifications | lotes de 200/min (rate limit ESP / Meta tier) | Recalcula consentimiento y baja por destinatario justo antes de enviar (MKT-BR-01/02), envía por adapter, guarda `provider_message_id`; reintentos backoff 1/5/15 min; al terminar → `CampaignSent` |
| Webhooks email/WhatsApp | integrations | on demand | Actualiza `recipients` (entregado/abierto/clic/respuesta/rebote); respuesta «BAJA» o enlace → `ConsentRevoked` |
| `ConsentRevoked` | notifications | evento | Confirmación de baja al titular (PROC-059), invalida caché de audiencias |
| `RecomputeAttribution` | reports | cada hora y en `ReservationCreated`/`LeadCreated`/`EcomOrderPaid` | Recalcula `mkt_attributions` (MKT-BR-10) |
| `RunAutomations` | notifications | cada 15 min (reactivación: lunes 08:00; cumpleaños: diario 09:00) | Calcula colas: suscriptores ≤ 7 días; pedidos web en pago pendiente > 1 h; reservas confirmadas/pend. pago/pend. proveedor con inicio en ≤ 3 días; completadas ≤ 7 días sin encuesta; cumpleaños en 7 días; clientes sin compra 365 días. Aplica MKT-BR-16/17, registra runs |
| Plantilla WA enviada a Meta | integrations | on demand | Adapter WhatsApp Business (INT-04) |
| `SalesTargetProgress` (read model) | reports | cada 15 min y en eventos de reserva/oportunidad | Materializa avance/proyección por meta |
| `SalesTargetsPendingClose` | low | diario 08:00 | Notifica al Manager metas vigentes con período terminado |
| `SalesTargetClosed` | critical | evento | Crea comisión «Bono por meta» (idempotente por target) → `CommissionCalculated` |
| Notificaciones | — | — | `marketing.prueba`, `marketing.programada`, `marketing.enviada`, `marketing.baja`, `marketing.auto.<key>`, `meta.asignada` (Portal + Email al asesor al activar/ajustar/cerrar), `comision.bono_meta` (Email a Finanzas) |
Tiempo real (Reverb): avance de envío (`enviando`) y conteo del constructor opcional.

---

## 9. Auditoría

| Entidad | Acciones registradas (antes → después) |
|---|---|
| Segmento | crear, editar (reglas en texto), duplicar, eliminar |
| Plantilla | crear, editar (estado), aprobacion_meta |
| Campaña | crear, editar, envio_prueba, programar, desprogramar, enviar (n.º destinatarios), envio_completado, cancelar (motivo), duplicar |
| Promoción | crear (código desde campaña) |
| Automatización | activar, pausar, configurar, ejecutar (enviados/suprimidos) |
| Consentimiento | otorgar_email / otorgar_whatsapp (origen), baja_email / baja_whatsapp (origen, campaña) |
| Meta comercial | crear, activar, ajustar (metas antes/después + motivo), anular (motivo), cerrar (% cumplimiento) |
| Comisión | calcular (Bono por meta, monto) |
| Exportación | exportar (tablas: campañas, segmentos, plantillas, consentimientos, destinatarios, metas, historial) |
| Acceso | acceso_denegado (Ventas u otros a `/marketing/*`) — núcleo |

---

## 10. Casos de prueba mínimos

| # | Tipo | Caso |
|---|---|---|
| 1 | Pest | Tenant A no ve segmentos, campañas, consentimientos ni metas del tenant B (RLS) |
| 2 | Pest | RBAC: Ventas → 403 en `/marketing/*`; Operaciones/Finanzas → 403 en `/crm/sales-targets` |
| 3 | Pest | Ventas lista metas solo propias sin borradores; GET de meta ajena → 403; ranking sin montos ajenos |
| 4 | Pest | Audiencia: contacto con baja, sin consentimiento, sin móvil y con campaña en 72 h quedan en supresiones con su motivo; evaluación histórica usa el estado a `at` |
| 5 | Pest | Programar sin envío de prueba → 422 MKT-BR-04; editar contenido invalida pruebas |
| 6 | Pest | WA con plantilla en revisión: programar, probar y enviar → 422 MKT-BR-03 |
| 7 | Pest | Programar < 15 min o WA a las 21:00 → 422 MKT-BR-05 |
| 8 | Pest | Envío idempotente: dos POST `/send` con la misma Idempotency-Key → un solo envío; transición inválida (enviada → programada) → 409 |
| 9 | Pest | Baja por respuesta «BAJA» en webhook revoca consentimiento y excluye al contacto de la campaña programada siguiente |
| 10 | Pest | Atribución: reserva de destinatario a los 10 días → atribuida; reserva desde cotización creada antes del envío → no; segunda campaña posterior → last-touch se lleva la conversión |
| 11 | Pest | Automatización ejecutada dos veces no reenvía (enrollments únicos); comercial sin consentimiento → suprimido; pre-viaje sin consentimiento pero sin baja → enviado |
| 12 | Pest | Segmento usado por campaña: DELETE → 409 MKT-BR-12 |
| 13 | Pest | Meta duplicada alcance+período → 409 MET-BR-01; período terminado → 422 |
| 14 | Pest | Manager reduce meta vigente al 97 % del período → 403 MET-BR-05; Tenant Admin → OK con ajuste auditado |
| 15 | Pest | Cerrar antes de fin de período → 422 MET-BR-06; cerrar MET con 112,9 % y bono S/ 600 → comisión «Bono por meta» S/ 600 calculada; repetir cierre → sin segunda comisión |
| 16 | Pest | Avance: ventas del asesor = Σ reservas no borrador/canceladas creadas en el período en PEN con TC de la reserva; sede por `branch_id`; equipo por integrantes vigentes |
| 17 | Playwright | Manager crea segmento (conteo en vivo cambia al agregar reglas), crea campaña email, envía prueba, envía, procesa y registra una baja; ve métricas |
| 18 | Playwright | Admin registra consentimiento de un lead con evidencia; aparece como elegible en la audiencia |
| 19 | Playwright | Manager cierra meta de agosto de U03 → Finanzas (U07) ve la comisión y la aprueba |
| 20 | Playwright | U03 abre Metas: ve «Su posición: 3 de 3», sin montos de otros; `/crm/metas/MET-0007` → «fuera de su cartera»; `/marketing/campanas` → 403. Responsive 400 px sin scroll horizontal |

---

## 11. Cambios y decisiones respecto a la documentación inicial

**Módulo nuevo: no estaba en la documentación inicial; motivo:** la documentación solo enunciaba PROC-010 (Fidelización: segmentación → acción comercial) y PROC-059 (notificaciones transaccionales), sin herramientas de campañas, ni gestión de consentimiento, ni metas de venta. El cliente pidió completar el SaaS; un CRM turístico de mercado (HubSpot, Salesforce Marketing Cloud, Lemax CRM) incluye campañas segmentadas con cumplimiento legal y metas/cuotas por vendedor. En Perú, enviar publicidad sin consentimiento expone al tenant a sanciones de la ANPD (Ley 29733) e Indecopi (Ley 29571 art. 58), por lo que el consentimiento se modela como entidad de primer nivel.

| Tipo | Cambio | Motivo |
|---|---|---|
| Agregado | Segmentos dinámicos evaluados al envío (no listas estáticas) | Evita enviar a quien revocó tras crear la lista; concreta PROC-010 |
| Agregado | Contacto unificado (clientes + leads abiertos + suscriptores) con deduplicación por email; entidad suscriptor | El newsletter capta contactos que aún no son leads |
| Agregado | Consentimiento por canal con origen, evidencia, versión de política e historial append-only | Ley 29733: carga de la prueba del consentimiento en el responsable |
| Agregado | Supresión con motivo y snapshot de audiencia por campaña | Demostrar cumplimiento ante un reclamo |
| Agregado | Plantillas con estado de aprobación Meta; WhatsApp no editable en campaña | Política de WhatsApp Business API (INT-04 en sandbox) |
| Agregado | Fatiga 72 h y horario WhatsApp 08:00–20:00 | Buenas prácticas y Ley 29571 |
| Agregado | Atribución last-touch 30 días excluyendo negocios ya iniciados | Evita atribuir a un newsletter ventas corporativas en curso (caso real en datos: RES-2026-000348) |
| Agregado | 6 automatizaciones con finalidad comercial vs servicio | Encuesta post-viaje complementa PROC-009; pre-viaje complementa el evento «inicio de servicio» de PROC-059 |
| Precisado | PROC-059: se reutilizan los adaptadores y el log de notificaciones para envíos de marketing y confirmación de baja | Regla 18/20: un solo motor de envío |
| Agregado | Metas por asesor/sede/equipo, mensual/trimestral, con 4 indicadores, proyección con pipeline ponderado y ranking | Gestión comercial; la documentación solo tenía dashboards |
| Agregado | Bloqueo de reducción de metas tras 50 % del período salvo Tenant Admin | Evita manipular el bono |
| Agregado | Bono por tramos → comisión «Bono por meta» en PROC-047 (Finanzas aprueba y paga) | Un solo circuito de pago de variables; `fin_commissions.reservation_id` pasa a nullable |
| Decisión | Ranking para Ventas: posición, nombre y % sin montos ni bonos ajenos | La transparencia del % motiva (práctica de leaderboards CRM) y los montos revelan tamaño de cartera y remuneración variable, información sensible entre pares |
| Decisión | Resultado congelado al cierre | Las anulaciones posteriores no deben alterar un bono ya liquidado; los ajustes van al período siguiente |
| Cambiado | Menú: nuevo grupo «Marketing» y entrada «Metas comerciales» en CRM | Registrado en `NAV` del núcleo |

---

## 12. Supuestos y puntos a validar con negocio

| # | Punto |
|---|---|
| 1 | Proveedor de email (ESP) y cuenta de WhatsApp Business (tier de mensajes/día, costo por conversación de marketing) — define tamaño de lote y costos a trasladar al plan SaaS |
| 2 | Texto y versión de la política de privacidad por tenant, e inscripción del banco de datos «Clientes y prospectos» ante la ANPD (responsabilidad del tenant; el SaaS es encargado de tratamiento) |
| 3 | Si los consentimientos del portal del cliente, checkout y formulario web se capturan con casilla independiente por canal (supuesto del prototipo) |
| 4 | Ventana de atribución (30 días) y modelo (last-touch) — ¿se requiere multi-touch o por código únicamente? |
| 5 | Límite de fatiga (72 h) y horario de WhatsApp configurables por tenant |
| 6 | Base de la meta de ventas: el prototipo usa **total con impuestos** de reservas creadas en el período; alternativa: venta neta sin IGV o por fecha de viaje |
| 7 | Tramos y monto del bono (política única por tenant) y si el bono exige un mínimo de margen |
| 8 | Si las metas de sede consideran reservas por sede de la reserva (supuesto) o por sede del asesor |
| 9 | Quién aprueba metas: hoy basta el Manager; ¿se requiere aprobación del Tenant Admin (bandeja de aprobaciones)? |
| 10 | Tratamiento de campañas a menores (viajes de promoción escolar): contactar solo a padres/docentes con consentimiento propio |
| 11 | Datos demo: los resultados de julio/agosto cerrados son snapshots (historial fuera de la muestra de reservas del prototipo); los períodos vigentes se calculan en vivo con `DB.reservations` |