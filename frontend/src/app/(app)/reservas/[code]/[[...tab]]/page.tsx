import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api } from "@/lib/api";
import { fetchScoped } from "@/lib/crm-server";
import { date, dateTime, type UserRef } from "@/lib/crm";
import { price } from "@/lib/catalog";
import { RES_FLOW, type PenaltyLine, type ResAction, type ResMeta, type Reservation } from "@/lib/reservations";
import { btnCls, btnGhostCls, Card, Notice, OutOfScope, PageHeader, Stepper, Table, Td } from "@/components/crm/ui";
import { Field, PathTabs } from "@/components/catalog/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { LockedButton } from "@/components/quotes/ui";
import { ResStatus } from "@/components/reservations/ui";
import { ReservationDialog } from "@/components/reservations/ReservationDialogs";

type Search = Record<string, string | undefined>;
type AuditRow = { entity: string; entity_id: string; action: string; before: string | null; after: string | null; actor: UserRef | { name: string }; at: string };

const LINKS: Record<string, (code: string) => string> = {
  confirmation_doc: (c) => `/reservas/${c}/confirmacion`,
};
const STATUS_LABEL: Record<string, string> = { borrador: "Borrador", confirmada: "Confirmada", en_operacion: "En operación", completada: "Completada" };

/** Reserva 360 (spec 04 §2): flujo, hechos, alertas, acciones por estado y rol, y pestañas filtradas por rol (§7). */
export default async function ReservationPage({ params, searchParams }: { params: Promise<{ code: string; tab?: string[] }>; searchParams: Promise<Search> }) {
  const [{ code, tab = [] }, sp] = await Promise.all([params, searchParams]);
  const res = await fetchScoped<{ data: Reservation }>(`/reservations/${code}`);
  if (!res) return <OutOfScope back="/reservas" />;
  const r = res.data;
  const v = r.view;
  const tabs = [
    ...(v.acc ? [{ key: "pagos", label: "Pagos" }] : []),
    { key: "resumen", label: "Resumen" },
    { key: "pasajeros", label: `Pasajeros (${r.passengers.length})` },
    { key: "servicios", label: `Servicios (${r.services.length})` },
    ...(!v.acc && !v.ops_view ? [{ key: "pagos", label: "Pagos" }] : []),
    { key: "documentos", label: "Documentos" },
    ...(!v.acc ? [{ key: "operacion", label: "Operación" }] : []),
    { key: "historial", label: "Historial" },
  ];
  const active = tab[0] ?? tabs[0].key;
  if (!tabs.some((t) => t.key === active)) notFound();
  const { data: meta } = await api<{ data: ResMeta }>("/reservations/meta");
  const base = `/reservas/${r.code}`;
  const here = active === tabs[0].key ? base : `${base}/${active}`;

  const closed = ["cancelada", "completada"].includes(r.status);
  const flowIndex = RES_FLOW.indexOf(r.status as (typeof RES_FLOW)[number]);
  const steps = r.status === "cancelada" ? ["Borrador", "Confirmada", "Cancelada"] : RES_FLOW.map((s) => STATUS_LABEL[s]);
  const current = r.status === "cancelada" ? 3 : flowIndex >= 0 ? flowIndex + 1 : r.status === "pendiente_disponibilidad" ? 1 : 2;
  const primary = r.actions.filter((a) => a.group === "primary");
  const more = r.actions.filter((a) => a.group === "more");
  const readOnly = !meta.can.modify && !meta.can.create && !meta.can.cancel;

  return (
    <>
      <PageHeader
        title={`${r.code} · ${r.title}`}
        subtitle={<span className="flex flex-wrap items-center gap-2"><ResStatus value={r.status} label={r.status_label} /> {r.client?.name} · {r.owner?.name} · {r.channel}</span>}
        actions={<Link href="/reservas" className="text-sm text-sky-800 hover:underline">← Reservas</Link>}
      />
      <div className="mb-4"><Stepper steps={steps} current={current} failed={r.status === "cancelada"} /></div>

      {v.ops_view && <Notice>Vista de Operaciones: ve costos de proveedores y la operación; los importes de venta, saldos y margen no se muestran.</Notice>}
      {readOnly && !v.ops_view && <Notice>Consulta enfocada en montos y pagos: su rol no confirma, modifica ni cancela reservas.</Notice>}
      {r.alerts.map((a) => (
        <Notice key={a.key} tone={a.tone === "danger" ? "danger" : a.tone === "warn" ? "warning" : "info"}><strong>{a.title}.</strong> {a.body}
          {a.key === "change" && r.can.approve_changes && r.pending_change && <> · <Link href={`${here}?accion=approve_change&c=${r.pending_change}`} className="font-medium underline">Aprobar</Link> · <Link href={`${here}?accion=reject_change&c=${r.pending_change}`} className="font-medium underline">Rechazar</Link></>}
          {a.key === "cancel_request" && r.can.process_cancel && <> · <Link href={`${here}?accion=cancel`} className="font-medium underline">Procesar cancelación</Link> · <Link href={`${here}?accion=reject_cancel_request`} className="font-medium underline">Rechazar solicitud</Link></>}
        </Notice>
      ))}

      <div className="mb-4 flex flex-wrap items-center gap-2" aria-label="Acciones">
        {primary.map((a, i) => <ActionButton key={a.key} a={a} code={r.code} here={here} primary={i === 0} />)}
        {more.length > 0 && (
          <details className="relative">
            <summary className={`${btnGhostCls} cursor-pointer list-none`}>Más ▾</summary>
            <div className="absolute z-20 mt-1 flex w-72 flex-col gap-1 rounded-md border border-slate-200 bg-white p-2 shadow-lg">
              {more.map((a) => a.enabled
                ? <Link key={a.key} href={a.target ?? LINKS[a.key]?.(r.code) ?? `${here}?accion=${a.key}`} className="rounded px-2 py-1 text-sm hover:bg-slate-50">{a.label}</Link>
                : <span key={a.key} title={a.reason ?? undefined} className="rounded px-2 py-1 text-sm text-slate-400">🔒 {a.label}</span>)}
            </div>
          </details>
        )}
      </div>

      <dl className="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-3 lg:grid-cols-6">
        <Field label="Cliente"><Link href={`/crm/clientes/${r.client?.code}`} className="text-sky-800 hover:underline">{r.client?.name}</Link></Field>
        <Field label="Viaje">{date(r.travel_from)}{r.travel_to !== r.travel_from && <> – {date(r.travel_to)}</>}</Field>
        <Field label="Pasajeros">{r.pax}</Field>
        {v.ops_view
          ? <><Field label="Costo proveedores">{price(r.provider_cost, r.currency)}</Field><Field label="Servicios confirmados">{r.services_confirmed}/{r.services_total}</Field></>
          : <><Field label="Total">{price(r.total, r.currency)}</Field><Field label="Saldo">{price(r.balance, r.currency)}{r.paid && <span className="block text-xs text-slate-500">Cobrado {price(r.paid, r.currency)}</span>}</Field></>}
        <Field label="Origen">{r.quote ? (v.ops_view ? `${r.quote.code} v${r.quote.version_no}` : <Link href={`/cotizaciones/${r.quote.code}`} className="text-sky-800 hover:underline">{r.quote.code} v{r.quote.version_no}</Link>) : "Reserva directa"}</Field>
      </dl>

      <PathTabs base={base} tabs={tabs} active={active} />
      {active === "resumen" && <SummaryTab r={r} here={here} />}
      {active === "pasajeros" && <PassengersTab r={r} here={here} />}
      {active === "servicios" && <ServicesTab r={r} here={here} />}
      {active === "pagos" && <PaymentsTab r={r} here={here} />}
      {active === "documentos" && <DocumentsTab r={r} />}
      {active === "operacion" && <OperationTab r={r} here={here} closed={closed} />}
      {active === "historial" && <HistoryTab code={r.code} />}

      {sp.accion && <ReservationDialog action={sp.accion} r={r} meta={meta} close={here} sp={sp} />}
    </>
  );
}

