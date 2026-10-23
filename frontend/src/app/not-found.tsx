import Link from "next/link";

export default function NotFound() {
  return (
    <main className="min-h-screen grid place-items-center bg-slate-100 px-4">
      <section className="max-w-md space-y-4 rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        <p className="text-sm font-semibold text-slate-500">Error 404</p>
        <h1 className="text-xl font-semibold text-slate-900">La pantalla solicitada no existe</h1>
        <Link href="/" className="inline-block rounded-md bg-sky-700 px-4 py-2 text-sm font-medium text-white">Ir a mi inicio</Link>
      </section>
    </main>
  );
}
