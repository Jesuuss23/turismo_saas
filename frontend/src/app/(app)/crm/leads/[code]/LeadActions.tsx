"use client";

import { useState } from "react";
import { ActionForm } from "@/components/crm/ActionForm";
import { Input, Select, TextArea, btnGhostCls } from "@/components/crm/ui";
import type { Lead } from "@/lib/crm";

type Opt = { value: string; label: string };
type Perms = { manage: boolean; take: boolean; assign: boolean; convert: boolean; activity: boolean };

const ghost = btnGhostCls;
const danger = "rounded-md border border-rose-300 bg-white px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50 disabled:opacity-50";

/** Panel de acciones de la ficha (§7.3): visibles por estado, deshabilitadas con motivo si falta permiso o propiedad. */
export function LeadActions({ lead, perms, discardReasons, advisors, clients, bant, today }: {
  lead: Lead; perms: Perms; discardReasons: Opt[]; advisors: Opt[] | null; clients: Opt[];
  bant: { labels: Record<string, string[]>; levels: number[] }; today: string;
}) {
  const open = ["nuevo", "contactado", "calificado"].includes(lead.status);
  const reason = !perms.manage ? (lead.owner ? "Requiere permiso crm.leads.manage o ser el responsable" : "Tome el lead para gestionarlo") : undefined;
  const [panel, setPanel] = useState<string | null>(null);
  const toggle = (p: string) => setPanel(panel === p ? null : p);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        {perms.take && (
          <ActionForm inline path={`/crm/leads/${lead.code}/take`} submitLabel="Tomar lead" success="Lead tomado: se programó el primer contacto" />
        )}
        {open && <button className={ghost} disabled={!perms.manage} title={reason} onClick={() => toggle("status")}>Cambiar estado</button>}
        {open && <button className={ghost} disabled={!perms.manage} title={reason} onClick={() => toggle("bant")}>Calificar (BANT)</button>}
        {lead.status === "calificado" && <button className={ghost} disabled={!perms.convert} title={perms.convert ? undefined : "Requiere crm.leads.manage y crm.opportunities.manage"} onClick={() => toggle("convert")}>Convertir</button>}
        {perms.assign && <button className={ghost} onClick={() => toggle("assign")}>{lead.owner ? "Reasignar responsable" : "Asignar responsable"}</button>}
        {open && <button className={danger} disabled={!perms.manage} title={reason} onClick={() => toggle("discard")}>Descartar</button>}
        {["descartado", "duplicado"].includes(lead.status) && (
          <ActionForm inline path={`/crm/leads/${lead.code}/reopen`} ifMatch={lead.lock_version} submitLabel="Reabrir lead" submitClass={ghost} disabled={!perms.manage} disabledReason={reason} confirm="¿Reabrir el lead como nuevo?" />
        )}
      </div>

      {panel === "status" && (
        <ActionForm path={`/crm/leads/${lead.code}/status`} ifMatch={lead.lock_version} submitLabel="Guardar estado" success="Estado actualizado">
          <Select name="status" label="Nuevo estado" defaultValue={lead.status} options={[
            { value: "nuevo", label: "Nuevo" }, { value: "contactado", label: "Contactado" }, { value: "calificado", label: "Calificado (score ≥ 50)" },
          ]} />
          <Input name="note" label="Comentario" />
        </ActionForm>
      )}

      {panel === "bant" && (
        <ActionForm path={`/crm/leads/${lead.code}/qualify`} ifMatch={lead.lock_version} submitLabel="Guardar calificación" success="Calificación registrada">
          <div className="grid gap-2 sm:grid-cols-2">
            {(["budget", "authority", "need", "timeline"] as const).map((dim) => (
              <Select key={dim} name={dim} label={{ budget: "Presupuesto", authority: "Autoridad", need: "Necesidad", timeline: "Plazo" }[dim]}
                options={bant.levels.map((lvl, i) => ({ value: String(lvl), label: `${bant.labels[dim][i]} (${lvl})` }))} defaultValue="0" />
            ))}
            <Input name="declared_amount" type="number" min={0} step="0.01" label="Monto declarado" />
            <Select name="currency" label="Moneda" options={[{ value: "PEN", label: "PEN" }, { value: "USD", label: "USD" }]} placeholder="—" />
          </div>
          <TextArea name="notes" label="Notas" />
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="mark_qualified" value="true" defaultChecked /> Marcar como calificado si el score llega a 50</label>
        </ActionForm>
      )}

      {panel === "discard" && (
        <ActionForm path={`/crm/leads/${lead.code}/discard`} ifMatch={lead.lock_version} submitLabel="Descartar lead" submitClass={danger} success="Lead descartado">
          <Select name="reason_code" label="Motivo *" options={discardReasons} required placeholder="Seleccione" />
          <TextArea name="detail" label="Detalle" />
          <p className="text-xs text-slate-500">El lead no se elimina; sus actividades pendientes se cerrarán automáticamente.</p>
        </ActionForm>
      )}

      {panel === "assign" && advisors && (
        <ActionForm path={`/crm/leads/${lead.code}/assign`} ifMatch={lead.lock_version} submitLabel="Asignar" success="Responsable actualizado">
          <Select name="owner_id" label="Asesor *" options={advisors.filter((a) => a.value !== lead.owner?.id)} required placeholder="Seleccione" />
          <Input name="note" label="Motivo (opcional)" />
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="schedule_first_contact" value="true" defaultChecked={!lead.owner} /> Programar primer contacto (+2 h)</label>
        </ActionForm>
      )}

      {panel === "convert" && <ConvertForm lead={lead} clients={clients} today={today} />}
    </div>
  );
}

