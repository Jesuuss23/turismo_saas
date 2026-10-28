import type { Metadata } from "next";
import { RegisterForm } from "@/components/site/AccountForms";
import { prefs } from "@/lib/site";

export const metadata: Metadata = { title: "Crear cuenta", robots: { index: false } };

export default async function RegisterPage({ searchParams }: { searchParams: Promise<{ email?: string }> }) {
  const en = (await prefs()).locale === "en";
  return (
    <div className="mx-auto max-w-xl px-4 py-10">
      <h1 className="mb-4 text-2xl font-semibold">{en ? "Create your account" : "Cree su cuenta"}</h1>
      <RegisterForm en={en} email={(await searchParams).email} />
    </div>
  );
}
