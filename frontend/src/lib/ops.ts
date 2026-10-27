import type { UserRef } from "./crm";

/** Tipos y etiquetas del módulo Operaciones (spec 05). Operaciones ve costos de proveedor; venta y margen solo con permisos de rentabilidad. */

export type OpsAction = { key: string; label: string; enabled: boolean; reason: string | null; group?: "primary" | "more" };
export type Rule = { code: string; text: string };

export type OrderRow = {
  id: string; code: string; title: string; status: string; status_label: string;
  reservation: { id: string; code: string; status: string; client: string | null } | null;
  coordinator: UserRef | null; date_from: string; date_to: string; pax: number;
  assignments_total: number; assignments_done: number; assigned_pct: number; incidents_open: number; critical_open: number; lock_version: number;
};

export type Assignment = {
  id: string; service_id: string; label: string; date: string; time: string | null; status: string; status_label: string;
  guide: { id: string; code: string; name: string; phone: string | null } | null;
  driver: { id: string; code: string; name: string; phone: string | null } | null;
  vehicle: { id: string; code: string; name: string; capacity: number } | null;
  confirmed_at: string | null; confirmed_via: string | null; started_at: string | null; first_checkin_at: string | null; finished_at: string | null;
  checkins: number; no_show: number; warnings_ack: { items: Rule[]; user: string; at: string } | null; lock_version: number;
};

export type OrderService = {
  id: string; code: string; name: string; date: string; time: string | null; kind: string; requires_assignment: boolean;
  status: string; status_label: string; provider: { id: string; name: string; usable: boolean } | null;
  confirmation_code: string | null; cost: string; reject_reason: string | null;
  assignment: Assignment | null; voucher: { id: string; code: string; version: number } | null;
  requests: { status: string; channel: string; requested_at: string; responded_at: string | null; confirmation_code: string | null; rejection_reason: string | null }[];
  actions: OpsAction[];
};

export type OpsPassenger = {
  id: string; seq: number; name: string; type: string; age: number | null; lead: boolean; relationship: string | null;
  nationality: string | null; diet: string | null; phone: string | null; doc: string | null; placeholder: boolean;
};

export type RoomingRow = {
  id: string; reservation: { code: string; title: string } | null; hotel: { id: string; name: string } | null; check_in: string; check_out: string;
  status: string; status_label: string; rooms_count: number; sent_at: string | null; sent_version: number; hotel_ack_at: string | null;
  hotel_confirmed_at: string | null; lock_version: number;
};

export type RoomingDetail = RoomingRow & {
  rooms: { id: string; room_number: string; room_type: string; capacity: number; status: string; hotel_note: string | null;
    guests: { passenger_id: string; name: string; type: string | null }[]; occupancy: number; infants: number }[];
  passengers: { id: string; name: string; type: string }[];
  validation: { conflicts: Rule[]; warnings: Rule[] }; can_modify: boolean;
  lists: { id: string; hotel: string | null; check_in: string; status: string }[];
};

export type VoucherRow = {
  id: string; code: string; status: string; status_label: string; version: number; service_label: string; service_date: string; pax: number;
  provider: { id: string; name: string } | null; reservation: { code: string; client: string | null } | null;
  issued_at: string; used_at: string | null; used_via: string | null; void_reason: string | null; origin: string; lock_version: number;
};

export type VoucherDocument = {
  service: string | null; service_code: string | null; date: string | null; time: string | null; provider: string | null; provider_phone: string | null;
  confirmation_code: string | null; meeting_point: string; reservation: string | null; client: string | null;
  passengers: { name: string; type: string; lead: boolean; relationship: string | null; diet: string | null }[];
  guide: string | null; guide_phone: string | null; transport: string | null; includes: string[]; excludes: string[]; diets: string[];
  coordinator: string | null; emergency: string; note: string | null;
};

export type VoucherDetail = VoucherRow & {
  qr_token: string; note: string | null; document: VoucherDocument;
  versions: { version: number; issued_at: string; issued_by: UserRef | null; reason: string | null }[];
  notifications: { event: string; channel: string; recipient: string; status: string; at: string }[];
  actions: OpsAction[]; can_issue: boolean; order: { id: string; code: string } | null;
};

export type IncidentRow = {
  id: string; code: string; title: string; type: string; severity: string; severity_label: string; status: string; status_label: string;
  order: { id: string; code: string } | null; assignee: UserRef | null; reported_by: UserRef | null; source: string; created_at: string;
  sla_hours: number; sla_due_at: string; sla_pct: number | null; sla_breached: boolean; related: string | null; lock_version: number;
};

