import Link from "@/components/Link";
import { Pill } from "@/components/admin/ui";
import { PageHeader } from "@/components/crm/ui";
import { DEADLINE_TONE, STATUS_TONE, type Deadline } from "@/lib/claims";

const TABS = [{ key: "", label: "Hojas" }, { key: "reportes", label: "Reportes" }, { key: "configuracion", label: "Configuración" }];

export function ClaimsHeader({ active, subtitle, actions }: { active: string; subtitle?: React.ReactNode; actions?: React.ReactNode }) {
  return (
    <>
      <PageHeader title="Libro de reclamaciones" subtitle={subtitle ?? "Hojas virtuales y presenciales con plazo en días hábiles, respuesta al consumidor y relación anual."} actions={actions} />
      <nav className="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Secciones del libro">
        {TABS.map((t) => (
          <Link key={t.key} href={`/crm/reclamaciones${t.key ? `/${t.key}` : ""}`} aria-current={t.key === active ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 ${t.key === active ? "border-sky-700 font-medium text-sky-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>{t.label}</Link>
        ))}
      </nav>
    </>
  );
}

export function DeadlinePill({ d }: { d: Deadline }) {
  return <Pill tone={DEADLINE_TONE[d.state] ?? "slate"}>{d.label}</Pill>;
}

export function BookStatus({ status, label }: { status: string; label: string }) {
  return <Pill tone={STATUS_TONE[status] ?? "slate"}>{label}</Pill>;
}
