import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { price } from "@/lib/catalog";
import type { PenaltyLine, ResMeta, Reservation } from "@/lib/reservations";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnGhostCls, Drawer, inputCls, Notice, Table, Td } from "@/components/crm/ui";
import { Check, Hidden } from "@/components/catalog/ui";
import type { DirectProduct } from "./DirectForm";

const label = "mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500";
const danger = "rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800 disabled:opacity-50";

type ChangePreview = { type: string; suggested_delta: string; suggested_cost?: string; rate?: string | null; currency: string; pax?: number;
  availability: { ok: boolean; message: string; alternatives?: { ref: string; date: string; available: number }[] } };
type ReschedulePreview = { new_start: string; new_end: string; shift_days: number; diff: string; currency: string; blockers: string[]; can_apply: boolean;
  lines: { code: string; name: string; old_date: string; new_date: string; pax: number | null; factor_old: string; factor_new: string; price: string; diff: string; ok: boolean; message: string }[] };
type CancelPreview = { lines: PenaltyLine[]; penalty_services: string; consumed: string; k: string; penalty: string; refund: string; owed: string; currency: string;
  total: string; paid: string; effects: string[]; pending_request: { reason: string } | null };

async function post<T>(path: string, body: unknown = {}): Promise<{ data?: T; error?: string }> {
  try {
    return { data: (await api<{ data: T }>(path, { method: "POST", body: JSON.stringify(body) })).data };
  } catch (e) {
    return { error: (e as { body?: { message?: string } }).body?.message ?? "No se pudo calcular" };
  }
}

