import Link from "@/components/Link";
import { api } from "@/lib/api";
import { catalogOptions, crmMeta, currentUser, fetchScoped, owners } from "@/lib/crm-server";
import { date, dateTime, money, qs, type Case, type Client, type HistoryRow, type Opportunity, type Paged, type Survey } from "@/lib/crm";
import { Badge, btnCls, btnGhostCls, Card, Drawer, Input, inputCls, Kpis, Notice, PageHeader, ScopeChip, Select, Table, Td, TextArea, Timeline } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { ExportButton } from "@/components/crm/ExportButton";
import { OpportunityForm } from "../oportunidades/OpportunityForm";

type Trip = { id: string; code: string; client_id: string; client_code: string; client_name: string; destination: string | null; end_date: string; status: string; survey: Survey | null };
type SurveyStats = { count: number; nps: number | null; average: number | null; distribution: number[]; promoters: number; passives: number; detractors: number };
type Segment = Client & { days_since_purchase: number | null; destinations: string[]; has_open_opportunity: boolean };
type CaseDetail = Case & { history: HistoryRow[] };
type Rebuy = { proposal: { client_id: string; title: string; destination_label: string; amount: number; currency: string; stage: string; expected_close_date: string; origin: string }; open_opportunities: Opportunity[]; requires_confirmation: boolean };
type Search = { owner?: string; status?: string; q?: string; segment?: string; recency?: string; destination?: string; case?: string; new?: string; rebuy?: string; client?: string; reservation?: string };

const PRIORITIES = ["baja", "media", "alta", "critica"].map((p) => ({ value: p, label: p[0].toUpperCase() + p.slice(1) }));
const RECENCY = [{ value: "le30", label: "≤ 30 días" }, { value: "gt90", label: "> 90 días" }, { value: "gt180", label: "> 180 días" }, { value: "none", label: "Sin compras" }];

