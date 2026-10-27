import { api } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { finPerms } from "@/lib/finance-server";
import { FLOWS, money, OP, type AuditRow, type Payment } from "@/lib/finance";
import Link from "@/components/Link";
import { ActionForm } from "@/components/crm/ActionForm";
import { ExportButton } from "@/components/crm/ExportButton";
import { btnGhostCls, Drawer, inputCls, Kpis, Notice, PageHeader, Table, Td, TextArea } from "@/components/crm/ui";
import { withParams } from "@/components/supplier/ui";
import { Audit, Dl, FinStatus, Flow, MaybeLink, RoleNote } from "@/components/finance/ui";
import type { Method } from "@/components/finance/forms";

type Detail = Payment & {
  history: { status: string; note: string | null; at: string; user: string | null }[];
  bank_line: { code: string; status: string; description: string } | null; receivable_status: string | null; receivable_balance: string | null;
  candidates: { id: string; code: string; concept: string; balance: string; currency: string }[]; audit: AuditRow[];
};
type Search = { status?: string; method?: string; code?: string; accion?: string };

const STEP: Record<string, number> = { pendiente: 1, confirmado: 3, aplicado: 5, rechazado: 1, revertido: 6 };
const small = "rounded-md px-3 py-1.5 text-sm font-medium disabled:opacity-50";

