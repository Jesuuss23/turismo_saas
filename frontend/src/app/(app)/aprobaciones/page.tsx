import { ApprovalsView, type ApprovalParams } from "@/components/collab/approvals";

/** Bandeja de aprobaciones unificada (spec 10 §2). */
export default async function ApprovalsPage({ searchParams }: { searchParams: Promise<ApprovalParams> }) {
  return <ApprovalsView sp={await searchParams} />;
}
