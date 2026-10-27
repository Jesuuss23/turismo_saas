/** Tipos y utilidades del módulo Finanzas (spec 07). Los montos llegan como string con 2 decimales. */
import type { HistoryRow } from "./crm";

export type Receivable = {
  id: string; code: string; concept: string; kind: string; kind_label: string; reservation: string | null; reservation_id: string | null; client: string | null;
  client_code: string | null; client_id: string; currency: string; fx_rate: string | null; amount: string; paid: string; balance: string; balance_pen: string;
  due_date: string; status: string; status_label: string; days_overdue: number; last_reminder_at: string | null; lock_version: number;
};

export type Payment = {
  id: string; code: string; kind: string; source: string; method: string; reference: string | null; receivable: string | null; receivable_id: string | null;
  reservation: string | null; client: string | null; currency: string; fx_rate: string | null; amount: string; amount_pen: string; paid_on: string;
  status: string; status_label: string; note: string | null; reject_reason: string | null; retries: number; revert_reason: string | null;
  registered_by: string | null; lock_version: number;
};

export type Payable = {
  id: string; code: string; kind: string; cost_type: string | null; cost_type_label: string | null; provider: string | null; provider_ref: string | null;
  provider_id: string; provider_status: string | null; reservation: string | null; reservation_id: string | null; service: string | null; concept: string;
  currency: string; fx_rate: string | null; amount: string; paid: string; balance: string; balance_pen: string; due_date: string; overdue: boolean;
  status: string; status_label: string; approved: boolean; approved_at: string | null; scheduled_date: string | null; supplier_doc_number: string | null;
  evidence_note: string | null; supplier_invoice: { number: string; status: string } | null; lock_version: number;
};

export type Commission = {
  id: string; code: string; kind: string; kind_label: string; beneficiary: string; reservation: string | null; sales_target?: string | null; channel: string | null; rule: string;
  base: string; pct: string; amount: string; currency: string; amount_pen: string; status: string; status_label: string; payment_method: string | null;
  payment_ref: string | null; paid_at: string | null; pay_methods: string[]; lock_version: number;
};

export type Invoice = {
  id: string; number: string; doc_type: string; type_label: string; series: string; issue_date: string; period: string; customer_name: string;
  customer_doc_type: string; customer_doc_number: string; customer_address: string | null; domiciled: boolean; reservation: string | null;
  receivable: string | null; receivable_id: string | null; client: string | null; operation_type: string; currency: string; fx_rate: string | null;
  taxable: string; igv: string; total: string; total_pen: string; in_words: string; description: string; reference: string | null;
  credit_reason: string | null; credit_reason_code: string | null; status: string; status_label: string; provider: string | null; ticket: string | null;
  ose_code: string | null; ose_message: string | null; ose_responded_at: string | null; hash: string | null; delivered_at: string | null; lock_version: number;
};

export type BankLine = {
  id: string; code: string; bank: string; value_date: string; description: string; amount: string; currency: string; kind: "abono" | "cargo";
  status: string; status_label: string; resolution: string | null; resolution_note: string | null;
  match: { type: string; code: string; label: string; amount: string } | null; closed: boolean; lock_version: number;
  candidates?: { id: string; code: string; label: string; amount_pen: string }[];
};

export type AuditRow = { entity: string; entity_id: string; action: string; before: string | null; after: string | null; actor: string; at: string };

export type Figures = {
  income: string; service_cost: string; extra_cost: string; cost: string; gross: string; commissions: string; net: string; margin_pct: number; light: "r" | "a" | "g";
};

export type CheckItem = { key: string; label: string; ok: boolean; detail?: string; message?: string | null };

export type PeriodControl = { code: string; label: string; kind: "auto" | "manual"; ok: boolean; detail: string; route?: string; checked_by?: string | null; checked_at?: string | null };
export type PeriodClose = { period: string; status: string; closed_at: string | null; closed_by: string | null; lock_version: number; controls: PeriodControl[]; done: number; total: number; ready: boolean };

/** Permiso operativo de tesorería (OP, FIN-BR-59). */
export const OP = "finance.payments.register";

export const STATUS_TONES: Record<string, string> = {
  abierta: "sky", parcial: "amber", vencida: "rose", pagada: "emerald", cancelada: "slate", aprobada: "indigo",
  pendiente: "amber", confirmado: "sky", aplicado: "emerald", rechazado: "rose", revertido: "slate",
  calculada: "amber",
  pendiente_envio: "amber", emitido: "sky", aceptado: "emerald", observado: "amber", anulado: "slate",
  sugerido: "sky", excepcion: "rose", conciliado: "emerald",
  abierto: "sky", cerrado: "emerald",
  r: "rose", a: "amber", g: "emerald",
};

export const LIGHT_LABELS: Record<string, string> = { g: "En meta", a: "Vigilar", r: "Bajo mínimo" };

export function money(value: string | number | null | undefined, currency: string | null | undefined = "PEN"): string {
  return `${currency ?? ""} ${Number(value ?? 0).toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim();
}

export function auditToHistory(rows: AuditRow[]): HistoryRow[] {
  return rows.map((r) => ({ entity: r.entity, entity_id: r.entity_id, action: r.action, before: r.before, after: r.after, actor: { name: r.actor }, at: r.at }));
}

/** Flujos oficiales que muestran las pantallas (DOC-PROC-001). */
export const FLOWS = {
  receivable: ["Venta", "Obligación", "Vencimiento", "Recordatorio", "Cobro", "Aplicación", "Cierre"],
  payment: ["Recibir", "Validar", "Confirmar", "Aplicar", "Notificar", "Auditar"],
  revert: ["Detectar", "Validar motivo", "Revertir", "Recalcular", "Notificar", "Auditar"],
  payable: ["Costo/obligación", "Validar", "Aprobar", "Pagar", "Aplicar", "Cerrar"],
  cost: ["Detectar", "Registrar", "Asociar", "Validar", "Aprobar", "Impactar rentabilidad"],
  invoice: ["Venta/Cobro", "Validación fiscal", "Preparación", "Emisión", "Respuesta", "Registro", "Entrega", "Auditoría"],
  reconciliation: ["Importar", "Normalizar", "Matching", "Excepción", "Resolver", "Cerrar"],
  profitability: ["Ingresos", "Costos", "Comisiones", "Margen", "Liquidación"],
};
