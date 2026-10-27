import { api } from "@/lib/api";
import { dateTime, qs } from "@/lib/crm";
import Link from "@/components/Link";
import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, Table, Td } from "@/components/crm/ui";
import { NotInPlan, apiSection } from "@/components/billing/api";
import { Facts, StatusPill } from "@/components/billing/ui";
import { type Delivery, type Webhook } from "@/lib/billing";

type DeliveryDetail = Delivery & { payload: unknown; request_headers: Record<string, string>; response_excerpt: string | null };

const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50";
const STATUSES: Record<string, string> = { pending: "Pendiente", delivered: "Entregada", retrying: "Reintentando", failed: "Fallida" };

/** Log de entregas (spec 13 §2): HTTP, duración, intento y próximo reintento; detalle con headers, firma, payload y respuesta; reenviar (manual). */
export default async function DeliveriesPage({ searchParams }: { searchParams: Promise<{ status?: string; webhook?: string; d?: string }> }) {
  const sp = await searchParams;
  const { info, header } = await apiSection("entregas");
  if (!info.features.webhooks) return <>{header}<NotInPlan feature="webhooks" /></>;
  const [rows, hooks, detail] = await Promise.all([
    api<{ data: Delivery[] }>(`/integrations/webhook-deliveries${qs({ status: sp.status, webhook: sp.webhook })}`).then((r) => r.data),
    api<{ data: Webhook[] }>("/integrations/webhooks").then((r) => r.data),
    sp.d ? api<{ data: DeliveryDetail }>(`/integrations/webhook-deliveries/${encodeURIComponent(sp.d)}`).then((r) => r.data) : Promise.resolve(null),
  ]);
  const back = `/admin/api/entregas${qs({ status: sp.status, webhook: sp.webhook })}`;

  return (
    <>
      {header}
      <form method="get" className="mb-4 flex flex-wrap gap-2">
        <select name="webhook" defaultValue={sp.webhook ?? ""} aria-label="Webhook" className="max-w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
          <option value="">Todos los webhooks</option>{hooks.map((w) => <option key={w.id} value={w.id}>{w.code} · {w.url}</option>)}</select>
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
          <option value="">Todos los estados</option>{Object.entries(STATUSES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        <button className="rounded-md border border-slate-300 px-3 py-2 text-sm">Filtrar</button>
      </form>
      <Table head={["Entrega", "Evento", "HTTP", "Intento", "Estado", ""]} empty="Sin entregas">
        {rows.map((d) => (
          <tr key={d.id} data-delivery={d.code}>
            <Td><Link href={`${back}${back.includes("?") ? "&" : "?"}d=${d.id}`} scroll={false} className="font-mono text-xs text-sky-800 hover:underline">{d.code}</Link>
              <span className="block text-xs text-slate-500">{d.webhook_code} · {dateTime(d.created_at)}</span></Td>
            <Td><code className="text-xs">{d.event_type}</code>{d.entity_code && <span className="block text-xs text-slate-500">{d.entity_code}</span>}</Td>
            <Td>{d.http_status ?? "—"}{d.duration_ms !== null && <span className="block text-xs text-slate-500">{d.duration_ms} ms</span>}</Td>
            <Td>{d.attempt}{d.manual && <span className="block text-xs text-slate-500">manual</span>}</Td>
            <Td><StatusPill status={d.status} label={d.status_label} />{d.next_retry_at && <span className="block text-xs text-slate-500">próximo {dateTime(d.next_retry_at)}</span>}
              {d.error && <span className="block max-w-xs text-xs text-rose-700">{d.error}</span>}</Td>
            <Td><ActionForm path={`/integrations/webhook-deliveries/${d.id}/redeliver`} idempotent inline submitLabel="Reenviar" submitClass={small} /></Td>
          </tr>
        ))}
      </Table>
      {detail && (
        <Drawer title={`Entrega ${detail.code}`} close={back}>
          <div className="space-y-4 text-sm">
            <Facts rows={[["Webhook", detail.webhook_code], ["Evento", `${detail.event_type} · ${detail.event_id}`], ["Estado", <StatusPill key="s" status={detail.status} label={detail.status_label} />],
              ["HTTP", `${detail.http_status ?? "—"}${detail.duration_ms !== null ? ` · ${detail.duration_ms} ms` : ""}`], ["Intento", `${detail.attempt}${detail.manual ? " (manual)" : ""}`]]} />
            <div><p className="mb-1 font-medium text-slate-800">Headers enviados</p>
              <pre className="overflow-x-auto rounded bg-slate-50 p-2 text-xs">{Object.entries(detail.request_headers).map(([k, v]) => `${k}: ${v}`).join("\n") || "Aún no se envió"}</pre></div>
            <div><p className="mb-1 font-medium text-slate-800">Payload</p><pre className="overflow-x-auto rounded bg-slate-50 p-2 text-xs">{JSON.stringify(detail.payload, null, 2)}</pre></div>
            <div><p className="mb-1 font-medium text-slate-800">Respuesta</p><pre className="overflow-x-auto rounded bg-slate-50 p-2 text-xs">{detail.response_excerpt ?? detail.error ?? "—"}</pre></div>
            <ActionForm path={`/integrations/webhook-deliveries/${detail.id}/redeliver`} idempotent submitLabel="Reenviar" />
          </div>
        </Drawer>
      )}
    </>
  );
}
