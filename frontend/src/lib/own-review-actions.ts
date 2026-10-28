"use server";

import { revalidatePath } from "next/cache";
import { api, ApiError } from "@/lib/api";
import { siteApi } from "@/lib/site";

export type ReviewFormState = { ok?: boolean; error?: string; fieldErrors?: Record<string, string[]>; code?: string; statusLabel?: string } | undefined;

/** FormData del formulario «Califica tu viaje» → JSON de la API (fotos en base64, hasta 3). */
async function payload(form: FormData) {
  const photos: { name: string; content_base64: string }[] = [];
  for (const f of form.getAll("photos")) {
    if (f instanceof File && f.size > 0) photos.push({ name: f.name, content_base64: Buffer.from(await f.arrayBuffer()).toString("base64") });
  }
  const subratings: Record<string, number> = {};
  for (const [k, v] of form.entries()) if (k.startsWith("subratings.") && typeof v === "string" && v !== "") subratings[k.slice(11)] = Number(v);
  return {
    product: String(form.get("product") ?? ""), stars: Number(form.get("stars") ?? 0), subratings, title: String(form.get("title") ?? ""),
    body: String(form.get("body") ?? ""), lang: String(form.get("lang") ?? "es"), consent_publish: form.get("consent_publish") === "on", photos,
  };
}

function failure(e: unknown): ReviewFormState {
  if (e instanceof ApiError) return { error: e.body?.message ?? "No se pudo enviar la reseña", fieldErrors: e.body?.errors };
  return { error: "No se pudo conectar con el servidor" };
}

/** Portal del pasajero (sesión del pasajero). */
export async function submitPassengerReview(_: ReviewFormState, form: FormData): Promise<ReviewFormState> {
  try {
    const { data } = await api<{ data: { code: string; status_label: string } }>("/portal/passenger/reviews", {
      method: "POST", headers: { "Idempotency-Key": String(form.get("_idem") || crypto.randomUUID()) }, body: JSON.stringify(await payload(form)),
    });
    revalidatePath("/canales/portal-pasajero", "layout");
    return { ok: true, code: data.code, statusLabel: data.status_label };
  } catch (e) {
    return failure(e);
  }
}

/** Formulario por enlace de la invitación (sin sesión): `/sitio/resena/{code}?t=`. */
export async function submitTokenReview(_: ReviewFormState, form: FormData): Promise<ReviewFormState> {
  try {
    const { data } = await siteApi<{ data: { code: string; status_label: string } }>("reviews/by-token", {
      method: "POST", session: false, headers: { "Idempotency-Key": String(form.get("_idem") || crypto.randomUUID()) },
      body: JSON.stringify({ ...(await payload(form)), request: form.get("request"), t: form.get("t") }),
    });
    return { ok: true, code: data.code, statusLabel: data.status_label };
  } catch (e) {
    return failure(e);
  }
}
