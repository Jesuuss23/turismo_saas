"use client";

import Link from "@/components/Link";
import { useActionState } from "react";
import { acceptInvitation } from "@/app/actions";
import { Alert, Button, Field } from "@/components/ui";

export function InvitationForm({ token, requireMfa }: { token: string; requireMfa: boolean }) {
  const [state, action, pending] = useActionState(acceptInvitation, undefined);

  if (state?.info) {
    return (
      <div className="mt-6 space-y-4">
        <Alert kind="info">{state.info}</Alert>
        <Link href={`/login?tenant=${state.secret ?? ""}`} className="block rounded-md bg-sky-700 px-4 py-2 text-center text-sm font-medium text-white">Ir a ingresar</Link>
      </div>
    );
  }

  return (
    <form action={action} className="mt-6 space-y-4">
      <input type="hidden" name="token" value={token} />
      <Field label="Contraseña (mín. 10, mayúsculas, minúsculas y números)" name="password" type="password" required autoComplete="new-password" minLength={10} />
      <Field label="Repita la contraseña" name="password_confirmation" type="password" required autoComplete="new-password" />
      {requireMfa && <p className="text-xs text-slate-500">Su rol exige verificación en dos pasos: la configurará al ingresar por primera vez.</p>}
      {state?.error && <Alert>{state.error}</Alert>}
      <Button type="submit" disabled={pending} className="w-full">{pending ? "Activando…" : "Activar cuenta"}</Button>
    </form>
  );
}
