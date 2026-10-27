"use server";

import { api, ApiError } from "@/lib/api";

/** Registro público de la hoja (sitio web): sin sesión, el tenant viaja en la ruta; 5/min por IP y captcha en el backend. */
export async function submitPublicClaim(slug: string, body: Record<string, unknown>, idem: string) {
  if (!/^[a-z0-9-]{2,40}$/.test(slug)) return { error: "Sitio no válido" };
  try {
    const res = await api<{ data: unknown; message?: string }>(`/public/${slug}/claim-book`, { method: "POST", token: "", body: JSON.stringify(body), headers: { "Idempotency-Key": idem } });
    return { ok: true, data: res.data, info: res.message };
  } catch (e) {
    if (e instanceof ApiError) {
      return { error: e.status === 429 ? "Demasiados envíos desde su conexión. Espere un minuto." : e.body?.message ?? "No se pudo registrar la hoja", code: e.body?.code, fieldErrors: e.body?.errors };
    }
    return { error: "No se pudo conectar con el servidor" };
  }
}
