"use server";

import { redirect } from "next/navigation";
import { api, ApiError } from "@/lib/api";

/** Confirma la baja desde el enlace del mensaje (sin sesión; el token firmado identifica tenant, contacto y canal). */
export async function confirmUnsubscribe(token: string) {
  if (!/^[\w.-]{10,600}$/.test(token)) return;
  try {
    await api(`/public/unsubscribe/${encodeURIComponent(token)}`, { method: "POST", token: "", body: "{}" });
  } catch (e) {
    if (!(e instanceof ApiError)) throw e;
  }
  redirect(`/sitio/baja/${encodeURIComponent(token)}?ok=1`);
}
