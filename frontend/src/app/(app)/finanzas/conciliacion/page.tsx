import { api } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { finPerms } from "@/lib/finance-server";
import { FLOWS, money, OP, type BankLine } from "@/lib/finance";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnCls, btnGhostCls, Card, Drawer, inputCls, Kpis, Notice, PageHeader, Table, Td, TextArea } from "@/components/crm/ui";
import { RowAction, withParams } from "@/components/supplier/ui";
import { FinStatus, Flow, NoPermission } from "@/components/finance/ui";
import { StatementImport } from "@/components/finance/forms";

type Meta = { counts: Record<"pendiente" | "sugerido" | "excepcion" | "conciliado", number>; banks: string[]; accounts: { id: string; bank: string; label: string; currency: string }[] };
type Closure = { code: string; period: string; closed_at: string; closed_by: string | null; lines: number; credits: string; debits: string; net: string; matched: number; others: number };
type Search = { scope?: string; status?: string; resolver?: string };

const xs = "rounded-md px-2 py-1 text-xs font-medium disabled:opacity-50";

/** Finanzas › Conciliación bancaria (PROC-049): importar extracto, matching, excepciones y cierre. */
export default async function ReconciliationPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const perms = await finPerms();
  if (!perms.has(OP)) {
    return <><PageHeader title="Conciliación bancaria" /><NoPermission permission={OP} /></>;
  }
  const [{ data: lines, meta }, { data: closures }] = await Promise.all([
    api<{ data: BankLine[]; meta: Meta }>(`/finance/bank-lines${qs({ "filter[scope]": sp.scope, "filter[status]": sp.status })}`),
    api<{ data: Closure[] }>("/finance/reconciliation/closures"),
  ]);
  const base = `/finanzas/conciliacion${qs({ scope: sp.scope, status: sp.status })}`;
  const c = meta.counts;
  const open = c.pendiente + c.sugerido + c.excepcion + c.conciliado;
  const resolving = sp.resolver ? lines.find((l) => l.code === sp.resolver && l.status === "excepcion") : undefined;

  return (
    <>
      <PageHeader title="Conciliación bancaria" subtitle="Extracto del banco contra pagos recibidos y pagos a proveedores"
        actions={<>
          <ActionForm path="/finance/reconciliation/matching" idempotent inline submitLabel="Ejecutar matching" success="Matching ejecutado" submitClass={btnGhostCls} disabled={c.pendiente === 0}
            disabledReason="No hay líneas pendientes" />
          <ActionForm path="/finance/reconciliation/closures" idempotent inline submitLabel="Cerrar conciliación" success="Conciliación cerrada" submitClass={btnCls}
            disabled={open === 0 || c.conciliado !== open} disabledReason="Todas las líneas abiertas deben estar conciliadas (FIN-BR-55)" confirm="¿Cerrar la conciliación? Las líneas quedan bloqueadas." />
        </>} />
      <Kpis items={[
        { label: "Pendientes", value: c.pendiente }, { label: "Sugeridas", value: c.sugerido },
        { label: "Excepciones", value: c.excepcion }, { label: "Conciliadas (abiertas)", value: c.conciliado },
      ]} />
      {c.excepcion > 0 && <Notice tone="warning">Hay {c.excepcion} {c.excepcion === 1 ? "línea en excepción" : "líneas en excepción"}: asígnelas o regístrelas con sustento antes de cerrar.</Notice>}
      <div className="mb-5"><Flow title="PROC-049 · Conciliación bancaria" steps={FLOWS.reconciliation} /></div>
      <Card title="Importar extracto" className="mb-5"><StatementImport banks={meta.banks} accounts={meta.accounts} /></Card>

      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <select name="scope" defaultValue={sp.scope ?? "abiertas"} aria-label="Alcance" className={`${inputCls} max-w-[12rem]`}>
          <option value="abiertas">Líneas abiertas</option><option value="cerradas">Cerradas</option><option value="todas">Todas</option>
        </select>
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Estado: todos</option><option value="pendiente">Pendiente</option><option value="sugerido">Sugerido</option><option value="excepcion">Excepción</option><option value="conciliado">Conciliado</option>
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Línea", "Fecha", "Descripción", "Abono / cargo", "Estado", "Match", "Acciones"]} empty="Sin líneas: importe un extracto">
        {lines.map((l) => (
          <tr key={l.id}>
            <Td className="whitespace-nowrap font-mono">{l.code}<span className="block font-sans text-xs text-slate-500">{l.bank}</span></Td>
            <Td className="whitespace-nowrap">{date(l.value_date)}</Td>
            <Td>{l.description}</Td>
            <Td className={`whitespace-nowrap ${l.kind === "abono" ? "text-emerald-800" : "text-rose-800"}`}>{money(l.amount, l.currency)}</Td>
            <Td><FinStatus value={l.status} label={l.status_label} />{l.closed && <span className="block text-xs text-slate-500">Cerrada</span>}</Td>
            <Td>{l.match ? <span>{l.match.code}<span className="block text-xs text-slate-500">{l.match.label} · {l.match.amount}</span></span>
              : l.resolution ? <span className="text-xs">{l.resolution.replace("_", " ")}<span className="block text-slate-500">{l.resolution_note}</span></span> : "—"}</Td>
            <Td>
              {!l.closed && l.status === "sugerido" && (
                <div className="flex flex-wrap gap-1">
                  <ActionForm path={`/finance/bank-lines/${l.code}/confirm`} inline submitLabel="Confirmar" success="Línea conciliada" submitClass={`${xs} bg-emerald-700 text-white`} />
                  <ActionForm path={`/finance/bank-lines/${l.code}/reject`} inline submitLabel="Rechazar" success="Sugerencia rechazada" submitClass={`${xs} bg-white text-slate-700 ring-1 ring-slate-300`} />
                </div>
              )}
              {!l.closed && l.status === "excepcion" && <RowAction primary href={withParams(base, { resolver: l.code })}>Resolver</RowAction>}
            </Td>
          </tr>
        ))}
      </Table>

      <Card title="Cierres de conciliación" className="mt-5">
        <Table head={["Cierre", "Fecha", "Líneas", "Abonos", "Cargos", "Neto", "Responsable"]} empty="Sin cierres">
          {closures.map((k) => (
            <tr key={k.code}><Td className="font-mono">{k.code}</Td><Td>{dateTime(k.closed_at)}</Td><Td>{k.lines} ({k.matched} match · {k.others} otros)</Td>
              <Td>{money(k.credits)}</Td><Td>{money(k.debits)}</Td><Td className="font-medium">{money(k.net)}</Td><Td>{k.closed_by}</Td></tr>
          ))}
        </Table>
      </Card>

      {resolving && (
        <Drawer title={`Resolver ${resolving.code}`} close={base}>
          <p className="mb-3 text-sm text-slate-600">{resolving.description} · {money(resolving.amount, resolving.currency)}</p>
          <ActionForm path={`/finance/bank-lines/${resolving.code}/resolve`} redirectTo={base} submitLabel="Resolver excepción">
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Resolución</span>
              <select aria-label="Resolución" name="mode" className={inputCls} defaultValue={resolving.candidates?.length ? "assign" : "other"}>
                <option value="assign">Asignar a {resolving.kind === "abono" ? "un pago" : "un documento por pagar"}</option>
                <option value="other">{resolving.kind === "abono" ? "Otro ingreso" : "Gasto / comisión bancaria"}</option>
              </select>
            </label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Candidato</span>
              <select aria-label="Candidato" name="target_id" className={inputCls} defaultValue="">
                <option value="">—</option>
                {resolving.candidates?.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.label} · {money(x.amount_pen)}</option>)}
              </select>
            </label>
            <TextArea label="Sustento" name="note" required maxLength={500} />
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}
