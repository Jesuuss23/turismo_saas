import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { Card, Kpis, Table, Td } from "@/components/crm/ui";
import { Bars, Pill, SeriesChart } from "@/components/admin/ui";
import { PlatformBillingHeader } from "@/components/billing/ui";
import { pen } from "@/lib/billing";

type Metrics = {
  kpis: { mrr: number; arr: number; arpa: number; active_tenants: number; trialing_tenants: number; logo_churn_pct: number; revenue_churn_pct: number; failed_payments: number; failed_amount: number };
  series: { month: string; mrr: number; arr: number; new: number; churned: number }[]; mrr_by_plan: { plan_id: string; plan: string; mrr: number }[];
  events: { kind: string; tenant: string; description: string; mrr_delta: number; at: string }[];
  failed: { number: string; tenant: string; name: string; total: number; next_retry_at: string | null; retries_done: number }[]; from: string; to: string;
};

const KIND: Record<string, ["green" | "sky" | "amber" | "rose" | "slate", string]> = {
  new: ["green", "Alta"], upgrade: ["sky", "Upgrade"], downgrade: ["amber", "Downgrade"], churn: ["rose", "Baja"], canceled: ["rose", "Baja"], suspended: ["rose", "Suspensión"],
  reactivated: ["green", "Reactivación"], trial: ["slate", "Trial"], converted: ["green", "Conversión"],
};
const MONTH = new Intl.DateTimeFormat("es-PE", { month: "short", timeZone: "UTC" });

/** Métricas de la plataforma (spec 13 §2, Super Admin): MRR, ARR, ARPA, churn de 90 días, cobros fallidos y ciclo de vida. */
export default async function PlatformBillingPage() {
  const m = (await api<{ data: Metrics }>("/platform/metrics")).data;
  const k = m.kpis;

  return (
    <>
      <PlatformBillingHeader active="" subtitle={`Del ${date(m.from)} al ${date(m.to)} · montos sin IGV`} />
      <Kpis items={[
        { label: "MRR", value: pen(k.mrr), hint: `ARR ${pen(k.arr)}` },
        { label: "ARPA", value: pen(k.arpa), hint: `${k.active_tenants} tenants activos · ${k.trialing_tenants} en prueba` },
        { label: "Churn 90 días", value: `${k.logo_churn_pct} %`, hint: `Churn de ingresos ${k.revenue_churn_pct} %` },
        { label: "Cobros fallidos", value: k.failed_payments, hint: <Link href="/admin/plataforma/facturacion/cobranza" className="text-sky-800 hover:underline">{pen(k.failed_amount)} en cobranza</Link> },
      ]} />
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="min-w-0 space-y-4 lg:col-span-2">
          <Card title="Evolución del MRR">
            <SeriesChart series={["MRR"]} unit="PEN" rows={m.series.map((r) => ({ label: MONTH.format(new Date(`${r.month.slice(0, 7)}-01T00:00:00Z`)), values: [r.mrr] }))} />
          </Card>
          <Card title="Altas y bajas por mes">
            <SeriesChart series={["Altas", "Bajas"]} rows={m.series.map((r) => ({ label: MONTH.format(new Date(`${r.month.slice(0, 7)}-01T00:00:00Z`)), values: [r.new, r.churned] }))} />
          </Card>
          <Card title="Eventos del ciclo de vida">
            <Table head={["Fecha", "Evento", "Tenant", "Detalle", "Δ MRR"]} empty="Sin eventos">
              {m.events.map((e, i) => (
                <tr key={i}>
                  <Td className="whitespace-nowrap">{dateTime(e.at)}</Td>
                  <Td><Pill tone={(KIND[e.kind] ?? ["slate"])[0]}>{(KIND[e.kind] ?? [null, e.kind])[1]}</Pill></Td>
                  <Td className="font-mono text-xs">{e.tenant}</Td><Td>{e.description}</Td>
                  <Td className={`whitespace-nowrap ${e.mrr_delta < 0 ? "text-rose-700" : e.mrr_delta > 0 ? "text-emerald-700" : ""}`}>{e.mrr_delta ? pen(e.mrr_delta) : "—"}</Td>
                </tr>
              ))}
            </Table>
          </Card>
        </div>
        <div className="min-w-0 space-y-4">
          <Card title="MRR por plan"><Bars rows={m.mrr_by_plan.map((p) => ({ label: p.plan, value: p.mrr }))} unit="PEN" /></Card>
          <Card title="Cobros fallidos">
            {m.failed.length === 0 ? <p className="text-sm text-slate-500">Sin cobros fallidos.</p> : (
              <ul className="space-y-2 text-sm">
                {m.failed.map((f) => (
                  <li key={f.number} data-failed={f.number}>
                    <p className="font-medium text-slate-900">{f.name} <span className="font-mono text-xs text-slate-500">{f.tenant}</span></p>
                    <p className="text-slate-600">{f.number} · {pen(f.total)} · reintento {f.retries_done + 1}{f.next_retry_at && ` el ${dateTime(f.next_retry_at)}`}</p>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>
    </>
  );
}
