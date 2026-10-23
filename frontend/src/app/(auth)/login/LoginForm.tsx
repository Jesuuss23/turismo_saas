"use client";

import { useActionState } from "react";
import { login } from "@/app/actions";
import { Alert, Button, Field } from "@/components/ui";

export function LoginForm({ next, tenant }: { next?: string; tenant?: string }) {
  const [state, action, pending] = useActionState(login, undefined);

  return (
    <form action={action} className="mt-6 space-y-4">
      <Field label="Agencia (tenant)" name="tenant" defaultValue={tenant} placeholder="andes" required autoComplete="organization" />
      <Field label="Correo" name="email" type="email" required autoComplete="username" />
      <Field label="Contraseña" name="password" type="password" required autoComplete="current-password" />
      {next && <input type="hidden" name="next" value={next} />}
      {state?.error && <Alert>{state.error}</Alert>}
      <Button type="submit" disabled={pending} className="w-full">
        {pending ? "Ingresando…" : "Ingresar"}
      </Button>
    </form>
  );
}
