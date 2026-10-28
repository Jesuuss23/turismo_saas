"use client";

import { useActionState, useState } from "react";
import type { ReviewFormState } from "@/lib/own-review-actions";
import type { ReviewEligibility } from "@/lib/own-reviews";

const input = "w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-sky-600 focus:ring-2 focus:ring-sky-100";

/**
 * «Califica tu viaje» (spec 18 §2, RVW-04/05): estrellas obligatorias, aspectos opcionales (solo los activos), título ≥ 3, texto 20–2000,
 * hasta 3 fotos y el consentimiento para publicar desmarcado por defecto. Sirve al portal del pasajero y al enlace de la invitación.
 */
export function OwnReviewForm({ action, items, subratings, langs, hidden = {}, preview = false, en = false }: {
  action: (state: ReviewFormState, form: FormData) => Promise<ReviewFormState>;
  items: ReviewEligibility["items"]; subratings: Record<string, string>; langs: Record<string, string>; hidden?: Record<string, string>; preview?: boolean; en?: boolean;
}) {
  const [state, run, pending] = useActionState(action, undefined);
  const [idem] = useState(() => crypto.randomUUID());
  const [stars, setStars] = useState(0);
  const open = items.filter((i) => i.status === "pendiente");
  const err = (f: string) => state?.fieldErrors?.[f]?.[0];

  if (state?.ok) {
    return (
      <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" data-review-sent>
        {en ? "Thank you! Your review" : "¡Gracias! Su reseña"} {state.code} {en ? "was received" : "quedó registrada"}: <strong>{state.statusLabel}</strong>.
      </div>
    );
  }
  if (open.length === 0) return <p className="text-sm text-slate-500">{en ? "Nothing left to rate for now." : "No tiene servicios pendientes de calificar por ahora."}</p>;

  return (
    <form action={run} className="space-y-4" data-review-form>
      <input type="hidden" name="_idem" value={idem} />
      {Object.entries(hidden).map(([k, v]) => <input key={k} type="hidden" name={k} value={v} />)}
      {state?.error && <p role="alert" className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">{state.error}</p>}
      <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">{en ? "Service" : "Servicio"}</span>
        <select name="product" className={input} defaultValue={open[0].product.ref}>
          {open.map((i) => <option key={i.product.ref} value={i.product.ref}>{i.product.name}</option>)}
        </select>
      </label>
      <fieldset>
        <legend className="mb-1 text-sm font-medium text-slate-700">{en ? "Your rating" : "Su calificación"} *</legend>
        <div className="flex gap-1" role="radiogroup">
          {[1, 2, 3, 4, 5].map((n) => (
            <label key={n} className="cursor-pointer text-3xl leading-none" title={`${n}`}>
              <input type="radio" name="stars" value={n} required className="sr-only" aria-label={`${n} ${n === 1 ? "estrella" : "estrellas"}`} onChange={() => setStars(n)} />
              <span className={n <= stars ? "text-amber-500" : "text-slate-300"}>★</span>
            </label>
          ))}
        </div>
        {err("stars") && <p className="text-xs text-rose-700">{err("stars")}</p>}
      </fieldset>
      {Object.keys(subratings).length > 0 && (
        <fieldset>
          <legend className="mb-1 text-sm font-medium text-slate-700">{en ? "By aspect (optional)" : "Por aspecto (opcional)"}</legend>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {Object.entries(subratings).map(([k, l]) => (
              <label key={k} className="text-sm">{l}
                <select name={`subratings.${k}`} aria-label={l} defaultValue="" className={input}><option value="">—</option>{[5, 4, 3, 2, 1].map((n) => <option key={n} value={n}>{"★".repeat(n)}</option>)}</select>
              </label>
            ))}
          </div>
        </fieldset>
      )}
      <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">{en ? "Title" : "Título"} *</span>
        <input name="title" required minLength={3} maxLength={120} className={input} />
        {err("title") && <span className="text-xs text-rose-700">{err("title")}</span>}
      </label>
      <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">{en ? "Your review" : "Su reseña"} *</span>
        <textarea name="body" required minLength={20} maxLength={2000} rows={5} className={input} />
        <span className="text-xs text-slate-500">{en ? "20 to 2,000 characters. Do not include emails, phones or document numbers." : "De 20 a 2 000 caracteres. No incluya correos, teléfonos ni números de documento."}</span>
        {err("body") && <span className="block text-xs text-rose-700">{err("body")}</span>}
      </label>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">{en ? "Language" : "Idioma"}</span>
          <select name="lang" defaultValue={en ? "en" : "es"} className={input}>{Object.entries(langs).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        </label>
        <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">{en ? "Photos (up to 3, 2 MB each)" : "Fotos (hasta 3, 2 MB c/u)"}</span>
          <input name="photos" type="file" multiple accept="image/jpeg,image/png,image/webp" className="block w-full text-sm" />
          {err("photos") && <span className="text-xs text-rose-700">{err("photos")}</span>}
        </label>
      </div>
      <label className="flex items-start gap-2 text-sm">
        <input type="checkbox" name="consent_publish" className="mt-1" />
        <span>{en ? "I authorize publishing my review (first name and initial) on the website. Without it, only the agency will read it."
          : "Autorizo publicar mi reseña (nombre e inicial del apellido) en el sitio web. Si no la marco, solo la leerá la agencia."}</span>
      </label>
      <p className="text-xs text-slate-500">{en ? "Every review is published as written, positive or negative. No rewards are offered for reviewing."
        : "Las reseñas se publican tal cual, positivas o negativas. No hay premios ni descuentos por reseñar."}</p>
      <button disabled={pending || preview} title={preview ? "Vista previa de solo lectura" : undefined}
        className="rounded-md bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:cursor-not-allowed disabled:opacity-50">
        {pending ? (en ? "Sending…" : "Enviando…") : (en ? "Send review" : "Enviar reseña")}
      </button>
    </form>
  );
}
