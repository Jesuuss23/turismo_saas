import Link from "@/components/Link";
import { Pill } from "@/components/admin/ui";
import { PageHeader } from "@/components/crm/ui";
import { API_TABS, PLATFORM_TABS, SUB_TABS, TONE, num, type UsageRow } from "@/lib/billing";

function SectionTabs({ base, tabs, active, label }: { base: string; tabs: { key: string; label: string }[]; active: string; label: string }) {
  return (
    <nav className="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label={label}>
      {tabs.map((t) => (
        <Link key={t.key} href={`${base}${t.key ? `/${t.key}` : ""}`} aria-current={t.key === active ? "page" : undefined}
          className={`-mb-px border-b-2 px-3 py-2 ${t.key === active ? "border-sky-700 font-medium text-sky-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>
          {t.label}
        </Link>
      ))}
    </nav>
  );
}

/** Suscripción del tenant (§2): resumen, facturas, método de pago y consumo mensual. */
export function SubscriptionHeader({ active, subtitle, actions }: { active: string; subtitle?: React.ReactNode; actions?: React.ReactNode }) {
  return (
    <>
      <PageHeader title="Suscripción" subtitle={subtitle ?? "Plan, consumo, add-ons, facturas de Qhapaq Cloud y método de pago."} actions={actions} />
      <SectionTabs base="/admin/suscripcion" tabs={SUB_TABS} active={active} label="Secciones de la suscripción" />
    </>
  );
}

/** API pública (§2): las pestañas de un grupo que el plan no incluye se ocultan (UX; el backend responde 403 igual). */
export function ApiHeader({ active, features }: { active: string; features: Record<string, boolean> }) {
  const tabs = API_TABS.filter((t) => !t.feature || features[t.feature]);
  return (
    <>
      <PageHeader title="API pública" subtitle="API keys, webhooks firmados, límites de uso y documentación para integrar su ERP o sitio web." />
      <SectionTabs base="/admin/api" tabs={tabs} active={active} label="Secciones de la API" />
    </>
  );
}

/** Consola de facturación de la plataforma (Super Admin): solo contadores y montos de suscripción. */
export function PlatformBillingHeader({ active, subtitle }: { active: string; subtitle?: React.ReactNode }) {
  return (
    <>
      <PageHeader title="Facturación SaaS" subtitle={subtitle ?? "Ingresos recurrentes, cobranza, trials, cupones, planes y feature flags de Qhapaq Cloud."} />
      <nav className="mb-2 flex gap-3 text-sm" aria-label="Consola de plataforma">
        <Link href="/admin/plataforma" className="text-slate-600 hover:underline">Salud de tenants</Link>
        <span className="font-medium text-sky-800">Facturación SaaS</span>
      </nav>
      <SectionTabs base="/admin/plataforma/facturacion" tabs={PLATFORM_TABS} active={active} label="Secciones de facturación" />
    </>
  );
}

export function StatusPill({ status, label }: { status: string; label: string }) {
  return <Pill tone={TONE[status] ?? "slate"}>{label}</Pill>;
}

const BAR: Record<string, string> = { ok: "bg-sky-600", warn: "bg-amber-500", limit: "bg-rose-600" };

/** Medidor de consumo con aviso al 80 % y tope al 100 % (SUB-BR-04). */
export function Meter({ row }: { row: UsageRow }) {
  const pct = row.pct === null ? 0 : Math.min(100, row.pct);
  return (
    <div data-meter={row.metric}>
      <div className="flex flex-wrap justify-between gap-x-2 text-sm">
        <span className="text-slate-700">{row.label}</span>
        <span className="font-medium text-slate-900">
          {num(row.used)} / {row.limit === 0 ? "No incluido" : num(row.limit, row.unit)}{row.pct !== null && row.limit !== 0 ? ` · ${row.pct} %` : ""}
        </span>
      </div>
      <div className="mt-1 h-2 rounded bg-slate-100" role="meter" aria-label={row.label} aria-valuenow={row.pct ?? 0} aria-valuemin={0} aria-valuemax={100}>
        <div className={`h-2 rounded ${BAR[row.level] ?? BAR.ok}`} style={{ width: `${row.limit === null ? 0 : pct}%` }} />
      </div>
      {row.addon > 0 && <p className="mt-0.5 text-xs text-slate-500">Incluye +{num(row.addon)} por add-on</p>}
    </div>
  );
}

const BANNER: Record<string, string> = {
  info: "bg-sky-50 text-sky-900 ring-sky-200", warn: "bg-amber-50 text-amber-900 ring-amber-200", error: "bg-rose-50 text-rose-900 ring-rose-200",
};

export function Banners({ rows }: { rows: { level: string; text: string }[] }) {
  if (!rows.length) return null;
  return (
    <div className="mb-4 space-y-2" data-banners>
      {rows.map((b) => <p key={b.text} className={`rounded-md px-3 py-2 text-sm ring-1 ${BANNER[b.level] ?? BANNER.info}`}>{b.text}</p>)}
    </div>
  );
}

/** Lista definición/valor compacta. */
export function Facts({ rows }: { rows: [React.ReactNode, React.ReactNode][] }) {
  return (
    <dl className="grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-4 gap-y-1 text-sm">
      {rows.map(([k, v], i) => (
        <div key={i} className="contents"><dt className="text-slate-500">{k}</dt><dd className="min-w-0 break-words text-slate-900">{v}</dd></div>
      ))}
    </dl>
  );
}
