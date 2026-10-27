"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { callApi } from "@/app/(app)/crm/actions";
import { btnCls, inputCls } from "@/components/crm/ui";

/**
 * Formulario embebido de la pasarela en modo sandbox (PCI DSS SAQ-A): el número y el CVV no salen del navegador.
 * Se tokeniza aquí como `tok_sandbox_{marca}_{last4}_{aleatorio}` y solo el token llega al backend.
 */
export function CardForm({ holder }: { holder: string }) {
  const router = useRouter();
  const [pending, start] = useTransition();
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [idem, setIdem] = useState(() => crypto.randomUUID());

  function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const digits = String(f.get("number") ?? "").replace(/\D/g, "");
    if (digits.length < 13 || digits.length > 19 || !luhn(digits)) return setMsg({ ok: false, text: "Número de tarjeta inválido." });
    if (!/^\d{3,4}$/.test(String(f.get("cvv") ?? ""))) return setMsg({ ok: false, text: "CVV inválido." });
    const [month, year] = String(f.get("exp") ?? "").split("/").map((v) => Number(v.trim()));
    const brand = digits.startsWith("4") ? "visa" : /^5[1-5]/.test(digits) ? "mastercard" : /^3[47]/.test(digits) ? "amex" : "card";
    const token = `tok_sandbox_${brand}_${digits.slice(-4)}_${Math.random().toString(36).slice(2, 12)}`;
    start(async () => {
      const r = await callApi("/billing/payment-methods/default", "PUT",
        { token, brand, exp_month: month, exp_year: year && year < 100 ? 2000 + year : year, holder: f.get("holder") }, { "Idempotency-Key": idem });
      setMsg(r.error ? { ok: false, text: [r.error, ...Object.values(r.fieldErrors ?? {}).flat()].join(" ") } : { ok: true, text: (r.data as { message?: string })?.message ?? "Tarjeta registrada." });
      if (!r.error) {
        setIdem(crypto.randomUUID());
        (e.target as HTMLFormElement).reset();
        router.refresh();
      }
    });
  }

  return (
    <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2" data-card-form>
      <label className="block text-sm sm:col-span-2"><span className="mb-1 block font-medium text-slate-700">Número de tarjeta</span>
        <input name="number" inputMode="numeric" autoComplete="cc-number" placeholder="4111 1111 1111 1111" required className={inputCls} /></label>
      <label className="block text-sm sm:col-span-2"><span className="mb-1 block font-medium text-slate-700">Titular</span>
        <input name="holder" autoComplete="cc-name" defaultValue={holder} required className={inputCls} /></label>
      <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Vencimiento (MM/AA)</span>
        <input name="exp" placeholder="12/29" pattern="\d{1,2}\s*/\s*\d{2,4}" required className={inputCls} /></label>
      <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">CVV</span>
        <input name="cvv" inputMode="numeric" autoComplete="cc-csc" maxLength={4} required className={inputCls} /></label>
      <div className="sm:col-span-2">
        <button type="submit" disabled={pending} className={btnCls}>{pending ? "Procesando…" : "Guardar tarjeta"}</button>
        {msg && <p className={`mt-2 rounded-md px-3 py-2 text-sm ring-1 ${msg.ok ? "bg-emerald-50 text-emerald-800 ring-emerald-200" : "bg-rose-50 text-rose-800 ring-rose-200"}`}>{msg.text}</p>}
      </div>
    </form>
  );
}

function luhn(digits: string): boolean {
  let sum = 0;
  for (let i = 0; i < digits.length; i++) {
    let d = Number(digits[digits.length - 1 - i]);
    if (i % 2 === 1) d = d * 2 > 9 ? d * 2 - 9 : d * 2;
    sum += d;
  }
  return sum % 10 === 0;
}
