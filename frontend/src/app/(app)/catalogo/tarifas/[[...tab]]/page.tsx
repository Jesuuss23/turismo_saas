import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import { catalogMeta } from "@/lib/catalog-server";
import { pct, price, today, type PriceRule, type Rate, type Season } from "@/lib/catalog";
import { btnGhostCls, Card, Input, inputCls, Notice, PageHeader, Select, Table, Td } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { PathTabs, Status } from "@/components/catalog/ui";
import { PricingSimulator } from "@/components/catalog/Calculators";

type Search = { type?: string; channel?: string; status?: string; currency?: string; date?: string };
const small = "rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50";

export default async function RatesPage({ params, searchParams }: { params: Promise<{ tab?: string[] }>; searchParams: Promise<Search> }) {
  const [{ tab }, sp, meta] = await Promise.all([params, searchParams, catalogMeta()]);
  const tabs = [{ key: "tarifas", label: "Tarifas" }, { key: "temporadas", label: "Temporadas" }, ...(meta.can.see_pricing_rules ? [{ key: "reglas", label: "Reglas y simulador" }] : [])];
  const requested = tab?.[0] ?? "tarifas";
  const active = tabs.some((t) => t.key === requested) ? requested : "tarifas";

  return (
    <>
      <PageHeader title="Tarifas, temporadas y pricing" />
      {requested !== active && <Notice tone="warning">La pestaña solicitada no está disponible para su rol.</Notice>}
      <PathTabs base="/catalogo/tarifas" tabs={tabs} active={active} />
      {active === "tarifas" && <RatesTab sp={sp} />}
      {active === "temporadas" && <SeasonsTab sp={sp} canEdit={meta.can.rates} />}
      {active === "reglas" && <RulesTab canEdit={meta.can.rates} products={meta.products.map((p) => ({ id: p.ref, label: `${p.ref} · ${p.name}` }))} channels={meta.enums.rate_channels} />}
    </>
  );
}

async function RatesTab({ sp }: { sp: Search }) {
  const meta = await catalogMeta();
  const res = await api<{ data: Rate[]; meta: { counts: Record<string, number>; hidden_net: boolean } }>(`/catalog/rates${qs({ "filter[type]": sp.type, "filter[channel]": sp.channel, "filter[status]": sp.status, "filter[currency]": sp.currency })}`);
  const c = meta.can;
  return (
    <>
      <form className="mb-3 grid gap-2 sm:grid-cols-5" role="search">
        <select name="type" defaultValue={sp.type ?? ""} className={inputCls} aria-label="Tipo"><option value="">Todos los tipos</option>{meta.enums.rate_types.map((t) => <option key={t}>{t}</option>)}</select>
        <select name="channel" defaultValue={sp.channel ?? ""} className={inputCls} aria-label="Canal"><option value="">Todos los canales</option>{meta.enums.rate_channels.map((t) => <option key={t}>{t}</option>)}</select>
        <select name="status" defaultValue={sp.status ?? ""} className={inputCls} aria-label="Estado"><option value="">Todos los estados</option>{["vigente", "programada", "vencida", "inactiva"].map((t) => <option key={t}>{t}</option>)}</select>
        <select name="currency" defaultValue={sp.currency ?? ""} className={inputCls} aria-label="Moneda"><option value="">Todas las monedas</option><option>USD</option><option>PEN</option></select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <p className="mb-2 text-xs text-slate-500">{Object.entries(res.meta.counts).map(([k, n]) => `${n} ${k}`).join(" · ")}{res.meta.hidden_net && " · tarifas netas/B2B ocultas para su rol"}</p>
      <Table head={["Tarifa", "Producto", "Tipo", "Segmento / canal", ...(c.see_costs ? ["Costo"] : []), "Precio", ...(c.see_margin ? ["Margen"] : []), "Temporada", "Vigencia", "Estado"]} empty="Sin tarifas">
        {res.data.map((r) => (
          <tr key={r.id}>
            <Td>{r.ref}</Td>
            <Td>{r.product && <Link href={`/catalogo/productos/${r.product.ref}/tarifas`} className="text-sky-800 hover:underline">{r.product.ref} · {r.product.name}</Link>}</Td>
            <Td>{r.rate_type}</Td><Td>{r.segment}<br /><span className="text-xs text-slate-500">{r.channel}</span></Td>
            {c.see_costs && <Td>{price(r.cost, r.currency)}</Td>}
            <Td className="font-medium">{price(r.price, r.currency)}</Td>
            {c.see_margin && <Td>{pct(r.margin_pct)}</Td>}
            <Td>{r.season?.name ?? "—"}</Td>
            <Td className="whitespace-nowrap text-xs">{date(r.valid_from)} → {date(r.valid_to)}</Td>
            <Td><Status value={r.effective_status} /></Td>
          </tr>
        ))}
      </Table>
    </>
  );
}