export type Evidence = {
  id: string; file_name: string; mime_type: string; size: number; source: string; service_id: string | null; incident_id: string | null;
  captured_at: string; offline: boolean; uploaded_by: { id: string; name: string } | null; sha256: string;
};

export type IncidentDetail = IncidentRow & {
  description: string; resolution: string | null; resolved_at: string | null; closed_at: string | null; step: number; flow: string[];
  reservation: { code: string; title: string } | null; service: { code: string; name: string } | null; related_code: string | null;
  logs: { kind: string; message: string; user: { name: string } | null; visibility: string; at: string }[];
  evidences: Evidence[]; notifications: { event: string; channel: string; recipient: string; at: string }[]; actions: OpsAction[];
};

export type CloseChecks = {
  checks: { key: string; rule: string; label: string; ok: boolean; detail: string }[];
  ready: boolean; status_ok: boolean; pending_tasks: string[];
};

export type OrderDetail = OrderRow & {
  reservation_detail: { code: string; title: string; status: string; status_label: string; client: string | null; notes: string | null; channel: string; owner: UserRef | null } | null;
  kpis: { services: number; supplier_confirmed: number; with_resources: number; assignments: number; vouchers_active: number; incidents_open: number; evidences: number };
  services: OrderService[];
  tasks: { id: string; title: string; done: boolean; done_by: UserRef | null; done_at: string | null }[];
  passengers: OpsPassenger[]; alert_tags: string[];
  rooming: RoomingRow[]; vouchers: VoucherRow[]; incidents: IncidentRow[]; evidences: Evidence[];
  close: CloseChecks; actions: OpsAction[];
  can: { assign: boolean; vouchers: boolean; incidents: boolean; close: boolean; rooming: boolean; see_margin: boolean };
};

export type ResourceCard = {
  type: "guide" | "driver" | "vehicle"; id: string; code: string; name: string; status: string; manual_status: string; phone: string | null;
  languages: string[] | null; rating: string | null; capacity: number | null; license_class: string | null;
  document: { label: string; expires_on: string; alert: "vencido" | "por_vencer" | null } | null;
  occupations: { source_type: string; source_id: string; order_id: string | null; order_code: string | null; date: string; time: string | null; label: string; status: string }[];
  statuses: string[];
};

export type CalendarEvent = {
  type: "service" | "departure"; id: string; date: string; time: string | null; kind: string; title: string; provider: string | null;
  status: string; status_label: string; order: { id: string; code: string } | null; assignment_id: string | null; resources: string[]; pending: boolean;
};

export const ORDER_FLOW = ["planificada", "pendiente_asignacion", "asignada", "confirmada", "en_ejecucion", "completada"] as const;
export const ORDER_FLOW_LABELS = ["Planificada", "Pend. asignación", "Asignada", "Confirmada", "En ejecución", "Completada"];

export const RESOURCE_TYPE: Record<string, string> = { guide: "Guía", driver: "Conductor", vehicle: "Vehículo" };
export const KIND_LABEL: Record<string, string> = {
  traslado: "Traslado", tour: "Tour / guiado", alojamiento: "Alojamiento", tren_entrada: "Tren / entrada", libre: "Otro", salida: "Salida programada",
};
export const LANGUAGE: Record<string, string> = { es: "Español", en: "Inglés", fr: "Francés", de: "Alemán", pt: "Portugués", it: "Italiano", qu: "Quechua", ja: "Japonés", zh: "Chino" };

