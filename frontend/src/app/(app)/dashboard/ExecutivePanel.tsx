import Link from "@/components/Link";
import { api, type Me } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Notice, PageHeader, Table, Td, btnCls, btnGhostCls } from "@/components/crm/ui";
import { Bars, Funnel, KpiGrid, Pill, SeriesChart, Share, fmt, type Kpi } from "@/components/admin/ui";

type Executive = {
  kpis: Kpi[]; critical_incident: { code: string; title: string; link: boolean } | null;
  monthly: { series: string[]; rows: { label: string; values: number[] }[] }; funnel: { label: string; value: number }[];
  services_7d: { days: { date: string; count: number; pending: number }[]; total: number; items: { code: string; name: string; service_date: string; service_time: string | null; status: string; pax: number; reservation: string; provider: string | null }[] };
  departures: { ref: string; product: string; date: string; capacity: number; sold: number; pct: number; status: string }[];
  team_pending: { user: string; total: number; overdue: number }[];
  quotes_followup: { code: string; status: string; valid_until: string | null; viewed_at: string | null; client: string; owner: string | null; expires_in: number | null }[];
  incidents: { code: string; title: string; severity: string; severity_label: string; status: string; sla_due_at: string | null }[];
  leads: { new: number; converted: number; conversion: number; by_source: { label: string; value: number }[] };
  by_channel: { label: string; value: number; count: number }[];
  targets: { sales: number; margin: number };
  permissions: { reports: boolean; new_quote: boolean; approve: boolean; manager: boolean; links: Record<string, boolean> };
};
type Manager = {
  approvals: {
    quotes: { code: string; client: string; owner: string | null; status: string; version: number; total: number; discount_pct: number; margin_pct: number; reasons: string[]; state: string; over_cap: boolean; lock_version: number; can_approve: boolean }[];
    cancellations: { code: string; title: string; reason: string; requested_at: string; requested_by: string | null }[];
    payments: { code: string; amount: string; currency: string; paid_on: string; source: string; client: string | null }[];
  };
  goals: { key: string; label: string; value: number; target: number; unit?: string; pct: number }[];
  advisors: { user: string; leads: number; pipeline: number; open_quotes: number; won: number; conversion: number; margin: number; margin_low: boolean; overdue: number }[];
  min_margin: number; max_discount: number;
};

const SEV: Record<string, "rose" | "amber" | "slate"> = { critica: "rose", alta: "amber" };

