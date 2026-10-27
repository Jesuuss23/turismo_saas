import { api, type Me } from "@/lib/api";
import { date } from "@/lib/crm";
import { finPerms } from "@/lib/finance-server";
import { money, OP, type PeriodClose } from "@/lib/finance";
import Link from "@/components/Link";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnCls, btnGhostCls, Card, Kpis, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { Bars, FinStatus, Light, MaybeLink } from "@/components/finance/ui";

type FinanceData = {
  alert: { pending_payments: number };
  kpis: { cash_month: string; cash_count: number; receivables_open: string; receivables_count: number; receivables_overdue: string; overdue_count: number; pending_payments: number;
    payables_7d: string; payables_7d_count: number; margin_month: number; margin_target: number };
  margins: { code: string; title: string; margin_pct: number; light: string; label: string }[];
  aging: { label: string; amount: string }[];
  overdue: { code: string; concept: string; currency: string; balance: string; due_date: string }[];
  pending_payments: { code: string; client: string; method: string; currency: string; amount: string; paid_on: string; receivable: string }[];
  payables_due: { code: string; provider: string; reservation: string | null; currency: string; balance: string; due_date: string; status: string; without_invoice: boolean }[];
  commissions: { code: string; beneficiary: string; reservation: string; currency: string; amount: string }[];
  sales_vs_cost: { month: string; income: string; cost: string }[];
  cash_by_method: { method: string; amount: string }[];
};

/** Panel de Finanzas (spec 07 §2.1). */
export async function FinancePanel({ me }: { me: Me }) {
  const perms = await finPerms();
  const { data: d } = await api<{ data: FinanceData }>("/finance/dashboards/finance");
  const k = d.kpis;
  const profit = perms.has("finance.profitability");

  return (
    <>
      <PageHeader title={`Hola, ${me.name}`} subtitle="Panel de Finanzas · caja, cobranza, pagos a proveedores y margen"
        actions={<>
          {perms.has(OP) && <Link href="/finanzas/conciliacion" className={btnGhostCls}>Conciliación</Link>}
          {perms.has(OP)
            ? <Link href="/finanzas/cobros?accion=pago" className={btnCls}>Registrar pago</Link>
            : <button type="button" disabled title="Requiere finance.payments.register" className={btnCls}>Registrar pago</button>}
        </>} />
      {d.alert.pending_payments > 0 && <Notice tone="warning">Hay {d.alert.pending_payments} {d.alert.pending_payments === 1 ? "pago" : "pagos"} por validar. <Link href="/finanzas/pagos?status=pendiente" className="font-medium underline">Revisar</Link></Notice>}
      <Kpis items={[
        { label: "Caja del mes", value: money(k.cash_month), hint: `${k.cash_count} pagos` },
        { label: "CxC abierta", value: money(k.receivables_open), hint: `${k.receivables_count} obligaciones · vencido ${money(k.receivables_overdue)} (${k.overdue_count})` },
        { label: "Pagos por validar", value: k.pending_payments },
        { label: "CxP a 7 días", value: money(k.payables_7d), hint: `${k.payables_7d_count} documentos` },
      ]} />
      <div className="mb-5 grid gap-4 lg:grid-cols-3">
        <Card title="Margen bruto del mes"><p className="text-3xl font-semibold">{k.margin_month.toLocaleString("es-PE")} %</p><p className="text-sm text-slate-500">Meta {k.margin_target} %</p></Card>
        <Card title="CxC por antigüedad (PEN)"><Bars rows={d.aging.map((a, i) => ({ label: a.label, value: Number(a.amount), tone: i === 0 ? "bg-sky-600" : "bg-rose-500" }))} /></Card>
        <Card title="Caja del mes por medio de pago"><Bars rows={d.cash_by_method.map((c) => ({ label: c.method, value: Number(c.amount) }))} /></Card>
      </div>
      <div className="mb-5 grid gap-4 lg:grid-cols-2">
        {profit && (
          <Card title="Margen por reserva">
            <ul className="divide-y divide-slate-100 text-sm">
              {d.margins.map((m) => <li key={m.code} className="flex justify-between gap-2 py-1.5"><Link href={`/finanzas/rentabilidad/${m.code}`} className="text-sky-800 hover:underline">{m.code} <span className="text-slate-500">{m.title}</span></Link><Light value={m.light} pct={m.margin_pct} /></li>)}
            </ul>
          </Card>
        )}
        <Card title="Ventas vs costo (6 meses)"><Bars rows={d.sales_vs_cost.flatMap((m) => [{ label: `${m.month} ventas`, value: Number(m.income) }, { label: `${m.month} costo`, value: Number(m.cost), tone: "bg-amber-500" }])} /></Card>
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <Card title="Pagos por validar">
          <ul className="space-y-2 text-sm">
            {d.pending_payments.map((p) => <li key={p.code}><Link href={`/finanzas/pagos?code=${p.code}`} className="font-medium text-sky-800 hover:underline">{p.code}</Link> · {p.client}<span className="block text-xs text-slate-500">{p.method} · {money(p.amount, p.currency)} · {date(p.paid_on)}</span></li>)}
            {d.pending_payments.length === 0 && <li className="text-slate-500">Sin pagos pendientes.</li>}
          </ul>
        </Card>
        <Card title="CxP por vencer (7 días)">
          <ul className="space-y-2 text-sm">
            {d.payables_due.map((p) => <li key={p.code}><MaybeLink href={`/finanzas/cuentas-por-pagar/${p.code}`} can={perms.has("finance.payables")}>{p.code}</MaybeLink> · {p.provider}
              <span className="block text-xs text-slate-500">{money(p.balance, p.currency)} · {date(p.due_date)}{p.without_invoice && <span className="font-medium text-amber-800"> · sin factura</span>}</span></li>)}
            {d.payables_due.length === 0 && <li className="text-slate-500">Sin vencimientos.</li>}
          </ul>
        </Card>
        <Card title="Comisiones por aprobar">
          <ul className="space-y-2 text-sm">
            {d.commissions.map((c) => <li key={c.code}><MaybeLink href={`/finanzas/comisiones?code=${c.code}`} can={perms.has("finance.commissions")}>{c.code}</MaybeLink> · {c.beneficiary}<span className="block text-xs text-slate-500">{c.reservation} · {money(c.amount, c.currency)}</span></li>)}
            {d.commissions.length === 0 && <li className="text-slate-500">Sin comisiones calculadas.</li>}
          </ul>
        </Card>
      </div>
      {d.overdue.length > 0 && (
        <Card title="CxC vencidas" className="mt-4">
          <Table head={["CxC", "Concepto", "Saldo", "Vencida"]}>
            {d.overdue.map((o) => <tr key={o.code}><Td><Link href={`/finanzas/cobros/${o.code}`} className="text-sky-800 hover:underline">{o.code}</Link></Td><Td>{o.concept}</Td><Td>{money(o.balance, o.currency)}</Td><Td>{date(o.due_date)}</Td></tr>)}
          </Table>
        </Card>
      )}
    </>
  );
}

