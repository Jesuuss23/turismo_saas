import Link from "@/components/Link";
import { api } from "@/lib/api";
import { catalogOptions, crmMeta, owners } from "@/lib/crm-server";
import { date, money, qs, type Client, type Paged } from "@/lib/crm";
import { Badge, btnCls, btnGhostCls, inputCls, Kpis, Notice, PageHeader, ScopeChip, Table, Td } from "@/components/crm/ui";
import { ExportButton } from "@/components/crm/ExportButton";

type ClientKpis = { clients: number; corporate_b2b: number; vip_groups: number; nps: number | null };
type Search = { q?: string; segment?: string; kind?: string; owner?: string; cursor?: string };

export default async function ClientsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const meta = await crmMeta();
  const advisors = meta.scope.supervisor ? await owners() : null;
  const filters = { "filter[q]": sp.q, "filter[segment]": sp.segment, "filter[kind]": sp.kind, "filter[owner]": sp.owner };
  const res = await api<Paged<Client, { kpis: ClientKpis }>>(`/crm/clients${qs({ ...filters, cursor: sp.cursor })}`);
  const k = res.meta.kpis;

  return (
    <>
      <PageHeader
        title="Clientes"
        subtitle={<ScopeChip ownOnly={meta.scope.own_only} ownerName={advisors?.data.find((a) => a.id === sp.owner)?.name} />}
        actions={<>
          <ExportButton resource="crm.clients" filters={{ q: sp.q, segment: sp.segment, kind: sp.kind, owner: sp.owner }} />
          {meta.can.clients_manage && <Link href="/crm/clientes/new" className={btnCls}>Nuevo cliente</Link>}
        </>}
      />
      {!meta.can.clients_manage && <Notice>Modo consulta: su rol no puede crear ni editar clientes (permiso crm.clients.manage).</Notice>}
      <Kpis items={[
        { label: "Clientes", value: k.clients },
        { label: "Corporativo + B2B", value: k.corporate_b2b },
        { label: "VIP + grupos", value: k.vip_groups },
        { label: "NPS", value: k.nps ?? "—" },
      ]} />
      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <input name="q" defaultValue={sp.q} placeholder="Código, nombre, documento, correo, teléfono, ciudad o etiqueta" className={`${inputCls} max-w-md`} />
        <select name="segment" defaultValue={sp.segment ?? ""} className={`${inputCls} max-w-xs`} aria-label="Segmento">
          <option value="">Segmento: todos</option>
          {catalogOptions(meta, "client_segment").map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
        <select name="kind" defaultValue={sp.kind ?? ""} className={`${inputCls} max-w-[10rem]`} aria-label="Tipo">
          <option value="">Tipo: todos</option>
          <option value="persona">Persona</option>
          <option value="empresa">Empresa</option>
        </select>
        {advisors && (
          <select name="owner" defaultValue={sp.owner ?? ""} className={`${inputCls} max-w-xs`} aria-label="Asesor">
            <option value="">Asesor: todos</option>
            {advisors.data.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
        )}
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Cliente", "Documento", "Segmento", "Contacto", "Reservas", "Total comprado", "Saldo", "NPS", "Última compra", "Responsable"]}>
        {res.data.map((c) => (
          <tr key={c.id}>
            <Td><Link href={`/crm/clientes/${c.code}`} className="font-medium text-sky-800 hover:underline">{c.code}</Link><br />{c.name}</Td>
            <Td>{c.doc_type} {c.doc_number}</Td>
            <Td><Badge status={c.segment.code}>{c.segment.label}</Badge></Td>
            <Td>{c.contact_name ?? c.email ?? c.phone ?? "—"}</Td>
            <Td>{c.reservations_count}</Td>
            <Td>{money(c.total_pen)}</Td>
            <Td className={c.balance_pen > 0 ? "text-rose-700" : ""}>{money(c.balance_pen)}</Td>
            <Td>{c.nps ?? "—"}</Td>
            <Td>{date(c.last_purchase_date)}</Td>
            <Td>{c.owner?.name}</Td>
          </tr>
        ))}
      </Table>
      {(res.meta.prev_cursor || res.meta.next_cursor) && (
        <nav className="mt-3 flex gap-2">
          {res.meta.prev_cursor && <Link className={btnGhostCls} href={`/crm/clientes${qs({ ...sp, cursor: res.meta.prev_cursor })}`}>← Anterior</Link>}
          {res.meta.next_cursor && <Link className={btnGhostCls} href={`/crm/clientes${qs({ ...sp, cursor: res.meta.next_cursor })}`}>Siguiente →</Link>}
        </nav>
      )}
    </>
  );
}
