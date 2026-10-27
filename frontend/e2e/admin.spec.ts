import { execSync } from "node:child_process";
import { expect, test, type Page } from "@playwright/test";

// Spec 09 §10: administración, consola de plataforma y reportes. Usuarios demo: database/seeders/DemoSeeder.php.
const PASSWORD = "Turismo2026!";
const TOTP_SECRET = "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP";
const run = Date.now().toString().slice(-6);

async function login(page: Page, email: string) {
  await page.goto("/login");
  await page.getByLabel("Agencia (tenant)").fill("andes");
  await page.getByLabel("Correo").fill(email);
  await page.getByLabel("Contraseña").fill(PASSWORD);
  await page.getByRole("button", { name: "Ingresar" }).click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
}

function totp() {
  return execSync(`php -r 'require "vendor/autoload.php"; echo (new PragmaRX\\Google2FA\\Google2FA)->getCurrentOtp("${TOTP_SECRET}");'`, { cwd: "../backend" }).toString().trim();
}

async function platformLogin(page: Page) {
  await page.goto("/login/plataforma");
  await page.getByLabel("Correo").fill("superadmin@turismo.test");
  await page.getByLabel("Contraseña").fill(PASSWORD);
  await page.getByRole("button", { name: /Ingresar/ }).click();
  await page.waitForURL(/\/mfa/);
  await page.getByLabel(/Código de 6 dígitos/).fill(totp());
  await page.getByRole("button", { name: "Verificar" }).click();
  // Un código TOTP no se acepta dos veces (anti-replay): si otra corrida ya lo usó, se espera la siguiente ventana de 30 s.
  const rejected = page.getByText("El código de verificación no es válido");
  await Promise.race([page.waitForURL((url) => !url.pathname.startsWith("/mfa")), rejected.waitFor()]);
  if (page.url().includes("/mfa")) {
    await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 500);
    await page.getByLabel(/Código de 6 dígitos/).fill(totp());
    await page.getByRole("button", { name: "Verificar" }).click();
    await page.waitForURL((url) => !url.pathname.startsWith("/mfa"));
  }
}

test("Super Admin: aterriza en la consola, da de alta un tenant en prueba y lo suspende/reactiva", async ({ page }) => {
  test.setTimeout(90_000);
  await platformLogin(page);
  await expect(page).toHaveURL(/\/admin\/tenants$/);
  await expect(page.getByRole("heading", { name: "Tenants", exact: true })).toBeVisible();
  await expect(page.getByText("Consola de plataforma").first()).toBeVisible();

  await page.getByRole("link", { name: "Nuevo tenant" }).click();
  const next = () => page.getByRole("button", { name: "Siguiente" }).click();
  await page.getByLabel("Nombre comercial").fill(`Cóndor ${run}`);
  await page.getByLabel("Subdominio").fill(`condor-${run}`);
  await next();
  await page.getByLabel("Razón social").fill(`Cóndor Tours ${run} S.A.C.`);
  await page.getByLabel("RUC").fill(`20${run}987`.padEnd(11, "1"));
  await page.getByLabel("Domicilio fiscal").fill("Av. Sol 120, Cusco");
  await page.getByLabel("Rubro").selectOption({ index: 1 });
  await next();
  await expect(page.getByText("Paso 3 de 11")).toBeVisible();
  await next();
  await page.getByLabel("Nombre de la sede").fill("Sede Cusco");
  await page.getByLabel("Ciudad").fill("Cusco");
  await page.getByLabel("Dirección").fill("Av. Sol 120");
  await next();
  await page.getByLabel("Nombre del administrador").fill("Rosa Quispe");
  await page.getByLabel("Correo").fill(`rosa.${run}@condor.test`);
  for (let step = 5; step < 11; step++) await next();
  await expect(page.getByText("Paso 11 de 11")).toBeVisible();
  await page.getByRole("button", { name: "Crear y activar tenant" }).click();
  const done = page.getByRole("status");
  await expect(done).toContainText("creado");
  const code = (await done.locator("p").first().innerText()).match(/TNT-\d+/)![0];

  await page.getByRole("link", { name: "Volver a tenants" }).click();
  const row = page.locator(`tr[data-tenant="${code}"]`);
  await expect(row).toContainText("En prueba");

  await page.goto(`/admin/tenants?id=${code}`);
  const drawer = page.getByRole("dialog");
  await drawer.getByLabel("Motivo (obligatorio)").fill("Falta de pago (prueba e2e)");
  await drawer.getByRole("button", { name: "Suspender tenant" }).click();
  await expect(drawer.getByRole("button", { name: "Reactivar tenant" })).toBeVisible();
  await drawer.getByLabel("Motivo (obligatorio)").fill("Pago regularizado");
  await drawer.getByRole("button", { name: "Reactivar tenant" }).click();
  await expect(drawer.getByRole("button", { name: "Suspender tenant" })).toBeVisible();
});

