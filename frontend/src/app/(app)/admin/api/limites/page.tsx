import { api } from "@/lib/api";
import { Card, Kpis, Table, Td } from "@/components/crm/ui";
import { apiSection } from "@/components/billing/api";
import { StatusPill } from "@/components/billing/ui";
import { num, type ApiKey } from "@/lib/billing";

type Limits = {
  rate_per_min: number; burst_per_sec: number; quota_month: number | null; used_month: number; pct: number | null;
  plans: { plan: string; api_keys: number | null; webhooks: number | null; api_rate_per_min: number | null; api_calls_month: number | null }[]; keys: ApiKey[]; headers: string[];
};

/** Límites de la API (spec 13 §2, SUB-BR-04): rate limit por key, cuota mensual del plan, headers y consumo por API key con 429. */
export default async function ApiLimitsPage() {
  const { header } = await apiSection("limites");
  const l = (await api<{ data: Limits }>("/integrations/api-limits")).data;

  return (
    <>
      {header}
      <Kpis items={[
        { label: "Rate limit", value: `${num(l.rate_per_min)} req/min`, hint: `Ráfaga de ${l.burst_per_sec} req/s por key` },
        { label: "Cuota mensual", value: l.quota_month === null ? "Ilimitada" : num(l.quota_month), hint: "Llamadas a la API del plan" },
        { label: "Usado este mes", value: num(l.used_month), hint: l.pct === null ? undefined : `${l.pct} % de la cuota` },
        { label: "Al superar el límite", value: "HTTP 429", hint: "RATE_LIMITED o QUOTA_EXCEEDED con Retry-After" },
      ]} />
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Consumo por API key (hoy)" className="min-w-0">
          <Table head={["API key", "Llamadas", "429", "Estado"]} empty="Sin API keys">
            {l.keys.map((k) => (
              <tr key={k.id} data-limit-key={k.code}>
                <Td><span className="font-medium">{k.name}</span><span className="block font-mono text-xs text-slate-500">{k.masked}</span></Td>
                <Td>{num(k.calls_today)}</Td><Td>{k.throttled_today}</Td><Td><StatusPill status={k.status} label={k.status_label} /></Td>
              </tr>
            ))}
          </Table>
        </Card>
        <Card title="Límites por plan" className="min-w-0">
          <Table head={["Plan", "Req/min", "Llamadas/mes", "API keys", "Webhooks"]}>
            {l.plans.map((p) => (
              <tr key={p.plan}><Td className="font-medium">{p.plan}</Td><Td>{p.api_rate_per_min ? num(p.api_rate_per_min) : "—"}</Td>
                <Td>{p.api_calls_month ? num(p.api_calls_month) : "No incluido"}</Td><Td>{p.api_keys ? num(p.api_keys) : "—"}</Td><Td>{p.webhooks ? num(p.webhooks) : "—"}</Td></tr>
            ))}
          </Table>
          <p className="mb-1 mt-4 text-sm font-medium text-slate-800">Headers de cada respuesta</p>
          <ul className="space-y-0.5 font-mono text-xs text-slate-700">{l.headers.map((h) => <li key={h}>{h}</li>)}</ul>
        </Card>
      </div>
    </>
  );
}
