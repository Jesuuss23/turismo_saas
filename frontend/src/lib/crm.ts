/** Tipos y utilidades de presentación del CRM (spec 01). Sin dependencias de servidor. */

export type UserRef = { id: string; code: string | null; name: string } | null;
export type Coded = { code: string; label: string };
export type Paged<T, M = Record<string, unknown>> = { data: T[]; meta: { next_cursor: string | null; prev_cursor: string | null; per_page: number } & M };

export type Lead = {
  id: string; code: string; full_name: string; company: string | null; email: string | null; phone: string | null;
  channel: Coded; source: Coded; interest: Coded; destination_label: string | null; travel_date: string | null; pax: number;
  status: string; status_label: string; owner: UserRef; score: number; notes: string | null;
  duplicate_of: { type: string; id: string } | null; first_contact_at: string | null;
  discard: { reason: Coded; detail: string | null; step: number } | null;
  flow: { step: number; failed: boolean }; lock_version: number; created_at: string; created_by: UserRef;
};

export type Opportunity = {
  id: string; code: string; title: string; client: { id: string; code?: string; name?: string }; stage: string; stage_label: string;
  amount: number; currency: string; probability: number; weighted: number; expected_close_date: string; closed_at: string | null;
  owner: UserRef; destination_label: string | null; pax: number; lead_id: string | null; origin: string | null; notes: string | null;
  lost: { reason: Coded; detail: string | null; at_stage: string } | null; is_closed: boolean; reopen_stage: string | null; lock_version: number;
};

export type Activity = {
  id: string; code: string; type: string; type_label: string; subject: string; description: string | null;
  related: { type: string; id: string } | null; owner: UserRef; due_at: string; status: string; result: string | null;
  completed_at: string | null; auto_closed: boolean; lock_version: number;
};

export type Client = {
  id: string; code: string; kind: string; name: string; doc_type: string; doc_number: string; email: string | null; phone: string | null;
  country: string; city: string | null; birth_date: string | null; segment: Coded; owner: UserRef; tags: string[]; nps: number | null; contact_name: string | null;
  reservations_count: number; total_pen: number; balance_pen: number; last_purchase_date: string | null; lock_version: number;
};

export type Case = {
  id: string; code: string; client: { id: string | null; code?: string; name?: string }; type: Coded; priority: string; subject: string; is_book?: boolean; book_number?: string | null;
  description: string; status: string; status_label: string; owner: UserRef; origin: string; resolution: string | null; lock_version: number;
  created_at: string; events: { event: string; from_status: string | null; to_status: string | null; message: string; user: UserRef; created_at: string }[] | null;
};

export type Survey = { id: string; code: string; client: { id: string; code?: string; name?: string }; score: number; category: string; comment: string | null; channel: Coded; responded_at: string };

export type HistoryRow = { entity: string; entity_id: string; action: string; before: string | null; after: string | null; actor: { name: string } | null; at: string };

export type CatalogEntry = { code: string; label: string; requires_detail: boolean };
export type CrmMeta = {
  catalogs: Record<string, CatalogEntry[]>;
  bant_labels: Record<"budget" | "authority" | "need" | "timeline", string[]>;
  bant_levels: number[];
  scope: { own_only: boolean; supervisor: boolean };
  can: Record<"leads_manage" | "opportunities_manage" | "activities_manage" | "clients_manage" | "quotes_create", boolean>;
};

export const STAGES = [
  { code: "calificacion", name: "Calificación" },
  { code: "descubrimiento", name: "Descubrimiento" },
  { code: "cotizacion", name: "Cotización" },
  { code: "negociacion", name: "Negociación" },
  { code: "ganada", name: "Ganada" },
  { code: "perdida", name: "Perdida" },
] as const;

export const LEAD_FLOW = ["Lead recibido", "Validar", "Detectar duplicado", "Registrar", "Asignar", "Primer contacto", "Calificar", "Convertir"];

export const STATUS_TONE: Record<string, string> = {
  nuevo: "sky", contactado: "indigo", calificado: "emerald", convertido: "green", duplicado: "slate", descartado: "rose",
  pendiente: "amber", vencida: "rose", completada: "green",
  calificacion: "slate", descubrimiento: "sky", cotizacion: "indigo", negociacion: "amber", ganada: "green", perdida: "rose",
  abierta: "rose", en_proceso: "amber", resuelta: "emerald", cerrada: "slate",
  promotor: "green", pasivo: "amber", detractor: "rose",
  baja: "slate", media: "amber", alta: "rose", critica: "rose",
};

export function money(amount: number, currency = "PEN") {
  return new Intl.NumberFormat("es-PE", { style: "currency", currency, maximumFractionDigits: 0 }).format(amount);
}

export function dateTime(value: string | null) {
  if (!value) return "—";
  return new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(value));
}

export function date(value: string | null) {
  if (!value) return "—";
  return new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeZone: "UTC" }).format(new Date(value.length === 10 ? `${value}T00:00:00Z` : value));
}

/** Arma un query string con filtros `filter[x]`. */
export function qs(params: Record<string, string | undefined | null>) {
  const search = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) if (v) search.set(k, v);
  const s = search.toString();
  return s ? `?${s}` : "";
}