/** Panel ejecutivo (spec 09 §4.10): KPIs del negocio para Tenant Admin y Gerencia; Gerencia suma aprobaciones, metas y asesores. */
export async function ExecutivePanel({ me }: { me: Me }) {
  const { data: d } = await api<{ data: Executive }>("/dashboard/executive");
  const manager = d.permissions.manager ? (await api<{ data: Manager }>("/dashboard/manager")).data : null;
  const l = d.permissions.links;

  return (
    <section>
      <PageHeader title={`Hola, ${me.name}`} subtitle={<>{me.role?.display_name} · {me.scope_label}</>}
        actions={<div className="flex flex-wrap gap-2">
          {d.permissions.new_quote ? <Link href="/cotizaciones/nueva" className={btnCls}>Nueva cotización</Link>
            : <button type="button" disabled title="Requiere permiso quotes.create" className={btnCls}>Nueva cotización</button>}
          {d.permissions.reports && <Link href="/reportes/comercial" className={btnGhostCls}>Ver reportes</Link>}
        </div>} />
      {d.critical_incident && (
        <Notice tone="danger">Incidencia crítica abierta: {d.critical_incident.link && l.incidents
          ? <Link href={`/operaciones/incidencias?i=${d.critical_incident.code}`} className="font-medium underline">{d.critical_incident.code} · {d.critical_incident.title}</Link>
          : <strong>{d.critical_incident.code} · {d.critical_incident.title}</strong>}</Notice>
      )}
      <KpiGrid items={d.kpis} />

      {manager && <ManagerBlock m={manager} />}

      <div className="grid gap-4 lg:grid-cols-3 [&>*]:min-w-0">
        <Card title="Ventas, costo y margen por mes" className="lg:col-span-2"><div className="p-4"><SeriesChart series={d.monthly.series} rows={d.monthly.rows} unit="PEN" /></div></Card>
        <Card title="Embudo comercial"><div className="p-4"><Funnel rows={d.funnel} /></div></Card>
        <Card title={`Servicios próximos 7 días (${d.services_7d.total})`} className="lg:col-span-2"
          actions={l.calendar && <Link href="/operaciones/calendario" className="text-xs text-sky-800 hover:underline">Calendario</Link>}>
          <div className="grid grid-cols-7 gap-1 p-4 text-center text-xs">
            {d.services_7d.days.map((x) => (
              <div key={x.date} className={`rounded p-2 ${x.pending ? "bg-amber-50 ring-1 ring-amber-200" : "bg-slate-50"}`}>
                <p className="text-slate-500">{date(x.date).slice(0, 5)}</p><p className="text-lg font-semibold">{x.count}</p>{x.pending > 0 && <p className="text-amber-800">{x.pending} pend.</p>}
              </div>
            ))}
          </div>
          <ul className="divide-y divide-slate-100 text-sm">
            {d.services_7d.items.map((s) => (
              <li key={s.code} className="flex flex-wrap justify-between gap-2 px-4 py-2">
                <span className="min-w-0">{s.name}<span className="block text-xs text-slate-500">{s.reservation} · {s.provider ?? "sin proveedor"} · {s.pax} pax</span></span>
                <span className="text-xs text-slate-600">{date(s.service_date)} {s.service_time?.slice(0, 5)} · {s.status}</span>
              </li>
            ))}
          </ul>
        </Card>
        <Card title="Ventas por canal"><div className="p-4"><Share rows={d.by_channel} unit="PEN" /></div></Card>
        <Card title="Ocupación de salidas">
          <ul className="space-y-2 p-4 text-sm">
            {d.departures.map((x) => (
              <li key={x.ref}><div className="flex justify-between gap-2"><span className="truncate">{x.product}</span><span className="shrink-0 text-xs text-slate-600">{date(x.date)} · {x.sold}/{x.capacity}</span></div>
                <div className="mt-1 h-2 rounded bg-slate-100"><div className={`h-2 rounded ${x.pct < 40 ? "bg-amber-500" : "bg-emerald-600"}`} style={{ width: `${Math.max(2, x.pct)}%` }} /></div></li>
            ))}
          </ul>
        </Card>
        <Card title="Cotizaciones por vencer" actions={l.quotes && <Link href="/cotizaciones" className="text-xs text-sky-800 hover:underline">Ver todas</Link>}>
          <ul className="divide-y divide-slate-100 text-sm">
            {d.quotes_followup.length === 0 && <li className="px-4 py-3 text-slate-500">Sin cotizaciones por vencer.</li>}
            {d.quotes_followup.map((q) => (
              <li key={q.code} className="px-4 py-2">{l.quotes ? <Link href={`/cotizaciones/${q.code}`} className="font-mono text-xs text-sky-800">{q.code}</Link> : <span className="font-mono text-xs">{q.code}</span>} · {q.client}
                <span className="block text-xs text-slate-500">{q.owner} · {q.status}{q.viewed_at && ` · vista ${dateTime(q.viewed_at)}`} · vence en {q.expires_in} día(s)</span></li>
            ))}
          </ul>
        </Card>
        <Card title="Incidencias abiertas" actions={l.incidents && <Link href="/operaciones/incidencias" className="text-xs text-sky-800 hover:underline">Ver todas</Link>}>
          <ul className="divide-y divide-slate-100 text-sm">
            {d.incidents.map((i) => <li key={i.code} className="px-4 py-2"><Pill tone={SEV[i.severity] ?? "slate"}>{i.severity_label}</Pill> {i.title}<span className="block text-xs text-slate-500">{i.code} · {i.status}{i.sla_due_at && ` · SLA ${dateTime(i.sla_due_at)}`}</span></li>)}
          </ul>
        </Card>
        <Card title={`Leads del mes: ${d.leads.new} · ${fmt(d.leads.conversion, "%")} convertidos`}><div className="p-4"><Bars rows={d.leads.by_source} /></div></Card>
        <Card title="Pendientes del equipo">
          <ul className="divide-y divide-slate-100 text-sm">
            {d.team_pending.map((t) => <li key={t.user} className="flex justify-between px-4 py-2"><span>{t.user}</span><span>{t.total}{t.overdue > 0 && <span className="text-rose-700"> · {t.overdue} vencida(s)</span>}</span></li>)}
          </ul>
        </Card>
      </div>
    </section>
  );
}

