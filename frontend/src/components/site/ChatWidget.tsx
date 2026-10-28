"use client";

import { useEffect, useRef, useState, useTransition } from "react";
import { chatMessages, chatPost, chatStart } from "@/app/sitio/actions";

type Msg = { from: string; body: string; at: string };

/** Chat flotante (spec 15 §2): abre una conversación «Web chat» en el inbox con respuesta automática; muestra solo mensajes, nunca notas internas. */
export function ChatWidget({ locale }: { locale: "es" | "en" }) {
  const [open, setOpen] = useState(false);
  const [session, setSession] = useState<string | null>(null);
  const [msgs, setMsgs] = useState<Msg[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [pending, start] = useTransition();
  const box = useRef<HTMLDivElement>(null);
  const en = locale === "en";

  useEffect(() => {
    try {
      const s = sessionStorage.getItem("ah_chat");
      if (s) setSession(s);
    } catch {}
  }, []);
  useEffect(() => {
    if (!open || !session) return;
    let alive = true;
    const load = () => chatMessages(session).then((r) => alive && r.ok && setMsgs(r.data));
    load();
    const id = setInterval(load, 8000);
    return () => {
      alive = false;
      clearInterval(id);
    };
  }, [open, session]);
  useEffect(() => box.current?.scrollTo(0, box.current.scrollHeight), [msgs]);

  return (
    <div className="fixed bottom-4 right-4 z-40 flex max-w-[calc(100vw-2rem)] flex-col items-end gap-2">
      {open && (
        <section aria-label="Chat" className="w-80 max-w-full rounded-2xl border border-slate-200 bg-white shadow-xl">
          <header className="flex items-center justify-between rounded-t-2xl bg-[#0b1f3a] px-4 py-2 text-white">
            <p className="text-sm font-semibold">{en ? "Chat with an advisor" : "Chatee con un asesor"}</p>
            <button onClick={() => setOpen(false)} aria-label={en ? "Close chat" : "Cerrar chat"} className="text-lg leading-none">×</button>
          </header>
          {session ? (
            <div className="p-3">
              <div ref={box} className="max-h-64 space-y-2 overflow-y-auto text-sm" data-testid="chat-messages">
                {msgs.map((m, i) => (
                  <p key={i} className={`rounded-lg px-3 py-2 ${m.from === "contact" ? "ml-6 bg-sky-50" : "mr-6 bg-slate-100"}`}>
                    {m.from === "system" && <span className="block text-[11px] font-semibold text-slate-500">{en ? "Automatic reply" : "Respuesta automática"}</span>}
                    {m.from === "agent" && <span className="block text-[11px] font-semibold text-slate-500">{en ? "Advisor" : "Asesor"}</span>}
                    {m.body}
                  </p>
                ))}
              </div>
              <form className="mt-2 flex gap-2" onSubmit={(e) => {
                e.preventDefault();
                const input = e.currentTarget.elements.namedItem("m") as HTMLInputElement;
                const text = input.value.trim();
                if (!text) return;
                start(async () => {
                  const r = await chatPost(session, text);
                  if (r.ok) {
                    setMsgs(r.data);
                    input.value = "";
                  } else setError(r.error);
                });
              }}>
                <input name="m" aria-label={en ? "Message" : "Mensaje"} className="min-w-0 flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm" />
                <button disabled={pending} className="rounded-lg bg-[#0b1f3a] px-3 text-sm text-white">{en ? "Send" : "Enviar"}</button>
              </form>
            </div>
          ) : (
            <form className="space-y-2 p-3 text-sm" onSubmit={(e) => {
              e.preventDefault();
              const f = new FormData(e.currentTarget);
              start(async () => {
                const r = await chatStart({ name: f.get("name"), contact: f.get("contact"), message: f.get("message"), consent: f.get("consent") === "on" });
                if (!r.ok) return setError(r.error);
                setError(null);
                setSession(r.data.session);
                setMsgs(r.data.messages);
                try {
                  sessionStorage.setItem("ah_chat", r.data.session);
                } catch {}
              });
            }}>
              <input name="name" required placeholder={en ? "Name" : "Nombre"} aria-label={en ? "Name" : "Nombre"} className="w-full rounded-lg border border-slate-300 px-2 py-1.5" />
              <input name="contact" required placeholder={en ? "Email or WhatsApp" : "Correo o WhatsApp"} aria-label={en ? "Email or WhatsApp" : "Correo o WhatsApp"} className="w-full rounded-lg border border-slate-300 px-2 py-1.5" />
              <textarea name="message" required rows={3} placeholder={en ? "How can we help?" : "¿En qué le ayudamos?"} aria-label={en ? "Your question" : "Su consulta"} className="w-full rounded-lg border border-slate-300 px-2 py-1.5" />
              <label className="flex items-start gap-2 text-xs text-slate-600">
                <input type="checkbox" name="consent" className="mt-0.5" />
                <span>{en ? "I accept the processing of my data to answer my question (Law 29733)." : "Acepto el tratamiento de mis datos para atender mi consulta (Ley 29733)."}</span>
              </label>
              {error && <p role="alert" className="text-xs text-rose-700">{error}</p>}
              <button disabled={pending} className="w-full rounded-lg bg-[#0b1f3a] py-2 font-medium text-white">{en ? "Start chat" : "Iniciar chat"}</button>
            </form>
          )}
        </section>
      )}
      <button onClick={() => setOpen((o) => !o)} className="rounded-full bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-lg" aria-expanded={open}>
        💬 {en ? "Chat" : "Chat"}
      </button>
    </div>
  );
}
