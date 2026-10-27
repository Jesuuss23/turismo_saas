import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import type { OrderDetail, ResourceCard, Rule } from "@/lib/ops";
import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, inputCls, Notice } from "@/components/crm/ui";
import { Hidden } from "@/components/catalog/ui";
import { AssignForm } from "./AssignForm";
import { EvidenceUpload } from "./EvidenceUpload";
import { RuleList } from "./ui";

const label = "mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500";
const danger = "rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800 disabled:opacity-50";

export type IncidentMeta = { types: string[]; severities: Record<string, string>; sla_hours: Record<string, number>; assignees: { id: string; name: string }[] };

/** Formulario de nueva incidencia (PROC-037); se reutiliza en la orden y en el listado de incidencias. */
export function IncidentForm({ meta, redirectTo, orders, order, services = [] }: {
  meta: IncidentMeta; redirectTo: string; order?: string; orders?: { id: string; code: string; title: string }[]; services?: { id: string; label: string }[];
}) {
  return (
    <ActionForm path="/ops/incidents" idempotent redirectTo={redirectTo} submitLabel="Registrar incidencia">
      {order ? <Hidden name="order_id" value={order} /> : orders && (
        <label className="block text-sm"><span className={label}>Orden operativa</span>
          <select name="order_id" className={inputCls} defaultValue=""><option value="">Sin orden (general)</option>{orders.map((o) => <option key={o.id} value={o.id}>{o.code} · {o.title}</option>)}</select></label>
      )}
      {services.length > 0 && (
        <label className="block text-sm"><span className={label}>Servicio afectado</span>
          <select name="reservation_service_id" className={inputCls} defaultValue=""><option value="">Toda la orden</option>{services.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}</select></label>
      )}
      <label className="block text-sm"><span className={label}>Título</span><input name="title" required maxLength={200} className={inputCls} /></label>
      <div className="grid grid-cols-2 gap-3">
        <label className="block text-sm"><span className={label}>Tipo</span>
          <select name="type" required className={inputCls}>{meta.types.map((t) => <option key={t}>{t}</option>)}</select></label>
        <label className="block text-sm"><span className={label}>Severidad</span>
          <select name="severity" required className={inputCls} defaultValue="media">{Object.entries(meta.severities).map(([k, v]) => <option key={k} value={k}>{v} (SLA {meta.sla_hours[k]} h)</option>)}</select></label>
      </div>
      <label className="block text-sm"><span className={label}>Responsable</span>
        <select name="assignee_id" className={inputCls} defaultValue=""><option value="">El coordinador de la orden (OPS-BR-65)</option>{meta.assignees.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></label>
      <label className="block text-sm"><span className={label}>Descripción</span><textarea name="description" required rows={4} className={inputCls} /></label>
      <p className="text-xs text-slate-500">Una incidencia crítica notifica de inmediato a Operaciones y Gerencia (OPS-BR-61).</p>
    </ActionForm>
  );
}

/** Diálogos de la Orden 360 (`?accion=`): el backend revalida reglas y lock_version al aplicar. */
export async function OrderDialog({ action, o, close, sp }: { action: string; o: OrderDetail; close: string; sp: Record<string, string | undefined> }) {
  const base = `/ops/orders/${o.code}`;
  const service = sp.s ? o.services.find((s) => s.id === sp.s) : undefined;
  const assignment = sp.a ? o.services.map((s) => s.assignment).find((a) => a?.id === sp.a) : undefined;
  const assignedService = assignment ? o.services.find((s) => s.assignment?.id === assignment.id) : undefined;
  const titles: Record<string, string> = {
    to_assignment: "Pasar a asignación", mark_assigned: "Marcar como asignada", confirm: "Confirmar operación", start: "Iniciar ejecución",
    coordinator: "Cambiar coordinador", new_incident: "Nueva incidencia", supplier_request: "Enviar solicitud al proveedor",
    supplier_confirm: "Registrar confirmación del proveedor", supplier_reject: "Registrar rechazo del proveedor", ensure_assignment: "Agregar asignación de recursos",
    complete_service: "Marcar servicio completado", issue_voucher: "Emitir voucher", assign: assignment?.status === "pendiente" ? "Asignar recursos" : "Reasignar recursos",
    confirm_assignment: "Registrar confirmación del recurso", release: "Liberar recursos", add_task: "Agregar tarea", evidence: "Registrar evidencia", remove_evidence: "Retirar evidencia",
  };
  const serviceLine = service && <p className="text-sm text-slate-600">{service.code} · {service.name} · {date(service.date)}{service.time && ` ${service.time}`} · {service.provider?.name ?? "Sin proveedor"}</p>;
  const missing = <p className="text-sm text-slate-600">El elemento ya no está disponible en la orden.</p>;
  const serviceOptions = o.services.filter((s) => s.status !== "cancelado").map((s) => ({ id: s.id, label: `${s.code} · ${s.name} · ${date(s.date)}` }));

  const body = await (async () => {
    switch (action) {
      case "to_assignment":
      case "mark_assigned":
      case "confirm":
      case "start": {
        const path = { to_assignment: "to-assignment", mark_assigned: "mark-assigned", confirm: "confirm", start: "start" }[action];
        const text = {
          to_assignment: "La orden pasa a Pendiente de asignación: se crean las asignaciones de los servicios que requieren guía, conductor o vehículo.",
          mark_assigned: "Todos los servicios que lo requieren tienen recursos asignados (OPS-BR-05).",
          confirm: "Requiere proveedores confirmados y recursos confirmados por el guía o conductor (OPS-BR-05). Se notifica al pasajero.",
          start: "La orden pasa a En ejecución; la reserva pasa a En operación.",
        }[action];
        const a = o.actions.find((x) => x.key === action);
        return (
          <ActionForm path={`${base}/${path}`} ifMatch={o.lock_version} redirectTo={close} submitLabel={titles[action]} disabled={a ? !a.enabled : false} disabledReason={a?.reason ?? undefined}>
            <p className="text-sm text-slate-600">{text}</p>
            {a && !a.enabled && <Notice tone="warning">{a.reason}</Notice>}
          </ActionForm>
        );
      }
      case "coordinator": {
        const { meta } = await api<{ meta: { coordinators: { id: string; name: string }[] } }>("/ops/orders/candidates");
        return (
          <ActionForm path={`${base}/coordinator`} method="PATCH" redirectTo={close} submitLabel="Cambiar coordinador">
            <label className="block text-sm"><span className={label}>Coordinador</span>
              <select name="coordinator_id" required defaultValue={o.coordinator?.id} className={inputCls}>{meta.coordinators.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></label>
            <p className="text-xs text-slate-500">Solo usuarios activos con rol de Operaciones o Gerencia (OPS-BR-04).</p>
          </ActionForm>
        );
      }
      case "new_incident": {
        const { meta } = await api<{ meta: IncidentMeta }>("/ops/incidents?per_page=1");
        return <IncidentForm meta={meta} redirectTo={`/operaciones/ordenes/${o.code}/incidencias`} order={o.id} services={serviceOptions} />;
      }
      case "supplier_request":
        if (!service) return missing;
        return (
          <ActionForm path={`/ops/reservation-services/${service.id}/supplier-request`} redirectTo={close} submitLabel="Enviar solicitud">
            {serviceLine}
            <p className="text-sm text-slate-600">Se envía la solicitud por correo y queda registrada; el servicio pasa a Solicitado.</p>
          </ActionForm>
        );
      case "supplier_confirm":
        if (!service) return missing;
        return (
          <ActionForm path={`/ops/reservation-services/${service.id}/supplier-confirmation`} redirectTo={close} submitLabel="Registrar confirmación">
            {serviceLine}
            <label className="block text-sm"><span className={label}>Código de confirmación (OPS-BR-11)</span><input name="confirmation_code" required maxLength={60} className={inputCls} /></label>
            <label className="block text-sm"><span className={label}>Hora</span><input name="time" type="time" defaultValue={service.time ?? ""} className={inputCls} /></label>
            <label className="block text-sm"><span className={label}>Nota</span><textarea name="note" rows={2} maxLength={500} className={inputCls} /></label>
          </ActionForm>
        );
      case "supplier_reject":
        if (!service) return missing;
        return (
          <ActionForm path={`/ops/reservation-services/${service.id}/supplier-rejection`} redirectTo={close} submitLabel="Registrar rechazo" submitClass={danger}>
            {serviceLine}
            <label className="block text-sm"><span className={label}>Motivo</span><textarea name="reason" rows={2} required maxLength={300} className={inputCls} /></label>
            <Notice tone="warning">Ventas recibe el aviso para buscar una alternativa con el cliente (OPS-BR-12).</Notice>
          </ActionForm>
        );
      case "ensure_assignment":
        if (!service) return missing;
        return (
          <ActionForm path={`${base}/assignments`} redirectTo={close} submitLabel="Agregar asignación">
            {serviceLine}
            <Hidden name="reservation_service_id" value={service.id} />
            <p className="text-sm text-slate-600">El servicio pasa a requerir guía, conductor o vehículo.</p>
          </ActionForm>
        );
      case "complete_service":
        if (!service) return missing;
        return (
          <ActionForm path={`/ops/reservation-services/${service.id}/complete`} redirectTo={close} submitLabel="Marcar completado">
            {serviceLine}
            <p className="text-sm text-slate-600">El servicio y su asignación quedan completados (PROC-036).</p>
          </ActionForm>
        );
      case "issue_voucher": {
        if (!service) return missing;
        const { data: check } = await api<{ data: { blocks: Rule[]; warnings: Rule[] } }>("/ops/vouchers/check", { method: "POST", body: JSON.stringify({ reservation_service_id: service.id }) });
        return (
          <ActionForm path="/ops/vouchers" idempotent redirectTo="/operaciones/vouchers/{data.code}" submitLabel="Emitir voucher" disabled={check.blocks.length > 0} disabledReason="Resuelva los bloqueos">
            {serviceLine}
            <RuleList blocks={check.blocks} warnings={check.warnings} />
            <Hidden name="reservation_service_id" value={service.id} />
            <label className="block text-sm"><span className={label}>Nota para el proveedor (opcional)</span><textarea name="note" rows={2} maxLength={500} className={inputCls} /></label>
            <p className="text-xs text-slate-500">Se envía al proveedor y al pasajero, y queda disponible en su app (OPS-BR-44).</p>
          </ActionForm>
        );
      }
      case "assign": {
        if (!assignment) return missing;
        const { data: resources } = await api<{ data: ResourceCard[] }>("/ops/resources");
        return (
          <div className="space-y-3">
            <p className="text-sm text-slate-600">{assignment.label} · {date(assignment.date)}{assignment.time && ` ${assignment.time}`} · {o.pax} pax</p>
            <AssignForm assignmentId={assignment.id} lockVersion={assignment.lock_version} close={close} resources={resources}
              current={{ guide_id: assignment.guide?.id ?? null, driver_id: assignment.driver?.id ?? null, vehicle_id: assignment.vehicle?.id ?? null }} />
          </div>
        );
      }
      case "confirm_assignment":
        if (!assignment) return missing;
        return (
          <ActionForm path={`/ops/assignments/${assignment.id}/confirm`} ifMatch={assignment.lock_version} redirectTo={close} submitLabel="Registrar confirmación">
            <p className="text-sm text-slate-600">{assignedService?.name ?? assignment.label} · {[assignment.guide?.name, assignment.driver?.name].filter(Boolean).join(" y ")}</p>
            <p className="text-sm text-slate-600">Registra que el recurso aceptó el servicio por otro medio; lo normal es que lo confirme desde su app.</p>
          </ActionForm>
        );
      case "release":
        if (!assignment) return missing;
        return (
          <ActionForm path={`/ops/assignments/${assignment.id}/release`} ifMatch={assignment.lock_version} redirectTo={close} submitLabel="Liberar recursos" submitClass={danger}
            confirm="¿Liberar los recursos de este servicio?">
            <p className="text-sm text-slate-600">{assignment.label}: la asignación vuelve a Pendiente y se avisa a los recursos liberados.</p>
          </ActionForm>
        );
      case "add_task":
        return (
          <ActionForm path={`${base}/tasks`} redirectTo={`/operaciones/ordenes/${o.code}/tareas`} submitLabel="Agregar tarea">
            <label className="block text-sm"><span className={label}>Tarea</span><input name="title" required maxLength={200} className={inputCls} /></label>
          </ActionForm>
        );
      case "evidence":
        return <EvidenceUpload path={`${base}/evidences`} services={serviceOptions} close={`/operaciones/ordenes/${o.code}/evidencias`} />;
      case "remove_evidence": {
        const e = o.evidences.find((x) => x.id === sp.e);
        if (!e) return missing;
        return (
          <ActionForm path={`/ops/evidences/${e.id}`} method="DELETE" redirectTo={close} submitLabel="Retirar evidencia" submitClass={danger}>
            <p className="text-sm text-slate-600">{e.file_name}: deja de mostrarse, pero se conserva en auditoría.</p>
            <label className="block text-sm"><span className={label}>Motivo</span><input name="reason" maxLength={300} className={inputCls} /></label>
          </ActionForm>
        );
      }
      default:
        return <p className="text-sm text-slate-600">Acción no disponible.</p>;
    }
  })();

  return <Drawer title={titles[action] ?? action} close={close}>{body}</Drawer>;
}
