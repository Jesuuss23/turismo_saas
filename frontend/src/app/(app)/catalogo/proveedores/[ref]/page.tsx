import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { catalogMeta } from "@/lib/catalog-server";
import { addDays, price, today, type AuditRow, type Provider, type Service } from "@/lib/catalog";
import { btnGhostCls, Card, Input, inputCls, Notice, PageHeader, Stepper, Table, Td, Timeline } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { Field, Status } from "@/components/catalog/ui";

type Detail = Omit<Provider, "services"> & {
  contacts: { id: string; name: string; role: string | null; phone: string | null; email: string | null }[];
  services: Service[]; products: { id: string; ref: string; name: string; status: string; is_main: boolean }[];
  active_products: string[]; reservations: { ref?: string; code?: string; date?: string }[];
  payables?: { open_count: number; open_pen: number; items: { ref?: string; amount?: number }[] }; history: AuditRow[] | null;
};
type ProviderUser = { id: string; code: string; name: string; email: string; status: string; provider_role: string; provider_role_label: string; last_login_at: string | null; invited_at: string | null };
const CYCLE = ["Crear", "Tipo", "Contactos", "Contrato", "Vigencia", "Costos", "Activar", "Utilizar", "Desactivar"];
const danger = "rounded-md border border-rose-300 bg-white px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50";

