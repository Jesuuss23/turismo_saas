import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { ExportButton } from "@/components/crm/ExportButton";
import { Card, Drawer, Kpis, Notice, PageHeader, Table, Td, btnCls, btnGhostCls } from "@/components/crm/ui";
import { Pill, SeriesChart } from "@/components/admin/ui";
import { AllotmentForm, type AllotmentOptions } from "@/components/inventory/AllotmentForm";

export type Cell = {
  date: string; blocked: number; released: number; used: number; pending: number; available: number; current: number; deadline: string; retained: string | null;
  state: string; state_label: string; lock_version: number; utilization: number | null; exposure: number;
};
type CellDetail = Cell & {
  contract: { id: string; code: string; name: string; unit: string; currency: string; net_rate: string; penalty_pct: string; status: string; provider: string };
  retained_by: string | null; retained_at: string | null; closed_at: string | null; retain_exposure: number; can_manage: boolean;
  consumptions: { reservation: string; reservation_title: string; service: string; service_name: string; service_status: string; qty: number; state: string }[];
  charge: { qty: number; amount: string; currency: string; reason: string; payable: string | null } | null;
};
export type ContractRow = {
  id: string; code: string; name: string; kind: string; kind_label: string; unit: string; provider: { id: string; name: string; ref: string }; service: { id: string; name: string; ref: string };
  contract_ref: string | null; valid_from: string; valid_to: string; base_qty: number; release_days: number; net_rate: string; currency: string; penalty_pct: string;
  status: string; status_label: string; owner: string | null; notes: string | null; released_at: string | null; release_reason: string | null; lock_version: number;
  utilization_30: number | null; used_30: number; current_30: number; penalties: string;
};
export type KpiSet = { active: number; utilization_30: number; release_soon: number; exposure_pen: number; penalties_pen: number; overdue: number; overbooked: number };
type GridRow = { id: string; code: string; name: string; kind: string; kind_label: string; unit: string; provider: string; status: string; status_label: string; currency: string; cells: (Cell | null)[] };
type ReleaseRow = Cell & { code: string; name: string; provider: string; currency: string; unit: string; retain_exposure: number; retained_by?: string | null };
export type CuposParams = { tab?: string; from?: string; kind?: string; closed?: string; cell?: string; nuevo?: string };

export const NEED = "Requiere permiso catalog.allotments.manage";
export const STATE_CLS: Record<string, string> = {
  holgado: "bg-emerald-50 text-emerald-900 ring-emerald-200", medio: "bg-sky-50 text-sky-900 ring-sky-200", completo: "bg-slate-200 text-slate-800 ring-slate-300",
  release_proximo: "bg-amber-50 text-amber-900 ring-amber-300", release_vencido: "bg-orange-100 text-orange-900 ring-orange-300", retenido: "bg-violet-50 text-violet-900 ring-violet-300",
  sobreventa: "bg-rose-100 text-rose-900 ring-rose-300", liberado: "bg-slate-50 text-slate-500 ring-slate-200", pasada: "bg-white text-slate-400 ring-slate-100",
};
const STATUS_TONE: Record<string, "green" | "amber" | "slate" | "rose"> = { activo: "green", en_release: "amber", liberado: "slate", vencido: "rose" };
const TABS = [{ key: "grid", label: "Grilla" }, { key: "contratos", label: "Contratos" }, { key: "release", label: "Release" }, { key: "pickup", label: "Pick-up" }];

export function money(value: string | number | null | undefined, currency = "PEN") {
  return new Intl.NumberFormat("es-PE", { style: "currency", currency, maximumFractionDigits: 2 }).format(Number(value ?? 0));
}

