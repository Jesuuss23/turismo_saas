"use client";

import { useState, useTransition } from "react";
import { callApi } from "@/app/(app)/crm/actions";
import type { QuoteLine } from "@/lib/booking";

type Result = { validation: { ok: boolean; errors: { rule: string; message: string }[]; ages: { age: number; type: string }[] }; lines: QuoteLine[]; subtotal: string; currency: string };

/** Simulador de pasajeros (`validatePax` + líneas base de `quote` + clasificador de edad). Usable en modo consulta. */
export function PaxSimulator({ refCode }: { refCode: string }) {
  const [res, setRes] = useState<Result | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pending, start] = useTransition();
  const input = "w-16 rounded-md border border-slate-300 px-2 py-1.5 text-sm";

  return (
    <form className="space-y-3" onSubmit={(e) => {
      e.preventDefault();
      const f = new FormData(e.currentTarget);
      const ages = String(f.get("ages") ?? "").split(/[,\s]+/).filter(Boolean).map(Number).filter((n) => !Number.isNaN(n));
      start(async () => {
        const r = await callApi(`/catalog/products/${refCode}/pax-rules/simulate`, "POST", { adt: Number(f.get("adt")), chd: Number(f.get("chd")), inf: Number(f.get("inf")), ages });
        setError(r?.error ?? null);
        setRes((r?.data as Result) ?? null);
      });
    }}>
      <div className="flex flex-wrap items-end gap-2">
        <label className="text-sm">Adultos <input name="adt" type="number" min={0} defaultValue={2} className={input} /></label>
        <label className="text-sm">Niños <input name="chd" type="number" min={0} defaultValue={1} className={input} /></label>
        <label className="text-sm">Infantes <input name="inf" type="number" min={0} defaultValue={0} className={input} /></label>
        <label className="text-sm">Edades <input name="ages" placeholder="35, 7, 2" className="w-32 rounded-md border border-slate-300 px-2 py-1.5 text-sm" /></label>
        <button type="submit" disabled={pending} className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-slate-50">{pending ? "Calculando…" : "Simular"}</button>
      </div>
      {error && <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">{error}</p>}
      {res && (
        <div className="space-y-2 text-sm" data-simulation>
          {res.validation.ok
            ? <p className="rounded-md bg-emerald-50 px-3 py-2 text-emerald-800">Combinación válida.</p>
            : <ul className="list-disc rounded-md bg-rose-50 py-2 pl-8 pr-3 text-rose-800" data-pax-errors>{res.validation.errors.map((e, i) => <li key={i}>{e.message}</li>)}</ul>}
          {res.validation.ages.length > 0 && <p className="text-xs text-slate-600">Edades: {res.validation.ages.map((a) => `${a.age} → ${a.type}`).join(" · ")}</p>}
          <ul className="text-xs">{res.lines.map((l) => <li key={l.key}>{l.label}: {l.qty} × {res.currency} {l.unit} = {l.amount}</li>)}</ul>
          <p className="text-xs font-medium">Subtotal base: {res.currency} {res.subtotal}</p>
        </div>
      )}
    </form>
  );
}