function ActionButton({ a, code, here, primary }: { a: ResAction; code: string; here: string; primary: boolean }) {
  if (!a.enabled) return <LockedButton label={a.label} reason={a.reason} />;
  const danger = a.key === "cancel" || a.key === "request_cancel";
  return <Link href={LINKS[a.key]?.(code) ?? `${here}?accion=${a.key}`} className={danger ? "rounded-md border border-rose-300 bg-white px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50" : primary ? btnCls : btnGhostCls}>{a.label}</Link>;
}

function SummaryTab({ r, here }: { r: Reservation; here: string }) {
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
      <div className="space-y-4">
        <Card title="Servicios">
          <ul className="divide-y divide-slate-100 text-sm">
            {r.services.map((s) => (
              <li key={s.id} className="flex items-center justify-between gap-2 py-1.5">
                <span><strong>{s.code}</strong> {s.name} · {date(s.service_date)}{s.service_time ? ` ${s.service_time}` : ""}</span>
                <ResStatus value={s.status} label={s.status_label} />
              </li>
            ))}
          </ul>
        </Card>
        <Card title="Modificaciones y reprogramaciones">
          {r.changes.length === 0 ? <p className="text-sm text-slate-500">Sin cambios registrados.</p> : (
            <Table head={["Tipo", "Descripción", ...(r.view.ops_view ? [] : ["Impacto"]), "Estado", "Solicitó", ""]}>
              {r.changes.map((c) => (
                <tr key={c.id}>
                  <Td>{c.type_label}{c.extra_pax ? ` (+${c.extra_pax})` : ""}</Td>
                  <Td>{c.description}{c.decision_note && <span className="block text-xs text-slate-500">{c.decision_note}</span>}</Td>
                  {!r.view.ops_view && <Td>{c.delta_amount !== undefined ? price(c.delta_amount, r.currency) : "—"}{c.credit_amount && <span className="block text-xs text-emerald-700">Saldo a favor {price(c.credit_amount, r.currency)}</span>}</Td>}
                  <Td><ResStatus value={c.status} label={c.status} /></Td>
                  <Td>{c.requested_by?.name}<span className="block text-xs text-slate-500">{dateTime(c.requested_at)}</span></Td>
                  <Td>{c.status === "pendiente" && r.can.approve_changes && <span className="flex gap-2 text-xs">
                    <Link href={`${here}?accion=approve_change&c=${c.id}`} className="text-sky-800 underline">Aprobar</Link>
                    <Link href={`${here}?accion=reject_change&c=${c.id}`} className="text-rose-700 underline">Rechazar</Link></span>}</Td>
                </tr>
              ))}
            </Table>
          )}
        </Card>
        {r.cancellation && (
          <Card title="Cancelación">
            <p className="mb-2 text-sm">{r.cancellation.reason} · {dateTime(r.cancellation.at)}</p>
            <PenaltyTable lines={r.cancellation.lines} currency={r.currency} />
            <p className="mt-2 text-sm">Factor k {r.cancellation.k} · <strong>Penalidad {price(r.cancellation.penalty, r.currency)}</strong> · Reembolso {price(r.cancellation.refund, r.currency)} · Por cobrar {price(r.cancellation.owed, r.currency)}</p>
          </Card>
        )}
      </div>
      <div className="space-y-4">
        <Card title="Políticas (snapshot)">
          {r.policies.length === 0 ? <p className="text-sm text-slate-500">Sin políticas registradas.</p> : r.policies.map((p) => (
            <div key={p.product} className="mb-2 text-sm"><strong>{p.product}</strong>
              <p className="text-slate-600">Cancelación: {p.cancel ?? "—"}</p>
              {p.modify && <p className="text-slate-600">Cambios: {p.modify}</p>}</div>
          ))}
          <p className="mt-2 text-xs text-slate-500">{r.snapshot_label}</p>
        </Card>
        {r.notes && <Card title="Notas"><p className="whitespace-pre-line text-sm text-slate-700">{r.notes}</p></Card>}
      </div>
    </div>
  );
}

