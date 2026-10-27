import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { limaToday } from "@/lib/ops";
import { finPerms } from "@/lib/finance-server";
import { FLOWS, money, OP, type AuditRow, type Payable } from "@/lib/finance";
import Link from "@/components/Link";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnCls, btnGhostCls, Card, Drawer, Input, inputCls, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { Audit, Dl, FinStatus, Flow, MaybeLink, RoleNote } from "@/components/finance/ui";

type Detail = Payable & {
  provider_email: string | null; provider_ruc: string | null; warnings: string[]; scheduled_account: string | null;
  payouts: { code: string; paid_on: string; amount: string; fx_rate: string | null; method: string; method_label: string; operation_ref: string | null; bank_line: string | null }[];
  invoices: { id: string; number: string; issue_date: string | null; amount: string; status: string; uploaded_at: string | null }[];
  audit: AuditRow[];
};
type Account = { id: string; bank: string; currency: string; number: string; label: string };

const METHODS = [["transferencia", "Transferencia"], ["transferencia_cci", "Transferencia CCI"], ["cheque_gerencia", "Cheque de gerencia"], ["efectivo_caja_chica", "Efectivo (caja chica)"], ["cargo_bancario", "Cargo bancario"]];

function step(p: Payable): number {
  if (p.status === "pagada") return 6;
  if (p.status === "parcial") return 4;
  return p.approved ? 3 : 1;
}

