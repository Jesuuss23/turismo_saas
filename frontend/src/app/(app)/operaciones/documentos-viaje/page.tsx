import { TravelDocsBoard, type DocsParams } from "@/components/ops/travelDocs";

/** Documentos de viaje del pasajero (spec 11 §6): reservas, pasajeros, gate MAPI y reglas. */
export default async function DocumentosViajePage({ searchParams }: { searchParams: Promise<DocsParams> }) {
  return <TravelDocsBoard sp={await searchParams} />;
}
