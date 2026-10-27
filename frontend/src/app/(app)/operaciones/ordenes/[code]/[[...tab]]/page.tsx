import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { price } from "@/lib/catalog";
import { KIND_LABEL, ORDER_FLOW, ORDER_FLOW_LABELS, type OrderDetail, type OrderService } from "@/lib/ops";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnGhostCls, Card, Notice, PageHeader, Stepper, Table, Td } from "@/components/crm/ui";
import { Field, Hidden, PathTabs } from "@/components/catalog/ui";
import { LockedButton } from "@/components/quotes/ui";
import { OpsActionLink, OpsStatus, SlaBar } from "@/components/ops/ui";
import { OrderDialog } from "@/components/ops/OrderDialogs";

type Search = Record<string, string | undefined>;
type CostSummary = { currency: string | null; cost: string; lines: { code: string; name: string; provider: string | null; date: string; status: string; cost: string }[];
  can_see_margin: boolean; sale?: string; margin?: string; margin_pct?: number | null };

const ACTION_HREF: Record<string, (code: string) => string> = {
  close: (c) => `/operaciones/ordenes/${c}/cierre`,
  cost_summary: (c) => `/operaciones/ordenes/${c}/cierre`,
};

/** Orden 360 (spec 05 §2): flujo, KPIs, servicios con proveedor y recursos, pasajeros, rooming, vouchers, incidencias, evidencias, tareas y cierre. */
export default async function OrderPage({ params, searchParams }: { params: Promise<{ code: string; tab?: string[] }>; searchParams: Promise<Search> }) {
  const [{ code, tab = [] }, sp] = await Promise.all([params, searchParams]);
  const { data: o } = await api<{ data: OrderDetail }>(`/ops/orders/${code}`);
  const tabs = [
    { key: "servicios", label: `Servicios (${o.services.length})` },
    { key: "pasajeros", label: `Pasajeros (${o.passengers.length})` },
    { key: "rooming", label: `Rooming (${o.rooming.length})` },
    { key: "vouchers", label: `Vouchers (${o.vouchers.length})` },
    { key: "incidencias", label: `Incidencias (${o.incidents.length})` },
    { key: "evidencias", label: `Evidencias (${o.evidences.length})` },
    { key: "tareas", label: `Tareas (${o.tasks.filter((t) => t.done).length}/${o.tasks.length})` },
    { key: "cierre", label: "Cierre" },
  ];
  const active = tab[0] ?? "servicios";
  if (!tabs.some((t) => t.key === active)) notFound();
  const base = `/operaciones/ordenes/${o.code}`;
  const here = active === "servicios" ? base : `${base}/${active}`;
  const flowIndex = ORDER_FLOW.indexOf(o.status as (typeof ORDER_FLOW)[number]);
  const primary = o.actions.filter((a) => a.group === "primary");
  const more = o.actions.filter((a) => a.group === "more");
  const k = o.kpis;

  return (
    <>
      <PageHeader
        title={`${o.code} · ${o.title}`}
        subtitle={<span className="flex flex-wrap items-center gap-2"><OpsStatus value={o.status} label={o.status_label} /> {o.reservation_detail?.client} · Coordina {o.coordinator?.name ?? "—"}</span>}
        actions={<Link href="/operaciones/ordenes" className="text-sm text-sky-800 hover:underline">← Órdenes</Link>}
      />
      <div className="mb-4"><Stepper steps={o.status === "cancelada" ? ["Planificada", "Cancelada"] : ORDER_FLOW_LABELS} current={o.status === "cancelada" ? 2 : flowIndex + 1} failed={o.status === "cancelada"} /></div>
      {o.status === "cancelada" && <Notice tone="danger">La reserva fue cancelada: la orden quedó cancelada, los recursos liberados y los vouchers anulados.</Notice>}
      {o.critical_open > 0 && <Notice tone="danger"><strong>Incidencia crítica abierta.</strong> <Link href={`${base}/incidencias`} className="underline">Ver incidencias</Link></Notice>}
      {o.alert_tags.length > 0 && <div className="mb-3 flex flex-wrap gap-2" aria-label="Alertas operativas">{o.alert_tags.map((t) => <span key={t} className="rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-800 ring-1 ring-violet-200">{t}</span>)}</div>}

      <div className="mb-4 flex flex-wrap items-center gap-2" aria-label="Acciones">
        {primary.map((a, i) => <OpsActionLink key={a.key} a={a} href={ACTION_HREF[a.key]?.(o.code) ?? `${here}?accion=${a.key}`} primary={i === 0} />)}
        {more.length > 0 && (
          <details className="relative">
            <summary className={`${btnGhostCls} cursor-pointer list-none`}>Más ▾</summary>
            <div className="absolute z-20 mt-1 flex w-72 flex-col gap-1 rounded-md border border-slate-200 bg-white p-2 shadow-lg">
              {more.map((a) => a.enabled
                ? <Link key={a.key} href={a.key === "view_reservation" ? `/reservas/${o.reservation?.code}` : ACTION_HREF[a.key]?.(o.code) ?? `${here}?accion=${a.key}`} className="rounded px-2 py-1 text-sm hover:bg-slate-50">{a.label}</Link>
                : <span key={a.key} title={a.reason ?? undefined} className="rounded px-2 py-1 text-sm text-slate-400">🔒 {a.label}</span>)}
            </div>
          </details>
        )}
      </div>

      <dl className="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-3 lg:grid-cols-7">
        <Field label="Reserva"><Link href={`/reservas/${o.reservation?.code}`} className="text-sky-800 hover:underline">{o.reservation?.code}</Link></Field>
        <Field label="Fechas">{date(o.date_from)}{o.date_to !== o.date_from && <> – {date(o.date_to)}</>}</Field>
        <Field label="Pax">{o.pax}</Field>
        <Field label="Proveedor confirmado">{k.supplier_confirmed}/{k.services}</Field>
        <Field label="Con recursos">{k.with_resources}/{k.assignments}</Field>
        <Field label="Vouchers vigentes">{k.vouchers_active}</Field>
        <Field label="Incidencias / evidencias">{k.incidents_open} / {k.evidences}</Field>
      </dl>

      <PathTabs base={base} tabs={tabs} active={active} />
      {active === "servicios" && <ServicesTab o={o} here={here} />}
      {active === "pasajeros" && <PassengersTab o={o} />}
      {active === "rooming" && <RoomingTab o={o} />}
      {active === "vouchers" && <VouchersTab o={o} />}
      {active === "incidencias" && <IncidentsTab o={o} here={here} />}
      {active === "evidencias" && <EvidencesTab o={o} here={here} />}
      {active === "tareas" && <TasksTab o={o} here={here} />}
      {active === "cierre" && <CloseTab o={o} here={here} />}

      {sp.accion && <OrderDialog action={sp.accion} o={o} close={here} sp={sp} />}
    </>
  );
}

