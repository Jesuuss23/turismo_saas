"use client";

import { useActionState, useState } from "react";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnCls, inputCls } from "@/components/crm/ui";

const label = "mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500";
type State = { ok?: boolean; error?: string; info?: string } | undefined;

function ApproveFields({ name }: { name?: string | null }) {
  return (
    <>
      <label className="block text-sm"><span className={label}>Su nombre</span>
        <input name="approved_by_name" required defaultValue={name ?? ""} className={inputCls} /></label>
      <label className="flex items-start gap-2 text-sm text-slate-700">
        <input type="checkbox" name="accept_conditions" value="true" required className="mt-0.5 h-4 w-4" />
        Acepto la propuesta, sus condiciones y las políticas de cancelación indicadas.
      </label>
    </>
  );
}

/** Aprobar o rechazar desde el portal del cliente (sesión iniciada, API /portal/quotes). */
export function PortalRespond({ code, name, back }: { code: string; name: string | null; back: string }) {
  const [mode, setMode] = useState<"approve" | "reject" | null>(null);
  return (
    <section className="rounded-lg border border-sky-200 bg-sky-50 p-4">
      <h2 className="mb-2 font-semibold text-sky-900">¿Qué le parece la propuesta?</h2>
      <div className="mb-3 flex gap-2">
        <button type="button" className={btnCls} onClick={() => setMode("approve")}>Aprobar cotización</button>
        <button type="button" className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" onClick={() => setMode("reject")}>Rechazar</button>
      </div>
      {mode === "approve" && (
        <ActionForm path={`/portal/quotes/${code}/approve`} idempotent redirectTo={back} submitLabel="Confirmar aprobación"><ApproveFields name={name} /></ActionForm>
      )}
      {mode === "reject" && (
        <ActionForm path={`/portal/quotes/${code}/reject`} redirectTo={back} submitLabel="Enviar rechazo">
          <label className="block text-sm"><span className={label}>Motivo</span><textarea name="reason" rows={3} required className={inputCls} /></label>
        </ActionForm>
      )}
    </section>
  );
}

/** Aprobar o rechazar desde el enlace público con token (sin sesión). */
export function PublicRespond({ action }: { action: (kind: "approve" | "reject", state: State, form: FormData) => Promise<State> }) {
  const [mode, setMode] = useState<"approve" | "reject" | null>(null);
  const [idem] = useState(() => crypto.randomUUID());
  const [approveState, approve, approving] = useActionState(action.bind(null, "approve"), undefined);
  const [rejectState, reject, rejecting] = useActionState(action.bind(null, "reject"), undefined);
  const state = mode === "approve" ? approveState : rejectState;
  if (state?.ok) return <p className="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200" role="status">{state.info}</p>;
  return (
    <section className="rounded-lg border border-sky-200 bg-sky-50 p-4 print:hidden">
      <h2 className="mb-2 font-semibold text-sky-900">¿Qué le parece la propuesta?</h2>
      <div className="mb-3 flex gap-2">
        <button type="button" className={btnCls} onClick={() => setMode("approve")}>Aprobar cotización</button>
        <button type="button" className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" onClick={() => setMode("reject")}>Rechazar</button>
      </div>
      {mode === "approve" && (
        <form action={approve} className="space-y-3">
          <input type="hidden" name="_idem" value={idem} />
          <ApproveFields />
          <button className={btnCls} disabled={approving}>{approving ? "Procesando…" : "Confirmar aprobación"}</button>
        </form>
      )}
      {mode === "reject" && (
        <form action={reject} className="space-y-3">
          <input type="hidden" name="_idem" value={idem} />
          <label className="block text-sm"><span className={label}>Motivo</span><textarea name="reason" rows={3} required className={inputCls} /></label>
          <button className={btnCls} disabled={rejecting}>{rejecting ? "Procesando…" : "Enviar rechazo"}</button>
        </form>
      )}
      {state?.error && <p className="mt-2 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200" role="alert">{state.error}</p>}
    </section>
  );
}
