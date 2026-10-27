import { expect, test, type Page } from "@playwright/test";

// Finanzas y Contabilidad (spec 07 §10.2: E2E-05, E2E-09, FIN-E2E-A…D). Requiere datos demo recién sembrados
// (php artisan migrate:fresh --database=pgsql_admin --seed); los casos se ejecutan en orden sobre los mismos datos.
const PASSWORD = "Turismo2026!";
const FINANCE = "finanzas@andes.test";
const ACCOUNTING = "contabilidad@andes.test";

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

const control = (page: Page, label: string) => page.getByRole("listitem").filter({ hasText: label });

test("FIN-E2E-B: Contabilidad: panel → cobro sin comprobante → emite boleta → aceptado → entrega → control del cierre en verde", async ({ page }) => {
  test.setTimeout(120_000);
  await login(page, ACCOUNTING);
  await page.goto("/dashboard");
  await expect(page.getByRole("heading", { name: "Cobros sin comprobante" })).toBeVisible();
  await expect(page.getByText("PAG-00002 · María Fernández")).toBeVisible();
  await expect(control(page, "Todo cobro aplicado con comprobante")).toContainText("✗");

  await page.goto("/finanzas/facturacion");
  await page.getByRole("row").filter({ hasText: "RES-2026-000358" }).getByRole("link", { name: "Emitir" }).click();
  const dialog = page.getByRole("form", { name: "Emitir comprobante" });
  await expect(dialog.getByText("Validación fiscal")).toBeVisible();
  await expect(dialog.getByRole("button", { name: "Emitir comprobante" })).toBeEnabled();
  await dialog.getByRole("button", { name: "Emitir comprobante" }).click();
  await page.waitForURL(/\/finanzas\/facturacion\/B001-\d{8}$/);
  await expect(page.getByRole("heading", { name: /^Boleta B001-/ })).toBeVisible();
  await expect(page.getByText("Aceptado").first()).toBeVisible();
  await expect(page.getByText(/^SON: .+ CON 70\/100 SOLES$/)).toBeVisible();

  await page.getByRole("button", { name: "Entregar al cliente" }).click();
  await expect(page.getByText(/Entregado al cliente el/)).toBeVisible();

  await page.goto("/dashboard");
  await expect(control(page, "Todo cobro aplicado con comprobante")).toContainText("✓");
  await expect(page.getByText("Todo cobro tiene comprobante.")).toBeVisible();
});

test("FIN-E2E-A: Finanzas valida el pago pendiente con match bancario → confirma y aplica → CxC pagada", async ({ page }) => {
  await login(page, FINANCE);
  await page.goto("/dashboard");
  // Spec 08 suma el pago informado por la agencia Sol Viajes (OP-902341), también por validar.
  await expect(page.getByText(/Hay 2 pagos por validar/)).toBeVisible();
  await page.getByRole("link", { name: "Revisar" }).click();
  await page.getByRole("row").filter({ hasText: "88123456" }).getByRole("link", { name: "PAG-00004" }).click();
  const drawer = page.getByRole("dialog", { name: "Pago PAG-00004" });
  await expect(drawer.getByText(/Abono en el extracto: BNK-1/)).toBeVisible();
  await drawer.getByRole("button", { name: "Validar y aplicar" }).click();
  await expect(page.getByRole("dialog", { name: "Pago PAG-00004" }).getByText("Aplicado").first()).toBeVisible();

  await page.goto("/finanzas/cobros/CXC-00731");
  await expect(page.getByText("Pagada").first()).toBeVisible();
  await expect(page.getByRole("row").filter({ hasText: "PAG-00004" })).toContainText("Aplicado");
});