function ServiceMenu({ s, here }: { s: OrderService; here: string }) {
  if (!s.actions.length) return null;
  // La clave cambia con el estado: tras aplicar una acción el menú vuelve a mostrarse cerrado.
  return (
    <details key={`${s.id}-${s.status}`} className="relative">
      <summary className="cursor-pointer list-none rounded px-2 text-slate-500 hover:bg-slate-100" aria-label={`Acciones de ${s.code}`}>…</summary>
      <div className="absolute right-0 z-20 mt-1 flex w-64 flex-col gap-1 rounded-md border border-slate-200 bg-white p-2 shadow-lg">
        {s.actions.map((a) => a.enabled
          ? <Link key={a.key} href={`${here}?accion=${a.key}&s=${s.id}`} scroll={false} className="rounded px-2 py-1 text-sm hover:bg-slate-50">{a.label}</Link>
          : <span key={a.key} title={a.reason ?? undefined} className="rounded px-2 py-1 text-sm text-slate-400">🔒 {a.label}</span>)}
      </div>
    </details>
  );
}

function ServicesTab({ o, here }: { o: OrderDetail; here: string }) {
  return (
    <Table head={["Servicio", "Fecha", "Proveedor", "Estado proveedor", "Recursos", "Voucher", ""]} empty="La reserva no tiene servicios">
      {o.services.map((s) => {
        const a = s.assignment;
        return (
          <tr key={s.id} className={s.status === "cancelado" ? "opacity-60" : undefined}>
            <Td><span className="font-medium">{s.name}</span><span className="block text-xs text-slate-500">{s.code} · {KIND_LABEL[s.kind] ?? s.kind} · costo {price(s.cost)}</span></Td>
            <Td>{date(s.date)}<span className="block text-xs text-slate-500">{s.time ?? "Sin hora"}</span></Td>
            <Td>{s.provider?.name ?? "—"}{s.provider && !s.provider.usable && <span className="block text-xs text-rose-700">Proveedor no activo</span>}</Td>
            <Td><OpsStatus value={s.status} label={s.status_label} />{s.confirmation_code && <span className="block text-xs text-slate-500">{s.confirmation_code}</span>}{s.reject_reason && <span className="block text-xs text-rose-700">{s.reject_reason}</span>}</Td>
            <Td>
              {a ? (
                <div className="space-y-1">
                  <OpsStatus value={a.status} label={a.status_label} />
                  <p className="text-xs text-slate-600">{[a.guide?.name && `Guía: ${a.guide.name}`, a.driver?.name && `Conductor: ${a.driver.name}`, a.vehicle?.name && `Vehículo: ${a.vehicle.name}`].filter(Boolean).join(" · ") || "Sin recursos"}</p>
                  {a.warnings_ack && <p className="text-xs text-amber-800" title={a.warnings_ack.items.map((w) => w.text).join("\n")}>⚠️ {a.warnings_ack.items.length} advertencia(s) asumidas por {a.warnings_ack.user}</p>}
                  {o.can.assign && !["completada", "en_curso"].includes(a.status) && ["planificada", "pendiente_asignacion", "asignada", "confirmada", "en_ejecucion"].includes(o.status) && (
                    <div className="flex flex-wrap gap-2 text-xs">
                      <Link href={`${here}?accion=assign&a=${a.id}`} scroll={false} className="font-medium text-sky-800 hover:underline">{a.status === "pendiente" ? "Asignar" : "Reasignar"}</Link>
                      {a.status === "asignada" && <Link href={`${here}?accion=confirm_assignment&a=${a.id}`} scroll={false} className="text-sky-800 hover:underline">Registrar confirmación</Link>}
                      {a.status !== "pendiente" && <Link href={`${here}?accion=release&a=${a.id}`} scroll={false} className="text-rose-700 hover:underline">Liberar</Link>}
                    </div>
                  )}
                  {!o.can.assign && a.status === "pendiente" && <LockedButton label="Asignar" reason="Requiere permiso operations.assign" />}
                </div>
              ) : s.requires_assignment ? <span className="text-xs text-slate-500">Sin asignación</span> : <span className="text-xs text-slate-400">No requiere</span>}
            </Td>
            <Td>{s.voucher ? <Link href={`/operaciones/vouchers/${s.voucher.code}`} className="text-sky-800 hover:underline">{s.voucher.code} v{s.voucher.version}</Link> : "—"}</Td>
            <Td><ServiceMenu s={s} here={here} /></Td>
          </tr>
        );
      })}
    </Table>
  );
}

