import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { limaToday } from "@/lib/ops";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, Notice, Table, Td, btnGhostCls, inputCls } from "@/components/crm/ui";
import { StatusPill, TreasuryHeader } from "@/components/treasury/ui";
import { CountForm, ExpenseForm, TransferForm } from "@/components/treasury/forms";
import { amount, NEED_REGISTER, type AccountOption, type PettyCard, type PettyCash } from "@/lib/treasury";

type Params = { caja?: string; accion?: string };
const NEED_APPROVE = "Requiere finance.profitability y approvals.view (Manager)";

function ActionLink({ href, enabled, reason, primary = false, children }: { href: string; enabled: boolean; reason?: string; primary?: boolean; children: React.ReactNode }) {
  const cls = primary ? "bg-sky-700 text-white hover:bg-sky-800" : "border border-slate-300 bg-white text-slate-700 hover:bg-slate-50";
  if (!enabled) return <button type="button" disabled title={reason} className={`cursor-not-allowed rounded-md px-3 py-1.5 text-sm font-medium opacity-50 ${cls}`}>{children}</button>;
  return <Link href={href} scroll={false} className={`rounded-md px-3 py-1.5 text-sm font-medium ${cls}`}>{children}</Link>;
}

function CajaCard({ c, d }: { c: PettyCard; d: PettyCash }) {
  const reg = d.can.register;
  const href = (accion: string) => `/finanzas/tesoreria/cajas${qs({ caja: c.code, accion })}`;
  const open = c.session?.status === "open";
  const rep = c.replenishment;
  const own = !!rep && d.replenishments.find((r) => r.code === rep.code)?.requested_by_id === d.user_id;
  return (
    <article className="rounded-lg border border-slate-200 bg-white p-4" aria-label={c.name} data-caja={c.code}>
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="font-semibold text-slate-900"><Link href={`/finanzas/tesoreria/cuentas/${c.code}`} className="hover:underline">{c.name}</Link></h2>
          <p className="text-xs text-slate-500">{c.code} · {c.sede} · custodia {c.custodian ?? "—"}</p>
        </div>
        {c.session ? <StatusPill status={c.session.status} label={`Sesión ${c.session.code} ${c.session.status === "open" ? "abierta" : "cerrada"} · ${date(c.session.date)}`} /> : <StatusPill status="closed" label="Sin sesiones" />}
      </header>
      <p className="mt-3 text-2xl font-semibold text-slate-900" data-testid="caja-balance">{amount(c.balance)}</p>
      <p className="text-xs text-slate-500">de {amount(c.fund_amount)} de fondo fijo · {c.fund_pct} %</p>
      <div className="mt-1 h-2 rounded bg-slate-100"><div className={`h-2 rounded ${c.below_threshold ? "bg-amber-500" : "bg-emerald-600"}`} style={{ width: `${Math.min(100, Math.max(0, c.fund_pct))}%` }} /></div>
      {c.below_threshold && <p className="mt-1 text-xs text-amber-800">Bajo el {d.settings.replenish_threshold_pct} % del fondo: solicite la reposición (TES-BR-08).</p>}
      {c.stale_session && <p className="mt-1 text-xs text-rose-700">La sesión de un día anterior sigue abierta: ciérrela con un arqueo (TES-BR-06).</p>}
      <p className="mt-2 text-sm text-slate-700">Gastos por rendir: <strong>{c.pending_count}</strong> · {amount(c.pending_amount)}</p>
      {rep && <p className="mt-1 text-sm">Reposición <span className="font-mono">{rep.code}</span> · {amount(rep.amount)} · <StatusPill status={rep.status} label={rep.status_label} /></p>}

      <div className="mt-3 flex flex-wrap gap-2">
        <ActionLink href={href("apertura")} enabled={reg && !open} reason={!reg ? NEED_REGISTER : "La caja ya tiene una sesión abierta"}>Aperturar</ActionLink>
        <ActionLink href={href("gasto")} enabled={reg && open} reason={!reg ? NEED_REGISTER : "Apertura la caja primero (TES-BR-05)"} primary>Gasto</ActionLink>
        <ActionLink href={href("arqueo")} enabled={reg} reason={NEED_REGISTER}>Arqueo</ActionLink>
        <ActionLink href={href("cierre")} enabled={reg && open} reason={!reg ? NEED_REGISTER : "No hay sesión abierta"}>Cerrar caja</ActionLink>
        <ActionLink href={href("reposicion")} enabled={reg && !rep && c.pending_count > 0} reason={!reg ? NEED_REGISTER : rep ? "Ya hay una reposición en curso (TES-BR-15)" : "Sin gastos por rendir"}>Reposición</ActionLink>
        <ActionLink href={href("deposito")} enabled={reg && c.balance > 0} reason={NEED_REGISTER}>Depositar</ActionLink>
      </div>

      {rep?.status === "requested" && d.can.approve && (
        <div className="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3">
          <ActionForm path={`/treasury/replenishments/${rep.code}/approve`} ifMatch={rep.lock_version} submitLabel={`Aprobar ${rep.code}`} success="Reposición aprobada" inline
            disabled={own} disabledReason="Quien solicita no aprueba (TES-BR-14)" />
          {!own && <Link href={href("rechazo")} scroll={false} className="text-sm text-rose-700 hover:underline">Rechazar…</Link>}
        </div>
      )}
      {rep?.status === "approved" && (
        <div className="mt-3 border-t border-slate-100 pt-3">
          {!reg && <p className="mb-1 text-xs text-slate-500">Aprobada · el pago lo registra Finanzas ({NEED_REGISTER}).</p>}
          <ActionForm path={`/treasury/replenishments/${rep.code}/pay`} idempotent submitLabel={`Pagar ${rep.code}`} success="Reposición pagada" disabled={!reg} disabledReason={NEED_REGISTER}
            className="flex flex-wrap items-end gap-2">
            <label className="text-sm"><span className="mb-1 block font-medium text-slate-700">Desde</span>
              <select name="from_account_id" disabled={!reg} className={inputCls} aria-label={`Cuenta de pago de ${rep.code}`}>{d.banks.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name}</option>)}</select></label>
            <label className="text-sm"><span className="mb-1 block font-medium text-slate-700">N° de operación</span>
              <input name="reference" required disabled={!reg} maxLength={80} className={inputCls} aria-label={`N° de operación de ${rep.code}`} /></label>
          </ActionForm>
        </div>
      )}
    </article>
  );
}

