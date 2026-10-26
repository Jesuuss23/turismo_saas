import "server-only";
import { cache } from "react";
import { api } from "./api";
import type { CatalogMeta, Product } from "./catalog";

/** Catálogos de referencia del módulo (una vez por render). */
export const catalogMeta = cache(async () => (await api<{ data: CatalogMeta }>("/catalog/meta")).data);

export const product = cache(async (ref: string) => (await api<{ data: Product }>(`/catalog/products/${encodeURIComponent(ref)}`)).data);

