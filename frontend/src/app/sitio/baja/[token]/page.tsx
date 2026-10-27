import { notFound } from "next/navigation";
import { api, ApiError } from "@/lib/api";
import { confirmUnsubscribe } from "./actions";

type Payload = { agency: string | null; channel: string; channel_label: string; name: string; address: string; status: string; revoked_at: string | null };

/** Página pública de baja (spec 14, MKT-BR-10): un clic, sin sesión ni datos adicionales; idempotente. */
export default async function UnsubscribePage({ params, searchParams }: { params: Promise<{ token: string }>; searchParams: Promise<{ ok?: string }> }) {
  const { token } = await params;
  const { ok } = await searchParams;
  let p: Payload;
  try {
    p = (await api<{ data: Payload }>(`/public/unsubscribe/${encodeURIComponent(token)}`, { token: "" })).data;
  } catch (e) {
    if (e instanceof ApiError && (e.status === 404 || e.status === 422)) notFound();
    throw e;
  }
  const done = p.status === "revocado";
  const confirm = confirmUnsubscribe.bind(null, token);

  return (
    <main className="mx-auto max-w-lg px-4 py-12">
      <section className="rounded-lg border border-slate-200 bg-white p-6 text-center">
        <p className="text-sm text-slate-500">{p.agency}</p>
        {done ? (
          <>
            <h1 className="mt-2 text-xl font-semibold text-slate-900">{ok ? "Listo: tu baja quedó registrada" : "Ya no recibes estas comunicaciones"}</h1>
            <p className="mt-2 text-sm text-slate-600">Hola {p.name}, no te enviaremos más comunicaciones comerciales por {p.channel_label} a {p.address}.
              Te enviamos una confirmación.</p>
          </>
        ) : (
          <>
            <h1 className="mt-2 text-xl font-semibold text-slate-900">¿Dejar de recibir comunicaciones por {p.channel_label}?</h1>
            <p className="mt-2 text-sm text-slate-600">Hola {p.name}, confirma la baja de {p.address}. Seguirás recibiendo los mensajes de servicio de tus viajes.</p>
            <form action={confirm} className="mt-5">
              <button className="rounded-md bg-rose-700 px-4 py-2 text-sm font-medium text-white hover:bg-rose-800">Confirmar baja</button>
            </form>
          </>
        )}
      </section>
    </main>
  );
}
