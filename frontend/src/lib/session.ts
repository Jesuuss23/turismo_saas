import { cookies } from "next/headers";

export const TOKEN_COOKIE = "tz_token";
export const MFA_COOKIE = "tz_mfa";

const base = {
  httpOnly: true,
  sameSite: "lax" as const,
  secure: process.env.NODE_ENV === "production",
  path: "/",
};

/** El token vive solo en una cookie httpOnly: el navegador nunca lo ve (lo usa el servidor de Next). */
export async function saveSession(token: string, mfaPending: boolean) {
  const jar = await cookies();
  jar.set(TOKEN_COOKIE, token, { ...base, maxAge: mfaPending ? 600 : 60 * 60 * 24 * 30 });
  if (mfaPending) jar.set(MFA_COOKIE, "1", { ...base, maxAge: 600 });
  else jar.delete(MFA_COOKIE);
}

export async function clearSession() {
  const jar = await cookies();
  jar.delete(TOKEN_COOKIE);
  jar.delete(MFA_COOKIE);
}

export async function sessionToken() {
  return (await cookies()).get(TOKEN_COOKIE)?.value;
}
