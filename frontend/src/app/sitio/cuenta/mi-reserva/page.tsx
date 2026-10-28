import type { Metadata } from "next";
import { LookupForm } from "@/components/site/AccountForms";
import { prefs } from "@/lib/site";

export const metadata: Metadata = { title: "Gestionar mi reserva", robots: { index: false } };

export default async function LookupPage() {
  const en = (await prefs()).locale === "en";
  return (
    <div className="mx-auto max-w-md px-4 py-10">
      <h1 className="text-2xl font-semibold">{en ? "Manage my booking" : "Gestionar mi reserva"}</h1>
      <p className="mb-4 mt-1 text-sm text-slate-600">{en ? "No account needed: enter the email you booked with and your code." : "Sin cuenta: ingrese el correo con el que reservó y su código."}</p>
      <LookupForm en={en} />
    </div>
  );
}
