import Link from "@/components/Link";
import { STATUS_TONE, dateTime, type HistoryRow } from "@/lib/crm";

const TONES: Record<string, string> = {
  sky: "bg-sky-50 text-sky-800 ring-sky-200",
  indigo: "bg-indigo-50 text-indigo-800 ring-indigo-200",
  emerald: "bg-emerald-50 text-emerald-800 ring-emerald-200",
  green: "bg-green-50 text-green-800 ring-green-200",
  slate: "bg-slate-100 text-slate-700 ring-slate-200",
  rose: "bg-rose-50 text-rose-800 ring-rose-200",
  amber: "bg-amber-50 text-amber-800 ring-amber-200",
};

export function Badge({ status, children }: { status: string; children?: React.ReactNode }) {
  const tone = TONES[STATUS_TONE[status] ?? "slate"];
  return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${tone}`}>{children ?? status}</span>;
}

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: React.ReactNode; actions?: React.ReactNode }) {
  return (
    <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-slate-600">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  );
}

export function Kpis({ items }: { items: { label: string; value: React.ReactNode; hint?: React.ReactNode }[] }) {
  return (
    <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
      {items.map((k) => (
        <div key={k.label} className="rounded-lg border border-slate-200 bg-white p-4">
          <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{k.label}</p>
          <p className="mt-1 text-2xl font-semibold text-slate-900">{k.value}</p>
          {k.hint && <p className="mt-1 text-xs text-slate-500">{k.hint}</p>}
        </div>
      ))}
    </div>
  );
}

export function Card({ title, actions, children, className = "" }: { title?: React.ReactNode; actions?: React.ReactNode; children: React.ReactNode; className?: string }) {
  return (
    <section className={`rounded-lg border border-slate-200 bg-white ${className}`}>
      {(title || actions) && (
        <header className="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
          <h2 className="text-sm font-semibold text-slate-800">{title}</h2>
          {actions}
        </header>
      )}
      <div className="p-4">{children}</div>
    </section>
  );
}

export function Table({ head, children, empty = "Sin registros", foot }: { head: React.ReactNode[]; children: React.ReactNode; empty?: string; foot?: React.ReactNode }) {
  const hasRows = Array.isArray(children) ? children.length > 0 : !!children;
  return (
    <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table className="min-w-full text-sm">
        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>{head.map((h, i) => <th key={i} className="px-3 py-2 font-medium">{h}</th>)}</tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {hasRows ? children : <tr><td colSpan={head.length} className="px-3 py-6 text-center text-slate-500">{empty}</td></tr>}
        </tbody>
        {foot && <tfoot className="bg-slate-50 font-medium">{foot}</tfoot>}
      </table>
    </div>
  );
}

export function Td({ children, className = "" }: { children: React.ReactNode; className?: string }) {
  return <td className={`px-3 py-2 align-top ${className}`}>{children}</td>;
}

export function Notice({ tone = "info", children }: { tone?: "info" | "warning" | "danger"; children: React.ReactNode }) {
  const cls = { info: "bg-sky-50 text-sky-900 ring-sky-200", warning: "bg-amber-50 text-amber-900 ring-amber-200", danger: "bg-rose-50 text-rose-900 ring-rose-200" }[tone];
  return <div className={`mb-4 rounded-md px-3 py-2 text-sm ring-1 ${cls}`}>{children}</div>;
}

/** Stepper horizontal (flujo de 8 pasos del lead, etapas de la oportunidad, estados del caso). */
export function Stepper({ steps, current, failed = false }: { steps: string[]; current: number; failed?: boolean }) {
  return (
    <ol className="flex flex-wrap gap-2 text-xs">
      {steps.map((s, i) => {
        const n = i + 1;
        const state = n < current ? "done" : n === current ? (failed ? "failed" : "current") : "todo";
        const cls = {
          done: "bg-emerald-600 text-white",
          current: "bg-sky-700 text-white",
          failed: "bg-rose-600 text-white",
          todo: "bg-slate-100 text-slate-500",
        }[state];
        return (
          <li key={s} className={`rounded-full px-3 py-1 ${cls}`} aria-current={state === "current" ? "step" : undefined}>
            {n}. {s}
          </li>
        );
      })}
    </ol>
  );
}

export function Tabs({ base, tabs, active }: { base: string; tabs: { key: string; label: string }[]; active: string }) {
  return (
    <nav className="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm">
      {tabs.map((t) => (
        <Link key={t.key} href={`${base}?tab=${t.key}`}
          className={`-mb-px border-b-2 px-3 py-2 ${t.key === active ? "border-sky-700 font-medium text-sky-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>
          {t.label}
        </Link>
      ))}
    </nav>
  );
}

