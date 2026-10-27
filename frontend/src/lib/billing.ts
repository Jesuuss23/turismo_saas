/** Tipos y utilidades de la plataforma SaaS (spec 13): suscripción, facturas de la plataforma, API pública y consola de facturación. */

export type Tone = "slate" | "green" | "amber" | "rose" | "sky" | "violet";

export type UsageRow = { metric: string; label: string; unit: string; used: number; limit: number | null; pct: number | null; level: "ok" | "warn" | "limit"; hard: boolean; addon: number };

export type PlanCard = {
  id: string; name: string; rank: number; price_month: number; price_year: number; trial_days: number; current: boolean; limits: Record<string, number | null>;
  rules_count: number; rules_added: string[]; direction: "upgrade" | "downgrade" | "current";
};

export type Addon = {
  code: string; label: string; unit: string; quantity_allowed: boolean; price: number; grants: Record<string, string | number>;
  state: "available" | "active" | "ending" | "included" | "unavailable"; quantity: number; ends_at: string | null;
};

export type Module = { key: string; label: string; module: string; kind: string; enabled: boolean; value: string; origin: string; valid_until: string | null };

export type Subscription = {
  subscription: {
    id: string; code: string; plan_id: string; plan_name: string; cycle: "monthly" | "annual"; cycle_label: string; status: string; status_label: string;
    period_start: string; period_end: string; renews_on: string | null; auto_renew: boolean; cancel_at: string | null; trial_ends_at: string | null; trial_days_left: number | null;
    trial_extended_days: number; credit_balance: number; price: number; negotiated: boolean; lock_version: number;
    billing: { ruc: string; name: string; address: string; ubigeo: string | null; email: string };
  };
  next_charge: { date: string | null; subtotal: number; igv: number; total: number; lines: { description: string; quantity: number; unit_price: number; type: string }[]; skipped: boolean } | null;
  payment_method: { brand: string; last4: string; exp: string; holder: string; status: string } | null;
  scheduled_change: { id: string; kind: string; to_plan_id: string; to_plan_name: string; to_cycle: string; effective_at: string } | null;
  coupon: { code: string; label: string; remaining_cycles: number } | null;
  addons: Addon[]; modules: Module[]; plans: PlanCard[]; banners: { level: string; text: string }[]; debt: number; cancel_reasons: Record<string, string>;
};

export type Preview = {
  kind: string; immediate: boolean; effective_at: string; plan: { id: string; name: string }; cycle: string; price: number;
  proration: { days_left: number; period_days: number; charge: string; credit: string; net: string; igv: string; total: string; period_start: string; period_end: string; new_period: boolean } | null;
  blockers: { type: string; key: string; message: string }[]; rules_gained: string[]; rules_lost: string[];
};

export type PlatformInvoice = {
  id: string; number: string; kind: string; period_from: string; period_to: string; issue_date: string; due_date: string; subtotal: number; igv: number; total: number;
  status: string; status_label: string; sunat_status: string; sunat_label: string; paid_at: string | null; payment_ref: string | null; payable?: boolean; next_retry_at?: string | null;
  tenant_id?: string; tenant_code?: string; tenant_name?: string; dunning_failed_at?: string | null; dunning_retries_done?: number; dunning_next_retry_at?: string | null;
  dunning_extended_days?: number; void_reason?: string | null;
};

export type InvoiceDetail = PlatformInvoice & {
  bill_to: { ruc: string; name: string; email: string; ubigeo: string | null; address: string }; issuer: { ruc: string; name: string; address: string; email: string };
  igv_pct: number; sunat_hash: string | null; lines: { description: string; quantity: number; unit_price: number; amount: number; type: string }[];
  attempts: { attempt_no: number; source: string; result: string; gateway_code: string | null; message: string | null; at: string }[];
  credit_note: { number: string; total: number; reason: string } | null;
};

export type ApiKey = {
  id: string; code: string; name: string; environment: string; prefix: string; last4: string; masked: string; scopes: string[]; ip_allowlist: string[]; expires_at: string | null;
  last_used_at: string | null; last_used_ip: string | null; status: string; status_label: string; revoked_at: string | null; revoke_reason: string | null; created_by: string | null;
  created_at: string; calls_today: number; throttled_today: number;
};

export type Webhook = {
  id: string; code: string; url: string; description: string | null; events: string[]; event_labels: string[]; secret_prefix: string; api_version: string; status: string;
  status_label: string; consecutive_failures: number; disabled_reason: string | null; rotation_grace_until: string | null; created_at: string; delivered_24h: number; failed_24h: number;
};

export type Delivery = {
  id: string; code: string; webhook_id: string; webhook_code: string; event_type: string; event_id: string; entity_code: string | null; attempt: number; status: string;
  status_label: string; http_status: number | null; duration_ms: number | null; error: string | null; next_retry_at: string | null; manual: boolean; created_at: string; updated_at: string;
};

export type PublicApi = { scopes: { key: string; label: string }[]; events: { key: string; label: string }[]; features: { api_access: boolean; webhooks: boolean; oauth_apps: boolean }; api_version: string };

export const TONE: Record<string, Tone> = {
  active: "green", trialing: "sky", past_due: "amber", suspended: "rose", canceled: "slate", cancel_scheduled: "amber",
  open: "sky", paid: "green", failed: "rose", uncollectible: "rose", void: "slate", draft: "slate",
  accepted: "green", pending: "amber", rejected: "rose", revoked: "slate", expired: "slate", paused: "amber", disabled: "rose", deleted: "slate",
  delivered: "green", retrying: "amber", inactive: "slate", ok: "green", warn: "amber", limit: "rose",
};

export const ORIGIN: Record<string, string> = { plan: "Plan", addon: "Add-on", override: "Excepción", "—": "—" };

export function pen(value: number | string | null | undefined): string {
  return new Intl.NumberFormat("es-PE", { style: "currency", currency: "PEN", minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value ?? 0));
}

export function num(value: number | null | undefined, unit?: string): string {
  if (value === null || value === undefined) return "Ilimitado";
  return `${new Intl.NumberFormat("es-PE", { maximumFractionDigits: 1 }).format(value)}${unit ? ` ${unit}` : ""}`;
}

/** Valores de la matriz de flags (§3.4). */
export const FLAG_VALUES: Record<string, string> = { included: "Incluido", not_included: "No incluido", basico: "Básico", completo: "Completo" };

export const SUB_TABS = [
  { key: "", label: "Resumen" }, { key: "facturas", label: "Facturas" }, { key: "pago", label: "Método de pago" }, { key: "historial", label: "Consumo mensual" },
];

export const API_TABS = [
  { key: "", label: "API keys", feature: "api_access" }, { key: "webhooks", label: "Webhooks", feature: "webhooks" }, { key: "entregas", label: "Entregas", feature: "webhooks" },
  { key: "limites", label: "Límites" }, { key: "docs", label: "Documentación" }, { key: "oauth", label: "OAuth", feature: "oauth_apps" },
];

export const PLATFORM_TABS = [
  { key: "", label: "Métricas" }, { key: "facturas", label: "Facturas" }, { key: "cobranza", label: "Cobranza" }, { key: "trials", label: "Trials" },
  { key: "cupones", label: "Cupones" }, { key: "planes", label: "Planes" }, { key: "flags", label: "Feature flags" }, { key: "uso", label: "Uso" },
];

/** Exige el grupo de la API pública en el plan (UX; el backend responde 403 FEATURE_NOT_IN_PLAN igual). */
export const API_FEATURE_LABEL: Record<string, string> = { api_access: "API keys", webhooks: "Webhooks", oauth_apps: "Aplicaciones OAuth" };
