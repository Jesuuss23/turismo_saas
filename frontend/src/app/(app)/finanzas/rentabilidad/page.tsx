import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import { finPerms } from "@/lib/finance-server";
import { FLOWS, LIGHT_LABELS, money, type Figures } from "@/lib/finance";
import Link from "@/components/Link";
import { btnGhostCls, Card, inputCls, Kpis, PageHeader, Table, Td } from "@/components/crm/ui";
import { Bars, Flow, Light, RoleNote } from "@/components/finance/ui";

type Group = { label: string; count: number; income: string; cost: string; gross: string; commissions?: string; net?: string; margin_pct: number; light?: string };
type Overview = {
  kpis: Omit<Figures, "service_cost" | "extra_cost"> & { count: number };
  monthly: (Group & { month: string })[]; by_product: Group[]; by_seller: Group[]; by_channel: Group[]; lights: Record<"r" | "a" | "g", number>;
};
type Row = Figures & { code: string; title: string; client: string; owner: string | null; channel: string; status: string; status_label: string; travel_from: string; currency: string; settled: boolean };

/** Finanzas › Rentabilidad (PROC-048): ingresos, costos, comisiones y margen por reserva; liquidación en `/[reservationCode]`. */
export default async function ProfitabilityPage({ searchParams }: { searchParams: Promise<{ light?: string }> }) {
  const sp = await searchParams;
  const perms = await finPerms();
  const [{ data: o }, { data: rows }] = await Promise.all([
    api<{ data: Overview }>("/finance/profitability"),
    api<{ data: Row[] }>(`/finance/profitability/reservations${qs({ "filter[light]": sp.light })}`),
  ]);
  const k = o.kpis;
  const shown = sp.light ? rows.filter((r) => r.light === sp.light) : rows;

  return (
    <>
      <PageHeader title="Rentabilidad" subtitle="Resultado por reserva al TC conservado: ingresos, costos de servicios y adicionales aprobados, comisiones y margen" />
      <RoleNote perms={perms} screen="profitability" />
      <div className="mb-5"><Flow title="Flujo financiero oficial" steps={FLOWS.profitability} /></div>
      <Kpis items={[
        { label: "Ingresos", value: money(k.income), hint: `${k.count} reservas` },
        { label: "Costos", value: money(k.cost) },
        { label: "Comisiones", value: money(k.commissions) },
        { label: "Margen bruto", value: `${k.margin_pct.toLocaleString("es-PE")} %`, hint: <>Utilidad neta {money(k.net)} · <Light value={k.light} /></> },
      ]} />
      <div className="mb-5 grid gap-4 lg:grid-cols-2">
        <Card title="Ventas vs costo (6 meses)">
          <ul className="space-y-2 text-sm">
            {o.monthly.map((m) => (
              <li key={m.month}>
                <div className="flex justify-between"><span>{m.month}</span><span>{money(m.income)} / {money(m.cost)} · {m.margin_pct.toLocaleString("es-PE")} %</span></div>
                <div className="mt-1 flex gap-1">
                  <div className="h-2 rounded bg-sky-600" style={{ width: `${Math.min(100, (Number(m.income) / Math.max(1, ...o.monthly.map((x) => Number(x.income)))) * 100)}%` }} />
                </div>
                <div className="mt-0.5 h-2 rounded bg-amber-500" style={{ width: `${Math.min(100, (Number(m.cost) / Math.max(1, ...o.monthly.map((x) => Number(x.income)))) * 100)}%` }} />
              </li>
            ))}
          </ul>
          <p className="mt-2 text-xs text-slate-500"><span className="inline-block h-2 w-3 rounded bg-sky-600" /> Ventas · <span className="inline-block h-2 w-3 rounded bg-amber-500" /> Costo</p>
        </Card>
        <Card title="Semáforo de margen">
          <ul className="space-y-2 text-sm">
            {(["g", "a", "r"] as const).map((l) => (
              <li key={l} className="flex items-center justify-between">
                <Link href={`/finanzas/rentabilidad?light=${l}`} className="text-sky-800 hover:underline">{LIGHT_LABELS[l]}</Link><span className="font-medium">{o.lights[l]}</span>
              </li>
            ))}
          </ul>
          <p className="mt-2 text-xs text-slate-500">≥ 25 % en meta · 15–25 % vigilar · &lt; 15 % bajo mínimo (FIN-BR-33).</p>
        </Card>
      </div>
      <div className="mb-5 grid gap-4 lg:grid-cols-3">
        {([["Margen por producto", o.by_product], ["Margen por vendedor", o.by_seller], ["Margen por canal", o.by_channel]] as const).map(([title, groups]) => (
          <Card key={title} title={title}>
            <Bars rows={groups.map((g) => ({ label: g.label, value: Number(g.gross), hint: `${g.margin_pct.toLocaleString("es-PE")} %`, tone: g.margin_pct < 15 ? "bg-rose-500" : g.margin_pct < 25 ? "bg-amber-500" : "bg-emerald-600" }))} />
          </Card>
        ))}
      </div>
      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <select name="light" defaultValue={sp.light ?? ""} aria-label="Semáforo" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Semáforo: todos</option>{Object.entries(LIGHT_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Reserva", "Cliente / vendedor", "Viaje", "Ingresos", "Costos", "Comisiones", "Utilidad neta", "Margen", ""]} empty="Sin reservas">
        {shown.map((r) => (
          <tr key={r.code}>
            <Td className="whitespace-nowrap"><Link href={`/finanzas/rentabilidad/${r.code}`} className="font-medium text-sky-800 hover:underline">{r.code}</Link><span className="block text-xs text-slate-500">{r.status_label}</span></Td>
            <Td>{r.client}<span className="block text-xs text-slate-500">{r.owner ?? r.channel}</span></Td>
            <Td className="whitespace-nowrap">{date(r.travel_from)}</Td>
            <Td>{money(r.income)}</Td><Td>{money(r.cost)}{Number(r.extra_cost) > 0 && <span className="block text-xs text-slate-500">+{money(r.extra_cost)} adicionales</span>}</Td>
            <Td>{money(r.commissions)}</Td><Td className="font-medium">{money(r.net)}</Td>
            <Td><Light value={r.light} pct={r.margin_pct} /></Td>
            <Td>{r.settled ? <span className="text-xs font-medium text-emerald-700">Liquidada</span> : <Link href={`/finanzas/rentabilidad/${r.code}`} className="text-xs text-sky-800 hover:underline">Liquidación</Link>}</Td>
          </tr>
        ))}
      </Table>
      <p className="mt-2 text-xs text-slate-500">Montos en PEN al tipo de cambio conservado de cada reserva.</p>
    </>
  );
}
