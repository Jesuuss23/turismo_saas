import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import { RESOURCE_TYPE, type Assignment, type ResourceCard } from "@/lib/ops";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnGhostCls, Card, Drawer, inputCls, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { LockedButton } from "@/components/quotes/ui";
import { OpsNav, OpsStatus } from "@/components/ops/ui";

type Search = { tipo?: string; agenda?: string };
type DocAlert = { type: string; id: string; code: string; name: string; document: string; expires_on: string; expired: boolean };
type QuickRow = Assignment & { order: { id: string; code: string; status: string }; reservation: { code: string; client: string | null } | null };
type Availability = { type: string; id: string; code: string; name: string; status: string; days: { date: string; state: string; items: { label: string; order_code: string | null; time: string | null }[] }[] };
type Occupation = ResourceCard["occupations"][number];

const CELL: Record<string, string> = {
  libre: "bg-emerald-50 text-emerald-800", ocupado: "bg-sky-100 text-sky-900", descanso: "bg-amber-50 text-amber-900",
  vacaciones: "bg-slate-200 text-slate-600", mantenimiento: "bg-rose-50 text-rose-800", asignado: "bg-sky-100 text-sky-900",
};

/** Asignaciones y recursos (spec 05 §2): alertas documentarias, asignación rápida, disponibilidad 7 días y tarjetas de recursos. */
export default async function AssignmentsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const type = ["guide", "driver", "vehicle"].includes(sp.tipo ?? "") ? sp.tipo : undefined;
  const [resources, { data: alerts }, { data: quick }, availability] = await Promise.all([
    api<{ data: ResourceCard[]; meta: { can_assign: boolean } }>(`/ops/resources${qs({ "filter[type]": type })}`),
    api<{ data: DocAlert[] }>("/ops/resources/document-alerts"),
    api<{ data: QuickRow[] }>("/ops/assignments?filter[incomplete]=1&filter[from]=today"),
    api<{ data: Availability[]; meta: { dates: string[] } }>(`/ops/resources/availability${qs({ days: "7", "filter[type]": type })}`),
  ]);
  const canAssign = resources.meta.can_assign;
  const [agendaType, agendaId] = (sp.agenda ?? "").split(":");
  const agenda = sp.agenda && agendaId
    ? (await api<{ data: { resource: ResourceCard; occupations: Occupation[] } }>(`/ops/resources/${agendaType}/${agendaId}/agenda`)).data
    : null;
  const here = `/operaciones/asignaciones${qs({ tipo: type })}`;

  return (
    <>
      <PageHeader title="Asignaciones y recursos" subtitle="Guías, conductores y vehículos propios" />
      <OpsNav active="/operaciones/asignaciones" />

      {alerts.length > 0 && (
        <Notice tone={alerts.some((a) => a.expired) ? "danger" : "warning"}>
          <strong>Alertas documentarias:</strong>{" "}
          {alerts.map((a, i) => <span key={a.type + a.id}>{i > 0 && " · "}{a.code} {a.name}: {a.document} {a.expired ? "vencido" : "vence"} el {date(a.expires_on)}</span>)}
        </Notice>
      )}

      <Card title={`Asignación rápida (${quick.length})`} className="mb-4">
        <Table head={["Servicio", "Fecha", "Orden / cliente", "Recursos actuales", ""]} empty="Todos los servicios próximos tienen recursos">
          {quick.map((a) => (
            <tr key={a.id}>
              <Td><span className="font-medium">{a.label}</span></Td>
              <Td>{date(a.date)}<span className="block text-xs text-slate-500">{a.time ?? "Sin hora"}</span></Td>
              <Td><Link href={`/operaciones/ordenes/${a.order.code}`} className="text-sky-800 hover:underline">{a.order.code}</Link><span className="block text-xs text-slate-500">{a.reservation?.client}</span></Td>
              <Td>{[a.guide?.name, a.driver?.name, a.vehicle?.name].filter(Boolean).join(" · ") || "Sin recursos"}</Td>
              <Td>{canAssign
                ? <Link href={`/operaciones/ordenes/${a.order.code}?accion=assign&a=${a.id}`} className="text-sm font-medium text-sky-800 hover:underline">Asignar</Link>
                : <LockedButton label="Asignar" reason="Requiere permiso operations.assign" />}</Td>
            </tr>
          ))}
        </Table>
      </Card>

      <nav className="mb-3 flex gap-2 text-sm" aria-label="Tipo de recurso">
        {[["", "Todos"], ["guide", "Guías"], ["driver", "Conductores"], ["vehicle", "Vehículos"]].map(([k, l]) => (
          <Link key={k} href={`/operaciones/asignaciones${qs({ tipo: k || undefined })}`} aria-current={(type ?? "") === k ? "page" : undefined}
            className={`rounded-full px-3 py-1 ring-1 ${(type ?? "") === k ? "bg-sky-700 text-white ring-sky-700" : "bg-white text-slate-700 ring-slate-300 hover:bg-slate-50"}`}>{l}</Link>
        ))}
      </nav>

      <Card title="Disponibilidad 7 días" className="mb-4">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[48rem] text-xs">
            <thead><tr><th className="px-2 py-1 text-left font-medium text-slate-500">Recurso</th>{availability.meta.dates.map((d) => <th key={d} className="px-1 py-1 text-center font-medium text-slate-500">{date(d)}</th>)}</tr></thead>
            <tbody>
              {availability.data.map((r) => (
                <tr key={r.type + r.id} className="border-t border-slate-100">
                  <td className="px-2 py-1"><Link href={`${here}${here.includes("?") ? "&" : "?"}agenda=${r.type}:${r.id}`} scroll={false} className="text-sky-800 hover:underline">{r.code}</Link> <span className="text-slate-600">{r.name}</span></td>
                  {r.days.map((d) => (
                    <td key={d.date} className="p-0.5">
                      <div className={`rounded px-1 py-1 text-center ${CELL[d.state] ?? "bg-slate-50"}`} title={d.items.map((i) => `${i.time ?? ""} ${i.label} ${i.order_code ?? ""}`).join("\n") || d.state}>
                        {d.state === "ocupado" ? d.items.length : d.state === "libre" ? "Libre" : d.state}
                      </div>
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" aria-label="Recursos">
        {resources.data.map((r) => (
          <li key={r.type + r.id} className="rounded-lg border border-slate-200 bg-white p-4 text-sm">
            <div className="flex items-start justify-between gap-2">
              <div>
                <p className="text-xs uppercase tracking-wide text-slate-500">{RESOURCE_TYPE[r.type]} · {r.code}</p>
                <p className="font-semibold text-slate-900">{r.name}</p>
              </div>
              <OpsStatus value={r.status} />
            </div>
            <p className="mt-1 text-slate-600">
              {[r.phone, r.languages?.join(" / "), r.capacity ? `${r.capacity} asientos` : null, r.license_class && `Licencia ${r.license_class}`, r.rating && `★ ${r.rating}`].filter(Boolean).join(" · ")}
            </p>
            {r.document && <p className={`mt-1 text-xs ${r.document.alert === "vencido" ? "font-medium text-rose-700" : r.document.alert ? "text-amber-800" : "text-slate-500"}`}>{r.document.label}: {r.document.alert === "vencido" ? "vencido" : "vence"} el {date(r.document.expires_on)}</p>}
            <p className="mt-2 text-xs text-slate-500">{r.occupations.length ? `${r.occupations.length} servicio(s) en 7 días` : "Sin servicios en 7 días"}</p>
            <div className="mt-3 flex flex-wrap items-end gap-2">
              <Link href={`${here}${here.includes("?") ? "&" : "?"}agenda=${r.type}:${r.id}`} scroll={false} className={btnGhostCls}>Agenda</Link>
              {canAssign ? (
                <ActionForm path={`/ops/resources/${r.type}/${r.id}/status`} method="PATCH" inline submitLabel="Cambiar estado" submitClass={`${btnGhostCls} whitespace-nowrap`}>
                  <select name="status" defaultValue={r.manual_status} aria-label={`Estado de ${r.code}`} className={`${inputCls} py-1.5`}>
                    {r.statuses.map((s) => <option key={s} value={s}>{s}</option>)}
                  </select>
                </ActionForm>
              ) : <LockedButton label="Cambiar estado" reason="Requiere permiso operations.assign" />}
            </div>
          </li>
        ))}
      </ul>

      {agenda && (
        <Drawer title={`Agenda · ${agenda.resource.code} ${agenda.resource.name}`} close={here}>
          <p className="mb-3 text-sm text-slate-600">{RESOURCE_TYPE[agenda.resource.type]} · estado <OpsStatus value={agenda.resource.status} /> · próximos 60 días</p>
          <Table head={["Fecha", "Servicio", "Origen"]} empty="Sin servicios programados">
            {agenda.occupations.map((x) => (
              <tr key={x.source_type + x.source_id}>
                <Td>{date(x.date)}<span className="block text-xs text-slate-500">{x.time ?? ""}</span></Td>
                <Td>{x.label}</Td>
                <Td>{x.order_code ? <Link href={`/operaciones/ordenes/${x.order_code}`} className="text-sky-800 hover:underline">{x.order_code}</Link> : "Salida programada"}</Td>
              </tr>
            ))}
          </Table>
        </Drawer>
      )}
    </>
  );
}
