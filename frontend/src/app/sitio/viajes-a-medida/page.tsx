import type { Metadata } from "next";
import { prefs, siteApi, siteGet } from "@/lib/site";
import type { ProductDetail } from "@/lib/site-shared";
import { LeadForm } from "@/components/site/Forms";

export const metadata: Metadata = { title: "Viajes a medida", description: "Cuéntenos su viaje ideal y un asesor le enviará una propuesta en 24 horas hábiles." };

type Dest = { slug: string; name: string };

/** Viaje a medida (spec 15 §2, SIT-BR-22): prellenado desde destino, ficha, fecha y viajeros. */
export default async function TailorMadePage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const sp = await searchParams;
  const { locale } = await prefs();
  const en = locale === "en";
  const [dests, product] = await Promise.all([
    siteApi<{ data: Dest[] }>("destinations").then((r) => r.data),
    sp.producto ? siteGet<ProductDetail>(`products/${encodeURIComponent(sp.producto)}`) : Promise.resolve(null),
  ]);
  const names = dests.map((d) => d.name);
  const fromSlug = dests.find((d) => d.slug === sp.destino)?.name;
  const fromProduct = product?.destination ? names.find((n) => product.destination?.includes(n)) ?? product.destination : undefined;
  const destination = fromSlug ?? fromProduct;
  if (destination && !names.includes(destination)) names.unshift(destination);
  const pax = Number(sp.pax);
  const date = sp.fecha && /^\d{4}-\d{2}-\d{2}$/.test(sp.fecha) ? sp.fecha : undefined;
  return (
    <div className="mx-auto max-w-3xl px-4 py-8">
      <h1 className="text-2xl font-semibold">{en ? "Tailor-made trips" : "Viajes a medida"}</h1>
      <p className="mt-1 text-slate-600">
        {en ? "Tell us how you imagine your trip. An advisor will send you a proposal within 24 business hours (4 h during office hours)." : "Cuéntenos cómo imagina su viaje. Un asesor le enviará una propuesta en un máximo de 24 horas hábiles (4 h en horario de oficina)."}
      </p>
      <div className="mt-6">
        <LeadForm en={en} destinations={names} prefill={{ destination, product: product?.slug, productName: product?.name, date, pax: pax > 0 ? pax : undefined }} />
      </div>
    </div>
  );
}
