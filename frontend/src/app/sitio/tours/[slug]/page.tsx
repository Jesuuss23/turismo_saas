import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { customer, prefs, siteApi, siteGet, siteInfo } from "@/lib/site";
import { fmtDate, t, type CalendarDay, type ProductDetail } from "@/lib/site-shared";
import { BookingPanel } from "@/components/site/BookingPanel";
import { ProductCard } from "@/components/site/ProductCard";
import { Picture } from "@/components/site/Picture";
import { TrackOnView } from "@/components/site/TrackOnView";
import { WishButton } from "@/components/site/WishButton";
import { VerifiedReviews } from "@/components/site/VerifiedReviews";
import type { ProductReviewBlock } from "@/lib/own-reviews";

type Props = { params: Promise<{ slug: string }>; searchParams: Promise<{ fecha?: string; pax?: string; estrellas?: string; pagina?: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const p = await siteGet<ProductDetail>(`products/${encodeURIComponent((await params).slug)}`);
  if (!p) return { title: "Producto no disponible", robots: { index: false } };
  return { title: p.seo.title, description: p.seo.description ?? p.summary ?? undefined, alternates: { canonical: `/sitio/tours/${p.slug}` } };
}

const POLICY_LABELS: Record<string, { es: string; en: string }> = {
  cancellation: { es: "Cancelación", en: "Cancellation" }, modification: { es: "Cambios", en: "Changes" }, no_show: { es: "No show", en: "No show" },
  refund: { es: "Reembolsos", en: "Refunds" }, advance: { es: "Anticipación", en: "Advance booking" },
};

/** Ficha del producto (spec 15 §2): contenido, salidas, políticas y panel de reserva con calendario de precios. */
export default async function ProductPage({ params, searchParams }: Props) {
  const { slug } = await params;
  const sp = await searchParams;
  const { locale, currency } = await prefs();
  const d = t(locale);
  const en = locale === "en";
  const p = await siteGet<ProductDetail>(`products/${encodeURIComponent(slug)}`);
  if (!p) notFound();
  const [site, me] = await Promise.all([siteInfo(), customer()]);
  // Spec 18: reseñas propias verificadas (solo publicadas, con R-OWN-REVIEWS efectiva y «Mostrar en el sitio»).
  const own = await siteApi<{ data: { block: ProductReviewBlock | null } }>("reviews/verified", { session: false, query: { product: p.ref, stars: sp.estrellas, page: sp.pagina } })
    .then((r) => r.data.block).catch(() => null);
  const wished = me ? await siteApi<{ data: { slug: string }[] }>("me/wishlist").then((r) => r.data.some((w) => w.slug === p.slug)).catch(() => false) : false;
  const startMonth = (sp.fecha ?? p.next_departure?.date ?? "").slice(0, 7);
  const cal = (await siteApi<{ data: { month: string; days: CalendarDay[]; next_available: string | null } }>(`products/${encodeURIComponent(p.slug)}/calendar`, {
    query: { month: /^\d{4}-\d{2}$/.test(startMonth) ? startMonth : undefined, pax: sp.pax },
  })).data;
  return (
    <div className="mx-auto max-w-6xl px-4 py-6">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(p.json_ld).replace(/</g, "\\u003c") }} />
      <TrackOnView event="view_item" params={{ currency, value: p.from_price, items: [{ item_id: p.ref, item_name: p.name, item_category: p.type }] }} />
      <nav aria-label="Ruta" className="text-xs text-slate-500">
        <Link href="/sitio" className="hover:underline">{en ? "Home" : "Inicio"}</Link> › {p.route.join(" › ")} › <span className="text-slate-700">{p.name}</span>
      </nav>
      <div className="mt-2 flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-2xl font-bold sm:text-3xl">{p.name}</h1>
          <p className="mt-1 text-sm text-slate-600">
            {p.rating?.verified
              ? <><a href="#resenas-verificadas" className="hover:underline" data-rating-chip>✓ {en ? "Verified trip" : "Viaje verificado"} · {Number(p.rating.avg).toFixed(1)} ({p.rating.count})</a> · </>
              : p.rating && <>★ {Number(p.rating.avg).toFixed(1)} ({p.rating.count} {en ? "reviews" : "reseñas"}{p.rating.demo ? " · demo" : ""}) · </>}
            {p.destination} · {p.type}
          </p>
        </div>
        <WishButton slug={p.slug} logged={!!me} locale={locale} initial={wished} />
      </div>
      {!p.translated && <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">This page is not yet translated; showing the Spanish content.</p>}
      <div className="mt-4 grid gap-2 sm:grid-cols-3">
        {p.gallery.map((g, i) => <Picture key={i} label={g.alt} tone={g.tone} className={`h-44 rounded-2xl ${i === 0 ? "sm:col-span-2 sm:h-64" : "hidden sm:block sm:h-64"}`} />)}
      </div>

      <div className="mt-6 grid gap-8 lg:grid-cols-[1fr_360px]">
        <div className="min-w-0 space-y-8">
          <ul className="grid grid-cols-2 gap-3 rounded-2xl border border-slate-200 bg-white p-4 text-sm sm:grid-cols-3">
            <li><span className="block text-xs text-slate-500">{en ? "Duration" : "Duración"}</span>{p.duration}</li>
            <li><span className="block text-xs text-slate-500">{en ? "Group size" : "Grupo"}</span>{p.min_pax}–{p.max_pax} pax</li>
            <li><span className="block text-xs text-slate-500">{en ? "Guide" : "Idioma"}</span>{p.languages.join(", ")}</li>
            <li><span className="block text-xs text-slate-500">{en ? "Pick-up" : "Recojo"}</span>{p.pickup ?? (en ? "Meeting point" : "Punto de encuentro")}</li>
            <li><span className="block text-xs text-slate-500">{en ? "Service" : "Modalidad"}</span>{p.modality}</li>
            <li><span className="block text-xs text-slate-500">{en ? "Fitness" : "Condición física"}</span>{p.difficulty_label ?? "—"}</li>
          </ul>
          <section>
            <p className="text-slate-700">{p.summary}</p>
            {p.description && <div className="mt-3 space-y-2 text-sm text-slate-700">{p.description.split("\n").map((l, i) => <p key={i}>{l}</p>)}</div>}
          </section>
          {p.itinerary.length > 0 && (
            <section>
              <h2 className="text-lg font-semibold">{d.itinerary}</h2>
              <ol className="mt-3 space-y-3">
                {p.itinerary.map((day) => (
                  <li key={day.day} className="rounded-xl border border-slate-200 bg-white p-3">
                    <p className="font-medium">{en ? "Day" : "Día"} {day.day} · {day.title}</p>
                    <ul className="mt-1 space-y-0.5 text-sm text-slate-600">{day.items.map((it, i) => <li key={i}>{it.time && <span className="mr-2 tabular-nums text-slate-500">{it.time}</span>}{it.activity}</li>)}</ul>
                  </li>
                ))}
              </ol>
            </section>
          )}
          <section className="grid gap-4 sm:grid-cols-2">
            <div><h2 className="font-semibold">{d.includes}</h2><ul className="mt-2 space-y-1 text-sm">{p.includes.map((x) => <li key={x}>✓ {x}</li>)}</ul></div>
            <div><h2 className="font-semibold">{d.excludes}</h2><ul className="mt-2 space-y-1 text-sm text-slate-600">{p.excludes.map((x) => <li key={x}>✗ {x}</li>)}</ul></div>
          </section>
          {(p.requirements.length > 0 || p.min_age > 0) && (
            <section>
              <h2 className="font-semibold">{d.requirements}</h2>
              <ul className="mt-2 list-disc space-y-1 pl-5 text-sm">{p.requirements.map((x) => <li key={x}>{x}</li>)}{p.min_age > 0 && <li>{en ? `Minimum age: ${p.min_age}` : `Edad mínima: ${p.min_age} años`}</li>}</ul>
            </section>
          )}
          {p.departures.length > 0 && (
            <section>
              <h2 className="text-lg font-semibold">{d.departures}</h2>
              <ul className="mt-2 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white text-sm" data-testid="departures">
                {p.departures.slice(0, 8).map((x) => (
                  <li key={x.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                    <span>{fmtDate(x.date, locale, { weekday: "short", day: "2-digit", month: "short" })} · {x.time}</span>
                    <span className={x.available === 0 ? "text-rose-700" : "text-slate-600"}>{x.available === 0 ? (en ? "Sold out" : "Agotado") : `${x.available} ${d.seats}`}{x.guaranteed && ` · ✓ ${d.guaranteed}`}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}
          <section>
            <h2 className="font-semibold">{d.meeting}</h2>
            <p className="mt-1 text-sm text-slate-700">{p.meeting_point ?? "—"} · {en ? "Operations line" : "Línea de operaciones"} {site.emergency}</p>
          </section>
          <section>
            <h2 className="font-semibold">{d.policies}</h2>
            <dl className="mt-2 space-y-1 text-sm">
              {Object.entries(p.policies).filter(([, v]) => v).map(([k, v]) => <div key={k}><dt className="inline font-medium">{POLICY_LABELS[k]?.[locale] ?? k}: </dt><dd className="inline text-slate-700">{v}</dd></div>)}
            </dl>
          </section>
          {p.faqs.length > 0 && (
            <section>
              <h2 className="font-semibold">{d.faq}</h2>
              <div className="mt-2 space-y-2">{p.faqs.map((f, i) => <details key={i} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"><summary className="cursor-pointer font-medium">{f.q}</summary><p className="mt-1 text-slate-700">{f.a}</p></details>)}</div>
            </section>
          )}
          {own && <VerifiedReviews block={own} slug={p.slug} locale={locale} />}
          <section>
            <h2 className="font-semibold">{d.reviews}</h2>
            <p className="text-xs text-slate-500">{d.demo}</p>
            <blockquote className="mt-2 rounded-xl border border-slate-200 bg-white p-3 text-sm">★★★★★ “{en ? "Well organized and our guide was great." : "Muy bien organizado y el guía excelente."}” — Carmen V.</blockquote>
          </section>
        </div>
        <BookingPanel p={p} locale={locale} currency={currency} whatsapp={site.whatsapp} initial={cal} preset={{ date: sp.fecha, pax: sp.pax ? Number(sp.pax) : undefined }} />
      </div>
      {p.related.length > 0 && (
        <section className="mt-10">
          <h2 className="text-lg font-semibold">{d.related}</h2>
          <div className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{p.related.map((r) => <ProductCard key={r.id} p={r} locale={locale} />)}</div>
        </section>
      )}
    </div>
  );
}
