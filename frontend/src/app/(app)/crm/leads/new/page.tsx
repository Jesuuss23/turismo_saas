import { crmMeta, catalogOptions, currentUser, owners } from "@/lib/crm-server";
import { Card, Notice, PageHeader } from "@/components/crm/ui";
import { LeadForm } from "../LeadForm";

export default async function NewLeadPage() {
  const [meta, me] = await Promise.all([crmMeta(), currentUser()]);
  if (!meta.scope.own_only && !meta.scope.supervisor) {
    return <Notice>Modo consulta: su rol no puede registrar leads (permiso crm.leads.manage).</Notice>;
  }
  const advisors = meta.scope.own_only ? null : (await owners()).data.map((o) => ({ value: o.id, label: o.name }));
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: "America/Lima" }).format(new Date());

  return (
    <>
      <PageHeader title="Nuevo lead" subtitle="PROC-004 · Captación" />
      <Card>
        <LeadForm
          channels={catalogOptions(meta, "lead_channel")}
          sources={catalogOptions(meta, "lead_source")}
          interests={catalogOptions(meta, "lead_interest")}
          owners={advisors}
          fixedOwner={meta.scope.own_only ? me.name : null}
          today={today}
        />
      </Card>
    </>
  );
}
