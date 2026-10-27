"use client";

import { useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { callApi } from "@/app/(app)/crm/actions";
import { btnCls, inputCls } from "@/components/crm/ui";
import type { Template } from "@/lib/marketing";

type Sample = Record<string, string>;

/** Sustituye las variables `{{x}}` con los datos del contacto de ejemplo (vista previa en vivo). */
export function renderSample(text: string, sample: Sample) {
  return text.replace(/\{\{\s*(\w+)\s*\}\}/g, (_, v: string) => sample[v] ?? `{{${v}}}`);
}

/** Inserta `{{variable}}` en la posición del cursor del campo activo. */
export function VariableChips({ variables, onPick }: { variables: string[]; onPick: (v: string) => void }) {
  return (
    <div className="flex flex-wrap gap-1" aria-label="Variables">
      {variables.map((v) => (
        <button key={v} type="button" onClick={() => onPick(`{{${v}}}`)} className="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-700 hover:bg-sky-100">
          {`{{${v}}}`}
        </button>
      ))}
    </div>
  );
}

export function insertAt(el: HTMLTextAreaElement | HTMLInputElement | null, value: string, current: string, set: (v: string) => void) {
  if (!el) return set(current + value);
  const start = el.selectionStart ?? current.length;
  const end = el.selectionEnd ?? current.length;
  set(current.slice(0, start) + value + current.slice(end));
  requestAnimationFrame(() => { el.focus(); el.setSelectionRange(start + value.length, start + value.length); });
}

/** Editor de plantillas (spec 14 §2): variables clicables, vista previa con un contacto real y validación de la baja (MKT-BR-02). */
export function TemplateEditor({ template, variables, sample }: { template: Template | null; variables: string[]; sample: Sample }) {
  const router = useRouter();
  const [channel, setChannel] = useState<"email" | "whatsapp">(template?.channel ?? "email");
  const [name, setName] = useState(template?.name ?? "");
  const [category, setCategory] = useState(template?.category ?? "MARKETING");
  const [subject, setSubject] = useState(template?.subject ?? "");
  const [body, setBody] = useState(template?.body ?? "");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const bodyRef = useRef<HTMLTextAreaElement>(null);
  const missingOptOut = category === "MARKETING" && (channel === "email" ? !body.includes("{{enlace_baja}}") : !body.toUpperCase().includes("BAJA"));

  async function save() {
    setBusy(true);
    setError(null);
    const payload = { channel, name, category, subject: channel === "email" ? subject : null, body };
    const res = template
      ? await callApi(`/marketing/templates/${template.code}`, "PUT", payload, { "If-Match": String(template.lock_version) })
      : await callApi("/marketing/templates", "POST", payload);
    setBusy(false);
    if (res?.error) {
      setError([res.error, ...Object.values(res.fieldErrors ?? {}).flat()].join(" · "));
      return;
    }
    router.push("/marketing/campanas?tab=plantillas");
    router.refresh();
  }

  return (
    <div className="space-y-3 text-sm">
      {!template && (
        <fieldset className="flex gap-4">
          <legend className="mb-1 font-medium text-slate-700">Canal</legend>
          {(["email", "whatsapp"] as const).map((c) => (
            <label key={c} className="flex items-center gap-1"><input type="radio" checked={channel === c} onChange={() => setChannel(c)} /> {c === "email" ? "Email" : "WhatsApp"}</label>
          ))}
        </fieldset>
      )}
      <label className="block"><span className="font-medium text-slate-700">Nombre</span>
        <input className={inputCls} value={name} onChange={(e) => setName(e.target.value)} placeholder={channel === "whatsapp" ? "minusculas_y_guion_bajo" : "Newsletter mensual"} />
      </label>
      <label className="block"><span className="font-medium text-slate-700">Categoría</span>
        <select className={inputCls} value={category} onChange={(e) => setCategory(e.target.value)}>
          <option value="MARKETING">MARKETING</option><option value="UTILITY">UTILITY</option><option value="SERVICIO">SERVICIO</option>
        </select>
      </label>
      {channel === "email" && (
        <label className="block"><span className="font-medium text-slate-700">Asunto</span>
          <input className={inputCls} value={subject} onChange={(e) => setSubject(e.target.value)} />
        </label>
      )}
      <div>
        <span className="font-medium text-slate-700">Variables</span>
        <VariableChips variables={variables} onPick={(v) => insertAt(bodyRef.current, v, body, setBody)} />
      </div>
      <label className="block"><span className="font-medium text-slate-700">Contenido</span>
        <textarea ref={bodyRef} className={`${inputCls} min-h-36 font-mono`} value={body} onChange={(e) => setBody(e.target.value)} />
      </label>
      {missingOptOut && (
        <p className="rounded-md bg-amber-50 px-3 py-2 text-amber-900 ring-1 ring-amber-200">
          {channel === "email" ? "Incluya {{enlace_baja}}: los correos comerciales deben permitir darse de baja (MKT-BR-02)." : "Indique «Responde BAJA» para que el contacto pueda darse de baja (MKT-BR-02)."}
        </p>
      )}
      <section className="rounded-md border border-slate-200 bg-slate-50 p-3" aria-label="Vista previa">
        <p className="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Vista previa · {sample.nombre ?? "contacto de ejemplo"}</p>
        {channel === "email" && <p className="font-medium text-slate-900">{renderSample(subject, sample)}</p>}
        <p className="whitespace-pre-wrap break-words text-slate-800">{renderSample(body, sample)}</p>
      </section>
      {channel === "whatsapp" && <p className="text-xs text-slate-500">Las plantillas de WhatsApp quedan «En revisión» hasta que Meta las apruebe; no se usan antes (MKT-BR-03).</p>}
      {error && <p className="rounded-md bg-rose-50 px-3 py-2 text-rose-800 ring-1 ring-rose-200">{error}</p>}
      <button type="button" onClick={save} disabled={busy} className={btnCls}>{busy ? "Guardando…" : "Guardar plantilla"}</button>
    </div>
  );
}