function ActionDrawer({ sp, d }: { sp: Params; d: PettyCash }) {
  const c = d.cards.find((x) => x.code === sp.caja);
  const close = "/finanzas/tesoreria/cajas";
  if (!c) return null;
  const today = limaToday();
  const titles: Record<string, string> = { apertura: "Aperturar caja", gasto: "Registrar gasto", arqueo: "Arqueo sorpresa", cierre: "Cerrar caja con arqueo", reposicion: "Solicitar reposición", rechazo: "Rechazar reposición", deposito: "Depositar efectivo" };
  const accounts: AccountOption[] = [{ id: c.id, code: c.code, name: c.name, currency: "PEN", kind: "petty_cash" }, ...d.banks.map((b) => ({ ...b, currency: "PEN", kind: "bank" }))];
  return (
    <Drawer title={`${titles[sp.accion ?? ""] ?? c.name} · ${c.name}`} close={close}>
      <p className="mb-3 text-sm text-slate-600">Saldo {amount(c.balance)} · fondo {amount(c.fund_amount)}</p>
      {sp.accion === "apertura" && (
        <ActionForm path={`/treasury/petty-cash/${c.code}/sessions`} submitLabel="Aperturar" success="Caja aperturada">
          <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Efectivo contado (S/)</span>
            <input name="opening_amount" type="number" step="0.01" min="0" defaultValue={c.balance.toFixed(2)} required className={inputCls} /></label>
          <p className="text-xs text-slate-500">Debe igualar el saldo del último cierre (TES-BR-07).</p>
        </ActionForm>
      )}
      {sp.accion === "gasto" && <ExpenseForm account={{ id: c.id, code: c.code, name: c.name, balance: c.balance }} receiptTypes={d.receipt_types} max={d.settings.max_petty_expense} swornMax={d.settings.sworn_statement_max} today={today} />}
      {(sp.accion === "arqueo" || sp.accion === "cierre") && (
        <CountForm account={c} denominations={d.denominations} expected={c.balance} tolerance={Number(d.settings.petty_tolerance)} witnesses={d.witnesses} closing={sp.accion === "cierre"} />
      )}
      {sp.accion === "reposicion" && (
        <ActionForm path={`/treasury/petty-cash/${c.code}/replenishments`} submitLabel="Solicitar reposición" success="Reposición solicitada">
          <p className="text-sm text-slate-700">Se rinden {c.pending_count} gasto(s) por {amount(c.pending_amount)}:</p>
          <ul className="max-h-48 space-y-1 overflow-y-auto text-xs text-slate-600">{c.expenses.map((e) => <li key={e.code}>{date(e.date)} · {e.description} · {e.counterparty} · {amount(e.amount)}</li>)}</ul>
          <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Nota (opcional)</span><input name="note" maxLength={500} className={inputCls} /></label>
        </ActionForm>
      )}
      {sp.accion === "rechazo" && c.replenishment && (
        <ActionForm path={`/treasury/replenishments/${c.replenishment.code}/reject`} ifMatch={c.replenishment.lock_version} submitLabel="Rechazar" submitClass={btnGhostCls} success="Reposición rechazada">
          <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Motivo</span><textarea name="reason" required rows={2} className={inputCls} /></label>
        </ActionForm>
      )}
      {sp.accion === "deposito" && <TransferForm accounts={accounts} from={c.id} today={today} deposit />}
    </Drawer>
  );
}

