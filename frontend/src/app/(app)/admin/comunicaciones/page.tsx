import Link from "@/components/Link";
import { api } from "@/lib/api";
import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, Input, Notice, PageHeader, Table, Td, Tabs, btnCls, btnGhostCls, inputCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { VarTextArea } from "@/components/experience/VarTextArea";
import { ManualSend } from "@/components/experience/ManualSend";
import { dateTime } from "@/lib/crm";
import { CHANNEL_LABEL, PAX_STATUS_TONE, type PaxMessage, type PaxMeta, type PaxReservation, type PaxTemplate } from "@/lib/experience";

const small = "rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50";
const TABS = [
  { key: "plantillas", label: "Plantillas" },
  { key: "proximos", label: "Próximos envíos" },
  { key: "historial", label: "Historial" },
];
const STATUS_LABEL: Record<string, string> = { programado: "Programado", enviado: "Enviado", entregado: "Entregado", leido: "Leído", fallido: "Fallido", cancelado: "Cancelado" };

type Search = { tab?: string; plantilla?: string; nueva?: string; vista?: string; reserva?: string; canal?: string; manual?: string; msg?: string; estado?: string; origen?: string };

/**
 * Mensajes al pasajero (spec 17 §2): plantillas por evento, canal e idioma; programación por reserva (próximos envíos) e historial con
 * acuses. Operaciones y Manager (`operations.assign`) envían, cancelan, reintentan y recalculan, pero no editan plantillas.
 */
export default async function PaxMessagesPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const tab = TABS.some((t) => t.key === sp.tab) ? sp.tab! : "plantillas";
  const { data: templates, meta } = await api<{ data: PaxTemplate[]; meta: PaxMeta }>("/notifications/pax-templates");
  const base = "/admin/comunicaciones";
  const close = `${base}?tab=${tab}`;
  const noEdit = "Requiere permiso admin.settings.manage";

  return (
    <section>
      <PageHeader
        title="Mensajes al pasajero"
        subtitle="Comunicaciones al viajero por reserva: confirmación, saldo, datos de acompañantes, recordatorios, cambios y postviaje, por canal e idioma."
        actions={<>
          <Link href={`${close}&manual=1`} className={btnGhostCls} scroll={false}>Envío manual</Link>
          {meta.can_edit
            ? <Link href={`${base}?tab=plantillas&nueva=1`} className={btnCls} scroll={false}>Nueva plantilla</Link>
            : <button type="button" disabled title={noEdit} className={btnCls}>Nueva plantilla</button>}
        </>}
      />
      {!meta.messages_on && (
        <Notice tone="warning">La regla «Mensajes al pasajero» (R-PAX-MESSAGES) está desactivada o fuera del plan: no se programan envíos automáticos. Los envíos manuales siguen disponibles. <Link href="/admin/reglas" className="underline">Centro de reglas</Link></Notice>
      )}
      <Tabs base={base} tabs={TABS} active={tab} />

      {tab === "plantillas" && <TemplatesTab templates={templates} meta={meta} />}
      {tab === "proximos" && <MessagesTab view="proximos" sp={sp} />}
      {tab === "historial" && <MessagesTab view="historial" sp={sp} />}

      {(sp.nueva || sp.plantilla) && meta.can_edit && <TemplateEditor template={templates.find((t) => t.id === sp.plantilla)} meta={meta} close={close} />}
      {sp.vista && <PreviewDrawer template={templates.find((t) => t.id === sp.vista)} meta={meta} sp={sp} close={close} />}
      {sp.manual && <ManualDrawer templates={templates} meta={meta} close={close} />}
    </section>
  );
}

