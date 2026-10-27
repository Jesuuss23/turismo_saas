import { execSync } from "node:child_process";
import { expect, test, type Page } from "@playwright/test";

test.describe.configure({ timeout: 180_000 });

// Spec 13 §10: suscripción, API pública y consola de facturación de la plataforma. Datos: database/seeders/PlatformBillingDemoSeeder.php.
const PASSWORD = "Turismo2026!";
const TOTP_SECRET = "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP";
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

function totp() {
  return execSync(`php -r 'require "vendor/autoload.php"; echo (new PragmaRX\\Google2FA\\Google2FA)->getCurrentOtp("${TOTP_SECRET}");'`, { cwd: "../backend" }).toString().trim();
}

async function platformLogin(page: Page) {
  const context = page.context();
  await context.clearCookies();
  const saved = sessions.get("sa");
  if (saved) return context.addCookies(saved);
  await page.goto("/login/plataforma");
  await page.getByLabel("Correo").fill("superadmin@turismo.test");
  await page.getByLabel("Contraseña").fill(PASSWORD);
  await page.getByRole("button", { name: /Ingresar/ }).click();
  await page.waitForURL(/\/mfa/);
  await page.getByLabel(/Código de 6 dígitos/).fill(totp());
  await page.getByRole("button", { name: "Verificar" }).click();
  // Anti-replay del TOTP: si el código ya se usó, se espera la siguiente ventana de 30 s.
  const rejected = page.getByText("El código de verificación no es válido");
  await Promise.race([page.waitForURL((url) => !url.pathname.startsWith("/mfa")), rejected.waitFor()]);
  if (page.url().includes("/mfa")) {
    await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 500);
    await page.getByLabel(/Código de 6 dígitos/).fill(totp());
    await page.getByRole("button", { name: "Verificar" }).click();
    await page.waitForURL((url) => !url.pathname.startsWith("/mfa"));
  }
  sessions.set("sa", await context.cookies());
}

const ok = (page: Page) => page.locator("p.bg-emerald-50").last();
const fail = (page: Page) => page.locator("p.bg-rose-50").last();

test.beforeEach(({ page }) => {
  page.on("dialog", (d) => d.accept());
});

test("PW-SUB-01: avisos de consumo, downgrade bloqueado y paso a anual con factura de prorrateo", async ({ page }) => {
  await login(page, "admin@andes.test");
  await page.goto("/admin/suscripcion");
  const banners = page.locator("[data-banners]");
  await expect(banners).toContainText("Mensajes de WhatsApp: lleva el 83 %");
  await expect(banners).toContainText("Llamadas a la API: lleva el 85 %");

  await page.getByRole("link", { name: "Pasar a Professional" }).click();
  await expect(page.locator("[data-blockers]")).toContainText("No puede pasar a Professional");
  await expect(page.getByRole("button", { name: "Programar cambio" })).toBeDisabled();

  await page.getByRole("link", { name: "Pasar a anual" }).click();
  await expect(page.locator("[data-change-preview]")).toContainText("Nuevo periodo");
  await page.getByRole("button", { name: /Confirmar y pagar/ }).click();
  await page.waitForURL((url) => url.pathname === "/admin/suscripcion" && !url.search);
  await expect(page.getByText("Activa · Anual")).toBeVisible();

  await page.getByRole("link", { name: "Facturas", exact: true }).click();
  const first = page.locator("[data-invoice]").first();
  await expect(first).toContainText("FQ01-");
  await expect(first).toContainText("Prorrateo");
  await expect(first).toContainText("Pagada");
});

test("PW-SUB-02: add-on de almacenamiento sube el medidor a 150 GB; cancelar con encuesta y reanudar", async ({ page }) => {
  await login(page, "admin@andes.test");
  await page.goto("/admin/suscripcion");
  await page.locator('[data-addon="storage_pack"]').getByRole("button", { name: "Contratar" }).click();
  await expect(page.locator('[data-meter="storage_gb"]')).toContainText("/ 150 GB");
  await expect(page.locator('[data-addon="storage_pack"]')).toContainText("Activo");

  await page.getByRole("link", { name: "Cancelar suscripción" }).click();
  await page.getByLabel("Motivo").selectOption("price");
  await page.getByRole("button", { name: "Programar cancelación" }).click();
  await expect(fail(page)).toContainText("SUB-BR-11");
  await page.getByLabel(/Entiendo que la suscripción termina/).check();
  await page.getByRole("button", { name: "Programar cancelación" }).click();
  await expect(page.locator("[data-banners]")).toContainText("Cancelación programada");

  await page.getByRole("button", { name: "Reanudar suscripción" }).click();
  await expect(page.locator("[data-banners]")).not.toContainText("Cancelación programada");
  await expect(page.getByText(/Activa · /).first()).toBeVisible();
});

