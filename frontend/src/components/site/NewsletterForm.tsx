"use client";

import { useState, useTransition } from "react";
import { subscribe } from "@/app/sitio/actions";

/** Newsletter con casilla de consentimiento (Ley 29733, SIT-BR-25) y doble opt-in. */
export function NewsletterForm({ locale, dark = false }: { locale: "es" | "en"; dark?: boolean }) {
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [pending, start] = useTransition();
  return (
    <form
      className="mt-2 space-y-2"
      onSubmit={(e) => {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        start(async () => {
          const r = await subscribe(String(f.get("email") ?? ""), f.get("consent") === "on");
          setMsg(r.ok ? { ok: true, text: r.data.message } : { ok: false, text: r.error });
        });
      }}
    >
      <div className="flex gap-2">
        <input name="email" type="email" required placeholder={locale === "en" ? "Your email" : "Su correo"} aria-label="Correo para el boletín"
          className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900" />
        <button disabled={pending} className="shrink-0 rounded-lg bg-amber-400 px-3 py-2 text-sm font-semibold text-slate-900 disabled:opacity-60">
          {locale === "en" ? "Subscribe" : "Suscribirme"}
        </button>
      </div>
      <label className={`flex items-start gap-2 text-xs ${dark ? "text-slate-300" : "text-slate-600"}`}>
        <input type="checkbox" name="consent" className="mt-0.5" />
        <span>{locale === "en"
          ? "I agree to receive news and offers by email (Law 29733). I can unsubscribe at any time."
          : "Acepto recibir novedades y ofertas por correo (Ley 29733). Puedo darme de baja cuando quiera."}</span>
      </label>
      {msg && <p role="status" className={`text-xs ${msg.ok ? (dark ? "text-emerald-300" : "text-emerald-700") : (dark ? "text-rose-300" : "text-rose-700")}`}>{msg.text}</p>}
    </form>
  );
}
