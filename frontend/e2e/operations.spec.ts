import { expect, test, type Page } from "@playwright/test";

// Operaciones y apps móviles (spec 05 §10, E2E-03/04/07/10). Requiere datos demo recién sembrados
// (php artisan migrate:fresh --database=pgsql_admin --seed); los casos se ejecutan en orden sobre los mismos datos.
const PASSWORD = "Turismo2026!";
const PNG = Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==", "base64");
const photo = (name: string) => ({ name, mimeType: "image/png", buffer: Buffer.concat([PNG, Buffer.from(name)]) });

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

/** Abre el menú "…" de un servicio de la Orden 360 y elige una acción. */
async function serviceAction(page: Page, service: RegExp, action: string) {
  const row = page.getByRole("row").filter({ hasText: service });
  if ((await row.locator("details").getAttribute("open")) === null) await row.locator("summary").click();
  await row.getByRole("link", { name: action }).click();
}

const app = (page: Page) => page.getByLabel("App del operador");

test("E2E-03: reserva confirmada → orden → solicitud y confirmación del proveedor → asignación con advertencia → voucher → app del pasajero", async ({ page }) => {
  test.setTimeout(180_000);
  await login(page, "operaciones@andes.test");
  await page.goto("/operaciones/ordenes/nueva?reserva=RES-2026-000355");
  await page.getByLabel("Coordinador").selectOption({ label: "Rosa Huamán" });
  await page.getByRole("button", { name: "Crear orden" }).click();
  await page.waitForURL(/\/operaciones\/ordenes\/OP-\d{4}-\d{4}$/);
  const order = page.url().split("/").pop()!;
  await expect(page.getByRole("heading", { name: new RegExp(`^${order} · Cusco de regreso`) })).toBeVisible();

  // Proveedor: solicitud y confirmación (OPS-BR-11 exige código).
  await serviceAction(page, /Movilidad turística/, "Enviar solicitud al proveedor");
  await page.getByRole("button", { name: "Enviar solicitud" }).click();
  await expect(page.getByRole("row").filter({ hasText: /Movilidad turística/ })).toContainText("Solicitado");
  for (const [service, code] of [[/Movilidad turística/, "QH-9001"], [/Guía profesional/, "GA-9002"]] as const) {
    await serviceAction(page, service, "Registrar confirmación del proveedor");
    await page.getByLabel(/Código de confirmación/).fill(code);
    await page.getByRole("button", { name: "Registrar confirmación" }).click();
    await expect(page.getByRole("row").filter({ hasText: service })).toContainText(code);
  }

  // Con servicios que requieren recursos, la orden queda en Pendiente de asignación y no puede marcarse asignada (OPS-BR-05).
  await expect(page.getByRole("button", { name: /Marcar como asignada \(bloqueado: Hay 2 servicio/ })).toBeDisabled();

  // Movilidad con conductor y vehículo de documentos por vencer: advertencias que hay que asumir (OPS-BR-26/27, OPS-BR-28).
  const pick = async (label: string, text: string) => {
    const select = page.getByLabel(label, { exact: true });
    await select.selectOption((await select.locator("option", { hasText: text }).getAttribute("value"))!);
  };
  await page.getByRole("row").filter({ hasText: /Movilidad turística/ }).getByRole("link", { name: "Asignar" }).click();
  await pick("Conductor", "C02 · Elmer Huamán");
  await pick("Vehículo", "V02 · CUS-456");
  await expect(page.getByText(/La licencia de Elmer Huamán vence/)).toBeVisible();
  await expect(page.getByText(/El SOAT de CUS-456 vence/)).toBeVisible();
  await expect(page.getByRole("button", { name: "Asignar recursos" })).toBeDisabled();
  await page.getByLabel(/Asumo las advertencias/).check();
  await page.getByRole("button", { name: "Asignar recursos" }).click();
  const bus = page.getByRole("row").filter({ hasText: /Movilidad turística/ });
  await expect(bus).toContainText("Conductor: Elmer Huamán");
  await expect(bus).toContainText("2 advertencia(s) asumidas por Rosa Huamán");

  await page.getByRole("row").filter({ hasText: /Guía profesional/ }).getByRole("link", { name: "Asignar" }).click();
  await pick("Guía", "G02 · Nadia Flores");
  await page.getByRole("button", { name: "Asignar recursos" }).click();
  await expect(page.getByRole("row").filter({ hasText: /Guía profesional/ })).toContainText("Guía: Nadia Flores");
  // Con todo asignado la orden avanza sola a Asignada; confirmarla espera la aceptación de los recursos (OPS-BR-05).
  await expect(page.getByRole("button", { name: /Confirmar operación \(bloqueado: 2 asignación\(es\) sin confirmar/ })).toBeDisabled();

  // Voucher del servicio confirmado y visible en la app del pasajero.
  await serviceAction(page, /Guía profesional/, "Emitir voucher");
  await page.getByRole("button", { name: "Emitir voucher" }).click();
  await page.waitForURL(/\/operaciones\/vouchers\/VOU-\d{4}-\d+$/);
  const voucher = page.url().split("/").pop()!;
  await expect(page.getByLabel(`Voucher ${voucher}`)).toContainText("GA-9002");
  await expect(page.getByLabel(`Voucher ${voucher}`)).toContainText("Nadia Flores");

  await page.goto("/mobile/pasajero?reserva=RES-2026-000355&tab=vouchers");
  await page.getByRole("link", { name: new RegExp(voucher) }).click();
  await expect(page.getByLabel("App del pasajero").getByRole("img", { name: "Código QR del voucher" })).toBeVisible();
  await expect(page.getByLabel("App del pasajero")).toContainText("Guía profesional bilingüe");
});

test("E2E-04: app operador: confirmar → iniciar → check-in → evidencia → finalizar → la orden 360 lo muestra completado", async ({ page }) => {
  test.setTimeout(120_000);
  // Nadia confirma su asignación pendiente desde la app.
  await login(page, "guia2@andes.test");
  await page.waitForURL(/\/mobile\/operador/);
  await app(page).getByRole("button", { name: /Guía profesional bilingüe/ }).first().click();
  await app(page).getByRole("button", { name: "Confirmar asignación" }).click();
  await expect(app(page).getByRole("status")).toContainText("Confirmar asignación: listo");

  // Wilber ejecuta el City Tour de hoy.
  await login(page, "guia1@andes.test");
  await page.goto("/mobile/operador");
  await app(page).getByRole("button", { name: /Guía profesional bilingüe/ }).first().click();
  await app(page).getByRole("button", { name: "Iniciar servicio" }).click();
  await expect(app(page).getByText("En curso").first()).toBeVisible();
  await app(page).getByRole("button", { name: "Check-in de John Miller" }).click();
  await expect(app(page).getByRole("button", { name: "Deshacer check-in de John Miller" })).toBeVisible();
  await app(page).getByRole("button", { name: /Check-in grupal/ }).click();
  await expect(app(page).getByText(/2\/2 con check-in/)).toBeVisible();
  await app(page).getByLabel("Foto de evidencia").setInputFiles(photo("city-tour.png"));
  await expect(app(page).getByText(/📷 city-tour\.png/)).toBeVisible();
  await app(page).getByRole("button", { name: "Finalizar servicio" }).click();
  await expect(app(page).getByText("Completada").first()).toBeVisible();

  await login(page, "operaciones@andes.test");
  await page.goto("/operaciones/ordenes/OP-2026-0417");
  const row = page.getByRole("row").filter({ hasText: /Guía profesional/ });
  await expect(row).toContainText("Completada");
  await page.getByRole("link", { name: /^Evidencias/ }).click();
  await expect(page.getByText("city-tour.png")).toBeVisible();
});

test("E2E-07: app operador sin conexión: 3 acciones en la bandeja → reconectar → sincronizadas con conflicto resuelto", async ({ browser }) => {
  test.setTimeout(120_000);
  const field = await (await browser.newContext()).newPage();
  const office = await (await browser.newContext()).newPage();
  await login(field, "conductor@andes.test");
  await login(office, "conductor@andes.test");
  await field.goto("/mobile/operador");

  await app(field).getByRole("button", { name: "Perfil" }).click();
  await app(field).getByRole("switch", { name: "Sin conexión" }).check();
  await expect(app(field).getByRole("button", { name: "Estado de red" })).toContainText("Sin conexión");
  await app(field).getByRole("button", { name: "Hoy" }).click();
  await app(field).getByRole("button", { name: /Movilidad turística City Tour/ }).first().click();
  await app(field).getByRole("button", { name: "Iniciar servicio" }).click();
  await app(field).getByRole("button", { name: /Check-in grupal/ }).click();
  await app(field).getByLabel("Foto de evidencia").setInputFiles(photo("bus.png"));
  await expect(app(field).getByRole("button", { name: "Estado de red" })).toContainText("Sin conexión · 3");
  await app(field).getByRole("button", { name: "Perfil" }).click();
  await expect(app(field).getByText("Bandeja de salida (3)")).toBeVisible();

  // Mientras tanto, el mismo servicio se inicia desde otro dispositivo con conexión.
  await office.goto("/mobile/operador");
  await app(office).getByRole("button", { name: /Movilidad turística City Tour/ }).first().click();
  await app(office).getByRole("button", { name: "Iniciar servicio" }).click();
  await app(office).getByRole("button", { name: /Check-in grupal/ }).click();
  await expect(app(office).getByText(/2\/2 con check-in/)).toBeVisible();

  await app(field).getByRole("switch", { name: "Sin conexión" }).uncheck();
  await expect(app(field).getByRole("status")).toContainText(/Sincronizado: 3 acción\(es\) · 2 conflicto\(s\) resuelto\(s\)/);
  await expect(app(field).getByText("Bandeja de salida (0)")).toBeVisible();
  await expect(app(field).getByText("Conflicto resuelto").first()).toBeVisible();
  await expect(app(field).getByText(/se conserva/).first()).toBeVisible();

  await app(field).getByRole("button", { name: "Hoy" }).click();
  await app(field).getByRole("button", { name: /Movilidad turística City Tour/ }).first().click();
  await app(field).getByRole("button", { name: "Finalizar servicio" }).click();
  await expect(app(field).getByText("Completada").first()).toBeVisible();
});

test("E2E-10: incidencia crítica desde la app → alerta a gerencia → resolución con evidencia → cierre → incidencias gestionadas para el cierre", async ({ page }) => {
  test.setTimeout(150_000);
  await login(page, "guia1@andes.test");
  await page.goto("/mobile/operador");
  await app(page).getByRole("button", { name: "Incidencias" }).click();
  await app(page).getByRole("button", { name: "Reportar" }).click();
  await app(page).getByLabel("Tipo").selectOption("Salud del pasajero");
  await app(page).getByText("Crítica", { exact: true }).click();
  await app(page).getByLabel("Título").fill("Pasajera con mal de altura");
  await app(page).getByLabel("Descripción").fill("Emily presenta mareo intenso en Sacsayhuamán; se solicita asistencia médica.");
  await app(page).getByRole("button", { name: "Enviar incidencia" }).click();
  await expect(app(page).getByRole("status")).toContainText(/Incidencia INC-\d+ registrada/);
  const incident = (await app(page).getByRole("status").innerText()).match(/INC-\d+/)![0];

  await login(page, "gerencia@andes.test");
  await page.goto("/operaciones/incidencias");
  await expect(page.getByText(/incidencia\(s\) crítica\(s\) activa\(s\)/).locator("..")).toContainText(incident);
  await page.goto(`/operaciones/incidencias/${incident}`);
  await expect(page.getByRole("table").last()).toContainText("whatsapp");

  await login(page, "operaciones@andes.test");
  await page.goto(`/operaciones/incidencias/${incident}`);
  await page.getByRole("link", { name: "Pasar a en proceso" }).click();
  await page.getByRole("button", { name: "Pasar a en proceso" }).click();
  await page.getByRole("link", { name: "Registrar resolución" }).click();
  await page.getByRole("textbox", { name: "Resolución" }).fill("Se trasladó a la clínica; la pasajera se recuperó y continuó el viaje.");
  await page.getByRole("button", { name: "Registrar resolución" }).click();
  await page.getByRole("link", { name: "Adjuntar evidencia" }).click();
  await page.getByLabel("Archivos de evidencia").setInputFiles(photo("informe-medico.png"));
  await page.getByRole("button", { name: "Adjuntar evidencia" }).click();
  await expect(page.getByText("informe-medico.png", { exact: true })).toBeVisible();
  await page.getByRole("link", { name: "Cerrar incidencia" }).click();
  await page.getByRole("button", { name: "Cerrar incidencia" }).click();
  await expect(page.getByText("Cerrada").first()).toBeVisible();

  // La otra incidencia de la orden también se cierra: el cierre deja de estar bloqueado por incidencias.
  await page.goto("/operaciones/incidencias/INC-0088");
  await page.getByRole("link", { name: "Cerrar incidencia" }).click();
  await page.getByRole("textbox", { name: "Comentario de cierre" }).fill("Menú sin mariscos confirmado con el restaurante.");
  await page.getByRole("button", { name: "Cerrar incidencia" }).click();
  await page.goto("/operaciones/ordenes/OP-2026-0417/cierre");
  await expect(page.getByText(/✓ Incidencias gestionadas/)).toBeVisible();
});

test("RBAC: Ventas sin Operaciones; guía solo su app; pasajero solo su viaje", async ({ page }) => {
  await login(page, "carla.ventas@andes.test");
  await page.goto("/operaciones/ordenes");
  await expect(page.getByRole("heading", { name: "No tiene acceso a esta pantalla" })).toBeVisible();

  await login(page, "conductor@andes.test");
  await page.goto("/operaciones/ordenes/OP-2026-0417");
  await expect(page.getByRole("heading", { name: "No tiene acceso a esta pantalla" })).toBeVisible();
  await page.goto("/mobile/operador?as=G01");
  await expect(page.getByLabel("App del operador")).toContainText("Raúl Mamani");
  await expect(page.getByLabel("Ver como recurso")).toHaveCount(0);

  await login(page, "pasajero@correo.test");
  await page.goto("/mobile/pasajero?reserva=RES-2026-000350");
  await expect(page.getByLabel("App del pasajero")).toContainText("RES-2026-000354");
  await expect(page.getByLabel("Reserva")).toHaveCount(0);
});
