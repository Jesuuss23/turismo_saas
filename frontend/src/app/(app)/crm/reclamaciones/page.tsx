import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Kpis, Table, Td, btnGhostCls, inputCls } from "@/components/crm/ui";
import { ClaimForm } from "@/components/claims/ClaimForm";
import { BookStatus, ClaimsHeader, DeadlinePill } from "@/components/claims/ui";
import type { ClaimFormData, ClaimRow } from "@/lib/claims";

type Params = { type?: string; sede?: string; book_status?: string; deadline?: string; q?: string; nuevo?: string };
type Listing = { data: ClaimRow[]; kpis: { month: number; open: number; due_soon: number; overdue: number; avg_days: number | null }; meta: { sedes: { id: string; name: string }[]; statuses: Record<string, string> } };

const DEADLINES = { en_plazo: "En plazo", por_vencer: "Por vencer", vencido: "Vencido", respondido_en_plazo: "Respondido en plazo", respondido_fuera_de_plazo: "Respondido fuera de plazo" };

/** Bandeja del libro (spec 12 §2): KPIs, filtros por tipo, sede, estado y plazo; registro presencial y job de alertas. */
export default async function ClaimsPage({ searchParams }: { searchParams: Promise<Params> }) {
  const sp = await searchParams;
  const filters = { type: sp.type, sede: sp.sede, book_status: sp.book_status, deadline: sp.deadline };
  const query = new URLSearchParams();
  Object.entries(filters).forEach(([k, v]) => v && query.set(`filter[${k}]`, v));
  if (sp.q) query.set("q", sp.q);
  const [res, form] = await Promise.all([
    api<Listing>(`/crm/claim-book?${query.toString()}`),
    sp.nuevo ? api<{ data: ClaimFormData }>("/crm/claim-book/form").then((r) => r.data) : Promise.resolve(null),
  ]);
  const k = res.kpis;
  return (
    <>
      <ClaimsHeader active="" actions={
        <>
          <Link href={`/crm/reclamaciones${qs({ ...filters, nuevo: "1" })}`} className="rounded-md bg-sky-700 px-3 py-2 text-sm font-medium text-white hover:bg-sky-800">Registro presencial</Link>
          <ActionForm path="/crm/claim-book/alerts" submitLabel="Alertar vencimientos" submitClass={btnGhostCls} inline />
          <Link href="/libro-reclamaciones" className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Vista previa del formulario</Link>
        </>
      } />
      <Kpis items={[
        { label: "Recibidas en el mes", value: k.month },
        { label: "Abiertas", value: k.open, hint: `${k.due_soon} por vencer` },
        { label: "Vencidas", value: <span className={k.overdue ? "text-rose-700" : ""}>{k.overdue}</span>, hint: "Sin respuesta pasada la fecha límite" },
        { label: "Tiempo medio de respuesta", value: k.avg_days === null ? "—" : `${k.avg_days} d hábiles` },
      ]} />
      {form && (
        <Card title="Registro presencial (el consumidor firma la hoja en la sede)" className="mb-4" actions={<Link href={`/crm/reclamaciones${qs(filters)}`} className="text-sm text-slate-600 hover:underline">Cerrar</Link>}>
          <ClaimForm form={form} mode="in_person" path="/crm/claim-book" />
        </Card>
      )}
      <form className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6" aria-label="Filtros">
        <select name="type" defaultValue={sp.type ?? ""} aria-label="Tipo" className={inputCls}><option value="">Reclamos y quejas</option><option value="reclamo">Reclamos</option><option value="queja">Quejas</option></select>
        <select name="sede" defaultValue={sp.sede ?? ""} aria-label="Sede" className={inputCls}><option value="">Todas las sedes</option>{res.meta.sedes.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
        <select name="book_status" defaultValue={sp.book_status ?? ""} aria-label="Estado" className={inputCls}><option value="">Todos los estados</option>{Object.entries(res.meta.statuses).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        <select name="deadline" defaultValue={sp.deadline ?? ""} aria-label="Plazo" className={inputCls}><option value="">Cualquier plazo</option>{Object.entries(DEADLINES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        <input name="q" defaultValue={sp.q} placeholder="N° de hoja, consumidor, bien" aria-label="Buscar" className={inputCls} />
        <button className="rounded-md bg-sky-700 px-3 py-2 text-sm font-medium text-white">Filtrar</button>
      </form>
      <Table head={["Hoja", "Tipo", "Consumidor", "Sede · canal", "Recibida", "Plazo", "Estado", "Responsable"]} empty="Sin hojas con estos filtros">
        {res.data.map((c) => (
          <tr key={c.id}>
            <Td><Link href={`/crm/reclamaciones/${c.code}`} className="font-mono text-sky-800 hover:underline">{c.book_number}</Link><span className="block text-xs text-slate-500">{c.code}</span></Td>
            <Td>{c.type_label}</Td>
            <Td>{c.consumer_name}<span className="block text-xs text-slate-500">{c.good}</span></Td>
            <Td>{c.sede}<span className="block text-xs text-slate-500">{c.channel_label}</span></Td>
            <Td className="whitespace-nowrap">{date(c.received_at)}<span className="block text-xs text-slate-500">vence {date(c.due_date)}</span></Td>
            <Td><DeadlinePill d={c.deadline} /></Td>
            <Td><BookStatus status={c.book_status} label={c.status_label} /></Td>
            <Td>{c.owner ?? "—"}</Td>
          </tr>
        ))}
      </Table>
    </>
  );
}
