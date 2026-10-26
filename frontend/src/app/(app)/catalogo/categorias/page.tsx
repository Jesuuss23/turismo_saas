import { api } from "@/lib/api";
import { Card, Input, PageHeader, Table, Td } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { Check } from "@/components/catalog/ui";

type Taxonomy = {
  types: { id: string; name: string; requires_itinerary: boolean; requires_components: boolean; products: number }[];
  categories: { id: string; ref: string; name: string; products: number; subcategories: { id: string; name: string; products: number }[] }[];
};
const danger = "rounded-md border border-rose-300 bg-white px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50 disabled:opacity-40";

/** Tipos de producto y categorías (CAT-BR-36: no se elimina lo que está en uso). */
export default async function TaxonomyPage() {
  const { data } = await api<{ data: Taxonomy }>("/catalog/taxonomy");

  return (
    <>
      <PageHeader title="Tipos y categorías" subtitle="Clasificación de productos del catálogo" />
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="space-y-4">
          <Table head={["Tipo", "Exige", "Productos", ""]}>
            {data.types.map((t) => (
              <tr key={t.id}>
                <Td className="font-medium">{t.name}</Td>
                <Td className="text-xs">{[t.requires_components && "componentes", t.requires_itinerary && "itinerario"].filter(Boolean).join(", ") || "—"}</Td>
                <Td>{t.products}</Td>
                <Td><ActionForm path={`/catalog/product-types/${t.id}`} method="DELETE" submitLabel="Eliminar" submitClass={danger} disabled={t.products > 0} disabledReason="En uso por productos (CAT-BR-36)" confirm={`¿Eliminar el tipo ${t.name}?`} inline /></Td>
              </tr>
            ))}
          </Table>
          <Card title="Nuevo tipo">
            <ActionForm path="/catalog/product-types" submitLabel="Crear tipo" className="space-y-2">
              <Input label="Nombre" name="name" required />
              <Check name="requires_components" label="Exige componentes" />
              <Check name="requires_itinerary" label="Exige itinerario" />
            </ActionForm>
          </Card>
        </div>
        <div className="space-y-4">
          {data.categories.map((c) => (
            <Card key={c.id} title={`${c.ref} · ${c.name} (${c.products} producto(s))`}
              actions={<ActionForm path={`/catalog/categories/${c.id}`} method="DELETE" submitLabel="Eliminar" submitClass={danger} disabled={c.products > 0} disabledReason="En uso por productos (CAT-BR-36)" confirm={`¿Eliminar la categoría ${c.name}?`} inline />}>
              <ul className="space-y-1 text-sm">
                {c.subcategories.map((s) => (
                  <li key={s.id} className="flex items-center justify-between gap-2">
                    <span>{s.name} <span className="text-xs text-slate-500">({s.products})</span></span>
                    <ActionForm path={`/catalog/categories/${c.id}/subcategories/${s.id}`} method="DELETE" submitLabel="Quitar" submitClass={danger} disabled={s.products > 0} disabledReason="En uso (CAT-BR-36)" inline />
                  </li>
                ))}
              </ul>
              <ActionForm path={`/catalog/categories/${c.id}/subcategories`} submitLabel="Agregar" className="mt-2 flex items-end gap-2">
                <Input label="Nueva subcategoría" name="name" required />
              </ActionForm>
            </Card>
          ))}
          <Card title="Nueva categoría">
            <ActionForm path="/catalog/categories" submitLabel="Crear categoría" className="flex items-end gap-2">
              <Input label="Nombre" name="name" required />
            </ActionForm>
          </Card>
        </div>
      </div>
    </>
  );
}
