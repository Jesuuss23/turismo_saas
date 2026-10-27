import { api } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { amount, type SupplierInvoice } from "@/lib/supplier";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnGhostCls, Drawer, inputCls, Kpis, Notice, PageHeader, Table, Td, TextArea } from "@/components/crm/ui";
import { RowAction, SupStatus, withParams } from "@/components/supplier/ui";

type Row = {
  id: string; code: string; provider: string; provider_ref: string; reservation_code: string | null; concept: string; amount: string; paid: string; balance: string;
  currency: string; due_date: string; overdue: boolean; status: string; status_label: string; service_status: string | null;
  voucher: { code: string; status: string; status_label: string; used_at: string | null; used_via: string | null } | null; invoices: SupplierInvoice[];
};
type Meta = { invoices_to_review: number; totals: Record<string, string>; providers: { ref: string; name: string }[]; labels: Record<string, string> };
type Search = { status?: string; provider?: string; invoice?: string; factura?: string; accion?: string };

/** Finanzas › Pagos a proveedores: CxP con la factura del proveedor y el uso del voucher como prueba de prestación (spec 06 §4.4). */
export default async function SupplierPayablesBackoffice({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const { data: rows, meta } = await api<{ data: Row[]; meta: Meta }>(
    `/fin/payables${qs({ "filter[status]": sp.status, "filter[provider]": sp.provider, "filter[invoice]": sp.invoice })}`);
  const base = `/finanzas/proveedores${qs({ status: sp.status, provider: sp.provider, invoice: sp.invoice })}`;
  const reviewing = sp.factura ? rows.flatMap((r) => r.invoices.map((i) => ({ row: r, invoice: i }))).find((x) => x.invoice.id === sp.factura) : undefined;
  const action = sp.accion === "reject" ? "reject" : "observe";
  const open = rows.filter((r) => r.status !== "pagada").length;

  return (
    <>
      <PageHeader title="Pagos a proveedores" subtitle="Cuentas por pagar generadas al confirmar servicios, con la factura que sube el proveedor desde la extranet" />
      <Kpis items={[
        { label: "Documentos abiertos", value: open },
        { label: "Facturas por revisar", value: meta.invoices_to_review },
        ...Object.entries(meta.totals).map(([c, v]) => ({ label: `Saldo ${c}`, value: amount(v, c) })),
      ]} />
      {meta.invoices_to_review > 0 && <Notice tone="warning">Hay {meta.invoices_to_review} {meta.invoices_to_review === 1 ? "factura registrada" : "facturas registradas"} por validar.</Notice>}
      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <select name="provider" defaultValue={sp.provider ?? ""} aria-label="Proveedor" className={`${inputCls} max-w-[16rem]`}>
          <option value="">Proveedor: todos</option>{meta.providers.map((p) => <option key={p.ref} value={p.ref}>{p.name}</option>)}
        </select>
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Estado: vigentes</option>{Object.entries(meta.labels).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
        </select>
        <select name="invoice" defaultValue={sp.invoice ?? ""} aria-label="Factura" className={`${inputCls} max-w-[14rem]`}>
          <option value="">Factura: todas</option><option value="registrada">Por validar</option>
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Documento", "Proveedor", "Concepto", "Importe / saldo", "Vence", "Estado", "Voucher", "Factura del proveedor"]} empty="Sin cuentas por pagar con estos filtros">
        {rows.map((r) => (
          <tr key={r.id}>
            <Td className="whitespace-nowrap"><span className="font-medium">{r.code}</span>{r.reservation_code && <span className="block text-xs text-slate-500">{r.reservation_code}</span>}</Td>
            <Td>{r.provider}<span className="block text-xs text-slate-500">{r.provider_ref}</span></Td>
            <Td>{r.concept}</Td>
            <Td className="whitespace-nowrap">{amount(r.amount, r.currency)}<span className="block text-xs text-slate-500">Saldo {amount(r.balance, r.currency)}</span></Td>
            <Td className="whitespace-nowrap">{date(r.due_date)}{r.overdue && <span className="block text-xs font-medium text-rose-700">Vencido</span>}</Td>
            <Td><SupStatus value={r.status} label={r.status_label} /></Td>
            <Td>{r.voucher ? (
              <span className="whitespace-nowrap"><span className="font-mono text-xs">{r.voucher.code}</span>
                <span className="block"><SupStatus value={r.voucher.status} label={r.voucher.status_label} /></span>
                {r.voucher.used_at && <span className="block text-xs text-slate-500">Usado {dateTime(r.voucher.used_at)}{r.voucher.used_via ? ` · ${r.voucher.used_via}` : ""}</span>}</span>
            ) : <span className="text-slate-400">—</span>}</Td>
            <Td>
              {r.invoices.length === 0 && <span className="text-slate-500">Sin factura</span>}
              {r.invoices.map((i) => (
                <div key={i.id} className="mb-2 last:mb-0">
                  <a href={`/bff/supplier-invoices/${i.id}?scope=fin`} target="_blank" rel="noreferrer" className="font-mono text-sky-800 hover:underline">{i.number}</a>{" "}
                  <SupStatus value={i.status} label={i.status_label} />
                  <span className="block text-xs text-slate-500">{date(i.issue_date)} · {amount(i.amount, i.currency)}
                    {Math.abs(Number(i.amount_difference)) > 0.01 && <span className="font-medium text-amber-800"> · dif. {amount(i.amount_difference, i.currency)}</span>}</span>
                  {i.validation_note && <span className="block text-xs text-slate-600">{i.validation_note}</span>}
                  {["registrada", "observada"].includes(i.status) && (
                    <div className="mt-1 flex flex-wrap gap-1">
                      <ActionForm path={`/fin/supplier-invoices/${i.id}/validate`} inline submitLabel="Validar" success="Factura validada"
                        submitClass="rounded-md bg-emerald-700 px-2 py-1 text-xs font-medium text-white hover:bg-emerald-800 disabled:opacity-50" />
                      {i.status === "registrada" && <RowAction href={withParams(base, { factura: i.id, accion: "observe" })}>Observar</RowAction>}
                      <RowAction href={withParams(base, { factura: i.id, accion: "reject" })}>Rechazar</RowAction>
                    </div>
                  )}
                </div>
              ))}
            </Td>
          </tr>
        ))}
      </Table>

      {reviewing && ["registrada", "observada"].includes(reviewing.invoice.status) && (
        <Drawer title={`${action === "reject" ? "Rechazar" : "Observar"} factura ${reviewing.invoice.number}`} close={base}>
          <p className="mb-3 text-sm text-slate-600">{reviewing.row.provider} · {reviewing.row.code} · {amount(reviewing.invoice.amount, reviewing.invoice.currency)}</p>
          <ActionForm path={`/fin/supplier-invoices/${reviewing.invoice.id}/${action}`} redirectTo={base} submitLabel={action === "reject" ? "Rechazar factura" : "Enviar observación"}>
            <TextArea label="Motivo para el proveedor" name="note" required maxLength={500} />
            <p className="text-xs text-slate-500">{action === "reject" ? "El proveedor podrá subir una nueva factura para este documento." : "El proveedor verá la observación en su pantalla de pagos."}</p>
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}
