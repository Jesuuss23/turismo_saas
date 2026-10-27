import Link from "@/components/Link";
import { api } from "@/lib/api";
import { dateTime } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, Input, PageHeader, Select, Table, Td, TextArea, btnCls } from "@/components/crm/ui";
import { Pill, RouteTabs } from "@/components/admin/ui";
import { ROLE_TABS, type RoleRow } from "./shared";

type AuditRow = { code: string; entity: string; role: string; action: string; before: string | null; after: string | null; actor: string | null; at: string };

const SCOPES = [{ value: "tenant", label: "Back-office (tenant)" }, { value: "mobile", label: "App móvil" }, { value: "canal", label: "Canal externo" }];

/** Roles y permisos (spec 09 §4.4): roles de plantilla y personalizados, alta por copia y bitácora de cambios. */
export default async function RolesPage({ searchParams }: { searchParams: Promise<{ nuevo?: string }> }) {
  const sp = await searchParams;
  const roles = await api<RoleRow[]>("/iam/roles");
  const { audit } = await api<{ audit: AuditRow[] }>("/iam/role-permissions");

  return (
    <section>
      <PageHeader title="Roles y permisos" subtitle="Los cambios se aplican en la siguiente solicitud de cada usuario y quedan auditados."
        actions={<Link href="/admin/roles?nuevo=1" className={btnCls}>Nuevo rol</Link>} />
      <RouteTabs base="/admin/roles" tabs={ROLE_TABS} active="" />
      <Table head={["Rol", "Alcance", "Tipo", "Permisos", "Usuarios activos"]}>
        {roles.map((r) => (
          <tr key={r.id}>
            <Td><Link href={`/admin/roles/${r.name}`} className="font-medium text-sky-800 hover:underline">{r.display_name}</Link>
              {r.description && <span className="block text-xs text-slate-500">{r.description}</span>}</Td>
            <Td>{r.scope_label}</Td>
            <Td>{r.is_custom ? <Pill tone="violet">Personalizado</Pill> : <Pill>Plantilla</Pill>}</Td>
            <Td>{r.permissions}</Td>
            <Td>{r.users}</Td>
          </tr>
        ))}
      </Table>

      <Card title="Últimos cambios de roles y permisos" className="mt-5">
        {audit.length === 0 ? <p className="p-4 text-sm text-slate-500">Sin cambios registrados.</p> : (
          <ul className="divide-y divide-slate-100 text-sm">
            {audit.map((a) => (
              <li key={a.code} className="flex flex-wrap justify-between gap-2 px-4 py-2">
                <span><span className="font-mono text-xs text-slate-500">{a.code}</span> · {a.role}: <span className="font-medium">{a.action}</span> {a.after ?? a.before}</span>
                <span className="text-xs text-slate-500">{a.actor} · {dateTime(a.at)}</span>
              </li>
            ))}
          </ul>
        )}
        <p className="border-t border-slate-100 px-4 py-2 text-xs"><Link href="/admin/auditoria?entity=Permiso,Rol" className="text-sky-800 hover:underline">Ver todo en auditoría</Link></p>
      </Card>

      {sp.nuevo && (
        <Drawer title="Nuevo rol personalizado" close="/admin/roles">
          <ActionForm path="/iam/roles" submitLabel="Crear rol" redirectTo="/admin/roles">
            <Input name="name" label="Nombre" required maxLength={80} />
            <TextArea name="description" label="Descripción (obligatoria)" required maxLength={200} />
            <Select name="scope" label="Alcance" options={SCOPES} defaultValue="tenant" required />
            <Select name="copy_from_role" label="Copiar permisos de (opcional)" options={roles.map((r) => ({ value: r.name, label: r.display_name }))} placeholder="Empezar sin permisos" />
            <p className="text-xs text-slate-500">El alcance no cambia después. Al copiar a un rol de canal o móvil se descartan los permisos de administración y finanzas (RBAC-BR-03/04).</p>
          </ActionForm>
        </Drawer>
      )}
    </section>
  );
}
