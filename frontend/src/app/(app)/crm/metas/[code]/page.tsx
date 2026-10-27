import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { Badge, btnGhostCls, Card, Input, Kpis, Notice, OutOfScope, PageHeader, Stepper, Table, Td } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { Bar, BackLink, Facts } from "@/components/marketing/ui";
import { AdjustDrawer } from "@/components/marketing/targets";
import { periodLabel, pct, pen, TARGET_FLOW, type TargetDetail, type TargetsMeta } from "@/lib/marketing";

const STEP: Record<string, number> = { borrador: 1, vigente: 2, cerrada: 3, anulada: 1 };
const ACTIONS: Record<string, string> = { crear: "Creación", activar: "Activación", ajustar: "Ajuste", anular: "Anulación", cerrar: "Cierre", bono: "Bono calculado" };
const GOAL_LABELS: Record<string, string> = { goal_sales_pen: "ventas S/", goal_bookings: "reservas", goal_margin_pen: "margen S/", goal_conversion_pct: "conversión %", bonus_amount: "bono S/" };

function describe(v: Record<string, unknown> | null) {
  if (!v) return "—";
  return Object.entries(v).filter(([, x]) => x !== null && x !== undefined).map(([k, x]) => `${GOAL_LABELS[k] ?? k}: ${typeof x === "number" ? x.toLocaleString("es-PE") : String(x)}`).join(" · ");
}

