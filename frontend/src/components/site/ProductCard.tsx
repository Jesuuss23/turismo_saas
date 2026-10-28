import Link from "next/link";
import { fmtDate, money, t, type Locale, type ProductCard as Card } from "@/lib/site-shared";
import { Picture } from "./Picture";

const TAGS: Record<string, { es: string; en: string; cls: string }> = {
  best_seller: { es: "Más vendido", en: "Best seller", cls: "bg-amber-100 text-amber-900" },
  offer: { es: "Oferta", en: "Deal", cls: "bg-rose-100 text-rose-800" },
  free_cancellation: { es: "Cancelación gratis", en: "Free cancellation", cls: "bg-emerald-100 text-emerald-800" },
  instant_confirmation: { es: "Confirmación inmediata", en: "Instant confirmation", cls: "bg-sky-100 text-sky-800" },
};

/** Tarjeta de producto del listado (spec 15 §2): etiquetas, rating, duración, idiomas, próxima salida y precio «desde» (tachado con precio web). */
export function ProductCard({ p, locale }: { p: Card; locale: Locale }) {
  const d = t(locale);
  return (
    <article className="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" data-testid="product-card">
      <Link href={`/sitio/tours/${p.slug}`} className="block">
        <Picture label={p.destination ?? p.name} tone={p.type === "PAQUETE" ? "emerald" : p.category === "Aventura" ? "amber" : "sky"} className="h-40" />
      </Link>
      <div className="flex flex-1 flex-col gap-2 p-4">
        <div className="flex flex-wrap gap-1">
          {p.tags.filter((x) => TAGS[x]).map((x) => <span key={x} className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${TAGS[x].cls}`}>{TAGS[x][locale]}</span>)}
        </div>
        <h3 className="font-semibold leading-snug"><Link href={`/sitio/tours/${p.slug}`} className="hover:underline">{p.name}</Link></h3>
        <p className="text-xs text-slate-500">
          {p.destination} · {p.duration} · {p.modality}{p.languages.length ? ` · ${p.languages.join("/")}` : ""}
          {p.rating && <> · ★ {Number(p.rating.avg).toFixed(1)} ({p.rating.count})</>}
        </p>
        {p.summary && <p className="line-clamp-2 text-sm text-slate-600">{p.summary}</p>}
        <p className="text-xs text-slate-600">
          {p.next_departure ? `${d.nextDeparture}: ${fmtDate(p.next_departure.date, locale)} ${p.next_departure.time} · ${p.next_departure.available} ${d.seats}` : p.daily ? d.daily : ""}
        </p>
        <div className="mt-auto flex items-end justify-between gap-2">
          <p className="text-sm">
            <span className="block text-xs text-slate-500">{d.from}</span>
            {p.list_price && <span className="mr-1 text-xs text-slate-400 line-through">{money(p.list_price, p.currency)}</span>}
            <span className="text-lg font-bold text-[#0b1f3a]" data-testid="from-price">{p.from_price ? money(p.from_price, p.currency) : "—"}</span>
            <span className="block text-[11px] text-slate-500">{d.perPerson}</span>
          </p>
          <Link href={`/sitio/tours/${p.slug}`} className="shrink-0 rounded-lg bg-[#0b1f3a] px-3 py-2 text-sm font-medium text-white">{d.seeTour}</Link>
        </div>
      </div>
    </article>
  );
}
