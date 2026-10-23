import Link from "@/components/Link";
import { RequestAccess } from "./RequestAccess";

export const metadata = { title: "Acceso restringido · Turismo SaaS" };

type Params = { path?: string; reason?: string; required?: string; home?: string; request?: string };

/** Pantalla 403 (spec 00 §2). El intento ya quedó auditado por el backend al decidir la ruta. */
export default async function ForbiddenPage({ searchParams }: { searchParams: Promise<Params> }) {
  const { path = "", reason, required, home, request } = await searchParams;
  const permissions = required ? required.split(",") : [];

  return (
    <main className="min-h-screen grid place-items-center bg-slate-100 px-4">
      <section className="w-full max-w-lg space-y-5 rounded-xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
        <div>
          <p className="text-sm font-semibold text-red-700">Error 403 · Acceso restringido</p>
          <h1 className="mt-1 text-xl font-semibold text-slate-900">No tiene acceso a esta pantalla</h1>
          <p className="mt-2 text-sm text-slate-600">
            {permissions.length > 0 ? "Su rol no tiene ninguno de los permisos que exige esta pantalla." : reason}
          </p>
        </div>

        {permissions.length > 0 && (
          <div>
            <p className="text-sm font-medium text-slate-700">Permisos requeridos (cualquiera de):</p>
            <ul className="mt-1 flex flex-wrap gap-2">
              {permissions.map((p) => <li key={p} className="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs">{p}</li>)}
            </ul>
          </div>
        )}

        <p className="text-sm text-slate-600">Ruta solicitada: <span className="font-mono">/{path}</span></p>
        <p className="text-xs text-slate-500">El intento quedó registrado en auditoría.</p>

        <div className="flex flex-wrap items-start gap-3">
          <Link href={home ? `/${home}` : "/"} className="rounded-md bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800">Ir a mi inicio</Link>
          {request === "1" && <RequestAccess path={path} />}
        </div>
      </section>
    </main>
  );
}
