/** Spec 17: centro de reglas v2.2, mensajes al pasajero, monedas, reseñas externas e integración web (tipos compartidos). */
import type { AdminRule, DayStatus, Quote } from "./booking";

export type RuleCenterRow = AdminRule & {
  is_custom: boolean; min_plan: string; plan_allows: boolean; effective: boolean; state: "on" | "off_tenant" | "off_plan" | "off_dependency";
  depends_on: string | null; depends_ok: boolean; reads: string | null;
};
export type RuleCenterMeta = {
  plan: string; plan_name: string; preview_plan: string | null; plans: { id: string; name: string }[]; groups: string[]; scopes: string[]; template_applied: { code: string; name: string; at: string; by: string } | null;
};
export type RuleTemplate = { code: string; name: string; description: string; enabled_codes: string[]; enable: { key: string; name: string; plan_allows: boolean }[]; disable: { key: string; name: string }[] };
export type RuleOverrides = { items: { product_id: string; ref: string; name: string; enabled: boolean | null; params: Record<string, number> }[]; candidates: { product_id: string; ref: string; name: string }[] };
export type AuditEntry = { id: string; at: string; entity: string; entity_id: string; action: string; actor: string | null; before: string | null; after: string | null };
export type Simulation = {
  product: { id: string; ref: string; name: string }; plan: string; date: string; day: DayStatus; month: DayStatus[]; legend: string;
  pax: { ok: boolean; errors: string[] }; quote: Quote & { fx_rate?: string; rules_applied?: string[] };
  rules: { key: string; name: string; group: string; state: string; effective: boolean; plan_allows: boolean; min_plan: string }[];
};

export type PaxTemplate = {
  id: string; code: string; name: string; event: string; event_label: string; offset_hours: number; offset_from_rule: boolean; effective_offset_hours: number; channels: string[];
  subject: string | null; body: string; lang: string; group_code: string | null; conditions: PaxConditions; whatsapp_template_name: string | null; active: boolean; lock_version: number;
  history: number; deletable: boolean; rule_ok: boolean; when: string;
};
export type PaxConditions = { requiresRule?: string; channels?: string[]; products?: string[]; onlyIfBalance?: boolean; onlyIfMissingDocs?: boolean };
export type PaxMeta = {
  events: Record<string, string>; channels: Record<string, string>; variables: Record<string, string>; rules: string[]; sales_channels: string[]; messages_on: boolean;
  review_invite_on: boolean; review_days_after: number; can_edit: boolean;
};
export type PaxMessage = {
  id: string; code: string; channel: string; recipient: string | null; subject: string | null; body: string; status: string; origin: string; lang: string; attempts: number; error: string | null;
  provider_message_id: string | null; scheduled_at: string | null; sent_at: string | null; delivered_at: string | null; read_at: string | null;
  reservation: { id: string; code: string; title: string } | null; template: { id: string; code: string; name: string } | null;
};
export type PaxReservation = { id: string; code: string; title: string; status: string; travel_from: string; client: string; lang: string; has_email: boolean; has_phone: boolean };

export type CurrencyRow = { code: string; name: string; symbol: string; rate_pen: string; client_rate: string; per_100_pen: string; active: boolean; is_base: boolean; is_charge: boolean; lock_version: number };
export type FxProvider = { id: string; code: string; name: string; kind: string; status: string; schedule_cron: string | null; last_run_at: string | null; last_error: string | null; note: string | null; is_current: boolean };
export type FxPoint = { date: string; rate: string; previous: string | null; change_pct: number | null; alert: boolean; source: string | null; is_demo: boolean };
export type CurrencyList = {
  items: CurrencyRow[]; settings: { fx_margin_pct: number; fx_alert_pct: number; rate_provider_id: string | null; rate_source: string | null; rate_updated_at: string | null; is_demo_rates: boolean };
  providers: FxProvider[]; multicurrency_on: boolean; alerts: { currency: string; date: string; rate: string; change_pct: number }[];
};

export type ReviewSource = {
  id: string; provider: string; name: string; phase: number; status: string; account_ref: string | null; secret_hint: string | null; scopes: string[]; can_reply: boolean; supports_invites: boolean;
  rating: number | null; review_count: number; last_sync_at: string | null; last_error: string | null; connected_at: string | null; contract_ack_at: string | null;
};
export type ExternalReview = {
  id: string; provider: string; source_name: string; external_id: string; author: string; date: string; rating: number; title: string | null; body: string; lang: string;
  product: { id: string; ref: string; name: string } | null; url: string | null; response_status: string; reply: string | null; replied_at: string | null; can_reply: boolean;
  conversation_id: string | null; conversation_code: string | null; is_example: boolean; suggested_reply: string;
};
export type ReviewInbox = { items: ExternalReview[]; kpis: { count: number; avg: number; unanswered: number; low: number; is_example: boolean } };
export type ReviewInvitations = {
  rule_on: boolean; days_after: number;
  items: { id: string; code: string; title: string; travel_to: string; client: string; email: boolean; invited: boolean; invite_status: string | null; invited_at: string | null }[];
};
export type ReviewsWidget = { count: number; layout: string; sources: string[]; min_rating: number };

