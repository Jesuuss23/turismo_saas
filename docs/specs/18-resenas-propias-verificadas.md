# Reseñas propias verificadas (v2.2)

Módulo del prototipo: `proto/assets/js/modules/resenas.js` (dueño de `DB.ownReviews`, `DB.ownReviewRequests`, `DB.ownReviewSettings`; expone `window.OwnReviews`). Consumidores: `canales.js` (portal del pasajero, sección «Califica tu viaje») y `sitio.js` (tarjetas, ficha, inicio y JSON-LD). Regla que lo gobierna: **R-OWN-REVIEWS** (grupo Comunicación, plan mínimo Professional, `productOverride: true`, parámetros `daysAfter`, `minStarsAutoPublish`, `moderation`).

Todo lo marcado «ejemplo (demo)» / «Demo — editable» son datos de prueba (12 reseñas, 6 solicitudes y los ajustes de moderación) que se reemplazan por los reales en producción.

## 1. Propósito y procesos cubiertos (PROC-xxx)

| Proceso | Cobertura |
|---|---|
| PROC-060 Postventa y fidelización | Solicitud de reseña N días después del viaje, recepción, moderación, respuesta pública, uso en el sitio |
| PROC-052 Portal del pasajero | Bloque «Califica tu viaje» (estrellas, subcalificaciones, título, texto, fotos, idioma, consentimiento) y «Mis reseñas» con respuesta de la agencia |
| PROC-059 Notificaciones | Invitación, aviso de nueva reseña al equipo, respuesta al pasajero |
| Sitio web público (esp. 15) | Calificación propia con sello «Viaje verificado», lista con filtro por estrellas, datos estructurados `AggregateRating`/`Review` |

Objetivo: tener una fuente de reseñas **propia y verificada** (solo opina quien tiene una reserva realizada) que conviva con las reseñas externas (esp. 17, `canales/resenas`), sin depender de APIs de terceros.

## 2. Pantallas

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/canales/resenas-propias` | `/canales/resenas-propias` | Encabezado con «Regla R-OWN-REVIEWS» (→ `admin/reglas`), «Reseñas externas», «Exportar al widget / sitio». Aviso «Demo — editable». KPIs: calificación promedio, reseñas publicadas (y recibidas), % recomendación (4–5★), pendientes de moderar (y con alertas). Pestañas **Resumen** (gráfico de distribución de estrellas, promedio por producto, promedio por subcalificación, últimas publicadas), **Bandeja de moderación** (pendientes con Publicar / Rechazar con motivo / Ocultar con motivo / Responder / Detalle), **Todas las reseñas** (tabla con búsqueda, filtros estado/estrellas/producto, exportación CSV; clic → drawer con historial de moderación), **Solicitudes enviadas** (tabla; Enviar ahora / Reenviar / Cancelar; «Programar invitaciones pendientes»; vista previa), **Reglas y ajustes** (parámetros de la regla, reglas de moderación, subcalificaciones activas, publicación sitio/widget, texto de invitación). Si la regla está apagada o el plan no la incluye: solo un aviso explicativo | Tenant Admin, Manager (`channels.ecommerce.manage`), Ventas (`crm.clients.manage`, solo lectura de su cartera) |
| `#/canales/resenas-propias/{RVO-id}` | `/canales/resenas-propias/[code]` | Abre el drawer de la reseña (si es de otra cartera para Ventas: toast de bloqueo) | Igual |
| `#/canales/portal-pasajero/resena` | `/(portal-pasajero)/resena` | «Califica tu viaje»: formulario y «Mis reseñas». En Mi viaje aparece un aviso «Califica tu viaje» con el número de servicios pendientes de calificar | Pasajero (su reserva); internos con vista previa del portal |
| `#/sitio/tour/{slug}` | `/tours/[slug]` | Bloque «Reseñas de viajeros verificados» (promedio, distribución, subcalificaciones, filtro por estrellas, «Ver las N reseñas», respuesta de la agencia) antes del bloque de reseñas externas; chip «Viaje verificado · 4.5 (n)» en la cabecera; JSON-LD | Público |
| `#/sitio` y listados | `/`, `/tours` … | Tarjeta: calificación propia con sello (si hay reseñas publicadas; si no, la calificación de catálogo). Inicio: sección «Opiniones de viajeros verificados» (3 últimas) | Público |

