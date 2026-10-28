"use client";

import { useEffect, useState } from "react";

/** Cuenta regresiva hasta el fin de una oferta (fin del día `until`, hora de Lima). */
export function Countdown({ until, locale }: { until: string; locale: "es" | "en" }) {
  const [now, setNow] = useState<number | null>(null);
  useEffect(() => {
    setNow(Date.now());
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, []);
  if (now === null) return <span className="tabular-nums">—</span>;
  const ms = Math.max(0, new Date(`${until}T23:59:59-05:00`).getTime() - now);
  const d = Math.floor(ms / 86400000);
  const h = Math.floor((ms % 86400000) / 3600000);
  const m = Math.floor((ms % 3600000) / 60000);
  const s = Math.floor((ms % 60000) / 1000);
  return <span className="tabular-nums">{ms === 0 ? (locale === "en" ? "Ended" : "Finalizada") : `${d}d ${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`}</span>;
}
