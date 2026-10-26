"use server";

import { api, ApiError } from "@/lib/api";

type State = { ok?: boolean; error?: string; info?: string } | undefined;

/** Respuesta del cliente desde el enlace público (sin sesión: el token identifica tenant y cotización). */
export async function respondPublic(token: string, kind: "approve" | "reject", _: State, form: FormData): Promise<State> {
  if (!/^[\w-]{20,128}$/.test(token)) return { error: "Enlace no válido" };
  const body = kind === "approve"
    ? { approved_by_name: String(form.get("approved_by_name") ?? ""), accept_conditions: form.get("accept_conditions") === "true" }
    : { reason: String(form.get("reason") ?? "") };
  try {
    await api(`/public/quotes/${token}/${kind}`, { method: "POST", token: "", body: JSON.stringify(body), headers: { "Idempotency-Key": String(form.get("_idem") ?? "") } });
  } catch (e) {
    if (e instanceof ApiError) return { error: [e.body?.message, ...Object.values(e.body?.errors ?? {}).flat()].filter(Boolean).join(" · ") || "No se pudo registrar su respuesta" };
    return { error: "No se pudo conectar con el servidor" };
  }
  return { ok: true, info: kind === "approve" ? "¡Gracias! Registramos su aprobación. Su asesor le contactará para confirmar la reserva." : "Gracias por su respuesta. Su asesor la revisará." };
}
