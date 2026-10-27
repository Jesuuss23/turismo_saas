import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import type { OrderRow } from "@/lib/ops";
import { btnCls, btnGhostCls, inputCls, Kpis, PageHeader, Table, Td } from "@/components/crm/ui";
import { Meter } from "@/components/catalog/ui";
import { LockedButton } from "@/components/quotes/ui";
import { OpsNav, OpsStatus } from "@/components/ops/ui";

type Search = { status?: string; coordinator?: string; q?: string };
type OrderKpis = { in_execution: number; active: number; pending_assignment: number; services_today: number; services_tomorrow: number; incidents_open: number; incidents_critical: number };

/** Órdenes operativas (spec 05 §2): KPIs, filtros y % de asignación por orden. */
export default async function OrdersPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const [list, { data: k }] = await Promise.all([
    api<{ data: OrderRow[]; meta: { statuses: Record<string, string>; can_create: boolean } }>(`/ops/orders${qs({ "filter[status]": sp.status, "filter[coordinator]": sp.coordinator, q: sp.q })}`),
    api<{ data: OrderKpis }>("/ops/orders/kpis"),
  ]);

  return (
    <>
      <PageHeader title="Operaciones" subtitle="Órdenes operativas, recursos, rooming, vouchers e incidencias"
        actions={list.meta.can_create ? <Link href="/operaciones/ordenes/nueva" className={btnCls}>Nueva orden</Link> : <LockedButton label="Nueva orden" reason="Requiere permiso operations.assign" />} />
      <OpsNav active="/operaciones/ordenes" />
      <Kpis items={[
        { label: "En ejecución", value: k.in_execution, hint: `${k.active} órdenes activas` },
        { label: "Servicios sin asignar", value: k.pending_assignment, hint: <Link href="/operaciones/asignaciones" className="text-sky-800 hover:underline">Asignación rápida</Link> },
        { label: "Servicios hoy / mañana", value: `${k.services_today} / ${k.services_tomorrow}` },
        { label: "Incidencias abiertas", value: k.incidents_open, hint: k.incidents_critical ? <span className="font-medium text-rose-700">{k.incidents_critical} crítica(s)</span> : "Sin críticas" },
      ]} />
      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <input name="q" defaultValue={sp.q} placeholder="Buscar por orden, reserva o cliente" aria-label="Buscar" className={`${inputCls} max-w-sm`} />
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[13rem]`}>
          <option value="">Estado: todos</option>
          {Object.entries(list.meta.statuses).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select name="coordinator" defaultValue={sp.coordinator ?? ""} aria-label="Coordinador" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Coordinador: todos</option>
          <option value="me">Mis órdenes</option>
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Orden", "Reserva / cliente", "Estado", "Fechas", "Pax", "Coordinador", "Asignación", "Incidencias"]} empty="No hay órdenes con estos filtros">
        {list.data.map((o) => (
          <tr key={o.id}>
            <Td><Link href={`/operaciones/ordenes/${o.code}`} className="font-medium text-sky-800 hover:underline">{o.code}</Link><span className="block text-xs text-slate-500">{o.title}</span></Td>
            <Td>{o.reservation?.code}<span className="block text-xs text-slate-500">{o.reservation?.client}</span></Td>
            <Td><OpsStatus value={o.status} label={o.status_label} /></Td>
            <Td>{date(o.date_from)}{o.date_to !== o.date_from && <> – {date(o.date_to)}</>}</Td>
            <Td>{o.pax}</Td>
            <Td>{o.coordinator?.name ?? "—"}</Td>
            <Td><div className="w-28"><Meter value={o.assigned_pct} max={100} tone={o.assigned_pct < 100 ? "amber" : "sky"} /><span className="text-xs text-slate-500">{o.assignments_done}/{o.assignments_total} · {o.assigned_pct}%</span></div></Td>
            <Td>{o.incidents_open ? <span className={o.critical_open ? "font-medium text-rose-700" : "text-amber-800"}>{o.incidents_open}{o.critical_open ? ` (${o.critical_open} crítica)` : ""}</span> : "—"}</Td>
          </tr>
        ))}
      </Table>
    </>
  );
}