export default async function TargetPage({ params, searchParams }: { params: Promise<{ code: string }>; searchParams: Promise<{ adjust?: string }> }) {
  const { code } = await params;
  const { adjust } = await searchParams;
  let t: TargetDetail;
  try {
    t = (await api<{ data: TargetDetail }>(`/crm/sales-targets/${encodeURIComponent(code)}`)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) notFound();
    if (e instanceof ApiError && e.status === 403) return <OutOfScope back="/crm/metas" />;
    throw e;
  }
  const meta = (await api<{ data: unknown[]; meta: TargetsMeta }>(`/crm/sales-targets?filter[period]=${encodeURIComponent(t.period_key)}&filter[owner]=me`)).meta;
  const manage = meta.manage;
  const ended = t.health === "por_cerrar" || (t.status === "cerrada");
  const me = t.ranking.find((r) => r.me);
  const base = `/crm/sales-targets/${t.code}`;

  return (
    <>
      <BackLink href={`/crm/metas?period=${t.period_key}`}>Metas comerciales</BackLink>
      <PageHeader title={`${t.code} · ${t.scope_name}`} subtitle={<>{t.scope_label} · {periodLabel(t.period_key)} ({date(t.period_from)} – {date(t.period_to)}) · <Badge status={t.status}>{t.status_label}</Badge> <Badge status={t.health}>{t.health_label}</Badge></>}
        actions={manage && (t.status === "borrador" || t.status === "vigente") ? <Link href={`/crm/metas/${t.code}?adjust=1`} scroll={false} className={btnGhostCls}>Ajustar</Link> : undefined} />
      <div className="mb-4 overflow-x-auto"><Stepper steps={TARGET_FLOW} current={STEP[t.status] ?? 1} failed={t.status === "anulada"} /></div>
      {t.health === "por_cerrar" && <Notice tone="warning">El período terminó el {date(t.period_to)}: la meta está por cerrar. Al cerrarla el resultado queda congelado (MET-BR-06).</Notice>}
      {t.result && (t.result as { snapshot?: boolean }).snapshot && <Notice>Resultado histórico (snapshot): fuera de la muestra de reservas de la demo.</Notice>}

      {manage && (t.status === "borrador" || (t.status === "vigente" && t.health === "por_cerrar")) && (
        <Card title="Acciones" className="mb-4">
          <div className="grid gap-4 md:grid-cols-2 [&>*]:min-w-0">
            {t.status === "borrador" && <ActionForm path={`${base}/activate`} ifMatch={t.lock_version} submitLabel="Activar (Vigente)" />}
            {t.status === "borrador" && (
              <ActionForm path={`${base}/void`} ifMatch={t.lock_version} submitLabel="Anular" submitClass="rounded-md border border-rose-300 bg-white px-3 py-2 text-sm font-medium text-rose-800 hover:bg-rose-50">
                <Input name="reason" label="Motivo" required />
              </ActionForm>
            )}
            {t.status === "vigente" && (
              <ActionForm path={`${base}/close`} idempotent submitLabel="Cerrar período" confirm={`¿Cerrar ${t.code}? El resultado queda congelado.`}>
                <p className="text-sm text-slate-600">Cumplimiento actual {pct(t.pct.sales)}{t.bonus.amount > 0 ? ` · genera la comisión «Bono por meta» de ${pen(t.bonus.amount)} para Finanzas` : ""}.</p>
              </ActionForm>
            )}
          </div>
        </Card>
      )}

      <Kpis items={[
        { label: ended ? "Cumplimiento final" : "Cumplimiento", value: pct(t.pct.sales), hint: `${pen(t.actual.sales, 0)} de ${pen(t.goals.sales, 0)}` },
        { label: "Proyección", value: pct(t.projection.pct), hint: `${pen(t.projection.sales, 0)} · pipeline ponderado ${pen(t.projection.pipeline_weighted, 0)}` },
        { label: "Días", value: `${t.days.elapsed} / ${t.days.total}`, hint: `${t.days.remaining} restantes` },
        { label: "Bono", value: pen(t.bonus.amount, 0), hint: t.bonus.base ? `base ${pen(t.bonus.base, 0)}${t.bonus.tier ? ` · tramo ≥ ${t.bonus.tier.min} %` : ""}` : "sin bono" },
      ]} />

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {([["Ventas S/", t.actual.sales, t.goals.sales, t.pct.sales, true], ["Reservas", t.actual.bookings, t.goals.bookings, t.pct.bookings, false],
          ["Margen S/", t.actual.margin, t.goals.margin, t.pct.margin, true], ["Conversión %", t.actual.conversion, t.goals.conversion, t.pct.conversion, false]] as const).map(([label, actual, goal, value, money]) => (
          <div key={label} className="min-w-0 rounded-lg border border-slate-200 bg-white p-3">
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
            <p className="mt-1 text-lg font-semibold text-slate-900">{actual === null ? "—" : money ? pen(actual, 0) : actual} <span className="text-sm font-normal text-slate-500">/ {goal === null ? "sin meta" : money ? pen(goal, 0) : goal}</span></p>
            {goal !== null && <Bar value={value} health={t.health} label={label} />}
            <p className="mt-1 text-xs text-slate-500">{pct(value)}</p>
          </div>
        ))}
      </div>

      <div className="mb-4 grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
        <Card title={`Reservas que componen el avance (${t.reservations.length})`}>
          <Table head={["Reserva", "Cliente", "Estado", "Venta S/", "Margen S/"]} empty="Sin reservas en el período">
            {t.reservations.map((r) => (
              <tr key={r.id}><Td><Link href={`/reservas/${r.code}`} className="text-sky-800 hover:underline">{r.code}</Link><p className="text-xs text-slate-500">{dateTime(r.created_at)}</p></Td>
                <Td>{r.client}</Td><Td><Badge status={r.status}>{r.status_label}</Badge></Td><Td className="tabular-nums">{pen(r.sales_pen, 0)}</Td><Td className="tabular-nums">{pen(r.margin_pen, 0)}</Td></tr>
            ))}
          </Table>
        </Card>
        <Card title="Pipeline del período">
          <Table head={["Oportunidad", "Etapa", "Monto S/", "Prob.", "Ponderado"]} empty="Sin oportunidades abiertas con cierre en el período">
            {t.pipeline.map((o) => (
              <tr key={o.code}><Td>{o.code}<p className="text-xs text-slate-500">{o.title}</p></Td><Td>{o.stage}</Td><Td className="tabular-nums">{pen(o.amount_pen, 0)}</Td>
                <Td>{o.probability} %</Td><Td className="tabular-nums">{pen(o.weighted_pen, 0)}</Td></tr>
            ))}
          </Table>
        </Card>
      </div>

      <div className="mb-4 grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
        <Card title="Definición y bono">
          <Facts rows={[
            ["Alcance", `${t.scope_label} · ${t.scope_name}`], ["Período", `${periodLabel(t.period_key)} (${t.period_type})`],
            ["Metas", describe({ goal_sales_pen: t.goals.sales, goal_bookings: t.goals.bookings, goal_margin_pen: t.goals.margin, goal_conversion_pct: t.goals.conversion })],
            ["Bono al 100 %", t.goals.bonus !== null ? pen(t.goals.bonus, 0) : "Sin bono"],
            ["Tramos", t.bonus.tiers.map((x) => `≥ ${x.min} % paga ${x.pay} %`).join(" · ")],
            ["Comisión", t.commission ? (t.commission.can_view
              ? <Link key="c" href={`/finanzas/comisiones?code=${t.commission.code}`} className="text-sky-800 hover:underline">{t.commission.code} · PEN {t.commission.amount} · {t.commission.status}</Link>
              : `${t.commission.code} · PEN ${t.commission.amount} · ${t.commission.status}`) : "—"],
          ]} />
        </Card>
        {t.scope_type === "asesor" && (
          <Card title={me ? `Ranking · Su posición: ${me.position} de ${t.ranking.length}` : `Ranking ${periodLabel(t.period_key)}`}>
            <Table head={["#", "Asesor", "Avance", "Estado"]}>
              {t.ranking.map((r) => (
                <tr key={r.user_id} className={r.me ? "bg-sky-50" : undefined}><Td>{r.position}</Td><Td>{r.name}{r.sales !== undefined && <p className="text-xs text-slate-500">{pen(r.sales, 0)} / {pen(r.goal ?? 0, 0)}</p>}</Td>
                  <Td><div className="w-24"><Bar value={r.pct} health={r.health} label={`Avance de ${r.name}`} /></div><span className="text-xs">{pct(r.pct)}</span></Td><Td><Badge status={r.health}>{r.health_label}</Badge></Td></tr>
              ))}
            </Table>
          </Card>
        )}
      </div>

      <Card title="Historial de ajustes">
        <ol className="space-y-3 text-sm">
          {t.adjustments.map((a, i) => (
            <li key={i} className="border-l-2 border-slate-200 pl-3">
              <p className="font-medium text-slate-800">{ACTIONS[a.action] ?? a.action}{a.reason ? ` · ${a.reason}` : ""}</p>
              {(a.before || a.after) && <p className="break-words text-slate-600">{a.before ? `${describe(a.before)} → ` : ""}{describe(a.after)}</p>}
              <p className="text-xs text-slate-500">{a.actor ?? "Sistema"} · {dateTime(a.at)}</p>
            </li>
          ))}
        </ol>
      </Card>

      {adjust && manage && (t.status === "borrador" || t.status === "vigente") && (
        <AdjustDrawer t={t} close={`/crm/metas/${t.code}`} admin={meta.admin} lockPct={meta.policy?.lock_decrease_after_pct ?? 50} />
      )}
    </>
  );
}
