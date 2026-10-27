import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import { limaToday } from "@/lib/ops";
import { finPerms } from "@/lib/finance-server";
import { FLOWS, money, OP, type Receivable } from "@/lib/finance";
import Link from "@/components/Link";
import { ActionForm } from "@/components/crm/ActionForm";
import { ExportButton } from "@/components/crm/ExportButton";
import { btnCls, btnGhostCls, Card, Drawer, Input, inputCls, Kpis, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { RowAction, withParams } from "@/components/supplier/ui";
import { Bars, FinStatus, Flow, MaybeLink, RoleNote } from "@/components/finance/ui";
import { PaymentFields, type Method } from "@/components/finance/forms";

type Summary = {
  kpis: { open: string; open_count: number; overdue: string; overdue_count: number; due_7d: string; due_7d_count: number; collected_month: string; uninvoiced: string; uninvoiced_count: number };
  aging: { label: string; amount: string }[];
  upcoming: Receivable[];
};
type Search = { status?: string; currency?: string; accion?: string; cxc?: string };

/** Finanzas › Cuentas por cobrar (PROC-040/041/042). */
export default async function ReceivablesPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const perms = await finPerms();
  const op = perms.has(OP);
  const invoicing = perms.has("finance.invoicing");
  const [{ data: rows, meta }, { data: summary }] = await Promise.all([
    api<{ data: Receivable[]; meta: { labels: Record<string, string> } }>(`/finance/receivables${qs({ "filter[status]": sp.status ?? "abiertas", "filter[currency]": sp.currency })}`),
    api<{ data: Summary }>("/finance/receivables/summary"),
  ]);
  const methods = op && sp.accion === "pago" ? (await api<{ data: Method[] }>("/finance/payment-methods")).data : [];
  const base = `/finanzas/cobros${qs({ status: sp.status, currency: sp.currency })}`;
  const k = summary.kpis;
  const paying = sp.accion === "pago" ? rows.find((r) => r.code === sp.cxc) : undefined;
  const reservations = [...new Map(rows.filter((r) => r.reservation).map((r) => [r.reservation!, r])).values()];
  const today = limaToday();

  return (
    <>
      <PageHeader title="Cuentas por cobrar" subtitle="Obligaciones de cobro por reserva, anticipos, cobranza y aplicación de pagos"
        actions={<>
          <ExportButton resource="finance.receivables" filters={{ status: sp.status ?? "abiertas", currency: sp.currency }} />
          {op && <Link href={withParams(base, { accion: "anticipo" })} scroll={false} className={btnGhostCls}>Solicitar anticipo</Link>}
          {op && <Link href={withParams(base, { accion: "pago" })} scroll={false} className={btnCls}>Registrar pago</Link>}
        </>} />
      <RoleNote perms={perms} screen="receivables" />
      <Kpis items={[
        { label: "Por cobrar", value: money(k.open), hint: `${k.open_count} obligaciones abiertas` },
        { label: "Vencido", value: money(k.overdue), hint: `${k.overdue_count} vencidas` },
        op || !invoicing
          ? { label: "Por vencer 7 días", value: money(k.due_7d), hint: `${k.due_7d_count} obligaciones` }
          : { label: "Cobrado por facturar", value: money(k.uninvoiced), hint: `${k.uninvoiced_count} reservas` },
        { label: "Cobrado en el mes", value: money(k.collected_month), hint: "Pagos aplicados (PEN)" },
      ]} />
      {k.overdue_count > 0 && <Notice tone="danger">Hay {k.overdue_count} {k.overdue_count === 1 ? "obligación vencida" : "obligaciones vencidas"} por {money(k.overdue)}.</Notice>}
      <div className="mb-5"><Flow title="PROC-040 · Ciclo de la cuenta por cobrar" steps={FLOWS.receivable} /></div>
      <div className="mb-5 grid gap-4 lg:grid-cols-2">
        <Card title="Antigüedad de saldos (PEN)"><Bars rows={summary.aging.map((a, i) => ({ label: a.label, value: Number(a.amount), tone: i === 0 ? "bg-sky-600" : "bg-rose-500" }))} /></Card>
        <Card title="Próximos vencimientos">
          <ul className="divide-y divide-slate-100 text-sm">
            {summary.upcoming.slice(0, 6).map((r) => (
              <li key={r.code} className="flex justify-between gap-2 py-1.5">
                <span><Link href={`/finanzas/cobros/${r.code}`} className="font-medium text-sky-800 hover:underline">{r.code}</Link> <span className="text-slate-500">{r.client}</span></span>
                <span className="whitespace-nowrap">{money(r.balance, r.currency)} · {date(r.due_date)}</span>
              </li>
            ))}
            {summary.upcoming.length === 0 && <li className="py-1.5 text-slate-500">Sin vencimientos próximos.</li>}
          </ul>
        </Card>
      </div>
      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <select name="status" defaultValue={sp.status ?? "abiertas"} aria-label="Estado" className={`${inputCls} max-w-[12rem]`}>
          <option value="abiertas">Estado: abiertas</option><option value="">Todas</option>
          {Object.entries(meta.labels).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select name="currency" defaultValue={sp.currency ?? ""} aria-label="Moneda" className={`${inputCls} max-w-[10rem]`}>
          <option value="">Moneda: todas</option><option value="PEN">PEN</option><option value="USD">USD</option>
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["CxC", "Concepto", "Reserva / cliente", "Vence", "Importe / saldo", "Estado", ...(op ? ["Acciones"] : [])]} empty="Sin cuentas por cobrar con estos filtros">
        {rows.map((r) => (
          <tr key={r.id}>
            <Td className="whitespace-nowrap"><Link href={`/finanzas/cobros/${r.code}`} className="font-medium text-sky-800 hover:underline">{r.code}</Link><span className="block text-xs text-slate-500">{r.kind_label}</span></Td>
            <Td>{r.concept}</Td>
            <Td>{r.reservation ? <MaybeLink href={`/reservas/${r.reservation}`} can={perms.has("reservations.view")}>{r.reservation}</MaybeLink> : "—"}<span className="block text-xs text-slate-500">{r.client}</span></Td>
            <Td className="whitespace-nowrap">{date(r.due_date)}{r.days_overdue > 0 && <span className="block text-xs font-medium text-rose-700">{r.days_overdue} días vencida</span>}</Td>
            <Td className="whitespace-nowrap">{money(r.amount, r.currency)}<span className="block text-xs text-slate-500">Saldo {money(r.balance, r.currency)}</span></Td>
            <Td><FinStatus value={r.status} label={r.status_label} /></Td>
            {op && (
              <Td>
                {["abierta", "parcial", "vencida"].includes(r.status) && (
                  <div className="flex flex-wrap gap-1">
                    <RowAction primary href={withParams(base, { accion: "pago", cxc: r.code })}>Cobrar</RowAction>
                    <ActionForm path={`/finance/receivables/${r.code}/reminders`} idempotent inline submitLabel="Recordatorio" success={`Recordatorio enviado a ${r.client}`}
                      submitClass="rounded-md bg-white px-2 py-1 text-xs font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50" />
                  </div>
                )}
              </Td>
            )}
          </tr>
        ))}
      </Table>

      {op && sp.accion === "anticipo" && (
        <Drawer title="Solicitar anticipo" close={base}>
          <ActionForm path="/finance/receivables/advances" idempotent redirectTo="/finanzas/cobros/{data.code}" submitLabel="Crear obligación">
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Reserva</span>
              <input aria-label="Reserva" name="reservation_id" list="adv-reservations" required className={inputCls} placeholder="RES-2026-000000" />
              <datalist id="adv-reservations">{reservations.map((r) => <option key={r.reservation} value={r.reservation!}>{r.client}</option>)}</datalist>
            </label>
            <div className="grid grid-cols-2 gap-3">
              <Input label="Importe" name="amount" type="number" step="0.01" min="0.01" required />
              <Input label="Vence" name="due_date" type="date" min={today} defaultValue={today} required />
            </div>
            <Input label="Concepto (opcional)" name="concept" maxLength={255} />
            <label className="flex items-center gap-2 text-sm"><input type="hidden" name="notify" value="false" /><input type="checkbox" name="notify" value="true" defaultChecked /> Notificar al cliente con el enlace de pago</label>
            <p className="text-xs text-slate-500">FIN-BR-03: el total de obligaciones no puede superar el total de la reserva.</p>
          </ActionForm>
        </Drawer>
      )}
      {op && sp.accion === "pago" && (
        <Drawer title={paying ? `Registrar pago · ${paying.code}` : "Registrar pago"} close={base}>
          {paying && <p className="mb-3 text-sm text-slate-600">{paying.concept} · {paying.client} · saldo {money(paying.balance, paying.currency)}</p>}
          <ActionForm path="/finance/payments" idempotent redirectTo="/finanzas/cobros/{data.receivable.code}" submitLabel="Registrar pago">
            {paying ? <input type="hidden" name="receivable_id" value={paying.id} /> : (
              <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Cuenta por cobrar</span>
                <select aria-label="Cuenta por cobrar" name="receivable_id" required className={inputCls} defaultValue="">
                  <option value="">Seleccione…</option>
                  {rows.filter((r) => ["abierta", "parcial", "vencida"].includes(r.status)).map((r) => <option key={r.id} value={r.id}>{r.code} · {r.client} · {money(r.balance, r.currency)}</option>)}
                </select>
              </label>
            )}
            <PaymentFields methods={methods} currency={paying?.currency ?? "PEN"} amount={paying?.balance ?? ""} today={today} />
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}
