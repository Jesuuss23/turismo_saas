"use client";

import { useState, useTransition } from "react";
import { bookHandoff } from "@/app/widget/actions";
import type { HandoffCheckout } from "@/lib/experience";

const input = "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm";
const fmt = (v: string | null, c: string | null) => `${c ?? ""} ${Number(v ?? 0).toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** Datos del titular, recojo y comentarios; crea la reserva con el `quote_token` del traspaso (POST /public/bookings, idempotente). */
export function HandoffCheckoutForm({ h, en }: { h: HandoffCheckout; en: boolean }) {
  const [idem] = useState(() => crypto.randomUUID());
  const [pending, start] = useTransition();
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [point, setPoint] = useState(h.pickup.points[0]?.id ?? "");
  const [done, setDone] = useState<null | { reservation: string; total: string; currency: string; deposit: string; balance: string; balance_due: string | null; hold: { expires_at: string } }>(null);
  const L = en
    ? { holder: "Lead traveller", name: "Full name", email: "Email", phone: "Phone", doc: "Document", number: "Number", country: "Country", pickup: "Pickup", address: "Hotel address", flight: "Flight number", comments: "Comments", book: "Confirm booking", ok: "Booking created", hold: "Your seats are held until", pay: "We sent the payment link and details to your email." }
    : { holder: "Titular de la reserva", name: "Nombre completo", email: "Correo", phone: "Teléfono", doc: "Documento", number: "Número", country: "País", pickup: "Recojo", address: "Dirección del hotel", flight: "Número de vuelo", comments: "Comentarios", book: "Confirmar reserva", ok: "Reserva creada", hold: "Sus cupos quedan reservados hasta", pay: "Le enviamos al correo el enlace de pago y el detalle." };

  if (done) {
    return (
      <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4" data-booking={done.reservation}>
        <p className="text-lg font-semibold text-emerald-900">{L.ok}: <span className="font-mono">{done.reservation}</span></p>
        <p className="mt-1 text-sm text-emerald-900">{fmt(done.total, done.currency)} · {L.hold} {new Date(done.hold.expires_at).toLocaleString(en ? "en-US" : "es-PE", { timeZone: "America/Lima" })}.</p>
        <p className="mt-1 text-sm text-emerald-900">{L.pay}</p>
      </div>
    );
  }
  const err = (k: string) => fields[k]?.[0] && <span className="mt-0.5 block text-xs text-rose-700">{fields[k][0]}</span>;
  const selected = h.pickup.points.find((p) => p.id === point);

  return (
    <form className="space-y-3 rounded-xl border border-slate-200 bg-white p-4" onSubmit={(e) => {
      e.preventDefault();
      const f = new FormData(e.currentTarget);
      const v = (k: string) => String(f.get(k) ?? "");
      start(async () => {
        const r = await bookHandoff({
          quote_token: h.quote_token, handoff: h.code, currency_display: h.display.currency,
          contact: { name: v("name"), email: v("email"), phone: v("phone"), doc_type: v("doc_type"), doc_number: v("doc_number"), country: v("country") },
          pickup: point ? { point_id: point, address: v("address"), flight: v("flight") } : {}, comments: v("comments"),
        }, idem);
        if (r.ok) setDone(r.data);
        else { setError(r.error); setFields(r.fields ?? {}); }
      });
    }}>
      <h2 className="font-semibold">{L.holder}</h2>
      {error && <p role="alert" className="rounded bg-rose-50 px-3 py-2 text-sm text-rose-800">{error}</p>}
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block text-sm">{L.name}<input name="name" required minLength={3} autoComplete="name" className={input} />{err("contact.name")}</label>
        <label className="block text-sm">{L.email}<input name="email" type="email" required autoComplete="email" className={input} />{err("contact.email")}</label>
        <label className="block text-sm">{L.phone}<input name="phone" required autoComplete="tel" placeholder="+51 984 000 000" className={input} />{err("contact.phone")}</label>
        <div className="grid grid-cols-[7rem_minmax(0,1fr)] gap-2">
          <label className="block text-sm">{L.doc}<select name="doc_type" className={input}><option>DNI</option><option>CE</option><option>Pasaporte</option></select></label>
          <label className="block text-sm">{L.number}<input name="doc_number" required className={input} />{err("contact.doc_number")}</label>
        </div>
        <label className="block text-sm">{L.country}<input name="country" defaultValue={en ? "" : "Perú"} className={input} /></label>
      </div>
      {h.pickup.points.length > 0 && (
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">{L.pickup}
            <select value={point} onChange={(e) => setPoint(e.target.value)} required={h.pickup.required} className={input}>
              {!h.pickup.required && <option value="">—</option>}
              {h.pickup.points.map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}
            </select>{err("pickup.point_id")}
          </label>
          {selected?.requires_address && <label className="block text-sm">{L.address}<input name="address" required className={input} />{err("pickup.address")}</label>}
          {h.pickup.ask_flight && <label className="block text-sm">{L.flight}<input name="flight" maxLength={20} className={input} /></label>}
        </div>
      )}
      {h.comments && <label className="block text-sm">{L.comments}<textarea name="comments" rows={3} maxLength={1000} className={input} /></label>}
      <button disabled={pending} className="w-full rounded-lg bg-rose-700 px-4 py-2.5 font-medium text-white disabled:opacity-50">{pending ? "…" : L.book}</button>
    </form>
  );
}
