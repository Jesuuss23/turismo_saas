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

Pendiente para los módulos 01–09: tablas de negocio (y sus FK de vínculos externos), ownership de Ventas sobre esas tablas
(X-07…X-09), invitaciones y administración de usuarios/roles (09), plantillas y reglas de notificación (09), Reverb.

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
Super Admin: `superadmin@turismo.test` con TOTP demo `JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP`. Lista completa en
`backend/database/seeders/DemoSeeder.php`.

## Reglas de trabajo

- Las migraciones se ejecutan con la conexión `pgsql_admin`. La app conecta como `turismo_app` (sin BYPASSRLS).
- Toda tabla con `tenant_id` se crea con `Rls::tenantTable()` y su modelo usa `BelongsToTenant`.
- Endpoints: `auth:sanctum` + grupo `tenant` + `experience:*` + `perm:*` + Policy. Operaciones críticas: `idempotent`.
- Toda acción crítica pasa por `AuditLogger`. Los precios solo los calcula `PricingService`.
