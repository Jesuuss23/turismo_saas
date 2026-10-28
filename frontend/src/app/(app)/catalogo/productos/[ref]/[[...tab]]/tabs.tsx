import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { pct, price, today, addDays, type AuditRow, type CatalogMeta, type Departure, type Product, type Rate, type Season } from "@/lib/catalog";
import { btnGhostCls, Card, Input, Select, Table, Td, TextArea, Timeline } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { Check, Field, Meter, Status } from "@/components/catalog/ui";
import { PriceQuote } from "@/components/catalog/Calculators";
import { DepartureForm } from "@/components/catalog/DepartureForm";
import { BookingTab } from "./reservas";

type Props = { product: Product; meta: CatalogMeta; search: Record<string, string | undefined>; sub?: string };
const get = async <T,>(p: Product, path: string) => (await api<{ data: T; meta?: Record<string, unknown> }>(`/catalog/products/${p.ref}/${path}`));
const small = "rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50";
const danger = "rounded-md border border-rose-300 bg-white px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50";

export async function TabContent({ tab, ...props }: Props & { tab: string }) {
  const map: Record<string, (p: Props) => Promise<React.ReactNode>> = {
    general: General, componentes: Components, itinerario: Itinerary, tarifas: Rates, temporadas: Seasons, disponibilidad: Availability,
    salidas: Departures, proveedores: Providers, politicas: Policies, inclusiones: Inclusions, relacionados: Related, b2b: B2b,
    promociones: Promotions, rentabilidad: Profitability, personalizacion: Personalization, contenido: Content, canales: Channels,
    versiones: Versions, historial: History, reservas: BookingTab,
  };
  return (map[tab] ?? General)(props);
}

