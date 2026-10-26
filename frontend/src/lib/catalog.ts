/** Tipos y utilidades de presentación del Catálogo turístico (spec 02). Sin dependencias de servidor. */

import type { UserRef } from "./crm";

export type Named = { id: string; name: string };
export type ProviderRef = { id: string; ref: string; name: string; status: string };

export type ProductRow = {
  id: string; ref: string; code: string; sku: string; name: string; type: string; category: string; subcategory: string | null;
  primary_destination: Named | null; status: string; status_label: string; sellable: boolean; duration: { value: number; unit: string };
  modality: string; is_bookable: boolean; is_featured: boolean; availability_model: string;
  from_price: { rate: string; currency: string; price: string; segment: string; channel: string } | null;
  margin_pct?: string | null; future_departures?: number; channels_count?: number; main_image?: string | null;
};

export type ProductActions = Record<
  "create" | "update" | "activate" | "rates" | "availability" | "publish" | "providers" | "see_costs" | "see_margin" | "see_net_rates" | "see_payables" | "see_pricing_rules" | "see_providers",
  boolean
>;

export type Product = ProductRow & {
  internal_name: string | null; product_type_id: string; category_id: string; subcategory_id: string | null; primary_destination_id: string;
  main_provider: ProviderRef | null; short_description: string | null; long_description: string | null;
  minimum_passengers: number; maximum_passengers: number; minimum_age: number | null; maximum_age: number | null;
  booking_advance_min_days: number | null; booking_advance_max_days: number | null; is_quoteable: boolean; is_b2b: boolean; is_ecommerce: boolean;
  availability_capacity: number | null; availability_source: string; features: string[]; video_url: string | null; seo_title: string | null;
  seo_description: string | null; slug: string | null; markup_pct: string | null; current_version: { id: string; version: string; status: string } | null;
  lock_version: number; created_at: string; updated_at: string; created_by: UserRef; secondary_destinations: Named[];
  checklist_summary: { ok: number; total: number };
  indicators: { rates_current: number; components: number; future_departures: number; seats_available: number; channels_published: number; versions: number };
  tabs: Record<string, string>; actions: ProductActions;
};

export type CatalogMeta = {
  types: { id: string; name: string; requires_itinerary: boolean; requires_components: boolean }[];
  categories: { id: string; ref: string; name: string; subcategories: Named[] }[];
  destinations: { id: string; name: string; type: string; level: number; parent_id: string | null }[];
  providers: (ProviderRef & { type: string; usable: boolean })[];
  services: Service[];
  seasons: Season[];
  products: { id: string; ref: string; name: string; status: string }[];
  agencies: { id: string; code: string; name: string; status: string; credit_line: number }[];
  guides: { id: string; name: string; on_vacation: boolean }[];
  vehicles: { id: string; plate: string; seats: number; in_maintenance: boolean }[];
  enums: Record<"durations" | "modalities" | "availability_models" | "rate_types" | "segments" | "rate_channels" | "price_bases" | "units" | "service_types" | "provider_types" | "itinerary_types" | "requirement_categories" | "relations" | "destination_types", string[]> & { channels: Record<string, string> };
  fx_usd: number;
  sku_prefix: string;
  can: ProductActions;
};

export type Rate = {
  id: string; ref: string; product_id: string; rate_type: string; segment: string; channel: string; currency: string; price_basis: string;
  cost?: string; price: string; min_price: string | null; max_price: string | null; margin_pct?: string | null; valid_from: string; valid_to: string;
  season: { id: string; ref: string; name: string } | null; conditions: string | null; status: string; effective_status: string; is_net: boolean; lock_version: number;
  product?: { ref: string; name: string; status: string } | null;
};

export type Season = { id: string; ref: string; name: string; type: string; date_from: string; date_to: string; priority: number; factor: string; status: string; rates?: number; products?: number; used_by_rates?: boolean };

export type Departure = {
  id: string; ref: string; product: { id: string; ref: string; name: string; status: string } | null; departure_date: string; departure_time: string;
  capacity: number; sold: number; held: number; available: number; occupancy_pct: number; status: string; provider: Named | null; provider_id: string | null;
  guide_id: string | null; vehicle_id: string | null; meeting_point: string; return_point: string | null; notes: string | null; lock_version: number;
  holds?: Hold[];
};

export type Hold = {
  id: string; ref: string; departure: { id: string; ref: string; date: string; time: string; product: { ref: string; name: string } | null } | null;
  quantity: number; reference: string; status: string; expired_pending: boolean; expires_at: string; released_reason: string | null; notes: string | null;
  created_at: string; created_by: UserRef; lock_version: number;
};

