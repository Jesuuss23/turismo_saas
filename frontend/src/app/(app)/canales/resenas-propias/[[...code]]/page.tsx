import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { ActionForm } from "@/components/crm/ActionForm";
import { ExportButton } from "@/components/crm/ExportButton";
import { Card, Drawer, Input, Kpis, Notice, PageHeader, Select, Table, Td, Tabs, TextArea, btnGhostCls } from "@/components/crm/ui";
import { Bars, Pill } from "@/components/admin/ui";
import { date, dateTime } from "@/lib/crm";
import { STATUS_TONE, stars, type OwnReview, type OwnReviewConfig, type OwnReviewStats, type ReviewRequestRow } from "@/lib/own-reviews";

const BASE = "/canales/resenas-propias";
const small = "rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50";
const TABS = [
  { key: "resumen", label: "Resumen" },
  { key: "bandeja", label: "Bandeja de moderación" },
  { key: "todas", label: "Todas las reseñas" },
  { key: "solicitudes", label: "Solicitudes enviadas" },
  { key: "ajustes", label: "Reglas y ajustes" },
];
const PLAN = (p: string | null) => (p ? p[0].toUpperCase() + p.slice(1) : "—");
const READ_ONLY = "Solo lectura: Ventas consulta las reseñas de su cartera; la moderación es de E-commerce o Administración.";

type Sp = { tab?: string; q?: string; status?: string; stars?: string; product?: string; preview?: string };

/**
 * Reseñas propias verificadas (spec 18 §2, R-OWN-REVIEWS): resumen, bandeja de moderación, todas las reseñas con su bitácora,
 * solicitudes postviaje y ajustes. Ventas (crm.clients.manage) ve solo su cartera y en solo lectura (RVW-11).
 */
