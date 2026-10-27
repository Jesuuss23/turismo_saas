import { API_URL } from "@/lib/api";
import { InvitationForm } from "./InvitationForm";

export const metadata = { title: "Activar cuenta · Turismo SaaS" };

type Invitation = { name: string; email: string; tenant: string; tenant_slug: string; role: string | null; require_mfa: boolean; expires_at: string };

/** Aceptar invitación (spec 09 §4.2): define la contraseña; el MFA se configura en el primer ingreso. */
export default async function InvitationPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const res = await fetch(`${API_URL}/auth/invitations/${encodeURIComponent(token)}`, { headers: { Accept: "application/json" }, cache: "no-store" });
  const body = await res.json().catch(() => null);

  if (!res.ok) {
    return (
      <>
        <h1 className="mt-2 text-xl font-semibold text-slate-900">Invitación no disponible</h1>
        <p className="mt-2 text-sm text-slate-600">{body?.message ?? "El enlace no es válido."}</p>
      </>
    );
  }
  const inv = body.data as Invitation;

  return (
    <>
      <h1 className="mt-2 text-xl font-semibold text-slate-900">Active su cuenta</h1>
      <p className="mt-1 text-sm text-slate-600">
        {inv.name}, fue invitado a <strong>{inv.tenant}</strong>{inv.role ? ` como ${inv.role}` : ""}. Defina su contraseña para {inv.email}.
      </p>
      <InvitationForm token={token} requireMfa={inv.require_mfa} />
    </>
  );
}
