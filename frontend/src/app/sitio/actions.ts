"use server";

import { cookies } from "next/headers";
import { revalidatePath } from "next/cache";
import { ApiError } from "@/lib/api";
import { CART_COOKIE, CURRENCY_COOKIE, httpOnlyCookie, LOCALE_COOKIE, ORDERS_COOKIE, orderTokens, SESSION_COOKIE, siteApi } from "@/lib/site";
import type { Cart, Order, Quote } from "@/lib/site-shared";

/** Resultado común de las acciones del sitio: datos o error con los mensajes por campo del backend (422). */
export type Result<T = unknown> = { ok: true; data: T } | { ok: false; error: string; code?: string; fields?: Record<string, string[]>; status?: number };

async function run<T>(fn: () => Promise<T>): Promise<Result<T>> {
  try {
    return { ok: true, data: await fn() };
  } catch (e) {
    if (e instanceof ApiError) {
      const msg = e.status === 429 ? "Demasiados intentos desde su conexión. Espere un minuto." : e.body?.message ?? "No se pudo completar la operación.";
      return { ok: false, error: msg, code: e.body?.code, fields: e.body?.errors, status: e.status };
    }
    return { ok: false, error: "No se pudo conectar con el servidor." };
  }
}

const post = <T>(path: string, body: unknown, init: Parameters<typeof siteApi>[1] = {}) =>
  siteApi<{ data: T }>(path, { ...init, method: init.method ?? "POST", body: JSON.stringify(body ?? {}) }).then((r) => r.data);

/** Idioma y moneda (cookies visibles; el backend convierte con el TC del día). */
export async function setPrefs(locale: "es" | "en", currency: "PEN" | "USD") {
  const jar = await cookies();
  jar.set(LOCALE_COOKIE, locale === "en" ? "en" : "es", { path: "/", sameSite: "lax", maxAge: 60 * 60 * 24 * 365 });
  jar.set(CURRENCY_COOKIE, currency === "USD" ? "USD" : "PEN", { path: "/", sameSite: "lax", maxAge: 60 * 60 * 24 * 365 });
  if (jar.get(CART_COOKIE)) await siteApi("carts/current/preferences", { method: "PATCH", cart: true, body: JSON.stringify({ locale, currency }) }).catch(() => null);
  revalidatePath("/sitio", "layout");
}

// --- precio y carrito ---

export async function quote(body: Record<string, unknown>) {
  return run(() => post<Quote>("price-quote", body, { session: false }));
}

async function ensureCart(): Promise<void> {
  const jar = await cookies();
  if (jar.get(CART_COOKIE)) {
    const alive = await siteApi("carts/current", { cart: true }).then(() => true).catch(() => false);
    if (alive) return;
  }
  const res = await post<Cart & { token: string }>("carts", { utm: null }, { headers: { "Idempotency-Key": crypto.randomUUID() } });
  jar.set(CART_COOKIE, res.token, { ...httpOnlyCookie, maxAge: 60 * 60 * 24 * 30 });
}

export async function addToCart(body: Record<string, unknown>) {
  return run(async () => {
    await ensureCart();
    const cart = await post<Cart>("carts/current/items", body, { cart: true });
    revalidatePath("/sitio", "layout");
    return cart;
  });
}

export async function updateLine(line: number, body: Record<string, unknown>) {
  return run(() => post<Cart>(`carts/current/items/${line}`, body, { cart: true, method: "PATCH" }));
}

export async function removeLine(line: number) {
  const r = await run(() => siteApi<{ data: Cart }>(`carts/current/items/${line}`, { cart: true, method: "DELETE" }).then((x) => x.data));
  revalidatePath("/sitio", "layout");
  return r;
}

export async function rehold(line: number) {
  return run(() => post<Cart>(`carts/current/items/${line}/hold`, {}, { cart: true }));
}

export async function setPromo(code: string | null) {
  return run(() => (code ? post<Cart>("carts/current/promo", { code }, { cart: true, method: "PUT" }) : siteApi<{ data: Cart }>("carts/current/promo", { cart: true, method: "DELETE" }).then((x) => x.data)));
}

export async function getCart() {
  return run(() => siteApi<{ data: Cart }>("carts/current", { cart: true }).then((x) => x.data));
}

// --- checkout ---

export async function validateStep(step: "comprador" | "pasajeros" | "revision", body: Record<string, unknown>) {
  return run(() => post<{ ok: boolean }>("checkout/validate", { step, ...body }, { cart: true }));
}

export async function createOrder(body: Record<string, unknown>, idem: string) {
  return run(async () => {
    const order = await post<Order & { token: string }>("checkout/orders", body, { cart: true, headers: { "Idempotency-Key": idem } });
    const jar = await cookies();
    const tokens = await orderTokens();
    tokens[order.code] = order.token;
    jar.set(ORDERS_COOKIE, JSON.stringify(Object.fromEntries(Object.entries(tokens).slice(-5))), { ...httpOnlyCookie, maxAge: 60 * 60 * 24 * 30 });
    return { code: order.code, status: order.status, amount_due_now: order.amount_due_now, currency: order.currency };
  });
}

