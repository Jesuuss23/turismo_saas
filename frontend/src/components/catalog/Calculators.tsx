"use client";

import { useState, useTransition } from "react";
import { callApi } from "@/app/(app)/crm/actions";
import { price } from "@/lib/catalog";
import { btnGhostCls, inputCls } from "@/components/crm/ui";

type Option = { id: string; label: string };
const label = "mb-1 block text-sm font-medium text-slate-700";

function useCall<T>() {
  const [result, setResult] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pending, start] = useTransition();
  const run = (path: string, body: unknown) => start(async () => {
    const res = await callApi(path, "POST", body);
    setError(res?.error ? [res.error, ...Object.values(res.fieldErrors ?? {}).flat()].join(" · ") : null);
    setResult(res?.error ? null : (res?.data as T));
  });
  return { result, error, pending, run };
}

function ErrorLine({ error }: { error: string | null }) {
  return error ? <p className="mt-2 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200" role="alert">{error}</p> : null;
}

type Quote = { rate: string; currency: string; base_price: string; options: { group: string; option: string; price_delta: string }[]; unit_price: string; pax: number; total: string };

/** Personalización: precio con las opciones elegidas (lo calcula el backend). */
export function PriceQuote({ productRef, rates, groups }: { productRef: string; rates: Option[]; groups: { id: string; name: string; options: { id: string; label: string }[] }[] }) {
  const { result, error, pending, run } = useCall<Quote>();
  return (
    <form className="space-y-2" onSubmit={(e) => {
      e.preventDefault();
      const fd = new FormData(e.currentTarget);
      const selections = Object.fromEntries(groups.map((g) => [g.id, String(fd.get(`g-${g.id}`) ?? "")]).filter(([, v]) => v !== ""));
      run(`/catalog/products/${productRef}/price-quote`, { rate_id: fd.get("rate_id"), pax: Number(fd.get("pax")), selections });
    }}>
      <label className="block text-sm"><span className={label}>Tarifa</span>
        <select name="rate_id" className={inputCls}>{rates.map((r) => <option key={r.id} value={r.id}>{r.label}</option>)}</select></label>
      {groups.map((g) => (
        <label key={g.id} className="block text-sm"><span className={label}>{g.name}</span>
          <select name={`g-${g.id}`} className={inputCls}><option value="">(sin elegir)</option>{g.options.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}</select></label>
      ))}
      <label className="block text-sm"><span className={label}>Pax</span><input name="pax" type="number" min={1} defaultValue={2} className={inputCls} /></label>
      <button className={btnGhostCls} disabled={pending || !rates.length}>{pending ? "Calculando…" : "Calcular"}</button>
      <ErrorLine error={error} />
      {result && (
        <dl className="mt-2 space-y-1 text-sm" data-testid="quote-result">
          <div className="flex justify-between"><dt>Precio base ({result.rate})</dt><dd>{price(result.base_price, result.currency)}</dd></div>
          {result.options.map((o) => <div key={o.group} className="flex justify-between"><dt>{o.group}: {o.option}</dt><dd>+{price(o.price_delta, result.currency)}</dd></div>)}
          <div className="flex justify-between"><dt>Precio unitario</dt><dd>{price(result.unit_price, result.currency)}</dd></div>
          <div className="flex justify-between font-semibold"><dt>Total {result.pax} pax</dt><dd>{price(result.total, result.currency)}</dd></div>
        </dl>
      )}
    </form>
  );
}

type Simulation = {
  product: string; rate: string; currency: string; date: string; pax: number; channel: string; residency: string; season: { name: string; factor: string } | null;
  steps: Record<string, string | null>; rules: { ref: string | null; name: string; kind: string; value: string }[];
};

const STEP_LABELS: [string, string][] = [
  ["cost", "Costo × pax"], ["season_adjustment", "Ajuste de temporada"], ["base", "Base"], ["extra", "Adicionales"], ["markup", "Markup"], ["subtotal", "Subtotal"],
  ["discount", "Descuento"], ["net", "Neto"], ["tax", "Impuesto"], ["total", "Total"], ["commission", "Comisión"], ["profit", "Utilidad"],
];

/** CAT-BR-42: simulador del motor de precios con desglose paso a paso. */
export function PricingSimulator({ products, channels, today }: { products: Option[]; channels: string[]; today: string }) {
  const { result, error, pending, run } = useCall<Simulation>();
  return (
    <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
      <form className="space-y-2" onSubmit={(e) => {
        e.preventDefault();
        const fd = new FormData(e.currentTarget);
        const body: Record<string, unknown> = {};
        fd.forEach((v, k) => { if (v !== "") body[k] = ["pax", "extra", "discount_fixed", "markup_override", "discount_override", "commission_pct"].includes(k) ? Number(v) : v; });
        run("/catalog/pricing/simulate", body);
      }}>
        <label className="block text-sm"><span className={label}>Producto</span>
          <select name="product_id" className={inputCls}>{products.map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}</select></label>
        <div className="grid grid-cols-2 gap-2">
          <label className="block text-sm"><span className={label}>Fecha</span><input name="date" type="date" defaultValue={today} className={inputCls} /></label>
          <label className="block text-sm"><span className={label}>Pax</span><input name="pax" type="number" min={1} defaultValue={2} className={inputCls} /></label>
          <label className="block text-sm"><span className={label}>Canal</span>
            <select name="channel" className={inputCls}>{channels.map((c) => <option key={c}>{c}</option>)}</select></label>
          <label className="block text-sm"><span className={label}>Residencia</span>
            <select name="residency" className={inputCls}><option value="dom">Domiciliado</option><option value="nodom">No domiciliado</option></select></label>
          <label className="block text-sm"><span className={label}>Adicionales</span><input name="extra" type="number" min={0} step="0.01" className={inputCls} /></label>
          <label className="block text-sm"><span className={label}>Markup % (override)</span><input name="markup_override" type="number" min={0} step="0.01" className={inputCls} /></label>
        </div>
        <button className={btnGhostCls} disabled={pending}>{pending ? "Simulando…" : "Simular"}</button>
        <ErrorLine error={error} />
      </form>
      {result ? (
        <div className="rounded-lg border border-slate-200 bg-white p-4 text-sm" data-testid="simulation">
          <p className="mb-2 text-slate-600">{result.product} · {result.rate} · {result.pax} pax · {result.channel} · {result.date}{result.season && ` · ${result.season.name} ×${result.season.factor}`}</p>
          <dl className="space-y-1">
            {STEP_LABELS.map(([k, l]) => result.steps[k] !== undefined && result.steps[k] !== null && (
              <div key={k} className={`flex justify-between ${k === "total" ? "border-t border-slate-200 pt-1 font-semibold" : ""}`}>
                <dt>{l}{k === "markup" && ` (${Number(result.steps.markup_pct)} %)`}{k === "discount" && ` (${Number(result.steps.discount_pct)} %)`}{k === "commission" && ` (${Number(result.steps.commission_pct)} %)`}</dt>
                <dd>{price(result.steps[k], result.currency)}</dd>
              </div>
            ))}
            <div className="flex justify-between"><dt>Margen</dt><dd>{result.steps.margin_pct} %</dd></div>
          </dl>
          <p className="mt-3 text-xs font-medium uppercase text-slate-500">Reglas aplicadas</p>
          <ul className="text-xs text-slate-700">{result.rules.map((r, i) => <li key={i}>{r.ref ?? "Manual"} · {r.name} ({r.kind} {r.value})</li>)}</ul>
        </div>
      ) : <p className="text-sm text-slate-500">Elija un producto y simule para ver el desglose.</p>}
    </div>
  );
}
