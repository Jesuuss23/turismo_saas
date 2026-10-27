import { expect, test, type Page } from "@playwright/test";

test.describe.configure({ timeout: 180_000 });

// Spec 14 §10 (casos 17–20): marketing y metas comerciales. Datos: database/seeders/MarketingDemoSeeder.php.
const PASSWORD = "Turismo2026!";
const run = Date.now().toString().slice(-6);

type Cookies = Awaited<ReturnType<ReturnType<Page["context"]>["cookies"]>>;
const sessions = new Map<string, Cookies>();

/** Ingresa una vez por usuario y reutiliza la sesión (throttle:login limita 5 ingresos por minuto y correo). */
async function login(page: Page, email: string) {
  const context = page.context();
  await context.clearCookies();
  const saved = sessions.get(email);
  if (saved) return context.addCookies(saved);
  for (let attempt = 0; ; attempt++) {
    await page.goto("/login");
    await page.getByLabel("Agencia (tenant)").fill("andes");
    await page.getByLabel("Correo").fill(email);
    await page.getByLabel("Contraseña").fill(PASSWORD);
    await page.getByRole("button", { name: "Ingresar" }).click();
    await Promise.race([page.waitForURL((url) => !url.pathname.startsWith("/login")), page.getByText("Demasiados intentos").waitFor()]);
    if (!new URL(page.url()).pathname.startsWith("/login")) break;
    expect(attempt, "límite de ingresos").toBeLessThan(2);
    await page.waitForTimeout(61_000);
  }
  sessions.set(email, await context.cookies());
}

test.beforeEach(({ page }) => {
  page.on("dialog", (d) => d.accept());
});

test("17 · Manager crea un segmento (conteo en vivo), una campaña email, la prueba, envía, procesa y registra una baja", async ({ page }) => {
  await login(page, "gerencia@andes.test");
  await page.goto("/marketing/segmentos/nuevo");
  await page.getByLabel("Nombre del segmento").fill(`Clientes Perú E2E ${run}`);
  const count = page.getByTestId("segment-count");
  await expect(count).toBeVisible();
  const all = Number(await count.innerText());

  await page.getByRole("button", { name: "+ Agregar regla" }).click();
  await page.getByLabel("Valor de la regla 1").selectOption("client");
  await expect(count).not.toHaveText(String(all));
  const clients = Number(await count.innerText());
  await page.getByRole("button", { name: "+ Agregar regla" }).click();
  await page.getByLabel("Campo de la regla 2").selectOption("country");
  await page.getByLabel("Valor de la regla 2").fill("Perú");
  await expect(count).not.toHaveText(String(clients));
  await page.getByRole("button", { name: "Guardar segmento" }).click();
  await expect(page).toHaveURL(/\/marketing\/segmentos\/SEG-\d+\?saved=1/);
  const segment = page.url().match(/SEG-\d+/)![0];

  await page.getByRole("link", { name: "Crear campaña" }).click();
  await expect(page.getByRole("heading", { name: "Nueva campaña" })).toBeVisible();
  await page.getByLabel("Nombre de la campaña").fill(`Promo E2E ${run}`);
  await expect(page.getByLabel("Segmento")).toHaveValue(segment);
  await expect(page.getByTestId("audience-eligible")).not.toHaveText("0");
  await page.getByRole("button", { name: "Crear campaña (borrador)" }).click();
  await expect(page).toHaveURL(/\/marketing\/campanas\/CMP-\d+$/);
  await expect(page.getByText("Borrador", { exact: true }).first()).toBeVisible();

  await page.getByRole("button", { name: "Enviar prueba" }).click();
  await expect(page.getByText(/Prueba enviada a/)).toBeVisible();
  await page.getByRole("button", { name: "Enviar ahora" }).click();
  await expect(page.getByRole("button", { name: "Procesar envío (job)" })).toBeVisible();
  await page.getByRole("button", { name: "Procesar envío (job)" }).click();
  await expect(page.getByRole("button", { name: "Procesar envío (job)" })).toHaveCount(0);

  await expect(page.getByText("Enviada", { exact: true }).first()).toBeVisible();
  const recipients = page.locator("section").filter({ has: page.getByRole("heading", { name: /^Destinatarios/ }) });
  const first = recipients.locator("tbody tr").first();
  await first.getByRole("button", { name: "Registrar baja" }).click();
  await expect(recipients.getByText(/^Baja /).first()).toBeVisible();
  await expect(page.getByText(/1 bajas/)).toBeVisible();
  await expect(page.getByText("Embudo")).toBeVisible();
});