export const OPS_TONE: Record<string, string> = {
  planificada: "bg-slate-100 text-slate-700 ring-slate-200", pendiente_asignacion: "bg-amber-50 text-amber-900 ring-amber-200",
  asignada: "bg-indigo-50 text-indigo-800 ring-indigo-200", confirmada: "bg-emerald-50 text-emerald-800 ring-emerald-200",
  en_ejecucion: "bg-sky-50 text-sky-800 ring-sky-200", completada: "bg-green-50 text-green-800 ring-green-200", cancelada: "bg-rose-50 text-rose-800 ring-rose-200",
  pendiente: "bg-slate-100 text-slate-700 ring-slate-200", en_curso: "bg-sky-50 text-sky-800 ring-sky-200",
  solicitado: "bg-sky-50 text-sky-800 ring-sky-200", confirmado: "bg-emerald-50 text-emerald-800 ring-emerald-200", rechazado: "bg-rose-50 text-rose-800 ring-rose-200",
  completado: "bg-green-50 text-green-800 ring-green-200", cancelado: "bg-slate-200 text-slate-600 ring-slate-300",
  emitido: "bg-emerald-50 text-emerald-800 ring-emerald-200", utilizado: "bg-green-50 text-green-800 ring-green-200", anulado: "bg-rose-50 text-rose-800 ring-rose-200",
  abierta: "bg-rose-50 text-rose-800 ring-rose-200", en_proceso: "bg-amber-50 text-amber-900 ring-amber-200", resuelta: "bg-emerald-50 text-emerald-800 ring-emerald-200",
  cerrada: "bg-slate-100 text-slate-700 ring-slate-200",
  borrador: "bg-slate-100 text-slate-700 ring-slate-200", modificada: "bg-amber-50 text-amber-900 ring-amber-200", enviada: "bg-sky-50 text-sky-800 ring-sky-200",
  disponible: "bg-emerald-50 text-emerald-800 ring-emerald-200", asignado: "bg-sky-50 text-sky-800 ring-sky-200", descanso: "bg-amber-50 text-amber-900 ring-amber-200",
  vacaciones: "bg-slate-200 text-slate-600 ring-slate-300", mantenimiento: "bg-rose-50 text-rose-800 ring-rose-200",
  critica: "bg-rose-600 text-white ring-rose-700", alta: "bg-rose-50 text-rose-800 ring-rose-200", media: "bg-amber-50 text-amber-900 ring-amber-200", baja: "bg-slate-100 text-slate-700 ring-slate-200",
};

/** Lee un archivo del navegador como base64 (sin el prefijo data:) y su SHA-256 en hex. */
export async function fileToUpload(file: File): Promise<{ file_name: string; mime_type: string; content_base64: string; sha256: string }> {
  const buffer = await file.arrayBuffer();
  const bytes = new Uint8Array(buffer);
  let binary = "";
  for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  const digest = await crypto.subtle.digest("SHA-256", buffer);
  const sha256 = Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, "0")).join("");
  return { file_name: file.name, mime_type: file.type || "application/octet-stream", content_base64: btoa(binary), sha256 };
}

/** Fecha de hoy en la zona de la agencia (America/Lima), como YYYY-MM-DD. */
export function limaToday(): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "America/Lima" }).format(new Date());
}

/** Suma días a una fecha YYYY-MM-DD sin depender de la zona del servidor. */
export function addDays(day: string, n: number): string {
  const d = new Date(`${day}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

export const WEEKDAYS = ["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"];
export const MONTHS = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];

/** Día de la semana con lunes = 0. */
export function weekday(day: string): number {
  return (new Date(`${day}T12:00:00Z`).getUTCDay() + 6) % 7;
}

// ── Apps móviles (spec 05 §2.1 y §2.2) ─────────────────────────────────

export const INCIDENT_TYPES = ["Transporte", "Proveedor", "Salud del pasajero", "Horario", "Clima", "Documentación", "Equipaje", "Seguridad", "Capacidad", "Pasajero", "Otro"];
export const SEVERITIES: Record<string, string> = { baja: "Baja", media: "Media", alta: "Alta", critica: "Crítica" };

export type MobileAssignment = {
  id: string; order_code: string; reservation_code: string; client: string | null; title: string; label: string; date: string; time: string | null;
  status: string; status_label: string; steps: string[]; step: number; meeting_point: string; provider: string | null; vehicle: string | null; role: string;
  partner: { name: string; phone: string | null; role: string } | null; coordinator: { name: string; phone: string | null } | null; notes: string | null;
  alert_tags: string[]; diets: string[];
  passengers: { id: string; name: string; type: string; lead: boolean; diet: string | null; doc: string | null; checked_at: string | null }[];
  incidents: { code: string; title: string; severity: string }[]; evidences: { id: string; file_name: string; captured_at: string }[];
  started_at: string | null; finished_at: string | null; no_show: number; order_status: string;
};

export type OperatorBootstrap = {
  cursor: string; full: boolean; today: string;
  resource: { type: "guide" | "driver"; id: string; code: string; name: string; phone: string | null; license_number: string | null; license_class: string | null; license_expires_on: string | null; languages: string[] | null; status: string };
  assignments: MobileAssignment[]; incidents: IncidentRow[];
  history: { client_action_id: string; type: string; label: string; result: string; message: string | null; synced_at: string; offline: boolean }[];
  last_sync_at: string | null;
};

export type OperatorPreview = { preview: false } | { preview: true; resources: { code: string; name: string; type: string }[] };

export type MobileActionResult = { client_action_id: string; result: "applied" | "duplicate" | "conflict_resolved" | "rejected"; conflict_rule: string | null; message: string | null; assignment?: MobileAssignment };
