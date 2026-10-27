/** Tipos y utilidades de Marketing y Metas comerciales (spec 14). */

import type { HistoryRow } from "@/lib/crm";

export type Rule = { f: string; op: string; v?: string | number | null };

export type FieldDef = { key: string; label: string; ops: Record<string, string>; type: "select" | "text" | "days" | "number"; options: Record<string, string> | null };

export type Template = {
  id: string; code: string; name: string; channel: "email" | "whatsapp"; channel_label: string; category: string; status: string; status_label: string; usable: boolean;
  provider_template_id: string | null; subject: string | null; body: string; language: string; reviewed_at: string | null; lock_version: number;
};

export type Options = {
  fields: FieldDef[]; segments: { id: string; code: string; name: string }[]; templates: Template[];
  promotions: { id: string; code: string; value: string; status: string; date_to: string | null; product: string }[]; products: { id: string; ref: string; name: string }[];
  test_users: { id: string; code: string; name: string; email: string; mobile: string | null }[]; variables: string[]; consent_sources: string[]; revoke_sources: string[];
  policy_versions: string[];
};

export type Metrics = {
  sent: number; delivered: number; opened: number; clicked: number; replied: number; bounced: number; failed: number; pending: number; unsubscribed: number;
  open_rate: number | null; ctr: number | null; reservations: number; leads: number; promo_uses: number; revenue_pen: number;
};

export type CampaignRow = {
  id: string; code: string; name: string; channel: "email" | "whatsapp"; channel_label: string; segment: { code: string; name: string }; template: { code: string; name: string };
  status: string; status_label: string; scheduled_at: string | null; sent_at: string | null; promo_code: string | null; owner: string | null; metrics: Metrics; lock_version: number;
};

export type AudienceCounts = { matched: number; eligible: number; optout: number; nodata: number; consent: number; fatigue: number };

export type Campaign = CampaignRow & {
  subject: string | null; body: string; utm: string; completed_at: string | null; cancel_reason: string | null;
  promotion: { code: string; value: string; date_from: string | null; date_to: string | null; status: string } | null;
  tests: { to: string; address: string; sent_at: string; valid: boolean }[]; has_valid_test: boolean;
  snapshot: (AudienceCounts & { at: string }) | null; audience: AudienceCounts | null;
  suppressed: { type: string; code?: string; name: string; reason: string; reason_label: string }[];
  recipients: { id: string; type: string; name: string; address: string; status: string; status_label: string; sent_at: string | null; opened_at: string | null; clicked_at: string | null; unsubscribed_at: string | null; error: string | null }[];
  attribution: { kind: string; code: string; label: string; revenue_pen: string | null; at: string }[];
  preview: { contact: string; subject: string | null; body: string } | null; history: HistoryRow[];
  can: { edit: boolean; test: boolean; schedule: boolean; unschedule: boolean; send: boolean; process: boolean; cancel: boolean };
};

export type Segment = {
  id: string; code: string; name: string; description: string | null; match: "all" | "any"; rules: Rule[]; rules_text: string; owner: string | null; lock_version: number;
  updated_at: string; matched: number; contactable_email: number; contactable_whatsapp: number; campaigns: string[]; history?: HistoryRow[];
};

export type ChannelCounts = { eligible: number; optout: number; nodata: number; consent: number; fatigue: number };

export type Preview = {
  matched: number; by_type: Record<string, number>; email: ChannelCounts; whatsapp: ChannelCounts; rules_text: string;
  sample: { type: string; type_label: string; id: string; code: string; name: string; email: string | null; phone: string | null; country: string | null; destination: string | null; consent: { email: string | null; whatsapp: string | null } }[];
};

export type Automation = {
  id: string; code: string; key: string; name: string; trigger: string; delay: string; channel: string; channel_label: string; purpose: string; status: string; status_label: string;
  template: Template; sent_total: number; suppressed_total: number; conversions_total: number; last_run_at: string | null; lock_version: number;
  queue: { name: string; subject_type: string; ref_code: string; reason: string | null; reason_label: string | null }[];
  runs: { started_at: string; queued: number; sent: number; suppressed: number; by: string | null }[];
};

export type ConsentCell = { code: string; status: string; granted_at: string | null; source: string | null; revoked_at: string | null; revoke_source: string | null } | null;

export type ContactRow = {
  type: string; type_label: string; id: string; code: string; name: string; email: string | null; phone: string | null; mobile: string | null;
  email_consent: ConsentCell; whatsapp_consent: ConsentCell;
};

export type ContactConsents = {
  contact: { type: string; type_label: string; id: string; code: string; name: string; email: string | null; phone: string | null; mobile: string | null };
  channels: Record<"email" | "whatsapp", {
    code: string | null; label: string; status: string; status_label: string; granted_at: string | null; source: string | null; evidence: string | null; policy_version: string | null;
    revoked_at: string | null; revoke_source: string | null; revoke_campaign: string | null; lock_version: number | null;
  }>;
  events: { channel: string; event: string; source: string | null; evidence: string | null; policy_version: string | null; campaign: string | null; actor: string | null; at: string }[];
};

