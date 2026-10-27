import Link from "@/components/Link";
import { date, dateTime } from "@/lib/crm";

/** KPI tal como lo devuelve el backend (reportes, panel ejecutivo, consola). */
export type Kpi = { key: string; label: string; value: number | string | null; unit?: string; hint?: string; tone?: "ok" | "warn" | null };

export function fmt(value: unknown, unit?: string | null): string {
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value !== "number") return String(value);
  if (unit === "PEN" || unit === "USD") return new Intl.NumberFormat("es-PE", { style: "currency", currency: unit, maximumFractionDigits: 0 }).format(value);
  if (unit === "%") return `${value.toLocaleString("es-PE", { maximumFractionDigits: 1 })} %`;
  if (unit === "date") return date(String(value));
  return value.toLocaleString("es-PE", { maximumFractionDigits: 2 }) + (unit === "GB" ? " GB" : "");
}

/** Celda de reporte según el formato de la columna (`PEN`, `%`, `date`, `datetime`, `link:quote`…). */
export function cell(value: unknown, format?: string) {
  if (format === "date") return date(value as string | null);
  if (format === "datetime") return dateTime(value as string | null);
  return fmt(value, format);
}

export function KpiGrid({ items }: { items: Kpi[] }) {
  return (
    <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
      {items.map((k) => (
        <div key={k.key} className={`rounded-lg border bg-white p-4 ${k.tone === "warn" ? "border-amber-300" : "border-slate-200"}`} data-kpi={k.key}>
          <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{k.label}</p>
          <p className="mt-1 text-xl font-semibold text-slate-900">{fmt(k.value, k.unit)}</p>
          {k.hint && <p className={`mt-1 text-xs ${k.tone === "warn" ? "text-amber-800" : "text-slate-500"}`}>{k.hint}</p>}
        </div>
      ))}
    </div>
  );
}

