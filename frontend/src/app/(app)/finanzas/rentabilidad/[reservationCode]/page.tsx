import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { finPerms } from "@/lib/finance-server";
import { money, OP, type AuditRow, type CheckItem, type Figures } from "@/lib/finance";
import Link from "@/components/Link";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnCls, btnGhostCls, Card, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { Audit, Checklist, FinStatus, Light, MaybeLink } from "@/components/finance/ui";

type Doc = { code: string; concept: string; currency: string; amount: string; paid: string; balance: string; status: string; status_label: string };
type Settlement = {
  reservation: { id: string; code: string; title: string; status: string; status_label: string; client: string; client_email: string | null; owner: string | null; channel: string;
    currency: string; fx_rate: string | null; total: string; travel_from: string; travel_to: string; lock_version: number };
  figures: Figures; cash: { collected: string; refunds: string; paid_out: string; net: string };
  receivables: (Doc & { due_date: string })[]; payables: (Doc & { provider: string; kind: string; approved: boolean })[];
  commissions: { code: string; kind: string; beneficiary: string; currency: string; amount: string; status: string; status_label: string }[];
  checklist: CheckItem[]; ready: boolean; audit: AuditRow[];
  settlement: { closed_at: string; closed_by: string | null; income_pen: string; cost_pen: string; commissions_pen: string; net_pen: string; gross_margin_pct: string } | null;
};

/** Liquidación / cierre financiero de la reserva (PROC-048, FIN-BR-34..37). */
export default async function SettlementPage({ params }: { params: Promise<{ reservationCode: string }> }) {
  const { reservationCode } = await params;
  const perms = await finPerms();
  const op = perms.has(OP);
  const { data: s } = await api<{ data: Settlement }>(`/finance/reservations/${encodeURIComponent(reservationCode)}/settlement`);
  const r = s.reservation;
  const f = s.figures;
  const self = `/finanzas/rentabilidad/${r.code}`;

  return (
    <>
      <p className="mb-2 text-sm"><Link href="/finanzas/rentabilidad" className="text-sky-800 hover:underline">← Rentabilidad</Link></p>
      <PageHeader title={`Liquidación · ${r.code}`} subtitle={<>{r.title} · {r.client} · {date(r.travel_from)} – {date(r.travel_to)} · {r.status_label}</>}
        actions={<>
          {perms.has("reservations.view") && <Link href={`/reservas/${r.code}`} className={btnGhostCls}>Ver reserva</Link>}
          {op && <ActionForm path={`/finance/reservations/${r.code}/account-statement`} idempotent inline submitLabel="Enviar estado de cuenta" success={`Estado de cuenta enviado a ${r.client_email ?? "el cliente"}`} submitClass={btnGhostCls} />}
        </>} />
      {s.settlement && <Notice>Reserva liquidada el {dateTime(s.settlement.closed_at)} por {s.settlement.closed_by}. Utilidad neta {money(s.settlement.net_pen)} · margen {s.settlement.gross_margin_pct} %.</Notice>}
      <div className="grid gap-4 lg:grid-cols-3">
        <Card title="Estado de resultados (PEN)">
          <dl className="space-y-1 text-sm">
            {([["Ingresos", f.income], ["Costo de servicios", f.service_cost], ["Costos adicionales aprobados", f.extra_cost], ["Margen bruto", f.gross], ["Comisiones", f.commissions], ["Utilidad neta", f.net]] as const).map(([l, v]) => (
              <div key={l} className={`flex justify-between ${l === "Utilidad neta" || l === "Margen bruto" ? "border-t border-slate-100 pt-1 font-semibold" : ""}`}><dt>{l}</dt><dd>{money(v)}</dd></div>
            ))}
          </dl>
          <p className="mt-3"><Light value={f.light} pct={f.margin_pct} /></p>
        </Card>
        <Card title="Flujo de caja (PEN)">
          <dl className="space-y-1 text-sm">
            <div className="flex justify-between"><dt>Cobrado</dt><dd>{money(s.cash.collected)}</dd></div>
            <div className="flex justify-between"><dt>Reembolsos</dt><dd>{money(s.cash.refunds)}</dd></div>
            <div className="flex justify-between"><dt>Pagado a proveedores</dt><dd>{money(s.cash.paid_out)}</dd></div>
            <div className="flex justify-between border-t border-slate-100 pt-1 font-semibold"><dt>Caja neta</dt><dd>{money(s.cash.net)}</dd></div>
          </dl>
        </Card>
        <Card title="Checklist de liquidación">
          <Checklist items={s.checklist} />
          {!s.settlement && op && (
            <div className="mt-4">
              <ActionForm path={`/finance/reservations/${r.code}/settlement`} idempotent ifMatch={r.lock_version} redirectTo={self} submitLabel="Cerrar liquidación"
                disabled={!s.ready} disabledReason="Complete el checklist (FIN-BR-36)" confirm="¿Cerrar la liquidación? No se puede re-liquidar (FIN-BR-37)." submitClass={btnCls} />
            </div>
          )}
        </Card>
      </div>
      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <Card title="Cobros">
          <Table head={["CxC", "Vence", "Importe", "Saldo", "Estado"]} empty="Sin obligaciones">
            {s.receivables.map((c) => (
              <tr key={c.code}><Td><MaybeLink href={`/finanzas/cobros/${c.code}`} can={perms.has("finance.receivables")}>{c.code}</MaybeLink><span className="block text-xs text-slate-500">{c.concept}</span></Td>
                <Td>{date(c.due_date)}</Td><Td>{money(c.amount, c.currency)}</Td><Td>{money(c.balance, c.currency)}</Td><Td><FinStatus value={c.status} label={c.status_label} /></Td></tr>
            ))}
          </Table>
        </Card>
        <Card title="Costos">
          <Table head={["CxP", "Proveedor", "Importe", "Saldo", "Estado"]} empty="Sin costos">
            {s.payables.map((p) => (
              <tr key={p.code}><Td><MaybeLink href={`/finanzas/cuentas-por-pagar/${p.code}`} can={perms.has("finance.payables")}>{p.code}</MaybeLink><span className="block text-xs text-slate-500">{p.concept}</span></Td>
                <Td>{p.provider}</Td><Td>{money(p.amount, p.currency)}</Td><Td>{money(p.balance, p.currency)}</Td>
                <Td><FinStatus value={p.status} label={p.status_label} />{!p.approved && p.status !== "cancelada" && <span className="block text-xs text-amber-800">Por aprobar</span>}</Td></tr>
            ))}
          </Table>
        </Card>
        <Card title="Comisiones">
          <Table head={["Comisión", "Beneficiario", "Monto", "Estado"]} empty="Sin comisiones">
            {s.commissions.map((c) => (
              <tr key={c.code}><Td><MaybeLink href={`/finanzas/comisiones?code=${c.code}`} can={perms.has("finance.commissions")}>{c.code}</MaybeLink></Td><Td>{c.beneficiary}</Td><Td>{money(c.amount, c.currency)}</Td><Td><FinStatus value={c.status} label={c.status_label} /></Td></tr>
            ))}
          </Table>
        </Card>
        <Card title="Auditoría de la reserva"><Audit rows={s.audit} /></Card>
      </div>
      {op && !s.settlement && (
        <p className="mt-4 text-sm"><Link href={`/finanzas/cuentas-por-pagar?accion=costo&reserva=${r.code}`} className="text-sky-800 hover:underline">Registrar costo adicional para esta reserva</Link></p>
      )}
    </>
  );
}
