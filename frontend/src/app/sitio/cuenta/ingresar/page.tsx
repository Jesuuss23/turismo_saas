import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { LoginForm } from "@/components/site/AccountForms";
import { customer, prefs } from "@/lib/site";

export const metadata: Metadata = { title: "Ingresar", robots: { index: false } };

export default async function LoginPage({ searchParams }: { searchParams: Promise<{ next?: string }> }) {
  if (await customer()) redirect("/sitio/cuenta");
  const en = (await prefs()).locale === "en";
  return (
    <div className="mx-auto max-w-md px-4 py-10">
      <h1 className="text-2xl font-semibold">{en ? "Sign in" : "Ingresar a Mi cuenta"}</h1>
      <p className="mb-4 mt-1 text-sm text-slate-600">{en ? "Your bookings, vouchers and payments in one place." : "Sus reservas, vouchers y pagos en un solo lugar."}</p>
      <LoginForm en={en} next={(await searchParams).next} />
    </div>
  );
}
