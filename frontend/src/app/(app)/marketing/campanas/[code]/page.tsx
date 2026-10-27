import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { dateTime } from "@/lib/crm";
import { Badge, btnGhostCls, Card, Input, Kpis, Notice, PageHeader, Select, Stepper, Table, Td, Timeline } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { BackLink, Facts, Funnel } from "@/components/marketing/ui";
import { CAMPAIGN_FLOW, pct, pen, REASON_LABELS, type Campaign, type Options } from "@/lib/marketing";

const STEP: Record<string, number> = { borrador: 1, programada: 2, enviando: 3, enviada: 4, cancelada: 1 };

export default async function CampaignPage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  let c: Campaign;
  try {
    c = (await api<{ data: Campaign }>(`/marketing/campaigns/${encodeURIComponent(code)}`)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) notFound();
    throw e;
  }
  const options = (await api<{ data: Options }>("/marketing/options")).data;
  const m = c.metrics;
  const sent = c.status === "enviando" || c.status === "enviada";
  const counts = c.snapshot ?? c.audience;
  const base = `/marketing/campaigns/${c.code}`;

  return (
    <>
      <BackLink href="/marketing/campanas">Campañas</BackLink>
      <PageHeader title={`${c.code} · ${c.name}`} subtitle={<>{c.channel_label} · {c.segment.code} {c.segment.name} · <Badge status={c.status}>{c.status_label}</Badge></>}
        actions={<>
          {c.can.edit && <Link href={`/marketing/campanas/${c.code}/editar`} className={btnGhostCls}>Editar</Link>}
          <ActionForm path={`${base}/duplicate`} inline submitLabel="Duplicar" submitClass={btnGhostCls} redirectTo="/marketing/campanas/{data.code}" />
        </>} />
      <div className="mb-4 overflow-x-auto"><Stepper steps={CAMPAIGN_FLOW} current={STEP[c.status] ?? 1} failed={c.status === "cancelada"} /></div>
      {c.status === "cancelada" && <Notice tone="danger">Campaña cancelada: {c.cancel_reason}</Notice>}
      {c.status === "programada" && <Notice>Programada para el {dateTime(c.scheduled_at)}. El job JOB-16 la dispara a la hora indicada; la audiencia se recalcula en ese momento.</Notice>}

      {(c.can.test || c.can.schedule || c.can.send || c.can.unschedule || c.can.process || c.can.cancel) && (
        <Card title="Acciones" className="mb-4">
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3 [&>*]:min-w-0">
            {c.can.test && (
              <ActionForm path={`${base}/test-send`} idempotent submitLabel="Enviar prueba">
                <Select name="to_user_id" label="Usuario interno" defaultValue={options.test_users[0]?.id}
                  options={options.test_users.map((u) => ({ value: u.id, label: `${u.name} · ${c.channel === "email" ? u.email : u.mobile ?? "sin móvil"}` }))} />
                <p className="text-xs text-slate-500">{c.has_valid_test ? "Ya hay una prueba vigente." : "Obligatoria antes de programar o enviar (MKT-BR-04)."}</p>
              </ActionForm>
            )}
            {c.can.schedule && (
              <ActionForm path={`${base}/schedule`} ifMatch={c.lock_version} submitLabel="Programar" disabled={!c.has_valid_test} disabledReason="Envíe una prueba primero">
                <Input name="scheduled_at" type="datetime-local" label="Fecha y hora (Lima)" required />
                {c.channel === "whatsapp" && <p className="text-xs text-slate-500">WhatsApp comercial solo entre 08:00 y 20:00.</p>}
              </ActionForm>
            )}
            {c.can.send && (
              <ActionForm path={`${base}/send`} idempotent submitLabel="Enviar ahora" disabled={!c.has_valid_test} disabledReason="Envíe una prueba primero"
                confirm={`¿Enviar ${c.code} ahora a ${counts?.eligible ?? 0} destinatario(s)?`}>
                <p className="text-sm text-slate-600">Envío inmediato a {counts?.eligible ?? 0} destinatario(s) elegibles.</p>
              </ActionForm>
            )}
            {c.can.unschedule && <ActionForm path={`${base}/unschedule`} ifMatch={c.lock_version} submitLabel="Desprogramar (volver a borrador)" submitClass={btnGhostCls} />}
            {c.can.process && (
              <ActionForm path={`${base}/process`} submitLabel="Procesar envío (job)">
                <p className="text-sm text-slate-600">{m.pending} pendiente(s) por enviar en este lote.</p>
              </ActionForm>
            )}
            {c.can.cancel && (
              <ActionForm path={`${base}/cancel`} ifMatch={c.lock_version} submitLabel="Cancelar campaña"
                submitClass="rounded-md border border-rose-300 bg-white px-3 py-2 text-sm font-medium text-rose-800 hover:bg-rose-50">
                <Input name="reason" label="Motivo" required />
              </ActionForm>
            )}
          </div>
        </Card>
      )}

      {sent && (
        <>
          <Kpis items={[
            { label: "Enviados", value: m.sent, hint: `${m.delivered} entregados · ${m.failed + m.bounced} fallidos` },
            { label: c.channel === "email" ? "Apertura" : "Lectura", value: pct(m.open_rate), hint: `${m.opened} contactos` },
            { label: "CTR", value: pct(m.ctr), hint: `${m.clicked} clics · ${m.unsubscribed} bajas` },
            { label: "Ingresos atribuidos", value: pen(m.revenue_pen, 0), hint: `${m.reservations} reservas · ${m.leads} leads` },
          ]} />
          <div className="mb-4 grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
            <Card title="Embudo">
              <Funnel steps={[{ label: "Enviados", value: m.sent }, { label: "Entregados", value: m.delivered }, { label: c.channel === "email" ? "Abiertos" : "Leídos", value: m.opened },
                { label: "Clics", value: m.clicked }, { label: "Conversiones", value: m.reservations + m.leads }]} />
            </Card>
            <Card title="Conversiones atribuidas (last-touch 30 días)">
              {c.attribution.length === 0 ? <p className="text-sm text-slate-500">Aún sin conversiones.</p> : (
                <ul className="divide-y divide-slate-100 text-sm">
                  {c.attribution.map((a) => (
                    <li key={`${a.kind}-${a.code}`} className="flex flex-wrap justify-between gap-2 py-1.5">
                      <Link className="text-sky-800 hover:underline" href={a.kind === "reservation" ? `/reservas/${a.code}` : a.kind === "lead" ? `/crm/leads/${a.code}` : "/canales/ecommerce"}>{a.code} · {a.label}</Link>
                      <span className="text-slate-600">{a.revenue_pen !== null ? pen(a.revenue_pen) : "—"} · {dateTime(a.at)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          </div>
          <Card title={`Destinatarios (${c.recipients.length})`} className="mb-4">
            <Table head={["Contacto", "Dirección", "Estado", "Enviado", "Apertura", ""]}>
              {c.recipients.map((r) => (
                <tr key={r.id}>
                  <Td>{r.name}</Td>
                  <Td className="break-all text-xs">{r.address}</Td>
                  <Td><Badge status={r.status}>{r.status_label}</Badge>{r.unsubscribed_at && <p className="text-xs text-rose-700">Baja {dateTime(r.unsubscribed_at)}</p>}</Td>
                  <Td className="whitespace-nowrap text-xs">{dateTime(r.sent_at)}</Td>
                  <Td className="whitespace-nowrap text-xs">{r.opened_at ? dateTime(r.opened_at) : "—"}{r.clicked_at ? " · clic" : ""}</Td>
                  <Td>{!r.unsubscribed_at && (
                    <ActionForm path={`${base}/recipients/${r.id}/unsubscribe`} inline submitLabel="Registrar baja" confirm={`¿Registrar la baja de ${r.name}?`}
                      submitClass="rounded border border-rose-300 px-2 py-1 text-xs text-rose-800 hover:bg-rose-50" />
                  )}</Td>
                </tr>
              ))}
            </Table>
          </Card>
        </>
      )}

      <div className="mb-4 grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
        <Card title="Resumen">
          <Facts rows={[
            ["Segmento", `${c.segment.code} · ${c.segment.name}`], ["Plantilla", `${c.template.code} · ${c.template.name}`],
            ["Código promocional", c.promo_code ? `${c.promo_code}${c.promotion ? ` · ${c.promotion.value} %${c.promotion.date_to ? ` · hasta ${c.promotion.date_to}` : ""}` : ""}` : "—"],
            ["UTM", <code key="u" className="break-all text-xs">{c.utm}</code>], ["Programación", c.scheduled_at ? dateTime(c.scheduled_at) : "—"],
            ["Enviada", c.sent_at ? dateTime(c.sent_at) : "—"], ["Responsable", c.owner],
            ["Pruebas", c.tests.length ? c.tests.map((t) => `${t.to} (${t.address}) ${dateTime(t.sent_at)}${t.valid ? "" : " · invalidada"}`).join("; ") : "Ninguna"],
          ]} />
        </Card>
        <Card title={c.snapshot ? `Audiencia al envío (${dateTime(c.snapshot.at)})` : "Audiencia estimada (ahora)"}>
          {counts && (
            <>
              <p className="text-sm"><span className="text-2xl font-semibold text-slate-900">{counts.eligible}</span> elegibles de {counts.matched} coincidentes</p>
              <p className="mt-1 text-xs text-slate-600">{Object.entries(REASON_LABELS).map(([k, l]) => `${l}: ${counts[k as "optout"]}`).join(" · ")}</p>
            </>
          )}
          {c.suppressed.length > 0 && (
            <ul className="mt-3 max-h-48 space-y-1 overflow-y-auto text-sm">
              {c.suppressed.map((s, i) => <li key={i} className="flex justify-between gap-2"><span className="min-w-0 break-words">{s.name}</span><span className="text-xs text-amber-800">{s.reason_label}</span></li>)}
            </ul>
          )}
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
        <Card title="Mensaje">
          {c.preview ? (
            <>
              <p className="mb-1 text-xs text-slate-500">Vista previa con {c.preview.contact}</p>
              {c.preview.subject && <p className="font-medium text-slate-900">{c.preview.subject}</p>}
              <p className="whitespace-pre-wrap break-words text-sm text-slate-800">{c.preview.body}</p>
            </>
          ) : (
            <>
              {c.subject && <p className="font-medium text-slate-900">{c.subject}</p>}
              <p className="whitespace-pre-wrap break-words text-sm text-slate-800">{c.body}</p>
            </>
          )}
        </Card>
        <Card title="Historial"><Timeline rows={c.history} /></Card>
      </div>
    </>
  );
}
