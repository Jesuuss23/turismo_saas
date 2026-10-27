import Link from "@/components/Link";
import { api } from "@/lib/api";
import { dateTime } from "@/lib/crm";
import { SEVERITIES, type SupplierIncident, type SupplierIncidentDetail } from "@/lib/supplier";
import { supplierMe } from "@/lib/supplier-server";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnCls, Drawer, Input, PageHeader, Select, Table, Td, TextArea } from "@/components/crm/ui";
import { Field } from "@/components/catalog/ui";
import { RowAction, SupStatus } from "@/components/supplier/ui";

type Meta = { services: { id: string; label: string }[]; types: string[] };

/** Incidencias del proveedor (spec 06 §4.5): reportar, seguir el timeline visible y comentar. */
export default async function SupplierIncidentsPage({ searchParams }: { searchParams: Promise<{ ver?: string; nueva?: string }> }) {
  const { me } = await supplierMe();
  if (!me) return null;
  const sp = await searchParams;
  const { data: rows, meta } = await api<{ data: SupplierIncident[]; meta: Meta }>("/supplier/incidents");
  const can = me.permissions.includes("supplier.incidents.report");
  const detail = sp.ver ? (await api<{ data: SupplierIncidentDetail }>(`/supplier/incidents/${sp.ver}`)).data : null;

  return (
    <>
      <PageHeader title="Incidencias" subtitle="Novedades sobre sus servicios. Operaciones de la agencia responde aquí mismo."
        actions={can ? <Link href="/proveedor/incidencias?nueva=1" scroll={false} className={btnCls}>Reportar incidencia</Link> : undefined} />
      <Table head={["Asunto", "Reserva", "Impacto", "Estado", "Última actualización", ""]} empty="No tiene incidencias registradas">
        {rows.map((i) => (
          <tr key={i.id}>
            <Td><span className="font-medium">{i.title}</span><span className="block text-xs text-slate-500">{i.code} · {i.type}</span></Td>
            <Td>{i.reservation_code ?? "—"}</Td>
            <Td>{i.severity_label}</Td>
            <Td><SupStatus value={i.status} label={i.status_label} /></Td>
            <Td className="whitespace-nowrap">{dateTime(i.updated_at)}</Td>
            <Td><RowAction href={`/proveedor/incidencias?ver=${i.id}`}>Ver seguimiento</RowAction></Td>
          </tr>
        ))}
      </Table>

      {sp.nueva && can && (
        <Drawer title="Reportar incidencia" close="/proveedor/incidencias">
          <ActionForm path="/supplier/incidents" idempotent redirectTo="/proveedor/incidencias" submitLabel="Enviar a Operaciones">
            <Select label="Servicio" name="reservation_service_id" required placeholder="Elija el servicio" options={meta.services.map((s) => ({ value: s.id, label: s.label }))} />
            <Select label="Tipo" name="type" required defaultValue="Proveedor" options={meta.types.map((t) => ({ value: t, label: t }))} />
            <Select label="Impacto" name="severity" required defaultValue="media" options={SEVERITIES.map((s) => ({ value: s.key, label: s.label }))} />
            <Input label="Asunto" name="title" required maxLength={160} />
            <TextArea label="Descripción" name="description" required maxLength={2000} rows={4} />
          </ActionForm>
        </Drawer>
      )}
      {detail && (
        <Drawer title={`${detail.code} · ${detail.title}`} close="/proveedor/incidencias">
          <dl className="mb-4 grid grid-cols-2 gap-3 rounded-md bg-slate-50 p-3">
            <Field label="Reserva">{detail.reservation_code}</Field>
            <Field label="Tipo">{detail.type}</Field>
            <Field label="Impacto">{detail.severity_label}</Field>
            <Field label="Estado"><SupStatus value={detail.status} label={detail.status_label} /></Field>
          </dl>
          <p className="mb-4 whitespace-pre-line text-sm text-slate-700">{detail.description}</p>
          <h3 className="mb-2 text-sm font-semibold text-slate-800">Seguimiento</h3>
          <ol className="mb-4 space-y-3 text-sm">
            {detail.logs.map((l, n) => (
              <li key={n} className="border-l-2 border-slate-200 pl-3">
                <p className="text-slate-800">{l.message}</p>
                <p className="text-xs text-slate-500">{l.author} · {dateTime(l.at)}</p>
              </li>
            ))}
          </ol>
          {can && detail.can_comment ? (
            <ActionForm path={`/supplier/incidents/${detail.id}/comments`} redirectTo={`/proveedor/incidencias?ver=${detail.id}`} submitLabel="Comentar">
              <TextArea label="Comentario para Operaciones" name="message" required maxLength={1000} />
            </ActionForm>
          ) : <p className="text-sm text-slate-500">La incidencia está {detail.status_label.toLowerCase()}: ya no admite comentarios.</p>}
        </Drawer>
      )}
    </>
  );
}
