# Turismo SaaS — CRM/ERP para agencias de turismo

Monorepo:

| Carpeta | Qué contiene |
|---|---|
| `backend/` | API Laravel 13 (PHP 8.3): Sanctum, spatie/permission con *teams*, PostgreSQL con RLS, Redis/Horizon, Pest |
| `frontend/` | Next.js 15 (App Router) como BFF: el token vive en cookie httpOnly; el middleware pide al backend cada decisión de ruta |
| `docs/specs/` | Especificaciones funcionales (fuente de verdad). Los tests leen las tablas de `00-transversal.md` |
| `infra/db/setup.sql` | Roles de PostgreSQL (migrador, app, plataforma) |

## Estado

Implementado el núcleo transversal (spec 00): multi-tenancy con doble aislamiento (scope Eloquent + RLS que falla cerrado),
RBAC de 57 permisos × 13 roles validado contra la spec, ACCESS/ROLE_UX, login + MFA TOTP (con anti-replay y códigos de
recuperación), tokens por experiencia con expiración y revocación, auditoría append-only particionada con correlación,
correlativos atómicos, idempotencia, bloqueo optimista, motor de precios con BigDecimal, Notify después del commit,
jobs TenantAware, outbox, pantalla 403 auditada con “Solicitar acceso”, shells de back-office y externos.

**CRM (spec 01)**: leads con detección de duplicados, BANT y conversión; oportunidades (kanban y tabla); actividades
con recordatorios horarios; Clientes 360 (12 pestañas, solo lectura para Finanzas/Contabilidad); postventa (NPS, casos,
segmentación y recompra); panel de Ventas "Mi cartera"; exportación CSV auditada. Los datos de cotizaciones, reservas y
finanzas llegan por `App\Services\Crm\Linked\LinkedData` (hoy `NullLinkedData`, vacío) hasta que existan esos módulos.

**Administración, reportes y panel ejecutivo (spec 09)**: consola de plataforma (`/login/plataforma`, alta de tenant en
11 pasos con Idempotency-Key, cambio de plan con límites, suspensión/reactivación, salud por tenant y prueba de aislamiento),
usuarios con invitación (`/invitacion/{token}`) y sedes, roles personalizados y matriz de permisos editable y auditada,
mapa de acceso, configuración por secciones (guardado por diferencia y auditoría por campo, series y correlativos),
notificaciones (matriz evento × canal, plantillas versionadas, log con reintento y canal alterno), integraciones,
scheduler y errores con reintento, auditoría con filtros y exportación, reportes por rol con exportación asíncrona
(URL firmada de 5 min) y envíos programados, y panel ejecutivo de Tenant Admin y Gerencia.

**Colaboración (spec 10)**: bandeja de aprobaciones unificada (`/aprobaciones`) que refleja las excepciones de cotización,
anulación, reversión de pago, CxP, comisión y tarifa de proveedor (observadores de `OriginSync`, en ambos sentidos: decidir
en la bandeja ejecuta el mismo efecto que el módulo de origen y decidir en el origen cierra la solicitud) más las genéricas
(crédito B2B, reembolso, otra). Enruta por la matriz `collab_approval_rules` (`/aprobaciones/reglas`, editable por el Tenant
Admin) con dos niveles por umbral en soles, SLA con aviso al 75 % y vencimiento escalado a Gerencia
(`approvals:sla-check`), pedido de información que pausa el SLA, delegación, retiro y reenvío de CxP/comisión observadas.
Bandeja omnicanal (`/inbox`) con WhatsApp, email, chat web, formulario y tickets del portal: webhooks firmados e
idempotentes, reconocimiento del contacto, alcance por rol (Ventas: propias y sin asignar; Operaciones: asignadas y
pasajeros en viaje), plantillas con variables, ventana de 24 h de WhatsApp, notas internas, adjuntos con antivirus
simulado, SLA de 1.ª respuesta (`inbox:first-response-sla`) y creación de lead, actividad y cotización desde el hilo.
Decisiones de diseño: etiquetas y canales en `jsonb` (no `text[]`); la cotización conserva `quote_internal_approvals` como
registro de origen (no se agregó `approval_request_id`); los efectos se aplican en la misma transacción (no en cola); los
canales quedan en modo `sandbox` con secretos de prueba (`whatsapp-sandbox-secret`, token `email-sandbox-token`) y los SLA
de la matriz, salvo el de cotización (8 h), son valores iniciales editables. «Simular entrante» usa el mismo procesador que
los webhooks. Las plantillas se consultan en «Canales e integración»; su edición queda por API.

