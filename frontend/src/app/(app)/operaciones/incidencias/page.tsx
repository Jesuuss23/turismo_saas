import Link from "@/components/Link";
import { api } from "@/lib/api";
import { dateTime, qs } from "@/lib/crm";
import type { IncidentRow } from "@/lib/ops";
import { btnCls, btnGhostCls, Drawer, inputCls, Kpis, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { LockedButton } from "@/components/quotes/ui";
import { OpsNav, OpsStatus, SlaBar } from "@/components/ops/ui";
import { IncidentForm, type IncidentMeta } from "@/components/ops/OrderDialogs";

type Search = { status?: string; severity?: string; type?: string; accion?: string };
export type IncidentListMeta = IncidentMeta & {
  kpis: { open: number; critical: number; sla_breached: number; resolved: number }; statuses: Record<string, string>;
  orders: { id: string; code: string; title: string }[]; can_manage: boolean;
};

/** Incidencias (spec 05 §2): KPIs, alerta de críticas y tabla filtrable con barra de SLA. */
export default async function IncidentsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const { data, meta } = await api<{ data: IncidentRow[]; meta: IncidentListMeta }>(
    `/ops/incidents${qs({ "filter[status]": sp.status, "filter[severity]": sp.severity, "filter[type]": sp.type })}`);
  const k = meta.kpis;
  const close = `/operaciones/incidencias${qs({ status: sp.status, severity: sp.severity, type: sp.type })}`;
  const critical = data.filter((i) => i.severity === "critica" && ["abierta", "en_proceso"].includes(i.status));

  return (
    <>
      <PageHeader title="Incidencias" subtitle="Registro, SLA y resolución (PROC-037)"
        actions={meta.can_manage ? <Link href={`${close}${close.includes("?") ? "&" : "?"}accion=new`} scroll={false} className={btnCls}>Nueva incidencia</Link>
          : <LockedButton label="Nueva incidencia" reason="Requiere permiso operations.incidents" />} />
      <OpsNav active="/operaciones/incidencias" />
      {critical.length > 0 && (
        <Notice tone="danger"><strong>{critical.length} incidencia(s) crítica(s) activa(s):</strong>{" "}
          {critical.map((i, n) => <span key={i.id}>{n > 0 && " · "}<Link href={`/operaciones/incidencias/${i.code}`} className="underline">{i.code} {i.title}</Link></span>)}</Notice>
      )}
      <Kpis items={[
        { label: "Abiertas", value: k.open }, { label: "Críticas activas", value: k.critical },
        { label: "SLA vencido", value: k.sla_breached }, { label: "Resueltas por cerrar", value: k.resolved },
      ]} />
      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Estado: todos</option>{Object.entries(meta.statuses).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select name="severity" defaultValue={sp.severity ?? ""} aria-label="Severidad" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Severidad: todas</option>{Object.entries(meta.severities).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select name="type" defaultValue={sp.type ?? ""} aria-label="Tipo" className={`${inputCls} max-w-[14rem]`}>
          <option value="">Tipo: todos</option>{meta.types.map((t) => <option key={t}>{t}</option>)}
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Incidencia", "Orden", "Tipo", "Severidad", "Estado", "Responsable", "Reportada", "SLA"]} empty="Sin incidencias con estos filtros">
        {data.map((i) => (
          <tr key={i.id}>
            <Td><Link href={`/operaciones/incidencias/${i.code}`} className="font-medium text-sky-800 hover:underline">{i.code}</Link><span className="block text-xs text-slate-500">{i.title}</span></Td>
            <Td>{i.order ? <Link href={`/operaciones/ordenes/${i.order.code}/incidencias`} className="text-sky-800 hover:underline">{i.order.code}</Link> : "—"}</Td>
            <Td>{i.type}</Td>
            <Td><OpsStatus value={i.severity} label={i.severity_label} /></Td>
            <Td><OpsStatus value={i.status} label={i.status_label} /></Td>
            <Td>{i.assignee?.name ?? "—"}</Td>
            <Td>{dateTime(i.created_at)}<span className="block text-xs text-slate-500">{i.reported_by?.name}{i.source === "app" && " · app"}</span></Td>
            <Td><SlaBar pct={i.sla_pct} breached={i.sla_breached} due={i.sla_due_at} /></Td>
          </tr>
        ))}
      </Table>
      {sp.accion === "new" && meta.can_manage && (
        <Drawer title="Nueva incidencia" close={close}>
          <IncidentForm meta={meta} redirectTo="/operaciones/incidencias/{data.code}" orders={meta.orders} />
        </Drawer>
      )}
    </>
  );
}
