import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Input, Select, Table, Td } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { PlatformBillingHeader } from "@/components/billing/ui";
import { FLAG_VALUES } from "@/lib/billing";

type Features = {
  plans: { id: string; name: string }[];
  features: { key: string; label: string; module: string; kind: string; addon_code: string | null; routes: string[]; values: Record<string, string> }[];
  overrides: { id: string; tenant_id: string; tenant_code: string; tenant_name: string; feature_key: string; feature_label: string; value: boolean; reason: string; valid_until: string; expired: boolean; created_at: string }[];
  tenants: { value: string; label: string; plan_id: string }[]; values: Record<string, string>;
};

const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50";
const VALUE_TONE: Record<string, "green" | "slate" | "sky"> = { included: "green", completo: "green", basico: "sky", not_included: "slate" };

/** Feature flags (spec 13 §2, PLN-BR-03): matriz por plan y excepciones por tenant con vigencia (máx. 180 días). */
export default async function FlagsPage() {
  const f = (await api<{ data: Features }>("/platform/features")).data;
  const values = Object.entries(f.values).map(([value, label]) => ({ value, label }));

  return (
    <>
      <PlatformBillingHeader active="flags" />
      <Card title="Funcionalidades por plan" className="mb-4">
        <Table head={["Funcionalidad", ...f.plans.map((p) => p.name)]}>
          {f.features.map((x) => (
            <tr key={x.key} data-feature={x.key}>
              <Td><span className="font-medium">{x.label}</span><span className="block text-xs text-slate-500">{x.module} · <code>{x.key}</code>{x.addon_code && ` · add-on ${x.addon_code}`}</span></Td>
              {f.plans.map((p) => (
                <Td key={p.id}>
                  <div data-flag={`${x.key}:${p.id}`}>
                    <Pill tone={VALUE_TONE[x.values[p.id]] ?? "slate"}>{FLAG_VALUES[x.values[p.id]] ?? x.values[p.id]}</Pill>
                    <ActionForm path={`/platform/features/${x.key}/plans/${p.id}`} method="PUT" className="mt-1 flex items-center gap-1" submitLabel="Guardar" submitClass={small}>
                      <select name="value" defaultValue={x.values[p.id]} aria-label={`${x.label} en ${p.name}`} className="rounded border border-slate-300 px-1 py-1 text-xs">
                        {values.map((v) => <option key={v.value} value={v.value}>{v.label}</option>)}
                      </select>
                    </ActionForm>
                  </div>
                </Td>
              ))}
            </tr>
          ))}
        </Table>
      </Card>
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="min-w-0 lg:col-span-2">
          <Table head={["Tenant", "Funcionalidad", "Valor", "Vigencia", "Motivo", ""]} empty="Sin excepciones">
            {f.overrides.map((o) => (
              <tr key={o.id} data-override={`${o.tenant_code}:${o.feature_key}`}>
                <Td>{o.tenant_name}<span className="block font-mono text-xs text-slate-500">{o.tenant_code}</span></Td>
                <Td>{o.feature_label}</Td>
                <Td><Pill tone={o.value ? "green" : "rose"}>{o.value ? "Habilitada" : "Bloqueada"}</Pill></Td>
                <Td className={o.expired ? "text-rose-700" : ""}>{date(o.valid_until)}{o.expired && " (vencida)"}</Td>
                <Td className="text-xs">{o.reason}</Td>
                <Td><ActionForm path={`/platform/feature-overrides/${o.id}`} method="DELETE" inline submitLabel="Quitar" submitClass={small} /></Td>
              </tr>
            ))}
          </Table>
        </div>
        <Card title="Nueva excepción">
          <ActionForm path="/platform/feature-overrides" submitLabel="Registrar excepción" className="space-y-3">
            <Select name="tenant_id" label="Tenant" required placeholder="Elija un tenant" options={f.tenants} />
            <Select name="feature_key" label="Funcionalidad" required placeholder="Elija" options={f.features.map((x) => ({ value: x.key, label: x.label }))} />
            <Select name="value" label="Valor" options={[{ value: "true", label: "Habilitar" }, { value: "false", label: "Bloquear" }]} defaultValue="true" />
            <Input name="valid_until" label="Vigente hasta (máx. 180 días)" type="date" />
            <Input name="reason" label="Motivo" required />
          </ActionForm>
        </Card>
      </div>
    </>
  );
}
