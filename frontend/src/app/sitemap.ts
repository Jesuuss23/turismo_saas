import type { MetadataRoute } from "next";
import { siteApi } from "@/lib/site";

export const dynamic = "force-dynamic";

const BASE = process.env.PUBLIC_SITE_URL ?? "http://localhost:3000";
const STATIC = ["", "/tours", "/paquetes", "/experiencias", "/destinos", "/ofertas", "/viajes-a-medida", "/blog", "/nosotros", "/contacto", "/libro-reclamaciones",
  "/legal/terminos-y-condiciones", "/legal/politica-de-privacidad", "/legal/politica-de-cancelacion", "/legal/politica-de-cookies"];

/** sitemap.xml del sitio público: páginas fijas + fichas, destinos y artículos publicados (el idioma va en cookie, una URL por página). */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [products, dests, posts] = await Promise.all([
    siteApi<{ data: { slug: string }[] }>("products", { session: false }).then((r) => r.data).catch(() => []),
    siteApi<{ data: { slug: string }[] }>("destinations", { session: false }).then((r) => r.data).catch(() => []),
    siteApi<{ data: { slug: string; published_at: string | null }[] }>("posts", { session: false }).then((r) => r.data).catch(() => []),
  ]);
  return [
    ...STATIC.map((p) => ({ url: `${BASE}/sitio${p}` })),
    ...products.map((p) => ({ url: `${BASE}/sitio/tours/${p.slug}` })),
    ...dests.map((d) => ({ url: `${BASE}/sitio/destinos/${d.slug}` })),
    ...posts.map((p) => ({ url: `${BASE}/sitio/blog/${p.slug}`, lastModified: p.published_at ?? undefined })),
  ];
}
