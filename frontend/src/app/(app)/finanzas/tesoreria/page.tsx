import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Kpis, btnGhostCls } from "@/components/crm/ui";
import { Bars } from "@/components/admin/ui";
import { AccountsTable, Alerts, BalanceSeries, MovementsTable, TreasuryHeader } from "@/components/treasury/ui";
import { MovementDrawer } from "@/components/treasury/MovementDrawer";
import { amount, type Account, type Movement, type TreasuryCan } from "@/lib/treasury";

type Summary = {
  as_of: string; fx_usd: number; can: TreasuryCan;
  kpis: { consolidated_pen: number; banks_pen: number; banks_usd: number; cash_gateway_pen: number; projected_30: number };
  accounts: Account[]; composition: { label: string; value: number }[]; alerts: { rule: string; tone: string; text: string; link: string }[];
  series: { label: string; value: number }[]; latest: Movement[];
};

/** Resumen de tesorería (spec 12 §2): KPIs, cuentas, composición, alertas TES-BR, saldo de 28 días y últimos movimientos. */
export default async function TreasuryPage({ searchParams }: { searchParams: Promise<{ mov?: string }> }) {
  const sp = await searchParams;
  const s = (await api<{ data: Summary }>("/treasury/summary")).data;
  return (
    <>
      <TreasuryHeader active="" subtitle={`Saldos al ${date(s.as_of)} · TC USD ${s.fx_usd.toFixed(3)}`}
        actions={<ActionForm path="/treasury/sync" submitLabel="Sincronizar con Finanzas" submitClass={btnGhostCls} success="Sincronización completada" inline />} />
      <Kpis items={[
        { label: "Saldo consolidado", value: amount(s.kpis.consolidated_pen), hint: "Bancos, cajas y pasarela en PEN" },
        { label: "Bancos", value: amount(s.kpis.banks_pen), hint: `y ${amount(s.kpis.banks_usd, "USD")}` },
        { label: "Cajas y pasarela", value: amount(s.kpis.cash_gateway_pen) },
        { label: "Proyectado a 30 días", value: amount(s.kpis.projected_30), hint: <Link href="/finanzas/tesoreria/flujo" className="text-sky-800 hover:underline">Ver flujo</Link> },
      ]} />
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="min-w-0 space-y-4 lg:col-span-2">
          <Card title="Cuentas"><AccountsTable accounts={s.accounts} /></Card>
          <Card title="Saldo consolidado · últimos 28 días"><BalanceSeries rows={s.series} /></Card>
          <Card title="Últimos movimientos" actions={<Link href="/finanzas/tesoreria/movimientos" className="text-sm text-sky-800 hover:underline">Ver todos</Link>}>
            <MovementsTable rows={s.latest} detailBase="/finanzas/tesoreria" />
          </Card>
        </div>
        <div className="min-w-0 space-y-4">
          <Card title="Alertas"><Alerts rows={s.alerts} /></Card>
          <Card title="Composición del saldo (PEN)"><Bars rows={s.composition} unit="PEN" /></Card>
        </div>
      </div>
      {sp.mov && <MovementDrawer code={sp.mov} close="/finanzas/tesoreria" can={s.can} />}
    </>
  );
}
