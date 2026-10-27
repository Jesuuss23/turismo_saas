import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, Table, Td } from "@/components/crm/ui";
import { Facts, StatusPill, SubscriptionHeader } from "@/components/billing/ui";
import { pen, type InvoiceDetail, type PlatformInvoice } from "@/lib/billing";

const KIND: Record<string, string> = { subscription: "Suscripción", proration: "Prorrateo", addon: "Add-on", manual: "Manual" };
const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50";

/** Facturas de Qhapaq Cloud al tenant (spec 13 §2): ver el comprobante, pagar ahora (SUB-BR-12) y reenviar por correo. */
export default async function SubscriptionInvoicesPage({ searchParams }: { searchParams: Promise<{ n?: string; status?: string }> }) {
  const sp = await searchParams;
  const rows = (await api<{ data: PlatformInvoice[] }>(`/billing/invoices${sp.status ? `?status=${encodeURIComponent(sp.status)}` : ""}`)).data;
  const detail = sp.n ? (await api<{ data: InvoiceDetail }>(`/billing/invoices/${encodeURIComponent(sp.n)}`)).data : null;

  return (
    <>
      <SubscriptionHeader active="facturas" />
      <Table head={["Factura", "Concepto", "Periodo", "Total", "Estado", "SUNAT", ""]} empty="Aún no tiene facturas">
        {rows.map((i) => (
          <tr key={i.id} data-invoice={i.number}>
            <Td><Link href={`/admin/suscripcion/facturas?n=${i.number}`} scroll={false} className="font-mono text-sky-800 hover:underline">{i.number}</Link>
              <span className="block text-xs text-slate-500">{date(i.issue_date)}</span></Td>
            <Td>{KIND[i.kind] ?? i.kind}</Td>
            <Td>{date(i.period_from)} al {date(i.period_to)}</Td>
            <Td className="whitespace-nowrap">{pen(i.total)}</Td>
            <Td><StatusPill status={i.status} label={i.status_label} />{i.next_retry_at && <span className="block text-xs text-slate-500">Reintento {dateTime(i.next_retry_at)}</span>}</Td>
            <Td><StatusPill status={i.sunat_status} label={i.sunat_label} /></Td>
            <Td>
              <div className="flex flex-wrap gap-1">
                {i.payable && <ActionForm path={`/billing/invoices/${i.number}/pay`} idempotent inline submitLabel="Pagar ahora" submitClass={small} confirm={`¿Pagar ${pen(i.total)} con la tarjeta registrada?`} />}
                <a href={`/bff/file?path=${encodeURIComponent(`/billing/invoices/${i.number}/pdf`)}`} target="_blank" rel="noopener" className={small}>PDF</a>
              </div>
            </Td>
          </tr>
        ))}
      </Table>
      {detail && <InvoiceDrawer i={detail} />}
    </>
  );
}

function InvoiceDrawer({ i }: { i: InvoiceDetail }) {
  return (
    <Drawer title={`Factura ${i.number}`} close="/admin/suscripcion/facturas">
      <div className="space-y-4 text-sm" data-invoice-detail={i.number}>
        <div className="rounded-lg border border-slate-200 p-3">
          <p className="font-semibold text-slate-900">{i.issuer.name}</p>
          <p className="text-xs text-slate-500">RUC {i.issuer.ruc} · {i.issuer.address}</p>
          <p className="mt-2 text-xs uppercase tracking-wide text-slate-500">Factura electrónica {i.number}</p>
        </div>
        <Facts rows={[
          ["Cliente", <>{i.bill_to.name}<span className="block text-xs text-slate-500">RUC {i.bill_to.ruc} · {i.bill_to.address}</span></>],
          ["Emisión", date(i.issue_date)], ["Periodo", `${date(i.period_from)} al ${date(i.period_to)}`],
          ["Estado", <StatusPill key="s" status={i.status} label={i.status_label} />], ["SUNAT", <StatusPill key="u" status={i.sunat_status} label={i.sunat_label} />],
          ...(i.paid_at ? [["Pagada", `${dateTime(i.paid_at)}${i.payment_ref ? ` · ${i.payment_ref}` : ""}`] as [string, string]] : []),
        ]} />
        <Table head={["Descripción", "Cant.", "Importe"]}>
          {i.lines.map((l, n) => <tr key={n}><Td>{l.description}</Td><Td>{l.quantity}</Td><Td className="whitespace-nowrap">{pen(l.amount)}</Td></tr>)}
        </Table>
        <Facts rows={[["Subtotal", pen(i.subtotal)], [`IGV ${i.igv_pct} %`, pen(i.igv)], ["Total", <strong key="t">{pen(i.total)}</strong>]]} />
        {i.credit_note && <p className="text-slate-700">Nota de crédito {i.credit_note.number} por {pen(i.credit_note.total)}: {i.credit_note.reason}</p>}
        {i.attempts.length > 0 && (
          <div>
            <p className="mb-1 font-medium text-slate-800">Intentos de cobro</p>
            <ul className="space-y-1 text-xs text-slate-600">
              {i.attempts.map((a) => <li key={a.attempt_no}>#{a.attempt_no} · {dateTime(a.at)} · {a.result === "succeeded" ? "Aprobado" : "Rechazado"}{a.message && ` · ${a.message}`}</li>)}
            </ul>
          </div>
        )}
        <div className="flex flex-wrap gap-2">
          {i.payable && <ActionForm path={`/billing/invoices/${i.number}/pay`} idempotent inline submitLabel={`Pagar ${pen(i.total)}`} />}
          <ActionForm path={`/billing/invoices/${i.number}/resend`} inline submitLabel="Reenviar por correo" submitClass={small} />
          <a href={`/bff/file?path=${encodeURIComponent(`/billing/invoices/${i.number}/pdf`)}`} target="_blank" rel="noopener" className={small}>Ver PDF</a>
        </div>
      </div>
    </Drawer>
  );
}
