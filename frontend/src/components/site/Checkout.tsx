"use client";

import { useMemo, useRef, useState, useTransition } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { createOrder, payOrder, validateStep } from "@/app/sitio/actions";
import { fmtDate, money, type Cart, type Customer, type Locale } from "@/lib/site-shared";
import { HoldTimer } from "./HoldTimer";
import { trackEvent } from "./Analytics";

type Pax = { type: "ADT" | "CHD" | "INF"; first_name: string; last_name: string; doc_type: string; doc_number: string; birth_date: string; nationality: string; diet: string };
type Fields = Record<string, string[]>;

const input = "mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm";

function F({ label, err, children }: { label: string; err?: string[]; children: React.ReactNode }) {
  return (
    <label className="block text-sm">
      <span className="font-medium text-slate-700">{label}</span>
      {children}
      {err && <span className="mt-0.5 block text-xs text-rose-700" role="alert">{err[0]}</span>}
    </label>
  );
}

/** Checkout en 5 pasos (spec 15 §2): Comprador → Pasajeros → Revisión → Pago → Confirmación, con resumen y temporizador del hold. */
export function Checkout({ cart, me, locale }: { cart: Cart; me: Customer | null; locale: Locale }) {
  const en = locale === "en";
  const router = useRouter();
  const steps = en ? ["Buyer", "Travelers", "Review", "Payment", "Confirmation"] : ["Comprador", "Pasajeros", "Revisión", "Pago", "Confirmación"];
  const [step, setStep] = useState(0);
  const [fields, setFields] = useState<Fields>({});
  const [error, setError] = useState<string | null>(null);
  const [pending, start] = useTransition();
  const [buyer, setBuyer] = useState({ name: me?.client.name ?? me?.name ?? "", email: me?.email ?? "", phone: me?.phone ?? "", country: me?.client.country ?? "Perú",
    doc_type: me?.client.doc_type === "PASAPORTE" ? "Pasaporte" : me?.client.doc_type ?? "DNI", doc_number: me?.client.doc_number ?? "" });
  const [billing, setBilling] = useState({ type: "Boleta", ruc: "", name: "", fiscal_address: "" });
  const need = useMemo(() => ({
    ADT: Math.max(...cart.lines.map((l) => l.adults)), CHD: Math.max(0, ...cart.lines.map((l) => l.children)), INF: Math.max(0, ...cart.lines.map((l) => l.infants)),
  }), [cart.lines]);
  const blank = (type: Pax["type"]): Pax => ({ type, first_name: "", last_name: "", doc_type: "DNI", doc_number: "", birth_date: "", nationality: "Perú", diet: "" });
  const [pax, setPax] = useState<Pax[]>(() => [...Array(need.ADT).fill("ADT"), ...Array(need.CHD).fill("CHD"), ...Array(need.INF).fill("INF")].map(blank));
  const [buyerTravels, setBuyerTravels] = useState(true);
  const [consents, setConsents] = useState({ terms: false, cancellation: false, privacy: false, marketing: false });
  const available = cart.pay_modes.filter((m) => m.available);
  const [mode, setMode] = useState<"total" | "anticipo">("total");
  const [method, setMethod] = useState<"card" | "transfer" | "yape" | "plin">("card");
  const [card, setCard] = useState({ number: "", holder: "", expiry: "", cvv: "" });
  const [opRef, setOpRef] = useState("");
  const [order, setOrder] = useState<{ code: string } | null>(null);
  const idemOrder = useRef(crypto.randomUUID());

  const passengers = () => pax.map((p, i) => {
    if (i === 0 && buyerTravels && p.type === "ADT" && !p.first_name) {
      const [first, ...rest] = buyer.name.trim().split(/\s+/);
      return { ...p, first_name: first ?? "", last_name: rest.join(" "), doc_type: buyer.doc_type, doc_number: buyer.doc_number, nationality: p.nationality || buyer.country };
    }
    return p;
  });
  const body = () => ({ buyer, billing: billing.type === "Factura" ? billing : { type: "Boleta" }, passengers: passengers(), consents, pay_mode: mode });

  function next() {
    const key = (["comprador", "pasajeros", "revision"] as const)[step];
    start(async () => {
      const r = await validateStep(key, body());
      if (!r.ok) {
        setFields(r.fields ?? {});
        setError(r.fields ? (en ? "Please review the highlighted fields." : "Revise los campos marcados.") : r.error);
        return;
      }
      setFields({});
      setError(null);
      if (step === 0) trackEvent("add_shipping_info", { currency: cart.currency, value: cart.total });
      if (step === 1) setPax(passengers());
      setStep(step + 1);
    });
  }

  function pay() {
    start(async () => {
      let current = order;
      if (!current) {
        const o = await createOrder(body(), idemOrder.current);
        if (!o.ok) {
          setFields(o.fields ?? {});
          setError(o.error);
          if (o.code === "SIT-BR-07") router.refresh();
          return;
        }
        current = { code: o.data.code };
        setOrder(current);
      }
      trackEvent("add_payment_info", { payment_type: method, currency: cart.currency, value: cart.total });
      const data = method === "card" ? { method: "card", card } : { method, operation_ref: opRef };
      const r = await payOrder(current.code, data, crypto.randomUUID());
      if (!r.ok) {
        setFields(r.fields ?? {});
        setError(r.error);
        return;
      }
      if (r.data.result === "rechazado") {
        trackEvent("payment_failed", { transaction_id: current.code });
        setError(`${r.data.message}. ${en ? "Your seats are still held; try another card." : "Sus cupos siguen bloqueados; intente con otra tarjeta."}`);
        return;
      }
      trackEvent(r.data.result === "aprobado" ? "purchase" : "purchase_pending", { transaction_id: current.code, currency: cart.currency, value: cart.total });
      setStep(4);
      router.push(`/sitio/confirmacion/${current.code}`);
    });
  }

  const pf = (i: number, k: keyof Pax) => fields[`passengers.${i}.${k}`];
  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
      <div className="min-w-0">
        <ol className="mb-4 flex flex-wrap gap-2 text-xs" aria-label={en ? "Steps" : "Pasos"}>
          {steps.map((s, i) => <li key={s} aria-current={i === step ? "step" : undefined} className={`rounded-full px-3 py-1 ${i === step ? "bg-[#0b1f3a] text-white" : i < step ? "bg-emerald-100 text-emerald-800" : "bg-slate-100 text-slate-500"}`}>{i + 1}. {s}</li>)}
        </ol>
        {!me && step === 0 && <p className="mb-3 rounded-lg bg-sky-50 px-3 py-2 text-sm">{en ? "Have an account?" : "¿Tiene cuenta?"} <Link href="/sitio/cuenta/ingresar?next=/sitio/checkout" className="font-medium text-sky-800 underline">{en ? "Sign in to autofill" : "Ingrese para autocompletar"}</Link></p>}

        {step === 0 && (
          <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2">
            <F label={en ? "Full name" : "Nombres y apellidos"} err={fields["buyer.name"]}><input className={input} value={buyer.name} onChange={(e) => setBuyer({ ...buyer, name: e.target.value })} /></F>
            <F label={en ? "Email" : "Correo"} err={fields["buyer.email"]}><input type="email" className={input} value={buyer.email} onChange={(e) => setBuyer({ ...buyer, email: e.target.value })} /></F>
            <F label={en ? "Mobile" : "Celular"} err={fields["buyer.phone"]}><input className={input} value={buyer.phone} onChange={(e) => setBuyer({ ...buyer, phone: e.target.value })} /></F>
            <F label={en ? "Country" : "País"} err={fields["buyer.country"]}><input className={input} value={buyer.country} onChange={(e) => setBuyer({ ...buyer, country: e.target.value })} /></F>
            <F label={en ? "ID type" : "Tipo de documento"}>
              <select className={input} value={buyer.doc_type} onChange={(e) => setBuyer({ ...buyer, doc_type: e.target.value })}><option>DNI</option><option>CE</option><option>Pasaporte</option></select>
            </F>
            <F label={en ? "ID number" : "Número de documento"} err={fields["buyer.doc_number"]}><input className={input} value={buyer.doc_number} onChange={(e) => setBuyer({ ...buyer, doc_number: e.target.value })} /></F>
            <fieldset className="sm:col-span-2">
              <legend className="text-sm font-medium text-slate-700">{en ? "Receipt" : "Comprobante"}</legend>
              <div className="mt-1 flex gap-4 text-sm">
                {["Boleta", "Factura"].map((x) => <label key={x} className="flex items-center gap-1"><input type="radio" name="billing" checked={billing.type === x} onChange={() => setBilling({ ...billing, type: x })} />{x}</label>)}
              </div>
            </fieldset>
            {billing.type === "Factura" && (
              <>
                <F label="RUC" err={fields["billing.ruc"]}><input className={input} inputMode="numeric" value={billing.ruc} onChange={(e) => setBilling({ ...billing, ruc: e.target.value })} /></F>
                <F label={en ? "Company name" : "Razón social"} err={fields["billing.name"]}><input className={input} value={billing.name} onChange={(e) => setBilling({ ...billing, name: e.target.value })} /></F>
                <div className="sm:col-span-2"><F label={en ? "Tax address" : "Dirección fiscal"} err={fields["billing.fiscal_address"]}><input className={input} value={billing.fiscal_address} onChange={(e) => setBilling({ ...billing, fiscal_address: e.target.value })} /></F></div>
              </>
            )}
          </section>
        )}

        {step === 1 && (
          <section className="space-y-3">
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={buyerTravels} onChange={(e) => setBuyerTravels(e.target.checked)} />{en ? "The buyer is also traveling (traveler 1)" : "El comprador también viaja (pasajero 1)"}</label>
            {fields.passengers && <p role="alert" className="text-sm text-rose-700">{fields.passengers[0]}</p>}
            {pax.map((p, i) => (
              <fieldset key={i} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-3" data-testid="passenger">
                <legend className="px-1 text-sm font-semibold">{en ? "Traveler" : "Pasajero"} {i + 1} · {p.type === "ADT" ? (en ? "Adult" : "Adulto") : p.type === "CHD" ? (en ? "Child" : "Niño") : (en ? "Infant" : "Infante")}</legend>
                {i === 0 && buyerTravels && p.type === "ADT" ? (
                  <p className="text-sm text-slate-600 sm:col-span-3">{buyer.name} · {buyer.doc_type} {buyer.doc_number}</p>
                ) : (
                  <>
                    <F label={en ? "First name(s)" : "Nombres"} err={pf(i, "first_name")}><input className={input} value={p.first_name} onChange={(e) => setPax(pax.map((x, j) => j === i ? { ...x, first_name: e.target.value } : x))} /></F>
                    <F label={en ? "Last name(s)" : "Apellidos"} err={pf(i, "last_name")}><input className={input} value={p.last_name} onChange={(e) => setPax(pax.map((x, j) => j === i ? { ...x, last_name: e.target.value } : x))} /></F>
                    <F label={en ? "ID type" : "Tipo de documento"}><select className={input} value={p.doc_type} onChange={(e) => setPax(pax.map((x, j) => j === i ? { ...x, doc_type: e.target.value } : x))}><option>DNI</option><option>CE</option><option>Pasaporte</option></select></F>
                    <F label={en ? "ID number" : "Nº de documento"} err={pf(i, "doc_number")}><input className={input} value={p.doc_number} onChange={(e) => setPax(pax.map((x, j) => j === i ? { ...x, doc_number: e.target.value } : x))} /></F>
                  </>
                )}
                <F label={en ? "Date of birth" : "Fecha de nacimiento"} err={pf(i, "birth_date")}><input type="date" className={input} value={p.birth_date} onChange={(e) => setPax(pax.map((x, j) => j === i ? { ...x, birth_date: e.target.value } : x))} /></F>
                <F label={en ? "Nationality" : "Nacionalidad"} err={pf(i, "nationality")}><input className={input} value={p.nationality} onChange={(e) => setPax(pax.map((x, j) => j === i ? { ...x, nationality: e.target.value } : x))} /></F>
                <F label={en ? "Dietary needs" : "Restricciones alimentarias"}><input className={input} value={p.diet} onChange={(e) => setPax(pax.map((x, j) => j === i ? { ...x, diet: e.target.value } : x))} /></F>
              </fieldset>
            ))}
          </section>
        )}

        {step === 2 && (
          <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 text-sm">
            <p><b>{buyer.name}</b> · {buyer.email} · {buyer.phone} · {billing.type}{billing.type === "Factura" ? ` RUC ${billing.ruc}` : ""}</p>
            <ul className="list-disc pl-5">{pax.map((p, i) => <li key={i}>{p.first_name} {p.last_name} · {p.type} · {p.doc_type} {p.doc_number} · {p.birth_date}</li>)}</ul>
            {[
              ["terms", en ? "I accept the terms and conditions." : "Acepto los términos y condiciones."],
              ["cancellation", en ? "I accept the cancellation policy of each product." : "Acepto las políticas de cancelación de cada producto."],
              ["privacy", en ? "I accept the processing of my personal data (Law 29733), including transfer to the providers of my trip." : "Acepto el tratamiento de mis datos personales (Ley 29733), incluida la transferencia a los proveedores de mi viaje."],
              ["marketing", en ? "(Optional) I want to receive offers by email." : "(Opcional) Quiero recibir ofertas por correo."],
            ].map(([k, label]) => (
              <label key={k} className="flex items-start gap-2">
                <input type="checkbox" className="mt-1" checked={consents[k as keyof typeof consents]} onChange={(e) => setConsents({ ...consents, [k]: e.target.checked })} />
                <span>{label}{fields[`consents.${k}`] && <span className="block text-xs text-rose-700" role="alert">{fields[`consents.${k}`][0]}</span>}</span>
              </label>
            ))}
            <p className="text-xs"><Link href="/sitio/legal/terminos-y-condiciones" target="_blank" className="underline">{en ? "Terms" : "Términos"}</Link> · <Link href="/sitio/legal/politica-de-privacidad" target="_blank" className="underline">{en ? "Privacy" : "Privacidad"}</Link> · <Link href="/sitio/legal/politica-de-cancelacion" target="_blank" className="underline">{en ? "Cancellation" : "Cancelación"}</Link></p>
          </section>
        )}

        {step === 3 && (
          <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 text-sm">
            <fieldset>
              <legend className="font-medium">{en ? "How much to pay now" : "¿Cuánto paga ahora?"}</legend>
              {cart.pay_modes.map((m) => (
                <label key={m.mode} className={`mt-1 flex items-start gap-2 ${m.available ? "" : "text-slate-400"}`}>
                  <input type="radio" name="mode" disabled={!m.available || !!order} checked={mode === m.mode} onChange={() => setMode(m.mode)} className="mt-1" />
                  <span>{m.mode === "total" ? (en ? "Full payment" : "Pago total") : `${en ? "Deposit" : "Anticipo"} ${m.pct} %`} · {money(m.amount, cart.currency)}
                    {m.balance_due && <span className="block text-xs text-slate-500">{en ? "Balance due" : "Saldo vence"} {fmtDate(m.balance_due, locale)}</span>}
                    {m.reason && <span className="block text-xs">{m.reason}</span>}
                  </span>
                </label>
              ))}
            </fieldset>
            <fieldset>
              <legend className="font-medium">{en ? "Payment method" : "Medio de pago"}</legend>
              <div className="mt-1 flex flex-wrap gap-3">
                {([["card", en ? "Card" : "Tarjeta"], ["transfer", en ? "Bank transfer" : "Transferencia"], ["yape", "Yape"], ["plin", "Plin"]] as const).map(([v, l]) => (
                  <label key={v} className="flex items-center gap-1"><input type="radio" name="method" checked={method === v} onChange={() => setMethod(v)} />{l}</label>
                ))}
              </div>
            </fieldset>
            {method === "card" ? (
              <div className="grid gap-3 sm:grid-cols-2">
                <F label={en ? "Card number" : "Número de tarjeta"} err={fields.number}><input className={input} inputMode="numeric" autoComplete="cc-number" value={card.number} onChange={(e) => setCard({ ...card, number: e.target.value })} /></F>
                <F label={en ? "Cardholder" : "Titular"}><input className={input} autoComplete="cc-name" value={card.holder} onChange={(e) => setCard({ ...card, holder: e.target.value })} /></F>
                <F label={en ? "Expiry (MM/YY)" : "Vencimiento (MM/AA)"} err={fields.expiry}><input className={input} placeholder="12/29" autoComplete="cc-exp" value={card.expiry} onChange={(e) => setCard({ ...card, expiry: e.target.value })} /></F>
                <F label="CVV"><input className={input} inputMode="numeric" autoComplete="cc-csc" value={card.cvv} onChange={(e) => setCard({ ...card, cvv: e.target.value })} /></F>
                <p className="text-xs text-slate-500 sm:col-span-2">{en ? "Sandbox: 4111 1111 1111 1111 approves; 4000 0000 0000 0002 is declined. We never store your card." : "Sandbox: 4111 1111 1111 1111 aprueba; 4000 0000 0000 0002 es rechazada. Nunca guardamos su tarjeta."}</p>
              </div>
            ) : (
              <div className="space-y-2">
                <p className="text-xs text-slate-600">{method === "transfer" ? (en ? "Transfer to BCP account 193-2456789-0-12 (Andes Travel Perú S.A.C.) and enter the operation number." : "Transfiera a la cuenta BCP 193-2456789-0-12 (Andes Travel Perú S.A.C.) e ingrese el número de operación.") : (en ? `Pay with ${method === "yape" ? "Yape" : "Plin"} to 984 123 456 and enter the operation code.` : `Pague con ${method === "yape" ? "Yape" : "Plin"} al 984 123 456 e ingrese el código de operación.`)}</p>
                <F label={en ? "Operation number" : "Número de operación"} err={fields.operation_ref}><input className={input} value={opRef} onChange={(e) => setOpRef(e.target.value)} /></F>
                <p className="text-xs text-slate-500">{en ? "Finance validates it within 4 business hours; your seats are reserved meanwhile." : "Finanzas lo valida en un máximo de 4 horas hábiles; sus cupos quedan reservados."}</p>
              </div>
            )}
          </section>
        )}

        {error && <p role="alert" className="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800" data-testid="checkout-error">{error}</p>}
        <div className="mt-4 flex flex-wrap gap-2">
          {step > 0 && step < 4 && !order && <button type="button" onClick={() => { setStep(step - 1); setError(null); }} className="rounded-lg border border-slate-300 px-4 py-2 text-sm">{en ? "Back" : "Atrás"}</button>}
          {step < 3 && <button type="button" disabled={pending} onClick={next} className="rounded-lg bg-[#0b1f3a] px-5 py-2 text-sm font-medium text-white disabled:opacity-60">{en ? "Continue" : "Continuar"}</button>}
          {step === 3 && <button type="button" disabled={pending} onClick={pay} className="rounded-lg bg-amber-400 px-5 py-2 text-sm font-semibold text-slate-900 disabled:opacity-60">{method === "card" ? (en ? "Pay" : "Pagar") : (en ? "Register payment" : "Registrar pago")} {money(mode === "anticipo" ? available.find((m) => m.mode === "anticipo")?.amount : cart.total, cart.currency)}</button>}
        </div>
      </div>
      <aside className="h-fit space-y-3 rounded-2xl border border-slate-200 bg-white p-4 text-sm">
        <HoldTimer until={cart.hold_expires_at} locale={locale} />
        <ul className="space-y-2">
          {cart.lines.map((l) => (
            <li key={l.line} className="flex justify-between gap-2">
              <span className="min-w-0"><span className="block font-medium">{l.product.name}</span><span className="text-xs text-slate-500">{fmtDate(l.date, locale)} · {l.adults + l.children + l.infants} pax</span></span>
              <span className="shrink-0">{money(l.total, cart.currency)}</span>
            </li>
          ))}
        </ul>
        {Number(cart.discount) > 0 && <p className="flex justify-between text-emerald-700"><span>{en ? "Discount" : "Descuento"}</span><span>−{money(cart.discount, cart.currency)}</span></p>}
        <p className="flex justify-between border-t border-slate-100 pt-2 text-base font-semibold"><span>Total</span><span data-testid="checkout-total">{money(cart.total, cart.currency)}</span></p>
        {order && <p className="text-xs text-slate-500">{en ? "Order" : "Pedido"} {order.code}</p>}
      </aside>
    </div>
  );
}
