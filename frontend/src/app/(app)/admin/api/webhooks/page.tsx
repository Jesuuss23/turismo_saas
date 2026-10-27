import Link from "@/components/Link";
import { api } from "@/lib/api";
import { dateTime } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Input } from "@/components/crm/ui";
import { NotInPlan, apiSection } from "@/components/billing/api";
import { StatusPill } from "@/components/billing/ui";
import { type Webhook } from "@/lib/billing";

const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50";

/** Webhooks salientes (spec 13 §2, API-BR-04..07): URL HTTPS con anti-SSRF, eventos, ping, rotación del secreto con 24 h de gracia, pausar y reactivar. */
export default async function WebhooksPage() {
  const { info, header } = await apiSection("webhooks");
  if (!info.features.webhooks) return <>{header}<NotInPlan feature="webhooks" /></>;
  const hooks = (await api<{ data: Webhook[] }>("/integrations/webhooks")).data;

  return (
    <>
      {header}
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="min-w-0 space-y-3 lg:col-span-2">
          {hooks.length === 0 && <p className="rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">Aún no tiene webhooks.</p>}
          {hooks.map((w) => (
            <Card key={w.id} title={<span className="break-all">{w.url}</span>} actions={<StatusPill status={w.status} label={w.status_label} />}>
              <div className="space-y-2 text-sm" data-webhook={w.code}>
                <p className="text-slate-600"><span className="font-mono text-xs">{w.code}</span> · secreto <code className="text-xs">{w.secret_prefix}…</code> · versión {w.api_version}
                  {w.description && ` · ${w.description}`}</p>
                <p className="text-xs text-slate-600">Eventos: {w.event_labels.join(", ")}</p>
                <p className="text-xs text-slate-600">
                  24 h: {w.delivered_24h} entregadas · {w.failed_24h} fallidas · {w.consecutive_failures} fallo(s) consecutivo(s)
                  {" · "}<Link href={`/admin/api/entregas?webhook=${w.id}`} className="text-sky-800 hover:underline">Ver entregas</Link>
                </p>
                {w.disabled_reason && <p className="rounded-md bg-rose-50 px-3 py-2 text-xs text-rose-800 ring-1 ring-rose-200">Deshabilitado: {w.disabled_reason}</p>}
                {w.rotation_grace_until && <p className="text-xs text-amber-800">El secreto anterior sigue firmando hasta {dateTime(w.rotation_grace_until)}.</p>}
                <div className="flex flex-wrap items-start gap-1">
                  <ActionForm path={`/integrations/webhooks/${w.id}/ping`} inline submitLabel="Enviar ping" submitClass={small} />
                  {w.status === "active" && <ActionForm path={`/integrations/webhooks/${w.id}`} method="PATCH" inline submitLabel="Pausar" submitClass={small}><input type="hidden" name="status" value="paused" /></ActionForm>}
                  {w.status !== "active" && <ActionForm path={`/integrations/webhooks/${w.id}`} method="PATCH" inline submitLabel={w.status === "disabled" ? "Reactivar" : "Reanudar"} submitClass={small}><input type="hidden" name="status" value="active" /></ActionForm>}
                  <ActionForm path={`/integrations/webhooks/${w.id}/rotate-secret`} inline submitLabel="Rotar secreto" submitClass={small}
                    confirm="El secreto actual seguirá firmando 24 h. ¿Rotar?" reveal={[{ field: "secret", label: "Nuevo secreto de firma" }]} />
                  <ActionForm path={`/integrations/webhooks/${w.id}`} method="DELETE" inline submitLabel="Eliminar" submitClass={small} confirm={`¿Eliminar ${w.code}?`} />
                </div>
                <details><summary className="cursor-pointer text-xs text-slate-600">Editar URL y eventos</summary>
                  <ActionForm path={`/integrations/webhooks/${w.id}`} method="PATCH" submitLabel="Guardar" className="mt-2 space-y-2">
                    <Input name="url" label="URL (HTTPS)" defaultValue={w.url} required />
                    <Events events={info.events} selected={w.events} />
                  </ActionForm>
                </details>
              </div>
            </Card>
          ))}
        </div>
        <Card title="Nuevo webhook">
          <ActionForm path="/integrations/webhooks" submitLabel="Crear webhook" className="space-y-3"
            reveal={[{ field: "secret", label: "Secreto de firma (Qhapaq-Signature)", note: "Guárdelo en su receptor para verificar la firma: no se volverá a mostrar." }]}>
            <Input name="url" label="URL (HTTPS)" placeholder="https://erp.miagencia.pe/webhooks/qhapaq" required />
            <Input name="description" label="Descripción (opcional)" maxLength={200} />
            <Events events={info.events} selected={["reservation.created", "reservation.confirmed"]} />
          </ActionForm>
          <p className="mt-3 text-xs text-slate-500">Solo URLs HTTPS públicas: se rechazan IPs privadas, localhost y metadatos de la nube (anti-SSRF).</p>
        </Card>
      </div>
    </>
  );
}

function Events({ events, selected }: { events: { key: string; label: string }[]; selected: string[] }) {
  return (
    <fieldset className="text-sm">
      <legend className="mb-1 font-medium text-slate-700">Eventos</legend>
      <div className="grid gap-x-3 sm:grid-cols-2">
        {events.map((e) => (
          <label key={e.key} className="flex items-center gap-2"><input type="checkbox" name="events[]" value={e.key} defaultChecked={selected.includes(e.key)} />
            <span className="text-xs"><code>{e.key}</code></span></label>
        ))}
      </div>
    </fieldset>
  );
}
