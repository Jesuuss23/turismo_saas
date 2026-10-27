import type { UserRef } from "./crm";

/** Tipos y etiquetas del módulo Reservas (spec 04). Montos como string con 2 decimales; lo que el rol no ve no llega (§7). */

export type ResAction = { key: string; label: string; enabled: boolean; reason: string | null; group: "primary" | "more" };
export type ResView = { see_margin: boolean; ops_view: boolean; acc: boolean; see_cost: boolean; see_price: boolean };

export type ReservationRow = {
  id: string; code: string; title: string; client: { id: string; code: string; name: string } | null; owner: UserRef;
  status: string; status_label: string; channel: string; travel_from: string; travel_to: string; pax: number; currency: string;
  quote: { code: string | null; version_no: number | null } | null; services_confirmed: number; services_total: number;
  total?: string; balance?: string; provider_cost?: string; margin_pct?: string;
};

export type Passenger = {
  id: string; seq: number; full_name: string; doc_type: string | null; doc_number: string | null; pax_type: string; nationality: string | null;
  birth_date: string | null; age: number | null; phone: string | null; email: string | null; relationship: string | null; is_lead: boolean;
  diet_notes: string | null; is_placeholder: boolean;
};

export type BookedService = {
  id: string; code: string; name: string; product: { id: string; ref: string; name: string } | null;
  provider: { id: string; name: string; type: string; usable: boolean } | null; service_date: string; service_time: string | null; pax: number | null;
  status: string; status_label: string; is_required: boolean; confirmation_code: string | null; confirmed_by: string | null;
  requested_at: string | null; confirmed_at: string | null; reject_reason: string | null; alt_of: string | null; replaced_by: string | null;
  has_departure: boolean; cost?: string; price?: string;
};

export type Receivable = { id: string; code: string; concept: string; amount: string; paid: string; balance: string; currency: string; due_date: string; status: string; open: boolean };

export type Reservation = ReservationRow & {
  client: { id: string; code: string; name: string; segment: string | null; email: string | null; phone: string | null } | null;
  lock_version: number; snapshot_label: string; notes: string | null;
  policies: { product: string; cancel: string | null; modify: string | null; noshow: string | null; refund: string | null }[];
  rescheduled_at: string | null; cancelled_at: string | null; cancel_reason: string | null;
  penalty_amount?: string | null; refund_amount?: string | null; paid?: string;
  alerts: { tone: string; key: string; title: string; body: string }[];
  passengers: Passenger[]; services: BookedService[];
  receivables: Receivable[] | null;
  payments: { id: string; code: string; kind: string; method: string; amount: string; currency: string; paid_on: string; status: string; reference: string | null; receivable: string | null }[] | null;
  changes: { id: string; type: string; type_label: string; description: string; extra_pax: number | null; service_date: string | null; status: string; delta_amount?: string; credit_amount?: string | null; requested_by: UserRef; requested_at: string; decided_by: UserRef | null; decided_at: string | null; decision_note: string | null }[];
  pending_change: string | null;
  cancel_request: { id: string; reason: string; requested_by: UserRef; requested_at: string } | null;
  cancellation: { penalty: string; refund: string; owed: string; k: string; lines: PenaltyLine[]; reason: string; at: string } | null;
  view: ResView; actions: ResAction[];
  can: { edit_passengers: boolean; manage_services: boolean; approve_changes: boolean; register_payment: boolean; process_cancel: boolean };
};

export type PenaltyLine = { code: string; name: string; product: string | null; days: number; policy: string | null; tiers: [number, number][]; pct: number; price: string; penalty: string };

export type ResMeta = {
  statuses: Record<string, string>; channels: string[]; clients: { id: string; code: string; name: string; channel: string }[];
  owners: { id: string; name: string; role?: string | null }[]; cancel_reasons: string[]; reject_reasons: string[]; payment_methods: string[];
  providers: { id: string; name: string; type: string }[]; today: string; own_only: boolean; view: ResView;
  can: { create: boolean; modify: boolean; cancel: boolean; approve_changes: boolean; payments: boolean; receivables: boolean; reassign: boolean };
};

export type ConversionPreview = {
  existing: { code: string; status: string } | null;
  checks: { key: string; label: string; ok: boolean; detail: string }[];
  items: { line_no: number; product: string | null; service_date: string; pax: number; ok: boolean; rule: string | null; message: string; provider_hold: boolean;
    departure: { ref: string; available: number } | null; alternatives: { ref: string; date: string; time: string; available: number }[] }[];
  can_convert: boolean; message: string;
};

export const RES_FLOW = ["borrador", "confirmada", "en_operacion", "completada"] as const;

export const RES_TONE: Record<string, string> = {
  borrador: "bg-slate-100 text-slate-700 ring-slate-200", pendiente_disponibilidad: "bg-amber-50 text-amber-900 ring-amber-200",
  confirmada: "bg-emerald-50 text-emerald-800 ring-emerald-200", pendiente_pago: "bg-amber-50 text-amber-900 ring-amber-200",
  pendiente_proveedor: "bg-amber-50 text-amber-900 ring-amber-200", reprogramada: "bg-indigo-50 text-indigo-800 ring-indigo-200",
  en_operacion: "bg-sky-50 text-sky-800 ring-sky-200", completada: "bg-green-50 text-green-800 ring-green-200", cancelada: "bg-rose-50 text-rose-800 ring-rose-200",
  // Servicios y CxC
  pendiente: "bg-slate-100 text-slate-700 ring-slate-200", solicitado: "bg-sky-50 text-sky-800 ring-sky-200", confirmado: "bg-emerald-50 text-emerald-800 ring-emerald-200",
  rechazado: "bg-rose-50 text-rose-800 ring-rose-200", cancelado: "bg-slate-200 text-slate-600 ring-slate-300", completado: "bg-green-50 text-green-800 ring-green-200",
  abierta: "bg-sky-50 text-sky-800 ring-sky-200", parcial: "bg-amber-50 text-amber-900 ring-amber-200", pagada: "bg-emerald-50 text-emerald-800 ring-emerald-200",
  vencida: "bg-rose-50 text-rose-800 ring-rose-200", aplicado: "bg-emerald-50 text-emerald-800 ring-emerald-200", aplicada: "bg-emerald-50 text-emerald-800 ring-emerald-200",
  rechazada: "bg-rose-50 text-rose-800 ring-rose-200",
};
