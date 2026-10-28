import { expect, test, type Page } from "@playwright/test";

test.describe.configure({ timeout: 240_000 });

// Spec 15 §10 (E2E-SIT-01..06): sitio web público de Andes Horizonte Travel. Datos: database/seeders/SiteDemoSeeder.php.
const PASSWORD = "Turismo2026!";
const run = Date.now().toString().slice(-6);

async function staffLogin(page: Page, email: string) {
  await page.context().clearCookies();
  await page.goto("/login");
  await page.getByLabel("Agencia (tenant)").fill("andes");
  await page.getByLabel("Correo", { exact: true }).fill(email);
  await page.getByLabel("Contraseña").fill(PASSWORD);
  await page.getByRole("button", { name: "Ingresar" }).click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
}

/** Primer día vendible del calendario desde `minDate` (avanza de mes si el actual no tiene cupo para el grupo). */
async function pickFirstDate(page: Page, minDate = ""): Promise<string> {
  const cal = page.getByTestId("calendar");
  for (let i = 0; i < 4; i++) {
    await expect(cal).not.toHaveAttribute("aria-busy", "true");
    const dates = await cal.locator('[data-sellable="1"]').evaluateAll((els) => els.map((e) => e.getAttribute("data-date")!));
    const date = dates.find((d) => d >= minDate);
    if (date) {
      await cal.locator(`[data-date="${date}"]`).click();
      await expect(page.getByTestId("selected-date")).toBeVisible();
      await expect(page.getByTestId("quote-total")).toBeVisible();
      return date;
    }
    await page.getByRole("button", { name: "Mes siguiente" }).click();
  }
  throw new Error("Sin fechas vendibles");
}

async function addToCart(page: Page) {
  await page.getByRole("button", { name: "Añadir al carrito" }).click();
  await expect(page.getByText("¡Añadido! Sus cupos quedaron bloqueados.")).toBeVisible();
}

async function fillBuyer(page: Page, name: string, email: string) {
  await page.getByLabel("Nombres y apellidos").fill(name);
  await page.getByLabel("Correo", { exact: true }).fill(email);
  await page.getByLabel("Celular").fill("+51 987 654 321");
  await page.getByLabel("País").fill("Perú");
  await page.getByLabel("Número de documento").fill("45879632");
}

async function fillPassengers(page: Page) {
  const cards = page.getByTestId("passenger");
  await expect(cards.first()).toBeVisible();
  const n = await cards.count();
  for (let i = 0; i < n; i++) {
    const c = cards.nth(i);
    if (await c.getByLabel("Nombres").count()) {
      await c.getByLabel("Nombres").fill(`Viajero${i + 1}`);
      await c.getByLabel("Apellidos").fill(`Prueba ${run}`);
      await c.getByLabel("Nº de documento").fill(`7${run}${i}`);
    }
    await c.getByLabel("Fecha de nacimiento").fill(`198${i}-05-1${i}`);
    await c.getByLabel("Nacionalidad").fill("Peruana");
  }
}

async function acceptConsents(page: Page) {
  await page.getByLabel("Acepto los términos y condiciones.").check();
  await page.getByLabel("Acepto las políticas de cancelación de cada producto.").check();
  await page.getByLabel(/Acepto el tratamiento de mis datos personales/).check();
}

