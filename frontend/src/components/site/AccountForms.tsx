"use client";

import { useRef, useState, useTransition } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { arco, login, logout, lookupOrder, payReceivable, register, updateMe } from "@/app/sitio/actions";
import { trackEvent } from "./Analytics";

const input = "mt-1 w-full rounded-lg border border-slate-300 px-2 py-2 text-sm";
type Fields = Record<string, string[]>;
const Err = ({ f, k }: { f: Fields; k: string }) => (f[k] ? <span role="alert" className="mt-0.5 block text-xs text-rose-700">{f[k][0]}</span> : null);

export function LoginForm({ en, next }: { en: boolean; next?: string }) {
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const [pending, start] = useTransition();
  const submit = (email: string, password: string) => start(async () => {
    const r = await login(email, password);
    if (!r.ok) return setError(r.error);
    trackEvent("login", { method: "email" });
    router.push(next && next.startsWith("/sitio") ? next : "/sitio/cuenta");
    router.refresh();
  });
  return (
    <form className="space-y-3" onSubmit={(e) => {
      e.preventDefault();
      const f = new FormData(e.currentTarget);
      submit(String(f.get("email")), String(f.get("password")));
    }}>
      <label className="block text-sm">{en ? "Email" : "Correo"}<input name="email" type="email" required autoComplete="email" className={input} /></label>
      <label className="block text-sm">{en ? "Password" : "Contraseña"}<input name="password" type="password" required autoComplete="current-password" className={input} /></label>
      {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
      <button disabled={pending} className="w-full rounded-lg bg-[#0b1f3a] py-2 font-medium text-white">{en ? "Sign in" : "Ingresar"}</button>
      <button type="button" disabled={pending} onClick={() => submit("cliente@correo.test", "Turismo2026!")} className="w-full rounded-lg border border-slate-300 py-2 text-sm">
        {en ? "Demo: sign in as Ana Gutiérrez (U14)" : "Demo: ingresar como Ana Gutiérrez (U14)"}
      </button>
      <p className="text-center text-sm">{en ? "New here?" : "¿Es nuevo?"} <Link href="/sitio/cuenta/registro" className="text-sky-700 underline">{en ? "Create an account" : "Cree su cuenta"}</Link></p>
    </form>
  );
}

export function RegisterForm({ en, email }: { en: boolean; email?: string }) {
  const router = useRouter();
  const [f, setF] = useState<Fields>({});
  const [error, setError] = useState<string | null>(null);
  const [pending, start] = useTransition();
  return (
    <form className="grid gap-3 sm:grid-cols-2" onSubmit={(e) => {
      e.preventDefault();
      const d = Object.fromEntries(new FormData(e.currentTarget));
      start(async () => {
        const r = await register({ ...d, privacy: d.privacy === "on", newsletter: d.newsletter === "on" });
        if (!r.ok) {
          setF(r.fields ?? {});
          return setError(r.error);
        }
        trackEvent("sign_up", { method: "email" });
        router.push("/sitio/cuenta");
        router.refresh();
      });
    }}>
      <label className="block text-sm sm:col-span-2">{en ? "Full name" : "Nombres y apellidos"}<input name="name" required className={input} /><Err f={f} k="name" /></label>
      <label className="block text-sm">{en ? "Email" : "Correo"}<input name="email" type="email" required defaultValue={email} className={input} /><Err f={f} k="email" /></label>
      <label className="block text-sm">{en ? "Mobile" : "Celular"}<input name="phone" required className={input} /><Err f={f} k="phone" /></label>
      <label className="block text-sm">{en ? "ID type" : "Tipo de documento"}<select name="doc_type" className={input}><option>DNI</option><option>CE</option><option>Pasaporte</option></select></label>
      <label className="block text-sm">{en ? "ID number" : "Número de documento"}<input name="doc_number" required className={input} /><Err f={f} k="doc_number" /></label>
      <label className="block text-sm">{en ? "Country" : "País"}<input name="country" defaultValue="Perú" className={input} /></label>
      <label className="block text-sm">{en ? "Password (min. 8)" : "Contraseña (mín. 8)"}<input name="password" type="password" minLength={8} required autoComplete="new-password" className={input} /><Err f={f} k="password" /></label>
      <label className="flex items-start gap-2 text-xs sm:col-span-2"><input type="checkbox" name="privacy" className="mt-0.5" /><span>{en ? "I accept the processing of my personal data (Law 29733)." : "Acepto el tratamiento de mis datos personales (Ley 29733)."}<Err f={f} k="privacy" /></span></label>
      <label className="flex items-start gap-2 text-xs sm:col-span-2"><input type="checkbox" name="newsletter" className="mt-0.5" /><span>{en ? "(Optional) Send me offers by email." : "(Opcional) Envíenme ofertas por correo."}</span></label>
      {error && <p role="alert" className="text-sm text-rose-700 sm:col-span-2">{error}</p>}
      <button disabled={pending} className="rounded-lg bg-[#0b1f3a] py-2 font-medium text-white sm:col-span-2">{en ? "Create account" : "Crear cuenta"}</button>
    </form>
  );
}

export function LookupForm({ en }: { en: boolean }) {
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const [pending, start] = useTransition();
  return (
    <form className="space-y-3" onSubmit={(e) => {
      e.preventDefault();
      const f = new FormData(e.currentTarget);
      start(async () => {
        const r = await lookupOrder(String(f.get("email")), String(f.get("code")).trim().toUpperCase());
        if (!r.ok) return setError(r.status === 404 ? (en ? "We couldn't find a booking with that email and code." : "No encontramos una reserva con ese correo y código.") : r.error);
        router.push(`/sitio/confirmacion/${r.data.code}`);
      });
    }}>
      <label className="block text-sm">{en ? "Email used to book" : "Correo con el que reservó"}<input name="email" type="email" required className={input} /></label>
      <label className="block text-sm">{en ? "Order (WEB-…) or booking (RES-…) code" : "Código de pedido (WEB-…) o reserva (RES-…)"}<input name="code" required className={input} /></label>
      {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
      <button disabled={pending} className="w-full rounded-lg bg-[#0b1f3a] py-2 font-medium text-white">{en ? "Find my booking" : "Buscar mi reserva"}</button>
    </form>
  );
}

export function LogoutButton({ en }: { en: boolean }) {
  const router = useRouter();
  const [pending, start] = useTransition();
  return <button disabled={pending} onClick={() => start(async () => { await logout(); router.push("/sitio"); router.refresh(); })} className="text-sm text-rose-700 hover:underline">{en ? "Sign out" : "Cerrar sesión"}</button>;
}

export function PayReceivable({ id, amount, en }: { id: string; amount: string; en: boolean }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [pending, start] = useTransition();
  const idem = useRef(crypto.randomUUID());
  if (!open) return <button onClick={() => setOpen(true)} className="rounded-lg bg-amber-400 px-3 py-1.5 text-sm font-semibold text-slate-900">{en ? "Pay" : "Pagar"} {amount}</button>;
  return (
    <form className="mt-2 grid gap-2 sm:grid-cols-4" onSubmit={(e) => {
      e.preventDefault();
      const f = Object.fromEntries(new FormData(e.currentTarget)) as Record<string, string>;
      start(async () => {
        const r = await payReceivable(id, f, idem.current);
        if (!r.ok) return setMsg({ ok: false, text: r.error });
        idem.current = crypto.randomUUID();
        setMsg({ ok: r.data.result !== "rechazado", text: r.data.message });
        if (r.data.result !== "rechazado") {
          trackEvent("purchase_balance", { value: amount });
          router.refresh();
        }
      });
    }}>
      <input name="number" required placeholder={en ? "Card number" : "Número de tarjeta"} aria-label={en ? "Card number" : "Número de tarjeta"} className="rounded border border-slate-300 px-2 py-1.5 text-sm sm:col-span-2" />
      <input name="expiry" required placeholder="MM/AA" aria-label={en ? "Expiry" : "Vencimiento"} className="rounded border border-slate-300 px-2 py-1.5 text-sm" />
      <input name="cvv" required placeholder="CVV" aria-label="CVV" className="rounded border border-slate-300 px-2 py-1.5 text-sm" />
      <button disabled={pending} className="rounded-lg bg-[#0b1f3a] px-3 py-1.5 text-sm text-white sm:col-span-4">{en ? "Confirm payment" : "Confirmar pago"}</button>
      {msg && <p role="status" className={`text-sm sm:col-span-4 ${msg.ok ? "text-emerald-700" : "text-rose-700"}`}>{msg.text}</p>}
    </form>
  );
}

export function ProfileForm({ en, phone, country, newsletter }: { en: boolean; phone: string | null; country: string | null; newsletter: boolean }) {
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [pending, start] = useTransition();
  return (
    <form className="grid gap-3 sm:grid-cols-2" onSubmit={(e) => {
      e.preventDefault();
      const f = new FormData(e.currentTarget);
      start(async () => {
        const r = await updateMe({ phone: f.get("phone"), country: f.get("country"), newsletter: f.get("newsletter") === "on" });
        setMsg(r.ok ? { ok: true, text: en ? "Saved." : "Datos guardados." } : { ok: false, text: r.error });
      });
    }}>
      <label className="block text-sm">{en ? "Mobile" : "Celular"}<input name="phone" defaultValue={phone ?? ""} className={input} /></label>
      <label className="block text-sm">{en ? "Country" : "País"}<input name="country" defaultValue={country ?? ""} className={input} /></label>
      <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" name="newsletter" defaultChecked={newsletter} />{en ? "Newsletter (offers by email)" : "Boletín (ofertas por correo)"}</label>
      {msg && <p role="status" className={`text-sm sm:col-span-2 ${msg.ok ? "text-emerald-700" : "text-rose-700"}`}>{msg.text}</p>}
      <button disabled={pending} className="rounded-lg bg-[#0b1f3a] py-2 text-sm text-white sm:col-span-2">{en ? "Save" : "Guardar"}</button>
    </form>
  );
}

export function ArcoForm({ en }: { en: boolean }) {
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [pending, start] = useTransition();
  return (
    <form className="space-y-2" onSubmit={(e) => {
      e.preventDefault();
      const f = new FormData(e.currentTarget);
      start(async () => {
        const r = await arco({ type: f.get("type"), detail: f.get("detail") });
        setMsg(r.ok ? { ok: true, text: `${r.data.ticket} · ${r.data.message}` } : { ok: false, text: r.error });
      });
    }}>
      <select name="type" aria-label={en ? "Right" : "Derecho"} className={input}>
        <option value="acceso">{en ? "Access" : "Acceso"}</option><option value="rectificacion">{en ? "Rectification" : "Rectificación"}</option>
        <option value="cancelacion">{en ? "Cancellation" : "Cancelación"}</option><option value="oposicion">{en ? "Objection" : "Oposición"}</option>
      </select>
      <textarea name="detail" rows={3} required aria-label={en ? "Detail" : "Detalle"} placeholder={en ? "Describe your request" : "Describa su solicitud"} className={input} />
      {msg && <p role="status" className={`text-sm ${msg.ok ? "text-emerald-700" : "text-rose-700"}`}>{msg.text}</p>}
      <button disabled={pending} className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">{en ? "Send ARCO request" : "Enviar solicitud ARCO"}</button>
    </form>
  );
}
