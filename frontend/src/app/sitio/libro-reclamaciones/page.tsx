import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { api, ApiError } from "@/lib/api";
import { ClaimForm } from "@/components/claims/ClaimForm";
import type { ClaimFormData } from "@/lib/claims";
import { submitPublicClaim } from "./actions";

export const metadata: Metadata = { title: "Libro de Reclamaciones", description: "Hoja de reclamación virtual (Código de Protección y Defensa del Consumidor)." };

/** Libro de reclamaciones del sitio web público (spec 12 §2): sin sesión; el tenant se toma de `?tenant=` (o del dominio en producción). */
export default async function PublicClaimBookPage({ searchParams }: { searchParams: Promise<{ tenant?: string }> }) {
  const slug = (await searchParams).tenant ?? process.env.PUBLIC_TENANT_SLUG ?? "andes";
  let form: ClaimFormData & { captcha: { question: string; token: string } };
  try {
    form = (await api<{ data: typeof form }>(`/public/${encodeURIComponent(slug)}/claim-book`, { token: "" })).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) notFound();
    throw e;
  }
  const submit = submitPublicClaim.bind(null, slug);
  return (
    <main className="mx-auto max-w-3xl px-4 py-8">
      <h1 className="text-2xl font-semibold text-slate-900">Libro de reclamaciones</h1>
      <p className="mb-6 mt-1 text-sm text-slate-600">{form.provider.trade_name ?? form.provider.name} · Hoja de reclamación virtual</p>
      <ClaimForm form={form} mode="web" captcha={form.captcha} submitPublic={submit} />
    </main>
  );
}