async function General({ product: p, meta }: Props) {
  const [checklist, requirements] = await Promise.all([
    get<{ key: string; label: string; ok: boolean; detail: string | null }[]>(p, "activation-checklist"),
    get<{ id: string; category: string | null; text: string }[]>(p, "requirements"),
  ]);
  const base = `/catalog/products/${p.ref}`;
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_340px]">
      <div className="space-y-4">
        <Card title="Ficha">
          <dl className="grid gap-3 sm:grid-cols-3">
            <Field label="Código">{p.code}</Field>
            <Field label="Nombre interno">{p.internal_name}</Field>
            <Field label="Duración">{p.duration.value} {p.duration.unit} · {p.modality}</Field>
            <Field label="Pasajeros">{p.minimum_passengers}–{p.maximum_passengers}</Field>
            <Field label="Edad">{p.minimum_age ?? 0}–{p.maximum_age ?? "sin límite"}</Field>
            <Field label="Anticipación">{p.booking_advance_min_days ?? 0}–{p.booking_advance_max_days ?? "—"} días</Field>
            <Field label="Destinos secundarios">{p.secondary_destinations.map((d) => d.name).join(", ") || "—"}</Field>
            <Field label="Proveedor principal">{p.main_provider?.name}</Field>
            <Field label="Creado">{dateTime(p.created_at)} · {p.created_by?.name}</Field>
          </dl>
          <p className="mt-3 text-sm text-slate-700">{p.short_description}</p>
        </Card>
        {p.actions.update && (
          <Card title="Editar ficha">
            <ActionForm path={base} method="PATCH" ifMatch={p.lock_version} submitLabel="Guardar cambios" className="grid gap-3 sm:grid-cols-3">
              <Input label="Nombre comercial" name="name" defaultValue={p.name} required />
              <Input label="Nombre interno" name="internal_name" defaultValue={p.internal_name ?? ""} />
              <Select label="Modalidad" name="modality" defaultValue={p.modality} options={meta.enums.modalities.map((m) => ({ value: m, label: m }))} />
              <Input label="Pax mínimo" name="minimum_passengers" type="number" min={1} defaultValue={p.minimum_passengers} />
              <Input label="Pax máximo" name="maximum_passengers" type="number" min={1} defaultValue={p.maximum_passengers} />
              <Input label="Duración" name="duration_value" type="number" min={1} defaultValue={p.duration.value} />
              <Input label="Motivo del cambio (si el producto está activo)" name="reason" />
              <div className="flex flex-col gap-1 pt-6"><Check name="is_bookable" label="Reservable" defaultChecked={p.is_bookable} /><Check name="is_featured" label="Destacado" defaultChecked={p.is_featured} /></div>
            </ActionForm>
          </Card>
        )}
        <Card title="Requisitos del pasajero">
          <ul className="space-y-1 text-sm">
            {requirements.data.map((r) => (
              <li key={r.id} className="flex items-center justify-between gap-2">
                <span><span className="text-xs text-slate-500">{r.category ?? "General"} · </span>{r.text}</span>
                {p.actions.update && <ActionForm path={`${base}/requirements/${r.id}`} method="DELETE" submitLabel="Quitar" submitClass={danger} inline />}
              </li>
            ))}
            {!requirements.data.length && <li className="text-slate-500">Sin requisitos.</li>}
          </ul>
          {p.actions.update && (
            <ActionForm path={`${base}/requirements`} submitLabel="Agregar requisito" className="mt-3 grid gap-2 sm:grid-cols-[180px_1fr_auto] sm:items-end">
              <Select label="Categoría" name="category" options={meta.enums.requirement_categories.map((c) => ({ value: c, label: c }))} />
              <Input label="Requisito" name="text" required />
            </ActionForm>
          )}
        </Card>
      </div>
      <Card title={`Checklist de activación · ${checklist.meta?.ok}/${checklist.meta?.total}`}>
        <ul className="space-y-2 text-sm">
          {checklist.data.map((c) => (
            <li key={c.key} className="flex gap-2">
              <span aria-hidden className={c.ok ? "text-emerald-600" : "text-rose-600"}>{c.ok ? "✓" : "✗"}</span>
              <span><span className="font-medium">{c.label}</span>{c.detail && <span className="block text-xs text-slate-500">{c.detail}</span>}</span>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}

type ComponentRow = {
  id: string; kind: string; service: { id: string; ref: string; name: string; type: string; status: string; selectable: boolean } | null;
  component_product: { id: string; ref: string; name: string } | null; provider: { name: string } | null; quantity: number; unit: string;
  sort_order: number; is_required: boolean; is_included: boolean; notes: string | null; unit_cost?: number; currency?: string; total_cost_pen?: number;
};

async function Components({ product: p, meta }: Props) {
  const res = await get<ComponentRow[]>(p, "components");
  const base = `/catalog/products/${p.ref}`;
  const costs = p.actions.see_costs;
  return (
    <div className="space-y-4">
      <Table head={["#", "Componente", "Proveedor", "Cantidad", ...(costs ? ["Costo unit.", "Total PEN"] : []), "Incluido", ""]}
        foot={costs ? <tr><td colSpan={5} className="px-3 py-2 text-right">Costo total</td><td className="px-3 py-2">{price(res.meta?.total_cost_pen as number, "PEN")}</td><td colSpan={2} /></tr> : undefined}>
        {res.data.map((c) => (
          <tr key={c.id}>
            <Td>{c.sort_order}</Td>
            <Td>{c.service ? <>{c.service.ref} · {c.service.name} <span className="text-xs text-slate-500">({c.service.type})</span>{!c.service.selectable && <span className="ml-1 text-xs text-rose-700">PRD-BR-007</span>}</> : <>{c.component_product?.ref} · {c.component_product?.name}</>}</Td>
            <Td>{c.provider?.name ?? "—"}</Td>
            <Td>{c.quantity} {c.unit}</Td>
            {costs && <Td>{price(c.unit_cost, c.currency)}</Td>}
            {costs && <Td>{price(c.total_cost_pen, "PEN")}</Td>}
            <Td>{c.is_included ? "Sí" : "Opcional"}</Td>
            <Td>{p.actions.update && <ActionForm path={`${base}/components/${c.id}`} method="DELETE" submitLabel="Quitar" submitClass={danger} confirm="¿Quitar el componente?" inline />}</Td>
          </tr>
        ))}
      </Table>
      {p.actions.update && (
        <Card title="Agregar componente">
          <ActionForm path={`${base}/components`} submitLabel="Agregar" className="grid gap-2 sm:grid-cols-[1fr_100px_140px_auto] sm:items-end">
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Servicio</span>
              <select name="service_id" className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                {meta.services.map((s) => <option key={s.id} value={s.id} disabled={!s.selectable}>{s.ref} · {s.name}{!s.selectable ? " · PRD-BR-007" : ""}</option>)}
              </select></label>
            <Input label="Cantidad" name="quantity" type="number" min={1} defaultValue={1} />
            <Select label="Unidad" name="unit" options={meta.enums.units.map((u) => ({ value: u, label: u }))} />
          </ActionForm>
        </Card>
      )}
    </div>
  );
}

type ItineraryData = { days: { id: string; day_number: number; title: string; items: { id: string; start_time: string | null; activity: string; item_type: string | null; location: string | null; cost?: number; price?: number }[] }[] };

async function Itinerary({ product: p, meta }: Props) {
  const res = await get<ItineraryData>(p, "itineraries");
  const base = `/catalog/products/${p.ref}/itineraries/days`;
  return (
    <div className="space-y-4">
      {res.data.days.map((d) => (
        <Card key={d.id} title={`Día ${d.day_number} · ${d.title}`}
          actions={p.actions.update && <ActionForm path={`${base}/${d.id}`} method="DELETE" submitLabel="Quitar día" submitClass={danger} confirm="¿Quitar el día y sus actividades?" inline />}>
          <ol className="space-y-1 text-sm">
            {d.items.map((it) => (
              <li key={it.id} className="flex items-center justify-between gap-2">
                <span><span className="font-mono text-xs text-slate-500">{it.start_time ?? "--:--"}</span> {it.activity} <span className="text-xs text-slate-500">{it.item_type}{it.location ? ` · ${it.location}` : ""}</span></span>
                {p.actions.update && <ActionForm path={`${base}/${d.id}/items/${it.id}`} method="DELETE" submitLabel="Quitar" submitClass={danger} inline />}
              </li>
            ))}
            {!d.items.length && <li className="text-amber-700">Día sin actividades.</li>}
          </ol>
          {p.actions.update && (
            <ActionForm path={`${base}/${d.id}/items`} submitLabel="Agregar actividad" className="mt-3 grid gap-2 sm:grid-cols-[110px_1fr_160px_auto] sm:items-end">
              <Input label="Hora" name="start_time" type="time" />
              <Input label="Actividad" name="activity" required />
              <Select label="Tipo" name="item_type" options={meta.enums.itinerary_types.map((t) => ({ value: t, label: t }))} />
            </ActionForm>
          )}
        </Card>
      ))}
      {p.actions.update && (
        <ActionForm path={base} submitLabel="Agregar día" className="flex items-end gap-2">
          <Input label="Título del nuevo día" name="title" required />
        </ActionForm>
      )}
    </div>
  );
}

async function Rates({ product: p, meta, search }: Props) {
  const res = await get<Rate[]>(p, "rates");
  const base = `/catalog/products/${p.ref}/rates`;
  const a = p.actions;
  const t = today();
  return (
    <div className="space-y-4">
      {res.meta?.hidden_net ? <p className="text-xs text-slate-500">Las tarifas netas y B2B no se muestran para su rol.</p> : null}
      <Table head={["Tarifa", "Tipo", "Segmento / canal", ...(a.see_costs ? ["Costo"] : []), "Precio", ...(a.see_margin ? ["Margen"] : []), "Vigencia", "Estado", ...(a.rates ? ["Cambiar precio"] : [])]}>
        {res.data.map((r) => (
          <tr key={r.id}>
            <Td>{r.ref}{res.meta?.from_price === r.ref && <span className="ml-1 rounded bg-sky-50 px-1 text-xs text-sky-800">desde</span>}{r.season && <span className="block text-xs text-slate-500">{r.season.name}</span>}</Td>
            <Td>{r.rate_type}</Td>
            <Td>{r.segment}<br /><span className="text-xs text-slate-500">{r.channel}</span></Td>
            {a.see_costs && <Td>{price(r.cost, r.currency)}</Td>}
            <Td className="whitespace-nowrap font-medium">{price(r.price, r.currency)}</Td>
            {a.see_margin && <Td>{pct(r.margin_pct)}</Td>}
            <Td className="whitespace-nowrap text-xs">{date(r.valid_from)} → {date(r.valid_to)}</Td>
            <Td><Status value={r.effective_status} /></Td>
            {a.rates && (
              <Td>
                {r.effective_status !== "vencida" && (
                  <ActionForm path={`${base}/${r.id}/price`} method="PATCH" ifMatch={r.lock_version} submitLabel="Guardar" submitClass={small} className="flex flex-wrap items-center gap-1">
                    <input name="cost" type="number" step="0.01" defaultValue={r.cost} aria-label="Costo" className="w-20 rounded border border-slate-300 px-1 py-0.5 text-xs" />
                    <input name="price" type="number" step="0.01" defaultValue={r.price} aria-label="Precio" className="w-20 rounded border border-slate-300 px-1 py-0.5 text-xs" />
                    <input name="reason" placeholder="Motivo" aria-label="Motivo" className="w-24 rounded border border-slate-300 px-1 py-0.5 text-xs" />
                  </ActionForm>
                )}
              </Td>
            )}
          </tr>
        ))}
      </Table>
      <ResolveRate product={p} meta={meta} search={search} />
      {a.rates && (
        <Card title="Nueva tarifa">
          <ActionForm path={base} submitLabel="Registrar tarifa" className="grid gap-2 sm:grid-cols-4">
            <Select label="Tipo" name="rate_type" options={meta.enums.rate_types.map((x) => ({ value: x, label: x }))} defaultValue="Venta" />
            <Select label="Segmento" name="segment" options={meta.enums.segments.map((x) => ({ value: x, label: x }))} />
            <Select label="Canal" name="channel" options={meta.enums.rate_channels.map((x) => ({ value: x, label: x }))} />
            <Select label="Moneda" name="currency" options={[{ value: "USD", label: "USD" }, { value: "PEN", label: "PEN" }]} />
            <Input label="Costo" name="cost" type="number" step="0.01" min={0} required />
            <Input label="Precio" name="price" type="number" step="0.01" min={0} required />
            <Input label="Vigente desde" name="valid_from" type="date" defaultValue={t} required />
            <Input label="Vigente hasta" name="valid_to" type="date" defaultValue={addDays(t, 365)} required />
            <Select label="Temporada" name="season_id" placeholder="(sin temporada)" options={meta.seasons.map((s) => ({ value: s.id, label: s.name }))} />
            <Input label="Precio mínimo" name="min_price" type="number" step="0.01" min={0} />
            <Input label="Precio máximo" name="max_price" type="number" step="0.01" min={0} />
          </ActionForm>
        </Card>
      )}
    </div>
  );
}

/** Resolver la tarifa aplicable por fecha, segmento y canal (form GET sobre la misma pestaña). */
async function ResolveRate({ product: p, meta, search }: Props) {
  return (
    <Card title="Resolver tarifa aplicable">
      <form action={`/catalogo/productos/${p.ref}/tarifas`} className="grid gap-2 sm:grid-cols-[160px_160px_160px_auto] sm:items-end">
        <Input label="Fecha" name="date" type="date" defaultValue={search.date ?? today()} />
        <Select label="Segmento" name="segment" defaultValue={search.segment} options={meta.enums.segments.map((x) => ({ value: x, label: x }))} />
        <Select label="Canal" name="channel" defaultValue={search.channel} options={meta.enums.rate_channels.map((x) => ({ value: x, label: x }))} />
        <button className={btnGhostCls}>Resolver</button>
      </form>
      <ResolveResult product={p} search={search} />
    </Card>
  );
}


async function ResolveResult({ product: p, search }: { product: Product; search: Props["search"] }) {
  if (!search.date) return null;
  const q = new URLSearchParams({ date: search.date, segment: search.segment ?? "", channel: search.channel ?? "" });
  const res = await api<{ data: { applicable: Rate | null; season: Season | null; rows: { rate: Rate; selectable: boolean; reason: string | null }[] } }>(`/catalog/products/${p.ref}/rates/resolve?${q}`);
  const r = res.data;
  return (
    <div className="mt-3 text-sm" data-testid="resolve-result">
      {r.applicable ? <p className="font-medium text-emerald-800">Aplica {r.applicable.ref}: {price(r.applicable.price, r.applicable.currency)} ({r.applicable.segment} · {r.applicable.channel})</p>
        : <p className="text-amber-800">No hay tarifa aplicable para esa combinación.</p>}
      {r.season && <p className="text-xs text-slate-600">Temporada: {r.season.name} (factor {r.season.factor})</p>}
      <ul className="mt-2 space-y-0.5 text-xs text-slate-600">
        {r.rows.map((row) => <li key={row.rate.id}>{row.selectable ? "✓" : "✗"} {row.rate.ref} {row.rate.segment}/{row.rate.channel} {row.reason ? `— ${row.reason}` : ""}</li>)}
      </ul>
    </div>
  );
}

async function Seasons({ product: p, meta }: Props) {
  const res = await get<Season[]>(p, "seasons");
  const base = `/catalog/products/${p.ref}/seasons`;
  const free = meta.seasons.filter((s) => !res.data.some((x) => x.id === s.id));
  return (
    <div className="space-y-4">
      <Table head={["Temporada", "Tipo", "Fechas", "Prioridad", "Factor", ""]}>
        {res.data.map((s) => (
          <tr key={s.id}>
            <Td>{s.ref} · {s.name}</Td><Td>{s.type}</Td><Td>{date(s.date_from)} → {date(s.date_to)}</Td><Td>{s.priority}</Td><Td>×{s.factor}</Td>
            <Td>{p.actions.rates && <ActionForm path={`${base}/${s.id}`} method="DELETE" submitLabel="Desasociar" submitClass={danger} inline />}</Td>
          </tr>
        ))}
      </Table>
      {p.actions.rates && free.length > 0 && (
        <ActionForm path={base} submitLabel="Asociar temporada" className="flex items-end gap-2">
          <Select label="Temporada" name="season_id" options={free.map((s) => ({ value: s.id, label: `${s.ref} · ${s.name}` }))} />
        </ActionForm>
      )}
    </div>
  );
}

type AvailabilityData = { availability_model: string; availability_capacity: number | null; availability_source: string; external_notice: string | null; future_departures: number; capacity: number; sold: number; held: number; available: number; active_holds: number; models: string[] };

async function Availability({ product: p }: Props) {
  const { data: d } = await get<AvailabilityData>(p, "availability");
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card title="Resumen de cupos (salidas futuras)">
        <dl className="grid grid-cols-3 gap-3">
          <Field label="Salidas">{d.future_departures}</Field><Field label="Capacidad">{d.capacity}</Field><Field label="Vendidos">{d.sold}</Field>
          <Field label="En hold">{d.held}</Field><Field label="Disponibles">{d.available}</Field><Field label="Holds activos">{d.active_holds}</Field>
        </dl>
        <div className="mt-3"><Meter value={d.sold + d.held} max={d.capacity} /></div>
        {d.external_notice && <p className="mt-2 text-sm text-amber-800">{d.external_notice}</p>}
        {"reservas" in p.tabs && <p className="mt-2 text-sm"><Link href={`/catalogo/productos/${p.ref}/reservas`} className="text-sky-700 underline">Reservas online</Link> · rangos de fechas, reglas de pasajeros y motor de reservas.</p>}
      </Card>
      <Card title="Modelo de disponibilidad">
        {p.actions.availability ? (
          <ActionForm path={`/catalog/products/${p.ref}/availability`} method="PUT" submitLabel="Guardar" className="grid gap-2 sm:grid-cols-3 sm:items-end">
            <Select label="Modelo" name="availability_model" defaultValue={d.availability_model} options={d.models.map((m) => ({ value: m, label: m }))} />
            <Input label="Capacidad" name="availability_capacity" type="number" min={0} defaultValue={d.availability_capacity ?? ""} />
            <Select label="Fuente" name="availability_source" defaultValue={d.availability_source} options={[{ value: "interna", label: "Interna" }, { value: "externa", label: "Externa" }]} />
          </ActionForm>
        ) : <p className="text-sm">{d.availability_model} · capacidad {d.availability_capacity ?? "—"} · fuente {d.availability_source}</p>}
      </Card>
    </div>
  );
}

async function Departures({ product: p, meta }: Props) {
  const res = await get<Departure[]>(p, "departures");
  const base = `/catalog/products/${p.ref}/departures`;
  return (
    <div className="space-y-4">
      <Table head={["Salida", "Fecha", "Capacidad", "Vendidos", "Hold", "Disponibles", "Ocupación", "Estado"]}>
        {res.data.map((d) => (
          <tr key={d.id}>
            <Td><Link href={`/catalogo/salidas?departure=${d.id}`} className="text-sky-800 hover:underline">{d.ref}</Link></Td>
            <Td className="whitespace-nowrap">{date(d.departure_date)} {d.departure_time}</Td>
            <Td>{d.capacity}</Td><Td>{d.sold}</Td><Td>{d.held}</Td><Td>{d.available}</Td>
            <Td className="w-32"><Meter value={d.sold + d.held} max={d.capacity} tone={d.occupancy_pct >= 90 ? "rose" : d.occupancy_pct >= 70 ? "amber" : "sky"} /><span className="text-xs">{d.occupancy_pct} %</span></Td>
            <Td><Status value={d.status} /></Td>
          </tr>
        ))}
      </Table>
      {p.actions.availability && <DepartureForm path={base} meta={meta} />}
    </div>
  );
}

type ProviderRow = { id: string; ref: string; name: string; type: string; status: string; effective_status: string; contract_end: string; rating: number | null; is_main: boolean; services: { ref: string; name: string }[] };

async function Providers({ product: p, meta }: Props) {
  const res = await get<ProviderRow[]>(p, "providers");
  return (
    <div className="space-y-4">
      <Table head={["Proveedor", "Tipo", "Servicios en el producto", "Contrato hasta", "Calificación", "Estado"]}>
        {res.data.map((v) => (
          <tr key={v.id}>
            <Td>{v.ref} · {v.name}{v.is_main && <span className="ml-1 rounded bg-sky-50 px-1 text-xs text-sky-800">principal</span>}</Td>
            <Td>{v.type}</Td><Td className="text-xs">{v.services.map((s) => s.name).join(", ")}</Td><Td>{date(v.contract_end)}</Td><Td>{v.rating ?? "—"}</Td>
            <Td><Status value={v.effective_status} /></Td>
          </tr>
        ))}
      </Table>
      {p.actions.update && (
        <ActionForm path={`/catalog/products/${p.ref}/main-provider`} method="PUT" submitLabel="Cambiar proveedor principal" className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
          <Select label="Proveedor" name="provider_id" defaultValue={p.main_provider?.id} options={meta.providers.filter((x) => x.usable).map((x) => ({ value: x.id, label: `${x.ref} · ${x.name}` }))} />
          <Input label="Motivo" name="reason" required />
        </ActionForm>
      )}
    </div>
  );
}

type Policies = Record<"cancellation" | "modification" | "no_show" | "penalties" | "refund" | "date_change" | "advance_min" | "advance_max", string | null>;

async function Policies({ product: p }: Props) {
  const { data } = await get<Policies>(p, "policies");
  const labels: [keyof Policies, string][] = [["cancellation", "Cancelación"], ["modification", "Modificación"], ["no_show", "No show"], ["refund", "Reembolso"], ["penalties", "Penalidades"], ["date_change", "Cambio de fecha"]];
  if (!p.actions.update) {
    return <Card title="Políticas"><dl className="grid gap-3 sm:grid-cols-2">{labels.map(([k, l]) => <Field key={k} label={l}>{data[k]}</Field>)}<Field label="Anticipación">{data.advance_min} – {data.advance_max}</Field></dl></Card>;
  }
  return (
    <Card title="Políticas">
      <ActionForm path={`/catalog/products/${p.ref}/policies`} method="PUT" submitLabel="Guardar políticas" className="grid gap-3 sm:grid-cols-2">
        {labels.map(([k, l]) => <TextArea key={k} label={l} name={k} defaultValue={data[k] ?? ""} required={["cancellation", "modification", "no_show", "refund"].includes(k)} />)}
        <Input label="Anticipación mínima" name="advance_min" defaultValue={data.advance_min ?? ""} />
        <Input label="Anticipación máxima" name="advance_max" defaultValue={data.advance_max ?? ""} />
      </ActionForm>
    </Card>
  );
}

async function Inclusions({ product: p }: Props) {
  const { data } = await get<{ include: { id: string; text: string }[]; exclude: { id: string; text: string }[] }>(p, "inclusions");
  const base = `/catalog/products/${p.ref}/inclusions`;
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {(["include", "exclude"] as const).map((kind) => (
        <Card key={kind} title={kind === "include" ? "Incluye" : "No incluye"}>
          <ul className="space-y-1 text-sm">
            {data[kind].map((i) => (
              <li key={i.id} className="flex items-center justify-between gap-2">
                <span>{kind === "include" ? "✓" : "✗"} {i.text}</span>
                {p.actions.update && <ActionForm path={`${base}/${i.id}`} method="DELETE" submitLabel="Quitar" submitClass={danger} inline />}
              </li>
            ))}
          </ul>
          {p.actions.update && (
            <ActionForm path={base} submitLabel="Agregar" className="mt-3 flex items-end gap-2">
              <input type="hidden" name="kind" value={kind} />
              <Input label="Texto" name="text" required />
            </ActionForm>
          )}
        </Card>
      ))}
    </div>
  );
}

