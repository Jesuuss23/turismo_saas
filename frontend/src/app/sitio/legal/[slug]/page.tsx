import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { siteGet } from "@/lib/site";
import { fmtDate } from "@/lib/site-shared";
import { Prose } from "@/components/site/Prose";

type Page = { title: string; body: string | null; published_at: string | null; seo: { title: string; description: string | null } };

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const p = await siteGet<Page>(`pages/${encodeURIComponent((await params).slug)}`);
  return p ? { title: p.seo.title, description: p.seo.description ?? undefined } : { title: "Legal" };
}

/** Páginas legales publicadas en el CMS (términos, privacidad, cancelación, cookies). */
export default async function LegalPage({ params }: { params: Promise<{ slug: string }> }) {
  const p = await siteGet<Page>(`pages/${encodeURIComponent((await params).slug)}`);
  if (!p) notFound();
  return (
    <div className="mx-auto max-w-3xl px-4 py-8">
      <h1 className="text-2xl font-semibold">{p.title}</h1>
      {p.published_at && <p className="mt-1 text-xs text-slate-500">Vigente desde {fmtDate(p.published_at)}</p>}
      <article className="mt-6"><Prose body={p.body} /></article>
    </div>
  );
}
