import type { Metadata } from "next";
import Link from "next/link";
import { ApiError } from "@/lib/api";
import { siteApi } from "@/lib/site";

export const metadata: Metadata = { title: "Confirmar suscripción", robots: { index: false } };

/** Doble opt-in del boletín (SIT-BR-25): el enlace del correo confirma la suscripción. */
export default async function ConfirmNewsletterPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  let r: { ok: true; message: string; email: string } | { ok: false; message: string };
  try {
    const x = (await siteApi<{ data: { message: string; email: string } }>(`newsletter/confirm/${encodeURIComponent(token)}`, { session: false, method: "POST" })).data;
    r = { ok: true, ...x };
  } catch (e) {
    r = { ok: false, message: e instanceof ApiError && e.status === 404 ? "El enlace no es válido o ya caducó." : "No pudimos confirmar su suscripción. Intente nuevamente." };
  }
  return (
    <div className="mx-auto max-w-xl px-4 py-16 text-center">
      <h1 className="text-2xl font-semibold">{r.ok ? "Suscripción confirmada" : "No se pudo confirmar"}</h1>
      <p className="mt-2 text-slate-600" data-testid="newsletter-status">{r.message}{r.ok ? ` (${r.email})` : ""}</p>
      <Link href="/sitio" className="mt-6 inline-block rounded-lg bg-[#0b1f3a] px-4 py-2 text-white">Volver al inicio</Link>
    </div>
  );
}