type RelatedRow = { id: string; relation: string; product: { id: string; ref: string; name: string; status: string; sellable: boolean } };

async function Related({ product: p, meta }: Props) {
  const { data } = await get<{ related: RelatedRow[]; referenced_by: RelatedRow[] }>(p, "related");
  const base = `/catalog/products/${p.ref}/related`;
  const row = (r: RelatedRow, removable: boolean) => (
    <li key={r.id} className="flex items-center justify-between gap-2">
      <span><span className="text-xs text-slate-500">{r.relation} · </span><Link href={`/catalogo/productos/${r.product.ref}`} className="text-sky-800 hover:underline">{r.product.ref} · {r.product.name}</Link> <Status value={r.product.status} /></span>
      {removable && p.actions.update && <ActionForm path={`${base}/${r.id}`} method="DELETE" submitLabel="Quitar" submitClass={danger} inline />}
    </li>
  );
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card title="Productos relacionados">
        <ul className="space-y-1 text-sm">{data.related.map((r) => row(r, true))}</ul>
        {p.actions.update && (
          <ActionForm path={base} submitLabel="Relacionar" className="mt-3 grid gap-2 sm:grid-cols-[1fr_160px_auto] sm:items-end">
            <Select label="Producto" name="related_product_id" options={meta.products.filter((x) => x.id !== p.id).map((x) => ({ value: x.id, label: `${x.ref} · ${x.name}` }))} />
            <Select label="Relación" name="relation" options={meta.enums.relations.map((x) => ({ value: x, label: x }))} />
          </ActionForm>
        )}
      </Card>
      <Card title="Lo referencian"><ul className="space-y-1 text-sm">{data.referenced_by.map((r) => row(r, false))}</ul></Card>
    </div>
  );
}

