import type { WidgetSettings } from "./experience";

/** Código de inserción para cada método (spec 17 §2 «Métodos de integración» a–d). */
export function widgetSnippet(d: WidgetSettings["defaults"], key: string, origin: string) {
  const attrs = `lang="${d.lang}" currency="${d.currency}" color="${d.color}" radius="${d.radius}" reviews="${d.reviews}"`;
  const query = new URLSearchParams({ key, lang: d.lang, currency: d.currency, theme: d.color.replace("#", ""), radius: String(d.radius), reviews: d.reviews ? "1" : "0", ...(d.domain ? { domain: d.domain } : {}) });
  const target = d.product ? `product="${d.product}"` : `category="${d.category ?? ""}"`;
  switch (d.method) {
    case "iframe":
      return `<iframe id="qhapaq-widget" src="${origin}/widget/${d.product ?? ""}?${query}" title="Reservar" style="width:100%;border:0" height="900" loading="lazy"></iframe>
<script>
  window.addEventListener("message", function (e) {
    if (e.origin !== "${origin}" || !e.data || e.data.type !== "qhapaq:resize") return;
    document.getElementById("qhapaq-widget").style.height = e.data.height + "px";
  });
</script>`;
    case "wordpress":
      return d.product
        ? `[qhapaq_booking product="${d.product}" ${attrs}]\n\n<!-- Catálogo por categoría -->\n[qhapaq_catalog category="${d.category ?? "Aventura"}" ${attrs}]`
        : `[qhapaq_catalog category="${d.category ?? ""}" ${attrs}]`;
    case "headless":
      return `# 1. Producto, pasajeros, adicionales, alojamiento y reglas
curl -H "X-Qhapaq-Key: ${key}" https://api.qhapaq.app/v1/public/widget/products/${d.product ?? "PRD-013"}

# 2. Cotización (mismo motor que el widget)
curl -X POST -H "X-Qhapaq-Key: ${key}" -H "Content-Type: application/json" \\
  -d '{"product_id":"${d.product ?? "PRD-013"}","date":"2026-12-12","pax":{"adt":2},"currency":"${d.currency}"}' \\
  https://api.qhapaq.app/v1/public/quote

# 3. Traspaso al checkout (devuelve checkout_url)
curl -X POST -H "X-Qhapaq-Key: ${key}" -H "Idempotency-Key: <uuid>" -H "Content-Type: application/json" \\
  -d '{"product":"${d.product ?? "PRD-013"}","date":"2026-12-12","pax":{"adt":2},"currency":"${d.currency}","payment_mode":"total"}' \\
  https://api.qhapaq.app/v1/public/widget-handoffs`;
    default:
      return `<script src="https://cdn.qhapaq.app/widget.js" data-key="${key}" async></script>
<qhapaq-${d.product ? "booking" : "catalog"} ${target} ${attrs}></qhapaq-${d.product ? "booking" : "catalog"}>`;
  }
}
