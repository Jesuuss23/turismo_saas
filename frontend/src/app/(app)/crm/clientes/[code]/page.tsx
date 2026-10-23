import Link from "@/components/Link";
import { api } from "@/lib/api";
import { catalogOptions, crmMeta, fetchScoped, owners } from "@/lib/crm-server";
import { dateTime, money, type Activity, type Client, type HistoryRow, type Lead, type Opportunity } from "@/lib/crm";
import { Badge, btnCls, Card, Kpis, Notice, OutOfScope, PageHeader, Select, Table, Td, Tabs, TextArea, Timeline, Input } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { NewActivityForm } from "@/components/crm/ActivityForms";
import { ClientForm } from "../ClientForm";

type Contact = { id: string; name: string; role: string | null; email: string | null; phone: string | null; is_primary: boolean };
type Detail = Client & {
  contacts: Contact[];
  kpis: { total_pen: number; reservations_count: number; average_ticket_pen: number; balance_pen: number; nps: number | null };
  alerts: { type: string; level: "danger" | "warning" | "info"; message: string }[];
  partial_view: boolean;
  visited_destinations: string[];
};
type Perms = { manage: boolean; reassign: boolean; activity: boolean; opportunity: boolean };
type Row = Record<string, unknown>;

const TABS = [
  { key: "resumen", label: "Resumen" },
  { key: "contactos", label: "Contactos" },
  { key: "leads", label: "Leads" },
  { key: "oportunidades", label: "Oportunidades" },
  { key: "cotizaciones", label: "Cotizaciones" },
  { key: "reservas", label: "Reservas" },
  { key: "pasajeros", label: "Pasajeros" },
  { key: "pagos", label: "Pagos y CxC" },
  { key: "documentos", label: "Comprobantes y vouchers" },
  { key: "comunicaciones", label: "Comunicaciones" },
  { key: "actividades", label: "Actividades" },
  { key: "historial", label: "Historial" },
];

/** Pestañas que vienen de módulos aún no construidos (cotizaciones, reservas, finanzas). */
const LINKED: Record<string, { api: string; title: string; module: string }[]> = {
  cotizaciones: [{ api: "quotes", title: "Cotizaciones", module: "Cotizaciones" }],
  reservas: [{ api: "reservations", title: "Reservas", module: "Reservas" }],
  pasajeros: [{ api: "passengers", title: "Pasajeros", module: "Reservas" }],
  pagos: [{ api: "receivables", title: "Cuentas por cobrar", module: "Finanzas" }, { api: "payments", title: "Pagos", module: "Finanzas" }],
  documentos: [{ api: "invoices", title: "Comprobantes", module: "Finanzas" }, { api: "vouchers", title: "Vouchers", module: "Operaciones" }],
};

