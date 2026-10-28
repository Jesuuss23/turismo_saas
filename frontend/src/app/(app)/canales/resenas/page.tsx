import Link from "@/components/Link";
import { api } from "@/lib/api";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, Input, Kpis, Notice, PageHeader, Table, Td, Tabs, btnGhostCls, inputCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { CopyBlock } from "@/components/experience/CopyBlock";
import { dateTime } from "@/lib/crm";
import { REVIEW_STATUS_LABEL, stars, type ReviewInbox, type ReviewInvitations, type ReviewSource, type ReviewsWidget } from "@/lib/experience";

const small = "rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50";
const ADMIN = "Requiere permiso admin.settings.manage";
const TABS = [
  { key: "bandeja", label: "Bandeja" },
  { key: "conectores", label: "Conectores" },
  { key: "invitaciones", label: "Invitaciones postviaje" },
  { key: "widget", label: "Widget para el sitio" },
];
const SOURCE_TONE: Record<string, "green" | "amber" | "rose" | "slate"> = { conectado: "green", pendiente: "amber", error: "rose", no_conectado: "slate" };
const REPLY_TONE: Record<string, "slate" | "amber" | "violet" | "green"> = { sin_responder: "slate", pendiente_publicar: "amber", no_admite: "violet", publicada: "green" };

type Search = { tab?: string; resena?: string; conectar?: string; fuente?: string; estado?: string; max?: string };

/**
 * Reseñas externas (spec 17 §2, v2.2): conectores por API oficial (fase 1 Google, TripAdvisor y Trustpilot; fase posterior Facebook,
 * GetYourGuide y Viator), bandeja con respuesta y derivación a postventa, invitaciones postviaje (R-REVIEW-INVITE) y widget de reseñas.
 * Admin, Manager y Ventas ven, responden, invitan y derivan; conectar, desconectar y sincronizar es solo del admin.
 */
export default async function ExternalReviewsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const tab = TABS.some((t) => t.key === sp.tab) ? sp.tab! : "bandeja";
  const [{ data: sources, meta }, { data: inbox }] = await Promise.all([
    api<{ data: ReviewSource[]; meta: { can_connect: boolean } }>("/integrations/review-sources"),
    api<{ data: ReviewInbox }>(`/channels/external-reviews?${new URLSearchParams(Object.entries({ provider: sp.fuente, status: sp.estado, max_rating: sp.max }).filter(([, v]) => v) as [string, string][])}`),
  ]);
  const base = "/canales/resenas";
  const close = `${base}?tab=${tab}`;
  const k = inbox.kpis;

  return (
    <section>
      <PageHeader title="Reseñas externas" subtitle="Reseñas de Google, TripAdvisor, Trustpilot y otras plataformas por su API oficial, para responderlas y llevar los casos a postventa." />
      <Notice>
        Cumplimiento: solo APIs oficiales con contrato o aprobación de cada plataforma, sin scraping, sin publicar reseñas propias como externas, sin filtrar a quién se invita según su opinión (gating) y sin incentivos por reseñar.
        Las reseñas propias de clientes son un módulo aparte (regla R-OWN-REVIEWS, plan Professional), previsto para una fase posterior.
      </Notice>
      <Kpis items={[
        { label: "Reseñas", value: k.count, hint: k.is_example ? "Datos de ejemplo" : undefined },
        { label: "Promedio", value: k.avg ? `${k.avg.toFixed(2)} ★` : "—", hint: k.is_example ? "Datos de ejemplo" : undefined },
        { label: "Sin responder", value: k.unanswered, hint: k.is_example ? "Datos de ejemplo" : undefined },
        { label: "3★ o menos", value: k.low, hint: k.is_example ? "Datos de ejemplo" : undefined },
      ]} />
      <Tabs base={base} tabs={TABS} active={tab} />

      {tab === "bandeja" && <InboxTab inbox={inbox} sources={sources} sp={sp} />}
      {tab === "conectores" && <SourcesTab sources={sources} canConnect={meta.can_connect} />}
      {tab === "invitaciones" && <InvitationsTab />}
      {tab === "widget" && <WidgetTab sources={sources} inbox={inbox} />}

      {sp.resena && <ReviewDrawer review={inbox.items.find((r) => r.id === sp.resena)} close={close} />}
      {sp.conectar && meta.can_connect && (() => {
        const s = sources.find((x) => x.provider === sp.conectar);
        return s ? (
          <Drawer title={`Conectar ${s.name} (demo)`} close={close}>
            <p className="mb-3 text-sm text-slate-600">Alcance que se solicitará: {s.scopes.join(", ")}. {s.can_reply ? "Permite responder por API." : "API de solo lectura: las respuestas se copian y publican a mano."}</p>
            <ActionForm path={`/integrations/review-sources/${s.provider}`} idempotent submitLabel="Conectar" success={`${s.name} conectado`} redirectTo={close} className="space-y-3">
              <Input name="account_ref" label="Cuenta o ubicación" required maxLength={120} placeholder="Andes Travel · Cusco" />
              <Input name="secret" label="Credencial (API key o token OAuth)" type="password" required minLength={8} maxLength={500} autoComplete="off" />
              <label className="flex items-start gap-2 text-sm"><input type="checkbox" name="contract_ack" value="true" required className="mt-1" />Declaro que la agencia tiene contrato o aprobación de {s.name} para usar su API oficial (RV-01).</label>
              <p className="text-xs text-slate-500">La credencial se guarda cifrada y nunca se vuelve a mostrar: solo sus últimos 4 caracteres.</p>
            </ActionForm>
          </Drawer>
        ) : null;
      })()}
    </section>
  );
}

