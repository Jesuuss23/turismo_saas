"use client";

import { useRef, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { callApi } from "@/app/(app)/crm/actions";
import { btnCls, inputCls } from "@/components/crm/ui";
import type { ClaimFormData } from "@/lib/claims";

type Prefill = { name?: string; doc_type?: string; doc_number?: string; email?: string; phone?: string | null; address?: string; good?: string; reservation?: string };
type Submitted = { book_number: string; due_date?: string; message?: string };
type Submit = (body: Record<string, unknown>, idem: string) => Promise<{ ok?: boolean; error?: string; code?: string; fieldErrors?: Record<string, string[]>; data?: unknown; info?: string }>;

function Field({ label, children, hint, className = "" }: { label: string; children: React.ReactNode; hint?: string; className?: string }) {
  return (
    <label className={`block text-sm ${className}`}>
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      {children}
      {hint && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
    </label>
  );
}

/**
 * Hoja de reclamación virtual (spec 12 §2, LR-BR-01..05). Modos: portal del cliente o del pasajero (datos precargados),
 * presencial (personal), sitio web (con captcha) y vista previa interna (no registra).
 */
export function ClaimForm({ form, prefill = {}, mode, path, captcha, submitPublic }: {
  form: ClaimFormData; prefill?: Prefill; mode: "customer" | "passenger" | "preview" | "in_person" | "web"; path?: string;
  captcha?: { question: string; token: string }; submitPublic?: Submit;
}) {
  const router = useRouter();
  const [minor, setMinor] = useState(false);
  const [error, setError] = useState<{ message: string; fields?: Record<string, string[]> } | null>(null);
  const [done, setDone] = useState<Submitted | null>(null);
  const [pending, start] = useTransition();
  const idem = useRef(crypto.randomUUID());
  const ref = useRef<HTMLFormElement>(null);
  const preview = mode === "preview";

  function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (preview) return;
    const f = new FormData(e.currentTarget);
    const s = (k: string) => String(f.get(k) ?? "").trim();
    const body: Record<string, unknown> = {
      sede_id: s("sede_id"), type: s("type"), accept_email: f.get("accept_email") === "on",
      consumer: { name: s("name"), doc_type: s("doc_type"), doc_number: s("doc_number"), address: s("address"), email: s("email"), phone: s("phone") || null, is_minor: minor,
        guardian: minor ? { name: s("g_name"), doc_type: s("g_doc_type"), doc_number: s("g_doc_number"), relation: s("g_relation") } : null },
      good: { kind: s("good_kind"), description: s("good_description"), amount: s("good_amount") || null, currency: s("good_currency") },
      detail: s("detail"), request: s("request"),
      ...(captcha ? { captcha_token: captcha.token, captcha_answer: s("captcha_answer") } : {}),
    };
    start(async () => {
      const res = submitPublic ? await submitPublic(body, idem.current) : await callApi(path ?? "/portal/claim-book", "POST", body, { "Idempotency-Key": idem.current });
      if (res.ok || res.code) idem.current = crypto.randomUUID();
      if (!res.ok) {
        setError({ message: res.error ?? "No se pudo registrar la hoja", fields: res.fieldErrors });
        return;
      }
      const data = res.data as Submitted;
      setError(null);
      setDone({ ...data, message: res.info });
      ref.current?.reset();
      setMinor(false);
      router.refresh();
    });
  }

  return (
    <form ref={ref} onSubmit={submit} className="space-y-5" aria-label="Hoja de reclamación">
      <section className="rounded-lg border border-slate-200 bg-white p-4 text-sm">
        <h2 className="mb-1 font-semibold text-slate-900">Proveedor</h2>
        <p className="text-slate-700">{form.provider.name}{form.provider.trade_name ? ` (${form.provider.trade_name})` : ""} · RUC {form.provider.ruc ?? "—"}</p>
        <p className="text-slate-500">{form.provider.address}</p>
        <Field label="Sede donde contrató" className="mt-3 max-w-sm">
          <select name="sede_id" required className={inputCls} aria-label="Sede">{form.sedes.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
        </Field>
      </section>

      <fieldset className="rounded-lg border border-slate-200 bg-white p-4">
        <legend className="px-1 text-sm font-semibold text-slate-900">1. Consumidor reclamante</legend>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Nombre completo" className="sm:col-span-2"><input name="name" required minLength={3} maxLength={160} defaultValue={prefill.name} className={inputCls} aria-label="Nombre completo" /></Field>
          <Field label="Tipo de documento"><select name="doc_type" defaultValue={prefill.doc_type ?? "DNI"} className={inputCls} aria-label="Tipo de documento">{form.doc_types.map((d) => <option key={d}>{d}</option>)}</select></Field>
          <Field label="N° de documento"><input name="doc_number" required maxLength={20} defaultValue={prefill.doc_number} className={inputCls} aria-label="N° de documento" /></Field>
          <Field label="Domicilio" className="sm:col-span-2"><input name="address" required maxLength={255} defaultValue={prefill.address} className={inputCls} aria-label="Domicilio" /></Field>
          <Field label="Correo electrónico" hint="Aquí recibirá la constancia y la respuesta."><input name="email" type="email" required maxLength={160} defaultValue={prefill.email} className={inputCls} aria-label="Correo electrónico" /></Field>
          <Field label="Teléfono (opcional)"><input name="phone" maxLength={30} defaultValue={prefill.phone ?? ""} className={inputCls} /></Field>
        </div>
        <label className="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" checked={minor} onChange={(e) => setMinor(e.target.checked)} /> Soy menor de edad (lo registra mi padre, madre o apoderado)</label>
        {minor && (
          <div className="mt-3 grid gap-3 rounded-md bg-slate-50 p-3 sm:grid-cols-2">
            <Field label="Nombre del padre, madre o apoderado"><input name="g_name" required minLength={3} className={inputCls} aria-label="Nombre del apoderado" /></Field>
            <Field label="Relación"><input name="g_relation" defaultValue="Apoderado" className={inputCls} /></Field>
            <Field label="Tipo de documento"><select name="g_doc_type" className={inputCls}>{form.doc_types.map((d) => <option key={d}>{d}</option>)}</select></Field>
            <Field label="N° de documento"><input name="g_doc_number" required minLength={5} className={inputCls} aria-label="Documento del apoderado" /></Field>
          </div>
        )}
      </fieldset>

      <fieldset className="rounded-lg border border-slate-200 bg-white p-4">
        <legend className="px-1 text-sm font-semibold text-slate-900">2. Bien contratado</legend>
        <div className="grid gap-3 sm:grid-cols-4">
          <Field label="Tipo"><select name="good_kind" className={inputCls}><option>Servicio</option><option>Producto</option></select></Field>
          <Field label="Descripción" className="sm:col-span-3"><input name="good_description" required minLength={3} maxLength={255} defaultValue={prefill.good} className={inputCls} aria-label="Descripción del bien" /></Field>
          <Field label="Monto reclamado (opcional)"><input name="good_amount" type="number" step="0.01" min="0" className={inputCls} /></Field>
          <Field label="Moneda"><select name="good_currency" className={inputCls}><option>PEN</option><option>USD</option></select></Field>
        </div>
        {prefill.reservation && <p className="mt-2 text-xs text-slate-500">Se vinculará a su reserva {prefill.reservation}.</p>}
      </fieldset>

      <fieldset className="rounded-lg border border-slate-200 bg-white p-4">
        <legend className="px-1 text-sm font-semibold text-slate-900">3. Detalle de la reclamación</legend>
        <div className="mb-3 flex flex-col gap-2 text-sm sm:flex-row sm:gap-6">
          <label className="flex items-start gap-2"><input type="radio" name="type" value="reclamo" defaultChecked required /><span><strong>Reclamo</strong><span className="block text-xs text-slate-500">Disconformidad con el producto o servicio contratado.</span></span></label>
          <label className="flex items-start gap-2"><input type="radio" name="type" value="queja" /><span><strong>Queja</strong><span className="block text-xs text-slate-500">Malestar con la atención, no relacionado con el servicio.</span></span></label>
        </div>
        <Field label="Detalle" hint="Mínimo 20 caracteres."><textarea name="detail" required minLength={20} maxLength={5000} rows={4} className={inputCls} aria-label="Detalle" /></Field>
        <Field label="Pedido del consumidor" className="mt-3"><textarea name="request" required minLength={5} maxLength={2000} rows={2} className={inputCls} aria-label="Pedido" /></Field>
      </fieldset>

      {captcha && (
        <Field label={`Verificación: ${captcha.question}`} className="max-w-xs"><input name="captcha_answer" required inputMode="numeric" className={inputCls} aria-label="Verificación" /></Field>
      )}
      <label className="flex items-start gap-2 text-sm"><input type="checkbox" name="accept_email" required /> Acepto recibir la constancia y la respuesta por correo electrónico.</label>
      <p className="text-xs text-slate-500">
        La formulación del reclamo no impide acudir a otras vías de solución de controversias ni es requisito previo para interponer una denuncia ante el INDECOPI.
        El proveedor responderá en un plazo no mayor a {form.response_days} días hábiles.
      </p>
      {preview && <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">Vista previa interna: el formulario no registra hojas. Para registrar una hoja presencial use la bandeja del libro.</p>}
      <button className={btnCls} disabled={pending || preview}>{pending ? "Enviando…" : mode === "in_person" ? "Registrar hoja presencial" : "Enviar hoja de reclamación"}</button>
      {error && (
        <p role="alert" className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200">
          {error.message}{error.fields && <span className="mt-1 block text-xs">{Object.values(error.fields).flat().join(" · ")}</span>}
        </p>
      )}
      {done && (
        <div role="status" className="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900 ring-1 ring-emerald-200">
          <p className="font-semibold">Hoja {done.book_number} registrada.</p>
          <p>{done.message}{done.due_date ? ` Plazo de respuesta: hasta el ${done.due_date.split("-").reverse().join("/")}.` : ""}</p>
        </div>
      )}
    </form>
  );
}
