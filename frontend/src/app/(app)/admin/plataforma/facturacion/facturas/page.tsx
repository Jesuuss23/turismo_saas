import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, Table, Td } from "@/components/crm/ui";
import { Facts, PlatformBillingHeader, StatusPill } from "@/components/billing/ui";
import { pen, type InvoiceDetail, type PlatformInvoice } from "@/lib/billing";

const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50";

/** Facturas de la plataforma por tenant (spec 13 §2): reintentar cobro, pago manual y anular con nota de crédito. */
export default async function PlatformInvoicesPage({ searchParams }: { searchParams: Promise<{ tenant?: string; status?: string; n?: string }> }) {
  const sp = await searchParams;
  const res = await api<{ data: PlatformInvoice[]; tenants: { value: string; label: string }[]; statuses: Record<string, string> }>(
    `/platform/invoices${qs({ tenant: sp.tenant, status: sp.status })}`);
  const detail = sp.n ? (await api<{ data: InvoiceDetail }>(`/platform/invoices/${encodeURIComponent(sp.n)}`)).data : null;
  const back = `/admin/plataforma/facturacion/facturas${qs({ tenant: sp.tenant, status: sp.status })}`;

  return (
    <>
      <PlatformBillingHeader active="facturas" />
      <form method="get" className="mb-4 flex flex-wrap gap-2">
        <select name="tenant" defaultValue={sp.tenant ?? ""} aria-label="Tenant" className="max-w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
          <option value="">Todos los tenants</option>{res.tenants.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}</select>
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
          <option value="">Todos los estados</option>{Object.entries(res.statuses).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        <button className="rounded-md border border-slate-300 px-3 py-2 text-sm">Filtrar</button>
      </form>
      <Table head={["Factura", "Tenant", "Periodo", "Total", "Estado", "SUNAT", ""]} empty="Sin facturas">
        {res.data.map((i) => (
          <tr key={i.id} data-invoice={i.number}>
            <Td><Link href={`${back}${back.includes("?") ? "&" : "?"}n=${i.id}`} scroll={false} className="font-mono text-sky-800 hover:underline">{i.number}</Link>
              <span className="block text-xs text-slate-500">{date(i.issue_date)}</span></Td>
            <Td>{i.tenant_name}<span className="block font-mono text-xs text-slate-500">{i.tenant_code}</span></Td>
            <Td>{date(i.period_from)} al {date(i.period_to)}</Td>
            <Td className="whitespace-nowrap">{pen(i.total)}</Td>
            <Td><StatusPill status={i.status} label={i.status_label} />{i.dunning_next_retry_at && <span className="block text-xs text-slate-500">Reintento {dateTime(i.dunning_next_retry_at)}</span>}</Td>
            <Td><StatusPill status={i.sunat_status} label={i.sunat_label} /></Td>
            <Td>{["failed", "uncollectible", "open"].includes(i.status) && <ActionForm path={`/platform/invoices/${i.id}/retry`} idempotent inline submitLabel="Reintentar cobro" submitClass={small} />}</Td>
          </tr>
        ))}
      </Table>
      {detail && (
        <Drawer title={`Factura ${detail.number}`} close={back}>
          <div className="space-y-4 text-sm">
            <Facts rows={[
              ["Tenant", `${detail.tenant_name ?? ""} ${detail.tenant_code ?? ""}`], ["Cliente", `${detail.bill_to.name} · RUC ${detail.bill_to.ruc}`],
              ["Periodo", `${date(detail.period_from)} al ${date(detail.period_to)}`], ["Estado", <StatusPill key="s" status={detail.status} label={detail.status_label} />],
              ["SUNAT", <StatusPill key="u" status={detail.sunat_status} label={detail.sunat_label} />], ["Total", pen(detail.total)],
              ...(detail.void_reason ? [["Anulada", detail.void_reason] as [string, string]] : []),
            ]} />
            <Table head={["Descripción", "Importe"]}>{detail.lines.map((l, n) => <tr key={n}><Td>{l.description}</Td><Td className="whitespace-nowrap">{pen(l.amount)}</Td></tr>)}</Table>
            {detail.attempts.length > 0 && (
              <ul className="space-y-1 text-xs text-slate-600">
                {detail.attempts.map((a) => <li key={a.attempt_no}>#{a.attempt_no} · {dateTime(a.at)} · {a.source} · {a.result === "succeeded" ? "aprobado" : "rechazado"}{a.message && ` · ${a.message}`}</li>)}
              </ul>
            )}
            <a href={`/bff/file?path=${encodeURIComponent(`/platform/invoices/${detail.id}/pdf`)}`} target="_blank" rel="noopener" className={small}>Ver PDF</a>
            {["failed", "uncollectible", "open"].includes(detail.status) && (
              <>
                <ActionForm path={`/platform/invoices/${detail.id}/retry`} idempotent submitLabel="Reintentar cobro ahora" />
                <ActionForm path={`/platform/invoices/${detail.id}/manual-payment`} idempotent submitLabel="Registrar pago manual" className="grid gap-2 sm:grid-cols-2">
                  <input name="reference" required placeholder="N.° de operación" aria-label="Referencia" className="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                  <select name="method" aria-label="Medio" className="rounded-md border border-slate-300 px-2 py-1.5 text-sm"><option value="transferencia">Transferencia</option><option value="deposito">Depósito</option></select>
                </ActionForm>
              </>
            )}
            {detail.status !== "void" && (
              <details><summary className="cursor-pointer text-xs text-rose-700">Anular con nota de crédito</summary>
                <ActionForm path={`/platform/invoices/${detail.id}/void`} idempotent submitLabel="Anular factura" className="mt-2 space-y-2" confirm="¿Anular la factura y emitir la nota de crédito?">
                  <input name="reason" required placeholder="Motivo" aria-label="Motivo" className="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                </ActionForm>
              </details>
            )}
          </div>
        </Drawer>
      )}
    </>
  );
}