export default async function OwnReviewsPage({ params, searchParams }: { params: Promise<{ code?: string[] }>; searchParams: Promise<Sp> }) {
  const [{ code = [] }, sp] = await Promise.all([params, searchParams]);
  if (code.length > 1) notFound();
  const { data: cfg } = await api<{ data: OwnReviewConfig }>("/settings/own-reviews");
  const tab = TABS.some((t) => t.key === sp.tab) ? sp.tab! : code.length ? "todas" : "resumen";
  const moderate = cfg.can_moderate;

  const header = (
    <PageHeader title="Reseñas propias verificadas" subtitle="Solo califican los pasajeros con viaje realizado; se publican en el sitio y el widget con la marca «Viaje verificado»."
      actions={(
        <div className="flex flex-wrap gap-2">
          {cfg.can_edit_rule && <Link href="/admin/reglas?regla=R-OWN-REVIEWS" className={btnGhostCls}>Regla R-OWN-REVIEWS</Link>}
          <Link href="/canales/resenas" className={btnGhostCls}>Reseñas externas</Link>
          {cfg.state === "on" && (
            <ActionForm path="/reviews/export-feed" idempotent inline submitLabel="Exportar al widget / sitio" submitClass={btnGhostCls}
              disabled={!moderate} disabledReason={READ_ONLY} />
          )}
        </div>
      )} />
  );

  // RVW-01: regla apagada o fuera del plan → solo el aviso.
  if (cfg.state !== "on") {
    return (
      <section>
        {header}
        <div data-rule-off>
          <Notice tone="warning">
            {cfg.state === "off_plan"
              ? <>Las reseñas propias están disponibles desde el plan {PLAN(cfg.min_plan)} (plan actual: {PLAN(cfg.plan)}). Las reseñas guardadas se conservan, pero no se muestran en el sitio ni se envían invitaciones. <Link href="/admin/suscripcion" className="underline">Mejorar plan</Link></>
              : <>La regla R-OWN-REVIEWS está desactivada: no hay formulario para el pasajero, bloque en el sitio, JSON-LD ni invitaciones. Las reseñas guardadas se conservan. {cfg.can_edit_rule && <Link href="/admin/reglas?regla=R-OWN-REVIEWS" className="underline">Activar en el centro de reglas</Link>}</>}
          </Notice>
        </div>
      </section>
    );
  }

  const stats = (await api<{ data: OwnReviewStats }>("/reviews/stats")).data;
  let drawer: React.ReactNode = null;
  if (code[0]) {
    try {
      const { data } = await api<{ data: OwnReview }>(`/reviews/${encodeURIComponent(code[0])}`);
      drawer = <ReviewDrawer r={data} cfg={cfg} close={`${BASE}?tab=${tab}`} />;
    } catch (e) {
      if (!(e instanceof ApiError) || ![403, 404].includes(e.status)) throw e;
      drawer = <div role="alert" data-toast className="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg bg-rose-700 px-4 py-3 text-sm text-white shadow-lg">
        {e.status === 403 ? "Esta reseña es de un cliente de otra cartera: no puede abrirla." : `No existe la reseña ${code[0]}.`}
      </div>;
    }
  }

  return (
    <section>
      {header}
      {cfg.settings.is_demo && <Notice>Demo — editable: reseñas, solicitudes y ajustes de ejemplo rotulados «ejemplo (demo)».</Notice>}
      {cfg.own_portfolio && <Notice>Mi cartera: ve solo las reseñas de sus reservas y de los clientes de su cartera, en modo consulta.</Notice>}
      <Kpis items={[
        { label: "Calificación promedio", value: stats.average !== null ? <span>{stats.average.toFixed(1)} <span className="text-base text-amber-500">★</span></span> : "—", hint: "de las publicadas" },
        { label: "Reseñas publicadas", value: stats.published, hint: `${stats.received} recibidas` },
        { label: "% recomendación", value: stats.recommend_pct !== null ? `${stats.recommend_pct} %` : "—", hint: "4–5 ★ sobre publicadas" },
        { label: "Pendientes de moderar", value: stats.pending, hint: `${stats.pending_flagged} con alertas` },
      ]} />
      <Tabs base={BASE} tabs={TABS} active={tab} />
      {tab === "resumen" && <Summary stats={stats} cfg={cfg} />}
      {tab === "bandeja" && <Inbox cfg={cfg} />}
      {tab === "todas" && <AllReviews sp={sp} cfg={cfg} />}
      {tab === "solicitudes" && <Requests cfg={cfg} preview={sp.preview} />}
      {tab === "ajustes" && <Settings cfg={cfg} />}
      {drawer}
    </section>
  );
}

function Stars({ n }: { n: number }) {
  return <span className="whitespace-nowrap text-amber-500" aria-label={`${n} de 5 estrellas`}>{stars(n)}</span>;
}

