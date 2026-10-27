import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import { catalogMeta } from "@/lib/catalog-server";
import { addDays, today, type Provider } from "@/lib/catalog";
import { btnGhostCls, Card, Input, inputCls, Kpis, PageHeader, Select, Table, Td } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { Status } from "@/components/catalog/ui";

type Search = { type?: string; status?: string; q?: string };
type ProviderKpis = { active: number; expiring_95: number; expired_or_inactive: number; open_payables?: number };

export default async function ProvidersPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const [meta, res] = await Promise.all([
    catalogMeta(),
    api<{ data: Provider[]; meta: { kpis: ProviderKpis } }>(`/catalog/providers${qs({ "filter[type]": sp.type, "filter[status]": sp.status, "filter[q]": sp.q })}`),
  ]);
  const k = res.meta.kpis;

  return (
    <>
      <PageHeader title="Proveedores" subtitle="Ciclo PROC-012: crear → contrato → activar → utilizar → desactivar"
        actions={<Link href="/catalogo/proveedores/solicitudes-tarifa" className={btnGhostCls}>Solicitudes de tarifa</Link>} />
      <Kpis items={[
        { label: "Activos", value: k.active },
        { label: "Contratos por vencer (≤95 días)", value: k.expiring_95 },
        { label: "Vencidos o inactivos", value: k.expired_or_inactive },
        ...(k.open_payables !== undefined ? [{ label: "CxP abiertas", value: k.open_payables }] : []),
      ]} />
      <form className="mb-3 grid gap-2 sm:grid-cols-4" role="search">
        <input name="q" defaultValue={sp.q} placeholder="Nombre, RUC, ciudad…" className={inputCls} aria-label="Buscar" />
        <select name="type" defaultValue={sp.type ?? ""} className={inputCls} aria-label="Tipo"><option value="">Todos los tipos</option>{meta.enums.provider_types.map((t) => <option key={t}>{t}</option>)}</select>
        <select name="status" defaultValue={sp.status ?? ""} className={inputCls} aria-label="Estado"><option value="">Todos los estados</option>{["activo", "inactivo", "vencido"].map((t) => <option key={t}>{t}</option>)}</select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Proveedor", "Tipo", "RUC", "Ciudad", "Contrato", "Servicios", "Calificación", "Estado"]} empty="Sin proveedores">
        {res.data.map((p) => (
          <tr key={p.id} className="hover:bg-slate-50">
            <Td><Link href={`/catalogo/proveedores/${p.ref}`} className="font-medium text-sky-800 hover:underline">{p.ref}</Link><br />{p.name}</Td>
            <Td>{p.type}{p.stars ? ` ${"★".repeat(p.stars)}` : ""}</Td><Td className="font-mono text-xs">{p.ruc}</Td><Td>{p.city ?? "—"}</Td>
            <Td className="whitespace-nowrap text-xs">{date(p.contract_start)} → {date(p.contract_end)}{p.contract_expiring && <span className="block text-amber-700">por vencer</span>}</Td>
            <Td>{typeof p.services === "number" ? p.services : 0}</Td><Td>{p.rating ?? "—"}</Td>
            <Td><Status value={p.effective_status} /></Td>
          </tr>
        ))}
      </Table>
      {meta.can.providers && (
        <Card title="Nuevo proveedor (nace inactivo)" className="mt-4">
          <ActionForm path="/catalog/providers" submitLabel="Crear proveedor" redirectTo="/catalogo/proveedores/{data.ref}" className="grid gap-2 sm:grid-cols-4">
            <Input label="Razón social" name="name" required />
            <Select label="Tipo" name="type" options={meta.enums.provider_types.map((t) => ({ value: t, label: t }))} />
            <Input label="RUC" name="ruc" pattern="\d{11}" required />
            <Input label="Ciudad" name="city" />
            <Input label="Contacto" name="contact_name" required />
            <Input label="Correo" name="email" type="email" />
            <Input label="Inicio de contrato" name="contract_start" type="date" defaultValue={today()} required />
            <Input label="Fin de contrato" name="contract_end" type="date" defaultValue={addDays(today(), 365)} required />
            <Select label="Moneda" name="currency" options={[{ value: "USD", label: "USD" }, { value: "PEN", label: "PEN" }]} />
            <Input label="Condiciones de pago" name="payment_terms" />
          </ActionForm>
        </Card>
      )}
    </>
  );
}
