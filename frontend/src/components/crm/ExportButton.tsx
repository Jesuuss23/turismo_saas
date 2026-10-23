"use client";

import { useState } from "react";

/** Exportación CSV auditada (POST /exports vía la ruta /bff/export de Next). */
export function ExportButton({ resource, filters = {} }: { resource: string; filters?: Record<string, string | undefined> }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function run() {
    setBusy(true);
    setError(null);
    const res = await fetch("/bff/export", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ resource, filters }) });
    setBusy(false);
    if (!res.ok) {
      setError((await res.json().catch(() => null))?.message ?? "No se pudo exportar");
      return;
    }
    const blob = await res.blob();
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = res.headers.get("Content-Disposition")?.match(/filename="(.+)"/)?.[1] ?? "export.csv";
    a.click();
    URL.revokeObjectURL(a.href);
  }

  return (
    <span className="inline-flex items-center gap-2">
      <button onClick={run} disabled={busy} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">
        {busy ? "Exportando…" : "Exportar CSV"}
      </button>
      {error && <span className="text-xs text-rose-700">{error}</span>}
    </span>
  );
}
