import type { Metadata } from "next";
import Link from "next/link";
import { prefs, siteApi } from "@/lib/site";
import { money, type ProductCard as Card } from "@/lib/site-shared";
import { Countdown } from "@/components/site/Countdown";
import { CopyCode } from "@/components/site/CopyCode";

export const metadata: Metadata = { title: "Ofertas", description: "Códigos promocionales, descuentos de grupo y precios web exclusivos." };

type Offer = { kind: "codigo" | "automatica" | "precio_web"; name: string; label: string | null; code: string | null; min_pax: number | null; ends_at: string | null; product: Card };

export default async function OffersPage() {
  const { locale } = await prefs();
  const en = locale === "en";
  const rows = (await siteApi<{ data: Offer[] }>("offers")).data;
  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <h1 className="text-2xl font-semibold">{en ? "Deals" : "Ofertas"}</h1>
      <div className="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-3" data-testid="offers">
        {rows.map((o, i) => (
          <article key={i} className="rounded-2xl border border-slate-200 bg-white p-4">
            <p className="text-xs font-medium uppercase text-rose-700">{o.kind === "codigo" ? (en ? "Promo code" : "Código promocional") : o.kind === "automatica" ? (en ? "Automatic" : "Automática") : (en ? "Web price" : "Precio web")}</p>
            <p className="mt-1 font-semibold">{o.name}{o.label ? ` · −${o.label}` : ""}</p>
            <p className="text-sm"><Link href={`/sitio/tours/${o.product.slug}`} className="text-sky-700 hover:underline">{o.product.name}</Link></p>
            <p className="mt-1 text-sm">{o.product.list_price && <span className="mr-1 text-slate-400 line-through">{money(o.product.list_price, o.product.currency)}</span>}{money(o.product.from_price, o.product.currency)}</p>
            {o.code && <CopyCode code={o.code} en={en} />}
            {o.min_pax && <p className="text-xs text-slate-600">{en ? `From ${o.min_pax} travelers` : `Desde ${o.min_pax} viajeros`}</p>}
            {o.ends_at && <p className="mt-1 text-xs text-slate-600">{en ? "Ends in" : "Termina en"} <Countdown until={o.ends_at} locale={locale} /></p>}
          </article>
        ))}
      </div>
    </div>
  );
}
