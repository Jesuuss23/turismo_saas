import { api } from "@/lib/api";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Table, Td } from "@/components/crm/ui";
import { PlatformBillingHeader } from "@/components/billing/ui";
import { num, pen } from "@/lib/billing";

type Plan = {
  id: string; name: string; rank: number; price_month: number; price_year: number; trial_days: number; status: string; currency: string; lock_version: number; tenants: number;
  rules_count: number; limits: { metric: string; label: string; unit: string; value: number | null; hard: boolean }[];
};
type Matrix = {
  is_demo: boolean; note: string; plans: { id: string; name: string; rules_count: number }[];
  groups: { key: string; label: string; rules: { code: string; label: string; min_plan: string; lock_version: number; tenants_with_access: number }[] }[];
};

const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50";
const cell = "w-24 rounded border border-slate-300 px-2 py-1 text-xs";

/** Planes (spec 13 §2): matriz planes × reglas de reserva (DEMO editable) y catálogo de precios, prueba y límites (PLN-BR-01/02). */
export default async function PlansPage() {
  const [plans, matrix] = await Promise.all([
    api<{ data: Plan[] }>("/platform/billing-plans").then((r) => r.data),
    api<{ data: Matrix }>("/platform/plan-rules").then((r) => r.data),
  ]);

  return (
    <>
      <PlatformBillingHeader active="planes" />
      <div className="mb-4 grid gap-4 lg:grid-cols-3">
        {plans.map((p) => (
          <Card key={p.id} title={`${p.name} · ${p.tenants} tenant(s)`}>
            <p className="mb-2 text-sm text-slate-700" data-plan-price={p.id}>{pen(p.price_month)} / mes · {pen(p.price_year)} / año · {p.trial_days} días de prueba</p>
            <ActionForm path={`/platform/plans/${p.id}`} method="PATCH" ifMatch={p.lock_version} submitLabel="Guardar plan" submitClass={small} className="space-y-2"
              confirm="Los tenants actuales conservan su precio 30 días (PLN-BR-02). ¿Guardar?">
              <div className="grid grid-cols-3 gap-2 text-xs text-slate-600">
                <label>Mensual<input name="price_month" type="number" step="0.01" min={0} defaultValue={p.price_month} className={`${cell} mt-1 block w-full`} /></label>
                <label>Anual<input name="price_year" type="number" step="0.01" min={0} defaultValue={p.price_year} className={`${cell} mt-1 block w-full`} /></label>
                <label>Prueba (días)<input name="trial_days" type="number" min={0} max={60} defaultValue={p.trial_days} className={`${cell} mt-1 block w-full`} /></label>
              </div>
              <details><summary className="cursor-pointer text-xs text-slate-600">Límites</summary>
                <div className="mt-2 grid grid-cols-2 gap-2 text-xs text-slate-600">
                  {p.limits.map((l) => (
                    <label key={l.metric}>{l.label} ({l.unit})
                      <input name={`limits.${l.metric}`} type="number" min={0} defaultValue={l.value ?? ""} placeholder="Ilimitado" className={`${cell} mt-1 block w-full`} /></label>
                  ))}
                </div>
              </details>
            </ActionForm>
            <p className="mt-2 text-xs text-slate-500">{p.limits.filter((l) => ["users", "sedes", "storage_gb"].includes(l.metric)).map((l) => `${num(l.value, l.unit)}`).join(" · ")}</p>
          </Card>
        ))}
      </div>

      <Card title={`Planes × reglas de reserva${matrix.is_demo ? " (DEMO, editable)" : ""}`}
        actions={<ActionForm path="/platform/plan-rules/reset-demo" inline submitLabel="Restablecer matriz demo" submitClass={small} confirm="¿Restablecer la matriz de demostración?" />}>
        <p className="mb-3 text-xs text-slate-500">{matrix.note} Marque «Simular» para ver qué tenants ganan o pierden la regla antes de aplicarla.</p>
        <Table head={["Regla", "Plan mínimo", "Tenants con acceso", ""]}>
          {matrix.groups.flatMap((g) => g.rules.map((r) => (
            <tr key={r.code} data-plan-rule={r.code}>
              <Td><span className="text-xs text-slate-500">{g.label}</span><span className="block">{r.label}</span></Td>
              <Td>{matrix.plans.find((p) => p.id === r.min_plan)?.name ?? r.min_plan}</Td>
              <Td>{r.tenants_with_access}</Td>
              <Td>
                <ActionForm path={`/platform/plan-rules/${r.code}`} method="PUT" ifMatch={r.lock_version} inline submitLabel="Aplicar" submitClass={small}>
                  <select name="min_plan" defaultValue={r.min_plan} aria-label={`Plan mínimo de ${r.label}`} className={cell}>
                    {matrix.plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                  </select>
                  <label className="flex items-center gap-1 text-xs text-slate-600"><input type="checkbox" name="dry_run" value="true" defaultChecked /> Simular</label>
                </ActionForm>
              </Td>
            </tr>
          )))}
        </Table>
      </Card>
    </>
  );
}
