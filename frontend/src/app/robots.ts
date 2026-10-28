import type { MetadataRoute } from "next";

const BASE = process.env.PUBLIC_SITE_URL ?? "http://localhost:3000";

/** robots.txt (spec 15 §8): solo el sitio público se indexa; carrito, checkout, confirmación, cuenta y la app interna no. */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: { userAgent: "*", allow: "/sitio", disallow: ["/sitio/carrito", "/sitio/checkout", "/sitio/confirmacion", "/sitio/cuenta", "/sitio/newsletter", "/sitio/baja", "/"] },
    sitemap: `${BASE}/sitemap.xml`,
  };
}
