import type { Metadata } from "next";
import Link from "next/link";
import { prefs, siteApi } from "@/lib/site";
import { Picture } from "@/components/site/Picture";

export const metadata: Metadata = { title: "Destinos", description: "Destinos del Perú: Cusco, Machu Picchu, Valle Sagrado, Lima, Arequipa y Puno." };

type Dest = { slug: string; name: string; tagline: string | null; altitude: string | null; products_count: number };

export default async function DestinationsPage() {
  const { locale } = await prefs();
  const rows = (await siteApi<{ data: Dest[] }>("destinations")).data;
  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <h1 className="text-2xl font-semibold">{locale === "en" ? "Destinations" : "Destinos"}</h1>
      <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {rows.map((x, i) => (
          <Link key={x.slug} href={`/sitio/destinos/${x.slug}`} className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <Picture label={x.name} tone={["sky", "emerald", "amber"][i % 3]} className="h-36" />
            <div className="p-3">
              <p className="font-semibold">{x.name}</p>
              <p className="text-xs text-slate-500">{x.tagline}</p>
              <p className="mt-1 text-xs text-slate-600">{x.altitude} · {x.products_count} {locale === "en" ? "experiences" : "experiencias"}</p>
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}