function Summary({ stats, cfg }: { stats: OwnReviewStats; cfg: OwnReviewConfig }) {
  return (
    <div className="grid grid-cols-1 gap-4 [&>*]:min-w-0 lg:grid-cols-2">
      <Card title="Distribución de estrellas"><Bars rows={[5, 4, 3, 2, 1].map((n) => ({ label: `${n} ★`, value: stats.distribution[n] ?? 0 }))} /></Card>
      <Card title="Promedio por producto">
        <Bars rows={stats.by_product.map((p) => ({ label: `${p.ref} · ${p.name}`, value: p.average, hint: `${p.count} reseña(s)` }))} />
      </Card>
      <Card title="Promedio por aspecto">
        <Bars rows={Object.entries(stats.subratings).filter(([, v]) => v !== null).map(([k, v]) => ({ label: cfg.subratings[k] ?? k, value: v as number }))} />
      </Card>
      <Card title="Últimas publicadas">
        {stats.latest.length === 0 ? <p className="text-sm text-slate-500">Aún no hay reseñas publicadas.</p> : (
          <ul className="space-y-3 text-sm">
            {stats.latest.map((r) => (
              <li key={r.code}>
                <Link href={`${BASE}/${r.code}?tab=resumen`} className="font-medium hover:underline">{r.title}</Link> <Stars n={r.stars} />
                <p className="text-xs text-slate-500">{r.author_display} · {r.product?.name} · {date(r.published_at)}{r.is_demo ? " · ejemplo (demo)" : ""}</p>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}

async function Inbox({ cfg }: { cfg: OwnReviewConfig }) {
  const { data } = await api<{ data: OwnReview[] }>("/reviews?tab=bandeja");
  if (data.length === 0) return <p className="text-sm text-slate-500">No hay reseñas pendientes de moderar.</p>;
  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-500">RVW-07: no hay filtro por estrellas; una reseña negativa se publica igual que una positiva. Rechazar u ocultar exige motivo.</p>
      {data.map((r) => (
        <article key={r.code} data-review={r.code} className="rounded-lg border border-slate-200 bg-white p-4">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div className="min-w-0">
              <p className="font-medium">{r.title} <Stars n={r.stars} /></p>
              <p className="text-xs text-slate-500">{r.code} · {r.author_name} · {r.product?.ref} {r.product?.name} · {r.reservation}{r.historic ? " (histórica)" : ""} · {dateTime(r.created_at)}{r.is_demo ? " · ejemplo (demo)" : ""}</p>
            </div>
            <Pill tone={STATUS_TONE[r.status]}>{r.status_label}</Pill>
          </div>
          <p className="mt-2 text-sm text-slate-700 [overflow-wrap:anywhere]">{r.body}</p>
          {r.flags.length > 0 && <p className="mt-2 rounded bg-amber-50 px-2 py-1 text-xs text-amber-900" data-flags>⚠ Alertas: {r.flags.join(" · ")}</p>}
          <ModerationActions r={r} cfg={cfg} back={`${BASE}?tab=bandeja`} />
        </article>
      ))}
    </div>
  );
}

function ModerationActions({ r, cfg, back }: { r: OwnReview; cfg: OwnReviewConfig; back: string }) {
  const off = !cfg.can_moderate;
  return (
    <div className="mt-3 flex flex-wrap items-end gap-2">
      {r.status !== "publicada" && (
        <ActionForm path={`/reviews/${r.code}/publish`} ifMatch={r.lock_version} inline submitLabel="Publicar" submitClass={small} success="Reseña publicada" redirectTo={back}
          disabled={off || !r.consent} disabledReason={off ? READ_ONLY : "RVW-05: el pasajero no autorizó publicarla."}
          confirm={r.flags.length ? `La reseña tiene alertas (${r.flags.join("; ")}). ¿Revisó el texto y desea publicarla?` : undefined}>
          {r.flags.length > 0 && <input type="hidden" name="confirm_flags" value="true" />}
        </ActionForm>
      )}
      {["pendiente", "publicada", "oculta"].includes(r.status) && <ReasonForm r={r} action="reject" label="Rechazar" off={off} back={back} />}
      {["pendiente", "publicada", "rechazada"].includes(r.status) && <ReasonForm r={r} action="hide" label="Ocultar" off={off} back={back} />}
      <Link href={`${BASE}/${r.code}?tab=${back.split("tab=")[1] ?? "todas"}`} className={small}>{off ? "Detalle" : "Responder / detalle"}</Link>
    </div>
  );
}

function ReasonForm({ r, action, label, off, back }: { r: OwnReview; action: "reject" | "hide"; label: string; off: boolean; back: string }) {
  return (
    <details className="text-xs">
      <summary className={`${small} cursor-pointer list-none`} aria-disabled={off}>{label}…</summary>
      <ActionForm path={`/reviews/${r.code}/${action}`} ifMatch={r.lock_version} submitLabel={`${label} con motivo`} submitClass={small} redirectTo={back}
        success={action === "reject" ? "Reseña rechazada" : "Reseña oculta"} disabled={off} disabledReason={READ_ONLY} className="mt-2 flex flex-wrap items-end gap-2">
        <div className="min-w-56"><Input name="reason" label={`Motivo (${label.toLowerCase()})`} required minLength={5} maxLength={500} disabled={off} /></div>
      </ActionForm>
    </details>
  );
}

async function AllReviews({ sp, cfg }: { sp: Sp; cfg: OwnReviewConfig }) {
  const q = new URLSearchParams(Object.entries({ q: sp.q, status: sp.status, stars: sp.stars, product: sp.product }).filter(([, v]) => v) as [string, string][]);
  const { data } = await api<{ data: OwnReview[] }>(`/reviews${q.toString() ? `?${q}` : ""}`);
  const products = [...new Map(data.filter((r) => r.product).map((r) => [r.product!.ref, r.product!.name])).entries()];
  return (
    <>
      <form className="mb-3 flex flex-wrap items-end gap-2" action={BASE}>
        <input type="hidden" name="tab" value="todas" />
        <label className="text-xs">Buscar<input name="q" defaultValue={sp.q} placeholder="Título, texto, autor o código" className="ml-1 w-56 rounded border border-slate-300 px-2 py-1 text-sm" /></label>
        <label className="text-xs">Estado<select name="status" defaultValue={sp.status ?? ""} className="ml-1 rounded border border-slate-300 px-2 py-1 text-sm">
          <option value="">Todos</option>{["pendiente", "publicada", "rechazada", "oculta"].map((s) => <option key={s} value={s}>{s}</option>)}</select></label>
        <label className="text-xs">Estrellas<select name="stars" defaultValue={sp.stars ?? ""} className="ml-1 rounded border border-slate-300 px-2 py-1 text-sm">
          <option value="">Todas</option>{[5, 4, 3, 2, 1].map((n) => <option key={n} value={n}>{n} ★</option>)}</select></label>
        <label className="text-xs">Producto<select name="product" defaultValue={sp.product ?? ""} className="ml-1 rounded border border-slate-300 px-2 py-1 text-sm">
          <option value="">Todos</option>{products.map(([ref, name]) => <option key={ref} value={ref}>{ref} · {name}</option>)}</select></label>
        <button className={small}>Filtrar</button>
        <span className="ml-auto"><ExportButton resource="reviews.own" filters={{ q: sp.q, status: sp.status, stars: sp.stars, product: sp.product }} /></span>
      </form>
      <Table head={["Reseña", "★", "Producto", "Reserva / cliente", "Estado", ""]} empty="Sin reseñas con estos filtros">
        {data.map((r) => (
          <tr key={r.code} data-review={r.code}>
            <Td><span className="font-medium">{r.title}</span><span className="block text-xs text-slate-500">{r.code} · {r.author_display}{r.is_demo ? " · ejemplo (demo)" : ""}</span></Td>
            <Td><Stars n={r.stars} /></Td>
            <Td className="text-xs">{r.product ? `${r.product.ref} · ${r.product.name}` : "—"}</Td>
            <Td className="text-xs">{r.reservation}{r.historic ? " (histórica)" : ""}<span className="block text-slate-500">{r.client?.name}{r.client?.owner ? ` · ${r.client.owner}` : ""}</span></Td>
            <Td><Pill tone={STATUS_TONE[r.status]}>{r.status_label}</Pill>{r.flags.length > 0 && <span className="ml-1 text-xs text-amber-700" title={r.flags.join(" · ")}>⚠</span>}</Td>
            <Td><Link href={`${BASE}/${r.code}?tab=todas`} className={small}>Detalle</Link></Td>
          </tr>
        ))}
      </Table>
      {!cfg.can_moderate && <p className="mt-2 text-xs text-slate-500">{READ_ONLY}</p>}
    </>
  );
}

function ReviewDrawer({ r, cfg, close }: { r: OwnReview; cfg: OwnReviewConfig; close: string }) {
  const off = !cfg.can_moderate;
  return (
    <Drawer title={`Reseña ${r.code}`} close={close}>
      <div className="space-y-3 text-sm" data-drawer={r.code}>
        <div className="flex flex-wrap items-center gap-2"><Pill tone={STATUS_TONE[r.status]}>{r.status_label}</Pill>{r.verified && <Pill tone="sky">Viaje verificado</Pill>}{r.is_demo && <Pill>ejemplo (demo)</Pill>}</div>
        <p className="text-lg font-semibold">{r.title} <Stars n={r.stars} /></p>
        <p className="text-slate-700 [overflow-wrap:anywhere]">{r.body}</p>
        {Object.keys(r.subratings).length > 0 && (
          <ul className="flex flex-wrap gap-2 text-xs">{Object.entries(r.subratings).map(([k, v]) => <li key={k} className="rounded bg-slate-100 px-2 py-0.5">{cfg.subratings[k] ?? k}: {v}/5</li>)}</ul>
        )}
        {r.photos.length > 0 && <p className="text-xs text-slate-500">Fotos: {r.photos.map((p) => p.name).join(", ")}</p>}
        {r.flags.length > 0 && <Notice tone="warning">Alertas: {r.flags.join(" · ")}</Notice>}
        {r.moderation_reason && r.status !== "publicada" && <p className="text-xs text-slate-600">Motivo: {r.moderation_reason}</p>}
        <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
          <dt className="text-slate-500">Autor</dt><dd>{r.author_name}{r.country ? ` · ${r.country}` : ""}</dd>
          <dt className="text-slate-500">Producto</dt><dd>{r.product ? `${r.product.ref} · ${r.product.name}` : "—"}</dd>
          <dt className="text-slate-500">Reserva</dt><dd>{r.reservation}{r.historic ? " (histórica)" : ""}</dd>
          <dt className="text-slate-500">Cliente</dt><dd>{r.client ? `${r.client.code} · ${r.client.name}` : "—"}</dd>
          <dt className="text-slate-500">Viaje</dt><dd>{date(r.travel_date)}</dd>
          <dt className="text-slate-500">Consentimiento</dt><dd>{r.consent ? "Autorizó publicar" : "Solo para la agencia"}</dd>
          <dt className="text-slate-500">Origen</dt><dd>{r.source}{r.request ? ` · ${r.request}` : ""}</dd>
        </dl>
        {r.reply && <div className="rounded border-l-4 border-sky-600 bg-sky-50 px-3 py-2 text-sm" data-reply><p className="text-xs font-medium text-sky-900">Respuesta de la agencia · {dateTime(r.replied_at)}</p><p>{r.reply}</p></div>}
        <ActionForm path={`/reviews/${r.code}/reply`} method="PUT" ifMatch={r.lock_version} submitLabel={r.reply ? "Editar respuesta" : "Responder"} success="Respuesta guardada: se avisó al pasajero"
          disabled={off} disabledReason={READ_ONLY} redirectTo={`${BASE}/${r.code}?tab=${close.split("tab=")[1] ?? "todas"}`}>
          <TextArea name="text" label="Respuesta pública (sin datos personales, RVW-08)" defaultValue={r.reply ?? ""} required minLength={5} maxLength={1500} rows={3} disabled={off} />
        </ActionForm>
        <ModerationActions r={r} cfg={cfg} back={close} />
        <section>
          <h3 className="mb-1 mt-4 text-sm font-semibold">Bitácora de moderación</h3>
          <ol className="space-y-1 text-xs" data-log>
            {(r.log ?? []).map((l, i) => <li key={i}><span className="font-medium">{l.action}</span> · {l.user} · {dateTime(l.at)}{l.note ? ` — ${l.note}` : ""}</li>)}
          </ol>
        </section>
      </div>
    </Drawer>
  );
}

async function Requests({ cfg, preview }: { cfg: OwnReviewConfig; preview?: string }) {
  const { data } = await api<{ data: ReviewRequestRow[] }>("/review-requests");
  const off = !cfg.can_moderate;
  const pv = preview && !off ? (await api<{ data: { subject: string; body: string; channel: string; to: string } }>(`/review-requests/${encodeURIComponent(preview)}/preview`, { method: "POST" }).catch(() => null))?.data : null;
  return (
    <>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-slate-500">RVW-09: se invita a todos los pasajeros elegibles {cfg.params.daysAfter} día(s) después del viaje a las 09:00, sin incentivos. Recordatorio a los {cfg.settings.invite.reminder_days} día(s), vence a los {cfg.settings.invite.expire_days}.</p>
        <ActionForm path="/review-requests/schedule-pending" idempotent inline submitLabel="Programar invitaciones pendientes" submitClass={small} disabled={off} disabledReason={READ_ONLY} />
      </div>
      <Table head={["Solicitud", "Reserva / cliente", "Canal", "Programada", "Estado", ""]} empty="Sin solicitudes">
        {data.map((q) => (
          <tr key={q.code} data-request={q.code}>
            <Td className="font-mono text-xs">{q.code}{q.is_demo && <span className="block font-sans text-slate-500">ejemplo (demo)</span>}</Td>
            <Td className="text-xs">{q.reservation?.code}<span className="block text-slate-500">{q.client?.name} · {q.to}</span></Td>
            <Td className="text-xs">{q.channel} · {q.lang}</Td>
            <Td className="text-xs">{dateTime(q.scheduled_at)}{q.sent_at && <span className="block text-slate-500">enviada {dateTime(q.sent_at)}</span>}{q.opened_at && <span className="block text-slate-500">abierta {dateTime(q.opened_at)}</span>}</Td>
            <Td><Pill tone={STATUS_TONE[q.status] ?? "slate"}>{q.status_label}</Pill></Td>
            <Td>
              <div className="flex flex-wrap gap-1">
                {q.status === "programada" && <ActionForm path={`/review-requests/${q.code}/send`} inline submitLabel="Enviar ahora" submitClass={small} success="Invitación enviada" disabled={off} disabledReason={READ_ONLY} />}
                {["enviada", "abierta", "vencida"].includes(q.status) && <ActionForm path={`/review-requests/${q.code}/send`} inline submitLabel="Reenviar" submitClass={small} success="Invitación reenviada con un enlace nuevo" disabled={off} disabledReason={READ_ONLY} />}
                {q.status === "programada" && <ActionForm path={`/review-requests/${q.code}/cancel`} inline submitLabel="Cancelar" submitClass={small} success="Solicitud cancelada" confirm={`¿Cancelar la invitación ${q.code}?`} disabled={off} disabledReason={READ_ONLY} />}
                {!off && <Link href={`${BASE}?tab=solicitudes&preview=${q.code}`} className={small} scroll={false}>Vista previa</Link>}
              </div>
            </Td>
          </tr>
        ))}
      </Table>
      {pv && (
        <Drawer title={`Vista previa · ${preview}`} close={`${BASE}?tab=solicitudes`}>
          <p className="text-xs text-slate-500">{pv.channel} · para {pv.to}</p>
          <p className="mt-2 font-medium">{pv.subject}</p>
          <pre className="mt-2 whitespace-pre-wrap rounded bg-slate-50 p-3 text-sm font-sans" data-preview>{pv.body}</pre>
        </Drawer>
      )}
    </>
  );
}

function Settings({ cfg }: { cfg: OwnReviewConfig }) {
  const s = cfg.settings;
  const off = !cfg.can_moderate;
  const yes = [{ value: "true", label: "Sí" }, { value: "false", label: "No" }];
  return (
    <div className="grid grid-cols-1 gap-4 [&>*]:min-w-0 lg:grid-cols-2">
      <Card title="Parámetros de la regla R-OWN-REVIEWS">
        {!cfg.can_edit_rule && <Notice>Solo el administrador cambia los parámetros de la regla.</Notice>}
        <ActionForm path="/settings/own-reviews" method="PATCH" submitLabel="Guardar parámetros" success="Parámetros de la regla guardados" disabled={!cfg.can_edit_rule}
          disabledReason="Requiere admin.settings.manage" className="space-y-3">
          <Input name="params.daysAfter" type="number" min={0} max={365} label="Días después del viaje para invitar (daysAfter)" defaultValue={cfg.params.daysAfter} disabled={!cfg.can_edit_rule} />
          <Input name="params.minStarsAutoPublish" type="number" min={1} max={5} label="Estrellas mínimas para autopublicar (minStarsAutoPublish)" defaultValue={cfg.params.minStarsAutoPublish} disabled={!cfg.can_edit_rule} />
          <Select name="params.moderation" label="Moderación previa (moderation)" options={yes} defaultValue={String(cfg.params.moderation)} />
        </ActionForm>
      </Card>
      <Card title="Moderación">
        <ActionForm path="/settings/own-reviews" method="PATCH" submitLabel="Guardar moderación" success="Ajustes de moderación guardados" disabled={off} disabledReason={READ_ONLY} className="space-y-3">
          <Select name="auto_publish" label="Autopublicar aunque haya moderación (sin alertas y con las estrellas mínimas)" options={yes} defaultValue={String(s.auto_publish)} />
          <Select name="personal_data" label="Datos personales (correo, teléfono, documento)" options={[{ value: "retener", label: "Retener para moderar" }, { value: "enmascarar", label: "Enmascarar «[dato oculto]»" }]} defaultValue={s.personal_data} />
          <TextArea name="banned_words" label="Palabras no permitidas (separadas por coma)" defaultValue={s.banned_words.join(", ")} rows={2} />
          <fieldset className="text-sm"><legend className="mb-1 font-medium text-slate-700">Aspectos que califica el pasajero</legend>
            <div className="flex flex-wrap gap-3">{Object.entries(cfg.subratings).map(([k, l]) => (
              <label key={k} className="flex items-center gap-1"><input type="checkbox" name="active_subratings[]" value={k} defaultChecked={s.active_subratings.includes(k)} />{l}</label>
            ))}</div>
          </fieldset>
        </ActionForm>
      </Card>
      <Card title="Publicación en el sitio y el widget">
        <ActionForm path="/settings/own-reviews" method="PATCH" submitLabel="Guardar publicación" success="Publicación guardada" disabled={off} disabledReason={READ_ONLY} className="space-y-3">
          <Select name="show_on_site" label="Mostrar en el sitio (ficha, tarjetas, inicio y JSON-LD)" options={yes} defaultValue={String(s.show_on_site)} />
          <Select name="show_on_widget" label="Mostrar en el widget" options={yes} defaultValue={String(s.show_on_widget)} />
        </ActionForm>
        <p className="mt-3 text-xs text-slate-500" data-last-export>{s.last_export ? `Último feed: ${dateTime(s.last_export.at)} · ${s.last_export.by} · ${s.last_export.count} reseña(s)` : "El feed aún no se exportó."}</p>
      </Card>
      <Card title="Texto de la invitación">
        <ActionForm path="/settings/own-reviews" method="PATCH" submitLabel="Guardar invitación" success="Invitación guardada" disabled={off} disabledReason={READ_ONLY} className="space-y-3">
          <Select name="invite.channel" label="Canal" options={[{ value: "email", label: "Correo" }, { value: "whatsapp", label: "WhatsApp" }]} defaultValue={s.invite.channel} />
          <div className="grid grid-cols-2 gap-2">
            <Input name="invite.reminder_days" type="number" min={0} max={30} label="Recordatorio (días)" defaultValue={s.invite.reminder_days} />
            <Input name="invite.expire_days" type="number" min={1} max={90} label="Vence (días)" defaultValue={s.invite.expire_days} />
          </div>
          <Input name="invite.subject" label="Asunto" defaultValue={s.invite.subject} required minLength={5} maxLength={160} />
          <TextArea name="invite.body" label="Mensaje (variables {nombre}, {agencia}, {producto}, {enlace}; sin incentivos)" defaultValue={s.invite.body} rows={6} required />
        </ActionForm>
      </Card>
    </div>
  );
}