const TL_START = Date.UTC(2026, 0, 1);
const TL_END = Date.UTC(2027, 2, 31);

async function SeasonsTab({ sp, canEdit }: { sp: Search; canEdit: boolean }) {
  const meta = await catalogMeta();
  const on = sp.date ?? today();
  const [res, resolved] = await Promise.all([
    api<{ data: Season[]; meta: { overlaps: { a: string; b: string; from: string; to: string; prevails: string | null; conflict: boolean }[]; types: string[] } }>("/catalog/seasons"),
    api<{ data: { date: string; applicable: Season | null; others: Season[] } }>(`/catalog/seasons/resolve?date=${on}`),
  ]);
  const span = TL_END - TL_START;
  const x = (iso: string) => Math.max(0, Math.min(100, ((Date.parse(`${iso}T00:00:00Z`) - TL_START) / span) * 100));
  return (
    <div className="space-y-4">
      <Card title="Línea de tiempo · ene 2026 → mar 2027">
        <div className="space-y-1">
          {res.data.map((s) => (
            <div key={s.id} className="flex items-center gap-2 text-xs">
              <span className="w-44 truncate" title={s.name}>{s.ref} · {s.name}</span>
              <div className="relative h-4 flex-1 rounded bg-slate-100">
                <div className={`absolute h-4 rounded ${s.status === "activa" ? (Number(s.factor) >= 1.1 ? "bg-rose-400" : Number(s.factor) < 1 ? "bg-sky-400" : "bg-amber-400") : "bg-slate-300"}`}
                  style={{ left: `${x(s.date_from)}%`, width: `${Math.max(0.8, x(s.date_to) - x(s.date_from))}%` }} title={`${s.date_from} → ${s.date_to} ×${s.factor}`} />
              </div>
            </div>
          ))}
        </div>
      </Card>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Temporada aplicable por fecha">
          <form action="/catalogo/tarifas/temporadas" className="flex items-end gap-2">
            <Input label="Fecha" name="date" type="date" defaultValue={on} />
            <button className={btnGhostCls}>Resolver</button>
          </form>
          <p className="mt-2 text-sm" data-testid="season-resolved">
            {resolved.data.applicable ? <>Aplica <strong>{resolved.data.applicable.name}</strong> (prioridad {resolved.data.applicable.priority}, ×{resolved.data.applicable.factor})</> : "Ninguna temporada activa cubre esa fecha."}
            {resolved.data.others.length > 0 && <span className="block text-xs text-slate-500">También coinciden: {resolved.data.others.map((o) => o.name).join(", ")}</span>}
          </p>
        </Card>
        <Card title="Solapamientos">
          <ul className="space-y-1 text-sm">
            {res.meta.overlaps.map((o, i) => (
              <li key={i} className={o.conflict ? "text-rose-700" : ""}>{o.a} ↔ {o.b} · {date(o.from)}–{date(o.to)} · {o.conflict ? "conflicto (misma prioridad)" : `prevalece ${o.prevails}`}</li>
            ))}
            {!res.meta.overlaps.length && <li className="text-slate-500">Sin solapamientos.</li>}
          </ul>
        </Card>
      </div>
      <Table head={["Temporada", "Tipo", "Fechas", "Prioridad", "Factor", "Uso", "Estado", ...(canEdit ? [""] : [])]}>
        {res.data.map((s) => (
          <tr key={s.id}>
            <Td>{s.ref} · {s.name}</Td><Td>{s.type}</Td><Td className="whitespace-nowrap">{date(s.date_from)} → {date(s.date_to)}</Td><Td>{s.priority}</Td><Td>×{s.factor}</Td>
            <Td className="text-xs">{s.rates} tarifa(s) · {s.products} producto(s)</Td><Td><Status value={s.status} /></Td>
            {canEdit && (
              <Td><span className="flex gap-1">
                <ActionForm path={`/catalog/seasons/${s.id}`} method="PATCH" submitLabel={s.status === "activa" ? "Inactivar" : "Activar"} submitClass={small} inline>
                  <input type="hidden" name="status" value={s.status === "activa" ? "inactiva" : "activa"} />
                </ActionForm>
                <ActionForm path={`/catalog/seasons/${s.id}`} method="DELETE" submitLabel="Eliminar" submitClass={small} confirm="¿Eliminar la temporada?" inline />
              </span></Td>
            )}
          </tr>
        ))}
      </Table>
      {canEdit && (
        <Card title="Nueva temporada">
          <ActionForm path="/catalog/seasons" submitLabel="Crear temporada" className="grid gap-2 sm:grid-cols-3">
            <Input label="Nombre" name="name" required />
            <Select label="Tipo" name="type" options={res.meta.types.map((t) => ({ value: t, label: t }))} />
            <Input label="Factor" name="factor" type="number" step="0.01" min={0.01} max={3} defaultValue={1} required />
            <Input label="Desde" name="date_from" type="date" required />
            <Input label="Hasta" name="date_to" type="date" required />
            <Input label="Prioridad (menor gana)" name="priority" type="number" min={0} defaultValue={10} required />
          </ActionForm>
        </Card>
      )}
      <p className="text-xs text-slate-500">{meta.seasons.length} temporada(s) activa(s) disponibles para tarifas.</p>
    </div>
  );
}

