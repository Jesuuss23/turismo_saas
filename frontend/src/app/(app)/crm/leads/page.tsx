import Link from "@/components/Link";
import { api } from "@/lib/api";
import { crmMeta, catalogOptions, owners } from "@/lib/crm-server";
import { dateTime, qs, type Lead, type Paged } from "@/lib/crm";
import { Badge, btnCls, btnGhostCls, Card, inputCls, Kpis, Notice, PageHeader, ScopeChip, Table, Td } from "@/components/crm/ui";
import { ExportButton } from "@/components/crm/ExportButton";

type LeadKpis = { new_today: number; uncontacted: number; unassigned: number; qualified: number; conversion_rate: number; by_source: Record<string, number>; by_status: Record<string, number> };
type Search = { status?: string; source?: string; owner?: string; q?: string; cursor?: string };

export default async function LeadsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const meta = await crmMeta();
  const [list, advisors] = await Promise.all([
    api<Paged<Lead, { kpis: LeadKpis }>>(`/crm/leads${qs({ "filter[status]": sp.status, "filter[source]": sp.source, "filter[owner]": sp.owner, "filter[q]": sp.q, cursor: sp.cursor })}`),
    meta.scope.supervisor ? owners() : Promise.resolve(null),
  ]);
  const k = list.meta.kpis;
  const sources = catalogOptions(meta, "lead_source");
  const ownerName = advisors?.data.find((a) => a.id === sp.owner)?.name;
  const maxSource = Math.max(1, ...Object.values(k.by_source));

  return (
    <>
      <PageHeader
        title="Leads"
        subtitle={<ScopeChip ownOnly={meta.scope.own_only} ownerName={ownerName} />}
        actions={<>
          <ExportButton resource="crm.leads" filters={{ status: sp.status, source: sp.source, owner: sp.owner }} />
          <Link href="/crm/leads/new" className={btnCls}>Nuevo lead</Link>
        </>}
      />
      <Kpis items={[
        { label: "Nuevos hoy", value: k.new_today },
        { label: "Sin contactar", value: k.uncontacted, hint: `${k.unassigned} sin asignar` },
        { label: "Calificados", value: k.qualified },
        { label: "Tasa de conversión", value: `${k.conversion_rate}%`, hint: "convertidos / no duplicados" },
      ]} />
      {meta.scope.own_only && k.unassigned > 0 && (
        <Notice tone="warning">Hay {k.unassigned} lead(s) sin asignar en la cola comercial. Use “Tomar lead” para gestionarlos.</Notice>
      )}

      <form className="mb-4 grid gap-2 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-5" role="search">
        <input name="q" defaultValue={sp.q} placeholder="Buscar por código, nombre, correo, teléfono, destino…" className={`${inputCls} sm:col-span-2`} />
        <select name="status" defaultValue={sp.status ?? ""} className={inputCls} aria-label="Estado">
          <option value="">Todos los estados</option>
          {["nuevo", "contactado", "calificado", "convertido", "duplicado", "descartado"].map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <select name="source" defaultValue={sp.source ?? ""} className={inputCls} aria-label="Fuente">
          <option value="">Todas las fuentes</option>
          {sources.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
        </select>
        <div className="flex gap-2">
          <select name="owner" defaultValue={sp.owner ?? ""} className={inputCls} aria-label="Asignación">
            <option value="">{meta.scope.supervisor ? "Asesor: todos" : "Todos"}</option>
            <option value="me">Míos</option>
            <option value="none">Sin asignar</option>
            {advisors?.data.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
          <button className={btnGhostCls}>Filtrar</button>
        </div>
      </form>

      <div className="grid gap-4 lg:grid-cols-[1fr_260px]">
        <div>
          <Table head={["Lead", "Contacto", "Fuente", "Destino", "Score", "Estado", "Responsable", "Recibido"]}>
            {list.data.map((l) => (
              <tr key={l.id} className="hover:bg-slate-50">
                <Td><Link href={`/crm/leads/${l.code}`} className="font-medium text-sky-800 hover:underline">{l.code}</Link><br />{l.full_name}{l.company && <span className="text-slate-500"> · {l.company}</span>}</Td>
                <Td className="text-slate-600">{l.email ?? "—"}<br />{l.phone}</Td>
                <Td>{l.source.label}<br /><span className="text-xs text-slate-500">{l.channel.label}</span></Td>
                <Td>{l.destination_label ?? "—"}{l.pax > 0 && <span className="text-xs text-slate-500"> · {l.pax} pax</span>}</Td>
                <Td>{l.score}</Td>
                <Td><Badge status={l.status}>{l.status_label}</Badge></Td>
                <Td>{l.owner?.name ?? <span className="text-amber-700">Sin asignar</span>}</Td>
                <Td className="whitespace-nowrap text-slate-600">{dateTime(l.created_at)}</Td>
              </tr>
            ))}
          </Table>
          {list.meta.next_cursor && (
            <Link className={`${btnGhostCls} mt-3 inline-block`} href={`/crm/leads${qs({ ...sp, cursor: list.meta.next_cursor })}`}>Siguiente página</Link>
          )}
        </div>
        <div className="space-y-4">
          <Card title="Por fuente">
            <ul className="space-y-2 text-sm">
              {Object.entries(k.by_source).map(([code, n]) => (
                <li key={code}>
                  <div className="flex justify-between"><span>{sources.find((s) => s.value === code)?.label ?? code}</span><span>{n}</span></div>
                  <div className="h-1.5 rounded bg-slate-100"><div className="h-1.5 rounded bg-sky-600" style={{ width: `${(n / maxSource) * 100}%` }} /></div>
                </li>
              ))}
            </ul>
          </Card>
          <Card title="Por estado">
            <ul className="space-y-1 text-sm">
              {Object.entries(k.by_status).map(([s, n]) => <li key={s} className="flex justify-between"><Badge status={s} /> <span>{n}</span></li>)}
            </ul>
          </Card>
        </div>
      </div>
    </>
  );
}
