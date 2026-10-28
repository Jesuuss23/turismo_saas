import type { Metadata } from "next";
import Link from "next/link";
import { prefs, siteGet, siteInfo } from "@/lib/site";
import { Prose } from "@/components/site/Prose";

export const metadata: Metadata = { title: "Nosotros", description: "Agencia y operador de turismo en Cusco: operación propia, guías certificados y atención 24/7." };

type Page = { title: string; body: string | null };

/** Nosotros: historia (CMS «nosotros»), cifras de ejemplo, sedes (iam_sedes), garantías y datos legales. */
export default async function AboutPage() {
  const { locale } = await prefs();
  const en = locale === "en";
  const [site, page] = await Promise.all([siteInfo(), siteGet<Page>("pages/nosotros")]);
  const ld = {
    "@context": "https://schema.org", "@type": "TravelAgency", name: site.name, legalName: site.legal_name, taxID: site.ruc, address: site.fiscal_address,
    telephone: site.phone, email: site.email ?? undefined, openingHours: site.hours, sameAs: site.socials,
  };
  const stats: [string, string][] = en
    ? [["12", "years operating"], ["18 000+", "travelers"], ["35", "certified guides"], ["4.8/5", "average rating"]]
    : [["12", "años operando"], ["18 000+", "viajeros"], ["35", "guías certificados"], ["4.8/5", "valoración media"]];
  return (
    <div className="mx-auto max-w-5xl px-4 py-8">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(ld).replace(/</g, "\\u003c") }} />
      <h1 className="text-2xl font-semibold">{page?.title ?? (en ? "About us" : "Nosotros")}</h1>
      <div className="mt-4"><Prose body={page?.body ?? null} /></div>
      <section className="mt-8">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {stats.map(([n, l]) => (
            <div key={l} className="rounded-2xl border border-slate-200 bg-white p-4 text-center">
              <p className="text-2xl font-bold text-[#0b1f3a]">{n}</p>
              <p className="text-xs text-slate-600">{l}</p>
            </div>
          ))}
        </div>
        <p className="mt-1 text-xs text-slate-500">{en ? "Sample figures (demo)." : "Cifras de ejemplo (demo)."}</p>
      </section>
      <section className="mt-8 grid gap-6 md:grid-cols-2">
        <div>
          <h2 className="font-semibold">{en ? "Our guarantees" : "Nuestras garantías"}</h2>
          <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-700">
            <li>{en ? "Own operation: guides, transport and 24/7 operations line." : "Operación propia: guías, movilidad y línea de operaciones 24/7."}</li>
            <li>{en ? "Prices calculated by our system, with no hidden fees." : "Precios calculados por nuestro sistema, sin cargos ocultos."}</li>
            <li>{en ? "Electronic receipts (boleta/factura) for every payment." : "Comprobante electrónico (boleta o factura) por cada pago."}</li>
            <li>{en ? "Complaints Book available online." : "Libro de Reclamaciones disponible en línea."}</li>
          </ul>
        </div>
        <div>
          <h2 className="font-semibold">{en ? "Legal information" : "Datos legales"}</h2>
          <p className="mt-2 text-sm text-slate-700">{site.legal_name} · RUC {site.ruc}</p>
          <p className="text-sm text-slate-700">{site.fiscal_address}</p>
          <p className="mt-2 text-sm"><Link href="/sitio/libro-reclamaciones" className="text-sky-700 underline">{en ? "Complaints Book" : "Libro de Reclamaciones"}</Link></p>
        </div>
      </section>
      <section className="mt-8">
        <h2 className="font-semibold">{en ? "Our offices" : "Nuestras oficinas"}</h2>
        <div className="mt-2 grid gap-3 sm:grid-cols-2" data-testid="sedes">
          {site.sedes.map((s) => (
            <div key={s.code} className="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
              <p className="font-semibold">{s.name}</p>
              <p className="text-slate-600">{[s.address, s.city].filter(Boolean).join(", ")}</p>
              {s.phone && <p className="text-slate-600">{s.phone}</p>}
            </div>
          ))}
        </div>
      </section>
    </div>
  );
}
