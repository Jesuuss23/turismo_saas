import Link from "@/components/Link";
import { api } from "@/lib/api";
import { dateTime, qs } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Input, Kpis, Notice, PageHeader, Table, Td, TextArea, btnGhostCls } from "@/components/crm/ui";
import { Pill, RouteTabs } from "@/components/admin/ui";

type Rule = { event: string; label: string; email: boolean; whatsapp: boolean; push: boolean; portal: boolean; recipients: string; timing: string; condition: string; critical: boolean };
type RulesMeta = { timings: Record<string, string>; conditions: Record<string, string>; variables: string[]; system_events: string[] };
type Template = {
  event: string; label: string; channel: string; subject: string; body: string; version: number; is_default: boolean; max_length: number | null;
  variables: string[]; preview: [string, string] | null; history: { version: number; subject: string; current: boolean; at: string }[];
};
type Message = {
  id: string; code: string; event: string; channel: string; recipient: string; subject: string; status: string; retries: number; last_error: string | null;
  permanent: boolean; is_fallback: boolean; can_retry: boolean; can_fallback: boolean; created_at: string; sent_at: string | null;
};
type Params = { e?: string; c?: string; channel?: string; status?: string; event?: string; q?: string };

const TABS = [{ key: "matriz", label: "Matriz de eventos" }, { key: "plantillas", label: "Plantillas" }, { key: "log", label: "Log de envíos" }];
const CHANNELS = [{ key: "email", label: "Email" }, { key: "whatsapp", label: "WhatsApp" }, { key: "push", label: "Push" }, { key: "portal", label: "Portal" }] as const;
const STATUS_TONE: Record<string, "green" | "amber" | "rose"> = { enviado: "green", pendiente: "amber", fallido: "rose" };
const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50";

/** Notificaciones (PROC-059, spec 09 §4.6): matriz evento × canal, plantillas versionadas y log con reintento. */
export default async function NotificationsPage({ params, searchParams }: { params: Promise<{ tab: string }>; searchParams: Promise<Params> }) {
  const [{ tab }, sp] = await Promise.all([params, searchParams]);
  const rules = await api<{ data: Rule[]; meta: RulesMeta }>("/notifications/rules");

  return (
    <section>
      <PageHeader title="Notificaciones" subtitle="Qué se avisa, a quién y por qué canal." />
      <RouteTabs base="/admin/notificaciones" tabs={TABS} active={tab} />
      {tab === "matriz" && <RulesTab rules={rules.data} meta={rules.meta} />}
      {tab === "plantillas" && <TemplatesTab rules={rules.data} sp={sp} />}
      {tab === "log" && <LogTab sp={sp} />}
    </section>
  );
}

function RulesTab({ rules, meta }: { rules: Rule[]; meta: RulesMeta }) {
  return (
    <div className="space-y-3">
      <Notice>Las incidencias críticas deben notificarse por al menos un canal (NTF-BR-01). Los eventos del sistema ({meta.system_events.length}) siempre se notifican.</Notice>
      {rules.map((r) => (
        <Card key={r.event} title={<span>{r.label} <span className="font-mono text-xs font-normal text-slate-500">{r.event}</span>{r.critical && <> <Pill tone="rose">Crítico</Pill></>}</span>}
          actions={<ActionForm path={`/notifications/rules/${r.event}/test`} inline submitLabel="Probar" submitClass={small} />}>
          <div className="p-4" data-rule={r.event}>
            <ActionForm path={`/notifications/rules/${r.event}`} method="PATCH" submitLabel="Guardar" success="Regla actualizada" className="grid gap-3 md:grid-cols-4">
              <fieldset className="md:col-span-4 flex flex-wrap gap-4 text-sm">
                <legend className="sr-only">Canales</legend>
                {CHANNELS.map((c) => (
                  <label key={c.key} className="flex items-center gap-1.5"><input type="hidden" name={c.key} value="false" />
                    <input type="checkbox" name={c.key} value="true" defaultChecked={r[c.key]} /> {c.label}</label>
                ))}
              </fieldset>
              <Input name="recipients" label="Destinatarios" defaultValue={r.recipients} />
              <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Momento</span>
                <select name="timing" defaultValue={r.timing} className="w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                  {Object.entries(meta.timings).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label>
              <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Condición</span>
                <select name="condition" defaultValue={r.condition} className="w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                  {Object.entries(meta.conditions).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label>
            </ActionForm>
          </div>
        </Card>
      ))}
    </div>
  );
}

