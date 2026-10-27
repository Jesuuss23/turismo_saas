import Link from "@/components/Link";
import { api } from "@/lib/api";
import { dateTime } from "@/lib/crm";
import type { IncidentDetail } from "@/lib/ops";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, inputCls, Notice, PageHeader, Stepper, Table, Td } from "@/components/crm/ui";
import { Field } from "@/components/catalog/ui";
import { OpsActionLink, OpsStatus, SlaBar } from "@/components/ops/ui";
import { EvidenceUpload } from "@/components/ops/EvidenceUpload";
import type { IncidentListMeta } from "../page";

type Search = { accion?: string };
const label = "mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500";

/** Incidencia (spec 05 §2): flujo PROC-037, detalle, bitácora con comentarios, SLA, evidencias y notificaciones. */
export default async function IncidentPage({ params, searchParams }: { params: Promise<{ code: string }>; searchParams: Promise<Search> }) {
  const [{ code }, sp] = await Promise.all([params, searchParams]);
  const [{ data: i }, { meta }] = await Promise.all([
    api<{ data: IncidentDetail }>(`/ops/incidents/${code}`),
    api<{ meta: IncidentListMeta }>("/ops/incidents?filter[status]=cerrada&filter[severity]=baja&filter[type]=__none__"),
  ]);
  const here = `/operaciones/incidencias/${i.code}`;
  const path = `/ops/incidents/${i.code}`;
  const action = sp.accion ? i.actions.find((a) => a.key === sp.accion && a.enabled) : undefined;

  const forms: Record<string, React.ReactNode> = {
    take: (
      <ActionForm path={`${path}/take`} ifMatch={i.lock_version} redirectTo={here} submitLabel="Pasar a en proceso">
        <p className="text-sm text-slate-600">Usted queda como responsable si la incidencia no tiene uno.</p>
      </ActionForm>
    ),
    assign: (
      <ActionForm path={`${path}/assignee`} method="PATCH" ifMatch={i.lock_version} redirectTo={here} submitLabel="Asignar">
        <label className="block text-sm"><span className={label}>Responsable</span>
          <select name="assignee_id" required defaultValue={i.assignee?.id} className={inputCls}>{meta.assignees.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></label>
      </ActionForm>
    ),
    reclassify: (
      <ActionForm path={`${path}/classification`} method="PATCH" ifMatch={i.lock_version} redirectTo={here} submitLabel="Reclasificar">
        <label className="block text-sm"><span className={label}>Tipo</span>
          <select name="type" required defaultValue={i.type} className={inputCls}>{meta.types.map((t) => <option key={t}>{t}</option>)}</select></label>
        <label className="block text-sm"><span className={label}>Severidad</span>
          <select name="severity" required defaultValue={i.severity} className={inputCls}>{Object.entries(meta.severities).map(([k, v]) => <option key={k} value={k}>{v} (SLA {meta.sla_hours[k]} h)</option>)}</select></label>
        <p className="text-xs text-slate-500">Reclasificar a crítica alerta a Gerencia (OPS-BR-61).</p>
      </ActionForm>
    ),
    resolve: (
      <ActionForm path={`${path}/resolve`} ifMatch={i.lock_version} redirectTo={here} submitLabel="Registrar resolución">
        <label className="block text-sm"><span className={label}>Resolución</span><textarea name="resolution" required rows={4} className={inputCls} /></label>
        <p className="text-xs text-slate-500">Para cerrar: alta y crítica requieren resolución, y crítica al menos una evidencia (OPS-BR-62).</p>
      </ActionForm>
    ),
    close: (
      <ActionForm path={`${path}/close`} ifMatch={i.lock_version} redirectTo={here} submitLabel="Cerrar incidencia">
        <label className="block text-sm"><span className={label}>Comentario de cierre</span><textarea name="closing_comment" rows={3} className={inputCls} /></label>
      </ActionForm>
    ),
    reopen: (
      <ActionForm path={`${path}/reopen`} ifMatch={i.lock_version} redirectTo={here} submitLabel="Reabrir">
        <label className="block text-sm"><span className={label}>Motivo (OPS-BR-63)</span><input name="reason" required maxLength={500} className={inputCls} /></label>
      </ActionForm>
    ),
    comment: (
      <ActionForm path={`${path}/comments`} redirectTo={here} submitLabel="Comentar">
        <label className="block text-sm"><span className={label}>Comentario</span><textarea name="message" required rows={3} maxLength={2000} className={inputCls} /></label>
        <label className="block text-sm"><span className={label}>Visibilidad</span>
          <select name="visibility" className={inputCls}><option value="interna">Interna</option><option value="proveedor">Compartida con el proveedor</option></select></label>
      </ActionForm>
    ),
    evidence: <EvidenceUpload path={`${path}/evidences`} close={here} label="Adjuntar evidencia" />,
  };

  return (
    <>
      <PageHeader title={`${i.code} · ${i.title}`}
        subtitle={<span className="flex flex-wrap items-center gap-2"><OpsStatus value={i.severity} label={i.severity_label} /><OpsStatus value={i.status} label={i.status_label} /> {i.type}</span>}
        actions={<Link href="/operaciones/incidencias" className="text-sm text-sky-800 hover:underline">← Incidencias</Link>} />
      <div className="mb-4"><Stepper steps={i.flow} current={i.step} /></div>
      {i.severity === "critica" && ["abierta", "en_proceso"].includes(i.status) && <Notice tone="danger">Incidencia crítica: Operaciones y Gerencia fueron notificadas (push y WhatsApp).</Notice>}
      <div className="mb-4 flex flex-wrap gap-2" aria-label="Acciones">
        {i.actions.map((a, n) => <OpsActionLink key={a.key} a={a} href={`${here}?accion=${a.key}`} primary={n === 0} />)}
      </div>
      <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
        <div className="space-y-4">
          <Card title="Detalle">
            <p className="mb-3 whitespace-pre-line text-sm text-slate-800">{i.description}</p>
            {i.resolution && <p className="mb-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900 ring-1 ring-emerald-200"><strong>Resolución:</strong> {i.resolution}</p>}
            <dl className="grid grid-cols-2 gap-3">
              <Field label="Orden">{i.order ? <Link href={`/operaciones/ordenes/${i.order.code}/incidencias`} className="text-sky-800 hover:underline">{i.order.code}</Link> : "—"}</Field>
              <Field label="Reserva">{i.reservation ? <Link href={`/reservas/${i.reservation.code}`} className="text-sky-800 hover:underline">{i.reservation.code}</Link> : "—"}</Field>
              <Field label="Servicio">{i.service ? `${i.service.code} · ${i.service.name}` : "Toda la orden"}</Field>
              <Field label="Responsable">{i.assignee?.name ?? "Sin asignar"}</Field>
              <Field label="Reportada por">{i.reported_by?.name ?? "—"} · {i.source === "app" ? "app operador" : i.source}</Field>
              <Field label="Registrada">{dateTime(i.created_at)}</Field>
              {i.related_code && <Field label="Relacionada"><Link href={`/operaciones/incidencias/${i.related_code}`} className="text-sky-800 hover:underline">{i.related_code}</Link></Field>}
              {i.closed_at && <Field label="Cerrada">{dateTime(i.closed_at)}</Field>}
            </dl>
          </Card>
          <Card title="Bitácora">
            <ol className="space-y-3 text-sm">
              {i.logs.map((l, n) => (
                <li key={n} className="border-l-2 border-slate-200 pl-3">
                  <p className="text-slate-800">{l.message}{l.visibility === "proveedor" && <span className="ml-1 text-xs text-violet-700">(compartido con proveedor)</span>}</p>
                  <p className="text-xs text-slate-500">{l.user?.name ?? "Sistema"} · {dateTime(l.at)} · {l.kind}</p>
                </li>
              ))}
            </ol>
          </Card>
        </div>
        <div className="space-y-4">
          <Card title="SLA">
            <SlaBar pct={i.sla_pct} breached={i.sla_breached} due={i.sla_due_at} />
            <p className="mt-2 text-xs text-slate-500">{i.sla_hours} h para severidad {i.severity_label.toLowerCase()} · vence {dateTime(i.sla_due_at)}</p>
          </Card>
          <Card title={`Evidencias (${i.evidences.length})`}>
            {i.evidences.length === 0 ? <p className="text-sm text-slate-500">Sin evidencias.</p> : (
              <ul className="grid grid-cols-2 gap-2">
                {i.evidences.map((e) => (
                  <li key={e.id} className="text-xs">
                    {e.mime_type.startsWith("image/")
                      // eslint-disable-next-line @next/next/no-img-element
                      ? <a href={`/bff/evidence/${e.id}`} target="_blank" rel="noreferrer"><img src={`/bff/evidence/${e.id}`} alt={e.file_name} className="h-24 w-full rounded bg-slate-100 object-cover" /></a>
                      : <a href={`/bff/evidence/${e.id}`} target="_blank" rel="noreferrer" className="flex h-24 items-center justify-center rounded bg-slate-100 text-slate-500">PDF</a>}
                    <p className="truncate" title={e.file_name}>{e.file_name}</p>
                    <p className="text-slate-500">{e.uploaded_by?.name} · {dateTime(e.captured_at)}</p>
                  </li>
                ))}
              </ul>
            )}
          </Card>
          <Card title="Notificaciones enviadas">
            <Table head={["Evento", "Canal", "Destinatario", "Fecha"]} empty="Sin notificaciones">
              {i.notifications.map((n, x) => <tr key={x}><Td>{n.event}</Td><Td>{n.channel}</Td><Td>{n.recipient}</Td><Td>{dateTime(n.at)}</Td></tr>)}
            </Table>
          </Card>
        </div>
      </div>
      {action && forms[action.key] && <Drawer title={action.label} close={here}>{forms[action.key]}</Drawer>}
    </>
  );
}
