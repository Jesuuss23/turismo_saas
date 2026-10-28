import type { Metadata } from "next";
import { SiteShell } from "@/components/site/SiteShell";
import { siteInfo } from "@/lib/site";

export async function generateMetadata(): Promise<Metadata> {
  const site = await siteInfo().catch(() => null);
  const name = site?.name ?? "Andes Travel";
  return {
    title: { default: `${name} · Tours y paquetes en Perú`, template: `%s · ${name}` },
    description: "Tours, paquetes y experiencias en Cusco, Machu Picchu y todo el Perú con operación propia, precios por temporada y pago seguro.",
  };
}

/** Sitio web público de la agencia (spec 15): sin login, tenant por dominio, idioma y moneda por cookie. */
export default function SiteLayout({ children }: { children: React.ReactNode }) {
  return <SiteShell>{children}</SiteShell>;
}
