"use client";

import { useState } from "react";
import { ActionForm } from "@/components/crm/ActionForm";
import { DAY_TONE, type DayStatus } from "@/lib/booking";

const HEAD = ["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"];
const btn = "rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50";

/**
 * Calendario mensual del motor (spec 16 §2, `BE.month`): estado por día, detalle del día y modo «Cerrar fechas»
 * (selección de días → POST close-dates; el backend agrupa fechas contiguas en rangos).
 */
export function BookingCalendar({ days, refCode, canClose }: { days: DayStatus[]; refCode: string; canClose: boolean }) {
  const [closing, setClosing] = useState(false);
  const [selected, setSelected] = useState<string[]>([]);
  const [detail, setDetail] = useState<DayStatus | null>(null);
  const first = days[0] ? new Date(`${days[0].date}T12:00:00`) : null;
  const offset = first ? (first.getDay() + 6) % 7 : 0;

  const toggle = (d: DayStatus) => {
    if (!closing) {
      setDetail(d);
      return;
    }
    if (d.status === "pasado") return;
    setSelected((s) => (s.includes(d.date) ? s.filter((x) => x !== d.date) : [...s, d.date].sort()));
  };

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-7 gap-1 text-center text-xs" role="grid" aria-label="Calendario de disponibilidad">
        {HEAD.map((h) => <div key={h} className="py-1 font-medium text-slate-500">{h}</div>)}
        {Array.from({ length: offset }).map((_, i) => <div key={`pad-${i}`} />)}
        {days.map((d) => {
          const isSel = selected.includes(d.date);
          return (
            <button key={d.date} type="button" onClick={() => toggle(d)} data-date={d.date} data-status={d.status} aria-pressed={closing ? isSel : undefined}
              title={`${d.date} · ${d.label}${d.note ? ` · ${d.note}` : ""}`}
              className={`min-h-14 rounded-md p-1 text-left ring-1 ${DAY_TONE[d.status]} ${isSel ? "outline outline-2 outline-sky-700" : ""}`}>
              <span className="block text-sm font-semibold">{Number(d.date.slice(8))}</span>
              <span className="block truncate text-[10px] leading-tight">{d.left !== null && d.status !== "cerrado" ? `${d.left} cupos` : d.label}</span>
            </button>
          );
        })}
      </div>
      <p className="flex flex-wrap gap-2 text-[11px] text-slate-600">
        {(["disponible", "sin_cupo", "agotado", "cerrado", "no_opera", "fuera_de_plazo"] as const).map((s) => (
          <span key={s} className={`rounded px-1.5 py-0.5 ring-1 ${DAY_TONE[s]}`}>{{ disponible: "Disponible", sin_cupo: "Sin cupo suficiente", agotado: "Agotado", cerrado: "Cerrado / sin rango", no_opera: "No opera", fuera_de_plazo: "Fuera de plazo" }[s]}</span>
        ))}
      </p>
      {detail && !closing && (
        <div className="rounded-md bg-slate-50 p-3 text-sm ring-1 ring-slate-200" data-day-detail>
          <p className="font-medium">{detail.date} · {detail.label}</p>
          <p className="text-xs text-slate-600">
            {detail.cap != null && <>Capacidad {detail.cap} · vendidos {detail.sold ?? 0} · en hold {detail.held ?? 0} · quedan {detail.left ?? "—"}</>}
            {detail.range && <> · rango {detail.range}</>}{detail.departure && <> · salida {detail.departure}</>}{detail.note && <> · {detail.note}</>}
          </p>
        </div>
      )}
      {canClose && (
        <div className="flex flex-wrap items-start gap-2">
          <button type="button" className={btn} onClick={() => { setClosing((c) => !c); setSelected([]); }}>{closing ? "Cancelar cierre" : "Cerrar fechas"}</button>
          {closing && (
            <ActionForm path={`/catalog/products/${refCode}/availability-ranges/close-dates`} submitLabel={`Cerrar ${selected.length} fecha(s)`} disabled={!selected.length}
              disabledReason="Seleccione días en el calendario" className="flex flex-wrap items-end gap-2">
              {selected.map((d) => <input key={d} type="hidden" name="dates[]" value={d} />)}
              <label className="text-sm">Estado
                <select name="status" className="ml-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm"><option value="cerrado">Cerrado</option><option value="agotado">Agotado</option></select>
              </label>
              <input name="note" placeholder="Motivo (visible en el calendario)" aria-label="Motivo del cierre" className="rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
              <label className="flex items-center gap-1 text-xs text-slate-600"><input type="checkbox" name="confirm_sales" value="true" /> Confirmo cerrar aunque haya ventas</label>
            </ActionForm>
          )}
        </div>
      )}
    </div>
  );
}
