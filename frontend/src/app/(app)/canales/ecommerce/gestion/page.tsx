import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { money } from "@/lib/finance";
import type { EcomOrder, EcomProduct, Promotion } from "@/lib/channels";
import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, Input, Kpis, Notice, PageHeader, Table, Tabs, Td, TextArea, btnGhostCls, inputCls } from "@/components/crm/ui";
import { ChStatus, Dl } from "@/components/channels/ui";
import { RowAction, withParams } from "@/components/supplier/ui";

type Summary = { orders: number; confirmed: number; pending: number; revenue: string; conversion: number; rejections: number; funnel: { step: string; value: number; simulated: boolean }[] };
type OrderDetail = EcomOrder & {
  departure: { ref: string; time: string; meeting_point: string | null } | null; adults: number; children: number; cancel_reason: string | null;
  buyer: Record<string, string | null> | null; passengers: { name: string; type: string }[]; vouchers: { code: string; service: string; version: number }[];
  attempts: { at: string; card: string | null; result: string; message: string | null }[];
};
type Sp = { tab?: string; q?: string; status?: string; pedido?: string; accion?: string; promo?: string };

const TABS = [{ key: "pedidos", label: "Pedidos" }, { key: "productos", label: "Productos publicados" }, { key: "promociones", label: "Promociones" }];

/** Gestión del e-commerce (spec 08 §2 `gestion`): KPIs, embudo, pedidos web, publicación y promociones del canal. */
export default async function EcommerceAdminPage({ searchParams }: { searchParams: Promise<Sp> }) {
  const sp = await searchParams;
  const tab = TABS.some((t) => t.key === sp.tab) ? sp.tab! : "pedidos";
  const { data: s } = await api<{ data: Summary }>("/channels/ecommerce/summary");
  const max = Math.max(1, ...s.funnel.map((f) => f.value));

  return (
    <>
      <PageHeader title="E-commerce" subtitle="Ventas de la tienda web: pedidos, pagos, productos publicados y promociones del canal." />
      <Kpis items={[
        { label: "Ventas confirmadas", value: money(s.revenue, "PEN"), hint: `${s.confirmed} de ${s.orders} pedido(s), equivalente en PEN` },
        { label: "Pendientes de pago", value: s.pending },
        { label: "Conversión", value: `${s.conversion} %`, hint: "Pagados / sesiones (simuladas)" },
        { label: "Pagos rechazados", value: s.rejections, hint: "Pedidos con al menos un intento rechazado" },
      ]} />
      <section className="my-4 rounded-lg border border-slate-200 bg-white p-4" aria-label="Embudo de conversión">
        <h2 className="mb-2 text-sm font-semibold text-slate-900">Embudo</h2>
        <ul className="space-y-1 text-sm">
          {s.funnel.map((f) => (
            <li key={f.step} className="grid grid-cols-[10rem_1fr_4rem] items-center gap-2">
              <span className="text-slate-600">{f.step}</span>
              <span className="h-3 rounded bg-slate-100"><span className={`block h-full rounded ${f.simulated ? "bg-slate-300" : "bg-sky-600"}`} style={{ width: `${(f.value * 100) / max}%` }} /></span>
              <span className="text-right tabular-nums">{f.value}</span>
            </li>
          ))}
        </ul>
        <p className="mt-2 text-xs text-slate-500">Las etapas en gris son estimadas hasta integrar la analítica del sitio (spec 15).</p>
      </section>
      <Tabs base="/canales/ecommerce/gestion" tabs={TABS} active={tab} />
      {tab === "pedidos" && <Orders sp={sp} />}
      {tab === "productos" && <Products />}
      {tab === "promociones" && <Promotions sp={sp} />}
    </>
  );
}