/** Finanzas › Pagos y aplicaciones (PROC-042 recepción/validación/aplicación, PROC-043 reversión). */
export default async function PaymentsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const perms = await finPerms();
  const op = perms.has(OP);
  const canRevert = perms.has("finance.payments.revert");
  const [{ data: rows, meta }, { data: s }] = await Promise.all([
    api<{ data: Payment[]; meta: { labels: Record<string, string>; methods: Method[] } }>(`/finance/payments${qs({ "filter[status]": sp.status, "filter[method]": sp.method })}`),
    api<{ data: { pending: number; applied_month: string; applied_month_count: number; reverted: number; rejected: number } }>("/finance/payments/summary"),
  ]);
  const base = `/finanzas/pagos${qs({ status: sp.status, method: sp.method })}`;
  const p = sp.code ? (await api<{ data: Detail }>(`/finance/payments/${encodeURIComponent(sp.code)}`)).data : null;
  const self = p ? withParams(base, { code: p.code }) : base;

  return (
    <>
      <PageHeader title="Pagos y aplicaciones" subtitle="Pagos recibidos por cualquier canal: validación, confirmación, aplicación a la obligación y reversión"
        actions={<ExportButton resource="finance.payments" filters={{ status: sp.status, method: sp.method }} />} />
      <RoleNote perms={perms} screen="payments" />
      <Kpis items={[
        { label: "Pendientes de validar", value: s.pending },
        { label: "Aplicados en el mes", value: money(s.applied_month), hint: `${s.applied_month_count} pagos` },
        { label: "Revertidos", value: s.reverted },
        { label: "Rechazados", value: s.rejected },
      ]} />
      {s.pending > 0 && <Notice tone="warning">Hay {s.pending} {s.pending === 1 ? "pago pendiente" : "pagos pendientes"} de validar.{op ? "" : " La validación corresponde a Finanzas."}</Notice>}
      <div className="mb-5 grid gap-3 lg:grid-cols-2">
        <Flow title="PROC-042 · Recepción y aplicación" steps={FLOWS.payment} />
        <Flow title="PROC-043 · Reversión" steps={FLOWS.revert} />
      </div>
      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Estado: todos</option>{Object.entries(meta.labels).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select name="method" defaultValue={sp.method ?? ""} aria-label="Método" className={`${inputCls} max-w-[14rem]`}>
          <option value="">Método: todos</option>{meta.methods.map((m) => <option key={m.code} value={m.label}>{m.label}</option>)}
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Pago", "CxC / reserva", "Cliente", "Medio / referencia", "Fecha", "Monto", "Estado"]} empty="Sin pagos con estos filtros">
        {rows.map((r) => (
          <tr key={r.id}>
            <Td className="whitespace-nowrap"><Link href={withParams(base, { code: r.code })} scroll={false} className="font-medium text-sky-800 hover:underline">{r.code}</Link>
              <span className="block text-xs text-slate-500">{r.kind === "reembolso" ? "Reembolso" : r.source === "portal_cliente" ? "Portal del cliente" : "Back-office"}</span></Td>
            <Td>{r.receivable ? <Link href={`/finanzas/cobros/${r.receivable}`} className="text-sky-800 hover:underline">{r.receivable}</Link> : "—"}<span className="block text-xs text-slate-500">{r.reservation}</span></Td>
            <Td>{r.client}</Td>
            <Td>{r.method}<span className="block text-xs text-slate-500">{r.reference ?? "—"}</span></Td>
            <Td className="whitespace-nowrap">{date(r.paid_on)}</Td>
            <Td className="whitespace-nowrap">{money(r.amount, r.currency)}</Td>
            <Td><FinStatus value={r.status} label={r.status_label} /></Td>
          </tr>
        ))}
      </Table>

      {p && (
        <Drawer title={`Pago ${p.code}`} close={base}>
          <div className="space-y-4">
            <Flow title={p.status === "revertido" ? "PROC-043 · Reversión" : "PROC-042 · Recepción y aplicación"} steps={p.status === "revertido" ? FLOWS.revert : FLOWS.payment} current={STEP[p.status]} />
            <Dl items={[
              ["Estado", <FinStatus key="s" value={p.status} label={p.status_label} />],
              ["Monto", money(p.amount, p.currency)],
              ["Medio", p.method], ["Referencia", p.reference ?? "—"], ["Fecha", date(p.paid_on)],
              ["CxC", p.receivable ? <Link key="c" href={`/finanzas/cobros/${p.receivable}`} className="text-sky-800 hover:underline">{p.receivable}</Link> : "—"],
              ["Saldo de la CxC", p.receivable_balance ? money(p.receivable_balance, p.currency) : "—"],
              ["Reserva", p.reservation ? <MaybeLink key="r" href={`/reservas/${p.reservation}`} can={perms.has("reservations.view")}>{p.reservation}</MaybeLink> : "—"],
              ["Cliente", p.client], ["Registrado por", p.registered_by ?? "Portal"],
            ]} />
            {p.note && <p className="text-sm text-slate-600">Nota: {p.note}</p>}
            {p.reject_reason && <Notice tone="danger">Rechazado: {p.reject_reason}</Notice>}
            {p.revert_reason && <Notice tone="warning">Revertido: {p.revert_reason}</Notice>}
            {p.bank_line
              ? <Notice>Abono en el extracto: <span className="font-mono">{p.bank_line.code}</span> · {p.bank_line.description} ({p.bank_line.status})</Notice>
              : p.status === "pendiente" && <p className="text-sm text-slate-500">Sin abono identificado en el extracto bancario.</p>}

            {op && p.status === "pendiente" && (
              <div className="space-y-3 rounded-md border border-slate-200 p-3">
                <ActionForm path={`/finance/payments/${p.code}/validate`} idempotent redirectTo={self} submitLabel="Validar y aplicar" submitClass={`${small} bg-emerald-700 text-white hover:bg-emerald-800`}>
                  <input type="hidden" name="apply" value="true" />
                  {p.candidates.length > 1 && (
                    <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Aplicar a</span>
                      <select aria-label="Aplicar a" name="receivable_id" defaultValue={p.receivable_id ?? ""} className={inputCls}>
                        {p.candidates.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.concept} · saldo {money(c.balance, c.currency)}</option>)}
                      </select>
                    </label>
                  )}
                </ActionForm>
                <ActionForm path={`/finance/payments/${p.code}/validate`} idempotent redirectTo={self} submitLabel="Solo confirmar" submitClass={`${small} bg-white text-slate-700 ring-1 ring-slate-300`} />
                <ActionForm path={`/finance/payments/${p.code}/reject`} redirectTo={self} submitLabel="Rechazar pago" submitClass={`${small} bg-rose-700 text-white hover:bg-rose-800`}>
                  <TextArea label="Motivo del rechazo (se informa al cliente)" name="reason" required minLength={10} maxLength={500} />
                </ActionForm>
              </div>
            )}
            {op && p.status === "confirmado" && (
              <ActionForm path={`/finance/payments/${p.code}/apply`} idempotent redirectTo={self} submitLabel="Aplicar a la obligación">
                <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Cuenta por cobrar</span>
                  <select aria-label="Cuenta por cobrar" name="receivable_id" defaultValue={p.receivable_id ?? ""} className={inputCls}>
                    {p.candidates.map((c) => <option key={c.id} value={c.id}>{c.code} · saldo {money(c.balance, c.currency)}</option>)}
                  </select>
                </label>
              </ActionForm>
            )}
            {op && p.status === "rechazado" && (
              <ActionForm path={`/finance/payments/${p.code}/retry`} redirectTo={self} submitLabel="Reintentar validación" submitClass={`${small} bg-sky-700 text-white`} />
            )}
            {p.status === "aplicado" && p.kind !== "reembolso" && (canRevert ? (
              <ActionForm path={`/finance/payments/${p.code}/revert`} idempotent redirectTo={self} submitLabel="Revertir pago" confirm="¿Revertir el pago? Esta acción queda auditada."
                submitClass={`${small} bg-rose-700 text-white hover:bg-rose-800`}>
                <TextArea label="Motivo de la reversión" name="reason" required minLength={10} maxLength={500} />
              </ActionForm>
            ) : <p className="text-sm text-slate-500">Su rol no puede revertir pagos (PROC-043).</p>)}
            {(op || canRevert) && ["confirmado", "aplicado"].includes(p.status) && p.kind !== "reembolso" && (
              <details className="text-sm">
                <summary className="cursor-pointer font-medium text-sky-800">Solicitar reversión a aprobación</summary>
                <ActionForm path={`/finance/payments/${p.code}/reversal-requests`} idempotent redirectTo="/aprobaciones/{data.code}?tab=sent" submitLabel="Enviar a la bandeja de aprobaciones"
                  submitClass={`${small} bg-sky-700 text-white`}>
                  <TextArea label="Motivo de la solicitud" name="reason" required minLength={10} maxLength={500} />
                  <p className="text-xs text-slate-500">La decide quien tenga finance.payments.revert desde la bandeja (spec 10); al aprobarla se revierte el pago.</p>
                </ActionForm>
              </details>
            )}

            <section>
              <h3 className="mb-2 text-sm font-semibold text-slate-800">Historial de estados</h3>
              <ol className="space-y-2 text-sm">
                {p.history.map((h, i) => (
                  <li key={i} className="border-l-2 border-slate-200 pl-3"><FinStatus value={h.status} label={meta.labels[h.status] ?? h.status} /> {h.note}
                    <span className="block text-xs text-slate-500">{h.user ?? "Sistema"} · {dateTime(h.at)}</span></li>
                ))}
              </ol>
            </section>
            <section><h3 className="mb-2 text-sm font-semibold text-slate-800">Auditoría</h3><Audit rows={p.audit} /></section>
          </div>
        </Drawer>
      )}
    </>
  );
}
