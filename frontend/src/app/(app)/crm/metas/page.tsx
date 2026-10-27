import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import { Badge, btnCls, btnGhostCls, Card, Drawer, Input, inputCls, Kpis, Notice, PageHeader, Select, Table, Td } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { Bar } from "@/components/marketing/ui";
import { AdjustDrawer } from "@/components/marketing/targets";
import { periodLabel, pct, pen, type RankRow, type Target, type TargetDetail, type TargetsMeta } from "@/lib/marketing";

type Search = { period?: string; advisor?: string; new?: string; adjust?: string; teams?: string };

/** Metas comerciales (spec 14 §2): vista de gestión (Manager/Admin) o vista propia (Ventas, MET-BR-07). */
export default async function TargetsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const res = await api<{ data: Target[]; meta: TargetsMeta }>(`/crm/sales-targets${qs({ "filter[period]": sp.period, "filter[advisor]": sp.advisor })}`);
  return res.meta.manage ? <ManagerView sp={sp} rows={res.data} meta={res.meta} /> : <SalesView rows={res.data} meta={res.meta} sp={sp} />;
}

function PeriodForm({ meta, sp, advisors }: { meta: TargetsMeta; sp: Search; advisors?: { id: string; name: string }[] }) {
  return (
    <form className="mb-4 flex flex-wrap gap-2">
      <select name="period" defaultValue={meta.period} aria-label="Período" className={`${inputCls} max-w-[14rem]`}>
        {meta.periods.map((p) => <option key={p} value={p}>{periodLabel(p)}</option>)}
      </select>
      {advisors && (
        <select name="advisor" defaultValue={sp.advisor ?? ""} aria-label="Asesor" className={`${inputCls} max-w-[14rem]`}>
          <option value="">Asesor: todos</option>
          {advisors.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
        </select>
      )}
      <button className={btnGhostCls}>Ver</button>
    </form>
  );
}

function Indicator({ label, actual, goal, value, projection, health, money = false }: { label: string; actual: number | null; goal: number | null; value: number | null; projection?: number | null; health: string; money?: boolean }) {
  const fmt = (v: number | null) => (v === null ? "—" : money ? pen(v, 0) : label.startsWith("Conversión") ? pct(v) : String(v));
  return (
    <div className="min-w-0 rounded-lg border border-slate-200 bg-white p-3">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className="mt-1 text-lg font-semibold text-slate-900">{fmt(actual)} <span className="text-sm font-normal text-slate-500">/ {goal === null ? "sin meta" : fmt(goal)}</span></p>
      {goal !== null && <Bar value={value} health={health} marker={projection} label={label} />}
      <p className="mt-1 text-xs text-slate-500">{pct(value)}{projection !== undefined && projection !== null ? ` · proyección ${pct(projection)}` : ""}</p>
    </div>
  );
}

function RankingTable({ rows, money }: { rows: RankRow[]; money: boolean }) {
  return (
    <Table head={["#", "Asesor", "Avance", ...(money ? ["Real", "Meta", "Bono est."] : []), "Proyección", "Estado"]} empty="Sin metas por asesor en el período">
      {rows.map((r) => (
        <tr key={r.user_id} className={r.me ? "bg-sky-50" : undefined}>
          <Td>{r.position}</Td>
          <Td>{r.name}{r.me && <span className="ml-1 text-xs text-sky-800">(usted)</span>}</Td>
          <Td><div className="w-28"><Bar value={r.pct} health={r.health} label={`Avance de ${r.name}`} /></div><span className="text-xs">{pct(r.pct)}</span></Td>
          {money && <><Td className="tabular-nums">{pen(r.sales ?? 0, 0)}</Td><Td className="tabular-nums">{pen(r.goal ?? 0, 0)}</Td><Td className="tabular-nums">{pen(r.bonus ?? 0, 0)}</Td></>}
          <Td>{pct(r.projection_pct)}</Td>
          <Td><Badge status={r.health}>{r.health_label}</Badge></Td>
        </tr>
      ))}
    </Table>
  );
}

async function ManagerView({ sp, rows, meta }: { sp: Search; rows: Target[]; meta: TargetsMeta }) {
  const opts = meta.options!;
  const ranking = (await api<{ data: RankRow[] }>(`/crm/sales-targets/ranking?period=${encodeURIComponent(meta.period)}`)).data;
  const advisorsRows = rows.filter((r) => r.scope_type === "asesor");
  const groupRows = rows.filter((r) => r.scope_type !== "asesor");
  const teams = groupRows.some((r) => r.scope_type === "equipo") || sp.teams ? (await api<{ data: { id: string; code: string; name: string; leader: string; members: { user: string; user_id: string; valid_to: string | null }[] }[] }>("/crm/sales-teams")).data : [];
  const individualGoal = (ids: string[]) => advisorsRows.filter((r) => ids.includes(r.scope_id) && r.status !== "anulada").reduce((s, r) => s + r.goals.sales, 0);
  const href = (patch: Partial<Search>) => `/crm/metas${qs({ period: sp.period, advisor: sp.advisor, ...patch })}`;
  const adjusting = sp.adjust ? rows.find((r) => r.code === sp.adjust) ?? meta.history?.find((r) => r.code === sp.adjust) : undefined;
  const k = meta.kpis;
  const scopeType = sp.new === "sede" || sp.new === "equipo" ? sp.new : "asesor";
  const scopeOptions = scopeType === "sede" ? opts.sedes.map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` }))
    : scopeType === "equipo" ? opts.teams.map((t) => ({ value: t.id, label: `${t.code} · ${t.name}` })) : opts.advisors.map((a) => ({ value: a.id, label: a.name }));

  return (
    <>
      <PageHeader title="Metas comerciales" subtitle={`Período: ${periodLabel(meta.period)} · ventas = total de reservas creadas en el período (S/)`}
        actions={<>
          <Link href={href({ teams: "1" })} className={btnGhostCls} scroll={false}>Equipos</Link>
          <Link href={href({ new: "asesor" })} className={btnCls} scroll={false}>Nueva meta</Link>
        </>} />
      <PeriodForm meta={meta} sp={sp} advisors={opts.advisors} />
      <Kpis items={[
        { label: "Meta asesores", value: pen(k.goal, 0) }, { label: "Real", value: pen(k.actual, 0), hint: k.goal ? `${pct((100 * k.actual) / k.goal)} de la meta` : undefined },
        { label: "Proyección", value: pen(k.projection, 0), hint: `${k.at_risk} asesor(es) en riesgo` }, { label: "Bonos estimados", value: pen(k.bonus, 0) },
      ]} />

      <Card title="Metas por asesor" className="mb-4">
        <Table head={["Meta", "Asesor", "Meta S/", "Real", "Avance", "Proyección", "Reservas", "Margen", "Conversión", "Estado", ""]} empty="Sin metas por asesor en el período">
          {advisorsRows.map((t) => (
            <tr key={t.id}>
              <Td><Link href={`/crm/metas/${t.code}`} className="font-medium text-sky-800 hover:underline">{t.code}</Link></Td>
              <Td>{t.scope_name}</Td>
              <Td className="tabular-nums">{pen(t.goals.sales, 0)}</Td>
              <Td className="tabular-nums">{pen(t.actual.sales, 0)}</Td>
              <Td><div className="w-28"><Bar value={t.pct.sales} health={t.health} marker={t.projection.pct} label={`Avance ${t.code}`} /></div><span className="text-xs">{pct(t.pct.sales)}</span></Td>
              <Td className="tabular-nums">{pen(t.projection.sales, 0)}<p className="text-xs text-slate-500">{pct(t.projection.pct)}</p></Td>
              <Td className="tabular-nums">{t.actual.bookings}{t.goals.bookings !== null ? ` / ${t.goals.bookings}` : ""}</Td>
              <Td className="tabular-nums">{pen(t.actual.margin, 0)}</Td>
              <Td>{pct(t.actual.conversion)}</Td>
              <Td><Badge status={t.status}>{t.status_label}</Badge> <Badge status={t.health}>{t.health_label}</Badge></Td>
              <Td>{(t.status === "borrador" || t.status === "vigente") && <Link href={href({ adjust: t.code })} scroll={false} className="text-xs text-sky-800 hover:underline">Ajustar</Link>}</Td>
            </tr>
          ))}
        </Table>
      </Card>

      <div className="mb-4 grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
        <Card title="Ranking del período"><RankingTable rows={ranking} money /></Card>
        <Card title="Metas de sede y equipo">
          {groupRows.length === 0 ? <p className="text-sm text-slate-500">Sin metas de sede o equipo en el período.</p> : (
            <ul className="space-y-3 text-sm">
              {groupRows.map((t) => {
                const team = teams.find((x) => x.id === t.scope_id);
                const covered = team ? individualGoal(team.members.filter((m) => !m.valid_to).map((m) => m.user_id)) : t.scope_type === "sede" ? null : 0;
                return (
                  <li key={t.id} className="min-w-0">
                    <div className="flex flex-wrap justify-between gap-2">
                      <Link href={`/crm/metas/${t.code}`} className="font-medium text-sky-800 hover:underline">{t.code} · {t.scope_label} {t.scope_name}</Link>
                      <span className="tabular-nums">{pen(t.actual.sales, 0)} / {pen(t.goals.sales, 0)}</span>
                    </div>
                    <Bar value={t.pct.sales} health={t.health} marker={t.projection.pct} label={`Avance ${t.code}`} />
                    <p className="text-xs text-slate-500">{pct(t.pct.sales)} · {t.health_label}
                      {covered !== null && ` · cobertura de metas individuales ${pct(t.goals.sales ? (100 * covered) / t.goals.sales : null)}`}</p>
                  </li>
                );
              })}
            </ul>
          )}
        </Card>
      </div>

      <Card title="Historial: metas cerradas y por cerrar">
        <Table head={["Meta", "Alcance", "Período", "Meta S/", "Resultado", "Estado", ""]} empty="Sin períodos terminados">
          {(meta.history ?? []).map((t) => (
            <tr key={t.id}>
              <Td><Link href={`/crm/metas/${t.code}`} className="font-medium text-sky-800 hover:underline">{t.code}</Link></Td>
              <Td>{t.scope_name}</Td>
              <Td>{periodLabel(t.period_key)}</Td>
              <Td className="tabular-nums">{pen(t.goals.sales, 0)}</Td>
              <Td className="tabular-nums">{pen(t.actual.sales, 0)} · {pct(t.pct.sales)}</Td>
              <Td>{t.status === "vigente" ? <Badge status="por_cerrar">Por cerrar</Badge> : <Badge status={t.health}>{t.health_label}</Badge>}</Td>
              <Td>{t.status === "vigente" && (
                <ActionForm path={`/crm/sales-targets/${t.code}/close`} idempotent inline submitLabel="Cerrar período" confirm={`¿Cerrar ${t.code}? El resultado queda congelado.`}
                  submitClass="rounded border border-sky-300 px-2 py-1 text-xs text-sky-800 hover:bg-sky-50" />
              )}</Td>
            </tr>
          ))}
        </Table>
      </Card>

      {sp.new && (
        <Drawer title="Nueva meta" close={href({})}>
          <nav className="mb-3 flex gap-2 text-sm" aria-label="Alcance">
            {(["asesor", "sede", "equipo"] as const).map((s) => (
              <Link key={s} href={href({ new: s })} scroll={false} className={`rounded-full px-3 py-1 ring-1 ${s === scopeType ? "bg-sky-700 text-white ring-sky-700" : "ring-slate-300"}`}>
                {s === "asesor" ? "Asesor" : s === "sede" ? "Sede" : "Equipo"}
              </Link>
            ))}
          </nav>
          <ActionForm path="/crm/sales-targets" submitLabel="Crear meta" redirectTo="/crm/metas/{data.code}">
            <input type="hidden" name="scope_type" value={scopeType} />
            <Select name="scope_id" label={scopeType === "asesor" ? "Asesor" : scopeType === "sede" ? "Sede" : "Equipo"} options={scopeOptions} placeholder="Elegir…" required />
            <Select name="period_type" label="Tipo de período" options={[{ value: "mes", label: "Mes" }, { value: "trimestre", label: "Trimestre" }]} defaultValue="mes" />
            <Input name="period_key" label="Período (AAAA-MM o AAAA-Qn)" defaultValue={meta.periods.find((p) => p > meta.period && !p.includes("Q")) ?? meta.period} required />
            <Input name="goal_sales_pen" label="Meta de ventas S/ (obligatoria)" type="number" min={1} step="0.01" required />
            <Input name="goal_bookings" label="Meta de reservas" type="number" min={0} />
            <Input name="goal_margin_pen" label="Meta de margen S/" type="number" min={0} step="0.01" />
            <Input name="goal_conversion_pct" label="Meta de conversión %" type="number" min={0} max={100} step="0.1" />
            {scopeType === "asesor" && <Input name="bonus_amount" label="Bono S/ al 100 % (por tramos)" type="number" min={0} step="0.01" />}
            <input type="hidden" name="activate" value="false" />
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="activate" value="true" /> Activar al crear (Vigente)</label>
          </ActionForm>
        </Drawer>
      )}

      {adjusting && <AdjustDrawer t={adjusting} close={href({})} admin={meta.admin} lockPct={meta.policy?.lock_decrease_after_pct ?? 50} />}

      {sp.teams && (
        <Drawer title="Equipos de ventas" close={href({})}>
          <ul className="mb-5 space-y-2 text-sm">
            {teams.map((t) => (
              <li key={t.id} className="rounded-md border border-slate-200 p-2">
                <p className="font-medium">{t.code} · {t.name}</p>
                <p className="text-xs text-slate-600">Líder: {t.leader} · Integrantes: {t.members.filter((m) => !m.valid_to).map((m) => m.user).join(", ") || "—"}</p>
              </li>
            ))}
          </ul>
          <h3 className="mb-2 text-sm font-semibold text-slate-800">Nuevo equipo</h3>
          <ActionForm path="/crm/sales-teams" method="PUT" submitLabel="Guardar equipo">
            <Input name="name" label="Nombre" required />
            <Select name="leader_id" label="Líder" options={[...opts.advisors].map((a) => ({ value: a.id, label: a.name }))} placeholder="Elegir…" />
            <fieldset className="text-sm"><legend className="mb-1 font-medium text-slate-700">Integrantes</legend>
              {opts.advisors.map((a) => <label key={a.id} className="mr-3 inline-flex items-center gap-1"><input type="checkbox" name="members[]" value={a.id} /> {a.name}</label>)}
            </fieldset>
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}

async function SalesView({ rows, meta, sp }: { rows: Target[]; meta: TargetsMeta; sp: Search }) {
  const own = rows.find((r) => r.scope_type === "asesor");
  const detail = own ? (await api<{ data: TargetDetail }>(`/crm/sales-targets/${own.code}`)).data : null;
  const me = detail?.ranking.find((r) => r.me);
  return (
    <>
      <PageHeader title="Mis metas" subtitle={`Período: ${periodLabel(meta.period)} · ventas = total de sus reservas creadas en el período (S/)`} />
      <PeriodForm meta={meta} sp={sp} />
      {!own || !detail ? <Notice>No tiene una meta vigente para {periodLabel(meta.period)}.</Notice> : (
        <>
          <Kpis items={[
            { label: "Cumplimiento", value: pct(own.pct.sales), hint: `${pen(own.actual.sales, 0)} de ${pen(own.goals.sales, 0)}` },
            { label: "Proyección", value: pct(own.projection.pct), hint: pen(own.projection.sales, 0) },
            { label: "Días restantes", value: own.days.remaining, hint: `${own.days.elapsed} de ${own.days.total} transcurridos` },
            { label: "Bono estimado", value: pen(own.bonus.amount, 0), hint: own.bonus.tier ? `tramo ≥ ${own.bonus.tier.min} % paga ${own.bonus.tier.pay} %` : own.bonus.base ? "aún sin tramo" : "sin bono" },
          ]} />
          <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Indicator label="Ventas S/" actual={own.actual.sales} goal={own.goals.sales} value={own.pct.sales} projection={own.projection.pct} health={own.health} money />
            <Indicator label="Reservas" actual={own.actual.bookings} goal={own.goals.bookings} value={own.pct.bookings} health={own.health} />
            <Indicator label="Margen S/" actual={own.actual.margin} goal={own.goals.margin} value={own.pct.margin} health={own.health} money />
            <Indicator label="Conversión %" actual={own.actual.conversion} goal={own.goals.conversion} value={own.pct.conversion} health={own.health} />
          </div>
          <div className="mb-4 grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
            <Card title={me ? `Ranking · Su posición: ${me.position} de ${detail.ranking.length}` : "Ranking"}>
              <RankingTable rows={detail.ranking} money={false} />
              <p className="mt-2 text-xs text-slate-500">Se muestra el avance (%) de cada asesor; los montos y bonos de otros asesores son confidenciales.</p>
            </Card>
            <Card title="Bono por tramos">
              <ul className="space-y-1 text-sm">
                {own.bonus.tiers.map((t) => (
                  <li key={t.min} className={`flex justify-between ${own.bonus.tier?.min === t.min ? "font-semibold text-emerald-800" : ""}`}>
                    <span>Cumplimiento ≥ {t.min} %</span><span>paga {t.pay} %{own.bonus.base ? ` = ${pen((own.bonus.base * t.pay) / 100, 0)}` : ""}</span>
                  </li>
                ))}
              </ul>
            </Card>
          </div>
          <div className="mb-4 grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
            <Card title={`Reservas que suman (${detail.reservations.length})`}>
              <Table head={["Reserva", "Cliente", "Venta S/", "Margen S/"]} empty="Aún sin reservas en el período">
                {detail.reservations.map((r) => (
                  <tr key={r.id}><Td><Link href={`/reservas/${r.code}`} className="text-sky-800 hover:underline">{r.code}</Link></Td><Td>{r.client}</Td>
                    <Td className="tabular-nums">{pen(r.sales_pen, 0)}</Td><Td className="tabular-nums">{pen(r.margin_pen, 0)}</Td></tr>
                ))}
              </Table>
            </Card>
            <Card title="Pipeline del período">
              <Table head={["Oportunidad", "Etapa", "Monto S/", "Ponderado"]} empty="Sin oportunidades con cierre en el período">
                {detail.pipeline.map((o) => (
                  <tr key={o.code}><Td>{o.code}<p className="text-xs text-slate-500">{o.title}</p></Td><Td>{o.stage}</Td>
                    <Td className="tabular-nums">{pen(o.amount_pen, 0)}</Td><Td className="tabular-nums">{pen(o.weighted_pen, 0)}</Td></tr>
                ))}
              </Table>
            </Card>
          </div>
        </>
      )}
      <Card title="Mis otros períodos">
        <Table head={["Meta", "Período", "Meta S/", "Resultado", "Estado"]} empty="Sin otros períodos">
          {(meta.own_history ?? []).filter((t) => t.code !== own?.code).map((t) => (
            <tr key={t.id}>
              <Td><Link href={`/crm/metas/${t.code}`} className="text-sky-800 hover:underline">{t.code}</Link></Td>
              <Td>{periodLabel(t.period_key)}<p className="text-xs text-slate-500">{date(t.period_from)} – {date(t.period_to)}</p></Td>
              <Td className="tabular-nums">{pen(t.goals.sales, 0)}</Td>
              <Td className="tabular-nums">{pct(t.pct.sales)}</Td>
              <Td><Badge status={t.status === "cerrada" ? t.health : t.status}>{t.status === "cerrada" ? t.health_label : t.status_label}</Badge></Td>
            </tr>
          ))}
        </Table>
      </Card>
    </>
  );
}
