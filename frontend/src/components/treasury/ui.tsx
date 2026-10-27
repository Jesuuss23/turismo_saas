import Link from "@/components/Link";
import { date } from "@/lib/crm";
import { Pill } from "@/components/admin/ui";
import { Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { amount, STATUS_LABEL, TABS, TONE, type Account, type Movement } from "@/lib/treasury";

/** Encabezado común de Tesorería con pestañas por ruta (§2). */
export function TreasuryHeader({ active, subtitle, actions }: { active: string; subtitle?: React.ReactNode; actions?: React.ReactNode }) {
  return (
    <>
      <PageHeader title="Tesorería" subtitle={subtitle ?? "Caja y bancos: saldos, movimientos, cajas chicas, flujo proyectado y diferencia de cambio."} actions={actions} />
      <nav className="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Secciones de tesorería">
        {TABS.map((t) => (
          <Link key={t.key} href={`/finanzas/tesoreria${t.key ? `/${t.key}` : ""}`} aria-current={t.key === active ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 ${t.key === active ? "border-sky-700 font-medium text-sky-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>
            {t.label}
          </Link>
        ))}
      </nav>
    </>
  );
}

export function StatusPill({ status, label }: { status: string; label?: string }) {
  return <Pill tone={TONE[status] ?? "slate"}>{label ?? STATUS_LABEL[status] ?? status}</Pill>;
}

export function Signed({ m }: { m: Pick<Movement, "direction" | "amount" | "currency" | "status"> }) {
  const cls = m.status === "voided" ? "text-slate-400 line-through" : m.direction === "in" ? "text-emerald-700" : "text-rose-700";
  return <span className={`whitespace-nowrap font-medium ${cls}`}>{m.direction === "in" ? "+" : "−"}{amount(m.amount, m.currency)}</span>;
}

export function SourceLink({ m }: { m: Movement }) {
  const text = `${m.source.kind}${m.source.label && m.source.label !== m.source.kind ? ` ${m.source.label}` : ""}`;
  return m.source.href ? <Link href={m.source.href} className="text-sky-800 hover:underline">{text}</Link> : <span>{text}</span>;
}

/** Libro de movimientos (resumen, movimientos y cuenta); `detailBase` abre el drawer con `?mov=`. */
export function MovementsTable({ rows, detailBase, running = false, showAccount = true }: { rows: Movement[]; detailBase: string; running?: boolean; showAccount?: boolean }) {
  const sep = detailBase.includes("?") ? "&" : "?";
  return (
    <Table head={["Fecha", "Código", ...(showAccount ? ["Cuenta"] : []), "Concepto", "Origen", "Monto", ...(running ? ["Saldo"] : []), "Estado"]} empty="Sin movimientos con estos filtros">
      {rows.map((m) => (
        <tr key={m.id} className={m.status === "voided" ? "bg-slate-50" : ""}>
          <Td className="whitespace-nowrap">{date(m.date)}</Td>
          <Td><Link href={`${detailBase}${sep}mov=${m.code}`} scroll={false} className="font-mono text-sky-800 hover:underline">{m.code}</Link></Td>
          {showAccount && <Td><Link href={`/finanzas/tesoreria/cuentas/${m.account.code}`} className="hover:underline">{m.account.name}</Link></Td>}
          <Td>
            <span className="block text-slate-800">{m.description}</span>
            <span className="block text-xs text-slate-500">{m.type_label}{m.counterparty ? ` · ${m.counterparty}` : ""}{m.reference ? ` · op. ${m.reference}` : ""}
              {m.orig_amount && m.orig_currency ? ` · orig. ${amount(m.orig_amount, m.orig_currency)} TC ${m.orig_fx_rate}` : ""}</span>
          </Td>
          <Td className="text-xs"><SourceLink m={m} />{m.synced && <span className="ml-1 rounded bg-slate-100 px-1 text-[10px] text-slate-600">sincronizado</span>}</Td>
          <Td className="text-right"><Signed m={m} /></Td>
          {running && <Td className="whitespace-nowrap text-right">{amount(m.running ?? 0, m.currency)}</Td>}
          <Td><StatusPill status={m.status} /></Td>
        </tr>
      ))}
    </Table>
  );
}

export function AccountsTable({ accounts }: { accounts: Account[] }) {
  return (
    <Table head={["Cuenta", "Tipo", "Sede", "Moneda", "Saldo", "Saldo PEN"]}>
      {accounts.map((a) => (
        <tr key={a.id}>
          <Td>
            <Link href={`/finanzas/tesoreria/cuentas/${a.code}`} className="font-medium text-sky-800 hover:underline">{a.name}</Link>
            <span className="block text-xs text-slate-500">{a.code}{a.account_number ? ` · ${a.bank} ${a.account_number}` : ""}{a.custodian ? ` · custodia ${a.custodian}` : ""}</span>
          </Td>
          <Td>{a.kind_label}{a.kind === "petty_cash" && a.fund_pct !== null && <span className="block text-xs text-slate-500">{a.fund_pct} % del fondo</span>}</Td>
          <Td>{a.sede}</Td>
          <Td>{a.currency}</Td>
          <Td className="whitespace-nowrap text-right font-medium">{amount(a.balance, a.currency)}</Td>
          <Td className="whitespace-nowrap text-right">{amount(a.balance_pen)}</Td>
        </tr>
      ))}
    </Table>
  );
}

export function Alerts({ rows }: { rows: { rule: string; tone: string; text: string; link: string }[] }) {
  if (!rows.length) return <p className="text-sm text-slate-500">Sin alertas.</p>;
  return (
    <ul className="space-y-2">
      {rows.map((a, i) => (
        <li key={i}>
          <Notice tone={a.tone === "danger" ? "danger" : a.tone === "warning" ? "warning" : "info"}>
            <span className="mr-1 font-mono text-xs">{a.rule}</span> <Link href={a.link} className="hover:underline">{a.text}</Link>
          </Notice>
        </li>
      ))}
    </ul>
  );
}

/** Línea vertical de saldo consolidado (28 días) como barras simples. */
export function BalanceSeries({ rows }: { rows: { label: string; value: number }[] }) {
  const max = Math.max(1, ...rows.map((r) => r.value));
  const min = Math.min(...rows.map((r) => r.value), max);
  const span = Math.max(1, max - min);
  return (
    <div>
      <div className="flex h-32 items-end gap-0.5" role="img" aria-label="Saldo consolidado de los últimos 28 días">
        {rows.map((r) => (
          <div key={r.label} title={`${r.label}: ${amount(r.value)}`} className="flex-1 rounded-t bg-sky-600" style={{ height: `${Math.max(4, Math.round(((r.value - min) / span) * 90) + 10)}%` }} />
        ))}
      </div>
      <div className="mt-1 flex justify-between text-[11px] text-slate-500"><span>{rows[0]?.label}</span><span>{rows[rows.length - 1]?.label}</span></div>
    </div>
  );
}
