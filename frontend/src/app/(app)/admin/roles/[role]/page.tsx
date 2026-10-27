import Link from "@/components/Link";
import { api } from "@/lib/api";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Input, Notice, PageHeader, TextArea, btnGhostCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import type { RoleRow } from "../shared";

type Matrix = { roles: { id: number; name: string; display_name: string; scope: string; is_custom: boolean; permissions: string[] }[]; modules: { module: string; permissions: { name: string; label: string }[] }[] };

/** Matriz de permisos de un rol (RBAC-BR-01…09): cada casilla se otorga o revoca y se audita. */
export default async function RolePage({ params }: { params: Promise<{ role: string }> }) {
  const { role: key } = await params;
  const [{ data }, roles] = await Promise.all([api<{ data: Matrix }>("/iam/role-permissions"), api<RoleRow[]>("/iam/roles")]);
  const role = data.roles.find((r) => r.name === key);
  const row = roles.find((r) => r.name === key);
  if (!role || !row) return <Notice tone="warning">El rol no existe. <Link href="/admin/roles" className="underline">Volver a roles</Link></Notice>;
  const has = new Set(role.permissions);
  const locked = role.name === "super_admin";
  const base = `/iam/roles/${role.name}/permissions`;

  return (
    <section>
      <PageHeader title={role.display_name} subtitle={<>{row.scope_label} · {has.size} permisos · {row.users} usuario(s) activo(s)</>}
        actions={<Link href="/admin/roles" className={btnGhostCls}>Volver</Link>} />
      <div className="mb-4 flex flex-wrap gap-2">{role.is_custom ? <Pill tone="violet">Personalizado</Pill> : <Pill>Plantilla del sistema</Pill>}</div>
      {locked && <Notice tone="warning">El rol Super Admin SaaS no se edita desde un tenant (RBAC-BR-01).</Notice>}
      {role.scope !== "tenant" && <Notice>Rol de {row.scope_label.toLowerCase()}: no puede recibir permisos de administración ni financieros (RBAC-BR-03/04).</Notice>}

      <div className="grid gap-4 lg:grid-cols-2">
        {data.modules.map((m) => {
          const granted = m.permissions.filter((p) => has.has(p.name)).length;
          return (
            <Card key={m.module} title={<span>{m.module} <span className="text-xs font-normal text-slate-500">{granted}/{m.permissions.length}</span></span>}
              actions={!locked && (
                <div className="flex gap-1">
                  <ActionForm path={`${base}/bulk`} inline submitLabel="Todos" submitClass="rounded border border-slate-300 px-2 py-0.5 text-xs">
                    <input type="hidden" name="module" value={m.module} /><input type="hidden" name="grant" value="true" />
                  </ActionForm>
                  <ActionForm path={`${base}/bulk`} inline submitLabel="Ninguno" submitClass="rounded border border-slate-300 px-2 py-0.5 text-xs">
                    <input type="hidden" name="module" value={m.module} /><input type="hidden" name="grant" value="false" />
                  </ActionForm>
                </div>
              )}>
              <ul className="divide-y divide-slate-100 text-sm" data-module={m.module}>
                {m.permissions.map((p) => {
                  const on = has.has(p.name);
                  return (
                    <li key={p.name} className="flex items-center justify-between gap-2 px-4 py-2" data-permission={p.name}>
                      <span className="min-w-0"><span className="block text-slate-800">{p.label}</span><span className="font-mono text-[11px] text-slate-500">{p.name}</span></span>
                      {!locked && (
                        <ActionForm path={`${base}/${p.name}`} method={on ? "DELETE" : "PUT"} inline submitLabel={on ? "✓ Otorgado" : "Otorgar"}
                          submitClass={on ? "rounded bg-emerald-700 px-2 py-1 text-xs font-medium text-white hover:bg-rose-700" : "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50"} />
                      )}
                    </li>
                  );
                })}
              </ul>
            </Card>
          );
        })}
      </div>

      {role.is_custom && (
        <div className="mt-5 grid gap-4 lg:grid-cols-2">
          <Card title="Editar rol">
            <div className="p-4">
              <ActionForm path={`/iam/roles/${role.name}`} method="PATCH" submitLabel="Guardar" success="Rol actualizado">
                <Input name="name" label="Nombre" defaultValue={role.display_name} required />
                <TextArea name="description" label="Descripción" defaultValue={row.description ?? ""} required />
              </ActionForm>
            </div>
          </Card>
          <Card title="Eliminar rol">
            <div className="p-4">
              <ActionForm path={`/iam/roles/${role.name}`} method="DELETE" submitLabel="Eliminar rol" redirectTo="/admin/roles" confirm="¿Eliminar el rol?"
                submitClass="rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white disabled:opacity-50" disabled={row.users > 0} disabledReason="Tiene usuarios asignados (RBAC-BR-07)" />
              {row.users > 0 && <p className="mt-2 text-xs text-slate-500">Reasigne a sus {row.users} usuario(s) antes de eliminarlo (RBAC-BR-07).</p>}
            </div>
          </Card>
        </div>
      )}
    </section>
  );
}
