import { defineConfig } from "@playwright/test";

// Requiere el backend (php artisan serve, datos demo) y el frontend corriendo.
export default defineConfig({
  testDir: "./e2e",
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:3000",
    launchOptions: process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {},
  },
  workers: 1,
  // Canales (spec 08) corre después de los módulos: crea reservas, cobros web y aprueba cotizaciones sobre los mismos datos demo.
  projects: [
    { name: "modulos", testIgnore: /(channels|admin|collab|cupos|treasury|plataforma|marketing|sitio|reservas|experiencia)\.spec\.ts/ },
    { name: "canales", testMatch: /channels\.spec\.ts/, dependencies: ["modulos"] },
    // Administración (spec 09) al final: cambia permisos, usuarios y crea tenants.
    { name: "administracion", testMatch: /admin\.spec\.ts/, dependencies: ["canales"] },
    // Colaboración (spec 10) al final: decide las solicitudes y conversaciones demo.
    { name: "colaboracion", testMatch: /collab\.spec\.ts/, dependencies: ["administracion"] },
    // Cupos y documentos de viaje (spec 11): libera cupos y valida documentos de los datos demo.
    { name: "inventario", testMatch: /cupos\.spec\.ts/, dependencies: ["colaboracion"] },
    // Tesorería y libro de reclamaciones (spec 12): cierra la caja de Cusco, aprueba ARQ-0040 y registra la diferencia cambiaria.
    { name: "tesoreria", testMatch: /treasury\.spec\.ts/, dependencies: ["inventario"] },
    // Plataforma SaaS (spec 13): cambia el plan de Andes a anual, convierte el trial de TNT-0005 y habilita e-commerce en Professional.
    { name: "plataforma", testMatch: /plataforma\.spec\.ts/, dependencies: ["tesoreria"] },
    // Marketing y metas (spec 14): envía una campaña, registra un consentimiento y cierra la meta de agosto de U03 (bono en Finanzas).
    { name: "marketing", testMatch: /marketing\.spec\.ts/, dependencies: ["plataforma"] },
    // Sitio web público (spec 15): crea pedidos web, holds, leads, conversaciones y una cuenta de cliente nueva.
    { name: "sitio", testMatch: /sitio\.spec\.ts/, dependencies: ["marketing"] },
    // Motor de reservas (spec 16): crea y cierra rangos, cambia reglas de pasajeros, pago y el centro de reglas del tenant.
    { name: "reservas", testMatch: /reservas\.spec\.ts/, dependencies: ["sitio"] },
    // Experiencia (spec 17): apaga y reactiva reglas con una plantilla, envía mensajes, cambia el TC de USD, responde reseñas y crea una reserva desde el widget.
    { name: "experiencia", testMatch: /experiencia\.spec\.ts/, dependencies: ["reservas"] },
  ],
});
