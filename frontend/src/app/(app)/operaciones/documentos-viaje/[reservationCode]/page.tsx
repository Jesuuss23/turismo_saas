import { TravelDocsDetail, type DocParams } from "@/components/ops/travelDocs";

/** Checklist documental de una reserva (spec 11 §6.2). */
export default async function DocumentosReservaPage({ params, searchParams }: { params: Promise<{ reservationCode: string }>; searchParams: Promise<DocParams> }) {
  const { reservationCode } = await params;
  return <TravelDocsDetail code={decodeURIComponent(reservationCode)} sp={await searchParams} />;
}
