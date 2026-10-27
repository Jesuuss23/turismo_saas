import { redirect } from "next/navigation";
import { api, ApiError, type AccessInfo, type Me } from "@/lib/api";
import { isPlatformSession } from "@/lib/session";
import { BackofficeShell } from "@/components/BackofficeShell";
import { ExternalShell } from "@/components/ExternalShell";

type PlatformMe = { id: string; name: string; email: string; role: { name: string; display_name: string } };

/** Elige el shell según la experiencia del rol (ROLE_UX, spec 00 §7.4); la consola de plataforma usa su propio guard. */
export default async function AppLayout({ children }: { children: React.ReactNode }) {
  let me: Me, access: AccessInfo, unread = 0;
  const platform = await isPlatformSession();
  try {
    if (platform) {
      const [pm, pa] = await Promise.all([api<PlatformMe>("/platform/me"), api<AccessInfo>("/platform/me/access")]);
      me = {
        id: pm.id, code: null, name: pm.name, email: pm.email, avatar_color: null, role: { ...pm.role, scope: "platform" }, experience: "backoffice",
        experience_label: "Consola de plataforma", home: "admin/tenants", scope: "tenant", scope_label: "Sin acceso a datos de negocio",
        sede: null, tenant: { id: "", slug: "plataforma", name: "Consola de plataforma" }, mfa: { enabled: true, required: true }, last_login_at: null,
      };
      access = pa;
    } else {
      [{ data: me }, access] = await Promise.all([api<{ data: Me }>("/me"), api<AccessInfo>("/me/access")]);
      if (access.experience === "backoffice") unread = (await api<{ unread_count: number }>("/me/notifications")).unread_count;
    }
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) redirect(platform ? "/login/plataforma" : "/login");
    throw e;
  }

  return access.experience === "backoffice" || access.experience === "platform"
    ? <BackofficeShell me={me} access={access} unread={unread} platform={platform}>{children}</BackofficeShell>
    : <ExternalShell me={me} access={access}>{children}</ExternalShell>;
}
