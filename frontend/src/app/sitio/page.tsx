import Link from "next/link";
import { prefs, siteApi, siteInfo } from "@/lib/site";
import { fmtDate, money, t, type ProductCard as Card } from "@/lib/site-shared";
import { ProductCard } from "@/components/site/ProductCard";
import { Countdown } from "@/components/site/Countdown";
import { NewsletterForm } from "@/components/site/NewsletterForm";
import { Picture } from "@/components/site/Picture";

type Dest = { slug: string; name: string; tagline: string | null; altitude: string | null; featured: boolean; products_count: number };
type Offer = { kind: string; name: string; label: string | null; code: string | null; min_pax: number | null; ends_at: string | null; product: Card };
type Guaranteed = { id: string; ref: string; date: string; time: string; capacity: number; sold: number; available: number; product: { slug: string; name: string }; price: string; currency: string };

/** Inicio del sitio (spec 15 §2): buscador, destinos y productos destacados, ofertas, salidas garantizadas, confianza y newsletter. */
export default async function SiteHome() {
  const { locale } = await prefs();
  const d = t(locale);
  const en = locale === "en";
  const [site, dests, products, offers, guaranteed] = await Promise.all([
    siteInfo(),
    siteApi<{ data: Dest[] }>("destinations").then((r) => r.data),
    siteApi<{ data: Card[]; meta: { facets: { destinations: { slug: string; name: string }[] } } }>("products"),
    siteApi<{ data: Offer[] }>("offers").then((r) => r.data),
    siteApi<{ data: Guaranteed[] }>("departures/guaranteed", { query: { limit: 6 } }).then((r) => r.data),
  ]);
  const featured = products.data.filter((p) => p.featured).concat(products.data.filter((p) => !p.featured)).slice(0, 6);
  const promos = offers.filter((o) => o.kind !== "precio_web").slice(0, 3);
  const ld = {
    "@context": "https://schema.org", "@type": "TravelAgency", name: site.name, legalName: site.legal_name, taxID: site.ruc, address: site.fiscal_address,
    telephone: site.phone, email: site.email ?? undefined, openingHours: site.hours, sameAs: site.socials,
  };
  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(ld).replace(/</g, "\\u003c") }} />
      <section className="relative overflow-hidden bg-[#0b1f3a] text-white">
        <svg viewBox="0 0 1200 300" preserveAspectRatio="none" className="absolute inset-x-0 bottom-0 h-40 w-full opacity-60" aria-hidden>
          <path d="M0 300 L180 120 L300 210 L480 60 L640 190 L760 110 L900 220 L1050 90 L1200 200 L1200 300 Z" fill="#12355f" />
          <path d="M0 300 L220 200 L380 260 L560 160 L720 250 L880 190 L1040 250 L1200 220 L1200 300 Z" fill="#0f2c50" />
        </svg>
        <div className="relative mx-auto max-w-6xl px-4 pb-16 pt-12">
          <h1 className="max-w-2xl text-3xl font-bold leading-tight sm:text-4xl">{d.heroTitle}</h1>
          <p className="mt-3 max-w-2xl text-slate-200">{d.heroText}</p>
          <form action="/sitio/buscar" className="mt-6 grid gap-2 rounded-2xl bg-white p-3 text-slate-900 shadow-lg sm:grid-cols-[1.5fr_1fr_0.8fr_auto]" role="search">
            <label className="text-xs font-medium text-slate-600">{d.whereTo}
              <select name="destination" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-2 text-sm">
                <option value="">{d.any}</option>
                {products.meta.facets.destinations.map((x) => <option key={x.slug} value={x.slug}>{x.name}</option>)}
              </select>
            </label>
            <label className="text-xs font-medium text-slate-600">{d.date}
              <input type="date" name="date" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-2 text-sm" />
            </label>
            <label className="text-xs font-medium text-slate-600">{d.travelers}
              <input type="number" name="pax" min={1} max={20} defaultValue={2} className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-2 text-sm" />
            </label>
            <button className="self-end rounded-lg bg-amber-400 px-5 py-2 text-sm font-semibold text-slate-900">{d.search}</button>
          </form>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 pt-10">
        <div className="flex items-end justify-between gap-2">
          <h2 className="text-xl font-semibold">{d.destinations}</h2>
          <Link href="/sitio/destinos" className="text-sm text-sky-700 hover:underline">{en ? "All destinations" : "Ver todos"}</Link>
        </div>
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {dests.filter((x) => x.featured).map((x, i) => (
            <Link key={x.slug} href={`/sitio/destinos/${x.slug}`} className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
              <Picture label={x.name} tone={["sky", "emerald", "amber"][i % 3]} className="h-32" />
              <div className="p-3">
                <p className="font-semibold">{x.name}</p>
                <p className="text-xs text-slate-500">{x.tagline} · {x.altitude} · {x.products_count} {en ? "experiences" : "experiencias"}</p>
              </div>
            </Link>
          ))}
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 pt-10">
        <h2 className="text-xl font-semibold">{d.featured}</h2>
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {featured.map((p) => <ProductCard key={p.id} p={p} locale={locale} />)}
        </div>
      </section>

      {promos.length > 0 && (
        <section className="mx-auto max-w-6xl px-4 pt-10">
          <div className="flex items-end justify-between gap-2">
            <h2 className="text-xl font-semibold">{d.offers}</h2>
            <Link href="/sitio/ofertas" className="text-sm text-sky-700 hover:underline">{en ? "All deals" : "Ver todas"}</Link>
          </div>
          <div className="mt-4 grid gap-4 md:grid-cols-3">
            {promos.map((o, i) => (
              <article key={i} className="rounded-2xl border border-rose-200 bg-rose-50 p-4">
                <p className="text-sm font-semibold text-rose-800">{o.name} · −{o.label}</p>
                <p className="mt-1 text-sm"><Link href={`/sitio/tours/${o.product.slug}`} className="hover:underline">{o.product.name}</Link></p>
                <p className="mt-1 text-xs text-slate-600">{o.code ? <>{en ? "Code" : "Código"} <b className="font-mono">{o.code}</b></> : `${en ? "Automatic from" : "Automática desde"} ${o.min_pax} pax`}</p>
                {o.ends_at && <p className="mt-2 text-xs text-slate-700">{en ? "Ends in" : "Termina en"} <Countdown until={o.ends_at} locale={locale} /></p>}
              </article>
            ))}
          </div>
        </section>
      )}

      {guaranteed.length > 0 && (
        <section className="mx-auto max-w-6xl px-4 pt-10">
          <h2 className="text-xl font-semibold">{d.guaranteedTitle}</h2>
          <ul className="mt-4 grid gap-3 md:grid-cols-2">
            {guaranteed.map((g) => {
              const pct = Math.min(100, Math.round((g.sold / Math.max(1, g.capacity)) * 100));
              return (
                <li key={g.id} className="rounded-xl border border-slate-200 bg-white p-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <Link href={`/sitio/tours/${g.product.slug}`} className="font-medium hover:underline">{g.product.name}</Link>
                    <span className="text-sm font-semibold">{money(g.price, g.currency)}</span>
                  </div>
                  <p className="text-xs text-slate-500">{fmtDate(g.date, locale)} {g.time} · {g.available} {d.seats} · {d.guaranteed}</p>
                  <div className="mt-2 h-2 rounded-full bg-slate-100" aria-label={`${pct} %`}><div className="h-2 rounded-full bg-emerald-500" style={{ width: `${pct}%` }} /></div>
                </li>
              );
            })}
          </ul>
        </section>
      )}

      <section className="mx-auto grid max-w-6xl gap-4 px-4 pt-10 md:grid-cols-4">
        <h2 className="text-xl font-semibold md:col-span-4">{d.whyUs}</h2>
        {(en
          ? [["Local operator", "Our own guides and vehicles in Cusco since 2011."], ["Real-time seats", "Your seats are held for " + site.hold_minutes + " minutes while you pay."], ["Secure payment", "Card, transfer or Yape; your data is never stored."], ["24/7 support", "Emergency line during your whole trip."]]
          : [["Operación propia", "Guías y movilidad propios en Cusco desde 2011."], ["Cupos en tiempo real", `Bloqueamos sus cupos ${site.hold_minutes} minutos mientras paga.`], ["Pago seguro", "Tarjeta, transferencia o Yape; no guardamos su tarjeta."], ["Atención 24/7", "Línea de emergencias durante todo su viaje."]]
        ).map(([title, text]) => (
          <div key={title} className="rounded-2xl border border-slate-200 bg-white p-4">
            <p className="font-semibold">{title}</p>
            <p className="mt-1 text-sm text-slate-600">{text}</p>
          </div>
        ))}
      </section>

      <section className="mx-auto max-w-6xl px-4 pt-10">
        <h2 className="text-xl font-semibold">{d.reviews}</h2>
        <p className="text-xs text-slate-500">{d.demo}</p>
        <div className="mt-4 grid gap-4 md:grid-cols-3">
          {[["Laura M. · España", "Todo impecable: recojo puntual y un guía que sabía muchísimo."], ["Tom R. · USA", "Great value, the train and lunch were excellent."], ["Paula G. · Chile", "Humantay es exigente pero vale cada paso. Muy bien organizado."]].map(([who, text]) => (
            <blockquote key={who} className="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
              <p>★★★★★</p>
              <p className="mt-2 text-slate-700">“{text}”</p>
              <footer className="mt-2 text-xs text-slate-500">{who}</footer>
            </blockquote>
          ))}
        </div>
        <p className="mt-4 text-xs text-slate-500">{en ? "Registered tour operator · Complaints Book available · Payments processed by a PCI-DSS certified gateway." : "Agencia registrada · Libro de Reclamaciones disponible · Pagos procesados por una pasarela certificada PCI-DSS."}</p>
      </section>

      <section className="mx-auto mt-10 max-w-6xl px-4">
        <div className="rounded-2xl bg-sky-50 p-6">
          <h2 className="text-lg font-semibold">{d.newsletter}</h2>
          <div className="max-w-xl"><NewsletterForm locale={locale} /></div>
        </div>
      </section>
    </>
  );
}
