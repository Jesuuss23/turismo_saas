import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Input, Select, Table, Td } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { PlatformBillingHeader } from "@/components/billing/ui";

type Coupon = {
  id: string; code: string; type: string; value: number; duration_cycles: number; plans: string[]; plan_ids: string[]; max_redemptions: number; redeemed_count: number;
  valid_to: string; status: string; expired: boolean; note: string | null; label: string;
};

const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50";

/** Cupones (spec 13 §2, SUB-BR-07/08): crear (máx. 50 %), activar y desactivar; canjes frente al máximo. */
export default async function CouponsPage() {
  const [coupons, plans] = await Promise.all([
    api<{ data: Coupon[] }>("/platform/coupons").then((r) => r.data),
    api<{ data: { id: string; name: string }[] }>("/platform/billing-plans").then((r) => r.data),
  ]);

  return (
    <>
      <PlatformBillingHeader active="cupones" />
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="min-w-0 lg:col-span-2">
          <Table head={["Cupón", "Descuento", "Planes", "Canjes", "Vigencia", "Estado", ""]} empty="Sin cupones">
            {coupons.map((c) => (
              <tr key={c.id} data-coupon={c.code}>
                <Td><span className="font-mono font-medium">{c.code}</span>{c.note && <span className="block text-xs text-slate-500">{c.note}</span>}</Td>
                <Td>{c.label}</Td>
                <Td className="text-xs">{c.plans.length ? c.plans.join(", ") : "Todos"}</Td>
                <Td>{c.redeemed_count} / {c.max_redemptions}</Td>
                <Td className={c.expired ? "text-rose-700" : ""}>{date(c.valid_to)}</Td>
                <Td>{c.expired ? <Pill tone="slate">Vencido</Pill> : c.redeemed_count >= c.max_redemptions ? <Pill tone="amber">Agotado</Pill>
                  : <Pill tone={c.status === "active" ? "green" : "slate"}>{c.status === "active" ? "Activo" : "Inactivo"}</Pill>}</Td>
                <Td>
                  <ActionForm path={`/platform/coupons/${c.id}`} method="PATCH" inline submitLabel={c.status === "active" ? "Desactivar" : "Activar"} submitClass={small}>
                    <input type="hidden" name="status" value={c.status === "active" ? "inactive" : "active"} />
                  </ActionForm>
                </Td>
              </tr>
            ))}
          </Table>
        </div>
        <Card title="Nuevo cupón">
          <ActionForm path="/platform/coupons" submitLabel="Crear cupón" className="space-y-3">
            <Input name="code" label="Código" placeholder="VERANO27" required pattern="[A-Za-z0-9_\-]{4,20}" />
            <div className="grid grid-cols-2 gap-2">
              <Select name="type" label="Tipo" options={[{ value: "percent", label: "Porcentaje" }, { value: "fixed", label: "Monto fijo (S/)" }]} defaultValue="percent" />
              <Input name="value" label="Valor" type="number" step="0.01" min={0.01} required />
              <Input name="duration_cycles" label="Ciclos" type="number" min={1} max={12} defaultValue={1} required />
              <Input name="max_redemptions" label="Canjes máximos" type="number" min={1} defaultValue={100} required />
            </div>
            <Input name="valid_to" label="Vigente hasta" type="date" required />
            <fieldset className="text-sm">
              <legend className="mb-1 font-medium text-slate-700">Planes (vacío = todos)</legend>
              <div className="flex flex-wrap gap-3">{plans.map((p) => <label key={p.id} className="flex items-center gap-1"><input type="checkbox" name="plan_ids[]" value={p.id} />{p.name}</label>)}</div>
            </fieldset>
            <Input name="note" label="Nota interna" maxLength={200} />
          </ActionForm>
          <p className="mt-2 text-xs text-slate-500">SUB-BR-08: el descuento porcentual máximo es 50 %. Los cupones no son acumulables.</p>
        </Card>
      </div>
    </>
  );
}
