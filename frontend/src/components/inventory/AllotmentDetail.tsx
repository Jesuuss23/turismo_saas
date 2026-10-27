import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, Input, Kpis, Notice, PageHeader, Table, Td, TextArea, btnCls, btnGhostCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { AllotmentForm, type AllotmentOptions } from "@/components/inventory/AllotmentForm";
import { CellBox, CellDrawer, NEED, StatusPill, money, type Cell, type ContractRow } from "@/components/inventory/allotments";

type Detail = ContractRow & {
  kpis: { available_30: number; pending_30: number; exposure: number; accrued: number; requests_open: number };
  flow: string[]; calendar_from: string; calendar: Cell[]; can_manage: boolean;
  consumptions: { date: string; reservation: string; reservation_title: string; service: string; qty: number; state: string }[];
  requests: { id: string; code: string; date_from: string; date_to: string; extra_qty: number; note: string; status: string; status_label: string; response: string | null; responded_at: string | null; responder: string | null; requester: string | null; created_at: string }[];
  charges: { date: string; qty: number; amount: string; currency: string; reason: string; payable: string | null; payable_status: string | null; system: boolean }[];
  events: { at: string; user: string; message: string }[];
  audit: { at: string; action: string; actor: string; before: string | null; after: string | null }[];
};
export type DetailParams = { tab?: string; from?: string; cell?: string; accion?: string };

const TABS = [
  { key: "calendario", label: "Calendario" }, { key: "condiciones", label: "Condiciones" }, { key: "consumo", label: "Consumo" },
  { key: "ampliaciones", label: "Ampliaciones" }, { key: "penalidades", label: "Penalidades" }, { key: "historial", label: "Historial" },
];
const FLOW_LABEL: Record<string, string> = { activo: "Activo", en_release: "En release", liberado: "Liberado", vencido: "Vencido" };
const rejectCls = "rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800 disabled:cursor-not-allowed disabled:opacity-50";

