/** Canales y portales (spec 08): tipos de la API /channels, /b2b, /cms, /portal/client y /portal/passenger. */

export type Agency = {
  id: string; code: string; name: string; ruc: string | null; contact_name: string | null; booking_email: string | null; price_list_id: string | null;
  price_list: string | null; commission_pct: string; credit_currency: string; credit_limit: string; credit_used: string; credit_available: string;
  usage_pct: number | null; valid_from: string | null; valid_to: string | null; status: string; effective_status: string; status_label: string;
  block_reason: string | null; block_note: string | null; restriction: string | null; client_id: string | null; lock_version: number;
};

export type B2bSummary = {
  active: number; total: number; credit_granted: string; credit_used: string; sales: string; sales_count: number; commissions_pending: string;
  restricted: { code: string; name: string; status: string; status_label: string; reason: string | null }[];
};

export type B2bMe = {
  id: string; code: string; name: string; ruc: string | null; status: string; status_label: string; price_list: string | null; commission_pct: string;
  credit_currency: string; credit_limit: string; credit_used: string; credit_available: string; valid_from: string | null; valid_to: string | null;
  credit_enabled: boolean; notice: string | null; expiring_days: number | null; booking_email: string | null; contact_name: string | null;
};

export type Departure = { id: string; ref: string; date: string; time: string; capacity: number; available: number; status: string; closed: boolean; meeting_point: string | null };

export type B2bProduct = {
  id: string; ref: string; name: string; destination: string | null; category: string | null; duration: string; min_pax: number; max_pax: number;
  advance_min_days: number; by_departure: boolean; ok: boolean; reason: string | null; net: string | null; currency: string | null; pvp: string | null;
  pvp_currency: string | null; commission_pct: string; commission: string | null; credit: boolean; valid_to: string | null; conditions: string | null;
  restrictions: string | null; departures: Departure[];
};

export type B2bReservation = {
  code: string; title: string; status: string; status_label: string; travel_from: string; travel_to: string; pax: number; total: string; currency: string;
  agency_ref: string | null; pay_mode: string | null; created_at: string;
  commission_pct?: string;
  services?: { code: string; name: string; date: string; time: string | null; pax: number; status: string; status_label: string; confirmation_code: string | null; voucher: string | null; voucher_note: string | null }[];
  passengers?: { name: string; doc: string; type: string; placeholder: boolean }[];
  receivables?: { id: string; code: string; concept: string; amount: string; balance: string; currency: string; due_date: string; status: string; status_label: string }[];
};

export type Statement = {
  kpis: { billed: string; paid: string; due: string; commissions: string; net: string };
  receivables: { id: string; code: string; reservation: string | null; concept: string; amount: string; paid: string; balance: string; currency: string; due_date: string; status: string; status_label: string; commission: string; reported: boolean }[];
  commissions: { code: string; reservation: string | null; amount: string; currency: string; pct: string; status: string; status_label: string; requested: boolean }[];
  payments: { code: string; method: string; amount: string; currency: string; paid_on: string; reference: string | null; status: string; status_label: string; reported: boolean }[];
  settlements: { code: string; amount_pen: string; status: string; status_label: string; at: string; count: number }[];
  can_settle: boolean; methods: string[];
};

export type EcomOrder = {
  id: string; code: string; ordered_at: string; customer: string; email: string; phone: string | null; country: string | null; product: string | null;
  product_ref: string | null; service_date: string; pax: number; total: string; currency: string; discount: string; promo_code: string | null; status: string;
  status_label: string; reservation: string | null; attempts: number | null; hold_expires_at: string | null; can_cancel: boolean; can_send_link: boolean; lock_version: number;
};

export type EcomProduct = {
  id: string; ref: string; name: string; status: string; destination: string | null; web_price: string | null; currency: string | null; rate_channel: string | null;
  promo: string | null; published: boolean; featured: boolean; can_publish: boolean; blocker: string | null;
};

export type Promotion = {
  id: string; ref: string; product: string | null; product_ref: string | null; name: string; kind: string; value: string; label: string; channel: string;
  promo_code: string | null; min_pax: number | null; date_from: string | null; date_to: string | null; status: string;
};

export type CmsRow = {
  id: string; code: string; title: string; type: string; channel: string; slug: string; status: string; status_label: string; author: string | null;
  placement: string | null; updated_at: string; published_at: string | null;
};

export type CmsDetail = CmsRow & {
  body: string; seo_title: string | null; seo_description: string | null; valid_from: string | null; valid_to: string | null; lock_version: number;
  actions: string[]; notice: string | null; events: { at: string; user: string | null; from: string | null; to: string | null; message: string; reason: string | null }[];
};

export type ClientQuoteRow = { code: string; title: string; display_status: string; status_label: string; currency: string; version_no: number; total: string; valid_until: string; travel_from: string; travel_to: string; expired: boolean };

