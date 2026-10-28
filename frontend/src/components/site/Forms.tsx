"use client";

import { useState, useTransition } from "react";
import { submitContact, submitLead } from "@/app/sitio/actions";

const input = "mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm";
const INTERESTS = ["Cultura", "Aventura", "Naturaleza", "Gastronomía", "Místico", "Lujo", "Familia"];

function FieldError({ fields, name }: { fields?: Record<string, string[]>; name: string }) {
  const m = fields?.[name]?.[0];
  return m ? <p className="mt-1 text-xs text-rose-700">{m}</p> : null;
}

/** Viaje a medida (SIT-BR-22): crea un lead web sin asignar y muestra el código con el SLA de respuesta. */
export function LeadForm({ en, destinations, prefill }: { en: boolean; destinations: string[]; prefill: { destination?: string; product?: string; productName?: string; date?: string; pax?: number } }) {
  const [res, setRes] = useState<{ code: string; message: string; sla_due_at: string } | null>(null);
  const [err, setErr] = useState<{ error: string; fields?: Record<string, string[]> } | null>(null);
  const [undecided, setUndecided] = useState(!prefill.date);
  const [pending, start] = useTransition();
  if (res)
    return (
      <div role="status" className="rounded-2xl border border-emerald-200 bg-emerald-50 p-5" data-testid="lead-ok">
        <p className="text-lg font-semibold text-emerald-800">{en ? "Request received" : "Solicitud recibida"} · <span data-testid="lead-code">{res.code}</span></p>
        <p className="mt-1 text-sm text-emerald-900">{res.message}</p>
        <p className="mt-1 text-xs text-emerald-900">{en ? "Reply due by" : "Respuesta a más tardar"}: {new Date(res.sla_due_at).toLocaleString(en ? "en-US" : "es-PE", { timeZone: "America/Lima", dateStyle: "medium", timeStyle: "short" })}</p>
      </div>
    );
  return (
    <form
      className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2"
      onSubmit={(e) => {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        const s = (k: string) => String(f.get(k) ?? "").trim();
        start(async () => {
          const r = await submitLead({
            destinations: f.getAll("destinations").map(String), undecided_dates: undecided, start_date: s("start_date"), return_date: s("return_date") || null,
            flexibility: s("flexibility"), adults: Number(s("adults") || 0), children: Number(s("children") || 0), budget: Number(s("budget") || 0) || null,
            interests: f.getAll("interests").map(String), hotel: s("hotel"), service_mode: s("service_mode"), comments: s("comments"), product: prefill.product ?? null,
            name: s("name"), email: s("email"), phone: s("phone"), country: s("country"), preferred_contact: s("preferred_contact"), consent: f.get("consent") === "on",
          });
          if (r.ok) setRes(r.data);
          else setErr({ error: r.error, fields: r.fields });
        });
      }}
    >
      {prefill.productName && <p className="rounded-lg bg-sky-50 p-2 text-sm text-sky-900 sm:col-span-2">{en ? "Based on" : "Basado en"}: <strong>{prefill.productName}</strong></p>}
      <fieldset className="sm:col-span-2">
        <legend className="text-sm font-medium">{en ? "Destinations" : "Destinos"} *</legend>
        <div className="mt-1 flex flex-wrap gap-2">
          {destinations.map((d) => (
            <label key={d} className="flex items-center gap-1 rounded-full border border-slate-300 px-3 py-1 text-sm">
              <input type="checkbox" name="destinations" value={d} defaultChecked={prefill.destination === d} /> {d}
            </label>
          ))}
        </div>
        <FieldError fields={err?.fields} name="destinations" />
      </fieldset>
      <label className="flex items-center gap-2 text-sm sm:col-span-2">
        <input type="checkbox" checked={undecided} onChange={(e) => setUndecided(e.target.checked)} /> {en ? "I haven't decided the dates yet" : "Aún no defino fechas"}
      </label>
      {!undecided && (
        <>
          <label className="text-sm">{en ? "Start date" : "Fecha de inicio"} *<input name="start_date" type="date" defaultValue={prefill.date} className={input} /><FieldError fields={err?.fields} name="start_date" /></label>
          <label className="text-sm">{en ? "Return date" : "Fecha de regreso"}<input name="return_date" type="date" className={input} /><FieldError fields={err?.fields} name="return_date" /></label>
          <label className="text-sm">{en ? "Flexibility" : "Flexibilidad"}
            <select name="flexibility" className={input}><option value="exactas">{en ? "Exact dates" : "Fechas exactas"}</option><option value="+-3">± 3 {en ? "days" : "días"}</option><option value="+-7">± 7 {en ? "days" : "días"}</option></select>
          </label>
        </>
      )}
      <label className="text-sm">{en ? "Adults" : "Adultos"} *<input name="adults" type="number" min={1} defaultValue={prefill.pax ?? 2} className={input} /><FieldError fields={err?.fields} name="adults" /></label>
      <label className="text-sm">{en ? "Children" : "Niños"}<input name="children" type="number" min={0} defaultValue={0} className={input} /></label>
      <label className="text-sm">{en ? "Budget per person (US$)" : "Presupuesto por persona (US$)"}<input name="budget" type="number" min={0} step={50} className={input} /></label>
      <label className="text-sm">{en ? "Hotel category" : "Categoría de hotel"}
        <select name="hotel" className={input}><option value="">{en ? "No preference" : "Sin preferencia"}</option><option>3★</option><option>4★</option><option>5★ / boutique</option></select>
      </label>
      <label className="text-sm">{en ? "Service type" : "Tipo de servicio"}
        <select name="service_mode" className={input}><option value="compartido">{en ? "Shared" : "Compartido"}</option><option value="privado">{en ? "Private" : "Privado"}</option></select>
      </label>
      <fieldset className="sm:col-span-2">
        <legend className="text-sm font-medium">{en ? "Interests" : "Intereses"}</legend>
        <div className="mt-1 flex flex-wrap gap-2">
          {INTERESTS.map((i) => <label key={i} className="flex items-center gap-1 rounded-full border border-slate-300 px-3 py-1 text-sm"><input type="checkbox" name="interests" value={i} /> {i}</label>)}
        </div>
      </fieldset>
      <label className="text-sm sm:col-span-2">{en ? "Comments" : "Comentarios"}<textarea name="comments" rows={3} className={input} /></label>
      <label className="text-sm">{en ? "Full name" : "Nombre completo"} *<input name="name" autoComplete="name" className={input} /><FieldError fields={err?.fields} name="name" /></label>
      <label className="text-sm">{en ? "Email" : "Correo"} *<input name="email" type="email" autoComplete="email" className={input} /><FieldError fields={err?.fields} name="email" /></label>
      <label className="text-sm">{en ? "Phone / WhatsApp" : "Celular / WhatsApp"} *<input name="phone" autoComplete="tel" className={input} /><FieldError fields={err?.fields} name="phone" /></label>
      <label className="text-sm">{en ? "Country" : "País"}<input name="country" autoComplete="country-name" className={input} /></label>
      <label className="text-sm">{en ? "Preferred channel" : "Canal preferido"}
        <select name="preferred_contact" className={input}><option value="whatsapp">WhatsApp</option><option value="email">{en ? "Email" : "Correo"}</option><option value="llamada">{en ? "Phone call" : "Llamada"}</option></select>
      </label>
      <label className="flex items-start gap-2 text-xs text-slate-600 sm:col-span-2">
        <input type="checkbox" name="consent" className="mt-0.5" />
        <span>{en ? "I accept the processing of my personal data to receive a quote (Law 29733)." : "Acepto el tratamiento de mis datos personales para recibir una cotización (Ley 29733)."} *</span>
      </label>
      <FieldError fields={err?.fields} name="consent" />
      {err && <p role="alert" className="text-sm text-rose-700 sm:col-span-2">{err.error}</p>}
      <button disabled={pending} className="rounded-lg bg-[#0b1f3a] px-4 py-2 font-medium text-white disabled:opacity-60 sm:col-span-2 sm:justify-self-start">
        {pending ? "…" : en ? "Request my quote" : "Solicitar cotización"}
      </button>
    </form>
  );
}