/** Diálogos de la Reserva 360 (`?accion=`). Las previsualizaciones se calculan en el servidor; el backend revalida todo al aplicar. */
export async function ReservationDialog({ action, r, meta, close, sp }: { action: string; r: Reservation; meta: ResMeta; close: string; sp: Record<string, string | undefined> }) {
  const base = `/reservations/${r.code}`;
  const titles: Record<string, string> = {
    passenger: sp.p ? "Pasajero" : "Agregar pasajero", service_confirm: "Confirmar servicio del proveedor", service_reject: "Registrar rechazo del proveedor",
    approve_change: "Aprobar modificación", reject_change: "Rechazar modificación", reject_cancel_request: "Rechazar solicitud de anulación",
  };
  const title = titles[action] ?? r.actions.find((a) => a.key === action)?.label ?? action;
  const service = sp.s ? r.services.find((s) => s.code === sp.s) : undefined;

  const body = await (async () => {
    switch (action) {
      case "confirm":
        return (
          <ActionForm path={`${base}/confirm`} ifMatch={r.lock_version} redirectTo={close} submitLabel="Confirmar reserva">
            <p className="text-sm text-slate-600">Verifica la disponibilidad de todos los servicios requeridos, retiene cupos de las salidas y crea las CxC si faltan (RES-BR-07).</p>
          </ActionForm>
        );
      case "start_operation":
      case "complete":
        return (
          <ActionForm path={`${base}/${action === "complete" ? "complete" : "start-operation"}`} ifMatch={r.lock_version} redirectTo={close} submitLabel={title}>
            <p className="text-sm text-slate-600">{action === "complete" ? "Marca el viaje como completado y los servicios activos como ejecutados." : "La reserva pasa a En operación (RES-BR-08)."}</p>
          </ActionForm>
        );
      case "request_pending":
        return (
          <ActionForm path={`${base}/services/request-pending`} redirectTo={close} submitLabel="Solicitar a proveedores">
            <p className="text-sm text-slate-600">Envía la solicitud por correo a los proveedores activos de los servicios pendientes; los de proveedor no activo se omiten (RES-BR-11).</p>
          </ActionForm>
        );
      case "service_confirm":
        if (!service) return <p className="text-sm">Servicio no encontrado.</p>;
        return (
          <ActionForm path={`${base}/services/${service.code}/confirm`} redirectTo={close} submitLabel="Confirmar servicio">
            <p className="text-sm text-slate-600">{service.code} · {service.name} · {date(service.service_date)} · {service.provider?.name}</p>
            <label className="block text-sm"><span className={label}>Código de confirmación</span><input name="confirmation_code" required className={inputCls} /></label>
            <label className="block text-sm"><span className={label}>Hora</span><input name="time" type="time" defaultValue={service.service_time ?? ""} className={inputCls} /></label>
            <label className="block text-sm"><span className={label}>Confirmado por</span><input name="confirmed_by" className={inputCls} /></label>
          </ActionForm>
        );
      case "service_reject": {
        if (!service) return <p className="text-sm">Servicio no encontrado.</p>;
        const sameType = meta.providers.filter((p) => p.type === service.provider?.type && p.id !== service.provider?.id);
        return (
          <ActionForm path={`${base}/services/${service.code}/reject`} redirectTo={close} submitLabel="Registrar rechazo" submitClass={danger}>
            <p className="text-sm text-slate-600">{service.code} · {service.name} · {service.provider?.name}</p>
            <label className="block text-sm"><span className={label}>Motivo</span>
              <select name="reason" required className={inputCls}>{meta.reject_reasons.map((m) => <option key={m}>{m}</option>)}</select></label>
            <label className="block text-sm"><span className={label}>Detalle</span><textarea name="detail" rows={2} required className={inputCls} /></label>
            <label className="block text-sm"><span className={label}>Alternativa (opcional)</span>
              <select name="alternative_provider_id" className={inputCls} defaultValue="">
                <option value="">Sin alternativa</option>
                <option value="same">Mismo proveedor, otra fecha</option>
                {sameType.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.type})</option>)}
              </select></label>
            <label className="block text-sm"><span className={label}>Fecha de la alternativa</span><input name="alternative_date" type="date" min={meta.today} defaultValue={service.service_date} className={inputCls} /></label>
            {r.status === "confirmada" && <Notice tone="warning">La reserva pasará a Pendiente de proveedor (RES-BR-13).</Notice>}
          </ActionForm>
        );
      }
      case "passenger": {
        const p = sp.p ? r.passengers.find((x) => x.id === sp.p) : undefined;
        return (
          <ActionForm path={`${base}/passengers${p ? `/${p.id}` : ""}`} method={p ? "PUT" : "POST"} ifMatch={r.lock_version} redirectTo={close} submitLabel="Guardar pasajero">
            <label className="block text-sm"><span className={label}>Nombre completo</span><input name="full_name" required defaultValue={p && !p.is_placeholder ? p.full_name : ""} className={inputCls} /></label>
            <div className="grid grid-cols-2 gap-2">
              <label className="block text-sm"><span className={label}>Documento</span>
                <select name="doc_type" defaultValue={p?.doc_type ?? "DNI"} className={inputCls}>{["DNI", "Pasaporte", "CE"].map((d) => <option key={d}>{d}</option>)}</select></label>
              <label className="block text-sm"><span className={label}>Número</span><input name="doc_number" required defaultValue={p?.doc_number ?? ""} className={inputCls} /></label>
              <label className="block text-sm"><span className={label}>Nacionalidad</span><input name="nationality" required defaultValue={p?.nationality ?? ""} className={inputCls} /></label>
              <label className="block text-sm"><span className={label}>Fecha de nacimiento</span><input name="birth_date" type="date" required defaultValue={p?.birth_date ?? ""} className={inputCls} /></label>
              <label className="block text-sm"><span className={label}>Tipo</span>
                <select name="pax_type" defaultValue={p?.pax_type ?? ""} className={inputCls}><option value="">Según edad</option>{["ADT", "CHD", "INF"].map((t) => <option key={t}>{t}</option>)}</select></label>
              <label className="block text-sm"><span className={label}>Parentesco</span>
                <select name="relationship" defaultValue={p?.relationship ?? ""} className={inputCls}><option value="">—</option>
                  {["Titular", "Cónyuge / pareja", "Hijo/a", "Padre / madre", "Hermano/a", "Amigo/a", "Otro"].map((t) => <option key={t}>{t}</option>)}</select></label>
              <label className="block text-sm"><span className={label}>Teléfono</span><input name="phone" defaultValue={p?.phone ?? ""} className={inputCls} /></label>
              <label className="block text-sm"><span className={label}>Correo</span><input name="email" type="email" defaultValue={p?.email ?? ""} className={inputCls} /></label>
            </div>
            <label className="block text-sm"><span className={label}>Dieta / requerimientos</span><input name="diet_notes" defaultValue={p?.diet_notes ?? ""} className={inputCls} /></label>
            <Check name="is_lead" label="Titular de la reserva (uno solo)" defaultChecked={p?.is_lead} />
          </ActionForm>
        );
      }
      case "register_payment": {
        const open = (r.receivables ?? []).filter((c) => c.open);
        const selected = open.find((c) => c.id === sp.cxc) ?? open[0];
        if (!selected) return <p className="text-sm text-slate-600">No hay CxC abiertas.</p>;
        return (
          <ActionForm path="/finance/payments" idempotent redirectTo={close} submitLabel="Registrar pago aplicado">
            <label className="block text-sm"><span className={label}>Cuenta por cobrar</span>
              <select name="receivable_id" defaultValue={selected.id} className={inputCls}>
                {open.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.concept} · saldo {c.currency} {c.balance}</option>)}
              </select></label>
            <label className="block text-sm"><span className={label}>Monto</span><input name="amount" type="number" step="0.01" min="0.01" max={selected.balance} required defaultValue={selected.balance} className={inputCls} /></label>
            <label className="block text-sm"><span className={label}>Medio de pago</span>
              <select name="method" className={inputCls}>{meta.payment_methods.map((m) => <option key={m}>{m}</option>)}</select></label>
            <label className="block text-sm"><span className={label}>Fecha</span><input name="paid_on" type="date" max={meta.today} defaultValue={meta.today} className={inputCls} /></label>
            <label className="block text-sm"><span className={label}>Referencia / operación</span><input name="reference" className={inputCls} /></label>
            <p className="text-xs text-slate-500">Se registra como aplicado (atajo PROC-042, RES-BR-40).</p>
          </ActionForm>
        );
      }
      case "generate_receivables":
        return (
          <ActionForm path={`${base}/receivables/generate`} idempotent redirectTo={close} submitLabel="Generar CxC">
            <p className="text-sm text-slate-600">Crea el anticipo 40 % (vence en ≤ 3 días) y el saldo 60 % (15 días antes del viaje) por {price(r.total, r.currency)} (RES-BR-41).</p>
          </ActionForm>
        );
      case "reassign":
        return (
          <ActionForm path={`${base}/reassign`} ifMatch={r.lock_version} redirectTo={close} submitLabel="Reasignar">
            <label className="block text-sm"><span className={label}>Nuevo asesor</span>
              <select name="owner_id" required className={inputCls} defaultValue="">
                <option value="" disabled>Seleccione…</option>
                {meta.owners.filter((o) => o.id !== r.owner?.id).map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
              </select></label>
            <label className="block text-sm"><span className={label}>Nota</span><input name="note" className={inputCls} /></label>
          </ActionForm>
        );
      case "approve_change":
      case "reject_change": {
        const c = r.changes.find((x) => x.id === sp.c);
        if (!c) return <p className="text-sm">Modificación no encontrada.</p>;
        return (
          <ActionForm path={`${base}/changes/${c.id}/${action === "approve_change" ? "approve" : "reject"}`} ifMatch={r.lock_version} redirectTo={close}
            submitLabel={action === "approve_change" ? "Aprobar y aplicar" : "Rechazar"} submitClass={action === "reject_change" ? danger : undefined}>
            <p className="text-sm text-slate-600">{c.type_label}: {c.description}{c.delta_amount !== undefined && <> · impacto {price(c.delta_amount, r.currency)}</>} · solicitada por {c.requested_by?.name}</p>
            {action === "reject_change" && <label className="block text-sm"><span className={label}>Motivo</span><textarea name="note" rows={2} className={inputCls} /></label>}
          </ActionForm>
        );
      }
      case "modify":
        return <ModifyDialog r={r} sp={sp} close={close} />;
      case "reschedule": {
        const res = await post<ReschedulePreview>(`${base}/reschedule/preview`, sp.new_start ? { new_start: sp.new_start } : {});
        const p = res.data;
        return (
          <div className="space-y-3">
            <form className="flex items-end gap-2">
              <input type="hidden" name="accion" value="reschedule" />
              <label className="block flex-1 text-sm"><span className={label}>Nueva fecha de inicio</span>
                <input name="new_start" type="date" min={meta.today} defaultValue={p?.new_start ?? sp.new_start} className={inputCls} /></label>
              <button className={btnGhostCls}>Calcular</button>
            </form>
            {res.error && <Notice tone="danger">{res.error}</Notice>}
            {p && <>
              <p className="text-sm">Nuevas fechas: <strong>{date(p.new_start)} – {date(p.new_end)}</strong> ({p.shift_days > 0 ? "+" : ""}{p.shift_days} días)</p>
              <Table head={["Servicio", "Fecha", "Nueva", "Temporada", ...(r.view.ops_view ? [] : ["Dif."]), "Disponibilidad"]}>
                {p.lines.map((l) => (
                  <tr key={l.code}><Td>{l.code} {l.name}</Td><Td>{date(l.old_date)}</Td><Td>{date(l.new_date)}</Td><Td>×{l.factor_old} → ×{l.factor_new}</Td>
                    {!r.view.ops_view && <Td>{price(l.diff, r.currency)}</Td>}<Td className={l.ok ? "text-emerald-700" : "text-rose-700"}>{l.message}</Td></tr>
                ))}
              </Table>
              {!r.view.ops_view && <p className="text-sm">Diferencia tarifaria: <strong>{price(p.diff, r.currency)}</strong>{Number(p.diff) > 0 ? " (genera CxC)" : Number(p.diff) < 0 ? " (no genera saldo a favor, §12-5)" : ""}</p>}
              {p.blockers.map((b) => <Notice key={b} tone="danger">{b}</Notice>)}
              <ActionForm path={`${base}/reschedule`} ifMatch={r.lock_version} idempotent redirectTo={close} submitLabel="Aplicar reprogramación" disabled={!p.can_apply} disabledReason={p.blockers[0]}>
                <Hidden name="new_start" value={p.new_start} />
                <label className="block text-sm"><span className={label}>Motivo</span><textarea name="reason" rows={2} required className={inputCls} /></label>
                <p className="text-xs text-slate-500">Libera y retiene cupos, reinicia los servicios a pendiente (sin código) y deja la reserva Reprogramada (RES-BR-23).</p>
              </ActionForm>
            </>}
          </div>
        );
      }
      case "cancel": {
        const res = await post<CancelPreview>(`${base}/cancellation/preview`);
        const p = res.data;
        if (!p) return <Notice tone="danger">{res.error}</Notice>;
        const [reason, ...rest] = (r.cancel_request?.reason ?? "").split(": ");
        return (
          <div className="space-y-3">
            {r.cancel_request && <Notice tone="warning">Solicitud de {r.cancel_request.requested_by?.name}: {r.cancel_request.reason}</Notice>}
            <Table head={["Servicio", "Días", "%", "Precio", "Penalidad"]}>
              {p.lines.map((l) => <tr key={l.code}><Td>{l.code} {l.name}<span className="block text-xs text-slate-500">{l.policy ?? "Política estándar"}</span></Td><Td>{l.days}</Td><Td>{l.pct} %</Td><Td>{price(l.price, p.currency)}</Td><Td>{price(l.penalty, p.currency)}</Td></tr>)}
            </Table>
            <dl className="grid grid-cols-2 gap-1 text-sm" data-testid="cancel-summary">
              <dt className="text-slate-500">Total de la reserva</dt><dd className="text-right">{price(p.total, p.currency)}</dd>
              <dt className="text-slate-500">Cobrado</dt><dd className="text-right">{price(p.paid, p.currency)}</dd>
              <dt className="text-slate-500">Factor k (IGV)</dt><dd className="text-right">{p.k}</dd>
              <dt className="font-medium">Penalidad</dt><dd className="text-right font-semibold">{price(p.penalty, p.currency)}</dd>
              <dt className="text-slate-500">Reembolso</dt><dd className="text-right">{price(p.refund, p.currency)}</dd>
              <dt className="text-slate-500">Penalidad por cobrar</dt><dd className="text-right">{price(p.owed, p.currency)}</dd>
            </dl>
            <ul className="list-disc pl-5 text-xs text-slate-600">{p.effects.map((e) => <li key={e}>{e}</li>)}</ul>
            <ActionForm path={`${base}/cancel`} ifMatch={r.lock_version} idempotent redirectTo={close} submitLabel="Cancelar reserva" submitClass={danger}
              confirm="La cancelación no se puede deshacer. ¿Continuar?">
              <label className="block text-sm"><span className={label}>Motivo</span>
                <select name="reason" required defaultValue={r.cancel_request ? reason : ""} className={inputCls}>
                  <option value="" disabled>Seleccione…</option>{meta.cancel_reasons.map((m) => <option key={m}>{m}</option>)}</select></label>
              <label className="block text-sm"><span className={label}>Detalle</span><textarea name="detail" rows={2} required defaultValue={rest.join(": ")} className={inputCls} /></label>
            </ActionForm>
          </div>
        );
      }
      case "request_cancel":
        return (
          <ActionForm path={`${base}/cancel-requests`} redirectTo={close} submitLabel="Enviar solicitud a Gerencia">
            <p className="text-sm text-slate-600">La reserva sigue vigente hasta que Gerencia procese la cancelación (RES-BR-33).</p>
            <label className="block text-sm"><span className={label}>Motivo</span>
              <select name="reason" required className={inputCls}>{meta.cancel_reasons.map((m) => <option key={m}>{m}</option>)}</select></label>
            <label className="block text-sm"><span className={label}>Detalle</span><textarea name="detail" rows={3} required className={inputCls} /></label>
          </ActionForm>
        );
      case "reject_cancel_request":
        if (!r.cancel_request) return <p className="text-sm">No hay solicitud pendiente.</p>;
        return (
          <ActionForm path={`${base}/cancel-requests/${r.cancel_request.id}/reject`} redirectTo={close} submitLabel="Rechazar solicitud" submitClass={danger}>
            <p className="text-sm text-slate-600">{r.cancel_request.requested_by?.name}: {r.cancel_request.reason}</p>
            <label className="block text-sm"><span className={label}>Motivo del rechazo</span><textarea name="note" rows={2} required className={inputCls} /></label>
          </ActionForm>
        );
      default:
        return <p className="text-sm text-slate-600">Acción no disponible.</p>;
    }
  })();

  return <Drawer title={title} close={close}>{body}</Drawer>;
}