## 3. Modelo de datos PostgreSQL

### 3.1 `crm_own_reviews`

| Columna | Tipo | Null | Notas / FK / índice |
|---|---|---|---|
| id | uuid | no | PK (UUID v7) |
| tenant_id | uuid | no | FK `tenants`, RLS |
| code | varchar(30) | no | `RVO-0001`; `UNIQUE (tenant_id, code)` |
| reservation_id | uuid | no | FK `booking_reservations` (verificación) |
| product_id | uuid | no | FK `catalog_products` |
| client_id | uuid | no | FK `crm_clients` |
| passenger_id | uuid | sí | FK `booking_passengers` (quien escribe) |
| author_display | varchar(80) | no | Nombre abreviado visible («Thomas K.») |
| author_name | varchar(160) | no | Nombre completo (no se publica) |
| country | varchar(60) | sí | |
| travel_date | date | no | Fecha del último servicio del producto calificado |
| stars | smallint | no | CHECK 1–5 |
| subratings | jsonb | no | `{guia, transporte, alojamiento, organizacion, valor}` valores 1–5 opcionales; CHECK por clave en FormRequest |
| title | varchar(120) | no | |
| body | text | no | 20–2000 caracteres |
| photos | jsonb | no | `[{path, name}]` (S3 `tenants/{t}/reviews/{uuid}.jpg`); prototipo guarda solo nombres |
| lang | char(2) | no | CHECK IN ('es','en','de','fr','pt') |
| verified | boolean | no | default true; siempre true si viene de reserva |
| consent_publish | boolean | no | Sin consentimiento → `oculta` y no publicable |
| status | varchar(20) | no | CHECK IN ('pendiente','publicada','rechazada','oculta') |
| moderation_reason | text | sí | Motivo de retención, rechazo u ocultamiento |
| reply_text | text | sí | Respuesta pública |
| reply_by | uuid | sí | FK `iam_users` |
| replied_at | timestamptz | sí | |
| helpful_count | integer | no | default 0 |
| source | varchar(10) | no | CHECK IN ('portal','email','widget') |
| request_id | uuid | sí | FK `crm_review_requests` |
| is_demo | boolean | no | default false (semilla) |
| published_at | timestamptz | sí | |
| moderated_by | uuid | sí | FK `iam_users` |
| created_at, updated_at, created_by, updated_by, deleted_at, lock_version | — | — | Convenciones §2 |

Restricciones e índices: `UNIQUE (tenant_id, reservation_id, product_id, passenger_id) WHERE deleted_at IS NULL` (una reseña por pasajero y producto de la reserva); índices `(tenant_id, status)`, `(tenant_id, product_id, status, published_at desc)` (sitio), `(tenant_id, client_id)` (alcance Ventas), GIN en `subratings` no necesario. CHECK `status <> 'publicada' OR consent_publish`.

### 3.2 `crm_own_review_log` (historial de moderación)
`id, tenant_id, review_id FK, action varchar(30) CHECK IN ('crear','publicar','rechazar','ocultar','responder','editar_respuesta','autopublicar'), note text, user_id uuid null, created_at`. Prototipo: `rv.log[{ts, u, m}]`.

### 3.3 `crm_review_requests`

| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id, tenant_id, code | uuid, uuid, varchar(30) | no | `SRV-0001`, único por tenant |
| reservation_id | uuid | no | FK; `UNIQUE (tenant_id, reservation_id)` |
| client_id | uuid | no | FK |
| to_address | varchar(160) | no | correo o teléfono |
| channel | varchar(10) | no | CHECK IN ('email','whatsapp') |
| lang | char(2) | no | |
| token_hash | char(64) | no | Enlace firmado de un solo uso a `/resena/{code}?t=` |
| scheduled_at, sent_at, opened_at, reminded_at, expires_at | timestamptz | sí | |
| status | varchar(15) | no | CHECK IN ('programada','enviada','abierta','respondida','vencida','cancelada') |
| is_demo | boolean | no | |

