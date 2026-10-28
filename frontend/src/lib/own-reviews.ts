// Spec 18: reseñas propias verificadas (R-OWN-REVIEWS). Tipos compartidos del back-office, el portal del pasajero y el sitio.

export type OwnReviewStatus = "pendiente" | "publicada" | "rechazada" | "oculta";

export type OwnReview = {
  id: string; code: string; status: OwnReviewStatus; status_label: string; pax_label: string; stars: number; subratings: Record<string, number>;
  title: string; body: string; lang: string; author_display: string; author_name: string; country: string | null; travel_date: string;
  product: { ref: string; name: string } | null; reservation: string; historic: boolean; client: { code: string; name: string; owner: string | null } | null;
  photos: { name: string }[]; verified: boolean; consent: boolean; flags: string[]; moderation_reason: string | null; reply: string | null; replied_at: string | null;
  source: string; is_demo: boolean; published_at: string | null; created_at: string; lock_version: number;
  log?: { action: string; note: string | null; user: string; at: string }[]; helpful_count?: number; request?: string | null;
};

export type OwnReviewSettings = {
  auto_publish: boolean; banned_words: string[]; personal_data: "retener" | "enmascarar"; active_subratings: string[]; show_on_site: boolean; show_on_widget: boolean;
  invite: { channel: "email" | "whatsapp"; reminder_days: number; expire_days: number; subject: string; body: string };
  last_export: { at: string; by: string; count: number } | null; is_demo: boolean;
};

export type OwnReviewConfig = {
  state: "on" | "off_tenant" | "off_plan" | "off_product"; min_plan: string | null; plan: string | null;
  params: { daysAfter: number; minStarsAutoPublish: number; moderation: boolean }; rule_lock_version: number | null;
  settings: OwnReviewSettings; subratings: Record<string, string>; langs: Record<string, string>; can_moderate: boolean; can_edit_rule: boolean; own_portfolio: boolean;
};

export type OwnReviewStats = {
  received: number; published: number; average: number | null; recommend_pct: number | null; pending: number; pending_flagged: number;
  distribution: Record<string, number>; by_product: { ref: string; name: string; average: number; count: number }[]; subratings: Record<string, number | null>; latest: OwnReview[];
};

export type ReviewRequestRow = {
  id: string; code: string; status: string; status_label: string; channel: string; lang: string; to: string;
  reservation: { code: string; title: string; travel_to: string } | null; client: { code: string; name: string } | null;
  scheduled_at: string; sent_at: string | null; opened_at: string | null; reminded_at: string | null; expires_at: string | null; is_demo: boolean; lock_version: number;
};

/** «Califica tu viaje» (portal del pasajero y formulario por enlace). */
export type ReviewEligibility = {
  state: string; enabled: boolean; reservation: { code: string; status: string; travel_to: string }; passenger: { name: string } | null;
  items: { product: { ref: string; name: string }; date: string; services: string[]; status: "pendiente" | "calificado" | "aun_no" | "no_disponible"; review: string | null }[];
  pending: number; subratings: Record<string, string>; langs: Record<string, string>;
  reviews: { code: string; product: string | null; stars: number; title: string; body: string; status: string; status_label: string; reply: string | null; replied_at: string | null; created_at: string }[];
  request?: string; agency?: string | null;
};

export type PublicOwnReview = {
  code: string; author: string; country: string | null; travel_date: string; stars: number; title: string; body: string; lang: string;
  subratings: Record<string, number>; reply: string | null; published_at: string | null; verified: boolean; is_demo: boolean; product?: string | null;
};

export type ProductReviewBlock = {
  average: number; count: number; distribution: Record<string, number>; subratings: { key: string; label: string; avg: number }[]; is_demo: boolean;
  filter: { stars: number | null; lang: string | null }; total: number; page: number; pages: number; items: PublicOwnReview[];
};

export const STATUS_TONE: Record<string, "slate" | "green" | "amber" | "rose" | "sky" | "violet"> = {
  pendiente: "amber", publicada: "green", rechazada: "rose", oculta: "slate",
  programada: "sky", enviada: "violet", abierta: "amber", respondida: "green", vencida: "slate", cancelada: "slate",
};

export function stars(n: number) {
  return "★".repeat(n) + "☆".repeat(Math.max(0, 5 - n));
}