**Cupos y documentos de viaje (spec 11)**: contratos de cupo con hoteles, tren y entradas (`/catalogo/cupos`) que generan
una fila por fecha; grilla fecha × contrato con estados (holgado, ≥ 50 %, completo, release próximo/vencido, retenido,
sobreventa, liberado), liberación manual con versión y bloqueo por fecha, retención con exposición a penalidad,
ampliaciones al proveedor, pick-up y job `allotments:release` (JOB-10) que libera lo vencido, cierra las fechas pasadas y
devenga la penalidad por no-uso como CxP (con aprobación vía bandeja). El consumo se recalcula al confirmar, cancelar o
cambiar servicios de reserva (los solicitados quedan «en trámite» y no consumen). Documentos de viaje
(`/operaciones/documentos-viaje`): checklist por pasajero según nacionalidad, edad, destinos y vigencia mínima, carga con
antivirus simulado, validar/observar, contacto de emergencia, atención especial sin datos clínicos, solicitudes al cliente,
recordatorios `travel-docs:remind` (JOB-11), gate de entradas a Machu Picchu en el voucher (DOC-BR-010) y reglas editables.
Ventas ve solo su cartera. Adaptaciones a los datos demo: los contratos usan Hotel Inti Wasi, PeruRail y la entrada
Machu Picchu circuito 2 (no hay Tambo, Ferrovía ni Ministerio de Cultura); el hotel consume ⌈pax sin INF / 2⌉ habitaciones
por noche y tren/entradas un cupo por pax sin INF; MAPI = servicio de tipo Entrada cuyo nombre contiene «machu picchu»;
el reporte programado demo pasó a JOB-12. La sobreventa se marca y notifica (no hay modo estricto con 409).

**Tesorería y libro de reclamaciones (spec 12)**: cuentas bancarias, cajas chicas y pasarela (`/finanzas/tesoreria`) con
libro por cuenta y saldo acumulado; los cobros y pagos de Finanzas se sincronizan como movimientos según el medio de cobro
de cada cuenta. Transferencias entre monedas con TC pactado, depósitos de efectivo, liquidación de pasarela con comisión y
anulación con motivo. Cajas chicas con fondo fijo: apertura diaria, gastos con comprobante (RUC en factura/RHE, tope y
declaración jurada), arqueos por denominación con acta firmada, diferencias sobre la tolerancia aprobadas por otra persona,
reposición solicitada → aprobada (Gerencia) → pagada (Finanzas). Flujo proyectado a 90 días y diferencia de cambio por
promedio ponderado (una por cuenta y periodo, la registra Contabilidad). Jobs `treasury:cash-deposit-alerts` (JOB-12),
`treasury:open-session-check` (JOB-13) y `treasury:cash-flow-snapshot` (JOB-14). Libro de reclamaciones virtual
(`/crm/reclamaciones`): hojas como casos de Postventa con correlativo por sede (`LR-LIM-2026-000001`), plazo de 15 días
hábiles con feriados, evaluación, respuesta motivada, cierre, constancia PDF, reporte anual exportable y alertas
`claims:deadline-alerts` (JOB-15). El consumidor llena la hoja en `/libro-reclamaciones` (cliente o pasajero, con «Mis
hojas») o en el sitio público `/sitio/libro-reclamaciones`. Adaptaciones: no hay sede Arequipa, las cajas chicas son Lima y
Cusco; los correlativos usan la tabla de contadores existente (no `sys_sequences`); el captcha del sitio público es una suma
simulada; los saldos se calculan en vivo (sin vista materializada); portal del cliente y del pasajero comparten la ruta
`/libro-reclamaciones`; el tenant del formulario público va en la ruta de la API (`/public/{tenant}/claim-book`); los
feriados nacionales 2026–27 se siembran en la migración y en cada test. El reporte programado demo pasó a JOB-16 (y a JOB-20 con la spec 14).

