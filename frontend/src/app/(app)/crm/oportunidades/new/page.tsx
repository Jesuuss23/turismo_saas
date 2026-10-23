import { api } from "@/lib/api";
import { crmMeta, currentUser, owners } from "@/lib/crm-server";
import { Card, PageHeader } from "@/components/crm/ui";
import { OpportunityForm } from "../OpportunityForm";

export default async function NewOpportunityPage({ searchParams }: { searchParams: Promise<{ client?: string }> }) {
  const sp = await searchParams;
  const [meta, me, clients] = await Promise.all([
    crmMeta(), currentUser(),
    api<{ data: { id: string; label: string }[] }>("/crm/activities/related-options?type=client"),
  ]);
  const advisors = meta.scope.own_only ? null : (await owners()).data.map((o) => ({ value: o.id, label: o.name }));

  return (
    <>
      <PageHeader title="Nueva oportunidad" subtitle="PROC-006" />
      <Card>
        <OpportunityForm clients={clients.data.map((c) => ({ value: c.id, label: c.label }))} defaultClient={sp.client} owners={advisors}
          fixedOwner={meta.scope.own_only ? me.name : null} today={new Intl.DateTimeFormat("en-CA", { timeZone: "America/Lima" }).format(new Date())} />
      </Card>
    </>
  );
}