test("E2E-SIT-01 · Anónimo: busca Cusco, arma un carrito con 2 holds, PRIMAVERA8, factura y paga con tarjeta tras un rechazo", async ({ page }) => {
  await page.goto("/sitio");
  await page.getByLabel("¿A dónde quiere ir?").selectOption({ label: "Cusco" });
  await page.getByLabel("Viajeros").fill("2");
  await page.getByRole("button", { name: "Buscar" }).click();
  await expect(page).toHaveURL(/\/sitio\/buscar\?.*destination=cusco/);
  await expect(page.getByTestId("result-count")).toBeVisible();

  await page.goto("/sitio/tours/machu-picchu-full-day?pax=2");
  await expect(page.getByRole("heading", { level: 1, name: "Machu Picchu Full Day" })).toBeVisible();
  await pickFirstDate(page);
  await addToCart(page);

  await page.goto("/sitio/tours/laguna-humantay-full-day");
  await pickFirstDate(page);
  await page.getByLabel(/Seguro de asistencia/).check();
  await expect(page.getByTestId("quote")).toContainText("Seguro");
  await addToCart(page);
  await expect(page.getByTestId("cart-count")).toHaveText("2");

  await page.goto("/sitio/carrito");
  await expect(page.getByTestId("cart-line")).toHaveCount(2);
  await expect(page.getByTestId("hold-status").first()).toContainText("HLD-");
  await expect(page.getByTestId("hold-timer").first()).toBeVisible();
  const before = await page.getByTestId("cart-total").innerText();
  await page.getByLabel("Código promocional").fill("PRIMAVERA8");
  await page.getByRole("button", { name: "Aplicar" }).click();
  await expect(page.getByTestId("promo-ok")).toContainText("PRIMAVERA8");
  await expect(page.getByTestId("cart-total")).not.toHaveText(before);

  await page.getByRole("link", { name: "Ir al checkout" }).click();
  await fillBuyer(page, `Lucía Torres ${run}`, `lucia${run}@correo.test`);
  await page.getByLabel("Factura").check();
  await page.getByLabel("RUC").fill("20512345678");
  await page.getByLabel("Razón social").fill("Viajes Corporativos SAC");
  await page.getByLabel("Dirección fiscal").fill("Av. Larco 345, Miraflores");
  await page.getByRole("button", { name: "Continuar" }).click();
  await expect(page.getByTestId("passenger").first()).toBeVisible();
  await fillPassengers(page);
  await page.getByRole("button", { name: "Continuar" }).click();
  await acceptConsents(page);
  await page.getByRole("button", { name: "Continuar" }).click();

  await page.getByLabel("Pago total").check();
  await page.getByLabel("Número de tarjeta").fill("4000 0000 0000 0002");
  await page.getByLabel("Titular").fill("LUCIA TORRES");
  await page.getByLabel("Vencimiento (MM/AA)").fill("12/29");
  await page.getByLabel("CVV").fill("123");
  await page.getByRole("button", { name: /^Pagar / }).click();
  await expect(page.getByTestId("checkout-error")).toBeVisible();
  await expect(page).toHaveURL(/\/sitio\/checkout/);

  await page.getByLabel("Número de tarjeta").fill("4111 1111 1111 1111");
  await page.getByRole("button", { name: /^Pagar / }).click();
  await page.waitForURL(/\/sitio\/confirmacion\/WEB-/);
  await expect(page.getByRole("heading", { name: "¡Su reserva está confirmada!" })).toBeVisible();
  await expect(page.getByTestId("reservation-code")).toHaveText(/RES-\d{4}-\d{6}/);
  await expect(page.getByTestId("order-status")).toHaveText("Confirmado");
  await expect(page.getByTestId("services").locator("li")).not.toHaveCount(0);
  await expect(page.getByTestId("vouchers").locator("li")).not.toHaveCount(0);
  await expect(page.getByTestId("cart-count")).toHaveText("0");
});

test("E2E-SIT-02 · Anónimo: paquete Cusco Mágico con anticipo y Yape queda en validación con CxC de anticipo y saldo", async ({ page }) => {
  await page.goto("/sitio/tours/cusco-magico-4d-3n");
  // El anticipo exige que el viaje empiece en ≥ 15 días.
  await pickFirstDate(page, new Date(Date.now() + 16 * 86_400_000).toISOString().slice(0, 10));
  await page.getByRole("button", { name: "Reservar ahora" }).click();
  await page.waitForURL(/\/sitio\/checkout/);

  await fillBuyer(page, `Mateo Quispe ${run}`, `mateo${run}@correo.test`);
  await page.getByRole("button", { name: "Continuar" }).click();
  await fillPassengers(page);
  await page.getByRole("button", { name: "Continuar" }).click();
  await acceptConsents(page);
  await page.getByRole("button", { name: "Continuar" }).click();

  await page.getByLabel(/^Anticipo/).check();
  await page.getByLabel("Yape").check();
  await page.getByLabel("Número de operación").fill(`YP-${run}`);
  await page.getByRole("button", { name: /^Registrar pago/ }).click();
  await page.waitForURL(/\/sitio\/confirmacion\/WEB-/);
  await expect(page.getByRole("heading", { name: "Pago en validación" })).toBeVisible();
  await expect(page.getByTestId("order-status")).toHaveText("Pago por validar");
  await expect(page.getByText(/Anticipo/).first()).toBeVisible();
  await expect(page.getByText(/Saldo/).first()).toBeVisible();
});

