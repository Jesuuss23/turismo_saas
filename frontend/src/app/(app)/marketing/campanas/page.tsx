import Link from "@/components/Link";
import { api } from "@/lib/api";
import { dateTime, qs } from "@/lib/crm";
import { Badge, btnCls, btnGhostCls, Card, Drawer, Input, inputCls, Kpis, Notice, PageHeader, Select, Table, Td } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { ExportButton } from "@/components/crm/ExportButton";
import { ConsentChip, Facts, MarketingTabs } from "@/components/marketing/ui";
import { TemplateEditor } from "@/components/marketing/TemplateEditor";
import { pct, pen, type Automation, type CampaignRow, type ContactConsents, type ContactRow, type Options, type Segment, type Template } from "@/lib/marketing";

type Search = { tab?: string; status?: string; channel?: string; type?: string; consent?: string; q?: string; contact?: string; tpl?: string; aut?: string };

export default async function MarketingPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const tab = sp.tab ?? "campanas";
  const actions = {
    campanas: <Link href="/marketing/campanas/nueva" className={btnCls}>Nueva campaña</Link>,
    segmentos: <Link href="/marketing/segmentos/nuevo" className={btnCls}>Nuevo segmento</Link>,
    plantillas: <Link href="/marketing/campanas?tab=plantillas&tpl=nueva" className={btnCls} scroll={false}>Nueva plantilla</Link>,
  }[tab];

  return (
    <>
      <PageHeader title="Marketing" subtitle="Campañas por email y WhatsApp con consentimiento (Ley 29733) y automatizaciones" actions={actions} />
      <MarketingTabs active={tab} />
      {tab === "segmentos" ? <Segments /> : tab === "plantillas" ? <Templates sp={sp} /> : tab === "automatizaciones" ? <Automations sp={sp} />
        : tab === "consentimientos" ? <Consents sp={sp} /> : <Campaigns sp={sp} />}
    </>
  );
}

