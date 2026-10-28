import { expect, test, type Page } from "@playwright/test";

test.describe.configure({ timeout: 180_000 });

// Spec 17 §10: centro de reglas v2.2, mensajes al pasajero, monedas, reseñas e integración web. Datos: ExperienceDemoSeeder y
// BookingDemoSeeder («Perú Circuito 10 días», PRD-013, cerrado de +10 a +12 días desde hoy).
const PASSWORD = "Turismo2026!";
const PRD = "PRD-013";

async function login(page: Page, email: string) {
  await page.context().clearCookies();
  await page.goto("/login");
  await page.getByLabel("Agencia (tenant)").fill("andes");
  await page.getByLabel("Correo", { exact: true }).fill(email);
  await page.getByLabel("Contraseña").fill(PASSWORD);
  await page.getByRole("button", { name: "Ingresar" }).click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
}

function watchErrors(page: Page) {
  const errors: string[] = [];
  page.on("console", (m) => { if (m.type() === "error") errors.push(m.text()); });
  page.on("pageerror", (e) => errors.push(e.message));
  return errors;
}

/** Avanza el calendario del widget hasta encontrar un día con el estado pedido (máx. 3 meses). */
async function findDay(page: Page, status: string) {
  for (let i = 0; i < 3; i++) {
    const day = page.locator(`[data-day][data-status="${status}"]`).filter({ hasNotText: /^\d+$/ });
    if (await day.count()) return day;
    await page.getByRole("button", { name: "Mes siguiente" }).click();
    await page.waitForTimeout(400);
  }
  throw new Error(`Sin días ${status}`);
}

test("E2E-W1: widget anónimo con alojamiento, adicional, quitar ítem, adelanto y traspaso al checkout", async ({ page }) => {
  const errors = watchErrors(page);
  await page.context().clearCookies();
  await page.goto(`/widget/${PRD}?lang=es&currency=USD`);
  await expect(page.getByRole("heading", { name: "Perú Circuito 10 días" })).toBeVisible();
  const free = await findDay(page, "disponible");
  await free.nth(6).click();

  await page.getByRole("button", { name: "+ Niños" }).click();
  await page.getByRole("button", { name: "+ Infantes" }).click();
  await expect(page.getByLabel("Adultos", { exact: true })).toHaveText("2");
  for (const city of ["Lima", "Cusco", "Puno", "Arequipa"]) {
    await page.getByLabel(`Hotel ${city}`).selectOption({ index: 1 });
  }
  await page.getByLabel(/Museo Larco/).check();
  await expect(page.locator('[data-line^="addon"]')).toContainText("Museo Larco");
  const total = page.locator("[data-total]");
  await expect(page.locator("[data-rooms]")).toContainText("noches");
  const before = await total.innerText();
  const nightsBefore = Number((await page.locator("[data-rooms]").innerText()).match(/(\d+) noches/)![1]);

  const lima = page.locator('[data-line^="lodging"]').filter({ hasText: "Lima" });
  await lima.getByRole("button", { name: "Quitar" }).click();
  await expect(lima).toHaveClass(/line-through/);
  await expect(total).not.toHaveText(before);
  const amount = (s: string) => Number(s.replace(/[^\d.,]/g, "").replace(/,/g, ""));
  expect(amount(await total.innerText())).toBeLessThan(amount(before));
  await expect(page.locator("[data-rooms]")).toContainText(`${nightsBefore - 2} noches`);

  await page.getByLabel("Adelanto").check();
  await expect(page.locator("[data-deposit]")).not.toHaveText(await total.innerText());
  await page.getByRole("button", { name: "Continuar reserva" }).click();
  const handoff = page.locator("[data-handoff]");
  await expect(handoff).toContainText(/WHO-\d+/);
  const code = (await handoff.getAttribute("data-handoff"))!;

  await handoff.getByRole("link", { name: "Ir al checkout" }).click();
  await page.waitForURL(new RegExp(`/sitio/reserva/${code}`));
  await expect(page.locator("[data-handoff-code]")).toHaveText(code);
  await expect(page.locator("[data-checkout-summary]")).toContainText("Museo Larco");
  await page.getByLabel("Nombre completo").fill("Laura Méndez");
  await page.getByLabel("Correo", { exact: true }).fill("laura.mendez@correo.test");
  await page.getByLabel("Teléfono").fill("+51 984 111 222");
  await page.getByLabel("Número", { exact: true }).fill("45879612");
  await page.getByRole("button", { name: "Confirmar reserva" }).click();
  await expect(page.locator("[data-booking]")).toContainText(/RES-\d{4}-\d+/);
  expect(errors).toEqual([]);
});