test("E2E-SIT-03 · Viaje a medida → lead sin asignar; contacto → inbox; chat; newsletter; hoja de reclamación", async ({ page }) => {
  await page.goto("/sitio/viajes-a-medida?destino=cusco&pax=4");
  await expect(page.getByLabel("Cusco")).toBeChecked();
  await page.getByLabel("Arequipa").check();
  await page.getByLabel("Presupuesto por persona (US$)").fill("2500");
  await page.getByLabel("Comentarios").fill("Viaje de aniversario, queremos hoteles boutique.");
  await page.getByLabel("Nombre completo *").fill(`Rosa Huamán ${run}`);
  await page.getByLabel("Correo *").fill(`rosa${run}@correo.test`);
  await page.getByLabel("Celular / WhatsApp *").fill("+51 955 123 456");
  await page.getByRole("button", { name: "Solicitar cotización" }).click();
  await expect(page.getByRole("alert").filter({ hasText: "Ley 29733" })).toBeVisible();
  await page.getByLabel(/Acepto el tratamiento de mis datos personales para recibir una cotización/).check();
  await page.getByRole("button", { name: "Solicitar cotización" }).click();
  const lead = (await page.getByTestId("lead-code").innerText()).trim();
  expect(lead).toMatch(/^LEAD-\d+$/);
  await expect(page.getByTestId("lead-ok")).toContainText("24 horas hábiles");

  await page.goto("/sitio/contacto");
  await page.getByLabel("Motivo").selectOption("Tengo una reserva");
  await page.getByLabel("Nombre *").fill(`Pedro Salas ${run}`);
  await page.getByLabel("Correo *").fill(`pedro${run}@correo.test`);
  await page.getByLabel("Mensaje *").fill(`¿Puedo cambiar la hora de recojo? (e2e ${run})`);
  await page.getByLabel(/Acepto el tratamiento de mis datos personales para atender/).check();
  await page.getByRole("button", { name: "Enviar" }).click();
  const conversation = (await page.getByTestId("contact-ref").innerText()).trim();
  expect(conversation).toMatch(/^CNV-\d+$/);

  await page.getByRole("button", { name: /Chat/ }).click();
  const chat = page.getByRole("region", { name: "Chat" });
  await chat.getByLabel("Nombre").fill(`Ana ${run}`);
  await chat.getByLabel("Correo o WhatsApp").fill(`ana${run}@correo.test`);
  await chat.getByLabel("Su consulta").fill("¿Tienen salidas a Humantay esta semana?");
  await chat.getByRole("checkbox").check();
  await chat.getByRole("button", { name: "Iniciar chat" }).click();
  await expect(chat.getByTestId("chat-messages")).toContainText("Humantay");
  await expect(chat.getByTestId("chat-messages")).toContainText("Respuesta automática");
  await chat.getByRole("button", { name: "Cerrar chat" }).click();

  const footer = page.locator("footer");
  await footer.getByPlaceholder("Su correo").fill(`news${run}@correo.test`);
  await footer.getByRole("button", { name: "Suscribirme" }).click();
  await expect(footer.getByRole("status")).toContainText("Ley 29733");
  await footer.getByLabel(/Acepto recibir novedades/).check();
  await footer.getByRole("button", { name: "Suscribirme" }).click();
  await expect(footer.getByRole("status")).toContainText("doble opt-in");

  await page.goto("/sitio/libro-reclamaciones");
  const form = page.getByRole("form", { name: "Hoja de reclamación" });
  await form.getByLabel("Nombre completo").fill(`Julia Ccori ${run}`);
  await form.getByLabel("N° de documento").first().fill("40112233");
  await form.getByLabel("Domicilio").fill("Jr. Puno 456, Cusco");
  await form.getByLabel(/Correo electrónico/).fill(`julia${run}@correo.test`);
  await form.getByLabel("Descripción").fill("Tour Laguna Humantay");
  await form.getByLabel("Detalle").fill(`El recojo del hotel se retrasó más de una hora (e2e ${run}).`);
  await form.getByLabel("Pedido").fill("Reembolso parcial");
  const q = (await form.getByText(/Verificación: ¿Cuánto es/).innerText()).match(/(\d+) \+ (\d+)/)!;
  await form.getByLabel("Verificación").fill(String(Number(q[1]) + Number(q[2])));
  await form.getByLabel(/Acepto recibir/).check();
  await form.getByRole("button", { name: "Enviar hoja de reclamación" }).click();
  await expect(page.getByRole("status").filter({ hasText: "registrada" })).toContainText(/LR-[A-Z]+-/);

  await staffLogin(page, "gerencia@andes.test");
  await page.goto(`/crm/leads/${lead}`);
  await expect(page.getByText(lead).first()).toBeVisible();
  await expect(page.getByText(`Rosa Huamán ${run}`).first()).toBeVisible();
  await expect(page.getByText("Sin asignar (cola comercial)")).toBeVisible();
  await page.goto(`/inbox/${conversation}`);
  await expect(page.getByText(`(e2e ${run})`).first()).toBeVisible();
});

