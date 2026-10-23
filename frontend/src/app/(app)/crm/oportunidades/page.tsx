import Link from "@/components/Link";
import { api } from "@/lib/api";
import { crmMeta, owners } from "@/lib/crm-server";
import { date, money, qs, type Opportunity, type Paged } from "@/lib/crm";
import { Badge, btnCls, btnGhostCls, inputCls, Kpis, PageHeader, ScopeChip, Table, Td } from "@/components/crm/ui";
import { ExportButton } from "@/components/crm/ExportButton";
import { Kanban } from "./Kanban";

type OppKpis = { pipeline_pen: number; weighted_pen: number; open_count: number; won_month_pen: number; won_month_count: number; close_rate: number };
type Search = { view?: string; owner?: string; q?: string; stage?: string; cursor?: string };

export default async function OpportunitiesPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const view = sp.view === "tabla" ? "tabla" : "kanban";
  const meta = await crmMeta();
  const advisors = meta.scope.supervisor ? await owners() : null;
  const filters = { "filter[owner]": sp.owner, "filter[q]": sp.q };
  const lostReasons = (meta.catalogs.lost_reason ?? []).map((r) => ({ value: r.code, label: r.label, requires_detail: r.requires_detail }));

  const board = view === "kanban"
    ? await api<{ data: { stage: string; name: string; count: number; total_pen: number; items: Opportunity[] }[]; meta: { kpis: OppKpis } }>(`/crm/opportunities${qs({ ...filters, group_by: "stage" })}`)
    : null;
  const table = view === "tabla"
    ? await api<Paged<Opportunity, { kpis: OppKpis; totals: { amount_pen: number; weighted_pen: number } }>>(`/crm/opportunities${qs({ ...filters, "filter[stage]": sp.stage, cursor: sp.cursor })}`)
    : null;
  const k = (board ?? table)!.meta.kpis;

  return (
    <>
      <PageHeader
        title="Oportunidades"
        subtitle={<ScopeChip ownOnly={meta.scope.own_only} ownerName={advisors?.data.find((a) => a.id === sp.owner)?.name} />}
        actions={<>
          <Link href={`/crm/oportunidades${qs({ ...sp, view: view === "kanban" ? "tabla" : "kanban", cursor: undefined })}`} className={btnGhostCls}>Ver {view === "kanban" ? "tabla" : "kanban"}</Link>
          <ExportButton resource="crm.opportunities" filters={{ owner: sp.owner, stage: sp.stage }} />
          <Link href="/crm/oportunidades/new" className={btnCls}>Nueva oportunidad</Link>
        </>}
      />
      <Kpis items={[
        { label: "Pipeline abierto", value: money(k.pipeline_pen), hint: `${k.open_count} oportunidades` },
        { label: "Ponderado", value: money(k.weighted_pen) },
        { label: "Ganadas del mes", value: money(k.won_month_pen), hint: `${k.won_month_count} cierres` },
        { label: "Tasa de cierre", value: `${k.close_rate}%` },
      ]} />
      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <input type="hidden" name="view" value={view} />
        <input name="q" defaultValue={sp.q} placeholder="Buscar por título, código, cliente o destino" className={`${inputCls} max-w-sm`} />
        {advisors && (
          <select name="owner" defaultValue={sp.owner ?? ""} className={`${inputCls} max-w-xs`} aria-label="Asesor">
            <option value="">Asesor: todos</option>
            {advisors.data.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
        )}
        <button className={btnGhostCls}>Filtrar</button>
      </form>

      {board && <Kanban columns={board.data} canManage lostReasons={lostReasons} />}
      {table && (
        <Table head={["Oportunidad", "Cliente", "Etapa", "Monto", "Prob.", "Ponderado", "Cierre", "Responsable"]}
          foot={<tr><Td>Totales (PEN)</Td><Td>{""}</Td><Td>{""}</Td><Td>{money(table.meta.totals.amount_pen)}</Td><Td>{""}</Td><Td>{money(table.meta.totals.weighted_pen)}</Td><Td>{""}</Td><Td>{""}</Td></tr>}>
          {table.data.map((o) => (
            <tr key={o.id}>
              <Td><Link href={`/crm/oportunidades/${o.code}`} className="font-medium text-sky-800 hover:underline">{o.code}</Link><br />{o.title}</Td>
              <Td>{o.client.name}</Td>
              <Td><Badge status={o.stage}>{o.stage_label}</Badge></Td>
              <Td>{money(o.amount, o.currency)}</Td>
              <Td>{o.probability}%</Td>
              <Td>{money(o.weighted, o.currency)}</Td>
              <Td>{date(o.closed_at ?? o.expected_close_date)}</Td>
              <Td>{o.owner?.name}</Td>
            </tr>
          ))}
        </Table>
      )}
    </>
  );
}
