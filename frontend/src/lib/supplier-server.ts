import "server-only";
import { cache } from "react";
import { api, ApiError } from "./api";
import type { SupplierMe } from "./supplier";

/**
 * Proveedor de la sesión (una llamada por request, compartida por el marco y la pantalla).
 * `blocked` trae el mensaje del backend cuando el usuario no está vinculado a un proveedor (SUP-BR-01).
 */
export const supplierMe = cache(async (): Promise<{ me: SupplierMe; blocked: null } | { me: null; blocked: string }> => {
  try {
    return { me: (await api<{ data: SupplierMe }>("/supplier/me")).data, blocked: null };
  } catch (e) {
    if (e instanceof ApiError && e.status === 403) return { me: null, blocked: e.body?.message ?? "Su usuario no está vinculado a un proveedor." };
    throw e;
  }
});