function PassengersTab({ o }: { o: OrderDetail }) {
  return (
    <Table head={["#", "Pasajero", "Tipo", "Documento", "Nacionalidad", "Dieta / notas", "Contacto"]}>
      {o.passengers.map((p) => (
        <tr key={p.id}>
          <Td>{p.seq}</Td>
          <Td>{p.name}{p.lead && <span className="ml-1 text-xs text-sky-700">(titular)</span>}{p.placeholder && <span className="block text-xs text-amber-700">Datos por completar</span>}</Td>
          <Td>{p.type}{p.age !== null && ` · ${p.age} años`}</Td>
          <Td>{p.doc ?? "—"}</Td>
          <Td>{p.nationality ?? "—"}</Td>
          <Td>{p.diet ?? "—"}</Td>
          <Td>{p.phone ?? "—"}</Td>
        </tr>
      ))}
    </Table>
  );
}

function RoomingTab({ o }: { o: OrderDetail }) {
  return (
    <Card title="Rooming lists" actions={o.can.rooming
      ? <Link href={`/operaciones/rooming?accion=new&reserva=${o.reservation?.code}`} className={btnGhostCls}>Nueva rooming list</Link>
      : <LockedButton label="Nueva rooming list" reason="Requiere permiso reservations.modify" />}>
      <Table head={["Hotel", "Estadía", "Habitaciones", "Estado", "Enviada"]} empty="Sin rooming lists">
        {o.rooming.map((l) => (
          <tr key={l.id}>
            <Td><Link href={`/operaciones/rooming/${l.id}`} className="text-sky-800 hover:underline">{l.hotel?.name}</Link></Td>
            <Td>{date(l.check_in)} – {date(l.check_out)}</Td>
            <Td>{l.rooms_count}</Td>
            <Td><OpsStatus value={l.status} label={l.status_label} /></Td>
            <Td>{l.sent_at ? `${dateTime(l.sent_at)} · v${l.sent_version}` : "—"}</Td>
          </tr>
        ))}
      </Table>
    </Card>
  );
}