/** Cajas chicas (§2): tarjeta por caja con sus acciones, arqueos (aprobación TES-BR-14), reposiciones y sesiones. */
export default async function PettyCashPage({ searchParams }: { searchParams: Promise<Params> }) {
  const sp = await searchParams;
  const d = (await api<{ data: PettyCash }>("/treasury/petty-cash")).data;
  return (
    <>
      <TreasuryHeader active="cajas" subtitle={`Tolerancia de arqueo ${amount(d.settings.petty_tolerance)} · tope por gasto ${amount(d.settings.max_petty_expense)}`} />
      {!d.can.register && <p className="mb-4 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700 ring-1 ring-slate-200" role="note">
        Modo supervisión: aperturar, registrar gastos, arqueos y reposiciones corresponde a Finanzas.{d.can.approve ? " Usted aprueba diferencias de arqueo y reposiciones." : ""}</p>}
      <div className="mb-6 grid gap-4 md:grid-cols-2">{d.cards.map((c) => <CajaCard key={c.id} c={c} d={d} />)}</div>
      {d.cards.length === 0 && <Notice>No hay cajas chicas configuradas.</Notice>}

      <Card title="Arqueos" className="mb-4">
        <Table head={["Acta", "Caja", "Tipo", "Contado / esperado", "Diferencia", "Firmó", "Estado", ""]} empty="Sin arqueos">
          {d.counts.map((c) => (
            <tr key={c.id} data-count={c.code}>
              <Td><span className="font-mono">{c.code}</span><span className="block text-xs text-slate-500">{dateTime(c.counted_at)}</span></Td>
              <Td>{c.account}</Td>
              <Td>{c.kind_label}</Td>
              <Td className="whitespace-nowrap">{amount(c.counted_amount)} / {amount(c.expected_amount)}</Td>
              <Td><StatusPill status={c.result} label={`${c.result_label} ${Number(c.difference) !== 0 ? amount(c.difference) : ""}`} />{c.note && <span className="block text-xs text-slate-500">{c.note}</span>}</Td>
              <Td>{c.signed_by}{c.approved_by && <span className="block text-xs text-slate-500">Aprobó {c.approved_by}</span>}{c.validated_by && <span className="block text-xs text-slate-500">Validó {c.validated_by}</span>}</Td>
              <Td><StatusPill status={c.status} label={c.status_label} /></Td>
              <Td>
                {c.status === "pending_approval" && (
                  <ActionForm path={`/treasury/counts/${c.code}/approve`} ifMatch={c.lock_version} submitLabel={`Aprobar ${c.code}`} success="Diferencia aprobada: se registró el ajuste" inline
                    disabled={!d.can.approve || c.signed_by_id === d.user_id} disabledReason={!d.can.approve ? NEED_APPROVE : "Quien firma no aprueba su diferencia (TES-BR-14)"} />
                )}
                {["signed", "approved"].includes(c.status) && d.can.validate && (
                  <ActionForm path={`/treasury/counts/${c.code}/validate`} submitLabel="Validar" submitClass={btnGhostCls} success="Arqueo validado" inline />
                )}
              </Td>
            </tr>
          ))}
        </Table>
      </Card>

      <Card title="Reposiciones" className="mb-4">
        <Table head={["Código", "Caja", "Monto", "Solicitó", "Estado", "Seguimiento"]} empty="Sin reposiciones">
          {d.replenishments.map((r) => (
            <tr key={r.id}>
              <Td><span className="font-mono">{r.code}</span><span className="block text-xs text-slate-500">{r.expenses} gasto(s)</span></Td>
              <Td>{r.account}</Td>
              <Td className="whitespace-nowrap">{amount(r.amount)}</Td>
              <Td>{r.requested_by}<span className="block text-xs text-slate-500">{dateTime(r.requested_at)}</span></Td>
              <Td><StatusPill status={r.status} label={r.status_label} />{r.reject_reason && <span className="block text-xs text-rose-700">{r.reject_reason}</span>}</Td>
              <Td><ul className="space-y-0.5 text-xs text-slate-600">{r.events.map((e, i) => <li key={i}>{dateTime(e.at)} · {e.message}</li>)}</ul></Td>
            </tr>
          ))}
        </Table>
      </Card>

      <Card title="Sesiones">
        <Table head={["Sesión", "Caja", "Fecha", "Apertura", "Cierre", "Estado"]} empty="Sin sesiones">
          {d.sessions.map((s) => (
            <tr key={s.code}>
              <Td className="font-mono">{s.code}</Td>
              <Td>{s.account}</Td>
              <Td>{date(s.date)}</Td>
              <Td>{amount(s.opening_amount)}<span className="block text-xs text-slate-500">{s.opened_by}</span></Td>
              <Td>{s.closed_at ? <>{dateTime(s.closed_at)}<span className="block text-xs text-slate-500">{s.closed_by}{s.count ? ` · ${s.count}` : ""}</span></> : "—"}</Td>
              <Td><StatusPill status={s.status} /></Td>
            </tr>
          ))}
        </Table>
      </Card>
      {sp.caja && <ActionDrawer sp={sp} d={d} />}
    </>
  );
}
