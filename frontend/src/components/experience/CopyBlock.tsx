"use client";

import { useRef, useState } from "react";

/** Bloque de código o texto con «Copiar»: `navigator.clipboard` y, si no está disponible (HTTP, permisos), selecciona el texto. */
export function CopyBlock({ text, label = "Copiar", pre = true }: { text: string; label?: string; pre?: boolean }) {
  const ref = useRef<HTMLPreElement>(null);
  const [state, setState] = useState<"idle" | "copied" | "selected">("idle");
  const select = () => {
    const el = ref.current;
    if (!el) return;
    const range = document.createRange();
    range.selectNodeContents(el);
    const sel = window.getSelection();
    sel?.removeAllRanges();
    sel?.addRange(range);
    setState("selected");
  };
  const copy = () => {
    if (!navigator.clipboard) return select();
    navigator.clipboard.writeText(text).then(() => setState("copied"), select);
  };
  return (
    <div className="min-w-0">
      <pre ref={ref} className={`max-h-64 overflow-auto rounded-md bg-slate-900 p-3 text-xs text-slate-100 ${pre ? "whitespace-pre" : "whitespace-pre-wrap"}`}>{text}</pre>
      <button type="button" onClick={copy} className="mt-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
        {state === "copied" ? "Copiado" : state === "selected" ? "Texto seleccionado: use Ctrl+C" : label}
      </button>
    </div>
  );
}
