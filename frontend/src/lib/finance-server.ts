import "server-only";
import { cache } from "react";
import { api } from "./api";

/** Permisos efectivos del usuario (una llamada por request) para mostrar u ocultar acciones (§7.2). El backend vuelve a validar. */
export const finPerms = cache(async (): Promise<Set<string>> => new Set((await api<{ permissions: string[] }>("/me/permissions")).permissions));
