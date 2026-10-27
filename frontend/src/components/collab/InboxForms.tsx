"use client";

import { useRef, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import Link from "@/components/Link";
import { callApi } from "@/app/(app)/crm/actions";
import { ActionForm } from "@/components/crm/ActionForm";
import { Input, Select, TextArea, btnCls, btnGhostCls, inputCls } from "@/components/crm/ui";

export type Template = { id: string; code: string; name: string; body: string; channels: string[]; is_whatsapp_approved: boolean; preview: string };
type Attachment = { id: string; file_name: string; scan_status: string; size_bytes: number };

const SAMPLE_FILES = [
  { file_name: "itinerario.pdf", mime: "application/pdf", size_bytes: 248_000 },
  { file_name: "voucher-hotel.png", mime: "image/png", size_bytes: 512_000 },
  { file_name: "eicar-test.pdf", mime: "application/pdf", size_bytes: 68 },
];

/**
 * Caja de respuesta (spec 10 §2): respuesta al cliente o nota interna, plantillas con variables, adjuntos simulados,
 * «Enviar» y «Enviar y resolver». WhatsApp fuera de la ventana de 24 h solo admite plantillas aprobadas (INB-BR-02).
 */
export function ReplyBox({ code, templates, windowOpen }: { code: string; templates: Template[]; windowOpen: boolean }) {
  const router = useRouter();
  const [mode, setMode] = useState<"reply" | "note">("reply");
  const [body, setBody] = useState("");
  const [template, setTemplate] = useState<Template | null>(null);
  const [files, setFiles] = useState<Attachment[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const [pending, start] = useTransition();
  const idem = useRef(crypto.randomUUID());
  const usable = templates.filter((t) => windowOpen || t.is_whatsapp_approved);
  const locked = mode === "reply" && !windowOpen;

  function pick(id: string) {
    const t = templates.find((x) => x.id === id) ?? null;
    setTemplate(t);
    if (t) setBody(t.preview);
  }

  function attach(sample: (typeof SAMPLE_FILES)[number]) {
    start(async () => {
      const res = await callApi("/inbox/attachments", "POST", sample);
      if (res.error) return setError(res.error);
      const file = res.data as Attachment;
      setFiles((f) => [...f, file]);
      setError(file.scan_status === "limpio" ? null : `${file.file_name}: el antivirus lo marcó como ${file.scan_status}; no se podrá enviar (INB-BR-11).`);
    });
  }

  function send(resolve: boolean) {
    setError(null);
    setInfo(null);
    start(async () => {
      const res = mode === "note"
        ? await callApi(`/inbox/conversations/${code}/notes`, "POST", { body })
        : await callApi(`/inbox/conversations/${code}/messages`, "POST",
          { body: template && body === template.preview ? "" : body, template_id: template?.id ?? null, attachments: files.map((f) => f.id), resolve },
          { "Idempotency-Key": idem.current });
      if (res.error) {
        setError(res.error + (res.fieldErrors ? ` · ${Object.values(res.fieldErrors).flat().join(" · ")}` : ""));
        if (res.code) idem.current = crypto.randomUUID();
        return;
      }
      idem.current = crypto.randomUUID();
      setBody("");
      setTemplate(null);
      setFiles([]);
      setInfo(mode === "note" ? "Nota interna agregada" : resolve ? "Respuesta enviada y conversación resuelta" : "Respuesta enviada");
      router.refresh();
    });
  }

  return (
    <div className={`border-t p-3 ${mode === "note" ? "border-amber-200 bg-amber-50" : "border-slate-200 bg-white"}`}>
      <div className="mb-2 flex flex-wrap items-center gap-2 text-sm" role="tablist" aria-label="Tipo de mensaje">
        <button type="button" role="tab" aria-selected={mode === "reply"} onClick={() => setMode("reply")}
          className={`rounded-md px-2.5 py-1 ${mode === "reply" ? "bg-sky-700 text-white" : "text-slate-600 hover:bg-slate-100"}`}>Responder</button>
        <button type="button" role="tab" aria-selected={mode === "note"} onClick={() => setMode("note")}
          className={`rounded-md px-2.5 py-1 ${mode === "note" ? "bg-amber-600 text-white" : "text-slate-600 hover:bg-slate-100"}`}>Nota interna</button>
        {mode === "reply" && (
          <select aria-label="Plantilla" value={template?.id ?? ""} onChange={(e) => pick(e.target.value)} className="ml-auto max-w-full rounded-md border border-slate-300 px-2 py-1 text-sm">
            <option value="">{locked ? "Elija una plantilla aprobada" : "Plantillas…"}</option>
            {usable.map((t) => <option key={t.id} value={t.id}>{t.code} · {t.name}</option>)}
          </select>
        )}
      </div>
      <label className="sr-only" htmlFor={`reply-${code}`}>{mode === "note" ? "Nota interna" : "Respuesta"}</label>
      <textarea id={`reply-${code}`} rows={3} value={body} onChange={(e) => setBody(e.target.value)} readOnly={locked && !!template}
        placeholder={mode === "note" ? "Solo visible para el equipo…" : locked ? "Ventana de 24 h cerrada: use una plantilla aprobada por Meta" : "Escriba la respuesta…"}
        className={inputCls} />
      {mode === "reply" && (
        <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
          <span className="text-slate-500">Adjuntar (simulado):</span>
          {SAMPLE_FILES.map((s) => <button key={s.file_name} type="button" onClick={() => attach(s)} disabled={pending} className="rounded border border-slate-300 px-2 py-0.5 hover:bg-slate-50">{s.file_name}</button>)}
          {files.map((f) => (
            <span key={f.id} className={`rounded-full px-2 py-0.5 ring-1 ${f.scan_status === "limpio" ? "bg-emerald-50 text-emerald-800 ring-emerald-200" : "bg-rose-50 text-rose-800 ring-rose-200"}`}>
              📎 {f.file_name} · {f.scan_status}
              <button type="button" aria-label={`Quitar ${f.file_name}`} className="ml-1" onClick={() => setFiles((x) => x.filter((y) => y.id !== f.id))}>✕</button>
            </span>
          ))}
        </div>
      )}
      <div className="mt-2 flex flex-wrap gap-2">
        <button type="button" className={btnCls} disabled={pending || (!body.trim() && !template)} onClick={() => send(false)}>
          {pending ? "Enviando…" : mode === "note" ? "Agregar nota" : "Enviar"}
        </button>
        {mode === "reply" && <button type="button" className={btnGhostCls} disabled={pending || (!body.trim() && !template)} onClick={() => send(true)}>Enviar y resolver</button>}
      </div>
      <div aria-live="polite">
        {error && <p className="mt-2 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200">{error}</p>}
        {info && <p className="mt-2 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">{info}</p>}
      </div>
    </div>
  );
}

/** Crear lead desde la conversación (PROC-004) con la misma resolución de duplicados del CRM (CRM-BR-04). */
export function LeadFromConversation({ code, name, email, phone, interests }: { code: string; name: string; email: string | null; phone: string | null; interests: { value: string; label: string }[] }) {
  return (
    <ActionForm path={`/inbox/conversations/${code}/lead`} idempotent submitLabel="Crear lead" success="Lead creado y vinculado"
      renderState={(state) => state?.code === "CRM-BR-04" && state.duplicates ? (
        <div className="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm">
          <p className="font-medium text-amber-900">Posibles duplicados. Elija qué hacer y vuelva a crear:</p>
          <ul className="my-2 list-disc pl-5 text-amber-900">
            {state.duplicates.map((d) => <li key={d.id}>{d.type === "lead" ? <Link className="underline" href={`/crm/leads/${d.code}`}>{d.code}</Link> : d.code} · {d.name} · {d.status}</li>)}
          </ul>
          <fieldset className="space-y-1">
            {state.duplicates.some((d) => d.type === "lead") && <label className="flex gap-2"><input type="radio" name="duplicate_resolution" value="merge" /> Fusionar con el lead existente</label>}
            <label className="flex gap-2"><input type="radio" name="duplicate_resolution" value="mark_duplicate" /> Registrar como duplicado</label>
            <label className="flex gap-2"><input type="radio" name="duplicate_resolution" value="create_new" /> Crear de todas formas</label>
          </fieldset>
        </div>
      ) : null}>
      <Input name="full_name" label="Nombre" defaultValue={name} required />
      <div className="grid gap-3 sm:grid-cols-2">
        <Input name="email" label="Correo" type="email" defaultValue={email ?? ""} />
        <Input name="phone" label="Teléfono" defaultValue={phone ?? ""} />
      </div>
      <Select name="interest_code" label="Interés" options={interests} defaultValue="paquete" />
      <div className="grid gap-3 sm:grid-cols-2">
        <Input name="destination_label" label="Destino" />
        <Input name="pax" label="Pasajeros" type="number" min={0} max={999} />
      </div>
      <TextArea name="notes" label="Notas" rows={2} />
    </ActionForm>
  );
}

/** «Crear cotización»: deja traza en el hilo y abre el asistente con la oportunidad del cliente si existe. */
export function QuoteIntentButton({ code }: { code: string }) {
  const router = useRouter();
  const [pending, start] = useTransition();
  const [error, setError] = useState<string | null>(null);
  return (
    <div>
      <button type="button" className={btnGhostCls} disabled={pending} onClick={() => start(async () => {
        const res = await callApi(`/inbox/conversations/${code}/quote-intent`, "POST", {});
        if (res.error) return setError(res.error);
        router.push((res.data as { url: string }).url);
      })}>{pending ? "Abriendo…" : "Crear cotización"}</button>
      {error && <p className="mt-1 text-xs text-rose-700">{error}</p>}
    </div>
  );
}
