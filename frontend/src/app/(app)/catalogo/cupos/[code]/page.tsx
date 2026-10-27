import { AllotmentDetail, type DetailParams } from "@/components/inventory/AllotmentDetail";

/** Detalle del contrato de cupo (spec 11 §2). */
export default async function CupoPage({ params, searchParams }: { params: Promise<{ code: string }>; searchParams: Promise<DetailParams> }) {
  return <AllotmentDetail code={(await params).code} sp={await searchParams} />;
}