Prototipo: `DB.ownReviewRequests[{id, resId, clientId, to, channel, lang, scheduledAt, sentAt, openedAt, status, reviewIds[]}]`.

### 3.4 Ajustes (`sys_settings.own_reviews`, jsonb por tenant)
`{auto_publish bool, banned_words text[], personal_data 'retener'|'enmascarar', active_subratings text[], show_on_site bool, show_on_widget bool, invite:{channel, reminder_days, expire_days, subject, body}, last_export:{at, by, count}, is_demo bool}`. Los parámetros `daysAfter`, `minStarsAutoPublish`, `moderation` viven en la regla (`booking_rules.params`, esp. 17). Prototipo: `DB.ownReviewSettings`.

## 4. Máquinas de estado

Reseña:

| Desde | Hacia | Quién | Efecto |
|---|---|---|---|
| (nuevo) | pendiente | Pasajero (portal/app/enlace) | Por defecto; o si hay alertas (palabras prohibidas, datos personales) |
| (nuevo) | publicada | Sistema | Autopublicación: consentimiento ∧ sin alertas ∧ `stars ≥ minStarsAutoPublish` ∧ (`moderation = false` ∨ `auto_publish = true`) |
| (nuevo) | oculta | Sistema | Sin consentimiento de publicación (solo uso interno) |
| pendiente / oculta / rechazada | publicada | Moderador | Requiere consentimiento; con alertas pide confirmación explícita |
| pendiente / publicada / oculta | rechazada | Moderador | Motivo obligatorio |
| pendiente / publicada / rechazada | oculta | Moderador | Motivo obligatorio; se conserva para reportes |

Responder / editar respuesta no cambia el estado. Solicitud: `programada → enviada → abierta → respondida`; `programada → cancelada`; `enviada|abierta → vencida` (job al `expires_at`); `vencida|enviada → enviada` (reenviar).

## 5. Reglas de negocio

| ID | Regla | Dónde |
|---|---|---|
| RVW-01 | Todo el módulo exige `effective(R-OWN-REVIEWS)` = regla activa ∧ plan ≥ Professional ∧ no desactivada en el producto. Si no: sin formulario, sin bloque en el sitio, sin JSON-LD, sin invitaciones; los datos se conservan | Policy + Service (`BE.on`) |
| RVW-02 | Verificación: solo reseñan pasajeros de una reserva `completada`, o de una `en_operacion` por los servicios con estado `completado` o fecha pasada (demo: Thomas Keller, RES-2026-000347, hasta el 02/10). Reservas `cancelada`/`borrador` no | Service |
| RVW-03 | Una reseña por pasajero, reserva y producto | constraint DB + FormRequest |
| RVW-04 | Estrellas 1–5 obligatorias; subcalificaciones opcionales y solo las activas; título ≥ 3; texto 20–2000 | FormRequest |
| RVW-05 | Publicación exige consentimiento explícito (casilla sin marcar por defecto); sin consentimiento → `oculta` | Service + CHECK |
| RVW-06 | Palabras prohibidas y datos personales (correo, teléfono, pasaporte/DNI) retienen la reseña con motivo; si `personal_data = enmascarar` se reemplazan por «[dato oculto]» antes de evaluar | Service |
| RVW-07 | Sin «review gating»: la nota nunca es motivo de rechazo; rechazar/ocultar exige motivo | Policy + UI |
| RVW-08 | La respuesta pública no puede contener datos personales; se notifica al pasajero y se deja en sus mensajes del portal | FormRequest + Listener |
| RVW-09 | Invitación `daysAfter` días después de `travel_to` a las 09:00, recordatorio y vencimiento configurables; a todos los elegibles, sin incentivos | Job |
| RVW-10 | Sitio/widget muestran solo `publicada`, con sello «Viaje verificado» y rótulo «ejemplo (demo)» si `is_demo`; `show_on_site/show_on_widget` apagan cada salida | Query + cache |
| RVW-11 | Ventas: solo reseñas de reservas propias o clientes de su cartera; no modera ni responde | Policy (`owner=me`) |

