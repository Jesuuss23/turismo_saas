import Link from "@/components/Link";
import { PlatformLoginForm } from "./PlatformLoginForm";

export const metadata = { title: "Consola de plataforma · Turismo SaaS" };

export default function PlatformLoginPage() {
  return (
    <>
      <h1 className="mt-2 text-xl font-semibold text-slate-900">Consola de plataforma</h1>
      <p className="mt-1 text-sm text-slate-500">Acceso exclusivo del Super Admin SaaS. Siempre exige verificación en dos pasos.</p>
      <PlatformLoginForm />
      <p className="mt-4 text-center text-xs text-slate-500">
        ¿Usuario de una agencia? <Link href="/login" className="text-sky-800 hover:underline">Ingresar a su agencia</Link>
      </p>
    </>
  );
}
