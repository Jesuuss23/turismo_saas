import Link from "@/components/Link";
import { api } from "@/lib/api";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, Input, Notice, PageHeader, Select, Table, Td, btnCls, btnGhostCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";

type Sede = { id: string; code: string; name: string; city: string | null; address: string | null; phone: string | null; manager_user_id: string | null; manager: string | null;
  status: string; is_main: boolean; active_users: number; reservations: number; lock_version: number };
type Meta = { max_sedes: number | null; plan: string; active: number; managers: { value: string; label: string }[] };

/** Sedes con límite por plan, responsable y sin eliminación con referencias (SED-BR-01…04). */
export default async function SedesPage({ searchParams }: { searchParams: Promise<{ s?: string; nueva?: string }> }) {
  const sp = await searchParams;
  const { data, meta } = await api<{ data: Sede[]; meta: Meta }>("/iam/sedes");
  const edit = sp.s ? data.find((s) => s.code === sp.s) : null;
  const full = meta.max_sedes !== null && meta.active >= meta.max_sedes;

  return (
    <section>
      <PageHeader title="Usuarios y sedes" subtitle={`Plan ${meta.plan}: ${meta.active} de ${meta.max_sedes ?? "∞"} sedes activas`}
        actions={<Link href="/admin/usuarios/sedes?nueva=1" className={btnCls}>Nueva sede</Link>} />
      <nav className="mb-4 flex gap-1 border-b border-slate-200 text-sm">
        <Link href="/admin/usuarios" className="px-3 py-2 text-slate-600 hover:text-slate-900">Usuarios</Link>
        <span className="-mb-px border-b-2 border-sky-700 px-3 py-2 font-medium text-sky-800">Sedes</span>
      </nav>
      {full && <Notice tone="warning">El plan {meta.plan} permite {meta.max_sedes} sede(s) activa(s) (SED-BR-02).</Notice>}
      <Table head={["Código", "Sede", "Dirección", "Responsable", "Usuarios", "Reservas", "Estado", ""]}>
        {data.map((s) => (
          <tr key={s.id}>
            <Td className="font-mono">{s.code}</Td>
            <Td><span className="font-medium">{s.name}</span>{s.is_main && <span className="ml-2"><Pill tone="sky">Principal</Pill></span>}<span className="block text-xs text-slate-500">{s.city}</span></Td>
            <Td>{s.address ?? "—"}</Td>
            <Td>{s.manager ?? "—"}</Td>
            <Td>{s.active_users}</Td>
            <Td>{s.reservations}</Td>
            <Td><Pill tone={s.status === "activa" ? "green" : "slate"}>{s.status === "activa" ? "Activa" : "Inactiva"}</Pill></Td>
            <Td><Link href={`/admin/usuarios/sedes?s=${s.code}`} className="text-sky-800 hover:underline">Gestionar</Link></Td>
          </tr>
        ))}
      </Table>

      {sp.nueva && (
        <Drawer title="Nueva sede" close="/admin/usuarios/sedes">
          <ActionForm path="/iam/sedes" submitLabel="Crear sede" redirectTo="/admin/usuarios/sedes" disabled={full} disabledReason="Límite de sedes del plan">
            <Input name="name" label="Nombre" required />
            <Input name="city" label="Ciudad" required />
            <Input name="address" label="Dirección" required />
            <Input name="phone" label="Teléfono" />
            <Select name="manager_user_id" label="Responsable" options={meta.managers} placeholder="Sin responsable" />
          </ActionForm>
        </Drawer>
      )}
      {edit && (
        <Drawer title={`${edit.code} · ${edit.name}`} close="/admin/usuarios/sedes">
          <div className="space-y-4">
            <ActionForm path={`/iam/sedes/${edit.code}`} method="PATCH" ifMatch={edit.lock_version} submitLabel="Guardar" success="Sede actualizada">
              <Input name="name" label="Nombre" defaultValue={edit.name} required />
              <Input name="city" label="Ciudad" defaultValue={edit.city ?? ""} required />
              <Input name="address" label="Dirección" defaultValue={edit.address ?? ""} required />
              <Input name="phone" label="Teléfono" defaultValue={edit.phone ?? ""} />
              <Select name="manager_user_id" label="Responsable" options={meta.managers} defaultValue={edit.manager_user_id} placeholder="Sin responsable" />
            </ActionForm>
            <Card title="Estado">
              {edit.status === "activa"
                ? <ActionForm path={`/iam/sedes/${edit.code}/deactivate`} submitLabel="Desactivar sede" submitClass={btnGhostCls} />
                : <ActionForm path={`/iam/sedes/${edit.code}/activate`} submitLabel="Activar sede" />}
              <p className="mt-2 text-xs text-slate-500">No se desactiva con usuarios activos ni la última sede activa (SED-BR-03).</p>
            </Card>
            <Card title="Eliminar">
              <ActionForm path={`/iam/sedes/${edit.code}`} method="DELETE" submitLabel="Eliminar sede" confirm="¿Eliminar la sede?" redirectTo="/admin/usuarios/sedes"
                submitClass="rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white disabled:opacity-50" disabled={edit.is_main} disabledReason="Es la sede principal" />
              <p className="mt-2 text-xs text-slate-500">Solo sin usuarios ni reservas asociadas (SED-BR-04).</p>
            </Card>
          </div>
        </Drawer>
      )}
    </section>
  );
}
