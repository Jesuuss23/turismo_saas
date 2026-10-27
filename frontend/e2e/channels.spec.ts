import { expect, test, type Page } from "@playwright/test";

// Canales y portales (spec 08 §10.2: P01–P07). Requiere datos demo recién sembrados
// (php artisan migrate:fresh --database=pgsql_admin --seed); los casos se ejecutan en orden sobre los mismos datos.
const PASSWORD = "Turismo2026!";
const API = process.env.E2E_API_URL ?? "http://localhost:8000/api/v1";

test.describe.configure({ mode: "serial" });

async function login(page: Page, email: string) {
  await page.context().clearCookies();
  await page.goto("/login");
  await page.getByLabel("Agencia (tenant)").fill("andes");
  await page.getByLabel("Correo").fill(email);
  await page.getByLabel("Contraseña").fill(PASSWORD);
  await page.getByRole("button", { name: "Ingresar" }).click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
}

async function reserveVinicunca(page: Page, pax: string) {
  await page.getByRole("listitem").filter({ hasText: "Montaña de 7 Colores" }).getByRole("link", { name: "Reservar" }).click();
  const drawer = page.getByRole("dialog", { name: "Reservar Montaña de 7 Colores (Vinicunca)" });
  await drawer.getByLabel("Salida").selectOption({ index: 1 });
  await drawer.getByLabel("Pasajeros", { exact: true }).fill(pax);
  return drawer;
}

