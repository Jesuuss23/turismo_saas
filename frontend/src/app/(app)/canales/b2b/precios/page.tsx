import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { money } from "@/lib/finance";
import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, Input, PageHeader, Select, Table, Td, btnGhostCls } from "@/components/crm/ui";
import { ChStatus } from "@/components/channels/ui";
import { RowAction, withParams } from "@/components/supplier/ui";
import { B2bTabs } from "../nav";

type Assignment = { id: string; agency_id: string; agency: string; agency_code: string | null; price_list: string | null; price: string; currency: string; commission_pct: string; is_credit: boolean; valid_to: string | null; expired: boolean };
type Row = { id: string; ref: string; name: string; status: string; is_b2b: boolean; public_price: string | null; public_currency: string | null; general_net: string | null; general_currency: string | null; assignments: Assignment[] };
type Meta = { agencies: { id: string; code: string; name: string; status: string }[] };
type Sp = { producto?: string; agencia?: string };

/** Tarifas por agencia (spec 08 §2 `precios`): neto B2B por producto y agencia, con la misma regla que Catálogo (B2B-BR-10). */
export default async function B2bRatesPage({ searchParams }: { searchParams: Promise<Sp> }) {
  const sp = await searchParams;
  const { data: rows, meta } = await api<{ data: Row[]; meta: Meta }>("/channels/b2b/product-rates");
  const base = "/canales/b2b/precios";
  const product = sp.producto ? rows.find((r) => r.ref === sp.producto) ?? null : null;
  const current = product && sp.agencia ? product.assignments.find((a) => a.agency_code === sp.agencia) ?? null : null;
  const target = sp.agencia ? meta.agencies.find((a) => a.code === sp.agencia) ?? null : null;

  return (
    <>
      <PageHeader title="Canal B2B" subtitle="Tarifa neta por producto y agencia. El neto no puede superar el precio público ni quedar bajo el costo." />
      <B2bTabs active="precios" />
      <Table head={["Producto", "Precio público", "Neto general", "Agencias con tarifa", ""]} empty="No hay productos habilitados para B2B">
        {rows.map((p) => (
          <tr key={p.id}>
            <Td><Link href={`/catalogo/productos/${p.ref}`} className="font-medium text-sky-800 hover:underline">{p.name}</Link><span className="block text-xs text-slate-500">{p.ref}{!p.is_b2b && " · canal B2B no publicado"}</span></Td>
            <Td className="whitespace-nowrap">{p.public_price ? money(p.public_price, p.public_currency) : "—"}</Td>
            <Td className="whitespace-nowrap">{p.general_net ? money(p.general_net, p.general_currency) : "—"}</Td>
            <Td>
              {p.assignments.length === 0 ? <span className="text-slate-500">Ninguna</span> : (
                <ul className="space-y-1 text-xs">
                  {p.assignments.map((a) => (
                    <li key={a.id}>
                      <Link href={withParams(base, { producto: p.ref, agencia: a.agency_code ?? "" })} scroll={false} className="text-sky-800 hover:underline">{a.agency}</Link>
                      {" "}· {money(a.price, a.currency)} · {a.commission_pct} % · {a.is_credit ? "crédito" : "prepago"} · hasta {date(a.valid_to)}
                      {a.expired && <span className="ml-1"><ChStatus value="vencida" label="Vencida" /></span>}
                    </li>
                  ))}
                </ul>
              )}
            </Td>
            <Td><RowAction href={withParams(base, { producto: p.ref })}>Asignar tarifa</RowAction></Td>
          </tr>
        ))}
      </Table>

      {product && (
        <Drawer title={`${current ? "Editar" : "Asignar"} tarifa · ${product.name}`} close={base}>
          <div className="space-y-4">
            <p className="text-sm text-slate-600">Precio público {product.public_price ? money(product.public_price, product.public_currency) : "sin tarifa pública vigente"}.</p>
            {!target ? (
              <form action={base} className="space-y-3">
                <input type="hidden" name="producto" value={product.ref} />
                <Select name="agencia" label="Agencia" required placeholder="Elegir…"
                  options={meta.agencies.filter((a) => !product.assignments.some((x) => x.agency_id === a.id)).map((a) => ({ value: a.code, label: `${a.name} (${a.status})` }))} />
                <button className={btnGhostCls}>Continuar</button>
              </form>
            ) : (
            <ActionForm path={`/channels/b2b/product-rates/${product.ref}/agencies/${target.code}`} method="PUT" submitLabel="Guardar tarifa" redirectTo={base} success="Tarifa guardada.">
              <p className="text-sm">Agencia: <span className="font-medium">{target.name}</span></p>
              <div className="grid grid-cols-2 gap-3">
                <Input name="price" type="number" step="0.01" min="0.01" label="Neto por pasajero" required defaultValue={current?.price} />
                <Select name="currency" label="Moneda" options={[{ value: "USD", label: "USD" }, { value: "PEN", label: "PEN" }]} defaultValue={current?.currency ?? product.public_currency ?? "USD"} />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <Input name="commission_pct" type="number" step="0.01" min="0" max="30" label="Comisión (%)" defaultValue={current?.commission_pct} placeholder="La de la agencia" />
                <Input name="valid_to" type="date" label="Vigente hasta" required defaultValue={current?.valid_to ?? ""} />
              </div>
              <Select name="is_credit" label="Forma de pago" options={[{ value: "true", label: "Admite crédito" }, { value: "false", label: "Solo prepago" }]} defaultValue={current ? String(current.is_credit) : "true"} />
            </ActionForm>
            )}
            {current && (
              <ActionForm path={`/channels/b2b/product-rates/${product.ref}/agencies/${current.agency_code}`} method="DELETE" submitLabel="Quitar tarifa" redirectTo={base}
                confirm="La agencia dejará de ver este producto como reservable." submitClass="rounded-md border border-rose-300 bg-white px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50" />
            )}
          </div>
        </Drawer>
      )}
    </>
  );
}
