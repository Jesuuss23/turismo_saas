import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { price } from "@/lib/catalog";
import { Table, Td } from "@/components/crm/ui";
import { QuoteStatus } from "@/components/quotes/ui";

type Row = { code: string; title: string; display_status: string; status_label: string; currency: string; total: string; valid_until: string; travel_from: string };

/** Portal del cliente: sus cotizaciones enviadas (los borradores no se muestran). */
export default async function PortalQuotesPage({ params }: { params: Promise<{ client: string }> }) {
  const { client } = await params;
  const { data } = await api<{ data: Row[] }>("/portal/quotes");
  return (
    <>
      <h1 className="mb-4 text-xl font-semibold text-slate-900">Mis cotizaciones</h1>
      <Table head={["Cotización", "Viaje", "Estado", "Total", "Válida hasta"]} empty="Aún no tiene cotizaciones">
        {data.map((q) => (
          <tr key={q.code}>
            <Td><Link href={`/canales/portal-cliente/${client}/cotizaciones/${q.code}`} className="font-medium text-sky-800 hover:underline">{q.title}</Link><span className="block text-xs text-slate-500">{q.code}</span></Td>
            <Td>{date(q.travel_from)}</Td>
            <Td><QuoteStatus value={q.display_status} label={q.status_label} /></Td>
            <Td>{price(q.total, q.currency)}</Td>
            <Td>{date(q.valid_until)}</Td>
          </tr>
        ))}
      </Table>
    </>
  );
}