export default async function Client360Page({ params, searchParams }: { params: Promise<{ code: string }>; searchParams: Promise<{ tab?: string; edit?: string }> }) {
  const [{ code }, sp] = await Promise.all([params, searchParams]);
  const tab = TABS.some((t) => t.key === sp.tab) ? sp.tab! : "resumen";
  const res = await fetchScoped<{ data: Detail; meta: { permissions: Perms } }>(`/crm/clients/${code}`);
  if (!res) return <OutOfScope back="/crm/clientes" />;
  const { data: c, meta: { permissions: perms } } = res;
  const meta = await crmMeta();
  const base = `/crm/clientes/${c.code}`;
  const tabData = <T,>(name: string) => api<{ data: T }>(`/crm/clients/${c.code}/${name}`).then((r) => r.data);

  return (
    <>
      <PageHeader
        title={`${c.code} · ${c.name}`}
        subtitle={<span className="flex flex-wrap items-center gap-2">
          <Badge status={c.segment.code}>{c.segment.label}</Badge> {c.kind === "empresa" ? "Empresa" : "Persona"} · {c.doc_type} {c.doc_number} · {c.city ?? c.country} · Responsable: {c.owner?.name ?? "—"}
          {c.tags.map((t) => <span key={t} className="rounded bg-slate-100 px-1.5 text-xs">{t}</span>)}
        </span>}
        actions={<>
          <Link href="/crm/clientes" className="self-center text-sm text-sky-800 hover:underline">← Clientes</Link>
          {perms.manage && <Link href={`${base}?tab=${tab}&edit=1`} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">Editar</Link>}
          {perms.opportunity && <Link href={`/crm/oportunidades/new?client=${c.id}`} className={btnCls}>Nueva oportunidad</Link>}
        </>}
      />
      {c.alerts.map((a) => <Notice key={a.type} tone={a.level}>{a.message}</Notice>)}
      <Kpis items={[
        { label: "Total comprado", value: money(c.kpis.total_pen) },
        { label: "Reservas", value: c.kpis.reservations_count },
        { label: "Ticket promedio", value: money(c.kpis.average_ticket_pen) },
        { label: "Saldo pendiente", value: money(c.kpis.balance_pen) },
        { label: "NPS (última)", value: c.kpis.nps ?? "—" },
      ]} />

      {sp.edit && perms.manage && (
        <Card title="Editar cliente" className="mb-4" actions={<Link href={`${base}?tab=${tab}`} className="text-sm text-slate-600 hover:underline">Cerrar</Link>}>
          <ClientForm client={c} segments={catalogOptions(meta, "client_segment")} owners={null} fixedOwner={null} />
        </Card>
      )}

      <Tabs base={base} tabs={TABS} active={tab} />

      {tab === "resumen" && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card title="Datos">
            <dl className="space-y-2 text-sm">
              {[["Correo", c.email ?? "—"], ["Teléfono", c.phone ?? "—"], ["País / ciudad", `${c.country}${c.city ? ` · ${c.city}` : ""}`],
                ["Contacto principal", c.contact_name ?? "—"], ["Última compra", c.last_purchase_date ?? "—"],
                ["Destinos visitados", c.visited_destinations.join(", ") || "—"]].map(([k, v]) => (
                <div key={k} className="flex justify-between gap-3"><dt className="text-slate-500">{k}</dt><dd className="text-right">{v}</dd></div>
              ))}
            </dl>
          </Card>
          <div className="space-y-4">
            {perms.reassign && (
              <Card title="Reasignar responsable">
                <ActionForm path={`/crm/clients/${c.code}/reassign`} ifMatch={c.lock_version} submitLabel="Reasignar" success="Cliente reasignado">
                  <Select name="owner_id" label="Nuevo responsable *" required placeholder="Seleccione"
                    options={(await owners()).data.filter((u) => u.id !== c.owner?.id).map((u) => ({ value: u.id, label: u.name }))} />
                  <TextArea name="note" label="Nota" />
                </ActionForm>
              </Card>
            )}
            {perms.activity && <Card title="Registrar actividad"><NewActivityForm relatedType="client" relatedId={c.id} /></Card>}
          </div>
        </div>
      )}

      {tab === "contactos" && (
        <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
          <Table head={["Nombre", "Cargo", "Correo", "Teléfono", ""]}>
            {c.contacts.map((ct) => (
              <tr key={ct.id}>
                <Td>{ct.name} {ct.is_primary && <Badge status="calificado">Principal</Badge>}</Td>
                <Td>{ct.role ?? "—"}</Td><Td>{ct.email ?? "—"}</Td><Td>{ct.phone ?? "—"}</Td>
                <Td>{perms.manage && !ct.is_primary && (
                  <ActionForm inline path={`/crm/clients/${c.code}/contacts/${ct.id}/make-primary`} submitLabel="Hacer principal" success="Contacto principal actualizado"
                    submitClass="text-xs text-sky-800 hover:underline" />
                )}</Td>
              </tr>
            ))}
          </Table>
          {perms.manage && (
            <Card title="Agregar contacto">
              <ActionForm path={`/crm/clients/${c.code}/contacts`} submitLabel="Agregar" success="Contacto agregado">
                <Input name="name" label="Nombre *" required maxLength={160} />
                <Input name="role" label="Cargo" maxLength={120} />
                <Input name="email" type="email" label="Correo" maxLength={190} />
                <Input name="phone" label="Teléfono" maxLength={40} />
              </ActionForm>
            </Card>
          )}
        </div>
      )}

      {tab === "leads" && (
        <Table head={["Lead", "Estado", "Fuente", "Score", "Responsable", "Creado"]}>
          {(await tabData<Lead[]>("leads")).map((l) => (
            <tr key={l.id}>
              <Td><Link className="font-medium text-sky-800 hover:underline" href={`/crm/leads/${l.code}`}>{l.code}</Link> · {l.full_name}</Td>
              <Td><Badge status={l.status}>{l.status_label}</Badge></Td><Td>{l.source.label}</Td><Td>{l.score}</Td>
              <Td>{l.owner?.name ?? "Sin asignar"}</Td><Td>{dateTime(l.created_at)}</Td>
            </tr>
          ))}
        </Table>
      )}

      {tab === "oportunidades" && (
        <Table head={["Oportunidad", "Etapa", "Monto", "Prob.", "Cierre", "Responsable"]}>
          {(await tabData<Opportunity[]>("opportunities")).map((o) => (
            <tr key={o.id}>
              <Td><Link className="font-medium text-sky-800 hover:underline" href={`/crm/oportunidades/${o.code}`}>{o.code}</Link> · {o.title}</Td>
              <Td><Badge status={o.stage}>{o.stage_label}</Badge></Td><Td>{money(o.amount, o.currency)}</Td><Td>{o.probability}%</Td>
              <Td>{o.closed_at ?? o.expected_close_date}</Td><Td>{o.owner?.name}</Td>
            </tr>
          ))}
        </Table>
      )}

      {LINKED[tab] && (
        <div className="space-y-4">
          {await Promise.all(LINKED[tab].map(async (l) => (
            <Card key={l.api} title={l.title}><GenericRows rows={await tabData<Row[]>(l.api)} empty={`Sin registros (se completa con el módulo de ${l.module})`} /></Card>
          )))}
        </div>
      )}

      {tab === "comunicaciones" && (
        <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
          <Table head={["Código", "Canal", "Asunto", "Destinatario", "Estado", "Fecha"]}>
            {(await tabData<{ id: string; code: string; channel: string; subject: string; recipient: string; status: string; created_at: string }[]>("communications")).map((m) => (
              <tr key={m.id}><Td>{m.code}</Td><Td>{m.channel}</Td><Td>{m.subject}</Td><Td>{m.recipient}</Td><Td>{m.status}</Td><Td>{dateTime(m.created_at)}</Td></tr>
            ))}
          </Table>
          {perms.manage && (
            <Card title="Enviar comunicación">
              <ActionForm path={`/crm/clients/${c.code}/communications`} idempotent submitLabel="Enviar" success="Comunicación encolada">
                <Select name="channel" label="Canal *" required defaultValue="email" options={[{ value: "email", label: "Email" }, { value: "whatsapp", label: "WhatsApp" }, { value: "portal", label: "Portal" }]} />
                <Input name="subject" label="Asunto *" required maxLength={200} />
                <TextArea name="body" label="Mensaje" rows={5} />
              </ActionForm>
            </Card>
          )}
        </div>
      )}

      {tab === "actividades" && (
        <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
          <Table head={["Actividad", "Estado", "Fecha", "Responsable"]}>
            {(await tabData<Activity[]>("activities")).map((a) => (
              <tr key={a.id}>
                <Td>{perms.activity ? <Link className="font-medium text-sky-800 hover:underline" href={`/crm/actividades?code=${a.code}`}>{a.code}</Link> : a.code} · {a.type_label} · {a.subject}</Td>
                <Td><Badge status={a.status} /></Td><Td>{dateTime(a.due_at)}</Td><Td>{a.owner?.name}</Td>
              </tr>
            ))}
          </Table>
          {perms.activity && <Card title="Nueva actividad"><NewActivityForm relatedType="client" relatedId={c.id} /></Card>}
        </div>
      )}

      {tab === "historial" && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card title="Línea de tiempo">
            <ol className="space-y-3 text-sm">
              {(await tabData<{ type: string; title: string; at: string; ref: string }[]>("timeline")).map((e, i) => (
                <li key={i} className="border-l-2 border-sky-200 pl-3"><p className="font-medium text-slate-800">{e.title}</p><p className="text-xs text-slate-500">{dateTime(e.at)}</p></li>
              ))}
            </ol>
          </Card>
          <Card title="Auditoría"><Timeline rows={await tabData<HistoryRow[]>("audit")} /></Card>
        </div>
      )}
    </>
  );
}

function GenericRows({ rows, empty }: { rows: Row[]; empty: string }) {
  if (!rows.length) return <p className="text-sm text-slate-500">{empty}</p>;
  const cols = Object.keys(rows[0]).filter((k) => k !== "id");
  return (
    <Table head={cols}>
      {rows.map((r, i) => <tr key={String(r.id ?? i)}>{cols.map((k) => <Td key={k}>{String(r[k] ?? "—")}</Td>)}</tr>)}
    </Table>
  );
}
