import Link from "@/components/Link";
import { api } from "@/lib/api";
import { dateTime, qs } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, Input, Kpis, Notice, PageHeader, Select, Table, Td, TextArea, btnCls, btnGhostCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";

type Row = {
  id: string; code: string; name: string; email: string; phone: string | null; role: string | null; role_label: string | null; external: boolean;
  sede_id: string | null; sede: string | null; status: string; status_label: string; mfa: boolean; mfa_required: boolean; last_login_at: string | null; lock_version: number;
};
type Detail = Row & {
  permissions: { module: string; permissions: { name: string; label: string }[] }[]; permission_count: number;
  activity: { code: string; entity: string; entity_id: string; action: string; detail: string | null; at: string }[];
  invitation: { expires_at: string; accepted_at: string | null; expired: boolean; resent_count: number } | null; is_self: boolean; deactivated_reason: string | null;
};
type Meta = {
  kpis: { internal_active: number; channel_active: number; without_mfa: number; pending: number; sedes: number };
  plan: { name: string; max_users: number | null; used: number; max_sedes: number | null };
  roles: { value: string; label: string }[]; sedes: { id: string; code: string; name: string; status: string }[];
};
type Params = { role?: string; sede?: string; status?: string; mfa?: string; q?: string; u?: string; nuevo?: string };

const TONE: Record<string, "green" | "amber" | "slate"> = { activo: "green", pendiente: "amber", inactivo: "slate" };

