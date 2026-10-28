import { expect, test, type Page } from "@playwright/test";

test.describe.configure({ timeout: 180_000 });

// Spec 16 §10.3: motor de reservas y centro de reglas. Datos: database/seeders/BookingDemoSeeder.php («Perú Circuito 10 días», PRD-013,
// rangos relativos a hoy: +5…+9 disponible 12/día, +10…+12 cerrado, +13…+17 disponible 12/día y +18…+120 disponible 8/día).
const PASSWORD = "Turismo2026!";
const PRD = "PRD-013";
const BASE = `/catalogo/productos/${PRD}/reservas`;

async function login(page: Page, email: string) {
  await page.context().clearCookies();
  await page.goto("/login");
  await page.getByLabel("Agencia (tenant)").fill("andes");
  await page.getByLabel("Correo", { exact: true }).fill(email);
  await page.getByLabel("Contraseña").fill(PASSWORD);
  await page.getByRole("button", { name: "Ingresar" }).click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
}

function addDays(iso: string, n: number) {
  const d = new Date(`${iso}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

/** «Hoy» del tenant: el mínimo del formulario «Nuevo rango». */
async function today(page: Page) {
  return (await page.locator('input[name="date_from"]').getAttribute("min"))!;
}

function form(page: Page, submit: string) {
  return page.locator("form").filter({ has: page.getByRole("button", { name: submit, exact: true }) });
}

test("RES-E2E-01..03: leyenda, rangos (CAT-AV-01) y cierre rápido de fechas", async ({ page }) => {
  const errors: string[] = [];
  page.on("console", (m) => { if (m.type() === "error") errors.push(m.text()); });
  await login(page, "admin@andes.test");
  await page.goto(BASE);
  await expect(page.locator("[data-legend]")).toContainText(/Disponible del \d{2} al \d{2} \w+ \(12 cupos\/día\) · Ocupado del \d{2}/);
  const t = await today(page);

  const range = form(page, "Nuevo rango");
  const create = async (from: string, to: string, status: string, cap?: number) => {
    await range.locator('input[name="date_from"]').fill(from);
    await range.locator('input[name="date_to"]').fill(to);
    await range.locator('select[name="status"]').selectOption(status);
    await range.locator('input[name="capacity_per_day"]').fill(cap ? String(cap) : "");
    await range.getByRole("button", { name: "Nuevo rango" }).click();
  };
  await create(addDays(t, 8), addDays(t, 14), "disponible", 10);
  await expect(range.getByText(/CAT-AV-01/)).toBeVisible();
  await create(addDays(t, 15), addDays(t, 15), "cerrado");
  await expect(range.getByText(/Rango RG-\d+ creado/)).toBeVisible();
  await create(addDays(t, 11), addDays(t, 13), "cerrado");
  await expect(range.getByText(/CAT-AV-01/)).toBeVisible();

  // Cerrar fechas 10, 11 y 13 del mes subsiguiente (dentro del rango +18…+120): dos bloques contiguos ⇒ 2 rangos.
  const month = addDays(`${t.slice(0, 7)}-15`, 61).slice(0, 7);
  await page.goto(`${BASE}?mes=${month}&pax=0`);
  await page.getByRole("button", { name: "Cerrar fechas" }).click();
  for (const d of ["10", "11", "13"]) await page.locator(`[data-date="${month}-${d}"]`).click();
  await page.getByLabel("Motivo del cierre").fill("Feriado local");
  await page.getByRole("button", { name: "Cerrar 3 fecha(s)" }).click();
  await expect(page.getByText("3 fecha(s) cerradas en 2 rango(s)")).toBeVisible();
  await expect(page.locator(`[data-date="${month}-11"]`)).toHaveAttribute("data-status", "cerrado");
  await page.goto(BASE);
  await expect(page.locator("[data-legend]")).toContainText("Ocupado");
  expect(errors).toEqual([]);
});

test("RES-E2E-04: pasajeros (simulador, CAT-PX-01 y guardado)", async ({ page }) => {
  await login(page, "admin@andes.test");
  await page.goto(`${BASE}/pasajeros`);
  await page.getByLabel("Adultos", { exact: true }).fill("1");
  await page.getByLabel("Niños", { exact: true }).fill("3");
  await page.getByLabel("Infantes", { exact: true }).fill("2");
  await page.getByRole("button", { name: "Simular" }).click();
  await expect(page.locator("[data-pax-errors] li")).toHaveCount(2);

  const pax = form(page, "Guardar pasajeros");
  await pax.getByLabel("Niño desde").fill("2");
  await pax.getByRole("button", { name: "Guardar pasajeros" }).click();
  await expect(pax.getByText(/CAT-PX-01/)).toBeVisible();
  await pax.getByLabel("Niño desde").fill("3");
  await pax.getByLabel("Mínimo de adultos").fill("1");
  await pax.getByRole("button", { name: "Guardar pasajeros" }).click();
  await expect(pax.getByText("Reglas de pasajeros guardadas")).toBeVisible();
});

test("RES-E2E-05: adicionales, hotel, pago y override de R-ADDONS con Reactivar", async ({ page }) => {
  await login(page, "admin@andes.test");
  await page.goto(`${BASE}/adicionales`);
  const addon = form(page, "Agregar adicional");
  await addon.getByLabel("Nombre").fill("Clase de cocina peruana");
  await addon.getByLabel("Precio").fill("55");
  await addon.getByRole("button", { name: "Agregar adicional" }).click();
  await expect(addon.getByText("Adicional creado")).toBeVisible();
  await page.reload();
  await expect(page.getByRole("cell", { name: "Clase de cocina peruana" })).toBeVisible();

  await page.goto(`${BASE}/alojamiento`);
  await expect(page.locator("[data-lodging-example]")).toContainText("habitación(es) · 9 noche(s)");
  await page.getByText("Hotel nuevo en Lima").click();
  const hotel = form(page, "Agregar hotel").first();
  await hotel.getByLabel("Hotel").fill("Hotel Barranco Boutique");
  await hotel.getByLabel("Precio por noche 2").fill("95");
  await hotel.getByRole("button", { name: "Agregar hotel" }).click();
  await expect(hotel.getByText("Hotel agregado")).toBeVisible();

  await page.goto(`${BASE}/pago`);
  const pay = form(page, "Guardar pago");
  await pay.getByLabel(/% de adelanto/).fill("40");
  await pay.getByRole("button", { name: "Guardar pago" }).click();
  await expect(pay.getByText("Política de pago guardada")).toBeVisible();
  await page.reload();
  await expect(page.getByRole("cell", { name: /\(40(\.00)? %\)/ })).toBeVisible();

  await page.goto(`${BASE}/reglas`);
  await page.locator('[data-rule="R-ADDONS"]').getByRole("button", { name: "No aplicar" }).click();
  await expect(page.locator('[data-rule="R-ADDONS"]').getByRole("button", { name: "Aplicar", exact: true })).toBeVisible();
  await page.goto(`${BASE}/adicionales`);
  await expect(page.getByRole("link", { name: "Adicionales (desactivada)" })).toBeVisible();
  await expect(page.getByText("Desactivada solo para este producto.")).toBeVisible();
  await page.getByRole("button", { name: "Reactivar" }).click();
  await expect(page.getByText("Desactivada solo para este producto.")).toHaveCount(0);
  await expect(page.getByRole("link", { name: "Adicionales", exact: true })).toBeVisible();
});

test("RES-E2E-06: R-LODGING apagada en el centro de reglas ⇒ «Regla desactivada para este tenant»", async ({ page }) => {
  await login(page, "admin@andes.test");
  await page.goto("/admin/reglas");
  await expect(page.getByRole("heading", { name: "Reglas de reserva" })).toBeVisible();
  const row = page.locator('[data-admin-rule="R-LODGING"]');
  await row.getByRole("button", { name: "Desactivar" }).click();
  await expect(row.getByText("Regla desactivada")).toBeVisible();

  await page.goto(`${BASE}/alojamiento`);
  await expect(page.getByText(/Regla desactivada para este tenant/)).toBeVisible();
  await expect(page.getByRole("link", { name: "Alojamiento (desactivada)" })).toBeVisible();

  // Regla propia del tenant: queda registrada pero el motor no la evalúa.
  await page.goto("/admin/reglas");
  const add = form(page, "Agregar regla");
  await add.getByLabel("Clave (R-…)").fill(`R-DEMO-${Date.now().toString().slice(-5)}`);
  await add.getByLabel("Grupo").selectOption("Checkout");
  await add.getByLabel("Nombre", { exact: true }).fill("Pedir alergias");
  await add.getByLabel("Descripción").fill("Pregunta por alergias alimentarias en el checkout.");
  await add.getByRole("button", { name: "Agregar regla" }).click();
  await expect(add.getByText("Regla creada")).toBeVisible();
  await page.reload();
  await expect(page.getByText("propia · no evaluada").first()).toBeVisible();

  await page.locator('[data-admin-rule="R-LODGING"]').getByRole("button", { name: "Activar" }).click();
  await expect(page.locator('[data-admin-rule="R-LODGING"]').getByText("Regla activada")).toBeVisible();
});

test("RES-E2E-07: permisos por rol y 400 px sin scroll horizontal", async ({ page }) => {
  // U05 Operaciones: solo disponibilidad y recojo.
  await login(page, "operaciones@andes.test");
  await page.goto(BASE);
  for (const name of ["Cerrar fechas", "Guardar operación", "Nuevo rango"]) await expect(page.getByRole("button", { name, exact: true })).toBeVisible();
  await page.goto(`${BASE}/recojo`);
  await expect(page.getByRole("button", { name: "Guardar recojo" })).toBeVisible();
  for (const [sub, name] of [["pasajeros", "Guardar pasajeros"], ["adicionales", "Agregar adicional"], ["pago", "Guardar pago"]]) {
    await page.goto(`${BASE}/${sub}`);
    await expect(page.getByRole("button", { name })).toHaveCount(0);
  }

  // U02 Gerencia: todo el producto, sin centro de reglas.
  await login(page, "gerencia@andes.test");
  await page.goto(`${BASE}/pasajeros`);
  await expect(page.getByRole("button", { name: "Guardar pasajeros" })).toBeVisible();
  await page.goto(`${BASE}/pago`);
  await expect(page.getByRole("button", { name: "Guardar pago" })).toBeVisible();
  await page.goto(`${BASE}/reglas`);
  await expect(page.locator('[data-rule="R-ADDONS"]').getByRole("button", { name: /^(No aplicar|Aplicar)$/ })).toBeVisible();
  await expect(page.getByRole("link", { name: "centro de reglas" })).toHaveCount(0);
  await page.goto("/admin/reglas");
  await expect(page.getByRole("button", { name: "Agregar regla" })).toHaveCount(0);

  // U03 Ventas: consulta sin botones de edición.
  await login(page, "carla.ventas@andes.test");
  await page.setViewportSize({ width: 400, height: 900 });
  await page.goto(BASE);
  await expect(page.getByText("Modo consulta · la configuración comercial se muestra en solo lectura.")).toBeVisible();
  for (const name of ["Cerrar fechas", "Guardar operación", "Nuevo rango"]) await expect(page.getByRole("button", { name, exact: true })).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(401);
  for (const sub of ["pasajeros", "alojamiento", "pago", "reglas"]) {
    await page.goto(`${BASE}/${sub}`);
    await expect(page.locator("main form button[type=submit]").filter({ hasNotText: "Simular" })).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth), sub).toBeLessThanOrEqual(401);
  }
});