function TemplatesTab({ templates, meta }: { templates: PaxTemplate[]; meta: PaxMeta }) {
  return (
    <Table head={["Código", "Plantilla", "Cuándo", "Canales", "Idioma", "Condiciones", "Estado", ""]}>
      {templates.map((t) => (
        <tr key={t.id} data-template={t.code}>
          <Td className="font-mono text-xs">{t.code}</Td>
          <Td><span className="font-medium">{t.name}</span>{t.history > 0 && <span className="block text-xs text-slate-500">{t.history} envío(s)</span>}</Td>
          <Td className="text-xs">{t.when}</Td>
          <Td className="text-xs">{t.channels.map((c) => CHANNEL_LABEL[c] ?? c).join(", ")}</Td>
          <Td>{t.lang}</Td>
          <Td className="text-xs">
            {conditionText(t) || "—"}
            {!t.rule_ok && <span className="block text-amber-700">Regla requerida inactiva: no se programa</span>}
          </Td>
          <Td>{t.active ? <Pill tone="green">Activa</Pill> : <Pill>Inactiva</Pill>}</Td>
          <Td>
            <div className="flex flex-wrap gap-1">
              <Link href={`/admin/comunicaciones?tab=plantillas&vista=${t.id}`} scroll={false} className={small}>Vista previa</Link>
              {meta.can_edit && <>
                <Link href={`/admin/comunicaciones?tab=plantillas&plantilla=${t.id}`} scroll={false} className={small}>Editar</Link>
                <ActionForm path={`/notifications/pax-templates/${t.id}/duplicate`} idempotent submitLabel={`Duplicar en ${t.lang === "ES" ? "EN" : "ES"}`} submitClass={small} success="Plantilla duplicada" inline>
                  <input type="hidden" name="lang" value={t.lang === "ES" ? "EN" : "ES"} />
                </ActionForm>
                <ActionForm path={`/notifications/pax-templates/${t.id}`} method="DELETE" submitLabel="Eliminar" submitClass={small} success="Plantilla eliminada" inline confirm={`¿Eliminar la plantilla ${t.code}?`}
                  disabled={!t.deletable} disabledReason="Tiene historial de envíos: desactívela en su lugar (MSG-04)" />
              </>}
            </div>
          </Td>
        </tr>
      ))}
    </Table>
  );
}

function conditionText(t: PaxTemplate) {
  const c = t.conditions;
  return [
    c.requiresRule && `requiere ${c.requiresRule}`,
    c.channels?.length && `canal de venta: ${c.channels.join(", ")}`,
    c.products?.length && `${c.products.length} producto(s)`,
    c.onlyIfBalance && "solo con saldo",
    c.onlyIfMissingDocs && "solo si faltan datos",
  ].filter(Boolean).join(" · ");
}

