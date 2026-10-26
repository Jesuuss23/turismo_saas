import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { catalogMeta } from "@/lib/catalog-server";
import { today, type Departure, type Hold } from "@/lib/catalog";
import { btnGhostCls, Card, Drawer, Input, inputCls, Kpis, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { Meter, PathTabs, Status } from "@/components/catalog/ui";
import { DepartureForm } from "@/components/catalog/DepartureForm";

type DepMeta = { kpis: { future: number; capacity: number; sold: number; held: number; available: number }; pending_expired_holds: number; last_expiry_run: { at: string; result: string } | null; hold_minutes: number };
type Search = { product?: string; status?: string; period?: string; month?: string; departure?: string };

const TABS = [{ key: "tabla", label: "Programación" }, { key: "calendario", label: "Calendario" }, { key: "holds", label: "Holds" }];
const small = "rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50";

export default async function DeparturesPage({ params, searchParams }: { params: Promise<{ tab?: string[] }>; searchParams: Promise<Search> }) {
  const [{ tab }, sp] = await Promise.all([params, searchParams]);
  const active = TABS.some((t) => t.key === tab?.[0]) ? tab![0] : "tabla";
  const month = sp.month ?? today().slice(0, 7);
  const meta = await catalogMeta();
  const can = meta.can.availability;
  const list = await api<{ data: Departure[]; meta: DepMeta }>(`/catalog/departures${qs({
    "filter[product]": sp.product, "filter[status]": sp.status, "filter[period]": active === "tabla" ? (sp.period ?? "future") : undefined, month: active === "calendario" ? month : undefined,
  })}`);
  const m = list.meta;
  const detail = sp.departure
    ? await api<{ data: Departure }>(`/catalog/departures/${encodeURIComponent(sp.departure)}`).then((r) => r.data, (e) => { if (e instanceof ApiError && e.status === 404) return null; throw e; })
    : null;

  return (
    <>
      <PageHeader title="Salidas y disponibilidad" subtitle={`Holds de ${m.hold_minutes} minutos por defecto · PROC-020`} />
      <Kpis items={[
        { label: "Salidas futuras", value: m.kpis.future },
        { label: "Capacidad", value: m.kpis.capacity },
        { label: "Vendidos · en hold", value: `${m.kpis.sold} · ${m.kpis.held}` },
        { label: "Disponibles", value: m.kpis.available },
      ]} />
      {m.pending_expired_holds > 0 && (
        <Notice tone="warning">
          Hay {m.pending_expired_holds} hold(s) vencido(s) sin procesar (ya no cuentan en la disponibilidad).
          {m.last_expiry_run && ` Última ejecución: ${dateTime(m.last_expiry_run.at)} · ${m.last_expiry_run.result}.`}
        </Notice>
      )}
      {can && (
        <div className="mb-4 flex flex-wrap gap-2">
          <ActionForm path="/catalog/holds/expire" submitLabel="Expirar holds vencidos" submitClass={btnGhostCls} success="Proceso ejecutado" inline />
        </div>
      )}

      <PathTabs base="/catalogo/salidas" tabs={TABS} active={active} />

      {active === "tabla" && (
        <>
          <form className="mb-3 grid gap-2 sm:grid-cols-4" role="search">
            <select name="product" defaultValue={sp.product ?? ""} className={inputCls} aria-label="Producto">
              <option value="">Todos los productos</option>
              {meta.products.map((p) => <option key={p.id} value={p.ref}>{p.ref} · {p.name}</option>)}
            </select>
            <select name="status" defaultValue={sp.status ?? ""} className={inputCls} aria-label="Estado">
              <option value="">Todos los estados</option>
              {["abierta", "completa", "cerrada", "cancelada"].map((s) => <option key={s}>{s}</option>)}
            </select>
            <select name="period" defaultValue={sp.period ?? "future"} className={inputCls} aria-label="Periodo">
              <option value="future">Futuras</option><option value="past">Pasadas</option><option value="all">Todas</option>
            </select>
            <button className={btnGhostCls}>Filtrar</button>
          </form>
          <Table head={["Salida", "Producto", "Fecha y hora", "Capacidad", "Vendidos", "Hold", "Disponibles", "Ocupación", "Estado"]} empty="Sin salidas">
            {list.data.map((d) => (
              <tr key={d.id} className="hover:bg-slate-50">
                <Td><Link href={`/catalogo/salidas${qs({ ...sp, departure: d.id })}`} scroll={false} className="font-medium text-sky-800 hover:underline">{d.ref}</Link></Td>
                <Td>{d.product && <Link href={`/catalogo/productos/${d.product.ref}/salidas`} className="hover:underline">{d.product.ref} · {d.product.name}</Link>}</Td>
                <Td className="whitespace-nowrap">{date(d.departure_date)} {d.departure_time}</Td>
                <Td>{d.capacity}</Td><Td>{d.sold}</Td><Td>{d.held}</Td><Td className="font-medium">{d.available}</Td>
                <Td className="w-32"><Meter value={d.sold + d.held} max={d.capacity} tone={d.occupancy_pct >= 90 ? "rose" : d.occupancy_pct >= 70 ? "amber" : "sky"} /><span className="text-xs">{d.occupancy_pct} %</span></Td>
                <Td><Status value={d.status} /></Td>
              </tr>
            ))}
          </Table>
          {can && <div className="mt-4"><DepartureForm path="/catalog/departures" meta={meta} productSelect /></div>}
        </>
      )}

      {active === "calendario" && <Calendar month={month} rows={list.data} search={sp} />}
      {active === "holds" && <Holds can={can} />}

      {detail && (
        <Drawer title={`${detail.ref} · ${detail.product?.name ?? ""}`} close={`/catalogo/salidas${active === "tabla" ? "" : `/${active}`}${qs({ ...sp, departure: undefined })}`}>
          <dl className="mb-4 grid grid-cols-3 gap-3 text-sm">
            <div><dt className="text-xs text-slate-500">Fecha</dt><dd>{date(detail.departure_date)} {detail.departure_time}</dd></div>
            <div><dt className="text-xs text-slate-500">Ocupación</dt><dd>{detail.sold} + {detail.held} / {detail.capacity}</dd></div>
            <div><dt className="text-xs text-slate-500">Disponibles</dt><dd className="font-semibold">{detail.available}</dd></div>
            <div className="col-span-3"><dt className="text-xs text-slate-500">Punto de encuentro</dt><dd>{detail.meeting_point}</dd></div>
          </dl>
          {can && (
            <div className="space-y-3">
              <Card title="Nuevo hold">
                <ActionForm path="/catalog/holds" idempotent submitLabel="Retener cupos" success="Cupos retenidos" className="grid gap-2 sm:grid-cols-3">
                  <input type="hidden" name="departure_id" value={detail.id} />
                  <Input label="Cupos" name="quantity" type="number" min={1} defaultValue={2} required />
                  <Input label="Minutos" name="minutes" type="number" min={5} defaultValue={m.hold_minutes} />
                  <Input label="Referencia" name="reference" placeholder="COT-…" required />
                </ActionForm>
              </Card>
              <Card title="Confirmar cupos directamente">
                <ActionForm path={`/catalog/departures/${detail.id}/confirm-seats`} idempotent submitLabel="Confirmar venta" className="grid gap-2 sm:grid-cols-2">
                  <Input label="Cupos" name="quantity" type="number" min={1} defaultValue={1} required />
                  <Input label="Referencia de venta" name="reference" placeholder="RES-…" required />
                </ActionForm>
              </Card>
            </div>
          )}
          <h3 className="mb-2 mt-4 text-sm font-semibold">Holds de la salida</h3>
          <HoldRows holds={detail.holds ?? []} can={can} />
        </Drawer>
      )}
    </>
  );
}

function Calendar({ month, rows, search }: { month: string; rows: Departure[]; search: Search }) {
  const [y, mo] = month.split("-").map(Number);
  const first = new Date(Date.UTC(y, mo - 1, 1));
  const days = new Date(Date.UTC(y, mo, 0)).getUTCDate();
  const offset = (first.getUTCDay() + 6) % 7;
  const shift = (n: number) => { const d = new Date(Date.UTC(y, mo - 1 + n, 1)); return d.toISOString().slice(0, 7); };
  const byDay = new Map<number, Departure[]>();
  rows.forEach((d) => { const n = Number(d.departure_date.slice(8, 10)); byDay.set(n, [...(byDay.get(n) ?? []), d]); });
  const label = new Intl.DateTimeFormat("es-PE", { month: "long", year: "numeric", timeZone: "UTC" }).format(first);
  return (
    <div>
      <div className="mb-3 flex items-center gap-3">
        <Link href={`/catalogo/salidas/calendario?month=${shift(-1)}`} className={btnGhostCls}>←</Link>
        <h2 className="text-lg font-semibold capitalize">{label}</h2>
        <Link href={`/catalogo/salidas/calendario?month=${shift(1)}`} className={btnGhostCls}>→</Link>
      </div>
      <div className="grid grid-cols-7 gap-1 text-xs">
        {["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"].map((d) => <div key={d} className="px-1 font-medium text-slate-500">{d}</div>)}
        {Array.from({ length: offset }).map((_, i) => <div key={`o${i}`} />)}
        {Array.from({ length: days }).map((_, i) => (
          <div key={i} className="min-h-20 rounded border border-slate-200 bg-white p-1">
            <div className="text-slate-500">{i + 1}</div>
            {(byDay.get(i + 1) ?? []).map((d) => (
              <Link key={d.id} href={`/catalogo/salidas/calendario${qs({ ...search, month, departure: d.id })}`} scroll={false}
                className={`mt-0.5 block truncate rounded px-1 ${d.occupancy_pct >= 90 ? "bg-rose-100 text-rose-900" : d.occupancy_pct >= 70 ? "bg-amber-100 text-amber-900" : "bg-sky-50 text-sky-900"}`}
                title={`${d.ref} · ${d.product?.name} · ${d.sold + d.held}/${d.capacity}`}>
                {d.departure_time} {d.product?.ref} {d.occupancy_pct}%
              </Link>
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}

async function Holds({ can }: { can: boolean }) {
  const res = await api<{ data: Hold[] }>("/catalog/holds");
  return <HoldRows holds={res.data} can={can} showDeparture />;
}

function HoldRows({ holds, can, showDeparture = false }: { holds: Hold[]; can: boolean; showDeparture?: boolean }) {
  return (
    <Table head={["Hold", ...(showDeparture ? ["Salida"] : []), "Cupos", "Referencia", "Vence", "Estado", ...(can ? [""] : [])]} empty="Sin holds">
      {holds.map((h) => (
        <tr key={h.id}>
          <Td>{h.ref}<br /><span className="text-xs text-slate-500">{h.created_by?.name}</span></Td>
          {showDeparture && <Td>{h.departure?.ref} · {h.departure?.product?.name}<br /><span className="text-xs text-slate-500">{date(h.departure?.date ?? null)} {h.departure?.time}</span></Td>}
          <Td>{h.quantity}</Td>
          <Td>{h.reference}</Td>
          <Td className="whitespace-nowrap text-xs">{dateTime(h.expires_at)}{h.expired_pending && <span className="block text-rose-700">vencido</span>}</Td>
          <Td><Status value={h.status} />{h.released_reason && <span className="block text-xs text-slate-500">{h.released_reason}</span>}</Td>
          {can && (
            <Td>
              <span className="flex flex-wrap gap-1">
                {h.status === "hold" && !h.expired_pending && <ActionForm path={`/catalog/holds/${h.id}/confirm`} submitLabel="Confirmar" submitClass={small} inline />}
                {["hold", "confirmada"].includes(h.status) && (
                  <ActionForm path={`/catalog/holds/${h.id}/release`} submitLabel="Liberar" submitClass={small} inline>
                    <input name="reason" required placeholder="Motivo" aria-label="Motivo de liberación" className="w-24 rounded border border-slate-300 px-1 py-0.5 text-xs" />
                  </ActionForm>
                )}
                {h.status === "confirmada" && <ActionForm path={`/catalog/holds/${h.id}/consume`} submitLabel="Consumir" submitClass={small} inline />}
              </span>
            </Td>
          )}
        </tr>
      ))}
    </Table>
  );
}