async function RulesTab({ canEdit, products, channels }: { canEdit: boolean; products: { id: string; label: string }[]; channels: string[] }) {
  const res = await api<{ data: PriceRule[]; meta: { kinds: string[] } }>("/catalog/price-rules");
  return (
    <div className="space-y-4">
      <Table head={["Regla", "Tipo", "Valor", "Alcance", "Canal", "Pax mín.", "Prioridad", "Estado", ...(canEdit ? [""] : [])]}>
        {res.data.map((r) => (
          <tr key={r.id}>
            <Td>{r.ref} · {r.name}</Td><Td>{r.kind}</Td><Td>{r.value}</Td><Td className="text-xs">{r.scope}</Td><Td>{r.channel ?? "Todos"}</Td><Td>{r.min_pax ?? "—"}</Td><Td>{r.priority}</Td>
            <Td><Status value={r.status} /></Td>
            {canEdit && <Td><ActionForm path={`/catalog/price-rules/${r.id}/${r.status === "activa" ? "deactivate" : "activate"}`} submitLabel={r.status === "activa" ? "Desactivar" : "Activar"} submitClass={small} inline /></Td>}
          </tr>
        ))}
      </Table>
      {canEdit && (
        <Card title="Nueva regla">
          <ActionForm path="/catalog/price-rules" submitLabel="Crear regla" className="grid gap-2 sm:grid-cols-4">
            <Input label="Nombre" name="name" required />
            <Select label="Tipo" name="kind" options={res.meta.kinds.map((k) => ({ value: k, label: k }))} />
            <Input label="Valor" name="value" type="number" step="0.01" required />
            <Input label="Alcance" name="scope" defaultValue="Todos los productos" required />
            <Select label="Canal" name="channel" placeholder="Todos" options={channels.map((c) => ({ value: c, label: c }))} />
            <Input label="Pax mínimo" name="min_pax" type="number" min={1} />
            <Input label="Prioridad" name="priority" type="number" min={0} defaultValue={100} />
          </ActionForm>
        </Card>
      )}
      <Card title="Simulador de precios (CAT-BR-42)">
        <PricingSimulator products={products} channels={channels} today={today()} />
      </Card>
    </div>
  );
}
