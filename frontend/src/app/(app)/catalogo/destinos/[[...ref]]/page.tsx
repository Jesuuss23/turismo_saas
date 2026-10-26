import Link from "@/components/Link";
import { api } from "@/lib/api";
import { catalogMeta } from "@/lib/catalog-server";
import { price, type Destination, type ProductRow } from "@/lib/catalog";
import { Card, Input, PageHeader, Select, TextArea } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { Field, Status } from "@/components/catalog/ui";

type Detail = Destination & { path: string; ancestors: { id: string; name: string; type: string }[]; children: Destination[]; products: ProductRow[] };

/** Árbol jerárquico de destinos + ficha del nodo + productos (incluye subdestinos). */
export default async function DestinationsPage({ params }: { params: Promise<{ ref?: string[] }> }) {
  const { ref } = await params;
  const [meta, list] = await Promise.all([catalogMeta(), api<{ data: Destination[] }>("/catalog/destinations")]);
  const selected = ref?.[0] ?? list.data[0]?.ref;
  const detail = selected ? (await api<{ data: Detail }>(`/catalog/destinations/${encodeURIComponent(selected)}`)).data : null;
  const can = meta.can.update;
  const types = meta.enums.destination_types.map((t) => ({ value: t, label: t }));

  return (
    <>
      <PageHeader title="Destinos" subtitle="País › Región › Departamento › Provincia › Ciudad › Atracción" />
      <div className="grid gap-4 lg:grid-cols-[300px_1fr]">
        <nav className="rounded-lg border border-slate-200 bg-white p-3 text-sm" aria-label="Árbol de destinos">
          <ul className="space-y-0.5">
            {list.data.map((d) => (
              <li key={d.id} style={{ paddingLeft: `${d.level * 10}px` }}>
                <Link href={`/catalogo/destinos/${d.ref}`} aria-current={d.ref === selected ? "page" : undefined}
                  className={`block rounded px-2 py-1 ${d.ref === selected ? "bg-sky-50 font-medium text-sky-900" : "hover:bg-slate-50"}`}>
                  {d.name} <span className="text-xs text-slate-500">{d.type} · {d.products_tree}</span>
                </Link>
              </li>
            ))}
          </ul>
        </nav>
        <div className="space-y-4">
          {detail && (
            <Card title={`${detail.ref} · ${detail.name}`}>
              <p className="mb-3 text-sm text-slate-600">{detail.path}</p>
              <dl className="grid gap-3 sm:grid-cols-4">
                <Field label="Tipo">{detail.type}</Field>
                <Field label="Coordenadas">{detail.lat !== null ? `${detail.lat}, ${detail.lng}` : "—"}</Field>
                <Field label="Subdestinos">{detail.children.length}</Field>
                <Field label="Productos (con subdestinos)">{detail.products.length}</Field>
              </dl>
              {detail.description && <p className="mt-3 text-sm">{detail.description}</p>}
            </Card>
          )}
          {detail && (
            <Card title="Productos">
              <ul className="space-y-1 text-sm">
                {detail.products.map((p) => (
                  <li key={p.id} className="flex justify-between gap-2">
                    <Link href={`/catalogo/productos/${p.ref}`} className="text-sky-800 hover:underline">{p.ref} · {p.name}</Link>
                    <span className="flex items-center gap-2">{p.from_price && price(p.from_price.price, p.from_price.currency)} <Status value={p.status} label={p.status_label} /></span>
                  </li>
                ))}
                {!detail.products.length && <li className="text-slate-500">Sin productos.</li>}
              </ul>
            </Card>
          )}
          {can && detail && (
            <div className="grid gap-4 lg:grid-cols-2">
              <Card title="Editar destino">
                <ActionForm path={`/catalog/destinations/${detail.id}`} method="PATCH" submitLabel="Guardar" className="space-y-2">
                  <Input label="Nombre" name="name" defaultValue={detail.name} />
                  <Select label="Tipo" name="type" defaultValue={detail.type} options={types} />
                  <Select label="Padre" name="parent_id" defaultValue={detail.parent_id ?? ""} placeholder="(raíz)" options={list.data.filter((d) => d.id !== detail.id).map((d) => ({ value: d.id, label: `${"· ".repeat(d.level)}${d.name}` }))} />
                  <TextArea label="Descripción" name="description" defaultValue={detail.description ?? ""} />
                </ActionForm>
                <div className="mt-3">
                  <ActionForm path={`/catalog/destinations/${detail.id}`} method="DELETE" submitLabel="Eliminar destino" redirectTo="/catalogo/destinos" confirm={`¿Eliminar ${detail.name}?`}
                    submitClass="rounded-md border border-rose-300 bg-white px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50" />
                </div>
              </Card>
              <Card title={`Nuevo subdestino de ${detail.name}`}>
                <ActionForm path="/catalog/destinations" submitLabel="Crear" className="space-y-2">
                  <input type="hidden" name="parent_id" value={detail.id} />
                  <Input label="Nombre" name="name" required />
                  <Select label="Tipo" name="type" options={types} defaultValue="Ciudad" />
                  <div className="grid grid-cols-2 gap-2"><Input label="Latitud" name="lat" type="number" step="any" /><Input label="Longitud" name="lng" type="number" step="any" /></div>
                </ActionForm>
              </Card>
            </div>
          )}
        </div>
      </div>
    </>
  );
}
