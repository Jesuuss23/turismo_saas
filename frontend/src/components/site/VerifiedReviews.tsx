import Link from "next/link";
import { fmtDate, type Locale } from "@/lib/site-shared";
import { stars, type ProductReviewBlock, type PublicOwnReview } from "@/lib/own-reviews";

/** Marca «Viaje verificado» (spec 18 RVW-10). */
export function VerifiedBadge({ locale, className = "" }: { locale: Locale; className?: string }) {
  return <span className={`inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800 ${className}`} data-verified-badge>✓ {locale === "en" ? "Verified trip" : "Viaje verificado"}</span>;
}

export function ReviewCard({ r, locale }: { r: PublicOwnReview; locale: Locale }) {
  const en = locale === "en";
  return (
    <article className="rounded-2xl border border-slate-200 bg-white p-4 text-sm" data-public-review={r.code}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-amber-500" aria-label={`${r.stars} / 5`}>{stars(r.stars)}</span>
        <VerifiedBadge locale={locale} />
      </div>
      <p className="mt-2 font-semibold">{r.title}</p>
      <p className="mt-1 text-slate-700 [overflow-wrap:anywhere]">{r.body}</p>
      <p className="mt-2 text-xs text-slate-500">{r.author}{r.country ? ` · ${r.country}` : ""} · {en ? "travelled" : "viajó"} {fmtDate(`${r.travel_date}-01`, locale, { month: "short", year: "numeric" })}
        {r.product ? ` · ${r.product}` : ""}{r.is_demo ? ` · ${en ? "sample (demo)" : "ejemplo (demo)"}` : ""}</p>
      {r.reply && <p className="mt-2 rounded-lg border-l-4 border-[#0b1f3a] bg-slate-50 px-3 py-2 text-xs" data-agency-reply><span className="font-medium">{en ? "Agency reply" : "Respuesta de la agencia"}:</span> {r.reply}</p>}
    </article>
  );
}

/** Ficha del producto: «Reseñas de viajeros verificados» con promedio, distribución, aspectos, filtro por estrellas y paginado. */
export function VerifiedReviews({ block, slug, locale }: { block: ProductReviewBlock; slug: string; locale: Locale }) {
  const en = locale === "en";
  const href = (q: { estrellas?: number | null; pagina?: number }) => {
    const p = new URLSearchParams();
    if (q.estrellas) p.set("estrellas", String(q.estrellas));
    if (q.pagina && q.pagina > 1) p.set("pagina", String(q.pagina));
    return `/sitio/tours/${slug}${p.toString() ? `?${p}` : ""}#resenas-verificadas`;
  };
  const max = Math.max(1, ...Object.values(block.distribution));
  return (
    <section id="resenas-verificadas" data-testid="verified-reviews" className="scroll-mt-20">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="text-lg font-semibold">{en ? "Reviews from verified travelers" : "Reseñas de viajeros verificados"}</h2>
        <VerifiedBadge locale={locale} />
      </div>
      {block.is_demo && <p className="text-xs text-slate-500">{en ? "Includes sample reviews (demo)." : "Incluye reseñas de ejemplo (demo)."}</p>}
      <div className="mt-3 grid gap-4 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-[160px_1fr]">
        <div>
          <p className="text-4xl font-bold">{block.average.toFixed(1)}</p>
          <p className="text-amber-500">{stars(Math.round(block.average))}</p>
          <p className="text-xs text-slate-500">{block.count} {en ? "verified reviews" : "reseñas verificadas"}</p>
        </div>
        <div className="min-w-0 space-y-1">
          {[5, 4, 3, 2, 1].map((n) => (
            <Link key={n} href={href({ estrellas: block.filter.stars === n ? null : n })} scroll={false} data-star-filter={n}
              className={`flex items-center gap-2 rounded px-1 text-xs hover:bg-slate-50 ${block.filter.stars === n ? "bg-amber-50 font-semibold" : ""}`}>
              <span className="w-8 shrink-0">{n} ★</span>
              <span className="h-2 flex-1 rounded bg-slate-100"><span className="block h-2 rounded bg-amber-400" style={{ width: `${Math.round(((block.distribution[n] ?? 0) / max) * 100)}%` }} /></span>
              <span className="w-6 shrink-0 text-right">{block.distribution[n] ?? 0}</span>
            </Link>
          ))}
          {block.subratings.length > 0 && (
            <ul className="mt-2 flex flex-wrap gap-2 text-xs">{block.subratings.map((s) => <li key={s.key} className="rounded-full bg-slate-100 px-2 py-0.5">{s.label} {s.avg.toFixed(1)}</li>)}</ul>
          )}
        </div>
      </div>
      {block.filter.stars && (
        <p className="mt-2 text-xs">{en ? `Showing ${block.filter.stars}-star reviews` : `Mostrando reseñas de ${block.filter.stars} ★`} · <Link href={href({})} scroll={false} className="underline">{en ? `See all ${block.count} reviews` : `Ver las ${block.count} reseñas`}</Link></p>
      )}
      <div className="mt-3 space-y-3">
        {block.items.length === 0 ? <p className="text-sm text-slate-500">{en ? "No reviews with this rating." : "No hay reseñas con esta calificación."}</p>
          : block.items.map((r) => <ReviewCard key={r.code} r={r} locale={locale} />)}
      </div>
      {block.pages > 1 && (
        <nav className="mt-3 flex items-center gap-3 text-sm" aria-label={en ? "Review pages" : "Páginas de reseñas"}>
          {block.page > 1 && <Link href={href({ estrellas: block.filter.stars, pagina: block.page - 1 })} scroll={false} className="underline">← {en ? "Previous" : "Anteriores"}</Link>}
          <span className="text-xs text-slate-500">{block.page} / {block.pages}</span>
          {block.page < block.pages && <Link href={href({ estrellas: block.filter.stars, pagina: block.page + 1 })} scroll={false} className="underline">{en ? "More" : `Ver más (${block.total})`} →</Link>}
        </nav>
      )}
    </section>
  );
}
