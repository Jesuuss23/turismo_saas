import { api } from "@/lib/api";
import { qs } from "@/lib/crm";
import { Table, Td } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { PlatformBillingHeader } from "@/components/billing/ui";
import { TONE, num } from "@/lib/billing";

type Usage = {
  tenant_id: string; code: string; name: string; plan_id: string; plan: string; status: string;
  metrics: Record<string, { used: number; limit: number | null; pct: number | null }>; top: { metric: string; label: string; pct: number | null; level: string } | null;
};

const COLS: [string, string][] = [["users", "Usuarios"], ["storage_gb", "GB"], ["reservations_month", "Reservas"], ["whatsapp_month", "WhatsApp"], ["email_month", "Correos"], ["api_calls_month", "API"]];

/** Uso por tenant (spec 13 §2): solo contadores agregados, con el % del límite más consumido. Sin datos comerciales. */
export default async function PlatformUsagePage({ searchParams }: { searchParams: Promise<{ plan?: string }> }) {
  const sp = await searchParams;
  const rows = (await api<{ data: Usage[] }>(`/platform/usage${qs({ "filter[plan]": sp.plan })}`)).data;

  return (
    <>
      <PlatformBillingHeader active="uso" subtitle="Contadores del mes por tenant. El Super Admin no ve clientes, reservas ni montos de venta." />
      <form method="get" className="mb-4 flex gap-2">
        <select name="plan" defaultValue={sp.plan ?? ""} aria-label="Plan" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
          <option value="">Todos los planes</option><option value="starter">Starter</option><option value="professional">Professional</option><option value="enterprise">Enterprise</option>
        </select>
        <button className="rounded-md border border-slate-300 px-3 py-2 text-sm">Filtrar</button>
      </form>
      <Table head={["Tenant", "Más consumido", ...COLS.map(([, l]) => l)]}>
        {rows.map((r) => (
          <tr key={r.tenant_id} data-usage={r.code}>
            <Td><span className="font-medium">{r.name}</span><span className="block text-xs text-slate-500">{r.code} · {r.plan} · {r.status}</span></Td>
            <Td>{r.top && r.top.pct !== null ? <Pill tone={TONE[r.top.level] ?? "slate"}>{r.top.label} {r.top.pct} %</Pill> : "—"}</Td>
            {COLS.map(([k]) => {
              const m = r.metrics[k];
              return <Td key={k} className="whitespace-nowrap">{num(m?.used ?? 0)}<span className="block text-xs text-slate-500">{m?.limit === null ? "∞" : m?.limit === 0 ? "no incl." : `${m?.pct ?? 0} %`}</span></Td>;
            })}
          </tr>
        ))}
      </Table>
    </>
  );
}
