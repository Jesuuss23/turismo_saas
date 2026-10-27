import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { dateTime, qs } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, Input, Notice, PageHeader, Select, Table, Td, btnGhostCls } from "@/components/crm/ui";
import { ChartView, KpiGrid, Pill, RouteTabs, cell, type Chart, type Kpi } from "@/components/admin/ui";

type Report = {
  kpis: Kpi[]; charts: Record<string, Chart>;
  table: { columns: [string, string, string?][]; rows: Record<string, unknown>[]; totals: Record<string, unknown> | null };
  meta: {
    report: string; title: string; tabs: { key: string; label: string }[]; period: string; periods: Record<string, string>; from: string; to: string; sede: string | null;
    sedes: { id: string; name: string }[]; own_only: boolean; can_export: boolean; name: string;
  };
};
type Export = { id: string; code: string; report_name: string; report_key: string; format: string; status: string; status_label: string; rows: number; size_bytes: number; requested_by: string | null; created_at: string; expires_at: string; error: string | null; can_download: boolean };
type Schedule = { id: string; code: string; name: string; report_key: string; format: string; cron_label: string; recipients: string; owner: string | null; paused: boolean; next_run_at: string | null; can_delete: boolean };
type History = { data: Export[]; schedules: Schedule[]; meta: { formats: string[]; frequencies: Record<string, string>; ttl_days: number } };
type Params = { period?: string; sede?: string; exportar?: string; programar?: string };

const TONE: Record<string, "green" | "amber" | "rose" | "slate"> = { disponible: "green", en_cola: "amber", procesando: "amber", fallido: "rose", expirado: "slate" };

