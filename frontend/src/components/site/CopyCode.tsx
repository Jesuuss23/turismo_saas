"use client";

import { useState } from "react";

export function CopyCode({ code, en }: { code: string; en: boolean }) {
  const [done, setDone] = useState(false);
  return (
    <button type="button" onClick={() => navigator.clipboard?.writeText(code).then(() => setDone(true)).catch(() => setDone(false))}
      className="mt-2 rounded-lg border border-dashed border-rose-400 px-3 py-1 font-mono text-sm">
      {code} · {done ? (en ? "Copied" : "Copiado") : (en ? "Copy" : "Copiar")}
    </button>
  );
}
