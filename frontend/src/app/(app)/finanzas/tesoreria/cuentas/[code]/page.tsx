import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { limaToday } from "@/lib/ops";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Kpis, Table, Td, btnGhostCls, inputCls } from "@/components/crm/ui";
import { MovementsTable, StatusPill, TreasuryHeader } from "@/components/treasury/ui";
import { MovementDrawer } from "@/components/treasury/MovementDrawer";
import { TransferForm } from "@/components/treasury/forms";
import { amount, COLLECT_METHODS, NEED_REGISTER, type Account, type AccountOption, type CashCount, type Movement, type TreasuryCan } from "@/lib/treasury";

type Ledger = {
  account: Account; opening: number; from: string | null; movements: Movement[]; counts: CashCount[]; pending_expenses: number | null; gateway_fee_pct: number | null;
  can: TreasuryCan; accounts: AccountOption[]; receipt_types: Record<string, string>;
};
type Params = { from?: string; to?: string; mov?: string; accion?: string };

/** Libro de la cuenta (§2) con saldo acumulado y acciones según el tipo: transferencia (banco), caja chica o liquidación de pasarela. */
export default async function AccountPage({ params, searchParams }: { params: Promise<{ code: string }>; searchParams: Promise<Params> }) {
  const { code } = await params;
  const sp = await searchParams;
  let l: Ledger;
  try {
    l = (await api<{ data: Ledger }>(`/treasury/accounts/${encodeURIComponent(code)}/movements${qs({ from: sp.from, to: sp.to })}`)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) notFound();
    throw e;
  }
  const a = l.account;
  const base = `/finanzas/tesoreria/cuentas/${a.code}`;
  const today = limaToday();
  const reg = l.can.register;
  return (
    <>
      <TreasuryHeader active="" subtitle={<><Link href="/finanzas/tesoreria" className="text-sky-800 hover:underline">Tesorería</Link> / {a.code} · {a.name}</>} />
      <Kpis items={[
        { label: "Saldo", value: amount(a.balance, a.currency), hint: a.currency !== "PEN" ? `${amount(a.balance_pen)} al TC del día` : undefined },
        { label: "Tipo", value: a.kind_label, hint: a.bank ? `${a.bank} ${a.account_number ?? ""}${a.cci ? ` · CCI ${a.cci}` : ""}` : a.sede },
        ...(a.kind === "petty_cash" ? [{ label: "Fondo fijo", value: amount(a.fund_amount), hint: `${a.fund_pct ?? 0} % · por rendir ${amount(l.pending_expenses ?? 0)}` }] : []),
        ...(a.kind === "gateway" ? [{ label: "Liquida en", value: a.settle_to?.name ?? "—", hint: `Comisión ${l.gateway_fee_pct ?? 0} %` }] : []),
        { label: "Saldo inicial", value: amount(a.opening_balance, a.currency), hint: `${date(a.opening_date)}${a.opening_rate ? ` · TC ${a.opening_rate}` : ""}` },
      ]} />
      <p className="mb-4 text-sm text-slate-600">
        {a.collect_methods.length > 0 && <>Recibe: {a.collect_methods.map((m) => COLLECT_METHODS[m] ?? m).join(", ")} · </>}
        {a.custodian && <>Custodia: {a.custodian} · </>}Estado: <StatusPill status={a.status} />
      </p>

      <div className="mb-4 flex flex-wrap gap-2">
        {a.kind === "bank" && <Link href={`${base}?accion=transferencia`} scroll={false} aria-disabled={!reg} title={reg ? undefined : NEED_REGISTER}
          className={`rounded-md px-3 py-2 text-sm font-medium ${reg ? "bg-sky-700 text-white" : "pointer-events-none bg-slate-200 text-slate-500"}`}>Transferir</Link>}
        {a.kind === "bank" && <Link href={`/finanzas/tesoreria/movimientos?nuevo=movimiento`} aria-disabled={!reg}
          className={`rounded-md border px-3 py-2 text-sm font-medium ${reg ? "border-slate-300 bg-white text-slate-700" : "pointer-events-none border-slate-200 text-slate-400"}`}>Otro ingreso o egreso</Link>}
        {a.kind === "petty_cash" && ["gasto", "arqueo", "cierre"].map((x) => (
          <Link key={x} href={`/finanzas/tesoreria/cajas?caja=${a.code}&accion=${x}`} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700">
            {{ gasto: "Gasto", arqueo: "Arqueo", cierre: "Cerrar caja" }[x]}</Link>
        ))}
        {a.kind === "gateway" && <Link href={`${base}?accion=liquidar`} scroll={false} aria-disabled={!reg}
          className={`rounded-md px-3 py-2 text-sm font-medium ${reg ? "bg-sky-700 text-white" : "pointer-events-none bg-slate-200 text-slate-500"}`}>Liquidar pasarela</Link>}
        {l.can.accounts && <Link href={`${base}?accion=editar`} scroll={false} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700">Editar cuenta</Link>}
      </div>

      {sp.accion === "transferencia" && reg && <Card title="Transferencia desde esta cuenta" className="mb-4"><TransferForm accounts={l.accounts} from={a.id} today={today} /></Card>}
      {sp.accion === "liquidar" && reg && (
        <Card title="Liquidación de la pasarela" className="mb-4">
          <ActionForm path={`/treasury/gateway/${a.code}/settlements`} idempotent submitLabel="Registrar liquidación" success="Liquidación registrada" className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Monto bruto liquidado</span><input name="gross_amount" type="number" step="0.01" min="0.01" required className={inputCls} /></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Comisión %</span><input name="fee_pct" type="number" step="0.01" min="0" defaultValue={l.gateway_fee_pct ?? undefined} className={inputCls} /></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Lote</span><input name="batch" required maxLength={60} className={inputCls} /></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Fecha de abono</span><input name="settlement_date" type="date" defaultValue={today} max={today} className={inputCls} /></label>
          </ActionForm>
        </Card>
      )}
      {sp.accion === "editar" && l.can.accounts && (
        <Card title="Editar cuenta" className="mb-4">
          <ActionForm path={`/treasury/accounts/${a.code}`} method="PATCH" ifMatch={a.lock_version} submitLabel="Guardar" success="Cuenta actualizada" className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Nombre</span><input name="name" defaultValue={a.name} required maxLength={120} className={inputCls} /></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Estado</span>
              <select name="status" defaultValue={a.status} className={inputCls}><option value="active">Activa</option><option value="inactive">Inactiva (saldo cero)</option></select></label>
            <fieldset className="text-sm sm:col-span-2"><legend className="mb-1 font-medium text-slate-700">Medios de cobro que ingresan aquí</legend>
              <div className="flex flex-wrap gap-3">{Object.entries(COLLECT_METHODS).map(([k, v]) => (
                <label key={k} className="inline-flex items-center gap-1"><input type="checkbox" name="collect_methods[]" value={k} defaultChecked={a.collect_methods.includes(k)} />{v}</label>
              ))}</div></fieldset>
          </ActionForm>
        </Card>
      )}

      <form className="mb-3 flex flex-wrap items-end gap-2 text-sm" aria-label="Rango de fechas">
        <label><span className="mb-1 block text-slate-600">Desde</span><input type="date" name="from" defaultValue={sp.from} className={inputCls} /></label>
        <label><span className="mb-1 block text-slate-600">Hasta</span><input type="date" name="to" defaultValue={sp.to} className={inputCls} /></label>
        <button className={btnGhostCls}>Filtrar</button>
        <span className="text-slate-600">Saldo inicial del rango: <strong>{amount(l.opening, a.currency)}</strong></span>
      </form>
      <MovementsTable rows={l.movements} detailBase={`${base}${qs({ from: sp.from, to: sp.to })}`} running showAccount={false} />

      {l.counts.length > 0 && (
        <Card title="Últimos arqueos" className="mt-4">
          <Table head={["Acta", "Fecha", "Contado", "Diferencia", "Estado"]}>
            {l.counts.map((c) => <tr key={c.id}><Td className="font-mono">{c.code}</Td><Td>{dateTime(c.counted_at)}</Td><Td>{amount(c.counted_amount)}</Td><Td>{amount(c.difference)}</Td><Td><StatusPill status={c.status} label={c.status_label} /></Td></tr>)}
          </Table>
        </Card>
      )}
      {sp.mov && <MovementDrawer code={sp.mov} close={base} can={l.can} />}
    </>
  );
}
