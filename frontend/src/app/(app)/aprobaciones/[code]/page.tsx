import { ApprovalsView, type ApprovalParams } from "@/components/collab/approvals";

/** Detalle de la solicitud como drawer sobre la lista (spec 10 §2). */
export default async function ApprovalPage({ params, searchParams }: { params: Promise<{ code: string }>; searchParams: Promise<ApprovalParams> }) {
  const [{ code }, sp] = await Promise.all([params, searchParams]);
  return <ApprovalsView sp={sp} code={code} />;
}
