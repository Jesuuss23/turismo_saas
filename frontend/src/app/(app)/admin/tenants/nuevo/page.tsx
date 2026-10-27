import { api } from "@/lib/api";
import { PageHeader } from "@/components/crm/ui";
import { TenantWizard, type WizardMeta } from "@/components/admin/TenantWizard";

/** Alta de tenant en 11 pasos (PROC-001, spec 09 §4.1): cada paso se valida en el backend antes de avanzar. */
export default async function NewTenantPage() {
  const { data } = await api<{ data: WizardMeta }>("/platform/tenants/wizard");
  return (
    <section>
      <PageHeader title="Nuevo tenant" subtitle="Provisiona la cuenta, su sede, roles, canales y la invitación del administrador." />
      <TenantWizard meta={data} />
    </section>
  );
}