test("18 · Admin registra el consentimiento de un lead con evidencia y pasa a ser elegible", async ({ page }) => {
  await login(page, "admin@andes.test");
  await page.goto("/marketing/campanas?tab=consentimientos&q=Camila");
  await page.getByRole("link", { name: "Gestionar Camila Torres" }).click();
  const drawer = page.getByRole("dialog");
  const email = drawer.getByRole("region", { name: "Canal Email" });
  await expect(email.getByText("Sin consentimiento")).toBeVisible();
  await email.getByRole("combobox", { name: /^Origen/ }).selectOption("Formulario web (casilla)");
  await email.getByLabel(/Evidencia/).fill(`Formulario cotiza-web · IP 190.12.${run.slice(0, 2)}.7`);
  await email.getByRole("button", { name: "Registrar consentimiento Email" }).click();
  // Al revalidarse, el canal pasa a «Otorgado» y ofrece registrar la baja.
  await expect(email.getByText("Otorgado", { exact: true }).first()).toBeVisible();
  await expect(email.getByRole("button", { name: "Registrar baja" })).toBeVisible();

  // Audiencia: un segmento de leads la cuenta como elegible por email.
  await page.goto("/marketing/segmentos/nuevo");
  await page.getByRole("button", { name: "+ Agregar regla" }).click();
  await page.getByLabel("Valor de la regla 1").selectOption("lead");
  const sample = page.getByRole("complementary").filter({ hasText: "Audiencia en vivo" }).locator("li").filter({ hasText: "Camila Torres" });
  await expect(sample.getByText("Email: otorgado")).toBeVisible();
});

test("19 · Manager cierra la meta de agosto de U03 y Finanzas aprueba el bono", async ({ page }) => {
  await login(page, "gerencia@andes.test");
  await page.goto("/crm/metas");
  const row = page.locator("tr").filter({ hasText: "MET-0003" });
  await expect(row.getByText("Por cerrar")).toBeVisible();
  await expect(row).toContainText("112.9 %");
  await row.getByRole("button", { name: "Cerrar período" }).click();
  await expect(row.getByText("Por cerrar")).toHaveCount(0);

  await page.goto("/crm/metas/MET-0003");
  await expect(page.getByText("Cerrada", { exact: true }).first()).toBeVisible();
  await expect(page.getByRole("link", { name: /COM-\d+ · PEN 600\.00/ })).toBeVisible();

  await login(page, "finanzas@andes.test");
  await page.goto("/finanzas/comisiones?kind=bono_meta");
  const commission = page.locator("tbody tr").filter({ hasText: "Meta MET-0003" });
  await expect(commission).toContainText("Bono por meta");
  await commission.getByRole("link", { name: /COM-\d+/ }).click();
  await page.getByRole("button", { name: "Aprobar comisión" }).click();
  await expect(page.getByRole("dialog").getByText("Aprobada").first()).toBeVisible();
});

test("20 · Ventas ve su meta y su posición sin montos ajenos; MET-0007 fuera de cartera; Marketing 403; 400 px", async ({ page }) => {
  await page.setViewportSize({ width: 400, height: 860 });
  await login(page, "carla.ventas@andes.test");
  await page.goto("/crm/metas");
  await expect(page.getByRole("heading", { name: "Mis metas" })).toBeVisible();
  await expect(page.getByText("Su posición: 3 de 3")).toBeVisible();
  const ranking = page.locator("section").filter({ has: page.getByRole("heading", { name: /Su posición/ }) });
  await expect(ranking.getByText("Diego Salazar")).toBeVisible();
  await expect(ranking).not.toContainText("S/");
  await expect(page.getByText("S/ 10,000")).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(401);

  await page.goto("/crm/metas/MET-0008");
  await expect(page.getByRole("heading", { name: /MET-0008/ })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(401);

  await page.goto("/crm/metas/MET-0007");
  await expect(page.getByText(/fuera de su cartera/)).toBeVisible();

  await page.goto("/marketing/campanas");
  await expect(page.getByText("Error 403 · Acceso restringido")).toBeVisible();
});
