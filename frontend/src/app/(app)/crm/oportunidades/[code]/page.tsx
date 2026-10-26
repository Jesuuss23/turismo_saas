import Link from "@/components/Link";
import { catalogOptions, crmMeta, fetchScoped, owners } from "@/lib/crm-server";
import { STAGES, date, dateTime, money, type Activity, type HistoryRow, type Opportunity } from "@/lib/crm";
import { Badge, Card, Notice, OutOfScope, PageHeader, Stepper, Table, Td, Timeline } from "@/components/crm/ui";
import { NewActivityForm } from "@/components/crm/ActivityForms";
import { StageActions } from "./StageActions";
import { QuoteStatus } from "@/components/quotes/ui";

type Detail = Opportunity & {
  client: { id: string; code: string; name: string };
  lead: { code: string; full_name: string } | null;
  quotes: { id: string; code: string; status: string; total: number; currency: string }[];
  activities: Activity[];
  history: HistoryRow[];
};
type Perms = { manage: boolean; reassign: boolean; create_quote: boolean; activity: boolean };

export default async function OpportunityDetailPage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  const res = await fetchScoped<{ data: Detail; meta: { permissions: Perms } }>(`/crm/opportunities/${code}`);
  if (!res) return <OutOfScope back="/crm/oportunidades" />;
  const { data: o, meta: { permissions: perms } } = res;
  const meta = await crmMeta();
  const advisors = perms.reassign ? (await owners()).data.map((a) => ({ value: a.id, label: a.name })) : null;

  const flow = o.stage === "perdida" ? [...STAGES.slice(0, 4).map((s) => s.name), "Perdida"] : STAGES.slice(0, 5).map((s) => s.name);
  const current = o.stage === "perdida" ? Math.max(1, STAGES.findIndex((s) => s.code === o.lost?.at_stage) + 1) : STAGES.findIndex((s) => s.code === o.stage) + 1;

  return (
    <>
      <PageHeader
        title={`${o.code} · ${o.title}`}
        subtitle={<span className="flex flex-wrap items-center gap-2"><Badge status={o.stage}>{o.stage_label}</Badge> {money(o.amount, o.currency)} · {o.probability}% · {o.owner?.name}</span>}
        actions={<Link href="/crm/oportunidades" className="text-sm text-sky-800 hover:underline">← Oportunidades</Link>}
      />
      <div className="mb-4"><Stepper steps={flow} current={o.stage === "perdida" ? current : current} failed={o.stage === "perdida"} /></div>
      {o.lost && <Notice tone="danger">Perdida desde {o.lost.at_stage}: {o.lost.reason.label}{o.lost.detail ? ` · ${o.lost.detail}` : ""}</Notice>}

      <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
        <div className="space-y-4">
          <Card title="Acciones"><StageActions opp={o} lostReasons={catalogOptions(meta, "lost_reason")} advisors={advisors} /></Card>
          <Card title="Cotizaciones vinculadas" actions={
            perms.create_quote && !o.is_closed
              ? <Link href={`/cotizaciones/nueva?opp=${o.code}`} className="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">Crear cotización</Link>
              : <button disabled title={o.is_closed ? "PROC-006: no se puede cotizar una oportunidad cerrada" : "Requiere quotes.create"}
                className="rounded-md border border-slate-300 px-2 py-1 text-xs disabled:opacity-50">Crear cotización</button>}>
            <Table head={["Código", "Estado", "Total"]} empty="Sin cotizaciones">
              {o.quotes.map((q) => <tr key={q.id}><Td><Link href={`/cotizaciones/${q.code}`} className="font-medium text-sky-800 hover:underline">{q.code}</Link></Td><Td><QuoteStatus value={q.status} /></Td><Td>{money(q.total, q.currency)}</Td></tr>)}
            </Table>
          </Card>
          <Card title="Actividades">
            <ul className="mb-4 divide-y divide-slate-100 text-sm">
              {o.activities.map((a) => (
                <li key={a.id} className="flex flex-wrap justify-between gap-2 py-2">
                  <span><Link className="font-medium text-sky-800 hover:underline" href={`/crm/actividades?code=${a.code}`}>{a.code}</Link> · {a.type_label} · {a.subject}</span>
                  <span className="flex items-center gap-2 text-slate-600">{dateTime(a.due_at)} <Badge status={a.status} /></span>
                </li>
              ))}
              {!o.activities.length && <li className="py-2 text-slate-500">Sin actividades.</li>}
            </ul>
            {perms.activity && <NewActivityForm relatedType="opportunity" relatedId={o.id} />}
          </Card>
        </div>
        <div className="space-y-4">
          <Card title="Resumen">
            <dl className="space-y-2 text-sm">
              {[
                ["Cliente", <Link key="c" className="text-sky-800 hover:underline" href={`/crm/clientes/${o.client.code}`}>{o.client.code} · {o.client.name}</Link>],
                ["Lead de origen", o.lead ? <Link key="l" className="text-sky-800 hover:underline" href={`/crm/leads/${o.lead.code}`}>{o.lead.code} · {o.lead.full_name}</Link> : "—"],
                ["Origen", o.origin ?? "—"], ["Destino", o.destination_label ?? "—"], ["Pasajeros", o.pax],
                ["Ponderado", money(o.weighted, o.currency)], ["Cierre esperado", date(o.expected_close_date)], ["Cerrada", date(o.closed_at)],
              ].map(([k, v]) => <div key={String(k)} className="flex justify-between gap-3"><dt className="text-slate-500">{k}</dt><dd className="text-right">{v}</dd></div>)}
            </dl>
            {o.notes && <p className="mt-3 whitespace-pre-line rounded bg-slate-50 p-2 text-sm">{o.notes}</p>}
          </Card>
          <Card title="Historial"><Timeline rows={o.history} /></Card>
        </div>
      </div>
    </>
  );
}
