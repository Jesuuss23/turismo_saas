import Link from "next/link";
import { VerifyForm } from "../forms";

export const metadata = { title: "Acceso al viaje · Turismo SaaS" };

export default async function PassengerVerifyPage({ searchParams }: { searchParams: Promise<{ token?: string; tenant?: string }> }) {
  const { token, tenant } = await searchParams;
  return (
    <>
      <h1 className="mt-2 text-xl font-semibold text-slate-900">Acceso a su viaje</h1>
      {token && tenant ? (
        <>
          <p className="mt-1 text-sm text-slate-500">El enlace es de un solo uso y vence a los 30 minutos.</p>
          <VerifyForm token={token} tenant={tenant} />
        </>
      ) : <p className="mt-2 text-sm text-rose-700">Enlace incompleto. <Link href="/portal-pasajero" className="underline">Solicite uno nuevo</Link>.</p>}
    </>
  );
}