test("E2E-SIT-04 · U14: Mi cuenta, pagar saldo, deseos, datos, checkout autocompletado, logout y registro nuevo", async ({ page }) => {
  await page.goto("/sitio/cuenta/ingresar");
  await page.getByRole("button", { name: /Demo: ingresar como/ }).click();
  await page.waitForURL(/\/sitio\/cuenta$/);
  await expect(page.getByTestId("my-reservations").locator("li")).not.toHaveCount(0);

  await page.goto("/sitio/cuenta/pagos");
  await expect(page.getByTestId("receivables")).toContainText("en validación");
  const idx = await page.getByTestId("receivable").evaluateAll((els) => els.findIndex((e) => [...e.querySelectorAll("button")].some((b) => b.textContent?.startsWith("Pagar "))));
  if (idx >= 0) {
    const payable = page.getByTestId("receivable").nth(idx);
    await payable.getByRole("button", { name: /^Pagar / }).click();
    await payable.getByLabel("Número de tarjeta").fill("4111111111111111");
    await payable.getByLabel("Vencimiento").fill("12/29");
    await payable.getByLabel("CVV").fill("123");
    await payable.getByRole("button", { name: "Confirmar pago" }).click();
    await expect(page.getByRole("status").first()).toBeVisible();
  }

  await page.goto("/sitio/tours/valle-sagrado-vip");
  const save = page.getByRole("button", { name: /Guardar$/ }).first();
  if (await save.count()) await save.click();
  await expect(page.getByRole("button", { name: /Guardado$/ }).first()).toBeVisible();
  await page.goto("/sitio/cuenta/deseos");
  await expect(page.getByTestId("wishlist")).toContainText("Valle Sagrado VIP");

  await page.goto("/sitio/cuenta/datos");
  await page.getByLabel("País").fill("Perú");
  await page.getByRole("button", { name: "Guardar" }).click();
  await expect(page.getByRole("status").first()).toBeVisible();

  await page.goto("/sitio/tours/laguna-humantay-full-day");
  await pickFirstDate(page);
  await page.getByRole("button", { name: "Reservar ahora" }).click();
  await page.waitForURL(/\/sitio\/checkout/);
  await expect(page.getByLabel("Correo", { exact: true })).toHaveValue("cliente@correo.test");
  await expect(page.getByLabel("Nombres y apellidos")).not.toHaveValue("");

  await page.goto("/sitio/cuenta");
  await page.getByRole("button", { name: "Cerrar sesión" }).click();
  await page.waitForURL(/\/sitio$/);
  await page.goto("/sitio/cuenta");
  await page.waitForURL(/\/sitio\/cuenta\/ingresar/);

  await page.goto("/sitio/cuenta/registro");
  await page.getByLabel("Nombres y apellidos").fill(`Carmen Vela ${run}`);
  await page.getByLabel("Correo", { exact: true }).fill(`carmen${run}@correo.test`);
  await page.getByLabel("Celular").fill("+51 944 111 222");
  await page.getByLabel("Número de documento").fill(`4${run}9`);
  await page.getByLabel("Contraseña (mín. 8)").fill("ClaveSegura2026");
  await page.getByLabel(/Acepto el tratamiento de mis datos personales/).check();
  await page.getByRole("button", { name: "Crear cuenta" }).click();
  await page.waitForURL(/\/sitio\/cuenta$/);
  await expect(page.getByRole("heading", { name: /Hola, Carmen/ })).toBeVisible();
});

