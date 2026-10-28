import type { Metadata } from "next";
import { Listing } from "@/components/site/Listing";
import { prefs } from "@/lib/site";

export const metadata: Metadata = { title: "Buscar", robots: { index: false } };

export default async function SearchPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const { locale } = await prefs();
  return <Listing params={await searchParams} title={locale === "en" ? "Search results" : "Resultados de búsqueda"} base="/sitio/buscar" />;
}
