import "server-only";
import { cache } from "react";
import { cookies, headers } from "next/headers";
import { API_URL, ApiError } from "./api";
import type { Currency, Locale, SiteInfo } from "./site-shared";

/**
 * BFF del sitio público (spec 15 §6): el tenant sale del dominio (aquí `PUBLIC_TENANT_SLUG`), el idioma y la moneda de cookies
 * visibles, y los tokens del carrito, del pedido y de la sesión del cliente viajan solo en cookies httpOnly (el navegador nunca los lee).
 */
export const SITE_TENANT = process.env.PUBLIC_TENANT_SLUG ?? "andes";
export const LOCALE_COOKIE = "ah_locale";
export const CURRENCY_COOKIE = "ah_currency";
export const CART_COOKIE = "ah_cart";
export const SESSION_COOKIE = "ah_session";
export const ORDERS_COOKIE = "ah_orders";

const secure = process.env.NODE_ENV === "production" && process.env.E2E !== "1";
export const httpOnlyCookie = { httpOnly: true, sameSite: "lax" as const, secure, path: "/" };

export async function prefs(): Promise<{ locale: Locale; currency: Currency }> {
  const jar = await cookies();
  const locale = jar.get(LOCALE_COOKIE)?.value === "en" ? "en" : "es";
  const currency = jar.get(CURRENCY_COOKIE)?.value === "USD" ? "USD" : "PEN";
  return { locale, currency };
}

export async function orderTokens(): Promise<Record<string, string>> {
  try {
    return JSON.parse((await cookies()).get(ORDERS_COOKIE)?.value ?? "{}") as Record<string, string>;
  } catch {
    return {};
  }
}

type SiteInit = RequestInit & { session?: boolean; cart?: boolean; order?: string; query?: Record<string, string | number | undefined | null> };

/** Llamada al backend público con tenant, idioma, moneda y (según el caso) carrito, pedido o sesión del cliente. */
export async function siteApi<T>(path: string, init: SiteInit = {}): Promise<T> {
  const jar = await cookies();
  const h = await headers();
  const { locale, currency } = await prefs();
  const q = new URLSearchParams({ locale, currency });
  for (const [k, v] of Object.entries(init.query ?? {})) if (v !== undefined && v !== null && v !== "") q.set(k, String(v));
  const session = init.session !== false ? jar.get(SESSION_COOKIE)?.value : undefined;
  const cart = init.cart ? jar.get(CART_COOKIE)?.value : undefined;
  const order = init.order ? (await orderTokens())[init.order] : undefined;
  const ip = h.get("x-forwarded-for")?.split(",")[0].trim() ?? h.get("x-real-ip");
  const res = await fetch(`${API_URL}/public/${path.replace(/^\//, "")}${path.includes("?") ? "&" : "?"}${q}`, {
    ...init,
    cache: "no-store",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-Tenant-Slug": SITE_TENANT,
      "X-Locale": locale,
      ...(session ? { Authorization: `Bearer ${session}` } : {}),
      ...(cart ? { "X-Cart-Token": cart } : {}),
      ...(order ? { "X-Order-Token": order } : {}),
      ...(ip ? { "X-Forwarded-For": ip } : {}),
      ...init.headers,
    },
  });
  const body = res.status === 204 ? null : await res.json().catch(() => null);
  if (!res.ok) throw new ApiError(res.status, body);
  return body as T;
}

/** Lectura tolerante: null si el backend responde 404 (ficha despublicada, destino inexistente). */
export async function siteGet<T>(path: string, init: SiteInit = {}): Promise<T | null> {
  try {
    return (await siteApi<{ data: T }>(path, init)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) return null;
    throw e;
  }
}

export const siteInfo = cache(async () => (await siteApi<{ data: SiteInfo }>("site", { session: false })).data);

export const customer = cache(async () => {
  if (!(await cookies()).get(SESSION_COOKIE)) return null;
  try {
    return (await siteApi<{ data: import("./site-shared").Customer }>("me")).data;
  } catch {
    return null;
  }
});

export const cartCount = cache(async () => {
  if (!(await cookies()).get(CART_COOKIE)) return 0;
  try {
    return (await siteApi<{ data: { count: number } }>("carts/current", { cart: true })).data.count;
  } catch {
    return 0;
  }
});
