"use server";

import { redirect } from "next/navigation";
import { api, ApiError } from "@/lib/api";
import { saveSession } from "@/lib/session";

export type PaxState = { error?: string; info?: string; devLink?: string } | undefined;

function message(e: unknown) {
  if (e instanceof ApiError) return e.status === 429 ? "Demasiados intentos. Espere un minuto e intente de nuevo." : e.body?.message ?? "No se pudo completar la operación";
  return "No se pudo conectar con el servidor";
}

/** PPX-BR-02: el correo debe coincidir con el titular o el cliente de la reserva; el enlace vale 30 minutos y un solo uso. */
export async function requestPassengerLink(_: PaxState, form: FormData): Promise<PaxState> {
  try {
    const { data } = await api<{ data: { sent_to: string; dev_link?: string } }>("/portal/passenger/auth/link", {
      method: "POST", token: "", headers: { "X-Tenant-Slug": String(form.get("tenant") ?? "") },
      body: JSON.stringify({ email: form.get("email"), code: form.get("code") }),
    });
    return { info: `Le enviamos un enlace de acceso a ${data.sent_to}. Vence en 30 minutos.`, devLink: data.dev_link };
  } catch (e) {
    return { error: message(e) };
  }
}

export async function verifyPassengerLink(_: PaxState, form: FormData): Promise<PaxState> {
  try {
    const { data } = await api<{ data: { token: string } }>("/portal/passenger/auth/verify", {
      method: "POST", token: "", headers: { "X-Tenant-Slug": String(form.get("tenant") ?? "") }, body: JSON.stringify({ token: form.get("token") }),
    });
    await saveSession(data.token, false);
  } catch (e) {
    return { error: message(e) };
  }
  redirect("/canales/portal-pasajero/viajes");
}
