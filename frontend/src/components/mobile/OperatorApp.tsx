"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { callApi } from "@/app/(app)/crm/actions";
import { date, dateTime } from "@/lib/crm";
import {
  addDays, fileToUpload, INCIDENT_TYPES, SEVERITIES, WEEKDAYS, weekday,
  type MobileActionResult, type MobileAssignment, type OperatorBootstrap,
} from "@/lib/ops";

type ActionType = "confirm" | "start" | "checkin" | "checkin_undo" | "checkin_all" | "evidence" | "incident" | "finish";
type Action = { client_action_id: string; type: ActionType; assignment_id: string; occurred_at: string; offline: boolean; attempts: number; payload: Record<string, unknown> };
type OutboxItem = { action: Action; label: string; service: string; status: "pendiente" | "enviando" | "error"; error?: string };
type Synced = { client_action_id: string; label: string; result: string; message: string | null; synced_at: string; offline: boolean };
type Screen = "hoy" | "detalle" | "cal" | "inc" | "incForm" | "perfil";

const LABELS: Record<ActionType, string> = {
  confirm: "Confirmar asignación", start: "Iniciar servicio", checkin: "Check-in", checkin_undo: "Deshacer check-in", checkin_all: "Check-in grupal",
  evidence: "Evidencia", incident: "Incidencia", finish: "Finalizar servicio",
};
const RESULT: Record<string, { label: string; cls: string }> = {
  applied: { label: "Aplicada", cls: "text-emerald-700" }, duplicate: { label: "Duplicada (ya aplicada)", cls: "text-slate-600" },
  conflict_resolved: { label: "Conflicto resuelto", cls: "text-amber-800" }, rejected: { label: "Rechazada", cls: "text-rose-700" },
};
const btn = "w-full rounded-lg bg-sky-700 px-3 py-2.5 text-sm font-semibold text-white active:bg-sky-800 disabled:opacity-50";
const btnGhost = "w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 active:bg-slate-50";

function load<T>(key: string, fallback: T): T {
  try { const v = localStorage.getItem(key); return v ? (JSON.parse(v) as T) : fallback; } catch { return fallback; }
}
function save(key: string, value: unknown): boolean {
  try { localStorage.setItem(key, JSON.stringify(value)); return true; } catch { return false; }
}

/** Aplica una acción sobre la copia local para que la app responda sin conexión (la verdad la fija el servidor al sincronizar). */
function optimistic(a: MobileAssignment, act: Action): MobileAssignment {
  const now = act.occurred_at;
  const step = (n: number) => Math.max(a.step, n);
  switch (act.type) {
    case "confirm": return { ...a, status: "confirmada", status_label: "Confirmada", step: step(1) };
    case "start": return { ...a, status: "en_curso", status_label: "En curso", step: step(2), started_at: a.started_at ?? now };
    case "checkin": return { ...a, step: step(3), passengers: a.passengers.map((p) => p.id === act.payload.passenger_id ? { ...p, checked_at: p.checked_at ?? now } : p) };
    case "checkin_undo": return { ...a, passengers: a.passengers.map((p) => p.id === act.payload.passenger_id ? { ...p, checked_at: null } : p) };
    case "checkin_all": return { ...a, step: step(3), passengers: a.passengers.map((p) => ({ ...p, checked_at: p.checked_at ?? now })) };
    case "evidence": return { ...a, step: step(4), evidences: [{ id: act.client_action_id, file_name: String(act.payload.file_name ?? "foto"), captured_at: now }, ...a.evidences] };
    case "finish": return { ...a, status: "completada", status_label: "Completada", step: 5, finished_at: now };
    default: return a;
  }
}

/**
 * App del operador (PROC-056) como web móvil: Hoy, Detalle, Calendario, Incidencias y Perfil con bandeja de salida.
 * Sin conexión, las acciones quedan en el outbox (localStorage) y se envían en lote a `/mobile/operator/sync`, que resuelve conflictos (§8.4.4).
 */
