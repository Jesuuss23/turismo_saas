import { defineConfig } from "@playwright/test";

// Requiere el backend (php artisan serve, datos demo) y el frontend corriendo.
export default defineConfig({
  testDir: "./e2e",
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:3000",
    launchOptions: process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {},
  },
  workers: 1,
  // Canales (spec 08) corre al final: crea reservas, cobros web y aprueba cotizaciones sobre los mismos datos demo.
  projects: [
    { name: "modulos", testIgnore: /channels\.spec\.ts/ },
    { name: "canales", testMatch: /channels\.spec\.ts/, dependencies: ["modulos"] },
  ],
});
