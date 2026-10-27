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
