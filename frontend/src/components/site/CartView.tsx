"use client";

import { useCallback, useState, useTransition } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { rehold, removeLine, setPromo, updateLine } from "@/app/sitio/actions";
import { fmtDate, money, t, type Cart, type Locale } from "@/lib/site-shared";
import { HoldTimer } from "./HoldTimer";
import { trackEvent } from "./Analytics";

/** Carrito (spec 15 §2): líneas con hold y temporizador, re-bloqueo, quitar, cambiar viajeros, código promocional y resumen. */
export function CartView({ initial, locale }: { initial: Cart | null; locale: Locale }) {
  const d = t(locale);
  const en = locale === "en";
  const router = useRouter();
  const [cart, setCart] = useState(initial);
  const [error, setError] = useState<string | null>(null);
  const [pending, start] = useTransition();
  const apply = (r: { ok: true; data: Cart } | { ok: false; error: string }) => {
    if (r.ok) {
      setCart(r.data);
      setError(null);
    } else setError(r.error);
    router.refresh();
  };
  const expire = useCallback(() => router.refresh(), [router]);
  if (!cart || cart.lines.length === 0) {
    return (
      <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center">
        <p>{d.emptyCart}</p>
        <Link href="/sitio/tours" className="mt-3 inline-block rounded-lg bg-[#0b1f3a] px-4 py-2 text-sm text-white">{en ? "Explore tours" : "Ver tours"}</Link>
      </div>
    );
  }
  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
      <div className="min-w-0 space-y-3">
        <HoldTimer until={cart.hold_expires_at} locale={locale} onExpire={expire} />
        {cart.lines.map((l) => (
          <article key={l.line} className="rounded-2xl border border-slate-200 bg-white p-4" data-testid="cart-line">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div className="min-w-0">
                <Link href={`/sitio/tours/${l.product.slug}`} className="font-semibold hover:underline">{l.product.name}</Link>
                <p className="text-xs text-slate-500">{fmtDate(l.date, locale, { weekday: "short", day: "2-digit", month: "short", year: "numeric" })}{l.time ? ` · ${l.time}` : ""}{l.departure ? ` · ${l.departure}` : ""} · {l.season?.name}</p>
              </div>
              <p className="font-semibold">{money(l.total, cart.currency)}</p>
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-3 text-sm">
              <label className="flex items-center gap-1">{d.adults}
                <select aria-label={`${d.adults} ${l.code}`} value={l.adults} disabled={pending} onChange={(e) => start(async () => apply(await updateLine(l.line, { adults: Number(e.target.value) })))} className="rounded border border-slate-300 px-1 py-0.5">
                  {Array.from({ length: 12 }, (_, i) => i + 1).map((n) => <option key={n}>{n}</option>)}
                </select>
              </label>
              {l.children > 0 && <span>{l.children} {en ? "children" : "niños"}</span>}
              {l.infants > 0 && <span>{l.infants} {en ? "infants" : "infantes"}</span>}
              {l.options.map((o) => <span key={o.group} className="text-xs text-slate-500">{o.group}: {o.option}</span>)}
            </div>
            {l.extras.length > 0 && <ul className="mt-1 text-xs text-slate-600">{l.extras.map((x) => <li key={x.code}>+ {x.label}</li>)}</ul>}
            {Number(l.discount) > 0 && <p className="mt-1 text-xs text-emerald-700">{l.discount_label}: −{money(l.discount, cart.currency)}</p>}
            <div className="mt-2 flex flex-wrap items-center gap-3 text-xs">
              {l.hold ? (
                <span data-testid="hold-status" className={l.hold.status === "hold" ? "text-emerald-700" : "text-rose-700"}>
                  {l.hold.status === "hold" ? `🔒 ${en ? "Seats held" : "Cupos bloqueados"} ${l.hold.ref ?? ""}` : `${en ? "Hold expired" : "Bloqueo vencido"} ${l.hold.ref ?? ""}`}
                </span>
              ) : <span className="text-slate-500">{en ? "On request · confirmed within 24 h" : "Bajo solicitud · confirmación en 24 h"}</span>}
              {l.hold && l.hold.status !== "hold" && (
                <button type="button" disabled={pending} onClick={() => start(async () => apply(await rehold(l.line)))} className="rounded border border-slate-300 px-2 py-0.5">{en ? "Re-hold seats" : "Volver a bloquear"}</button>
              )}
              <button type="button" disabled={pending} onClick={() => start(async () => {
                trackEvent("remove_from_cart", { items: [{ item_name: l.product.name }] });
                apply(await removeLine(l.line));
              })} className="text-rose-700 hover:underline">{en ? "Remove" : "Quitar"}</button>
            </div>
          </article>
        ))}
      </div>
      <aside className="h-fit space-y-3 rounded-2xl border border-slate-200 bg-white p-4">
        <form className="flex gap-2" onSubmit={(e) => {
          e.preventDefault();
          const code = String(new FormData(e.currentTarget).get("code") ?? "").trim();
          start(async () => {
            const r = await setPromo(code || null);
            apply(r);
            if (r.ok) trackEvent("apply_promo", { coupon: code });
          });
        }}>
          <input name="code" defaultValue={cart.promo_code ?? ""} placeholder={en ? "Promo code" : "Código promocional"} aria-label={en ? "Promo code" : "Código promocional"} className="min-w-0 flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm uppercase" />
          <button disabled={pending} className="rounded-lg border border-[#0b1f3a] px-3 text-sm text-[#0b1f3a]">{en ? "Apply" : "Aplicar"}</button>
        </form>
        {cart.promo_applied && <p className="text-xs text-emerald-700" data-testid="promo-ok">✓ {cart.promo_code}</p>}
        {cart.promo_message && <p className="text-xs text-amber-800">{cart.promo_message}</p>}
        <dl className="space-y-1 text-sm">
          <div className="flex justify-between"><dt>{d.subtotal}</dt><dd>{money(cart.subtotal, cart.currency)}</dd></div>
          {Number(cart.extras) > 0 && <div className="flex justify-between"><dt>{d.extras}</dt><dd>{money(cart.extras, cart.currency)}</dd></div>}
          {Number(cart.discount) > 0 && <div className="flex justify-between text-emerald-700"><dt>{d.discount}</dt><dd>−{money(cart.discount, cart.currency)}</dd></div>}
          <div className="flex justify-between border-t border-slate-100 pt-1 text-base font-semibold"><dt>{d.total}</dt><dd data-testid="cart-total">{money(cart.total, cart.currency)}</dd></div>
        </dl>
        <p className="text-[11px] text-slate-500">{en ? "Final price calculated by our server at payment." : cart.note}</p>
        {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
        <Link href="/sitio/checkout" onClick={() => trackEvent("begin_checkout", { currency: cart.currency, value: cart.total })} className="block rounded-lg bg-amber-400 py-2.5 text-center font-semibold text-slate-900">{d.checkout}</Link>
      </aside>
    </div>
  );
}