async function ModifyDialog({ r, sp, close }: { r: Reservation; sp: Record<string, string | undefined>; close: string }) {
  const base = `/reservations/${r.code}`;
  const type = sp.type ?? "pax";
  const products = type === "servicio" ? (await api<{ data: DirectProduct[] }>("/reservations/products").catch(() => ({ data: [] as DirectProduct[] }))).data : [];
  const asked = type === "pax" ? { type, extra_pax: Number(sp.extra_pax ?? 1) } : type === "servicio" ? (sp.product_id && sp.service_date ? { type, product_id: sp.product_id, service_date: sp.service_date } : null) : { type };
  const res = asked ? await post<ChangePreview>(`${base}/changes/preview`, asked) : {};
  const p = res.data;
  const ops = r.view.ops_view;
  return (
    <div className="space-y-3">
      <form className="space-y-2">
        <input type="hidden" name="accion" value="modify" />
        <label className="block text-sm"><span className={label}>Tipo</span>
          <select name="type" defaultValue={type} className={inputCls}>
            <option value="pax">Agregar pasajeros</option><option value="servicio">Servicio adicional</option><option value="upgrade">Upgrade</option><option value="otro">Otro ajuste</option>
          </select></label>
        {type === "pax" && <label className="block text-sm"><span className={label}>Pasajeros adicionales</span><input name="extra_pax" type="number" min={1} defaultValue={sp.extra_pax ?? 1} className={inputCls} /></label>}
        {type === "servicio" && <>
          <label className="block text-sm"><span className={label}>Producto</span>
            <select name="product_id" defaultValue={sp.product_id ?? ""} className={inputCls}><option value="">Seleccione…</option>
              {products.map((x) => <option key={x.id} value={x.id} disabled={!!x.blocked_reason}>{x.ref} · {x.name}</option>)}</select></label>
          <label className="block text-sm"><span className={label}>Fecha</span><input name="service_date" type="date" defaultValue={sp.service_date ?? r.travel_from} className={inputCls} /></label>
        </>}
        <button className={btnGhostCls}>Calcular impacto</button>
      </form>
      {res.error && <Notice tone="danger">{res.error}</Notice>}
      {p && <>
        <Notice tone={p.availability.ok ? "info" : "danger"}>{p.availability.message}</Notice>
        <ActionForm path={`${base}/changes`} ifMatch={r.lock_version} redirectTo={close} submitLabel={r.can.approve_changes ? "Registrar" : "Solicitar aprobación"} disabled={!p.availability.ok} disabledReason="RT-4: sin disponibilidad">
          <Hidden name="type" value={type} />
          {type === "pax" && <Hidden name="extra_pax" value={sp.extra_pax ?? 1} />}
          {type === "servicio" && <><Hidden name="product_id" value={sp.product_id} /><Hidden name="service_date" value={sp.service_date} /></>}
          <label className="block text-sm"><span className={label}>Impacto en {p.currency} (+ cargo, − saldo a favor){!ops && ` · sugerido ${p.suggested_delta}`}</span>
            <input name="delta_amount" type="number" step="0.01" defaultValue={ops ? undefined : p.suggested_delta} placeholder={ops ? "Lo define el supervisor" : undefined} className={inputCls} /></label>
          <label className="block text-sm"><span className={label}>Descripción</span><textarea name="description" rows={2} required className={inputCls} /></label>
          {r.can.approve_changes ? <Check name="apply_now" label="Aprobar como supervisor y aplicar ahora" defaultChecked /> : <p className="text-xs text-slate-500">Quedará pendiente de aprobación de un supervisor (RES-BR-14).</p>}
        </ActionForm>
      </>}
      <p className="text-xs text-slate-500"><Link href={close} className="underline">Cancelar</Link></p>
    </div>
  );
}