test("E2E-W2: días cerrados del widget no seleccionables y rotulados «Ocupado»", async ({ page }) => {
  await page.context().clearCookies();
  await page.goto(`/widget/${PRD}?lang=es`);
  await expect(page.locator("[data-legend]")).toContainText("Ocupado del");
  const closed = page.locator('[data-day][data-status="cerrado"]').filter({ hasText: "Ocupado" });
  await expect(closed.first()).toBeVisible();
  expect(await closed.count()).toBeGreaterThanOrEqual(1);
  for (let i = 0; i < await closed.count(); i++) await expect(closed.nth(i)).toBeDisabled();

  // Sin producto válido: lista de productos vendibles.
  await page.goto("/widget/PRD-999");
  await expect(page.getByRole("heading", { name: "Elija su experiencia" })).toBeVisible();
  await expect(page.locator(`[data-product="${PRD}"]`)).toBeVisible();
});

test("E2E-R1: R-PAX-MESSAGES apagada y plantilla «Tour operador receptivo»", async ({ page }) => {
  const errors = watchErrors(page);
  page.on("dialog", (d) => d.accept());
  await login(page, "admin@andes.test");
  await page.goto("/admin/reglas");
  const rule = page.locator('[data-admin-rule="R-PAX-MESSAGES"]');
  await expect(rule).toContainText("Desde Professional");
  await rule.getByRole("button", { name: "Desactivar" }).click();
  await expect(rule.getByText("Regla desactivada")).toBeVisible();

  await page.goto("/admin/comunicaciones?tab=proximos");
  await expect(page.getByText(/R-PAX-MESSAGES\) está desactivada/)).toBeVisible();
  await expect(page.getByText("No hay envíos programados")).toBeVisible();

  await page.goto("/admin/reglas?tab=plantillas");
  const tpl = page.locator('[data-template="receptivo"]');
  await expect(tpl).toContainText("+ Mensajes al pasajero");
  await tpl.getByRole("button", { name: "Aplicar «Tour operador receptivo»" }).click();
  await expect(tpl.getByText("Plantilla aplicada")).toBeVisible();

  await page.goto("/admin/reglas");
  await expect(page.locator('[data-admin-rule="R-REVIEW-INVITE"]')).toContainText("Activa");
  await page.goto("/admin/comunicaciones?tab=proximos");
  await expect(page.getByText(/R-PAX-MESSAGES\) está desactivada/)).toHaveCount(0);
  await expect(page.locator("[data-message]").first()).toBeVisible();

  // Vista previa como plan Starter (solo visual) y simulador con plan a simular.
  await page.goto("/admin/reglas?tab=reglas&plan=starter&fuera=1");
  await expect(page.getByText(/Vista previa como plan Starter/)).toBeVisible();
  await expect(page.locator('[data-admin-rule="R-MULTICURRENCY"]')).toContainText("Disponible desde el plan");
  await expect(page.locator('[data-admin-rule="R-MULTICURRENCY"]').getByRole("link", { name: "Mejorar plan" })).toBeVisible();
  await page.goto(`/admin/reglas?tab=simulador&producto=${PRD}&plan_sim=starter&adt=2`);
  await expect(page.locator('[data-sim-rule="R-MULTICURRENCY"]')).toContainText("Fuera del plan Starter");
  await expect(page.locator("[data-sim-total]")).toBeVisible();
  expect(errors).toEqual([]);
});

test("E2E-M1: Operaciones envía un mensaje manual; no edita plantillas ni entra a reglas", async ({ page }) => {
  await login(page, "operaciones@andes.test");
  await page.goto("/admin/comunicaciones");
  await expect(page.getByRole("button", { name: "Nueva plantilla" })).toBeDisabled();
  await page.getByRole("link", { name: "Envío manual" }).click();
  const dialog = page.getByRole("dialog", { name: "Envío manual" });
  await dialog.getByRole("textbox", { name: "Mensaje" }).fill("Le recordamos llevar su pasaporte y llegar 15 minutos antes al punto de recojo.");
  await dialog.getByRole("button", { name: "Enviar ahora" }).click();
  await expect(dialog.getByText("Mensaje enviado")).toBeVisible();

  await page.goto("/admin/comunicaciones?tab=historial&origen=manual");
  await expect(page.locator("[data-message]").first()).toContainText("manual");
  await page.goto("/admin/reglas");
  await expect(page.getByText("Error 403 · Acceso restringido")).toBeVisible();
});

