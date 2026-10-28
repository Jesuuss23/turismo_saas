import { expect, test, type Page } from "@playwright/test";

test.describe.configure({ timeout: 180_000, mode: "serial" });

// Spec 18 §10: reseñas propias verificadas. Datos: OwnReviewDemoSeeder (RVO-0001…0012, SRV-0001…0006) y John Miller (U15) en
// RES-2026-000354, con el traslado de llegada (PRD-009) ya realizado y el City Tour de hoy aún por hacer.
const PASSWORD = "Turismo2026!";
const BASE = "/canales/resenas-propias";
const PNG = Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==", "base64");

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

test("E2E-RV1: el pasajero ve el aviso y califica el traslado (5★, aspecto, foto, consentimiento) → «En revisión»", async ({ page }) => {
  const errors = watchErrors(page);
  await login(page, "pasajero@correo.test");
  await page.goto("/canales/portal-pasajero/viajes");
  await expect(page.locator("[data-rate-notice]")).toContainText(/Califica tu viaje: tiene \d+ servicio\(s\) pendiente/);
  await page.getByRole("link", { name: "Calificar ahora" }).click();
  await expect(page).toHaveURL(/portal-pasajero\/resena/);

  const form = page.locator("[data-review-form]");
  // El traslado de llegada ya se realizó; el City Tour de hoy aparece solo si otra prueba ya lo completó.
  await form.getByLabel("Servicio").selectOption("PRD-009");
  await expect(form.getByRole("checkbox")).not.toBeChecked();
  await form.getByLabel("5 estrellas").check({ force: true });
  await form.getByLabel("Guía").selectOption("5");
  await form.locator('input[name="title"]').fill("Traslado puntual");
  await form.locator('textarea[name="body"]').fill("Nos esperaban en el aeropuerto con un cartel y el vehículo estaba impecable.");
  await form.locator('input[name="photos"]').setInputFiles({ name: "llegada.png", mimeType: "image/png", buffer: PNG });
  await form.getByRole("checkbox").check();
  await form.getByRole("button", { name: "Enviar reseña" }).click();
  await expect(page.locator("[data-review-sent]")).toContainText("En revisión");

  await page.reload();
  await expect(page.locator("[data-my-review]").filter({ hasText: "Traslado puntual" })).toContainText("En revisión");
  await expect(page.locator('[data-review-form] option[value="PRD-009"]')).toHaveCount(0);
  expect(errors).toEqual([]);
});

test("E2E-RV2: Ventas (U03) ve solo su cartera, sin acciones, y no abre una reseña ajena", async ({ page }) => {
  await login(page, "carla.ventas@andes.test");
  await page.goto(`${BASE}?tab=bandeja`);
  await expect(page.getByText("Mi cartera", { exact: false }).first()).toBeVisible();
  const valeria = page.locator('[data-review="RVO-0009"]');
  await expect(valeria.locator("[data-flags]")).toContainText("correo");
  await expect(valeria.getByRole("button", { name: "Publicar" })).toBeDisabled();
  // La reseña nueva de John Miller (CLI-0003) es de su cartera: la ve, pero sin poder moderarla.
  await expect(page.locator('[data-review="RVO-0013"]')).toContainText("Traslado puntual");

  await page.goto(`${BASE}?tab=todas`);
  await expect(page.locator('[data-review="RVO-0001"]')).toBeVisible();
  await expect(page.locator('[data-review="RVO-0004"]')).toHaveCount(0);
  await page.goto(`${BASE}/RVO-0004`);
  await expect(page.locator("[data-toast]")).toContainText("otra cartera");
  await expect(page.getByRole("link", { name: "Regla R-OWN-REVIEWS" })).toHaveCount(0);
});

test("E2E-RV3: el admin publica la reseña nueva, responde, rechaza con motivo y exporta al widget", async ({ page }) => {
  const errors = watchErrors(page);
  page.on("dialog", (d) => d.accept());
  await login(page, "admin@andes.test");
  await page.goto(`${BASE}?tab=bandeja`);
  const fresh = page.locator("[data-review]").filter({ hasText: "Traslado puntual" });
  await expect(fresh).toContainText("RES-2026-000354");
  await fresh.getByRole("button", { name: "Publicar" }).click();
  await expect(page.locator("[data-review]").filter({ hasText: "Traslado puntual" })).toHaveCount(0);

  // Retenida por correo en el texto (RVW-06): publicarla pide confirmación; aquí se rechaza con motivo (RVW-07).
  const flagged = page.locator('[data-review="RVO-0009"]');
  await flagged.getByText("Rechazar…").click();
  await flagged.getByLabel("Motivo (rechazar)").fill("Incluye datos personales");
  await flagged.getByRole("button", { name: "Rechazar con motivo" }).click();
  await expect(page.locator('[data-review="RVO-0009"]')).toHaveCount(0);

  await page.goto(`${BASE}?tab=todas&q=Traslado`);
  await page.locator("[data-review]").filter({ hasText: "Traslado puntual" }).getByRole("link", { name: /Detalle/ }).click();
  const drawer = page.locator("[data-drawer]");
  await expect(drawer).toContainText("Fotos: llegada.png");
  await drawer.getByLabel(/Respuesta pública/).fill("¡Gracias, John! Nos alegra que el traslado haya salido puntual.");
  await drawer.getByRole("button", { name: "Responder" }).click();
  await expect(page.locator("[data-drawer] [data-reply]")).toContainText("Nos alegra que el traslado");
  await expect(page.locator("[data-drawer] [data-log]")).toContainText("responder");

  await page.goto(BASE);
  await page.getByRole("button", { name: "Exportar al widget / sitio" }).click();
  await expect(page.getByText(/Feed regenerado: \d+ reseña\(s\) publicadas/)).toBeVisible();
  expect(errors).toEqual([]);
});