export default async function ProviderPage({ params }: { params: Promise<{ ref: string }> }) {
  const { ref } = await params;
  const [meta, { data: p }] = await Promise.all([catalogMeta(), api<{ data: Detail }>(`/catalog/providers/${encodeURIComponent(ref)}`)]);
  const can = meta.can.providers;
  const base = `/catalog/providers/${p.id}`;
  const users = can ? await api<{ data: ProviderUser[]; meta: { roles: Record<string, string> } }>(`${base}/users`).catch(() => null) : null;
  const step = p.effective_status === "activo" ? (p.products.length ? 8 : 7) : p.status === "inactivo" && p.services.length ? 9 : p.contacts.length ? 6 : 3;

  return (
    <>
      <PageHeader title={`${p.ref} · ${p.name}`} subtitle={<span className="flex items-center gap-2"><Status value={p.effective_status} /> {p.type} · RUC {p.ruc}</span>}
        actions={<Link href="/catalogo/proveedores" className={btnGhostCls}>Volver</Link>} />
      {p.effective_status === "vencido" && <Notice tone="danger">Contrato vencido: sus servicios no se pueden usar en productos (PRD-BR-007). Renueve el contrato.</Notice>}
      <div className="mb-4"><Stepper steps={CYCLE} current={step} failed={p.effective_status !== "activo" && step < 7} /></div>
      <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
        <div className="space-y-4">
          <Card title="Ficha">
            <dl className="grid gap-3 sm:grid-cols-3">
              <Field label="Ciudad">{p.city}</Field><Field label="Contacto">{p.contact_name}</Field><Field label="Correo">{p.email}</Field>
              <Field label="Contrato">{date(p.contract_start)} → {date(p.contract_end)}</Field><Field label="Pago">{p.payment_terms}</Field>
              <Field label="Calificación">{p.rating ? `${p.rating} / 5` : "—"}</Field>
            </dl>
          </Card>
          <Card title="Contactos">
            <ul className="space-y-1 text-sm">
              {p.contacts.map((c) => (
                <li key={c.id} className="flex items-center justify-between gap-2">
                  <span>{c.name}{c.role && <span className="text-slate-500"> · {c.role}</span>} <span className="text-xs text-slate-500">{c.phone} {c.email}</span></span>
                  {can && <ActionForm path={`${base}/contacts/${c.id}`} method="DELETE" submitLabel="Quitar" submitClass={danger} inline />}
                </li>
              ))}
            </ul>
            {can && (
              <ActionForm path={`${base}/contacts`} submitLabel="Agregar contacto" className="mt-3 grid gap-2 sm:grid-cols-4">
                <Input label="Nombre" name="name" required /><Input label="Cargo" name="role" /><Input label="Teléfono" name="phone" /><Input label="Correo" name="email" type="email" />
              </ActionForm>
            )}
          </Card>
          <Table head={["Servicio", "Tipo", ...(meta.can.see_costs ? ["Costo"] : []), "Vigencia", "Estado"]}>
            {p.services.map((s) => (
              <tr key={s.id}>
                <Td><Link href={`/catalogo/servicios/${s.ref}`} className="text-sky-800 hover:underline">{s.ref}</Link> · {s.name}</Td><Td>{s.type}</Td>
                {meta.can.see_costs && <Td>{price(s.cost, s.currency)}</Td>}
                <Td className="text-xs">{date(s.valid_from)} → {date(s.valid_to)}</Td><Td><Status value={s.effective_status} /></Td>
              </tr>
            ))}
          </Table>
          <Card title="Productos donde participa">
            <ul className="space-y-1 text-sm">
              {p.products.map((x) => <li key={x.id}><Link href={`/catalogo/productos/${x.ref}`} className="text-sky-800 hover:underline">{x.ref} · {x.name}</Link> <Status value={x.status} />{x.is_main && <span className="ml-1 text-xs text-sky-800">principal</span>}</li>)}
              {!p.products.length && <li className="text-slate-500">Sin productos.</li>}
            </ul>
          </Card>
          {p.payables && (
            <Card title="Cuentas por pagar">
              <p className="text-sm">{p.payables.open_count} abierta(s) · {price(p.payables.open_pen, "PEN")}</p>
            </Card>
          )}
          {users && (
            <Card title="Usuarios de la extranet">
              <ul className="mb-3 divide-y divide-slate-100 text-sm">
                {users.data.map((u) => (
                  <li key={u.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span><span className="font-medium">{u.name}</span> <span className="text-slate-500">{u.email}</span></span>
                    <span className="flex items-center gap-2 text-xs text-slate-600">{u.provider_role_label} <Status value={u.status} />
                      {u.last_login_at ? `Último ingreso ${date(u.last_login_at)}` : u.invited_at ? `Invitado ${date(u.invited_at)}` : ""}</span>
                  </li>
                ))}
                {!users.data.length && <li className="py-2 text-slate-500">Nadie del proveedor tiene acceso a la extranet.</li>}
              </ul>
              <ActionForm path={`${base}/users`} idempotent submitLabel="Invitar a la extranet" success="Invitación enviada" className="grid gap-2 sm:grid-cols-2">
                <Input label="Nombre" name="name" required maxLength={120} />
                <Input label="Correo" name="email" type="email" required />
                <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Perfil</span>
                  <select name="provider_role" defaultValue="reservas" className={inputCls}>{Object.entries(users.meta.roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
              </ActionForm>
              <p className="mt-2 text-xs text-slate-500">Invitar requiere además el permiso de administración de usuarios. El proveedor solo verá datos vinculados a su cuenta.</p>
            </Card>
          )}
          {p.history && <Card title="Historial"><Timeline rows={p.history.map((r) => ({ ...r, actor: r.actor ? { name: r.actor.name } : null }))} /></Card>}
        </div>
        {can && (
          <div className="space-y-4">
            <Card title="Estado">
              {p.status === "activo"
                ? <ActionForm path={`${base}/deactivate`} submitLabel="Desactivar" submitClass={btnGhostCls}><Input label="Motivo" name="reason" required /></ActionForm>
                : <ActionForm path={`${base}/activate`} submitLabel="Activar proveedor" />}
            </Card>
            <Card title="Renovar contrato">
              <ActionForm path={`${base}/renew-contract`} submitLabel="Renovar" className="space-y-2">
                <Input label="Nuevo fin de contrato" name="contract_end" type="date" defaultValue={addDays(today(), 365)} required />
                <Input label="Condiciones de pago" name="payment_terms" defaultValue={p.payment_terms ?? ""} />
              </ActionForm>
            </Card>
            <Card title="Calificar">
              <ActionForm path={`${base}/rating`} submitLabel="Guardar calificación" className="space-y-2">
                <Input label="Calificación (1–5)" name="rating" type="number" min={1} max={5} step="0.1" defaultValue={p.rating ?? 4} required />
                <Input label="Nota" name="note" />
              </ActionForm>
            </Card>
            <Card title="Editar datos">
              <ActionForm path={base} method="PATCH" ifMatch={p.lock_version} submitLabel="Guardar" className="space-y-2">
                <Input label="Contacto" name="contact_name" defaultValue={p.contact_name} />
                <Input label="Teléfono" name="phone" defaultValue={p.phone ?? ""} />
                <Input label="Correo" name="email" type="email" defaultValue={p.email ?? ""} />
              </ActionForm>
            </Card>
          </div>
        )}
      </div>
    </>
  );
}