export type Service = {
  id: string; ref: string; name: string; type: string; unit: string; provider: ProviderRef | null; provider_id: string; cost?: string; currency: string;
  capacity: number | null; valid_from: string; valid_to: string; status: string; effective_status: string; selectable: boolean; lock_version: number; products?: number;
};

export type Provider = {
  id: string; ref: string; name: string; type: string; ruc: string; city: string | null; stars: number | null; contact_name: string; phone: string | null;
  email: string | null; contract_start: string; contract_end: string; currency: string; payment_terms: string | null; status: string; effective_status: string;
  rating: number | null; rating_note: string | null; lock_version: number; services?: number | Service[]; contract_expiring?: boolean;
};

export type Destination = {
  id: string; ref: string; name: string; type: string; level: number; parent_id: string | null; lat: number | null; lng: number | null;
  description: string | null; products: number; products_tree: number; children: number;
};

export type PriceRule = {
  id: string; ref: string; name: string; kind: string; value: string; scope: string; channel: string | null; min_pax: number | null;
  customer_residency: string | null; product_id: string | null; currency: string | null; priority: number; status: string;
};

export type AuditRow = { entity: string; entity_id: string; action: string; before: string | null; after: string | null; actor: UserRef; actor_role?: string; at: string };

export const PRODUCT_FLOW = ["DRAFT", "REVIEW", "APPROVED", "ACTIVE"] as const;
export const PRODUCT_STATUS_LABEL: Record<string, string> = {
  DRAFT: "Borrador", REVIEW: "En revisión", APPROVED: "Aprobado", ACTIVE: "Activo", SUSPENDED: "Suspendido", INACTIVE: "Inactivo", ARCHIVED: "Archivado",
};

const TONE: Record<string, string> = {
  DRAFT: "bg-slate-100 text-slate-700 ring-slate-200",
  REVIEW: "bg-amber-50 text-amber-800 ring-amber-200",
  APPROVED: "bg-indigo-50 text-indigo-800 ring-indigo-200",
  ACTIVE: "bg-green-50 text-green-800 ring-green-200",
  SUSPENDED: "bg-rose-50 text-rose-800 ring-rose-200",
  INACTIVE: "bg-slate-100 text-slate-600 ring-slate-200",
  ARCHIVED: "bg-slate-200 text-slate-600 ring-slate-300",
  activo: "bg-green-50 text-green-800 ring-green-200", activa: "bg-green-50 text-green-800 ring-green-200", vigente: "bg-green-50 text-green-800 ring-green-200",
  abierta: "bg-green-50 text-green-800 ring-green-200", confirmada: "bg-sky-50 text-sky-800 ring-sky-200", hold: "bg-amber-50 text-amber-800 ring-amber-200",
  completa: "bg-indigo-50 text-indigo-800 ring-indigo-200", programada: "bg-sky-50 text-sky-800 ring-sky-200", borrador: "bg-slate-100 text-slate-700 ring-slate-200",
  inactivo: "bg-slate-100 text-slate-600 ring-slate-200", inactiva: "bg-slate-100 text-slate-600 ring-slate-200", pausada: "bg-slate-100 text-slate-600 ring-slate-200",
  vencido: "bg-rose-50 text-rose-800 ring-rose-200", vencida: "bg-rose-50 text-rose-800 ring-rose-200", expirada: "bg-rose-50 text-rose-800 ring-rose-200",
  cancelada: "bg-rose-50 text-rose-800 ring-rose-200", cerrada: "bg-slate-200 text-slate-600 ring-slate-300", liberada: "bg-slate-100 text-slate-600 ring-slate-200",
  consumida: "bg-emerald-50 text-emerald-800 ring-emerald-200", historica: "bg-slate-100 text-slate-600 ring-slate-200",
};

export function toneOf(status: string) {
  return TONE[status] ?? "bg-slate-100 text-slate-700 ring-slate-200";
}

export function price(amount: string | number | null | undefined, currency = "USD") {
  if (amount === null || amount === undefined || amount === "") return "—";
  return new Intl.NumberFormat("es-PE", { style: "currency", currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(amount));
}

export function pct(value: string | number | null | undefined) {
  return value === null || value === undefined ? "—" : `${Number(value).toFixed(1)} %`;
}

/** Ruta completa de un destino (Perú › Cusco › Machu Picchu) a partir de la lista plana. */
export function destinationPath(list: { id: string; name: string; parent_id: string | null }[], id: string | null | undefined) {
  const byId = new Map(list.map((d) => [d.id, d]));
  const parts: string[] = [];
  let cur = id ? byId.get(id) : undefined;
  while (cur) {
    parts.unshift(cur.name);
    cur = cur.parent_id ? byId.get(cur.parent_id) : undefined;
  }
  return parts.join(" › ");
}

export function today() {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "America/Lima" }).format(new Date());
}

export function addDays(iso: string, days: number) {
  const d = new Date(`${iso}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}
