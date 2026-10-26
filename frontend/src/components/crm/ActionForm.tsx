"use client";

import { startTransition, useActionState, useEffect, useId, useState } from "react";
import { runCrm, type CrmFormState } from "@/app/(app)/crm/actions";

type Props = {
  path: string;
  method?: "POST" | "PATCH" | "PUT" | "DELETE";
  ifMatch?: number;
  /** Agrega Idempotency-Key estable por render del formulario (doble envío = misma operación). */
  idempotent?: boolean;
  redirectTo?: string;
  success?: string;
  submitLabel: string;
  submitClass?: string;
  disabled?: boolean;
  disabledReason?: string;
  confirm?: string;
  className?: string;
  inline?: boolean;
  children?: React.ReactNode;
  /** Render adicional según el resultado (p. ej. resolver duplicados). */
  renderState?: (state: CrmFormState) => React.ReactNode;
};

/** Formulario que llama a la API del CRM mediante una Server Action y muestra errores de negocio. */
export function ActionForm(props: Props) {
  const [state, action, pending] = useActionState(runCrm, undefined);
  const [idem, setIdem] = useState(() => (props.idempotent ? crypto.randomUUID() : ""));
  const id = useId();
  // Tras un envío exitoso, el siguiente es otra operación: nueva Idempotency-Key.
  useEffect(() => {
    if (state?.ok && props.idempotent) setIdem(crypto.randomUUID());
  }, [state, props.idempotent]);

  return (
    <form
      className={props.className ?? (props.inline ? "inline-flex items-end gap-2" : "space-y-3")}
      // Envío manual: conserva lo escrito si la API rechaza (React reinicia el form con `action={}`).
      onSubmit={(e) => {
        e.preventDefault();
        if (props.confirm && !window.confirm(props.confirm)) return;
        const data = new FormData(e.currentTarget, (e.nativeEvent as SubmitEvent).submitter);
        startTransition(() => action(data));
      }}
      aria-describedby={`${id}-msg`}
    >
      <input type="hidden" name="_path" value={props.path} />
      <input type="hidden" name="_method" value={props.method ?? "POST"} />
      {props.ifMatch !== undefined && <input type="hidden" name="_ifmatch" value={props.ifMatch} />}
      {idem && <input type="hidden" name="_idem" value={idem} />}
      {props.redirectTo && <input type="hidden" name="_redirect" value={props.redirectTo} />}
      {props.success && <input type="hidden" name="_success" value={props.success} />}
      {props.children}
      <div className={props.inline ? "" : "flex items-center gap-3"}>
        <button
          type="submit"
          disabled={pending || props.disabled}
          title={props.disabled ? props.disabledReason : undefined}
          className={props.submitClass ?? "rounded-md bg-sky-700 px-3 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:cursor-not-allowed disabled:opacity-50"}
        >
          {pending ? "Procesando…" : props.submitLabel}
        </button>
      </div>
      <div id={`${id}-msg`} aria-live="polite">
        {state?.error && (
          <p className="mt-2 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200">
            {state.error}
            {state.fieldErrors && (
              <span className="mt-1 block text-xs">{Object.values(state.fieldErrors).flat().join(" · ")}</span>
            )}
          </p>
        )}
        {state?.ok && !props.redirectTo && (
          <p className="mt-2 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">{state.info}</p>
        )}
        {state?.warnings?.map((w) => (
          <p key={w} className="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">{w}</p>
        ))}
        {props.renderState?.(state)}
      </div>
    </form>
  );
}
