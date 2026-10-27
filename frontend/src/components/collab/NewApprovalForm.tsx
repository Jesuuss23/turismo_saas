"use client";

import { useState } from "react";
import { ActionForm } from "@/components/crm/ActionForm";
import { Input, TextArea, inputCls } from "@/components/crm/ui";
import type { NewMeta } from "@/components/collab/approvals";

const HINT: Record<string, string> = {
  credit_b2b: "Al aprobar se amplía la línea de la agencia por el monto indicado y se reactiva si estaba bloqueada por consumo. Desde S/ 5,000 pasa también por Finanzas.",
  refund: "Al aprobar se crea un pago de reembolso pendiente para que Finanzas lo procese. Desde S/ 1,000 requiere segundo nivel.",
  other: "Solo registro y evidencia de la excepción aprobada.",
};

/** «Nueva solicitud»: tipos genéricos (crédito B2B, reembolso, otra excepción). */
export function NewApprovalForm({ meta }: { meta: NewMeta }) {
  const [type, setType] = useState("credit_b2b");
  const [agency, setAgency] = useState(meta.agencies[0]?.code ?? "");
  const [reservation, setReservation] = useState("");
  const currency = type === "credit_b2b" ? meta.agencies.find((a) => a.code === agency)?.currency ?? "PEN"
    : type === "refund" ? meta.reservations.find((r) => r.code === reservation)?.currency ?? "PEN" : null;

  return (
    <ActionForm path="/approvals" idempotent submitLabel="Enviar a aprobación" redirectTo="/aprobaciones/{data.code}?tab=sent">
      <label className="block text-sm">
        <span className="mb-1 block font-medium text-slate-700">Tipo</span>
        <select name="type" value={type} onChange={(e) => setType(e.target.value)} className={inputCls}>
          {Object.entries(meta.generic).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
      </label>
      {type === "credit_b2b" && (
        <label className="block text-sm">
          <span className="mb-1 block font-medium text-slate-700">Agencia B2B</span>
          <select name="agency_code" value={agency} onChange={(e) => setAgency(e.target.value)} required className={inputCls}>
            {meta.agencies.map((a) => <option key={a.code} value={a.code}>{a.code} · {a.name} ({a.status}, disponible {a.currency} {a.available})</option>)}
          </select>
        </label>
      )}
      {type === "refund" && (
        <label className="block text-sm">
          <span className="mb-1 block font-medium text-slate-700">Reserva</span>
          <select name="reservation_code" value={reservation} onChange={(e) => setReservation(e.target.value)} required className={inputCls}>
            <option value="">Elija la reserva</option>
            {meta.reservations.map((r) => <option key={r.code} value={r.code}>{r.code} · {r.title}</option>)}
          </select>
        </label>
      )}
      <Input name="title" label="Título" required maxLength={200} />
      <div className="grid grid-cols-2 gap-3">
        <Input key={type} name="amount" label={type === "credit_b2b" ? "Ampliación" : "Monto"} type="number" min={0} step="0.01" required={type !== "other"} defaultValue={type === "other" ? 0 : undefined} />
        {currency ? (
          <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Moneda</span>
            <input name="currency" value={currency} readOnly className={`${inputCls} bg-slate-50`} /></label>
        ) : (
          <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Moneda</span>
            <select name="currency" defaultValue="PEN" className={inputCls}><option value="PEN">PEN</option><option value="USD">USD</option></select></label>
        )}
      </div>
      {type === "credit_b2b" && <Input name="valid_until" label="Vigente hasta (opcional)" type="date" />}
      <TextArea name="justification" label="Justificación" required rows={4} />
      <p className="text-xs text-slate-500">{HINT[type]}</p>
    </ActionForm>
  );
}