function ConvertForm({ lead, clients, today }: { lead: Lead; clients: Opt[]; today: string }) {
  const [mode, setMode] = useState<"new" | "existing">("new");
  const [kind, setKind] = useState(lead.company ? "empresa" : "persona");
  const close = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);

  return (
    <ActionForm path={`/crm/leads/${lead.code}/convert`} idempotent submitLabel="Convertir lead" redirectTo="/crm/oportunidades/{data.opportunity.code}">
      <fieldset className="flex gap-4 text-sm">
        <label className="flex items-center gap-2"><input type="radio" name="client_mode" value="new" checked={mode === "new"} onChange={() => setMode("new")} /> Cliente nuevo</label>
        <label className="flex items-center gap-2"><input type="radio" name="client_mode" value="existing" checked={mode === "existing"} onChange={() => setMode("existing")} /> Vincular a cliente existente</label>
      </fieldset>
      {mode === "existing" ? (
        <Select name="client_id" label="Cliente *" options={clients} required placeholder="Seleccione" />
      ) : (
        <div className="grid gap-2 sm:grid-cols-3">
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-slate-700">Tipo *</span>
            <select name="client.kind" value={kind} onChange={(e) => setKind(e.target.value)} className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
              <option value="persona">Persona</option><option value="empresa">Empresa</option>
            </select>
          </label>
          <Input name="client.name" label={kind === "empresa" ? "Razón social" : "Nombre"} defaultValue={kind === "empresa" ? lead.company ?? "" : lead.full_name} />
          <Select name="client.doc_type" label="Documento *" defaultValue={kind === "empresa" ? "RUC" : "DNI"} options={["DNI", "RUC", "PASAPORTE", "CE"].map((d) => ({ value: d, label: d }))} />
          <Input name="client.doc_number" label="Número *" required maxLength={20} />
          <Input name="client.city" label="Ciudad" />
          <Input name="client.country" label="País" defaultValue="Perú" />
        </div>
      )}
      <div className="grid gap-2 sm:grid-cols-3">
        <div className="sm:col-span-3"><Input name="opportunity.title" label="Título de la oportunidad *" required defaultValue={`${lead.interest.label} — ${lead.destination_label ?? lead.full_name}`} /></div>
        <Select name="opportunity.stage" label="Etapa inicial" defaultValue="descubrimiento" options={[{ value: "calificacion", label: "Calificación" }, { value: "descubrimiento", label: "Descubrimiento" }]} />
        <Input name="opportunity.amount" type="number" min={1} step="0.01" label="Monto *" required />
        <Select name="opportunity.currency" label="Moneda *" defaultValue="PEN" options={[{ value: "PEN", label: "PEN" }, { value: "USD", label: "USD" }]} />
        <Input name="opportunity.expected_close_date" type="date" min={today} defaultValue={close} label="Cierre esperado *" required />
      </div>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="schedule_discovery_meeting" value="true" defaultChecked /> Programar reunión de descubrimiento</label>
    </ActionForm>
  );
}
