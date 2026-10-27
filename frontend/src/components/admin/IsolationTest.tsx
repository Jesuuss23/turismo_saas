"use client";

import { useState, useTransition } from "react";
import { callApi } from "@/app/(app)/crm/actions";
import { btnGhostCls } from "@/components/crm/ui";

type Result = { passed: boolean; log: { t: string; line: string; ok: boolean }[] };

/** E2E-08: desde el tenant A se intenta leer un registro del tenant B; RLS debe ocultarlo y el intento queda auditado. */
export function IsolationTest({ tenants }: { tenants: { value: string; label: string }[] }) {
  const [a, setA] = useState(tenants[0]?.value ?? "");
  const [b, setB] = useState(tenants[1]?.value ?? "");
  const [result, setResult] = useState<Result | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pending, start] = useTransition();

  const run = () => start(async () => {
    setError(null);
    const r = await callApi("/platform/tenants/isolation-test", "POST", { tenant_a: a, tenant_b: b });
    if (r.error) setError(r.error);
    else setResult(r.data as Result);
  });

  return (
    <div className="space-y-3 text-sm">
      <div className="flex flex-wrap items-end gap-2">
        <label className="block">Desde<select value={a} onChange={(e) => setA(e.target.value)} className="mt-1 block rounded-md border border-slate-300 px-2 py-2">{tenants.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}</select></label>
        <label className="block">Leer datos de<select value={b} onChange={(e) => setB(e.target.value)} className="mt-1 block rounded-md border border-slate-300 px-2 py-2">{tenants.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}</select></label>
        <button type="button" onClick={run} disabled={pending || a === b} className={btnGhostCls}>{pending ? "Probando…" : "Ejecutar prueba"}</button>
      </div>
      {error && <p role="alert" className="text-rose-700">{error}</p>}
      {result && (
        <div role="status">
          <p className={`font-medium ${result.passed ? "text-emerald-800" : "text-rose-700"}`}>{result.passed ? "Aislamiento correcto: el tenant B no es visible." : "Fallo de aislamiento."}</p>
          <ol className="mt-2 space-y-0.5 rounded bg-slate-900 p-3 font-mono text-xs text-slate-100">
            {result.log.map((l, i) => <li key={i} className={l.ok ? "" : "text-rose-300"}>{l.t} {l.line}</li>)}
          </ol>
        </div>
      )}
    </div>
  );
}