## 6. API REST

| Método | Endpoint | Permiso | Descripción | Idem./lock |
|---|---|---|---|---|
| GET | `/api/v1/reviews` | channels.ecommerce.manage \| crm.clients.manage | Lista (filtros status, stars, product, `owner=me` forzado para Ventas) | — |
| GET | `/api/v1/reviews/stats` | idem | KPIs, distribución, por producto y subcalificación | — |
| GET | `/api/v1/reviews/{code}` | idem + policy | Detalle + log | — |
| POST | `/api/v1/reviews/{code}/publish` | channels.ecommerce.manage | Publicar (`confirm_flags=true` si hay alertas) | lock_version |
| POST | `/api/v1/reviews/{code}/reject` | idem | `{reason}` | lock_version |
| POST | `/api/v1/reviews/{code}/hide` | idem | `{reason}` | lock_version |
| PUT | `/api/v1/reviews/{code}/reply` | idem | `{text}` crea o edita | lock_version |
| GET | `/api/v1/review-requests` | idem | Solicitudes | — |
| POST | `/api/v1/review-requests/schedule-pending` | channels.ecommerce.manage | Programa invitaciones faltantes | Idempotency-Key |
| POST | `/api/v1/review-requests/{code}/send` · `/cancel` | idem | Enviar ahora / reenviar / cancelar | — |
| GET/PATCH | `/api/v1/settings/own-reviews` | idem (params de la regla: admin.settings.manage) | Ajustes | — |
| POST | `/api/v1/reviews/export-feed` | channels.ecommerce.manage | Regenera feed del widget y cache del sitio | Idempotency-Key |
| GET | `/api/v1/passenger/reviews/eligibility` | passenger (su reserva) | Productos calificables y reseñas propias | — |
| POST | `/api/v1/passenger/reviews` | passenger | Alta (multipart con fotos) | Idempotency-Key |
| POST | `/api/v1/public/reviews/by-token` | token firmado | Alta desde el enlace del correo | Idempotency-Key |
| GET | `/api/v1/public/reviews?product=&stars=&lang=&page=` | público | Publicadas + agregado (cache 10 min, invalidada al moderar) | — |

## 7. Permisos y comportamiento por rol

| Rol | Ve | Hace |
|---|---|---|
| Tenant Admin (U01) | Todo | Moderar, responder, solicitudes, ajustes y parámetros de la regla, exportar |
| Manager (U02) | Todo | Igual que Admin salvo parámetros de la regla (requiere `admin.settings.manage`: campos deshabilitados con aviso) |
| Ventas (U03/U04) | Solo reseñas de sus reservas/clientes (`Scope.clientIds()`); aviso «Mi cartera» | Solo lectura: botones deshabilitados con tooltip; toast de bloqueo si lo intenta |
| Operaciones, Finanzas, Contabilidad | — | 403 por ACCESS |
| Pasajero (U15) | Su reserva: servicios calificables y sus reseñas con estado («En revisión», «Publicada», «No publicada», «Solo para la agencia») y respuesta | Crear reseña |
| Público | Reseñas publicadas | Filtrar por estrellas |

## 8. Eventos, jobs y notificaciones

| Evento / job | Cola | Efecto |
|---|---|---|
| `ReservationCompleted` → `ScheduleReviewRequest` | default | Crea solicitud en `travel_to + daysAfter` |
| `SendDueReviewRequests` (cada 15 min) | notifications | Envía invitaciones vencidas de programación; recordatorio a `reminder_days`; vence a `expire_days` |
| `OwnReviewSubmitted` | notifications | `Notify` a admin y asesor dueño («Nueva reseña n★…») |
| `OwnReviewPublished/Hidden/Rejected` | default | Invalida cache `public:reviews:{product}` y feed del widget |
| `OwnReviewReplied` | notifications | Correo al pasajero + mensaje en portal (`pax_messages`) |
| Prototipo | — | `Notify.push('resena.nueva'|'resena.respuesta'|'resena.invitacion', …)` |