type B2bRow = { id: string; agency: { id: string; code: string; name: string } | null; price_list: string; price: string; currency: string; commission_pct: string | null; is_credit: boolean; valid_to: string; conditions: string | null; expired: boolean };

async function B2b({ product: p }: Props) {
  const res = await get<B2bRow[]>(p, "b2b-rates");
  const agencies = (res.meta?.agencies ?? []) as { id: string; code: string; name: string }[];
  const base = `/catalog/products/${p.ref}/b2b-rates`;
  return (
    <div className="space-y-4">
      {!res.meta?.is_b2b && <p className="text-sm text-amber-800">El producto no está habilitado para el canal B2B.</p>}
      <Table head={["Agencia", "Tarifario", "Precio", "Comisión", "Crédito", "Vence", ""]}>
        {res.data.map((b) => (
          <tr key={b.id}>
            <Td>{b.agency?.code} · {b.agency?.name}</Td><Td>{b.price_list}</Td><Td>{price(b.price, b.currency)}</Td><Td>{pct(b.commission_pct)}</Td>
            <Td>{b.is_credit ? "Sí" : "No"}</Td><Td>{date(b.valid_to)}{b.expired && <span className="ml-1 text-xs text-rose-700">vencida</span>}</Td>
            <Td>{p.actions.update && <ActionForm path={`${base}/${b.id}`} method="DELETE" submitLabel="Quitar" submitClass={danger} inline />}</Td>
          </tr>
        ))}
      </Table>
      {p.actions.update && agencies.length > 0 && (
        <Card title="Nueva tarifa B2B">
          <ActionForm path={base} submitLabel="Guardar" className="grid gap-2 sm:grid-cols-3">
            <Select label="Agencia" name="agency_id" options={agencies.map((x) => ({ value: x.id, label: `${x.code} · ${x.name}` }))} />
            <Input label="Tarifario" name="price_list" defaultValue="Tarifario agencias" required />
            <Input label="Precio" name="price" type="number" step="0.01" min={0} required />
            <Input label="Comisión %" name="commission_pct" type="number" step="0.01" min={0} max={100} />
            <Input label="Vence" name="valid_to" type="date" defaultValue={addDays(today(), 365)} required />
            <div className="pt-6"><Check name="is_credit" label="Venta a crédito" /></div>
          </ActionForm>
        </Card>
      )}
    </div>
  );
}

