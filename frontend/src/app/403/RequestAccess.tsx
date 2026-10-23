"use client";

import { useActionState } from "react";
import { requestAccess } from "@/app/actions";
import { Alert, Button } from "@/components/ui";

export function RequestAccess({ path }: { path: string }) {
  const [state, action, pending] = useActionState(requestAccess, undefined);

  if (state?.info) return <Alert kind="info">{state.info}</Alert>;

  return (
    <form action={action} className="space-y-2">
      <input type="hidden" name="path" value={path} />
      {state?.error && <Alert>{state.error}</Alert>}
      <Button type="submit" disabled={pending} className="bg-white !text-sky-800 ring-1 ring-sky-700 hover:bg-sky-50">
        Solicitar acceso al administrador
      </Button>
    </form>
  );
}
