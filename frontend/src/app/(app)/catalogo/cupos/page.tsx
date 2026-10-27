import { AllotmentsView, type CuposParams } from "@/components/inventory/allotments";

/** Cupos y bloqueos (spec 11 §2): grilla, contratos, release y pick-up. */
export default async function CuposPage({ searchParams }: { searchParams: Promise<CuposParams> }) {
  return <AllotmentsView sp={await searchParams} />;
}
