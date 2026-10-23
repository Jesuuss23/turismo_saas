import Link from "@/components/Link";
import { api } from "@/lib/api";
import { catalogOptions, crmMeta, fetchScoped, owners } from "@/lib/crm-server";
import { dateTime, qs, type Activity, type HistoryRow, type Paged } from "@/lib/crm";
import { Badge, btnCls, btnGhostCls, Card, Drawer, inputCls, Kpis, Notice, PageHeader, ScopeChip, Select, TextArea, Timeline } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { CompleteActivityForm, NewActivityForm, RescheduleForm } from "@/components/crm/ActivityForms";

type ActKpis = { overdue: number; today: number; next_7_days: number; completed_7_days: number };
type Detail = Activity & { related_label: { id: string; code: string; name: string } | null; history: HistoryRow[] };
type Search = { view?: string; type?: string; owner?: string; week?: string; code?: string; new?: string };

const GROUPS = [
  { status: "vencida", title: "Vencidas" },
  { status: "hoy", title: "Hoy" },
  { status: "proximas", title: "Próximas" },
  { status: "completada", title: "Completadas" },
] as const;

const RELATED_PATH: Record<string, string> = { lead: "/crm/leads", opportunity: "/crm/oportunidades", client: "/crm/clientes" };
const DAY = 86_400_000;

/** Fecha (YYYY-MM-DD) en Lima. */
function limaDay(d: Date) {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "America/Lima" }).format(d);
}

/** Lunes de la semana de `day` (YYYY-MM-DD). */
function mondayOf(day: string) {
  const d = new Date(`${day}T12:00:00Z`);
  return limaDay(new Date(d.getTime() - ((d.getUTCDay() + 6) % 7) * DAY));
}

function addDays(day: string, n: number) {
  return new Date(new Date(`${day}T12:00:00Z`).getTime() + n * DAY).toISOString().slice(0, 10);
}

