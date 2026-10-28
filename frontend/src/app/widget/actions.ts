"use server";

import { ApiError } from "@/lib/api";
import { siteApi } from "@/lib/site";
import type { DayStatus, Quote } from "@/lib/booking";
import type { WidgetHandoff } from "@/lib/experience";

/** Resultado de las acciones del widget: datos o error de negocio del motor (sin excepciones hacia el navegador). */
export type WidgetResult<T> = { ok: true; data: T } | { ok: false; error: string; code?: string; fields?: Record<string, string[]> };

async function run<T>(fn: () => Promise<T>): Promise<WidgetResult<T>> {
  try {
    return { ok: true, data: await fn() };
  } catch (e) {
    if (e instanceof ApiError) {
      return { ok: false, error: e.status === 429 ? "Demasiados intentos. Espere un minuto." : e.body?.message ?? "No se pudo completar la operación.", code: e.body?.code, fields: e.body?.errors };
    }
    return { ok: false, error: "No se pudo conectar con el servidor." };
  }
}

/** Calendario de un mes del producto (spec 16 §6.1, cacheado con ETag en el backend). */
export async function widgetMonth(ref: string, month: string, seats: number) {
  const [y, m] = month.split("-").map(Number);
  const last = new Date(Date.UTC(y, m, 0)).getUTCDate();
  return run(async () => (await siteApi<{ data: { days: DayStatus[]; legend: string; next_available: string | null } }>(
    `products/${encodeURIComponent(ref)}/availability`, { session: false, query: { from: `${month}-01`, to: `${month}-${String(last).padStart(2, "0")}`, seats } },
  )).data);
}

/** Cotización del motor (el navegador nunca es autoridad del precio). */
export async function widgetQuote(body: Record<string, unknown>) {
  return run(async () => (await siteApi<{ data: Quote & { fx_rate?: string } }>("quote", { session: false, method: "POST", body: JSON.stringify(body) })).data);
}

/** «Continuar reserva»: traspaso idempotente al checkout (WHO-…). */
export async function widgetHandoff(body: Record<string, unknown>, idem: string) {
  return run(async () => (await siteApi<{ data: WidgetHandoff }>("widget-handoffs", { session: false, method: "POST", body: JSON.stringify(body), headers: { "Idempotency-Key": idem } })).data);
}

/** Checkout del traspaso: crea la reserva con el `quote_token` firmado por el servidor. */
export async function bookHandoff(body: Record<string, unknown>, idem: string) {
  return run(async () => (await siteApi<{ data: { reservation: string; status: string; total: string; currency: string; currency_display?: string; deposit: string; balance: string; balance_due: string | null; hold: { ref: string; expires_at: string }; handoff?: string } }>(
    "bookings", { session: false, method: "POST", body: JSON.stringify(body), headers: { "Idempotency-Key": idem } },
  )).data);
}