test("E2E-SIT-05 · Inglés + USD: cabecera, fichas y precios convertidos; título, descripción y JSON-LD por página", async ({ page }) => {
  await page.goto("/sitio/tours");
  const pen = await page.getByTestId("from-price").first().innerText();
  expect(pen).toContain("S/");
  await page.getByLabel("Idioma", { exact: true }).selectOption("en");
  await expect(page.getByRole("link", { name: "Destinations" }).first()).toBeVisible();
  await page.getByLabel("Moneda", { exact: true }).selectOption("USD");
  await expect(page.getByTestId("from-price").first()).toContainText("US$");
  await page.goto("/sitio/tours/machu-picchu-full-day");
  await expect(page.getByText("Itinerary").first()).toBeVisible();
  await expect(page.getByRole("button", { name: "Add to cart" })).toBeVisible();
  await expect(page.getByRole("complementary", { name: "Booking" })).toContainText("US$");

  for (const [url, type] of [["/sitio", "TravelAgency"], ["/sitio/tours/machu-picchu-full-day", "Product"], ["/sitio/blog/como-llegar-a-machu-picchu", "BlogPosting"], ["/sitio/nosotros", "TravelAgency"]] as const) {
    await page.goto(url);
    expect(await page.title()).toMatch(/Andes/);
    expect(await page.locator('meta[name="description"]').getAttribute("content")).toBeTruthy();
    const ld = await page.locator('script[type="application/ld+json"]').first().textContent();
    expect(ld).toContain(type);
  }
  await page.getByLabel("Idioma", { exact: true }).selectOption("es");
  await expect(page.getByRole("link", { name: "Destinos" }).first()).toBeVisible();
  await page.getByLabel("Moneda", { exact: true }).selectOption("PEN");
});

const ROUTES = ["/sitio", "/sitio/tours", "/sitio/paquetes", "/sitio/experiencias", "/sitio/buscar?destination=cusco&pax=2", "/sitio/tours/machu-picchu-full-day",
  "/sitio/tours/cusco-magico-4d-3n", "/sitio/destinos", "/sitio/destinos/cusco", "/sitio/ofertas", "/sitio/viajes-a-medida", "/sitio/blog", "/sitio/blog/como-llegar-a-machu-picchu",
  "/sitio/nosotros", "/sitio/contacto", "/sitio/legal/terminos-y-condiciones", "/sitio/legal/politica-de-privacidad", "/sitio/libro-reclamaciones", "/sitio/carrito",
  "/sitio/cuenta/ingresar", "/sitio/cuenta/registro", "/sitio/cuenta/mi-reserva"];

for (const width of [400, 1440]) {
  test(`E2E-SIT-06 · Responsive ${width} px: todas las rutas sin scroll horizontal ni errores de consola`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));
    page.on("console", (m) => { if (m.type() === "error") errors.push(m.text()); });
    for (const url of ROUTES) {
      const res = await page.goto(url);
      expect(res?.status(), url).toBeLessThan(400);
      await expect(page.locator("h1").first()).toBeVisible();
      const sw = await page.evaluate(() => document.documentElement.scrollWidth);
      expect(sw, `${url} sin scroll horizontal`).toBeLessThanOrEqual(width);
    }
    await page.goto("/sitio/cuenta/ingresar");
    await page.getByRole("button", { name: /Demo: ingresar como/ }).click();
    await page.waitForURL(/\/sitio\/cuenta$/);
    for (const url of ["/sitio/cuenta", "/sitio/cuenta/vouchers", "/sitio/cuenta/pagos", "/sitio/cuenta/deseos", "/sitio/cuenta/datos"]) {
      await page.goto(url);
      expect(await page.evaluate(() => document.documentElement.scrollWidth), url).toBeLessThanOrEqual(width);
    }
    expect(errors).toEqual([]);
  });
}
