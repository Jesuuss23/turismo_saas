import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import { catalogMeta } from "@/lib/catalog-server";
import { addDays, price, today, type Service } from "@/lib/catalog";
import { btnGhostCls, Card, Input, inputCls, Kpis, PageHeader, Select, Table, Td } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { Status } from "@/components/catalog/ui";

type Search = { type?: string; provider?: string; status?: string; q?: string };
type ServiceKpis = { active: number; expired_or_inactive: number; expiring_90: number; provider_not_active: number };

export default async function ServicesPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const [meta, res] = await Promise.all([
    catalogMeta(),
    api<{ data: Service[]; meta: { kpis: ServiceKpis } }>(`/catalog/services${qs({ "filter[type]": sp.type, "filter[provider]": sp.provider, "filter[status]": sp.status, "filter[q]": sp.q })}`),
  ]);
  const k = res.meta.kpis;
  const costs = meta.can.see_costs;

  return (
    <>
      <PageHeader title="Servicios" subtitle="Servicios de proveedores que componen los productos · PROC-013" />
      <Kpis items={[
        { label: "Activos", value: k.active },
        { label: "Vencidos o inactivos", value: k.expired_or_inactive },
        { label: "Vencen en 90 días", value: k.expiring_90 },
        { label: "Proveedor no activo", value: k.provider_not_active },
      ]} />
      <form className="mb-3 grid gap-2 sm:grid-cols-5" role="search">
        <input name="q" defaultValue={sp.q} placeholder="Nombre o código…" className={inputCls} aria-label="Buscar" />
        <select name="type" defaultValue={sp.type ?? ""} className={inputCls} aria-label="Tipo"><option value="">Todos los tipos</option>{meta.enums.service_types.map((t) => <option key={t}>{t}</option>)}</select>
        <select name="provider" defaultValue={sp.provider ?? ""} className={inputCls} aria-label="Proveedor"><option value="">Todos los proveedores</option>{meta.providers.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>
        <select name="status" defaultValue={sp.status ?? ""} className={inputCls} aria-label="Estado"><option value="">Todos los estados</option>{["activo", "inactivo", "vencido"].map((t) => <option key={t}>{t}</option>)}</select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Servicio", "Tipo", "Proveedor", ...(costs ? ["Costo"] : []), "Vigencia", "Productos", "Estado"]} empty="Sin servicios">
        {res.data.map((s) => (
          <tr key={s.id} className="hover:bg-slate-50">
            <Td><Link href={`/catalogo/servicios/${s.ref}`} className="font-medium text-sky-800 hover:underline">{s.ref}</Link><br />{s.name}</Td>
            <Td>{s.type}<br /><span className="text-xs text-slate-500">por {s.unit}</span></Td>
            <Td>{s.provider?.name}{s.provider && s.provider.status !== "activo" && <span className="block text-xs text-rose-700">proveedor {s.provider.status}</span>}</Td>
            {costs && <Td>{price(s.cost, s.currency)}</Td>}
            <Td className="whitespace-nowrap text-xs">{date(s.valid_from)} → {date(s.valid_to)}</Td>
            <Td>{s.products ?? 0}</Td>
            <Td><Status value={s.effective_status} /></Td>
          </tr>
        ))}
      </Table>
      {meta.can.providers && (
        <Card title="Nuevo servicio" className="mt-4">
          <ActionForm path="/catalog/services" submitLabel="Crear servicio" className="grid gap-2 sm:grid-cols-4">
            <Select label="Proveedor" name="provider_id" options={meta.providers.map((p) => ({ value: p.id, label: `${p.ref} · ${p.name}${p.usable ? "" : " (no activo)"}` }))} />
            <Input label="Nombre" name="name" required />
            <Select label="Tipo" name="type" options={meta.enums.service_types.map((t) => ({ value: t, label: t }))} />
            <Select label="Unidad" name="unit" options={meta.enums.units.map((t) => ({ value: t, label: t }))} />
            <Input label="Costo" name="cost" type="number" step="0.01" min={0} required />
            <Select label="Moneda" name="currency" options={[{ value: "USD", label: "USD" }, { value: "PEN", label: "PEN" }]} />
            <Input label="Vigente desde" name="valid_from" type="date" defaultValue={today()} required />
            <Input label="Vigente hasta" name="valid_to" type="date" defaultValue={addDays(today(), 365)} required />
          </ActionForm>
        </Card>
      )}
    </>
  );
}
