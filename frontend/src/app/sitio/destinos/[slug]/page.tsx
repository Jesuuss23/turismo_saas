import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { prefs, siteGet, siteInfo } from "@/lib/site";
import { fmtDate, type ProductCard as Card } from "@/lib/site-shared";
import { ProductCard } from "@/components/site/ProductCard";
import { Picture } from "@/components/site/Picture";

type Dest = {
  slug: string; name: string; tagline: string | null; altitude: string | null; description: string | null; climate: string | null; faqs: { q: string; a: string }[];
  lat: string | null; lng: string | null; products: Card[]; best_season: { month: string; kind: string; season: string | null }[]; special_dates: { name: string; from: string; to: string; factor: string }[];
};

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const d = await siteGet<Dest>(`destinations/${encodeURIComponent((await params).slug)}`);
  return d ? { title: `${d.name}: tours y paquetes`, description: d.tagline ?? undefined } : { title: "Destino" };
}

const KIND: Record<string, string> = { alta: "bg-rose-200", media: "bg-sky-100", baja: "bg-emerald-200", especial: "bg-violet-200" };

/** Destino (spec 15 §2): mejor época derivada de temporadas, clima, productos del subárbol y FAQ. */
export default async function DestinationPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const { locale } = await prefs();
  const en = locale === "en";
  const [d, site] = await Promise.all([siteGet<Dest>(`destinations/${encodeURIComponent(slug)}`), siteInfo()]);
  if (!d) notFound();
  return (
    <div>
      <Picture label={d.name} tone="emerald" className="h-48 sm:h-64" />
      <div className="mx-auto max-w-6xl px-4 py-6">
        <h1 className="text-3xl font-bold">{d.name}</h1>
        <p className="text-slate-600">{d.tagline} · {d.altitude}</p>
        <div className="mt-6 grid gap-8 lg:grid-cols-[1fr_320px]">
          <div className="min-w-0 space-y-6">
            <p className="text-slate-700">{d.description}</p>
            <section>
              <h2 className="font-semibold">{en ? "Best time to go" : "Mejor época"}</h2>
              <div className="mt-2 grid grid-cols-6 gap-1 sm:grid-cols-12" data-testid="best-season">
                {d.best_season.map((m) => (
                  <div key={m.month} title={m.season ?? ""} className={`rounded px-1 py-2 text-center text-[11px] ${KIND[m.kind] ?? "bg-slate-100"}`}>{fmtDate(`${m.month}-15`, locale, { month: "short" })}</div>
                ))}
              </div>
              {d.special_dates.length > 0 && <p className="mt-2 text-xs text-slate-600">{en ? "Special dates" : "Fechas especiales"}: {d.special_dates.map((s) => `${s.name} (${fmtDate(s.from, locale)}–${fmtDate(s.to, locale)}, ×${s.factor})`).join(" · ")}</p>}
              <p className="mt-2 text-sm text-slate-700">{d.climate}</p>
            </section>
            <section>
              <h2 className="text-lg font-semibold">{en ? "Experiences" : "Experiencias"} ({d.products.length})</h2>
              {d.products.length === 0 ? (
                <p className="mt-2 text-sm">{en ? "No tours online yet." : "Aún no hay tours en línea."} <Link href={`/sitio/viajes-a-medida?destino=${d.slug}`} className="text-sky-700 underline">{en ? "Request a tailor-made trip" : "Pida un viaje a medida"}</Link></p>
              ) : <div className="mt-3 grid gap-4 sm:grid-cols-2">{d.products.map((p) => <ProductCard key={p.id} p={p} locale={locale} />)}</div>}
            </section>
            {d.faqs.length > 0 && (
              <section>
                <h2 className="font-semibold">FAQ</h2>
                {d.faqs.map((f, i) => <details key={i} className="mt-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"><summary className="cursor-pointer font-medium">{f.q}</summary><p className="mt-1">{f.a}</p></details>)}
              </section>
            )}
          </div>
          <aside className="h-fit space-y-3 rounded-2xl border border-slate-200 bg-white p-4 text-sm">
            <p className="font-semibold">{en ? "Location" : "Ubicación"}</p>
            <svg viewBox="0 0 200 120" className="w-full rounded bg-sky-50" role="img" aria-label={`${d.name} ${d.lat}, ${d.lng}`}>
              <path d="M20 20 L80 10 L120 40 L170 50 L180 100 L100 110 L40 90 Z" fill="#d1fae5" stroke="#10b981" />
              <circle cx="110" cy="70" r="5" fill="#e11d48" />
            </svg>
            <p className="text-xs text-slate-500">{d.lat}, {d.lng}</p>
            <Link href={`/sitio/viajes-a-medida?destino=${d.slug}`} className="block rounded-lg bg-[#0b1f3a] py-2 text-center text-white">{en ? "Get a quote" : "Cotizar viaje"}</Link>
            <a href={`https://wa.me/${site.whatsapp.replace(/\D/g, "")}`} target="_blank" rel="noopener noreferrer" className="block text-center text-emerald-700 hover:underline">WhatsApp</a>
          </aside>
        </div>
      </div>
    </div>
  );
}
