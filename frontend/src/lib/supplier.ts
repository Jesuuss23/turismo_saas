/** Extranet de proveedores (spec 06): tipos de la API /supplier y de sus contrapartes del back-office. */

export type SupplierMe = {
  provider: { id: string; ref: string; name: string; type: string; currency: string; payment_terms: string | null; status: string };
  user: { id: string; name: string; email: string; provider_role: string; provider_role_label: string };
  permissions: string[]; agency: string; today: string;
};

export type SupplierDashboard = {
  pending_requests: number; alternatives_in_review: number; response_target_hours: number;
  arrivals_7d: { services: number; pax: number }; balance: Record<string, string>; open_incidents: number;
};

export type SupplierResponse = {
  id: string; type: "confirmacion" | "rechazo" | "alternativa"; responded_at: string; confirmed_by_name: string | null; confirmation_code: string | null;
  rejection_reason: string | null; alt_kind: string | null; alt_date: string | null; alt_service: string | null; alt_net_cost: string | null; currency: string | null;
  hold_until: string | null; note: string | null; review_status: string | null; review_label: string | null; review_note: string | null;
};

export type SupplierRequestRow = {
  id: string; code: string; reservation_code: string | null; service_label: string; service_date: string; service_time: string | null; days_to: number;
  holder_label: string; pax: number | null; unit_cost: string; currency: string | null; status: string; state: string; state_label: string;
  confirmation_code: string | null; requested_at: string | null; can_respond: boolean; can_propose: boolean; response: SupplierResponse | null;
};

export type RoomingCard = {
  id: string; reservation_code: string | null; holder_label: string; check_in: string; check_out: string; nights: number; arrival: string; version: number;
  sent_at: string | null; modified: boolean; state: "por_acusar" | "recibida" | "confirmada"; state_label: string; ack_at: string | null; confirmed_at: string | null;
  lock_version: number; rooms_count: number; guests_count: number;
  rooms: { id: string; room_number: string; room_type: string; status: string; hotel_note: string | null; guests: string[] }[];
};

export type SupplierRequestDetail = SupplierRequestRow & {
  responses: SupplierResponse[]; rooming: RoomingCard | null; voucher: SupplierVoucher | null; alternative_services: { id: string; name: string; unit: string }[];
};

export type SupplierVoucher = {
  id: string; code: string; version: number; issued_at: string; service_label: string; reservation_code: string | null; service_date: string; pax: number;
  status: string; status_label: string; used_at: string | null; used_via: string | null; lock_version: number;
};

export type SupplierInvoice = {
  id: string; number: string; issue_date: string; amount: string; currency: string; amount_difference: string; file_name: string; status: string;
  status_label: string; uploaded_at: string; validation_note: string | null;
};

export type SupplierPayable = {
  id: string; code: string; reservation_code: string | null; concept: string; amount: string; paid: string; balance: string; currency: string; due_date: string;
  days_to_due: number; status: string; status_label: string; invoice: SupplierInvoice | null; can_upload: boolean;
};

export type SupplierIncident = {
  id: string; code: string; title: string; type: string; reservation_code: string | null; severity: string; severity_label: string; status: string;
  status_label: string; created_at: string; updated_at: string; can_comment: boolean;
};

export type SupplierIncidentDetail = SupplierIncident & { description: string; logs: { kind: string; message: string; at: string; author: string }[] };

export type Contact = { label: string; name: string | null; email: string | null; phone: string | null };

export type SupplierContract = {
  provider: { ref: string; name: string; ruc: string; type: string; stars: number | null; city: string | null; contract_start: string; contract_end: string;
    status: string; currency: string; payment_terms: string | null };
  alert: { tone: "warning" | "danger"; message: string } | null;
  billing: { legal_name: string | null; ruc: string | null; trade_name: string | null };
  contacts: { provider: Contact[]; agency: Contact[] };
};

export type SupplierRate = {
  id: string; ref: string; name: string; type: string; unit: string; cost: string; currency: string; valid_from: string; valid_to: string; status: string;
  pending_request: string | null; can_request: boolean; next_rate: { cost: string; currency: string; valid_from: string } | null;
};

export type RateRequestRow = {
  id: string; code: string; service: string | null; service_id: string; current_cost: string; proposed_cost: string; currency: string; effective_from: string;
  reason_type: string; reason_detail: string | null; status: string; status_label: string; requested_at: string; reviewed_at: string | null; review_note: string | null;
  provider?: string; provider_ref?: string; service_ref?: string; unit?: string;
};

export const REQUEST_FILTERS = [
  { key: "", label: "Todas" }, { key: "pending", label: "Por responder" }, { key: "alternative", label: "Alternativa propuesta" },
  { key: "confirmado", label: "Confirmado" }, { key: "rechazado", label: "Rechazado" }, { key: "completado", label: "Completado" }, { key: "cancelado", label: "Cancelado" },
];

export const REJECT_REASONS = ["Sin disponibilidad (sold out)", "Fecha bloqueada por mantenimiento", "Tarifa no vigente para la fecha", "Cupo de grupo excedido", "Otro"];
export const ALT_KINDS = ["Otra fecha", "Otro tipo de habitación", "Otro horario", "Upgrade sin costo", "Otro"];
export const OBSERVATION_KINDS = ["Habitación no disponible", "Tipo de habitación distinto", "Capacidad excedida", "Fechas no coinciden", "Otro"];
export const IMPACTS = [{ key: "baja", label: "Baja" }, { key: "media", label: "Media" }, { key: "alta", label: "Alta" }];
export const SEVERITIES = [{ key: "baja", label: "Baja" }, { key: "media", label: "Media" }, { key: "alta", label: "Alta" }, { key: "critica", label: "Crítica" }];

export const SUPPLIER_TONE: Record<string, string> = {
  abierta: "bg-sky-50 text-sky-800 ring-sky-200", pending: "bg-amber-50 text-amber-900 ring-amber-200", alternative: "bg-indigo-50 text-indigo-800 ring-indigo-200",
  por_acusar: "bg-amber-50 text-amber-900 ring-amber-200", recibida: "bg-sky-50 text-sky-800 ring-sky-200",
  registrada: "bg-sky-50 text-sky-800 ring-sky-200", validada: "bg-emerald-50 text-emerald-800 ring-emerald-200", observada: "bg-amber-50 text-amber-900 ring-amber-200",
  rechazada: "bg-rose-50 text-rose-800 ring-rose-200", aprobada: "bg-emerald-50 text-emerald-800 ring-emerald-200", retirada: "bg-slate-100 text-slate-700 ring-slate-200",
  pendiente: "bg-amber-50 text-amber-900 ring-amber-200", aceptada: "bg-emerald-50 text-emerald-800 ring-emerald-200", expirada: "bg-slate-100 text-slate-700 ring-slate-200",
  pagada: "bg-green-50 text-green-800 ring-green-200", parcial: "bg-sky-50 text-sky-800 ring-sky-200", vencida: "bg-rose-50 text-rose-800 ring-rose-200",
};

/** "en N d" / "hoy" / "hace N d" para fechas cercanas. */
export function relDays(days: number): string {
  if (days === 0) return "hoy";
  if (days === 1) return "mañana";
  return days > 0 ? `en ${days} d` : `hace ${-days} d`;
}

export function amount(value: string | number, currency: string | null | undefined): string {
  return `${currency ?? ""} ${Number(value).toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim();
}