export type ClientReservation = {
  code: string; title: string; status: string; status_label: string; channel: string; travel_from: string; travel_to: string; currency: string; total: string; pax: number;
  services?: { code: string; name: string; date: string; time: string | null; status: string; status_label: string; pax: number }[];
  passengers?: { name: string; doc_type: string | null; doc: string | null; type: string; lead: boolean }[];
  receivables?: Receivable[];
  vouchers?: { code: string; service: string; date: string; status_label: string; version: number }[];
  itinerary_available?: boolean; policies?: unknown[];
};

export type Receivable = {
  id: string; code: string; concept: string; reservation: string | null; currency: string; amount: string; paid: string; balance: string; due_date: string;
  status: string; status_label: string; overdue: boolean; can_pay: boolean;
};

export type Ticket = { id: string; code: string; subject: string; category: string; topic: string | null; priority: string; status: string; status_label: string; message: string; reservation: string | null; created_at: string; due_at: string | null; owner: string | null };

export type Banner = { code: string; title: string; type?: string; placement: string | null; html: string };

export type PaxRules = { "R-PAX-MESSAGES": boolean; "R-PAX-FEEDBACK": boolean; "R-COMPANIONS": boolean; companions_days_before: number };

export type PaxService = {
  id: string; code: string; name: string; date: string; time: string | null; kind: string; status: string; status_label: string; provider: string | null;
  confirmation_code: string | null; meeting_point: string | null; guide: { name: string; phone: string | null } | null; driver: { name: string; vehicle: string | null } | null;
  includes: string[]; visual?: { key: string; label: string };
};

const TONE: Record<string, string> = {
  activa: "bg-emerald-50 text-emerald-800 ring-emerald-200", bloqueada: "bg-rose-50 text-rose-800 ring-rose-200", inactiva: "bg-slate-100 text-slate-700 ring-slate-200",
  vencido: "bg-amber-50 text-amber-900 ring-amber-200", vencida: "bg-amber-50 text-amber-900 ring-amber-200",
  confirmado: "bg-emerald-50 text-emerald-800 ring-emerald-200", confirmada: "bg-emerald-50 text-emerald-800 ring-emerald-200",
  pago_pendiente: "bg-amber-50 text-amber-900 ring-amber-200", pago_por_validar: "bg-amber-50 text-amber-900 ring-amber-200", pago_rechazado: "bg-rose-50 text-rose-800 ring-rose-200",
  pendiente_pago: "bg-amber-50 text-amber-900 ring-amber-200", pendiente_proveedor: "bg-sky-50 text-sky-800 ring-sky-200",
  cancelado: "bg-slate-100 text-slate-700 ring-slate-200", cancelada: "bg-slate-100 text-slate-700 ring-slate-200", expirado: "bg-slate-100 text-slate-700 ring-slate-200",
  borrador: "bg-slate-100 text-slate-700 ring-slate-200", en_revision: "bg-amber-50 text-amber-900 ring-amber-200", aprobado: "bg-sky-50 text-sky-800 ring-sky-200",
  publicado: "bg-emerald-50 text-emerald-800 ring-emerald-200", despublicado: "bg-slate-100 text-slate-700 ring-slate-200",
  abierta: "bg-sky-50 text-sky-800 ring-sky-200", parcial: "bg-amber-50 text-amber-900 ring-amber-200", pagada: "bg-emerald-50 text-emerald-800 ring-emerald-200",
  pendiente: "bg-amber-50 text-amber-900 ring-amber-200", aplicado: "bg-emerald-50 text-emerald-800 ring-emerald-200", rechazado: "bg-rose-50 text-rose-800 ring-rose-200",
  calculada: "bg-sky-50 text-sky-800 ring-sky-200", aprobada: "bg-emerald-50 text-emerald-800 ring-emerald-200", solicitada: "bg-amber-50 text-amber-900 ring-amber-200",
  done: "bg-slate-100 text-slate-700 ring-slate-200", today: "bg-sky-50 text-sky-800 ring-sky-200", ongoing: "bg-emerald-50 text-emerald-800 ring-emerald-200",
  next: "bg-white text-slate-700 ring-slate-300", alta: "bg-rose-50 text-rose-800 ring-rose-200", media: "bg-amber-50 text-amber-900 ring-amber-200",
};

export function tone(status: string) {
  return TONE[status] ?? "bg-slate-100 text-slate-700 ring-slate-200";
}

/** Prefijo de la API del portal del cliente: el cliente usa su token; un interno previsualiza por id (solo lectura). */
export function clientBase(preview: boolean, client: string) {
  return preview ? `/channels/portal-preview/clients/${client}` : "/portal/client";
}

const VOUCHER_FIELDS: [string, string][] = [["service", "Servicio"], ["date", "Fecha"], ["time", "Hora"], ["meeting_point", "Punto de encuentro"], ["provider", "Proveedor"],
  ["provider_phone", "Teléfono del proveedor"], ["confirmation_code", "Código de confirmación"], ["reservation", "Reserva"], ["client", "Cliente"]];

/** Filas legibles del documento de un voucher (snapshot del backend). */
export function voucherRows(doc: Record<string, unknown>): [string, string][] {
  return VOUCHER_FIELDS.filter(([k]) => typeof doc[k] === "string" || typeof doc[k] === "number").map(([k, label]) => [label, String(doc[k])]);
}
