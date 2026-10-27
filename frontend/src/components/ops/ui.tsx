import Link from "@/components/Link";
import { dateTime } from "@/lib/crm";
import { OPS_TONE, type OpsAction, type Rule } from "@/lib/ops";
import { btnCls, btnGhostCls } from "@/components/crm/ui";
import { LockedButton } from "@/components/quotes/ui";

export function OpsStatus({ value, label }: { value: string; label?: string }) {
  return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${OPS_TONE[value] ?? OPS_TONE.pendiente}`}>{label ?? value}</span>;
}

/** Barra de SLA de una incidencia (verde → ámbar → rojo; vencida en rojo). */
export function SlaBar({ pct, breached, due }: { pct: number | null; breached: boolean; due: string }) {
  if (pct === null) return <span className="text-xs text-slate-500">—</span>;
  const color = breached || pct >= 100 ? "bg-rose-600" : pct >= 75 ? "bg-amber-500" : "bg-emerald-600";
  return (
    <div className="min-w-[7rem]" title={`Vence ${dateTime(due)}`}>
      <div className="h-1.5 w-full rounded bg-slate-100"><div className={`h-1.5 rounded ${color}`} style={{ width: `${Math.min(100, pct)}%` }} /></div>
      <p className={`mt-0.5 text-xs ${breached ? "font-medium text-rose-700" : "text-slate-500"}`}>{breached ? "SLA vencido" : `${pct}% del SLA`}</p>
    </div>
  );
}

/** Botón de acción con candado y motivo cuando el rol o el estado no lo permiten (OPS-BR-91). */
export function OpsActionLink({ a, href, primary = false }: { a: OpsAction; href: string; primary?: boolean }) {
  if (!a.enabled) return <LockedButton label={a.label} reason={a.reason} />;
  return <Link href={href} scroll={false} className={primary ? btnCls : btnGhostCls}>{a.label}</Link>;
}

export function RuleList({ blocks = [], warnings = [] }: { blocks?: Rule[]; warnings?: Rule[] }) {
  if (!blocks.length && !warnings.length) return null;
  return (
    <ul className="space-y-1 text-sm">
      {blocks.map((b) => <li key={b.code + b.text} className="rounded bg-rose-50 px-2 py-1 text-rose-800 ring-1 ring-rose-200">⛔ {b.text} <span className="text-xs opacity-70">({b.code})</span></li>)}
      {warnings.map((w) => <li key={w.code + w.text} className="rounded bg-amber-50 px-2 py-1 text-amber-900 ring-1 ring-amber-200">⚠️ {w.text} <span className="text-xs opacity-70">({w.code})</span></li>)}
    </ul>
  );
}

export const opsTabs = [
  { href: "/operaciones/ordenes", label: "Órdenes" },
  { href: "/operaciones/calendario", label: "Calendario" },
  { href: "/operaciones/asignaciones", label: "Asignaciones y recursos" },
  { href: "/operaciones/rooming", label: "Rooming" },
  { href: "/operaciones/vouchers", label: "Vouchers" },
  { href: "/operaciones/incidencias", label: "Incidencias" },
];

/** Navegación entre las pantallas del módulo. */
export function OpsNav({ active }: { active: string }) {
  return (
    <nav className="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Operaciones">
      {opsTabs.map((t) => (
        <Link key={t.href} href={t.href} aria-current={t.href === active ? "page" : undefined}
          className={`-mb-px border-b-2 px-3 py-2 ${t.href === active ? "border-sky-700 font-medium text-sky-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>{t.label}</Link>
      ))}
    </nav>
  );
}
