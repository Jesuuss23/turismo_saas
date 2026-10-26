import { INTERNAL_LABEL, QUOTE_STATUS, QUOTE_TONE, type InternalState } from "@/lib/quotes";

export function QuoteStatus({ value, label }: { value: string; label?: string }) {
  return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${QUOTE_TONE[value] ?? QUOTE_TONE.borrador}`}>{label ?? QUOTE_STATUS[value] ?? value}</span>;
}

export function InternalBadge({ state }: { state: InternalState }) {
  const tone = { no_requerida: "text-slate-500", requerida: "text-amber-800", pendiente: "text-amber-800", aprobada: "text-emerald-700", rechazada: "text-rose-700" }[state];
  return <span className={`text-xs font-medium ${tone}`}>{state === "no_requerida" ? "—" : INTERNAL_LABEL[state]}</span>;
}

/** Botón deshabilitado con candado y tooltip (principio `pbtn`, spec 03 §7). */
export function LockedButton({ label, reason }: { label: string; reason: string | null }) {
  return (
    <button type="button" disabled title={reason ?? undefined} aria-label={`${label} (bloqueado: ${reason ?? ""})`}
      className="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-400">
      <span aria-hidden>🔒</span>{label}
    </button>
  );
}