function VouchersTab({ o }: { o: OrderDetail }) {
  return (
    <>
      {!o.can.vouchers && <Notice>Su rol puede consultar el voucher pero no emitir, reenviar, versionar ni anular (requiere operations.vouchers).</Notice>}
      <Table head={["Voucher", "Servicio", "Fecha", "Proveedor", "Estado", "Versión"]} empty="Sin vouchers emitidos">
        {o.vouchers.map((v) => (
          <tr key={v.id}>
            <Td><Link href={`/operaciones/vouchers/${v.code}`} className="font-medium text-sky-800 hover:underline">{v.code}</Link></Td>
            <Td>{v.service_label}</Td><Td>{date(v.service_date)}</Td><Td>{v.provider?.name}</Td>
            <Td><OpsStatus value={v.status} label={v.status_label} />{v.void_reason && <span className="block text-xs text-rose-700">{v.void_reason}</span>}</Td>
            <Td>v{v.version}</Td>
          </tr>
        ))}
      </Table>
    </>
  );
}

function IncidentsTab({ o, here }: { o: OrderDetail; here: string }) {
  return (
    <Card title="Incidencias" actions={o.can.incidents ? <Link href={`${here}?accion=new_incident`} scroll={false} className={btnGhostCls}>Nueva incidencia</Link> : <LockedButton label="Nueva incidencia" reason="Requiere permiso operations.incidents" />}>
      <Table head={["Incidencia", "Tipo", "Severidad", "Estado", "Responsable", "SLA"]} empty="Sin incidencias">
        {o.incidents.map((i) => (
          <tr key={i.id}>
            <Td><Link href={`/operaciones/incidencias/${i.code}`} className="font-medium text-sky-800 hover:underline">{i.code}</Link><span className="block text-xs text-slate-500">{i.title}</span></Td>
            <Td>{i.type}</Td><Td><OpsStatus value={i.severity} label={i.severity_label} /></Td><Td><OpsStatus value={i.status} label={i.status_label} /></Td>
            <Td>{i.assignee?.name ?? "—"}</Td><Td><SlaBar pct={i.sla_pct} breached={i.sla_breached} due={i.sla_due_at} /></Td>
          </tr>
        ))}
      </Table>
    </Card>
  );
}