type Promo = { id: string; ref: string; name: string; kind: string; value: string; channel: string | null; promo_code: string | null; date_from: string; date_to: string; effective_status: string; resulting_price: string | null };

async function Promotions({ product: p }: Props) {
  const res = await get<Promo[]>(p, "promotions");
  const m = res.meta as { public_rate: { currency: string; price: string } | null; kinds: string[]; channels: string[] };
  const base = `/catalog/products/${p.ref}/promotions`;
  return (
    <div className="space-y-4">
      {m.public_rate && <p className="text-sm text-slate-600">Tarifa pública de referencia: {price(m.public_rate.price, m.public_rate.currency)}</p>}
      <Table head={["Promoción", "Tipo", "Valor", "Canal", "Código", "Vigencia", "Precio resultante", "Estado", ""]}>
        {res.data.map((x) => (
          <tr key={x.id}>
            <Td>{x.ref} · {x.name}</Td><Td>{x.kind}</Td><Td>{x.value}</Td><Td>{x.channel ?? "Todos"}</Td><Td className="font-mono">{x.promo_code ?? "—"}</Td>
            <Td className="text-xs">{date(x.date_from)} → {date(x.date_to)}</Td><Td>{x.resulting_price && m.public_rate ? price(x.resulting_price, m.public_rate.currency) : "—"}</Td>
            <Td><Status value={x.effective_status} /></Td>
            <Td>{p.actions.update && (
              <span className="flex gap-1">
                <ActionForm path={`${base}/${x.id}/${x.effective_status === "pausada" ? "activate" : "pause"}`} submitLabel={x.effective_status === "pausada" ? "Activar" : "Pausar"} submitClass={small} inline />
                <ActionForm path={`${base}/${x.id}`} method="DELETE" submitLabel="Quitar" submitClass={danger} inline />
              </span>
            )}</Td>
          </tr>
        ))}
      </Table>
      {p.actions.update && (
        <Card title="Nueva promoción">
          <ActionForm path={base} submitLabel="Crear promoción" className="grid gap-2 sm:grid-cols-4">
            <Input label="Nombre" name="name" required />
            <Select label="Tipo" name="kind" options={m.kinds.map((k) => ({ value: k, label: k }))} />
            <Input label="Valor" name="value" type="number" step="0.01" required />
            <Select label="Canal" name="channel" options={m.channels.map((k) => ({ value: k, label: k }))} />
            <Input label="Código (opcional)" name="promo_code" />
            <Input label="Desde" name="date_from" type="date" defaultValue={today()} required />
            <Input label="Hasta" name="date_to" type="date" defaultValue={addDays(today(), 30)} required />
          </ActionForm>
        </Card>
      )}
    </div>
  );
}

