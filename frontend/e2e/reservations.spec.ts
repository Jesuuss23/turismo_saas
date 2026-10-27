import { expect, test, type Page } from "@playwright/test";

// Reservas (spec 04 §10.3). Requiere datos demo recién sembrados
// (php artisan migrate:fresh --database=pgsql_admin --seed).
const PASSWORD = "Turismo2026!";

async function login(page: Page, email: string) {
  await page.context().clearCookies();
  await page.goto("/login");
  await page.getByLabel("Agencia (tenant)").fill("andes");
  await page.getByLabel("Correo").fill(email);
  await page.getByLabel("Contraseña").fill(PASSWORD);
  await page.getByRole("button", { name: "Ingresar" }).click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
}

test("T-R17: cotización aprobada → convertir en reserva → reserva 360 y cotización convertida", async ({ page }) => {
  test.setTimeout(120_000);
  // Gerencia convierte (Carla ya agota su límite de 5 ingresos por minuto en el resto de la suite).
  await login(page, "gerencia@andes.test");
  await page.goto("/cotizaciones/COT-2026-00194");
  await page.getByRole("link", { name: "Convertir en reserva" }).click();
  await expect(page.getByLabel("Precondiciones")).toContainText("Cotización aprobada por el cliente");
  await expect(page.getByText(/Todo listo: la reserva nace confirmada/)).toBeVisible();
  await page.getByRole("button", { name: "Convertir y confirmar reserva" }).click();

  await page.waitForURL(/\/reservas\/RES-\d{4}-\d{6}$/);
  const code = page.url().split("/").pop()!;
  await expect(page.getByRole("heading", { name: new RegExp(`^${code} · Fin de semana Cusco`) })).toBeVisible();
  await expect(page.getByText("Confirmada").first()).toBeVisible();
  await page.getByRole("link", { name: /^Servicios \(/ }).click();
  await expect(page.getByText(/PRD-004|Vinicunca/).first()).toBeVisible();

  // La cotización queda convertida y enlaza a la reserva.
  await page.goto("/cotizaciones/COT-2026-00194");
  await page.getByRole("link", { name: "Ver reserva" }).click();
  await page.waitForURL(new RegExp(`/reservas/${code}$`));
});

test("T-R18: Ventas no ve reservas de otra cartera", async ({ page }) => {
  await login(page, "diego.ventas@andes.test");
  await page.goto("/reservas/RES-2026-000350");
  await expect(page.getByRole("heading", { name: "Registro fuera de su cartera" })).toBeVisible();
});

test("T-R19: Operaciones ve costos y no importes de venta", async ({ page }) => {
  await login(page, "operaciones@andes.test");
  await page.goto("/reservas/RES-2026-000350");
  await expect(page.getByText(/^Vista de Operaciones:/)).toBeVisible();
  await expect(page.getByText("Costo proveedores")).toBeVisible();
  await expect(page.getByText("Saldo", { exact: true })).toHaveCount(0);
  await expect(page.getByRole("link", { name: /^Pagos/ })).toHaveCount(0);
});
