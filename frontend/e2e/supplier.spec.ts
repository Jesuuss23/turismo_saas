import { expect, test, type Page } from "@playwright/test";

// Extranet de proveedores (spec 06 §10, SUP-E2E-01…04). Requiere datos demo recién sembrados
// (php artisan migrate:fresh --database=pgsql_admin --seed); los casos se ejecutan en orden sobre los mismos datos.
const PASSWORD = "Turismo2026!";
const HOTEL = "reservas@intiwasi.test";

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

test("SUP-E2E-01: el hotel confirma con código → Operaciones emite voucher → el hotel lo valida en recepción → Finanzas ve el uso", async ({ page }) => {
  test.setTimeout(120_000);
  // Solicitud enviada por Operaciones (datos demo): estadía de Ana Gutiérrez que llega mañana.
  await login(page, HOTEL);
  await page.waitForURL(/\/proveedor\/solicitudes$/);
  // Spec 15 suma la habitación del pedido web de U14 (Cusco Mágico), también de Ana Gutiérrez pero en semanas.
  await expect(page.getByText(/Tiene 3 solicitudes por responder/)).toBeVisible();
  const tomorrow = page.getByRole("row").filter({ hasText: "Titular: Ana Gutiérrez" }).filter({ hasText: "mañana" });
  const rs = (await tomorrow.innerText()).match(/RS-\d+/)![0];
  const row = page.getByRole("row").filter({ hasText: rs });
  await row.getByRole("link", { name: "Confirmar" }).click();
  await page.getByLabel("Código de confirmación").fill("iw-50731");
  await page.getByRole("button", { name: "Confirmar servicio" }).click();
  await expect(row).toContainText("Confirmado");
  await expect(row).toContainText("IW-50731");
  await expect(page.getByText(/Tiene 2 solicitudes por responder/)).toBeVisible();

  // Operaciones emite el voucher del servicio confirmado.
  await login(page, "operaciones@andes.test");
  await page.goto("/operaciones/vouchers");
  await page.getByRole("row").filter({ hasText: "IW-50731" }).getByRole("link", { name: "Emitir" }).click();
  await page.getByRole("button", { name: "Emitir voucher" }).click();
  await page.waitForURL(/\/operaciones\/vouchers\/VOU-\d{4}-\d+$/);
  const voucher = page.url().split("/").pop()!;

  // El hotel valida el voucher en recepción: el servicio es de mañana, así que pide confirmar el uso anticipado (SUP-BR-32).
  await login(page, HOTEL);
  await page.goto("/proveedor/vouchers");
  await page.getByLabel("Código del voucher").fill(voucher);
  await page.getByRole("button", { name: "Validar y marcar utilizado" }).click();
  await expect(page.getByText(/Registre el uso solo si el huésped ya se presentó/)).toBeVisible();
  await page.getByLabel(/El servicio es de mañana/).check();
  await page.getByRole("button", { name: "Validar y marcar utilizado" }).click();
  await expect(page.getByText("Voucher validado y marcado como utilizado")).toBeVisible();
  await page.reload();
  await expect(page.getByRole("row").filter({ hasText: voucher })).toContainText("Utilizado");

  // Finanzas ve la cuenta por pagar con el voucher utilizado como prueba de prestación.
  await login(page, "finanzas@andes.test");
  await page.goto("/finanzas/cuentas-por-pagar?provider=PRV-001");
  const payable = page.getByRole("row").filter({ hasText: voucher });
  await expect(payable).toContainText("Utilizado");
  await expect(payable).toContainText("RS-");
});

