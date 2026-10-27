import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { finPerms } from "@/lib/finance-server";
import { FLOWS, money, type AuditRow, type Invoice } from "@/lib/finance";
import Link from "@/components/Link";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnCls, btnGhostCls, Card, Drawer, Notice, PageHeader, TextArea } from "@/components/crm/ui";
import { Audit, FinStatus, Flow, NoPermission } from "@/components/finance/ui";
import { InvoiceForm, PrintButton } from "@/components/finance/forms";

type Detail = Invoice & {
  issuer: { name: string; trade_name: string | null; ruc: string | null } | null; client_email: string | null; creditable: string;
  lines: { line_no: number; description: string; quantity: string; unit_code: string; unit_value: string; igv_affectation: string; line_amount: string; igv: string }[];
  credit_notes: { number: string; total: string; status: string }[];
  events: { action: string; attempt: number; status: string; code: string | null; message: string | null; at: string }[];
  history: { status: string; note: string | null; at: string; user: string | null }[]; audit: AuditRow[];
};

const STEP: Record<string, number> = { pendiente_envio: 3, emitido: 4, observado: 4, rechazado: 4, aceptado: 5 };

/** Comprobante electrónico: vista previa imprimible, consulta al OSE, corrección y reenvío, NC y entrega (PROC-050). */
export default async function InvoicePage({ params, searchParams }: { params: Promise<{ number: string }>; searchParams: Promise<{ accion?: string }> }) {
  const [{ number }, sp] = await Promise.all([params, searchParams]);
  const perms = await finPerms();
  if (!perms.has("finance.invoicing")) return <NoPermission permission="finance.invoicing" />;
  const { data: i } = await api<{ data: Detail }>(`/finance/invoices/${encodeURIComponent(number)}`);
  const self = `/finanzas/facturacion/${i.number}`;
  const isCredit = i.doc_type === "07";
  const canCredit = !isCredit && i.status === "aceptado" && Number(i.creditable) > 0;
  const reasons = canCredit && sp.accion === "nc" ? (await api<{ meta: { credit_reasons: Record<string, string> } }>("/finance/invoices?filter[type]=07")).meta.credit_reasons : undefined;
  const step = i.delivered_at ? 7 : STEP[i.status] ?? 3;

  return (
    <>
      <p className="mb-2 text-sm print:hidden"><Link href="/finanzas/facturacion" className="text-sky-800 hover:underline">← Facturación</Link></p>
      <div className="print:hidden">
        <PageHeader title={`${i.type_label} ${i.number}`} subtitle={<>{i.customer_name} · {date(i.issue_date)} · <FinStatus value={i.status} label={i.status_label} /></>}
          actions={<>
            <PrintButton />
            {["pendiente_envio", "emitido", "observado", "rechazado"].includes(i.status) && (
              <ActionForm path={`/finance/invoices/${i.number}/status-check`} inline submitLabel="Consultar OSE" success="Estado consultado" submitClass={btnGhostCls} />
            )}
            {i.status === "observado" && <Link href={`${self}?accion=corregir`} scroll={false} className={btnGhostCls}>Corregir y reenviar</Link>}
            {canCredit && <Link href={`${self}?accion=nc`} scroll={false} className={btnGhostCls}>Nota de crédito</Link>}
            {i.status === "aceptado" && !i.delivered_at && (
              <ActionForm path={`/finance/invoices/${i.number}/deliver`} idempotent inline submitLabel="Entregar al cliente" success={`Enviado a ${i.client_email ?? "el cliente"}`} submitClass={btnCls} />
            )}
          </>} />
        {i.status === "observado" && <Notice tone="warning">Observado por el OSE ({i.ose_code}): {i.ose_message}</Notice>}
        {i.status === "rechazado" && <Notice tone="danger">Rechazado por el OSE ({i.ose_code}): {i.ose_message}. Emita un nuevo comprobante corregido.</Notice>}
        {i.delivered_at && <Notice>Entregado al cliente el {dateTime(i.delivered_at)}.</Notice>}
        <div className="mb-5"><Flow title="PROC-050 · Facturación electrónica" steps={FLOWS.invoice} current={step} /></div>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <article className="rounded-lg border border-slate-300 bg-white p-6 text-sm lg:col-span-2 print:col-span-3 print:border-0" aria-label="Vista previa del comprobante">
          <header className="mb-4 flex flex-wrap justify-between gap-4">
            <div><p className="text-lg font-semibold">{i.issuer?.name}</p><p className="text-slate-600">{i.issuer?.trade_name}{i.issuer?.ruc ? ` · RUC ${i.issuer.ruc}` : ""}</p></div>
            <div className="rounded border border-slate-400 px-4 py-2 text-center"><p className="font-semibold uppercase">{i.type_label} electrónica</p><p className="font-mono">{i.number}</p></div>
          </header>
          <dl className="mb-4 grid grid-cols-2 gap-2">
            <div><dt className="text-xs text-slate-500">Cliente</dt><dd>{i.customer_name}</dd></div>
            <div><dt className="text-xs text-slate-500">{i.customer_doc_type}</dt><dd>{i.customer_doc_number}</dd></div>
            <div><dt className="text-xs text-slate-500">Dirección</dt><dd>{i.customer_address ?? "—"}</dd></div>
            <div><dt className="text-xs text-slate-500">Emisión</dt><dd>{date(i.issue_date)} · {i.currency}{i.fx_rate ? ` · TC ${i.fx_rate}` : ""}</dd></div>
            {i.reference && <div><dt className="text-xs text-slate-500">Documento que modifica</dt><dd className="font-mono">{i.reference}</dd></div>}
            {i.credit_reason && <div><dt className="text-xs text-slate-500">Motivo ({i.credit_reason_code})</dt><dd>{i.credit_reason}</dd></div>}
          </dl>
          <table className="mb-4 w-full text-left">
            <thead className="border-b border-slate-300 text-xs uppercase text-slate-500"><tr><th className="py-1">Cant.</th><th>Descripción</th><th className="text-right">V. unitario</th><th className="text-right">Importe</th></tr></thead>
            <tbody>
              {i.lines.map((l) => <tr key={l.line_no} className="border-b border-slate-100"><td className="py-1">{Number(l.quantity)} {l.unit_code}</td><td>{l.description}</td><td className="text-right">{money(l.unit_value, i.currency)}</td><td className="text-right">{money(l.line_amount, i.currency)}</td></tr>)}
            </tbody>
          </table>
          <dl className="ml-auto max-w-xs space-y-1">
            <div className="flex justify-between"><dt>{i.operation_type === "exportacion" ? "Exportación (inafecto)" : "Op. gravada"}</dt><dd>{money(i.taxable, i.currency)}</dd></div>
            <div className="flex justify-between"><dt>IGV 18 %</dt><dd>{money(i.igv, i.currency)}</dd></div>
            <div className="flex justify-between border-t border-slate-300 pt-1 font-semibold"><dt>Total</dt><dd>{money(i.total, i.currency)}</dd></div>
          </dl>
          <p className="mt-3 text-xs">{i.in_words}</p>
          <footer className="mt-4 border-t border-slate-200 pt-2 text-xs text-slate-500">
            Representación impresa del comprobante electrónico. Hash: <span className="font-mono break-all">{i.hash ?? "pendiente"}</span>
            {i.ticket && <> · Ticket {i.provider} {i.ticket}</>}
          </footer>
        </article>
        <div className="space-y-4 print:hidden">
          <Card title="Respuesta del OSE">
            <ol className="space-y-2 text-sm">
              {i.events.map((e, n) => <li key={n} className="border-l-2 border-slate-200 pl-3">{e.action} #{e.attempt} · {e.status}{e.code && ` · ${e.code}`}
                {e.message && <span className="block text-xs text-slate-600">{e.message}</span>}<span className="block text-xs text-slate-500">{dateTime(e.at)}</span></li>)}
              {i.events.length === 0 && <li className="text-slate-500">Sin envíos todavía.</li>}
            </ol>
          </Card>
          {i.credit_notes.length > 0 && (
            <Card title="Notas de crédito">
              <ul className="text-sm">{i.credit_notes.map((n) => <li key={n.number}><Link href={`/finanzas/facturacion/${n.number}`} className="font-mono text-sky-800 hover:underline">{n.number}</Link> · {money(n.total, i.currency)} · {n.status}</li>)}</ul>
              <p className="mt-2 text-xs text-slate-500">Saldo acreditable: {money(i.creditable, i.currency)}</p>
            </Card>
          )}
          <Card title="Datos de la venta">
            <p className="text-sm">Reserva {i.reservation ?? "—"} · CxC {i.receivable ? <Link href={`/finanzas/cobros/${i.receivable}`} className="text-sky-800 hover:underline">{i.receivable}</Link> : "—"}</p>
          </Card>
          <Card title="Auditoría"><Audit rows={i.audit} /></Card>
        </div>
      </div>

      {i.status === "observado" && sp.accion === "corregir" && (
        <Drawer title={`Corregir y reenviar ${i.number}`} close={self}>
          <p className="mb-3 text-sm text-slate-600">Observación del OSE: {i.ose_message}</p>
          <ActionForm path={`/finance/invoices/${i.number}/resend`} idempotent ifMatch={i.lock_version} redirectTo={self} submitLabel="Reenviar al OSE">
            <TextArea label="Descripción corregida" name="description" defaultValue={i.description} required maxLength={500} rows={3} />
            <p className="text-xs text-slate-500">El número del comprobante se conserva (FIN-BR-49).</p>
          </ActionForm>
        </Drawer>
      )}
      {canCredit && sp.accion === "nc" && (
        <Drawer title={`Nota de crédito sobre ${i.number}`} close={self}>
          <InvoiceForm back={self} creditReasons={reasons} draft={{
            reservation: i.reservation, currency: i.currency, doc_type: "07", customer_doc_type: i.customer_doc_type, customer_doc_number: i.customer_doc_number,
            customer_name: i.customer_name, customer_address: i.customer_address, domiciled: i.domiciled, max: i.creditable, amount: i.creditable, fx_rate: i.fx_rate,
            description: `Anulación de ${i.number} — ${i.description}`, ref_invoice_id: i.id, ref_number: i.number,
          }} />
        </Drawer>
      )}
    </>
  );
}
