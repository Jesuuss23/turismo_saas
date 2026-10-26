import Link from "@/components/Link";
import { api, type Me } from "@/lib/api";
import { date, dateTime, money } from "@/lib/crm";
import { Badge, btnCls, btnGhostCls, Card, Drawer, Kpis, Notice, PageHeader } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { CompleteActivityForm } from "@/components/crm/ActivityForms";

type PanelActivity = { code: string; type: string; subject: string; due_at: string; status: string };
type PanelLead = { code: string; full_name: string; destination_label: string | null; pax: number; score: number; status: string; created_at: string };
type Panel = {
  alert: string | null;
  kpis: {
    pipeline: { total_pen: number; count: number; weighted_pen: number };
    open_quotes: { count: number; expiring_7d: number };
    activities: { due_now: number; pending: number };
    conversion: { rate: number; won: number; lost: number; leads_converted: number; leads_total: number };
    commission: { total_pen: number; by_currency: { currency: string; amount: number }[] };
  };
  pipeline_by_stage: { stage: string; name: string; count: number; total_pen: number }[];
  closing_soon: { code: string; title: string; stage: string; amount: number; currency: string; expected_close_date: string | null }[];
  activities: { due_now: PanelActivity[]; upcoming: PanelActivity[] };
  quotes_expiring: { code: string; title: string; client: string; days_left: number; viewed: boolean; total: number; currency: string; margin_pct: number }[];
  leads: { mine: PanelLead[]; pool: PanelLead[] };
  upcoming_trips: { code: string; client: string; destination: string; start_date: string }[];
};
type Perms = { new_lead: boolean; new_quote: boolean; take: boolean; complete: boolean };

