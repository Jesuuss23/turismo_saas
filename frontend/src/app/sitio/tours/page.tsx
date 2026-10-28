import type { Metadata } from "next";
import { Listing } from "@/components/site/Listing";
import { prefs } from "@/lib/site";

export const metadata: Metadata = { title: "Tours y excursiones", description: "Tours y excursiones de un día en Cusco, Machu Picchu y el Perú con salidas y cupos en tiempo real." };

export default async function ToursPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const { locale } = await prefs();
  return <Listing type="tours" params={await searchParams} title={locale === "en" ? "Tours & day trips" : "Tours y excursiones"} base="/sitio/tours" />;
}