type Profit = { breakdown: Record<string, string> | null; by_rate: Rate[]; components_cost_pen: number; markup_pct: string | null; can_edit_markup: boolean };

async function Profitability({ product: p }: Props) {
  const { data } = await get<Profit>(p, "profitability");
  const b = data.breakdown;
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
      <Table head={["Tarifa", "Segmento / canal", "Costo", "Precio", "Margen"]}>
        {data.by_rate.map((r) => (
          <tr key={r.id}><Td>{r.ref}</Td><Td>{r.segment} · {r.channel}</Td><Td>{price(r.cost, r.currency)}</Td><Td>{price(r.price, r.currency)}</Td>
            <Td className={Number(r.margin_pct) < 15 ? "text-rose-700" : ""}>{pct(r.margin_pct)}</Td></tr>
        ))}
      </Table>
      <div className="space-y-4">
        {b && (
          <Card title={`Ejemplo ${b.rate} · ${b.pax} pax`}>
            <dl className="space-y-1 text-sm">
              {[["Venta", b.sale], ["Costo proveedor", b.provider_cost], ["Otros costos", b.other_cost], [`Comisión ${b.commission_pct} %`, b.commission], ["Utilidad", b.profit]].map(([l, v]) => (
                <div key={l} className="flex justify-between"><dt>{l}</dt><dd>{price(v, b.currency)}</dd></div>
              ))}
              <div className="flex justify-between font-semibold"><dt>Margen</dt><dd>{pct(b.margin_pct)}</dd></div>
            </dl>
          </Card>
        )}
        <Card title="Markup del producto">
          <p className="mb-2 text-sm">Costo de componentes: {price(data.components_cost_pen, "PEN")}</p>
          {data.can_edit_markup ? (
            <ActionForm path={`/catalog/products/${p.ref}/markup`} method="PUT" submitLabel="Guardar markup" className="flex items-end gap-2">
              <Input label="Markup %" name="markup_pct" type="number" step="0.01" min={0} max={500} defaultValue={data.markup_pct ?? ""} required />
            </ActionForm>
          ) : <p className="text-sm">Markup: {pct(data.markup_pct)}</p>}
        </Card>
      </div>
    </div>
  );
}

