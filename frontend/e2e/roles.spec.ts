import { expect, test, type Page } from "@playwright/test";

// Usuarios demo (backend: database/seeders/DemoSeeder.php).
const PASSWORD = "Turismo2026!";

async function login(page: Page, email: string) {
  await page.goto("/login");
  await page.getByLabel("Agencia (tenant)").fill("andes");
  await page.getByLabel("Correo").fill(email);
  await page.getByLabel("Contraseña").fill(PASSWORD);
  await page.getByRole("button", { name: "Ingresar" }).click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
}

test("Ventas (U03) aterriza en su inicio con el menú filtrado", async ({ page }) => {
  await login(page, "carla.ventas@andes.test");
  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByText("Tenant: andes · Mi cartera")).toBeVisible();

  const nav = page.locator("aside nav a");
  await expect(nav.filter({ hasText: "Leads" })).toBeVisible();
  await expect(nav.filter({ hasText: "Rentabilidad" })).toHaveCount(0);
  await expect(nav.filter({ hasText: "Usuarios" })).toHaveCount(0);
});

test("una ruta prohibida muestra 403 con permisos requeridos y permite solicitar acceso", async ({ page }) => {
  await login(page, "diego.ventas@andes.test");
  await page.goto("/finanzas/rentabilidad");

  // La 403 se muestra en la misma URL (rewrite del middleware).
  await expect(page).toHaveURL(/\/finanzas\/rentabilidad$/);
  await expect(page.getByText("Error 403 · Acceso restringido")).toBeVisible();
  await expect(page.getByText("finance.profitability", { exact: true })).toBeVisible();
  await expect(page.getByText("El intento quedó registrado en auditoría.")).toBeVisible();
  await page.getByRole("button", { name: "Solicitar acceso al administrador" }).click();
  await expect(page.getByText("Solicitud enviada al administrador de la agencia.")).toBeVisible();
});

test("externos (Guía U09): shell propio, sin menú lateral, sin back-office", async ({ page }) => {
  await login(page, "guia1@andes.test");
  await expect(page).toHaveURL(/\/mobile\/operador$/);
  await expect(page.locator("aside")).toHaveCount(0);
  await expect(page.getByText("Solo se muestran datos vinculados a su cuenta")).toBeVisible();

  await page.goto("/dashboard");
  await expect(page.getByText("no está disponible para usuarios externos")).toBeVisible();
  await expect(page.getByRole("button", { name: "Solicitar acceso al administrador" })).toHaveCount(0);
});

test("Cliente (U14) que abre el portal de otro cliente vuelve al suyo", async ({ page }) => {
  await login(page, "cliente@correo.test");
  await expect(page).toHaveURL(/\/canales\/portal-cliente\/[0-9a-f-]+\/inicio$/);
  const own = new URL(page.url()).pathname;

  await page.goto("/canales/portal-cliente/otro-cliente/inicio");
  await expect(page).toHaveURL(new RegExp(own + "$"));
});

test("sin sesión redirige al login y conserva la ruta", async ({ page }) => {
  await page.goto("/crm/leads");
  await expect(page).toHaveURL(/\/login\?next=%2Fcrm%2Fleads/);
});
