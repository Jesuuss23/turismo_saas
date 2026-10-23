"use client";

import Link from "@/components/Link";
import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { runCrm } from "../actions";
import { STAGES, money, date, type Opportunity } from "@/lib/crm";

type Column = { stage: string; name: string; count: number; total_pen: number; items: Opportunity[] };
type Pending = { opp: Opportunity; stage: string } | null;

/** Kanban con drag & drop (escritorio) y menú ⋯ (móvil). Perder abre el modal de motivo (CRM-E2E-02). */
export function Kanban({ columns, canManage, lostReasons }: { columns: Column[]; canManage: boolean; lostReasons: { value: string; label: string; requires_detail: boolean }[] }) {
  const router = useRouter();
  const [busy, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);
  const [warning, setWarning] = useState<string | null>(null);
  const [pendingLost, setPendingLost] = useState<Pending>(null);
  const [menu, setMenu] = useState<string | null>(null);

  function move(opp: Opportunity, stage: string, extra: Record<string, string> = {}) {
    if (stage === opp.stage) return;
    if (stage === "perdida" && !extra.lost_reason_code) return setPendingLost({ opp, stage });
    const reopening = opp.is_closed && !["ganada", "perdida"].includes(stage);
    if (reopening && !window.confirm(`¿Reabrir ${opp.code}?`)) return;
    if (stage === "ganada" && !window.confirm(`¿Marcar ${opp.code} como ganada?`)) return;

    const fd = new FormData();
    fd.set("_path", `/crm/opportunities/${opp.code}/stage`);
    fd.set("_ifmatch", String(opp.lock_version));
    fd.set("stage", stage);
    if (reopening) fd.set("confirm_reopen", "true");
    Object.entries(extra).forEach(([k, v]) => fd.set(k, v));
    startTransition(async () => {
      const res = await runCrm(undefined, fd);
      setError(res?.error ?? null);
      setWarning(res?.warnings?.join(" ") || null);
      setPendingLost(null);
      setMenu(null);
      router.refresh();
    });
  }

  return (
    <>
      {error && <p className="mb-3 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200">{error}</p>}
      {warning && <p className="mb-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">{warning}</p>}
      <div className={`grid gap-3 md:grid-cols-3 xl:grid-cols-6 ${busy ? "opacity-60" : ""}`}>
        {columns.map((col) => (
          <section key={col.stage} aria-label={col.name} data-stage={col.stage}
            className="min-w-0 rounded-lg border border-slate-200 bg-slate-100/60 p-2"
            onDragOver={(e) => canManage && e.preventDefault()}
            onDrop={(e) => {
              const id = e.dataTransfer.getData("text/plain");
              const opp = columns.flatMap((c) => c.items).find((o) => o.id === id);
              if (opp) move(opp, col.stage);
            }}>
            <header className="mb-2 px-1">
              <p className="text-sm font-semibold text-slate-800">{col.name} <span className="font-normal text-slate-500">({col.count})</span></p>
              <p className="text-xs text-slate-500">{money(col.total_pen)}</p>
            </header>
            <ul className="space-y-2">
              {col.items.map((o) => (
                <li key={o.id} draggable={canManage} onDragStart={(e) => e.dataTransfer.setData("text/plain", o.id)}
                  className="relative rounded-md border border-slate-200 bg-white p-2 text-sm shadow-sm" data-code={o.code}>
                  <div className="flex items-start justify-between gap-1">
                    <Link href={`/crm/oportunidades/${o.code}`} className="font-medium text-sky-800 hover:underline">{o.title}</Link>
                    {canManage && (
                      <button aria-label={`Mover ${o.code}`} className="rounded px-1 text-slate-500 hover:bg-slate-100" onClick={() => setMenu(menu === o.id ? null : o.id)}>⋯</button>
                    )}
                  </div>
                  <p className="text-xs text-slate-500">{o.code} · {o.client.name}</p>
                  <p className="mt-1 text-xs"><strong>{money(o.amount, o.currency)}</strong> · {o.probability}% · cierre {date(o.expected_close_date)}</p>
                  <p className="text-xs text-slate-500">{o.owner?.name}</p>
                  {menu === o.id && (
                    <div role="menu" className="absolute right-1 top-7 z-10 w-44 rounded-md border border-slate-200 bg-white py-1 shadow-lg">
                      {STAGES.filter((s) => s.code !== o.stage).map((s) => (
                        <button key={s.code} role="menuitem" className="block w-full px-3 py-1.5 text-left text-sm hover:bg-slate-50" onClick={() => move(o, s.code)}>Mover a {s.name}</button>
                      ))}
                    </div>
                  )}
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>

      {pendingLost && (
        <div role="dialog" aria-modal="true" aria-labelledby="lost-title" className="fixed inset-0 z-20 flex items-center justify-center bg-slate-900/40 p-4">
          <form className="w-full max-w-md space-y-3 rounded-lg bg-white p-5 shadow-xl" onSubmit={(e) => {
            e.preventDefault();
            const fd = new FormData(e.currentTarget);
            move(pendingLost.opp, "perdida", { lost_reason_code: String(fd.get("lost_reason_code")), lost_detail: String(fd.get("lost_detail") ?? "") });
          }}>
            <h2 id="lost-title" className="text-lg font-semibold">Marcar {pendingLost.opp.code} como perdida</h2>
            <label className="block text-sm"><span className="mb-1 block font-medium">Motivo *</span>
              <select name="lost_reason_code" required className="w-full rounded-md border border-slate-300 px-3 py-2">
                <option value="">Seleccione</option>
                {lostReasons.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
              </select>
            </label>
            <label className="block text-sm"><span className="mb-1 block font-medium">Detalle (obligatorio para “Otro”)</span>
              <textarea name="lost_detail" rows={3} className="w-full rounded-md border border-slate-300 px-3 py-2" />
            </label>
            <div className="flex justify-end gap-2">
              <button type="button" className="rounded-md border border-slate-300 px-3 py-2 text-sm" onClick={() => setPendingLost(null)}>Cancelar</button>
              <button className="rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white">Marcar como perdida</button>
            </div>
          </form>
        </div>
      )}
    </>
  );
}
