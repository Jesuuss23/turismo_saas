import type { Metadata } from "next";
import { AccountShell } from "@/components/site/AccountShell";
import { PayReceivable } from "@/components/site/AccountForms";
import { siteApi } from "@/lib/site";
import { fmtDate, money } from "@/lib/site-shared";

export const metadata: Metadata = { title: "Pagos pendientes", robots: { index: false } };

type R = { id: string; code: string; concept: string; reservation: string; currency: string; amount: string; paid: string; balance: string; due_date: string; status_label: string; overdue: boolean; pending_validation: string; payable: string; can_pay: boolean };

export default function PaymentsPage() {
  return (
    <AccountShell active="/sitio/cuenta/pagos">
      {async (_me, en) => {
        const rows = (await siteApi<{ data: R[] }>("me/receivables")).data;
        return (
          <section>
            <h2 className="text-lg font-semibold">{en ? "Pending payments" : "Pagos pendientes"}</h2>
            {rows.length === 0 ? <p className="mt-2 text-sm text-slate-600">{en ? "You're all paid up." : "No tiene pagos pendientes."}</p> : (
              <ul className="mt-3 space-y-2" data-testid="receivables">
                {rows.map((r) => (
                  <li key={r.id} className="rounded-xl border border-slate-200 bg-white p-3 text-sm" data-testid="receivable">
                    <div className="flex flex-wrap justify-between gap-2"><b>{r.code}</b><span className={r.overdue ? "text-rose-700" : "text-slate-600"}>{en ? "Due" : "Vence"} {fmtDate(r.due_date, en ? "en" : "es")}{r.overdue ? (en ? " · overdue" : " · vencida") : ""}</span></div>
                    <p>{r.concept}</p>
                    <p className="text-xs text-slate-500">{r.reservation} · {en ? "Balance" : "Saldo"} {money(r.balance, r.currency)}{Number(r.pending_validation) > 0 ? ` · ${en ? "under validation" : "en validación"} ${money(r.pending_validation, r.currency)}` : ""}</p>
                    <div className="mt-2">{r.can_pay ? <PayReceivable id={r.id} amount={money(r.payable, r.currency)} en={en} /> : <span className="text-xs text-amber-800">{en ? "Payment under validation by Finance" : "Pago en validación por Finanzas"}</span>}</div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        );
      }}
    </AccountShell>
  );
}