/** Reportes (spec 09 §4.9): KPIs, gráficos y tabla por módulo; exportación asíncrona, envíos programados e historial. */
export default async function ReportsPage({ params, searchParams }: { params: Promise<{ tab: string }>; searchParams: Promise<Params> }) {
  const [{ tab }, sp] = await Promise.all([params, searchParams]);
  if (tab === "historial") return <HistoryView />;

  let r: Report;
  try {
    r = await api<Report>(`/reports/${encodeURIComponent(tab)}${qs({ period: sp.period, sede: sp.sede })}`);
  } catch (e) {
    if (e instanceof ApiError && (e.status === 403 || e.status === 404)) {
      return <section><PageHeader title="Reportes" /><Notice tone="warning">{e.body?.message ?? "No tiene acceso a este reporte."} <Link href="/reportes/comercial" className="underline">Volver a reportes</Link></Notice></section>;
    }
    throw e;
  }
  const filters = { period: sp.period, sede: sp.sede };
  const back = `/reportes/${tab}${qs(filters)}`;
  const join = back.includes("?") ? "&" : "?";
  const tabs = r.meta.can_export ? [...r.meta.tabs, { key: "historial", label: "Historial de exportaciones" }] : r.meta.tabs;

  return (
    <section>
      <PageHeader title="Reportes" subtitle={<>{r.meta.name} · {r.meta.from} al {r.meta.to}</>}
        actions={r.meta.can_export ? (
          <div className="flex gap-2">
            <Link href={`${back}${join}exportar=1`} className={btnGhostCls}>Exportar</Link>
            <Link href={`${back}${join}programar=1`} className={btnGhostCls}>Programar envío</Link>
          </div>
        ) : (
          <div className="flex gap-2" title="Requiere permiso reports.export">
            <button type="button" disabled className={btnGhostCls}>Exportar</button>
            <button type="button" disabled className={btnGhostCls}>Programar envío</button>
          </div>
        )} />
      <RouteTabs base="/reportes" tabs={tabs} active={tab} />
      {r.meta.own_only && <Notice>Ve solo sus propios registros según su rol.</Notice>}
      <form method="get" className="mb-4 flex flex-wrap gap-2">
        <select name="period" defaultValue={r.meta.period} aria-label="Periodo" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
          {Object.entries(r.meta.periods).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        <select name="sede" defaultValue={r.meta.sede ?? ""} aria-label="Sede" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
          <option value="">Todas las sedes</option>{r.meta.sedes.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
        <button className={btnGhostCls}>Aplicar</button>
      </form>
      <KpiGrid items={r.kpis} />
      <div className="mb-5 grid gap-4 lg:grid-cols-2">
        {Object.entries(r.charts).map(([k, c]) => <Card key={k} title={c.title}><div className="p-4" data-chart={k}><ChartView chart={c} /></div></Card>)}
      </div>
      <Table head={r.table.columns.map((c) => c[1])}
        foot={r.table.totals && <tr className="bg-slate-50 font-medium">{r.table.columns.map((c) => <Td key={c[0]}>{cell(r.table.totals?.[c[0]], c[2])}</Td>)}</tr>}>
        {r.table.rows.map((row, i) => <tr key={i}>{r.table.columns.map((c) => <Td key={c[0]}>{cell(row[c[0]], c[2])}</Td>)}</tr>)}
      </Table>

      {sp.exportar && r.meta.can_export && (
        <Drawer title={`Exportar ${r.meta.title}`} close={back}>
          <ActionForm path="/reports/exports" idempotent submitLabel="Generar archivo" success="Exportación en proceso: aparecerá en el historial cuando esté lista.">
            <input type="hidden" name="report_key" value={tab} />
            <input type="hidden" name="filters.period" value={r.meta.period} />
            {r.meta.sede && <input type="hidden" name="filters.sede" value={r.meta.sede} />}
            <Select name="format" label="Formato" options={["CSV", "XLSX", "PDF"].map((f) => ({ value: f, label: f }))} defaultValue="XLSX" required />
            <Select name="scope" label="Contenido" options={[{ value: "filtered", label: "Lo que veo (filtros aplicados)" }, { value: "detailed", label: "Detalle completo" }]} defaultValue="filtered" />
            <p className="text-xs text-slate-500">El archivo se genera en segundo plano y queda disponible 7 días. Toda exportación queda auditada.</p>
          </ActionForm>
          <p className="mt-4 text-sm"><Link href="/reportes/historial" className="text-sky-800 hover:underline">Ir al historial de exportaciones</Link></p>
        </Drawer>
      )}
      {sp.programar && r.meta.can_export && (
        <Drawer title={`Programar ${r.meta.title}`} close={back}>
          <ActionForm path="/reports/schedules" submitLabel="Programar envío" redirectTo="/reportes/historial">
            <input type="hidden" name="report_key" value={tab} />
            <Select name="frequency" label="Frecuencia" options={[{ value: "diario", label: "Diario" }, { value: "lunes", label: "Semanal (lunes)" }, { value: "viernes", label: "Semanal (viernes)" }, { value: "mensual", label: "Mensual (día 1)" }]} defaultValue="lunes" required />
            <Input name="time" label="Hora" type="time" defaultValue="08:00" required />
            <Select name="format" label="Formato" options={["CSV", "XLSX", "PDF"].map((f) => ({ value: f, label: f }))} defaultValue="PDF" required />
            <Input name="recipients" label="Destinatarios (correos separados por coma)" required />
          </ActionForm>
        </Drawer>
      )}
    </section>
  );
}

async function HistoryView() {
  const [h, first] = await Promise.all([api<History>("/reports/exports"), api<Report>("/reports/comercial")]);
  const tabs = [...first.meta.tabs, { key: "historial", label: "Historial de exportaciones" }];

  return (
    <section>
      <PageHeader title="Reportes" subtitle={`Los archivos vencen a los ${h.meta.ttl_days} días. Cada descarga queda auditada.`} />
      <RouteTabs base="/reportes" tabs={tabs} active="historial" />
      <Table head={["Código", "Reporte", "Formato", "Estado", "Solicitado por", "Fecha", ""]}>
        {h.data.map((e) => (
          <tr key={e.id} data-export={e.code}>
            <Td><span className="font-mono text-xs">{e.code}</span></Td>
            <Td>{e.report_name}<span className="block text-xs text-slate-500">{e.rows} fila(s) · {(e.size_bytes / 1024).toFixed(1)} KB</span></Td>
            <Td>{e.format}</Td>
            <Td><Pill tone={TONE[e.status] ?? "slate"}>{e.status_label}</Pill>{e.error && <span className="block text-xs text-rose-700">{e.error}</span>}</Td>
            <Td>{e.requested_by ?? "Programado"}</Td>
            <Td>{dateTime(e.created_at)}<span className="block text-xs text-slate-500">vence {dateTime(e.expires_at)}</span></Td>
            <Td>{e.can_download && <a href={`/bff/report-download/${e.id}`} className="text-sky-800 hover:underline">Descargar</a>}</Td>
          </tr>
        ))}
      </Table>
      <Card title="Envíos programados" className="mt-5">
        {h.schedules.length === 0 ? <p className="p-4 text-sm text-slate-500">No hay envíos programados.</p> : (
          <ul className="divide-y divide-slate-100 text-sm">
            {h.schedules.map((s) => (
              <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3" data-schedule={s.code}>
                <span><span className="font-mono text-xs text-slate-500">{s.code}</span> {s.name} · {s.format} · {s.cron_label}{s.paused && " (pausado)"}
                  <span className="block text-xs text-slate-500">{s.recipients}{s.owner && ` · ${s.owner}`}{s.next_run_at && ` · próximo ${dateTime(s.next_run_at)}`}</span></span>
                {s.can_delete && <ActionForm path={`/jobs/${s.code}`} method="DELETE" inline submitLabel="Eliminar" submitClass="rounded border border-slate-300 px-2 py-1 text-xs" confirm={`¿Eliminar ${s.code}?`} />}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </section>
  );
}
