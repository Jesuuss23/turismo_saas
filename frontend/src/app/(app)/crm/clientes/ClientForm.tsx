"use client";

import Link from "@/components/Link";
import { useState } from "react";
import { ActionForm } from "@/components/crm/ActionForm";
import { Input, Select } from "@/components/crm/ui";
import type { Client } from "@/lib/crm";

type Opt = { value: string; label: string };

const KINDS = [{ value: "persona", label: "Persona" }, { value: "empresa", label: "Empresa" }];
const DOCS = ["DNI", "RUC", "PASAPORTE", "CE"].map((d) => ({ value: d, label: d }));

/** Alta/edición de cliente: CRM-BR-15 (documento por tipo), CRM-BR-16 (documento único) y CRM-BR-17 (aviso de correo/teléfono). */
export function ClientForm({ segments, owners, fixedOwner, client }: { segments: Opt[]; owners: Opt[] | null; fixedOwner: string | null; client?: Client }) {
  const [tags, setTags] = useState((client?.tags ?? []).join(", "));
  const [kind, setKind] = useState(client?.kind ?? "persona");
  const tagList = tags.split(",").map((t) => t.trim()).filter(Boolean);

  return (
    <ActionForm
      path={client ? `/crm/clients/${client.code}` : "/crm/clients"}
      method={client ? "PATCH" : "POST"}
      ifMatch={client?.lock_version}
      redirectTo={client ? undefined : "/crm/clientes/{data.code}"}
      success="Cliente actualizado"
      submitLabel={client ? "Guardar cambios" : "Registrar cliente"}
      renderState={(state) => (
        <>
          {state?.code === "CRM-BR-17" && state.duplicates && (
            <div className="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
              <ul className="mb-2 list-disc pl-5">
                {state.duplicates.map((d) => <li key={d.id}><Link className="underline" href={`/crm/clientes/${d.code}`}>{d.code}</Link> · {d.name} (coincide por {d.fields.join(" y ")})</li>)}
              </ul>
              <label className="flex gap-2"><input type="checkbox" name="confirm_duplicate" value="true" /> Es otra persona/empresa: guardar igualmente</label>
            </div>
          )}
        </>
      )}
    >
      {tagList.map((t) => <input key={t} type="hidden" name="tags[]" value={t} />)}
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block text-sm">
          <span className="mb-1 block font-medium text-slate-700">Tipo *</span>
          <select name="kind" value={kind} onChange={(e) => setKind(e.target.value)} className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            {KINDS.map((k) => <option key={k.value} value={k.value}>{k.label}</option>)}
          </select>
        </label>
        <Input name="name" label={kind === "empresa" ? "Razón social *" : "Nombre completo *"} required maxLength={200} defaultValue={client?.name} />
        <Select name="doc_type" label="Tipo de documento *" options={DOCS} defaultValue={client?.doc_type ?? (kind === "empresa" ? "RUC" : "DNI")} required />
        <Input name="doc_number" label="Número de documento *" required maxLength={20} defaultValue={client?.doc_number} />
        <Input name="email" type="email" label="Correo" maxLength={190} defaultValue={client?.email ?? ""} />
        <Input name="phone" label="Teléfono" maxLength={40} defaultValue={client?.phone ?? ""} />
        <Input name="country" label="País" maxLength={80} defaultValue={client?.country ?? "Perú"} />
        <Input name="city" label="Ciudad" maxLength={80} defaultValue={client?.city ?? ""} />
        <Select name="segment_code" label="Segmento *" options={segments} defaultValue={client?.segment.code} required placeholder="Seleccione" />
        {kind === "persona" && <Input name="birth_date" type="date" label="Fecha de nacimiento" defaultValue={client?.birth_date ?? ""} />}
        {fixedOwner ? (
          <p className="self-end text-sm text-slate-600">Responsable: <strong>{fixedOwner}</strong> · La reasignación la realiza Gerencia.</p>
        ) : owners && !client && (
          <Select name="owner_id" label="Responsable" options={owners} placeholder="Yo" />
        )}
        <label className="block text-sm sm:col-span-2">
          <span className="mb-1 block font-medium text-slate-700">Etiquetas (separadas por coma)</span>
          <input value={tags} onChange={(e) => setTags(e.target.value)} className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="aventura, frecuente" />
        </label>
      </div>
      {!client && kind === "empresa" && (
        <fieldset className="grid gap-3 rounded-md border border-slate-200 p-3 sm:grid-cols-2">
          <legend className="px-1 text-sm font-medium text-slate-700">Contacto principal</legend>
          <Input name="contact.name" label="Nombre" maxLength={160} />
          <Input name="contact.role" label="Cargo" maxLength={120} />
          <Input name="contact.email" type="email" label="Correo" maxLength={190} />
          <Input name="contact.phone" label="Teléfono" maxLength={40} />
        </fieldset>
      )}
      <p className="text-xs text-slate-500">DNI: 8 dígitos · RUC: 11 dígitos (empresa) · Registre al menos correo o teléfono.</p>
    </ActionForm>
  );
}