## 9. Auditoría

`Audit.log` en el prototipo: `Reseña propia` (crear, publicar, rechazar, ocultar, responder, editar_respuesta), `Solicitud de reseña` (programar, enviar, reenviar, cancelar), `Reseñas propias` (ajustes/actualizar, feed/exportar_widget), `Regla de reserva R-OWN-REVIEWS` (parametros). Producción: `audit_logs` con antes/después y motivo.

## 10. Casos de prueba mínimos

Pest: aislamiento tenant; RVW-01 (regla apagada / plan Starter → 403 `RULE_NOT_IN_PLAN` en alta y lista pública vacía); RVW-02 (reserva ajena, cancelada, servicio futuro → 422); RVW-03 duplicado → 409; autopublicación vs. pendiente según parámetros; PII retenida y enmascarada; publicar sin consentimiento → 422; rechazar sin motivo → 422; Ventas: solo su cartera y 403 al moderar; respuesta con teléfono → 422; job de invitaciones respeta `daysAfter`.

Playwright (equivalente a `e2e_resenas.js`, 22 comprobaciones): U15 ve aviso y califica PRD-002 (5★, subcalificación, foto, consentimiento) → pendiente; reseña con correo → retenida; U03 ve solo su cartera sin acciones; U01 publica, responde, rechaza con motivo, exporta; U15 ve la respuesta; sitio anónimo a 400 px: bloque con sello, filtro por estrellas, JSON-LD, sin scroll horizontal; regla apagada y plan Starter → todo desaparece.

## 11. Cambios y decisiones respecto a la documentación inicial

- **Módulo nuevo**: no estaba en la documentación inicial. Motivo: decisión del cliente del 30/09/2026 («considera algunas plataformas de reseñas, como también su propio módulo de reseñas»).
- Convive con reseñas externas (esp. 17): las externas siguen rotuladas como ejemplo; las propias llevan sello «Viaje verificado» y, las semilla, «ejemplo (demo)».
- El `aggregateRating` del JSON-LD de la ficha (esp. 15 §11.3) se alimenta **solo** de reseñas propias verificadas (no de externas ni de la calificación de catálogo).
- Datos estructurados: `TouristTrip`+`Product` agrega `aggregateRating {@type: AggregateRating, ratingValue, reviewCount, bestRating: 5, worstRating: 1}` y `review[]` (hasta 5: `Review` con `author` Person, `datePublished`, `name`, `reviewBody`, `inLanguage`, `reviewRating` Rating). Sin reseñas publicadas no se emite.
- Moderación sin gating y consentimiento explícito para cumplir la Ley 29733 (datos personales) y las guías de Google para reseñas.
- App del pasajero (React Native): usa los mismos endpoints `passenger/reviews`; en el prototipo el formulario está en el portal (la maqueta de la app en `operaciones.js` no se modificó en esta iteración).

## 12. Supuestos y puntos a validar con negocio

- ¿Quién puede reseñar en reservas grupales o B2B (solo titular o cada pasajero)? Prototipo: cualquier pasajero de la reserva con su nombre.
- Valores definitivos de `daysAfter` (demo 1), nota mínima de autopublicación (demo 4), palabras prohibidas y texto de invitación (todos «Demo — editable»).
- ¿Publicar reseñas en idioma distinto al del sitio o traducirlas automáticamente?
- Política de fotos (moderación de imágenes, límite de tamaño) y si se muestran en el sitio.
- ¿Votos «útil» públicos en el sitio? (campo previsto, sin UI pública en el prototipo).
- Las reseñas semilla con reservas históricas (RES-2025-000212, RES-2026-000230…) no existen en `DB.reservations`; se muestran como «(histórica)».
