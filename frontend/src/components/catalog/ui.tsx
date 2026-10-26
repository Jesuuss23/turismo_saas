import Link from "@/components/Link";
import { PRODUCT_STATUS_LABEL, toneOf } from "@/lib/catalog";

export function Status({ value, label }: { value: string; label?: string }) {
  return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${toneOf(value)}`}>{label ?? PRODUCT_STATUS_LABEL[value] ?? value}</span>;
}

/** Pestañas con la clave en la ruta (`/catalogo/salidas/holds`), no en el query string. */
export function PathTabs({ base, tabs, active }: { base: string; tabs: { key: string; label: string }[]; active: string }) {
  return (
    <nav className="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Pestañas">
      {tabs.map((t, i) => (
        <Link key={t.key} href={i === 0 ? base : `${base}/${t.key}`} aria-current={t.key === active ? "page" : undefined}
          className={`-mb-px border-b-2 px-3 py-2 ${t.key === active ? "border-sky-700 font-medium text-sky-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>
          {t.label}
        </Link>
      ))}
    </nav>
  );
}

/** Barra de un indicador de ocupación. */
export function Meter({ value, max, tone = "sky" }: { value: number; max: number; tone?: "sky" | "amber" | "rose" }) {
  const width = max > 0 ? Math.min(100, (value / max) * 100) : 0;
  const color = { sky: "bg-sky-600", amber: "bg-amber-500", rose: "bg-rose-600" }[tone];
  return <div className="h-1.5 w-full rounded bg-slate-100"><div className={`h-1.5 rounded ${color}`} style={{ width: `${width}%` }} /></div>;
}

export function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-0.5 text-sm text-slate-800">{children ?? "—"}</dd>
    </div>
  );
}

export function Hidden({ name, value }: { name: string; value: string | number | boolean | null | undefined }) {
  return <input type="hidden" name={name} value={value === null || value === undefined ? "" : String(value)} />;
}

export function Check({ name, label, defaultChecked }: { name: string; label: string; defaultChecked?: boolean }) {
  return (
    <label className="flex items-center gap-2 text-sm text-slate-700">
      <input type="hidden" name={name} value="false" />
      <input type="checkbox" name={name} value="true" defaultChecked={defaultChecked} className="h-4 w-4 rounded border-slate-300" />
      {label}
    </label>
  );
}