export function Timeline({ rows }: { rows: HistoryRow[] }) {
  if (!rows.length) return <p className="text-sm text-slate-500">Sin movimientos.</p>;
  return (
    <ol className="space-y-3 text-sm">
      {rows.map((r, i) => (
        <li key={i} className="border-l-2 border-slate-200 pl-3">
          <p className="font-medium text-slate-800">{r.entity} {r.entity_id} · {r.action.replaceAll("_", " ")}</p>
          <p className="text-slate-600">{r.before ?? "—"} → {r.after ?? "—"}</p>
          <p className="text-xs text-slate-500">{r.actor?.name ?? "Sistema"} · {dateTime(r.at)}</p>
        </li>
      ))}
    </ol>
  );
}

/** Panel lateral controlado por la URL (`?code=`, `?case=`…); cerrar = volver a la URL sin el parámetro. */
export function Drawer({ title, close, children }: { title: string; close: string; children: React.ReactNode }) {
  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-slate-900/30" role="dialog" aria-modal="true" aria-label={title}>
      <Link href={close} className="flex-1" aria-label="Cerrar" scroll={false} />
      <aside className="h-full w-full max-w-lg overflow-y-auto bg-white p-5 shadow-xl">
        <header className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
          <Link href={close} scroll={false} className="text-sm text-slate-600 hover:underline">Cerrar ✕</Link>
        </header>
        {children}
      </aside>
    </div>
  );
}

export function OutOfScope({ back }: { back: string }) {
  return (
    <section className="mx-auto max-w-lg rounded-lg border border-amber-200 bg-amber-50 p-6 text-center">
      <h1 className="text-lg font-semibold text-amber-900">Registro fuera de su cartera</h1>
      <p className="mt-2 text-sm text-amber-900">Este registro pertenece a otro asesor. Pida a Gerencia que se lo reasigne si debe atenderlo.</p>
      <Link href={back} className="mt-4 inline-block text-sm font-medium text-sky-800 hover:underline">Volver</Link>
    </section>
  );
}

export function ScopeChip({ ownOnly, ownerName }: { ownOnly: boolean; ownerName?: string | null }) {
  if (ownOnly) return <span className="rounded-full bg-sky-50 px-3 py-1 text-xs font-medium text-sky-800 ring-1 ring-sky-200">Mi cartera</span>;
  if (ownerName) return <span className="rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-900 ring-1 ring-amber-200">Mostrando solo la cartera de {ownerName}</span>;
  return null;
}

export const inputCls = "w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-sky-600 focus:ring-2 focus:ring-sky-100";
export const btnCls = "rounded-md bg-sky-700 px-3 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:cursor-not-allowed disabled:opacity-50";
export const btnGhostCls = "rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50";

export function Select({ name, label, options, defaultValue, required, placeholder }: {
  name: string; label: string; options: { value: string; label: string }[]; defaultValue?: string | null; required?: boolean; placeholder?: string;
}) {
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      <select name={name} defaultValue={defaultValue ?? ""} required={required} className={inputCls}>
        {placeholder !== undefined && <option value="">{placeholder}</option>}
        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </label>
  );
}

export function Input(props: React.InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  const { label, ...input } = props;
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      <input {...input} className={inputCls} />
    </label>
  );
}

export function TextArea(props: React.TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string }) {
  const { label, ...input } = props;
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      <textarea rows={3} {...input} className={inputCls} />
    </label>
  );
}
