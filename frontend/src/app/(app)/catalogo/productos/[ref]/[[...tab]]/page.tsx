import Link from "@/components/Link";
import { catalogMeta, product as loadProduct } from "@/lib/catalog-server";
import { PRODUCT_FLOW, PRODUCT_STATUS_LABEL, destinationPath, price } from "@/lib/catalog";
import { btnGhostCls, Kpis, Notice, PageHeader } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { PathTabs, Status } from "@/components/catalog/ui";
import { TabContent } from "./tabs";

const SLUG: Record<string, string> = { costos: "rentabilidad" };

export default async function Product360({ params, searchParams }: { params: Promise<{ ref: string; tab?: string[] }>; searchParams: Promise<Record<string, string | undefined>> }) {
  const [{ ref, tab }, search] = await Promise.all([params, searchParams]);
  const [p, meta] = await Promise.all([loadProduct(ref), catalogMeta()]);
  const requested = SLUG[tab?.[0] ?? ""] ?? tab?.[0] ?? "general";
  const hiddenTab = !(requested in p.tabs);
  const active = hiddenTab ? "general" : requested;
  const a = p.actions;
  const lock = p.lock_version;
  const base = `/catalog/products/${p.ref}`;
  const flowIndex = PRODUCT_FLOW.indexOf(p.status as (typeof PRODUCT_FLOW)[number]);
  const reasonField = <input name="reason" required placeholder="Motivo" aria-label="Motivo" className="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />;

  return (
    <>
      <PageHeader
        title={`${p.ref} · ${p.name}`}
        subtitle={<span className="flex flex-wrap items-center gap-2">
          <Status value={p.status} label={p.status_label} />
          <span className="font-mono text-xs">{p.sku}</span>
          <span>{p.type} · {p.category}{p.subcategory ? ` › ${p.subcategory}` : ""}</span>
          <span>· {destinationPath(meta.destinations, p.primary_destination_id)}</span>
          {p.current_version && <span>· versión {p.current_version.version} ({p.current_version.status})</span>}
        </span>}
        actions={<Link href="/catalogo/productos" className={btnGhostCls}>Volver</Link>}
      />

      {hiddenTab && tab?.[0] && <Notice tone="warning">La pestaña solicitada no está disponible para su rol.</Notice>}
      {!a.update && !a.rates && !a.availability && (
        <Notice>{a.see_margin ? "Finanzas · costos y rentabilidad en solo lectura." : "Modo consulta · Ventas · consulta de oferta y tarifas de venta."}</Notice>
      )}

      <section className="mb-4 rounded-lg border border-slate-200 bg-white p-4">
        <ol className="mb-3 flex flex-wrap items-center gap-2 text-xs" aria-label="Ciclo de vida">
          {PRODUCT_FLOW.map((s, i) => (
            <li key={s} className={`rounded-full px-3 py-1 ${flowIndex < 0 ? "bg-slate-100 text-slate-500" : i < flowIndex ? "bg-emerald-600 text-white" : i === flowIndex ? "bg-sky-700 text-white" : "bg-slate-100 text-slate-500"}`}>
              {PRODUCT_STATUS_LABEL[s]}
            </li>
          ))}
          {flowIndex < 0 && <li><Status value={p.status} label={p.status_label} /></li>}
          <li className="ml-auto text-slate-600">Checklist de activación {p.checklist_summary.ok}/{p.checklist_summary.total}</li>
        </ol>
        <div className="flex flex-wrap items-start gap-2">
          {a.update && p.status === "DRAFT" && <ActionForm path={`${base}/submit-review`} ifMatch={lock} submitLabel="Enviar a revisión" success="Enviado a revisión" inline />}
          {a.activate && p.status === "REVIEW" && <ActionForm path={`${base}/approve`} ifMatch={lock} submitLabel="Aprobar" success="Aprobado" inline />}
          {a.activate && p.status === "REVIEW" && <ActionForm path={`${base}/return-to-draft`} ifMatch={lock} submitLabel="Devolver a borrador" submitClass={btnGhostCls} inline>{reasonField}</ActionForm>}
          {a.activate && ["DRAFT", "REVIEW", "APPROVED", "SUSPENDED", "INACTIVE"].includes(p.status) && (
            <ActionForm path={`${base}/activate`} ifMatch={lock} submitLabel="Activar" success="Producto activo" inline />
          )}
          {a.activate && p.status === "ACTIVE" && <ActionForm path={`${base}/suspend`} ifMatch={lock} submitLabel="Suspender" submitClass={btnGhostCls} inline>{reasonField}</ActionForm>}
          {a.activate && ["ACTIVE", "SUSPENDED"].includes(p.status) && <ActionForm path={`${base}/deactivate`} ifMatch={lock} submitLabel="Inactivar" submitClass={btnGhostCls} inline>{reasonField}</ActionForm>}
          {a.activate && p.status !== "ARCHIVED" && <ActionForm path={`${base}/archive`} ifMatch={lock} submitLabel="Archivar" submitClass={btnGhostCls} confirm="¿Archivar el producto?" inline>{reasonField}</ActionForm>}
          {a.create && <ActionForm path={`${base}/duplicate`} idempotent submitLabel="Duplicar" submitClass={btnGhostCls} redirectTo="/catalogo/productos/{data.ref}" inline />}
          {a.activate && (
            <ActionForm path={base} method="DELETE" submitLabel="Eliminar" submitClass="rounded-md border border-rose-300 bg-white px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50" confirm="¿Eliminar el producto? Solo es posible si nadie lo referencia." redirectTo="/catalogo/productos" inline>
              {reasonField}
            </ActionForm>
          )}
          <Link href={`/catalogo/productos/${p.ref}`} className={btnGhostCls}>Validar checklist</Link>
        </div>
      </section>

      <Kpis items={[
        { label: "Tarifas vigentes", value: p.indicators.rates_current, hint: p.from_price ? `desde ${price(p.from_price.price, p.from_price.currency)}` : undefined },
        { label: "Componentes", value: p.indicators.components },
        { label: "Salidas futuras", value: p.indicators.future_departures, hint: `${p.indicators.seats_available} cupos disponibles` },
        { label: "Canales publicados", value: p.indicators.channels_published, hint: `${p.indicators.versions} versión(es)` },
      ]} />

      <PathTabs base={`/catalogo/productos/${p.ref}`} tabs={Object.entries(p.tabs).map(([key, label]) => ({ key, label }))} active={active} />
      <TabContent tab={active} product={p} meta={meta} search={search} />
    </>
  );
}
