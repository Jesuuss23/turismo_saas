"use client";

import { useState, useTransition } from "react";
import Link from "next/link";
import { toggleWish } from "@/app/sitio/actions";
import { trackEvent } from "./Analytics";

/** ♥ Lista de deseos: requiere cuenta (se guarda en el cliente CRM). */
export function WishButton({ slug, logged, locale, initial = false }: { slug: string; logged: boolean; locale: "es" | "en"; initial?: boolean }) {
  const [on, setOn] = useState(initial);
  const [pending, start] = useTransition();
  const label = locale === "en" ? (on ? "Saved" : "Save") : on ? "Guardado" : "Guardar";
  if (!logged) return <Link href="/sitio/cuenta/ingresar" className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm">♡ {label}</Link>;
  return (
    <button type="button" disabled={pending} aria-pressed={on} className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm"
      onClick={() => start(async () => {
        const r = await toggleWish(slug, !on);
        if (r.ok) {
          setOn(!on);
          trackEvent(on ? "remove_from_wishlist" : "add_to_wishlist", { item_id: slug });
        }
      })}>
      {on ? "♥" : "♡"} {label}
    </button>
  );
}