export default async function AfterSalesPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const meta = await crmMeta();
  if (!meta.can.clients_manage) return <Notice tone="warning">Su rol no tiene acceso a Postventa (permiso crm.clients.manage).</Notice>;
  const advisors = meta.scope.supervisor ? await owners() : null;
  const href = (patch: Partial<Search>) => `/crm/postventa${qs({ ...sp, case: undefined, new: undefined, rebuy: undefined, client: undefined, reservation: undefined, ...patch })}`;
  const owner = { "filter[owner]": sp.owner };

  const [trips, surveys, cases, segments, clientOpts] = await Promise.all([
    api<{ data: Trip[] }>("/crm/after-sales/trips"),
    api<Paged<Survey, { stats: SurveyStats }>>("/crm/surveys?per_page=10"),
    api<Paged<Case, { kpis: { open: number } }>>(`/crm/cases${qs({ ...owner, "filter[status]": sp.status, "filter[q]": sp.q, per_page: "50" })}`),
    api<{ data: Segment[] }>(`/crm/loyalty/segments${qs({ ...owner, "filter[segment]": sp.segment, "filter[recency]": sp.recency, "filter[destination]": sp.destination })}`),
    api<{ data: { id: string; label: string }[] }>("/crm/activities/related-options?type=client"),
  ]);
  const st = surveys.meta.stats;
  const clients = clientOpts.data.map((c) => ({ value: c.id, label: c.label }));
  const maxBar = Math.max(1, ...st.distribution);

  const caseDetail = sp.case ? await fetchScoped<{ data: CaseDetail; meta: { permissions: { manage: boolean } } }>(`/crm/cases/${encodeURIComponent(sp.case)}`) : undefined;
  const rebuy = sp.rebuy ? await fetchScoped<{ data: Rebuy }>(`/crm/clients/${encodeURIComponent(sp.rebuy)}/rebuy-proposal`) : undefined;
  const me = rebuy ? await currentUser() : null;

  return (
    <>
      <PageHeader
        title="Postventa y fidelización"
        subtitle={<ScopeChip ownOnly={meta.scope.own_only} ownerName={advisors?.data.find((a) => a.id === sp.owner)?.name} />}
        actions={<>
          <Link href={href({ new: "survey" })} className={btnGhostCls}>Registrar encuesta NPS</Link>
          <Link href={href({ new: "case" })} className={btnCls}>Registrar reclamo/feedback</Link>
        </>}
      />
      <Kpis items={[
        { label: "Viajes por cerrar", value: trips.data.length },
        { label: "NPS", value: st.nps ?? "—", hint: `${st.count} encuestas` },
        { label: "Promedio", value: st.average ?? "—", hint: "escala 0–10" },
        { label: "Casos abiertos", value: cases.meta.kpis.open },
      ]} />
      {advisors && (
        <form className="mb-4 flex gap-2">
          <select name="owner" defaultValue={sp.owner ?? ""} className={`${inputCls} max-w-xs`} aria-label="Asesor">
            <option value="">Asesor: todos</option>
            {advisors.data.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
          <button className={btnGhostCls}>Filtrar</button>
        </form>
      )}

      <div className="mb-4 grid gap-4 lg:grid-cols-[1fr_380px] [&>*]:min-w-0">
        <Card title="Viajes recientes (completados o en curso)">
          <Table head={["Reserva", "Cliente", "Destino", "Fin", "Encuesta", ""]} empty="Sin viajes por cerrar (se completa con el módulo de Reservas)">
            {trips.data.map((t) => (
              <tr key={t.id}>
                <Td>{t.code}</Td>
                <Td><Link className="text-sky-800 hover:underline" href={`/crm/clientes/${t.client_code}`}>{t.client_name}</Link></Td>
                <Td>{t.destination ?? "—"}</Td><Td>{date(t.end_date)}</Td>
                <Td>{t.survey ? <Badge status={t.survey.category}>{t.survey.score}</Badge> : "Pendiente"}</Td>
                <Td>{!t.survey && <Link className="text-xs text-sky-800 hover:underline" href={href({ new: "survey", client: t.client_id, reservation: t.id })}>Registrar NPS</Link>}</Td>
              </tr>
            ))}
          </Table>
        </Card>
        <Card title="Distribución NPS">
          <div className="mb-3 flex h-3 overflow-hidden rounded" aria-label={`Promotores ${st.promoters}, pasivos ${st.passives}, detractores ${st.detractors}`}>
            {st.count > 0 && <>
              <div className="bg-green-600" style={{ width: `${(st.promoters / st.count) * 100}%` }} />
              <div className="bg-amber-400" style={{ width: `${(st.passives / st.count) * 100}%` }} />
              <div className="bg-rose-600" style={{ width: `${(st.detractors / st.count) * 100}%` }} />
            </>}
          </div>
          <p className="mb-3 text-xs text-slate-600">Promotores {st.promoters} · Pasivos {st.passives} · Detractores {st.detractors}</p>
          <div className="flex h-28 items-end gap-1">
            {st.distribution.map((n, score) => (
              <div key={score} className="flex flex-1 flex-col items-center gap-1" title={`${score}: ${n}`}>
                <span className="text-[10px] text-slate-500">{n || ""}</span>
                <div className={`w-full rounded-t ${score >= 9 ? "bg-green-600" : score >= 7 ? "bg-amber-400" : "bg-rose-600"}`} style={{ height: `${(n / maxBar) * 80}px` }} />
                <span className="text-[10px] text-slate-600">{score}</span>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <Card title="Casos de postventa" className="mb-4" actions={<ExportButton resource="crm.cases" filters={{ status: sp.status, owner: sp.owner, q: sp.q }} />}>
        <form className="mb-3 flex flex-wrap gap-2">
          {sp.owner && <input type="hidden" name="owner" value={sp.owner} />}
          <input name="q" defaultValue={sp.q} placeholder="Código, asunto o cliente" className={`${inputCls} max-w-xs`} />
          <select name="status" defaultValue={sp.status ?? ""} className={`${inputCls} max-w-xs`} aria-label="Estado">
            <option value="">Estado: todos</option>
            <option value="abierta,en_proceso">Pendientes</option>
            <option value="abierta">Abierta</option><option value="en_proceso">En proceso</option>
            <option value="resuelta">Resuelta</option><option value="cerrada">Cerrada</option>
          </select>
          <button className={btnGhostCls}>Filtrar</button>
        </form>
        <Table head={["Caso", "Cliente", "Tipo", "Prioridad", "Estado", "Origen", "Responsable", "Fecha"]}>
          {cases.data.map((c) => (
            <tr key={c.id}>
              <Td>{c.is_book
                ? <Link className="font-medium text-sky-800 hover:underline" href={`/crm/reclamaciones/${c.code}`}>{c.code} · {c.book_number}</Link>
                : <Link className="font-medium text-sky-800 hover:underline" href={href({ case: c.code })} scroll={false}>{c.code}</Link>}<br />{c.subject}</Td>
              <Td>{c.client.name ?? <span className="text-slate-500">Sin cliente vinculado</span>}</Td><Td>{c.type.label}</Td><Td><Badge status={c.priority} /></Td>
              <Td><Badge status={c.status}>{c.status_label}</Badge></Td><Td>{c.origin}</Td><Td>{c.owner?.name}</Td><Td>{dateTime(c.created_at)}</Td>
            </tr>
          ))}
        </Table>
      </Card>

      <Card title="Segmentación y recompra" className="mb-4" actions={<ExportButton resource="crm.loyalty_segments" filters={{ segment: sp.segment, recency: sp.recency, destination: sp.destination, owner: sp.owner }} />}>
        <form className="mb-3 flex flex-wrap gap-2">
          {sp.owner && <input type="hidden" name="owner" value={sp.owner} />}
          <select name="segment" defaultValue={sp.segment ?? ""} className={`${inputCls} max-w-xs`} aria-label="Segmento">
            <option value="">Segmento: todos</option>
            {catalogOptions(meta, "client_segment").map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
          <select name="recency" defaultValue={sp.recency ?? ""} className={`${inputCls} max-w-xs`} aria-label="Última compra">
            <option value="">Última compra: todas</option>
            {RECENCY.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
          <input name="destination" defaultValue={sp.destination} placeholder="Destino" className={`${inputCls} max-w-xs`} />
          <button className={btnGhostCls}>Filtrar</button>
        </form>
        <Table head={["Cliente", "Segmento", "Última compra", "Días", "Destinos", "Total", "NPS", ""]}>
          {segments.data.map((s) => (
            <tr key={s.id}>
              <Td><Link className="font-medium text-sky-800 hover:underline" href={`/crm/clientes/${s.code}`}>{s.code}</Link> · {s.name}</Td>
              <Td>{s.segment.label}</Td><Td>{date(s.last_purchase_date)}</Td><Td>{s.days_since_purchase ?? "—"}</Td>
              <Td>{s.destinations.join(", ") || "—"}</Td><Td>{money(s.total_pen)}</Td><Td>{s.nps ?? "—"}</Td>
              <Td>{meta.can.opportunities_manage && (
                <Link className="text-xs text-sky-800 hover:underline" href={href({ rebuy: s.code })} scroll={false}>
                  {s.has_open_opportunity ? "Recompra (tiene abierta)" : "Crear oportunidad de recompra"}
                </Link>
              )}</Td>
            </tr>
          ))}
        </Table>
      </Card>

      <Card title="Últimas encuestas">
        <Table head={["Encuesta", "Cliente", "Puntaje", "Canal", "Comentario", "Fecha"]}>
          {surveys.data.map((s) => (
            <tr key={s.id}>
              <Td>{s.code}</Td><Td>{s.client.name}</Td><Td><Badge status={s.category}>{s.score}</Badge></Td>
              <Td>{s.channel.label}</Td><Td>{s.comment ?? "—"}</Td><Td>{dateTime(s.responded_at)}</Td>
            </tr>
          ))}
        </Table>
      </Card>

      {sp.new === "survey" && (
        <Drawer title="Registrar encuesta NPS" close={href({})}>
          <ActionForm path="/crm/surveys" idempotent submitLabel="Registrar encuesta" success="Encuesta registrada">
            <Select name="client_id" label="Cliente *" options={clients} defaultValue={sp.client} required placeholder="Seleccione" />
            {sp.reservation && <input type="hidden" name="reservation_id" value={sp.reservation} />}
            <Select name="channel_code" label="Canal *" options={catalogOptions(meta, "survey_channel")} required placeholder="Seleccione" />
            <Input name="score" type="number" min={0} max={10} label="Puntaje 0–10 *" required />
            <TextArea name="comment" label="Comentario" />
            <p className="text-xs text-slate-500">Un puntaje de 0 a 6 (detractor) abre un caso y una llamada de seguimiento automáticamente.</p>
          </ActionForm>
        </Drawer>
      )}
      {sp.new === "case" && (
        <Drawer title="Registrar reclamo / feedback" close={href({})}>
          <ActionForm path="/crm/cases" idempotent submitLabel="Registrar caso" redirectTo="/crm/postventa?case={data.code}">
            <Select name="client_id" label="Cliente *" options={clients} required placeholder="Seleccione" />
            <div className="grid gap-3 sm:grid-cols-2">
              <Select name="type_code" label="Tipo *" options={catalogOptions(meta, "case_type")} required placeholder="Seleccione" />
              <Select name="priority" label="Prioridad *" options={PRIORITIES} defaultValue="media" required />
            </div>
            <Input name="subject" label="Asunto *" required maxLength={200} />
            <TextArea name="description" label="Descripción *" required />
            {advisors && <Select name="owner_id" label="Responsable" options={advisors.data.map((a) => ({ value: a.id, label: a.name }))} placeholder="Responsable del cliente" />}
          </ActionForm>
        </Drawer>
      )}
      {caseDetail === null && <Drawer title="Caso" close={href({})}><Notice tone="warning">Registro fuera de su cartera.</Notice></Drawer>}
      {caseDetail && <CaseDrawer c={caseDetail.data} manage={caseDetail.meta.permissions.manage} close={href({})} />}
      {rebuy === null && <Drawer title="Recompra" close={href({})}><Notice tone="warning">Cliente fuera de su cartera.</Notice></Drawer>}
      {rebuy && (
        <Drawer title="Oportunidad de recompra" close={href({})}>
          {rebuy.data.requires_confirmation && (
            <Notice tone="warning">
              El cliente ya tiene oportunidades abiertas: {rebuy.data.open_opportunities.map((o) => (
                <Link key={o.id} className="underline" href={`/crm/oportunidades/${o.code}`}>{o.code} </Link>
              ))}. Confirme que se trata de un viaje distinto antes de crear otra.
            </Notice>
          )}
          <OpportunityForm clients={clients} owners={null} fixedOwner={me?.name ?? null}
            today={new Intl.DateTimeFormat("en-CA", { timeZone: "America/Lima" }).format(new Date())}
            preset={{ ...rebuy.data.proposal, client_id: rebuy.data.proposal.client_id }} />
        </Drawer>
      )}
    </>
  );
}

function CaseDrawer({ c, manage, close }: { c: CaseDetail; manage: boolean; close: string }) {
  return (
    <Drawer title={`${c.code} · ${c.type.label}`} close={close}>
      <div className="mb-4 space-y-1 text-sm">
        <p className="font-medium text-slate-900">{c.subject}</p>
        <p className="flex flex-wrap items-center gap-2 text-slate-600"><Badge status={c.status}>{c.status_label}</Badge> <Badge status={c.priority} /> {c.client.name} · {c.owner?.name}</p>
        <p className="whitespace-pre-line rounded bg-slate-50 p-2">{c.description}</p>
        {c.resolution && <p className="rounded bg-emerald-50 p-2">Resolución: {c.resolution}</p>}
      </div>
      {!manage && <Notice tone="warning">Su rol no puede gestionar casos de postventa.</Notice>}
      {manage && (
        <div className="mb-4 space-y-3">
          {c.status === "abierta" && <ActionForm path={`/crm/cases/${c.code}/start`} ifMatch={c.lock_version} submitLabel="Iniciar atención" success="Caso en proceso" />}
          {c.status === "en_proceso" && (
            <ActionForm path={`/crm/cases/${c.code}/resolve`} ifMatch={c.lock_version} submitLabel="Resolver" success="Caso resuelto">
              <TextArea name="resolution" label="Resolución *" required />
            </ActionForm>
          )}
          {c.status === "resuelta" && <ActionForm path={`/crm/cases/${c.code}/close`} ifMatch={c.lock_version} submitLabel="Cerrar caso" success="Caso cerrado" />}
          {c.status !== "cerrada" && (
            <ActionForm path={`/crm/cases/${c.code}/follow-ups`} submitLabel="Agregar seguimiento" success="Seguimiento registrado"
              submitClass="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
              <TextArea name="message" label="Nota de seguimiento *" required />
            </ActionForm>
          )}
        </div>
      )}
      <Card title="Bitácora">
        <ol className="space-y-3 text-sm">
          {(c.events ?? []).map((e, i) => (
            <li key={i} className="border-l-2 border-slate-200 pl-3">
              <p className="text-slate-800">{e.message}</p>
              <p className="text-xs text-slate-500">{e.user?.name ?? "Sistema"} · {dateTime(e.created_at)}{e.to_status ? ` · ${e.from_status ?? "—"} → ${e.to_status}` : ""}</p>
            </li>
          ))}
        </ol>
      </Card>
      <div className="mt-4"><Card title="Auditoría"><Timeline rows={c.history} /></Card></div>
    </Drawer>
  );
}