export type WidgetDomain = {
  id: string; domain: string; cms: string | null; status: string; verify_token: string; txt_record: { host: string; value: string }; verified_at: string | null; last_check_at: string | null;
};
export type WidgetSettings = {
  public_key: string; public_key_prev: string | null; prev_expires_at: string | null; tenant_slug: string;
  defaults: { lang: string; color: string; method: string; radius: number; reviews: boolean; currency: string; product?: string | null; category?: string | null; domain?: string | null };
  lock_version: number; methods: Record<string, string>; cms: string[]; domains: WidgetDomain[]; browser_events: Record<string, string>; webhook_events: string[];
  webhooks: { id: string; url: string; status: string; events: string[] }[];
  handoffs: WidgetHandoff[];
  products: { ref: string; name: string; category: string; duration: string }[];
};

export type WidgetHandoff = {
  code: string; status: string; product: { ref: string; name: string } | null; date: string | null; pax: { adt: number; chd: number; inf: number } | null; currency: string | null;
  payment_mode: string | null; lang: string | null; total: string | null; lines: Quote["lines"]; adjustments: Quote["adjustments"]; deposit: string | null; balance: string | null;
  rooms: number; nights: number; origin_domain: string | null; expires_at: string | null; created_at: string | null; reservation_id: string | null; checkout_url: string;
};

export const PAX_STATUS_TONE: Record<string, "slate" | "green" | "amber" | "rose" | "sky" | "violet"> = {
  programado: "sky", enviado: "violet", entregado: "green", leido: "green", fallido: "rose", cancelado: "slate",
};
export const REVIEW_STATUS_LABEL: Record<string, string> = { sin_responder: "Sin responder", pendiente_publicar: "Pendiente de publicar", no_admite: "Copiar y publicar manualmente", publicada: "Publicada" };
export const CHANNEL_LABEL: Record<string, string> = { email: "Email", whatsapp: "WhatsApp", push: "Push", portal: "Portal" };

export function stars(n: number) {
  return "★".repeat(Math.round(n)) + "☆".repeat(5 - Math.round(n));
}

export type WidgetPublicConfig = {
  agency: string; defaults: WidgetSettings["defaults"]; multicurrency: boolean; preview_remove: boolean;
  currencies: Record<string, { symbol: string; name: string; rate_pen: string; client_rate: string; is_charge: boolean }>;
  products: { ref: string; name: string; category: string; duration: string }[];
};
export type WidgetProduct = {
  ref: string; name: string; category: string | null; duration: string; max_pax: number | null; modes: string[]; next_available: string | null;
  pax: { adult_min_age: number; child_min_age: number; child_max_age: number; infant_max_age: number; child_pays: boolean; infant_allowed: boolean; max_children_per_adult: number | null; max_infants_per_adult: number | null; min_adults: number };
  addons: { id: string; code: string; name: string; category: string; price: string; currency: string; charge_per: "pax" | "reserva"; applies_to: string[]; stock_note: string | null }[];
  lodging: { optional: boolean; currency: string | null; note: string | null; nights: number; legs: { id: string; city: string; nights: number; hotels: { id: string; name: string; category: string; room_types: { room_type: string; capacity: number; price_per_night: string }[] }[] }[] } | null;
  rules: { promo: boolean; preview_remove: boolean; multicurrency: boolean; deposit: boolean; pay_later: boolean; lodging: boolean; addons: boolean };
};
export type PublicReviews = {
  items: { author: string; date: string; rating: number; title: string | null; body: string; lang: string; source: string; source_name: string; url: string | null; is_example: boolean }[];
  summary: { source: string; rating: number | null; count: number }[]; layout: string; is_example: boolean; attribution: string;
};
export type HandoffCheckout = WidgetHandoff & {
  quote: Quote; quote_token: string; display: { currency: string | null; total: string | null }; charge_currency: string;
  pickup: { required: boolean; ask_flight: boolean; points: { id: string; label: string; kind: string; requires_address: boolean }[] }; comments: boolean;
};
