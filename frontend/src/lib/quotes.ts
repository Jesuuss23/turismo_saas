import type { UserRef } from "./crm";

/** Tipos y etiquetas del módulo Cotizaciones (spec 03). Montos como string con 2 decimales (backend autoritativo). */

export type QuoteAction = { key: string; label: string; enabled: boolean; reason: string | null; group: "primary" | "more" };

export type QuoteRow = {
  id: string; code: string; title: string; client: { id: string; code: string; name: string } | null; opportunity: { id: string; code: string } | null;
  owner: UserRef; status: string; display_status: string; status_label: string; expired: boolean; version_no: number; currency: string;
  total: string; total_pen: string; valid_until: string; days_left: number; travel_from: string; internal: InternalState; created_at: string; margin_pct?: string;
};

export type InternalState = "no_requerida" | "requerida" | "pendiente" | "aprobada" | "rechazada";

export type QuoteItem = {
  id: string; line_no: number; product: { id: string; ref: string; name: string } | null; product_version: string; rate: { id: string; ref: string | null; channel: string | null } | null;
  segment: string; service_date: string; qty: number; pax: number; unit_price: string; sale_total: string; season_factor: string | null; season: string | null;
  price_source: string; unit_cost?: string; cost_total?: string; margin_pct?: string;
};

export type Approval = {
  id: string; status: string; reasons: string[]; over_cap: boolean; requested_by: UserRef; requested_at: string | null; request_note: string | null;
  decided_by: UserRef; decided_at: string | null; decision_note: string | null;
};

export type QuoteVersion = {
  id: string; version_no: number; status: string; status_label: string; issued_on: string; author: UserRef; discount_pct: string; grand_total: string;
  snapshot_label: string; frozen_at: string | null; margin_pct?: string; tax_pct: string; notes: string | null; conditions: string | null; fx_rate: string;
  content_hash: string | null; lock_version: number;
  totals: { sale: string; discount: string; net: string; tax: string; total: string; cost?: string; profit?: string; margin_pct?: string; markup_pct?: string };
  items: QuoteItem[];
  catalog_snapshot: { line_no: number; product: { ref: string; name: string; version: string; short_description: string | null }; components: string[]; rate: Record<string, unknown> | null; inclusions: string[]; exclusions: string[] }[];
  policies_snapshot: { product: string; cancel: string; modify: string; noshow: string; refund: string; advanceMin: string | null; advanceMax: string | null }[];
};

export type Quote = QuoteRow & {
  client: { id: string; code: string; name: string; country: string; segment: string; email: string | null; phone: string | null } | null;
  opportunity: { id: string; code: string; title: string; stage: string } | null;
  travel_to: string; fx_rate: string; pax: { adt: number; chd: number; inf: number; total: number }; viewed_at: string | null;
  approval: { at: string; by_name: string; channel: string; ref: string | null; recorded_by: UserRef; ip: string | null; hash: string | null; version_no: number } | null;
  rejection: { at: string; reason: string } | null; reservation_id: string | null; duplicated_from: string | null; lock_version: number; see_margin: boolean;
  version: QuoteVersion | null;
  internal_approval: { state: InternalState; reasons: string[]; over_cap: boolean; pending: Approval | null; last_decision: Approval | null } | null;
  token: { url: string; created_at: string; expires_at: string } | null;
  versions: Omit<QuoteVersion, "totals" | "items" | "catalog_snapshot" | "policies_snapshot" | "tax_pct" | "notes" | "conditions" | "fx_rate" | "content_hash" | "lock_version">[];
  sends: { id: string; version_no: number; channel: string; recipient: string; message: string | null; sent_by: UserRef; sent_at: string }[];
  views: { id: string; version_no: number; viewed_at: string; source: string; ip: string | null; recorded_by: UserRef }[];
  negotiations: { id: string; channel: string; note: string; created_version_no: number | null; by: UserRef; at: string }[];
  actions: QuoteAction[];
};

export type QuoteMeta = {
  clients: { id: string; code: string; name: string; country: string; segment: string; tax_pct: string; has_email: boolean; has_phone: boolean }[];
  opportunities: { id: string; code: string; title: string; client_id: string; stage: string; pax: number; currency: string; travel_date: string | null }[];
  owners: { id: string; name: string; role: string | null }[];
  channels: string[]; negotiation_channels: string[]; evidence: string[]; statuses: Record<string, string>; segments: string[];
  defaults: { fx: string; validity_days: number; today: string; conditions: string; discount_internal: number; margin_min: number; max_discount: number };
  can: { create: boolean; send: boolean; approve_internal: boolean; discount_override: boolean; reassign: boolean; client_approval: boolean; see_margin: boolean; export: boolean };
  own_only: boolean;
};

