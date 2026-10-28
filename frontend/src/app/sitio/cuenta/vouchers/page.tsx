import type { Metadata } from "next";
import { AccountShell } from "@/components/site/AccountShell";
import { siteApi } from "@/lib/site";
import { fmtDate } from "@/lib/site-shared";

export const metadata: Metadata = { title: "Vouchers", robots: { index: false } };

type V = { code: string; service: string; date: string; reservation: string; pax: number; version: number; status_label: string };

export default function VouchersPage() {
  return (
    <AccountShell active="/sitio/cuenta/vouchers">
      {async (_me, en) => {
        const rows = (await siteApi<{ data: V[] }>("me/vouchers")).data;
        return (
          <section>
            <h2 className="text-lg font-semibold">Vouchers</h2>
            {rows.length === 0 ? <p className="mt-2 text-sm text-slate-600">{en ? "Vouchers are issued when your booking is fully paid." : "Los vouchers se emiten cuando su reserva queda pagada."}</p> : (
              <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                {rows.map((v) => (
                  <li key={v.code} className="rounded-xl border border-dashed border-emerald-400 bg-emerald-50 p-3 text-sm">
                    <p className="font-mono font-semibold">{v.code}</p><p>{v.service}</p>
                    <p className="text-xs text-slate-600">{fmtDate(v.date, en ? "en" : "es")} · {v.reservation} · {v.pax} pax · v{v.version} · {v.status_label}</p>
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
