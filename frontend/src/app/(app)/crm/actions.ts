"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { api, ApiError } from "@/lib/api";

export type CrmFormState = {
  ok?: boolean;
  info?: string;
  error?: string;
  code?: string;
  fieldErrors?: Record<string, string[]>;
  duplicates?: { type: string; id: string; code: string; name: string; status: string; fields: string[] }[];
  warnings?: string[];
} | undefined;

/** Convierte FormData en JSON: `a.b` anida, `x[]` arma listas, "" → null, "true"/"false" → booleanos. */
function toBody(form: FormData) {
  const body: Record<string, unknown> = {};
  for (const [rawKey, raw] of form.entries()) {
    if (rawKey.startsWith("_") || rawKey.startsWith("$ACTION")) continue;
    const value = typeof raw === "string" ? (raw === "" ? null : raw === "true" ? true : raw === "false" ? false : raw) : null;
    const isList = rawKey.endsWith("[]");
    const path = (isList ? rawKey.slice(0, -2) : rawKey).split(".");
    let target = body;
    path.slice(0, -1).forEach((p) => { target = (target[p] ??= {}) as Record<string, unknown>; });
    const leaf = path[path.length - 1];
    if (isList) {
      if (value !== null) ((target[leaf] ??= []) as unknown[]).push(value);
    } else {
      target[leaf] = value;
    }
  }
  return body;
}

function resolve(template: string, response: unknown) {
  return template.replace(/\{([\w.]+)\}/g, (_, path: string) =>
    String(path.split(".").reduce<unknown>((acc, k) => (acc as Record<string, unknown> | undefined)?.[k], response) ?? ""));
}

/**
 * Acción genérica de formularios CRM. Campos de control: `_path` (bajo /crm, /exports o /dashboard),
 * `_method`, `_ifmatch` (lock_version), `_idem` (Idempotency-Key), `_redirect` (admite {data.code}).
 */
export async function runCrm(_: CrmFormState, form: FormData): Promise<CrmFormState> {
  const path = String(form.get("_path") ?? "");
  if (!/^\/crm\/[\w\-/.]+$/.test(path)) return { error: "Ruta no permitida" };
  const method = String(form.get("_method") ?? "POST").toUpperCase();
  const ifMatch = form.get("_ifmatch");
  const idem = form.get("_idem");

  let response: { data?: unknown; meta?: { warnings?: string[] } } | null;
  try {
    response = await api(path, {
      method,
      body: JSON.stringify(toBody(form)),
      headers: { ...(ifMatch !== null ? { "If-Match": String(ifMatch) } : {}), ...(idem ? { "Idempotency-Key": String(idem) } : {}) },
    });
  } catch (e) {
    if (e instanceof ApiError) {
      const body = e.body as ApiError["body"] & { duplicates?: NonNullable<CrmFormState>["duplicates"] };
      return { error: body?.message ?? "No se pudo completar la operación", code: body?.code, fieldErrors: body?.errors, duplicates: body?.duplicates };
    }
    return { error: "No se pudo conectar con el servidor" };
  }

  revalidatePath("/crm", "layout");
  revalidatePath("/dashboard");
  const target = form.get("_redirect");
  if (target) redirect(resolve(String(target), response));

  return { ok: true, info: String(form.get("_success") ?? "Guardado"), warnings: response?.meta?.warnings };
}
