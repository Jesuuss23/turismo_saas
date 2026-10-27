import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Table, Td } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { PlatformBillingHeader } from "@/components/billing/ui";

type Trial = {
  tenant_id: string; tenant_code: string; tenant_name: string; plan_id: string; plan_name: string; subscription: string; trial_starts_at: string; trial_ends_at: string;
  days_left: number; extended_days: number; can_extend: boolean; has_card: boolean; level: string;
};

const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50";
const LEVEL: Record<string, "rose" | "amber" | "sky"> = { error: "rose", warn: "amber", ok: "sky" };

/** Trials por vencer (spec 13 §2, SUB-BR-10): extender 7 días (máx. 14) y convertir a suscripción con cupón opcional. */
export default async function TrialsPage() {
  const rows = (await api<{ data: Trial[] }>("/platform/trials")).data;
  return (
    <>
      <PlatformBillingHeader active="trials" />
      <Table head={["Tenant", "Plan", "Prueba", "Quedan", "Tarjeta", ""]} empty="No hay tenants en prueba">
        {rows.map((t) => (
          <tr key={t.tenant_id} data-trial={t.tenant_code}>
            <Td><span className="font-medium">{t.tenant_name}</span><span className="block font-mono text-xs text-slate-500">{t.tenant_code} · {t.subscription}</span></Td>
            <Td>{t.plan_name}</Td>
            <Td>{date(t.trial_starts_at)} al <span data-trial-end>{date(t.trial_ends_at)}</span>{t.extended_days > 0 && <span className="block text-xs text-slate-500">Extendido +{t.extended_days} días</span>}</Td>
            <Td><Pill tone={LEVEL[t.level] ?? "sky"}>{t.days_left} día(s)</Pill></Td>
            <Td>{t.has_card ? "Registrada" : "Sin tarjeta"}</Td>
            <Td>
              <div className="flex flex-wrap items-start gap-1">
                <ActionForm path={`/platform/trials/${t.tenant_id}/extend`} inline submitLabel="Extender 7 días" submitClass={small} disabled={!t.can_extend} disabledReason="Máximo 14 días de extensión" />
                <ActionForm path={`/platform/trials/${t.tenant_id}/convert`} inline submitLabel="Convertir" submitClass={small} confirm={`¿Convertir ${t.tenant_code} a suscripción pagada?`}>
                  <input name="coupon" placeholder="Cupón (opcional)" aria-label="Cupón" className="w-32 rounded border border-slate-300 px-2 py-1 text-xs uppercase" />
                </ActionForm>
              </div>
            </Td>
          </tr>
        ))}
      </Table>
    </>
  );
}
