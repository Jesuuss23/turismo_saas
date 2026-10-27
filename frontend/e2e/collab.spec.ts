import { expect, test, type Page } from "@playwright/test";

test.describe.configure({ timeout: 200_000 });

// Spec 10 §10: bandeja de aprobaciones unificada y bandeja omnicanal. Datos: database/seeders/CollabDemoSeeder.php.
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

/** Abre el detalle (drawer) de la solicitud cuya fila contiene `text`. */
async function openRequest(page: Page, tab: string, text: string) {
  await page.goto(`/aprobaciones?tab=${tab}`);
  await page.getByRole("row").filter({ hasText: text }).getByRole("link").first().click();
  const drawer = page.getByRole("dialog");
  await expect(drawer).toBeVisible();
  return drawer;
}

async function noOverflow(page: Page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(401);
}

test("E2E-AP-01: Ventas pide 8 % de descuento → Gerencia aprueba desde la bandeja → Ventas ve «Aprobada»", async ({ page }) => {
  await login(page, "gerencia@andes.test");
  const drawer = await openRequest(page, "mine", "COT-2026-00197");
  await expect(drawer.getByText("descuento 8.0% > 5%").first()).toBeVisible();
  await expect(drawer.getByText("Registra la aprobación interna de la versión")).toBeVisible();
  await drawer.getByLabel("Comentario (opcional)").fill("Aprobado por volumen anual");
  await drawer.getByRole("button", { name: "Aprobar", exact: true }).click();
  await expect(drawer.getByText("Aprobada", { exact: true }).first()).toBeVisible();

  await login(page, "diego.ventas@andes.test");
  await page.goto("/aprobaciones?tab=sent");
  await expect(page.getByRole("row").filter({ hasText: "COT-2026-00197" }).first()).toContainText("Aprobada");
  // Ventas no ve botones de decisión.
  await page.getByRole("row").filter({ hasText: "COT-2026-00197" }).getByRole("link").first().click();
  await expect(page.getByRole("dialog").getByRole("button", { name: "Aprobar", exact: true })).toHaveCount(0);
});

test("E2E-AP-02: anulación → Gerencia pide información → Ventas responde → Gerencia aprueba y la reserva queda cancelada", async ({ page }) => {
  await login(page, "gerencia@andes.test");
  let drawer = await openRequest(page, "all", "RES-2026-000360");
  await drawer.getByLabel("Pregunta al solicitante").fill("¿La clienta aceptó la penalidad?");
  await drawer.getByRole("button", { name: "Enviar consulta" }).click();
  await expect(drawer.getByText("Información solicitada").first()).toBeVisible();

  await login(page, "carla.ventas@andes.test");
  drawer = await openRequest(page, "sent", "RES-2026-000360");
  await expect(drawer.getByText("¿La clienta aceptó la penalidad?").first()).toBeVisible();
  await drawer.getByLabel("Respuesta").fill("Sí, la aceptó por correo");
  await drawer.getByRole("button", { name: "Responder" }).click();
  await expect(drawer.getByText("Sí, la aceptó por correo").first()).toBeVisible();

  await login(page, "gerencia@andes.test");
  drawer = await openRequest(page, "mine", "RES-2026-000360");
  await drawer.getByRole("button", { name: "Aprobar", exact: true }).click();
  await expect(drawer.getByText("Aprobada", { exact: true }).first()).toBeVisible();
  await page.goto("/reservas/RES-2026-000360");
  await expect(page.getByText(/Cancelada/).first()).toBeVisible();
});

test("E2E-AP-03: crédito B2B de dos niveles (Gerencia → Finanzas) amplía la línea de la agencia", async ({ page }) => {
  await login(page, "gerencia@andes.test");
  let drawer = await openRequest(page, "mine", "Ampliación de línea para temporada alta");
  await expect(drawer.getByText("Nivel 1 de 2")).toBeVisible();
  await drawer.getByRole("button", { name: "Aprobar nivel 1" }).click();
  await expect(drawer.getByText("Nivel 2 de 2")).toBeVisible();
  await expect(drawer.getByRole("button", { name: /^Aprobar/ })).toHaveCount(0);

  await login(page, "finanzas@andes.test");
  drawer = await openRequest(page, "mine", "Ampliación de línea para temporada alta");
  await drawer.getByRole("button", { name: "Aprobar", exact: true }).click();
  await expect(drawer.getByText("Aprobada", { exact: true }).first()).toBeVisible();
  await expect(drawer.getByText("21000.00").first()).toBeVisible();
});

test("E2E-AP-04: Operaciones aprueba la tarifa del proveedor y el hotel la ve aprobada en la extranet", async ({ page }) => {
  await login(page, "operaciones@andes.test");
  await page.goto("/aprobaciones?tab=mine");
  await page.getByRole("row").filter({ hasText: "Tarifa de proveedor" }).first().getByRole("link").first().click();
  const drawer = page.getByRole("dialog");
  const code = (await drawer.getByText(/SOL-TAR-\d+ ·/).first().innerText()).match(/SOL-TAR-\d+/)![0];
  await drawer.getByRole("button", { name: "Aprobar", exact: true }).click();
  await expect(drawer.getByText("Aprobada", { exact: true }).first()).toBeVisible();

  await login(page, "reservas@intiwasi.test");
  await page.goto("/proveedor/contrato");
  await expect(page.getByRole("row").filter({ hasText: code })).toContainText(/Aprobada/i);
});