test("FIN-E2E-D: conciliación completa: importar → matching → confirmar/resolver → cerrar", async ({ page }) => {
  test.setTimeout(120_000);
  await login(page, FINANCE);
  await page.goto("/finanzas/conciliacion");
  await page.getByRole("button", { name: "Importar extracto" }).click();
  await expect(page.getByText(/importado: \d+ líneas/)).toBeVisible();
  await page.getByRole("button", { name: "Ejecutar matching" }).click();
  await expect(page.getByText("Matching ejecutado")).toBeVisible();

  // Spec 08: el extracto también trae la transferencia informada por Sol Viajes (OP-902341).
  for (const text of ["CARGO PAGO PROV", "ABONO YAPE 88123456", "ABONO TRANSF OP-902341"]) {
    const row = page.getByRole("row").filter({ hasText: text });
    await expect(row).toContainText("Sugerido");
    await row.getByRole("button", { name: "Confirmar" }).click();
    await expect(page.getByRole("row").filter({ hasText: text })).toContainText("Conciliado");
  }

  const fee = page.getByRole("row").filter({ hasText: "COMISION MANTENIMIENTO CUENTA" });
  await expect(fee).toContainText("Excepción");
  await fee.getByRole("link", { name: "Resolver" }).click();
  await page.getByLabel("Resolución").selectOption("other");
  await page.getByLabel("Sustento").fill("Comisión de mantenimiento de cuenta del mes según tarifario BCP");
  await page.getByRole("button", { name: "Resolver excepción" }).click();
  await expect(page.getByRole("row").filter({ hasText: "COMISION MANTENIMIENTO CUENTA" })).toContainText("Conciliado");

  page.once("dialog", (d) => d.accept());
  await page.getByRole("button", { name: "Cerrar conciliación" }).click();
  await expect(page.getByRole("row").filter({ hasText: /CON-/ })).toBeVisible();
  await expect(page.getByText("Sin líneas: importe un extracto")).toBeVisible();
});

test("FIN-E2E-C: Gerencia ve CxC y pagos sin acciones; aprueba una CxP y una comisión; no ve Pagar", async ({ page }) => {
  await login(page, "gerencia@andes.test");
  await page.goto("/finanzas/cobros");
  await expect(page.getByText(/Modo supervisión: solo lectura/)).toBeVisible();
  await expect(page.getByRole("link", { name: "Registrar pago" })).toHaveCount(0);
  await expect(page.getByRole("link", { name: "Solicitar anticipo" })).toHaveCount(0);
  await expect(page.getByRole("columnheader", { name: "Acciones" })).toHaveCount(0);

  await page.goto("/finanzas/pagos?code=PAG-00002");
  const drawer = page.getByRole("dialog", { name: "Pago PAG-00002" });
  await expect(drawer.getByText("Su rol no puede revertir pagos (PROC-043).")).toBeVisible();
  await expect(drawer.getByRole("button")).toHaveCount(0);

  await page.goto("/finanzas/cuentas-por-pagar?approval=pendiente");
  await page.getByRole("row").filter({ hasText: "Ingreso adicional al Qorikancha" }).getByRole("link").first().click();
  await page.getByRole("button", { name: "Aprobar costo" }).click();
  await expect(page.getByText(/^Aprobada \d/)).toBeVisible();
  await expect(page.getByRole("link", { name: "Pagar", exact: true })).toHaveCount(0);
  await expect(page.getByRole("link", { name: "Programar", exact: true })).toHaveCount(0);

  await page.goto("/finanzas/comisiones?status=calculada");
  await page.getByRole("row").filter({ hasText: "RES-2026-000351" }).getByRole("link").first().click();
  const commission = page.getByRole("dialog", { name: /^Comisión COM-/ });
  await commission.getByRole("button", { name: "Aprobar comisión" }).click();
  await expect(page.getByRole("dialog", { name: /^Comisión COM-/ }).getByText("Aprobada").first()).toBeVisible();
  await expect(page.getByRole("button", { name: "Pagar comisión" })).toHaveCount(0);
});

