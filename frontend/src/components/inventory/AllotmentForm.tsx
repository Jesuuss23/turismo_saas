"use client";

import { useState } from "react";
import { ActionForm } from "@/components/crm/ActionForm";
import { Input, Select, TextArea, inputCls } from "@/components/crm/ui";

export type AllotmentOptions = {
  providers: { id: string; name: string; ref: string; type: string }[];
  services: { id: string; provider_id: string; name: string; ref: string; type: string; kind: string; cost: string; currency: string }[];
  units: Record<string, string>;
};
export type AllotmentValues = {
  code: string; provider: { id: string }; service: { id: string }; name: string; contract_ref: string | null; valid_from: string; valid_to: string; base_qty: number;
  release_days: number; net_rate: string; currency: string; penalty_pct: string; notes: string | null; lock_version: number;
};

/** Modal "Nuevo contrato" / "Editar" (spec 11 §2): el servicio se filtra por proveedor y define el tipo de cupo (CUP-BR-002). */
export function AllotmentForm({ options, current, close }: { options: AllotmentOptions; current?: AllotmentValues; close: string }) {
  const [provider, setProvider] = useState(current?.provider.id ?? "");
  const services = options.services.filter((s) => s.provider_id === provider);
  const [service, setService] = useState(current?.service.id ?? "");
  const picked = options.services.find((s) => s.id === service);
  const unit = picked ? options.units[picked.kind] : null;

  return (
    <ActionForm path={current ? `/catalog/allotments/${current.code}` : "/catalog/allotments"} method={current ? "PATCH" : "POST"} ifMatch={current?.lock_version}
      idempotent={!current} redirectTo={current ? close : "/catalogo/cupos/{data.code}"} success={current ? "Contrato actualizado" : "Contrato registrado"}
      submitLabel={current ? "Guardar cambios" : "Registrar contrato"}>
      <label className="block text-sm">
        <span className="mb-1 block font-medium text-slate-700">Proveedor</span>
        <select name="provider_id" required value={provider} disabled={!!current} className={inputCls}
          onChange={(e) => { setProvider(e.target.value); setService(""); }}>
          <option value="">Seleccione…</option>
          {options.providers.map((p) => <option key={p.id} value={p.id}>{p.name} · {p.type}</option>)}
        </select>
        {current && <input type="hidden" name="provider_id" value={provider} />}
      </label>
      <label className="block text-sm">
        <span className="mb-1 block font-medium text-slate-700">Servicio (Alojamiento, Tren o Entrada)</span>
        <select name="service_id" required value={service} onChange={(e) => setService(e.target.value)} className={inputCls}>
          <option value="">{provider ? "Seleccione…" : "Elija primero el proveedor"}</option>
          {services.map((s) => <option key={s.id} value={s.id}>{s.name} · {s.type} · {s.currency} {s.cost}</option>)}
        </select>
      </label>
      <Input label="Nombre del contrato" name="name" required maxLength={150} defaultValue={current?.name} placeholder="Ej.: Bloqueo temporada alta" />
      <Input label="Referencia (adenda / convenio)" name="contract_ref" maxLength={80} defaultValue={current?.contract_ref ?? ""} />
      <div className="grid grid-cols-2 gap-3">
        <Input label="Vigente desde" name="valid_from" type="date" required defaultValue={current?.valid_from} />
        <Input label="Vigente hasta" name="valid_to" type="date" required defaultValue={current?.valid_to} />
        <Input label={`Bloqueo base${unit ? ` (${unit})` : ""}`} name="base_qty" type="number" min={1} max={9999} required defaultValue={current?.base_qty} />
        <Input label="Días de release" name="release_days" type="number" min={0} max={120} required defaultValue={current?.release_days ?? 7} />
        <Input label="Tarifa neta" name="net_rate" type="number" step="0.01" min={0} required defaultValue={current?.net_rate ?? picked?.cost} />
        <Select name="currency" label="Moneda" defaultValue={current?.currency ?? picked?.currency ?? "USD"} options={[{ value: "USD", label: "USD" }, { value: "PEN", label: "PEN" }]} />
        <Input label="Penalidad por no-uso (%)" name="penalty_pct" type="number" step="0.01" min={0} max={100} defaultValue={current?.penalty_pct ?? 0} />
      </div>
      <TextArea label="Notas" name="notes" maxLength={2000} defaultValue={current?.notes ?? ""} />
      <p className="text-xs text-slate-500">La penalidad se aplica sobre la tarifa neta de lo retenido y no usado. Al guardar se genera una fila por fecha de vigencia.</p>
    </ActionForm>
  );
}
