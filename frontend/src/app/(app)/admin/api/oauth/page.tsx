import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Input, Table, Td, TextArea } from "@/components/crm/ui";
import { NotInPlan, apiSection } from "@/components/billing/api";
import { StatusPill } from "@/components/billing/ui";

type OAuthApp = {
  id: string; code: string; name: string; vendor: string | null; client_id: string; secret_prefix: string; redirect_uris: string[]; allowed_scopes: string[];
  status: string; status_label: string; grant: string; created_at: string; revoked_at: string | null;
};

const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50";

/** Aplicaciones OAuth 2.0 (spec 13 §2): registro de apps de terceros; el secreto se muestra una sola vez; rotar y revocar. */
export default async function OAuthAppsPage() {
  const { info, header } = await apiSection("oauth");
  if (!info.features.oauth_apps) return <>{header}<NotInPlan feature="oauth_apps" /></>;
  const apps = (await api<{ data: OAuthApp[] }>("/integrations/oauth-apps")).data;

  return (
    <>
      {header}
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="min-w-0 lg:col-span-2">
          <Table head={["Aplicación", "Client ID", "Scopes", "Estado", ""]} empty="Sin aplicaciones registradas">
            {apps.map((a) => (
              <tr key={a.id} data-oauth-app={a.code}>
                <Td><span className="font-medium">{a.name}</span><span className="block text-xs text-slate-500">{a.code}{a.vendor && ` · ${a.vendor}`} · {date(a.created_at)}</span></Td>
                <Td><code className="break-all text-xs">{a.client_id}</code><span className="block text-xs text-slate-500">secreto {a.secret_prefix}… · {a.grant}</span></Td>
                <Td><span className="text-xs">{a.allowed_scopes.join(", ")}</span></Td>
                <Td><StatusPill status={a.status} label={a.status_label} /></Td>
                <Td>
                  {a.status === "active" && (
                    <div className="flex flex-wrap gap-1">
                      <ActionForm path={`/integrations/oauth-apps/${a.id}/secret`} inline submitLabel="Rotar secreto" submitClass={small} reveal={[{ field: "client_secret", label: "Nuevo client secret" }]} />
                      <ActionForm path={`/integrations/oauth-apps/${a.id}`} method="DELETE" inline submitLabel="Revocar" submitClass={small} confirm={`¿Revocar ${a.name}? Sus tokens dejarán de funcionar.`} />
                    </div>
                  )}
                </Td>
              </tr>
            ))}
          </Table>
        </div>
        <Card title="Registrar aplicación">
          <ActionForm path="/integrations/oauth-apps" submitLabel="Registrar" className="space-y-3" reveal={[{ field: "client_secret", label: "Client secret" }]}>
            <Input name="name" label="Nombre" required maxLength={120} />
            <Input name="vendor" label="Proveedor (opcional)" maxLength={120} />
            <TextArea name="redirect_uris" label="URLs de redirección (una por línea)" rows={2} required />
            <fieldset className="text-sm">
              <legend className="mb-1 font-medium text-slate-700">Scopes permitidos</legend>
              {info.scopes.map((s) => <label key={s.key} className="flex items-center gap-2"><input type="checkbox" name="allowed_scopes[]" value={s.key} /><code className="text-xs">{s.key}</code></label>)}
            </fieldset>
          </ActionForm>
        </Card>
      </div>
    </>
  );
}
