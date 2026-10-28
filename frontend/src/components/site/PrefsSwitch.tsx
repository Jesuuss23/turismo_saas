"use client";

import { useTransition } from "react";
import { setPrefs } from "@/app/sitio/actions";

/** Idioma ES/EN y moneda PEN/USD (cookies; los precios se recalculan en el servidor con el TC del día). */
export function PrefsSwitch({ locale, currency }: { locale: "es" | "en"; currency: "PEN" | "USD" }) {
  const [pending, start] = useTransition();
  return (
    <div className="flex items-center gap-1 text-xs" aria-busy={pending}>
      <label className="sr-only" htmlFor="site-locale">Idioma</label>
      <select id="site-locale" aria-label="Idioma" value={locale} disabled={pending} onChange={(e) => start(() => setPrefs(e.target.value as "es" | "en", currency))}
        className="rounded border border-slate-200 bg-white px-1 py-1">
        <option value="es">ES</option>
        <option value="en">EN</option>
      </select>
      <label className="sr-only" htmlFor="site-currency">Moneda</label>
      <select id="site-currency" aria-label="Moneda" value={currency} disabled={pending} onChange={(e) => start(() => setPrefs(locale, e.target.value as "PEN" | "USD"))}
        className="rounded border border-slate-200 bg-white px-1 py-1">
        <option value="PEN">S/ PEN</option>
        <option value="USD">US$ USD</option>
      </select>
    </div>
  );
}