export type PortalQuote = {
  code: string; title: string; status: string; display_status: string; status_label: string; expired: boolean; client: string | null;
  travel_from: string; travel_to: string; valid_until: string; currency: string; pax: { adt: number; chd: number; inf: number }; version_no: number;
  items: { line_no: number; product: string | null; description: string | null; service_date: string; segment: string; qty: number; pax: number; unit_price: string; total: string; inclusions: string[]; exclusions: string[] }[];
  totals: { sale: string; discount_pct: string; discount: string; net: string; tax_pct: string; tax: string; total: string } | null;
  conditions: string | null; notes: string | null; policies: QuoteVersion["policies_snapshot"];
  approval: { at: string; by_name: string; version_no: number } | null; rejection: { at: string; reason: string } | null;
  can_respond: boolean; notice: string | null; agency?: string | null;
};

export const QUOTE_FLOW = ["borrador", "enviada", "vista", "negociacion", "aprobada", "convertida"] as const;

export const QUOTE_STATUS: Record<string, string> = {
  borrador: "Borrador", enviada: "Enviada", vista: "Vista", negociacion: "Negociación", aprobada: "Aprobada", rechazada: "Rechazada",
  vencida: "Vencida", convertida: "Convertida", cancelada: "Cancelada", reemplazada: "Reemplazada",
};

export const QUOTE_TONE: Record<string, string> = {
  borrador: "bg-slate-100 text-slate-700 ring-slate-200", enviada: "bg-sky-50 text-sky-800 ring-sky-200", vista: "bg-indigo-50 text-indigo-800 ring-indigo-200",
  negociacion: "bg-amber-50 text-amber-900 ring-amber-200", aprobada: "bg-emerald-50 text-emerald-800 ring-emerald-200", convertida: "bg-green-50 text-green-800 ring-green-200",
  rechazada: "bg-rose-50 text-rose-800 ring-rose-200", vencida: "bg-rose-50 text-rose-800 ring-rose-200", cancelada: "bg-slate-200 text-slate-600 ring-slate-300",
  reemplazada: "bg-slate-100 text-slate-500 ring-slate-200",
};

export const INTERNAL_LABEL: Record<InternalState, string> = {
  no_requerida: "No requerida", requerida: "Requerida", pendiente: "Pendiente de gerencia", aprobada: "Aprobada", rechazada: "Rechazada",
};

/** Color del margen de línea en el constructor: rojo < 15 %, ámbar < 22 %, verde ≥ 22 %. */
export function marginTone(value: string | number | undefined) {
  const v = Number(value);
  if (value === undefined || Number.isNaN(v)) return "text-slate-500";
  return v < 15 ? "text-rose-700" : v < 22 ? "text-amber-700" : "text-emerald-700";
}

/** Documento para el cliente a partir del detalle interno (mismo formato que el portal: sin costos ni margen). */
export function toDocument(q: Quote, v: QuoteVersion): PortalQuote {
  const snap = new Map(v.catalog_snapshot.map((s) => [s.line_no, s]));
  return {
    code: q.code, title: q.title, status: q.status, display_status: q.display_status, status_label: q.status_label, expired: q.expired,
    client: q.client?.name ?? null, travel_from: q.travel_from, travel_to: q.travel_to, valid_until: q.valid_until, currency: q.currency,
    pax: q.pax, version_no: v.version_no,
    items: v.items.map((i) => ({
      line_no: i.line_no, product: i.product?.name ?? null, description: snap.get(i.line_no)?.product.short_description ?? null, service_date: i.service_date,
      segment: i.segment, qty: i.qty, pax: i.pax, unit_price: i.unit_price, total: i.sale_total,
      inclusions: snap.get(i.line_no)?.inclusions ?? [], exclusions: snap.get(i.line_no)?.exclusions ?? [],
    })),
    totals: { sale: v.totals.sale, discount_pct: v.discount_pct, discount: v.totals.discount, net: v.totals.net, tax_pct: v.tax_pct, tax: v.totals.tax, total: v.totals.total },
    conditions: v.conditions, notes: v.notes, policies: v.policies_snapshot,
    approval: q.approval ? { at: q.approval.at, by_name: q.approval.by_name, version_no: q.approval.version_no } : null,
    rejection: q.rejection, can_respond: false, notice: null,
  };
}
