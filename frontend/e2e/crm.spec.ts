import { expect, test, type Page } from "@playwright/test";

// CRM (spec 01). Requiere datos demo recién sembrados: los flujos modifican registros
// (php artisan migrate:fresh --database=pgsql_admin --seed).
const PASSWORD = "Turismo2026!";

async function login(page: Page, email: string) {
  await page.goto("/login");
  await page.getByLabel("Agencia (tenant)").fill("andes");
  await page.getByLabel("Correo").fill(email);
  await page.getByLabel("Contraseña").fill(PASSWORD);
  await page.getByRole("button", { name: "Ingresar" }).click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
}

test("Tenant Admin recorre todas las pantallas del CRM sin errores", async ({ page }) => {
  test.setTimeout(120_000);
  await login(page, "admin@andes.test");
  const pages: [string, string][] = [
    ["/crm/leads", "Leads"],
    ["/crm/leads/LEAD-0003", "LEAD-0003"],
    ["/crm/oportunidades", "Oportunidades"],
    ["/crm/oportunidades?view=tabla", "Oportunidades"],
    ["/crm/oportunidades/OPP-0001", "OPP-0001"],
    ["/crm/actividades", "Actividades"],
    ["/crm/actividades?view=semana", "Actividades"],
    ["/crm/clientes", "Clientes"],
    ["/crm/clientes/new", "Nuevo cliente"],
    ["/crm/postventa", "Postventa y fidelización"],
    ["/crm/postventa?case=REC-0001", "REC-0001"],
    ["/crm/postventa?rebuy=CLI-0001", "Oportunidad de recompra"],
  ];
  for (const [url, heading] of pages) {
    await page.goto(url);
    await expect(page.getByRole("heading", { name: heading }).first(), url).toBeVisible();
  }
  for (const tab of ["resumen", "contactos", "leads", "oportunidades", "cotizaciones", "reservas", "pasajeros", "pagos", "documentos", "comunicaciones", "actividades", "historial"]) {
    await page.goto(`/crm/clientes/CLI-0001?tab=${tab}`);
    await expect(page.getByRole("heading", { name: /CLI-0001/ }), tab).toBeVisible();
  }
});

test("Finanzas ve Clientes 360 en modo consulta", async ({ page }) => {
  await login(page, "finanzas@andes.test");
  await page.goto("/crm/clientes");
  await expect(page.getByText("Modo consulta")).toBeVisible();
  await expect(page.getByRole("link", { name: "Nuevo cliente" })).toHaveCount(0);
  await page.goto("/crm/clientes/CLI-0001");
  await expect(page.getByText("Modo consulta: su rol no puede modificar clientes")).toBeVisible();
  await expect(page.getByRole("link", { name: "Editar" })).toHaveCount(0);
});

test("CRM-E2E-02: en móvil el menú ⋯ mueve a Perdida pidiendo el motivo", async ({ page }) => {
  await page.setViewportSize({ width: 400, height: 860 });
  await login(page, "carla.ventas@andes.test");
  await page.goto("/crm/oportunidades");
  await page.getByRole("button", { name: "Mover OPP-0005" }).click();
  await page.getByRole("menuitem", { name: "Mover a Perdida" }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog.getByText("Marcar OPP-0005 como perdida")).toBeVisible();
  await dialog.getByLabel("Motivo *").selectOption("precio");
  await dialog.getByRole("button", { name: "Marcar como perdida" }).click();
  await expect(page.locator('[data-stage="perdida"]').getByText("OPP-0005")).toBeVisible();

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});

test("CRM-E2E-03: Ventas toma un lead de la bolsa y marca la actividad como hecha desde su panel", async ({ page }) => {
  await login(page, "diego.ventas@andes.test");
  await expect(page.getByRole("heading", { name: "Panel de Ventas · Mi cartera" }).or(page.getByText("Panel de Ventas · Mi cartera"))).toBeVisible();

  const pool = page.locator("li", { has: page.getByRole("button", { name: "Tomar" }) }).first();
  const code = (await pool.getByRole("link").first().textContent())!.trim();
  await pool.getByRole("button", { name: "Tomar" }).click();
  await expect(page.locator("li", { hasText: code }).getByRole("button", { name: "Tomar" })).toHaveCount(0);

  // El primer contacto programado al tomar aparece en "Hoy y vencidas" con el botón "Hecha".
  await page.goto(`/crm/leads/${code}`);
  const activity = (await page.getByRole("link", { name: /^ACT-\d+$/ }).first().textContent())!.trim();
  await page.goto(`/dashboard?done=${activity}`);
  const drawer = page.getByRole("dialog");
  await drawer.getByLabel("Resultado *").fill("Llamada realizada, interesado en Cusco");
  await drawer.getByRole("button", { name: "Completar" }).click();
  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByRole("dialog")).toHaveCount(0);

  await page.goto(`/crm/leads/${code}`);
  await expect(page.getByText("Contactado").first()).toBeVisible();
});