async function TemplatesTab({ rules, sp }: { rules: Rule[]; sp: Params }) {
  const event = sp.e ?? rules[0]?.event;
  const channel = sp.c ?? "email";
  const t = event ? (await api<{ data: Template }>(`/notifications/templates/${event}/${channel}`)).data : null;
  const path = `/notifications/templates/${event}/${channel}`;

  return (
    <div className="grid gap-4 lg:grid-cols-[16rem_1fr]">
      <nav className="space-y-1 text-sm" aria-label="Eventos">
        {rules.map((r) => (
          <Link key={r.event} href={`/admin/notificaciones/plantillas${qs({ e: r.event, c: channel })}`} aria-current={r.event === event ? "page" : undefined}
            className={`block rounded px-2 py-1.5 ${r.event === event ? "bg-sky-50 font-medium text-sky-800" : "text-slate-700 hover:bg-slate-100"}`}>{r.label}</Link>
        ))}
      </nav>
      {t && (
        <div className="min-w-0 space-y-4">
          <nav className="flex flex-wrap gap-1 text-sm" aria-label="Canal">
            {CHANNELS.map((c) => (
              <Link key={c.key} href={`/admin/notificaciones/plantillas${qs({ e: event, c: c.key })}`} aria-current={c.key === channel ? "page" : undefined}
                className={`rounded-full px-3 py-1 ring-1 ${c.key === channel ? "bg-sky-700 text-white ring-sky-700" : "text-slate-700 ring-slate-300"}`}>{c.label}</Link>
            ))}
          </nav>
          <Card title={<span>{t.label} · {channel} <span className="text-xs font-normal text-slate-500">v{t.version}{t.is_default ? " · plantilla base" : ""}</span></span>}>
            <div className="space-y-3 p-4">
              <ActionForm key={`${event}-${channel}-${t.version}`} path={path} method="PUT" submitLabel="Guardar nueva versión">
                <Input name="subject" label="Asunto" defaultValue={t.subject} required />
                <TextArea name="body" label={`Cuerpo${t.max_length ? ` (máx. ${t.max_length} caracteres)` : ""}`} defaultValue={t.body} rows={6} required />
                <p className="text-xs text-slate-500">Variables: {t.variables.map((v) => `{{${v}}}`).join(" ")}</p>
              </ActionForm>
              <div className="flex flex-wrap gap-2">
                <ActionForm path={`${path}/test`} inline submitLabel="Enviarme una prueba" submitClass={btnGhostCls} />
                {!t.is_default && <ActionForm path={path} method="DELETE" inline submitLabel="Restaurar plantilla base" submitClass={btnGhostCls} confirm="¿Restaurar la plantilla base?" />}
              </div>
            </div>
          </Card>
          {t.preview && (
            <Card title="Vista previa con datos de ejemplo">
              <div className="space-y-1 p-4 text-sm"><p className="font-medium">{t.preview[0]}</p><p className="whitespace-pre-line text-slate-700">{t.preview[1]}</p></div>
            </Card>
          )}
          {t.history.length > 0 && (
            <Card title="Versiones">
              <ul className="divide-y divide-slate-100 text-sm">{t.history.map((h) => <li key={h.version} className="flex justify-between px-4 py-2"><span>v{h.version} · {h.subject}{h.current && " (vigente)"}</span><span className="text-xs text-slate-500">{dateTime(h.at)}</span></li>)}</ul>
            </Card>
          )}
        </div>
      )}
    </div>
  );
}

async function LogTab({ sp }: { sp: Params }) {
  const filters = { channel: sp.channel, status: sp.status, event: sp.event, q: sp.q };
  const { data, meta } = await api<{ data: Message[]; meta: { kpis: { sent: number; failed: number; pending: number; last_24h: number }; events: string[] } }>(`/notifications/messages${qs(filters)}`);

  return (
    <div>
      <Kpis items={[{ label: "Enviados", value: meta.kpis.sent }, { label: "Fallidos", value: meta.kpis.failed }, { label: "Pendientes", value: meta.kpis.pending }, { label: "Últimas 24 h", value: meta.kpis.last_24h }]} />
      <div className="mb-4 flex flex-wrap items-end gap-2">
        <form method="get" className="flex flex-wrap gap-2">
          <input name="q" defaultValue={sp.q} placeholder="Destinatario o asunto" className="rounded-md border border-slate-300 px-3 py-2 text-sm" />
          <select name="channel" defaultValue={sp.channel ?? ""} aria-label="Canal" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
            <option value="">Todos los canales</option>{CHANNELS.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}</select>
          <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
            <option value="">Todos los estados</option><option value="enviado">Enviado</option><option value="pendiente">Pendiente</option><option value="fallido">Fallido</option></select>
          <button className={btnGhostCls}>Filtrar</button>
        </form>
        {meta.kpis.failed > 0 && <ActionForm path="/notifications/messages/retry-failed" inline submitLabel={`Reintentar fallidos (${meta.kpis.failed})`} submitClass={btnGhostCls} />}
      </div>
      <Table head={["Código", "Evento", "Canal", "Destinatario", "Estado", "Fecha", ""]}>
        {data.map((m) => (
          <tr key={m.id} data-message={m.code}>
            <Td><span className="font-mono text-xs">{m.code}</span></Td>
            <Td>{m.event}<span className="block max-w-xs truncate text-xs text-slate-500">{m.subject}</span></Td>
            <Td>{m.channel}{m.is_fallback && <span className="block text-xs text-slate-500">alterno</span>}</Td>
            <Td>{m.recipient}</Td>
            <Td><Pill tone={STATUS_TONE[m.status] ?? "slate"}>{m.status}</Pill>{m.last_error && <span className="block max-w-xs text-xs text-rose-700">{m.last_error}</span>}</Td>
            <Td>{dateTime(m.sent_at ?? m.created_at)}</Td>
            <Td>
              <div className="flex flex-wrap gap-1">
                {m.can_retry && <ActionForm path={`/notifications/messages/${m.id}/retry`} inline submitLabel="Reintentar" submitClass={small} />}
                {m.can_fallback && <ActionForm path={`/notifications/messages/${m.id}/fallback`} inline submitLabel="Enviar por email" submitClass={small} />}
              </div>
            </Td>
          </tr>
        ))}
      </Table>
    </div>
  );
}
