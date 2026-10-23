"use client";

import Link from "next/link";
import { useActionState, useState, useTransition } from "react";
import { confirmEnrollment, startEnrollment, verifyMfa, type FormState } from "@/app/actions";
import { Alert, Button, Field } from "@/components/ui";

export function VerifyForm() {
  const [state, action, pending] = useActionState(verifyMfa, undefined);
  return (
    <form action={action} className="mt-6 space-y-4">
      <Field label="Código de 6 dígitos o código de recuperación" name="code" required autoComplete="one-time-code" autoFocus />
      {state?.error && <Alert>{state.error}</Alert>}
      <Button type="submit" disabled={pending} className="w-full">Verificar</Button>
    </form>
  );
}

export function EnrollForm() {
  const [enrollment, setEnrollment] = useState<FormState>();
  const [loading, start] = useTransition();
  const [state, action, pending] = useActionState(confirmEnrollment, undefined);

  if (state?.recoveryCodes) {
    return (
      <div className="mt-6 space-y-4">
        <Alert kind="info">{state.info}</Alert>
        <ul className="grid grid-cols-2 gap-2 font-mono text-sm">
          {state.recoveryCodes.map((c) => <li key={c} className="rounded bg-slate-100 px-2 py-1">{c}</li>)}
        </ul>
        <Link href="/" className="block rounded-md bg-sky-700 px-4 py-2 text-center text-sm font-medium text-white">Continuar</Link>
      </div>
    );
  }

  if (!enrollment?.secret) {
    return (
      <div className="mt-6 space-y-4">
        <p className="text-sm text-slate-600">Su agencia exige verificación en dos pasos. Configure una app autenticadora (Google Authenticator, Microsoft Authenticator, 1Password…).</p>
        {enrollment?.error && <Alert>{enrollment.error}</Alert>}
        <Button className="w-full" disabled={loading} onClick={() => start(async () => setEnrollment(await startEnrollment()))}>Configurar MFA</Button>
      </div>
    );
  }

  return (
    <form action={action} className="mt-6 space-y-4">
      <p className="text-sm text-slate-600">Agregue esta clave en su app autenticadora y escriba el código que muestra.</p>
      <p className="break-all rounded bg-slate-100 px-3 py-2 font-mono text-sm">{enrollment.secret}</p>
      <a href={enrollment.otpauth} className="block text-sm text-sky-700 underline">Abrir en la app del teléfono</a>
      <Field label="Código de 6 dígitos" name="code" inputMode="numeric" pattern="\d{6}" required autoComplete="one-time-code" />
      {state?.error && <Alert>{state.error}</Alert>}
      <Button type="submit" disabled={pending} className="w-full">Confirmar</Button>
    </form>
  );
}