/** Ficha de la cuenta por pagar / costo (aprobar, programar y pagar; PROC-044/045/046). */
export default async function PayablePage({ params, searchParams }: { params: Promise<{ code: string }>; searchParams: Promise<{ accion?: string }> }) {
  const [{ code }, sp] = await Promise.all([params, searchParams]);
  const perms = await finPerms();
  const op = perms.has(OP);
  const { data: p } = await api<{ data: Detail }>(`/finance/payables/${encodeURIComponent(code)}`);
  const self = `/finanzas/cuentas-por-pagar/${p.code}`;
  const payable = ["abierta", "aprobada", "parcial", "vencida"].includes(p.status);
  const accounts = op && ["programar", "pagar"].includes(sp.accion ?? "") ? (await api<{ data: Account[] }>(`/finance/bank-accounts?currency=${p.currency}`)).data : [];
  const today = limaToday();
  const isCost = p.kind === "costo_adicional";

  return (
    <>
      <p className="mb-2 text-sm"><Link href={isCost ? "/finanzas/cuentas-por-pagar?tab=costos" : "/finanzas/cuentas-por-pagar"} className="text-sky-800 hover:underline">← Cuentas por pagar</Link></p>
      <PageHeader title={`${p.code} · ${p.concept}`} subtitle={<>{p.provider} · {isCost ? p.cost_type_label : "Servicio"} · vence {date(p.due_date)}</>}
        actions={<>
          {payable && !p.approved && <ActionForm path={`/finance/payables/${p.code}/approve`} inline submitLabel={isCost ? "Aprobar costo" : "Aprobar"} success="Aprobado" submitClass={btnCls} />}
          {op && payable && p.approved && <Link href={`${self}?accion=programar`} scroll={false} className={btnGhostCls}>Programar</Link>}
          {op && payable && p.approved && <Link href={`${self}?accion=pagar`} scroll={false} className={btnCls}>Pagar</Link>}
        </>} />
      <RoleNote perms={perms} screen="payables" />
      {p.warnings.map((w) => <Notice key={w} tone="warning">{w}</Notice>)}
      {payable && !p.approved && <Notice>Pendiente de aprobación: no se puede programar ni pagar hasta aprobarla (PROC-046).</Notice>}
      <div className="mb-5"><Flow title={isCost ? "PROC-045 · Costo adicional" : "PROC-044 · Pago a proveedor"} steps={isCost ? FLOWS.cost : FLOWS.payable} current={isCost ? (p.approved ? 5 : 3) : step(p)} /></div>
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Card title="Documento">
            <Dl items={[
              ["Estado", <FinStatus key="s" value={p.status} label={p.status_label} />],
              ["Aprobación", p.approved ? `Aprobada ${dateTime(p.approved_at)}` : "Por aprobar"],
              ["Proveedor", <span key="p">{p.provider} <span className="text-xs text-slate-500">{p.provider_ref}{p.provider_ruc ? ` · RUC ${p.provider_ruc}` : ""}</span></span>],
              ["Reserva", p.reservation ? <MaybeLink key="r" href={`/finanzas/rentabilidad/${p.reservation}`} can={perms.has("finance.profitability")}>{p.reservation}</MaybeLink> : "—"],
              ["Servicio", p.service ?? "—"],
              ["Importe", money(p.amount, p.currency)], ["Pagado", money(p.paid, p.currency)],
              ["Saldo", <span key="b" className="font-semibold">{money(p.balance, p.currency)}</span>],
              ["TC", p.fx_rate ?? "—"],
              ["Programado", p.scheduled_date ? `${date(p.scheduled_date)} · ${p.scheduled_account ?? ""}` : "—"],
              ["Documento del proveedor", p.supplier_doc_number ?? p.supplier_invoice?.number ?? "—"],
              ["Sustento", p.evidence_note ?? "—"],
            ]} />
          </Card>
          <Card title="Pagos realizados">
            <Table head={["Pago", "Fecha", "Medio", "Operación", "Monto"]} empty="Sin pagos">
              {p.payouts.map((o) => (
                <tr key={o.code}><Td className="font-mono">{o.code}</Td><Td>{date(o.paid_on)}</Td><Td>{o.method_label}</Td><Td>{o.operation_ref ?? "—"}{o.bank_line && <span className="block text-xs text-slate-500">Extracto {o.bank_line}</span>}</Td><Td>{money(o.amount, p.currency)}</Td></tr>
              ))}
            </Table>
          </Card>
          <Card title="Facturas del proveedor">
            {p.invoices.length === 0 ? <p className="text-sm text-slate-500">El proveedor aún no sube su factura.</p> : (
              <ul className="divide-y divide-slate-100 text-sm">
                {p.invoices.map((i) => <li key={i.id} className="flex justify-between py-1.5"><span className="font-mono">{i.number}</span><span>{money(i.amount, p.currency)} · {i.status}</span></li>)}
              </ul>
            )}
          </Card>
        </div>
        <Card title="Historial de auditoría"><Audit rows={p.audit} /></Card>
      </div>

      {op && payable && p.approved && sp.accion === "programar" && (
        <Drawer title={`Programar pago · ${p.code}`} close={self}>
          <ActionForm path={`/finance/payables/${p.code}/schedule`} ifMatch={p.lock_version} redirectTo={self} submitLabel="Programar pago">
            <Input label="Fecha programada" name="scheduled_date" type="date" min={today} defaultValue={p.due_date >= today ? p.due_date : today} required />
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Cuenta de origen ({p.currency})</span>
              <select aria-label="Cuenta de origen" name="bank_account_id" required className={inputCls}>{accounts.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}</select>
            </label>
          </ActionForm>
        </Drawer>
      )}
      {op && payable && p.approved && sp.accion === "pagar" && (
        <Drawer title={`Pagar · ${p.code}`} close={self}>
          <p className="mb-3 text-sm text-slate-600">{p.provider} · saldo {money(p.balance, p.currency)}</p>
          <ActionForm path={`/finance/payables/${p.code}/payouts`} idempotent ifMatch={p.lock_version} redirectTo={self} submitLabel="Registrar pago al proveedor">
            <div className="grid grid-cols-2 gap-3">
              <Input label="Monto" name="amount" type="number" step="0.01" min="0.01" max={p.balance} defaultValue={p.balance} required />
              <Input label="Fecha" name="paid_on" type="date" max={today} defaultValue={today} required />
            </div>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Medio</span>
              <select aria-label="Medio" name="method" className={inputCls}>{METHODS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
            </label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Cuenta de origen</span>
              <select aria-label="Cuenta de origen" name="bank_account_id" className={inputCls}><option value="">—</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}</select>
            </label>
            <Input label="N° de operación" name="operation_ref" maxLength={60} />
            {p.currency !== "PEN" && <Input label="Tipo de cambio" name="fx_rate" type="number" step="0.000001" min="0" required />}
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}
