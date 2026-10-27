import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { dateTime, qs } from "@/lib/crm";
import { isPlatformSession } from "@/lib/session";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, Kpis, PageHeader, Table, Td, btnGhostCls } from "@/components/crm/ui";
import { Bars, Pill } from "@/components/admin/ui";

type Event = {
  code: string; occurred_at: string; actor: string | null; actor_role: string | null; actor_type: string; entity: string; entity_id: string; action: string;
  before: string | null; after: string | null; critical: boolean; ip: string | null; correlation_id: string | null; tenant: string | null;
};
type Detail = Event & { diff: { field: string; before: unknown; after: unknown }[] | null; evidence: string | null; user_agent: string | null; related: (Event & { same_correlation: boolean })[] };
type Opt = { value: string; label: string };
type Meta = {
  total: number; critical: number; by_action: { label: string; value: number }[]; by_entity: { label: string; value: number }[]; by_actor: { label: string; value: number }[];
  entities: string[]; actions: string[]; users: Opt[]; tenants: Opt[]; periods: Record<string, string>; platform: boolean;
};
type Params = { period?: string; entity?: string; action?: string; user?: string; tenant?: string; critical?: string; q?: string; e?: string };

const sel = "rounded-md border border-slate-300 px-2 py-2 text-sm";