const REASONS = ["Consulta general", "Quiero cotizar un viaje", "Tengo una reserva", "Pagos y comprobantes", "Otro"];

/** Contacto (SIT-BR-23): conversación en el inbox (canal Formulario) y lead si el motivo es «cotizar». */
export function ContactForm({ en }: { en: boolean }) {
  const [res, setRes] = useState<{ message: string; lead: string | null; conversation: string } | null>(null);
  const [err, setErr] = useState<{ error: string; fields?: Record<string, string[]> } | null>(null);
  const [pending, start] = useTransition();
  if (res)
    return (
      <div role="status" className="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900" data-testid="contact-ok">
        <p className="font-semibold">{res.message}</p>
        <p className="mt-1 text-sm">{en ? "Reference" : "Referencia"}: <strong data-testid="contact-ref">{res.conversation}</strong></p>
        {res.lead && <p className="mt-1 text-sm">{en ? "Quote request" : "Solicitud de cotización"}: <strong>{res.lead}</strong></p>}
      </div>
    );
  return (
    <form
      className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2"
      onSubmit={(e) => {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        const s = (k: string) => String(f.get(k) ?? "").trim();
        start(async () => {
          const r = await submitContact({ reason: s("reason"), reservation: s("reservation"), name: s("name"), email: s("email"), phone: s("phone") || null, message: s("message"), consent: f.get("consent") === "on" });
          if (r.ok) setRes(r.data);
          else setErr({ error: r.error, fields: r.fields });
        });
      }}
    >
      <label className="text-sm">{en ? "Reason" : "Motivo"}
        <select name="reason" className={input}>{REASONS.map((r) => <option key={r}>{r}</option>)}</select>
      </label>
      <label className="text-sm">{en ? "Booking number (optional)" : "Nº de reserva (opcional)"}<input name="reservation" placeholder="RES-2026-000123" className={input} /></label>
      <label className="text-sm">{en ? "Name" : "Nombre"} *<input name="name" autoComplete="name" className={input} /><FieldError fields={err?.fields} name="name" /></label>
      <label className="text-sm">{en ? "Email" : "Correo"} *<input name="email" type="email" autoComplete="email" className={input} /><FieldError fields={err?.fields} name="email" /></label>
      <label className="text-sm sm:col-span-2">{en ? "Phone (optional)" : "Celular (opcional)"}<input name="phone" autoComplete="tel" className={input} /></label>
      <label className="text-sm sm:col-span-2">{en ? "Message" : "Mensaje"} *<textarea name="message" rows={4} className={input} /><FieldError fields={err?.fields} name="message" /></label>
      <label className="flex items-start gap-2 text-xs text-slate-600 sm:col-span-2">
        <input type="checkbox" name="consent" className="mt-0.5" />
        <span>{en ? "I accept the processing of my personal data to answer my request (Law 29733)." : "Acepto el tratamiento de mis datos personales para atender mi consulta (Ley 29733)."} *</span>
      </label>
      {err && <p role="alert" className="text-sm text-rose-700 sm:col-span-2">{err.error}</p>}
      <button disabled={pending} className="rounded-lg bg-[#0b1f3a] px-4 py-2 font-medium text-white disabled:opacity-60 sm:col-span-2 sm:justify-self-start">{pending ? "…" : en ? "Send" : "Enviar"}</button>
    </form>
  );
}