**Plataforma SaaS (spec 13)**: suscripción del tenant (`/admin/suscripcion`) con medidores de consumo y avisos al 80/100 %,
comparativo de planes, cambio de plan con prorrateo (upgrade y paso a anual inmediatos con factura y cobro; downgrade
programado y bloqueado si el consumo o los módulos en uso no caben), add-ons, cupones no acumulables, cancelación con
encuesta y reanudación, facturas de Qhapaq Cloud (`FQ01`) con pago idempotente y PDF, tarjeta tokenizada y datos de
facturación. Entitlements por plan, add-on y override (`EnsureFeature` → 403 `FEATURE_NOT_IN_PLAN`, el menú oculta lo no
incluido) y límites hard (422 `PLAN_LIMIT_REACHED`). API pública `/api/v1/ext/*` con API keys (`qts_live_…`, solo hash en
BD, scopes, IP allowlist, rate limit por minuto y ráfaga, cuota mensual con 429 y `Retry-After`), webhooks firmados
HMAC-SHA256 con anti-SSRF, reintentos con backoff, rotación con 24 h de gracia y deshabilitado a los 10 fallos, OpenAPI 3.1 en
`/api/v1/docs/openapi.json` (`/admin/api`). Consola del Super Admin en `/admin/plataforma/facturacion`: MRR/ARR/ARPA y churn,
facturas, cobranza (D+1/D+3/D+7, suspensión y reactivación al pagar), trials, cupones (máx. 50 %), planes con
grandfathering de 30 días, matriz planes × reglas, feature flags con excepciones y uso por tenant (solo contadores). Jobs
`billing:renewals|dunning|trials|expire-cards`, `usage:consolidate`, `platform:metrics-snapshot`, `api-keys:expire`,
`webhooks:deliver|purge`. Adaptaciones: pasarela y OSE en sandbox (tarjetas terminadas en 0002/9995/0069 se rechazan;
`PLATFORM_OSE_DOWN=true` simula la caída); sin Passport (las apps OAuth son un registro de client_id/secret); los eventos de
webhook salen de observers de modelo al confirmar la transacción (sin outbox) y sin tablas particionadas; los webhooks
gestionados por API van en `/ext/webhooks`; los límites de usuarios y sedes responden 422 (antes 409) y las pruebas crean
tenants Enterprise por defecto. Pendiente: modo solo lectura tras la cancelación (el tenant conserva su estado) y diferir
WhatsApp al 100 % del límite (hoy solo avisa).

**Marketing y metas comerciales (spec 14)**: `/marketing/campanas` con pestañas Campañas (KPIs, métricas y CSV),
Segmentos (constructor con conteo en vivo, desglose por tipo y supresión por canal), Plantillas (variables, vista previa y
estado de Meta para WhatsApp), Automatizaciones (6 disparadores con cola real, idempotentes por disparador) y
Consentimientos (Ley 29733: por canal, con origen, evidencia y versión de política; historial append-only). Campañas
Borrador → Programada → Enviando → Enviada con prueba obligatoria a un usuario interno, audiencia congelada al envío
(prioridad baja > sin dato > sin consentimiento > fatiga 72 h), WhatsApp solo 08:00–20:00, lotes por job
(`SendCampaignBatch`, «Procesar envío» lo ejecuta en línea), baja de un clic en `/sitio/baja/{token}` firmado y por «BAJA» en
WhatsApp, webhooks de estados (WhatsApp y ESP con `X-Esp-Token`) y atribución last-touch de 30 días a reservas, leads y
usos del código. Metas en `/crm/metas` por asesor, sede o equipo (mes o trimestre) con 4 indicadores, proyección con pipeline
ponderado, ranking (Ventas ve posición y % sin montos ajenos), ajustes con motivo y bloqueo de reducción tras el 50 % del
período salvo Tenant Admin, y cierre que congela el resultado y crea la comisión «Bono por meta» por tramos para Finanzas.
Jobs `marketing:run dispatch-scheduled|automations|attribution|targets-pending-close` (JOB-16..19). Demo: la asesora U20
Jimena Vela (Cusco) completa el ranking; julio y setiembre son snapshots cerrados y agosto de U03 queda por cerrar al
112,9 % con reservas históricas RES-2026-000341..343. Adaptaciones: ESP y WhatsApp en sandbox (mismo motor de
notificaciones); `fin_commissions.reservation_id` pasa a nullable.

