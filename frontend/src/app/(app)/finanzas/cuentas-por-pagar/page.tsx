import { api } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { finPerms } from "@/lib/finance-server";
import { FLOWS, money, OP, type Payable } from "@/lib/finance";
import { amount, type SupplierInvoice } from "@/lib/supplier";
import Link from "@/components/Link";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnCls, btnGhostCls, Drawer, inputCls, Kpis, Notice, PageHeader, Table, Tabs, Td, TextArea } from "@/components/crm/ui";
import { RowAction, SupStatus, withParams } from "@/components/supplier/ui";
import { FinStatus, Flow, MaybeLink, RoleNote } from "@/components/finance/ui";
import { CostForm } from "@/components/finance/forms";

type Row = Payable & {
  service_status: string | null; invoices: SupplierInvoice[];
  voucher: { code: string; status: string; status_label: string; used_at: string | null; used_via: string | null } | null;
};
type Meta = { labels: Record<string, string>; cost_types: Record<string, string>; invoices_to_review: number; providers: { id: string; ref: string; name: string; status: string }[] };
type ByProvider = { provider: string; provider_ref: string; ruc: string | null; documents: number; total: string; paid: string; balance: string; overdue: string; next_due: string | null };
type Search = { tab?: string; status?: string; provider?: string; invoice?: string; approval?: string; factura?: string; accion?: string; reserva?: string };

