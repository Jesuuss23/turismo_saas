import { cookies } from "next/headers";

export const TOKEN_COOKIE = "tz_token";
export const MFA_COOKIE = "tz_mfa";
/** Marca de sesión de la consola de plataforma (guard `platform`, spec 09 §6.1): el token sigue en `tz_token`. */
export const PLATFORM_COOKIE = "tz_platform";

const base = {
  httpOnly: true,
  sameSite: "lax" as const,
  secure: process.env.NODE_ENV === "production",
  path: "/",
};

/** El token vive solo en una cookie httpOnly: el navegador nunca lo ve (lo usa el servidor de Next). */
export async function saveSession(token: string, mfaPending: boolean, platform?: boolean) {
  const jar = await cookies();
  jar.set(TOKEN_COOKIE, token, { ...base, maxAge: mfaPending ? 600 : 60 * 60 * 24 * 30 });
  if (platform === true) jar.set(PLATFORM_COOKIE, "1", { ...base, maxAge: 60 * 60 * 12 });
  if (platform === false) jar.delete(PLATFORM_COOKIE);
  if (mfaPending) jar.set(MFA_COOKIE, "1", { ...base, maxAge: 600 });
  else jar.delete(MFA_COOKIE);
}

export async function clearSession() {
  const jar = await cookies();
  jar.delete(TOKEN_COOKIE);
  jar.delete(MFA_COOKIE);
  jar.delete(PLATFORM_COOKIE);
}

export async function isPlatformSession() {
  return (await cookies()).get(PLATFORM_COOKIE)?.value === "1";
}

export async function sessionToken() {
  return (await cookies()).get(TOKEN_COOKIE)?.value;
}
