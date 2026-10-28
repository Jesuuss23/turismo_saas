/** Spec 16: motor de reservas y centro de reglas (tipos compartidos del Product 360 y /admin/reglas). */

export type RuleState = "on" | "off_tenant" | "off_plan" | "off_product";

export type DayStatus = {
  date: string;
  status: "pasado" | "sin_configurar" | "fuera_de_plazo" | "no_opera" | "cerrado" | "agotado" | "sin_cupo" | "disponible";
  label: string;
  note: string | null;
  left: number | null;
  cap?: number | null;
  sold?: number | null;
  held?: number | null;
  range?: string | null;
  departure?: string | null;
};

export type AvailabilityRange = { id: string; code: string; date_from: string; date_to: string; status: "disponible" | "cerrado" | "agotado"; capacity_per_day: number; note: string | null; lock_version: number };

export type PaxRules = {
  adult_min_age: number; child_min_age: number; child_max_age: number; infant_max_age: number; child_pays: boolean; child_price_pct: string;
  infant_allowed: boolean; infant_pays: boolean; max_children_per_adult: number | null; max_infants_per_adult: number | null; min_adults: number; note: string | null; source?: string;
};

export type Addon = { id: string; code: string; name: string; category: string; price: string; currency: string; charge_per: "pax" | "reserva"; applies_to: string[]; stock_note: string | null; provider_id: string | null; provider: string | null; service_id: string | null; is_optional: boolean };

export type RoomType = { room_type: string; capacity: number; price_per_night: string };
export type Hotel = { id: string; code: string; name: string; category: string; provider_id: string | null; room_types: RoomType[] };
export type Leg = { id: string; code: string; sequence: number; city: string; destination_id: string | null; nights: number; hotels: Hotel[] };

export type RuleRow = {
  key: string; group: string; name: string; description: string; product_override: boolean; is_system: boolean; tenant_enabled: boolean;
  tenant_params: Record<string, number>; override: { enabled: boolean | null; params: Record<string, number> } | null; effective: boolean; state: RuleState;
  params: Record<string, number | boolean>; schema: { properties?: Record<string, { type: string; minimum?: number; maximum?: number }> };
};

export type BookingConfig = {
  product: { id: string; ref: string; code: string; name: string; status: string; duration_value: number; duration_unit: string; max_pax: number; min_pax: number };
  today: string;
  sections: Record<string, { state: RuleState; rules: { key: string; name: string; state: RuleState }[] }>;
  legend: string;
  next_available: string | null;
  ranges: AvailabilityRange[];
  operation: { operating_weekdays: number[]; cutoff_hours: number | null; effective_cutoff_hours: number; advance_min_days: number; default_hours: number };
  pax: { effective: PaxRules; stored: PaxRules | null; defaults: PaxRules; warnings: string[] };
  addons: Addon[];
  accommodation: { enabled: boolean; optional: boolean; currency: string | null; note: string | null; summary: string; nights: number; expected_nights: number | null; legs: Leg[]; warnings: string[] };
  payment: {
    modes: string[]; deposit_pct: string | null; balance_due_days: number | null; note: string | null; stored: boolean; pay_later_enabled: boolean; pay_later_state: RuleState;
    deposit_state: RuleState; defaults: { pct: number; balance_due_days: number; hold_hours: number }; effective_modes: string[];
  };
  pickup: { required: boolean; ask_flight: boolean; state: RuleState; points: { id: string; sequence: number; label: string; kind: string; requires_address: boolean }[] };
  rules: RuleRow[];
  can: { availability: boolean; update: boolean; rates: boolean; pickup: boolean; rules_center: boolean };
};

export type QuoteLine = { key: string; kind: "base" | "info" | "addon" | "lodging" | "supplement"; label: string; qty: number; unit: string; amount: string; removable: boolean; removed: boolean; meta: Record<string, unknown> };
export type Quote = {
  lines: QuoteLine[]; adjustments: { key: string; label: string; amount: string }[]; subtotal: string; total: string; currency: string; modes: string[]; payment_mode: string;
  deposit_pct: string; deposit: string; balance: string; balance_due: string | null; hold_hours: number | null; rooms: number; nights: number; lodging_total: string;
  warnings: { code: string; message: string; blocking: boolean }[]; ok: boolean;
};

export type AdminRule = {
  key: string; group: string; name: string; description: string; enabled: boolean; params: Record<string, number>; scope: string[]; product_override: boolean;
  is_system: boolean; schema: RuleRow["schema"]; evaluated: boolean; overrides: number; lock_version: number;
};

export const RULE_GROUPS = ["Disponibilidad", "Pasajeros", "Alojamiento", "Adicionales", "Precios", "Monedas y pagos", "Checkout", "Comunicación"];
export const RULE_SCOPES = ["Sitio web", "Widget", "Cotizaciones", "B2B", "Portal cliente", "Operaciones"];

export const DAY_TONE: Record<DayStatus["status"], string> = {
  disponible: "bg-emerald-50 text-emerald-900 ring-emerald-200",
  sin_cupo: "bg-amber-50 text-amber-900 ring-amber-200",
  agotado: "bg-rose-50 text-rose-900 ring-rose-200",
  cerrado: "bg-slate-200 text-slate-700 ring-slate-300",
  no_opera: "bg-slate-100 text-slate-500 ring-slate-200",
  fuera_de_plazo: "bg-slate-100 text-slate-500 ring-slate-200",
  sin_configurar: "bg-white text-slate-500 ring-slate-200",
  pasado: "bg-white text-slate-300 ring-slate-100",
};

export const WEEKDAYS = ["Dom", "Lun", "Mar", "Mié", "Jue", "Vie", "Sáb"];

export const SECTION_LABELS: Record<string, string> = {
  disponibilidad: "Resumen y disponibilidad", pasajeros: "Pasajeros", adicionales: "Adicionales", alojamiento: "Alojamiento", pago: "Pago", recojo: "Recojo", reglas: "Reglas",
};

export const MODE_LABELS: Record<string, string> = { total: "Pago total", adelanto: "Adelanto", pagar_despues: "Reservar y pagar después" };