type ConfigGroup = { id: string; name: string; options: { id: string; label: string; price_delta: string | number | null }[] };

async function Personalization({ product: p }: Props) {
  const [groups, rates] = await Promise.all([get<ConfigGroup[]>(p, "config-groups"), get<Rate[]>(p, "rates")]);
  const base = `/catalog/products/${p.ref}/config-groups`;
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card title="Grupos de configuración">
        {groups.data.length === 0 && <p className="text-sm text-slate-500">Sin opciones de personalización.</p>}
        <ul className="space-y-2 text-sm">
          {groups.data.map((g) => (
            <li key={g.id} className="flex items-start justify-between gap-2">
              <span><span className="font-medium">{g.name}:</span> {g.options.map((o) => `${o.label}${Number(o.price_delta) ? ` (+${o.price_delta})` : ""}`).join(" · ")}</span>
              {p.actions.update && <ActionForm path={`${base}/${g.id}`} method="DELETE" submitLabel="Quitar" submitClass={danger} inline />}
            </li>
          ))}
        </ul>
        {p.actions.update && (
          <ActionForm path={base} submitLabel="Agregar grupo" className="mt-3 grid gap-2 sm:grid-cols-2">
            <Input label="Grupo" name="name" placeholder="Almuerzo" required />
            <span />
            <Input label="Opción 1" name="options.0.label" required />
            <Input label="Recargo 1" name="options.0.price_delta" type="number" step="0.01" defaultValue={0} />
            <Input label="Opción 2" name="options.1.label" required />
            <Input label="Recargo 2" name="options.1.price_delta" type="number" step="0.01" defaultValue={0} />
          </ActionForm>
        )}
      </Card>
      <Card title="Simular precio de la configuración">
        <PriceQuote productRef={p.ref} groups={groups.data} rates={rates.data.filter((r) => r.effective_status === "vigente").map((r) => ({ id: r.id, label: `${r.ref} · ${r.segment} ${price(r.price, r.currency)}` }))} />
      </Card>
    </div>
  );
}

type ContentData = { short_description: string | null; long_description: string | null; features: string[]; video_url: string | null; seo_title: string | null; seo_description: string | null; slug: string | null;
  media: { id: string; storage_path: string; alt_text: string; is_main: boolean }[]; faqs: { id: string; question: string; answer: string }[] };

async function Content({ product: p }: Props) {
  const { data } = await get<ContentData>(p, "content");
  const base = `/catalog/products/${p.ref}`;
  const edit = p.actions.update;
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card title="Textos y SEO">
        {edit ? (
          <ActionForm path={`${base}/content`} method="PUT" submitLabel="Guardar contenido" className="space-y-2">
            <TextArea label="Descripción corta" name="short_description" defaultValue={data.short_description ?? ""} />
            <TextArea label="Descripción larga" name="long_description" rows={5} defaultValue={data.long_description ?? ""} />
            {data.features.map((f, i) => <input key={i} type="hidden" name="features[]" value={f} />)}
            <Input label="Título SEO" name="seo_title" defaultValue={data.seo_title ?? ""} />
            <Input label="Descripción SEO" name="seo_description" defaultValue={data.seo_description ?? ""} />
            <Input label="Slug" name="slug" defaultValue={data.slug ?? ""} />
            <Input label="Video (URL)" name="video_url" type="url" defaultValue={data.video_url ?? ""} />
          </ActionForm>
        ) : (
          <dl className="space-y-2"><Field label="Descripción">{data.long_description}</Field><Field label="SEO">{data.seo_title}</Field><Field label="Slug">{data.slug}</Field></dl>
        )}
        <ul className="mt-3 list-disc pl-5 text-sm">{data.features.map((f) => <li key={f}>{f}</li>)}</ul>
      </Card>
      <div className="space-y-4">
        <Card title="Multimedia">
          <ul className="space-y-1 text-sm">
            {data.media.map((m) => (
              <li key={m.id} className="flex items-center justify-between gap-2">
                <span className="font-mono text-xs">{m.storage_path}</span><span className="flex-1 text-slate-600">{m.alt_text}</span>
                {m.is_main ? <span className="text-xs text-sky-800">principal</span> : edit && <ActionForm path={`${base}/media/${m.id}`} method="PATCH" submitLabel="Principal" submitClass={small} inline><input type="hidden" name="is_main" value="true" /></ActionForm>}
                {edit && <ActionForm path={`${base}/media/${m.id}`} method="DELETE" submitLabel="Quitar" submitClass={danger} inline />}
              </li>
            ))}
          </ul>
          {edit && (
            <ActionForm path={`${base}/media`} submitLabel="Agregar imagen" className="mt-3 grid gap-2 sm:grid-cols-2">
              <Input label="Ruta o URL" name="storage_path" required />
              <Input label="Texto alternativo" name="alt_text" required />
            </ActionForm>
          )}
        </Card>
        <Card title="Preguntas frecuentes">
          <dl className="space-y-2 text-sm">
            {data.faqs.map((f) => (
              <div key={f.id}><dt className="flex justify-between font-medium">{f.question}{edit && <ActionForm path={`${base}/faqs/${f.id}`} method="DELETE" submitLabel="Quitar" submitClass={danger} inline />}</dt><dd className="text-slate-600">{f.answer}</dd></div>
            ))}
          </dl>
          {edit && (
            <ActionForm path={`${base}/faqs`} submitLabel="Agregar pregunta" className="mt-3 space-y-2">
              <Input label="Pregunta" name="question" required />
              <TextArea label="Respuesta" name="answer" required />
            </ActionForm>
          )}
        </Card>
      </div>
    </div>
  );
}