async function Orders({ sp }: { sp: Sp }) {
  const qs = new URLSearchParams();
  if (sp.q) qs.set("q", sp.q);
  if (sp.status) qs.set("filter[status]", sp.status);
  const { data: rows, meta } = await api<{ data: EcomOrder[]; meta: { statuses: Record<string, string> } }>(`/channels/ecommerce/orders?${qs}`);
  const base = `/canales/ecommerce/gestion?${new URLSearchParams(Object.entries({ tab: "pedidos", q: sp.q, status: sp.status }).filter(([, v]) => v) as [string, string][])}`;
  const open = sp.pedido ? (await api<{ data: OrderDetail }>(`/channels/ecommerce/orders/${encodeURIComponent(sp.pedido)}`)).data : null;

  return (
    <>
      <form className="mb-3 flex flex-wrap gap-2" action="/canales/ecommerce/gestion">
        <input type="hidden" name="tab" value="pedidos" />
        <input name="q" defaultValue={sp.q} placeholder="Pedido, cliente o correo" aria-label="Buscar pedido" className={`${inputCls} max-w-xs`} />
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Todos los estados</option>
          {Object.entries(meta.statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Pedido", "Cliente", "Producto", "Fecha servicio", "Total", "Estado", "Reserva"]} empty="Sin pedidos web">
        {rows.map((o) => (
          <tr key={o.id}>
            <Td><Link href={withParams(base, { pedido: o.code })} scroll={false} className="font-medium text-sky-800 hover:underline">{o.code}</Link><span className="block text-xs text-slate-500">{dateTime(o.ordered_at)}</span></Td>
            <Td>{o.customer}<span className="block text-xs text-slate-500">{o.email}</span></Td>
            <Td>{o.product ?? "—"}</Td>
            <Td className="whitespace-nowrap">{date(o.service_date)} · {o.pax} pax</Td>
            <Td className="whitespace-nowrap">{money(o.total, o.currency)}{o.promo_code && <span className="block text-xs text-emerald-700">{o.promo_code} −{money(o.discount, o.currency)}</span>}</Td>
            <Td><ChStatus value={o.status} label={o.status_label} /></Td>
            <Td>{o.reservation ? <Link href={`/reservas/${o.reservation}`} className="text-sky-800 hover:underline">{o.reservation}</Link> : "—"}</Td>
          </tr>
        ))}
      </Table>
      {open && (
        <Drawer title={`Pedido ${open.code}`} close={base}>
          <div className="space-y-4">
            <ChStatus value={open.status} label={open.status_label} />
            {open.status === "pago_pendiente" && open.hold_expires_at && <Notice tone="warning">Cupos retenidos hasta {dateTime(open.hold_expires_at)}; luego el pedido expira.</Notice>}
            <Dl rows={[["Cliente", `${open.customer} · ${open.email}`], ["Teléfono", open.phone], ["País", open.country], ["Producto", open.product],
              ["Servicio", `${date(open.service_date)}${open.departure ? ` · salida ${open.departure.ref} ${open.departure.time}` : ""}`], ["Pasajeros", `${open.adults} adulto(s), ${open.children} niño(s)`],
              ["Total", money(open.total, open.currency)], ["Reserva", open.reservation], ["Motivo de anulación", open.cancel_reason]]} />
            {open.attempts.length > 0 && (
              <section>
                <h3 className="mb-1 text-sm font-semibold">Intentos de pago</h3>
                <ul className="text-sm">{open.attempts.map((a, i) => <li key={i}>{dateTime(a.at)} · {a.card ?? "—"} · <ChStatus value={a.result} label={a.result} /> {a.message}</li>)}</ul>
              </section>
            )}
            {open.vouchers.length > 0 && <p className="text-sm">Vouchers: {open.vouchers.map((v) => `${v.code} (${v.service})`).join(", ")}</p>}
            <div className="flex flex-wrap gap-2">
              {open.can_send_link && <ActionForm path={`/channels/ecommerce/orders/${open.code}/payment-link`} idempotent inline submitLabel="Reenviar enlace de pago" success={`Enlace enviado a ${open.email}.`} />}
              {open.can_cancel && sp.accion !== "anular" && <RowAction href={withParams(base, { pedido: open.code, accion: "anular" })}>Anular pedido</RowAction>}
            </div>
            {open.can_cancel && sp.accion === "anular" && (
              <ActionForm path={`/channels/ecommerce/orders/${open.code}/cancel`} ifMatch={open.lock_version} submitLabel="Anular pedido" success="Pedido anulado: se liberaron los cupos.">
                <TextArea name="reason" label="Motivo" required maxLength={300} />
              </ActionForm>
            )}
          </div>
        </Drawer>
      )}
    </>
  );
}

async function Products() {
  const { data: rows } = await api<{ data: EcomProduct[] }>("/channels/ecommerce/products");
  return (
    <Table head={["Producto", "Precio web", "Promoción", "Estado", ""]} empty="Sin productos activos">
      {rows.map((p) => (
        <tr key={p.id}>
          <Td><Link href={`/catalogo/productos/${p.ref}`} className="font-medium text-sky-800 hover:underline">{p.name}</Link><span className="block text-xs text-slate-500">{p.ref} · {p.destination ?? "—"}</span></Td>
          <Td className="whitespace-nowrap">{p.web_price ? money(p.web_price, p.currency) : "—"}{p.rate_channel && <span className="block text-xs text-slate-500">Tarifa {p.rate_channel}</span>}</Td>
          <Td>{p.promo ?? "—"}</Td>
          <Td>{p.published ? <ChStatus value="publicado" label="Publicado" /> : <ChStatus value="borrador" label="No publicado" />}{p.blocker && <span className="block text-xs text-amber-800">{p.blocker}</span>}</Td>
          <Td>
            {p.published ? <ActionForm path={`/channels/ecommerce/products/${p.ref}/unpublish`} inline submitLabel="Despublicar" submitClass="inline-flex rounded-md bg-white px-2 py-1 text-xs font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50" />
              : <ActionForm path={`/channels/ecommerce/products/${p.ref}/publish`} inline submitLabel="Publicar" disabled={!p.can_publish} disabledReason={p.blocker ?? undefined}
                submitClass="inline-flex rounded-md bg-sky-700 px-2 py-1 text-xs font-medium text-white hover:bg-sky-800" />}
          </Td>
        </tr>
      ))}
    </Table>
  );
}

async function Promotions({ sp }: { sp: Sp }) {
  const [{ data: rows }, { data: products }] = await Promise.all([
    api<{ data: Promotion[] }>("/channels/ecommerce/promotions"),
    api<{ data: EcomProduct[] }>("/channels/ecommerce/products"),
  ]);
  const base = "/canales/ecommerce/gestion?tab=promociones";
  const target = sp.promo ? products.find((p) => p.ref === sp.promo) ?? null : null;

  return (
    <>
      <form action="/canales/ecommerce/gestion" className="mb-3 flex flex-wrap items-end gap-2">
        <input type="hidden" name="tab" value="promociones" />
        <select name="promo" aria-label="Producto" required className={`${inputCls} max-w-xs`} defaultValue="">
          <option value="">Nueva promoción para…</option>
          {products.filter((p) => p.status === "ACTIVE").map((p) => <option key={p.id} value={p.ref}>{p.name}</option>)}
        </select>
        <button className={btnGhostCls}>Crear</button>
      </form>
      <Table head={["Promoción", "Producto", "Descuento", "Condición", "Vigencia", "Estado", ""]} empty="Sin promociones del canal">
        {rows.map((p) => (
          <tr key={p.id}>
            <Td>{p.name}<span className="block text-xs text-slate-500">{p.ref}</span></Td>
            <Td>{p.product ?? "—"}</Td>
            <Td>{p.label}</Td>
            <Td>{p.promo_code ? <span className="font-mono text-xs">{p.promo_code}</span> : p.min_pax ? `Desde ${p.min_pax} pax` : "—"}</Td>
            <Td className="whitespace-nowrap">{p.date_from ? `${date(p.date_from)} – ${date(p.date_to)}` : "Sin fecha"}</Td>
            <Td><ChStatus value={p.status} label={{ activa: "Activa", inactiva: "Inactiva", vencida: "Vencida" }[p.status] ?? p.status} /></Td>
            <Td>
              <ActionForm path={`/channels/ecommerce/promotions/${p.id}/${p.status === "activa" ? "deactivate" : "activate"}`} inline submitLabel={p.status === "activa" ? "Desactivar" : "Activar"}
                submitClass="inline-flex rounded-md bg-white px-2 py-1 text-xs font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50" />
            </Td>
          </tr>
        ))}
      </Table>
      {target && (
        <Drawer title={`Nueva promoción · ${target.name}`} close={base}>
          <ActionForm path={`/channels/ecommerce/products/${target.ref}/promotions`} submitLabel="Crear promoción" redirectTo={base}>
            <Input name="name" label="Nombre" required maxLength={80} />
            <Input name="value" type="number" min="1" max="50" step="0.5" label="Descuento (%)" required />
            <Input name="promo_code" label="Código promocional (opcional)" maxLength={30} pattern="[A-Za-z0-9_\-]{3,30}" />
            <Input name="min_pax" type="number" min="2" label="Mínimo de pasajeros (si no hay código)" defaultValue="4" />
            <div className="grid grid-cols-2 gap-3">
              <Input name="date_from" type="date" label="Desde" />
              <Input name="date_to" type="date" label="Hasta" />
            </div>
            <p className="text-xs text-slate-500">Con código, el comprador lo ingresa en el checkout; sin código, aplica automáticamente desde el mínimo de pasajeros.</p>
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}