function PenaltyTable({ lines, currency }: { lines: PenaltyLine[]; currency: string }) {
  return (
    <Table head={["Servicio", "Días antes", "Política", "%", "Precio", "Penalidad"]}>
      {lines.map((l) => (
        <tr key={l.code}>
          <Td>{l.code} {l.name}</Td><Td>{l.days}</Td><Td className="text-xs">{l.policy ?? "Estándar"}</Td><Td>{l.pct} %</Td>
          <Td>{price(l.price, currency)}</Td><Td>{price(l.penalty, currency)}</Td>
        </tr>
      ))}
    </Table>
  );
}

function PassengersTab({ r, here }: { r: Reservation; here: string }) {
  return (
    <Card title="Pasajeros" actions={r.can.edit_passengers && <Link href={`${here}?accion=passenger`} className={btnGhostCls}>Agregar pasajero</Link>}>
      <Table head={["#", "Nombre", "Tipo", "Documento", "Nacionalidad", "Edad", "Parentesco", "Dieta / requerimientos", ""]}>
        {r.passengers.map((p) => (
          <tr key={p.id} className={p.is_placeholder ? "text-slate-500" : ""}>
            <Td>{p.seq}</Td>
            <Td>{p.full_name}{p.is_lead && <span className="ml-1 rounded bg-sky-50 px-1 text-xs text-sky-800">Titular</span>}</Td>
            <Td>{p.pax_type}</Td><Td>{p.doc_number ? `${p.doc_type} ${p.doc_number}` : "—"}</Td><Td>{p.nationality ?? "—"}</Td>
            <Td>{p.age ?? "—"}</Td><Td>{p.relationship ?? "—"}</Td><Td>{p.diet_notes ?? "—"}</Td>
            <Td>{r.can.edit_passengers && <Link href={`${here}?accion=passenger&p=${p.id}`} className="text-xs text-sky-800 underline">{p.is_placeholder ? "Completar" : "Editar"}</Link>}</Td>
          </tr>
        ))}
      </Table>
      <p className="mt-2 text-xs text-slate-500">Agregar o quitar pasajeros aquí no recalcula precio ni cupos; para el impacto use Modificar (RES-BR-10).</p>
    </Card>
  );
}

