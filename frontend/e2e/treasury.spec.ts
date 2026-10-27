import { expect, test, type Page } from "@playwright/test";

test.describe.configure({ timeout: 200_000 });

// Spec 12 §10: tesorería (caja y bancos) y libro de reclamaciones virtual. Datos: database/seeders/TreasuryDemoSeeder.php.
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

/** "S/ 1,234.50" → 1234.5 */
function money(text: string) {
  return Number(text.replace(/[^\d.-]/g, ""));
}

/** Cantidades por denominación (de mayor a menor) que suman exactamente `total`. */
function breakdown(total: number) {
  let cents = Math.round(total * 100);
  const out: Record<string, number> = {};
  for (const d of ["200", "100", "50", "20", "10", "5", "2", "1", "0.5", "0.2", "0.1"]) {
    const v = Math.round(Number(d) * 100);
    const n = Math.floor(cents / v);
    if (n > 0) out[d] = n;
    cents -= n * v;
  }
  expect(cents, "monto múltiplo de S/ 0.10").toBe(0);
  return out;
}

const caja = (page: Page, code: string) => page.locator(`[data-caja="${code}"]`);

test("TES-E2E-01: gasto en Caja Cusco, cierre cuadrado, reposición aprobada por Gerencia y pagada por Finanzas", async ({ page }) => {
  await login(page, "finanzas@andes.test");
  await page.goto("/finanzas/tesoreria/cajas?caja=CTA-05&accion=gasto");
  const drawer = page.getByRole("dialog");
  await drawer.getByLabel("Monto del gasto").fill("35.50");
  await drawer.getByLabel("Serie y número").fill(`B001-${run}`);
  await drawer.getByLabel("Proveedor o beneficiario").fill("Taxi Cusco Seguro");
  await drawer.getByLabel("Descripción del gasto").fill(`Movilidad al aeropuerto e2e ${run}`);
  await drawer.getByRole("button", { name: "Registrar gasto" }).click();
  await expect(drawer.getByText(/Gasto MOV-\d+ registrado/)).toBeVisible();

  // Cierre con arqueo cuadrado: se cuenta exactamente el saldo esperado.
  await page.goto("/finanzas/tesoreria/cajas?caja=CTA-05&accion=cierre");
  const summary = await page.getByTestId("count-summary").innerText();
  const expected = money(summary.split("esperado")[1].split("·")[0]);
  for (const [d, n] of Object.entries(breakdown(expected))) await page.getByLabel(`Cantidad de S/ ${d}`, { exact: true }).fill(String(n));
  await expect(page.getByTestId("count-summary")).toContainText("diferencia S/ 0.00");
  await page.getByRole("button", { name: "Firmar acta y cerrar caja" }).click();
  await expect(page.getByRole("dialog").getByText(/ARQ-\d+/).first()).toBeVisible();

  await page.goto("/finanzas/tesoreria/cajas?caja=CTA-05&accion=reposicion");
  await expect(page.getByRole("dialog")).toContainText(`Movilidad al aeropuerto e2e ${run}`);
  await page.getByRole("dialog").getByRole("button", { name: "Solicitar reposición" }).click();
  await expect(page.getByRole("dialog").getByText("Reposición solicitada")).toBeVisible();
  await page.goto("/finanzas/tesoreria/cajas");
  const rep = (await caja(page, "CTA-05").getByText(/^REP-\d+$/).innerText()).trim();
  // TES-BR-14: quien solicita no aprueba.
  await expect(caja(page, "CTA-05").getByRole("button", { name: `Aprobar ${rep}` })).toBeDisabled();

  await login(page, "gerencia@andes.test");
  await page.goto("/finanzas/tesoreria/cajas");
  await caja(page, "CTA-05").getByRole("button", { name: `Aprobar ${rep}` }).click();
  await expect(caja(page, "CTA-05").getByText(/Aprobada · el pago lo registra Finanzas/)).toBeVisible();

  await login(page, "finanzas@andes.test");
  await page.goto("/finanzas/tesoreria/cajas");
  const card = caja(page, "CTA-05");
  await card.getByLabel(`N° de operación de ${rep}`).fill(`OP-${run}`);
  await card.getByRole("button", { name: `Pagar ${rep}` }).click();
  await expect(card.getByRole("button", { name: `Pagar ${rep}` })).toBeHidden();
  await expect(caja(page, "CTA-05").getByTestId("caja-balance")).toHaveText("S/ 1,500.00");
});

test("TES-E2E-02: Gerencia supervisa sin registrar y aprueba la diferencia de ARQ-0040", async ({ page }) => {
  await login(page, "gerencia@andes.test");
  await page.goto("/finanzas/tesoreria/cajas");
  await expect(page.getByRole("note")).toContainText("Modo supervisión");
  for (const name of ["Gasto", "Arqueo", "Aperturar"]) await expect(caja(page, "CTA-04").getByRole("button", { name, exact: true })).toBeDisabled();
  const row = page.locator('[data-count="ARQ-0040"]');
  await expect(row).toContainText("Pendiente de aprobación");
  await row.getByRole("button", { name: "Aprobar ARQ-0040" }).click();
  await expect(page.locator('[data-count="ARQ-0040"]')).toContainText("Aprobó Martín Quispe");
});

