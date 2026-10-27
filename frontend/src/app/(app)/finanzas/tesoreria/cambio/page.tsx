import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Kpis, Notice, Table, Td, inputCls } from "@/components/crm/ui";
import { TreasuryHeader } from "@/components/treasury/ui";
import { amount } from "@/lib/treasury";

type Preview = {
  period: string; close_rate: number; diff_pen: number; realized_pen: number; registered: boolean; can_register: boolean; today_rate: number;
  rows: { account: { id: string; code: string; name: string }; balance_usd: number; book_rate: number; book_value_pen: number; close_value_pen: number; diff_pen: number; registered: string | null;
    realized: { date: string; code: string; description: string; amount_usd: number; rate: number; avg_rate: number; diff_pen: number }[] }[];
  history: { code: string; account: string; period: string; date: string; balance_usd: string; book_rate: string; close_rate: string; diff_pen: string }[];
};

function Diff({ value }: { value: number | string }) {
  const n = Number(value);
  return <span className={n >= 0 ? "text-emerald-700" : "text-rose-700"}>{amount(n)} {n >= 0 ? "ganancia (776)" : "pérdida (676)"}</span>;
}

/** Diferencia de cambio (§2, TES-BR-16/18): revaluación de saldos USD por promedio ponderado; registra solo finance.invoicing. */
export default async function FxPage({ searchParams }: { searchParams: Promise<{ close_rate?: string; period?: string }> }) {
  const sp = await searchParams;
  const p = (await api<{ data: Preview }>(`/treasury/fx/revaluation-preview${qs({ close_rate: sp.close_rate, period: sp.period })}`)).data;
  return (
    <>
      <TreasuryHeader active="cambio" subtitle={`Periodo ${p.period} · TC del día ${p.today_rate.toFixed(3)}`} />
      <form className="mb-4 flex flex-wrap items-end gap-3 text-sm" aria-label="Parámetros de la revaluación">
        <label><span className="mb-1 block font-medium text-slate-700">Periodo</span><input name="period" type="month" defaultValue={p.period} className={inputCls} /></label>
        <label><span className="mb-1 block font-medium text-slate-700">TC de cierre (SBS)</span><input name="close_rate" type="number" step="0.0001" min="0" defaultValue={p.close_rate} className={inputCls} /></label>
        <button className="rounded-md border border-slate-300 bg-white px-3 py-2 font-medium text-slate-700">Recalcular</button>
      </form>
      <Kpis items={[
        { label: "Diferencia no realizada", value: <Diff value={p.diff_pen} />, hint: "Saldo USD × (TC cierre − TC promedio)" },
        { label: "Realizada en salidas USD", value: <Diff value={p.realized_pen} />, hint: "Informativa: ya incluida en cada salida" },
        { label: "TC de cierre", value: p.close_rate.toFixed(4) },
        { label: "Estado del periodo", value: p.registered ? "Registrado" : "Pendiente" },
      ]} />
      {p.registered && <Notice tone="warning">La diferencia cambiaria de {p.period} ya está registrada (TES-BR-16): una por cuenta y periodo.</Notice>}
      {p.rows.map((r) => (
        <Card key={r.account.id} title={`${r.account.code} · ${r.account.name}`} className="mb-4">
          <dl className="mb-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
            {[["Saldo USD", amount(r.balance_usd, "USD")], ["TC promedio", r.book_rate.toFixed(4)], ["Valor contable", amount(r.book_value_pen)], ["Valor al cierre", amount(r.close_value_pen)],
              ["Diferencia", <Diff key="d" value={r.diff_pen} />]].map(([k, v]) => (
              <div key={k as string} className="rounded-md bg-slate-50 p-2"><dt className="text-xs text-slate-500">{k}</dt><dd className="font-medium">{v}</dd></div>
            ))}
          </dl>
          {r.realized.length > 0 && (
            <Table head={["Fecha", "Movimiento", "USD", "TC salida", "TC promedio", "Realizada"]}>
              {r.realized.map((x) => (
                <tr key={x.code}><Td>{date(x.date)}</Td><Td><span className="font-mono">{x.code}</span><span className="block text-xs text-slate-500">{x.description}</span></Td>
                  <Td>{amount(x.amount_usd, "USD")}</Td><Td>{x.rate.toFixed(4)}</Td><Td>{x.avg_rate.toFixed(4)}</Td><Td><Diff value={x.diff_pen} /></Td></tr>
              ))}
            </Table>
          )}
        </Card>
      ))}
      <Card title="Registrar" className="mb-4">
        {!p.can_register && <p className="mb-2 text-sm text-slate-600">Solo Contabilidad registra la diferencia (finance.invoicing).</p>}
        <ActionForm path="/treasury/fx/revaluations" submitLabel={`Registrar diferencia de ${p.period}`} success="Diferencia cambiaria registrada" disabled={!p.can_register}
          disabledReason="Requiere permiso finance.invoicing" confirm={`Se registrará ${amount(p.diff_pen)} con TC ${p.close_rate}. ¿Continuar?`}>
          <input type="hidden" name="period" value={p.period} />
          <input type="hidden" name="close_rate" value={p.close_rate} />
        </ActionForm>
      </Card>
      <Card title="Historial">
        <Table head={["Código", "Cuenta", "Periodo", "Saldo USD", "TC libro → cierre", "Diferencia"]} empty="Sin revaluaciones registradas">
          {p.history.map((h) => (
            <tr key={h.code}><Td className="font-mono">{h.code}</Td><Td>{h.account}</Td><Td>{h.period}<span className="block text-xs text-slate-500">{date(h.date)}</span></Td>
              <Td>{amount(h.balance_usd, "USD")}</Td><Td>{Number(h.book_rate).toFixed(4)} → {Number(h.close_rate).toFixed(4)}</Td><Td><Diff value={h.diff_pen} /></Td></tr>
          ))}
        </Table>
      </Card>
    </>
  );
}