test("Nueva solicitud genérica: Ventas la crea y la retira", async ({ page }) => {
  await login(page, "carla.ventas@andes.test");
  await page.goto("/aprobaciones");
  await page.getByRole("link", { name: "Nueva solicitud" }).click();
  const drawer = page.getByRole("dialog");
  await drawer.getByLabel("Tipo").selectOption({ label: "Otra excepción" });
  await drawer.getByLabel("Título").fill(`Cortesía e2e ${run}`);
  await drawer.getByLabel("Justificación").fill("Prueba de punta a punta de la bandeja.");
  await drawer.getByRole("button", { name: "Enviar a aprobación" }).click();
  await page.waitForURL(/\/aprobaciones\/APR-\d{4}-\d{4}/);
  await expect(page.getByRole("dialog").getByText("Pendiente", { exact: true }).first()).toBeVisible();
  page.once("dialog", (d) => d.accept());
  await page.getByRole("dialog").getByRole("button", { name: "Retirar solicitud" }).click();
  await expect(page.getByRole("dialog").getByText("Anulada", { exact: true }).first()).toBeVisible();
});

test("E2E-IB-01: chat web nuevo → Ventas lo toma con plantilla, crea lead y actividad, y resuelve", async ({ page }) => {
  await login(page, "carla.ventas@andes.test");
  await page.goto("/inbox");
  await page.getByRole("link", { name: "Simular entrante" }).click();
  const sim = page.getByRole("dialog");
  await sim.getByLabel("Nombre del contacto").fill(`Turista ${run}`);
  await sim.getByLabel("Correo").fill(`turista${run}@correo.test`);
  await sim.getByRole("button", { name: "Simular entrante" }).click();
  await page.waitForURL(/\/inbox\/CNV-\d+/);
  const thread = page.getByRole("region", { name: /Conversación CNV-/ });
  await expect(thread.getByText("quisiera información del tour a Machu Picchu")).toBeVisible();

  await page.getByRole("button", { name: "Tomar conversación" }).click();
  await expect(page.getByText("Carla Mendoza tomó la conversación")).toBeVisible();
  await page.getByLabel("Plantilla").selectOption({ label: "PLT-01 · Saludo inicial" });
  await page.getByRole("button", { name: "Enviar", exact: true }).click();
  await expect(thread.getByText("Hola Turista, soy Carla Mendoza")).toBeVisible();

  await page.getByText("Registrar lead desde la conversación").click();
  await page.getByRole("button", { name: "Crear lead", exact: true }).click();
  await expect(page.getByText("La conversación ya tiene un lead")).toBeVisible();
  await page.getByText("Programar actividad").click();
  await page.getByLabel("Fecha").fill("2026-11-05T10:00");
  await page.getByRole("button", { name: "Crear actividad" }).click();
  await expect(page.getByText("Actividad creada", { exact: true })).toBeVisible();
  await expect(thread.getByText(/Actividad creada: Seguimiento CNV-/)).toBeVisible();

  await thread.getByRole("button", { name: "Resolver", exact: true }).click();
  await expect(thread.getByText("Resuelta", { exact: true })).toBeVisible();
});

test("E2E-IB-02: el pasajero en viaje (WhatsApp) es visible para Operaciones y la nota interna no llega al portal", async ({ page }) => {
  await login(page, "operaciones@andes.test");
  await page.goto("/inbox");
  const list = page.getByRole("complementary", { name: "Conversaciones" });
  await expect(list.getByText("John Miller")).toBeVisible();
  await expect(list.getByText("Pedro Ruiz")).toHaveCount(0);
  await list.getByRole("link").filter({ hasText: "John Miller" }).click();
  await page.getByRole("tab", { name: "Nota interna" }).click();
  const note = `Coordinado con PeruRail ${run}`;
  await page.getByLabel("Nota interna").fill(note);
  await page.getByRole("button", { name: "Agregar nota" }).click();
  await expect(page.locator('[data-direction="internal"]').filter({ hasText: note })).toContainText("no visible para el cliente");

  await login(page, "pasajero@correo.test");
  for (const path of ["/canales/portal-pasajero/viajes", "/canales/portal-pasajero/contacto"]) {
    await page.goto(path);
    await expect(page.getByText(note)).toHaveCount(0);
  }
});

test("Responsive 400 px: bandeja de aprobaciones y conversación sin scroll horizontal", async ({ page }) => {
  await page.setViewportSize({ width: 400, height: 800 });
  await login(page, "gerencia@andes.test");
  await page.goto("/aprobaciones?tab=all");
  await noOverflow(page);
  await page.getByRole("row").filter({ hasText: "COT-2026-00193" }).getByRole("link").first().click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await noOverflow(page);

  await page.goto("/inbox/CNV-0101");
  await noOverflow(page);
  await expect(page.getByRole("complementary", { name: "Conversaciones" })).toBeHidden();
  await page.getByRole("link", { name: "Ficha" }).click();
  await expect(page.getByRole("dialog", { name: /Ficha/ })).toBeVisible();
  await noOverflow(page);
  await page.goto("/inbox/CNV-0101");
  await page.getByRole("link", { name: "← Bandeja" }).click();
  await expect(page.getByRole("complementary", { name: "Conversaciones" })).toBeVisible();
});
