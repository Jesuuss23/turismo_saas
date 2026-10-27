import { api } from "@/lib/api";
import { Card, Table, Td } from "@/components/crm/ui";
import { SeriesChart } from "@/components/admin/ui";
import { SubscriptionHeader } from "@/components/billing/ui";
import { num } from "@/lib/billing";

type History = { metrics: { key: string; label: string; unit: string }[]; months: ({ month: string } & Record<string, number | string>)[] };

const MONTH = new Intl.DateTimeFormat("es-PE", { month: "short", year: "numeric", timeZone: "UTC" });
const label = (m: string) => MONTH.format(new Date(`${m.slice(0, 7)}-01T00:00:00Z`));

/** Consumo mensual (spec 13 §2): WhatsApp, correo, API, reservas y almacenamiento de los últimos meses. */
export default async function UsageHistoryPage() {
  const h = (await api<{ data: History }>("/billing/usage/history")).data;
  const chart = h.metrics.filter((m) => ["whatsapp_month", "reservations_month"].includes(m.key));

  return (
    <>
      <SubscriptionHeader active="historial" />
      <Card title="Mensajería y reservas" className="mb-4">
        <SeriesChart series={chart.map((m) => m.label)} rows={h.months.map((r) => ({ label: label(r.month), values: chart.map((m) => Number(r[m.key] ?? 0)) }))} />
      </Card>
      <Table head={["Mes", ...h.metrics.map((m) => `${m.label} (${m.unit})`)]}>
        {h.months.map((r) => (
          <tr key={r.month} data-month={r.month}>
            <Td className="whitespace-nowrap font-medium">{label(r.month)}</Td>
            {h.metrics.map((m) => <Td key={m.key}>{num(Number(r[m.key] ?? 0))}</Td>)}
          </tr>
        ))}
      </Table>
    </>
  );
}
