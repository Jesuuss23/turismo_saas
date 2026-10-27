import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { amount, relDays, type SupplierPayable } from "@/lib/supplier";
import { supplierMe } from "@/lib/supplier-server";
import { Drawer, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { InvoiceUpload } from "@/components/supplier/InvoiceUpload";
import { RowAction, SupStatus } from "@/components/supplier/ui";

type Meta = { totals: Record<string, string>; without_invoice: number; payment_terms: string | null };

/** Pagos de la agencia (spec 06 §4.4): cuentas por pagar con saldo, fecha programada y su factura. */
export default async function SupplierPayablesPage({ searchParams }: { searchParams: Promise<{ factura?: string }> }) {
  const { me } = await supplierMe();
  if (!me) return null;
  if (!me.permissions.includes("supplier.payables.view")) {
    return <Notice>Su perfil en la extranet no incluye los pagos. Pida al administrador de su cuenta el perfil Contabilidad.</Notice>;
  }
  const sp = await searchParams;
  const { data: rows, meta } = await api<{ data: SupplierPayable[]; meta: Meta }>("/supplier/payables");
  const canUpload = me.permissions.includes("supplier.invoices.upload");
  const uploading = sp.factura && canUpload ? rows.find((p) => p.id === sp.factura && p.can_upload) : undefined;

  return (
    <>
      <PageHeader title="Pagos" subtitle={<>Condición de pago del contrato: <span className="font-medium text-slate-800">{meta.payment_terms ?? "—"}</span></>} />
      {meta.without_invoice > 0 && (
        <Notice tone="warning">{meta.without_invoice} {meta.without_invoice === 1 ? "documento no tiene" : "documentos no tienen"} factura. La agencia programa el pago cuando recibe y valida su factura.</Notice>
      )}
      <Table head={["Documento", "Concepto", "Importe", "Saldo", "Fecha programada", "Estado", "Su factura", ""]} empty="La agencia no tiene cuentas por pagar con usted"
        foot={Object.entries(meta.totals).map(([c, v]) => (
          <tr key={c}><td colSpan={3} className="px-3 py-2 text-right text-sm">Saldo total {c}</td><td className="whitespace-nowrap px-3 py-2 text-sm">{amount(v, c)}</td><td colSpan={4} /></tr>
        ))}>
        {rows.map((p) => (
          <tr key={p.id}>
            <Td className="whitespace-nowrap"><span className="font-medium">{p.code}</span>{p.reservation_code && <span className="block text-xs text-slate-500">{p.reservation_code}</span>}</Td>
            <Td>{p.concept}</Td>
            <Td className="whitespace-nowrap">{amount(p.amount, p.currency)}</Td>
            <Td className="whitespace-nowrap">{amount(p.balance, p.currency)}</Td>
            <Td className="whitespace-nowrap">{date(p.due_date)}
              {p.status !== "pagada" && <span className={`block text-xs ${p.days_to_due < 0 ? "font-medium text-rose-700" : "text-slate-500"}`}>{p.days_to_due < 0 ? "Vencido" : relDays(p.days_to_due)}</span>}
            </Td>
            <Td><SupStatus value={p.status} label={p.status_label} /></Td>
            <Td>{p.invoice ? (
              <span className="whitespace-nowrap">
                <a href={`/bff/supplier-invoices/${p.invoice.id}`} target="_blank" rel="noreferrer" className="font-mono text-sky-800 hover:underline">{p.invoice.number}</a>
                <span className="block"><SupStatus value={p.invoice.status} label={p.invoice.status_label} /></span>
                {p.invoice.validation_note && <span className="block text-xs text-amber-900">{p.invoice.validation_note}</span>}
              </span>
            ) : <span className="text-slate-500">Sin factura</span>}</Td>
            <Td>{canUpload && p.can_upload && <RowAction primary href={`/proveedor/pagos?factura=${p.id}`}>Subir factura</RowAction>}</Td>
          </tr>
        ))}
      </Table>

      {uploading && (
        <Drawer title={`Subir factura · ${uploading.code}`} close="/proveedor/pagos">
          <p className="mb-3 text-sm text-slate-600">{uploading.concept} · saldo {amount(uploading.balance, uploading.currency)}</p>
          <InvoiceUpload payableId={uploading.id} balance={uploading.balance} currency={uploading.currency} close="/proveedor/pagos" />
        </Drawer>
      )}
    </>
  );
}