function ManagerBlock({ m }: { m: Manager }) {
  const total = m.approvals.quotes.length + m.approvals.cancellations.length + m.approvals.payments.length;
  return (
    <div className="mb-5 grid gap-4 lg:grid-cols-3 [&>*]:min-w-0">
      <Card title={`Aprobaciones pendientes (${total})`} className="lg:col-span-2">
        <div className="space-y-4 p-4 text-sm">
          {m.approvals.quotes.map((q) => (
            <div key={q.code} className="rounded-md border border-slate-200 p-3" data-approval={q.code}>
              <p><Link href={`/cotizaciones/${q.code}`} className="font-mono text-xs text-sky-800">{q.code}</Link> · {q.client} · {fmt(q.total, "PEN")}</p>
              <p className="text-xs text-slate-600">{q.owner} · descuento {q.discount_pct} % · margen {q.margin_pct} % · {q.reasons.join(" · ")}</p>
              {q.can_approve ? (
                <ActionForm path={`/quotes/${q.code}/versions/${q.version}/internal-approval`} ifMatch={q.lock_version} inline submitLabel="Aprobar" className="mt-2 flex flex-wrap items-end gap-2">
                  <input type="hidden" name="decision" value="approve" />
                  <input name="note" required placeholder="Justificación" aria-label={`Justificación ${q.code}`} className="min-w-0 flex-1 rounded border border-slate-300 px-2 py-1 text-sm" />
                </ActionForm>
              ) : <p className="mt-1 text-xs text-amber-800">{q.over_cap ? "Excede su tope de aprobación: escalar a Tenant Admin." : "Sin solicitud de aprobación enviada."}</p>}
            </div>
          ))}
          {m.approvals.cancellations.map((c) => <p key={c.code}>Cancelación <Link href={`/reservas/${c.code}`} className="font-mono text-xs text-sky-800">{c.code}</Link> · {c.title}<span className="block text-xs text-slate-500">{c.requested_by} · {c.reason}</span></p>)}
          {m.approvals.payments.map((p) => <p key={p.code}>Pago {p.code} · {p.client} · {fmt(Number(p.amount), p.currency)}<span className="block text-xs text-slate-500">{date(p.paid_on)} · {p.source} · por validar en Finanzas</span></p>)}
          {total === 0 && <p className="text-slate-500">Nada pendiente de aprobar.</p>}
        </div>
      </Card>
      <Card title="Metas del mes">
        <ul className="space-y-3 p-4 text-sm">
          {m.goals.map((g) => (
            <li key={g.key}><div className="flex justify-between gap-2"><span>{g.label}</span><span className="text-xs text-slate-600">{fmt(g.value, g.unit)} / {fmt(g.target, g.unit)}</span></div>
              <div className="mt-1 h-2 rounded bg-slate-100"><div className="h-2 rounded bg-sky-600" style={{ width: `${Math.min(100, Math.max(2, g.pct))}%` }} /></div></li>
          ))}
        </ul>
      </Card>
      <div className="lg:col-span-3">
        <Table head={["Asesor", "Leads", "Pipeline", "Cotiz. abiertas", "Ganado", "Conversión", "Margen", "Vencidas"]}>
          {m.advisors.map((a) => (
            <tr key={a.user}><Td>{a.user}</Td><Td>{a.leads}</Td><Td>{fmt(a.pipeline, "PEN")}</Td><Td>{a.open_quotes}</Td><Td>{fmt(a.won, "PEN")}</Td><Td>{fmt(a.conversion, "%")}</Td>
              <Td><span className={a.margin_low ? "text-rose-700" : ""}>{fmt(a.margin, "%")}</span></Td><Td>{a.overdue}</Td></tr>
          ))}
        </Table>
      </div>
    </div>
  );
}
