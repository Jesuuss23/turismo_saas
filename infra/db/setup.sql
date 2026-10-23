-- Roles de base de datos (ejecutar como superusuario una vez por entorno).
-- turismo_migrator: dueño del esquema; ejecuta migraciones y aprovisionamiento (BYPASSRLS).
-- turismo_app:      conexión de la aplicación (app_role), sujeta a RLS.
-- turismo_platform: conexión de la consola de plataforma (platform_role), sujeta a RLS.
DO $$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'turismo_migrator') THEN
    CREATE ROLE turismo_migrator LOGIN PASSWORD 'migrator' BYPASSRLS CREATEDB;
  END IF;
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'turismo_app') THEN
    CREATE ROLE turismo_app LOGIN PASSWORD 'app' NOBYPASSRLS;
  END IF;
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'turismo_platform') THEN
    CREATE ROLE turismo_platform LOGIN PASSWORD 'platform' NOBYPASSRLS;
  END IF;
END $$;