/** Usuarios del tenant (PROC-002, spec 09 §2): ciclo invitado → activo → inactivo. */
export default async function UsersPage({ searchParams }: { searchParams: Promise<Params> }) {
  const sp = await searchParams;
  const filters = { role: sp.role, sede: sp.sede, status: sp.status, mfa: sp.mfa, q: sp.q };
  const { data, meta } = await api<{ data: Row[]; meta: Meta }>(`/iam/users${qs(filters)}`);
  const detail = sp.u ? (await api<{ data: Detail }>(`/iam/users/${encodeURIComponent(sp.u)}`)).data : null;
  const back = `/admin/usuarios${qs(filters)}`;
  const sedeOptions = meta.sedes.filter((s) => s.status === "activa").map((s) => ({ value: s.id, label: s.name }));
  const full = meta.plan.max_users !== null && meta.plan.used >= meta.plan.max_users;

  return (
    <section>
      <PageHeader title="Usuarios y sedes" subtitle={`Plan ${meta.plan.name}: ${meta.plan.used} de ${meta.plan.max_users ?? "∞"} usuarios`}
        actions={<Link href={`${back}${back.includes("?") ? "&" : "?"}nuevo=1`} className={btnCls} aria-disabled={full}>Invitar usuario</Link>} />
      <nav className="mb-4 flex gap-1 border-b border-slate-200 text-sm">
        <span className="-mb-px border-b-2 border-sky-700 px-3 py-2 font-medium text-sky-800">Usuarios</span>
        <Link href="/admin/usuarios/sedes" className="px-3 py-2 text-slate-600 hover:text-slate-900">Sedes ({meta.kpis.sedes})</Link>
      </nav>
      <Kpis items={[
        { label: "Internos activos", value: meta.kpis.internal_active },
        { label: "Usuarios de canal", value: meta.kpis.channel_active, hint: "Clientes, agencias, proveedores, pasajeros" },
        { label: "Sin MFA", value: meta.kpis.without_mfa, hint: "Internos activos" },
        { label: "Invitaciones pendientes", value: meta.kpis.pending },
      ]} />
      {full && <Notice tone="warning">El plan {meta.plan.name} llegó al límite de usuarios: desactive usuarios o cambie de plan para invitar (USR-BR-02).</Notice>}

      <form className="mb-4 grid grid-cols-2 gap-2 md:grid-cols-6" method="get">
        <input name="q" defaultValue={sp.q} placeholder="Buscar nombre, correo o código" className="col-span-2 rounded-md border border-slate-300 px-3 py-2 text-sm" />
        <select name="role" defaultValue={sp.role ?? ""} className="rounded-md border border-slate-300 px-2 py-2 text-sm" aria-label="Rol">
          <option value="">Todos los roles</option>{meta.roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
        </select>
        <select name="sede" defaultValue={sp.sede ?? ""} className="rounded-md border border-slate-300 px-2 py-2 text-sm" aria-label="Sede">
          <option value="">Todas las sedes</option>{meta.sedes.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
        <select name="status" defaultValue={sp.status ?? ""} className="rounded-md border border-slate-300 px-2 py-2 text-sm" aria-label="Estado">
          <option value="">Todos los estados</option><option value="activo">Activo</option><option value="pendiente">Invitación enviada</option><option value="inactivo">Inactivo</option>
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>

      <Table head={["Código", "Usuario", "Rol", "Sede", "Estado", "MFA", "Último acceso"]}>
        {data.map((u) => (
          <tr key={u.id}>
            <Td><Link href={`${back}${back.includes("?") ? "&" : "?"}u=${u.code}`} className="font-mono text-sky-800 hover:underline">{u.code}</Link></Td>
            <Td><span className="font-medium text-slate-900">{u.name}</span><span className="block text-xs text-slate-500">{u.email}</span></Td>
            <Td>{u.role_label}{u.external && <span className="ml-1 text-xs text-slate-500">(canal)</span>}</Td>
            <Td>{u.sede ?? "—"}</Td>
            <Td><Pill tone={TONE[u.status]}>{u.status_label}</Pill></Td>
            <Td>{u.mfa ? <Pill tone="green">Activo</Pill> : u.mfa_required && !u.external ? <Pill tone="amber">Pendiente</Pill> : <span className="text-slate-400">—</span>}</Td>
            <Td>{dateTime(u.last_login_at)}</Td>
          </tr>
        ))}
      </Table>

      {sp.nuevo && (
        <Drawer title="Invitar usuario" close={back}>
          <ActionForm path="/iam/users" idempotent submitLabel="Enviar invitación" redirectTo={`${back}${back.includes("?") ? "&" : "?"}u={user.code}`} disabled={full}
            disabledReason="Plan lleno (USR-BR-02)">
            <Input name="name" label="Nombre completo" required />
            <Input name="email" label="Correo" type="email" required />
            <Select name="role" label="Rol" options={meta.roles} required placeholder="Elija un rol" />
            <Select name="sede_id" label="Sede" options={sedeOptions} required placeholder="Elija una sede" />
            <Input name="phone" label="Teléfono (WhatsApp)" />
            <label className="flex items-center gap-2 text-sm"><input type="hidden" name="require_mfa" value="false" /><input type="checkbox" name="require_mfa" value="true" defaultChecked /> Exigir MFA en el primer ingreso</label>
            <p className="text-xs text-slate-500">El enlace de activación vence en 72 horas. El usuario queda “Invitación enviada” hasta definir su contraseña.</p>
          </ActionForm>
        </Drawer>
      )}

      {detail && (
        <Drawer title={`${detail.code} · ${detail.name}`} close={back}>
          <div className="space-y-4">
            <div className="flex flex-wrap gap-2"><Pill tone={TONE[detail.status]}>{detail.status_label}</Pill><Pill tone="sky">{detail.role_label}</Pill>{detail.mfa && <Pill tone="green">MFA activo</Pill>}</div>
            <dl className="grid grid-cols-2 gap-2 text-sm">
              <dt className="text-slate-500">Correo</dt><dd>{detail.email}</dd>
              <dt className="text-slate-500">Sede</dt><dd>{detail.sede ?? "—"}</dd>
              <dt className="text-slate-500">Último acceso</dt><dd>{dateTime(detail.last_login_at)}</dd>
              {detail.deactivated_reason && <><dt className="text-slate-500">Motivo de baja</dt><dd>{detail.deactivated_reason}</dd></>}
            </dl>
            {detail.status === "pendiente" && detail.invitation && (
              <Notice tone={detail.invitation.expired ? "warning" : "info"}>
                Invitación {detail.invitation.expired ? "vencida" : `vigente hasta ${dateTime(detail.invitation.expires_at)}`} · reenviada {detail.invitation.resent_count} vez(es).
              </Notice>
            )}
            {detail.is_self && <Notice>Es su propia cuenta: no puede cambiar su rol ni desactivarse (USR-BR-04/06).</Notice>}

            <Card title="Editar">
              <ActionForm path={`/iam/users/${detail.code}`} method="PATCH" ifMatch={detail.lock_version} submitLabel="Guardar cambios" success="Usuario actualizado">
                <Input name="name" label="Nombre" defaultValue={detail.name} required />
                <Select name="role" label="Rol" options={meta.roles} defaultValue={detail.role} />
                <Select name="sede_id" label="Sede" options={sedeOptions} defaultValue={detail.sede_id} />
                <Input name="phone" label="Teléfono" defaultValue={detail.phone ?? ""} />
                <p className="text-xs text-slate-500">El correo no se edita después de la invitación (USR-BR-03).</p>
              </ActionForm>
            </Card>

            <div className="grid gap-3">
              {detail.status === "pendiente" && <ActionForm path={`/iam/users/${detail.code}/resend-invitation`} submitLabel="Reenviar invitación" submitClass={btnGhostCls} />}
              {detail.status !== "inactivo" ? (
                <Card title="Desactivar">
                  <ActionForm path={`/iam/users/${detail.code}/deactivate`} submitLabel="Desactivar usuario" submitClass="rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800 disabled:opacity-50"
                    disabled={detail.is_self} disabledReason="No puede desactivar su propia cuenta">
                    <TextArea name="reason" label="Motivo (obligatorio)" required />
                    <p className="text-xs text-slate-500">Se revocan sus sesiones. Se conservan su historial y registros.</p>
                  </ActionForm>
                </Card>
              ) : (
                <ActionForm path={`/iam/users/${detail.code}/activate`} submitLabel="Activar usuario" />
              )}
              {!detail.external && detail.status === "activo" && (
                <Card title="Resetear MFA">
                  <ActionForm path={`/iam/users/${detail.code}/reset-mfa`} submitLabel="Resetear MFA" submitClass={btnGhostCls}>
                    <Input name="reason" label="Motivo" required />
                  </ActionForm>
                </Card>
              )}
            </div>

            <Card title={`Permisos efectivos del rol (${detail.permission_count})`}>
              <ul className="space-y-2 text-sm">
                {detail.permissions.map((g) => (
                  <li key={g.module}><span className="font-medium text-slate-800">{g.module}</span>
                    <span className="block text-xs text-slate-600">{g.permissions.map((p) => p.label).join(" · ")}</span></li>
                ))}
              </ul>
            </Card>
            <Card title="Actividad reciente">
              <ul className="space-y-1 text-sm">
                {detail.activity.map((a) => <li key={a.code}><span className="text-slate-800">{a.entity} {a.entity_id} · {a.action.replaceAll("_", " ")}</span> <span className="text-xs text-slate-500">{dateTime(a.at)}</span></li>)}
                {!detail.activity.length && <li className="text-slate-500">Sin actividad.</li>}
              </ul>
            </Card>
          </div>
        </Drawer>
      )}
    </section>
  );
}
