import type { Metadata } from "next";
import { prefs, siteInfo } from "@/lib/site";
import { ContactForm } from "@/components/site/Forms";

export const metadata: Metadata = { title: "Contacto", description: "Escríbanos: consultas, cotizaciones y atención de reservas por WhatsApp, teléfono y correo." };

/** Contacto (SIT-BR-23): formulario al inbox + canales de atención y oficinas. */
export default async function ContactPage() {
  const { locale } = await prefs();
  const en = locale === "en";
  const site = await siteInfo();
  return (
    <div className="mx-auto max-w-5xl px-4 py-8">
      <h1 className="text-2xl font-semibold">{en ? "Contact" : "Contacto"}</h1>
      <div className="mt-6 grid gap-8 lg:grid-cols-[1fr_300px]">
        <div className="min-w-0"><ContactForm en={en} /></div>
        <aside className="space-y-4 text-sm">
          <div className="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 className="font-semibold">{en ? "Customer service" : "Canales de atención"}</h2>
            <p className="mt-2">WhatsApp: <span className="select-all">{site.whatsapp}</span></p>
            <p>{en ? "Phone" : "Teléfono"}: <span className="select-all">{site.phone}</span></p>
            <p>{en ? "Emergencies 24/7" : "Emergencias 24/7"}: <span className="select-all">{site.emergency}</span></p>
            {site.email && <p>{en ? "Email" : "Correo"}: <span className="select-all">{site.email}</span></p>}
            <p className="mt-1 text-slate-600">{site.hours}</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 className="font-semibold">{en ? "Offices" : "Oficinas"}</h2>
            {site.sedes.map((s) => <p key={s.code} className="mt-2"><strong>{s.name}</strong><br />{[s.address, s.city].filter(Boolean).join(", ")}</p>)}
          </div>
        </aside>
      </div>
    </div>
  );
}
