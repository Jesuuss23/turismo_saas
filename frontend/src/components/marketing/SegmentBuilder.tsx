"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { callApi } from "@/app/(app)/crm/actions";
import { btnCls, inputCls } from "@/components/crm/ui";
import { ConsentChip } from "./ui";
import { REASON_LABELS, type FieldDef, type Preview, type Rule, type Segment } from "@/lib/marketing";

const NO_VALUE = ["never", "none"];

/**
 * Constructor de segmentos (spec 14 §2): coincidencia TODAS/ALGUNA, reglas campo/operador/valor y conteo en vivo
 * (POST /marketing/segments/preview) con desglose por tipo, supresión por canal y 10 contactos de muestra.
 */
export function SegmentBuilder({ segment, fields }: { segment: Segment | null; fields: FieldDef[] }) {
  const router = useRouter();
  const [name, setName] = useState(segment?.name ?? "");
  const [description, setDescription] = useState(segment?.description ?? "");
  const [match, setMatch] = useState<"all" | "any">(segment?.match ?? "all");
  const [rules, setRules] = useState<Rule[]>(segment?.rules ?? []);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const byKey = Object.fromEntries(fields.map((f) => [f.key, f]));
  const complete = rules.filter((r) => r.f && r.op && (NO_VALUE.includes(r.op) || (r.v !== undefined && r.v !== null && r.v !== "")));
  const signature = JSON.stringify([match, complete]);

  useEffect(() => {
    const timer = setTimeout(async () => {
      const [m, rs] = JSON.parse(signature) as ["all" | "any", Rule[]];
      const res = await callApi("/marketing/segments/preview", "POST", { match: m, rules: rs });
      if (res?.error) { setPreviewError(res.error); return; }
      setPreviewError(null);
      setPreview(res?.data as Preview);
    }, 250);
    return () => clearTimeout(timer);
  }, [signature]);

  const update = (i: number, patch: Partial<Rule>) => setRules((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  const addRule = () => setRules((rs) => [...rs, { f: fields[0].key, op: Object.keys(fields[0].ops)[0], v: "" }]);

  async function save() {
    setBusy(true);
    setError(null);
    const payload = { name, description, match, rules: complete };
    const res = segment
      ? await callApi(`/marketing/segments/${segment.code}`, "PUT", payload, { "If-Match": String(segment.lock_version) })
      : await callApi("/marketing/segments", "POST", payload);
    setBusy(false);
    if (res?.error) { setError([res.error, ...Object.values(res.fieldErrors ?? {}).flat()].join(" · ")); return; }
    const code = (res?.data as { code?: string } | undefined)?.code ?? segment?.code;
    router.push(`/marketing/segmentos/${code}?saved=1`);
    router.refresh();
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px] [&>*]:min-w-0">
      <section className="space-y-3 rounded-lg border border-slate-200 bg-white p-4 text-sm">
        <label className="block"><span className="font-medium text-slate-700">Nombre del segmento</span>
          <input className={inputCls} value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <label className="block"><span className="font-medium text-slate-700">Descripción</span>
          <input className={inputCls} value={description} onChange={(e) => setDescription(e.target.value)} />
        </label>
        <fieldset className="flex flex-wrap items-center gap-3">
          <legend className="sr-only">Coincidencia</legend>
          <span className="font-medium text-slate-700">Coinciden</span>
          <label className="flex items-center gap-1"><input type="radio" name="match" checked={match === "all"} onChange={() => setMatch("all")} /> TODAS las reglas</label>
          <label className="flex items-center gap-1"><input type="radio" name="match" checked={match === "any"} onChange={() => setMatch("any")} /> ALGUNA regla</label>
        </fieldset>
        <ol className="space-y-2" aria-label="Reglas">
          {rules.map((r, i) => {
            const def = byKey[r.f] ?? fields[0];
            return (
              <li key={i} className="grid gap-2 rounded-md border border-slate-200 p-2 sm:grid-cols-[1fr_1fr_1fr_auto]" data-rule={i}>
                <select aria-label={`Campo de la regla ${i + 1}`} className={inputCls} value={r.f}
                  onChange={(e) => { const f = byKey[e.target.value]; update(i, { f: f.key, op: Object.keys(f.ops)[0], v: "" }); }}>
                  {fields.map((f) => <option key={f.key} value={f.key}>{f.label}</option>)}
                </select>
                <select aria-label={`Operador de la regla ${i + 1}`} className={inputCls} value={r.op} onChange={(e) => update(i, { op: e.target.value })}>
                  {Object.entries(def.ops).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                </select>
                {NO_VALUE.includes(r.op) ? <span /> : def.type === "select" && def.options ? (
                  <select aria-label={`Valor de la regla ${i + 1}`} className={inputCls} value={String(r.v ?? "")} onChange={(e) => update(i, { v: e.target.value })}>
                    <option value="">Elegir…</option>
                    {Object.entries(def.options).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                  </select>
                ) : (
                  <input aria-label={`Valor de la regla ${i + 1}`} className={inputCls} type={def.type === "text" ? "text" : "number"} min={0} value={String(r.v ?? "")}
                    placeholder={def.type === "days" ? "días" : undefined} onChange={(e) => update(i, { v: e.target.value })} />
                )}
                <button type="button" onClick={() => setRules((rs) => rs.filter((_, j) => j !== i))} className="text-xs text-rose-700 hover:underline">Quitar</button>
              </li>
            );
          })}
        </ol>
        <button type="button" onClick={addRule} className="rounded-md border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">+ Agregar regla</button>
        {error && <p className="rounded-md bg-rose-50 px-3 py-2 text-rose-800 ring-1 ring-rose-200">{error}</p>}
        <div><button type="button" onClick={save} disabled={busy} className={btnCls}>{busy ? "Guardando…" : "Guardar segmento"}</button></div>
      </section>

      <aside className="space-y-3 rounded-lg border border-slate-200 bg-white p-4 text-sm" aria-live="polite">
        <h2 className="font-semibold text-slate-800">Audiencia en vivo</h2>
        {previewError && <p className="text-rose-700">{previewError}</p>}
        {preview && (
          <>
            <p className="text-3xl font-semibold text-slate-900" data-testid="segment-count">{preview.matched}</p>
            <p className="text-slate-600">contactos coinciden · {preview.by_type.client ?? 0} clientes, {preview.by_type.lead ?? 0} leads, {preview.by_type.subscriber ?? 0} suscriptores</p>
            <p className="text-xs text-slate-500">{preview.rules_text}</p>
            <table className="w-full text-xs">
              <thead><tr className="text-left text-slate-500"><th className="py-1">Canal</th><th>Elegibles</th>{Object.values(REASON_LABELS).map((l) => <th key={l}>{l}</th>)}</tr></thead>
              <tbody>
                {(["email", "whatsapp"] as const).map((c) => (
                  <tr key={c} className="border-t border-slate-100">
                    <td className="py-1">{c === "email" ? "Email" : "WhatsApp"}</td>
                    <td className="font-semibold">{preview[c].eligible}</td>
                    {(Object.keys(REASON_LABELS) as (keyof typeof REASON_LABELS)[]).map((k) => <td key={k}>{preview[c][k as "optout"]}</td>)}
                  </tr>
                ))}
              </tbody>
            </table>
            <h3 className="pt-2 text-xs font-medium uppercase tracking-wide text-slate-500">Vista previa (10)</h3>
            <ul className="divide-y divide-slate-100">
              {preview.sample.map((c) => (
                <li key={`${c.type}-${c.id}`} className="flex flex-wrap items-center justify-between gap-1 py-1.5">
                  <span className="min-w-0"><span className="font-medium text-slate-800">{c.name}</span> <span className="text-xs text-slate-500">{c.type_label} · {c.country ?? "—"}</span></span>
                  <span className="flex gap-1"><ConsentChip status={c.consent.email} label={`Email: ${c.consent.email ?? "—"}`} /></span>
                </li>
              ))}
            </ul>
          </>
        )}
      </aside>
    </div>
  );
}
