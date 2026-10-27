import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, Input, Notice, Select, btnCls, btnGhostCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { LeadFromConversation, QuoteIntentButton, ReplyBox, type Template } from "@/components/collab/InboxForms";

type Row = {
  id: string; code: string; channel: string; channel_label: string; contact_name: string; contact_email: string | null; contact_phone: string | null; subject: string;
  status: string; status_label: string; priority: string; tags: string[]; assignee: string | null; assignee_id: string | null; client: string | null; unread: number;
  last_message_at: string; first_response_due_at: string | null; first_response_at: string | null; sla: string; lock_version: number; preview?: string;
};
type Message = { id: string; direction: "in" | "out" | "internal" | "system"; body: string; author: string | null; at: string; delivery_status: string | null; template_id: string | null;
  attachments: { id: string; file_name: string; size_bytes: number; scan_status: string }[] };
type Detail = Row & {
  messages: Message[]; window_open: boolean;
  contact: {
    client: { code: string; name: string; email: string | null; phone: string | null; segment: string | null } | null;
    lead: { code: string; name: string; status: string } | null;
    reservation: { code: string; title: string; status: string; travel: string } | null;
    ticket: { code: string; status: string; source: string } | null;
    reservations: { code: string; title: string; status: string; travel_from: string | null }[];
    quotes: { code: string; title: string; status: string }[];
    possible_leads: string[];
  };
  assignees: { id: string; name: string }[]; templates: Template[];
  actions: { manage: boolean; reassign: boolean; lead: string | null; activity: string | null; quote: string | null; link: string | null };
};
type Meta = {
  counters: { abierta: number; pendiente: number; mine: number; unassigned: number; sla_overdue: number };
  scope: string; mode: string; channels: Record<string, string>; statuses: Record<string, string>; can_manage: boolean;
};
type Channel = { channel: string; label: string; name: string; status: string; first_response_sla_minutes: number; phone_number_id: string | null; mailbox: string | null; webhook: string; has_secret: boolean };
export type InboxParams = { q?: string; channel?: string; status?: string; assignee?: string; ficha?: string; simular?: string; canales?: string };

const CHANNEL_TONE: Record<string, "green" | "sky" | "violet" | "amber" | "slate"> = { whatsapp: "green", email: "sky", webchat: "violet", form: "amber", portal: "slate" };
const STATUS_TONE: Record<string, "rose" | "amber" | "green"> = { abierta: "rose", pendiente: "amber", resuelta: "green" };
const PRIORITIES = [{ value: "baja", label: "Baja" }, { value: "media", label: "Media" }, { value: "alta", label: "Alta" }, { value: "critica", label: "Crítica" }];
const ACTIVITY_TYPES = [{ value: "llamada", label: "Llamada" }, { value: "email", label: "Email" }, { value: "whatsapp", label: "WhatsApp" }, { value: "reunion", label: "Reunión" }, { value: "tarea", label: "Tarea" }];

function slaPill(r: Row) {
  if (r.status !== "abierta" || r.first_response_at) return null;
  const overdue = r.first_response_due_at && new Date(r.first_response_due_at).getTime() < Date.now();
  return <Pill tone={overdue ? "rose" : "amber"}>{overdue ? "SLA vencido" : `Responder antes de ${dateTime(r.first_response_due_at).split(", ").pop()}`}</Pill>;
}

/** `null` si la conversación está fuera del alcance del rol (INB-BR-04). */
async function fetchDetail(code: string): Promise<Detail | null> {
  try {
    return (await api<{ data: Detail }>(`/inbox/conversations/${encodeURIComponent(code)}`)).data;
  } catch (e) {
    if (e instanceof ApiError && (e.status === 403 || e.status === 404)) return null;
    throw e;
  }
}

async function interests() {
  try {
    const meta = (await api<{ data: { catalogs: Record<string, { code: string; label: string }[]> } }>("/crm/meta")).data;
    return (meta.catalogs.lead_interest ?? []).map((c) => ({ value: c.code, label: c.label }));
  } catch {
    return [{ value: "paquete", label: "Paquete" }];
  }
}