type ChannelRow = { channel: string; label: string; is_published: boolean; is_requested: boolean; published_at: string | null; published_by: { name: string } | null; pending_activation: boolean; locked: boolean };

async function Channels({ product: p }: Props) {
  const res = await get<ChannelRow[]>(p, "channels");
  return (
    <Table head={["Canal", "Estado", "Publicado", ""]}>
      {res.data.map((c) => (
        <tr key={c.channel}>
          <Td>{c.label}</Td>
          <Td>{c.is_published ? <Status value="activo" label="Publicado" /> : c.pending_activation ? <Status value="hold" label="Solicitado · al activar" /> : <Status value="inactivo" label="No publicado" />}</Td>
          <Td className="text-xs">{c.published_at ? `${dateTime(c.published_at)} · ${c.published_by?.name ?? ""}` : "—"}</Td>
          <Td>{p.actions.publish && !c.locked && (
            <ActionForm path={`/catalog/products/${p.ref}/channels/${c.channel}`} method="PUT" submitLabel={c.is_published || c.is_requested ? "Retirar" : "Publicar"} submitClass={small} inline>
              <input type="hidden" name="published" value={c.is_published || c.is_requested ? "false" : "true"} />
            </ActionForm>
          )}</Td>
        </tr>
      ))}
    </Table>
  );
}

type Version = { id: string; version: string; status: string; valid_from: string; reason: string | null; created_at: string; created_by: { name: string } | null; is_current: boolean };

async function Versions({ product: p, search }: Props) {
  const res = await get<Version[]>(p, "versions");
  const { a: va, b: vb, show } = search;
  const diff = va && vb ? (await get<{ a: string; b: string; diff: { field: string; a: unknown; b: unknown }[] }>(p, `versions/compare?a=${va}&b=${vb}`)).data : null;
  const snapshot = show ? (await get<{ version: string; snapshot: unknown }>(p, `versions/${show}`)).data : null;
  const opts = res.data.map((v) => ({ value: v.id, label: `${v.version} (${v.status})` }));
  return (
    <div className="space-y-4">
      <Table head={["Versión", "Estado", "Vigente desde", "Motivo", "Creada", ""]}>
        {res.data.map((v) => (
          <tr key={v.id}>
            <Td className="font-medium">{v.version}</Td><Td><Status value={v.status} /></Td><Td>{date(v.valid_from)}</Td><Td>{v.reason}</Td>
            <Td className="text-xs">{dateTime(v.created_at)} · {v.created_by?.name}</Td>
            <Td><Link href={`/catalogo/productos/${p.ref}/versiones?show=${v.id}`} className="text-sm text-sky-800 hover:underline">Ver snapshot</Link></Td>
          </tr>
        ))}
      </Table>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Comparar versiones">
          <form action={`/catalogo/productos/${p.ref}/versiones`} className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <Select label="Versión A" name="a" options={opts} defaultValue={va ?? res.data[1]?.id} />
            <Select label="Versión B" name="b" options={opts} defaultValue={vb ?? res.data[0]?.id} />
            <button className={btnGhostCls}>Comparar</button>
          </form>
          {diff && (
            <div className="mt-3">
              <p className="mb-1 text-sm font-medium">{diff.a} → {diff.b}: {diff.diff.length} diferencia(s)</p>
              <ul className="space-y-1 text-xs">{diff.diff.map((d) => <li key={d.field}><span className="font-mono">{d.field}</span>: {JSON.stringify(d.a)} → {JSON.stringify(d.b)}</li>)}</ul>
            </div>
          )}
        </Card>
        {p.actions.update && (
          <Card title="Nueva versión">
            <ActionForm path={`/catalog/products/${p.ref}/versions`} submitLabel="Crear versión" className="grid gap-2 sm:grid-cols-2">
              <Select label="Tipo" name="kind" options={[{ value: "minor", label: "Menor (x.1)" }, { value: "major", label: "Mayor (x+1.0)" }]} />
              <Input label="Vigente desde" name="valid_from" type="date" defaultValue={today()} required />
              <div className="sm:col-span-2"><TextArea label="Motivo" name="reason" required /></div>
            </ActionForm>
          </Card>
        )}
      </div>
      {snapshot && <Card title={`Snapshot versión ${snapshot.version}`}><pre className="max-h-96 overflow-auto text-xs">{JSON.stringify(snapshot.snapshot, null, 2)}</pre></Card>}
    </div>
  );
}

async function History({ product: p }: Props) {
  const res = await get<AuditRow[]>(p, "audit");
  return <Card title="Historial de auditoría"><Timeline rows={res.data.map((r) => ({ ...r, actor: r.actor ? { name: r.actor.name } : null }))} /></Card>;
}

