import Link from "@/components/Link";
import { api } from "@/lib/api";
import { qs } from "@/lib/crm";
import { catalogMeta } from "@/lib/catalog-server";
import { destinationPath, pct, price, PRODUCT_STATUS_LABEL, type ProductRow } from "@/lib/catalog";
import { btnCls, btnGhostCls, inputCls, Kpis, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { ExportButton } from "@/components/crm/ExportButton";
import { Status } from "@/components/catalog/ui";
import { ViewToggle } from "./ViewToggle";

type Search = { q?: string; type?: string; category?: string; status?: string; destination?: string; channel?: string; view?: string };
type ProductKpis = { active: number; in_progress: number; with_future_departures: number; total: number; avg_margin_pct?: number; bookable_today?: number };

export default async function ProductsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const view = sp.view === "tabla" ? "tabla" : "tarjetas";
  const filters = { "filter[q]": sp.q, "filter[type]": sp.type, "filter[category]": sp.category, "filter[status]": sp.status, "filter[destination]": sp.destination, "filter[channel]": sp.channel };
  const [meta, list, kpis] = await Promise.all([
    catalogMeta(),
    api<{ data: ProductRow[]; meta: { total: number } }>(`/catalog/products${qs({ ...filters, per_page: "200" })}`),
    api<{ data: ProductKpis }>("/catalog/products/kpis"),
  ]);
  const k = kpis.data;
  const can = meta.can;

  return (
    <>
      <PageHeader
        title="Productos"
        subtitle={`${list.meta.total} producto(s) en el catálogo`}
        actions={<>
          <ViewToggle view={view} />
          {can.update && <Link href="/catalogo/categorias" className={btnGhostCls}>Tipos y categorías</Link>}
          {view === "tabla" && <ExportButton resource="catalog.products" filters={{ q: sp.q, type: sp.type, category: sp.category, status: sp.status, destination: sp.destination, channel: sp.channel }} />}
          {can.create && <Link href="/catalogo/productos/nuevo" className={btnCls}>Nuevo producto</Link>}
        </>}
      />
      {!can.update && !can.rates && !can.availability && (
        <Notice>{can.see_margin ? "Finanzas · costos y rentabilidad en solo lectura." : "Modo consulta · Ventas · consulta de oferta y tarifas de venta."}</Notice>
      )}
      <Kpis items={[
        { label: "Activos", value: k.active, hint: `de ${k.total} productos` },
        { label: "En revisión y borradores", value: k.in_progress },
        { label: "Con salidas futuras", value: k.with_future_departures },
        k.avg_margin_pct !== undefined
          ? { label: "Margen promedio", value: pct(k.avg_margin_pct), hint: "tarifa “desde” de productos activos" }
          : { label: "Reservables hoy", value: k.bookable_today ?? 0 },
      ]} />

      <form className="mb-4 grid gap-2 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-6" role="search">
        <input type="hidden" name="view" value={view} />
        <input name="q" defaultValue={sp.q} placeholder="Nombre, código, SKU o destino…" className={`${inputCls} sm:col-span-2`} aria-label="Buscar" />
        <select name="type" defaultValue={sp.type ?? ""} className={inputCls} aria-label="Tipo">
          <option value="">Todos los tipos</option>
          {meta.types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
        </select>
        <select name="category" defaultValue={sp.category ?? ""} className={inputCls} aria-label="Categoría">
          <option value="">Todas las categorías</option>
          {meta.categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        <select name="status" defaultValue={sp.status ?? ""} className={inputCls} aria-label="Estado">
          <option value="">Todos los estados</option>
          {Object.entries(PRODUCT_STATUS_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select name="destination" defaultValue={sp.destination ?? ""} className={inputCls} aria-label="Destino">
          <option value="">Todos los destinos</option>
          {meta.destinations.map((d) => <option key={d.id} value={d.id}>{"  ".repeat(d.level)}{d.name}</option>)}
        </select>
        <select name="channel" defaultValue={sp.channel ?? ""} className={inputCls} aria-label="Canal">
          <option value="">Todos los canales</option>
          {Object.entries(meta.enums.channels).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <div className="flex gap-2 sm:col-span-5">
          <button className={btnGhostCls}>Filtrar</button>
          <Link href={`/catalogo/productos?view=${view}`} className="self-center text-sm text-slate-600 hover:underline">Limpiar</Link>
        </div>
      </form>

      {view === "tabla" ? (
        <Table head={["Producto", "Tipo / categoría", "Destino", "Duración", "Desde", ...(can.see_margin ? ["Margen"] : []), "Salidas", "Estado"]} empty="Sin productos para estos filtros">
          {list.data.map((p) => (
            <tr key={p.id} className="hover:bg-slate-50">
              <Td><Link href={`/catalogo/productos/${p.ref}`} className="font-medium text-sky-800 hover:underline">{p.ref}</Link> <span className="text-xs text-slate-500">{p.sku}</span><br />{p.name}</Td>
              <Td>{p.type}<br /><span className="text-xs text-slate-500">{p.category}{p.subcategory ? ` · ${p.subcategory}` : ""}</span></Td>
              <Td className="text-slate-600">{destinationPath(meta.destinations, p.primary_destination?.id) || "—"}</Td>
              <Td>{p.duration.value} {p.duration.unit}<br /><span className="text-xs text-slate-500">{p.modality}</span></Td>
              <Td className="whitespace-nowrap">{p.from_price ? price(p.from_price.price, p.from_price.currency) : "—"}</Td>
              {can.see_margin && <Td>{pct(p.margin_pct)}</Td>}
              <Td>{p.future_departures ?? 0}</Td>
              <Td><Status value={p.status} label={p.status_label} /></Td>
            </tr>
          ))}
        </Table>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {list.data.length === 0 && <p className="text-sm text-slate-500">Sin productos para estos filtros.</p>}
          {list.data.map((p) => (
            <Link key={p.id} href={`/catalogo/productos/${p.ref}`} className="flex flex-col rounded-lg border border-slate-200 bg-white p-4 hover:border-sky-300 hover:shadow-sm">
              <div className="flex items-start justify-between gap-2">
                <span className="font-mono text-xs text-slate-500">{p.ref} · {p.sku}</span>
                <Status value={p.status} label={p.status_label} />
              </div>
              <h2 className="mt-2 font-semibold text-slate-900">{p.name}</h2>
              <p className="text-sm text-slate-600">{p.type} · {p.category}</p>
              <p className="mt-1 text-xs text-slate-500">{destinationPath(meta.destinations, p.primary_destination?.id)}</p>
              <div className="mt-auto flex items-end justify-between pt-4 text-sm">
                <span className="text-slate-600">{p.duration.value} {p.duration.unit} · {p.modality}</span>
                <span className="text-right">
                  {p.from_price && <><span className="block text-xs text-slate-500">desde</span><span className="font-semibold text-slate-900">{price(p.from_price.price, p.from_price.currency)}</span></>}
                </span>
              </div>
              <div className="mt-2 flex gap-3 text-xs text-slate-500">
                <span>{p.future_departures ?? 0} salida(s)</span>
                <span>{p.channels_count ?? 0} canal(es)</span>
                {can.see_margin && p.margin_pct && <span>margen {pct(p.margin_pct)}</span>}
              </div>
            </Link>
          ))}
        </div>
      )}
    </>
  );
}
