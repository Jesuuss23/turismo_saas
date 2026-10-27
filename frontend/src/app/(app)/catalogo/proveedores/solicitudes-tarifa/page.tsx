import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { amount, type RateRequestRow } from "@/lib/supplier";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnGhostCls, Drawer, inputCls, Notice, PageHeader, Table, Td, TextArea } from "@/components/crm/ui";
import { Field } from "@/components/catalog/ui";
import { RowAction, SupStatus, withParams } from "@/components/supplier/ui";

type Search = { status?: string; revisar?: string };

/** Solicitudes de actualización de tarifa enviadas desde la extranet (spec 06 §4.6, SUP-BR-61…63). */
export default async function RateRequestsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const status = sp.status ?? "pendiente";
  const { data: rows, meta } = await api<{ data: RateRequestRow[]; meta: { pending: number; can_review: boolean } }>(
    `/catalog/rate-requests${qs({ "filter[status]": status === "todas" ? undefined : status })}`);
  const base = `/catalogo/proveedores/solicitudes-tarifa${qs({ status: sp.status })}`;
  const reviewing = sp.revisar && meta.can_review ? rows.find((r) => r.id === sp.revisar && r.status === "pendiente") : undefined;
  const diff = (r: RateRequestRow) => Number(r.current_cost) > 0 ? ((Number(r.proposed_cost) - Number(r.current_cost)) / Number(r.current_cost)) * 100 : 0;

  return (
    <>
      <PageHeader title="Solicitudes de tarifa" subtitle="Actualizaciones de tarifa neta propuestas por los proveedores desde la extranet"
        actions={<Link href="/catalogo/proveedores" className={btnGhostCls}>Proveedores</Link>} />
      {meta.pending > 0 && <Notice tone="warning">{meta.pending} {meta.pending === 1 ? "solicitud pendiente" : "solicitudes pendientes"} de revisión.</Notice>}
      {!meta.can_review && <Notice>Puede consultar las solicitudes; aprobarlas o rechazarlas requiere catalog.products.manage_rates.</Notice>}
      <form className="mb-4 flex flex-wrap gap-2">
        <select name="status" defaultValue={status} aria-label="Estado" className={`${inputCls} max-w-[14rem]`}>
          {[["pendiente", "Pendientes"], ["aprobada", "Aprobadas"], ["rechazada", "Rechazadas"], ["retirada", "Retiradas"], ["todas", "Todas"]].map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Solicitud", "Proveedor", "Servicio", "Actual → propuesta", "Desde", "Motivo", "Estado", ""]} empty="Sin solicitudes">
        {rows.map((r) => (
          <tr key={r.id}>
            <Td className="whitespace-nowrap"><span className="font-medium">{r.code}</span><span className="block text-xs text-slate-500">{dateTime(r.requested_at)}</span></Td>
            <Td><Link href={`/catalogo/proveedores/${r.provider_ref}`} className="text-sky-800 hover:underline">{r.provider_ref}</Link><span className="block text-xs text-slate-500">{r.provider}</span></Td>
            <Td>{r.service}<span className="block text-xs text-slate-500">{r.service_ref} · por {r.unit}</span></Td>
            <Td className="whitespace-nowrap">{amount(r.current_cost, r.currency)} → {amount(r.proposed_cost, r.currency)}
              <span className={`block text-xs ${diff(r) > 0 ? "text-rose-700" : "text-emerald-700"}`}>{diff(r) > 0 ? "+" : ""}{diff(r).toFixed(1)} %</span></Td>
            <Td className="whitespace-nowrap">{date(r.effective_from)}</Td>
            <Td>{r.reason_type}{r.reason_detail && <span className="block text-xs text-slate-500">{r.reason_detail}</span>}</Td>
            <Td><SupStatus value={r.status} label={r.status_label} />{r.review_note && <span className="mt-1 block text-xs text-slate-600">{r.review_note}</span>}</Td>
            <Td>{meta.can_review && r.status === "pendiente" && <RowAction primary href={withParams(base, { revisar: r.id })}>Revisar</RowAction>}</Td>
          </tr>
        ))}
      </Table>

      {reviewing && (
        <Drawer title={`Revisar ${reviewing.code}`} close={base}>
          <dl className="mb-4 grid grid-cols-2 gap-3 rounded-md bg-slate-50 p-3">
            <Field label="Proveedor">{reviewing.provider}</Field>
            <Field label="Servicio">{reviewing.service}</Field>
            <Field label="Tarifa actual">{amount(reviewing.current_cost, reviewing.currency)}</Field>
            <Field label="Propuesta">{amount(reviewing.proposed_cost, reviewing.currency)} ({diff(reviewing).toFixed(1)} %)</Field>
            <Field label="Vigente desde">{date(reviewing.effective_from)}</Field>
            <Field label="Motivo">{reviewing.reason_type}</Field>
          </dl>
          {reviewing.reason_detail && <p className="mb-4 text-sm text-slate-700">“{reviewing.reason_detail}”</p>}
          <div className="space-y-6">
            <ActionForm path={`/catalog/rate-requests/${reviewing.id}/approve`} redirectTo={base} submitLabel="Aprobar nueva vigencia">
              <TextArea label="Nota para el proveedor (opcional)" name="note" maxLength={500} rows={2} />
              <p className="text-xs text-slate-500">Se crea una vigencia desde {date(reviewing.effective_from)}; el costo del servicio cambia ese día. Las reservas confirmadas conservan su costo.</p>
            </ActionForm>
            <ActionForm path={`/catalog/rate-requests/${reviewing.id}/reject`} redirectTo={base} submitLabel="Rechazar"
              submitClass="rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800 disabled:opacity-50">
              <TextArea label="Motivo del rechazo (se envía al proveedor)" name="note" required maxLength={500} rows={2} />
            </ActionForm>
          </div>
        </Drawer>
      )}
    </>
  );
}