test("E2E-RV4: el pasajero ve la respuesta de la agencia", async ({ page }) => {
  await login(page, "pasajero@correo.test");
  await page.goto("/canales/portal-pasajero/resena");
  const mine = page.locator("[data-my-review]").filter({ hasText: "Traslado puntual" });
  await expect(mine).toContainText("Publicada");
  await expect(mine.locator("[data-agency-reply]")).toContainText("Nos alegra que el traslado");
});

test("E2E-RV5: sitio anónimo a 400 px: bloque verificado, insignia, filtro por estrellas y aggregateRating", async ({ page }) => {
  await page.setViewportSize({ width: 400, height: 860 });
  await page.context().clearCookies();
  await page.goto("/sitio/tours/machu-picchu-full-day");
  await expect(page.locator("[data-rating-chip]")).toContainText("Viaje verificado");
  const block = page.getByTestId("verified-reviews");
  await expect(block.locator("[data-public-review]").first()).toBeVisible();
  await expect(block.locator("[data-verified-badge]").first()).toBeVisible();
  await expect(block).toContainText("Thomas K.");
  await expect(block).not.toContainText("Keller");
  await block.locator('[data-star-filter="5"]').click();
  await expect(page).toHaveURL(/estrellas=5/);
  for (const s of await block.locator("[data-public-review] [aria-label$='/ 5']").allInnerTexts()) expect(s).toBe("★★★★★");

  const lds = await page.locator('script[type="application/ld+json"]').allTextContents();
  const product = lds.map((l) => JSON.parse(l)).find((j) => [j["@type"]].flat().includes("Product"));
  expect(product.aggregateRating).toMatchObject({ "@type": "AggregateRating", ratingValue: 5, reviewCount: 2 });
  expect(product.review.length).toBeGreaterThan(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

  await page.goto("/sitio");
  await expect(page.getByTestId("home-verified-reviews").locator("[data-public-review]")).toHaveCount(3);

  // Enlace de la invitación: token inválido → aviso claro.
  await page.goto("/sitio/resena/SRV-0002?t=otro");
  await expect(page.locator("[data-token-error]")).toContainText("no es válido");
});

test("E2E-RV6: regla apagada → desaparecen formulario, bloque, JSON-LD y la bandeja; Starter lo marca fuera del plan", async ({ page }) => {
  page.on("dialog", (d) => d.accept());
  await login(page, "admin@andes.test");
  await page.goto("/admin/reglas?tab=reglas&plan=starter&fuera=1");
  await expect(page.locator('[data-admin-rule="R-OWN-REVIEWS"]')).toContainText("Disponible desde el plan");

  await page.goto("/admin/reglas");
  const rule = page.locator('[data-admin-rule="R-OWN-REVIEWS"]');
  await rule.getByRole("button", { name: "Desactivar" }).click();
  await expect(rule.getByText("Regla desactivada")).toBeVisible();
  await page.goto(BASE);
  await expect(page.locator("[data-rule-off]")).toContainText("R-OWN-REVIEWS está desactivada");

  await page.context().clearCookies();
  await page.goto("/sitio/tours/machu-picchu-full-day");
  await expect(page.getByTestId("verified-reviews")).toHaveCount(0);
  await expect(page.locator("[data-rating-chip]")).toHaveCount(0);
  const lds = await page.locator('script[type="application/ld+json"]').allTextContents();
  expect(lds.join("")).not.toContain("aggregateRating");

  await login(page, "pasajero@correo.test");
  await page.goto("/canales/portal-pasajero/viajes");
  await expect(page.getByRole("link", { name: "Califica tu viaje" })).toHaveCount(0);
  await expect(page.locator("[data-rate-notice]")).toHaveCount(0);

  await login(page, "admin@andes.test");
  await page.goto("/admin/reglas");
  await page.locator('[data-admin-rule="R-OWN-REVIEWS"]').getByRole("button", { name: "Activar" }).click();
  await expect(page.locator('[data-admin-rule="R-OWN-REVIEWS"]')).toContainText("Activa");
});
