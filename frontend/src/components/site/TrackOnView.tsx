"use client";

import { useEffect } from "react";
import { trackEvent } from "./Analytics";

/** Dispara un evento GA4 al montar (vista de listado, ficha, carrito, compra). */
export function TrackOnView({ event, params }: { event: string; params: Record<string, unknown> }) {
  const key = JSON.stringify(params);
  useEffect(() => {
    trackEvent(event, JSON.parse(key));
  }, [event, key]);
  return null;
}
