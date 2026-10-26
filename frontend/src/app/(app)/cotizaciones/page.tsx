import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, money, qs, type Paged } from "@/lib/crm";
import { price } from "@/lib/catalog";
import { INTERNAL_LABEL, type QuoteMeta, type QuoteRow } from "@/lib/quotes";
import { btnCls, btnGhostCls, inputCls, Kpis, Notice, PageHeader, ScopeChip, Table, Td } from "@/components/crm/ui";
import { ExportButton } from "@/components/crm/ExportButton";
import { InternalBadge, QuoteStatus } from "@/components/quotes/ui";

type QuoteKpis = { open: number; follow_up_pen: string; approval_rate: number; expiring_3d: number; pending_internal: number };
type Pending = { quote: string; version_no: number; reasons: string[]; requested_by: { name: string } | null; requested_at: string; note: string | null };
type Search = { q?: string; status?: string; internal?: string; owner?: string; cursor?: string };

export default async function QuotesPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const filters = { "filter[q]": sp.q, "filter[status]": sp.status, "filter[internal_approval]": sp.internal, "filter[owner]": sp.owner };
  const [{ data: meta }, list, kpis] = await Promise.all([
    api<{ data: QuoteMeta }>("/quotes/meta"),
    api<Paged<QuoteRow, { see_margin: boolean; own_only: boolean }>>(`/quotes${qs({ ...filters, cursor: sp.cursor })}`),
    api<{ data: QuoteKpis; meta: { pending_requests: Pending[] } }>(`/quotes/kpis${qs({ "filter[owner]": sp.owner })}`),
  ]);
  const k = kpis.data;
  const ownerName = meta.owners.find((o) => o.id === sp.owner)?.name;

  return (
    <>
      <PageHeader
        title="Cotizaciones"
        subtitle={<ScopeChip ownOnly={meta.own_only} ownerName={ownerName} />}
        actions={<>
          <ExportButton resource="quotes.quotes" filters={{ status: sp.status, owner: sp.owner }} />
          {meta.can.create
            ? <Link href="/cotizaciones/nueva" className={btnCls}>Nueva cotización</Link>
            : <button disabled title="Requiere permiso quotes.create" className={btnCls}>Nueva cotización</button>}
        </>}
      />
      <Kpis items={[
        { label: "Abiertas", value: k.open },
        { label: "Monto en seguimiento", value: money(Number(k.follow_up_pen)), hint: "Enviadas, vistas y en negociación (S/)" },
        { label: "Tasa de aprobación", value: `${k.approval_rate}%`, hint: "Aprobadas / (aprobadas + rechazadas)" },
        { label: "Por vencer ≤ 3 días", value: k.expiring_3d },
        { label: "Pendientes de gerencia", value: k.pending_internal },
      ]} />

      {meta.can.approve_internal && kpis.meta.pending_requests.length > 0 && (
        <Notice tone="warning">
          <strong>{kpis.meta.pending_requests.length} solicitud(es) de aprobación interna pendiente(s):</strong>{" "}
          {kpis.meta.pending_requests.map((p, i) => (
            <span key={p.quote}>{i > 0 && " · "}<Link href={`/cotizaciones/${p.quote}`} className="font-medium underline">{p.quote}</Link> v{p.version_no} ({p.reasons.join("; ")}) de {p.requested_by?.name}</span>
          ))}
        </Notice>
      )}

      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <input name="q" defaultValue={sp.q} placeholder="Buscar por código, título o cliente" aria-label="Buscar" className={`${inputCls} max-w-sm`} />
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Estado: todos</option>
          {Object.entries(meta.statuses).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select name="internal" defaultValue={sp.internal ?? ""} aria-label="Aprobación interna" className={`${inputCls} max-w-[14rem]`}>
          <option value="">Aprobación interna: todas</option>
          <option value="pend">{INTERNAL_LABEL.pendiente}</option>
          <option value="req">Requerida sin solicitar</option>
          <option value="ok">{INTERNAL_LABEL.aprobada}</option>
        </select>
        {!meta.own_only && (
          <select name="owner" defaultValue={sp.owner ?? ""} aria-label="Asesor" className={`${inputCls} max-w-[14rem]`}>
            <option value="">Asesor: todos</option>
            {meta.owners.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
          </select>
        )}
        <button className={btnGhostCls}>Filtrar</button>
      </form>

      <Table head={["Cotización", "Cliente", ...(meta.own_only ? [] : ["Asesor"]), "Estado", "Aprob. interna", "Total", ...(list.meta.see_margin ? ["Margen"] : []), "Válida hasta"]}
        empty="No hay cotizaciones con estos filtros">
        {list.data.map((q) => (
          <tr key={q.id}>
            <Td><Link href={`/cotizaciones/${q.code}`} className="font-medium text-sky-800 hover:underline">{q.code}</Link> <span className="text-xs text-slate-500">v{q.version_no}</span><br />{q.title}</Td>
            <Td>{q.client?.name}{q.opportunity && <span className="block text-xs text-slate-500">{q.opportunity.code}</span>}</Td>
            {!meta.own_only && <Td>{q.owner?.name}</Td>}
            <Td><QuoteStatus value={q.display_status} label={q.status_label} /></Td>
            <Td><InternalBadge state={q.internal} /></Td>
            <Td>{price(q.total, q.currency)}{q.currency !== "PEN" && <span className="block text-xs text-slate-500">≈ {price(q.total_pen, "PEN")}</span>}</Td>
            {list.meta.see_margin && <Td className={Number(q.margin_pct) < 15 ? "text-rose-700" : ""}>{q.margin_pct} %</Td>}
            <Td>{date(q.valid_until)}{!q.expired && q.days_left >= 0 && q.days_left <= 3 && ["enviada", "vista", "negociacion"].includes(q.status) && <span className="block text-xs text-amber-700">vence en {q.days_left} d</span>}</Td>
          </tr>
        ))}
      </Table>
      {list.meta.next_cursor && (
        <div className="mt-3 text-right"><Link href={`/cotizaciones${qs({ ...sp, cursor: list.meta.next_cursor })}`} className={btnGhostCls}>Siguiente página</Link></div>
      )}
    </>
  );
}