function ServicesTab({ r, here }: { r: Reservation; here: string }) {
  const v = r.view;
  return (
    <Card title="Servicios por proveedor">
      <Table head={["Código", "Servicio", "Fecha", "Pax", "Proveedor", "Estado", ...(v.see_cost && !v.acc ? ["Costo"] : []), ...(v.see_price && !v.ops_view ? ["Precio"] : []), ""]}>
        {r.services.map((s) => (
          <tr key={s.id}>
            <Td>{s.code}{s.alt_of && <span className="block text-xs text-slate-500">alternativa</span>}</Td>
            <Td>{s.name}{!s.is_required && <span className="block text-xs text-slate-500">opcional</span>}{s.reject_reason && <span className="block text-xs text-rose-700">{s.reject_reason}</span>}</Td>
            <Td>{date(s.service_date)}{s.service_time && <span className="block text-xs text-slate-500">{s.service_time}</span>}</Td>
            <Td>{s.pax ?? "—"}</Td>
            <Td>{s.provider?.name ?? "—"}{s.provider && !s.provider.usable && <span className="block text-xs text-rose-700">no activo</span>}</Td>
            <Td><ResStatus value={s.status} label={s.status_label} />{s.confirmation_code && <span className="block text-xs text-slate-500">{s.confirmation_code}{s.confirmed_by ? ` · ${s.confirmed_by}` : ""}</span>}</Td>
            {v.see_cost && !v.acc && <Td>{price(s.cost, r.currency)}</Td>}
            {v.see_price && !v.ops_view && <Td>{price(s.price, r.currency)}</Td>}
            <Td>{r.can.manage_services && <span className="flex flex-wrap gap-2 text-xs">
              {s.status === "pendiente" && <ActionForm path={`/reservations/${r.code}/services/${s.code}/request`} inline submitLabel="Solicitar" success="Solicitud enviada"
                submitClass="text-xs font-medium text-sky-800 underline" disabled={!s.provider?.usable} disabledReason="RES-BR-11: proveedor no activo" />}
              {["pendiente", "solicitado"].includes(s.status) && <Link href={`${here}?accion=service_confirm&s=${s.code}`} className="text-emerald-700 underline">Confirmar</Link>}
              {["solicitado", "confirmado"].includes(s.status) && <Link href={`${here}?accion=service_reject&s=${s.code}`} className="text-rose-700 underline">Rechazo</Link>}
            </span>}</Td>
          </tr>
        ))}
      </Table>
    </Card>
  );
}