/**
 * Bandeja omnicanal (spec 10 §2): lista · hilo · ficha. Por debajo de 1180 px la ficha se abre como drawer (botón «Ficha»);
 * por debajo de 760 px se ve una sola columna (lista, o el hilo con el botón «Bandeja»).
 */
export async function InboxView({ sp, code }: { sp: InboxParams; code?: string }) {
  const filters = { "filter[q]": sp.q, "filter[channel]": sp.channel, "filter[status]": sp.status, "filter[assignee]": sp.assignee };
  const [{ data, meta }, detail] = await Promise.all([
    api<{ data: Row[]; meta: Meta }>(`/inbox/conversations${qs(filters)}`),
    code ? fetchDetail(code) : Promise.resolve(null),
  ]);
  const query = qs({ q: sp.q, channel: sp.channel, status: sp.status, assignee: sp.assignee });
  const here = code ? `/inbox/${code}${query}` : `/inbox${query}`;
  const withParam = (p: string) => `${here}${here.includes("?") ? "&" : "?"}${p}`;
  const k = meta.counters;
  const leadInterests = detail && !detail.actions.lead ? await interests() : [];

  return (
    <section className="min-w-0">
      <header className="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-2xl font-semibold text-slate-900">Bandeja omnicanal</h1>
          <p className="mt-1 text-sm text-slate-600">{meta.scope}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link href={withParam("canales=1")} scroll={false} className={btnGhostCls}>Canales e integración</Link>
          {meta.can_manage && <Link href={withParam("simular=1")} scroll={false} className={btnCls}>Simular entrante</Link>}
        </div>
      </header>
      <ul className="mb-3 flex flex-wrap gap-2 text-xs" aria-label="Contadores">
        <li><Pill tone="rose">{k.abierta} abiertas</Pill></li>
        <li><Pill tone="amber">{k.pendiente} esperan al cliente</Pill></li>
        <li><Pill tone="sky">{k.mine} asignadas a mí</Pill></li>
        <li><Pill>{k.unassigned} sin asignar</Pill></li>
        <li><Pill tone={k.sla_overdue ? "rose" : "green"}>{k.sla_overdue} con SLA de 1.ª respuesta vencido</Pill></li>
      </ul>

      <div className="grid min-w-0 gap-3 min-[760px]:grid-cols-[minmax(240px,300px)_minmax(0,1fr)] min-[1180px]:grid-cols-[280px_minmax(0,1fr)_300px]">
        <aside className={`${code ? "hidden min-[760px]:block" : ""} min-w-0 rounded-lg border border-slate-200 bg-white`} aria-label="Conversaciones">
          <form method="get" action={code ? `/inbox/${code}` : "/inbox"} className="grid grid-cols-2 gap-2 border-b border-slate-100 p-3">
            <input name="q" defaultValue={sp.q} placeholder="Buscar contacto, asunto o código" aria-label="Buscar" className="col-span-2 rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
            <select name="channel" defaultValue={sp.channel ?? ""} aria-label="Canal" className="rounded-md border border-slate-300 px-2 py-1.5 text-sm">
              <option value="">Todos los canales</option>{Object.entries(meta.channels).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
            <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className="rounded-md border border-slate-300 px-2 py-1.5 text-sm">
              <option value="">Todos los estados</option><option value="abierta">Abierta</option><option value="pendiente">Pendiente</option><option value="resuelta">Resuelta</option>
            </select>
            <select name="assignee" defaultValue={sp.assignee ?? ""} aria-label="Asignación" className="rounded-md border border-slate-300 px-2 py-1.5 text-sm">
              <option value="">Todas</option><option value="me">A mí</option><option value="none">Sin asignar</option>
            </select>
            <button className={btnGhostCls}>Filtrar</button>
          </form>
          <ul className="max-h-[70vh] divide-y divide-slate-100 overflow-y-auto">
            {data.map((r) => (
              <li key={r.id} data-code={r.code}>
                <Link href={`/inbox/${r.code}${query}`} scroll={false} aria-current={r.code === code ? "page" : undefined}
                  className={`block px-3 py-2.5 hover:bg-slate-50 ${r.code === code ? "bg-sky-50" : ""}`}>
                  <span className="flex items-center justify-between gap-2">
                    <span className={`truncate text-sm ${r.unread ? "font-semibold text-slate-900" : "text-slate-800"}`}>{r.contact_name}</span>
                    <span className="shrink-0 text-[11px] text-slate-500">{dateTime(r.last_message_at).split(", ").pop()}</span>
                  </span>
                  <span className="mt-0.5 block truncate text-xs text-slate-600">{r.preview || r.subject}</span>
                  <span className="mt-1 flex flex-wrap gap-1">
                    <Pill tone={CHANNEL_TONE[r.channel]}>{r.channel_label}</Pill>
                    <Pill tone={STATUS_TONE[r.status]}>{r.status_label}</Pill>
                    {r.unread > 0 && <Pill tone="sky">{r.unread} nuevo{r.unread > 1 ? "s" : ""}</Pill>}
                    {slaPill(r)}
                  </span>
                  <span className="mt-1 block text-[11px] text-slate-500">{r.code} · {r.assignee ?? "Sin asignar"}</span>
                </Link>
              </li>
            ))}
            {!data.length && <li className="px-3 py-6 text-center text-sm text-slate-500">Sin conversaciones.</li>}
          </ul>
        </aside>

        <div className={`${code ? "" : "hidden min-[760px]:block"} min-w-0`}>
          {!code ? (
            <div className="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500">Seleccione una conversación.</div>
          ) : !detail ? (
            <section className="rounded-lg border border-amber-200 bg-amber-50 p-6 text-center">
              <h2 className="text-lg font-semibold text-amber-900">Conversación fuera de su alcance</h2>
              <p className="mt-2 text-sm text-amber-900">Esta conversación está asignada a otra persona del equipo (INB-BR-04).</p>
              <Link href={`/inbox${query}`} className="mt-4 inline-block text-sm font-medium text-sky-800 hover:underline">Volver a la bandeja</Link>
            </section>
          ) : (
            <Thread c={detail} back={`/inbox${query}`} ficha={withParam("ficha=1")} />
          )}
        </div>

        {detail && (
          <aside className="hidden min-w-0 min-[1180px]:block" aria-label="Ficha del contacto">
            <ContactPanel c={detail} interests={leadInterests} />
          </aside>
        )}
      </div>

      {detail && sp.ficha && (
        <Drawer title={`Ficha · ${detail.contact_name}`} close={here}>
          <ContactPanel c={detail} interests={leadInterests} />
        </Drawer>
      )}
      {sp.simular && meta.can_manage && (
        <Drawer title="Simular mensaje entrante" close={here}>
          <Notice>Pasa por el mismo procesador que los webhooks reales: deduplica, reconoce al contacto y crea o reabre la conversación.</Notice>
          <ActionForm path="/inbox/simulate" submitLabel="Simular entrante" redirectTo="/inbox/{data.conversation}">
            <Select name="channel" label="Canal" options={[{ value: "webchat", label: "Chat web" }, { value: "whatsapp", label: "WhatsApp" }, { value: "email", label: "Email" }, { value: "form", label: "Formulario" }]} defaultValue="webchat" />
            <Input name="name" label="Nombre del contacto" defaultValue="Lucía Torres" />
            <div className="grid gap-3 sm:grid-cols-2">
              <Input name="email" label="Correo" type="email" />
              <Input name="phone" label="Teléfono" />
            </div>
            <Input name="subject" label="Asunto (email/formulario)" />
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Mensaje</span>
              <textarea name="body" rows={3} required defaultValue="Hola, quisiera información del tour a Machu Picchu para 2 personas." className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" /></label>
            <Input name="conversation_code" label="Continuar conversación (opcional)" placeholder="CNV-0101" defaultValue={code ?? ""} />
          </ActionForm>
        </Drawer>
      )}
      {sp.canales && <ChannelsDrawer close={here} />}
    </section>
  );
}

async function ChannelsDrawer({ close }: { close: string }) {
  const [{ data: channels }, { data: templates }] = await Promise.all([
    api<{ data: Channel[] }>("/inbox/channels"),
    api<{ data: Template[] }>("/inbox/templates"),
  ]);
  return (
    <Drawer title="Canales e integración" close={close}>
      <div className="space-y-4">
        {channels.map((ch) => (
          <Card key={ch.channel} title={ch.label} actions={<Pill tone={ch.status === "activo" ? "green" : ch.status === "sandbox" ? "amber" : "slate"}>{ch.status}</Pill>}>
            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
              <dt className="text-slate-500">Cuenta</dt><dd className="[overflow-wrap:anywhere]">{ch.name}</dd>
              <dt className="text-slate-500">SLA 1.ª respuesta</dt><dd>{ch.first_response_sla_minutes} min</dd>
              {ch.phone_number_id && <><dt className="text-slate-500">Phone number ID</dt><dd className="font-mono text-xs">{ch.phone_number_id}</dd></>}
              {ch.mailbox && <><dt className="text-slate-500">Buzón</dt><dd className="[overflow-wrap:anywhere]">{ch.mailbox}</dd></>}
              <dt className="text-slate-500">Webhook</dt><dd className="font-mono text-xs [overflow-wrap:anywhere]">{ch.webhook}</dd>
              <dt className="text-slate-500">Secreto</dt><dd>{ch.has_secret ? "Configurado (cifrado)" : "—"}</dd>
            </dl>
          </Card>
        ))}
        <Card title="Plantillas de respuesta">
          <ul className="space-y-2 text-sm">
            {templates.map((t) => (
              <li key={t.id}>
                <p className="font-medium text-slate-800">{t.code} · {t.name} {t.is_whatsapp_approved && <Pill tone="green">Aprobada por Meta</Pill>}</p>
                <p className="text-slate-600 [overflow-wrap:anywhere]">{t.body}</p>
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </Drawer>
  );
}

function Thread({ c, back, ficha }: { c: Detail; back: string; ficha: string }) {
  return (
    <section className="flex min-w-0 flex-col rounded-lg border border-slate-200 bg-white" aria-label={`Conversación ${c.code}`}>
      <header className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-3 py-2">
        <Link href={back} className="text-sm text-sky-800 hover:underline min-[760px]:hidden">← Bandeja</Link>
        <div className="min-w-[10rem] flex-1">
          <h2 className="truncate font-semibold text-slate-900">{c.contact_name}</h2>
          <p className="truncate text-xs text-slate-500">{c.code} · {c.subject}</p>
        </div>
        <Pill tone={CHANNEL_TONE[c.channel]}>{c.channel_label}</Pill>
        <Pill tone={STATUS_TONE[c.status]}>{c.status_label}</Pill>
        <Link href={ficha} scroll={false} className={`${btnGhostCls} min-[1180px]:hidden`}>Ficha</Link>
        {c.actions.manage && (c.status === "resuelta"
          ? <ActionForm path={`/inbox/conversations/${c.code}/reopen`} ifMatch={c.lock_version} submitLabel="Reabrir" submitClass={btnGhostCls} inline success="Conversación reabierta" />
          : <ActionForm path={`/inbox/conversations/${c.code}/resolve`} ifMatch={c.lock_version} submitLabel="Resolver" submitClass={btnGhostCls} inline success="Conversación resuelta" />)}
      </header>
      {c.channel === "whatsapp" && !c.window_open && (
        <p className="border-b border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">Pasaron más de 24 h desde el último mensaje del cliente: en WhatsApp solo puede enviar plantillas aprobadas (INB-BR-02).</p>
      )}
      <ol className="max-h-[60vh] min-h-48 space-y-2 overflow-y-auto p-3" aria-label="Mensajes">
        {c.messages.map((m) => <Bubble key={m.id} m={m} />)}
      </ol>
      {c.actions.manage ? <ReplyBox code={c.code} templates={c.templates} windowOpen={c.window_open} />
        : <p className="border-t border-slate-100 p-3 text-xs text-slate-500">Solo lectura: responder requiere inbox.manage.</p>}
    </section>
  );
}

function Bubble({ m }: { m: Message }) {
  if (m.direction === "system") {
    return <li className="text-center text-[11px] text-slate-500">{m.body} · {dateTime(m.at)}</li>;
  }
  const cls = { in: "mr-auto bg-slate-100 text-slate-900", out: "ml-auto bg-sky-700 text-white", internal: "mx-auto border border-dashed border-amber-300 bg-amber-50 text-amber-950" }[m.direction];
  return (
    <li className={`max-w-[85%] rounded-lg px-3 py-2 text-sm ${cls}`} data-direction={m.direction}>
      {m.direction === "internal" && <p className="mb-0.5 text-[11px] font-semibold uppercase tracking-wide">Nota interna · no visible para el cliente</p>}
      <p className="whitespace-pre-line [overflow-wrap:anywhere]">{m.body}</p>
      {m.attachments.map((a) => <p key={a.id} className="mt-1 text-xs opacity-90">📎 {a.file_name} ({Math.round(a.size_bytes / 1024)} KB)</p>)}
      <p className={`mt-1 text-[11px] ${m.direction === "out" ? "text-sky-100" : "text-slate-500"}`}>
        {m.author ?? (m.direction === "in" ? "Cliente" : "")} · {dateTime(m.at)}{m.delivery_status ? ` · ${m.delivery_status}` : ""}
      </p>
    </li>
  );
}

function ContactPanel({ c, interests }: { c: Detail; interests: { value: string; label: string }[] }) {
  const ct = c.contact;
  const a = c.actions;
  return (
    <div className="space-y-3">
      <Card title="Contacto">
        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
          <dt className="text-slate-500">Nombre</dt><dd className="[overflow-wrap:anywhere]">{c.contact_name}</dd>
          {c.contact_email && <><dt className="text-slate-500">Correo</dt><dd className="[overflow-wrap:anywhere]">{c.contact_email}</dd></>}
          {c.contact_phone && <><dt className="text-slate-500">Teléfono</dt><dd>{c.contact_phone}</dd></>}
          <dt className="text-slate-500">Asignada</dt><dd>{c.assignee ?? "Sin asignar"}</dd>
          <dt className="text-slate-500">Prioridad</dt><dd className="capitalize">{c.priority}</dd>
          {c.tags.length > 0 && <><dt className="text-slate-500">Etiquetas</dt><dd className="[overflow-wrap:anywhere]">{c.tags.join(", ")}</dd></>}
        </dl>
      </Card>

      <Card title="Vínculos CRM">
        <ul className="space-y-1 text-sm">
          <li>Cliente: {ct.client ? <Link href={`/crm/clientes/${ct.client.code}`} className="text-sky-800 hover:underline">{ct.client.code} · {ct.client.name}</Link> : "—"}</li>
          <li>Lead: {ct.lead ? <Link href={`/crm/leads/${ct.lead.code}`} className="text-sky-800 hover:underline">{ct.lead.code} · {ct.lead.status}</Link> : "—"}</li>
          {ct.reservation && <li>Reserva: <Link href={`/reservas/${ct.reservation.code}`} className="text-sky-800 hover:underline">{ct.reservation.code}</Link> · {ct.reservation.status} · {ct.reservation.travel}</li>}
          {ct.ticket && <li>Ticket: {ct.ticket.code} · {ct.ticket.status}</li>}
          {ct.possible_leads.length > 0 && <li className="text-amber-800">Posible lead existente: {ct.possible_leads.join(", ")}</li>}
        </ul>
        {ct.reservations.length > 0 && (
          <>
            <p className="mt-3 text-xs font-medium uppercase tracking-wide text-slate-500">Reservas del cliente</p>
            <ul className="mt-1 space-y-0.5 text-sm">{ct.reservations.map((r) => <li key={r.code}><Link href={`/reservas/${r.code}`} className="text-sky-800 hover:underline">{r.code}</Link> · {r.status} · {date(r.travel_from)}</li>)}</ul>
          </>
        )}
        {ct.quotes.length > 0 && (
          <>
            <p className="mt-3 text-xs font-medium uppercase tracking-wide text-slate-500">Cotizaciones</p>
            <ul className="mt-1 space-y-0.5 text-sm">{ct.quotes.map((q) => <li key={q.code}><Link href={`/cotizaciones/${q.code}`} className="text-sky-800 hover:underline">{q.code}</Link> · {q.status}</li>)}</ul>
          </>
        )}
      </Card>

      {a.manage && (
        <Card title="Asignación">
          <ActionForm path={`/inbox/conversations/${c.code}/assign`} ifMatch={c.lock_version} submitLabel={a.reassign ? "Asignar" : "Tomar conversación"} success="Asignación actualizada">
            {a.reassign ? (
              <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Responsable</span>
                <select name="user_id" defaultValue={c.assignee_id ?? ""} className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                  {c.assignees.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                </select></label>
            ) : <input type="hidden" name="user_id" value={c.assignees[0]?.id ?? ""} />}
          </ActionForm>
          <div className="mt-3">
            <ActionForm path={`/inbox/conversations/${c.code}`} method="PATCH" ifMatch={c.lock_version} submitLabel="Guardar" submitClass={btnGhostCls} success="Clasificación guardada">
              <Select name="priority" label="Prioridad" options={PRIORITIES} defaultValue={c.priority} />
              <Input name="tags" label="Etiquetas (separadas por coma)" defaultValue={c.tags.join(", ")} />
            </ActionForm>
          </div>
        </Card>
      )}

      {a.manage && !ct.client && (
        <Card title="Vincular">
          {a.link ? <p className="text-xs text-slate-500">{a.link}</p> : (
            <ActionForm path={`/inbox/conversations/${c.code}/link`} submitLabel="Vincular" submitClass={btnGhostCls} success="Conversación vinculada">
              <Input name="client_code" label="Código de cliente" placeholder="CLI-0001" />
              <Input name="reservation_code" label="o código de reserva" placeholder="RES-2026-000350" />
            </ActionForm>
          )}
        </Card>
      )}

      <Card title="Acciones CRM">
        <div className="space-y-3">
          {a.lead ? <p className="text-xs text-slate-500">Crear lead: {a.lead}</p> : (
            <details><summary className="cursor-pointer text-sm font-medium text-sky-800">Registrar lead desde la conversación</summary>
              <div className="mt-2"><LeadFromConversation code={c.code} name={c.contact_name} email={c.contact_email} phone={c.contact_phone} interests={interests} /></div>
            </details>
          )}
          {a.activity ? <p className="text-xs text-slate-500">Actividad: {a.activity}</p> : (
            <details><summary className="cursor-pointer text-sm font-medium text-sky-800">Programar actividad</summary>
              <div className="mt-2">
                <ActionForm path={`/inbox/conversations/${c.code}/activities`} submitLabel="Crear actividad" success="Actividad creada">
                  <Select name="type" label="Tipo" options={ACTIVITY_TYPES} defaultValue={c.channel === "whatsapp" ? "whatsapp" : "llamada"} />
                  <Input name="subject" label="Asunto" required defaultValue={`Seguimiento ${c.code}`} />
                  <Input name="due_at" label="Fecha" type="datetime-local" required />
                </ActionForm>
              </div>
            </details>
          )}
          {a.quote ? <p className="text-xs text-slate-500">{a.quote}</p> : <QuoteIntentButton code={c.code} />}
        </div>
      </Card>
    </div>
  );
}