// ── Metas comerciales ───────────────────────────────────────────────

export type Goals = { sales: number; bookings: number | null; margin: number | null; conversion: number | null; bonus: number | null };
export type Indicators = { sales: number | null; bookings: number | null; margin: number | null; conversion: number | null };

export type Target = {
  id: string; code: string; scope_type: "asesor" | "sede" | "equipo"; scope_label: string; scope_id: string; scope_name: string; period_type: "mes" | "trimestre"; period_key: string;
  period_from: string; period_to: string; status: string; status_label: string; goals: Goals; own: boolean; lock_version: number;
  actual: { sales: number; bookings: number; margin: number; conversion: number | null }; pct: Indicators;
  projection: { sales: number; pct: number | null; margin: number; pipeline_weighted: number; linear: number };
  days: { total: number; elapsed: number; remaining: number; elapsed_pct: number }; health: string; health_label: string;
  bonus: { base: number | null; tier: { min: number; pay: number } | null; amount: number; tiers: { min: number; pay: number }[] };
};

export type RankRow = { code?: string; user_id: string; name: string; pct: number; sales?: number; goal?: number; projection_pct: number | null; bonus?: number; health: string; health_label: string; position: number; me: boolean };

export type TargetDetail = Target & {
  reservations: { id: string; code: string; title: string; client: string | null; owner: string | null; status: string; status_label: string; currency: string; total: string; sales_pen: number; margin_pen: number; created_at: string }[];
  pipeline: { code: string; title: string; client: string | null; owner: string | null; stage: string; amount_pen: number; probability: number; weighted_pen: number; close_date: string }[];
  adjustments: { action: string; before: Record<string, unknown> | null; after: Record<string, unknown> | null; reason: string | null; actor: string | null; at: string }[];
  commission: { code: string; amount: string; status: string; can_view: boolean } | null; ranking: RankRow[]; result: Record<string, unknown> | null;
};

export type TargetsMeta = {
  period: string; manage: boolean; admin: boolean; kpis: { goal: number; actual: number; projection: number; at_risk: number; bonus: number }; periods: string[];
  history: Target[] | null; own_history: Target[] | null;
  options: { advisors: { id: string; code: string; name: string }[]; sedes: { id: string; code: string; name: string }[]; teams: { id: string; code: string; name: string }[] } | null;
  policy: { tiers: { min: number; pay: number }[]; lock_decrease_after_pct: number; attribution_window_days: number } | null;
};

export const CAMPAIGN_TABS = [
  { key: "campanas", label: "Campañas" }, { key: "segmentos", label: "Segmentos" }, { key: "plantillas", label: "Plantillas" },
  { key: "automatizaciones", label: "Automatizaciones" }, { key: "consentimientos", label: "Consentimientos" },
];

export const CAMPAIGN_FLOW = ["Borrador", "Programada", "Enviando", "Enviada"];

export const CAMPAIGN_STATUS: Record<string, string> = { borrador: "Borrador", programada: "Programada", enviando: "Enviando", enviada: "Enviada", cancelada: "Cancelada" };

export const REASON_LABELS: Record<string, string> = { optout: "Baja", nodata: "Sin dato", consent: "Sin consentimiento", fatigue: "Fatiga 72 h" };

export const CONSENT_LABELS: Record<string, string> = { otorgado: "Otorgado", revocado: "Revocado", sin_consentimiento: "Sin consentimiento" };

export const TARGET_FLOW = ["Borrador", "Vigente", "Cerrada"];

export function pen(value: number | string | null | undefined, digits = 2): string {
  return new Intl.NumberFormat("es-PE", { style: "currency", currency: "PEN", minimumFractionDigits: digits, maximumFractionDigits: digits }).format(Number(value ?? 0));
}

export function pct(value: number | null | undefined): string {
  if (value === null || value === undefined) return "—";
  return `${new Intl.NumberFormat("es-PE", { maximumFractionDigits: 1 }).format(value)} %`;
}

/** «2026-10» → «octubre 2026»; «2026-Q4» → «4.º trimestre 2026». */
export function periodLabel(key: string): string {
  const q = key.match(/^(\d{4})-Q([1-4])$/);
  if (q) return `${q[2]}.º trimestre ${q[1]}`;
  const [y, m] = key.split("-").map(Number);
  if (!y || !m) return key;
  return new Intl.DateTimeFormat("es-PE", { month: "long", year: "numeric", timeZone: "UTC" }).format(new Date(Date.UTC(y, m - 1, 1)));
}

/** Color de la barra de avance según salud (§4.3). */
export const HEALTH_BAR: Record<string, string> = {
  cumplida: "bg-emerald-600", en_camino: "bg-emerald-500", en_riesgo: "bg-amber-500", critico: "bg-rose-500", no_cumplida: "bg-rose-500", por_iniciar: "bg-slate-400",
  borrador: "bg-slate-400", anulada: "bg-slate-300", por_cerrar: "bg-amber-500",
};
