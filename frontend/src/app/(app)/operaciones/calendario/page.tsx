import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import { addDays, KIND_LABEL, limaToday, MONTHS, weekday, WEEKDAYS, type CalendarEvent } from "@/lib/ops";
import { btnCls, btnGhostCls, Drawer, inputCls, Kpis, PageHeader, Table, Td } from "@/components/crm/ui";
import { Field } from "@/components/catalog/ui";
import { LockedButton } from "@/components/quotes/ui";
import { OpsNav, OpsStatus } from "@/components/ops/ui";

type Search = { vista?: string; mes?: string; semana?: string; tipo?: string; ev?: string };
const VIEWS = [{ key: "mes", label: "Mes" }, { key: "semana", label: "Semana" }, { key: "lista", label: "Lista" }];

/** Calendario operativo (spec 05 §2): servicios de reservas no canceladas y salidas programadas, por mes, semana o lista. */
export default async function CalendarPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const today = limaToday();
  const view = VIEWS.some((v) => v.key === sp.vista) ? sp.vista! : "mes";
  const month = /^\d{4}-\d{2}$/.test(sp.mes ?? "") ? sp.mes! : today.slice(0, 7);
  const weekStart = /^\d{4}-\d{2}-\d{2}$/.test(sp.semana ?? "") ? addDays(sp.semana!, -weekday(sp.semana!)) : addDays(today, -weekday(today));

  const monthStart = `${month}-01`;
  const [y, m] = month.split("-").map(Number);
  const nextMonth = m === 12 ? `${y + 1}-01-01` : `${y}-${String(m + 1).padStart(2, "0")}-01`;
  const monthEnd = addDays(nextMonth, -1);
  const [from, to] = view === "semana" ? [weekStart, addDays(weekStart, 6)] : [monthStart, monthEnd];

  const { data: events, meta } = await api<{ data: CalendarEvent[]; meta: { can_assign: boolean } }>(`/ops/calendar${qs({ from, to, "filter[kind]": sp.tipo })}`);
  const canAssign = meta.can_assign;
  const selected = sp.ev ? events.find((e) => e.id === sp.ev) : undefined;
  const link = (over: Partial<Search>) => `/operaciones/calendario${qs({ vista: view, mes: view === "mes" ? month : undefined, semana: view === "semana" ? weekStart : undefined, tipo: sp.tipo, ...over })}`;
  const close = link({});
  const byDay = Map.groupBy(events, (e) => e.date);
  const services = events.filter((e) => e.type === "service");

  const prev = view === "semana" ? link({ semana: addDays(weekStart, -7) }) : link({ mes: addDays(monthStart, -1).slice(0, 7) });
  const next = view === "semana" ? link({ semana: addDays(weekStart, 7) }) : link({ mes: nextMonth.slice(0, 7) });
  const title = view === "semana" ? `Semana del ${date(weekStart)} al ${date(addDays(weekStart, 6))}` : `${MONTHS[m - 1]} ${y}`;

  const chip = (e: CalendarEvent) => (
    <Link key={e.type + e.id} href={link({ ev: e.id })} scroll={false}
      className={`block truncate rounded px-1.5 py-0.5 text-xs ring-1 ${e.pending ? "bg-amber-50 text-amber-900 ring-amber-200" : e.type === "departure" ? "bg-violet-50 text-violet-800 ring-violet-200" : "bg-sky-50 text-sky-900 ring-sky-200"}`}
      title={`${e.time ?? ""} ${e.title}${e.pending ? " · pendiente" : ""}`}>
      {e.pending && "● "}{e.time && <span className="font-medium">{e.time} </span>}{e.title}
    </Link>
  );

  const grid = (start: string, days: number, compact: boolean) => (
    <div className="grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 text-sm">
      {WEEKDAYS.map((d) => <div key={d} className="bg-slate-50 px-2 py-1 text-xs font-medium text-slate-500">{d}</div>)}
      {Array.from({ length: days }, (_, i) => addDays(start, i)).map((d) => {
        const out = view === "mes" && d.slice(0, 7) !== month;
        const items = byDay.get(d) ?? [];
        return (
          <div key={d} className={`${compact ? "min-h-24" : "min-h-64"} space-y-1 bg-white p-1.5 ${out ? "opacity-40" : ""}`} aria-label={d}>
            <p className={`text-xs ${d === today ? "inline-block rounded-full bg-sky-700 px-1.5 font-semibold text-white" : "text-slate-500"}`}>{Number(d.slice(8))}</p>
            {(compact ? items.slice(0, 3) : items).map(chip)}
            {compact && items.length > 3 && <Link href={link({ vista: "semana", semana: d })} className="block text-xs text-sky-800 hover:underline">+{items.length - 3} más</Link>}
          </div>
        );
      })}
    </div>
  );

  const gridStart = addDays(monthStart, -weekday(monthStart));
  const gridDays = Math.ceil((weekday(monthStart) + Number(monthEnd.slice(8))) / 7) * 7;

  return (
    <>
      <PageHeader title="Calendario operativo" subtitle="Servicios de reservas activas y salidas programadas" />
      <OpsNav active="/operaciones/calendario" />
      <Kpis items={[
        { label: view === "semana" ? "Servicios de la semana" : "Servicios del mes", value: services.length },
        { label: "Pendientes (proveedor o recursos)", value: events.filter((e) => e.pending).length },
        { label: "Salidas programadas", value: events.filter((e) => e.type === "departure").length },
        { label: "Hoy", value: (byDay.get(today) ?? []).length },
      ]} />
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <Link href={prev} className={btnGhostCls} aria-label="Anterior">←</Link>
          <h2 className="min-w-48 text-center font-semibold capitalize text-slate-900">{title}</h2>
          <Link href={next} className={btnGhostCls} aria-label="Siguiente">→</Link>
          <Link href={`/operaciones/calendario${qs({ vista: view, tipo: sp.tipo })}`} className="text-sm text-sky-800 hover:underline">Hoy</Link>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <nav className="flex rounded-md border border-slate-300 text-sm" aria-label="Vista">
            {VIEWS.map((v) => <Link key={v.key} href={`/operaciones/calendario${qs({ vista: v.key, tipo: sp.tipo })}`} aria-current={v.key === view ? "page" : undefined}
              className={`px-3 py-1.5 ${v.key === view ? "bg-sky-700 text-white" : "text-slate-700 hover:bg-slate-50"}`}>{v.label}</Link>)}
          </nav>
          <form className="flex gap-2">
            <input type="hidden" name="vista" value={view} />
            {view === "mes" && <input type="hidden" name="mes" value={month} />}
            {view === "semana" && <input type="hidden" name="semana" value={weekStart} />}
            <select name="tipo" defaultValue={sp.tipo ?? ""} aria-label="Tipo de servicio" className={`${inputCls} max-w-[12rem]`}>
              <option value="">Tipo: todos</option>
              {Object.entries(KIND_LABEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
            <button className={btnGhostCls}>Filtrar</button>
          </form>
        </div>
      </div>
      <p className="mb-2 flex flex-wrap gap-3 text-xs text-slate-500">
        <span><span className="mr-1 inline-block h-2 w-2 rounded-full bg-sky-400" />Servicio</span>
        <span><span className="mr-1 inline-block h-2 w-2 rounded-full bg-violet-400" />Salida programada</span>
        <span><span className="mr-1 inline-block h-2 w-2 rounded-full bg-amber-400" />Pendiente de proveedor o recursos</span>
      </p>

      {view === "mes" && grid(gridStart, gridDays, true)}
      {view === "semana" && grid(weekStart, 7, false)}
      {view === "lista" && (
        <Table head={["Fecha", "Servicio", "Tipo", "Orden", "Proveedor", "Recursos", "Estado"]} empty="Sin servicios en el mes">
          {events.map((e) => (
            <tr key={e.type + e.id}>
              <Td>{date(e.date)}<span className="block text-xs text-slate-500">{e.time ?? "Sin hora"}</span></Td>
              <Td><Link href={link({ ev: e.id })} scroll={false} className="font-medium text-sky-800 hover:underline">{e.title}</Link>{e.pending && <span className="block text-xs text-amber-800">Pendiente</span>}</Td>
              <Td>{KIND_LABEL[e.kind] ?? e.kind}</Td>
              <Td>{e.order ? <Link href={`/operaciones/ordenes/${e.order.code}`} className="text-sky-800 hover:underline">{e.order.code}</Link> : "—"}</Td>
              <Td>{e.provider ?? "—"}</Td>
              <Td>{e.resources.join(", ") || "—"}</Td>
              <Td><OpsStatus value={e.status} label={e.status_label} /></Td>
            </tr>
          ))}
        </Table>
      )}

      {selected && (
        <Drawer title={selected.title} close={close}>
          <dl className="grid grid-cols-2 gap-3">
            <Field label="Fecha">{date(selected.date)} {selected.time ?? ""}</Field>
            <Field label="Tipo">{KIND_LABEL[selected.kind] ?? selected.kind}</Field>
            <Field label="Estado"><OpsStatus value={selected.status} label={selected.status_label} /></Field>
            <Field label="Proveedor">{selected.provider ?? "—"}</Field>
            <Field label="Recursos">{selected.resources.join(", ") || "Sin recursos"}</Field>
            <Field label="Orden">{selected.order ? <Link href={`/operaciones/ordenes/${selected.order.code}`} className="text-sky-800 hover:underline">{selected.order.code}</Link> : "Salida del catálogo"}</Field>
          </dl>
          {selected.pending && <p className="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">Falta confirmación del proveedor o asignar recursos.</p>}
          <div className="mt-4 flex flex-wrap gap-2">
            {selected.order && <Link href={`/operaciones/ordenes/${selected.order.code}`} className={btnGhostCls}>Abrir orden</Link>}
            {selected.assignment_id && selected.order && (canAssign
              ? <Link href={`/operaciones/ordenes/${selected.order.code}?accion=assign&a=${selected.assignment_id}`} className={btnCls}>Asignar recursos</Link>
              : <LockedButton label="Asignar recursos" reason="Requiere permiso operations.assign" />)}
            {selected.type === "departure" && <Link href={`/catalogo/salidas`} className={btnGhostCls}>Ver salidas del catálogo</Link>}
          </div>
        </Drawer>
      )}
    </>
  );
}
