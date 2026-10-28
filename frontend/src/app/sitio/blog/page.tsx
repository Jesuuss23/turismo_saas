import type { Metadata } from "next";
import Link from "next/link";
import { prefs, siteApi } from "@/lib/site";
import { fmtDate } from "@/lib/site-shared";
import { Picture } from "@/components/site/Picture";

export const metadata: Metadata = { title: "Blog de viajes", description: "Guías y consejos para viajar por Cusco, Machu Picchu y el Perú." };

type Post = { slug: string; title: string; excerpt: string; published_at: string | null; author: string | null };

export default async function BlogPage() {
  const { locale } = await prefs();
  const posts = (await siteApi<{ data: Post[] }>("posts")).data;
  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <h1 className="text-2xl font-semibold">Blog</h1>
      {posts.length === 0 && <p className="mt-4 text-slate-600">{locale === "en" ? "No articles yet." : "Aún no hay artículos publicados."}</p>}
      <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="posts">
        {posts.map((p, i) => (
          <Link key={p.slug} href={`/sitio/blog/${p.slug}`} className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <Picture label={p.title} tone={["amber", "sky", "emerald"][i % 3]} className="h-32" />
            <div className="p-3">
              <p className="font-semibold">{p.title}</p>
              <p className="mt-1 text-xs text-slate-500">{fmtDate(p.published_at, locale)}{p.author ? ` · ${p.author}` : ""}</p>
              <p className="mt-2 line-clamp-3 text-sm text-slate-600">{p.excerpt.replace(/#+ /g, "")}</p>
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}
