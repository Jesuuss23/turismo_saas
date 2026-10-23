import { EnrollForm, VerifyForm } from "./MfaForms";

export const metadata = { title: "Verificación MFA · Turismo SaaS" };

export default async function MfaPage({ searchParams }: { searchParams: Promise<{ mode?: string }> }) {
  const { mode } = await searchParams;

  return (
    <>
      <h1 className="mt-2 text-xl font-semibold text-slate-900">Verificación en dos pasos</h1>
      {mode === "enroll" ? <EnrollForm /> : <VerifyForm />}
    </>
  );
}