function addDays(day: string, n: number) {
  const d = new Date(`${day}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

/** /catalogo/cupos/[code] (§2): KPIs, ciclo de estados, pestañas y acciones Solicitar ampliación, Editar y Liberar contrato. */
export async function AllotmentDetail({ code, sp }: { code: string; sp: DetailParams }) {
  let a: Detail;
  try {
    a = (await api<{ data: Detail }>(`/catalog/allotments/${encodeURIComponent(code)}${qs({ from: sp.from })}`)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) notFound();
    throw e;
  }
  const tab = TABS.some((t) => t.key === sp.tab) ? sp.tab! : "calendario";
  const base = `/catalogo/cupos/${a.code}`;
  const href = (extra: Record<string, string | undefined>) => `${base}${qs({ tab, from: sp.from, ...extra })}`;
  const close = href({});
  const live = ["activo", "en_release"].includes(a.status);
  const options = sp.accion === "editar" && a.can_manage ? (await api<{ data: AllotmentOptions }>("/catalog/allotments/options")).data : null;
  const action = (key: string, label: string, primary = false) => a.can_manage && live
    ? <Link href={href({ accion: key })} scroll={false} className={primary ? btnCls : btnGhostCls}>{label}</Link>
    : <button disabled title={a.can_manage ? "El contrato no está vigente" : NEED} className={primary ? btnCls : btnGhostCls}>{label}</button>;

  return (
    <section>
      <p className="mb-2 text-sm"><Link href="/catalogo/cupos" className="text-sky-800 hover:underline">← Cupos y bloqueos</Link></p>
      <PageHeader title={`${a.code} · ${a.name}`} subtitle={<>{a.provider.name} · {a.service.name} · <StatusPill status={a.status} label={a.status_label} /></>}
        actions={<div className="flex flex-wrap gap-2">{action("ampliar", "Solicitar ampliación", true)}{action("editar", "Editar")}{action("liberar", "Liberar contrato")}</div>} />
      <Kpis items={[
        { label: "Utilización 30 días", value: a.utilization_30 === null ? "—" : `${a.utilization_30} %`, hint: `${a.used_30} de ${a.current_30} ${a.unit}` },
        { label: "Disponible 30 días", value: a.kpis.available_30, hint: a.kpis.pending_30 ? `${a.kpis.pending_30} en trámite` : a.unit },
        { label: "Exposición a penalidad", value: money(a.kpis.exposure, a.currency), hint: "Retenido tras el release" },
        { label: "Penalidad devengada", value: money(a.kpis.accrued, a.currency) },
        { label: "Ampliaciones abiertas", value: a.kpis.requests_open },
      ]} />
      <ol className="mb-4 flex flex-wrap items-center gap-2 text-xs" aria-label="Ciclo del contrato">
        {a.flow.map((s, i) => (
          <li key={s} className="flex items-center gap-2">
            <span className={`rounded-full px-3 py-1 ring-1 ${s === a.status ? "bg-sky-700 text-white ring-sky-700" : "bg-white text-slate-600 ring-slate-200"}`}>{FLOW_LABEL[s]}</span>
            {i < a.flow.length - 1 && <span className="text-slate-300">→</span>}
          </li>
        ))}
      </ol>
      {a.status === "liberado" && <Notice tone="info">Liberado el {dateTime(a.released_at)}: {a.release_reason}</Notice>}
      <nav className="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Secciones del contrato">
        {TABS.map((t) => (
          <Link key={t.key} href={`${base}?tab=${t.key}`} aria-current={t.key === tab ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 ${t.key === tab ? "border-sky-700 font-medium text-sky-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>{t.label}</Link>
        ))}
      </nav>

      {tab === "calendario" && (
        <Card title={`28 días desde ${date(a.calendar_from)}`} actions={
          <span className="flex gap-2"><Link href={`${base}?tab=calendario&from=${addDays(a.calendar_from, -28)}`} className={btnGhostCls}>←</Link>
            <Link href={`${base}?tab=calendario&from=${addDays(a.calendar_from, 28)}`} className={btnGhostCls}>→</Link></span>}>
          {a.calendar.length === 0 ? <p className="text-sm text-slate-500">Sin fechas de vigencia en este rango.</p> : (
            <div className="grid grid-cols-4 gap-2 sm:grid-cols-7">
              {a.calendar.map((c) => (
                <div key={c.date}>
                  <p className="mb-0.5 text-center text-[11px] text-slate-500">{c.date.slice(8, 10)}/{c.date.slice(5, 7)}</p>
                  <CellBox cell={c} unit={a.unit} href={href({ cell: `${a.code}:${c.date}` })} />
                </div>
              ))}
            </div>
          )}
        </Card>
      )}
      {tab === "condiciones" && (
        <Card title="Condiciones del contrato">
          <dl className="grid gap-3 text-sm sm:grid-cols-2">
            {[["Tipo", `${a.kind_label} (${a.unit})`], ["Proveedor", `${a.provider.name} · ${a.provider.ref}`], ["Servicio", `${a.service.name} · ${a.service.ref}`],
              ["Referencia", a.contract_ref ?? "—"], ["Vigencia", `${date(a.valid_from)} – ${date(a.valid_to)}`], ["Bloqueo base", `${a.base_qty} ${a.unit}`],
              ["Release", `${a.release_days} días antes del servicio`], ["Tarifa neta", money(a.net_rate, a.currency)], ["Penalidad por no-uso", `${Number(a.penalty_pct)} % de la tarifa neta`],
              ["Responsable", a.owner ?? "—"], ["Notas", a.notes ?? "—"]].map(([k, v]) => (
              <div key={k}><dt className="text-xs uppercase tracking-wide text-slate-500">{k}</dt><dd className="text-slate-900">{v}</dd></div>
            ))}
          </dl>
        </Card>
      )}
      {tab === "consumo" && (
        <Card title="Reservas que consumen el cupo">
          <Table head={["Fecha", "Reserva", "Servicio", "Unidades", "Estado"]} empty="Sin consumo registrado.">
            {a.consumptions.map((c) => (
              <tr key={`${c.date}-${c.service}`}>
                <Td>{date(c.date)}</Td>
                <Td><Link href={`/reservas/${c.reservation}`} className="font-medium text-sky-800 hover:underline">{c.reservation}</Link><span className="block text-xs text-slate-500">{c.reservation_title}</span></Td>
                <Td>{c.service}</Td><Td>{c.qty}</Td>
                <Td><Pill tone={c.state === "consumido" ? "green" : c.state === "en_tramite" ? "amber" : "slate"}>{c.state === "en_tramite" ? "En trámite" : c.state === "consumido" ? "Consumido" : "Revertido"}</Pill></Td>
              </tr>
            ))}
          </Table>
        </Card>
      )}
      {tab === "ampliaciones" && (
        <Card title="Ampliaciones solicitadas al proveedor">
          <Table head={["Código", "Rango", "Adicional", "Motivo", "Estado", "Respuesta"]} empty="Sin ampliaciones.">
            {a.requests.map((r) => (
              <tr key={r.id}>
                <Td><span className="font-medium">{r.code}</span><span className="block text-xs text-slate-500">{r.requester ?? "—"} · {dateTime(r.created_at)}</span></Td>
                <Td>{date(r.date_from)} – {date(r.date_to)}</Td>
                <Td>+{r.extra_qty} {a.unit}</Td>
                <Td className="min-w-40 text-xs">{r.note}</Td>
                <Td><Pill tone={r.status === "aceptada" ? "green" : r.status === "rechazada" ? "rose" : "amber"}>{r.status_label}</Pill></Td>
                <Td className="min-w-56">
                  {r.status === "solicitada" ? (
                    <ActionForm path={`/catalog/allotments/${a.code}/requests/${r.code}/respond`} submitLabel="Registrar respuesta" disabled={!a.can_manage} disabledReason={NEED} success="Respuesta registrada" className="space-y-2">
                      <select name="accepted" aria-label={`Respuesta del proveedor ${r.code}`} disabled={!a.can_manage} className="w-full rounded-md border border-slate-300 px-2 py-1 text-sm">
                        <option value="true">Aceptada</option><option value="false">Rechazada</option>
                      </select>
                      <input name="response" required maxLength={500} aria-label={`Detalle de la respuesta ${r.code}`} placeholder="Detalle de la respuesta" disabled={!a.can_manage}
                        className="w-full rounded-md border border-slate-300 px-2 py-1 text-sm" />
                    </ActionForm>
                  ) : <span className="text-xs">{r.response}<span className="block text-slate-500">{r.responder ?? ""} · {dateTime(r.responded_at)}</span></span>}
                </Td>
              </tr>
            ))}
          </Table>
        </Card>
      )}
      {tab === "penalidades" && (
        <Card title="Cargos por no-uso">
          <Table head={["Fecha", "Unidades", "Monto", "Motivo", "Cuenta por pagar"]} empty="Sin penalidades devengadas.">
            {a.charges.map((c) => (
              <tr key={c.date}>
                <Td>{date(c.date)}</Td><Td>{c.qty} {a.unit}</Td><Td>{money(c.amount, c.currency)}</Td><Td className="min-w-48 text-xs">{c.reason}</Td>
                <Td>{c.payable ? <span>{c.payable} <Pill tone="slate">{c.payable_status}</Pill></span> : "Sin CxP (0 %)"}</Td>
              </tr>
            ))}
          </Table>
        </Card>
      )}
      {tab === "historial" && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card title="Bitácora del contrato">
            <ol className="space-y-2 text-sm">{a.events.map((e, i) => (
              <li key={i} className="border-l-2 border-slate-200 pl-3"><p className="text-slate-800">{e.message}</p><p className="text-xs text-slate-500">{e.user} · {dateTime(e.at)}</p></li>
            ))}</ol>
          </Card>
          <Card title="Auditoría">
            <ol className="space-y-2 text-sm">{a.audit.map((e, i) => (
              <li key={i} className="border-l-2 border-slate-200 pl-3"><p className="font-medium text-slate-800">{e.action.replaceAll("_", " ")}</p>
                <p className="text-slate-600">{e.before ?? "—"} → {e.after ?? "—"}</p><p className="text-xs text-slate-500">{e.actor} · {dateTime(e.at)}</p></li>
            ))}</ol>
          </Card>
        </div>
      )}

      {sp.cell && <CellDrawer spec={sp.cell} close={close} />}
      {sp.accion === "ampliar" && a.can_manage && (
        <Drawer title="Solicitar ampliación al proveedor" close={close}>
          <ActionForm path={`/catalog/allotments/${a.code}/requests`} idempotent redirectTo={`${base}?tab=ampliaciones`} success="Ampliación enviada" submitLabel="Enviar solicitud">
            <div className="grid grid-cols-2 gap-3">
              <Input label="Desde" name="date_from" type="date" required min={addDays(new Date().toISOString().slice(0, 10), 1)} max={a.valid_to} />
              <Input label="Hasta" name="date_to" type="date" required max={a.valid_to} />
            </div>
            <Input label={`Unidades adicionales (${a.unit})`} name="extra_qty" type="number" min={1} max={999} required />
            <TextArea label="Motivo para el proveedor" name="note" required maxLength={2000} />
            <p className="text-xs text-slate-500">Debe ser futura y dentro de la vigencia ({date(a.valid_from)} – {date(a.valid_to)}). Se notifica al proveedor por correo.</p>
          </ActionForm>
        </Drawer>
      )}
      {options && (
        <Drawer title={`Editar ${a.code}`} close={close}>
          <AllotmentForm options={options} close={close} current={a} />
        </Drawer>
      )}
      {sp.accion === "liberar" && a.can_manage && (
        <Drawer title={`Liberar contrato ${a.code}`} close={close}>
          <Notice tone="warning">Se libera todo lo disponible de las fechas futuras, se borran las retenciones y el contrato deja de aceptar consumo nuevo. Se notifica al proveedor.</Notice>
          <ActionForm path={`/catalog/allotments/${a.code}/release`} idempotent redirectTo={base} success="Contrato liberado" submitLabel="Liberar contrato" submitClass={rejectCls}
            confirm={`¿Liberar todo el contrato ${a.code}?`}>
            <TextArea label="Motivo" name="reason" required maxLength={2000} />
          </ActionForm>
        </Drawer>
      )}
    </section>
  );
}