/** Pestañas por ruta (`/admin/configuracion/general`…). */
export function RouteTabs({ base, tabs, active }: { base: string; tabs: { key: string; label: string }[]; active: string }) {
  return (
    <nav className="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Secciones">
      {tabs.map((t) => (
        <Link key={t.key} href={`${base}/${t.key}`} aria-current={t.key === active ? "page" : undefined}
          className={`-mb-px border-b-2 px-3 py-2 ${t.key === active ? "border-sky-700 font-medium text-sky-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>
          {t.label}
        </Link>
      ))}
    </nav>
  );
}

/** Barras horizontales genéricas. */
export function Bars({ rows, unit }: { rows: { label: string; value: number; hint?: string }[]; unit?: string }) {
  const max = Math.max(1, ...rows.map((r) => Math.abs(r.value)));
  if (!rows.length) return <p className="text-sm text-slate-500">Sin datos en el periodo.</p>;
  return (
    <ul className="space-y-2 text-sm">
      {rows.map((r) => (
        <li key={r.label}>
          <div className="flex justify-between gap-2"><span className="truncate text-slate-700">{r.label}</span><span className="shrink-0 font-medium text-slate-900">{fmt(r.value, unit)}{r.hint ? ` · ${r.hint}` : ""}</span></div>
          <div className="mt-1 h-2 rounded bg-slate-100"><div className="h-2 rounded bg-sky-600" style={{ width: `${Math.round((Math.abs(r.value) / max) * 100)}%` }} /></div>
        </li>
      ))}
    </ul>
  );
}

const SERIES = ["bg-sky-600", "bg-amber-500", "bg-emerald-600"];

/** Series por mes (columnas agrupadas). */
export function SeriesChart({ series, rows, unit }: { series: string[]; rows: { label: string; values: number[] }[]; unit?: string }) {
  const max = Math.max(1, ...rows.flatMap((r) => r.values.map(Math.abs)));
  return (
    <div>
      <div className="flex h-40 items-end gap-2 overflow-x-auto border-b border-slate-200 pb-1">
        {rows.map((r) => (
          <div key={r.label} className="flex min-w-10 flex-1 flex-col items-center gap-1">
            <div className="flex h-36 w-full items-end justify-center gap-0.5">
              {r.values.map((v, i) => (
                <div key={i} title={`${series[i]}: ${fmt(v, unit)}`} className={`w-3 rounded-t ${SERIES[i % SERIES.length]}`} style={{ height: `${Math.max(2, Math.round((Math.abs(v) / max) * 100))}%` }} />
              ))}
            </div>
          </div>
        ))}
      </div>
      <div className="mt-1 flex gap-2 overflow-x-auto text-[11px] text-slate-500">
        {rows.map((r) => <span key={r.label} className="min-w-10 flex-1 text-center">{r.label}</span>)}
      </div>
      <p className="mt-2 flex flex-wrap gap-3 text-xs text-slate-600">
        {series.map((s, i) => <span key={s} className="inline-flex items-center gap-1"><span className={`inline-block h-2 w-2 rounded ${SERIES[i % SERIES.length]}`} />{s}</span>)}
      </p>
    </div>
  );
}

/** Embudo: barras decrecientes con % respecto del primer paso. */
export function Funnel({ rows }: { rows: { label: string; value: number }[] }) {
  const first = Math.max(1, rows[0]?.value ?? 1);
  return (
    <ol className="space-y-1.5 text-sm">
      {rows.map((r) => (
        <li key={r.label} className="flex items-center gap-2">
          <span className="w-28 shrink-0 text-slate-700">{r.label}</span>
          <span className="h-5 rounded bg-indigo-500/80" style={{ width: `${Math.max(4, Math.round((r.value / first) * 100))}%` }} />
          <span className="shrink-0 text-xs text-slate-600">{r.value} · {Math.round((r.value / first) * 100)} %</span>
        </li>
      ))}
    </ol>
  );
}

/** Distribución (donut del prototipo) como lista de proporciones. */
export function Share({ rows, unit }: { rows: { label: string; value: number }[]; unit?: string }) {
  const total = rows.reduce((a, r) => a + r.value, 0) || 1;
  return (
    <ul className="space-y-1.5 text-sm">
      {rows.map((r, i) => (
        <li key={r.label} className="flex items-center gap-2">
          <span className={`inline-block h-2.5 w-2.5 shrink-0 rounded-full ${["bg-sky-600", "bg-emerald-600", "bg-amber-500", "bg-rose-500", "bg-indigo-500", "bg-slate-400"][i % 6]}`} />
          <span className="flex-1 truncate text-slate-700">{r.label}</span>
          <span className="shrink-0 text-slate-900">{fmt(r.value, unit)} · {Math.round((r.value / total) * 100)} %</span>
        </li>
      ))}
    </ul>
  );
}

export type Chart = { title: string; type: "series" | "funnel" | "bars" | "donut"; unit?: string; series?: string[]; rows: { label: string; value?: number; values?: number[] }[] };

export function ChartView({ chart }: { chart: Chart }) {
  if (chart.type === "series") return <SeriesChart series={chart.series ?? []} rows={chart.rows as { label: string; values: number[] }[]} unit={chart.unit} />;
  if (chart.type === "funnel") return <Funnel rows={chart.rows as { label: string; value: number }[]} />;
  if (chart.type === "donut") return <Share rows={chart.rows as { label: string; value: number }[]} unit={chart.unit} />;
  return <Bars rows={chart.rows as { label: string; value: number }[]} unit={chart.unit} />;
}

export function Pill({ tone = "slate", children }: { tone?: "slate" | "green" | "amber" | "rose" | "sky" | "violet"; children: React.ReactNode }) {
  const cls = {
    slate: "bg-slate-100 text-slate-700 ring-slate-200", green: "bg-emerald-50 text-emerald-800 ring-emerald-200", amber: "bg-amber-50 text-amber-900 ring-amber-200",
    rose: "bg-rose-50 text-rose-800 ring-rose-200", sky: "bg-sky-50 text-sky-800 ring-sky-200", violet: "bg-violet-50 text-violet-800 ring-violet-200",
  }[tone];
  return <span className={`inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${cls}`}>{children}</span>;
}

export function Check({ ok }: { ok: boolean }) {
  return <span aria-label={ok ? "sí" : "no"} className={ok ? "text-emerald-700" : "text-slate-300"}>{ok ? "✓" : "–"}</span>;
}