test("E2E-05: obligación → registrar pago → emitir comprobante → registrar costo → aprobar → rentabilidad actualizada → liquidación", async ({ page }) => {
  test.setTimeout(150_000);
  await login(page, FINANCE);
  // La reserva ya tiene sus obligaciones 40/60 por el total: un anticipo adicional se rechaza (PROC-041).
  await page.goto("/finanzas/cobros?accion=anticipo");
  await page.getByLabel("Reserva").fill("RES-2026-000351");
  await page.getByLabel("Importe").fill("50");
  await page.getByRole("button", { name: "Crear obligación" }).click();
  await expect(page.getByText(/excede el saldo no comprometido/)).toBeVisible();

  await page.goto("/finanzas/cobros");
  await page.getByRole("row").filter({ hasText: "CXC-00717" }).getByRole("link", { name: "Cobrar" }).click();
  await page.getByLabel("Medio de pago").selectOption("Transferencia BCP");
  await expect(page.getByLabel("Monto (PEN)")).toHaveValue("100.80");
  await page.getByLabel(/N° de operación/).fill("OP-551234");
  await page.getByRole("button", { name: "Registrar pago" }).click();
  await page.waitForURL(/\/finanzas\/cobros\/CXC-00717$/);
  await expect(page.getByText("Pagada").first()).toBeVisible();

  await page.getByRole("link", { name: "Emitir comprobante" }).click();
  const dialog = page.getByRole("form", { name: "Emitir comprobante" });
  await expect(dialog.getByRole("button", { name: "Emitir comprobante" })).toBeEnabled();
  await dialog.getByRole("button", { name: "Emitir comprobante" }).click();
  await page.waitForURL(/\/finanzas\/facturacion\/B001-\d{8}$/);
  await expect(page.getByText("Aceptado").first()).toBeVisible();

  await page.goto("/finanzas/rentabilidad/RES-2026-000351");
  await expect(page.getByText("Sin costos adicionales")).toBeVisible();
  await page.getByRole("link", { name: "Registrar costo adicional para esta reserva" }).click();
  const cost = page.getByRole("dialog", { name: "Registrar costo adicional" });
  await expect(cost.getByRole("combobox", { name: "Reserva" })).toHaveValue("RES-2026-000351");
  await cost.getByLabel("Proveedor", { exact: true }).selectOption({ index: 1 });
  await cost.getByLabel("Tipo de costo").selectOption("gasto_operativo_destino");
  await cost.getByLabel("Concepto").fill("Estacionamiento en aeropuerto por demora del vuelo");
  await cost.getByLabel("Importe").fill("30");
  await expect(cost.getByLabel("Impacto en rentabilidad")).toContainText("PEN 177.00");
  await cost.getByRole("button", { name: "Registrar costo" }).click();
  await page.waitForURL(/\/finanzas\/cuentas-por-pagar\/CXP-\d+$/);
  await expect(page.getByText(/Pendiente de aprobación/)).toBeVisible();
  await page.getByRole("button", { name: "Aprobar costo" }).click();
  await expect(page.getByText(/^Aprobada \d/)).toBeVisible();

  await page.goto("/finanzas/rentabilidad");
  await expect(page.getByRole("row").filter({ hasText: "RES-2026-000351" })).toContainText("+PEN 30.00 adicionales");
  await page.getByRole("link", { name: "RES-2026-000351" }).click();
  await expect(control(page, "Costos adicionales aprobados")).toContainText("Todos aprobados");
  await expect(control(page, "Reserva completada")).toContainText("✗");
  await expect(page.getByRole("button", { name: "Cerrar liquidación" })).toBeDisabled();
});

test("E2E-09: cancelación de reserva → CxC canceladas → NC + reembolso pendiente → finanzas y auditoría", async ({ page }) => {
  test.setTimeout(150_000);
  await login(page, "admin@andes.test");
  await page.goto("/reservas/RES-2026-000358?accion=cancel");
  await expect(page.getByTestId("cancel-summary")).toContainText("Reembolso");
  await page.getByLabel("Motivo").selectOption({ index: 1 });
  await page.getByLabel("Detalle").fill("El cliente posterga el viaje familiar al próximo año");
  page.once("dialog", (d) => d.accept());
  await page.getByRole("button", { name: "Cancelar reserva", exact: true }).click();
  await expect(page.getByText("Cancelada").first()).toBeVisible();

  await login(page, FINANCE);
  await page.goto("/finanzas/cobros?status=cancelada");
  await expect(page.getByRole("row").filter({ hasText: "CXC-00733" })).toContainText("Cancelada");
  await page.goto("/finanzas/pagos?status=pendiente");
  const refund = page.getByRole("row").filter({ hasText: "RES-2026-000358" }).filter({ hasText: "Reembolso" });
  await expect(refund).toContainText("PEN 119.70");
  await expect(refund).toContainText("Pendiente");

  await login(page, ACCOUNTING);
  await page.goto("/finanzas/facturacion?type=07");
  const note = page.getByRole("row").filter({ hasText: "Ref. B001-" });
  await expect(note).toContainText("Nota de crédito");
  await expect(note).toContainText("Aceptado");
  await note.getByRole("link").first().click();
  await expect(page.getByText(/Anulación por cancelación de RES-2026-000358/).first()).toBeVisible();
  await expect(page.getByLabel("Vista previa del comprobante")).toContainText("Documento que modifica");
  await expect(page.getByText(/Comprobante BC01-\d{8} · emitir/)).toBeVisible();
});
