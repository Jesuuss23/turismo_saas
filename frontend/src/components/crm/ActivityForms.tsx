"use client";

import { ActionForm } from "./ActionForm";
import { Input, Select, TextArea } from "./ui";

const TYPES = [
  { value: "llamada", label: "Llamada" }, { value: "email", label: "Email" }, { value: "whatsapp", label: "WhatsApp" },
  { value: "reunion", label: "Reunión" }, { value: "tarea", label: "Tarea" }, { value: "postventa", label: "Postventa" },
];

/** Zona del tenant: el backend interpreta las fechas sin zona en la hora de la agencia. */
const TZ = "America/Lima";

/** "YYYY-MM-DDTHH:mm" en la zona del tenant, `days` días desde hoy a las `hour` horas (o dentro de `hour` horas si `relative`). */
function tenantAt(days: number, hour: number, relative = false) {
  const now = new Date();
  const base = relative ? new Date(now.getTime() + hour * 3_600_000) : now;
  const parts = Object.fromEntries(new Intl.DateTimeFormat("en-CA", { timeZone: TZ, year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", hourCycle: "h23" })
    .formatToParts(base).map((p) => [p.type, p.value]));
  const day = new Date(Date.UTC(+parts.year, +parts.month - 1, +parts.day + days)).toISOString().slice(0, 10);
  return `${day}T${String(relative ? +parts.hour : hour).padStart(2, "0")}:00`;
}

/** Fecha y hora en la zona de la agencia; se envía sin zona y el backend la interpreta en la zona del tenant. */
function DateTimeField({ name, label, defaultValue }: { name: string; label: string; defaultValue: string }) {
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label} <span className="font-normal text-slate-500">(hora de Lima)</span></span>
      <input type="datetime-local" name={name} required defaultValue={defaultValue} className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
    </label>
  );
}

export function NewActivityForm({ relatedType, relatedId, owners }: { relatedType?: string; relatedId?: string; owners?: { value: string; label: string }[] | null }) {
  return (
    <ActionForm path="/crm/activities" submitLabel="Programar actividad" success="Actividad programada">
      {relatedType && <><input type="hidden" name="related_type" value={relatedType} /><input type="hidden" name="related_id" value={relatedId} /></>}
      <div className="grid gap-3 sm:grid-cols-2">
        <Select name="type" label="Tipo *" options={TYPES} defaultValue="llamada" required />
        <DateTimeField name="due_at" label="Fecha y hora *" defaultValue={tenantAt(0, 2, true)} />
        <div className="sm:col-span-2"><Input name="subject" label="Asunto *" required maxLength={200} /></div>
        {owners && <Select name="owner_id" label="Responsable" options={owners} placeholder="Yo" />}
      </div>
      <TextArea name="description" label="Descripción / guion" />
    </ActionForm>
  );
}

/** Completar con resultado obligatorio y próxima actividad (activada por defecto, CRM-BR-29). */
export function CompleteActivityForm({ code, defaultSubject, redirectTo }: { code: string; defaultSubject: string; redirectTo?: string }) {
  return (
    <ActionForm path={`/crm/activities/${code}/complete`} idempotent submitLabel="Completar" success="Actividad completada" redirectTo={redirectTo}>
      <TextArea name="result" label="Resultado *" required />
      <details open className="rounded-md border border-slate-200 p-3 text-sm">
        <summary className="cursor-pointer font-medium text-slate-700">Programar próxima actividad</summary>
        <div className="mt-2 grid gap-2 sm:grid-cols-3">
          <Select name="next.type" label="Tipo" options={TYPES} defaultValue="llamada" />
          <Input name="next.subject" label="Asunto" defaultValue={`Seguimiento — ${defaultSubject}`} />
          <DateTimeField name="next.due_at" label="Fecha y hora" defaultValue={tenantAt(2, 10)} />
        </div>
        <p className="mt-1 text-xs text-slate-500">Para no programar otra, vacíe el asunto.</p>
      </details>
    </ActionForm>
  );
}

export function RescheduleForm({ code }: { code: string }) {
  return (
    <ActionForm path={`/crm/activities/${code}/reschedule`} submitLabel="Reprogramar" success="Reprogramada"
      submitClass="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
      <div className="grid gap-2 sm:grid-cols-2">
        <DateTimeField name="due_at" label="Nueva fecha y hora *" defaultValue={tenantAt(1, 10)} />
        <Input name="reason" label="Motivo" />
      </div>
    </ActionForm>
  );
}
