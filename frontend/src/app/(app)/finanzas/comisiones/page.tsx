import { api } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { finPerms } from "@/lib/finance-server";
import { money, OP, type AuditRow, type Commission } from "@/lib/finance";
import Link from "@/components/Link";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnCls, btnGhostCls, Card, Drawer, Input, inputCls, Kpis, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { withParams } from "@/components/supplier/ui";
import { Audit, Dl, FinStatus, MaybeLink, RoleNote } from "@/components/finance/ui";

type Summary = Record<"calculada" | "aprobada" | "pagada", { count: number; amount: string }> & {
  without_calculation: number; by_beneficiary: { beneficiary: string; kind: string; count: number; amount: string; pending: string }[];
};
type Rules = {
  rules: { label: string; condition: string; pct: string | null; base: string; valid_from: string }[];
  agencies: { code: string; name: string; status: string; commission_pct: string; credit_limit: string; credit_used: string; available: string }[];
};
type Detail = Commission & {
  drift: { expected: string; expected_pct: string; rule: string; difference: string; alert: boolean };
  history: { status: string; note: string | null; at: string; user: string | null }[]; audit: AuditRow[];
};
type Search = { status?: string; kind?: string; code?: string; accion?: string };

/** Finanzas › Comisiones (PROC-047): vendedores y agencias B2B. */
export default async function CommissionsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const perms = await finPerms();
  const op = perms.has(OP);
  const [{ data: rows, meta }, { data: s }, { data: rules }] = await Promise.all([
    api<{ data: Commission[]; meta: { labels: Record<string, string> } }>(`/finance/commissions${qs({ "filter[status]": sp.status, "filter[kind]": sp.kind })}`),
    api<{ data: Summary }>("/finance/commissions/summary"),
    api<{ data: Rules }>("/finance/commission-rules"),
  ]);
  const base = `/finanzas/comisiones${qs({ status: sp.status, kind: sp.kind })}`;
  const c = sp.code ? (await api<{ data: Detail }>(`/finance/commissions/${encodeURIComponent(sp.code)}`)).data : null;
  const self = c ? withParams(base, { code: c.code }) : base;

  return (
    <>
      <PageHeader title="Comisiones" subtitle="Comisiones de vendedores y agencias B2B: cálculo según reglas vigentes, aprobación y pago"
        actions={op ? <ActionForm path="/finance/commissions/calculate" idempotent inline submitLabel="Calcular pendientes" success="Cálculo ejecutado" submitClass={btnCls} /> : undefined} />
      <RoleNote perms={perms} screen="commissions" />
      <Kpis items={[
        { label: "Calculadas", value: s.calculada.count, hint: money(s.calculada.amount) },
        { label: "Aprobadas", value: s.aprobada.count, hint: money(s.aprobada.amount) },
        { label: "Pagadas", value: s.pagada.count, hint: money(s.pagada.amount) },
        { label: "Ventas sin cálculo", value: s.without_calculation },
      ]} />
      {s.without_calculation > 0 && <Notice tone="warning">Hay {s.without_calculation} reservas confirmadas sin comisión calculada.</Notice>}
      <div className="mb-5 grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
        <Card title="Reglas vigentes">
          <ul className="space-y-1 text-sm">
            {rules.rules.map((r) => <li key={r.condition}>{r.label}{r.pct && <span className="font-medium"> · {r.pct} %</span>}<span className="block text-xs text-slate-500">Base {r.base} · desde {date(r.valid_from)}</span></li>)}
          </ul>
          <p className="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Convenios B2B</p>
          <ul className="text-sm">{rules.agencies.map((a) => <li key={a.code}>{a.name} ({a.code}) · {a.commission_pct} %</li>)}</ul>
        </Card>
        <Card title="Resumen por beneficiario">
          <Table head={["Beneficiario", "Comisiones", "Total", "Por pagar"]}>
            {s.by_beneficiary.map((b) => <tr key={b.beneficiary}><Td>{b.beneficiary}<span className="block text-xs text-slate-500">{b.kind === "vendedor" ? "Vendedor" : b.kind === "bono_meta" ? "Bono por meta" : "Agencia B2B"}</span></Td><Td>{b.count}</Td><Td>{money(b.amount)}</Td><Td>{money(b.pending)}</Td></tr>)}
          </Table>
        </Card>
      </div>
      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Estado: todos</option>{Object.entries(meta.labels).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select name="kind" defaultValue={sp.kind ?? ""} aria-label="Tipo" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Tipo: todos</option><option value="vendedor">Vendedor</option><option value="agencia_b2b">Agencia B2B</option><option value="bono_meta">Bono por meta</option>
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Comisión", "Beneficiario", "Reserva / canal", "Regla", "Base × %", "Comisión", "Estado"]} empty="Sin comisiones">
        {rows.map((r) => (
          <tr key={r.id}>
            <Td className="whitespace-nowrap"><Link href={withParams(base, { code: r.code })} scroll={false} className="font-medium text-sky-800 hover:underline">{r.code}</Link></Td>
            <Td>{r.beneficiary}<span className="block text-xs text-slate-500">{r.kind_label}</span></Td>
            <Td>{r.reservation ?? (r.sales_target ? <Link href={`/crm/metas/${r.sales_target}`} className="text-sky-800 hover:underline">Meta {r.sales_target}</Link> : "—")}<span className="block text-xs text-slate-500">{r.channel}</span></Td>
            <Td className="max-w-xs text-xs text-slate-600">{r.rule}</Td>
            <Td className="whitespace-nowrap">{money(r.base, r.currency)} × {r.pct} %</Td>
            <Td className="whitespace-nowrap font-medium">{money(r.amount, r.currency)}</Td>
            <Td><FinStatus value={r.status} label={r.status_label} /></Td>
          </tr>
        ))}
      </Table>

      {c && (
        <Drawer title={`Comisión ${c.code}`} close={base}>
          <div className="space-y-4">
            <Dl items={[
              ["Estado", <FinStatus key="s" value={c.status} label={c.status_label} />], ["Beneficiario", `${c.beneficiary} · ${c.kind_label}`],
              c.sales_target ? ["Meta comercial", <Link key="m" href={`/crm/metas/${c.sales_target}`} className="text-sky-800 hover:underline">{c.sales_target}</Link>]
                : ["Reserva", c.reservation ? <MaybeLink key="r" href={`/finanzas/rentabilidad/${c.reservation}`} can={perms.has("finance.profitability")}>{c.reservation}</MaybeLink> : "—"],
              ["Regla", c.rule], ["Base", money(c.base, c.currency)], ["Porcentaje", `${c.pct} %`], ["Comisión", money(c.amount, c.currency)],
              ["Pago", c.paid_at ? `${c.payment_method} · ${c.payment_ref} · ${dateTime(c.paid_at)}` : "—"],
            ]} />
            {c.drift.alert && c.status !== "pagada" && (
              <Notice tone="warning">La regla vigente da {money(c.drift.expected, c.currency)} ({c.drift.expected_pct} %): diferencia {money(c.drift.difference, c.currency)}. {op ? "Recalcule antes de aprobar." : ""}</Notice>
            )}
            <div className="flex flex-wrap gap-2">
              {c.status === "calculada" && <ActionForm path={`/finance/commissions/${c.code}/approve`} ifMatch={c.lock_version} redirectTo={self} inline submitLabel="Aprobar comisión" submitClass={btnCls} />}
              {op && c.status !== "pagada" && <ActionForm path={`/finance/commissions/${c.code}/recalculate`} ifMatch={c.lock_version} redirectTo={self} inline submitLabel="Recalcular" submitClass={btnGhostCls} />}
            </div>
            {op && c.status === "aprobada" && (
              <ActionForm path={`/finance/commissions/${c.code}/pay`} idempotent ifMatch={c.lock_version} redirectTo={self} submitLabel="Pagar comisión">
                <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Forma de pago</span>
                  <select aria-label="Forma de pago" name="method" className={inputCls}>{c.pay_methods.map((m) => <option key={m}>{m}</option>)}</select>
                </label>
                <Input label="Referencia" name="reference" required maxLength={60} />
              </ActionForm>
            )}
            <section>
              <h3 className="mb-2 text-sm font-semibold text-slate-800">Historial de estados</h3>
              <ol className="space-y-2 text-sm">
                {c.history.map((h, i) => <li key={i} className="border-l-2 border-slate-200 pl-3">{meta.labels[h.status] ?? h.status} · {h.note}<span className="block text-xs text-slate-500">{h.user ?? "Sistema"} · {dateTime(h.at)}</span></li>)}
              </ol>
            </section>
            <section><h3 className="mb-2 text-sm font-semibold text-slate-800">Auditoría</h3><Audit rows={c.audit} /></section>
          </div>
        </Drawer>
      )}
    </>
  );
}
