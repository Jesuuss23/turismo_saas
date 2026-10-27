import Link from "@/components/Link";
import { Timeline } from "@/components/crm/ui";
import { auditToHistory, LIGHT_LABELS, money, STATUS_TONES, type AuditRow, type CheckItem } from "@/lib/finance";

const TONES: Record<string, string> = {
  sky: "bg-sky-50 text-sky-800 ring-sky-200", indigo: "bg-indigo-50 text-indigo-800 ring-indigo-200", emerald: "bg-emerald-50 text-emerald-800 ring-emerald-200",
  slate: "bg-slate-100 text-slate-700 ring-slate-200", rose: "bg-rose-50 text-rose-800 ring-rose-200", amber: "bg-amber-50 text-amber-800 ring-amber-200",
};

export function FinStatus({ value, label }: { value: string; label?: string }) {
  return <span className={`inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${TONES[STATUS_TONES[value] ?? "slate"]}`}>{label ?? value}</span>;
}

/** Semáforo de margen (FIN-BR-33): < 15 % rojo, 15–25 % ámbar, ≥ 25 % verde. */
export function Light({ value, pct }: { value: string; pct?: number }) {
  return <FinStatus value={value} label={`${pct !== undefined ? `${pct.toLocaleString("es-PE", { maximumFractionDigits: 1 })} % · ` : ""}${LIGHT_LABELS[value]}`} />;
}

/** Diagrama del proceso oficial (pasos en línea; `current` resalta el paso en curso). */
export function Flow({ title, steps, current }: { title: string; steps: string[]; current?: number }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-3">
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</p>
      <ol className="flex flex-wrap items-center gap-1 text-xs">
        {steps.map((s, i) => (
          <li key={s} className="flex items-center gap-1">
            <span className={`rounded-full px-2 py-0.5 ${current !== undefined && i < current ? "bg-emerald-600 text-white" : current === i ? "bg-sky-700 text-white" : "bg-slate-100 text-slate-600"}`}>{s}</span>
            {i < steps.length - 1 && <span className="text-slate-400" aria-hidden>→</span>}
          </li>
        ))}
      </ol>
    </div>
  );
}

export function Audit({ rows }: { rows: AuditRow[] }) {
  return <Timeline rows={auditToHistory(rows)} />;
}

export function Dl({ items }: { items: [string, React.ReactNode][] }) {
  return (
    <dl className="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
      {items.map(([k, v]) => (
        <div key={k}><dt className="text-xs uppercase tracking-wide text-slate-500">{k}</dt><dd className="text-slate-800">{v ?? "—"}</dd></div>
      ))}
    </dl>
  );
}

/** Lista de verificación (validación fiscal, liquidación, cierre). */
export function Checklist({ items }: { items: CheckItem[] }) {
  return (
    <ul className="space-y-1 text-sm">
      {items.map((c) => (
        <li key={c.key} className="flex gap-2">
          <span aria-hidden className={c.ok ? "text-emerald-700" : "text-rose-700"}>{c.ok ? "✓" : "✗"}</span>
          <span><span className={c.ok ? "text-slate-800" : "font-medium text-rose-800"}>{c.label}</span>
            {(c.detail ?? c.message) && <span className="block text-xs text-slate-500">{c.detail ?? c.message}</span>}</span>
        </li>
      ))}
    </ul>
  );
}

/** Barras horizontales simples (montos PEN). */
export function Bars({ rows, currency = "PEN" }: { rows: { label: string; value: number; tone?: string; hint?: string }[]; currency?: string }) {
  const max = Math.max(1, ...rows.map((r) => Math.abs(r.value)));
  return (
    <ul className="space-y-2 text-sm">
      {rows.map((r) => (
        <li key={r.label}>
          <div className="flex justify-between gap-2"><span className="text-slate-700">{r.label}</span><span className="font-medium text-slate-900">{money(r.value, currency)}{r.hint ? ` · ${r.hint}` : ""}</span></div>
          <div className="mt-1 h-2 rounded bg-slate-100"><div className={`h-2 rounded ${r.tone ?? "bg-sky-600"}`} style={{ width: `${Math.round((Math.abs(r.value) / max) * 100)}%` }} /></div>
        </li>
      ))}
    </ul>
  );
}

/** Enlace solo si el rol puede abrir la ruta destino (§2: si no, texto plano). */
export function MaybeLink({ href, can, children }: { href: string; can: boolean; children: React.ReactNode }) {
  return can ? <Link href={href} className="text-sky-800 hover:underline">{children}</Link> : <>{children}</>;
}

export function NoPermission({ permission, children }: { permission: string; children?: React.ReactNode }) {
  return (
    <div className="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
      {children ?? "Su rol no tiene el permiso para operar esta pantalla."} Permiso requerido: <span className="font-mono">{permission}</span> (Regla transversal 17).
    </div>
  );
}

/** Banner de rol (`roleNote`, §7.2): Gerencia supervisa en solo lectura; Contabilidad consulta para facturar. */
export function RoleNote({ perms, screen }: { perms: Set<string>; screen: "receivables" | "payments" | "payables" | "commissions" | "profitability" }) {
  if (perms.has("finance.payments.register")) return null;
  const manager = perms.has("finance.payables") || perms.has("finance.profitability");
  const text = manager
    ? `Modo supervisión: solo lectura${screen === "payables" ? "; puede aprobar cuentas por pagar y costos" : screen === "commissions" ? "; puede aprobar comisiones" : ""}.`
    : "Consulta de CxC y pagos para facturar: registrar, aplicar o revertir pagos corresponde a Finanzas.";
  return <div className="mb-4 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700 ring-1 ring-slate-200" role="note">{text}</div>;
}
