import { expect, test, type Page } from "@playwright/test";

// Catálogo turístico (spec 02 §10.3). Requiere datos demo recién sembrados
// (php artisan migrate:fresh --database=pgsql_admin --seed).
const PASSWORD = "Turismo2026!";

async function login(page: Page, email: string) {
  await page.goto("/login");
  await page.getByLabel("Agencia (tenant)").fill("andes");
  await page.getByLabel("Correo").fill(email);
  await page.getByLabel("Contraseña").fill(PASSWORD);
  await page.getByRole("button", { name: "Ingresar" }).click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
}

function isoIn(days: number) {
  const d = new Date();
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

test("CAT-E2E-01: Manager crea con el asistente, aprueba, activa y publica en e-commerce", async ({ page }) => {
  test.setTimeout(120_000);
  await login(page, "gerencia@andes.test");
  await page.goto("/catalogo/productos/nuevo");
  const next = page.getByRole("button", { name: "Siguiente" });

  await page.getByLabel("Código interno").fill(`E2E${Date.now() % 100000}`);
  await page.getByLabel("Nombre comercial").fill("Tour E2E Valle Sur");
  await next.click();
  await next.click();
  await expect(page.getByText("Seleccione tipo y categoría.")).toBeVisible();
  await page.getByLabel("Tipo de producto").selectOption({ label: "EXCURSIÓN" });
  await page.getByRole("combobox", { name: /^Categoría/ }).selectOption({ label: "Cultural" });
  await next.click();
  await page.getByLabel("Destino principal").selectOption({ label: "Perú › Cusco" });
  await next.click();
  await page.getByRole("button", { name: "Agregar componente" }).click();
  await next.click();
  await page.getByLabel("Actividad").first().fill("Visita guiada a Tipón");
  await next.click();
  await page.getByLabel("Costo").fill("40");
  await page.getByLabel("Precio").fill("75");
  await next.click();
  await next.click();
  // Sin salidas programadas, el checklist exige un modelo que no dependa de salidas.
  await page.getByLabel("Modelo de disponibilidad").selectOption("SIN_CONTROL");
  await next.click();
  await next.click();
  await page.getByRole("button", { name: "Crear producto en borrador" }).click();

  await page.waitForURL(/\/catalogo\/productos\/PRD-\d+/);
  await expect(page.getByText("Borrador").first()).toBeVisible();
  await page.getByRole("button", { name: "Enviar a revisión" }).click();
  await page.getByRole("button", { name: "Aprobar" }).click();
  await expect(page.getByRole("button", { name: "Aprobar" })).toHaveCount(0);
  await expect(page.getByText("Checklist de activación 12/12")).toBeVisible();
  await page.getByRole("button", { name: "Activar" }).click();
  await expect(page.getByRole("button", { name: "Suspender" })).toBeVisible();

  await page.getByRole("link", { name: "Canales" }).click();
  const ecommerce = page.getByRole("row", { name: /E-commerce/ });
  await ecommerce.getByRole("button", { name: "Publicar" }).click();
  await expect(ecommerce.getByText("Publicado")).toBeVisible();
});

test("CAT-E2E-02: Manager crea la versión 2.1, la compara con 2.0 y ve el snapshot", async ({ page }) => {
  await login(page, "gerencia@andes.test");
  await page.goto("/catalogo/productos/PRD-001/versiones");
  const versionForm = page.locator("form", { has: page.getByRole("button", { name: "Crear versión" }) });
  await versionForm.getByLabel("Motivo").fill("Ajuste de itinerario E2E");
  await versionForm.getByRole("button", { name: "Crear versión" }).click();
  await expect(page.getByRole("cell", { name: "2.1", exact: true })).toBeVisible();

  await page.getByLabel("Versión A").selectOption({ label: "2.0 (historica)" });
  await page.getByLabel("Versión B").selectOption({ label: "2.1 (vigente)" });
  await page.getByRole("button", { name: "Comparar" }).click();
  await expect(page.getByText(/2\.0 → 2\.1: \d+ diferencia/)).toBeVisible();

  await page.getByRole("row", { name: /2\.1/ }).getByRole("link", { name: "Ver snapshot" }).click();
  await expect(page.getByRole("heading", { name: "Snapshot versión 2.1" })).toBeVisible();
});

test("CAT-E2E-03: Operaciones programa una salida, retiene y confirma cupos; el exceso se rechaza", async ({ page }) => {
  test.setTimeout(90_000);
  await login(page, "operaciones@andes.test");
  await page.goto("/catalogo/salidas");
  const when = isoIn(45);
  const form = page.locator("form", { has: page.getByRole("button", { name: "Programar salida" }) });
  await form.getByLabel("Producto").selectOption({ label: "PRD-001 · Machu Picchu Full Day" });
  await form.getByLabel("Fecha").fill(when);
  await form.getByLabel("Capacidad").fill("10");
  await form.getByRole("button", { name: "Programar salida" }).click();
  await expect(page.getByText("Salida programada")).toBeVisible();

  await page.goto(`/catalogo/salidas/calendario?month=${when.slice(0, 7)}`);
  // La salida nueva es la de las 06:00 (las sembradas de PRD-001 salen 04:30–05:00 y pueden caer en el mismo mes).
  await page.getByRole("link", { name: /06:00 PRD-001 0%/ }).click();
  const drawer = page.getByRole("dialog");
  await drawer.getByLabel("Cupos").first().fill("2");
  await drawer.getByLabel("Referencia", { exact: true }).fill("COT-E2E-1");
  await drawer.getByRole("button", { name: "Retener cupos" }).click();
  await expect(drawer.getByText("Cupos retenidos")).toBeVisible();
  await drawer.getByRole("row", { name: /COT-E2E-1/ }).getByRole("button", { name: "Confirmar" }).click();
  await expect(drawer.getByRole("row", { name: /COT-E2E-1/ }).getByText("confirmada")).toBeVisible();

  await drawer.getByLabel("Cupos").first().fill("12");
  await drawer.getByLabel("Referencia", { exact: true }).fill("COT-E2E-2");
  await drawer.getByRole("button", { name: "Retener cupos" }).click();
  await expect(drawer.getByText(/PRD-BR-012.*Alternativas/)).toBeVisible();

  await page.goto(`/catalogo/salidas/calendario?month=${when.slice(0, 7)}`);
  await expect(page.getByRole("link", { name: /06:00 PRD-001 20%/ })).toBeVisible();
});

test("CAT-E2E-04: Ventas ve el 360 sin pestañas sensibles y no accede a Proveedores", async ({ page }) => {
  await login(page, "carla.ventas@andes.test");
  await page.goto("/catalogo/productos/PRD-001");
  const tabs = page.getByRole("navigation", { name: "Pestañas" });
  await expect(tabs.getByRole("link", { name: "Tarifas" })).toBeVisible();
  for (const hidden of ["Proveedores", "B2B", "Costos y rentabilidad", "Versiones", "Historial"]) {
    await expect(tabs.getByRole("link", { name: hidden, exact: true })).toHaveCount(0);
  }
  await expect(page.getByText("Modo consulta · Ventas")).toBeVisible();
  await expect(page.getByRole("button", { name: "Activar" })).toHaveCount(0);

  await page.goto("/catalogo/productos/PRD-001/rentabilidad");
  await expect(page.getByText("La pestaña solicitada no está disponible para su rol.")).toBeVisible();
  await expect(page.getByText("Checklist de activación").first()).toBeVisible();

  await page.goto("/catalogo/proveedores");
  await expect(page.getByRole("heading", { name: "No tiene acceso a esta pantalla" })).toBeVisible();
});

test("CAT-E2E-05: resolver tarifa por fecha/segmento/canal y simulador con ≥10 pax aplica PR-05", async ({ page }) => {
  await login(page, "gerencia@andes.test");
  await page.goto("/catalogo/productos/PRD-001/tarifas");
  await page.getByLabel("Fecha", { exact: true }).fill(isoIn(20));
  await page.getByLabel("Segmento").first().selectOption("Adulto");
  await page.getByLabel("Canal").first().selectOption("Directo");
  await page.getByRole("button", { name: "Resolver" }).click();
  await expect(page.getByTestId("resolve-result")).toContainText("Aplica TAR-001");

  await page.goto("/catalogo/tarifas/reglas");
  const sim = page.locator("form", { has: page.getByRole("button", { name: "Simular" }) });
  await sim.getByLabel("Producto").selectOption({ label: "PRD-001 · Machu Picchu Full Day" });
  await sim.getByLabel("Pax").fill("12");
  await sim.getByRole("button", { name: "Simular" }).click();
  await expect(page.getByTestId("simulation")).toContainText("PR-05");
});

test("pantallas del catálogo cargan para Finanzas en solo lectura", async ({ page }) => {
  await login(page, "finanzas@andes.test");
  for (const [url, heading] of [
    ["/catalogo/productos", "Productos"], ["/catalogo/salidas/holds", "Salidas y disponibilidad"], ["/catalogo/tarifas/temporadas", "Tarifas, temporadas y pricing"],
    ["/catalogo/servicios/SRV-001", /SRV-001/], ["/catalogo/proveedores/PRV-001", /PRV-001/], ["/catalogo/destinos/D02", "Destinos"],
    ["/catalogo/productos/PRD-001/rentabilidad", /PRD-001/],
  ] as const) {
    await page.goto(url);
    await expect(page.getByRole("heading", { name: heading }).first(), url).toBeVisible();
  }
  await expect(page.getByText("Finanzas · costos y rentabilidad en solo lectura.")).toBeVisible();
});
