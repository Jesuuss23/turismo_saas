import { api } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { amount } from "@/lib/supplier";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnGhostCls, Drawer, Input, inputCls, Notice, PageHeader, Table, Td, TextArea } from "@/components/crm/ui";
import { Field } from "@/components/catalog/ui";
import { OpsNav } from "@/components/ops/ui";
import { RowAction, SupStatus, withParams } from "@/components/supplier/ui";

type Alt = {
  id: string; type: string; provider: string; provider_ref: string; responder: string | null;
  service: { id: string; code: string; name: string; date: string; status: string; cost: string; pax: number | null };
  reservation: { code: string; title: string; currency: string }; responded_at: string; alt_kind: string | null; alt_date: string | null; alt_service: string | null;
  alt_net_cost: string | null; currency: string | null; hold_until: string | null; note: string | null; review_status: string | null; review_label: string | null; review_note: string | null;
};
type Search = { review?: string; aceptar?: string; rechazar?: string };

/** Bandeja de alternativas propuestas por proveedores desde la extranet (spec 06 §6, contraparte back-office). */
export default async function SupplierResponsesPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const { data: rows, meta } = await api<{ data: Alt[]; meta: { pending: number } }>(`/ops/supplier-responses${qs({ "filter[review_status]": sp.review ?? "pendiente" })}`);
  const base = `/operaciones/proveedores${qs({ review: sp.review })}`;
  const accepting = sp.aceptar ? rows.find((r) => r.id === sp.aceptar && r.review_status === "pendiente") : undefined;
  const rejecting = sp.rechazar ? rows.find((r) => r.id === sp.rechazar && r.review_status === "pendiente") : undefined;
  const current = accepting ?? rejecting;

  return (
    <>
      <PageHeader title="Respuestas de proveedores" subtitle="Alternativas que los proveedores proponen desde la extranet: acéptelas para aplicar el cambio o recháselas" />
      <OpsNav active="/operaciones/proveedores" />
      {meta.pending > 0 && <Notice tone="warning">{meta.pending} {meta.pending === 1 ? "alternativa espera" : "alternativas esperan"} su revisión. El proveedor mantiene la oferta hasta la fecha indicada.</Notice>}
      <form className="mb-4 flex flex-wrap gap-2">
        <select name="review" defaultValue={sp.review ?? "pendiente"} aria-label="Revisión" className={`${inputCls} max-w-[14rem]`}>
          {[["pendiente", "Pendientes"], ["aceptada", "Aceptadas"], ["rechazada", "Rechazadas"], ["expirada", "Expiradas"], ["todas", "Todas"]].map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Proveedor", "Servicio solicitado", "Alternativa", "Mantiene hasta", "Revisión", ""]} empty="Sin alternativas con este filtro">
        {rows.map((r) => (
          <tr key={r.id}>
            <Td>{r.provider}<span className="block text-xs text-slate-500">{r.provider_ref} · {r.responder} · {dateTime(r.responded_at)}</span></Td>
            <Td>{r.service.name}<span className="block text-xs text-slate-500">{r.reservation.code} · {r.service.code} · {date(r.service.date)} · {amount(r.service.cost, r.reservation.currency)}</span></Td>
            <Td><span className="font-medium">{r.alt_kind}</span>
              <span className="block text-xs text-slate-600">{[r.alt_date && date(r.alt_date), r.alt_service, r.alt_net_cost && amount(r.alt_net_cost, r.currency)].filter(Boolean).join(" · ")}</span>
              {r.note && <span className="block text-xs text-slate-500">“{r.note}”</span>}</Td>
            <Td className="whitespace-nowrap">{date(r.hold_until)}</Td>
            <Td><SupStatus value={r.review_status ?? "pendiente"} label={r.review_label ?? "Pendiente"} />{r.review_note && <span className="mt-1 block text-xs text-slate-600">{r.review_note}</span>}</Td>
            <Td>{r.review_status === "pendiente" && (
              <div className="flex flex-wrap gap-1">
                <RowAction primary href={withParams(base, { aceptar: r.id })}>Aceptar</RowAction>
                <RowAction href={withParams(base, { rechazar: r.id })}>Rechazar</RowAction>
              </div>
            )}</Td>
          </tr>
        ))}
      </Table>

      {current && (
        <Drawer title={accepting ? "Aceptar alternativa" : "Rechazar alternativa"} close={base}>
          <dl className="mb-4 grid grid-cols-2 gap-3 rounded-md bg-slate-50 p-3">
            <Field label="Proveedor">{current.provider}</Field>
            <Field label="Reserva">{current.reservation.code} · {current.reservation.title}</Field>
            <Field label="Solicitado">{current.service.name} · {date(current.service.date)} · {amount(current.service.cost, current.reservation.currency)}</Field>
            <Field label="Alternativa">{[current.alt_kind, current.alt_date && date(current.alt_date), current.alt_service, current.alt_net_cost && amount(current.alt_net_cost, current.currency)].filter(Boolean).join(" · ")}</Field>
          </dl>
          {accepting ? (
            <ActionForm path={`/ops/supplier-responses/${accepting.id}/accept`} idempotent redirectTo={base} submitLabel="Aceptar y aplicar">
              <Input label="Código de confirmación del proveedor (si ya lo dio)" name="confirmation_code" maxLength={40} autoComplete="off" />
              <TextArea label="Nota (opcional)" name="note" maxLength={500} rows={2} />
              <p className="text-xs text-slate-500">Se aplican la fecha, el servicio y el costo propuestos. Con código, el servicio queda confirmado; sin código, se reenvía la solicitud al proveedor para que confirme.</p>
            </ActionForm>
          ) : (
            <ActionForm path={`/ops/supplier-responses/${current.id}/reject`} idempotent redirectTo={base} submitLabel="Rechazar alternativa"
              submitClass="rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800 disabled:opacity-50">
              <TextArea label="Motivo (se envía al proveedor)" name="note" required maxLength={500} rows={2} />
              <p className="text-xs text-slate-500">La solicitud vuelve a “Por responder” para el proveedor.</p>
            </ActionForm>
          )}
        </Drawer>
      )}
    </>
  );
}