test("P01: usuario B2B reserva con crédito → voucher en Mis reservas → estado de cuenta con cargo y comisión", async ({ page }) => {
  test.setTimeout(90_000);
  await login(page, "b2b@solviajes.test");
  await expect(page).toHaveURL(/\/canales\/b2b\/portal\/catalogo$/);
  await expect(page.getByText("Vista del canal")).toHaveCount(0);
  const drawer = await reserveVinicunca(page, "2");
  await expect(drawer.getByLabel("Forma de pago")).toHaveValue("credito");
  await drawer.getByLabel("Su referencia (file de la agencia)").fill("SV-E2E-01");
  await drawer.getByLabel(/Pasajeros \(uno por línea/).fill("Ana Torres — 45678912\nLuis Vega — 47896541");
  await drawer.getByRole("button", { name: "Confirmar reserva" }).click();
  await page.waitForURL(/\/canales\/b2b\/portal\/reservas\?reserva=RES-/);
  const code = new URL(page.url()).searchParams.get("reserva")!;
  const detail = page.getByRole("dialog", { name: `Reserva ${code}` });
  await expect(detail.getByText("Confirmada").first()).toBeVisible();
  await expect(detail.getByText("PEN 250.00", { exact: true })).toBeVisible();
  await detail.getByRole("link", { name: /^Ver voucher VOU-/ }).first().click();
  await expect(page.getByText(/^Voucher VOU-.+ · v1/)).toBeVisible();

  await page.getByRole("link", { name: "Cerrar ✕" }).click();
  await page.getByRole("link", { name: "Estado de cuenta", exact: true }).click();
  await expect(page.getByRole("row").filter({ hasText: code }).first()).toBeVisible();
  await expect(page.getByRole("row").filter({ hasText: code }).filter({ hasText: "PEN 25.00" })).toBeVisible();
});

test("P02: agencia bloqueada solo ve prepago, con aviso, y su reserva queda pendiente de pago", async ({ page }) => {
  await login(page, "b2b@viajesexpress.test");
  await expect(page.getByText(/Bloqueada manualmente: .+ Solo prepago\./)).toBeVisible();
  const drawer = await reserveVinicunca(page, "1");
  await expect(drawer.getByLabel("Forma de pago").locator("option")).toHaveText(["Prepago"]);
  await drawer.getByRole("button", { name: "Confirmar reserva" }).click();
  await page.waitForURL(/\/canales\/b2b\/portal\/reservas\?reserva=RES-/);
  const code = new URL(page.url()).searchParams.get("reserva")!;
  const detail = page.getByRole("dialog", { name: `Reserva ${code}` });
  await expect(detail.getByText("Pendiente de pago").first()).toBeVisible();
  await expect(detail.getByText("Voucher: Tras el pago").first()).toBeVisible();
});

test("P03: checkout web por API — PRIMAVERA8 → tarjeta rechazada → reintento aprobado → confirmado con voucher", async ({ request }) => {
  const headers = { "X-Tenant-Slug": "andes", Accept: "application/json" };
  const product = (await (await request.get(`${API}/public/shop/products/machu-picchu-full-day`, { headers })).json()).data;
  const departure = product.departures.find((d: { closed: boolean; available: number }) => !d.closed && d.available >= 1);
  expect(departure).toBeTruthy();
  const price = (await (await request.post(`${API}/public/shop/price`, { headers, data: { product: "PRD-001", date: departure.date, adults: 1, code: "PRIMAVERA8" } })).json()).data;
  expect(price.promo_state).toBe("ok");
  expect(Number(price.discount)).toBeGreaterThan(0);
  const avail = (await (await request.post(`${API}/public/shop/availability`, { headers, data: { product: "PRD-001", departure_id: departure.id, adults: 1 } })).json()).data;
  expect(avail.ok).toBe(true);

  const created = await request.post(`${API}/public/shop/orders`, { headers: { ...headers, "Idempotency-Key": `e2e-${Date.now()}` }, data: {
    product: "PRD-001", departure_id: departure.id, adults: 1, code: "PRIMAVERA8", passengers: [],
    buyer: { name: "Valeria E2E", doc_type: "DNI", doc_number: "70999888", email: "valeria.e2e@correo.test", phone: "+51 987 000 111", country: "Perú", accept_privacy: true },
  } });
  expect(created.status()).toBe(201);
  const order = (await created.json()).data;
  expect(order.promo_code).toBe("PRIMAVERA8");
  expect(order.total).toBe(price.total);
  const pay = (number: string, key: string) => request.post(`${API}/public/shop/orders/${order.code}/payments`, {
    headers: { ...headers, "X-Order-Token": order.token, "Idempotency-Key": key }, data: { card: { number, expiry: "12/29", cvv: "123" } } });

  const rejected = await pay("4000000000000002", `e2e-r-${order.code}`);
  expect(rejected.status()).toBe(200);
  expect((await rejected.json()).data).toMatchObject({ result: "rechazado", order: { status: "pago_rechazado" } });
  const approved = await pay("4111111111111111", `e2e-a-${order.code}`);
  expect(approved.status()).toBe(201);
  const final = (await approved.json()).data.order;
  expect(final.status).toBe("confirmado");
  expect(final.reservation.code).toMatch(/^RES-/);
  expect(final.vouchers.length).toBeGreaterThan(0);
});

test("P04: CMS — se redacta y envía a revisión → gerente aprueba y publica → banner visible → despublica con motivo", async ({ page, request }) => {
  test.setTimeout(120_000);
  await login(page, "admin@andes.test");
  await page.goto("/canales/cms");
  await page.getByRole("link", { name: "Nuevo contenido" }).click();
  const drawer = page.getByRole("dialog", { name: "Nuevo contenido" });
  await drawer.getByLabel("Título", { exact: true }).fill("Aviso de temporada de lluvias");
  await drawer.getByLabel("Tipo").selectOption("Banner");
  await drawer.getByLabel("Canal destino").selectOption("Portal cliente");
  await drawer.getByLabel("Ubicación (banners)").fill("portal_home");
  await drawer.getByLabel("Contenido").fill("Lleve impermeable entre diciembre y marzo.");
  await drawer.getByLabel("Título SEO (hasta 60)").fill("Temporada de lluvias");
  await drawer.getByRole("button", { name: "Guardar borrador" }).click();
  await page.waitForURL(/\/canales\/cms\/CMS-\d+$/);
  const url = page.url();
  await page.getByRole("button", { name: "Enviar a revisión" }).click();
  await expect(page.getByText("En revisión").first()).toBeVisible();

  await login(page, "gerencia@andes.test");
  await page.goto(url);
  await page.getByRole("button", { name: "Aprobar" }).click();
  await expect(page.getByRole("button", { name: "Publicar" })).toBeVisible();
  await page.getByRole("button", { name: "Publicar" }).click();
  await expect(page.getByText("Publicado").first()).toBeVisible();
  const placement = async () => (await (await request.get(`${API}/public/cms/placements/portal_home`, { headers: { "X-Tenant-Slug": "andes" } })).json()).data as { title: string }[];
  expect((await placement()).map((c) => c.title)).toContain("Aviso de temporada de lluvias");

  await login(page, "cliente@correo.test");
  await expect(page.getByRole("region", { name: "Aviso de temporada de lluvias" })).toBeVisible();

  await login(page, "gerencia@andes.test");
  await page.goto(url);
  await page.getByRole("link", { name: "Despublicar" }).click();
  await page.getByLabel("Motivo").fill("Fin de temporada");
  await page.getByRole("button", { name: "Despublicar" }).click();
  await expect(page.getByText("Despublicado").first()).toBeVisible();
  await expect(page.getByText("“Fin de temporada”")).toBeVisible();
  expect((await placement()).map((c) => c.title)).not.toContain("Aviso de temporada de lluvias");
});

test("P05: cliente abre su cotización (pasa a vista) → aprueba → aparece en Documentos", async ({ page }) => {
  await login(page, "cliente@correo.test");
  const home = new URL(page.url()).pathname.replace(/\/inicio$/, "");
  await page.getByRole("listitem").filter({ hasText: "Responder la propuesta COT-2026-00192" }).getByRole("link", { name: "Ver" }).click();
  await page.waitForURL(/\/cotizaciones\/COT-2026-00192$/);
  await page.goto(`${home}/cotizaciones`);
  await expect(page.getByRole("row").filter({ hasText: "COT-2026-00192" }).getByText("Vista", { exact: true })).toBeVisible();
  await page.getByRole("link", { name: "Luna de miel Cusco 4D/3N" }).click();
  await page.getByRole("link", { name: "Aprobar cotización" }).click();
  await page.getByLabel("Nombre de quien aprueba").fill("Ana Gutiérrez");
  await page.getByLabel(/Acepto la propuesta/).check();
  await page.getByRole("button", { name: "Confirmar aprobación" }).click();
  await expect(page.getByText(/Usted aprobó la versión \d+/)).toBeVisible();
  await page.goto(`${home}/documentos`);
  await expect(page.getByRole("row").filter({ hasText: "Propuesta COT-2026-00192" })).toBeVisible();
});

test("P06: pasajero — mi viaje → alerta → confirma lectura → itinerario → abre voucher", async ({ page, request }) => {
  // Operaciones registra un cambio de horario en la orden del viaje (la alerta sembrada puede haberse cerrado en E2E-10).
  const login0 = await request.post(`${API}/auth/login`, { data: { tenant_slug: "andes", email: "operaciones@andes.test", password: PASSWORD } });
  const auth = { Authorization: `Bearer ${(await login0.json()).token}`, Accept: "application/json" };
  const order = (await (await request.get(`${API}/ops/orders/OP-2026-0417`, { headers: auth })).json()).data;
  const created = await request.post(`${API}/ops/incidents`, { headers: { ...auth, "Idempotency-Key": `e2e-p06-${Date.now()}` }, data: {
    order_id: order.id, type: "Horario", severity: "media", title: "Tren de regreso reprogramado a las 16:20", description: "PeruRail reprograma la salida por mantenimiento." } });
  expect(created.status()).toBe(201);

  await login(page, "pasajero@correo.test");
  await expect(page).toHaveURL(/\/canales\/portal-pasajero\/viajes$/);
  await expect(page.getByText(/Tiene \d+ alerta\(s\) sin confirmar/)).toBeVisible();
  await page.getByRole("link", { name: "Ver alertas" }).click();
  const alert = page.getByRole("listitem").filter({ hasText: "Tren de regreso reprogramado a las 16:20" });
  await expect(alert).toContainText("Operaciones está atendiendo un cambio en su viaje");
  await alert.getByRole("button", { name: "Entendido" }).click();
  await expect(alert).toContainText("Confirmada");
  await page.getByRole("link", { name: "Itinerario", exact: true }).click();
  await expect(page.getByRole("heading", { name: /^Día 1 · / })).toBeVisible();
  await page.getByRole("link", { name: "Vouchers", exact: true }).click();
  await page.getByRole("row").filter({ hasText: "City Tour" }).getByRole("link", { name: "Ver" }).click();
  await expect(page.getByRole("dialog", { name: /^Voucher VOU-/ }).getByLabel("Código QR")).toBeVisible();
});

test("P07: a 400 px los portales no tienen scroll horizontal y los externos no ven la vista interna", async ({ page }) => {
  await page.setViewportSize({ width: 400, height: 800 });
  const noOverflow = async () => expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  for (const [email, paths] of [
    ["b2b@solviajes.test", ["/canales/b2b/portal/catalogo"]],
    ["cliente@correo.test", []],
    ["pasajero@correo.test", ["/canales/portal-pasajero/viajes", "/canales/portal-pasajero/alertas"]],
  ] as const) {
    await login(page, email);
    for (const path of paths) await page.goto(path);
    await noOverflow();
    await expect(page.getByText("Vista del canal")).toHaveCount(0);
    await expect(page.getByText("Ver portal como")).toHaveCount(0);
  }
  // Interno (administrador): sí ve la vista del canal, en solo lectura.
  await login(page, "admin@andes.test");
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto("/canales/b2b/portal/catalogo?agency=AGB-03");
  await expect(page.getByText(/Vista del canal · Portal de agencias/)).toBeVisible();
  await expect(page.getByRole("link", { name: "Reservar" })).toHaveCount(1);
});