test("TES-E2E-03: Contabilidad registra la diferencia cambiaria del periodo y la segunda vez recibe un aviso", async ({ page }) => {
  await login(page, "contabilidad@andes.test");
  await page.goto("/finanzas/tesoreria/cambio");
  page.on("dialog", (d) => d.accept());
  await expect(page.getByText("Pendiente", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: /Registrar diferencia de/ }).click();
  await expect(page.getByText("Diferencia cambiaria registrada")).toBeVisible();
  await page.goto("/finanzas/tesoreria/cambio");
  await expect(page.getByText(/ya está registrada \(TES-BR-16\)/)).toBeVisible();
  await page.getByRole("button", { name: /Registrar diferencia de/ }).click();
  await expect(page.getByText(/^La diferencia cambiaria de \d{4}-\d{2} ya está registrada\.$/)).toBeVisible();
});

test("LR-E2E-01: la clienta envía una hoja desde el portal, Gerencia responde y cierra, y ella ve la respuesta", async ({ page }) => {
  await login(page, "cliente@correo.test");
  await page.goto("/libro-reclamaciones");
  const form = page.getByRole("form", { name: "Hoja de reclamación" });
  await expect(form.getByLabel("Nombre completo")).toHaveValue("Ana Gutiérrez");
  if (!(await form.getByLabel("Domicilio").inputValue())) await form.getByLabel("Domicilio").fill("Av. Arequipa 1234, Lima");
  await form.getByLabel("Descripción del bien").fill("Paquete Cusco clásico 4 días");
  await form.getByLabel("Detalle").fill(`El traslado del hotel llegó con una hora de retraso (e2e ${run}).`);
  await form.getByLabel("Pedido").fill("Compensación por el retraso");
  await form.getByLabel(/Acepto recibir/).check();
  await form.getByRole("button", { name: "Enviar hoja de reclamación" }).click();
  const ok = page.getByRole("status").filter({ hasText: "registrada" });
  await expect(ok).toBeVisible();
  const number = (await ok.innerText()).match(/LR-LIM-[\w-]+/)![0];
  await expect(page.locator(`[data-claim="${number}"]`)).toContainText(`e2e ${run}`);

  await login(page, "gerencia@andes.test");
  await page.goto("/crm/reclamaciones");
  await page.getByRole("link", { name: number }).first().click();
  await expect(page.getByRole("heading", { name: new RegExp(number) })).toBeVisible();
  await page.getByLabel("Decisión").selectOption({ index: 0 });
  await page.getByLabel(/Respuesta motivada/).fill("Lamentamos el retraso del traslado; reembolsaremos el 50 % del servicio de traslado en su medio de pago.");
  await page.getByLabel("Acciones adoptadas").fill("Reembolso parcial y llamada al proveedor de transporte.");
  await page.getByRole("button", { name: "Responder y enviar" }).click();
  await expect(page.getByRole("button", { name: "Reenviar respuesta" })).toBeVisible();
  await page.getByLabel("Motivo de cierre").fill("Reembolso aceptado por la clienta");
  await page.getByRole("button", { name: "Cerrar hoja" }).click();
  await expect(page.getByText("Hoja cerrada")).toBeVisible();

  await login(page, "cliente@correo.test");
  await page.goto("/libro-reclamaciones");
  const mine = page.locator(`[data-claim="${number}"]`);
  await expect(mine).toContainText("Cerrada");
  await expect(mine).toContainText("reembolsaremos el 50 %");
});

test("LR-E2E-02: hoja del pasajero con la reserva precargada; un interno sin crm.claims.manage no entra al libro", async ({ page }) => {
  await login(page, "pasajero@correo.test");
  await page.goto("/libro-reclamaciones");
  const form = page.getByRole("form", { name: "Hoja de reclamación" });
  await expect(form.getByLabel("Nombre completo")).toHaveValue("John Miller");
  await expect(form.getByText(/^Se vinculará a su reserva RES-\d{4}-\d{6}\.$/)).toBeVisible();
  await expect(form.getByLabel("Descripción del bien")).not.toHaveValue("");

  await login(page, "finanzas@andes.test");
  await page.goto("/crm/reclamaciones");
  await expect(page.getByRole("heading", { name: "No tiene acceso a esta pantalla" })).toBeVisible();
});

test("Responsive: tesorería y libro sin scroll horizontal a 400 px", async ({ page }) => {
  await page.setViewportSize({ width: 400, height: 800 });
  await login(page, "admin@andes.test");
  for (const url of ["/finanzas/tesoreria", "/finanzas/tesoreria/movimientos", "/finanzas/tesoreria/cajas", "/finanzas/tesoreria/flujo", "/finanzas/tesoreria/cambio",
    "/finanzas/tesoreria/cuentas/CTA-01", "/crm/reclamaciones", "/crm/reclamaciones/reportes", "/crm/reclamaciones/configuracion", "/libro-reclamaciones"]) {
    await page.goto(url);
    expect(await page.evaluate(() => document.documentElement.scrollWidth), url).toBeLessThanOrEqual(401);
  }
  await login(page, "cliente@correo.test");
  await page.goto("/libro-reclamaciones");
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(401);
});
