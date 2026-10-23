"use client";

import { ActionForm } from "@/components/crm/ActionForm";
import { Input, Select, TextArea } from "@/components/crm/ui";

type Opt = { value: string; label: string };
const OPEN = [{ value: "calificacion", label: "Calificación" }, { value: "descubrimiento", label: "Descubrimiento" }, { value: "cotizacion", label: "Cotización" }, { value: "negociacion", label: "Negociación" }];

/** Alta manual o de recompra (CRM-BR-20/21/45). */
export function OpportunityForm({ clients, defaultClient, owners, fixedOwner, today, preset }: {
  clients: Opt[]; defaultClient?: string; owners: Opt[] | null; fixedOwner: string | null; today: string;
  preset?: { title: string; destination_label: string; amount: number; currency: string; stage: string; expected_close_date: string; origin: string; client_id: string };
}) {
  return (
    <ActionForm path="/crm/opportunities" idempotent redirectTo="/crm/oportunidades/{data.code}" submitLabel={preset ? "Crear oportunidad de recompra" : "Crear oportunidad"}>
      {preset && <input type="hidden" name="origin" value={preset.origin} />}
      <div className="grid gap-3 sm:grid-cols-2">
        <Select name="client_id" label="Cliente *" options={clients} defaultValue={preset?.client_id ?? defaultClient} required placeholder="Seleccione" />
        {fixedOwner
          ? <p className="self-end text-sm text-slate-600">Responsable: <strong>{fixedOwner}</strong></p>
          : owners && <Select name="owner_id" label="Responsable *" options={owners} placeholder="Yo" />}
        <div className="sm:col-span-2"><Input name="title" label="Título *" required maxLength={200} defaultValue={preset?.title} /></div>
        <Select name="stage" label="Etapa inicial" options={OPEN} defaultValue={preset?.stage ?? "calificacion"} />
        <Input name="destination_label" label="Destino" defaultValue={preset?.destination_label} />
        <Input name="amount" type="number" min={1} step="0.01" label="Monto *" required defaultValue={preset?.amount} />
        <Select name="currency" label="Moneda *" options={[{ value: "PEN", label: "PEN" }, { value: "USD", label: "USD" }]} defaultValue={preset?.currency ?? "PEN"} />
        <Input name="expected_close_date" type="date" min={today} label="Cierre esperado *" required defaultValue={preset?.expected_close_date} />
        <Input name="pax" type="number" min={0} label="Pasajeros" defaultValue={0} />
      </div>
      <TextArea name="notes" label="Necesidad / notas" />
    </ActionForm>
  );
}
