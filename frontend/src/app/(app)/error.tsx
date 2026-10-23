"use client";

/** Error al cargar una pantalla del back-office (p. ej. 429 por rate limit o backend caído). */
export default function AppError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <section className="mx-auto max-w-lg rounded-lg border border-rose-200 bg-rose-50 p-6 text-center">
      <h1 className="text-lg font-semibold text-rose-900">No se pudo cargar esta pantalla</h1>
      <p className="mt-2 text-sm text-rose-900">Puede deberse a demasiadas solicitudes seguidas o a una falla temporal del servidor. Espere unos segundos e intente de nuevo.</p>
      {error.digest && <p className="mt-2 text-xs text-rose-700">Referencia: {error.digest}</p>}
      <button onClick={reset} className="mt-4 rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800">Reintentar</button>
    </section>
  );
}
