import { InboxView, type InboxParams } from "@/components/collab/inbox";

/** Bandeja omnicanal (spec 10 §2). */
export default async function InboxPage({ searchParams }: { searchParams: Promise<InboxParams> }) {
  return <InboxView sp={await searchParams} />;
}