function shift(day: string, days: number) {
  const d = new Date(`${day}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

function dayLabel(day: string) {
  const d = new Date(`${day}T00:00:00Z`);
  return { dow: new Intl.DateTimeFormat("es-PE", { weekday: "short", timeZone: "UTC" }).format(d), dm: day.slice(8, 10) + "/" + day.slice(5, 7) };
}

export function StatusPill({ status, label }: { status: string; label: string }) {
  return <Pill tone={STATUS_TONE[status] ?? "slate"}>{label}</Pill>;
}

/** Celda de la grilla: disponible grande, usado/cupo vigente y liberado (colores por estado §4.2). */
export function CellBox({ cell, href, unit }: { cell: Cell; href: string; unit: string }) {
  return (
    <Link href={href} scroll={false} title={`${cell.state_label} · ${cell.available} disp. · release ${date(cell.deadline)}`}
      aria-label={`${dayLabel(cell.date).dm}: ${cell.available} disponibles, ${cell.used} de ${cell.current} ${unit} usados, ${cell.state_label}`}
      className={`block min-w-16 rounded-md px-2 py-1 text-center ring-1 hover:ring-2 hover:ring-sky-500 ${STATE_CLS[cell.state] ?? ""}`}>
      <span className="block text-base font-semibold leading-tight">{cell.available}</span>
      <span className="block text-[11px] leading-tight">{cell.used}/{cell.current}</span>
      {cell.released > 0 && <span className="block text-[10px] leading-tight opacity-70">lib. {cell.released}</span>}
      {cell.retained && <span className="block text-[10px] font-medium leading-tight">retenido</span>}
    </Link>
  );
}

async function cellDetail(code: string, day: string): Promise<CellDetail | null> {
  try {
    return (await api<{ data: CellDetail }>(`/catalog/allotments/${encodeURIComponent(code)}/days/${day}`)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) return null;
    throw e;
  }
}

/** Drawer de celda (§2): cifras, reservas que consumen y acciones Liberar N / Retener / Quitar retención. */
export async function CellDrawer({ spec, close }: { spec: string; close: string }) {
  const [code, day] = spec.split(":");
  const c = code && day ? await cellDetail(code, day) : null;
  if (!c) return <Drawer title="Fecha de cupo" close={close}><Notice tone="warning">La fecha no pertenece a la vigencia del contrato.</Notice></Drawer>;
  const unit = c.contract.unit;
  const open = ["activo", "en_release"].includes(c.contract.status) && c.state !== "pasada";
  const base = `/catalog/allotments/${c.contract.code}/days/${c.date}`;
  return (
    <Drawer title={`${c.contract.name} · ${date(c.date)}`} close={close}>
      <p className="mb-3 text-sm text-slate-600">{c.contract.code} · {c.contract.provider} · <Pill tone="slate">{c.state_label}</Pill></p>
      <dl className="mb-4 grid grid-cols-3 gap-2 text-sm">
        {[["Bloqueado", c.blocked], ["Liberado", c.released], ["Usado", c.used], ["En trámite", c.pending], ["Disponible", c.available], ["Utilización", c.utilization === null ? "—" : `${c.utilization} %`]].map(([k, v]) => (
          <div key={k as string} className="rounded-md bg-slate-50 p-2"><dt className="text-xs text-slate-500">{k}</dt><dd className="text-lg font-semibold text-slate-900">{v}</dd></div>
        ))}
      </dl>
      <p className="mb-1 text-sm text-slate-700">Release: <strong>{date(c.deadline)}</strong> · unidad {unit}</p>
      {c.retained && <Notice tone="warning">Retenido por {c.retained_by ?? "—"} ({dateTime(c.retained_at)}): {c.retained}{c.exposure > 0 ? ` · exposición ${money(c.exposure, c.contract.currency)}` : ""}</Notice>}
      {c.charge && <Notice tone="danger">Cargo por no-uso: {c.charge.qty} {unit} · {money(c.charge.amount, c.charge.currency)}{c.charge.payable ? ` · CxP ${c.charge.payable}` : ""} · {c.charge.reason}</Notice>}
      {c.available < 0 && <Notice tone="danger">Sobreventa: {Math.abs(c.available)} {unit} por encima del cupo. Los servicios nuevos quedan a pedido (on request).</Notice>}

      <h3 className="mb-2 mt-4 text-sm font-semibold text-slate-800">Reservas que consumen</h3>
      {c.consumptions.length === 0 ? <p className="text-sm text-slate-500">Sin consumo en esta fecha.</p> : (
        <ul className="space-y-1 text-sm">
          {c.consumptions.map((x) => (
            <li key={`${x.reservation}-${x.service}`} className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 px-2 py-1">
              <span><Link href={`/reservas/${x.reservation}`} className="font-medium text-sky-800 hover:underline">{x.reservation}</Link> · {x.service}</span>
              <span>{x.qty} {unit} · <Pill tone={x.state === "consumido" ? "green" : x.state === "en_tramite" ? "amber" : "slate"}>{x.state === "en_tramite" ? "En trámite" : x.state === "consumido" ? "Consumido" : "Revertido"}</Pill></span>
            </li>
          ))}
        </ul>
      )}

      <h3 className="mb-2 mt-5 text-sm font-semibold text-slate-800">Acciones</h3>
      {!c.can_manage && <p className="mb-2 text-xs text-slate-500">Solo lectura · {NEED}</p>}
      {!open && c.can_manage && <p className="mb-2 text-xs text-slate-500">La fecha ya pasó o el contrato no está vigente.</p>}
      <div className="space-y-4">
        <ActionForm path={`${base}/release`} ifMatch={c.lock_version} submitLabel="Liberar" success="Unidades liberadas"
          disabled={!c.can_manage || !open || c.available <= 0} disabledReason={!c.can_manage ? NEED : "Sin disponible para liberar"} className="flex flex-wrap items-end gap-2">
          <label className="text-sm"><span className="mb-1 block font-medium text-slate-700">Unidades a liberar</span>
            <input name="qty" type="number" min={1} max={Math.max(1, c.available)} defaultValue={Math.min(2, Math.max(1, c.available))} required disabled={!c.can_manage}
              className="w-28 rounded-md border border-slate-300 px-3 py-2 text-sm" /></label>
        </ActionForm>
        {c.retained ? (
          <ActionForm path={`${base}/retention`} method="DELETE" submitLabel="Quitar retención" submitClass={btnGhostCls} success="Retención quitada"
            disabled={!c.can_manage || !open} disabledReason={NEED} />
        ) : (
          <ActionForm path={`${base}/retention`} method="PUT" submitLabel="Retener" submitClass={btnGhostCls} success="Fecha retenida"
            disabled={!c.can_manage || !open || c.available <= 0} disabledReason={!c.can_manage ? NEED : "Sin disponible"} className="space-y-2">
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Motivo de la retención</span>
              <input name="reason" required maxLength={200} disabled={!c.can_manage} className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" /></label>
            {c.retain_exposure > 0 && <p className="text-xs text-amber-800">Si no se usa, la penalidad sería {money(c.retain_exposure, c.contract.currency)} ({c.available} × {money(c.contract.net_rate, c.contract.currency)} × {c.contract.penalty_pct} %).</p>}
          </ActionForm>
        )}
        <Link href={`/catalogo/cupos/${c.contract.code}`} className="inline-block text-sm font-medium text-sky-800 hover:underline">Ver contrato {c.contract.code} →</Link>
      </div>
    </Drawer>
  );
}

/** /catalogo/cupos (§2): KPIs, alertas y pestañas Grilla, Contratos, Release y Pick-up. */
export async function AllotmentsView({ sp }: { sp: CuposParams }) {
  const tab = TABS.some((t) => t.key === sp.tab) ? sp.tab! : "grid";
  const grid = await api<{ data: { from: string; to: string; dates: string[]; rows: GridRow[]; alerts: { type: string; code: string; date: string; text: string }[]; today: string }; meta: { kpis: KpiSet; can_manage: boolean; states: Record<string, string> } }>(
    `/catalog/allotments/grid${qs({ from: sp.from, days: "14", kind: sp.kind, include_closed: sp.closed ? "1" : undefined })}`);
  const { kpis: k, can_manage: canManage } = grid.meta;
  const query = (extra: Record<string, string | undefined>) => `/catalogo/cupos${qs({ tab, from: sp.from, kind: sp.kind, closed: sp.closed, ...extra })}`;
  const close = query({});
  const options = sp.nuevo && canManage ? (await api<{ data: AllotmentOptions }>("/catalog/allotments/options")).data : null;

  return (
    <section>
      <PageHeader title="Cupos y bloqueos" subtitle="Inventario contratado con hoteles, tren y entradas: release, penalidades y ritmo de ventas"
        actions={canManage ? <Link href={query({ nuevo: "1" })} scroll={false} className={btnCls}>Nuevo contrato</Link>
          : <button disabled title={NEED} className={btnCls}>Nuevo contrato</button>} />
      <Kpis items={[
        { label: "Contratos activos", value: k.active },
        { label: "Utilización 30 días", value: `${k.utilization_30} %` },
        { label: "Release ≤ 3 días", value: k.release_soon, hint: k.overdue ? `${k.overdue} vencido(s) sin liberar` : "Fechas por liberar" },
        { label: "Exposición a penalidad", value: money(k.exposure_pen), hint: "Retenido tras el release" },
        { label: "Penalidades devengadas", value: money(k.penalties_pen), hint: "En soles (TC del registro)" },
      ]} />
      {grid.data.alerts.length > 0 && (
        <div className="mb-4 space-y-2">
          {grid.data.alerts.slice(0, 4).map((a) => <Notice key={`${a.code}-${a.date}`} tone={a.type === "sobreventa" ? "danger" : "warning"}>{a.text}</Notice>)}
          {grid.data.alerts.length > 4 && <p className="text-xs text-slate-500">y {grid.data.alerts.length - 4} alerta(s) más en la pestaña Release.</p>}
        </div>
      )}
      <nav className="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Vistas de cupos">
        {TABS.map((t) => (
          <Link key={t.key} href={`/catalogo/cupos?tab=${t.key}`} aria-current={t.key === tab ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 ${t.key === tab ? "border-sky-700 font-medium text-sky-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>{t.label}</Link>
        ))}
      </nav>
      {tab === "grid" && <GridTab grid={grid.data} sp={sp} query={query} />}
      {tab === "contratos" && <ContractsTab kind={sp.kind} />}
      {tab === "release" && <ReleaseTab canManage={canManage} query={query} />}
      {tab === "pickup" && <PickupTab />}
      {sp.cell && <CellDrawer spec={sp.cell} close={close} />}
      {options && <Drawer title="Nuevo contrato de cupo" close={close}><AllotmentForm options={options} close={close} /></Drawer>}
    </section>
  );
}

function GridTab({ grid, sp, query }: { grid: { from: string; to: string; dates: string[]; rows: GridRow[]; today: string }; sp: CuposParams; query: (e: Record<string, string | undefined>) => string }) {
  return (
    <>
      <div className="mb-3 flex flex-wrap items-center gap-2 text-sm">
        <Link href={query({ from: shift(grid.from, -7) })} className={btnGhostCls}>← 7 días</Link>
        <Link href={query({ from: undefined })} className={btnGhostCls}>Hoy</Link>
        <Link href={query({ from: shift(grid.from, 7) })} className={btnGhostCls}>7 días →</Link>
        <span className="text-slate-600">{date(grid.from)} – {date(grid.to)}</span>
        <form method="get" action="/catalogo/cupos" className="flex flex-wrap items-center gap-2 sm:ml-auto">
          <input type="hidden" name="tab" value="grid" />
          {sp.from && <input type="hidden" name="from" value={sp.from} />}
          <select name="kind" defaultValue={sp.kind ?? ""} aria-label="Tipo" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
            <option value="">Todos los tipos</option><option value="hotel">Hotel</option><option value="tren">Tren</option><option value="entrada">Entrada</option>
          </select>
          <label className="inline-flex items-center gap-1 text-slate-700"><input type="checkbox" name="closed" value="1" defaultChecked={!!sp.closed} /> Incluir liberados y vencidos</label>
          <button className={btnGhostCls}>Aplicar</button>
        </form>
      </div>
      {grid.rows.length === 0 ? <Card><p className="text-sm text-slate-500">No hay contratos vigentes en estas fechas.</p></Card> : (
        <div className="max-w-full overflow-x-auto rounded-lg border border-slate-200 bg-white" data-testid="cupos-grid">
          <table className="min-w-max border-collapse text-sm">
            <thead>
              <tr className="bg-slate-50 text-xs text-slate-600">
                <th className="sticky left-0 z-10 bg-slate-50 px-3 py-2 text-left font-medium">Contrato</th>
                {grid.dates.map((d) => {
                  const l = dayLabel(d);
                  return <th key={d} className={`px-1 py-2 text-center font-medium ${d === grid.today ? "text-sky-800" : ""}`}><span className="block capitalize">{l.dow}</span>{l.dm}</th>;
                })}
              </tr>
            </thead>
            <tbody>
              {grid.rows.map((r) => (
                <tr key={r.id} className="border-t border-slate-100">
                  <th scope="row" className="sticky left-0 z-10 max-w-44 bg-white px-3 py-2 text-left align-top font-normal">
                    <Link href={`/catalogo/cupos/${r.code}`} className="block truncate font-medium text-sky-800 hover:underline">{r.name}</Link>
                    <span className="block truncate text-xs text-slate-500">{r.code} · {r.kind_label} · {r.unit}</span>
                    {r.status !== "activo" && <StatusPill status={r.status} label={r.status_label} />}
                  </th>
                  {r.cells.map((c, i) => (
                    <td key={grid.dates[i]} className="px-1 py-1 align-top">
                      {c ? <CellBox cell={c} unit={r.unit} href={query({ cell: `${r.code}:${c.date}` })} /> : <span className="block min-w-16 text-center text-slate-300">—</span>}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <p className="mt-2 flex flex-wrap gap-2 text-xs text-slate-600">
        {[["holgado", "Holgado"], ["medio", "≥ 50 % usado"], ["completo", "Completo"], ["release_proximo", "Release ≤ 3 d"], ["release_vencido", "Release vencido"], ["retenido", "Retenido"], ["sobreventa", "Sobreventa"], ["liberado", "Liberado"]].map(([s, l]) => (
          <span key={s} className={`rounded px-2 py-0.5 ring-1 ${STATE_CLS[s]}`}>{l}</span>
        ))}
        <span>Celda: disponible · usado/cupo vigente</span>
      </p>
    </>
  );
}

async function ContractsTab({ kind }: { kind?: string }) {
  const { data } = await api<{ data: ContractRow[] }>(`/catalog/allotments${qs({ "filter[kind]": kind, all: "1" })}`);
  return (
    <Card title="Contratos" actions={<ExportButton resource="catalog.allotments" filters={{ kind }} />}>
      <Table head={["Contrato", "Proveedor", "Vigencia", "Bloqueo base", "Release", "Tarifa neta", "Penalidad", "Utilización 30 d", "Devengado", "Estado"]} empty="Sin contratos de cupo.">
        {data.map((r) => (
          <tr key={r.id}>
            <Td><Link href={`/catalogo/cupos/${r.code}`} className="font-medium text-sky-800 hover:underline">{r.code}</Link><span className="block text-xs text-slate-500">{r.name}</span></Td>
            <Td>{r.provider.name}<span className="block text-xs text-slate-500">{r.kind_label}</span></Td>
            <Td>{date(r.valid_from)} – {date(r.valid_to)}</Td>
            <Td>{r.base_qty} {r.unit}</Td>
            <Td>{r.release_days} d</Td>
            <Td>{money(r.net_rate, r.currency)}</Td>
            <Td>{Number(r.penalty_pct)} %</Td>
            <Td>{r.utilization_30 === null ? "—" : `${r.utilization_30} %`}</Td>
            <Td>{money(r.penalties, r.currency)}</Td>
            <Td><StatusPill status={r.status} label={r.status_label} /></Td>
          </tr>
        ))}
      </Table>
    </Card>
  );
}

async function ReleaseTab({ canManage, query }: { canManage: boolean; query: (e: Record<string, string | undefined>) => string }) {
  const { data } = await api<{ data: { overdue: ReleaseRow[]; upcoming: ReleaseRow[]; retained: ReleaseRow[];
    job: { code: string; name: string; cron_label: string; last_run_at: string | null; last_status: string | null; last_result: string | null; next_run_at: string | null; paused: boolean } | null } }>("/catalog/allotments/release-board");
  const row = (r: ReleaseRow, extra?: React.ReactNode) => (
    <tr key={`${r.code}-${r.date}`}>
      <Td><Link href={query({ cell: `${r.code}:${r.date}` })} scroll={false} className="font-medium text-sky-800 hover:underline">{r.name}</Link><span className="block text-xs text-slate-500">{r.code} · {r.provider}</span></Td>
      <Td>{date(r.date)}</Td>
      <Td>{date(r.deadline)}</Td>
      <Td>{r.available} {r.unit}</Td>
      <Td>{r.retain_exposure > 0 ? money(r.retain_exposure, r.currency) : "Sin penalidad"}</Td>
      {extra}
    </tr>
  );
  return (
    <div className="space-y-4">
      {data.job && (
        <Card title={`${data.job.code} · ${data.job.name}`} actions={
          <ActionForm path="/catalog/allotments/jobs/release" submitLabel="Liberar cupos en release" disabled={!canManage} disabledReason={NEED} success="Job ejecutado" />}>
          <p className="text-sm text-slate-700">Programado {data.job.cron_label}{data.job.paused ? " (pausado)" : ""} · última ejecución {dateTime(data.job.last_run_at)}
            {data.job.last_status ? <> · <Pill tone={data.job.last_status === "ok" ? "green" : data.job.last_status === "error" ? "rose" : "amber"}>{data.job.last_status}</Pill></> : null}</p>
          {data.job.last_result && <p className="mt-1 text-sm text-slate-600">{data.job.last_result}</p>}
        </Card>
      )}
      <Card title={`Release vencido sin liberar (${data.overdue.length})`}>
        <Table head={["Contrato", "Fecha", "Release", "Disponible", "Penalidad si se retiene"]} empty="Sin fechas vencidas: el job liberó todo lo pendiente.">{data.overdue.map((r) => row(r))}</Table>
      </Card>
      <Card title={`Release en los próximos 3 días (${data.upcoming.length})`}>
        <Table head={["Contrato", "Fecha", "Release", "Disponible", "Penalidad si se retiene"]} empty="Sin releases próximos.">{data.upcoming.map((r) => row(r))}</Table>
      </Card>
      <Card title={`Retenciones activas (${data.retained.length})`}>
        <Table head={["Contrato", "Fecha", "Release", "Disponible", "Exposición", "Motivo"]} empty="Sin retenciones.">
          {data.retained.map((r) => row(r, <Td>{r.retained}<span className="block text-xs text-slate-500">{r.retained_by ?? ""}</span></Td>))}
        </Table>
      </Card>
    </div>
  );
}

async function PickupTab() {
  const { data } = await api<{ data: { rows: { code: string; name: string; kind_label: string; provider: string; unit: string; current: number; sold: number; pending: number; utilization: number; pickup_7: number; pickup_30: number; recommendation: string }[];
    series: { label: string; sold: number; cupo: number; target: number }[] } }>("/catalog/allotments/pickup?days=30&weeks=8");
  return (
    <div className="space-y-4">
      <Card title="Pick-up report · próximos 30 días">
        <Table head={["Contrato", "Cupo vigente", "Vendido", "En trámite", "Utilización", "Pick-up 7 d", "Pick-up 30 d", "Recomendación"]} empty="Sin contratos vigentes.">
          {data.rows.map((r) => (
            <tr key={r.code}>
              <Td><Link href={`/catalogo/cupos/${r.code}`} className="font-medium text-sky-800 hover:underline">{r.name}</Link><span className="block text-xs text-slate-500">{r.code} · {r.kind_label} · {r.unit}</span></Td>
              <Td>{r.current}</Td><Td>{r.sold}</Td><Td>{r.pending}</Td><Td>{r.utilization} %</Td><Td>{r.pickup_7}</Td><Td>{r.pickup_30}</Td>
              <Td className="min-w-48 text-xs">{r.recommendation}</Td>
            </tr>
          ))}
        </Table>
      </Card>
      <Card title="Curva semanal: vendido acumulado vs cupo vs ritmo objetivo (85 %)">
        <SeriesChart series={["Vendido acumulado", "Cupo vigente", "Ritmo objetivo"]} rows={data.series.map((s) => ({ label: s.label, values: [s.sold, s.cupo, s.target] }))} />
      </Card>
    </div>
  );
}