type AccountingData = {
  alerts: { number: string; status: string; status_label: string; message: string | null }[];
  kpis: { invoices_month: string; invoices_month_count: number; flagged: number; without_invoice: number; receivables_open: number };
  invoices: { number: string; type: string; customer: string; currency: string; total: string; issue_date: string; status: string; status_label: string }[];
  without_invoice: { code: string; reservation: string | null; client: string | null; currency: string; amount: string; paid_on: string }[];
  receivables: { code: string; reservation: string | null; client: string | null; currency: string; balance: string; due_date: string; status: string; status_label: string }[];
  period: PeriodClose;
};

/** Panel de Contabilidad con cierre de periodo (spec 07 §2.2). */
export async function AccountingPanel({ me }: { me: Me }) {
  const perms = await finPerms();
  const { data: d } = await api<{ data: AccountingData }>("/finance/dashboards/accounting");
  const k = d.kpis;
  const p = d.period;
  const invoicing = perms.has("finance.invoicing");
  const closed = p.status === "cerrado";

  return (
    <>
      <PageHeader title={`Hola, ${me.name}`} subtitle="Panel de Contabilidad · comprobantes electrónicos, cobros sin comprobante y cierre del periodo"
        actions={<>
          <Link href="/finanzas/cobros" className={btnGhostCls}>CxC</Link>
          {invoicing ? <Link href="/finanzas/facturacion" className={btnCls}>Facturación</Link> : <button type="button" disabled title="Requiere finance.invoicing" className={btnCls}>Facturación</button>}
        </>} />
      {d.alerts.map((a) => (
        <Notice key={a.number} tone={a.status === "rechazado" ? "danger" : "warning"}>
          <Link href={`/finanzas/facturacion/${a.number}`} className="font-mono font-medium underline">{a.number}</Link> {a.status_label.toLowerCase()} por el OSE: {a.message}
        </Notice>
      ))}
      <Kpis items={[
        { label: "Comprobantes del mes", value: money(k.invoices_month), hint: `${k.invoices_month_count} emitidos (sin NC)` },
        { label: "Observados / rechazados", value: k.flagged },
        { label: "Cobros sin comprobante", value: k.without_invoice },
        { label: "CxC abiertas", value: k.receivables_open },
      ]} />
      <div className="mb-5 grid gap-4 lg:grid-cols-2">
        <Card title={`Cierre del periodo ${p.period}`} actions={<FinStatus value={p.status} label={closed ? "Cerrado" : "Abierto"} />}>
          <div className="mb-3 h-2 rounded bg-slate-100" role="progressbar" aria-valuenow={p.done} aria-valuemin={0} aria-valuemax={p.total} aria-label="Controles completos">
            <div className="h-2 rounded bg-emerald-600" style={{ width: `${Math.round((p.done / p.total) * 100)}%` }} />
          </div>
          <p className="mb-3 text-sm text-slate-600">{p.done} de {p.total} controles completos</p>
          <ul className="space-y-2 text-sm">
            {p.controls.map((c) => (
              <li key={c.code} className="flex items-start justify-between gap-2">
                <span className="flex gap-2">
                  <span aria-hidden className={c.ok ? "text-emerald-700" : "text-rose-700"}>{c.ok ? "✓" : "✗"}</span>
                  <span>{c.route ? <MaybeLink href={`/${c.route}`} can={perms.has(OP)}>{c.label}</MaybeLink> : c.label}
                    <span className="block text-xs text-slate-500">{c.kind === "manual" ? "Manual" : "Automático"} · {c.detail}</span></span>
                </span>
                {c.kind === "manual" && !closed && invoicing && (
                  <ActionForm path={`/finance/period-closes/${p.period}/checks/${c.code}`} method={c.ok ? "DELETE" : "PUT"} inline
                    submitLabel={c.ok ? "Desmarcar" : "Marcar"} success={c.ok ? "Control reabierto" : "Control marcado"}
                    submitClass="rounded-md bg-white px-2 py-1 text-xs font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50" />
                )}
              </li>
            ))}
          </ul>
          {closed
            ? <p className="mt-3 text-sm text-emerald-800">Periodo cerrado por {p.closed_by}: los comprobantes de {p.period} ya no se modifican (FIN-BR-48).</p>
            : (
              <div className="mt-4">
                <ActionForm path={`/finance/period-closes/${p.period}/close`} idempotent submitLabel="Cerrar periodo" success="Periodo cerrado" disabled={!p.ready || !invoicing}
                  disabledReason={invoicing ? "Complete los 7 controles (FIN-BR-56)" : "Requiere finance.invoicing"} confirm={`¿Cerrar el periodo ${p.period}?`} />
              </div>
            )}
        </Card>
        <Card title="Cobros sin comprobante">
          <ul className="space-y-2 text-sm">
            {d.without_invoice.map((w) => <li key={w.code}>{w.code} · {w.client}<span className="block text-xs text-slate-500">{w.reservation} · {money(w.amount, w.currency)} · {date(w.paid_on)}</span></li>)}
            {d.without_invoice.length === 0 && <li className="text-slate-500">Todo cobro tiene comprobante.</li>}
          </ul>
          {d.without_invoice.length > 0 && invoicing && <Link href="/finanzas/facturacion" className="mt-3 inline-block text-sm font-medium text-sky-800 hover:underline">Emitir desde Facturación →</Link>}
        </Card>
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Comprobantes emitidos">
          <Table head={["Número", "Cliente", "Total", "Estado"]} empty="Sin comprobantes">
            {d.invoices.map((i) => <tr key={i.number}><Td><MaybeLink href={`/finanzas/facturacion/${i.number}`} can={invoicing}><span className="font-mono">{i.number}</span></MaybeLink><span className="block text-xs text-slate-500">{i.type} · {date(i.issue_date)}</span></Td>
              <Td>{i.customer}</Td><Td>{money(i.total, i.currency)}</Td><Td><FinStatus value={i.status} label={i.status_label} /></Td></tr>)}
          </Table>
        </Card>
        <Card title="CxC abiertas">
          <Table head={["CxC", "Cliente", "Saldo", "Vence"]}>
            {d.receivables.map((r) => <tr key={r.code}><Td><Link href={`/finanzas/cobros/${r.code}`} className="text-sky-800 hover:underline">{r.code}</Link><span className="block text-xs text-slate-500">{r.reservation}</span></Td>
              <Td>{r.client}</Td><Td>{money(r.balance, r.currency)}</Td><Td>{date(r.due_date)}</Td></tr>)}
          </Table>
        </Card>
      </div>
    </>
  );
}
