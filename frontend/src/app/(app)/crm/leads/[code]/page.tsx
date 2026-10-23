import Link from "@/components/Link";
import { api } from "@/lib/api";
import { catalogOptions, crmMeta, fetchScoped, owners } from "@/lib/crm-server";
import { LEAD_FLOW, date, dateTime, type Activity, type HistoryRow, type Lead } from "@/lib/crm";
import { Badge, Card, Notice, OutOfScope, PageHeader, Stepper, Timeline } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { NewActivityForm } from "@/components/crm/ActivityForms";
import { LeadActions } from "./LeadActions";

type Detail = Lead & {
  qualification: { budget: number; authority: number; need: number; timeline: number; score: number; declared_amount: string | null; currency: string | null; qualified_by: { name: string } | null; qualified_at: string } | null;
  duplicates: { type: string; id: string; code: string; name: string; status: string; fields: string[] }[];
  activities: Activity[];
  history: HistoryRow[];
  converted: { client: { code: string; name: string } | null; opportunity: { code: string; title: string } | null } | null;
};
type Perms = { manage: boolean; take: boolean; assign: boolean; convert: boolean; activity: boolean };

export default async function LeadDetailPage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  const res = await fetchScoped<{ data: Detail; meta: { permissions: Perms } }>(`/crm/leads/${code}`);
  if (!res) return <OutOfScope back="/crm/leads" />;
  const { data: lead, meta: { permissions: perms } } = res;
  const meta = await crmMeta();
  const [advisors, clients] = await Promise.all([
    perms.assign ? owners() : Promise.resolve(null),
    perms.convert ? api<{ data: { id: string; label: string }[] }>("/crm/activities/related-options?type=client") : Promise.resolve({ data: [] }),
  ]);
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: "America/Lima" }).format(new Date());
  const q = lead.qualification;

  return (
    <>
      <PageHeader
        title={`${lead.code} · ${lead.full_name}`}
        subtitle={<span className="flex flex-wrap items-center gap-2"><Badge status={lead.status}>{lead.status_label}</Badge> Score {lead.score} · {lead.owner ? `Responsable: ${lead.owner.name}` : "Sin asignar (cola comercial)"}</span>}
        actions={<Link href="/crm/leads" className="text-sm text-sky-800 hover:underline">← Leads</Link>}
      />
      <div className="mb-4"><Stepper steps={LEAD_FLOW} current={lead.flow.step} failed={lead.flow.failed} /></div>
      {lead.status === "descartado" && lead.discard && <Notice tone="danger">Descartado: {lead.discard.reason.label}{lead.discard.detail ? ` · ${lead.discard.detail}` : ""}</Notice>}
      {lead.converted && (
        <Notice tone="info">
          Convertido en <Link className="underline" href={`/crm/clientes/${lead.converted.client?.code}`}>{lead.converted.client?.code} {lead.converted.client?.name}</Link>{" + "}
          <Link className="underline" href={`/crm/oportunidades/${lead.converted.opportunity?.code}`}>{lead.converted.opportunity?.code} {lead.converted.opportunity?.title}</Link>
        </Notice>
      )}

      <div className="grid gap-4 lg:grid-cols-[1fr_380px]">
        <div className="space-y-4">
          <Card title="Acciones">
            <LeadActions lead={lead} perms={perms} discardReasons={catalogOptions(meta, "discard_reason")}
              advisors={advisors?.data.map((a) => ({ value: a.id, label: a.name })) ?? null}
              clients={clients.data.map((c) => ({ value: c.id, label: c.label }))}
              bant={{ labels: meta.bant_labels, levels: meta.bant_levels }} today={today} />
          </Card>

          <Card title="Ficha">
            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
              {[
                ["Empresa", lead.company], ["Correo", lead.email], ["Teléfono", lead.phone], ["Canal", lead.channel.label],
                ["Fuente", lead.source.label], ["Interés", lead.interest.label], ["Destino", lead.destination_label],
                ["Fecha tentativa", date(lead.travel_date)], ["Pasajeros", String(lead.pax)], ["Primer contacto", dateTime(lead.first_contact_at)],
                ["Recibido", `${dateTime(lead.created_at)} · ${lead.created_by?.name ?? "Sistema"}`],
              ].map(([k, v]) => <div key={k}><dt className="text-xs text-slate-500">{k}</dt><dd className="text-slate-800">{v || "—"}</dd></div>)}
            </dl>
            {lead.notes && <p className="mt-3 whitespace-pre-line rounded bg-slate-50 p-2 text-sm text-slate-700">{lead.notes}</p>}
          </Card>

          <Card title="Actividades">
            <ul className="mb-4 divide-y divide-slate-100 text-sm">
              {lead.activities.map((a) => (
                <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span><Link href={`/crm/actividades?code=${a.code}`} className="font-medium text-sky-800 hover:underline">{a.code}</Link> · {a.type_label} · {a.subject}</span>
                  <span className="flex items-center gap-2 text-slate-600">{dateTime(a.due_at)} <Badge status={a.status} /></span>
                </li>
              ))}
              {!lead.activities.length && <li className="py-2 text-slate-500">Sin actividades.</li>}
            </ul>
            {perms.activity && lead.status !== "descartado"
              ? <NewActivityForm relatedType="lead" relatedId={lead.id} />
              : <p className="text-xs text-slate-500">Registrar actividad requiere crm.activities.manage y ser el responsable.</p>}
          </Card>
        </div>

        <div className="space-y-4">
          <Card title="Calificación BANT">
            {q ? (
              <div className="text-sm">
                <p className="text-3xl font-semibold text-slate-900">{q.score}<span className="text-base text-slate-500">/100</span></p>
                <ul className="mt-2 space-y-1">
                  {(["budget", "authority", "need", "timeline"] as const).map((dim) => (
                    <li key={dim} className="flex justify-between"><span className="text-slate-600">{{ budget: "Presupuesto", authority: "Autoridad", need: "Necesidad", timeline: "Plazo" }[dim]}</span>
                      <span>{meta.bant_labels[dim][meta.bant_levels.indexOf(q[dim])]} ({q[dim]})</span></li>
                  ))}
                </ul>
                <p className="mt-2 text-xs text-slate-500">{q.qualified_by?.name} · {dateTime(q.qualified_at)}</p>
              </div>
            ) : <p className="text-sm text-slate-500">Sin calificar. Umbral para “calificado”: 50.</p>}
          </Card>

          {lead.duplicates.length > 0 && (
            <Card title="Posibles duplicados">
              <ul className="space-y-3 text-sm">
                {lead.duplicates.map((d) => (
                  <li key={d.id} className="rounded border border-amber-200 bg-amber-50 p-2">
                    <p>{d.type === "lead" ? <Link className="font-medium underline" href={`/crm/leads/${d.code}`}>{d.code}</Link> : <Link className="font-medium underline" href={`/crm/clientes/${d.code}`}>{d.code}</Link>} · {d.name}</p>
                    <p className="text-xs text-amber-900">{d.status} · coincide por {d.fields.join(" y ")}</p>
                    {perms.manage && (
                      <div className="mt-2 flex flex-wrap gap-2">
                        <ActionForm inline path={`/crm/leads/${lead.code}/mark-duplicate`} ifMatch={lead.lock_version} submitLabel="Marcar como duplicado"
                          submitClass="rounded border border-amber-400 bg-white px-2 py-1 text-xs">
                          <input type="hidden" name="duplicate_of_type" value={d.type} /><input type="hidden" name="duplicate_of_id" value={d.id} />
                        </ActionForm>
                        {d.type === "lead" && !["convertido", "duplicado"].includes(d.status) && (
                          <ActionForm inline path={`/crm/leads/${lead.code}/merge`} submitLabel="Fusionar en este lead" confirm={`¿Fusionar ${d.code} dentro de ${lead.code}?`}
                            submitClass="rounded border border-amber-400 bg-white px-2 py-1 text-xs">
                            <input type="hidden" name="source_lead_id" value={d.id} />
                          </ActionForm>
                        )}
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            </Card>
          )}

          <Card title="Historial"><Timeline rows={lead.history} /></Card>
        </div>
      </div>
    </>
  );
}
