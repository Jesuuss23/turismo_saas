import { expect, test, type Page } from "@playwright/test";

// Cotizaciones (spec 03 §10.3). Requiere datos demo recién sembrados
// (php artisan migrate:fresh --database=pgsql_admin --seed).
const PASSWORD = "Turismo2026!";

async function login(page: Page, email: string) {
  await page.context().clearCookies();
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

test("T-Q15: cotización → aprobación interna → envío → enlace del cliente → aprobación", async ({ page, browser }) => {
  test.setTimeout(180_000);

  // 1. Ventas arma la cotización con 8 % de descuento (> 5 %: requiere gerencia).
  await login(page, "carla.ventas@andes.test");
  await page.goto("/cotizaciones/nueva");
  await page.getByLabel("Cliente").selectOption({ label: "CLI-0001 · Ana Gutiérrez" });
  await page.getByLabel("Título").fill("E2E Cusco clásico");
  await page.getByLabel("Viaje desde").fill(isoIn(30));
  await page.getByLabel("Viaje hasta").fill(isoIn(33));
  const picker = page.getByLabel("Producto del catálogo");
  await picker.selectOption((await picker.locator("option", { hasText: "PRD-002" }).getAttribute("value"))!);
  await page.getByRole("button", { name: "Agregar ítem" }).click();
  await expect(page.getByTestId("quote-line")).toHaveCount(1);
  await expect(page.getByLabel("Precio ítem 1")).not.toHaveValue("");
  await expect(page.getByTestId("quote-totals")).toContainText("Total");
  await page.getByLabel("Descuento %").fill("8");
  await expect(page.getByText(/Requiere aprobación interna: descuento 8/)).toBeVisible();
  await page.getByRole("button", { name: "Guardar borrador" }).click();
  await page.waitForURL(/\/cotizaciones\/COT-\d{4}-\d{5}$/);
  const url = page.url();
  const code = url.split("/").pop()!;

  // 2. Enviar está bloqueado con candado; se solicita la aprobación a gerencia.
  await expect(page.getByRole("button", { name: /^Enviar \(bloqueado/ })).toBeVisible();
  await page.getByRole("link", { name: "Solicitar aprobación a gerencia" }).click();
  await page.getByLabel("Justificación comercial").fill("Cliente frecuente, viaje de aniversario.");
  await page.getByRole("button", { name: "Solicitar aprobación" }).click();
  await expect(page.getByText("Pendiente de aprobación de gerencia")).toBeVisible();

  // 3. Gerencia aprueba internamente.
  await login(page, "gerencia@andes.test");
  await page.goto(`/cotizaciones/${code}`);
  await page.getByRole("link", { name: "Aprobar internamente" }).click();
  await page.getByLabel("Comentario").fill("Aprobado por fidelidad.");
  await page.getByRole("button", { name: "Aprobar", exact: true }).click();
  await expect(page.getByText(/Aprobada por gerencia/)).toBeVisible();

  // 4. Ventas envía: se congela la versión y se genera el enlace.
  await login(page, "carla.ventas@andes.test");
  await page.goto(`/cotizaciones/${code}`);
  await page.getByRole("link", { name: "Enviar", exact: true }).click();
  await page.getByRole("button", { name: "Enviar cotización" }).click();
  await page.waitForURL((u) => !u.search.includes("accion"));
  await expect(page.getByRole("heading", { level: 1 }).locator("xpath=following-sibling::p")).toContainText("Enviada");
  await page.goto(`/cotizaciones/${code}/seguimiento`);
  const link = await page.getByTestId("quote-link").getAttribute("href");
  expect(link).toMatch(/\/c\/[\w-]{20,}$/);

  // 5. El cliente abre el enlace sin sesión y aprueba.
  const guest = await browser.newContext();
  const client = await guest.newPage();
  await client.goto(link!);
  await expect(client.getByTestId("quote-document")).toContainText("E2E Cusco clásico");
  await client.getByRole("button", { name: "Aprobar cotización" }).click();
  await client.getByLabel("Su nombre").fill("Ana Gutiérrez");
  await client.getByLabel(/Acepto la propuesta/).check();
  await client.getByRole("button", { name: "Confirmar aprobación" }).click();
  await expect(client.getByText(/Registramos su aprobación/)).toBeVisible();
  await guest.close();

  // 6. Ventas ve la apertura y la aprobación con evidencia.
  await page.goto(`/cotizaciones/${code}/seguimiento`);
  await expect(page.getByText(/Aprobada por el cliente: Ana Gutiérrez/)).toBeVisible();
  await expect(page.getByText("Evidencia de aprobación")).toBeVisible();
});

test("T-Q16: Ventas ve la solicitud a gerencia y Enviar bloqueado; Finanzas en modo consulta", async ({ page }) => {
  await login(page, "diego.ventas@andes.test");
  await page.goto("/cotizaciones/COT-2026-00193");
  await expect(page.getByText("Pendiente de aprobación de gerencia")).toBeVisible();
  await expect(page.getByRole("button", { name: /^Solicitar aprobación a gerencia \(bloqueado: Solicitud pendiente/ })).toBeVisible();
  const send = page.getByRole("button", { name: /^Enviar \(bloqueado/ });
  await expect(send).toBeDisabled();
  await expect(send).toHaveAttribute("title", /aprobación/i);

  await login(page, "finanzas@andes.test");
  await page.goto("/cotizaciones/COT-2026-00194");
  await expect(page.getByText(/Modo consulta/)).toBeVisible();
  await expect(page.getByRole("link", { name: "Enviar", exact: true })).toHaveCount(0);
});
