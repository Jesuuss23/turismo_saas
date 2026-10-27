"use client";

import { useActionState } from "react";
import { Alert, Button, Field } from "@/components/ui";
import { requestPassengerLink, verifyPassengerLink } from "./actions";

export function RequestLinkForm({ tenant }: { tenant?: string }) {
  const [state, action, pending] = useActionState(requestPassengerLink, undefined);
  return (
    <form action={action} className="mt-6 space-y-4">
      <Field label="Agencia (tenant)" name="tenant" defaultValue={tenant} placeholder="andes" required />
      <Field label="Correo registrado en la reserva" name="email" type="email" required autoComplete="email" />
      <Field label="Código de reserva" name="code" placeholder="RES-2026-000347" required />
      {state?.error && <Alert>{state.error}</Alert>}
      {state?.info && (
        <Alert kind="info">
          {state.info}
          {state.devLink && <a href={state.devLink} className="mt-1 block break-all underline">Entorno de pruebas: abrir el enlace</a>}
        </Alert>
      )}
      <Button type="submit" disabled={pending} className="w-full">{pending ? "Enviando…" : "Recibir enlace de acceso"}</Button>
    </form>
  );
}

export function VerifyForm({ token, tenant }: { token: string; tenant: string }) {
  const [state, action, pending] = useActionState(verifyPassengerLink, undefined);
  return (
    <form action={action} className="mt-6 space-y-4">
      <input type="hidden" name="token" value={token} />
      <input type="hidden" name="tenant" value={tenant} />
      {state?.error && <Alert>{state.error}</Alert>}
      <Button type="submit" disabled={pending} className="w-full">{pending ? "Ingresando…" : "Ingresar a mi viaje"}</Button>
    </form>
  );
}