**Sitio web público (spec 15)**: Andes Horizonte Travel en `/sitio` (sin sesión): home con buscador, destinos con mejor
época derivada de temporadas, listados de tours/paquetes/experiencias con filtros y orden en la URL, ficha con calendario
de precio adulto por día y temporada (solo días vendibles para el grupo), panel de reserva con opciones y extras (seguro,
traslado), carrito multiproducto con bloqueo de cupos `HLD-…` y temporizador, código promocional, checkout en 4 pasos +
confirmación (boleta/factura con RUC, pasajeros, consentimientos, total o anticipo si faltan ≥ 15 días; tarjeta sandbox
4111… aprueba y 4000…0002 rechaza, transferencia/Yape/Plin quedan «Pago por validar»), pedido `WEB-…` que crea la reserva,
CxC de anticipo y saldo, y vouchers. Cuenta del cliente (reservas, vouchers, pagos pendientes con pago de saldo, deseos,
datos, ARCO), «Gestionar mi reserva» sin cuenta, viajes a medida y contacto «cotizar» → lead web sin asignar con SLA de 24 h
hábiles, contacto → conversación del inbox, chat web con respuesta automática, newsletter con doble opt-in, ofertas con
cuenta regresiva, blog y páginas legales desde el CMS, JSON-LD (`TravelAgency`, `Product`, `BlogPosting`), `robots.txt` y
`sitemap.xml`. El tenant sale de `PUBLIC_TENANT_SLUG` (en producción, del dominio); idioma (ES/EN) y moneda (PEN/USD con
el TC del día) van en cookies visibles y los tokens de carrito, pedido y sesión solo en cookies httpOnly del BFF de Next. API
en `/api/v1/public/…` con límites por IP: lectura 600/min, escritura 120/min, analítica 300/min y búsqueda de pedido 5/min
(detrás de otro proxy, configurar `TRUSTED_PROXIES`). Demo: PRD-011 Laguna Humantay y PRD-012 Cusco Mágico 4D/3N, traducciones
EN, contenido de 6 destinos, 2 artículos, 3 páginas legales y el pedido web de U14 (anticipo por Yape en validación); la
cuenta demo es `cliente@correo.test`. Decisiones: rutas bajo `/sitio` con idioma por cookie en vez de segmento `[locale]`;
páginas SSR sin caché (sin ISR ni revalidación por webhook todavía); el motor de reservas v2.1 (E2E-SIT-07..09: EUR, hoteles,
reglas configurables) queda fuera de este alcance; Lighthouse no se mide en CI.

**Motor de reservas y centro de reglas (spec 16, v2.1)**: pestaña «Reservas online» del Product 360
(`/catalogo/productos/{ref}/reservas[/pasajeros|adicionales|alojamiento|pago|recojo|reglas]`) con leyenda comercial
(«Disponible del 02 al 06 oct (12 cupos/día) · Ocupado del 07 al 09 oct»), calendario por día con detalle y *Cerrar fechas*,
rangos de disponibilidad `RG-…` (dos disponibles no se solapan, un cierre sí va sobre un disponible: CAT-AV-01; cierre con
ventas pide confirmación y avisa a Operaciones), días de operación y anticipación, bandas de edad con simulador, adicionales
`ADD-…`, alojamiento opcional por tramo/ciudad con hoteles y tipos de habitación, modalidades de pago (total, adelanto con
saldo, reservar y pagar después) y puntos de recojo. `/admin/reglas` lista las 31 reglas `R-…` por grupo: se activan o
desactivan por tenant, sus parámetros son los valores por defecto del tenant, cada producto puede sobrescribirlas si la regla
lo permite y el tenant puede agregar reglas propias (se guardan, pero el motor no las evalúa sin implementación). Apagar una
regla nunca borra la configuración del producto. `BookingEngine` es la única fuente de estado del día y del precio (16 pasos)
para la API pública: `GET /public/products/{id}/availability` (caché por mes con ETag), `POST /public/quote` (devuelve un
`quote_token` HMAC de 30 min) y `POST /public/bookings` (idempotente, re-cotiza y bloquea el día: AVAIL_CHANGED,
QUOTE_CHANGED). Jobs `booking:run expire-holds|min-group`. Demo: PRD-013 «Perú Circuito 10 días» con rangos relativos a hoy.
Decisiones: listas en `jsonb` con CHECK (no arrays de Postgres); el calendario interno es `booking-availability` porque
`products/{ref}/availability` ya existía; el centro está en `/api/v1/admin/booking-rules`; las reglas R-* no pasan por el
gating de plan de la spec 13; los holds del motor apuntan a la reserva (no a una salida) y lo vendido se mide por reserva.

