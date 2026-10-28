import type { Metadata } from "next";
import { Listing } from "@/components/site/Listing";
import { prefs } from "@/lib/site";

export const metadata: Metadata = { title: "Experiencias", description: "Experiencias y actividades: gastronomía, cultura viva y aventura en el Perú." };

export default async function ExperiencesPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const { locale } = await prefs();
  return <Listing type="experiencias" params={await searchParams} title={locale === "en" ? "Experiences" : "Experiencias"} base="/sitio/experiencias" />;
}
