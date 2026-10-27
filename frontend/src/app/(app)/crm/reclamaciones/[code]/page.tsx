import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Notice, Stepper, btnGhostCls, inputCls } from "@/components/crm/ui";
import { Dl } from "@/components/finance/ui";
import { BookStatus, ClaimsHeader, DeadlinePill } from "@/components/claims/ui";
import { STEPS, type ClaimDetail } from "@/lib/claims";

/** Hoja del libro (spec 12 §2): flujo, plazo, consumidor, bien, vínculos, respuesta, historial, auditoría y constancia. */
export default async function ClaimPage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  let c: ClaimDetail;
  try {
    c = (await api<{ data: ClaimDetail }>(`/crm/claim-book/${encodeURIComponent(code)}`)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) notFound();
    throw e;
  }
  const g = c.consumer.guardian;
  const r = c.response;
  return (
    <>
      <ClaimsHeader active="" subtitle={<><Link href="/crm/reclamaciones" className="text-sky-800 hover:underline">Libro de reclamaciones</Link> / {c.book_number}</>} actions={
        <a href={`/bff/file?path=${encodeURIComponent(`/crm/claim-book/${c.code}/certificate`)}`} target="_blank" className={btnGhostCls}>Constancia PDF</a>
      } />
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <h2 className="text-xl font-semibold text-slate-900"><span className="font-mono">{c.book_number}</span> · {c.type_label}</h2>
        <BookStatus status={c.book_status} label={c.status_label} /> <DeadlinePill d={c.deadline} />
      </div>
      <div className="mb-4"><Stepper steps={STEPS} current={c.step + 1} /></div>
      <p className="mb-4 text-sm text-slate-600">
        Recibida el {dateTime(c.received_at)} por {c.channel_label} en {c.sede} · vence el <strong>{date(c.due_date)}</strong> ({c.response_days} días hábiles)
        {c.ack_sent_at ? ` · constancia enviada ${dateTime(c.ack_sent_at)}` : ""} · caso de Postventa {c.code}
      </p>
      {c.deadline.state === "vencido" && <Notice tone="danger">El plazo venció sin respuesta. Responda cuanto antes: quedará registrada como fuera de plazo.</Notice>}

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="min-w-0 space-y-4 lg:col-span-2">
          <Card title="Consumidor">
            <Dl items={[["Nombre", c.consumer.name], ["Documento", `${c.consumer.doc_type} ${c.consumer.doc_number}`], ["Correo", c.consumer.email], ["Teléfono", c.consumer.phone],
              ["Domicilio", c.consumer.address], ["Menor de edad", c.consumer.is_minor ? "Sí" : "No"],
              ...(g ? [["Apoderado", `${g.name} · ${g.doc_type} ${g.doc_number} (${g.relation})`] as [string, string]] : [])]} />
          </Card>
          <Card title="Bien contratado y reclamación">
            <Dl items={[["Bien", `${c.good_detail.kind}: ${c.good_detail.description}`], ["Monto", c.good_detail.amount ? `${c.good_detail.currency} ${c.good_detail.amount}` : null]]} />
            <h3 className="mt-3 text-xs uppercase tracking-wide text-slate-500">Detalle</h3>
            <p className="whitespace-pre-line text-sm text-slate-800">{c.detail}</p>
            <h3 className="mt-3 text-xs uppercase tracking-wide text-slate-500">Pedido</h3>
            <p className="whitespace-pre-line text-sm text-slate-800">{c.request}</p>
          </Card>
          <Card title="Respuesta al consumidor">
            {r ? (
              <div className="space-y-2 text-sm">
                <p><strong>{c.decisions[r.decision] ?? r.decision}</strong> · enviada el {dateTime(r.sent_at)} a {r.sent_to} por {r.answered_by}
                  {r.late && <span className="ml-2 rounded bg-rose-100 px-1.5 py-0.5 text-xs font-medium text-rose-800">FUERA DE PLAZO</span>}</p>
                <p className="whitespace-pre-line text-slate-800">{r.text}</p>
                <p className="text-slate-600">Acciones: {r.actions}</p>
                {r.resends?.map((x, i) => <p key={i} className="text-xs text-slate-500">Reenviada {dateTime(x.at)} por {x.by} a {x.to}</p>)}
              </div>
            ) : c.can.respond ? (
              <ActionForm path={`/crm/claim-book/${c.code}/response`} idempotent submitLabel="Responder y enviar" success="Respuesta enviada">
                <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Decisión</span>
                  <select name="decision" required className={inputCls}>{Object.entries(c.decisions).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
                <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Respuesta motivada (mínimo 40 caracteres)</span>
                  <textarea name="text" required minLength={40} rows={4} className={inputCls} /></label>
                <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Acciones adoptadas</span>
                  <textarea name="actions" required rows={2} className={inputCls} /></label>
                <p className="text-xs text-slate-500">Se envía por correo a {c.consumer.email}{g ? ` (apoderado: ${g.name})` : ""}.</p>
              </ActionForm>
            ) : <p className="text-sm text-slate-500">Sin respuesta.</p>}
            {c.can.resend && <div className="mt-3"><ActionForm path={`/crm/claim-book/${c.code}/response/resend`} submitLabel="Reenviar respuesta" submitClass={btnGhostCls} success="Respuesta reenviada" inline /></div>}
          </Card>
          <Card title="Historial">
            <ol className="space-y-2 text-sm">{c.events.map((e, i) => <li key={i} className="border-l-2 border-slate-200 pl-3"><p className="text-slate-800">{e.message}</p><p className="text-xs text-slate-500">{e.user} · {dateTime(e.at)}</p></li>)}</ol>
          </Card>
        </div>
        <div className="min-w-0 space-y-4">
          <Card title="Gestión">
            <p className="mb-3 text-sm text-slate-700">Responsable: <strong>{c.owner ?? "—"}</strong></p>
            {c.can.review && (
              <ActionForm path={`/crm/claim-book/${c.code}/review`} ifMatch={c.lock_version} submitLabel="Pasar a evaluación" success="Hoja en evaluación">
                <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Responsable</span>
                  <select name="owner_id" defaultValue={c.owner_id ?? ""} className={inputCls}>{c.owners.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}</select></label>
              </ActionForm>
            )}
            {c.can.close && (
              <ActionForm path={`/crm/claim-book/${c.code}/close`} ifMatch={c.lock_version} submitLabel="Cerrar hoja" success="Hoja cerrada" className="mt-3 space-y-2">
                <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Motivo de cierre</span><input name="note" required maxLength={500} className={inputCls} /></label>
              </ActionForm>
            )}
            {c.book_status === "closed" && <p className="text-sm text-slate-600">Cerrada el {dateTime(c.closed_at)}: {c.close_note}</p>}
          </Card>
          <Card title="Vínculos">
            <p className="mb-2 text-sm">Cliente: {c.client ? <Link href={`/crm/clientes/${c.client.code}`} className="text-sky-800 hover:underline">{c.client.code} · {c.client.name}</Link> : "sin vincular"}</p>
            <p className="mb-3 text-sm">Reserva: {c.reservation ? <Link href={`/reservas/${c.reservation}`} className="text-sky-800 hover:underline">{c.reservation}</Link> : "sin vincular"}</p>
            <ActionForm path={`/crm/claim-book/${c.code}/links`} method="PATCH" submitLabel="Vincular" submitClass={btnGhostCls} success="Vínculos actualizados" className="space-y-2">
              <label className="block text-sm"><span className="mb-1 block text-slate-700">ID del cliente</span><input name="client_id" defaultValue={c.client?.id ?? ""} className={inputCls} /></label>
              <label className="block text-sm"><span className="mb-1 block text-slate-700">Código de reserva</span><input name="reservation_code" defaultValue={c.reservation ?? ""} placeholder="RES-2026-000123" className={inputCls} /></label>
            </ActionForm>
          </Card>
          <Card title="Auditoría">
            <ol className="space-y-2 text-xs">{c.audit.map((a, i) => <li key={i}><p className="font-medium text-slate-800">{a.action.replaceAll("_", " ")}</p><p className="text-slate-600">{a.before ?? "—"} → {a.after ?? "—"}</p><p className="text-slate-500">{a.actor} · {dateTime(a.at)}</p></li>)}</ol>
          </Card>
        </div>
      </div>
    </>
  );
}