**Centro de reglas v2.2, mensajes al pasajero, monedas, reseñas e integración web (spec 17)**: `/admin/reglas` pasa a 32 reglas
con plan mínimo DEMO (Starter 15 · Professional +14 · Enterprise +3): fuera del plan la regla queda con candado, «Disponible desde
el plan X» y «Mejorar plan» (RULE_NOT_IN_PLAN si se intenta activar); «Previsualizar como plan…» y el simulador con «Plan a simular»
son solo visuales. Pestañas Reglas (dependencias como R-LODGING-CAPACITY → R-LODGING, qué lee el motor, drawer de excepciones por
producto, validación cruzada CR-01), Simulador, Plantillas por tipo de negocio (diferencia +/−) e Historial; reglas propias
(nacen inactivas) y «Restablecer valores por defecto» con motivo. `/admin/comunicaciones`: 10 plantillas PMT-… por evento,
canal e idioma (variables `{{…}}`, MSG-01), programación por reserva con índice único (recalcular no duplica; EN si el cliente
no es de Perú), envío manual, reintentos con backoff y acuses firmados por proveedor (`POST /webhooks/providers/{ses|whatsapp|fcm}`).
`/admin/monedas` (DEMO): 10 monedas, TC al cliente = TC × (1 − margen), 30 días de historial, proveedores, umbral de alerta (FX-01
pide confirmar) y conversor; Finanzas solo edita el TC. `/canales/resenas`: Google, TripAdvisor y Trustpilot (fase 1) y Facebook,
GetYourGuide y Viator (fase posterior) por API oficial con credencial cifrada que nunca se devuelve, bandeja con respuesta o
«guardar y copiar», derivación a postventa (≤3★), invitaciones postviaje y widget de reseñas; las reseñas demo son de ejemplo.
`/canales/integracion-web`: configurador con vista previa en vivo, Web Component, iframe, shortcodes de WordPress y API headless,
dominios con verificación DNS TXT (solo esos reciben CORS: `X-Qhapaq-Key` + `Origin`), clave pública con 24 h de gracia al
regenerarla. El widget público es `/widget/{PRD}` (sin sesión) y «Continuar reserva» crea un traspaso `WHO-…` que se paga en
`/sitio/reserva/{WHO}` (re-cotiza en PEN o USD y guarda la moneda que vio el cliente). Jobs `experience:run schedule-messages|
dispatch-messages|fx-update-daily|sync-reviews|publish-replies|verify-domains|expire-handoffs`. Decisiones: las columnas de reglas
conservan `rule_key`/`group_name`/`is_system`; el traspaso vive en `channel_widget_handoffs` (no en localStorage); CORS ya no es `*`
(`CORS_ALLOWED_ORIGINS` más los dominios verificados); en entornos no productivos todo dominio verifica salvo `sin-txt.*`.

