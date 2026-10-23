"use client";

import { useState } from "react";
import { ActionForm } from "@/components/crm/ActionForm";
import { Input, Select, TextArea, btnGhostCls } from "@/components/crm/ui";
import { STAGES, type Opportunity } from "@/lib/crm";

/** "Mover a", ganar, perder, reabrir, editar y reasignar (§4.2, §7.3). */
export function StageActions({ opp, lostReasons, advisors }: { opp: Opportunity; lostReasons: { value: string; label: string }[]; advisors: { value: string; label: string }[] | null }) {
  const [panel, setPanel] = useState<string | null>(null);
  const toggle = (p: string) => setPanel(panel === p ? null : p);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        {!opp.is_closed && STAGES.filter((s) => !["ganada", "perdida", opp.stage].includes(s.code)).map((s) => (
          <ActionForm key={s.code} inline path={`/crm/opportunities/${opp.code}/stage`} ifMatch={opp.lock_version} submitLabel={`Mover a ${s.name}`} submitClass={btnGhostCls}>
            <input type="hidden" name="stage" value={s.code} />
          </ActionForm>
        ))}
        {opp.stage !== "ganada" && (
          <ActionForm inline path={`/crm/opportunities/${opp.code}/stage`} ifMatch={opp.lock_version} submitLabel="Marcar como ganada" confirm="¿Marcar la oportunidad como ganada?"
            submitClass="rounded-md bg-green-700 px-3 py-2 text-sm font-medium text-white hover:bg-green-800">
            <input type="hidden" name="stage" value="ganada" />
            {opp.is_closed && <input type="hidden" name="confirm_reopen" value="true" />}
          </ActionForm>
        )}
        {opp.stage !== "perdida" && <button className="rounded-md border border-rose-300 px-3 py-2 text-sm font-medium text-rose-700" onClick={() => toggle("lost")}>Marcar como perdida</button>}
        {opp.is_closed && opp.reopen_stage && (
          <ActionForm inline path={`/crm/opportunities/${opp.code}/stage`} ifMatch={opp.lock_version} submitLabel="Reabrir oportunidad" submitClass={btnGhostCls}
            confirm={`¿Reabrir la oportunidad en ${STAGES.find((s) => s.code === opp.reopen_stage)?.name}?`}>
            <input type="hidden" name="stage" value={opp.reopen_stage} />
            <input type="hidden" name="confirm_reopen" value="true" />
          </ActionForm>
        )}
        <button className={btnGhostCls} onClick={() => toggle("edit")}>Editar</button>
        {advisors && <button className={btnGhostCls} onClick={() => toggle("reassign")}>Reasignar</button>}
      </div>

      {panel === "lost" && (
        <ActionForm path={`/crm/opportunities/${opp.code}/stage`} ifMatch={opp.lock_version} submitLabel="Confirmar pérdida" submitClass="rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white">
          <input type="hidden" name="stage" value="perdida" />
          <Select name="lost_reason_code" label="Motivo *" options={lostReasons} required placeholder="Seleccione" />
          <TextArea name="lost_detail" label="Detalle (obligatorio para “Otro”)" />
        </ActionForm>
      )}
      {panel === "edit" && (
        <ActionForm path={`/crm/opportunities/${opp.code}`} method="PATCH" ifMatch={opp.lock_version} submitLabel="Guardar cambios" success="Oportunidad actualizada">
          <div className="grid gap-2 sm:grid-cols-3">
            <div className="sm:col-span-3"><Input name="title" label="Título" defaultValue={opp.title} required /></div>
            <Input name="amount" type="number" min={1} step="0.01" label="Monto" defaultValue={opp.amount} />
            <Select name="currency" label="Moneda" options={[{ value: "PEN", label: "PEN" }, { value: "USD", label: "USD" }]} defaultValue={opp.currency} />
            <Input name="probability" type="number" min={0} max={100} label="Probabilidad %" defaultValue={opp.probability} />
            <Input name="expected_close_date" type="date" label="Cierre esperado" defaultValue={opp.expected_close_date} />
            <Input name="destination_label" label="Destino" defaultValue={opp.destination_label ?? ""} />
            <Input name="pax" type="number" min={0} label="Pasajeros" defaultValue={opp.pax} />
          </div>
        </ActionForm>
      )}
      {panel === "reassign" && advisors && (
        <ActionForm path={`/crm/opportunities/${opp.code}/reassign`} ifMatch={opp.lock_version} submitLabel="Reasignar" success="Reasignada">
          <Select name="owner_id" label="Nuevo responsable *" options={advisors.filter((a) => a.value !== opp.owner?.id)} required placeholder="Seleccione" />
          <Input name="note" label="Motivo" />
        </ActionForm>
      )}
    </div>
  );
}
