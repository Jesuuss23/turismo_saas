import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, inputCls, Notice } from "@/components/crm/ui";
import { Check, Hidden } from "@/components/catalog/ui";
import { date } from "@/lib/crm";
import type { Quote, QuoteMeta } from "@/lib/quotes";

const label = "mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500";

/** Diálogos de acciones de la cotización (`?accion=`). El backend valida permisos, estado y versión en cada llamada. */
export function QuoteDialog({ action, q, meta, close }: { action: string; q: Quote; meta: QuoteMeta; close: string }) {
  const base = `/quotes/${q.code}`;
  const back = `/cotizaciones/${q.code}`;
  const v = q.version;
  const internal = q.internal_approval;
  const title = q.actions.find((a) => a.key === action)?.label ?? action;

  const body = (() => {
    switch (action) {
      case "send":
        return (
          <ActionForm path={`${base}/send`} ifMatch={q.lock_version} idempotent redirectTo={back} submitLabel={q.status === "borrador" ? "Enviar cotización" : "Reenviar"}>
            <p className="text-sm text-slate-600">Se congela la versión {v?.version_no} (QTE-BR-19) y se genera el enlace del cliente válido hasta {date(q.valid_until)}.</p>
            <fieldset>
              <legend className={label}>Canales</legend>
              {meta.channels.map((c) => {
                const missing = (c === "Email" && !q.client?.email) || (c === "WhatsApp" && !q.client?.phone);
                return (
                  <label key={c} className="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="channels[]" value={c} defaultChecked={c === "Email" && !missing} className="h-4 w-4" />
                    {c}{missing && <span className="text-xs text-amber-700">(el cliente no tiene {c === "Email" ? "correo" : "teléfono"}, QTE-BR-23)</span>}
                  </label>
                );
              })}
            </fieldset>
            <label className="block text-sm"><span className={label}>Mensaje</span>
              <textarea name="message" rows={3} className={inputCls} defaultValue={`Hola ${q.client?.name ?? ""}, te comparto la cotización ${q.code}.`} /></label>
          </ActionForm>
        );
      case "request_approval":
        return (
          <ActionForm path={`${base}/internal-approval-requests`} redirectTo={back} submitLabel="Solicitar aprobación">
            {internal && <p className="text-sm text-slate-600">Motivos: {internal.reasons.join("; ")}</p>}
            <label className="block text-sm"><span className={label}>Justificación comercial</span>
              <textarea name="note" rows={4} required className={inputCls} /></label>
          </ActionForm>
        );
      case "approve_internal":
      case "reject_internal":
        return (
          <ActionForm path={`${base}/versions/${v?.version_no}/internal-approval`} ifMatch={q.lock_version} redirectTo={back}
            submitLabel={action === "approve_internal" ? "Aprobar" : "Rechazar"}>
            <Hidden name="decision" value={action === "approve_internal" ? "approve" : "reject"} />
            {internal?.pending
              ? <p className="text-sm text-slate-600">Solicitud de {internal.pending.requested_by?.name}: «{internal.pending.request_note}». Motivos: {internal.pending.reasons.join("; ")}.</p>
              : <p className="text-sm text-slate-600">Motivos: {internal?.reasons.join("; ")}.</p>}
            <label className="block text-sm"><span className={label}>{action === "approve_internal" ? "Comentario" : "Motivo del rechazo"}</span>
              <textarea name="note" rows={3} required className={inputCls} /></label>
          </ActionForm>
        );
      case "negotiate":
        return (
          <ActionForm path={`${base}/negotiations`} redirectTo={back} submitLabel="Registrar">
            <label className="block text-sm"><span className={label}>Canal</span>
              <select name="channel" className={inputCls}>{meta.negotiation_channels.map((c) => <option key={c}>{c}</option>)}</select></label>
            <label className="block text-sm"><span className={label}>Contrapropuesta del cliente</span>
              <textarea name="note" rows={4} required className={inputCls} /></label>
            <Check name="create_version" label="Crear una nueva versión en borrador para ajustar la propuesta" />
          </ActionForm>
        );
      case "approve": {
        const needsInternal = internal && ["requerida", "pendiente", "rechazada"].includes(internal.state) && meta.can.approve_internal;
        return (
          <ActionForm path={`${base}/approve`} ifMatch={q.lock_version} idempotent redirectTo={back} submitLabel="Registrar aprobación">
            <p className="text-sm text-slate-600">Registra la aceptación del cliente sobre la versión {v?.version_no} con su evidencia (QTE-BR-21).</p>
            <label className="block text-sm"><span className={label}>Aprobado por</span>
              <input name="approved_by_name" required defaultValue={q.client?.name ?? ""} className={inputCls} /></label>
            <label className="block text-sm"><span className={label}>Evidencia</span>
              <select name="channel" className={inputCls}>{meta.evidence.map((c) => <option key={c}>{c}</option>)}</select></label>
            <label className="block text-sm"><span className={label}>Referencia (correo, chat, documento)</span>
              <input name="ref" required className={inputCls} /></label>
            {needsInternal && (
              <label className="block text-sm"><span className={label}>Aprobación interna en el mismo paso (QTE-BR-13)</span>
                <textarea name="internal_approval.note" rows={2} required className={inputCls} placeholder={`Motivos: ${internal.reasons.join("; ")}`} /></label>
            )}
          </ActionForm>
        );
      }
      case "reject":
        return (
          <ActionForm path={`${base}/reject`} ifMatch={q.lock_version} redirectTo={back} submitLabel="Rechazar cotización" submitClass="rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800">
            <label className="block text-sm"><span className={label}>Motivo</span>
              <textarea name="reason" rows={3} required className={inputCls} /></label>
          </ActionForm>
        );
      case "new_version":
        return (
          <ActionForm path={`${base}/versions`} ifMatch={q.lock_version} redirectTo={`/cotizaciones/${q.code}/editar`} submitLabel="Crear versión">
            <p className="text-sm text-slate-600">Copia los ítems de la versión {v?.version_no}, actualiza la validez y deja la nueva versión en borrador (QTE-BR-18).</p>
            <label className="block text-sm"><span className={label}>Nota</span><input name="note" className={inputCls} /></label>
          </ActionForm>
        );
      case "duplicate":
        return (
          <ActionForm path={`${base}/duplicate`} idempotent redirectTo="/cotizaciones/{data.code}/editar" submitLabel="Duplicar">
            <p className="text-sm text-slate-600">Crea una cotización nueva en borrador con los mismos ítems y precios vigentes.</p>
          </ActionForm>
        );
      case "reassign":
        return (
          <ActionForm path={`${base}/reassign`} ifMatch={q.lock_version} redirectTo={back} submitLabel="Reasignar">
            <label className="block text-sm"><span className={label}>Nuevo asesor</span>
              <select name="owner_id" required className={inputCls} defaultValue="">
                <option value="" disabled>Seleccione…</option>
                {meta.owners.filter((o) => o.id !== q.owner?.id).map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
              </select></label>
            <label className="block text-sm"><span className={label}>Nota</span><input name="note" className={inputCls} /></label>
          </ActionForm>
        );
      case "token":
        return (
          <div className="space-y-3">
            {q.token
              ? <Notice>Enlace activo hasta {date(q.token.expires_at)}: <a href={q.token.url} className="break-all font-mono text-xs underline" data-testid="quote-link">{q.token.url}</a></Notice>
              : <p className="text-sm text-slate-600">Aún no hay enlace. Se genera también al enviar.</p>}
            <ActionForm path={`${base}/token`} submitLabel={q.token ? "Generar un enlace nuevo" : "Generar enlace"} success="Enlace generado"
              confirm={q.token ? "El enlace anterior dejará de funcionar. ¿Continuar?" : undefined} />
          </div>
        );
      case "register_view":
        return (
          <ActionForm path={`${base}/views`} redirectTo={back} submitLabel="Registrar apertura">
            <p className="text-sm text-slate-600">Use esta opción si el cliente confirmó que abrió la cotización por otro medio (QTE-BR-22).</p>
          </ActionForm>
        );
      default:
        return <p className="text-sm text-slate-600">Acción no disponible.</p>;
    }
  })();

  return <Drawer title={title} close={close}>{body}</Drawer>;
}
