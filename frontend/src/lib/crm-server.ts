import "server-only";
import { cache } from "react";
import { api, ApiError, type Me } from "./api";
import type { CrmMeta } from "./crm";

/** Catálogos y alcance del usuario (una vez por render). */
export const crmMeta = cache(async () => (await api<{ data: CrmMeta }>("/crm/meta")).data);

export const currentUser = cache(async () => (await api<{ data: Me }>("/me")).data);

export const owners = cache(async (includeOperations = false) =>
  (await api<{ data: { id: string; code: string | null; name: string; role: string }[]; meta: { fixed: boolean } }>(
    `/crm/owners${includeOperations ? "?include_operations=1" : ""}`,
  )));

export function catalogOptions(meta: CrmMeta, kind: string) {
  return (meta.catalogs[kind] ?? []).map((c) => ({ value: c.code, label: c.label }));
}

/** Devuelve null si el registro está fuera de la cartera (403 SCOPE_OWNERSHIP) para mostrar la pantalla dedicada. */
export async function fetchScoped<T>(path: string): Promise<T | null> {
  try {
    return await api<T>(path);
  } catch (e) {
    if (e instanceof ApiError && e.status === 403 && e.body?.code === "SCOPE_OWNERSHIP") return null;
    throw e;
  }
}
