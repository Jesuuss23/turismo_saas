import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, qs, type Paged } from "@/lib/crm";
import { price } from "@/lib/catalog";
import type { ResMeta, ReservationRow } from "@/lib/reservations";
import { btnCls, btnGhostCls, Card, inputCls, Kpis, Notice, PageHeader, ScopeChip, Table, Td } from "@/components/crm/ui";
import { ExportButton } from "@/components/crm/ExportButton";
import { Meter } from "@/components/catalog/ui";
import { ResStatus } from "@/components/reservations/ui";

type Search = { view?: string; q?: string; status?: string; channel?: string; owner?: string; cursor?: string; month?: string };
type ResKpis = { confirmed: number; in_operation: number; pending: number; traveling_today: number };
type Occupancy = {
  month: string; days: { date: string; pax: number; reservations: number; codes: { code: string; client: string | null; status: string }[] }[];
  next_days: { date: string; pax: number }[];
  departures: { ref: string; product: string | null; date: string; time: string; capacity: number; sold: number; available: number; status: string }[];
};

/** Reservas (spec 04 §2): lista con KPIs y filtros, u ocupación por fecha. Operaciones ve costos en lugar de total y saldo. */
export default async function ReservationsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const view = sp.view === "occupancy" ? "occupancy" : "list";
  const filters = { "filter[q]": sp.q, "filter[status]": sp.status, "filter[channel]": sp.channel, "filter[owner]": sp.owner };
  const [{ data: meta }, kpis] = await Promise.all([
    api<{ data: ResMeta }>("/reservations/meta"),
    api<{ data: ResKpis }>(`/reservations/kpis${qs({ "filter[owner]": sp.owner })}`),
  ]);
  const ownerName = meta.owners.find((o) => o.id === sp.owner)?.name;
  const k = kpis.data;

  return (
    <>
      <PageHeader
        title="Reservas"
        subtitle={<ScopeChip ownOnly={meta.own_only} ownerName={ownerName} />}
        actions={<>
          <ExportButton resource="reservations.reservations" filters={{ status: sp.status, channel: sp.channel, owner: sp.owner }} />
          {meta.can.create
            ? <Link href="/reservas/nueva" className={btnCls}>Nueva reserva directa</Link>
            : !meta.view.ops_view && <button disabled title="Requiere permiso reservations.create" className={btnCls}>Nueva reserva directa</button>}
        </>}
      />
      <Kpis items={[
        { label: "Confirmadas", value: k.confirmed },
        { label: "En operación", value: k.in_operation },
        { label: "Pendientes pago / proveedor", value: k.pending },
        { label: "Pasajeros en viaje hoy", value: k.traveling_today },
      ]} />
      {meta.view.ops_view && <Notice>Vista de Operaciones: ve costos de proveedores y estado de servicios; los importes de venta y saldos no se muestran.</Notice>}

      <nav className="mb-4 flex gap-1 border-b border-slate-200 text-sm" aria-label="Vista">
        {[["list", "Lista"], ["occupancy", "Ocupación por fecha"]].map(([key, label]) => (
          <Link key={key} href={`/reservas${qs({ view: key === "list" ? undefined : key, owner: sp.owner })}`} aria-current={view === key ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 ${view === key ? "border-sky-700 font-medium text-sky-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>{label}</Link>
        ))}
      </nav>

      {view === "list" ? <ListView sp={sp} meta={meta} filters={filters} /> : <OccupancyView sp={sp} />}
    </>
  );
}

async function ListView({ sp, meta, filters }: { sp: Search; meta: ResMeta; filters: Record<string, string | undefined> }) {
  const list = await api<Paged<ReservationRow, { own_only: boolean; view: ResMeta["view"] }>>(`/reservations${qs({ ...filters, cursor: sp.cursor })}`);
  const ops = list.meta.view.ops_view;
  return (
    <>
      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <input name="q" defaultValue={sp.q} placeholder="Buscar por código, título o cliente" aria-label="Buscar" className={`${inputCls} max-w-sm`} />
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[13rem]`}>
          <option value="">Estado: todos</option>
          <option value="pendientes">Pendientes pago / proveedor</option>
          {Object.entries(meta.statuses).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select name="channel" defaultValue={sp.channel ?? ""} aria-label="Canal" className={`${inputCls} max-w-[11rem]`}>
          <option value="">Canal: todos</option>
          {meta.channels.map((c) => <option key={c}>{c}</option>)}
        </select>
        {!meta.own_only && (
          <select name="owner" defaultValue={sp.owner ?? ""} aria-label="Asesor" className={`${inputCls} max-w-[14rem]`}>
            <option value="">Asesor: todos</option>
            {meta.owners.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
          </select>
        )}
        <button className={btnGhostCls}>Filtrar</button>
      </form>

      <Table head={["Reserva", "Cliente", ...(meta.own_only ? [] : ["Asesor"]), "Estado", "Canal", "Viaje", "Pax",
        ...(ops ? ["Servicios conf.", "Costo proveedores"] : ["Total", "Saldo"]), ...(meta.view.see_margin ? ["Margen"] : [])]}
        empty="No hay reservas con estos filtros">
        {list.data.map((r) => (
          <tr key={r.id}>
            <Td><Link href={`/reservas/${r.code}`} className="font-medium text-sky-800 hover:underline">{r.code}</Link><br />{r.title}
              {r.quote && <span className="block text-xs text-slate-500">{r.quote.code} v{r.quote.version_no}</span>}</Td>
            <Td>{r.client?.name}</Td>
            {!meta.own_only && <Td>{r.owner?.name}</Td>}
            <Td><ResStatus value={r.status} label={r.status_label} /></Td>
            <Td>{r.channel}</Td>
            <Td>{date(r.travel_from)}{r.travel_to !== r.travel_from && <> – {date(r.travel_to)}</>}</Td>
            <Td>{r.pax}</Td>
            {ops
              ? <><Td>{r.services_confirmed}/{r.services_total}</Td><Td>{price(r.provider_cost, r.currency)}</Td></>
              : <><Td>{price(r.total, r.currency)}</Td><Td className={Number(r.balance) > 0 ? "text-amber-800" : "text-emerald-700"}>{price(r.balance, r.currency)}</Td></>}
            {meta.view.see_margin && <Td>{r.margin_pct ?? "—"} %</Td>}
          </tr>
        ))}
      </Table>
      {list.meta.next_cursor && (
        <div className="mt-3 text-right"><Link href={`/reservas${qs({ ...sp, cursor: list.meta.next_cursor })}`} className={btnGhostCls}>Siguiente página</Link></div>
      )}
    </>
  );
}

const WEEKDAYS = ["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"];

function shiftMonth(month: string, delta: number) {
  const [y, m] = month.split("-").map(Number);
  const d = new Date(Date.UTC(y, m - 1 + delta, 1));
  return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, "0")}`;
}

async function OccupancyView({ sp }: { sp: Search }) {
  const { data: o } = await api<{ data: Occupancy }>(`/reservations/occupancy${qs({ month: sp.month, "filter[owner]": sp.owner })}`);
  const first = new Date(`${o.month}-01T00:00:00Z`).getUTCDay();
  const pad = (first + 6) % 7;
  const maxNext = Math.max(1, ...o.next_days.map((d) => d.pax));
  const monthLabel = new Date(`${o.month}-01T12:00:00Z`).toLocaleDateString("es-PE", { month: "long", year: "numeric", timeZone: "UTC" });

  return (
    <div className="grid gap-4 xl:grid-cols-[1fr_360px]">
      <Card title={<span className="capitalize">{monthLabel}</span>} actions={
        <span className="flex gap-2 text-sm">
          <Link href={`/reservas${qs({ view: "occupancy", month: shiftMonth(o.month, -1), owner: sp.owner })}`} className="text-sky-800 hover:underline">← Anterior</Link>
          <Link href={`/reservas${qs({ view: "occupancy", month: shiftMonth(o.month, 1), owner: sp.owner })}`} className="text-sky-800 hover:underline">Siguiente →</Link>
        </span>}>
        <div className="grid grid-cols-7 gap-1 text-xs" aria-label="Calendario de ocupación">
          {WEEKDAYS.map((d) => <div key={d} className="px-1 py-1 font-medium text-slate-500">{d}</div>)}
          {Array.from({ length: pad }, (_, i) => <div key={`p${i}`} />)}
          {o.days.map((d) => (
            <div key={d.date} className={`min-h-20 rounded border p-1 ${d.pax ? "border-sky-200 bg-sky-50" : "border-slate-100"}`}>
              <div className="flex justify-between"><span className="font-medium text-slate-700">{Number(d.date.slice(8))}</span>{d.pax > 0 && <span className="text-sky-800">{d.pax} pax</span>}</div>
              {d.codes.map((c) => <Link key={c.code} href={`/reservas/${c.code}`} className="block truncate text-[11px] text-sky-800 hover:underline" title={c.client ?? ""}>{c.code.slice(-6)} · {c.client}</Link>)}
              {d.reservations > d.codes.length && <span className="text-[11px] text-slate-500">+{d.reservations - d.codes.length} más</span>}
            </div>
          ))}
        </div>
      </Card>
      <div className="space-y-4">
        <Card title="Pasajeros próximos 14 días">
          <ul className="space-y-1.5 text-sm">
            {o.next_days.map((d) => (
              <li key={d.date} className="grid grid-cols-[5.5rem_1fr_2.5rem] items-center gap-2"><span className="text-slate-600">{date(d.date)}</span><Meter value={d.pax} max={maxNext} /><span className="text-right">{d.pax}</span></li>
            ))}
          </ul>
        </Card>
        <Card title="Salidas con cupos (14 días)">
          {o.departures.length === 0 ? <p className="text-sm text-slate-500">Sin salidas programadas.</p> : (
            <ul className="space-y-2 text-sm">
              {o.departures.map((d) => (
                <li key={d.ref}>
                  <div className="flex justify-between"><span><strong>{d.ref}</strong> · {date(d.date)} {d.time}</span><span>{d.sold}/{d.capacity}</span></div>
                  <span className="block text-xs text-slate-500">{d.product} · {d.available} libre(s)</span>
                  <Meter value={d.sold} max={d.capacity} tone={d.available === 0 ? "rose" : d.available <= 3 ? "amber" : "sky"} />
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  );
}
