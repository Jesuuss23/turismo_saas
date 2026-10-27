import { InboxView, type InboxParams } from "@/components/collab/inbox";

/** Conversación seleccionada (spec 10 §2). */
export default async function ConversationPage({ params, searchParams }: { params: Promise<{ code: string }>; searchParams: Promise<InboxParams> }) {
  const [{ code }, sp] = await Promise.all([params, searchParams]);
  return <InboxView sp={sp} code={code} />;
}