async function TemplateEditor({ template: t, meta, close }: { template?: PaxTemplate; meta: PaxMeta; close: string }) {
  const { data: products } = await api<{ data: { id: string; ref: string; name: string; is_bookable: boolean }[] }>("/catalog/products?per_page=200");
  const c = t?.conditions ?? {};
  return (
    <Drawer title={t ? `Editar ${t.code}` : "Nueva plantilla"} close={close}>
      <ActionForm path={t ? `/notifications/pax-templates/${t.id}` : "/notifications/pax-templates"} method={t ? "PATCH" : "POST"} ifMatch={t?.lock_version} idempotent={!t}
        submitLabel={t ? "Guardar plantilla" : "Crear plantilla"} success={t ? "Plantilla guardada" : "Plantilla creada"} redirectTo={close} className="space-y-3">
        <Input name="name" label="Nombre" required maxLength={120} defaultValue={t?.name} />
        <div className="grid grid-cols-2 gap-2">
          <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Evento</span>
            <select name="event" defaultValue={t?.event ?? "reserva_confirmada"} className={inputCls}>
              {Object.entries(meta.events).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </label>
          <Input name="offset_hours" label="Desfase (horas; negativo = antes)" type="number" min={-720} max={720} defaultValue={t?.offset_hours ?? 0} />
          <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Idioma</span>
            <select name="lang" defaultValue={t?.lang ?? "ES"} className={inputCls}><option value="ES">Español</option><option value="EN">Inglés</option></select>
          </label>
          <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Estado</span>
            <select name="active" defaultValue={t?.active === false ? "false" : "true"} className={inputCls}><option value="true">Activa</option><option value="false">Inactiva</option></select>
          </label>
        </div>
        <fieldset className="flex flex-wrap gap-3 text-sm"><legend className="mb-1 font-medium text-slate-700">Canales</legend>
          {Object.entries(meta.channels).map(([k, v]) => <label key={k} className="flex items-center gap-1"><input type="checkbox" name="channels[]" value={k} defaultChecked={t ? t.channels.includes(k) : k === "email"} />{v}</label>)}
        </fieldset>
        <Input name="subject" label="Asunto (email, portal y push)" maxLength={200} defaultValue={t?.subject ?? ""} />
        <VarTextArea name="body" label="Mensaje" required defaultValue={t?.body} variables={meta.variables} />
        <fieldset className="space-y-2 rounded-md border border-slate-200 p-3 text-sm"><legend className="px-1 font-medium text-slate-700">Condiciones</legend>
          <label className="block"><span className="mb-1 block text-slate-700">Solo si la regla está activa</span>
            <select name="conditions.requiresRule" defaultValue={c.requiresRule ?? ""} className={inputCls}>
              <option value="">Sin condición de regla</option>
              {meta.rules.map((r) => <option key={r} value={r}>{r}</option>)}
            </select>
          </label>
          <div className="flex flex-wrap gap-3"><span className="text-slate-700">Canal de venta:</span>
            {meta.sales_channels.map((s) => <label key={s} className="flex items-center gap-1"><input type="checkbox" name="conditions.channels[]" value={s} defaultChecked={c.channels?.includes(s)} />{s}</label>)}
          </div>
          <label className="block"><span className="mb-1 block text-slate-700">Solo estos productos (vacío = todos)</span>
            <select name="conditions.products[]" multiple defaultValue={c.products ?? []} className={`${inputCls} h-24`}>
              {products.filter((p) => p.is_bookable).map((p) => <option key={p.id} value={p.id}>{p.ref} · {p.name}</option>)}
            </select>
          </label>
          <input type="hidden" name="conditions.onlyIfBalance" value="false" />
          <label className="flex items-center gap-2"><input type="checkbox" name="conditions.onlyIfBalance" value="true" defaultChecked={!!c.onlyIfBalance} />Solo si hay saldo pendiente</label>
          <input type="hidden" name="conditions.onlyIfMissingDocs" value="false" />
          <label className="flex items-center gap-2"><input type="checkbox" name="conditions.onlyIfMissingDocs" value="true" defaultChecked={!!c.onlyIfMissingDocs} />Solo si faltan datos o documentos de pasajeros</label>
        </fieldset>
        <p className="text-xs text-slate-500">MSG-01: variables solo de la lista; WhatsApp hasta 1 024 caracteres y requiere plantilla aprobada por Meta.</p>
      </ActionForm>
    </Drawer>
  );
}

async function PreviewDrawer({ template: t, meta, sp, close }: { template?: PaxTemplate; meta: PaxMeta; sp: Search; close: string }) {
  if (!t) return null;
  const channel = sp.canal && meta.channels[sp.canal] ? sp.canal : t.channels[0] ?? "email";
  const [{ data: reservations }, preview] = await Promise.all([
    api<{ data: PaxReservation[] }>("/notifications/pax-messages/reservations"),
    api<{ data: { channel: string; subject: string | null; body: string; length: number; reservation: { code: string; title: string } | null; recipient: string | null } }>(
      `/notifications/pax-templates/${t.id}/preview`, { method: "POST", body: JSON.stringify({ reservation: sp.reserva || null, channel }) },
    ).then((r) => r.data).catch(() => null),
  ]);
  return (
    <Drawer title={`Vista previa · ${t.code}`} close={close}>
      <form method="get" className="mb-4 grid gap-2 text-sm sm:grid-cols-2">
        <input type="hidden" name="tab" value="plantillas" /><input type="hidden" name="vista" value={t.id} />
        <label className="block"><span className="mb-1 block font-medium text-slate-700">Reserva</span>
          <select name="reserva" defaultValue={sp.reserva ?? ""} className={inputCls}>
            <option value="">Reserva de ejemplo</option>
            {reservations.map((r) => <option key={r.id} value={r.id}>{r.code} · {r.client}</option>)}
          </select>
        </label>
        <label className="block"><span className="mb-1 block font-medium text-slate-700">Canal</span>
          <select name="canal" defaultValue={channel} className={inputCls}>
            {t.channels.map((c) => <option key={c} value={c}>{meta.channels[c] ?? c}</option>)}
          </select>
        </label>
        <button className={`${btnGhostCls} sm:col-span-2`}>Actualizar vista previa</button>
      </form>
      {preview ? (
        <article className="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm" data-preview>
          <p className="text-xs text-slate-500">{CHANNEL_LABEL[preview.channel] ?? preview.channel} · para {preview.recipient ?? "—"}{preview.reservation ? ` · ${preview.reservation.code}` : ""}</p>
          {preview.subject && <p className="mt-2 font-medium">{preview.subject}</p>}
          <p className="mt-2 whitespace-pre-wrap">{preview.body}</p>
          <p className="mt-2 text-xs text-slate-500">{preview.length} caracteres</p>
        </article>
      ) : <Notice tone="danger">No se pudo generar la vista previa.</Notice>}
      {meta.can_edit && (
        <div className="mt-4">
          <ActionForm path={`/notifications/pax-templates/${t.id}/test`} idempotent submitLabel="Enviar prueba a mi correo" submitClass={small} success="Prueba enviada" inline>
            <input type="hidden" name="channel" value={channel} />
          </ActionForm>
        </div>
      )}
    </Drawer>
  );
}

async function ManualDrawer({ templates, meta, close }: { templates: PaxTemplate[]; meta: PaxMeta; close: string }) {
  const { data: reservations } = await api<{ data: PaxReservation[] }>("/notifications/pax-messages/reservations");
  return (
    <Drawer title="Envío manual" close={close}>
      <ManualSend reservations={reservations} channels={meta.channels} templates={templates.filter((t) => t.active).map((t) => ({ id: t.id, label: `${t.code} · ${t.name} (${t.lang})` }))} />
    </Drawer>
  );
}

async function MessagesTab({ view, sp }: { view: "proximos" | "historial"; sp: Search }) {
  const q = new URLSearchParams({ view });
  if (sp.canal) q.set("channel", sp.canal);
  if (sp.estado) q.set("status", sp.estado);
  if (sp.origen) q.set("origin", sp.origen);
  if (sp.reserva) q.set("reservation", sp.reserva);
  const { data } = await api<{ data: PaxMessage[] }>(`/notifications/pax-messages?${q}`);
  const detail = sp.msg ? data.find((m) => m.id === sp.msg) : undefined;
  const close = `/admin/comunicaciones?tab=${view}`;
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <form method="get" className="flex flex-wrap items-end gap-2 text-sm">
          <input type="hidden" name="tab" value={view} />
          <label className="block"><span className="mb-1 block font-medium text-slate-700">Reserva</span><input name="reserva" defaultValue={sp.reserva ?? ""} placeholder="RES-2026-…" className={inputCls} /></label>
          <label className="block"><span className="mb-1 block font-medium text-slate-700">Canal</span>
            <select name="canal" defaultValue={sp.canal ?? ""} className={inputCls}><option value="">Todos</option>{Object.entries(CHANNEL_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
          </label>
          {view === "historial" && (
            <label className="block"><span className="mb-1 block font-medium text-slate-700">Estado</span>
              <select name="estado" defaultValue={sp.estado ?? ""} className={inputCls}><option value="">Todos</option>{Object.entries(STATUS_LABEL).filter(([k]) => k !== "programado").map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
            </label>
          )}
          <label className="block"><span className="mb-1 block font-medium text-slate-700">Origen</span>
            <select name="origen" defaultValue={sp.origen ?? ""} className={inputCls}><option value="">Todos</option><option value="auto">Automático</option><option value="manual">Manual</option><option value="prueba">Prueba</option></select>
          </label>
          <button className={btnGhostCls}>Filtrar</button>
        </form>
        <div className="flex gap-2">
          {view === "proximos"
            ? <ActionForm path="/notifications/pax-messages/reschedule" submitLabel="Recalcular programación" submitClass={btnGhostCls} success="Programación recalculada" inline />
            : <ActionForm path="/notifications/pax-messages/sync-acks" submitLabel="Actualizar acuses" submitClass={btnGhostCls} success="Acuses actualizados" inline />}
        </div>
      </div>
      <p className="text-xs text-slate-500">{data.length} mensaje(s){data.length >= 300 ? " (se muestran los 300 más recientes)" : ""}.</p>
      <Table head={["Mensaje", "Reserva", "Plantilla", "Canal", view === "proximos" ? "Programado" : "Enviado", "Estado", ""]} empty={view === "proximos" ? "No hay envíos programados" : "Sin envíos"}>
        {data.map((m) => (
          <tr key={m.id} data-message={m.code}>
            <Td><Link href={`${close}&msg=${m.id}`} scroll={false} className="font-mono text-xs text-sky-800 hover:underline">{m.code}</Link><span className="block text-xs text-slate-500">{m.origin} · {m.lang}</span></Td>
            <Td>{m.reservation ? <Link href={`/reservas/${m.reservation.code}`} className="text-sky-800 hover:underline">{m.reservation.code}</Link> : "—"}<span className="block max-w-[14rem] truncate text-xs text-slate-500">{m.reservation?.title}</span></Td>
            <Td className="text-xs">{m.template ? `${m.template.code} · ${m.template.name}` : "Libre"}</Td>
            <Td className="text-xs">{CHANNEL_LABEL[m.channel] ?? m.channel}<span className="block max-w-[12rem] truncate text-slate-500">{m.recipient}</span></Td>
            <Td className="whitespace-nowrap text-xs">{dateTime(view === "proximos" ? m.scheduled_at : m.sent_at ?? m.scheduled_at)}</Td>
            <Td><Pill tone={PAX_STATUS_TONE[m.status] ?? "slate"}>{STATUS_LABEL[m.status] ?? m.status}</Pill>{m.error && <span className="block max-w-[12rem] text-xs text-rose-700">{m.error}</span>}</Td>
            <Td>
              <div className="flex flex-wrap gap-1">
                {m.status === "programado" && <>
                  <ActionForm path={`/notifications/pax-messages/${m.id}/send-now`} idempotent submitLabel="Enviar ahora" submitClass={small} success="Mensaje enviado" inline />
                  <ActionForm path={`/notifications/pax-messages/${m.id}/cancel`} idempotent submitLabel="Cancelar" submitClass={small} success="Envío cancelado" inline />
                </>}
                {m.status === "fallido" && <ActionForm path={`/notifications/pax-messages/${m.id}/retry`} idempotent submitLabel="Reintentar" submitClass={small} success="Reintento enviado" inline />}
              </div>
            </Td>
          </tr>
        ))}
      </Table>
      {detail && (
        <Drawer title={`Mensaje ${detail.code}`} close={close}>
          <dl className="grid grid-cols-2 gap-2 text-sm">
            <Kv k="Estado" v={STATUS_LABEL[detail.status] ?? detail.status} /><Kv k="Canal" v={CHANNEL_LABEL[detail.channel] ?? detail.channel} />
            <Kv k="Destinatario" v={detail.recipient ?? "—"} /><Kv k="Origen" v={detail.origin} />
            <Kv k="Programado" v={dateTime(detail.scheduled_at)} /><Kv k="Enviado" v={dateTime(detail.sent_at)} />
            <Kv k="Entregado" v={dateTime(detail.delivered_at)} /><Kv k="Leído" v={dateTime(detail.read_at)} />
            <Kv k="Intentos" v={String(detail.attempts)} /><Kv k="Id del proveedor" v={detail.provider_message_id ?? "—"} />
          </dl>
          {detail.error && <Notice tone="danger">{detail.error}</Notice>}
          <article className="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
            {detail.subject && <p className="font-medium">{detail.subject}</p>}
            <p className="mt-1 whitespace-pre-wrap">{detail.body}</p>
          </article>
        </Drawer>
      )}
    </div>
  );
}

function Kv({ k, v }: { k: string; v: string }) {
  return <div><dt className="text-xs text-slate-500">{k}</dt><dd className="break-words">{v}</dd></div>;
}
