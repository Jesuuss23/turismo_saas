import type { Metadata } from "next";
import Link from "next/link";
import { ApiError } from "@/lib/api";
import { siteApi } from "@/lib/site";
import { HandoffCheckoutForm } from "@/components/experience/HandoffCheckout";
import type { HandoffCheckout } from "@/lib/experience";

export const metadata: Metadata = { title: "Reserva", robots: { index: false } };

const fmt = (v: string | null, c: string | null) => `${c ?? ""} ${Number(v ?? 0).toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/**
 * Checkout del traspaso del widget (spec 17 §2, WHO-…): el servidor re-cotiza la selección guardada en una moneda de cobro (WG-03) y
 * firma el `quote_token`; el cliente completa el titular y se crea la reserva. El precio que vio el cliente queda como moneda de visualización.
 */
export default async function HandoffPage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  let h: HandoffCheckout | null = null;
  let error: string | null = null;
  try {
    h = (await siteApi<{ data: HandoffCheckout }>(`widget-handoffs/${encodeURIComponent(code)}`, { session: false })).data;
  } catch (e) {
    error = e instanceof ApiError ? (e.status === 404 ? "No encontramos esa reserva en curso." : e.body?.message ?? "No se pudo cargar la reserva.") : "No se pudo conectar con el servidor.";
  }
  const en = h?.lang === "en";
  if (!h) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-10">
        <p className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center" role="alert">{error} <Link href="/sitio/tours" className="text-sky-700 underline">Ver tours</Link></p>
      </div>
    );
  }
  const q = h.quote;
  const differs = h.display.currency && h.display.currency !== h.charge_currency;
  return (
    <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
      <div className="min-w-0 space-y-4">
        <h1 className="text-2xl font-semibold">{en ? "Complete your booking" : "Complete su reserva"}</h1>
        <p className="text-sm text-slate-600">{en ? "Reference" : "Referencia"} <span className="font-mono font-medium" data-handoff-code>{h.code}</span>{h.status === "convertido" ? ` · ${en ? "already booked" : "ya reservada"}` : ""}</p>
        {h.status === "convertido"
          ? <p className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{en ? "This selection was already booked. Check your email for details." : "Esta selección ya se reservó. Revise su correo con el detalle."}</p>
          : <HandoffCheckoutForm h={h} en={en} />}
      </div>
      <aside className="h-fit rounded-xl border border-slate-200 bg-white p-4 text-sm" data-checkout-summary>
        <p className="font-semibold">{h.product?.name}</p>
        <p className="text-slate-600">{h.date} · {h.pax ? `${h.pax.adt} ADT${h.pax.chd ? ` + ${h.pax.chd} CHD` : ""}${h.pax.inf ? ` + ${h.pax.inf} INF` : ""}` : ""}</p>
        <ul className="mt-3 divide-y divide-slate-100">
          {q.lines.filter((l) => l.kind !== "info" && !l.removed).map((l) => <li key={l.key} className="flex justify-between gap-2 py-1"><span className="min-w-0">{l.label}{l.qty > 1 ? ` ×${l.qty}` : ""}</span><span className="whitespace-nowrap">{fmt(l.amount, q.currency)}</span></li>)}
          {q.adjustments.map((a) => <li key={a.key} className="flex justify-between py-1"><span>{a.label}</span><span>{fmt(a.amount, q.currency)}</span></li>)}
        </ul>
        {q.rooms > 0 && <p className="mt-1 text-xs text-slate-500">{q.rooms} {en ? "rooms" : "habitaciones"} · {q.nights} {en ? "nights" : "noches"}</p>}
        <dl className="mt-3 space-y-1 border-t border-slate-100 pt-2">
          <div className="flex justify-between font-semibold"><dt>Total</dt><dd data-charge-total>{fmt(q.total, q.currency)}</dd></div>
          {differs && <div className="flex justify-between text-xs text-slate-500"><dt>{en ? "Shown in the widget" : "Visto en el widget"}</dt><dd>{fmt(h.display.total, h.display.currency)}</dd></div>}
          <div className="flex justify-between"><dt>{en ? "Pay now" : "Paga ahora"}{q.payment_mode === "adelanto" ? ` (${Number(q.deposit_pct)} %)` : ""}</dt><dd>{fmt(q.deposit, q.currency)}</dd></div>
          {Number(q.balance) > 0 && <div className="flex justify-between text-slate-600"><dt>{en ? "Balance" : "Saldo"}{q.balance_due ? ` · ${q.balance_due}` : ""}</dt><dd>{fmt(q.balance, q.currency)}</dd></div>}
        </dl>
        {differs && <p className="mt-2 text-xs text-slate-500">{en ? `Charged in ${q.currency}; ${h.display.currency} is for reference only.` : `Se cobra en ${q.currency}; ${h.display.currency} es referencial.`}</p>}
      </aside>
    </div>
  );
}
