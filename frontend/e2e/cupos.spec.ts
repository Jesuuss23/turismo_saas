import { expect, test, type Page } from "@playwright/test";

test.describe.configure({ timeout: 200_000 });

// Spec 11 §10: cupos y bloqueos · documentos de viaje. Datos: database/seeders/InventoryDemoSeeder.php.
const PASSWORD = "Turismo2026!";
const run = Date.now().toString().slice(-6);

type Cookies = Awaited<ReturnType<ReturnType<Page["context"]>["cookies"]>>;
const sessions = new Map<string, Cookies>();

/**
 * Ingresa una vez por usuario y reutiliza la sesión: la suite cambia de usuario seguido y el ingreso está limitado a
 * 5 por minuto y correo (throttle:login). Si aun así se alcanza el límite, espera la ventana y reintenta.
 */
async function login(page: Page, email: string) {
  const context = page.context();
  await context.clearCookies();
  const saved = sessions.get(email);
  if (saved) {
    await context.addCookies(saved);
    return;
  }
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

/** Fecha del tenant (America/Lima) desplazada `n` días, en ISO y como la muestra la grilla (dd/mm). */
function day(n: number) {
  const d = new Date(Date.now() - 5 * 3_600_000 + n * 86_400_000);
  const iso = d.toISOString().slice(0, 10);
  return { iso, dm: `${iso.slice(8, 10)}/${iso.slice(5, 7)}` };
}

/** Celda de la grilla para el contrato cuya fila contiene `text`. */
function cell(page: Page, text: string, dm: string) {
  return page.getByTestId("cupos-grid").getByRole("row").filter({ hasText: text }).getByRole("link", { name: new RegExp(`^${dm}:`) });
}

async function noOverflow(page: Page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(401);
}

const PDF = Buffer.from("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

test("CUP-01: Operaciones libera 2 habitaciones del Inti Wasi desde la grilla", async ({ page }) => {
  const d = day(10);
  await login(page, "operaciones@andes.test");
  await page.goto("/catalogo/cupos");
  await expect(cell(page, "Hotel Inti Wasi", d.dm)).toHaveAccessibleName(new RegExp(`^${d.dm}: 6 disponibles`));
  await cell(page, "Hotel Inti Wasi", d.dm).click();
  const drawer = page.getByRole("dialog");
  await drawer.getByLabel("Unidades a liberar").fill("2");
  await drawer.getByRole("button", { name: "Liberar", exact: true }).click();
  await expect(drawer.getByText("Unidades liberadas")).toBeVisible();
  await page.goto("/catalogo/cupos");
  await expect(cell(page, "Hotel Inti Wasi", d.dm)).toHaveAccessibleName(new RegExp(`^${d.dm}: 4 disponibles`));
});

test("CUP-02: ampliación de PeruRail aceptada por el proveedor sube el cupo", async ({ page }) => {
  const from = day(20);
  const to = day(21);
  await login(page, "operaciones@andes.test");
  await page.goto("/catalogo/cupos/CUP-2026-002");
  await page.getByRole("link", { name: "Solicitar ampliación" }).click();
  const drawer = page.getByRole("dialog");
  await drawer.getByLabel("Desde").fill(from.iso);
  await drawer.getByLabel("Hasta").fill(to.iso);
  await drawer.getByLabel(/Unidades adicionales/).fill("4");
  await drawer.getByLabel("Motivo para el proveedor").fill(`Grupo corporativo e2e ${run}`);
  await drawer.getByRole("button", { name: "Enviar solicitud" }).click();
  await expect(page).toHaveURL(/tab=ampliaciones/);
  const row = page.getByRole("row").filter({ hasText: `Grupo corporativo e2e ${run}` });
  await expect(row.getByText("Solicitada")).toBeVisible();
  await row.getByLabel(/Respuesta del proveedor/).selectOption("true");
  await row.getByLabel(/Detalle de la respuesta/).fill("Confirmado por PeruRail");
  await row.getByRole("button", { name: "Registrar respuesta" }).click();
  await expect(page.getByRole("row").filter({ hasText: `Grupo corporativo e2e ${run}` }).getByText("Aceptada")).toBeVisible();
  await page.goto(`/catalogo/cupos?from=${from.iso}`);
  await expect(cell(page, "PeruRail", from.dm)).toHaveAccessibleName(new RegExp(`^${from.dm}: 16 disponibles`));
});

test("CUP-03: Finanzas ve las penalidades y las acciones de cupo deshabilitadas", async ({ page }) => {
  await login(page, "finanzas@andes.test");
  await page.goto("/catalogo/cupos/CUP-2026-001?tab=penalidades");
  await expect(page.getByRole("row").filter({ hasText: "570" }).first()).toBeVisible();
  await expect(page.getByRole("button", { name: "Solicitar ampliación" })).toBeDisabled();
  await page.goto(`/catalogo/cupos?cell=CUP-2026-001:${day(12).iso}`);
  const drawer = page.getByRole("dialog");
  await expect(drawer.getByRole("button", { name: "Liberar", exact: true })).toBeDisabled();
  await expect(drawer.getByRole("button", { name: "Retener" })).toBeDisabled();
});

test("DOC-01: validar el DNI y la autorización del menor desbloquea Machu Picchu en RES-359", async ({ page }) => {
  const base = "/operaciones/documentos-viaje/RES-2026-000359";
  await login(page, "operaciones@andes.test");
  await page.goto(base);
  await expect(page.getByTestId("mapi-gate")).toBeVisible();

  await page.getByRole("link", { name: "Gestionar Documento de identidad de Luis Vega" }).click();
  let drawer = page.getByRole("dialog");
  await drawer.getByRole("button", { name: "Validar" }).locator("xpath=ancestor::form").getByLabel("Vencimiento").fill("2032-05-14");
  await drawer.getByRole("button", { name: "Validar" }).click();
  await expect(drawer.getByText("Documento validado")).toBeVisible();

  await page.goto(base);
  await page.getByRole("link", { name: "Gestionar Autorización de menor de Luis Vega" }).click();
  drawer = page.getByRole("dialog");
  await drawer.locator("input[type=file]").setInputFiles({ name: "autorizacion-luis.pdf", mimeType: "application/pdf", buffer: PDF });
  await drawer.getByRole("button", { name: "Cargar archivo" }).click();
  await expect(drawer.getByText("autorizacion-luis.pdf")).toBeVisible();
  await drawer.getByRole("button", { name: "Validar" }).click();
  await expect(drawer.getByText("Documento validado")).toBeVisible();

  await page.goto(base);
  await expect(page.getByTestId("mapi-gate")).toHaveCount(0);
});

test("DOC-02: Ventas ve solo su cartera y solicita documentos al cliente", async ({ page }) => {
  await login(page, "carla.ventas@andes.test");
  await page.goto("/operaciones/documentos-viaje");
  await expect(page.getByRole("link", { name: "RES-2026-000350" }).first()).toBeVisible();
  await expect(page.getByRole("link", { name: "RES-2026-000351" })).toHaveCount(0);
  await page.goto("/operaciones/documentos-viaje/RES-2026-000351");
  await expect(page.getByText("Registro fuera de su cartera").first()).toBeVisible();

  await page.goto("/operaciones/documentos-viaje/RES-2026-000350");
  await page.getByRole("link", { name: "Solicitar documentos al cliente" }).click();
  const drawer = page.getByRole("dialog");
  await drawer.getByLabel("Mensaje (opcional)").fill(`Por favor suba el DNI corregido · e2e ${run}`);
  await drawer.getByRole("button", { name: "Enviar solicitud" }).click();
  await expect(page.getByText(`Por favor suba el DNI corregido · e2e ${run}`)).toBeVisible();
});

test("Responsive 400 px: grilla de cupos y checklist documental sin scroll horizontal", async ({ page }) => {
  await page.setViewportSize({ width: 400, height: 800 });
  await login(page, "operaciones@andes.test");
  for (const url of ["/catalogo/cupos", "/catalogo/cupos/CUP-2026-001", "/operaciones/documentos-viaje", "/operaciones/documentos-viaje/RES-2026-000354"]) {
    await page.goto(url);
    await expect(page.locator("h1").first()).toBeVisible();
    await noOverflow(page);
  }
});
