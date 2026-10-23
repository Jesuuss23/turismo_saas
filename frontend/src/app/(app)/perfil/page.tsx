import { api, type Me } from "@/lib/api";

/** “Mi perfil”: rol, experiencia, alcance, sede, tenant, MFA, último acceso y permisos efectivos. */
export default async function ProfilePage() {
  const [{ data: me }, { permissions }] = await Promise.all([
    api<{ data: Me }>("/me"),
    api<{ permissions: string[] }>("/me/permissions"),
  ]);

  const rows: [string, string][] = [
    ["Rol", me.role?.display_name ?? "—"],
    ["Experiencia", me.experience_label ?? me.experience],
    ["Alcance de datos", me.scope_label],
    ["Sede", me.sede?.name ?? "—"],
    ["Tenant", `${me.tenant.name} (${me.tenant.slug})`],
    ["MFA", me.mfa.enabled ? "Habilitado" : me.mfa.required ? "Requerido, pendiente" : "No habilitado"],
    ["Último acceso", me.last_login_at ? new Date(me.last_login_at).toLocaleString("es-PE") : "—"],
  ];

  return (
    <section className="max-w-2xl space-y-6">
      <h1 className="text-2xl font-semibold text-slate-900">Mi perfil</h1>
      <dl className="divide-y divide-slate-200 rounded-lg bg-white ring-1 ring-slate-200">
        {rows.map(([k, v]) => (
          <div key={k} className="grid grid-cols-3 gap-4 px-4 py-2 text-sm">
            <dt className="text-slate-500">{k}</dt>
            <dd className="col-span-2 text-slate-900">{v}</dd>
          </div>
        ))}
      </dl>
      <div>
        <h2 className="text-sm font-semibold text-slate-700">Permisos efectivos ({permissions.length})</h2>
        <ul className="mt-2 flex flex-wrap gap-2">
          {permissions.map((p) => <li key={p} className="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs">{p}</li>)}
        </ul>
      </div>
    </section>
  );
}
