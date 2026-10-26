import "server-only";
import { api } from "./api";
import { addDays } from "./catalog";
import type { Line, PickerProduct } from "@/components/quotes/Builder";
import type { QuoteItem, QuoteMeta } from "./quotes";

/** Datos del constructor: meta de cotizaciones y productos cotizables (PRD-BR-002 marcado por el backend). */
export async function builderData() {
  const [{ data: meta }, { data: products }] = await Promise.all([
    api<{ data: QuoteMeta }>("/quotes/meta"),
    api<{ data: PickerProduct[] }>("/catalog/products/quoteable"),
  ]);
  return { meta, products, validUntil: addDays(meta.defaults.today, meta.defaults.validity_days) };
}

type RateOptions = { data: { rates: Line["rates"]; season: { name: string; factor: string } | null } };

/** Ítems guardados → líneas editables; las tarifas disponibles se piden por línea para la fecha del servicio. */
export async function linesFromItems(items: QuoteItem[], ctx: { currency: string; clientId: string | null; fx: string }): Promise<Line[]> {
  return Promise.all(items.map(async (i) => {
    let rates: Line["rates"] = [];
    let season: string | null = i.season ? `${i.season} ×${i.season_factor}` : null;
    if (i.product) {
      const params = new URLSearchParams({ date: i.service_date, currency: ctx.currency, fx_rate: ctx.fx });
      if (ctx.clientId) params.set("client_id", ctx.clientId);
      if (i.rate?.id) params.set("rate_id", i.rate.id);
      const res = await api<RateOptions>(`/catalog/products/${i.product.id}/rate-suggestion?${params}`).catch(() => null);
      rates = res?.data.rates ?? [];
      season ??= res?.data.season ? `${res.data.season.name} ×${res.data.season.factor}` : null;
    }
    return {
      key: i.id, product_id: i.product?.id ?? "", product_label: i.product ? `${i.product.ref} · ${i.product.name}` : "Producto",
      rate_id: i.rate?.id ?? null, rates, segment: i.segment, service_date: i.service_date, qty: i.qty, pax: i.pax,
      unit_cost: i.unit_cost ?? "", unit_price: i.unit_price, season, notice: null,
    };
  }));
}
