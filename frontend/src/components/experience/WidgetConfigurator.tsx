"use client";

import { useState } from "react";
import { ActionForm } from "@/components/crm/ActionForm";
import { inputCls } from "@/components/crm/ui";
import { CopyBlock } from "./CopyBlock";
import { widgetSnippet } from "@/lib/widget-snippet";
import type { WidgetSettings } from "@/lib/experience";

type Defaults = WidgetSettings["defaults"];

/** Configurador con vista previa en vivo del widget real (`/widget/[code]`) dentro de una página WordPress simulada. */
export function WidgetConfigurator({ cfg, origin, currencies, categories, canEdit }: { cfg: WidgetSettings; origin: string; currencies: string[]; categories: string[]; canEdit: boolean }) {
  const [d, setD] = useState<Defaults>({ ...cfg.defaults, product: cfg.defaults.product ?? cfg.products.find((p) => p.ref === "PRD-013")?.ref ?? cfg.products[0]?.ref ?? null });
  const set = <K extends keyof Defaults>(k: K, v: Defaults[K]) => setD((x) => ({ ...x, [k]: v }));
  const verified = cfg.domains.filter((x) => x.status === "verificado");
  const preview = `/widget/${d.product ?? ""}?${new URLSearchParams({ lang: d.lang, currency: d.currency, theme: d.color.replace("#", ""), radius: String(d.radius), reviews: d.reviews ? "1" : "0", ...(d.category && !d.product ? { category: d.category } : {}) })}`;
  return (
    <div className="grid grid-cols-1 gap-4 [&>*]:min-w-0 xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
      <div className="space-y-4">
        <ActionForm path="/channels/widget" method="PUT" ifMatch={cfg.lock_version} submitLabel="Guardar como predeterminado" success="Configuración del widget guardada" disabled={!canEdit} disabledReason="Requiere permiso channels.ecommerce.manage" className="space-y-3 text-sm">
          <label className="block"><span className="mb-1 block font-medium text-slate-700">Método</span>
            <select name="method" value={d.method} onChange={(e) => set("method", e.target.value)} className={inputCls}>
              {Object.entries(cfg.methods).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </label>
          <label className="block"><span className="mb-1 block font-medium text-slate-700">Producto (vacío = catálogo por categoría)</span>
            <select name="product" value={d.product ?? ""} onChange={(e) => set("product", e.target.value || null)} className={inputCls}>
              <option value="">Catálogo</option>
              {cfg.products.map((p) => <option key={p.ref} value={p.ref}>{p.ref} · {p.name}</option>)}
            </select>
          </label>
          <label className="block"><span className="mb-1 block font-medium text-slate-700">Categoría del catálogo</span>
            <select name="category" value={d.category ?? ""} onChange={(e) => set("category", e.target.value || null)} className={inputCls}>
              <option value="">Todas</option>
              {categories.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
          </label>
          <div className="grid grid-cols-2 gap-2">
            <label className="block"><span className="mb-1 block font-medium text-slate-700">Idioma</span>
              <select name="lang" value={d.lang} onChange={(e) => set("lang", e.target.value)} className={inputCls}><option value="es">Español</option><option value="en">English</option></select>
            </label>
            <label className="block"><span className="mb-1 block font-medium text-slate-700">Moneda</span>
              <select name="currency" value={d.currency} onChange={(e) => set("currency", e.target.value)} className={inputCls}>{currencies.map((c) => <option key={c}>{c}</option>)}</select>
            </label>
            <label className="block"><span className="mb-1 block font-medium text-slate-700">Color</span>
              <input type="color" name="color" value={d.color} onChange={(e) => set("color", e.target.value)} className="h-9 w-full rounded-md border border-slate-300" />
            </label>
            <label className="block"><span className="mb-1 block font-medium text-slate-700">Radio ({d.radius} px)</span>
              <input type="range" name="radius" min={0} max={24} value={d.radius} onChange={(e) => set("radius", Number(e.target.value))} className="w-full" />
            </label>
          </div>
          <input type="hidden" name="reviews" value={d.reviews ? "true" : "false"} />
          <label className="flex items-center gap-2"><input type="checkbox" checked={d.reviews} onChange={(e) => set("reviews", e.target.checked)} />Mostrar reseñas</label>
          <label className="block"><span className="mb-1 block font-medium text-slate-700">Dominio donde se inserta</span>
            <select name="domain" value={d.domain ?? ""} onChange={(e) => set("domain", e.target.value || null)} className={inputCls}>
              <option value="">—</option>
              {verified.map((x) => <option key={x.id} value={x.domain}>{x.domain}</option>)}
            </select>
          </label>
        </ActionForm>
        <div>
          <p className="mb-1 text-sm font-medium">Código generado · {cfg.methods[d.method]}</p>
          <CopyBlock text={widgetSnippet(d, cfg.public_key, origin)} label="Copiar código" />
        </div>
      </div>
      <figure className="min-w-0 overflow-hidden rounded-lg border border-slate-300 bg-slate-100">
        <figcaption className="flex items-center gap-2 border-b border-slate-300 bg-slate-200 px-3 py-1.5 text-xs text-slate-600">
          <span className="flex gap-1"><i className="h-2.5 w-2.5 rounded-full bg-rose-400" /><i className="h-2.5 w-2.5 rounded-full bg-amber-400" /><i className="h-2.5 w-2.5 rounded-full bg-emerald-400" /></span>
          <span className="truncate">https://{d.domain ?? "www.su-agencia.com"}/tours/ · WordPress (vista previa)</span>
        </figcaption>
        <div className="bg-white px-4 py-3">
          <p className="text-lg font-semibold text-slate-800">Tours y experiencias</p>
          <p className="text-xs text-slate-500">Página de ejemplo del sitio de la agencia con el widget insertado.</p>
        </div>
        <iframe key={preview} src={preview} title="Vista previa del widget" className="h-[900px] w-full border-0 bg-white" />
      </figure>
    </div>
  );
}