**Reseñas propias verificadas (spec 18)**: regla R-OWN-REVIEWS (Comunicación, desde Professional, parámetros `daysAfter`,
`minStarsAutoPublish` y `moderation` sí/no; el centro de reglas ahora edita parámetros booleanos). `/canales/resenas-propias`:
KPIs, Resumen, Bandeja de moderación (publicar, rechazar u ocultar con motivo, responder), Todas (filtros y CSV `reviews.own`),
Solicitudes (programar, enviar, reenviar, cancelar, vista previa) y Ajustes (palabras prohibidas, datos personales retener o
enmascarar, aspectos, sitio/widget, texto de invitación); Ventas solo lee su cartera y recibe un aviso si abre una reseña ajena.
El pasajero califica en `/canales/portal-pasajero/resena` (aviso en «Mi viaje») o por el enlace firmado `/sitio/resena/{SRV}?t=`;
solo productos de reservas completadas o con servicios ya realizados, una reseña por pasajero y producto, consentimiento
desmarcado por defecto, sin «review gating». El sitio muestra el bloque «Reseñas de viajeros verificados», la insignia «Viaje
verificado» en tarjetas, ficha e inicio, y el `aggregateRating` del JSON-LD sale solo de reseñas propias publicadas. API:
`/reviews…`, `/review-requests…`, `/settings/own-reviews`, `/portal|mobile/passenger/reviews…` y públicas
`/public/reviews/verified` y `/public/reviews/by-token` (la ruta `/public/reviews` sigue siendo la de reseñas externas de la
spec 17). Evento `ReservationCompleted` programa la invitación; job `experience:run review-requests` cada 15 min. Las 12
reseñas y 6 solicitudes de ejemplo están rotuladas «ejemplo (demo)».

Pendiente: Reverb (tiempo real) y read models materializados para reportes (hoy se calculan en vivo).

## Puesta en marcha (local)

```bash
# PostgreSQL 16 y Redis corriendo
sudo -u postgres psql -f infra/db/setup.sql
sudo -u postgres createdb -O turismo_migrator turismo
sudo -u postgres createdb -O turismo_migrator turismo_test
for db in turismo turismo_test; do
  sudo -u postgres psql -d $db -c "REVOKE ALL ON SCHEMA public FROM PUBLIC; GRANT USAGE ON SCHEMA public TO turismo_app, turismo_platform; ALTER SCHEMA public OWNER TO turismo_migrator;"
done

cd backend
composer install
cp .env.example .env && php artisan key:generate   # ajustar contraseñas de BD
php artisan migrate:fresh --database=pgsql_admin --seed   # SIEMPRE con pgsql_admin
php artisan serve                                    # http://localhost:8000
./vendor/bin/pest                                    # pruebas (usa turismo_test)

cd ../frontend
npm install
cp .env.example .env.local
npm run build && npm start                           # http://localhost:3000
CHROMIUM_PATH=/ruta/a/chrome npm run e2e             # Playwright (con ambos servidores arriba)
```

Datos demo: agencia `andes` (y `inka` para pruebas de aislamiento), contraseña `Turismo2026!`.
Ejemplos: `admin@andes.test` (Tenant Admin), `carla.ventas@andes.test` (Ventas), `operaciones@andes.test`,
`finanzas@andes.test`, `guia1@andes.test`, `cliente@correo.test`, `reservas@intiwasi.test` (proveedor).
CRM demo: Carla (U03) y Diego (U04) tienen cartera propia, hay leads en la cola sin asignar, clientes CLI-0001…0008,
oportunidades OPP-0001…0007 (una ganada y una perdida) y un caso REC-0001 abierto por una encuesta detractora.
Los e2e del CRM modifican datos: vuelva a sembrar antes de repetirlos. En local conviene `API_RATE_PER_MINUTE=600`.
Super Admin: `superadmin@turismo.test` con TOTP demo `JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP`, entra por `/login/plataforma`.
Invitación demo pendiente (Valeria Ríos, U19): `/invitacion/demo-invitacion-valeria`. Lista completa en
`backend/database/seeders/DemoSeeder.php`.

## Reglas de trabajo

- Las migraciones se ejecutan con la conexión `pgsql_admin`. La app conecta como `turismo_app` (sin BYPASSRLS).
- Toda tabla con `tenant_id` se crea con `Rls::tenantTable()` y su modelo usa `BelongsToTenant`.
- Endpoints: `auth:sanctum` + grupo `tenant` + `experience:*` + `perm:*` + Policy. Operaciones críticas: `idempotent`.
- En el frontend use `@/components/Link` (sin prefetch): cada prefetch pasa por el middleware y consulta el backend.
- Toda acción crítica pasa por `AuditLogger`. Los precios solo los calcula `PricingService`.
