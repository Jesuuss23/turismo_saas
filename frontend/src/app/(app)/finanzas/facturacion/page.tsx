import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import { finPerms } from "@/lib/finance-server";
import { FLOWS, money, type Invoice } from "@/lib/finance";
import Link from "@/components/Link";
import { ExportButton } from "@/components/crm/ExportButton";
import { btnGhostCls, Card, Drawer, inputCls, Kpis, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { RowAction } from "@/components/supplier/ui";
import { FinStatus, Flow, NoPermission } from "@/components/finance/ui";
import { InvoiceForm } from "@/components/finance/forms";

type Meta = { types: Record<string, string>; labels: Record<string, string>; credit_reasons: Record<string, string>; provider: string };
type Uninvoiced = { reservation: string; client: string | null; collected: string; invoiced: string; pending: string;
  receivables: { id: string; code: string; concept: string; currency: string; paid: string; max: string }[] };
type Summary = { issued_month: number; issued_month_amount: string; accepted: number; observed: number; waiting: number; uninvoiced: string; uninvoiced_rows: Uninvoiced[] };
type Search = { type?: string; status?: string; emitir?: string };

/** Finanzas › Facturación electrónica (PROC-050). OSE/PSE simulado mientras se valida el proveedor (§1.1). */
export default async function InvoicingPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const perms = await finPerms();
  if (!perms.has("finance.invoicing")) {
    return <><PageHeader title="Facturación electrónica" /><NoPermission permission="finance.invoicing" /></>;
  }
  const [{ data: rows, meta }, { data: s }] = await Promise.all([
    api<{ data: Invoice[]; meta: Meta }>(`/finance/invoices${qs({ "filter[type]": sp.type, "filter[status]": sp.status })}`),
    api<{ data: Summary }>("/finance/invoices/summary"),
  ]);
  const base = `/finanzas/facturacion${qs({ type: sp.type, status: sp.status })}`;
  const draft = sp.emitir ? (await api<{ data: Parameters<typeof InvoiceForm>[0]["draft"] }>(`/finance/receivables/${encodeURIComponent(sp.emitir)}/invoice-draft`)).data : null;

  return (
    <>
      <PageHeader title="Facturación electrónica" subtitle="Facturas, boletas y notas de crédito electrónicas: validación fiscal, envío al OSE, respuesta y entrega"
        actions={<ExportButton resource="finance.invoices" filters={{ type: sp.type, status: sp.status }} />} />
      {meta.provider === "simulado" && (
        <Notice>Integración OSE/PSE en modo simulado: el comprobante se valida y numera igual que en producción, pero la respuesta SUNAT la genera el simulador (§1.1, punto por validar con negocio).</Notice>
      )}
      <Kpis items={[
        { label: "Emitidos en el mes", value: s.issued_month, hint: money(s.issued_month_amount) },
        { label: "Aceptados", value: s.accepted, hint: s.waiting ? `${s.waiting} en espera del OSE` : "Mes en curso" },
        { label: "Observados / rechazados", value: s.observed },
        { label: "Cobrado por facturar", value: money(s.uninvoiced), hint: `${s.uninvoiced_rows.length} reservas` },
      ]} />
      {s.observed > 0 && <Notice tone="warning">Hay {s.observed} {s.observed === 1 ? "comprobante observado o rechazado" : "comprobantes observados o rechazados"} por corregir.</Notice>}
      <div className="mb-5"><Flow title="PROC-050 · Facturación electrónica" steps={FLOWS.invoice} /></div>
      <Card title="Cobros pendientes de facturar" className="mb-5">
        {s.uninvoiced_rows.length === 0 ? <p className="text-sm text-slate-500">Todo lo cobrado tiene comprobante.</p> : (
          <Table head={["Reserva", "Cliente", "Cobrado", "Facturado", "Por facturar", "Obligaciones"]}>
            {s.uninvoiced_rows.map((u) => (
              <tr key={u.reservation}>
                <Td className="font-medium">{u.reservation}</Td><Td>{u.client}</Td><Td>{money(u.collected)}</Td><Td>{money(u.invoiced)}</Td><Td className="font-medium">{money(u.pending)}</Td>
                <Td>{u.receivables.map((c) => (
                  <div key={c.id} className="mb-1 flex flex-wrap items-center gap-2"><span>{c.code} · {money(c.max, c.currency)}</span>
                    <RowAction primary href={`/finanzas/facturacion?emitir=${c.code}`}>Emitir</RowAction></div>
                ))}</Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <select name="type" defaultValue={sp.type ?? ""} aria-label="Tipo" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Tipo: todos</option>{Object.entries(meta.types).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[14rem]`}>
          <option value="">Estado: todos</option>{Object.entries(meta.labels).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Número", "Tipo", "Emisión", "Cliente", "Reserva", "Total", "Estado", "OSE"]} empty="Sin comprobantes">
        {rows.map((i) => (
          <tr key={i.id}>
            <Td className="whitespace-nowrap"><Link href={`/finanzas/facturacion/${i.number}`} className="font-mono font-medium text-sky-800 hover:underline">{i.number}</Link>
              {i.reference && <span className="block text-xs text-slate-500">Ref. {i.reference}</span>}</Td>
            <Td>{i.type_label}{i.operation_type === "exportacion" && <span className="block text-xs text-slate-500">Exportación</span>}</Td>
            <Td className="whitespace-nowrap">{date(i.issue_date)}</Td>
            <Td>{i.customer_name}<span className="block text-xs text-slate-500">{i.customer_doc_type} {i.customer_doc_number}</span></Td>
            <Td>{i.reservation ?? "—"}</Td>
            <Td className="whitespace-nowrap">{money(i.total, i.currency)}</Td>
            <Td><FinStatus value={i.status} label={i.status_label} />{i.delivered_at && <span className="block text-xs text-emerald-700">Entregado</span>}</Td>
            <Td className="max-w-xs text-xs text-slate-600">{i.ose_code ?? "—"}{i.ose_message && i.status !== "aceptado" && <span className="block">{i.ose_message}</span>}</Td>
          </tr>
        ))}
      </Table>

      {draft && (
        <Drawer title="Emitir comprobante" close={base}>
          <Flow title="PROC-050" steps={FLOWS.invoice} current={1} />
          <div className="mt-4"><InvoiceForm draft={draft} back={base} /></div>
        </Drawer>
      )}
    </>
  );
}
