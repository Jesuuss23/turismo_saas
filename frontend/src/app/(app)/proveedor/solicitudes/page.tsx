import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { ALT_KINDS, amount, REJECT_REASONS, REQUEST_FILTERS, relDays, type SupplierRequestDetail, type SupplierRequestRow, type SupplierResponse } from "@/lib/supplier";
import { supplierMe } from "@/lib/supplier-server";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnGhostCls, Card, Drawer, Input, inputCls, Notice, PageHeader, Select, Table, Td, TextArea } from "@/components/crm/ui";
import { Field } from "@/components/catalog/ui";
import { Label, RowAction, SupStatus, withParams } from "@/components/supplier/ui";

type Search = { state?: string; q?: string; ver?: string; accion?: string };

/** Solicitudes del proveedor (spec 06 §2, §4.1): responder confirma, rechaza o propone una alternativa. */
export default async function SupplierRequestsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const { me } = await supplierMe();
  if (!me) return null;
  const sp = await searchParams;
  const { data: rows } = await api<{ data: SupplierRequestRow[] }>(`/supplier/requests${qs({ "filter[state]": sp.state, q: sp.q })}`);
  const pending = rows.filter((r) => r.state === "pending").length;
  const canRespond = me.permissions.includes("supplier.requests.respond");
  const base = `/proveedor/solicitudes${qs({ state: sp.state, q: sp.q })}`;
  const detail = sp.ver ? (await api<{ data: SupplierRequestDetail }>(`/supplier/requests/${sp.ver}`)).data : null;
  const action = detail && canRespond && detail.can_respond ? sp.accion : undefined;

  return (
    <>
      <PageHeader title="Solicitudes" subtitle={`Servicios que ${me.agency} le solicita. Confirme con su código de reserva o proponga una alternativa.`} />
      {pending > 0 && (
        <Notice tone="warning">
          Tiene {pending} {pending === 1 ? "solicitud" : "solicitudes"} por responder. Mientras no confirme, la agencia no puede emitir el voucher al pasajero (RT-11).
        </Notice>
      )}

      <nav className="mb-3 flex flex-wrap gap-1" aria-label="Filtrar por estado">
        {REQUEST_FILTERS.map((f) => (
          <Link key={f.key} href={`/proveedor/solicitudes${qs({ state: f.key, q: sp.q })}`} aria-current={(sp.state ?? "") === f.key ? "page" : undefined}
            className={`rounded-full px-3 py-1 text-xs font-medium ring-1 ${(sp.state ?? "") === f.key ? "bg-sky-700 text-white ring-sky-700" : "bg-white text-slate-700 ring-slate-300 hover:bg-slate-50"}`}>
            {f.label}
          </Link>
        ))}
      </nav>
      <form className="mb-4 flex flex-wrap gap-2" role="search">
        {sp.state && <input type="hidden" name="state" value={sp.state} />}
        <input name="q" defaultValue={sp.q} placeholder="Buscar por reserva, servicio o titular" aria-label="Buscar" className={`${inputCls} max-w-sm`} />
        <button className={btnGhostCls}>Buscar</button>
      </form>

      <Table head={["Fecha", "Servicio", "Grupo / titular", "Pax", "Tarifa neta", "Estado", ""]} empty="Sin solicitudes con este filtro">
        {rows.map((r) => (
          <tr key={r.id}>
            <Td className="whitespace-nowrap">
              {date(r.service_date)}{r.service_time && <span className="block text-xs text-slate-500">{r.service_time}</span>}
              {r.days_to >= 0 && r.days_to <= 2 && ["pending", "alternative"].includes(r.state) && <span className="block text-xs font-medium text-rose-700">{relDays(r.days_to)}</span>}
            </Td>
            <Td><span className="font-medium">{r.service_label}</span><span className="block text-xs text-slate-500">{r.reservation_code} · {r.code}</span></Td>
            <Td>{r.holder_label}</Td>
            <Td>{r.pax ?? "—"}</Td>
            <Td className="whitespace-nowrap">{amount(r.unit_cost, r.currency)}</Td>
            <Td><SupStatus value={r.state} label={r.state_label} />{r.confirmation_code && <span className="block font-mono text-xs text-slate-600">{r.confirmation_code}</span>}</Td>
            <Td>
              <div className="flex flex-wrap gap-1">
                {canRespond && r.can_respond && <>
                  <RowAction primary href={withParams(base, { ver: r.id, accion: "confirmar" })}>Confirmar</RowAction>
                  {r.can_propose && <RowAction href={withParams(base, { ver: r.id, accion: "alternativa" })}>Alternativa</RowAction>}
                  <RowAction href={withParams(base, { ver: r.id, accion: "rechazar" })}>Rechazar</RowAction>
                </>}
                <RowAction href={withParams(base, { ver: r.id })}>Ver detalle</RowAction>
              </div>
            </Td>
          </tr>
        ))}
      </Table>

      {detail && action === "confirmar" && (
        <Drawer title="Confirmar solicitud" close={base}>
          <Summary r={detail} />
          <ActionForm path={`/supplier/requests/${detail.id}/confirm`} idempotent redirectTo={base} submitLabel="Confirmar servicio">
            <Input label="Código de confirmación" name="confirmation_code" required minLength={4} maxLength={40} autoComplete="off" placeholder="Ej. IW-48213" />
            <Input label="Confirmado por" name="confirmed_by_name" defaultValue={me.user.name} maxLength={120} />
            <TextArea label="Nota para Operaciones (opcional)" name="note" maxLength={500} />
          </ActionForm>
        </Drawer>
      )}
      {detail && action === "rechazar" && (
        <Drawer title="Rechazar solicitud" close={base}>
          <Summary r={detail} />
          <ActionForm path={`/supplier/requests/${detail.id}/reject`} idempotent redirectTo={base} submitLabel="Rechazar solicitud"
            submitClass="rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800 disabled:opacity-50">
            <Select label="Motivo" name="reason" required placeholder="Elija el motivo" options={REJECT_REASONS.map((x) => ({ value: x, label: x }))} />
            <TextArea label="Detalle (obligatorio si el motivo es Otro)" name="note" maxLength={500} />
            <p className="text-xs text-slate-500">Operaciones buscará otro proveedor. Si puede ofrecer otra fecha o tipo de servicio, use “Alternativa”.</p>
          </ActionForm>
        </Drawer>
      )}
      {detail && action === "alternativa" && detail.can_propose && (
        <Drawer title="Proponer alternativa" close={base}>
          <Summary r={detail} />
          <ActionForm path={`/supplier/requests/${detail.id}/alternative`} idempotent redirectTo={base} submitLabel="Enviar alternativa">
            <Select label="Tipo de alternativa" name="kind" required placeholder="Elija" options={ALT_KINDS.map((x) => ({ value: x, label: x }))} />
            <Input label="Nueva fecha (si cambia)" name="date" type="date" />
            {detail.alternative_services.length > 0 && (
              <Select label="Otro servicio contratado (opcional)" name="service_id" placeholder="El mismo servicio"
                options={detail.alternative_services.map((s) => ({ value: s.id, label: `${s.name} (${s.unit})` }))} />
            )}
            <Input label={`Tarifa neta total (${detail.currency ?? ""})`} name="net_cost" type="number" step="0.01" min="0" defaultValue={detail.unit_cost} required />
            <Input label="Mantener la oferta hasta" name="hold_until" type="date" required />
            <TextArea label="Nota para Operaciones" name="note" maxLength={500} />
          </ActionForm>
        </Drawer>
      )}
      {detail && !action && (
        <Drawer title={`Solicitud ${detail.code}`} close={base}>
          <Summary r={detail} />
          <div className="space-y-4">
            {detail.response && <Card title="Su respuesta"><ResponseView r={detail.response} /></Card>}
            {detail.responses.length > 1 && (
              <Card title="Respuestas anteriores">
                <ul className="space-y-3">{detail.responses.slice(1).map((x) => <li key={x.id}><ResponseView r={x} /></li>)}</ul>
              </Card>
            )}
            <Card title="Rooming list vinculada">
              {detail.rooming ? (
                <p className="text-sm">{date(detail.rooming.check_in)} → {date(detail.rooming.check_out)} · {detail.rooming.rooms_count} hab. · {detail.rooming.guests_count} huéspedes ·{" "}
                  <SupStatus value={detail.rooming.state} label={detail.rooming.state_label} />{" "}
                  <Link href="/proveedor/rooming" className="text-sky-800 hover:underline">Ver</Link></p>
              ) : <p className="text-sm text-slate-500">Aún no hay rooming list para esta reserva.</p>}
            </Card>
            <Card title="Voucher vinculado">
              {detail.voucher ? (
                <p className="text-sm"><span className="font-mono">{detail.voucher.code}</span> · v{detail.voucher.version} · <SupStatus value={detail.voucher.status} label={detail.voucher.status_label} /></p>
              ) : <p className="text-sm text-slate-500">La agencia emite el voucher cuando usted confirma.</p>}
            </Card>
          </div>
        </Drawer>
      )}
    </>
  );
}

