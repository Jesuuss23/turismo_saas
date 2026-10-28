import type { Metadata } from "next";
import { AccountShell } from "@/components/site/AccountShell";
import { siteApi } from "@/lib/site";
import { fmtDate, money } from "@/lib/site-shared";

export const metadata: Metadata = { title: "Mi cuenta", robots: { index: false } };

type Res = { code: string; title: string; status: string; status_label: string; travel_from: string; travel_to: string; currency: string; total: string; pax: number; balance: string };

export default function AccountPage() {
  return (
    <AccountShell active="/sitio/cuenta">
      {async (_me, en) => {
        const rows = (await siteApi<{ data: Res[] }>("me/reservations")).data;
        return (
          <section>
            <h2 className="text-lg font-semibold">{en ? "My bookings" : "Mis reservas"}</h2>
            {rows.length === 0 ? <p className="mt-2 text-sm text-slate-600">{en ? "No bookings yet." : "Aún no tiene reservas."}</p> : (
              <ul className="mt-3 space-y-2" data-testid="my-reservations">
                {rows.map((r) => (
                  <li key={r.code} className="rounded-xl border border-slate-200 bg-white p-3 text-sm">
                    <div className="flex flex-wrap justify-between gap-2"><b>{r.code}</b><span className="rounded-full bg-slate-100 px-2 text-xs">{r.status_label}</span></div>
                    <p>{r.title}</p>
                    <p className="text-xs text-slate-500">{fmtDate(r.travel_from, en ? "en" : "es")} – {fmtDate(r.travel_to, en ? "en" : "es")} · {r.pax} pax · {money(r.total, r.currency)}{Number(r.balance) > 0 ? ` · ${en ? "balance" : "saldo"} ${money(r.balance, r.currency)}` : ""}</p>
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