test("Tenant Admin: invita, cambia el rol y desactiva con motivo", async ({ page }) => {
  await login(page, "admin@andes.test");
  await page.goto("/admin/usuarios?nuevo=1");
  const invite = page.getByRole("dialog");
  await invite.getByLabel("Nombre completo").fill(`Lucas Prueba ${run}`);
  await invite.getByLabel("Correo").fill(`lucas.${run}@andes.test`);
  await invite.getByLabel("Rol").selectOption({ label: "Ventas (Sales)" });
  await invite.getByLabel("Sede").selectOption({ index: 1 });
  await invite.getByRole("button", { name: "Enviar invitación" }).click();

  const drawer = page.getByRole("dialog");
  await expect(drawer).toContainText(`Lucas Prueba ${run}`);
  await expect(drawer.getByText("Invitación enviada").first()).toBeVisible();

  await drawer.getByLabel("Rol").selectOption({ label: "Operaciones" });
  await drawer.getByRole("button", { name: "Guardar cambios" }).click();
  await expect(drawer.getByText("Usuario actualizado")).toBeVisible();

  await drawer.getByLabel("Motivo (obligatorio)").fill("Ya no trabaja en la agencia");
  await drawer.getByRole("button", { name: "Desactivar usuario" }).click();
  await expect(drawer.getByRole("button", { name: "Activar usuario" })).toBeVisible();
});

test("Tenant Admin: quitar reports.export a Finanzas deshabilita su exportación", async ({ browser }) => {
  const admin = await browser.newPage();
  await login(admin, "admin@andes.test");
  await admin.goto("/admin/roles/finance");
  const cell = admin.locator('li[data-permission="reports.export"]');
  await cell.getByRole("button", { name: "✓ Otorgado" }).click();
  await expect(cell.getByRole("button", { name: "Otorgar" })).toBeVisible();

  const finance = await browser.newPage();
  await login(finance, "finanzas@andes.test");
  await finance.goto("/reportes/finanzas");
  await expect(finance.getByRole("button", { name: "Exportar" })).toBeDisabled();
  await expect(finance.getByRole("link", { name: "Historial de exportaciones" })).toHaveCount(0);

  await admin.goto("/admin/auditoria?entity=Permiso,Rol");
  await expect(admin.getByText("reports.export").first()).toBeVisible();

  // Restituye la casilla: la demo queda como estaba.
  await admin.goto("/admin/roles/finance");
  await cell.getByRole("button", { name: "Otorgar" }).click();
  await expect(cell.getByRole("button", { name: "✓ Otorgado" })).toBeVisible();
  await finance.reload();
  await expect(finance.getByRole("link", { name: "Exportar" })).toBeVisible();
});

test("Manager: Administración solo muestra Auditoría y /admin/usuarios es 403", async ({ page }) => {
  await login(page, "gerencia@andes.test");
  const nav = page.locator("aside nav a");
  await expect(nav.filter({ hasText: "Auditoría" })).toBeVisible();
  await expect(nav.filter({ hasText: "Usuarios" })).toHaveCount(0);
  await expect(nav.filter({ hasText: "Roles y permisos" })).toHaveCount(0);
  await expect(page.getByText("Aprobaciones pendientes")).toBeVisible();

  await page.goto("/admin/usuarios");
  await expect(page.getByText("Error 403 · Acceso restringido")).toBeVisible();
  await expect(page.getByRole("button", { name: "Solicitar acceso al administrador" })).toBeVisible();
});

test("Ventas: reportes solo Comercial y Cotizaciones, exportar deshabilitado", async ({ page }) => {
  await login(page, "carla.ventas@andes.test");
  await page.goto("/reportes");
  const tabs = page.getByRole("navigation", { name: "Secciones" }).getByRole("link");
  await expect(tabs).toHaveText(["Comercial / CRM", "Cotizaciones"]);
  const exportBtn = page.getByRole("button", { name: "Exportar" });
  await expect(exportBtn).toBeDisabled();
  await expect(page.locator('[title="Requiere permiso reports.export"]')).toBeVisible();
});

test.describe("400 px sin scroll horizontal de página", () => {
  test.use({ viewport: { width: 400, height: 800 } });

  const noHorizontalScroll = async (page: Page) =>
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(400);

  test("mapa de acceso", async ({ page }) => {
    await login(page, "admin@andes.test");
    await page.goto("/admin/roles/mapa");
    await expect(page.getByRole("heading", { name: "Roles y permisos" })).toBeVisible();
    await noHorizontalScroll(page);
  });

  test("panel de gerencia", async ({ page }) => {
    await login(page, "gerencia@andes.test");
    await expect(page.getByText("Aprobaciones pendientes")).toBeVisible();
    await noHorizontalScroll(page);
  });

  test("reporte de finanzas", async ({ page }) => {
    await login(page, "finanzas@andes.test");
    await page.goto("/reportes/finanzas");
    await expect(page.getByRole("heading", { name: "Reportes" })).toBeVisible();
    await noHorizontalScroll(page);
  });
});