test("PW-API-01: API key visible una vez, webhook anti-SSRF con secreto y WH-0002 deshabilitado tras los reenvíos", async ({ page }) => {
  await login(page, "admin@andes.test");
  await page.goto("/admin/api");
  await page.getByLabel("Nombre").fill(`ERP e2e ${run}`);
  await page.getByRole("button", { name: "Crear API key" }).click();
  const reveal = page.locator('[data-reveal="token"] code');
  await expect(reveal).toContainText("qts_live_");
  const token = (await reveal.innerText()).trim();
  const row = page.locator("[data-api-key]").filter({ hasText: `ERP e2e ${run}` });
  await expect(row).toContainText(token.slice(0, 13));
  await expect(row).not.toContainText(token);
  await page.reload();
  await expect(page.locator("body")).not.toContainText(token);

  await page.goto("/admin/api/webhooks");
  const url = page.getByLabel("URL (HTTPS)").last();
  await url.fill("https://10.0.0.5/hook");
  await page.getByRole("button", { name: "Crear webhook" }).click();
  await expect(fail(page)).toBeVisible();
  await url.fill(`https://webhook.test/e2e-${run}`);
  await page.getByRole("button", { name: "Crear webhook" }).click();
  await expect(page.locator('[data-reveal="secret"] code')).toContainText("whsec_");
  await expect(page.getByRole("heading", { name: `https://webhook.test/e2e-${run}` })).toBeVisible();

  await page.locator('[data-webhook="WH-0002"]').getByRole("link", { name: "Ver entregas" }).click();
  for (let i = 0; i < 3; i++) {
    await page.locator("[data-delivery]").first().getByRole("button", { name: "Reenviar" }).click();
    await page.waitForLoadState("networkidle");
    await page.goto("/admin/api/webhooks");
    if (await page.locator('[data-webhook="WH-0002"]').getByText("Deshabilitado:").isVisible()) break;
    await page.goBack();
  }
  await expect(page.locator('[data-webhook="WH-0002"]')).toContainText("Deshabilitado: 10 fallos consecutivos");
});

test("PW-PLAT-01: cobranza reprograma TNT-0004, trial extendido y convertido, cupón con tope 50 % y e-commerce en Professional", async ({ page }) => {
  await platformLogin(page);
  await page.goto("/admin/plataforma/facturacion/cobranza");
  await page.getByRole("button", { name: "Ejecutar ahora" }).click();
  // Falló hace 3 días: el tercer reintento (D+7) queda en 4 días.
  const d = new Date(Date.now() + 4 * 86_400_000).toLocaleDateString("es-PE", { day: "2-digit", month: "2-digit", timeZone: "America/Lima" });
  await expect(ok(page)).toContainText(`(TNT-0004): reprogramada al ${d}`);

  await page.goto("/admin/plataforma/facturacion/trials");
  const trial = page.locator('[data-trial="TNT-0005"]');
  const before = await trial.locator("[data-trial-end]").innerText();
  await trial.getByRole("button", { name: "Extender 7 días" }).click();
  await expect(trial.locator("[data-trial-end]")).not.toHaveText(before);
  await expect(trial).toContainText("Extendido +7 días");
  await trial.getByLabel("Cupón").fill("BIENVENIDA25");
  await trial.getByRole("button", { name: "Convertir" }).click();
  await expect(page.locator('[data-trial="TNT-0005"]')).toHaveCount(0);

  const code = `VERANO${run.slice(-2)}`;
  await page.goto("/admin/plataforma/facturacion/cupones");
  await page.getByLabel("Código").fill(code);
  await page.getByLabel("Valor").fill("60");
  await page.getByLabel("Vigente hasta").fill(new Date(Date.now() + 90 * 86_400_000).toISOString().slice(0, 10));
  await page.getByRole("button", { name: "Crear cupón" }).click();
  await expect(fail(page)).toContainText("SUB-BR-08");
  await page.getByLabel("Valor").fill("25");
  await page.getByRole("button", { name: "Crear cupón" }).click();
  await expect(page.locator(`[data-coupon="${code}"]`)).toContainText("25 % por 1 ciclo(s)");

  await page.goto("/admin/plataforma/facturacion/flags");
  const flag = page.locator('[data-flag="ecommerce:professional"]');
  await flag.getByRole("combobox").selectOption("included");
  await flag.getByRole("button", { name: "Guardar" }).click();
  await expect(page.locator('[data-flag="ecommerce:professional"]')).toContainText("Incluido");
});

test("PW-RBAC-01: Gerencia y Super Admin no acceden a pantallas ajenas; sin scroll horizontal a 400 px", async ({ page }) => {
  await page.setViewportSize({ width: 400, height: 800 });
  const noScroll = async (path: string) =>
    expect(await page.evaluate(() => document.documentElement.scrollWidth), `scroll horizontal en ${path}`).toBeLessThanOrEqual(401);

  await login(page, "gerencia@andes.test");
  for (const path of ["/admin/suscripcion", "/admin/api", "/admin/plataforma/facturacion"]) {
    await page.goto(path);
    await expect(page.getByRole("heading", { name: "No tiene acceso a esta pantalla" })).toBeVisible();
  }
  await page.goto("/dashboard");
  await expect(page.locator('a[href="/admin/suscripcion"]')).toHaveCount(0);
  await expect(page.locator('a[href="/admin/api"]')).toHaveCount(0);

  await login(page, "admin@andes.test");
  for (const path of ["/admin/suscripcion", "/admin/suscripcion?plan=professional&cycle=monthly", "/admin/suscripcion/facturas", "/admin/suscripcion/pago",
    "/admin/suscripcion/historial", "/admin/api", "/admin/api/webhooks", "/admin/api/entregas", "/admin/api/limites", "/admin/api/docs", "/admin/api/oauth"]) {
    await page.goto(path);
    await expect(page.locator("h1").first()).not.toHaveText("No tiene acceso a esta pantalla");
    await noScroll(path);
  }

  await platformLogin(page);
  for (const path of ["/admin/suscripcion", "/admin/api"]) {
    await page.goto(path);
    await expect(page.getByRole("heading", { name: "No tiene acceso a esta pantalla" })).toBeVisible();
  }
  for (const tab of ["", "/facturas", "/cobranza", "/trials", "/cupones", "/planes", "/flags", "/uso"]) {
    const path = `/admin/plataforma/facturacion${tab}`;
    await page.goto(path);
    await expect(page.getByRole("heading", { name: "Facturación SaaS" })).toBeVisible();
    await noScroll(path);
  }
});
