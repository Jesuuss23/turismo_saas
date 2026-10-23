import "server-only";
import { headers } from "next/headers";
import { sessionToken } from "./session";

export const API_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";

/** Formato de error común del backend (spec 00 §6). */
export type ApiErrorBody = {
  error: string;
  code: string;
  message: string;
  required_permissions?: string[];
  errors?: Record<string, string[]>;
  correlation_id: string;
};

export class ApiError extends Error {
  constructor(public status: number, public body: ApiErrorBody) {
    super(body?.message ?? `HTTP ${status}`);
  }
}

/** Llamada al backend desde el servidor de Next, con el token de la cookie y el X-Request-Id entrante. */
export async function api<T>(path: string, init: RequestInit & { token?: string } = {}): Promise<T> {
  const token = init.token ?? (await sessionToken());
  const incoming = (await headers()).get("x-request-id");
  // IP real del usuario para auditoría y rate limit (el backend confía en este proxy: TRUSTED_PROXIES).
  const clientIp = (await headers()).get("x-forwarded-for")?.split(",")[0].trim() ?? (await headers()).get("x-real-ip");

  const res = await fetch(`${API_URL}${path}`, {
    ...init,
    cache: "no-store",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(incoming ? { "X-Request-Id": incoming } : {}),
      ...(clientIp ? { "X-Forwarded-For": clientIp } : {}),
      ...init.headers,
    },
  });

  const body = res.status === 204 ? null : await res.json().catch(() => null);
  if (!res.ok) throw new ApiError(res.status, body);
  return body as T;
}

export type Me = {
  id: string;
  code: string | null;
  name: string;
  email: string;
  avatar_color: string | null;
  role: { name: string; display_name: string; scope: string } | null;
  experience: "backoffice" | "portal" | "mobile";
  experience_label: string | null;
  home: string;
  scope: "own" | "tenant" | "linked";
  scope_label: string;
  sede: { id: string; name: string } | null;
  tenant: { id: string; slug: string; name: string };
  mfa: { enabled: boolean; required: boolean };
  last_login_at: string | null;
};

export type AccessInfo = {
  experience: string;
  label: string;
  home: string;
  routes: string[];
  nav: { route: string; label: string; group?: string }[];
  screens: Record<string, boolean>;
};