/** Panel de Ventas "Mi cartera" (spec 01 §7.4). "Hecha" y "Tomar" usan los mismos endpoints que el CRM. */
export async function SalesPanel({ me, done }: { me: Me; done?: string }) {
  const { data: p, meta: { permissions: perms } } = await api<{ data: Panel; meta: { permissions: Perms } }>("/dashboard/sales");
  const k = p.kpis;
  const maxStage = Math.max(1, ...p.pipeline_by_stage.map((s) => s.total_pen));
  const doneActivity = done ? [...p.activities.due_now, ...p.activities.upcoming].find((a) => a.code === done) : undefined;

  return (
    <>
      <PageHeader
        title={`Hola, ${me.name.split(" ")[0]}`}
        subtitle="Panel de Ventas · Mi cartera"
        actions={<>
          {perms.new_lead ? <Link href="/crm/leads/new" className={btnCls}>Nuevo lead</Link> : <button disabled title="Requiere permiso crm.leads.manage" className={btnCls}>Nuevo lead</button>}
          {perms.new_quote ? <Link href="/cotizaciones/nueva" className={btnGhostCls}>Nueva cotización</Link> : <button disabled title="Requiere permiso quotes.create" className={btnGhostCls}>Nueva cotización</button>}
        </>}
      />
      {p.alert && <Notice tone="warning">{p.alert}</Notice>}
      <Kpis items={[
        { label: "Mi pipeline", value: money(k.pipeline.total_pen), hint: `${k.pipeline.count} abiertas · ponderado ${money(k.pipeline.weighted_pen)}` },
        { label: "Mis cotizaciones abiertas", value: k.open_quotes.count, hint: `${k.open_quotes.expiring_7d} vencen en ≤ 7 días` },
        { label: "Actividades hoy/vencidas", value: k.activities.due_now, hint: `${k.activities.pending} pendientes en total` },
        { label: "Mi conversión", value: `${k.conversion.rate}%`, hint: `${k.conversion.won} ganadas / ${k.conversion.lost} perdidas · leads ${k.conversion.leads_converted}/${k.conversion.leads_total}` },
        { label: "Comisión estimada (mes)", value: money(k.commission.total_pen), hint: k.commission.by_currency.map((c) => money(c.amount, c.currency)).join(" · ") || "Sin comisiones del mes" },
      ]} />

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Mi pipeline por etapa" actions={<Link className="text-sm text-sky-800 hover:underline" href="/crm/oportunidades">Ver todo</Link>}>
          <ul className="mb-4 space-y-2 text-sm">
            {p.pipeline_by_stage.map((s) => (
              <li key={s.stage}>
                <div className="flex justify-between"><span>{s.name} ({s.count})</span><span>{money(s.total_pen)}</span></div>
                <div className="h-1.5 rounded bg-slate-100"><div className="h-1.5 rounded bg-sky-600" style={{ width: `${(s.total_pen / maxStage) * 100}%` }} /></div>
              </li>
            ))}
          </ul>
          <h3 className="mb-1 text-xs font-semibold uppercase text-slate-500">Cierre más próximo</h3>
          <ul className="divide-y divide-slate-100 text-sm">
            {p.closing_soon.map((o) => (
              <li key={o.code} className="flex justify-between gap-2 py-1.5">
                <span><Link className="text-sky-800 hover:underline" href={`/crm/oportunidades/${o.code}`}>{o.code}</Link> · {o.title}</span>
                <span className="text-slate-600">{money(o.amount, o.currency)} · {date(o.expected_close_date)}</span>
              </li>
            ))}
            {!p.closing_soon.length && <li className="py-1.5 text-slate-500">Sin oportunidades abiertas.</li>}
          </ul>
        </Card>

        <Card title="Mis actividades" actions={<Link className="text-sm text-sky-800 hover:underline" href="/crm/actividades">Ver todas</Link>}>
          <h3 className="mb-1 text-xs font-semibold uppercase text-slate-500">Hoy y vencidas</h3>
          <ul className="mb-3 divide-y divide-slate-100 text-sm">
            {p.activities.due_now.map((a) => (
              <li key={a.code} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
                <span><Link className="text-sky-800 hover:underline" href={`/crm/actividades?code=${a.code}`}>{a.code}</Link> · {a.subject}</span>
                <span className="flex items-center gap-2 text-slate-600">{dateTime(a.due_at)} <Badge status={a.status} />
                  {perms.complete && <Link href={`/dashboard?done=${a.code}`} scroll={false} className="rounded border border-slate-300 px-2 py-0.5 text-xs">Hecha</Link>}
                </span>
              </li>
            ))}
            {!p.activities.due_now.length && <li className="py-1.5 text-slate-500">Nada pendiente para hoy.</li>}
          </ul>
          <h3 className="mb-1 text-xs font-semibold uppercase text-slate-500">Próximas</h3>
          <ul className="divide-y divide-slate-100 text-sm">
            {p.activities.upcoming.map((a) => (
              <li key={a.code} className="flex justify-between gap-2 py-1.5"><span>{a.code} · {a.subject}</span><span className="text-slate-600">{dateTime(a.due_at)}</span></li>
            ))}
          </ul>
        </Card>

        <Card title="Mis cotizaciones por vencer">
          {p.quotes_expiring.length ? (
            <ul className="divide-y divide-slate-100 text-sm">
              {p.quotes_expiring.map((q) => (
                <li key={q.code} className="flex flex-wrap justify-between gap-2 py-1.5">
                  <span><Link href={`/cotizaciones/${q.code}`} className="font-medium text-sky-800 hover:underline">{q.code}</Link> · {q.title} · {q.client}</span>
                  <span className="text-slate-600">
                    {q.days_left < 0 ? "vencida" : q.days_left === 0 ? "vence hoy" : `vence en ${q.days_left} d`} · {q.viewed ? "vista" : "no abierta"} · {money(q.total, q.currency)} ·{" "}
                    <span className={q.margin_pct < 15 ? "font-medium text-rose-700" : ""}>margen {q.margin_pct}%</span>
                  </span>
                </li>
              ))}
            </ul>
          ) : <p className="text-sm text-slate-500">Sin cotizaciones por vencer.</p>}
        </Card>

        <Card title="Mis leads" actions={<Link className="text-sm text-sky-800 hover:underline" href="/crm/leads?owner=me">Ver todos</Link>}>
          <ul className="mb-3 divide-y divide-slate-100 text-sm">
            {p.leads.mine.map((l) => (
              <li key={l.code} className="flex flex-wrap justify-between gap-2 py-1.5">
                <span><Link className="text-sky-800 hover:underline" href={`/crm/leads/${l.code}`}>{l.full_name}</Link> · {l.destination_label ?? "—"} · {l.pax} pax</span>
                <span className="flex items-center gap-2">score {l.score} <Badge status={l.status} /></span>
              </li>
            ))}
            {!p.leads.mine.length && <li className="py-1.5 text-slate-500">Sin leads abiertos.</li>}
          </ul>
          <h3 className="mb-1 text-xs font-semibold uppercase text-slate-500">Sin asignar · disponibles para tomar</h3>
          <ul className="divide-y divide-slate-100 text-sm">
            {p.leads.pool.map((l) => (
              <li key={l.code} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
                <span><Link className="text-sky-800 hover:underline" href={`/crm/leads/${l.code}`}>{l.code}</Link> · {l.full_name} · {l.destination_label ?? "—"}</span>
                {perms.take && <ActionForm inline path={`/crm/leads/${l.code}/take`} submitLabel="Tomar" success="Lead tomado"
                  submitClass="rounded border border-sky-700 px-2 py-0.5 text-xs text-sky-800 hover:bg-sky-50" />}
              </li>
            ))}
            {!p.leads.pool.length && <li className="py-1.5 text-slate-500">La cola comercial está vacía.</li>}
          </ul>
        </Card>

        <Card title="Mis próximos viajes" className="lg:col-span-2">
          {p.upcoming_trips.length ? (
            <ul className="mb-3 divide-y divide-slate-100 text-sm">
              {p.upcoming_trips.map((t) => <li key={t.code} className="flex justify-between py-1.5"><span>{t.code} · {t.client}</span><span>{t.destination} · {date(t.start_date)}</span></li>)}
            </ul>
          ) : <p className="mb-3 text-sm text-slate-500">Sin viajes próximos (se completa con el módulo de Reservas).</p>}
          <div className="flex flex-wrap gap-2 text-sm">
            <Link className={btnGhostCls} href="/crm/leads">Leads</Link>
            <Link className={btnGhostCls} href="/crm/clientes">Mis clientes</Link>
            <Link className={btnGhostCls} href="/crm/postventa">Postventa</Link>
          </div>
        </Card>
      </div>

      {doneActivity && (
        <Drawer title={`Marcar como hecha · ${doneActivity.code}`} close="/dashboard">
          <p className="mb-3 text-sm text-slate-700">{doneActivity.subject}</p>
          <CompleteActivityForm code={doneActivity.code} defaultSubject={doneActivity.subject} redirectTo="/dashboard" />
        </Drawer>
      )}
    </>
  );
}