function PaymentsTab({ r, here }: { r: Reservation; here: string }) {
  if (!r.receivables) return <p className="text-sm text-slate-500">Sin acceso a pagos.</p>;
  return (
    <div className="space-y-4">
      <Card title="Cuentas por cobrar" actions={r.receivables.length === 0 && r.actions.find((a) => a.key === "generate_receivables")?.enabled && <Link href={`${here}?accion=generate_receivables`} className={btnGhostCls}>Generar anticipo y saldo</Link>}>
        <Table head={["CxC", "Concepto", "Monto", "Pagado", "Saldo", "Vence", "Estado", ""]} empty="Sin cuentas por cobrar">
          {r.receivables.map((c) => (
            <tr key={c.id}>
              <Td>{c.code}</Td><Td>{c.concept}</Td><Td>{price(c.amount, c.currency)}</Td><Td>{price(c.paid, c.currency)}</Td><Td>{price(c.balance, c.currency)}</Td>
              <Td>{date(c.due_date)}</Td><Td><ResStatus value={c.status} label={c.status} /></Td>
              <Td>{c.open && (r.can.register_payment
                ? <Link href={`${here}?accion=register_payment&cxc=${c.id}`} className="text-xs text-sky-800 underline">Registrar pago</Link>
                : <span title="Requiere permiso finance.payments.register" className="text-xs text-slate-400">🔒 Registrar pago</span>)}</Td>
            </tr>
          ))}
        </Table>
      </Card>
      <Card title="Pagos y reembolsos">
        <Table head={["Pago", "Tipo", "Medio", "Monto", "Fecha", "Estado", "CxC", "Referencia"]} empty="Sin pagos registrados">
          {(r.payments ?? []).map((p) => (
            <tr key={p.id}>
              <Td>{p.code}</Td><Td>{p.kind}</Td><Td>{p.method}</Td><Td>{price(p.amount, p.currency)}</Td><Td>{date(p.paid_on)}</Td>
              <Td><ResStatus value={p.status} label={p.status} /></Td><Td>{p.receivable ?? "—"}</Td><Td className="text-xs">{p.reference ?? "—"}</Td>
            </tr>
          ))}
        </Table>
      </Card>
    </div>
  );
}

function DocumentsTab({ r }: { r: Reservation }) {
  return (
    <Card title="Documentos">
      <ul className="space-y-2 text-sm">
        <li><Link href={`/reservas/${r.code}/confirmacion`} className="text-sky-800 underline">Documento de confirmación de reserva</Link> <span className="text-slate-500">(vista de impresión, sin costos)</span></li>
        {r.quote && <li>Cotización de origen: {r.view.ops_view ? `${r.quote.code} v${r.quote.version_no}` : <Link href={`/cotizaciones/${r.quote.code}/documento`} className="text-sky-800 underline">{r.quote.code} v{r.quote.version_no}</Link>}</li>}
        <li className="text-slate-500">Vouchers y orden operativa: se emiten con el módulo de Operaciones.</li>
      </ul>
    </Card>
  );
}

function OperationTab({ r, here, closed }: { r: Reservation; here: string; closed: boolean }) {
  const start = r.actions.find((a) => a.key === "start_operation");
  const complete = r.actions.find((a) => a.key === "complete");
  const order = r.actions.find((a) => a.key === "operation_order");
  return (
    <Card title="Operación">
      <div className="space-y-3 text-sm">
        <p>Servicios confirmados por proveedor: <strong>{r.services_confirmed}/{r.services_total}</strong></p>
        <div className="flex flex-wrap gap-2">
          {start && (start.enabled ? <Link href={`${here}?accion=start_operation`} className={btnCls}>{start.label}</Link> : <LockedButton label={start.label} reason={start.reason} />)}
          {complete && (complete.enabled ? <Link href={`${here}?accion=complete`} className={btnCls}>{complete.label}</Link> : <LockedButton label={complete.label} reason={complete.reason} />)}
          {order && (order.enabled && order.target ? <Link href={order.target} className={btnGhostCls}>{order.label}</Link> : <LockedButton label={order.label} reason={order.reason} />)}
        </div>
        {closed && <p className="text-slate-500">La reserva está cerrada.</p>}
      </div>
    </Card>
  );
}

async function HistoryTab({ code }: { code: string }) {
  const { data } = await api<{ data: AuditRow[] }>(`/reservations/${code}/history`);
  return (
    <Card title="Historial">
      <Table head={["Fecha", "Registro", "Acción", "Antes", "Después", "Usuario"]} empty="Sin eventos">
        {data.map((e, i) => (
          <tr key={i}><Td>{dateTime(e.at)}</Td><Td>{e.entity} {e.entity_id}</Td><Td>{e.action}</Td><Td className="text-xs">{e.before ?? "—"}</Td><Td className="text-xs">{e.after ?? "—"}</Td><Td>{e.actor?.name}</Td></tr>
        ))}
      </Table>
    </Card>
  );
}
