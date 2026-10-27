/** Tipos del Libro de Reclamaciones (spec 12): las hojas son casos de Postventa con `is_book`. */

export type Deadline = { state: string; label: string; business_days_left: number | null; tone: string };

export type ClaimRow = {
  id: string; code: string; book_number: string; type: "reclamo" | "queja"; type_label: string; sede: string | null; sede_id: string; channel: string; channel_label: string;
  consumer_name: string | null; consumer_email: string | null; good: string | null; book_status: string; status_label: string; deadline: Deadline;
  received_at: string; due_date: string; owner: string | null; client: { id: string; code: string; name: string } | null; reservation: string | null; priority: string;
};

export type Consumer = {
  name: string; doc_type: string; doc_number: string; address: string; email: string; phone: string | null; is_minor: boolean;
  guardian: { name: string; doc_type: string; doc_number: string; relation: string } | null;
};

export type ClaimResponse = { decision: string; text: string; actions: string; sent_at: string; answered_at: string; answered_by: string; sent_to: string; late?: boolean; resends?: { at: string; by: string; to: string }[] };

export type ClaimDetail = ClaimRow & {
  consumer: Consumer; good_detail: { kind: string; description: string; amount: number | null; currency: string }; detail: string; request: string;
  response: ClaimResponse | null; close_note: string | null; closed_at: string | null; ack_sent_at: string | null; response_days: number; lock_version: number; step: number;
  subject: string; owner_id: string | null; events: { at: string; event: string; message: string; user: string }[];
  audit: { at: string; action: string; actor: string; before: string | null; after: string | null }[]; decisions: Record<string, string>;
  owners: { id: string; name: string }[]; can: { manage: boolean; review: boolean; respond: boolean; resend: boolean; close: boolean };
};

export type PortalClaim = {
  code: string; book_number: string; type_label: string; sede: string | null; status_label: string; book_status: string; received_at: string; due_date: string;
  good: string | null; detail: string; request: string; response: { decision: string; text: string; actions: string; sent_at: string } | null;
};

export type ClaimFormData = {
  sedes: { id: string; name: string }[]; doc_types: string[]; response_days: number;
  provider: { name: string | null; trade_name: string | null; ruc: string | null; address: string | null };
};

export const DEADLINE_TONE: Record<string, "slate" | "green" | "amber" | "rose" | "sky" | "violet"> = {
  en_plazo: "sky", por_vencer: "amber", vencido: "rose", respondido_en_plazo: "green", respondido_fuera_de_plazo: "rose",
};

export const STATUS_TONE: Record<string, "slate" | "green" | "amber" | "rose" | "sky" | "violet"> = { received: "amber", in_review: "sky", answered: "green", closed: "slate" };

export const STEPS = ["Recibida", "En evaluación", "Respondida", "Cerrada"];
