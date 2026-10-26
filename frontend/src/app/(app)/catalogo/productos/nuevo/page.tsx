import Link from "@/components/Link";
import { catalogMeta } from "@/lib/catalog-server";
import { Notice, PageHeader } from "@/components/crm/ui";
import { Wizard } from "./Wizard";

export default async function NewProductPage() {
  const meta = await catalogMeta();

  return (
    <>
      <PageHeader title="Nuevo producto" subtitle="Asistente PROC-014 · el producto se crea en borrador" actions={<Link href="/catalogo/productos" className="text-sm text-slate-600 hover:underline">Volver al catálogo</Link>} />
      {meta.can.create ? <Wizard meta={meta} /> : <Notice tone="danger">Su rol no tiene el permiso “Crear productos” (catalog.products.create · 403).</Notice>}
    </>
  );
}