test("E2E-F1: Finanzas cambia el TC de USD; activar y moneda de cobro deshabilitados", async ({ page }) => {
  await login(page, "finanzas@andes.test");
  await page.goto("/admin/monedas");
  const usd = page.locator('[data-currency="USD"]');
  await expect(usd.getByRole("button", { name: "Desactivar" })).toBeDisabled();
  await expect(usd.getByRole("button", { name: /Sí · quitar/ })).toBeDisabled();
  await usd.getByLabel("TC USD").fill("3.77");
  await usd.getByRole("button", { name: "Guardar" }).click();
  await expect(usd.getByText("TC de USD guardado")).toBeVisible();
  await page.goto("/admin/monedas?moneda=USD");
  await expect(page.locator("[data-fx-date]").first()).toContainText("3.7700");
  await expect(page.locator('[data-currency="USD"]').getByLabel("TC USD")).toHaveValue("3.77");
});

test("E2E-V1: Manager responde una reseña y genera shortcodes de WordPress", async ({ page }) => {
  await login(page, "gerencia@andes.test");
  await page.goto("/canales/resenas?tab=bandeja&estado=sin_responder&fuente=google");
  await page.locator("[data-review] a").first().click();
  const drawer = page.getByRole("dialog");
  await drawer.getByRole("button", { name: "Guardar respuesta (simulado)" }).click();
  await expect(drawer.getByText("Pendiente de publicar")).toBeVisible();
  await page.goto("/canales/resenas?tab=bandeja&estado=pendiente_publicar");
  await expect(page.locator("[data-review]").first()).toContainText("Pendiente de publicar");
  await page.goto("/canales/resenas?tab=conectores");
  await expect(page.getByRole("button", { name: "Sincronizar" }).first()).toBeDisabled();

  await page.goto("/canales/integracion-web");
  await page.getByLabel("Método").selectOption("wordpress");
  await expect(page.locator("pre").first()).toContainText("[qhapaq_booking");
  await expect(page.locator("pre").first()).toContainText("[qhapaq_catalog");
  await page.getByLabel(/^Producto/).selectOption("");
  await page.getByLabel("Categoría del catálogo").selectOption("Aventura");
  await expect(page.locator("pre").first()).toContainText('[qhapaq_catalog category="Aventura"');
  await expect(page.getByRole("button", { name: "Regenerar clave" })).toBeDisabled();
  await expect(page.frameLocator('iframe[title="Vista previa del widget"]').getByRole("heading", { name: "Elija su experiencia" })).toBeVisible();
});

test("Barrido 400/1440 px de las pantallas de la spec 17 sin desborde ni errores", async ({ page }) => {
  const errors = watchErrors(page);
  await login(page, "admin@andes.test");
  const routes = [
    "/admin/reglas", "/admin/reglas?tab=simulador", "/admin/reglas?tab=plantillas", "/admin/reglas?tab=historial", "/admin/reglas?tab=reglas&regla=R-ADDONS",
    "/admin/comunicaciones", "/admin/comunicaciones?tab=proximos", "/admin/comunicaciones?tab=historial", "/admin/monedas",
    "/canales/resenas", "/canales/resenas?tab=conectores", "/canales/resenas?tab=invitaciones", "/canales/resenas?tab=widget",
    "/canales/integracion-web", "/canales/integracion-web?tab=metodos", "/canales/integracion-web?tab=dominios", "/canales/integracion-web?tab=eventos",
    `/widget/${PRD}`, "/widget",
  ];
  for (const width of [400, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    for (const r of routes) {
      await page.goto(r);
      await expect(page.locator("body")).not.toContainText("Application error");
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      expect(overflow, `${r} @${width}px`).toBeLessThanOrEqual(1);
    }
  }
  expect(errors).toEqual([]);
});
