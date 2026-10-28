import type { Metadata } from "next";
import { Listing } from "@/components/site/Listing";
import { prefs } from "@/lib/site";

export const metadata: Metadata = { title: "Paquetes turísticos", description: "Paquetes y circuitos de varios días por el Perú con hotel, traslados y guía; pague con anticipo." };

export default async function PackagesPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const { locale } = await prefs();
  return <Listing type="paquetes" params={await searchParams} title={locale === "en" ? "Packages" : "Paquetes"} base="/sitio/paquetes" />;
}
