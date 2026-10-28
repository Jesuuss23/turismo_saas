"use client";

import { useEffect, useState } from "react";

/** Temporizador del bloqueo de cupos (SIT-BR-06): mm:ss hasta `until`; avisa al llegar a cero. */
export function HoldTimer({ until, locale, onExpire }: { until: string | null; locale: "es" | "en"; onExpire?: () => void }) {
  const [now, setNow] = useState<number | null>(null);
  useEffect(() => {
    setNow(Date.now());
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, []);
  const left = until && now !== null ? Math.max(0, new Date(until).getTime() - now) : null;
  useEffect(() => {
    if (left === 0) onExpire?.();
  }, [left, onExpire]);
  if (!until) return null;
  const mm = left === null ? "--" : String(Math.floor(left / 60000)).padStart(2, "0");
  const ss = left === null ? "--" : String(Math.floor((left % 60000) / 1000)).padStart(2, "0");
  return (
    <p role="timer" data-testid="hold-timer" className={`rounded-lg px-3 py-2 text-sm ${left === 0 ? "bg-rose-50 text-rose-800" : "bg-amber-50 text-amber-900"}`}>
      ⏱ {left === 0 ? (locale === "en" ? "Your hold expired: re-hold your seats." : "Su bloqueo venció: vuelva a bloquear sus cupos.")
        : <>{locale === "en" ? "Seats held for" : "Cupos bloqueados por"} <b className="tabular-nums">{mm}:{ss}</b></>}
    </p>
  );
}
