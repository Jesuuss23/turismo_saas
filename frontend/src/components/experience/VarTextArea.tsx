"use client";

import { useRef, useState } from "react";
import { inputCls } from "@/components/crm/ui";

/** Cuerpo de plantilla con variables insertables en el cursor (`{{pasajero}}`…) y contador para WhatsApp (MSG-01: 1 024). */
export function VarTextArea({ name, label, defaultValue, variables, required }: { name: string; label: string; defaultValue?: string; variables: Record<string, string>; required?: boolean }) {
  const ref = useRef<HTMLTextAreaElement>(null);
  const [length, setLength] = useState(defaultValue?.length ?? 0);
  const insert = (v: string) => {
    const el = ref.current;
    if (!el) return;
    const token = `{{${v}}}`;
    const start = el.selectionStart ?? el.value.length;
    el.value = el.value.slice(0, start) + token + el.value.slice(el.selectionEnd ?? start);
    el.focus();
    el.setSelectionRange(start + token.length, start + token.length);
    setLength(el.value.length);
  };
  return (
    <div className="text-sm">
      <label htmlFor={`${name}-ta`} className="mb-1 block font-medium text-slate-700">{label}</label>
      <textarea id={`${name}-ta`} ref={ref} name={name} defaultValue={defaultValue} required={required} rows={6} maxLength={4000} onChange={(e) => setLength(e.target.value.length)} className={inputCls} />
      <p className={`mt-1 text-xs ${length > 1024 ? "text-rose-700" : "text-slate-500"}`}>{length} caracteres{length > 1024 ? " · supera el máximo de WhatsApp (1 024)" : ""}</p>
      <div className="mt-1 flex flex-wrap gap-1">
        {Object.entries(variables).map(([k, v]) => (
          <button key={k} type="button" title={v} onClick={() => insert(k)} className="rounded border border-slate-300 bg-slate-50 px-1.5 py-0.5 font-mono text-[11px] text-slate-700 hover:bg-slate-100">{`{{${k}}}`}</button>
        ))}
      </div>
    </div>
  );
}
