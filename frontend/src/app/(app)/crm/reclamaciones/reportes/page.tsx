import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { Card, Kpis, Table, Td, inputCls } from "@/components/crm/ui";
import { Bars, SeriesChart } from "@/components/admin/ui";
import { AnnualExport } from "@/components/claims/AnnualExport";
import { ClaimsHeader, DeadlinePill } from "@/components/claims/ui";
import type { ClaimRow } from "@/lib/claims";

type Reports = {
  by_type: { label: string; value: number }[]; by_sede: { label: string; value: number }[];
  compliance: { answered: number; in_time: number; late: number; overdue_open: number; pct: number | null };
  by_month: { series: string[]; rows: { label: string; values: number[] }[] }; overdue: ClaimRow[]; year: number; annual: string[][]; can_export: boolean;
};
const HEADER = ["N.° hoja", "Fecha", "Sede", "Tipo", "Consumidor", "Documento", "Bien contratado", "Vence", "Respondida", "Decisión", "Estado", "Plazo"];

/** Reportes del libro (§2): por tipo, sede, cumplimiento del plazo, 6 meses, vencidas y relación anual para INDECOPI. */
export default async function ClaimReportsPage({ searchParams }: { searchParams: Promise<{ year?: string }> }) {
  const sp = await searchParams;
  const r = (await api<{ data: Reports }>(`/crm/claim-book/reports${sp.year ? `?year=${encodeURIComponent(sp.year)}` : ""}`)).data;
  const c = r.compliance;
  return (
    <>
      <ClaimsHeader active="reportes" />
      <Kpis items={[
        { label: "Cumplimiento del plazo", value: c.pct === null ? "—" : `${c.pct} %`, hint: `${c.in_time} de ${c.answered} respondidas en plazo` },
        { label: "Respondidas fuera de plazo", value: c.late },
        { label: "Vencidas sin respuesta", value: <span className={c.overdue_open ? "text-rose-700" : ""}>{c.overdue_open}</span> },
        { label: "Hojas del año", value: r.annual.length },
      ]} />
      <div className="mb-4 grid gap-4 lg:grid-cols-3 [&>*]:min-w-0">
        <Card title="Por tipo"><Bars rows={r.by_type} /></Card>
        <Card title="Por sede"><Bars rows={r.by_sede} /></Card>
        <Card title="Últimos 6 meses"><SeriesChart series={r.by_month.series} rows={r.by_month.rows} /></Card>
      </div>
      <Card title="Vencidas sin respuesta" className="mb-4">
        <Table head={["Hoja", "Consumidor", "Venció", "Plazo", "Responsable"]} empty="Ninguna hoja vencida">
          {r.overdue.map((o) => (
            <tr key={o.id}><Td><Link href={`/crm/reclamaciones/${o.code}`} className="font-mono text-sky-800 hover:underline">{o.book_number}</Link></Td><Td>{o.consumer_name}</Td>
              <Td>{date(o.due_date)}</Td><Td><DeadlinePill d={o.deadline} /></Td><Td>{o.owner ?? "—"}</Td></tr>
          ))}
        </Table>
      </Card>
      <Card title={`Relación anual ${r.year} (INDECOPI)`} actions={
        <div className="flex flex-wrap items-center gap-2">
          <form className="flex gap-1"><input name="year" type="number" min="2020" max="2100" defaultValue={r.year} aria-label="Año" className={`${inputCls} w-24`} /><button className="rounded-md border border-slate-300 px-2 text-sm">Ver</button></form>
          <AnnualExport year={r.year} enabled={r.can_export} />
        </div>
      }>
        <Table head={HEADER} empty="Sin hojas en el año">
          {r.annual.map((row) => <tr key={row[0]}>{row.map((v, i) => <Td key={i} className={i === 0 ? "font-mono" : ""}>{v || "—"}</Td>)}</tr>)}
        </Table>
      </Card>
    </>
  );
}
