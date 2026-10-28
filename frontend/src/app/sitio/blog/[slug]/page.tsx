import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { prefs, siteApi, siteGet, siteInfo } from "@/lib/site";
import { fmtDate, type ProductCard as Card } from "@/lib/site-shared";
import { ProductCard } from "@/components/site/ProductCard";
import { Prose } from "@/components/site/Prose";

type Post = { slug: string; title: string; body: string | null; published_at: string | null; author: string | null; seo: { title: string; description: string | null } };

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const p = await siteGet<Post>(`posts/${encodeURIComponent((await params).slug)}`);
  return p ? { title: p.seo.title, description: p.seo.description ?? undefined } : { title: "Blog" };
}

/** Artículo del blog (CMS publicado) con tours relacionados y CTA. */
export default async function PostPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const { locale } = await prefs();
  const en = locale === "en";
  const [p, site, products] = await Promise.all([
    siteGet<Post>(`posts/${encodeURIComponent(slug)}`),
    siteInfo(),
    siteApi<{ data: Card[] }>("products", { query: { sort: "best_seller" } }).then((r) => r.data),
  ]);
  if (!p) notFound();
  const words = p.title.toLowerCase().split(/\W+/).filter((w) => w.length > 4);
  const related = [...products].sort((a, b) => Number(words.some((w) => b.name.toLowerCase().includes(w))) - Number(words.some((w) => a.name.toLowerCase().includes(w)))).slice(0, 3);
  const ld = { "@context": "https://schema.org", "@type": "BlogPosting", headline: p.title, datePublished: p.published_at, author: { "@type": "Person", name: p.author ?? site.name } };
  return (
    <div className="mx-auto max-w-3xl px-4 py-8">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(ld).replace(/</g, "\\u003c") }} />
      <Link href="/sitio/blog" className="text-sm text-sky-700 hover:underline">← Blog</Link>
      <h1 className="mt-2 text-3xl font-bold">{p.title}</h1>
      <p className="mt-1 text-sm text-slate-500">{fmtDate(p.published_at, locale)}{p.author ? ` · ${p.author}` : ""}</p>
      <article className="mt-6"><Prose body={p.body} /></article>
      <section className="mt-10">
        <h2 className="font-semibold">{en ? "Tours for this trip" : "Tours para este viaje"}</h2>
        <div className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{related.map((c) => <ProductCard key={c.id} p={c} locale={locale} />)}</div>
      </section>
      <div className="mt-8 rounded-2xl bg-[#0b1f3a] p-5 text-white">
        <p className="font-semibold">{en ? "Want a trip designed for you?" : "¿Quiere un viaje diseñado para usted?"}</p>
        <Link href="/sitio/viajes-a-medida" className="mt-2 inline-block rounded-lg bg-amber-400 px-4 py-2 text-sm font-semibold text-slate-900">{en ? "Request a quote" : "Cotizar viaje a medida"}</Link>
      </div>
    </div>
  );
}
