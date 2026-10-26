"use client";

import { useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";

const KEY = "qhapaq_cat_view";

/** Preferencia tarjetas/tabla por usuario en localStorage (spec 02 §2); la URL manda si trae `view`. */
export function ViewToggle({ view }: { view: "tarjetas" | "tabla" }) {
  const router = useRouter();
  const params = useSearchParams();

  useEffect(() => {
    if (params.get("view")) {
      try { localStorage.setItem(KEY, view); } catch {}
      return;
    }
    let saved: string | null = null;
    try { saved = localStorage.getItem(KEY); } catch {}
    if (saved === "tabla" && view !== "tabla") {
      const next = new URLSearchParams(params);
      next.set("view", "tabla");
      router.replace(`?${next}`);
    }
  }, [params, router, view]);

  function go(target: "tarjetas" | "tabla") {
    const next = new URLSearchParams(params);
    next.set("view", target);
    router.push(`?${next}`);
  }

  return (
    <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5 text-sm" role="group" aria-label="Vista">
      {(["tarjetas", "tabla"] as const).map((v) => (
        <button key={v} type="button" onClick={() => go(v)} aria-pressed={view === v}
          className={`rounded px-3 py-1.5 ${view === v ? "bg-sky-700 text-white" : "text-slate-700 hover:bg-slate-50"}`}>
          {v === "tarjetas" ? "Tarjetas" : "Tabla"}
        </button>
      ))}
    </div>
  );
}
