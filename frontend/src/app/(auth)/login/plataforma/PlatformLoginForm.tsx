"use client";

import { useActionState } from "react";
import { platformLogin } from "@/app/actions";
import { Alert, Button, Field } from "@/components/ui";

export function PlatformLoginForm() {
  const [state, action, pending] = useActionState(platformLogin, undefined);

  return (
    <form action={action} className="mt-6 space-y-4">
      <Field label="Correo" name="email" type="email" required autoComplete="username" />
      <Field label="Contraseña" name="password" type="password" required autoComplete="current-password" />
      {state?.error && <Alert>{state.error}</Alert>}
      <Button type="submit" disabled={pending} className="w-full">{pending ? "Ingresando…" : "Ingresar a la consola"}</Button>
    </form>
  );
}