export async function payOrder(code: string, body: Record<string, unknown>, idem: string) {
  return run(async () => {
    const r = await post<{ result: string; message: string; order: Order }>(`orders/${encodeURIComponent(code)}/payments`, body, { order: code, headers: { "Idempotency-Key": idem } });
    if (r.result !== "rechazado") {
      (await cookies()).delete(CART_COOKIE);
      revalidatePath("/sitio", "layout");
    }
    return { result: r.result, message: r.message, status: r.order.status };
  });
}

export async function lookupOrder(email: string, code: string) {
  return run(async () => {
    const r = await post<{ code: string; token: string }>("orders/lookup", { email, code }, { session: false });
    const jar = await cookies();
    const tokens = await orderTokens();
    tokens[r.code] = r.token;
    jar.set(ORDERS_COOKIE, JSON.stringify(Object.fromEntries(Object.entries(tokens).slice(-5))), { ...httpOnlyCookie, maxAge: 60 * 60 * 2 });
    return { code: r.code };
  });
}

// --- cuenta ---

async function saveSession(token: string) {
  (await cookies()).set(SESSION_COOKIE, token, { ...httpOnlyCookie, maxAge: 60 * 60 * 24 * 7 });
  revalidatePath("/sitio", "layout");
}

export async function login(email: string, password: string) {
  return run(async () => {
    const r = await post<{ token: string; user: { name: string } }>("auth/login", { email, password }, { session: false });
    await saveSession(r.token);
    return { name: r.user.name };
  });
}

export async function register(body: Record<string, unknown>) {
  return run(async () => {
    const r = await post<{ token: string; user: { name: string } }>("auth/register", body, { session: false });
    await saveSession(r.token);
    return { name: r.user.name };
  });
}

export async function logout() {
  await siteApi("me/logout", { method: "POST" }).catch(() => null);
  (await cookies()).delete(SESSION_COOKIE);
  revalidatePath("/sitio", "layout");
}

export async function updateMe(body: Record<string, unknown>) {
  return run(() => post<unknown>("me", body, { method: "PATCH" }));
}

export async function payReceivable(id: string, card: Record<string, string>, idem: string) {
  return run(async () => {
    const r = await post<{ result: string; message: string }>(`me/receivables/${encodeURIComponent(id)}/pay`, { card }, { headers: { "Idempotency-Key": idem } });
    revalidatePath("/sitio/cuenta", "layout");
    return r;
  });
}

export async function toggleWish(slug: string, on: boolean) {
  return run(async () => {
    const r = await siteApi<{ data: unknown }>(`me/wishlist/${encodeURIComponent(slug)}`, { method: on ? "POST" : "DELETE" });
    revalidatePath("/sitio/cuenta/deseos");
    return r.data;
  });
}

export async function arco(body: Record<string, unknown>) {
  return run(() => post<{ ticket: string; message: string }>("me/arco", body));
}

// --- captación ---

export async function submitLead(body: Record<string, unknown>) {
  return run(() => post<{ code: string; status: string; sla_due_at: string; message: string }>("leads", body, { session: false }));
}

export async function submitContact(body: Record<string, unknown>) {
  return run(() => post<{ conversation: string; lead: string | null; message: string }>("contact-form", body, { session: false }));
}

export async function subscribe(email: string, consent: boolean, name?: string) {
  return run(() => post<{ status: string; code: string; message: string; confirm_token?: string }>("newsletter", { email, consent, name, source: "sitio" }, { session: false }));
}

export async function chatStart(body: Record<string, unknown>) {
  return run(() => post<{ session: string; conversation: string; messages: { from: string; body: string; at: string }[] }>("chat/sessions", body, { session: false }));
}

export async function chatPost(session: string, message: string) {
  return run(() => post<{ from: string; body: string; at: string }[]>(`chat/sessions/${encodeURIComponent(session)}/messages`, { message }, { session: false }));
}

export async function chatMessages(session: string) {
  return run(() => siteApi<{ data: { from: string; body: string; at: string }[] }>(`chat/sessions/${encodeURIComponent(session)}/messages`, { session: false }).then((x) => x.data));
}

export async function track(events: { event: string; session_id: string; params?: Record<string, unknown> }[]) {
  await siteApi("analytics/events", { method: "POST", session: false, body: JSON.stringify({ events }) }).catch(() => null);
}

export async function recentEvents(session: string) {
  return run(() => siteApi<{ data: { event: string; params: Record<string, unknown>; at: string }[] }>(`analytics/events/${encodeURIComponent(session)}`, { session: false }).then((x) => x.data));
}

export async function calendar(slug: string, month: string, pax: number) {
  return run(() => siteApi<{ data: { month: string; currency: string; days: import("@/lib/site-shared").CalendarDay[]; next_available: string | null } }>(
    `products/${encodeURIComponent(slug)}/calendar`, { session: false, query: { month, pax } }).then((x) => x.data));
}