/** Finanzas › Cuentas por pagar (PROC-044 pagos a proveedores, PROC-045 costos adicionales, PROC-046 aprobación). */
export default async function PayablesPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const perms = await finPerms();
  const op = perms.has(OP);
  const tab = ["proveedor", "costos"].includes(sp.tab ?? "") ? sp.tab! : "documento";
  const filters = { "filter[status]": sp.status, "filter[provider]": sp.provider, "filter[invoice]": sp.invoice, "filter[approval]": sp.approval,
    "filter[kind]": tab === "costos" ? "costo_adicional" : undefined };
  const [{ data: rows, meta }, { data: s }] = await Promise.all([
    api<{ data: Row[]; meta: Meta }>(`/finance/payables${qs(filters)}`),
    api<{ data: { open: string; open_count: number; overdue: string; overdue_count: number; to_approve: number; paid_month: string; scheduled_7d: string; scheduled_7d_count: number } }>("/finance/payables/summary"),
  ]);
  const byProvider = tab === "proveedor" ? (await api<{ data: ByProvider[] }>("/finance/payables/by-provider")).data : [];
  const reservations = op && sp.accion === "costo" && perms.has("finance.profitability")
    ? (await api<{ data: { code: string; title: string }[] }>("/finance/profitability/reservations")).data : [];
  const base = `/finanzas/cuentas-por-pagar${qs({ tab: tab === "documento" ? undefined : tab, status: sp.status, provider: sp.provider, invoice: sp.invoice, approval: sp.approval })}`;
  const reviewing = sp.factura ? rows.flatMap((r) => r.invoices.map((i) => ({ row: r, invoice: i }))).find((x) => x.invoice.id === sp.factura) : undefined;
  const action = sp.accion === "reject" ? "reject" : "observe";

  return (
    <>
      <PageHeader title="Cuentas por pagar" subtitle="Obligaciones con proveedores y costos adicionales: validación, aprobación, programación y pago"
        actions={op ? <Link href={withParams(base, { accion: "costo" })} scroll={false} className={btnCls}>Registrar costo</Link> : undefined} />
      <RoleNote perms={perms} screen="payables" />
      <Kpis items={[
        { label: "Por pagar", value: money(s.open), hint: `${s.open_count} documentos` },
        { label: "Vencido", value: money(s.overdue), hint: `${s.overdue_count} documentos` },
        { label: "Por aprobar", value: s.to_approve, hint: <Link href="/finanzas/cuentas-por-pagar?approval=pendiente" className="text-sky-800 hover:underline">Ver pendientes</Link> },
        { label: "Pagado en el mes", value: money(s.paid_month), hint: `Programado 7 días: ${money(s.scheduled_7d)} (${s.scheduled_7d_count})` },
      ]} />
      {meta.invoices_to_review > 0 && <Notice tone="warning">Hay {meta.invoices_to_review} {meta.invoices_to_review === 1 ? "factura de proveedor registrada" : "facturas de proveedor registradas"} por validar.</Notice>}
      <div className="mb-5 grid gap-3 lg:grid-cols-2">
        <Flow title="PROC-044/046 · Pago a proveedores" steps={FLOWS.payable} />
        <Flow title="PROC-045 · Costos adicionales" steps={FLOWS.cost} />
      </div>
      <Tabs base="/finanzas/cuentas-por-pagar" active={tab} tabs={[{ key: "documento", label: "Por documento" }, { key: "proveedor", label: "Por proveedor" }, { key: "costos", label: "Costos registrados" }]} />

      {tab !== "proveedor" && (
        <form className="mb-4 flex flex-wrap gap-2" role="search">
          {tab !== "documento" && <input type="hidden" name="tab" value={tab} />}
          <select name="provider" defaultValue={sp.provider ?? ""} aria-label="Proveedor" className={`${inputCls} max-w-[16rem]`}>
            <option value="">Proveedor: todos</option>{meta.providers.map((p) => <option key={p.ref} value={p.ref}>{p.name}</option>)}
          </select>
          <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[12rem]`}>
            <option value="">Estado: vigentes</option>{Object.entries(meta.labels).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
          </select>
          <select name="approval" defaultValue={sp.approval ?? ""} aria-label="Aprobación" className={`${inputCls} max-w-[12rem]`}>
            <option value="">Aprobación: todas</option><option value="pendiente">Por aprobar</option>
          </select>
          <select name="invoice" defaultValue={sp.invoice ?? ""} aria-label="Factura" className={`${inputCls} max-w-[14rem]`}>
            <option value="">Factura: todas</option><option value="registrada">Por validar</option>
          </select>
          <button className={btnGhostCls}>Filtrar</button>
        </form>
      )}

      {tab === "proveedor" ? (
        <Table head={["Proveedor", "RUC", "Documentos", "Total", "Pagado", "Saldo", "Vencido", "Próximo vencimiento"]} empty="Sin cuentas por pagar">
          {byProvider.map((p) => (
            <tr key={p.provider_ref}>
              <Td><Link href={`/finanzas/cuentas-por-pagar?provider=${p.provider_ref}`} className="font-medium text-sky-800 hover:underline">{p.provider}</Link><span className="block text-xs text-slate-500">{p.provider_ref}</span></Td>
              <Td>{p.ruc ?? "—"}</Td><Td>{p.documents}</Td><Td>{money(p.total)}</Td><Td>{money(p.paid)}</Td>
              <Td className="font-medium">{money(p.balance)}</Td><Td className={Number(p.overdue) > 0 ? "text-rose-700" : ""}>{money(p.overdue)}</Td><Td>{date(p.next_due)}</Td>
            </tr>
          ))}
        </Table>
      ) : (
        <Table head={["Documento", "Proveedor", "Concepto", "Importe / saldo", "Vence", "Estado", tab === "costos" ? "Tipo de costo" : "Voucher", "Factura del proveedor"]}
          empty="Sin cuentas por pagar con estos filtros">
          {rows.map((r) => (
            <tr key={r.id}>
              <Td className="whitespace-nowrap"><Link href={`/finanzas/cuentas-por-pagar/${r.code}`} className="font-medium text-sky-800 hover:underline">{r.code}</Link>
                {r.reservation && <span className="block text-xs text-slate-500">{r.reservation}</span>}
                {r.service && <span className="block text-xs text-slate-500">{r.service}</span>}</Td>
              <Td>{r.provider}<span className="block text-xs text-slate-500">{r.provider_ref}</span></Td>
              <Td>{r.concept}</Td>
              <Td className="whitespace-nowrap">{money(r.amount, r.currency)}<span className="block text-xs text-slate-500">Saldo {money(r.balance, r.currency)}</span></Td>
              <Td className="whitespace-nowrap">{date(r.due_date)}{r.overdue && <span className="block text-xs font-medium text-rose-700">Vencido</span>}
                {r.scheduled_date && <span className="block text-xs text-slate-500">Programado {date(r.scheduled_date)}</span>}</Td>
              <Td><FinStatus value={r.status} label={r.status_label} />{!r.approved && ["abierta", "vencida"].includes(r.status) && <span className="block text-xs text-amber-800">Por aprobar</span>}</Td>
              <Td>{tab === "costos" ? r.cost_type_label : r.voucher ? (
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
      )}

      {reviewing && ["registrada", "observada"].includes(reviewing.invoice.status) && (
        <Drawer title={`${action === "reject" ? "Rechazar" : "Observar"} factura ${reviewing.invoice.number}`} close={base}>
          <p className="mb-3 text-sm text-slate-600">{reviewing.row.provider} · {reviewing.row.code} · {amount(reviewing.invoice.amount, reviewing.invoice.currency)}</p>
          <ActionForm path={`/fin/supplier-invoices/${reviewing.invoice.id}/${action}`} redirectTo={base} submitLabel={action === "reject" ? "Rechazar factura" : "Enviar observación"}>
            <TextArea label="Motivo para el proveedor" name="note" required maxLength={500} />
            <p className="text-xs text-slate-500">{action === "reject" ? "El proveedor podrá subir una nueva factura para este documento." : "El proveedor verá la observación en su pantalla de pagos."}</p>
          </ActionForm>
        </Drawer>
      )}
      {op && sp.accion === "costo" && (
        <Drawer title="Registrar costo adicional" close={base}>
          <Flow title="PROC-045" steps={FLOWS.cost} current={1} />
          <div className="mt-4">
            <CostForm providers={meta.providers} costTypes={meta.cost_types} reservations={reservations} reservation={sp.reserva} back={base} />
          </div>
          <p className="mt-3 text-xs text-slate-500">El costo queda por aprobar (FIN-BR-23) y se refleja en la rentabilidad de la reserva al aprobarse.{" "}
            <MaybeLink href="/finanzas/rentabilidad" can={perms.has("finance.profitability")}>Ver rentabilidad</MaybeLink></p>
        </Drawer>
      )}
    </>
  );
}
