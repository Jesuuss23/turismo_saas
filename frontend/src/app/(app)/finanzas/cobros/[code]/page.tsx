import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { limaToday } from "@/lib/ops";
import { finPerms } from "@/lib/finance-server";
import { FLOWS, money, OP, type AuditRow, type Invoice, type Payment, type Receivable } from "@/lib/finance";
import Link from "@/components/Link";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnCls, btnGhostCls, Card, Drawer, PageHeader, Table, Td, TextArea } from "@/components/crm/ui";
import { RowAction } from "@/components/supplier/ui";
import { Audit, Dl, FinStatus, Flow, MaybeLink, RoleNote } from "@/components/finance/ui";
import { PaymentFields, type Method } from "@/components/finance/forms";

type Detail = Receivable & {
  client_email: string | null; client_phone: string | null; reservation_status: string | null; cancel_reason: string | null;
  reminders: { channels: string[]; sent_at: string; balance: string }[]; payments: Payment[]; invoices: Invoice[]; audit: AuditRow[];
  notifications: { event: string; channel: string; recipient: string; subject: string | null; status: string; at: string }[];
};

const STEP: Record<string, number> = { abierta: 2, vencida: 3, parcial: 5, pagada: 7, cancelada: 7 };

/** Ficha de la obligación de cobro (§2: flujo PROC-040, pagos, comprobantes, auditoría y notificaciones). */
export default async function ReceivablePage({ params, searchParams }: { params: Promise<{ code: string }>; searchParams: Promise<{ accion?: string; revertir?: string }> }) {
  const [{ code }, sp] = await Promise.all([params, searchParams]);
  const perms = await finPerms();
  const op = perms.has(OP);
  const { data: r } = await api<{ data: Detail }>(`/finance/receivables/${encodeURIComponent(code)}`);
  const methods = op && sp.accion === "pago" ? (await api<{ data: Method[] }>("/finance/payment-methods")).data : [];
  const self = `/finanzas/cobros/${r.code}`;
  const open = ["abierta", "parcial", "vencida"].includes(r.status);
  const reverting = sp.revertir ? r.payments.find((p) => p.code === sp.revertir && p.status === "aplicado") : undefined;
  const invoiceable = Number(r.paid) > 0 && r.status !== "cancelada";

  return (
    <>
      <p className="mb-2 text-sm"><Link href="/finanzas/cobros" className="text-sky-800 hover:underline">← Cuentas por cobrar</Link></p>
      <PageHeader title={`${r.code} · ${r.concept}`} subtitle={<>{r.kind_label} · {r.client} · vence {date(r.due_date)}</>}
        actions={<>
          {op && open && <ActionForm path={`/finance/receivables/${r.code}/reminders`} idempotent inline submitLabel="Enviar recordatorio" success="Recordatorio enviado" submitClass={btnGhostCls} />}
          {perms.has("finance.invoicing") && invoiceable && <Link href={`/finanzas/facturacion?emitir=${r.code}`} className={op ? btnGhostCls : btnCls}>{op ? "Emitir comprobante" : "Ir a facturación"}</Link>}
          {op && open && <Link href={`${self}?accion=pago`} scroll={false} className={btnCls}>Registrar pago</Link>}
        </>} />
      <RoleNote perms={perms} screen="receivables" />
      <div className="mb-5"><Flow title="PROC-040 · Ciclo de la cuenta por cobrar" steps={FLOWS.receivable} current={STEP[r.status]} /></div>
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Card title="Obligación">
            <Dl items={[
              ["Estado", <FinStatus key="s" value={r.status} label={r.status_label} />],
              ["Reserva", r.reservation ? <MaybeLink key="r" href={`/reservas/${r.reservation}`} can={perms.has("reservations.view")}>{r.reservation}</MaybeLink> : "—"],
              ["Cliente", <MaybeLink key="c" href={`/crm/clientes/${r.client_id}`} can={perms.has("crm.clients.manage") || perms.has("finance.receivables")}>{r.client}</MaybeLink>],
              ["Contacto", [r.client_email, r.client_phone].filter(Boolean).join(" · ") || "—"],
              ["Importe", money(r.amount, r.currency)],
              ["Pagado", money(r.paid, r.currency)],
              ["Saldo", <span key="b" className="font-semibold">{money(r.balance, r.currency)}{r.currency !== "PEN" && <span className="text-xs text-slate-500"> · {money(r.balance_pen)}</span>}</span>],
              ["TC conservado", r.fx_rate ?? "—"],
              ["Último recordatorio", r.last_reminder_at ? dateTime(r.last_reminder_at) : "—"],
              ...(r.cancel_reason ? [["Motivo de cancelación", r.cancel_reason] as [string, string]] : []),
            ]} />
          </Card>
          <Card title="Pagos">
            <Table head={["Pago", "Medio / referencia", "Fecha", "Monto", "Estado", ""]} empty="Sin pagos registrados">
              {r.payments.map((p) => (
                <tr key={p.id}>
                  <Td><Link href={`/finanzas/pagos?code=${p.code}`} className="font-medium text-sky-800 hover:underline">{p.code}</Link><span className="block text-xs text-slate-500">{p.kind === "reembolso" ? "Reembolso" : p.source}</span></Td>
                  <Td>{p.method}<span className="block text-xs text-slate-500">{p.reference ?? "—"}</span></Td>
                  <Td className="whitespace-nowrap">{date(p.paid_on)}</Td>
                  <Td className="whitespace-nowrap">{money(p.amount, p.currency)}</Td>
                  <Td><FinStatus value={p.status} label={p.status_label} />{p.reject_reason && <span className="block text-xs text-slate-500">{p.reject_reason}</span>}</Td>
                  <Td>
                    {op && ["pendiente", "confirmado"].includes(p.status) && <RowAction primary href={`/finanzas/pagos?code=${p.code}`}>Validar</RowAction>}
                    {perms.has("finance.payments.revert") && p.status === "aplicado" && p.kind !== "reembolso" && <RowAction href={`${self}?revertir=${p.code}`}>Revertir</RowAction>}
                  </Td>
                </tr>
              ))}
            </Table>
          </Card>
          <Card title="Comprobantes de la reserva">
            {r.invoices.length === 0 ? <p className="text-sm text-slate-500">Sin comprobantes emitidos.</p> : (
              <ul className="divide-y divide-slate-100 text-sm">
                {r.invoices.map((i) => (
                  <li key={i.id} className="flex flex-wrap justify-between gap-2 py-1.5">
                    <span><MaybeLink href={`/finanzas/facturacion/${i.number}`} can={perms.has("finance.invoicing")}><span className="font-mono">{i.number}</span></MaybeLink> · {i.type_label}</span>
                    <span>{money(i.total, i.currency)} <FinStatus value={i.status} label={i.status_label} /></span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
        <div className="space-y-4">
          <Card title="Notificaciones">
            {r.notifications.length === 0 && r.reminders.length === 0 ? <p className="text-sm text-slate-500">Sin notificaciones.</p> : (
              <ul className="space-y-2 text-sm">
                {r.reminders.map((m) => <li key={m.sent_at}>Recordatorio · {m.channels.join(", ")} · saldo {money(m.balance, r.currency)}<span className="block text-xs text-slate-500">{dateTime(m.sent_at)}</span></li>)}
                {r.notifications.map((n, i) => <li key={i}>{n.subject ?? n.event} · {n.channel}<span className="block text-xs text-slate-500">{n.recipient} · {dateTime(n.at)}</span></li>)}
              </ul>
            )}
          </Card>
          <Card title="Historial de auditoría"><Audit rows={r.audit} /></Card>
        </div>
      </div>

      {op && open && sp.accion === "pago" && (
        <Drawer title={`Registrar pago · ${r.code}`} close={self}>
          <p className="mb-3 text-sm text-slate-600">Saldo {money(r.balance, r.currency)}</p>
          <ActionForm path="/finance/payments" idempotent redirectTo={self} submitLabel="Registrar pago">
            <input type="hidden" name="receivable_id" value={r.id} />
            <PaymentFields methods={methods} currency={r.currency} amount={r.balance} today={limaToday()} />
          </ActionForm>
        </Drawer>
      )}
      {reverting && (
        <Drawer title={`Revertir pago ${reverting.code}`} close={self}>
          <Flow title="PROC-043 · Reversión" steps={FLOWS.revert} current={1} />
          <p className="my-3 text-sm text-slate-600">{reverting.method} · {money(reverting.amount, reverting.currency)}. El saldo de {r.code} se recalcula y se notifica a Finanzas.</p>
          <ActionForm path={`/finance/payments/${reverting.code}/revert`} idempotent redirectTo={self} submitLabel="Revertir pago" confirm="¿Revertir el pago? Esta acción queda auditada.">
            <TextArea label="Motivo de la reversión" name="reason" required minLength={10} maxLength={500} />
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}