/** Auditoría (spec 09 §4.8, AUD-BR-01…05): bitácora inmutable; en la consola de plataforma solo eventos de plataforma con columna Tenant. */
export default async function AuditPage({ searchParams }: { searchParams: Promise<Params> }) {
  const sp = await searchParams;
  const platform = await isPlatformSession();
  const filters = { period: sp.period, entity: sp.entity, action: sp.action, critical: sp.critical, q: sp.q, ...(platform ? { tenant: sp.tenant } : { user: sp.user }) };
  const prefix = platform ? "/platform/audit-events" : "/audit-events";
  const { data, meta } = await api<{ data: Event[]; meta: Meta }>(`${prefix}${qs(filters)}`);
  const detail = sp.e ? await api<{ data: Detail }>(`${prefix}/${encodeURIComponent(sp.e)}`).then((r) => r.data).catch((e) => {
    if (e instanceof ApiError && e.status === 404) return null;
    throw e;
  }) : null;
  const back = `/admin/auditoria${qs(filters)}`;
  const join = back.includes("?") ? "&" : "?";

  return (
    <section>
      <PageHeader title="Auditoría" subtitle={platform ? "Eventos de la plataforma (altas, suspensiones, planes, accesos)." : "Bitácora inmutable de acciones del tenant."}
        actions={!platform && (
          <ActionForm path="/audit-events/exports" idempotent inline submitLabel="Exportar CSV" submitClass={btnGhostCls} success="Exportación en proceso: la verá en Reportes › Historial">
            <input type="hidden" name="format" value="CSV" />
            {Object.entries(filters).map(([k, v]) => v ? <input key={k} type="hidden" name={`filters.${k}`} value={v} /> : null)}
          </ActionForm>
        )} />
      <Kpis items={[{ label: "Eventos", value: meta.total }, { label: "Críticos", value: meta.critical }, { label: "Acción más frecuente", value: meta.by_action[0]?.label ?? "—" }, { label: "Actor más activo", value: meta.by_actor[0]?.label ?? "—" }]} />

      <form method="get" className="mb-4 grid grid-cols-2 gap-2 md:grid-cols-7">
        <input name="q" defaultValue={sp.q} placeholder="Buscar entidad, actor o ID" className="col-span-2 rounded-md border border-slate-300 px-3 py-2 text-sm" />
        <select name="period" defaultValue={sp.period ?? ""} aria-label="Periodo" className={sel}><option value="">Todo</option>{Object.entries(meta.periods).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        <select name="entity" defaultValue={sp.entity ?? ""} aria-label="Entidad" className={sel}><option value="">Todas las entidades</option>
          {sp.entity?.includes(",") && <option value={sp.entity}>{sp.entity}</option>}{meta.entities.map((v) => <option key={v} value={v}>{v}</option>)}</select>
        <select name="action" defaultValue={sp.action ?? ""} aria-label="Acción" className={sel}><option value="">Todas las acciones</option>{meta.actions.map((v) => <option key={v} value={v}>{v}</option>)}</select>
        {platform
          ? <select name="tenant" defaultValue={sp.tenant ?? ""} aria-label="Tenant" className={sel}><option value="">Todos los tenants</option>{meta.tenants.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>
          : <select name="user" defaultValue={sp.user ?? ""} aria-label="Usuario" className={sel}><option value="">Todos los usuarios</option>{meta.users.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>}
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="critical" value="1" defaultChecked={sp.critical === "1"} /> Solo críticos</label>
        <button className={btnGhostCls}>Filtrar</button>
      </form>

      <div className="grid gap-4 lg:grid-cols-[1fr_18rem]">
        <Table head={["Código", "Fecha", "Actor", ...(platform ? ["Tenant"] : []), "Entidad", "Acción", "Cambio"]}>
          {data.map((e) => (
            <tr key={e.code} data-audit={e.code}>
              <Td><Link href={`${back}${join}e=${e.code}`} className="font-mono text-xs text-sky-800 hover:underline">{e.code}</Link>{e.critical && <> <Pill tone="rose">Crítico</Pill></>}</Td>
              <Td>{dateTime(e.occurred_at)}</Td>
              <Td>{e.actor ?? "Sistema"}<span className="block text-xs text-slate-500">{e.actor_role}</span></Td>
              {platform && <Td>{e.tenant ?? "—"}</Td>}
              <Td>{e.entity}<span className="block max-w-[12rem] truncate font-mono text-xs text-slate-500">{e.entity_id}</span></Td>
              <Td>{e.action}</Td>
              <Td><span className="block max-w-xs truncate text-xs">{e.before ? `${e.before} → ` : ""}{e.after}</span></Td>
            </tr>
          ))}
        </Table>
        <div className="space-y-4">
          <Card title="Por entidad"><div className="p-4"><Bars rows={meta.by_entity} /></div></Card>
          <Card title="Por actor"><div className="p-4"><Bars rows={meta.by_actor} /></div></Card>
        </div>
      </div>

      {detail && (
        <Drawer title={`${detail.code} · ${detail.action}`} close={back}>
          <dl className="grid grid-cols-[8rem_1fr] gap-2 text-sm">
            <dt className="text-slate-500">Fecha</dt><dd>{dateTime(detail.occurred_at)}</dd>
            <dt className="text-slate-500">Actor</dt><dd>{detail.actor ?? "Sistema"} ({detail.actor_role ?? detail.actor_type})</dd>
            {detail.tenant && <><dt className="text-slate-500">Tenant</dt><dd>{detail.tenant}</dd></>}
            <dt className="text-slate-500">Entidad</dt><dd>{detail.entity} · <span className="font-mono text-xs">{detail.entity_id}</span></dd>
            <dt className="text-slate-500">Antes</dt><dd className="break-words">{detail.before || "—"}</dd>
            <dt className="text-slate-500">Después</dt><dd className="break-words">{detail.after || "—"}</dd>
            <dt className="text-slate-500">IP</dt><dd>{detail.ip ?? "—"}</dd>
            <dt className="text-slate-500">Navegador</dt><dd className="break-words text-xs">{detail.user_agent ?? "—"}</dd>
            <dt className="text-slate-500">Correlación</dt><dd className="font-mono text-xs">{detail.correlation_id ?? "—"}</dd>
            {detail.evidence && <><dt className="text-slate-500">Evidencia</dt><dd>{detail.evidence}</dd></>}
          </dl>
          {detail.related.length > 0 && (
            <Card title="Eventos relacionados" className="mt-4">
              <ul className="divide-y divide-slate-100 text-sm">
                {detail.related.map((r) => <li key={r.code} className="px-4 py-2"><Link href={`${back}${join}e=${r.code}`} className="font-mono text-xs text-sky-800">{r.code}</Link> {r.action} · {r.entity} {r.same_correlation && <Pill tone="sky">misma solicitud</Pill>}<span className="block text-xs text-slate-500">{dateTime(r.occurred_at)}</span></li>)}
              </ul>
            </Card>
          )}
          <p className="mt-4 text-xs text-slate-500">Los eventos de auditoría no se editan ni se eliminan (AUD-BR-01).</p>
        </Drawer>
      )}
    </section>
  );
}
