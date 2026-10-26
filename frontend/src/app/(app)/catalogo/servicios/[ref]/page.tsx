import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { catalogMeta } from "@/lib/catalog-server";
import { price, type AuditRow, type Service } from "@/lib/catalog";
import { btnGhostCls, Card, Input, Notice, PageHeader, Stepper, Timeline } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { Field, Status } from "@/components/catalog/ui";

type Detail = Service & { days_to_expiry: number; expiry_alert: boolean; products: { id: string; ref: string; name: string; status: string }[]; history: AuditRow[] | null };
const CHAIN = ["Proveedor", "Servicio", "Vigencia", "Costo", "Producto"];

export default async function ServicePage({ params }: { params: Promise<{ ref: string }> }) {
  const { ref } = await params;
  const [meta, { data: s }] = await Promise.all([catalogMeta(), api<{ data: Detail }>(`/catalog/services/${encodeURIComponent(ref)}`)]);
  const can = meta.can.providers;
  const step = s.provider?.status !== "activo" ? 1 : s.effective_status === "vencido" ? 3 : s.products.length ? 5 : 4;

  return (
    <>
      <PageHeader title={`${s.ref} · ${s.name}`} subtitle={<span className="flex items-center gap-2"><Status value={s.effective_status} /> {s.type} · por {s.unit}</span>}
        actions={<Link href="/catalogo/servicios" className={btnGhostCls}>Volver</Link>} />
      {s.expiry_alert && <Notice tone="warning">La vigencia vence en {s.days_to_expiry} día(s).</Notice>}
      <div className="mb-4"><Stepper steps={CHAIN} current={step} failed={step < 4} /></div>
      <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
        <div className="space-y-4">
          <Card title="Ficha">
            <dl className="grid gap-3 sm:grid-cols-3">
              <Field label="Proveedor">{s.provider && <Link href={`/catalogo/proveedores/${s.provider.ref}`} className="text-sky-800 hover:underline">{s.provider.name}</Link>}</Field>
              {s.cost !== undefined && <Field label="Costo">{price(s.cost, s.currency)}</Field>}
              <Field label="Capacidad">{s.capacity ?? "—"}</Field>
              <Field label="Vigencia">{date(s.valid_from)} → {date(s.valid_to)}</Field>
            </dl>
          </Card>
          <Card title={`Productos que lo usan (${s.products.length})`}>
            <ul className="space-y-1 text-sm">
              {s.products.map((p) => <li key={p.id}><Link href={`/catalogo/productos/${p.ref}/componentes`} className="text-sky-800 hover:underline">{p.ref} · {p.name}</Link> <Status value={p.status} /></li>)}
              {!s.products.length && <li className="text-slate-500">Ningún producto lo usa.</li>}
            </ul>
          </Card>
          {s.history && <Card title="Historial"><Timeline rows={s.history.map((r) => ({ ...r, actor: r.actor ? { name: r.actor.name } : null }))} /></Card>}
        </div>
        {can && (
          <div className="space-y-4">
            <Card title="Editar">
              <ActionForm path={`/catalog/services/${s.id}`} method="PATCH" ifMatch={s.lock_version} submitLabel="Guardar" className="space-y-2">
                <Input label="Nombre" name="name" defaultValue={s.name} />
                <Input label="Costo" name="cost" type="number" step="0.01" min={0} defaultValue={s.cost} />
                <Input label="Vigente hasta" name="valid_to" type="date" defaultValue={s.valid_to} />
              </ActionForm>
            </Card>
            <Card title="Estado">
              {s.status === "activo"
                ? <ActionForm path={`/catalog/services/${s.id}/deactivate`} submitLabel="Desactivar" submitClass={btnGhostCls}><Input label="Motivo" name="reason" required /></ActionForm>
                : <ActionForm path={`/catalog/services/${s.id}/activate`} submitLabel="Activar" />}
            </Card>
          </div>
        )}
      </div>
    </>
  );
}