function InboxTab({ inbox, sources, sp }: { inbox: ReviewInbox; sources: ReviewSource[]; sp: Search }) {
  return (
    <div className="space-y-3">
      <form method="get" className="flex flex-wrap items-end gap-2 text-sm">
        <input type="hidden" name="tab" value="bandeja" />
        <label className="block"><span className="mb-1 block font-medium text-slate-700">Fuente</span>
          <select name="fuente" defaultValue={sp.fuente ?? ""} className={inputCls}><option value="">Todas</option>{sources.map((s) => <option key={s.provider} value={s.provider}>{s.name}</option>)}</select>
        </label>
        <label className="block"><span className="mb-1 block font-medium text-slate-700">Respuesta</span>
          <select name="estado" defaultValue={sp.estado ?? ""} className={inputCls}><option value="">Todas</option>{Object.entries(REVIEW_STATUS_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
        </label>
        <label className="block"><span className="mb-1 block font-medium text-slate-700">Estrellas</span>
          <select name="max" defaultValue={sp.max ?? ""} className={inputCls}><option value="">Todas</option><option value="3">3★ o menos</option></select>
        </label>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Fuente", "Autor y fecha", "Estrellas", "Reseña", "Producto", "Respuesta"]} empty="Sin reseñas. Conecte una plataforma en «Conectores».">
        {inbox.items.map((r) => (
          <tr key={r.id} data-review={r.external_id}>
            <Td className="text-xs">{r.source_name}{r.is_example && <span className="block text-slate-500">ejemplo</span>}</Td>
            <Td>{r.author}<span className="block text-xs text-slate-500">{r.date}</span></Td>
            <Td className="whitespace-nowrap text-amber-600" aria-label={`${r.rating} de 5`}>{stars(r.rating)}</Td>
            <Td className="max-w-md"><Link href={`/canales/resenas?tab=bandeja&resena=${r.id}`} scroll={false} className="font-medium text-sky-800 hover:underline">{r.title ?? "Sin título"}</Link><span className="line-clamp-2 block text-xs text-slate-600">{r.body}</span></Td>
            <Td className="text-xs">{r.product ? <Link href={`/catalogo/productos/${r.product.ref}`} className="hover:underline">{r.product.ref} · {r.product.name}</Link> : "—"}</Td>
            <Td><Pill tone={REPLY_TONE[r.response_status] ?? "slate"}>{REVIEW_STATUS_LABEL[r.response_status] ?? r.response_status}</Pill>{r.conversation_code && <span className="block text-xs text-slate-500">Postventa {r.conversation_code}</span>}</Td>
          </tr>
        ))}
      </Table>
    </div>
  );
}

function ReviewDrawer({ review: r, close }: { review?: ReviewInbox["items"][number]; close: string }) {
  if (!r) return null;
  return (
    <Drawer title={`${r.source_name} · ${r.author}`} close={close}>
      <p className="text-amber-600">{stars(r.rating)} <span className="text-xs text-slate-500">{r.date} · {r.lang}</span></p>
      {r.title && <p className="mt-2 font-medium">{r.title}</p>}
      <p className="mt-1 whitespace-pre-wrap text-sm text-slate-700">{r.body}</p>
      {r.url && <a href={r.url} target="_blank" rel="noreferrer" className="mt-1 inline-block text-xs text-sky-800 underline">Ver en la plataforma</a>}
      <p className="mt-3 text-sm">Estado: <Pill tone={REPLY_TONE[r.response_status] ?? "slate"}>{REVIEW_STATUS_LABEL[r.response_status] ?? r.response_status}</Pill></p>

      {r.reply ? (
        <div className="mt-3 space-y-2">
          <p className="text-sm font-medium">Respuesta guardada{r.replied_at ? ` · ${dateTime(r.replied_at)}` : ""}</p>
          <CopyBlock text={r.reply} label="Copiar respuesta" pre={false} />
          {r.response_status !== "publicada" && (
            <ActionForm path={`/channels/external-reviews/${r.id}/mark-published`} idempotent submitLabel="Marcar como publicada" submitClass={small} success="Respuesta marcada como publicada" inline />
          )}
        </div>
      ) : (
        <ActionForm path={`/channels/external-reviews/${r.id}/reply`} idempotent submitLabel={r.can_reply ? "Guardar respuesta (simulado)" : "Guardar y copiar"} success={r.can_reply ? "Respuesta pendiente de publicar" : "Respuesta guardada: cópiela y publíquela en la plataforma"} className="mt-3 space-y-2">
          <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Respuesta (mín. 20 caracteres; se sugiere un texto)</span>
            <textarea name="reply" required minLength={20} maxLength={4000} rows={5} defaultValue={r.suggested_reply} className={inputCls} />
          </label>
          {!r.can_reply && <p className="text-xs text-slate-500">La API de {r.source_name} es de solo lectura: la respuesta queda guardada para copiarla y publicarla a mano.</p>}
        </ActionForm>
      )}

      <div className="mt-4 border-t border-slate-100 pt-3">
        {r.conversation_code
          ? <p className="text-sm">Derivada a postventa: <Link href={`/inbox/${r.conversation_code}`} className="text-sky-800 underline">{r.conversation_code}</Link></p>
          : <ActionForm path={`/channels/external-reviews/${r.id}/escalate`} idempotent submitLabel="Derivar a postventa" submitClass={small} success="Conversación creada en la bandeja de postventa" inline
              disabled={r.rating > 3} disabledReason="Solo se derivan reseñas de 3★ o menos" />}
      </div>
    </Drawer>
  );
}

function SourcesTab({ sources, canConnect }: { sources: ReviewSource[]; canConnect: boolean }) {
  const phases = [{ n: 1, label: "Priorizados (fase 1)" }, { n: 2, label: "Fase posterior" }];
  return (
    <div className="space-y-4">
      <Notice>Decisión del cliente (30/09/2026): se priorizan Google Business Profile, TripAdvisor y Trustpilot; Facebook, GetYourGuide y Viator quedan para una fase posterior (disponibles en demo).</Notice>
      {phases.map((ph) => (
        <Card key={ph.n} title={ph.label}>
          <div className="grid grid-cols-1 gap-3 [&>*]:min-w-0 md:grid-cols-3">
            {sources.filter((s) => (ph.n === 1 ? s.phase === 1 : s.phase !== 1)).map((s) => (
              <article key={s.provider} className="rounded-md border border-slate-200 p-3 text-sm" data-source={s.provider}>
                <p className="flex items-center justify-between gap-2"><span className="font-medium">{s.name}</span><Pill tone={SOURCE_TONE[s.status] ?? "slate"}>{s.status.replace("_", " ")}</Pill></p>
                <p className="mt-1 text-xs text-slate-500">{s.can_reply ? "Responde por API" : "Solo lectura (guardar y copiar)"} · {s.supports_invites ? "admite invitaciones" : "sin invitaciones"}</p>
                {s.status !== "no_conectado" && (
                  <p className="mt-1 text-xs text-slate-600">{s.account_ref} · credencial {s.secret_hint ?? "—"}<br />{s.review_count} reseña(s){s.rating ? ` · ${s.rating.toFixed(2)} ★` : ""} · sincronizado {dateTime(s.last_sync_at)}</p>
                )}
                {s.last_error && <p className="mt-1 text-xs text-rose-700">{s.last_error}</p>}
                <div className="mt-2 flex flex-wrap gap-1">
                  {s.status === "no_conectado"
                    ? (canConnect ? <Link href={`/canales/resenas?tab=conectores&conectar=${s.provider}`} scroll={false} className={small}>Conectar (demo)</Link> : <button type="button" disabled title={ADMIN} className={small}>Conectar (demo)</button>)
                    : <>
                      <ActionForm path={`/integrations/review-sources/${s.provider}/sync`} submitLabel="Sincronizar" submitClass={small} success="Sincronización completada" inline disabled={!canConnect} disabledReason={ADMIN} />
                      <ActionForm path={`/integrations/review-sources/${s.provider}`} method="DELETE" submitLabel="Desconectar" submitClass={small} success={`${s.name} desconectado`} inline confirm={`¿Desconectar ${s.name}? Se revoca el token y se borra la credencial.`} disabled={!canConnect} disabledReason={ADMIN} />
                    </>}
                </div>
              </article>
            ))}
          </div>
        </Card>
      ))}
    </div>
  );
}

async function InvitationsTab() {
  const { data } = await api<{ data: ReviewInvitations }>("/channels/review-invitations");
  return (
    <div className="space-y-3">
      {!data.rule_on && <Notice tone="warning">La regla «Invitación a reseñar» (R-REVIEW-INVITE) está desactivada o fuera del plan: no se pueden enviar invitaciones. <Link href="/admin/reglas" className="underline">Centro de reglas</Link></Notice>}
      <p className="text-sm text-slate-600">Reservas con el viaje terminado. La invitación automática sale {data.days_after} día(s) después del fin del viaje (plantilla PMT-009); se invita a todos los pasajeros por igual, sin filtrar por su opinión.</p>
      <Table head={["Reserva", "Cliente", "Fin del viaje", "Invitación", ""]} empty="No hay reservas finalizadas">
        {data.items.map((r) => (
          <tr key={r.id} data-invitation={r.code}>
            <Td><Link href={`/reservas/${r.code}`} className="text-sky-800 hover:underline">{r.code}</Link><span className="block text-xs text-slate-500">{r.title}</span></Td>
            <Td>{r.client}{!r.email && <span className="block text-xs text-amber-700">sin email</span>}</Td>
            <Td>{r.travel_to}</Td>
            <Td className="text-xs">{r.invited ? `${r.invite_status ?? "enviada"} · ${dateTime(r.invited_at)}` : "—"}</Td>
            <Td>
              <ActionForm path={`/reservations/${r.code}/review-invitations`} idempotent submitLabel={r.invited ? "Reenviar" : "Invitar"} submitClass={small} success="Invitación enviada" inline
                disabled={!data.rule_on || !r.email} disabledReason={!data.rule_on ? "R-REVIEW-INVITE está desactivada" : "El cliente no tiene email"} />
            </Td>
          </tr>
        ))}
      </Table>
    </div>
  );
}

async function WidgetTab({ sources, inbox }: { sources: ReviewSource[]; inbox: ReviewInbox }) {
  const { data: cfg } = await api<{ data: ReviewsWidget }>("/channels/reviews-widget");
  const shown = inbox.items.filter((r) => cfg.sources.includes(r.provider) && r.rating >= cfg.min_rating).slice(0, cfg.count);
  const snippet = `<qhapaq-reviews sources="${cfg.sources.join(",")}" min-rating="${cfg.min_rating}" count="${cfg.count}" layout="${cfg.layout}"></qhapaq-reviews>\n<script src="https://cdn.qhapaq.app/widget.js" async></script>`;
  return (
    <div className="grid grid-cols-1 gap-4 [&>*]:min-w-0 lg:grid-cols-2">
      <Card title="Configuración">
        <ActionForm path="/channels/reviews-widget" method="PUT" submitLabel="Guardar widget" success="Widget de reseñas guardado" className="space-y-3 text-sm">
          <fieldset className="flex flex-wrap gap-3"><legend className="mb-1 font-medium text-slate-700">Fuentes</legend>
            {sources.map((s) => <label key={s.provider} className="flex items-center gap-1"><input type="checkbox" name="sources[]" value={s.provider} defaultChecked={cfg.sources.includes(s.provider)} />{s.name}</label>)}
          </fieldset>
          <div className="grid grid-cols-3 gap-2">
            <label className="block"><span className="mb-1 block font-medium text-slate-700">Mínimo ★</span><select name="min_rating" defaultValue={String(cfg.min_rating)} className={inputCls}>{[1, 2, 3, 4, 5].map((n) => <option key={n}>{n}</option>)}</select></label>
            <label className="block"><span className="mb-1 block font-medium text-slate-700">Cantidad</span><input type="number" name="count" min={1} max={20} defaultValue={cfg.count} className={inputCls} /></label>
            <label className="block"><span className="mb-1 block font-medium text-slate-700">Diseño</span><select name="layout" defaultValue={cfg.layout} className={inputCls}><option value="carrusel">Carrusel</option><option value="cuadricula">Cuadrícula</option><option value="lista">Lista</option></select></label>
          </div>
        </ActionForm>
        <p className="mb-1 mt-4 text-sm font-medium">Código para el sitio</p>
        <CopyBlock text={snippet} />
      </Card>
      <Card title="Vista previa">
        <div className={cfg.layout === "cuadricula" ? "grid gap-2 sm:grid-cols-2" : cfg.layout === "carrusel" ? "flex gap-2 overflow-x-auto" : "space-y-2"} data-reviews-preview>
          {shown.length === 0 && <p className="text-sm text-slate-500">Ninguna reseña cumple los filtros.</p>}
          {shown.map((r) => (
            <blockquote key={r.id} className="min-w-56 rounded-md border border-slate-200 p-3 text-sm">
              <p className="text-amber-600">{stars(r.rating)}</p>
              <p className="mt-1 line-clamp-4 text-slate-700">{r.body}</p>
              <footer className="mt-1 text-xs text-slate-500">{r.author} · {r.source_name}</footer>
            </blockquote>
          ))}
        </div>
      </Card>
    </div>
  );
}