test("SUP-E2E-02: Operaciones reenvía la rooming → el hotel acusa recibo, observa una habitación y confirma el resto → Operaciones lo ve", async ({ page }) => {
  test.setTimeout(120_000);
  await login(page, "operaciones@andes.test");
  await page.goto("/operaciones/rooming");
  await page.getByRole("row").filter({ hasText: "Hotel Inti Wasi" }).getByRole("link").first().click();
  await page.getByRole("link", { name: "Agregar habitación" }).click();
  await page.getByLabel("Número").fill("305");
  await page.getByLabel("Tipo").selectOption({ index: 0 });
  await page.getByRole("button", { name: "Agregar habitación" }).click();
  await page.getByRole("button", { name: "Confirmar", exact: true }).click();
  page.once("dialog", (d) => d.accept());
  await page.getByRole("button", { name: /Reenviar al hotel|Enviar al hotel/ }).click();
  await expect(page.getByText(/· v2/)).toBeVisible();

  await login(page, HOTEL);
  await page.goto("/proveedor/rooming");
  const card = page.locator("section").filter({ hasText: "Titular: John Miller" });
  await expect(card).toContainText("Por acusar recibo");
  await card.getByRole("button", { name: "Acusar recibo" }).click();
  await expect(card).toContainText("Recibida el");
  await card.getByRole("listitem").filter({ hasText: "Hab. 305" }).getByRole("link", { name: "Reportar observación" }).click();
  await page.getByLabel("Motivo").selectOption("Habitación no disponible");
  await page.getByLabel("Impacto").selectOption("media");
  await page.getByLabel("Propuesta del hotel").fill("La 305 está en mantenimiento; ofrecemos la 310, del mismo tipo.");
  await page.getByRole("button", { name: "Enviar observación" }).click();
  await expect(card.getByRole("listitem").filter({ hasText: "Hab. 305" })).toContainText("Observación: Habitación no disponible");
  await card.getByRole("button", { name: "Confirmar habitaciones" }).click();
  await expect(card).toContainText("Confirmada por el hotel el");

  await login(page, "operaciones@andes.test");
  await page.goto("/operaciones/rooming");
  await expect(page.getByRole("row").filter({ hasText: "Hotel Inti Wasi" })).toContainText("Confirmada por el hotel");
  await page.goto("/operaciones/incidencias");
  await expect(page.getByText(/hab\. 305: Habitación no disponible/)).toBeVisible();
});

test("SUP-E2E-03: el hotel sube su factura → Finanzas la ve en cuentas por pagar y la valida", async ({ page }) => {
  await login(page, HOTEL);
  await page.goto("/proveedor/pagos");
  const row = page.getByRole("row").filter({ hasText: "Sin factura" }).first();
  const code = (await row.locator("td").first().locator("span").first().textContent())!.trim();
  await row.getByRole("link", { name: "Subir factura" }).click();
  await page.getByLabel("Número de factura").fill("F001-001024");
  await page.getByLabel("Fecha de emisión").fill(new Date().toISOString().slice(0, 10));
  await page.getByLabel(/Comprobante/).setInputFiles({ name: "F001-001024.pdf", mimeType: "application/pdf", buffer: Buffer.from("%PDF-1.4\n% factura de prueba\n") });
  await page.getByRole("button", { name: "Subir factura" }).click();
  const uploaded = page.getByRole("row").filter({ hasText: code });
  await expect(uploaded).toContainText("F001-001024");
  await expect(uploaded).toContainText("Registrada");

  await login(page, "finanzas@andes.test");
  await page.goto("/finanzas/cuentas-por-pagar?invoice=registrada");
  const payable = page.getByRole("row").filter({ hasText: "F001-001024" });
  await expect(payable).toContainText(code);
  await payable.getByRole("button", { name: "Validar" }).click();
  await expect(page.getByRole("row").filter({ hasText: "F001-001024" })).toHaveCount(0);
  await page.goto("/finanzas/cuentas-por-pagar?provider=PRV-001");
  await expect(page.getByRole("row").filter({ hasText: "F001-001024" })).toContainText("Validada");
});

test("SUP-E2E-04: extranet en 400 px sin scroll horizontal y con las acciones accesibles", async ({ page }) => {
  await page.setViewportSize({ width: 400, height: 860 });
  await login(page, HOTEL);
  for (const path of ["/proveedor/solicitudes", "/proveedor/rooming", "/proveedor/vouchers", "/proveedor/pagos", "/proveedor/incidencias", "/proveedor/contrato"]) {
    await page.goto(path);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow, `scroll horizontal en ${path}`).toBeLessThanOrEqual(0);
  }
  await page.goto("/proveedor/solicitudes");
  const row = page.getByRole("row").filter({ hasText: "Grupo: Promoción Colegio San Agustín" });
  await row.getByRole("link", { name: "Confirmar" }).click();
  await expect(page.getByLabel("Código de confirmación")).toBeInViewport();
  await expect(page.getByRole("button", { name: "Confirmar servicio" })).toBeVisible();
  await page.getByRole("link", { name: "Cerrar ✕" }).click();
  await page.getByRole("link", { name: "Incidencias" }).first().click();
  await page.getByRole("link", { name: "Reportar incidencia" }).click();
  await expect(page.getByRole("button", { name: "Enviar a Operaciones" })).toBeVisible();
});
