import type { Metadata } from "next";
import Link from "next/link";
import { customer, prefs, siteGet, siteInfo } from "@/lib/site";
import { fmtDate, money, type Order } from "@/lib/site-shared";
import { TrackOnView } from "@/components/site/TrackOnView";

export const metadata: Metadata = { title: "Confirmación", robots: { index: false } };

const TONE: Record<string, string> = { confirmado: "bg-emerald-100 text-emerald-800", pago_por_validar: "bg-amber-100 text-amber-900", pago_rechazado: "bg-rose-100 text-rose-800" };

/** Confirmación del pedido (spec 15 §2): solo desde el navegador que compró (token httpOnly) o por el cliente dueño. */
export default async function ConfirmationPage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  const { locale } = await prefs();
  const en = locale === "en";
  const [o, me, site] = await Promise.all([siteGet<Order>(`orders/${encodeURIComponent(code)}`, { order: code }), customer(), siteInfo()]);
  if (!o) {
    return (
      <div className="mx-auto max-w-xl px-4 py-12 text-center">
        <h1 className="text-xl font-semibold">{en ? "Order not available" : "Pedido no disponible"}</h1>
        <p className="mt-2 text-sm text-slate-600">{en ? "Open it from the browser you bought with, or look it up with your email and code." : "Ábralo desde el navegador con el que compró o búsquelo con su correo y código."}</p>
        <Link href="/sitio/cuenta/mi-reserva" className="mt-4 inline-block rounded-lg bg-[#0b1f3a] px-4 py-2 text-sm text-white">{en ? "Manage my booking" : "Gestionar mi reserva"}</Link>
      </div>
    );
  }
  const reservations = o.lines.map((l) => l.reservation?.code).filter(Boolean);
  return (
    <div className="mx-auto max-w-4xl px-4 py-8">
      <TrackOnView event="page_view" params={{ page_type: "confirmation", transaction_id: o.code }} />
      <div className="rounded-2xl border border-slate-200 bg-white p-5">
        <p className="text-sm text-slate-500">{en ? "Order" : "Pedido"} <b data-testid="order-code">{o.code}</b> · {fmtDate(o.ordered_at, locale)}</p>
        <h1 className="mt-1 text-2xl font-bold">
          {o.status === "confirmado" ? (en ? "Your booking is confirmed!" : "¡Su reserva está confirmada!") : o.status === "pago_por_validar" ? (en ? "Payment under validation" : "Pago en validación") : o.status_label}
        </h1>
        <p className="mt-1 text-sm">{en ? "Booking" : "Reserva"} <b data-testid="reservation-code">{reservations.join(", ")}</b> · <span className={`rounded-full px-2 py-0.5 text-xs ${TONE[o.status] ?? "bg-slate-100"}`} data-testid="order-status">{o.status_label}</span></p>
        <p className="mt-1 text-xs text-slate-500">{en ? "We sent the details to" : "Enviamos el detalle a"} {o.buyer.email}</p>
      </div>

      <section className="mt-6">
        <h2 className="font-semibold">{en ? "What happens now" : "Qué pasa ahora"}</h2>
        <ol className="mt-2 space-y-1 text-sm" data-testid="next-steps">
          {o.next_steps.map((s, i) => <li key={i} className={s.done ? "text-emerald-700" : "text-slate-700"}>{s.done ? "✓" : "○"} {s.label}</li>)}
        </ol>
      </section>

      <section className="mt-6 space-y-3">
        {o.lines.map((l) => (
          <article key={l.line} className="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
            <div className="flex flex-wrap justify-between gap-2">
              <p className="font-semibold">{l.product}</p>
              <p>{money(l.total, o.currency)}</p>
            </div>
            <p className="text-xs text-slate-500">{fmtDate(l.date, locale, { weekday: "long", day: "numeric", month: "long", year: "numeric" })}{l.time ? ` · ${l.time}` : ""} · {l.meeting_point} · {l.adults + l.children + l.infants} pax</p>
            <ul className="mt-2 space-y-1" data-testid="services">
              {l.services.map((s) => <li key={s.code} className="flex flex-wrap justify-between gap-2"><span>{s.name}</span><span className="text-xs text-slate-600">{s.status_label}{s.confirmation_code ? ` · ${s.confirmation_code}` : ""}</span></li>)}
            </ul>
          </article>
        ))}
      </section>

      {o.vouchers.length > 0 && (
        <section className="mt-6">
          <h2 className="font-semibold">Vouchers</h2>
          <ul className="mt-2 grid gap-2 sm:grid-cols-2" data-testid="vouchers">
            {o.vouchers.map((v) => (
              <li key={v.code} className="rounded-xl border border-dashed border-emerald-400 bg-emerald-50 p-3 text-sm">
                <p className="font-mono font-semibold">{v.code}</p>
                <p>{v.service}</p>
                <p className="text-xs text-slate-600">{fmtDate(v.date, locale)} · {v.pax} pax · v{v.version} · {v.reservation}</p>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="mt-6 grid gap-4 sm:grid-cols-2">
        <div className="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
          <h2 className="font-semibold">{en ? "Payment" : "Pago"}</h2>
          <dl className="mt-2 space-y-1">
            <div className="flex justify-between"><dt>Total</dt><dd>{money(o.total, o.currency)}</dd></div>
            <div className="flex justify-between"><dt>{en ? "Paid" : "Pagado"}</dt><dd>{money(o.paid, o.currency)}</dd></div>
            {Number(o.pending_validation) > 0 && <div className="flex justify-between text-amber-800"><dt>{en ? "Under validation" : "En validación"}</dt><dd>{money(o.pending_validation, o.currency)}</dd></div>}
            <div className="flex justify-between font-semibold"><dt>{en ? "Balance" : "Saldo"}</dt><dd>{money(o.balance, o.currency)}</dd></div>
            <div className="flex justify-between text-xs text-slate-500"><dt>{en ? "Mode" : "Modalidad"}</dt><dd>{o.pay_mode === "anticipo" ? `${en ? "Deposit" : "Anticipo"} ${Number(o.deposit_pct)} %` : (en ? "Full payment" : "Pago total")}{o.balance_due_date ? ` · ${en ? "balance due" : "saldo vence"} ${fmtDate(o.balance_due_date, locale)}` : ""}</dd></div>
            {o.billing && <div className="flex justify-between text-xs text-slate-500"><dt>{o.billing.type}</dt><dd>{o.billing.doc_type} {o.billing.doc_number}</dd></div>}
          </dl>
        </div>
        <div className="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
          <h2 className="font-semibold">{en ? "Need help?" : "¿Necesita ayuda?"}</h2>
          <p className="mt-2">WhatsApp {site.whatsapp} · {en ? "Emergencies" : "Emergencias"} {site.emergency}</p>
          {!me && <Link href={`/sitio/cuenta/registro?email=${encodeURIComponent(o.buyer.email)}`} className="mt-3 inline-block rounded-lg bg-[#0b1f3a] px-3 py-2 text-white">{en ? "Create your account with this email" : "Cree su cuenta con este correo"}</Link>}
          {me && <Link href="/sitio/cuenta" className="mt-3 inline-block rounded-lg bg-[#0b1f3a] px-3 py-2 text-white">{en ? "Go to my account" : "Ir a Mi cuenta"}</Link>}
        </div>
      </section>
    </div>
  );
}
