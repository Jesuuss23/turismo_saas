"use client";

import { useEffect, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { callApi } from "@/app/(app)/crm/actions";
import type { ResourceCard, Rule } from "@/lib/ops";
import { RESOURCE_TYPE } from "@/lib/ops";
import { btnCls, inputCls } from "@/components/crm/ui";
import { RuleList } from "./ui";

type Props = {
  assignmentId: string; lockVersion: number; close: string;
  current: { guide_id: string | null; driver_id: string | null; vehicle_id: string | null };
  resources: ResourceCard[];
};

/** Asignar/reasignar recursos con validación en vivo (dry-run) y advertencias asumidas (OPS-BR-28). */
export function AssignForm({ assignmentId, lockVersion, close, current, resources }: Props) {
  const router = useRouter();
  const [ids, setIds] = useState(current);
  const [check, setCheck] = useState<{ blocks: Rule[]; warnings: Rule[] }>({ blocks: [], warnings: [] });
  const [ack, setAck] = useState(false);
  const [notify, setNotify] = useState({ push: true, whatsapp: false });
  const [error, setError] = useState<string | null>(null);
  const [pending, start] = useTransition();

  useEffect(() => {
    let active = true;
    callApi(`/ops/assignments/${assignmentId}/validate`, "POST", ids).then((r) => {
      if (active && r.ok) setCheck(r.data as { blocks: Rule[]; warnings: Rule[] });
    });
    return () => { active = false; };
  }, [assignmentId, ids]);

  const submit = () => start(async () => {
    setError(null);
    const r = await callApi(`/ops/assignments/${assignmentId}/resources`, "PUT", { ...ids, ack_warnings: ack, notify }, { "If-Match": String(lockVersion) });
    if (r.error) { setError(r.error); return; }
    router.push(close, { scroll: false });
    router.refresh();
  });

  const select = (type: "guide" | "driver" | "vehicle") => {
    const key = `${type}_id` as const;
    return (
      <label className="block">
        <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">{RESOURCE_TYPE[type]}</span>
        <select aria-label={RESOURCE_TYPE[type]} value={ids[key] ?? ""} onChange={(e) => setIds({ ...ids, [key]: e.target.value || null })} className={inputCls}>
          <option value="">— Sin {RESOURCE_TYPE[type].toLowerCase()} —</option>
          {resources.filter((r) => r.type === type).map((r) => (
            <option key={r.id} value={r.id}>
              {r.code} · {r.name}{r.capacity ? ` (${r.capacity} pax)` : ""}{r.languages ? ` · ${r.languages.join("/")}` : ""}{r.status !== "disponible" ? ` · ${r.status}` : ""}{r.document?.alert ? ` · ${r.document.label} ${r.document.alert.replace("_", " ")}` : ""}
            </option>
          ))}
        </select>
      </label>
    );
  };

  const blocked = check.blocks.length > 0;
  return (
    <div className="space-y-3">
      {select("guide")}
      {select("driver")}
      {select("vehicle")}
      <RuleList blocks={check.blocks} warnings={check.warnings} />
      {check.warnings.length > 0 && !blocked && (
        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" checked={ack} onChange={(e) => setAck(e.target.checked)} className="h-4 w-4 rounded border-slate-300" />
          Asumo las advertencias (queda registrado en auditoría)
        </label>
      )}
      <fieldset className="flex gap-4 text-sm text-slate-700">
        <legend className="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Notificar al recurso</legend>
        <label className="flex items-center gap-2"><input type="checkbox" checked={notify.push} onChange={(e) => setNotify({ ...notify, push: e.target.checked })} /> Push (app)</label>
        <label className="flex items-center gap-2"><input type="checkbox" checked={notify.whatsapp} onChange={(e) => setNotify({ ...notify, whatsapp: e.target.checked })} /> WhatsApp</label>
      </fieldset>
      <button type="button" onClick={submit} disabled={pending || blocked || (check.warnings.length > 0 && !ack)} className={btnCls}>
        {pending ? "Asignando…" : "Asignar recursos"}
      </button>
      {error && <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200">{error}</p>}
    </div>
  );
}