export function OperatorApp({ initial, asCode, preview }: { initial: OperatorBootstrap; asCode?: string; preview: boolean }) {
  const [data, setData] = useState(initial);
  const [screen, setScreen] = useState<Screen>("hoy");
  const [selected, setSelected] = useState<string | null>(null);
  const [online, setOnline] = useState(true);
  const [outbox, setOutbox] = useState<OutboxItem[]>([]);
  const [synced, setSynced] = useState<Synced[]>(initial.history.map((h) => ({ ...h })));
  const [toast, setToast] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [deviceId, setDeviceId] = useState<string | null>(null);
  const [calDay, setCalDay] = useState(initial.today);
  const q = asCode ? `?as=${encodeURIComponent(asCode)}` : "";
  const boxKey = `ops.outbox.${data.resource.code}`;

  useEffect(() => {
    setOutbox(load<OutboxItem[]>(boxKey, []));
    setOnline(load("ops.online", true));
    let id = load<string | null>("ops.device", null);
    if (!id) { id = crypto.randomUUID(); save("ops.device", id); }
    setDeviceId(id);
    callApi("/mobile/devices", "POST", { id, app: "operador", platform: "web", app_version: "web-1.0" });
  }, [boxKey]);

  const headers = useMemo((): Record<string, string> => (deviceId ? { "X-Device-Id": deviceId } : {}), [deviceId]);
  const persistOutbox = (next: OutboxItem[]) => { setOutbox(next); if (!save(boxKey, next)) setToast({ ok: false, text: "No hay espacio para guardar la bandeja (fotos muy pesadas)." }); };
  const replace = (a: MobileAssignment) => setData((d) => ({ ...d, assignments: d.assignments.map((x) => (x.id === a.id ? a : x)) }));
  const flash = (ok: boolean, text: string) => { setToast({ ok, text }); setTimeout(() => setToast(null), 5000); };

  const refresh = useCallback(async () => {
    const r = await callApi(`/mobile/operator/bootstrap${q}`, "GET", null, headers);
    if (r.ok && r.data) setData(r.data as OperatorBootstrap);
  }, [q, headers]);

  const sync = useCallback(async (items: OutboxItem[]) => {
    if (!items.length) { await refresh(); return; }
    setBusy(true);
    setOutbox(items.map((i) => ({ ...i, status: "enviando" })));
    const r = await callApi(`/mobile/operator/sync${q}`, "POST", { actions: items.map((i) => ({ ...i.action, attempts: i.action.attempts })) }, headers);
    setBusy(false);
    if (!r.ok) {
      const next = items.map((i) => ({ ...i, status: "error" as const, error: r.error, action: { ...i.action, attempts: i.action.attempts + 1 } }));
      persistOutbox(next);
      flash(false, `No se pudo sincronizar: ${r.error}`);
      return;
    }
    const results = r.data as MobileActionResult[];
    const at = new Date().toISOString();
    const done: Synced[] = results.map((res) => {
      const item = items.find((i) => i.action.client_action_id === res.client_action_id);
      return { client_action_id: res.client_action_id, label: item ? `${item.label} · ${item.service}` : res.client_action_id, result: res.result, message: res.message, synced_at: at, offline: true };
    });
    setSynced((s) => [...done, ...s]);
    persistOutbox([]);
    const conflicts = results.filter((x) => x.result === "conflict_resolved").length;
    const rejected = results.filter((x) => x.result === "rejected").length;
    flash(rejected === 0, `Sincronizado: ${results.length} acción(es)${conflicts ? ` · ${conflicts} conflicto(s) resuelto(s)` : ""}${rejected ? ` · ${rejected} rechazada(s)` : ""}.`);
    await refresh();
  }, [q, headers, refresh]); // eslint-disable-line react-hooks/exhaustive-deps

  const setConnectivity = (value: boolean) => {
    setOnline(value);
    save("ops.online", value);
    if (value) sync(outbox);
  };

  const act = async (type: ActionType, a: MobileAssignment, payload: Record<string, unknown> = {}, label = LABELS[type]) => {
    const action: Action = { client_action_id: crypto.randomUUID(), type, assignment_id: a.id, occurred_at: new Date().toISOString(), offline: !online, attempts: 1, payload };
    if (!online) {
      if (type !== "incident") replace(optimistic(a, action));
      persistOutbox([...outbox, { action, label, service: a.label, status: "pendiente" }]);
      flash(true, `${label}: guardado en la bandeja de salida.`);
      return;
    }
    setBusy(true);
    const r = await callApi(`/mobile/operator/actions${q}`, "POST", action, headers);
    setBusy(false);
    if (!r.ok) { flash(false, r.error ?? "No se pudo registrar"); return; }
    const res = r.data as MobileActionResult;
    if (res.assignment) replace(res.assignment);
    setSynced((s) => [{ client_action_id: action.client_action_id, label: `${label} · ${a.label}`, result: res.result, message: res.message, synced_at: new Date().toISOString(), offline: false }, ...s]);
    if (res.result === "rejected") flash(false, res.message ?? "Acción rechazada");
    else flash(true, res.message ?? `${label}: listo.`);
    if (type === "incident") await refresh();
  };

  const today = data.today;
  const current = data.assignments.find((a) => a.id === selected) ?? null;
  const todays = data.assignments.filter((a) => a.date === today);
  const upcoming = data.assignments.filter((a) => a.date > today);
  const recent = data.assignments.filter((a) => a.date < today || a.status === "completada").slice(-2).reverse();
  const toConfirm = data.assignments.filter((a) => a.status === "asignada").length;
  const myOrders = new Set(data.assignments.filter((a) => a.date >= today).map((a) => a.order_code));
  const critical = data.incidents.find((i) => i.severity === "critica" && ["abierta", "en_proceso"].includes(i.status) && i.order && myOrders.has(i.order.code));
  const open = (a: MobileAssignment) => { setSelected(a.id); setScreen("detalle"); };

  const card = (a: MobileAssignment) => (
    <button key={a.id} type="button" onClick={() => open(a)} className="w-full rounded-xl border border-slate-200 bg-white p-3 text-left shadow-sm active:bg-slate-50">
      <div className="flex items-center justify-between text-xs text-slate-500"><span>{date(a.date)} · {a.time ?? "Sin hora"}</span><StatusPill s={a.status} l={a.status_label} /></div>
      <p className="mt-1 font-semibold text-slate-900">{a.label}</p>
      <p className="text-xs text-slate-600">{a.client} · {a.order_code} · {a.passengers.length} pax</p>
      {a.alert_tags.length > 0 && <p className="mt-1 text-xs text-violet-700">{a.alert_tags.join(" · ")}</p>}
    </button>
  );

  return (
    <div className="mx-auto flex h-[44rem] w-full max-w-sm flex-col overflow-hidden rounded-[2rem] border-8 border-slate-800 bg-slate-100 shadow-xl" aria-label="App del operador">
      <header className="flex items-center justify-between bg-sky-800 px-4 py-2 text-white">
        <p className="text-sm font-semibold">{data.resource.name}</p>
        <button type="button" onClick={() => setScreen("perfil")} className={`rounded-full px-2 py-0.5 text-xs font-medium ${online ? "bg-emerald-500" : "bg-amber-500"}`} aria-label="Estado de red">
          {online ? "En línea" : "Sin conexión"}{outbox.length > 0 && ` · ${outbox.length}`}
        </button>
      </header>
      {toast && <p role="status" className={`mx-3 mt-2 rounded-lg px-3 py-2 text-xs ring-1 ${toast.ok ? "bg-emerald-50 text-emerald-900 ring-emerald-200" : "bg-rose-50 text-rose-800 ring-rose-200"}`}>{toast.text}</p>}

      <main className="flex-1 space-y-3 overflow-y-auto p-3">
        {screen === "hoy" && (
          <>
            <div>
              <h2 className="text-lg font-semibold text-slate-900">Hola, {data.resource.name.split(" ")[0]}</h2>
              <p className="text-xs text-slate-500">{data.resource.type === "guide" ? "Guía" : "Conductor"} · {data.resource.code} · {online ? "en línea" : "sin conexión"} · última sincronización {dateTime(data.last_sync_at ?? data.cursor)}</p>
            </div>
            <div className="grid grid-cols-3 gap-2 text-center">
              {[["Hoy", todays.length], ["Próximos", upcoming.length], ["Por confirmar", toConfirm]].map(([l, n]) => (
                <div key={l} className="rounded-xl bg-white p-2 shadow-sm"><p className="text-xl font-semibold text-slate-900">{n}</p><p className="text-xs text-slate-500">{l}</p></div>
              ))}
            </div>
            {critical && <p className="rounded-lg bg-rose-600 px-3 py-2 text-xs font-medium text-white">Incidencia crítica abierta en {critical.order?.code}: {critical.title}</p>}
            <Section title="Hoy">{todays.length ? todays.map(card) : <Empty text="Sin servicios hoy" />}</Section>
            <Section title="Próximos">{upcoming.length ? upcoming.slice(0, 5).map(card) : <Empty text="Sin servicios próximos" />}</Section>
            <Section title="Recientes">{recent.length ? recent.map(card) : <Empty text="Sin servicios recientes" />}</Section>
          </>
        )}

        {screen === "detalle" && current && <Detail a={current} busy={busy} act={act} onIncident={() => setScreen("incForm")} back={() => setScreen("hoy")} />}

        {screen === "cal" && (
          <>
            <h2 className="font-semibold text-slate-900">Calendario</h2>
            <div className="grid grid-cols-7 gap-1" aria-label="Próximos 7 días">
              {Array.from({ length: 7 }, (_, i) => addDays(today, i)).map((d) => {
                const n = data.assignments.filter((a) => a.date === d).length;
                return (
                  <button key={d} type="button" onClick={() => setCalDay(d)} className={`rounded-lg py-1 text-center text-xs ${d === calDay ? "bg-sky-700 text-white" : "bg-white text-slate-700"}`}>
                    <span className="block">{WEEKDAYS[weekday(d)]}</span><span className="block font-semibold">{Number(d.slice(8))}</span>
                    <span className={`mx-auto mt-0.5 block h-1.5 w-1.5 rounded-full ${n ? (d === calDay ? "bg-white" : "bg-sky-600") : "bg-transparent"}`} />
                  </button>
                );
              })}
            </div>
            <Section title={`Agenda desde ${date(calDay)} (14 días)`}>
              {data.assignments.filter((a) => a.date >= calDay && a.date <= addDays(calDay, 13)).map(card)}
            </Section>
          </>
        )}

        {screen === "inc" && (
          <>
            <div className="flex items-center justify-between"><h2 className="font-semibold text-slate-900">Incidencias</h2>
              <button type="button" onClick={() => { setSelected(selected ?? data.assignments.find((a) => a.status !== "completada")?.id ?? null); setScreen("incForm"); }} className="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white">Reportar</button></div>
            {data.incidents.length === 0 && <Empty text="Sin incidencias en sus órdenes" />}
            {data.incidents.map((i) => (
              <div key={i.id} className="rounded-xl bg-white p-3 text-sm shadow-sm">
                <div className="flex justify-between text-xs"><span className="text-slate-500">{i.code} · {i.order?.code}</span><span className={i.severity === "critica" ? "font-semibold text-rose-700" : "text-slate-600"}>{i.severity_label}</span></div>
                <p className="font-medium text-slate-900">{i.title}</p>
                <p className="text-xs text-slate-500">{i.status_label} · {i.assignee?.name ?? "Sin responsable"}</p>
              </div>
            ))}
            {outbox.filter((o) => o.action.type === "incident").map((o) => (
              <div key={o.action.client_action_id} className="rounded-xl border border-dashed border-amber-300 bg-amber-50 p-3 text-sm"><p className="font-medium">{String(o.action.payload.title)}</p><p className="text-xs text-amber-800">En bandeja de salida</p></div>
            ))}
          </>
        )}

        {screen === "incForm" && <IncidentForm assignments={data.assignments.filter((a) => a.status !== "completada" || a.date >= addDays(today, -1))} selected={selected}
          busy={busy} onCancel={() => setScreen(current ? "detalle" : "inc")}
          onSubmit={async (a, payload) => { await act("incident", a, payload, `Incidencia "${payload.title}"`); setScreen("inc"); }} />}

        {screen === "perfil" && (
          <>
            <Section title="Perfil">
              <div className="rounded-xl bg-white p-3 text-sm shadow-sm">
                <p className="font-semibold">{data.resource.name}</p>
                <p className="text-xs text-slate-600">{data.resource.type === "guide" ? "Guía" : "Conductor"} · {data.resource.code} · {data.resource.phone}</p>
                <p className="mt-1 text-xs text-slate-600">{data.resource.type === "guide" ? "Carné" : "Licencia"} {data.resource.license_number}{data.resource.license_class && ` (${data.resource.license_class})`} · vence {date(data.resource.license_expires_on)}</p>
                {data.resource.languages && <p className="text-xs text-slate-600">Idiomas: {data.resource.languages.join(", ")}</p>}
              </div>
            </Section>
            <label className="flex items-center justify-between rounded-xl bg-white p-3 text-sm shadow-sm">
              <span><span className="font-medium">Sin conexión</span><span className="block text-xs text-slate-500">Simula zona sin señal (demo)</span></span>
              <input type="checkbox" role="switch" aria-label="Sin conexión" checked={!online} onChange={(e) => setConnectivity(!e.target.checked)} className="h-5 w-5" />
            </label>
            <Section title={`Bandeja de salida (${outbox.length})`}>
              {outbox.length === 0 ? <Empty text="Nada pendiente" /> : outbox.map((o) => (
                <div key={o.action.client_action_id} className="rounded-lg bg-white px-3 py-2 text-xs shadow-sm">
                  <p className="font-medium text-slate-800">{o.label} · {o.service}</p>
                  <p className={o.status === "error" ? "text-rose-700" : "text-amber-800"}>{o.status === "enviando" ? "Enviando…" : o.status === "error" ? `Error: ${o.error} (intento ${o.action.attempts})` : "Pendiente"} · {dateTime(o.action.occurred_at)}</p>
                </div>
              ))}
              {online && outbox.length > 0 && <button type="button" disabled={busy} onClick={() => sync(outbox)} className={btn}>Sincronizar ahora</button>}
            </Section>
            <Section title="Sincronizadas recientemente">
              {synced.slice(0, 6).map((s) => (
                <div key={s.client_action_id} className="rounded-lg bg-white px-3 py-2 text-xs shadow-sm">
                  <p className="font-medium text-slate-800">{s.label}</p>
                  <p className={RESULT[s.result]?.cls}>{RESULT[s.result]?.label ?? s.result}{s.offline && " · offline"} · {dateTime(s.synced_at)}</p>
                  {s.message && <p className="text-slate-600">{s.message}</p>}
                </div>
              ))}
              {synced.length === 0 && <Empty text="Sin acciones todavía" />}
            </Section>
            {preview && <p className="text-xs text-slate-500">Vista previa interna: las acciones se registran como el recurso seleccionado.</p>}
          </>
        )}
      </main>

      <nav className="grid grid-cols-4 border-t border-slate-200 bg-white text-xs" aria-label="Navegación de la app">
        {([["hoy", "Hoy"], ["cal", "Calendario"], ["inc", "Incidencias"], ["perfil", "Perfil"]] as const).map(([k, l]) => (
          <button key={k} type="button" onClick={() => setScreen(k)} aria-current={screen === k || (k === "hoy" && screen === "detalle") ? "page" : undefined}
            className={`py-2.5 ${screen === k || (k === "hoy" && screen === "detalle") ? "font-semibold text-sky-800" : "text-slate-500"}`}>{l}</button>
        ))}
      </nav>
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return <section className="space-y-2"><h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</h3>{children}</section>;
}
function Empty({ text }: { text: string }) {
  return <p className="rounded-xl bg-white p-3 text-center text-xs text-slate-500">{text}</p>;
}
function StatusPill({ s, l }: { s: string; l: string }) {
  const cls = s === "en_curso" ? "bg-sky-100 text-sky-800" : s === "completada" ? "bg-emerald-100 text-emerald-800" : s === "confirmada" ? "bg-indigo-100 text-indigo-800" : "bg-amber-100 text-amber-900";
  return <span className={`rounded-full px-2 py-0.5 font-medium ${cls}`}>{l}</span>;
}

function Detail({ a, busy, act, onIncident, back }: {
  a: MobileAssignment; busy: boolean; back: () => void; onIncident: () => void;
  act: (type: ActionType, a: MobileAssignment, payload?: Record<string, unknown>, label?: string) => Promise<void>;
}) {
  const running = a.status === "en_curso";
  const checked = a.passengers.filter((p) => p.checked_at).length;
  const photo = async (file: File | undefined) => {
    if (!file) return;
    if (file.size > 8 * 1024 * 1024) { alert("La foto supera 8 MB."); return; }
    await act("evidence", a, await fileToUpload(file), `Evidencia ${file.name}`);
  };
  return (
    <>
      <button type="button" onClick={back} className="text-xs text-sky-800">← Volver</button>
      <div className="rounded-xl bg-white p-3 shadow-sm">
        <div className="flex items-center justify-between text-xs text-slate-500"><span>{date(a.date)} · {a.time ?? "Sin hora"}</span><StatusPill s={a.status} l={a.status_label} /></div>
        <h2 className="mt-1 font-semibold text-slate-900">{a.label}</h2>
        <p className="text-xs text-slate-600">{a.title} · {a.order_code}</p>
        <ol className="mt-3 flex gap-1" aria-label="Pasos del servicio">
          {a.steps.map((s, i) => <li key={s} className={`flex-1 rounded py-1 text-center text-[10px] ${i <= a.step ? "bg-sky-700 text-white" : "bg-slate-100 text-slate-500"}`}>{s}</li>)}
        </ol>
      </div>
      <div className="space-y-1 rounded-xl bg-white p-3 text-sm shadow-sm">
        <p><span className="text-xs text-slate-500">Punto de encuentro</span><br />{a.meeting_point}</p>
        {a.vehicle && <p><span className="text-xs text-slate-500">Vehículo</span><br />{a.vehicle}</p>}
        {a.partner && <p><span className="text-xs text-slate-500">{a.partner.role}</span><br />{a.partner.name} {a.partner.phone && <a href={`tel:${a.partner.phone}`} className="text-sky-800">{a.partner.phone}</a>}</p>}
        {a.coordinator && <p className="flex items-center justify-between"><span><span className="text-xs text-slate-500">Coordinador</span><br />{a.coordinator.name}</span>
          {a.coordinator.phone && <a href={`tel:${a.coordinator.phone}`} className="rounded-lg bg-emerald-600 px-3 py-1 text-xs font-semibold text-white">Llamar</a>}</p>}
        {a.notes && <p className="rounded bg-slate-50 px-2 py-1 text-xs text-slate-700">{a.notes}</p>}
        {a.diets.length > 0 && <p className="rounded bg-violet-50 px-2 py-1 text-xs text-violet-900">{a.diets.join(" · ")}</p>}
        {a.incidents.map((i) => <p key={i.code} className="rounded bg-amber-50 px-2 py-1 text-xs text-amber-900">⚠️ {i.code} {i.title}</p>)}
      </div>

      <div className="space-y-2">
        {a.status === "asignada" && <button type="button" disabled={busy} onClick={() => act("confirm", a)} className={btn}>Confirmar asignación</button>}
        {a.status === "confirmada" && <button type="button" disabled={busy} onClick={() => act("start", a)} className={btn}>Iniciar servicio</button>}
        {running && <button type="button" disabled={busy || checked === a.passengers.length} onClick={() => act("checkin_all", a)} className={btnGhost}>Check-in grupal ({checked}/{a.passengers.length})</button>}
        {(running || a.status === "completada") && (
          <label className={`${btnGhost} block cursor-pointer text-center`}>
            Registrar evidencia (foto)
            <input type="file" accept="image/*,application/pdf" capture="environment" className="sr-only" aria-label="Foto de evidencia" onChange={(e) => { photo(e.target.files?.[0]); e.target.value = ""; }} />
          </label>
        )}
        <button type="button" onClick={onIncident} className="w-full rounded-lg border border-rose-300 bg-white px-3 py-2.5 text-sm font-medium text-rose-700">Reportar incidencia</button>
        {running && <button type="button" disabled={busy} onClick={() => act("finish", a)} className={btn}>Finalizar servicio</button>}
      </div>

      <Section title={`Pasajeros (${checked}/${a.passengers.length} con check-in)`}>
        {a.passengers.map((p) => (
          <div key={p.id} className="flex items-center justify-between rounded-lg bg-white px-3 py-2 text-sm shadow-sm">
            <span>{p.name}{p.lead && " ★"}<span className="block text-xs text-slate-500">{p.type}{p.doc && ` · ${p.doc}`}{p.diet && ` · ${p.diet}`}</span></span>
            {running ? (
              <button type="button" disabled={busy} onClick={() => act(p.checked_at ? "checkin_undo" : "checkin", a, { passenger_id: p.id }, `${p.checked_at ? "Deshacer check-in" : "Check-in"} ${p.name}`)}
                aria-label={`${p.checked_at ? "Deshacer check-in de" : "Check-in de"} ${p.name}`}
                className={`rounded-full px-3 py-1 text-xs font-semibold ${p.checked_at ? "bg-emerald-600 text-white" : "bg-slate-100 text-slate-700"}`}>{p.checked_at ? "✓" : "Check-in"}</button>
            ) : <span className="text-xs text-slate-500">{p.checked_at ? `✓ ${dateTime(p.checked_at).slice(-5)}` : ""}</span>}
          </div>
        ))}
      </Section>
      <Section title={`Evidencias (${a.evidences.length})`}>
        {a.evidences.length === 0 ? <Empty text="Sin evidencias" /> : a.evidences.map((e) => <p key={e.id} className="rounded-lg bg-white px-3 py-2 text-xs shadow-sm">📷 {e.file_name} · {dateTime(e.captured_at)}</p>)}
      </Section>
    </>
  );
}

function IncidentForm({ assignments, selected, busy, onSubmit, onCancel }: {
  assignments: MobileAssignment[]; selected: string | null; busy: boolean; onCancel: () => void;
  onSubmit: (a: MobileAssignment, payload: Record<string, unknown>) => Promise<void>;
}) {
  const [assignmentId, setAssignmentId] = useState(selected && assignments.some((a) => a.id === selected) ? selected : assignments[0]?.id ?? "");
  const [type, setType] = useState("Transporte");
  const [severity, setSeverity] = useState("media");
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [photo, setPhoto] = useState<File | null>(null);
  const input = "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm";
  const submit = async () => {
    const a = assignments.find((x) => x.id === assignmentId);
    if (!a || !title.trim()) return;
    await onSubmit(a, { type, severity, title: title.trim(), description: description.trim() || title.trim(), ...(photo ? { photo: await fileToUpload(photo) } : {}) });
  };
  return (
    <div className="space-y-3">
      <h2 className="font-semibold text-slate-900">Nueva incidencia</h2>
      {assignments.length === 0 ? <Empty text="No tiene servicios activos para reportar" /> : (
        <>
          <label className="block text-xs text-slate-600">Servicio
            <select value={assignmentId} onChange={(e) => setAssignmentId(e.target.value)} className={input}>
              {assignments.map((a) => <option key={a.id} value={a.id}>{date(a.date)} · {a.label}</option>)}
            </select></label>
          <label className="block text-xs text-slate-600">Tipo
            <select value={type} onChange={(e) => setType(e.target.value)} className={input}>{INCIDENT_TYPES.map((t) => <option key={t}>{t}</option>)}</select></label>
          <fieldset className="text-xs text-slate-600"><legend>Severidad</legend>
            <div className="mt-1 grid grid-cols-4 gap-1">
              {Object.entries(SEVERITIES).map(([k, l]) => (
                <label key={k} className={`rounded-lg py-1.5 text-center ring-1 ${severity === k ? (k === "critica" ? "bg-rose-600 text-white ring-rose-600" : "bg-sky-700 text-white ring-sky-700") : "bg-white ring-slate-300"}`}>
                  <input type="radio" name="severity" value={k} checked={severity === k} onChange={() => setSeverity(k)} className="sr-only" />{l}
                </label>
              ))}
            </div>
          </fieldset>
          <label className="block text-xs text-slate-600">Título<input value={title} onChange={(e) => setTitle(e.target.value)} maxLength={200} className={input} /></label>
          <label className="block text-xs text-slate-600">Descripción<textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={3} className={input} /></label>
          <label className="block text-xs text-slate-600">Adjuntar foto<input type="file" accept="image/*" capture="environment" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} className="block w-full text-xs" /></label>
          <button type="button" disabled={busy || !title.trim()} onClick={submit} className="w-full rounded-lg bg-rose-600 px-3 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Enviar incidencia</button>
        </>
      )}
      <button type="button" onClick={onCancel} className={btnGhost}>Cancelar</button>
    </div>
  );
}
