import { catalogOptions, crmMeta, currentUser, owners } from "@/lib/crm-server";
import { Card, Notice, PageHeader } from "@/components/crm/ui";
import { ClientForm } from "../ClientForm";

export default async function NewClientPage() {
  const [meta, me] = await Promise.all([crmMeta(), currentUser()]);
  if (!meta.can.clients_manage) return <Notice>Modo consulta: su rol no puede registrar clientes (permiso crm.clients.manage).</Notice>;
  const advisors = meta.scope.own_only ? null : (await owners()).data.map((o) => ({ value: o.id, label: o.name }));

  return (
    <>
      <PageHeader title="Nuevo cliente" subtitle="PROC-008 · Maestro de clientes" />
      <Card>
        <ClientForm segments={catalogOptions(meta, "client_segment")} owners={advisors} fixedOwner={meta.scope.own_only ? me.name : null} />
      </Card>
    </>
  );
}