function Summary({ r }: { r: SupplierRequestRow }) {
  return (
    <dl className="mb-4 grid grid-cols-2 gap-3 rounded-md bg-slate-50 p-3">
      <Field label="Servicio">{r.service_label}</Field>
      <Field label="Fecha">{date(r.service_date)}{r.service_time ? ` · ${r.service_time}` : ""}</Field>
      <Field label="Reserva">{r.reservation_code} · {r.code}</Field>
      <Field label="Pasajeros">{r.holder_label} · {r.pax ?? "—"} pax</Field>
      <Field label="Tarifa neta">{amount(r.unit_cost, r.currency)}</Field>
      <Field label="Estado"><SupStatus value={r.state} label={r.state_label} /></Field>
    </dl>
  );
}

function ResponseView({ r }: { r: SupplierResponse }) {
  const kind = { confirmacion: "Confirmación", rechazo: "Rechazo", alternativa: "Alternativa" }[r.type];
  return (
    <div className="space-y-1 text-sm">
      <p className="font-medium text-slate-900">{kind} · <span className="font-normal text-slate-600">{dateTime(r.responded_at)}</span></p>
      {r.confirmation_code && <p>Código <span className="font-mono">{r.confirmation_code}</span>{r.confirmed_by_name && ` · por ${r.confirmed_by_name}`}</p>}
      {r.rejection_reason && <p>Motivo: {r.rejection_reason}</p>}
      {r.alt_kind && <p>{r.alt_kind}{r.alt_date && ` · ${date(r.alt_date)}`}{r.alt_service && ` · ${r.alt_service}`}{r.alt_net_cost && ` · ${amount(r.alt_net_cost, r.currency)}`}{r.hold_until && ` · se mantiene hasta ${date(r.hold_until)}`}</p>}
      {r.note && <p className="text-slate-600">“{r.note}”</p>}
      {r.review_status && <p><Label>Revisión de Operaciones</Label><SupStatus value={r.review_status} label={r.review_label ?? r.review_status} />{r.review_note && <span className="ml-2 text-slate-600">{r.review_note}</span>}</p>}
    </div>
  );
}
