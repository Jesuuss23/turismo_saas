"use client";

import { useState } from "react";
import { ActionForm } from "@/components/crm/ActionForm";
import { inputCls } from "@/components/crm/ui";
import type { PaxReservation } from "@/lib/experience";

/** «Envío manual» (spec 17 §2): elegir la reserva arma la ruta `POST /reservations/{code}/pax-messages`. */
export function ManualSend({ reservations, templates, channels }: { reservations: PaxReservation[]; templates: { id: string; label: string }[]; channels: Record<string, string> }) {
  const [code, setCode] = useState(reservations[0]?.code ?? "");
  const [template, setTemplate] = useState("");
  const r = reservations.find((x) => x.code === code);
  return (
    <div className="space-y-3 text-sm">
      <label className="block"><span className="mb-1 block font-medium text-slate-700">Reserva</span>
        <select value={code} onChange={(e) => setCode(e.target.value)} className={inputCls}>
          {reservations.map((x) => <option key={x.id} value={x.code}>{x.code} · {x.client} · {x.title}</option>)}
        </select>
      </label>
      {r && <p className="text-xs text-slate-500">Viaje {r.travel_from} · idioma {r.lang} · {r.has_email ? "con email" : "sin email"} · {r.has_phone ? "con teléfono" : "sin teléfono"}</p>}
      <ActionForm key={code} path={`/reservations/${code}/pax-messages`} idempotent submitLabel="Enviar ahora" success="Mensaje enviado" className="space-y-3">
        <label className="block"><span className="mb-1 block font-medium text-slate-700">Plantilla</span>
          <select name="template_id" value={template} onChange={(e) => setTemplate(e.target.value)} className={inputCls}>
            <option value="">Mensaje libre</option>
            {templates.map((t) => <option key={t.id} value={t.id}>{t.label}</option>)}
          </select>
        </label>
        <label className="block"><span className="mb-1 block font-medium text-slate-700">Canal</span>
          <select name="channel" defaultValue="email" className={inputCls}>
            {Object.entries(channels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </label>
        {!template && (
          <>
            <label className="block"><span className="mb-1 block font-medium text-slate-700">Asunto</span><input name="subject" maxLength={200} className={inputCls} /></label>
            <label className="block"><span className="mb-1 block font-medium text-slate-700">Mensaje</span><textarea name="body" required rows={5} maxLength={4000} className={inputCls} /></label>
          </>
        )}
      </ActionForm>
    </div>
  );
}
