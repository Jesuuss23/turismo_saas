import Link from "@/components/Link";
import { Tabs } from "@/components/crm/ui";
import { CAMPAIGN_TABS, HEALTH_BAR, pct } from "@/lib/marketing";

/** Encabezado del módulo Marketing con las pestañas de `/marketing/campanas` (spec 14 §2). */
export function MarketingTabs({ active }: { active: string }) {
  return <Tabs base="/marketing/campanas" tabs={CAMPAIGN_TABS} active={active} />;
}

/** Barra de avance (0–100 %, se recorta visualmente) con marca opcional de proyección o del tiempo transcurrido. */
export function Bar({ value, health = "en_camino", marker, label }: { value: number | null; health?: string; marker?: number | null; label?: string }) {
  const width = Math.max(0, Math.min(100, value ?? 0));
  return (
    <div className="relative h-2 w-full min-w-16 rounded-full bg-slate-100" role="progressbar" aria-valuenow={Math.round(value ?? 0)} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
      <div className={`h-2 rounded-full ${HEALTH_BAR[health] ?? "bg-sky-600"}`} style={{ width: `${width}%` }} />
      {marker !== undefined && marker !== null && (
        <span className="absolute -top-1 h-4 w-0.5 bg-slate-700" style={{ left: `${Math.max(0, Math.min(100, marker))}%` }} title={`Proyección ${pct(marker)}`} />
      )}
    </div>
  );
}

/** Embudo de una campaña enviada: cada paso con su conteo y % sobre enviados. */
export function Funnel({ steps }: { steps: { label: string; value: number }[] }) {
  const base = Math.max(1, steps[0]?.value ?? 1);
  return (
    <ol className="space-y-2 text-sm">
      {steps.map((s) => (
        <li key={s.label} className="grid grid-cols-[7rem_1fr_4rem] items-center gap-2">
          <span className="text-slate-600">{s.label}</span>
          <span className="h-3 rounded bg-slate-100"><span className="block h-3 rounded bg-sky-600" style={{ width: `${Math.min(100, (100 * s.value) / base)}%` }} /></span>
          <span className="text-right tabular-nums text-slate-800">{s.value}</span>
        </li>
      ))}
    </ol>
  );
}

export function ConsentChip({ status, label }: { status: string | null | undefined; label?: string }) {
  const s = status ?? "sin_consentimiento";
  const cls = s === "otorgado" ? "bg-emerald-50 text-emerald-800 ring-emerald-200" : s === "revocado" ? "bg-rose-50 text-rose-800 ring-rose-200" : "bg-slate-100 text-slate-600 ring-slate-200";
  return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${cls}`}>{label ?? (s === "otorgado" ? "Otorgado" : s === "revocado" ? "Revocado" : "Sin consentimiento")}</span>;
}

export function Facts({ rows }: { rows: [React.ReactNode, React.ReactNode][] }) {
  return (
    <dl className="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-sm">
      {rows.map(([k, v], i) => (
        <div key={i} className="contents">
          <dt className="text-slate-500">{k}</dt>
          <dd className="break-words text-slate-900">{v ?? "—"}</dd>
        </div>
      ))}
    </dl>
  );
}

export function BackLink({ href, children }: { href: string; children: React.ReactNode }) {
  return <Link href={href} className="mb-3 inline-block text-sm text-sky-800 hover:underline">← {children}</Link>;
}
