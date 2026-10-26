"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { callApi } from "@/app/(app)/crm/actions";
import { addDays, destinationPath, price, today, type CatalogMeta } from "@/lib/catalog";
import { btnCls, btnGhostCls, inputCls } from "@/components/crm/ui";

const STEPS = ["Definir", "Categorizar", "Destino", "Componentes", "Itinerario", "Tarifas", "Temporadas", "Disponibilidad", "Contenido", "Publicar"];

type Component = { service_id: string; quantity: number; is_included: boolean };
type Day = { title: string; items: { start_time: string; activity: string; item_type: string }[] };
type RateRow = { rate_type: string; segment: string; channel: string; currency: string; cost: string; price: string; valid_from: string; valid_to: string };

/** Asistente PROC-014: valida cada paso en el cliente y crea el producto en DRAFT con un único POST. */
export function Wizard({ meta }: { meta: CatalogMeta }) {
  const router = useRouter();
  const [step, setStep] = useState(0);
  const [pending, start] = useTransition();
  const [errors, setErrors] = useState<string[]>([]);
  const [idem] = useState(() => crypto.randomUUID());
  const start0 = today();

  const [f, setF] = useState({
    code: "", name: "", internal_name: "", short_description: "", duration_value: "1", duration_unit: meta.enums.durations.includes("días") ? "días" : meta.enums.durations[0],
    modality: meta.enums.modalities[0], minimum_passengers: "1", maximum_passengers: "12",
    product_type_id: "", category_id: "", subcategory_id: "", primary_destination_id: "", secondary_destination_ids: [] as string[],
    availability_model: "POR_SALIDA", availability_capacity: "", availability_source: "interna",
    long_description: "", features: "", seo_title: "", channels: ["quotes", "crm"] as string[], season_ids: [] as string[],
  });
  const [components, setComponents] = useState<Component[]>([]);
  const [days, setDays] = useState<Day[]>([{ title: "Día 1", items: [{ start_time: "08:00", activity: "", item_type: meta.enums.itinerary_types[0] }] }]);
  const [rates, setRates] = useState<RateRow[]>([
    { rate_type: "Venta", segment: "Adulto", channel: "Directo", currency: "USD", cost: "", price: "", valid_from: start0, valid_to: addDays(start0, 365) },
  ]);

  const set = (key: keyof typeof f, value: string | string[]) => setF((prev) => ({ ...prev, [key]: value }));
  const type = meta.types.find((t) => t.id === f.product_type_id);
  const category = meta.categories.find((c) => c.id === f.category_id);
  const selectable = meta.services.filter((s) => s.selectable);

  function validate(i: number): string[] {
    const e: string[] = [];
    if (i === 0) {
      if (!f.code.trim()) e.push("Indique el código interno.");
      if (f.name.trim().length < 3) e.push("El nombre comercial debe tener al menos 3 caracteres.");
      if (Number(f.minimum_passengers) > Number(f.maximum_passengers)) e.push("El mínimo de pasajeros no puede superar al máximo (CAT-BR-02).");
      if (Number(f.duration_value) < 1) e.push("La duración debe ser al menos 1.");
    }
    if (i === 1 && (!f.product_type_id || !f.category_id)) e.push("Seleccione tipo y categoría.");
    if (i === 2 && !f.primary_destination_id) e.push("Seleccione el destino principal.");
    if (i === 3 && type?.requires_components && components.length === 0) e.push(`El tipo ${type.name} exige al menos un componente.`);
    if (i === 3 && new Set(components.map((c) => c.service_id)).size !== components.length) e.push("No repita servicios en el asistente.");
    if (i === 4 && type?.requires_itinerary && days.some((d) => !d.title.trim() || !d.items.some((it) => it.activity.trim()))) e.push("Cada día del itinerario necesita título y al menos una actividad.");
    if (i === 5) {
      rates.forEach((r, n) => {
        if (!(Number(r.price) > 0)) e.push(`Tarifa ${n + 1}: el precio debe ser mayor que 0.`);
        if (r.cost === "" || Number(r.cost) < 0) e.push(`Tarifa ${n + 1}: indique el costo.`);
        if (r.valid_to < r.valid_from) e.push(`Tarifa ${n + 1}: la vigencia “hasta” es anterior a “desde”.`);
        if (r.valid_to < start0) e.push(`Tarifa ${n + 1}: no se puede registrar una tarifa vencida.`);
      });
    }
    return e;
  }

  function next() {
    const e = validate(step);
    setErrors(e);
    if (!e.length) setStep((s) => Math.min(s + 1, STEPS.length - 1));
  }

  function submit() {
    const all = STEPS.flatMap((_, i) => validate(i));
    setErrors(all);
    if (all.length) return;
    const body = {
      ...f,
      subcategory_id: f.subcategory_id || null,
      internal_name: f.internal_name || null,
      availability_capacity: f.availability_capacity === "" ? null : Number(f.availability_capacity),
      features: f.features.split("\n").map((x) => x.trim()).filter(Boolean),
      seo_title: f.seo_title || null,
      components: components.map((c) => ({ service_id: c.service_id, quantity: c.quantity, is_included: c.is_included })),
      itinerary: days.map((d) => ({ title: d.title, items: d.items.filter((it) => it.activity.trim()).map((it) => ({ ...it, start_time: it.start_time || null })) })),
      rates: rates.map((r) => ({ ...r, cost: Number(r.cost), price: Number(r.price) })),
    };
    start(async () => {
      const res = await callApi("/catalog/products", "POST", body, { "Idempotency-Key": idem });
      if (res?.error) {
        setErrors([res.error, ...Object.values(res.fieldErrors ?? {}).flat()]);
        return;
      }
      router.push(`/catalogo/productos/${(res?.data as { ref: string }).ref}`);
    });
  }

  const box = "rounded-lg border border-slate-200 bg-white p-4";
  const label = "mb-1 block text-sm font-medium text-slate-700";

  return (
    <div className="space-y-4">
      <ol className="flex flex-wrap gap-2 text-xs" aria-label="Pasos">
        {STEPS.map((s, i) => (
          <li key={s}>
            <button type="button" onClick={() => i < step && setStep(i)} aria-current={i === step ? "step" : undefined}
              className={`rounded-full px-3 py-1 ${i < step ? "bg-emerald-600 text-white" : i === step ? "bg-sky-700 text-white" : "bg-slate-100 text-slate-500"}`}>
              {i + 1}. {s}
            </button>
          </li>
        ))}
      </ol>

      <section className={box} aria-label={STEPS[step]}>
        <h2 className="mb-3 text-lg font-semibold text-slate-900">Paso {step + 1}: {STEPS[step]}</h2>

        {step === 0 && (
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm"><span className={label}>Código interno</span>
              <input className={inputCls} value={f.code} onChange={(e) => set("code", e.target.value.toUpperCase())} placeholder="MAPI-FD" />
              <span className="text-xs text-slate-500">SKU: {meta.sku_prefix}-{f.code || "…"}</span></label>
            <label className="block text-sm"><span className={label}>Nombre comercial</span>
              <input className={inputCls} value={f.name} onChange={(e) => set("name", e.target.value)} /></label>
            <label className="block text-sm"><span className={label}>Nombre interno (opcional)</span>
              <input className={inputCls} value={f.internal_name} onChange={(e) => set("internal_name", e.target.value)} /></label>
            <label className="block text-sm"><span className={label}>Modalidad</span>
              <select className={inputCls} value={f.modality} onChange={(e) => set("modality", e.target.value)}>{meta.enums.modalities.map((m) => <option key={m}>{m}</option>)}</select></label>
            <div className="flex gap-2">
              <label className="block flex-1 text-sm"><span className={label}>Duración</span>
                <input type="number" min={1} className={inputCls} value={f.duration_value} onChange={(e) => set("duration_value", e.target.value)} /></label>
              <label className="block flex-1 text-sm"><span className={label}>Unidad</span>
                <select className={inputCls} value={f.duration_unit} onChange={(e) => set("duration_unit", e.target.value)}>{meta.enums.durations.map((d) => <option key={d}>{d}</option>)}</select></label>
            </div>
            <div className="flex gap-2">
              <label className="block flex-1 text-sm"><span className={label}>Pax mínimo</span>
                <input type="number" min={1} className={inputCls} value={f.minimum_passengers} onChange={(e) => set("minimum_passengers", e.target.value)} /></label>
              <label className="block flex-1 text-sm"><span className={label}>Pax máximo</span>
                <input type="number" min={1} className={inputCls} value={f.maximum_passengers} onChange={(e) => set("maximum_passengers", e.target.value)} /></label>
            </div>
            <label className="block text-sm sm:col-span-2"><span className={label}>Descripción corta</span>
              <textarea rows={2} className={inputCls} value={f.short_description} onChange={(e) => set("short_description", e.target.value)} /></label>
          </div>
        )}

        {step === 1 && (
          <div className="grid gap-3 sm:grid-cols-3">
            <label className="block text-sm"><span className={label}>Tipo de producto</span>
              <select className={inputCls} value={f.product_type_id} onChange={(e) => set("product_type_id", e.target.value)}>
                <option value="">Seleccione…</option>{meta.types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></label>
            <label className="block text-sm"><span className={label}>Categoría</span>
              <select className={inputCls} value={f.category_id} onChange={(e) => { set("category_id", e.target.value); set("subcategory_id", ""); }}>
                <option value="">Seleccione…</option>{meta.categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label>
            <label className="block text-sm"><span className={label}>Subcategoría</span>
              <select className={inputCls} value={f.subcategory_id} onChange={(e) => set("subcategory_id", e.target.value)}>
                <option value="">(ninguna)</option>{category?.subcategories.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
            {type && <p className="text-xs text-slate-500 sm:col-span-3">{type.requires_components ? "Exige componentes. " : ""}{type.requires_itinerary ? "Exige itinerario día a día." : ""}</p>}
          </div>
        )}

        {step === 2 && (
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm"><span className={label}>Destino principal</span>
              <select className={inputCls} value={f.primary_destination_id} onChange={(e) => set("primary_destination_id", e.target.value)}>
                <option value="">Seleccione…</option>
                {meta.destinations.map((d) => <option key={d.id} value={d.id}>{destinationPath(meta.destinations, d.id)}</option>)}</select></label>
            <fieldset className="text-sm"><legend className={label}>Destinos secundarios</legend>
              <div className="max-h-48 space-y-1 overflow-y-auto rounded border border-slate-200 p-2">
                {meta.destinations.filter((d) => d.id !== f.primary_destination_id).map((d) => (
                  <label key={d.id} className="flex items-center gap-2">
                    <input type="checkbox" checked={f.secondary_destination_ids.includes(d.id)}
                      onChange={(e) => set("secondary_destination_ids", e.target.checked ? [...f.secondary_destination_ids, d.id] : f.secondary_destination_ids.filter((x) => x !== d.id))} />
                    {destinationPath(meta.destinations, d.id)}
                  </label>
                ))}
              </div></fieldset>
          </div>
        )}

        {step === 3 && (
          <div className="space-y-3">
            {components.map((c, i) => (
              <div key={i} className="grid items-end gap-2 sm:grid-cols-[1fr_100px_140px_auto]">
                <label className="block text-sm"><span className={label}>Servicio</span>
                  <select className={inputCls} value={c.service_id} onChange={(e) => setComponents(components.map((x, j) => j === i ? { ...x, service_id: e.target.value } : x))}>
                    {meta.services.map((s) => (
                      <option key={s.id} value={s.id} disabled={!s.selectable}>{s.ref} · {s.name} ({s.provider?.name}){!s.selectable ? " · PRD-BR-007" : ""}</option>
                    ))}</select></label>
                <label className="block text-sm"><span className={label}>Cantidad</span>
                  <input type="number" min={1} className={inputCls} value={c.quantity} onChange={(e) => setComponents(components.map((x, j) => j === i ? { ...x, quantity: Number(e.target.value) } : x))} /></label>
                <label className="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" checked={c.is_included} onChange={(e) => setComponents(components.map((x, j) => j === i ? { ...x, is_included: e.target.checked } : x))} /> Incluido</label>
                <button type="button" className={btnGhostCls} onClick={() => setComponents(components.filter((_, j) => j !== i))}>Quitar</button>
              </div>
            ))}
            <button type="button" className={btnGhostCls} disabled={!selectable.length}
              onClick={() => setComponents([...components, { service_id: (selectable.find((s) => !components.some((c) => c.service_id === s.id)) ?? selectable[0]).id, quantity: 1, is_included: true }])}>
              Agregar componente
            </button>
          </div>
        )}

        {step === 4 && (
          <div className="space-y-4">
            {days.map((d, i) => (
              <div key={i} className="rounded border border-slate-200 p-3">
                <div className="mb-2 flex items-end gap-2">
                  <label className="block flex-1 text-sm"><span className={label}>Día {i + 1}</span>
                    <input className={inputCls} value={d.title} onChange={(e) => setDays(days.map((x, j) => j === i ? { ...x, title: e.target.value } : x))} /></label>
                  {days.length > 1 && <button type="button" className={btnGhostCls} onClick={() => setDays(days.filter((_, j) => j !== i))}>Quitar día</button>}
                </div>
                {d.items.map((it, k) => (
                  <div key={k} className="mb-2 grid gap-2 sm:grid-cols-[110px_1fr_160px]">
                    <input type="time" aria-label="Hora" className={inputCls} value={it.start_time}
                      onChange={(e) => setDays(days.map((x, j) => j === i ? { ...x, items: x.items.map((y, m) => m === k ? { ...y, start_time: e.target.value } : y) } : x))} />
                    <input aria-label="Actividad" placeholder="Actividad" className={inputCls} value={it.activity}
                      onChange={(e) => setDays(days.map((x, j) => j === i ? { ...x, items: x.items.map((y, m) => m === k ? { ...y, activity: e.target.value } : y) } : x))} />
                    <select aria-label="Tipo" className={inputCls} value={it.item_type}
                      onChange={(e) => setDays(days.map((x, j) => j === i ? { ...x, items: x.items.map((y, m) => m === k ? { ...y, item_type: e.target.value } : y) } : x))}>
                      {meta.enums.itinerary_types.map((t) => <option key={t}>{t}</option>)}</select>
                  </div>
                ))}
                <button type="button" className="text-sm text-sky-800 hover:underline"
                  onClick={() => setDays(days.map((x, j) => j === i ? { ...x, items: [...x.items, { start_time: "", activity: "", item_type: meta.enums.itinerary_types[0] }] } : x))}>+ Actividad</button>
              </div>
            ))}
            <button type="button" className={btnGhostCls} onClick={() => setDays([...days, { title: `Día ${days.length + 1}`, items: [{ start_time: "", activity: "", item_type: meta.enums.itinerary_types[0] }] }])}>Agregar día</button>
          </div>
        )}

        {step === 5 && (
          <div className="space-y-3">
            {rates.map((r, i) => {
              const upd = (k: keyof RateRow, v: string) => setRates(rates.map((x, j) => j === i ? { ...x, [k]: v } : x));
              return (
                <div key={i} className="grid gap-2 rounded border border-slate-200 p-3 sm:grid-cols-4">
                  <select aria-label="Tipo de tarifa" className={inputCls} value={r.rate_type} onChange={(e) => upd("rate_type", e.target.value)}>{meta.enums.rate_types.map((t) => <option key={t}>{t}</option>)}</select>
                  <select aria-label="Segmento" className={inputCls} value={r.segment} onChange={(e) => upd("segment", e.target.value)}>{meta.enums.segments.map((t) => <option key={t}>{t}</option>)}</select>
                  <select aria-label="Canal" className={inputCls} value={r.channel} onChange={(e) => upd("channel", e.target.value)}>{meta.enums.rate_channels.map((t) => <option key={t}>{t}</option>)}</select>
                  <select aria-label="Moneda" className={inputCls} value={r.currency} onChange={(e) => upd("currency", e.target.value)}><option>USD</option><option>PEN</option></select>
                  <input aria-label="Costo" type="number" min={0} step="0.01" placeholder="Costo" className={inputCls} value={r.cost} onChange={(e) => upd("cost", e.target.value)} />
                  <input aria-label="Precio" type="number" min={0} step="0.01" placeholder="Precio" className={inputCls} value={r.price} onChange={(e) => upd("price", e.target.value)} />
                  <input aria-label="Vigente desde" type="date" className={inputCls} value={r.valid_from} onChange={(e) => upd("valid_from", e.target.value)} />
                  <input aria-label="Vigente hasta" type="date" className={inputCls} value={r.valid_to} onChange={(e) => upd("valid_to", e.target.value)} />
                  {rates.length > 1 && <button type="button" className="text-left text-sm text-rose-700 hover:underline" onClick={() => setRates(rates.filter((_, j) => j !== i))}>Quitar tarifa</button>}
                </div>
              );
            })}
            <button type="button" className={btnGhostCls} onClick={() => setRates([...rates, { ...rates[rates.length - 1], segment: "Niño", cost: "", price: "" }])}>Agregar tarifa</button>
          </div>
        )}

        {step === 6 && (
          <fieldset className="space-y-1 text-sm"><legend className={label}>Temporadas que aplican</legend>
            {meta.seasons.length === 0 && <p className="text-slate-500">No hay temporadas activas.</p>}
            {meta.seasons.map((s) => (
              <label key={s.id} className="flex items-center gap-2">
                <input type="checkbox" checked={f.season_ids.includes(s.id)} onChange={(e) => set("season_ids", e.target.checked ? [...f.season_ids, s.id] : f.season_ids.filter((x) => x !== s.id))} />
                {s.ref} · {s.name} ({s.date_from} → {s.date_to}, factor {s.factor})
              </label>
            ))}
          </fieldset>
        )}

        {step === 7 && (
          <div className="grid gap-3 sm:grid-cols-3">
            <label className="block text-sm"><span className={label}>Modelo de disponibilidad</span>
              <select className={inputCls} value={f.availability_model} onChange={(e) => set("availability_model", e.target.value)}>{meta.enums.availability_models.map((m) => <option key={m}>{m}</option>)}</select></label>
            <label className="block text-sm"><span className={label}>Capacidad de referencia</span>
              <input type="number" min={0} className={inputCls} value={f.availability_capacity} onChange={(e) => set("availability_capacity", e.target.value)} /></label>
            <label className="block text-sm"><span className={label}>Fuente</span>
              <select className={inputCls} value={f.availability_source} onChange={(e) => set("availability_source", e.target.value)}><option value="interna">Interna</option><option value="externa">Externa</option></select></label>
          </div>
        )}

        {step === 8 && (
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm sm:col-span-2"><span className={label}>Descripción larga</span>
              <textarea rows={4} className={inputCls} value={f.long_description} onChange={(e) => set("long_description", e.target.value)} /></label>
            <label className="block text-sm"><span className={label}>Características (una por línea)</span>
              <textarea rows={4} className={inputCls} value={f.features} onChange={(e) => set("features", e.target.value)} /></label>
            <label className="block text-sm"><span className={label}>Título SEO</span>
              <input className={inputCls} value={f.seo_title} onChange={(e) => set("seo_title", e.target.value)} /></label>
          </div>
        )}

        {step === 9 && (
          <div className="space-y-3 text-sm">
            <fieldset><legend className={label}>Canales solicitados (efectivos al activar; ERP siempre publicado)</legend>
              <div className="grid gap-1 sm:grid-cols-3">
                {Object.entries(meta.enums.channels).filter(([c]) => c !== "erp").map(([c, l]) => (
                  <label key={c} className="flex items-center gap-2">
                    <input type="checkbox" checked={f.channels.includes(c)} onChange={(e) => set("channels", e.target.checked ? [...f.channels, c] : f.channels.filter((x) => x !== c))} />{l}
                  </label>
                ))}
              </div></fieldset>
            <dl className="grid gap-2 rounded bg-slate-50 p-3 sm:grid-cols-3">
              <div><dt className="text-xs text-slate-500">Producto</dt><dd>{f.name || "—"} ({meta.sku_prefix}-{f.code})</dd></div>
              <div><dt className="text-xs text-slate-500">Tipo</dt><dd>{type?.name ?? "—"} · {category?.name ?? "—"}</dd></div>
              <div><dt className="text-xs text-slate-500">Destino</dt><dd>{destinationPath(meta.destinations, f.primary_destination_id) || "—"}</dd></div>
              <div><dt className="text-xs text-slate-500">Componentes</dt><dd>{components.length}</dd></div>
              <div><dt className="text-xs text-slate-500">Días de itinerario</dt><dd>{days.length}</dd></div>
              <div><dt className="text-xs text-slate-500">Tarifas</dt><dd>{rates.map((r) => `${r.segment} ${price(r.price || 0, r.currency)}`).join(" · ")}</dd></div>
            </dl>
            <p className="text-slate-600">Se creará en <strong>Borrador</strong> con la versión 0.1.</p>
          </div>
        )}
      </section>

      {errors.length > 0 && (
        <ul className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200" role="alert">
          {errors.map((e) => <li key={e}>{e}</li>)}
        </ul>
      )}

      <div className="flex gap-2">
        <button type="button" className={btnGhostCls} disabled={step === 0} onClick={() => { setErrors([]); setStep(step - 1); }}>Anterior</button>
        {step < STEPS.length - 1
          ? <button type="button" className={btnCls} onClick={next}>Siguiente</button>
          : <button type="button" className={btnCls} disabled={pending} onClick={submit}>{pending ? "Creando…" : "Crear producto en borrador"}</button>}
      </div>
    </div>
  );
}
