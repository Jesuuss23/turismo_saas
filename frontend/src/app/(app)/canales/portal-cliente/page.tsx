import { redirect } from "next/navigation";
import { api } from "@/lib/api";
import { PageHeader, Table, Td } from "@/components/crm/ui";
import Link from "@/components/Link";

type Row = { id: string; code: string; name: string; email: string | null };

/** Vista del canal (interno): elegir un cliente de su cartera para ver su portal en solo lectura. */
export default async function ClientPortalPicker({ searchParams }: { searchParams: Promise<{ client?: string }> }) {
  const sp = await searchParams;
  if (sp.client) redirect(`/canales/portal-cliente/${encodeURIComponent(sp.client)}/inicio`);
  const { data } = await api<{ data: Row[] }>("/channels/portal-preview/clients");
  return (
    <>
      <PageHeader title="Portal del cliente" subtitle="Vista del canal: elija un cliente con cotizaciones o reservas para ver su portal tal como lo ve él (solo lectura)." />
      <Table head={["Cliente", "Correo", ""]} empty="No hay clientes con cotizaciones enviadas o reservas en su cartera">
        {data.map((c) => (
          <tr key={c.id}>
            <Td>{c.name}<span className="block text-xs text-slate-500">{c.code}</span></Td>
            <Td>{c.email ?? "—"}</Td>
            <Td><Link href={`/canales/portal-cliente/${c.id}/inicio`} className="text-sm text-sky-800 hover:underline">Ver portal</Link></Td>
          </tr>
        ))}
      </Table>
    </>
  );
}
