"use server";

import { redirect } from "next/navigation";
import { api, ApiError } from "@/lib/api";
import { clearSession, isPlatformSession, saveSession } from "@/lib/session";

export type FormState = { error?: string; info?: string; secret?: string; otpauth?: string; recoveryCodes?: string[] } | undefined;

type LoginResponse = { mfa_required: boolean; mfa_status?: "verify" | "enroll"; token: string; user?: { home: string } };

function message(e: unknown) {
  if (e instanceof ApiError) {
    if (e.status === 429) return "Demasiados intentos. Espere un minuto e intente de nuevo.";
    return e.body?.message ?? "No se pudo completar la operación";
  }
  return "No se pudo conectar con el servidor";
}

export async function login(_: FormState, form: FormData): Promise<FormState> {
  let res: LoginResponse;
  try {
    res = await api<LoginResponse>("/auth/login", {
      method: "POST",
      token: "",
      body: JSON.stringify({ tenant_slug: form.get("tenant"), email: form.get("email"), password: form.get("password") }),
    });
  } catch (e) {
    return { error: message(e) };
  }

  await saveSession(res.token, res.mfa_required, false);
  if (res.mfa_required) redirect(`/mfa?mode=${res.mfa_status}`);
  redirect(safeNext(form.get("next")) ?? "/" + (res.user?.home ?? "dashboard"));
}

/** Consola de plataforma (SEC-BR-06): MFA siempre. */
export async function platformLogin(_: FormState, form: FormData): Promise<FormState> {
  let res: LoginResponse;
  try {
    res = await api<LoginResponse>("/platform/auth/login", {
      method: "POST",
      token: "",
      body: JSON.stringify({ email: form.get("email"), password: form.get("password") }),
    });
  } catch (e) {
    return { error: message(e) };
  }
  await saveSession(res.token, true, true);
  redirect("/mfa?mode=verify");
}

export async function verifyMfa(_: FormState, form: FormData): Promise<FormState> {
  let res: LoginResponse;
  try {
    const path = (await isPlatformSession()) ? "/platform/auth/mfa/verify" : "/auth/mfa/verify";
    res = await api<LoginResponse>(path, { method: "POST", body: JSON.stringify({ code: form.get("code") }) });
  } catch (e) {
    return { error: message(e) };
  }
  await saveSession(res.token, false);
  redirect("/" + (res.user?.home ?? "dashboard"));
}

export async function startEnrollment(): Promise<FormState> {
  try {
    const res = await api<{ secret: string; otpauth_url: string }>("/me/mfa/enroll", { method: "POST" });
    return { secret: res.secret, otpauth: res.otpauth_url };
  } catch (e) {
    return { error: message(e) };
  }
}

export async function confirmEnrollment(prev: FormState, form: FormData): Promise<FormState> {
  try {
    const res = await api<{ recovery_codes: string[]; token?: string }>("/me/mfa/confirm", { method: "POST", body: JSON.stringify({ code: form.get("code") }) });
    if (res.token) await saveSession(res.token, false);
    return { recoveryCodes: res.recovery_codes, info: "MFA habilitado. Guarde estos códigos de recuperación en un lugar seguro." };
  } catch (e) {
    return { ...prev, error: message(e) };
  }
}

export async function logout() {
  const platform = await isPlatformSession();
  try {
    await api(platform ? "/platform/auth/logout" : "/auth/logout", { method: "POST" });
  } catch {
    // La sesión se limpia igual.
  }
  await clearSession();
  redirect(platform ? "/login/plataforma" : "/login");
}

/** Aceptar invitación (spec 09 §4.2): público, el token identifica tenant y usuario. */
export async function acceptInvitation(_: FormState, form: FormData): Promise<FormState> {
  const token = String(form.get("token") ?? "");
  if (!/^[\w-]{10,100}$/.test(token)) return { error: "Invitación inválida" };
  try {
    const res = await api<{ message: string; tenant_slug: string }>(`/auth/invitations/${token}/accept`, {
      method: "POST",
      token: "",
      body: JSON.stringify({ password: form.get("password"), password_confirmation: form.get("password_confirmation") }),
    });
    return { info: res.message, secret: res.tenant_slug };
  } catch (e) {
    if (e instanceof ApiError && e.body?.errors) return { error: Object.values(e.body.errors).flat().join(" ") };
    return { error: message(e) };
  }
}

export async function requestAccess(_: FormState, form: FormData): Promise<FormState> {
  try {
    await api("/access-requests", { method: "POST", body: JSON.stringify({ path: form.get("path") }) });
    return { info: "Solicitud enviada al administrador de la agencia." };
  } catch (e) {
    return { error: message(e) };
  }
}

function safeNext(value: FormDataEntryValue | null) {
  const next = typeof value === "string" ? value : "";
  return next.startsWith("/") && !next.startsWith("//") ? next : null;
}