function EvidencesTab({ o, here }: { o: OrderDetail; here: string }) {
  return (
    <Card title="Evidencias" actions={o.can.assign ? <Link href={`${here}?accion=evidence`} scroll={false} className={btnGhostCls}>Registrar evidencia</Link> : null}>
      {o.evidences.length === 0 ? <p className="text-sm text-slate-500">La orden no tiene evidencias.</p> : (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {o.evidences.map((e) => (
            <li key={e.id} className="rounded-md border border-slate-200 p-2 text-sm">
              {e.mime_type.startsWith("image/")
                // eslint-disable-next-line @next/next/no-img-element
                ? <a href={`/bff/evidence/${e.id}`} target="_blank" rel="noreferrer"><img src={`/bff/evidence/${e.id}`} alt={e.file_name} className="mb-2 h-28 w-full rounded bg-slate-100 object-cover" /></a>
                : <a href={`/bff/evidence/${e.id}`} target="_blank" rel="noreferrer" className="mb-2 flex h-28 items-center justify-center rounded bg-slate-100 text-slate-500">PDF</a>}
              <p className="truncate font-medium" title={e.file_name}>{e.file_name}</p>
              <p className="text-xs text-slate-500">{dateTime(e.captured_at)} · {e.uploaded_by?.name}{e.offline && " · offline"} · {e.source === "app_operador" ? "app" : e.source}</p>
              {o.can.assign && <Link href={`${here}?accion=remove_evidence&e=${e.id}`} scroll={false} className="text-xs text-rose-700 hover:underline">Retirar</Link>}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

function TasksTab({ o, here }: { o: OrderDetail; here: string }) {
  return (
    <Card title="Checklist de la orden" actions={o.can.assign ? <Link href={`${here}?accion=add_task`} scroll={false} className={btnGhostCls}>Agregar tarea</Link> : null}>
      <ul className="divide-y divide-slate-100">
        {o.tasks.map((t) => (
          <li key={t.id} className="flex items-center justify-between gap-3 py-2 text-sm">
            <span className={t.done ? "text-slate-500 line-through" : ""}>{t.done ? "☑" : "☐"} {t.title}
              {t.done && <span className="ml-2 text-xs no-underline">{t.done_by?.name} · {dateTime(t.done_at)}</span>}</span>
            {o.can.assign && (
              <span className="flex gap-2">
                <ActionForm path={`/ops/order-tasks/${t.id}`} method="PATCH" inline submitLabel={t.done ? "Reabrir" : "Hecha"} submitClass="text-xs text-sky-800 hover:underline">
                  <Hidden name="done" value={!t.done} />
                </ActionForm>
                <ActionForm path={`/ops/order-tasks/${t.id}`} method="DELETE" inline submitLabel="Eliminar" confirm="¿Eliminar la tarea?" submitClass="text-xs text-rose-700 hover:underline" />
              </span>
            )}
          </li>
        ))}
      </ul>
      <p className="mt-2 text-xs text-slate-500">Las tareas pendientes no bloquean el cierre (OPS-BR-07).</p>
    </Card>
  );
}

async function CloseTab({ o, here }: { o: OrderDetail; here: string }) {
  const { data: cost } = await api<{ data: CostSummary }>(`/ops/orders/${o.code}/cost-summary`);
  const c = o.close;
  const closeAction = o.actions.find((a) => a.key === "close");
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card title="Precondiciones del cierre (PROC-039)">
        <ul className="space-y-2 text-sm">
          {c.checks.map((x) => (
            <li key={x.key} className={`rounded-md px-3 py-2 ring-1 ${x.ok ? "bg-emerald-50 text-emerald-900 ring-emerald-200" : "bg-rose-50 text-rose-900 ring-rose-200"}`}>
              {x.ok ? "✓" : "✗"} <strong>{x.label}</strong> <span className="text-xs opacity-70">({x.rule})</span><span className="block text-xs">{x.detail}</span>
            </li>
          ))}
        </ul>
        {c.pending_tasks.length > 0 && <p className="mt-3 text-sm text-amber-800">Tareas pendientes (no bloquean): {c.pending_tasks.join(", ")}</p>}
        <div className="mt-4">
          {o.status === "completada" ? <p className="text-sm text-emerald-800">Operación cerrada.</p>
            : !closeAction ? null
            : !closeAction.enabled ? <Notice tone="warning">{closeAction.reason === "Requiere permiso operations.close" ? "Su rol no tiene el permiso operations.close." : closeAction.reason}</Notice>
            : (
              <ActionForm path={`/ops/orders/${o.code}/close`} ifMatch={o.lock_version} idempotent redirectTo={here} submitLabel="Cerrar operación" disabled={!c.ready}
                disabledReason="Resuelva las precondiciones" confirm="¿Cerrar la operación? La orden y la reserva quedarán completadas.">
                <p className="text-sm text-slate-600">La orden y la reserva pasan a Completada; se notifica a Finanzas para la rentabilidad del viaje.</p>
              </ActionForm>
            )}
        </div>
      </Card>
      <Card title={cost.can_see_margin ? "Resumen económico" : "Costos de proveedores"}>
        <Table head={["Servicio", "Proveedor", "Estado", "Costo"]} foot={<tr><Td>Total costo</Td><Td>{""}</Td><Td>{""}</Td><Td>{price(cost.cost, cost.currency ?? "USD")}</Td></tr>}>
          {cost.lines.map((l) => <tr key={l.code}><Td>{l.code} · {l.name}</Td><Td>{l.provider}</Td><Td><OpsStatus value={l.status} /></Td><Td>{price(l.cost, cost.currency ?? "USD")}</Td></tr>)}
        </Table>
        {cost.can_see_margin
          ? <dl className="mt-3 grid grid-cols-3 gap-3"><Field label="Venta">{price(cost.sale, cost.currency ?? "USD")}</Field><Field label="Margen">{price(cost.margin, cost.currency ?? "USD")}</Field><Field label="Margen %">{cost.margin_pct ?? "—"}%</Field></dl>
          : <p className="mt-3 text-xs text-slate-500">Precios de venta y márgenes visibles solo para Gerencia y Finanzas.</p>}
      </Card>
    </div>
  );
}
