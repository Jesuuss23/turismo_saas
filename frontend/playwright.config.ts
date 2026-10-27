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
    { name: "modulos", testIgnore: /(channels|admin|collab)\.spec\.ts/ },
    { name: "canales", testMatch: /channels\.spec\.ts/, dependencies: ["modulos"] },
    // Administración (spec 09) al final: cambia permisos, usuarios y crea tenants.
    { name: "administracion", testMatch: /admin\.spec\.ts/, dependencies: ["canales"] },
    // Colaboración (spec 10) al final: decide las solicitudes y conversaciones demo.
    { name: "colaboracion", testMatch: /collab\.spec\.ts/, dependencies: ["administracion"] },
  ],
});
