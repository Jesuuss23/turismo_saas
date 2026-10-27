"use client";

import { useState } from "react";

/** Relación anual para INDECOPI (CSV/XLSX/PDF); la exportación queda auditada en el backend. */
export function AnnualExport({ year, enabled }: { year: number; enabled: boolean }) {
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  async function run(format: string) {
    setBusy(format);
    setError(null);
    const res = await fetch(`/bff/file?path=${encodeURIComponent("/crm/claim-book/exports")}`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ year, format }) });
    setBusy(null);
    if (!res.ok) {
      setError((await res.json().catch(() => null))?.message ?? "No se pudo exportar");
      return;
    }
    const a = document.createElement("a");
    a.href = URL.createObjectURL(await res.blob());
    a.download = res.headers.get("Content-Disposition")?.match(/filename="(.+)"/)?.[1] ?? `libro-reclamaciones-${year}`;
    a.click();
    URL.revokeObjectURL(a.href);
  }
  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      {["CSV", "XLSX", "PDF"].map((f) => (
        <button key={f} type="button" onClick={() => run(f)} disabled={!enabled || !!busy} title={enabled ? undefined : "Requiere permiso reports.export"}
          className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">{busy === f ? "Exportando…" : f}</button>
      ))}
      {error && <span className="text-xs text-rose-700">{error}</span>}
    </span>
  );
}
