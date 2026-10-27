import Link from "next/link";
import { RequestLinkForm } from "./forms";

export const metadata = { title: "Portal del pasajero · Turismo SaaS" };

/** Acceso del pasajero sin contraseña (spec 08 §6.7): correo + código de reserva → enlace de un solo uso. */
export default async function PassengerAccessPage({ searchParams }: { searchParams: Promise<{ tenant?: string }> }) {
  const { tenant } = await searchParams;
  return (
    <>
      <h1 className="mt-2 text-xl font-semibold text-slate-900">Portal del pasajero</h1>
      <p className="mt-1 text-sm text-slate-500">Ingrese el correo con el que reservó y su código de reserva; le enviaremos un enlace de acceso.</p>
      <RequestLinkForm tenant={tenant} />
      <p className="mt-4 text-center text-xs text-slate-500">¿Es parte del equipo? <Link href="/login" className="text-sky-700 hover:underline">Ingresar con contraseña</Link></p>
    </>
  );
}
