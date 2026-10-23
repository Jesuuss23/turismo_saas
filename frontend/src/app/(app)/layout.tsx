import { redirect } from "next/navigation";
import { api, ApiError, type AccessInfo, type Me } from "@/lib/api";
import { BackofficeShell } from "@/components/BackofficeShell";
import { ExternalShell } from "@/components/ExternalShell";

/** Elige el shell según la experiencia del rol (ROLE_UX, spec 00 §7.4). */
export default async function AppLayout({ children }: { children: React.ReactNode }) {
  let me: Me, access: AccessInfo, unread = 0;
  try {
    [{ data: me }, access] = await Promise.all([api<{ data: Me }>("/me"), api<AccessInfo>("/me/access")]);
    if (access.experience === "backoffice") unread = (await api<{ unread_count: number }>("/me/notifications")).unread_count;
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) redirect("/login");
    throw e;
  }

  return access.experience === "backoffice"
    ? <BackofficeShell me={me} access={access} unread={unread}>{children}</BackofficeShell>
    : <ExternalShell me={me} access={access}>{children}</ExternalShell>;
}
