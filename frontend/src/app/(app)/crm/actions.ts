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
  /** Respuesta de la API (p. ej. secretos que se muestran una sola vez) y detalle de errores de negocio (bloqueos). */
  data?: unknown;
  blockers?: { type: string; key: string; message: string }[];
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

/** Rutas de la API que pueden llamar los formularios del back-office y del portal. */
const ALLOWED = /^\/(crm|catalog|quotes|portal\/quotes|portal\/client|portal\/passenger|portal\/claim-book|treasury|reservations|finance|fin|ops|mobile|supplier|channels|b2b|cms|iam|settings|notifications|integrations|jobs|integration-errors|audit-events|reports|dashboard|platform|approvals|approval-rules|inbox|billing)(\/[\w\-/.?=&%]*)?$/;

function allowed(path: string) {
  return ALLOWED.test(path) && !path.includes("..");
}

function revalidateFor(path: string) {
  // Spec 12: tesorería (los pagos de reposición y cobros cambian Finanzas) y libro de reclamaciones (es un caso de Postventa).
  if (path.startsWith("/treasury")) {
    revalidatePath("/finanzas", "layout");
    return;
  }
  if (path.startsWith("/crm/claim-book") || path.startsWith("/portal/claim-book")) {
    revalidatePath("/crm", "layout");
    revalidatePath("/libro-reclamaciones");
    return;
  }
  // Spec 10: colaboración (las decisiones también cambian los módulos de origen).
  if (/^\/(approvals|approval-rules|inbox)(\/|$)/.test(path) || path.includes("/reversal-requests")) {
    revalidatePath("/aprobaciones", "layout");
    revalidatePath("/inbox", "layout");
    if (path.startsWith("/inbox")) { revalidatePath("/crm", "layout"); return; }
    ["/cotizaciones", "/reservas", "/finanzas", "/catalogo", "/canales", "/dashboard"].forEach((p) => revalidatePath(p, "layout"));
    return;
  }
  // Spec 11: documentos de viaje (checklist, gate MAPI y reglas).
  if (path.includes("/travel-docs")) {
    revalidatePath("/operaciones", "layout");
    revalidatePath("/reservas", "layout");
    return;
  }
  // Spec 09: administración, reportes y consola de plataforma.
  if (/^\/(iam|settings|notifications|integrations|jobs|integration-errors|audit-events|platform|billing)(\/|$)/.test(path)) {
    // Spec 13: el plan, los add-ons y los flags cambian el menú de todo el back-office.
    if (/^\/(billing|platform\/(features|feature-overrides))(\/|$)/.test(path)) revalidatePath("/", "layout");
    revalidatePath("/admin", "layout");
    revalidatePath("/reportes", "layout");
    return;
  }
  if (path.startsWith("/reports")) {
    revalidatePath("/reportes", "layout");
    return;
  }
  if (/^\/(channels|b2b|cms|portal\/client|portal\/passenger)\//.test(path)) {
    revalidatePath("/canales", "layout");
    if (path.startsWith("/channels/b2b/product-rates")) revalidatePath("/catalogo", "layout");
  } else if (path.startsWith("/supplier/")) revalidatePath("/proveedor", "layout");
  else if (path.startsWith("/fin/")) revalidatePath("/finanzas", "layout");
  else if (path.startsWith("/ops/") || path.startsWith("/mobile/")) {
    revalidatePath("/operaciones", "layout");
    revalidatePath("/mobile", "layout");
    revalidatePath("/reservas", "layout");
  } else if (path.startsWith("/catalog/")) {
    revalidatePath("/catalogo", "layout");
    revalidatePath("/proveedor", "layout");
  } else if (path.startsWith("/reservations") || path.startsWith("/finance")) {
    if (path.startsWith("/finance")) revalidatePath("/finanzas", "layout");
    revalidatePath("/reservas", "layout");
    revalidatePath("/cotizaciones", "layout");
    revalidatePath("/crm", "layout");
  } else if (path.startsWith("/quotes") || path.startsWith("/portal/quotes")) {
    revalidatePath("/reservas", "layout");
    revalidatePath("/cotizaciones", "layout");
    revalidatePath("/canales", "layout");
    revalidatePath("/crm", "layout");
  } else revalidatePath("/crm", "layout");
  revalidatePath("/dashboard");
}

function resolve(template: string, response: unknown) {
  return template.replace(/\{([\w.]+)\}/g, (_, path: string) =>
    String(path.split(".").reduce<unknown>((acc, k) => (acc as Record<string, unknown> | undefined)?.[k], response) ?? ""));
}

/**
 * Acción genérica de formularios CRM y Catálogo. Campos de control: `_path` (bajo /crm o /catalog),
 * `_method`, `_ifmatch` (lock_version), `_idem` (Idempotency-Key), `_redirect` (admite {data.code}).
 */
export async function runCrm(_: CrmFormState, form: FormData): Promise<CrmFormState> {
  const path = String(form.get("_path") ?? "");
  if (!allowed(path)) return { error: "Ruta no permitida" };
  const method = String(form.get("_method") ?? "POST").toUpperCase();
  const ifMatch = form.get("_ifmatch");
  const idem = form.get("_idem");

  let response: { data?: unknown; meta?: { warnings?: string[] }; message?: string; warnings?: string[] } | null;
  try {
    response = await api(path, {
      method,
      body: JSON.stringify(toBody(form)),
      headers: { ...(ifMatch !== null ? { "If-Match": String(ifMatch) } : {}), ...(idem ? { "Idempotency-Key": String(idem) } : {}) },
    });
  } catch (e) {
    if (e instanceof ApiError) {
      const body = e.body as ApiError["body"] & { duplicates?: NonNullable<CrmFormState>["duplicates"]; blockers?: NonNullable<CrmFormState>["blockers"] };
      return { error: body?.message ?? "No se pudo completar la operación", code: body?.code, fieldErrors: body?.errors, duplicates: body?.duplicates, blockers: body?.blockers };
    }
    return { error: "No se pudo conectar con el servidor" };
  }

  revalidateFor(path);
  // Pasarela: un cobro rechazado responde 200 con `result: rechazado` (la obligación se mantiene): se muestra como error y no redirige.
  const data = response?.data as { result?: string; message?: string } | undefined;
  if (data?.result === "rechazado") return { error: data.message ?? "Pago rechazado", code: "PAYMENT_DECLINED" };
  const target = form.get("_redirect");
  if (target) redirect(resolve(String(target), response));

  return { ok: true, info: String(form.get("_success") ?? data?.message ?? response?.message ?? "Guardado"), warnings: response?.meta?.warnings ?? response?.warnings, data: response?.data };
}

/** Llamada JSON directa (asistente de producto y formularios con listas de objetos). */
export async function callApi(path: string, method: string, body: unknown, headers: Record<string, string> = {}): Promise<CrmFormState & { data?: unknown }> {
  if (!allowed(path)) return { error: "Ruta no permitida" };
  try {
    const response = await api<{ data?: unknown; meta?: { warnings?: string[] }; message?: string; warnings?: string[] } | null>(path, { method, body: method === "GET" ? undefined : JSON.stringify(body), headers });
    if (method !== "GET") revalidateFor(path);
    return { ok: true, data: response?.data, info: response?.message, warnings: response?.meta?.warnings ?? response?.warnings };
  } catch (e) {
    if (e instanceof ApiError) return { error: e.body?.message ?? "No se pudo completar la operación", code: e.body?.code, fieldErrors: e.body?.errors };
    return { error: "No se pudo conectar con el servidor" };
  }
}
