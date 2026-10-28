"use client";

import { useEffect } from "react";
import { usePathname } from "next/navigation";
import { track } from "@/app/sitio/actions";

export function sessionId(): string {
  try {
    let id = localStorage.getItem("ah_sid");
    if (!id) {
      id = crypto.randomUUID().replace(/-/g, "").slice(0, 24);
      localStorage.setItem("ah_sid", id);
    }
    return id;
  } catch {
    return "anonymous0";
  }
}

/** Evento del embudo (nombres GA4): se guarda en el backend para el reporte de conversión; sin datos personales. */
export function trackEvent(event: string, params: Record<string, unknown> = {}) {
  void track([{ event, session_id: sessionId(), params }]);
}

/** page_view por navegación (consentimiento de cookies de analítica pendiente de CMP; §12). */
export function Analytics({ currency, locale }: { currency: string; locale: string }) {
  const path = usePathname();
  useEffect(() => {
    trackEvent("page_view", { page_path: path, currency, locale });
  }, [path, currency, locale]);
  return null;
}
