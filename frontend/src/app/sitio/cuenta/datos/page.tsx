import type { Metadata } from "next";
import { AccountShell } from "@/components/site/AccountShell";
import { ArcoForm, ProfileForm } from "@/components/site/AccountForms";

export const metadata: Metadata = { title: "Datos personales", robots: { index: false } };

export default function ProfilePage() {
  return (
    <AccountShell active="/sitio/cuenta/datos">
      {(me, en) => (
        <section className="space-y-6">
          <div className="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 className="text-lg font-semibold">{en ? "Personal data" : "Datos personales"}</h2>
            <p className="mt-1 text-sm text-slate-600">{me.client.name} · {me.client.doc_type} {me.client.doc_number} · {me.email}</p>
            <div className="mt-3"><ProfileForm en={en} phone={me.phone} country={me.client.country} newsletter={me.newsletter} /></div>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 className="font-semibold">{en ? "Your ARCO rights (Law 29733)" : "Sus derechos ARCO (Ley 29733)"}</h2>
            <p className="mb-2 text-sm text-slate-600">{en ? "Access, rectification, cancellation or objection to the processing of your data." : "Acceso, rectificación, cancelación u oposición al tratamiento de sus datos."}</p>
            <ArcoForm en={en} />
          </div>
        </section>
      )}
    </AccountShell>
  );
}