export default async function ActivitiesPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const view = sp.view === "semana" ? "semana" : "lista";
  const meta = await crmMeta();
  const advisors = meta.scope.supervisor ? await owners(true) : null;
  const base = { "filter[type]": sp.type, "filter[owner]": sp.owner };
  const href = (patch: Partial<Search>) => `/crm/actividades${qs({ ...sp, code: undefined, new: undefined, ...patch })}`;

  const groups = view === "lista"
    ? await Promise.all(GROUPS.map((g) => api<Paged<Activity, { kpis: ActKpis }>>(`/crm/activities${qs({ ...base, "filter[status]": g.status, per_page: "50" })}`)))
    : null;
  const week = mondayOf(sp.week && /^\d{4}-\d{2}-\d{2}$/.test(sp.week) ? sp.week : limaDay(new Date()));
  const weekRes = view === "semana"
    ? await api<Paged<Activity, { kpis: ActKpis }>>(`/crm/activities${qs({ ...base, "filter[from]": week, "filter[to]": addDays(week, 6), per_page: "100" })}`)
    : null;
  const k = (groups?.[0] ?? weekRes)!.meta.kpis;

  const detail = sp.code ? await fetchScoped<{ data: Detail }>(`/crm/activities/${encodeURIComponent(sp.code)}`) : undefined;

  return (
    <>
      <PageHeader
        title="Actividades"
        subtitle={<ScopeChip ownOnly={meta.scope.own_only} ownerName={advisors?.data.find((a) => a.id === sp.owner)?.name} />}
        actions={<>
          <Link href={href({ view: view === "lista" ? "semana" : "lista" })} className={btnGhostCls}>Ver {view === "lista" ? "semana" : "lista"}</Link>
          <Link href={href({ new: "1" })} className={btnCls}>Nueva actividad</Link>
        </>}
      />
      <Kpis items={[
        { label: "Vencidas", value: k.overdue },
        { label: "Hoy", value: k.today },
        { label: "Próximos 7 días", value: k.next_7_days },
        { label: "Completadas (7 días)", value: k.completed_7_days },
      ]} />
      {k.overdue + k.today > 0 && <Notice tone="warning">{k.overdue + k.today} actividad(es) para hoy o vencidas. Los recordatorios se envían cada hora.</Notice>}

      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <input type="hidden" name="view" value={view} />
        {view === "semana" && <input type="hidden" name="week" value={week} />}
        <select name="type" defaultValue={sp.type ?? ""} className={`${inputCls} max-w-xs`} aria-label="Tipo">
          <option value="">Tipo: todos</option>
          {catalogOptions(meta, "activity_type").map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
        {advisors && (
          <select name="owner" defaultValue={sp.owner ?? ""} className={`${inputCls} max-w-xs`} aria-label="Responsable">
            <option value="">Responsable: todos</option>
            {advisors.data.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
        )}
        <button className={btnGhostCls}>Filtrar</button>
      </form>

      {groups && (
        <div className="grid gap-4 lg:grid-cols-2">
          {GROUPS.map((g, i) => (
            <Card key={g.status} title={`${g.title} (${groups[i].data.length}${groups[i].meta.next_cursor ? "+" : ""})`}>
              <ActivityList items={groups[i].data} link={(code) => href({ code })} />
            </Card>
          ))}
        </div>
      )}

      {weekRes && (
        <>
          <div className="mb-3 flex items-center gap-2 text-sm">
            <Link className={btnGhostCls} href={href({ week: addDays(week, -7) })}>← Semana anterior</Link>
            <Link className={btnGhostCls} href={href({ week: undefined })}>Esta semana</Link>
            <Link className={btnGhostCls} href={href({ week: addDays(week, 7) })}>Semana siguiente →</Link>
            <span className="text-slate-600">Semana del {week}</span>
          </div>
          <div className="grid gap-2 md:grid-cols-7">
            {Array.from({ length: 7 }, (_, d) => addDays(week, d)).map((day) => {
              const items = weekRes.data.filter((a) => limaDay(new Date(a.due_at)) === day);
              return (
                <section key={day} className="min-h-32 rounded-lg border border-slate-200 bg-white p-2">
                  <h3 className={`mb-2 text-xs font-semibold uppercase ${day === limaDay(new Date()) ? "text-sky-800" : "text-slate-500"}`}>
                    {new Intl.DateTimeFormat("es-PE", { weekday: "short", day: "numeric", timeZone: "UTC" }).format(new Date(`${day}T12:00:00Z`))}
                  </h3>
                  <ul className="space-y-1">
                    {items.map((a) => (
                      <li key={a.id}>
                        <Link href={href({ code: a.code })} className="block rounded bg-slate-50 p-1.5 text-xs hover:bg-sky-50">
                          <span className="font-medium">{new Intl.DateTimeFormat("es-PE", { timeStyle: "short", timeZone: "America/Lima" }).format(new Date(a.due_at))}</span> {a.subject}
                          <span className="mt-0.5 block"><Badge status={a.status} /></span>
                        </Link>
                      </li>
                    ))}
                  </ul>
                </section>
              );
            })}
          </div>
        </>
      )}

      {sp.new && (
        <Drawer title="Nueva actividad" close={href({})}>
          <NewActivityForm owners={advisors?.data.map((a) => ({ value: a.id, label: `${a.name} (${a.role})` })) ?? null} />
          {meta.scope.own_only && <p className="mt-2 text-xs text-slate-500">Responsable: usted. La reasignación la realiza Gerencia.</p>}
        </Drawer>
      )}
      {detail === null && (
        <Drawer title="Actividad" close={href({})}><Notice tone="warning">Registro fuera de su cartera.</Notice></Drawer>
      )}
      {detail && <ActivityDrawer a={detail.data} close={href({})} advisors={advisors?.data ?? null} />}
    </>
  );
}

function ActivityList({ items, link }: { items: Activity[]; link: (code: string) => string }) {
  if (!items.length) return <p className="text-sm text-slate-500">Sin actividades.</p>;
  return (
    <ul className="divide-y divide-slate-100 text-sm">
      {items.map((a) => (
        <li key={a.id} className="flex flex-wrap items-start justify-between gap-2 py-2">
          <span>
            <Link href={link(a.code)} className="font-medium text-sky-800 hover:underline">{a.code}</Link> · {a.type_label} · {a.subject}
            <span className="block text-xs text-slate-500">{a.owner?.name}{a.auto_closed ? " · cerrada automáticamente" : ""}</span>
          </span>
          <span className="flex items-center gap-2 text-slate-600">{dateTime(a.completed_at ?? a.due_at)} <Badge status={a.status} /></span>
        </li>
      ))}
    </ul>
  );
}

function ActivityDrawer({ a, close, advisors }: { a: Detail; close: string; advisors: { id: string; name: string; role: string }[] | null }) {
  const open = a.status !== "completada";
  const rel = a.related && a.related_label;
  return (
    <Drawer title={`${a.code} · ${a.type_label}`} close={close}>
      <div className="mb-4 space-y-1 text-sm">
        <p className="font-medium text-slate-900">{a.subject}</p>
        <p className="flex items-center gap-2 text-slate-600"><Badge status={a.status} /> {dateTime(a.due_at)} · {a.owner?.name}</p>
        {rel && <p>Relacionada: <Link className="text-sky-800 hover:underline" href={`${RELATED_PATH[a.related!.type] ?? "#"}/${a.related_label!.code}`}>{a.related_label!.code} · {a.related_label!.name}</Link></p>}
        {a.description && <p className="whitespace-pre-line rounded bg-slate-50 p-2">{a.description}</p>}
        {a.result && <p className="rounded bg-emerald-50 p-2">Resultado: {a.result} ({dateTime(a.completed_at)})</p>}
      </div>
      {open && (
        <div className="space-y-4">
          <Card title="Completar"><CompleteActivityForm code={a.code} defaultSubject={a.subject} /></Card>
          <Card title="Reprogramar"><RescheduleForm code={a.code} /></Card>
          {advisors && (
            <Card title="Reasignar">
              <ActionForm path={`/crm/activities/${a.code}/reassign`} submitLabel="Reasignar" success="Actividad reasignada">
                <Select name="owner_id" label="Nuevo responsable *" required options={advisors.filter((u) => u.id !== a.owner?.id).map((u) => ({ value: u.id, label: `${u.name} (${u.role})` }))} placeholder="Seleccione" />
                <TextArea name="note" label="Nota" />
              </ActionForm>
            </Card>
          )}
        </div>
      )}
      <div className="mt-4"><Card title="Historial"><Timeline rows={a.history} /></Card></div>
    </Drawer>
  );
}
