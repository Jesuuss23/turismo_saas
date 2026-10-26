"use client";

import { useCallback, useEffect, useMemo, useRef, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { callApi } from "@/app/(app)/crm/actions";
import { price } from "@/lib/catalog";
import { marginTone, type QuoteMeta } from "@/lib/quotes";
import { btnCls, btnGhostCls, inputCls, Notice } from "@/components/crm/ui";

export type PickerProduct = { id: string; ref: string; name: string; type: string | null; destination: string | null; status: string; blocked_reason: string | null; rates_valid: number; from: string | null };
type RateOption = { id: string; ref: string; label: string; segment: string; channel: string; valid: boolean; disabled_reason: string | null };
type Suggestion = {
  product: { id: string; ref: string; name: string; type: string | null; blocked_reason: string | null };
  rate_id: string | null; unit_price: string | null; unit_cost?: string | null; season: { name: string; factor: string } | null; season_factor: string | null;
  defaults: { qty: number; pax: number; segment: string }; manual: boolean; notice: string | null; rates: RateOption[];
};
export type Line = {
  key: string; product_id: string; product_label: string; rate_id: string | null; rates: RateOption[]; segment: string; service_date: string;
  qty: number; pax: number; unit_cost: string; unit_price: string; season: string | null; notice: string | null;
};
export type Header = {
  client_id: string; opportunity_id: string; title: string; travel_from: string; travel_to: string; valid_until: string; currency: "PEN" | "USD";
  pax_adt: number; pax_chd: number; pax_inf: number; discount_pct: string; tax_pct: string; notes: string; conditions: string;
};
type Preview = {
  lines: { line_no: number; unit_price: string; sale_total: string; unit_cost?: string; margin_pct?: string; season_factor: string | null }[];
  totals: { sale: string; discount: string; net: string; tax: string; total: string; tax_pct: string; total_pen: string; cost?: string; profit?: string; margin_pct?: string; markup_pct?: string };
  needs_internal: boolean; reasons: string[]; over_cap: boolean;
};

const label = "mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500";
const round2 = (n: number) => (Math.round(n * 100) / 100).toFixed(2);

/**
 * Constructor de cotizaciones (PROC-021, §2): precio sugerido por tarifa × temporada (PRC-01/02/03),
 * previsualización de totales y política en el backend (RT-5) y guardado como borrador.
 */
export function Builder({ meta, products, initial, initialLines, edit }: {
  meta: QuoteMeta; products: PickerProduct[]; initial: Header; initialLines: Line[];
  edit?: { code: string; version: number; lock: number };
}) {
  const router = useRouter();
  const [h, setH] = useState<Header>(initial);
  const [lines, setLines] = useState<Line[]>(initialLines);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [picked, setPicked] = useState("");
  const [saving, startSaving] = useTransition();
  const [idem] = useState(() => crypto.randomUUID());
  const seq = useRef(0);

  const client = meta.clients.find((c) => c.id === h.client_id);
  const opportunities = meta.opportunities.filter((o) => o.client_id === h.client_id);
  const set = <K extends keyof Header>(key: K, value: Header[K]) => setH((prev) => ({ ...prev, [key]: value }));
  const patch = (key: string, values: Partial<Line>) => setLines((prev) => prev.map((l) => (l.key === key ? { ...l, ...values } : l)));

  const suggest = useCallback(async (productId: string, date: string, rateId?: string | null) => {
    const params = new URLSearchParams({ date, currency: h.currency, pax_adt: String(h.pax_adt), pax_chd: String(h.pax_chd), fx_rate: meta.defaults.fx });
    if (h.client_id) params.set("client_id", h.client_id);
    if (rateId) params.set("rate_id", rateId);
    const res = await callApi(`/catalog/products/${productId}/rate-suggestion?${params}`, "GET", null);
    if (res?.error) {
      setError(res.error);
      return null;
    }
    return res?.data as Suggestion;
  }, [h.currency, h.pax_adt, h.pax_chd, h.client_id, meta.defaults.fx]);

  async function addProduct() {
    const product = products.find((p) => p.id === picked);
    if (!product || product.blocked_reason) return;
    const date = h.travel_from || meta.defaults.today;
    const s = await suggest(product.id, date);
    if (!s) return;
    setLines((prev) => [...prev, {
      key: crypto.randomUUID(), product_id: product.id, product_label: `${product.ref} · ${product.name}`, rate_id: s.rate_id, rates: s.rates,
      segment: s.defaults.segment, service_date: date, qty: s.defaults.qty, pax: s.defaults.pax,
      unit_cost: s.unit_cost ?? "", unit_price: s.unit_price ?? "", season: s.season ? `${s.season.name} ×${s.season.factor}` : null, notice: s.notice,
    }]);
    setPicked("");
  }

  /** PRC-01: cambiar tarifa o fecha recalcula costo y precio; si la tarifa deja de estar vigente se quita (QTE-BR-06). */
  async function resuggest(line: Line, changes: Partial<Line>) {
    const next = { ...line, ...changes };
    patch(line.key, changes);
    const s = await suggest(next.product_id, next.service_date, next.rate_id);
    if (!s) return;
    const lost = next.rate_id !== null && s.rate_id === null;
    patch(line.key, {
      rate_id: s.rate_id, rates: s.rates, season: s.season ? `${s.season.name} ×${s.season.factor}` : null,
      notice: lost ? "La tarifa no está vigente para la nueva fecha (PRD-BR-005): se quitó; ingrese el precio manualmente." : s.notice,
      ...(s.rate_id ? { unit_price: s.unit_price ?? next.unit_price, unit_cost: s.unit_cost ?? next.unit_cost } : {}),
      ...(s.rate_id && s.rates.find((r) => r.id === s.rate_id)?.segment === "Niño" ? { segment: "Niño" } : {}),
    });
  }

  /** PRC-06: cambiar la moneda convierte todos los importes (las líneas con tarifa se vuelven a sugerir). */
  async function changeCurrency(currency: "PEN" | "USD") {
    const from = h.currency;
    if (from === currency) return;
    const fx = Number(meta.defaults.fx);
    const conv = (v: string) => (v === "" ? "" : round2(from === "USD" ? Number(v) * fx : Number(v) / fx));
    setH((prev) => ({ ...prev, currency }));
    setLines((prev) => prev.map((l) => ({ ...l, unit_cost: conv(l.unit_cost), unit_price: conv(l.unit_price) })));
  }

  // Al cambiar la moneda, las líneas con tarifa toman el precio sugerido en la nueva moneda.
  const currencyRef = useRef(h.currency);
  useEffect(() => {
    if (currencyRef.current === h.currency) return;
    currencyRef.current = h.currency;
    lines.filter((l) => l.rate_id).forEach((l) => { void resuggest(l, {}); });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [h.currency]);

  const body = useMemo(() => ({
    ...h,
    opportunity_id: h.opportunity_id || null,
    discount_pct: Number(h.discount_pct || 0),
    tax_pct: h.tax_pct === "" ? null : Number(h.tax_pct),
    notes: h.notes || null,
    conditions: h.conditions || null,
    items: lines.map((l) => ({
      product_id: l.product_id, rate_id: l.rate_id, segment: l.segment, service_date: l.service_date, qty: Number(l.qty), pax: Number(l.pax),
      unit_cost: l.unit_cost === "" ? null : Number(l.unit_cost), unit_price: l.unit_price === "" ? null : Number(l.unit_price),
    })),
  }), [h, lines]);

  // RT-5: el backend calcula totales, margen y motivos de aprobación (previsualización con debounce).
  useEffect(() => {
    if (!lines.length) {
      setPreview(null);
      return;
    }
    const id = ++seq.current;
    const timer = setTimeout(async () => {
      const res = await callApi("/quotes/pricing/preview", "POST", { ...body, fx_rate: meta.defaults.fx });
      if (id !== seq.current) return;
      if (res?.error) {
        setPreviewError([res.error, ...Object.values(res.fieldErrors ?? {}).flat()].join(" · "));
        return;
      }
      setPreviewError(null);
      setPreview(res?.data as Preview);
    }, 350);
    return () => clearTimeout(timer);
  }, [body, lines.length, meta.defaults.fx]);

  function save() {
    setError(null);
    startSaving(async () => {
      const res = edit
        ? await callApi(`/quotes/${edit.code}/versions/${edit.version}`, "PUT", body, { "If-Match": String(edit.lock) })
        : await callApi("/quotes", "POST", body, { "Idempotency-Key": idem });
      if (res?.error) {
        setError([res.error, ...Object.values(res.fieldErrors ?? {}).flat()].join(" · "));
        return;
      }
      router.push(`/cotizaciones/${(res?.data as { code: string }).code}`);
      router.refresh();
    });
  }

  const t = preview?.totals;
  const maxDiscount = meta.defaults.max_discount;

  return (
    <div className="grid gap-4 xl:grid-cols-[1fr_340px]">
      <div className="space-y-4">
        <section className="rounded-lg border border-slate-200 bg-white p-4">
          <h2 className="mb-3 text-sm font-semibold text-slate-800">Datos generales</h2>
          <div className="grid gap-3 md:grid-cols-2">
            <label className="block text-sm"><span className={label}>Cliente</span>
              <select value={h.client_id} onChange={(e) => {
                const c = meta.clients.find((x) => x.id === e.target.value);
                setH((prev) => ({ ...prev, client_id: e.target.value, opportunity_id: "", tax_pct: c ? String(Number(c.tax_pct)) : prev.tax_pct }));
              }} className={inputCls} required disabled={!!edit}>
                <option value="">Seleccione…</option>
                {meta.clients.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
              </select></label>
            <label className="block text-sm"><span className={label}>Oportunidad</span>
              <select value={h.opportunity_id} onChange={(e) => set("opportunity_id", e.target.value)} className={inputCls} disabled={!h.client_id}>
                <option value="">(sin oportunidad)</option>
                {opportunities.map((o) => <option key={o.id} value={o.id}>{o.code} · {o.title}</option>)}
              </select></label>
            <label className="block text-sm md:col-span-2"><span className={label}>Título</span>
              <input value={h.title} onChange={(e) => set("title", e.target.value)} className={inputCls} maxLength={200} required /></label>
            <label className="block text-sm"><span className={label}>Viaje desde</span>
              <input type="date" value={h.travel_from} onChange={(e) => set("travel_from", e.target.value)} className={inputCls} required /></label>
            <label className="block text-sm"><span className={label}>Viaje hasta</span>
              <input type="date" value={h.travel_to} onChange={(e) => set("travel_to", e.target.value)} className={inputCls} required /></label>
            <label className="block text-sm"><span className={label}>Válida hasta</span>
              <input type="date" value={h.valid_until} onChange={(e) => set("valid_until", e.target.value)} className={inputCls} required /></label>
            <label className="block text-sm"><span className={label}>Moneda</span>
              <select value={h.currency} onChange={(e) => void changeCurrency(e.target.value as "PEN" | "USD")} className={inputCls}>
                <option value="PEN">PEN (S/)</option><option value="USD">USD (US$)</option>
              </select></label>
            <div className="grid grid-cols-3 gap-2 md:col-span-2">
              {([["pax_adt", "Adultos (ADT)"], ["pax_chd", "Niños (CHD)"], ["pax_inf", "Infantes (INF)"]] as const).map(([k, l]) => (
                <label key={k} className="block text-sm"><span className={label}>{l}</span>
                  <input type="number" min={0} value={h[k]} onChange={(e) => set(k, Number(e.target.value))} className={inputCls} /></label>
              ))}
            </div>
          </div>
          {client && client.country !== "Perú" && <p className="mt-2 text-xs text-slate-600">Cliente no domiciliado ({client.country}): IGV 0 % por exportación de servicios (PRC-07).</p>}
        </section>

        <section className="rounded-lg border border-slate-200 bg-white p-4">
          <h2 className="mb-3 text-sm font-semibold text-slate-800">Ítems</h2>
          <div className="mb-3 flex flex-wrap items-end gap-2">
            <label className="block min-w-[18rem] flex-1 text-sm"><span className={label}>Producto del catálogo</span>
              <select value={picked} onChange={(e) => setPicked(e.target.value)} className={inputCls} aria-label="Producto del catálogo">
                <option value="">Seleccione un producto…</option>
                {products.map((p) => (
                  <option key={p.id} value={p.id} disabled={!!p.blocked_reason}>
                    {p.ref} · {p.name}{p.blocked_reason ? ` — ${p.blocked_reason} (PRD-BR-002)` : p.from ? ` — desde ${price(p.from, "PEN")}` : ""}
                  </option>
                ))}
              </select></label>
            <button type="button" className={btnGhostCls} onClick={() => void addProduct()} disabled={!picked}>Agregar ítem</button>
          </div>
          {lines.length === 0 && <p className="text-sm text-slate-500">Agregue al menos un ítem (QTE-BR-04).</p>}
          <div className="space-y-3">
            {lines.map((l, i) => {
              const p = preview?.lines[i];
              return (
                <div key={l.key} className="rounded-md border border-slate-200 p-3" data-testid="quote-line">
                  <div className="mb-2 flex items-start justify-between gap-2">
                    <p className="text-sm font-medium text-slate-800">{i + 1}. {l.product_label}</p>
                    <button type="button" className="text-xs text-rose-700 hover:underline" onClick={() => setLines((prev) => prev.filter((x) => x.key !== l.key))}>Quitar</button>
                  </div>
                  <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
                    <label className="block text-sm sm:col-span-2"><span className={label}>Tarifa</span>
                      <select value={l.rate_id ?? ""} onChange={(e) => void resuggest(l, { rate_id: e.target.value || null })} className={inputCls} aria-label={`Tarifa ítem ${i + 1}`}>
                        <option value="">Precio manual</option>
                        {l.rates.map((r) => <option key={r.id} value={r.id} disabled={!r.valid}>{r.label}{r.disabled_reason ? ` — ${r.disabled_reason}` : ""}</option>)}
                      </select></label>
                    <label className="block text-sm"><span className={label}>Fecha</span>
                      <input type="date" value={l.service_date} onChange={(e) => void resuggest(l, { service_date: e.target.value })} className={inputCls} aria-label={`Fecha ítem ${i + 1}`} /></label>
                    <label className="block text-sm"><span className={label}>Segmento</span>
                      <select value={l.segment} onChange={(e) => patch(l.key, { segment: e.target.value })} className={inputCls}>
                        {meta.segments.map((s) => <option key={s}>{s}</option>)}
                      </select></label>
                    <label className="block text-sm"><span className={label}>Cant.</span>
                      <input type="number" min={1} value={l.qty} onChange={(e) => patch(l.key, { qty: Number(e.target.value) })} className={inputCls} aria-label={`Cantidad ítem ${i + 1}`} /></label>
                    <label className="block text-sm"><span className={label}>Pax</span>
                      <input type="number" min={1} value={l.pax} onChange={(e) => patch(l.key, { pax: Number(e.target.value) })} className={inputCls} aria-label={`Pax ítem ${i + 1}`} /></label>
                    {meta.can.see_margin && (
                      <label className="block text-sm"><span className={label}>Costo unit.</span>
                        <input type="number" min={0} step="0.01" value={l.unit_cost} onChange={(e) => patch(l.key, { unit_cost: e.target.value })} className={inputCls} aria-label={`Costo ítem ${i + 1}`} /></label>
                    )}
                    <label className="block text-sm"><span className={label}>Precio unit.</span>
                      <input type="number" min={0.01} step="0.01" value={l.unit_price} onChange={(e) => patch(l.key, { unit_price: e.target.value })} className={inputCls} aria-label={`Precio ítem ${i + 1}`} /></label>
                    <div className="text-sm sm:col-span-2">
                      <span className={label}>Subtotal</span>
                      <p className="py-2 font-medium">{p ? price(p.sale_total, h.currency) : "—"}
                        {p?.margin_pct !== undefined && <span className={`ml-2 text-xs ${marginTone(p.margin_pct)}`}>margen {p.margin_pct} %</span>}</p>
                    </div>
                  </div>
                  {(l.season || l.notice) && <p className="mt-1 text-xs text-slate-600">{l.season && `Temporada aplicada: ${l.season}. `}{l.notice && <span className="text-amber-800">{l.notice}</span>}</p>}
                </div>
              );
            })}
          </div>
        </section>

        <section className="rounded-lg border border-slate-200 bg-white p-4">
          <h2 className="mb-3 text-sm font-semibold text-slate-800">Notas y condiciones</h2>
          <div className="grid gap-3 md:grid-cols-2">
            <label className="block text-sm"><span className={label}>Notas</span>
              <textarea rows={3} value={h.notes} onChange={(e) => set("notes", e.target.value)} className={inputCls} /></label>
            <label className="block text-sm"><span className={label}>Condiciones</span>
              <textarea rows={3} value={h.conditions} onChange={(e) => set("conditions", e.target.value)} className={inputCls} /></label>
          </div>
        </section>
      </div>

      <aside className="space-y-3 xl:sticky xl:top-4 xl:self-start">
        <section className="rounded-lg border border-slate-200 bg-white p-4" data-testid="quote-totals">
          <h2 className="mb-3 text-sm font-semibold text-slate-800">Totales</h2>
          <div className="mb-3 grid grid-cols-2 gap-2">
            <label className="block text-sm"><span className={label}>Descuento %</span>
              <input type="number" min={0} max={maxDiscount} step="0.01" value={h.discount_pct} onChange={(e) => set("discount_pct", e.target.value)} className={inputCls} aria-label="Descuento %" /></label>
            <label className="block text-sm"><span className={label}>IGV %</span>
              <input type="number" min={0} max={18} step="0.01" value={h.tax_pct} onChange={(e) => set("tax_pct", e.target.value)} className={inputCls} aria-label="IGV %" /></label>
          </div>
          <p className="mb-2 text-xs text-slate-500">Tope de descuento {meta.can.discount_override ? `${maxDiscount} % (override)` : `${maxDiscount} % (QTE-BR-08)`}. Más de {meta.defaults.discount_internal} % o margen menor a {meta.defaults.margin_min} % requiere aprobación de gerencia.</p>
          {t ? (
            <dl className="space-y-1 text-sm">
              <div className="flex justify-between"><dt>Venta bruta</dt><dd>{price(t.sale, h.currency)}</dd></div>
              <div className="flex justify-between"><dt>Descuento</dt><dd>− {price(t.discount, h.currency)}</dd></div>
              <div className="flex justify-between"><dt>Valor de venta</dt><dd>{price(t.net, h.currency)}</dd></div>
              <div className="flex justify-between"><dt>IGV ({Number(t.tax_pct)} %)</dt><dd>{price(t.tax, h.currency)}</dd></div>
              <div className="flex justify-between border-t border-slate-200 pt-1 text-base font-semibold"><dt>Total</dt><dd>{price(t.total, h.currency)}</dd></div>
              {h.currency === "USD" && <div className="flex justify-between text-xs text-slate-500"><dt>Equivalente en soles</dt><dd>{price(t.total_pen, "PEN")}</dd></div>}
              {t.margin_pct !== undefined && <>
                <div className="flex justify-between pt-2 text-slate-600"><dt>Costo</dt><dd>{price(t.cost, h.currency)}</dd></div>
                <div className="flex justify-between text-slate-600"><dt>Utilidad</dt><dd>{price(t.profit, h.currency)}</dd></div>
                <div className={`flex justify-between font-medium ${marginTone(t.margin_pct)}`}><dt>Margen</dt><dd>{t.margin_pct} %</dd></div>
              </>}
            </dl>
          ) : <p className="text-sm text-slate-500">Agregue ítems para calcular.</p>}
          {previewError && <p className="mt-2 rounded-md bg-rose-50 px-3 py-2 text-xs text-rose-800 ring-1 ring-rose-200" role="alert">{previewError}</p>}
        </section>
        {preview?.needs_internal && (
          <Notice tone="warning">Requiere aprobación interna{preview.reasons.length ? `: ${preview.reasons.join("; ")}` : ""}. Podrá guardarla, pero no enviarla hasta que gerencia la apruebe (QTE-BR-12).</Notice>
        )}
        {error && <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200" role="alert">{error}</p>}
        <button type="button" className={`${btnCls} w-full`} onClick={save} disabled={saving || !lines.length}>
          {saving ? "Guardando…" : edit ? `Guardar versión ${edit.version}` : "Guardar borrador"}
        </button>
      </aside>
    </div>
  );
}