async function Campaigns({ sp }: { sp: Search }) {
  const res = await api<{ data: CampaignRow[]; meta: { kpis: { sent_90d: number; open_rate: number | null; ctr: number | null; revenue_pen: number; contactable_email: number; contactable_whatsapp: number } } }>(
    `/marketing/campaigns${qs({ "filter[status]": sp.status, "filter[channel]": sp.channel })}`);
  const k = res.meta.kpis;
  return (
    <>
      <Kpis items={[
        { label: "Enviadas (90 d)", value: k.sent_90d },
        { label: "Apertura / lectura", value: pct(k.open_rate), hint: `CTR ${pct(k.ctr)}` },
        { label: "Ingresos atribuidos", value: pen(k.revenue_pen, 0), hint: "last-touch 30 días" },
        { label: "Contactables", value: `${k.contactable_email} · ${k.contactable_whatsapp}`, hint: "email · WhatsApp" },
      ]} />
      <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
        <form className="flex flex-wrap gap-2">
          <input type="hidden" name="tab" value="campanas" />
          <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[14rem]`}>
            <option value="">Estado: todos</option>
            {["borrador", "programada", "enviando", "enviada", "cancelada"].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
          </select>
          <select name="channel" defaultValue={sp.channel ?? ""} aria-label="Canal" className={`${inputCls} max-w-[14rem]`}>
            <option value="">Canal: todos</option><option value="email">Email</option><option value="whatsapp">WhatsApp</option>
          </select>
          <button className={btnGhostCls}>Filtrar</button>
        </form>
        <ExportButton resource="marketing.campaigns" filters={{ status: sp.status, channel: sp.channel }} />
      </div>
      <Table head={["Campaña", "Canal", "Segmento", "Estado", "Envío", "Enviados", "Apertura", "Conversiones", "Ingresos"]} empty="Aún no hay campañas">
        {res.data.map((c) => (
          <tr key={c.id}>
            <Td><Link href={`/marketing/campanas/${c.code}`} className="font-medium text-sky-800 hover:underline">{c.code}</Link><p className="text-xs text-slate-600">{c.name}</p></Td>
            <Td>{c.channel_label}</Td>
            <Td>{c.segment.code}<p className="text-xs text-slate-500">{c.segment.name}</p></Td>
            <Td><Badge status={c.status}>{c.status_label}</Badge></Td>
            <Td className="whitespace-nowrap">{c.sent_at ? dateTime(c.sent_at) : c.scheduled_at ? `Prog. ${dateTime(c.scheduled_at)}` : "—"}</Td>
            <Td className="tabular-nums">{c.metrics.sent}</Td>
            <Td className="tabular-nums">{pct(c.metrics.open_rate)}</Td>
            <Td className="tabular-nums">{c.metrics.reservations} res. · {c.metrics.leads} leads</Td>
            <Td className="tabular-nums">{pen(c.metrics.revenue_pen, 0)}</Td>
          </tr>
        ))}
      </Table>
    </>
  );
}

async function Segments() {
  const res = await api<{ data: Segment[] }>("/marketing/segments");
  return (
    <Table head={["Segmento", "Reglas", "Coinciden", "Email", "WhatsApp", "Campañas"]} empty="Sin segmentos">
      {res.data.map((s) => (
        <tr key={s.id}>
          <Td><Link href={`/marketing/segmentos/${s.code}`} className="font-medium text-sky-800 hover:underline">{s.code}</Link><p className="text-xs text-slate-600">{s.name}</p></Td>
          <Td className="max-w-md text-xs text-slate-600">{s.rules_text}</Td>
          <Td className="tabular-nums">{s.matched}</Td>
          <Td className="tabular-nums">{s.contactable_email}</Td>
          <Td className="tabular-nums">{s.contactable_whatsapp}</Td>
          <Td className="text-xs">{s.campaigns.join(", ") || "—"}</Td>
        </tr>
      ))}
    </Table>
  );
}

async function Templates({ sp }: { sp: Search }) {
  const [res, options] = await Promise.all([api<{ data: Template[] }>("/marketing/templates"), api<{ data: Options }>("/marketing/options")]);
  const editing = sp.tpl ? res.data.find((t) => t.code === sp.tpl) ?? null : null;
  const contacts = sp.tpl ? (await api<{ data: ContactRow[] }>("/marketing/contacts")).data : [];
  const sample = contacts.find((c) => c.email_consent?.status === "otorgado") ?? contacts[0];
  const vars = { nombre: sample?.name.split(" ")[0] ?? "Ana", destino: "Cusco", asesor: "Carla Mendoza", codigo_promo: "BIENVENIDO5", fecha_viaje: "15/11/2026", agencia: "la agencia", enlace_baja: "https://…/sitio/baja/…" };
  return (
    <>
      <Table head={["Plantilla", "Canal", "Categoría", "Estado Meta", "Contenido", ""]}>
        {res.data.map((t) => (
          <tr key={t.id}>
            <Td><span className="font-medium">{t.code}</span><p className="text-xs text-slate-600">{t.name}</p></Td>
            <Td>{t.channel_label}</Td>
            <Td className="text-xs">{t.category}</Td>
            <Td><Badge status={t.status}>{t.status_label}</Badge></Td>
            <Td className="max-w-sm text-xs text-slate-600"><span className="line-clamp-2 break-words">{t.subject ? `${t.subject} — ` : ""}{t.body}</span></Td>
            <Td className="whitespace-nowrap">
              <Link href={`/marketing/campanas?tab=plantillas&tpl=${t.code}`} scroll={false} className="text-xs text-sky-800 hover:underline">Editar</Link>
              {t.channel === "whatsapp" && t.status === "en_revision" && (
                <ActionForm path={`/marketing/templates/${t.code}/review`} inline submitLabel="Registrar respuesta de Meta: aprobada"
                  submitClass="ml-2 rounded border border-emerald-300 px-2 py-1 text-xs text-emerald-800 hover:bg-emerald-50">
                  <input type="hidden" name="status" value="aprobada" />
                </ActionForm>
              )}
            </Td>
          </tr>
        ))}
      </Table>
      {sp.tpl && (
        <Drawer title={editing ? `Plantilla ${editing.code}` : "Nueva plantilla"} close="/marketing/campanas?tab=plantillas">
          <TemplateEditor template={editing} variables={options.data.variables} sample={vars} />
        </Drawer>
      )}
    </>
  );
}

async function Automations({ sp }: { sp: Search }) {
  const [res, options] = await Promise.all([api<{ data: Automation[] }>("/marketing/automations"), api<{ data: Options }>("/marketing/options")]);
  const editing = sp.aut ? res.data.find((a) => a.code === sp.aut) : undefined;
  return (
    <>
      <Notice>Las automatizaciones comerciales exigen consentimiento; las de servicio (recordatorio pre-viaje y encuesta) solo respetan la baja (MKT-BR-16).
        Cada disparador se procesa una sola vez (MKT-BR-17).</Notice>
      <div className="grid gap-4 md:grid-cols-2 [&>*]:min-w-0">
        {res.data.map((a) => (
          <Card key={a.id} title={<span>{a.code} · {a.name}</span>} actions={<Badge status={a.status}>{a.status_label}</Badge>}>
            <Facts rows={[
              ["Disparador", a.trigger], ["Espera", a.delay], ["Canal", `${a.channel_label} · ${a.purpose === "servicio" ? "Servicio" : "Comercial"}`],
              ["Plantilla", <span key="t">{a.template.code} · {a.template.name} {!a.template.usable && <Badge status="en_revision">{a.template.status_label}</Badge>}</span>],
              ["Acumulado", `${a.sent_total} enviados · ${a.suppressed_total} suprimidos · ${a.conversions_total} conversiones`],
              ["Última ejecución", a.last_run_at ? dateTime(a.last_run_at) : "—"],
            ]} />
            <h3 className="mt-3 text-xs font-medium uppercase tracking-wide text-slate-500">Cola actual ({a.queue.length})</h3>
            <ul className="mt-1 max-h-40 space-y-1 overflow-y-auto text-sm">
              {a.queue.length === 0 && <li className="text-slate-500">Sin disparadores pendientes.</li>}
              {a.queue.map((q, i) => (
                <li key={i} className="flex flex-wrap justify-between gap-1">
                  <span className="min-w-0 break-words">{q.name} <span className="text-xs text-slate-500">{q.ref_code}</span></span>
                  {q.reason ? <span className="text-xs text-amber-800">Suprimido: {q.reason_label}</span> : <span className="text-xs text-emerald-700">Se enviará</span>}
                </li>
              ))}
            </ul>
            <div className="mt-3 flex flex-wrap gap-2">
              {a.status === "activa" ? (
                <>
                  <ActionForm path={`/marketing/automations/${a.code}/run`} idempotent inline submitLabel="Ejecutar ahora" />
                  <ActionForm path={`/marketing/automations/${a.code}/pause`} ifMatch={a.lock_version} inline submitLabel="Pausar" submitClass={btnGhostCls} />
                </>
              ) : (
                <ActionForm path={`/marketing/automations/${a.code}/activate`} ifMatch={a.lock_version} inline submitLabel="Activar" />
              )}
              <Link href={`/marketing/campanas?tab=automatizaciones&aut=${a.code}`} scroll={false} className={btnGhostCls}>Configurar</Link>
            </div>
          </Card>
        ))}
      </div>
      {editing && (
        <Drawer title={`Configurar ${editing.code}`} close="/marketing/campanas?tab=automatizaciones">
          <ActionForm path={`/marketing/automations/${editing.code}`} method="PUT" submitLabel="Guardar configuración">
            <Select name="template_id" label="Plantilla" defaultValue={editing.template.code}
              options={options.data.templates.filter((t) => t.channel === editing.channel).map((t) => ({ value: t.code, label: `${t.code} · ${t.name} (${t.status_label})` }))} />
            <Input name="delay_desc" label="Espera" defaultValue={editing.delay} maxLength={80} />
          </ActionForm>
          <h3 className="mb-2 mt-5 text-sm font-semibold text-slate-800">Historial de ejecuciones</h3>
          <Table head={["Fecha", "Cola", "Enviados", "Suprimidos", "Por"]} empty="Aún no se ejecutó">
            {editing.runs.map((r, i) => (
              <tr key={i}><Td>{dateTime(r.started_at)}</Td><Td>{r.queued}</Td><Td>{r.sent}</Td><Td>{r.suppressed}</Td><Td>{r.by ?? "Sistema"}</Td></tr>
            ))}
          </Table>
        </Drawer>
      )}
    </>
  );
}

async function Consents({ sp }: { sp: Search }) {
  const [channel, status] = (sp.consent ?? "").split(":");
  const res = await api<{ data: ContactRow[]; kpis: { email: number; whatsapp: number; optouts_30d: number; without: number } }>(
    `/marketing/consents${qs({ "filter[type]": sp.type, "filter[channel]": channel || undefined, "filter[status]": status || undefined, q: sp.q })}`);
  const [type, id] = (sp.contact ?? "").split(":");
  const detail = sp.contact ? (await api<{ data: ContactConsents }>(`/marketing/contacts/${type}/${id}/consents`)).data : null;
  const options = detail ? (await api<{ data: Options }>("/marketing/options")).data : null;
  const href = (contact?: string) => `/marketing/campanas${qs({ tab: "consentimientos", type: sp.type, consent: sp.consent, q: sp.q, contact })}`;
  return (
    <>
      <Notice tone="warning">Ley 29733 de Protección de Datos Personales: el consentimiento es previo, expreso, por canal y con evidencia; la baja se atiende de inmediato y prevalece sobre
        cualquier segmento. Las comunicaciones comerciales por WhatsApp solo entre 08:00 y 20:00 (Ley 29571, art. 58).</Notice>
      <Kpis items={[
        { label: "Email autorizado", value: res.kpis.email }, { label: "WhatsApp autorizado", value: res.kpis.whatsapp },
        { label: "Bajas (30 d)", value: res.kpis.optouts_30d }, { label: "Sin consentimiento", value: res.kpis.without },
      ]} />
      <form className="mb-3 flex flex-wrap gap-2">
        <input type="hidden" name="tab" value="consentimientos" />
        <input name="q" defaultValue={sp.q ?? ""} placeholder="Buscar nombre, email o código" aria-label="Buscar" className={`${inputCls} w-56`} />
        <select name="type" defaultValue={sp.type ?? ""} aria-label="Tipo" className={`${inputCls} max-w-[14rem]`}>
          <option value="">Tipo: todos</option><option value="client">Clientes</option><option value="lead">Leads</option><option value="subscriber">Suscriptores</option>
        </select>
        <select name="consent" defaultValue={sp.consent ?? ""} aria-label="Consentimiento" className={`${inputCls} max-w-[14rem]`}>
          <option value="">Consentimiento: todos</option>
          <option value="email:otorgado">Email otorgado</option><option value="email:revocado">Email revocado</option><option value="email:sin">Sin email</option>
          <option value="whatsapp:otorgado">WhatsApp otorgado</option><option value="whatsapp:revocado">WhatsApp revocado</option><option value="whatsapp:sin">Sin WhatsApp</option>
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Contacto", "Tipo", "Email", "WhatsApp", ""]} empty="Sin contactos">
        {res.data.map((c) => (
          <tr key={`${c.type}-${c.id}`}>
            <Td><span className="font-medium">{c.name}</span><p className="break-all text-xs text-slate-500">{c.code} · {c.email ?? c.phone ?? "—"}</p></Td>
            <Td>{c.type_label}</Td>
            <Td><ConsentChip status={c.email_consent?.status} />{c.email_consent && <p className="text-xs text-slate-500">{dateTime(c.email_consent.revoked_at ?? c.email_consent.granted_at)} · {c.email_consent.revoke_source ?? c.email_consent.source}</p>}</Td>
            <Td><ConsentChip status={c.whatsapp_consent?.status} />{c.whatsapp_consent && <p className="text-xs text-slate-500">{dateTime(c.whatsapp_consent.revoked_at ?? c.whatsapp_consent.granted_at)} · {c.whatsapp_consent.revoke_source ?? c.whatsapp_consent.source}</p>}</Td>
            <Td><Link href={href(`${c.type}:${c.id}`)} scroll={false} className="text-sm text-sky-800 hover:underline" aria-label={`Gestionar ${c.name}`}>Gestionar</Link></Td>
          </tr>
        ))}
      </Table>
      {detail && options && (
        <Drawer title={`Consentimientos · ${detail.contact.name}`} close={href()}>
          <p className="mb-3 text-sm text-slate-600">{detail.contact.type_label} {detail.contact.code} · {detail.contact.email ?? "sin email"} · {detail.contact.mobile ?? "sin móvil"}</p>
          {(["email", "whatsapp"] as const).map((ch) => {
            const c = detail.channels[ch];
            return (
              <section key={ch} className="mb-4 rounded-lg border border-slate-200 p-3" aria-label={`Canal ${c.label}`}>
                <header className="mb-2 flex items-center justify-between"><h3 className="font-semibold text-slate-800">{c.label}</h3><ConsentChip status={c.status} /></header>
                {c.code && <Facts rows={[
                  ["Otorgado", dateTime(c.granted_at)], ["Origen", c.source], ["Evidencia", c.evidence], ["Política", c.policy_version],
                  ...(c.status === "revocado" ? [["Baja", `${dateTime(c.revoked_at)} · ${c.revoke_source ?? ""}${c.revoke_campaign ? ` · ${c.revoke_campaign}` : ""}`] as [string, string]] : []),
                ]} />}
                {c.status === "otorgado" ? (
                  <ActionForm path={`/marketing/consents/${c.code}/revoke`} submitLabel="Registrar baja" className="mt-3 space-y-2"
                    submitClass="rounded-md border border-rose-300 bg-white px-3 py-2 text-sm font-medium text-rose-800 hover:bg-rose-50">
                    <Select name="source" label="Origen de la baja" options={options.revoke_sources.map((s) => ({ value: s, label: s }))} defaultValue="Solicitud verbal" />
                  </ActionForm>
                ) : (
                  <ActionForm path="/marketing/consents" submitLabel={`Registrar consentimiento ${c.label}`} className="mt-3 space-y-2">
                    <input type="hidden" name="subject_type" value={detail.contact.type} />
                    <input type="hidden" name="subject_id" value={detail.contact.id} />
                    <input type="hidden" name="channel" value={ch} />
                    <Select name="source" label="Origen" placeholder="Elegir…" options={options.consent_sources.map((s) => ({ value: s, label: s }))} />
                    <Input name="evidence" label="Evidencia (IP, ID de formulario, grabación, ficha o wamid)" />
                    <Select name="policy_version" label="Versión de la política informada" options={options.policy_versions.map((s) => ({ value: s, label: s }))} />
                  </ActionForm>
                )}
              </section>
            );
          })}
          <h3 className="mb-2 text-sm font-semibold text-slate-800">Historial</h3>
          <ol className="space-y-2 text-sm">
            {detail.events.length === 0 && <li className="text-slate-500">Sin movimientos.</li>}
            {detail.events.map((e, i) => (
              <li key={i} className="border-l-2 border-slate-200 pl-3">
                <p className="font-medium text-slate-800">{e.channel === "email" ? "Email" : "WhatsApp"} · {e.event}</p>
                <p className="break-words text-slate-600">{e.source ?? "—"}{e.evidence ? ` · ${e.evidence}` : ""}{e.campaign ? ` · ${e.campaign}` : ""}</p>
                <p className="text-xs text-slate-500">{e.actor ?? "Sistema"} · {dateTime(e.at)}</p>
              </li>
            ))}
          </ol>
        </Drawer>
      )}
    </>
  );
}
