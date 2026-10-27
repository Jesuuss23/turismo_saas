import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import { Card, Kpis, Table, Td, inputCls } from "@/components/crm/ui";
import { SeriesChart } from "@/components/admin/ui";
import { TreasuryHeader } from "@/components/treasury/ui";
import { amount } from "@/lib/treasury";

type Flow = {
  as_of: string; horizon: number; include_overdue: boolean; include_fixed: boolean; fx_usd: number; opening_pen: number;
  buckets: { label: string; in: number; out: number; net: number; balance: number }[];
  weeks: { label: string; in: number; out: number; balance: number }[];
  totals: { receivables: number; payables: number; commissions: number; fixed: number; ending: number };
  projected_30: number; min_week: { label: string; balance: number } | null;
  items: { date: string; kind: string; direction: "in" | "out"; ref: string | null; party: string; amount_pen: number; overdue: boolean }[];
  recurring: { day: number; amount: number; concept: string }[];
};
type Params = { horizon?: string; include_overdue?: string; include_fixed?: string; kind?: string };

/** Flujo de caja proyectado (§2, TES-BR-17): horizontes 0–30/31–60/61–90, 13 semanas y detalle de CxC, CxP, comisiones y egresos fijos. */
export default async function CashFlowPage({ searchParams }: { searchParams: Promise<Params> }) {
  const sp = await searchParams;
  const f = (await api<{ data: Flow }>(`/treasury/cash-flow${qs({ horizon: sp.horizon, include_overdue: sp.include_overdue, include_fixed: sp.include_fixed })}`)).data;
  const items = sp.kind ? f.items.filter((i) => i.kind === sp.kind) : f.items;
  const kinds = [...new Set(f.items.map((i) => i.kind))];
  return (
    <>
      <TreasuryHeader active="flujo" subtitle={`Saldo inicial ${amount(f.opening_pen)} al ${date(f.as_of)} · USD a ${f.fx_usd.toFixed(3)}`} />
      <form className="mb-4 flex flex-wrap items-end gap-3 text-sm" aria-label="Parámetros del flujo">
        <label><span className="mb-1 block font-medium text-slate-700">Horizonte</span>
          <select name="horizon" defaultValue={String(f.horizon)} className={inputCls}>{[30, 60, 90].map((h) => <option key={h} value={h}>{h} días</option>)}</select></label>
        <label><span className="mb-1 block font-medium text-slate-700">CxC vencidas</span>
          <select name="include_overdue" defaultValue={f.include_overdue ? "1" : "0"} className={inputCls}><option value="1">Incluir (cobro esperado en 7 días)</option><option value="0">Excluir</option></select></label>
        <label><span className="mb-1 block font-medium text-slate-700">Egresos fijos</span>
          <select name="include_fixed" defaultValue={f.include_fixed ? "1" : "0"} className={inputCls}><option value="1">Incluir</option><option value="0">Excluir</option></select></label>
        <button className="rounded-md bg-sky-700 px-3 py-2 font-medium text-white">Recalcular</button>
      </form>
      <Kpis items={[
        { label: "Proyectado a 30 días", value: amount(f.projected_30) },
        { label: `Saldo final (${f.horizon} d)`, value: amount(f.totals.ending) },
        { label: "Semana más baja", value: f.min_week ? amount(f.min_week.balance) : "—", hint: f.min_week ? `Semana del ${f.min_week.label}` : undefined },
        { label: "Por cobrar / por pagar", value: amount(f.totals.receivables), hint: `CxP ${amount(f.totals.payables)} · comisiones ${amount(f.totals.commissions)} · fijos ${amount(f.totals.fixed)}` },
      ]} />
      <div className="mb-4 grid gap-4 lg:grid-cols-3 [&>*]:min-w-0">
        {f.buckets.map((b) => (
          <Card key={b.label} title={b.label}>
            <dl className="grid grid-cols-2 gap-2 text-sm">
              <dt className="text-slate-500">Ingresos</dt><dd className="text-right text-emerald-700">{amount(b.in)}</dd>
              <dt className="text-slate-500">Egresos</dt><dd className="text-right text-rose-700">{amount(b.out)}</dd>
              <dt className="text-slate-500">Neto</dt><dd className="text-right font-medium">{amount(b.net)}</dd>
              <dt className="text-slate-500">Saldo al cierre</dt><dd className={`text-right font-semibold ${b.balance < 0 ? "text-rose-700" : "text-slate-900"}`}>{amount(b.balance)}</dd>
            </dl>
          </Card>
        ))}
      </div>
      <Card title="13 semanas: ingresos, egresos y saldo" className="mb-4">
        <SeriesChart series={["Ingresos", "Egresos", "Saldo"]} rows={f.weeks.map((w) => ({ label: w.label, values: [w.in, w.out, w.balance] }))} unit="PEN" />
      </Card>
      <Card title="Detalle" actions={
        <form className="flex gap-2" aria-label="Filtrar detalle">
          {sp.horizon && <input type="hidden" name="horizon" value={sp.horizon} />}
          <select name="kind" defaultValue={sp.kind ?? ""} className={inputCls} aria-label="Tipo"><option value="">Todo</option>{kinds.map((k) => <option key={k}>{k}</option>)}</select>
          <button className="rounded-md border border-slate-300 px-2 text-sm">Ver</button>
        </form>
      }>
        <Table head={["Fecha", "Tipo", "Referencia", "Contraparte", "Monto PEN"]} empty="Sin partidas en el horizonte">
          {items.map((i, n) => (
            <tr key={n}>
              <Td className="whitespace-nowrap">{date(i.date)}{i.overdue && <span className="block text-xs text-amber-700">vencida</span>}</Td>
              <Td>{i.kind}</Td>
              <Td className="font-mono text-xs">{i.ref ?? "—"}</Td>
              <Td>{i.party}</Td>
              <Td className={`whitespace-nowrap text-right font-medium ${i.direction === "in" ? "text-emerald-700" : "text-rose-700"}`}>{i.direction === "in" ? "+" : "−"}{amount(i.amount_pen)}</Td>
            </tr>
          ))}
        </Table>
        {f.recurring.length > 0 && <p className="mt-3 text-xs text-slate-500">Egresos fijos configurados: {f.recurring.map((r) => `${r.concept} (día ${r.day}, ${amount(r.amount)})`).join(" · ")}</p>}
      </Card>
    </>
  );
}
