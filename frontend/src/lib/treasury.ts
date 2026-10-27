/** Tipos y utilidades de Tesorería (spec 12). Los saldos llegan como número y los montos de movimientos como string con 2 decimales. */

export type TreasuryCan = { register: boolean; approve: boolean; validate: boolean; fx: boolean; accounts: boolean };

export type Account = {
  id: string; code: string; kind: "bank" | "petty_cash" | "gateway"; kind_label: string; bank: string | null; name: string; account_number: string | null;
  cci: string | null; currency: string; sede: string; sede_id: string; balance: number; balance_pen: number; fund_amount: string | null; fund_pct: number | null;
  custodian: string | null; custodian_id: string | null; settle_to: { id: string; code: string; name: string } | null; collect_methods: string[]; status: string; lock_version: number;
  opening_balance: string; opening_date: string; opening_rate: string | null; session: { code: string; date: string; today: boolean; status?: string } | null;
};

export type Movement = {
  id: string; code: string; date: string; direction: "in" | "out"; type: string; type_label: string; amount: string; currency: string; fx_rate: string | null;
  orig_amount: string | null; orig_currency: string | null; orig_fx_rate: string | null; account: { code: string; name: string }; description: string;
  reference: string | null; counterparty: string | null; category: string | null; source: { label: string; kind: string; href: string | null };
  status: "posted" | "voided"; synced: boolean; can_void: boolean; void_reason: string | null;
  receipt: { type: string; type_label: string; series_number: string | null; issuer_ruc: string | null; supplier_name: string; file_name: string | null } | null;
  replenishment: string | null; deposited: string | null; running?: number;
};

export type MovementDetail = Movement & {
  reservation: string | null; created_by: string | null; created_at: string; voided_by: string | null; voided_at: string | null;
  partidas: { code: string; account: string; direction: "in" | "out"; amount: string; currency: string; status: "posted" | "voided" }[];
};

export type AccountOption = { id: string; code: string; name: string; currency: string; kind: string };

export type CashCount = {
  id: string; code: string; account: string; kind: string; kind_label: string; counted_at: string; denominations: Record<string, number>; counted_amount: string;
  expected_amount: string; difference: string; result: string; result_label: string; note: string | null; status: string; status_label: string;
  signed_by: string; signed_by_id: string; approved_by: string | null; approve_note: string | null; validated_by: string | null; lock_version: number;
};

export type Replenishment = {
  id: string; code: string; account: string; account_code: string; amount: string; status: string; status_label: string; requested_by: string;
  requested_by_id: string; requested_at: string; approved_by: string | null; paid_by: string | null; paid_at: string | null; reject_reason: string | null;
  note: string | null; lock_version: number; expenses: number; events: { status: string; message: string; user: string | null; at: string }[];
};

export type PettyCard = {
  id: string; code: string; name: string; sede: string; custodian: string | null; status: string; balance: number; fund_amount: string; fund_pct: number;
  below_threshold: boolean; session: { code: string; date: string; status: string; today: boolean; opening_amount: string } | null; open_today: boolean;
  stale_session: boolean; pending_count: number; pending_amount: number; replenishment: { code: string; status: string; status_label: string; amount: string; lock_version: number } | null;
  expenses: { code: string; date: string; amount: string; description: string; counterparty: string | null }[];
};

export type PettyCash = {
  can: TreasuryCan; user_id: string; settings: { petty_tolerance: string; max_petty_expense: string; sworn_statement_max: string; replenish_threshold_pct: number };
  denominations: string[]; receipt_types: Record<string, string>; banks: { id: string; code: string; name: string }[]; witnesses: { id: string; name: string }[];
  cards: PettyCard[]; counts: CashCount[]; replenishments: Replenishment[];
  sessions: { code: string; account: string; date: string; status: string; opened_by: string | null; opened_at: string; opening_amount: string; closed_by: string | null; closed_at: string | null; count: string | null }[];
};

export const TONE: Record<string, "slate" | "green" | "amber" | "rose" | "sky" | "violet"> = {
  posted: "green", voided: "slate", open: "green", closed: "slate", signed: "sky", pending_approval: "amber", approved: "green", validated: "violet",
  requested: "amber", paid: "green", rejected: "rose", balanced: "green", over: "amber", short: "rose", active: "green", inactive: "slate",
};

export const STATUS_LABEL: Record<string, string> = { posted: "Registrado", voided: "Anulado", open: "Abierta", closed: "Cerrada", active: "Activa", inactive: "Inactiva" };

export const COLLECT_METHODS: Record<string, string> = {
  transferencia_bcp: "Transferencia BCP", transferencia_interbank: "Transferencia Interbank", transferencia_bbva: "Transferencia BBVA", yape: "Yape", plin: "Plin",
  efectivo: "Efectivo", tarjeta: "Tarjeta", tarjeta_ecommerce: "Tarjeta (e-commerce)", tarjeta_portal: "Tarjeta (portal cliente)",
};

export function amount(value: string | number | null | undefined, currency: string | null | undefined = "PEN"): string {
  return new Intl.NumberFormat("es-PE", { style: "currency", currency: currency ?? "PEN", minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value ?? 0));
}

export const TABS = [
  { key: "", label: "Resumen" }, { key: "movimientos", label: "Movimientos" }, { key: "cajas", label: "Cajas chicas" },
  { key: "flujo", label: "Flujo proyectado" }, { key: "cambio", label: "Diferencia de cambio" },
];

export const NEED_REGISTER = "Requiere permiso finance.payments.register";
