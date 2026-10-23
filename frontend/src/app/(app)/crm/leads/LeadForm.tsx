"use client";

import Link from "@/components/Link";
import { ActionForm } from "@/components/crm/ActionForm";
import { Input, Select, TextArea } from "@/components/crm/ui";

type Opt = { value: string; label: string };

/** Alta de lead (PROC-004) con resolución explícita de duplicados (CRM-BR-04). */
export function LeadForm({ channels, sources, interests, owners, fixedOwner, today }: {
  channels: Opt[]; sources: Opt[]; interests: Opt[]; owners: Opt[] | null; fixedOwner: string | null; today: string;
}) {
  return (
    <ActionForm path="/crm/leads" idempotent redirectTo="/crm/leads/{data.code}" submitLabel="Registrar lead"
      renderState={(state) => state?.code === "CRM-BR-04" && state.duplicates ? (
        <div className="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm">
          <p className="font-medium text-amber-900">Posibles duplicados encontrados. Elija qué hacer y vuelva a registrar:</p>
          <ul className="my-2 list-disc pl-5 text-amber-900">
            {state.duplicates.map((d) => (
              <li key={d.id}>
                {d.type === "lead" ? <Link className="underline" href={`/crm/leads/${d.code}`}>{d.code}</Link> : d.code} · {d.name} · {d.status} (coincide por {d.fields.join(" y ")})
              </li>
            ))}
          </ul>
          <fieldset className="space-y-1">
            {state.duplicates.some((d) => d.type === "lead" && ["nuevo", "contactado", "calificado"].includes(d.status)) && (
              <label className="flex gap-2"><input type="radio" name="duplicate_resolution" value="merge" /> Fusionar con el lead existente (no crea un registro nuevo)</label>
            )}
            <label className="flex gap-2"><input type="radio" name="duplicate_resolution" value="mark_duplicate" /> Registrar como duplicado</label>
            <label className="flex gap-2"><input type="radio" name="duplicate_resolution" value="create_new" /> Crear de todas formas (queda nota)</label>
          </fieldset>
        </div>
      ) : null}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <Input name="full_name" label="Nombre *" required maxLength={160} />
        <Input name="company" label="Empresa" maxLength={160} />
        <Input name="email" type="email" label="Correo" maxLength={190} />
        <Input name="phone" label="Teléfono" maxLength={40} />
        <Select name="channel_code" label="Canal *" options={channels} required placeholder="Seleccione" />
        <Select name="source_code" label="Fuente *" options={sources} required placeholder="Seleccione" />
        <Select name="interest_code" label="Interés *" options={interests} required placeholder="Seleccione" />
        <Input name="destination_label" label="Destino" maxLength={120} placeholder="Cusco, Arequipa, Varios…" />
        <Input name="travel_date" type="date" label="Fecha tentativa de viaje" min={today} />
        <Input name="pax" type="number" label="Pasajeros" min={0} max={999} defaultValue={0} />
        {fixedOwner ? (
          <p className="text-sm text-slate-600 sm:col-span-2">Responsable: <strong>{fixedOwner}</strong> · La reasignación la realiza Gerencia.</p>
        ) : owners && (
          <Select name="owner_id" label="Responsable" options={owners} placeholder="Sin asignar (cola comercial)" />
        )}
      </div>
      <TextArea name="notes" label="Notas" maxLength={5000} />
      <p className="text-xs text-slate-500">Registre al menos correo o teléfono (PROC-004).</p>
    </ActionForm>
  );
}
