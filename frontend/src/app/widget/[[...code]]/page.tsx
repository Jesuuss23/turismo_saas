import type { Metadata } from "next";
import { siteGet } from "@/lib/site";
import { BookingWidget, WidgetCatalog } from "@/components/experience/BookingWidget";
import type { PublicReviews, WidgetProduct, WidgetPublicConfig } from "@/lib/experience";

export const metadata: Metadata = { title: "Reservar", robots: { index: false } };

type Search = { lang?: string; currency?: string; theme?: string; radius?: string; reviews?: string; category?: string; domain?: string; key?: string };

/**
 * Widget público a pantalla completa (spec 17 §2, sin sesión): lo cargan el iframe, el Web Component y la vista previa del configurador.
 * Parámetros: `lang`, `currency`, `theme` (hex sin #), `radius`, `reviews`, `category`. Sin producto válido muestra los productos vendibles.
 */
export default async function WidgetPage({ params, searchParams }: { params: Promise<{ code?: string[] }>; searchParams: Promise<Search> }) {
  const [{ code }, sp] = await Promise.all([params, searchParams]);
  const config = await siteGet<WidgetPublicConfig>("widget/config", { session: false });
  if (!config) return <p className="p-6 text-sm">Widget no disponible.</p>;
  const ref = code?.[0]?.toUpperCase();
  const product = ref ? await siteGet<WidgetProduct>(`widget/products/${encodeURIComponent(ref)}`, { session: false }) : null;
  const d = config.defaults;
  const lang = sp.lang === "en" || sp.lang === "es" ? sp.lang : d.lang === "en" ? "en" : "es";
  const color = /^[0-9a-fA-F]{6}$/.test(sp.theme ?? "") ? `#${sp.theme}` : d.color;
  const radius = Math.min(24, Math.max(0, Number(sp.radius ?? d.radius) || 0));
  const showReviews = sp.reviews !== undefined ? sp.reviews === "1" || sp.reviews === "true" : d.reviews;
  const query = new URLSearchParams(Object.entries(sp).filter(([k, v]) => typeof v === "string" && k !== "category") as [string, string][]).toString();

  if (!product) return <WidgetCatalog config={config} lang={lang} query={query} category={sp.category ?? null} />;

  const reviews = showReviews ? await siteGet<PublicReviews>("reviews", { session: false, query: { count: 3 } }).catch(() => null) : null;
  return (
    <main className="min-h-screen bg-slate-50">
      <BookingWidget product={product} config={config} initial={{ lang, currency: (sp.currency ?? d.currency).toUpperCase(), color, radius }} />
      {reviews && reviews.items.length > 0 && (
        <section className="mx-auto max-w-3xl px-3 pb-6 sm:px-5" aria-label={lang === "en" ? "Reviews" : "Reseñas"}>
          <h2 className="mb-2 text-sm font-semibold">{lang === "en" ? "What our travellers say" : "Lo que dicen nuestros viajeros"}{reviews.is_example && <span className="ml-2 text-xs font-normal text-slate-500">({lang === "en" ? "sample data" : "datos de ejemplo"})</span>}</h2>
          <div className="grid gap-2 sm:grid-cols-3">
            {reviews.items.map((r, i) => (
              <blockquote key={i} className="rounded-lg border border-slate-200 bg-white p-3 text-xs">
                <p className="text-amber-600" aria-label={`${r.rating} / 5`}>{"★".repeat(r.rating)}{"☆".repeat(5 - r.rating)}</p>
                <p className="mt-1 line-clamp-4 text-slate-700">{r.body}</p>
                <footer className="mt-1 text-slate-500">{r.author} · {r.source_name}</footer>
              </blockquote>
            ))}
          </div>
          <p className="mt-1 text-[11px] text-slate-500">{reviews.attribution}</p>
        </section>
      )}
    </main>
  );
}
