import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Input, Select, Table, Td } from "@/components/crm/ui";
import { NotInPlan, apiSection } from "@/components/billing/api";
import { StatusPill } from "@/components/billing/ui";
import { type ApiKey } from "@/lib/billing";

const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50";

/** API keys (spec 13 §2, API-BR-01/02): crear con scopes, entorno, vencimiento e IP allowlist; el token se muestra una sola vez; revocar. */
export default async function ApiKeysPage() {
  const { info, header } = await apiSection("");
  if (!info.features.api_access) return <>{header}<NotInPlan feature="api_access" /></>;
  const keys = (await api<{ data: ApiKey[] }>("/integrations/api-keys")).data;

  return (
    <>
      {header}
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="min-w-0 lg:col-span-2">
          <Table head={["API key", "Token", "Scopes", "Último uso", "Hoy", "Estado", ""]} empty="Aún no tiene API keys">
            {keys.map((k) => (
              <tr key={k.id} data-api-key={k.code}>
                <Td><span className="font-medium text-slate-900">{k.name}</span><span className="block font-mono text-xs text-slate-500">{k.code} · {k.environment}</span></Td>
                <Td><code className="whitespace-nowrap font-mono text-xs">{k.masked}</code>{k.expires_at && <span className="block text-xs text-slate-500">Vence {date(k.expires_at)}</span>}</Td>
                <Td><span className="text-xs">{k.scopes.join(", ")}</span>{k.ip_allowlist.length > 0 && <span className="block text-xs text-slate-500">IP: {k.ip_allowlist.join(", ")}</span>}</Td>
                <Td>{k.last_used_at ? <>{dateTime(k.last_used_at)}<span className="block text-xs text-slate-500">{k.last_used_ip}</span></> : "Nunca"}</Td>
                <Td>{k.calls_today}{k.throttled_today > 0 && <span className="block text-xs text-rose-700">{k.throttled_today} × 429</span>}</Td>
                <Td><StatusPill status={k.status} label={k.status_label} />{k.revoke_reason && <span className="block text-xs text-slate-500">{k.revoke_reason}</span>}</Td>
                <Td>
                  {k.status === "active" && (
                    <details><summary className="cursor-pointer text-xs text-rose-700">Revocar</summary>
                      <ActionForm path={`/integrations/api-keys/${k.id}`} method="DELETE" className="mt-1 space-y-1" submitLabel="Revocar ahora" submitClass={small}
                        confirm={`¿Revocar ${k.code}? Las llamadas con esta key responderán 401 de inmediato.`}>
                        <input name="reason" required placeholder="Motivo" aria-label="Motivo de la revocación" className="w-36 rounded border border-slate-300 px-2 py-1 text-xs" />
                      </ActionForm>
                    </details>
                  )}
                </Td>
              </tr>
            ))}
          </Table>
        </div>
        <Card title="Nueva API key">
          <ActionForm path="/integrations/api-keys" submitLabel="Crear API key" className="space-y-3"
            reveal={[{ field: "token", label: "Token de la API key", note: "Cópielo ahora: por seguridad solo guardamos su hash y no se volverá a mostrar." }]}>
            <Input name="name" label="Nombre" placeholder="ERP contable" required maxLength={120} />
            <Select name="environment" label="Entorno" options={[{ value: "live", label: "Producción (live)" }, { value: "test", label: "Pruebas (test)" }]} defaultValue="live" />
            <fieldset className="text-sm">
              <legend className="mb-1 font-medium text-slate-700">Scopes</legend>
              {info.scopes.map((s) => (
                <label key={s.key} className="flex items-center gap-2"><input type="checkbox" name="scopes[]" value={s.key} defaultChecked={s.key === "reservations:read"} />
                  <span><code className="text-xs">{s.key}</code> · {s.label}</span></label>
              ))}
            </fieldset>
            <Input name="expires_at" label="Vence (opcional)" type="date" />
            <Input name="ip_allowlist" label="IP allowlist (opcional)" placeholder="200.48.10.0/24, 190.12.4.8" />
          </ActionForm>
        </Card>
      </div>
    </>
  );
}
