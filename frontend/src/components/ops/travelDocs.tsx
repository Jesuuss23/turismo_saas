import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, Input, Kpis, Notice, OutOfScope, PageHeader, Select, Table, Td, TextArea, btnCls, btnGhostCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { TravelDocUpload } from "@/components/ops/TravelDocUpload";

type Gate = { applies: boolean; blocked: boolean; missing: { passenger: string; passenger_id: string; type: string; label: string; status: string; status_label: string }[]; services: { code: string; name: string; date: string }[] };
type Item = {
  id: string; type: string; label: string; status: string; status_label: string; stored_status: string; applies: boolean; reason: string | null; doc_number: string | null; expiry_date: string | null;
  min_expiry: string | null; file_name: string | null; file_mime: string | null; file_size: number | null; has_file: boolean; uploaded_by_type: string | null; uploaded_at: string | null;
  observation: string | null; validated_by: string | null; validated_at: string | null; note: string | null; lock_version: number; data_type: boolean;
};
type Pax = {
  id: string; seq: number; name: string; pax_type: string; nationality: string | null; doc_type: string | null; doc_number: string | null; birth_date: string | null; is_lead: boolean;
  foreign: boolean; minor: boolean; status: string; items: Item[]; not_applicable: { type: string; label: string; reason: string }[];
  contact: { name: string; phone: string; relation: string } | null; special: { requires_attention: boolean; operational_note: string | null } | null;
};
type BoardRow = {
  id: string; code: string; title: string; client: string | null; owner: string | null; reservation_status: string; travel_from: string; travel_to: string; days_left: number | null;
  status: string; status_label: string; pax: number; pax_pending: number; to_validate: number; observed: number; expired: number; pending: number; gate: Gate; last_reminder: string | null;
};
type Settings = { reminder_days: number[]; channels: string[]; passport_months: number; visa_nationalities: string[]; insurance_trekking: boolean; clinical_terms: string[]; types: Record<string, string> };
export type DocsParams = { tab?: string; status?: string };
export type DocParams = { item?: string; pax?: string; form?: string; solicitar?: string };

const NEED = "Requiere permiso operations.travel_docs";
const TONE: Record<string, "green" | "amber" | "rose" | "sky" | "slate" | "violet"> = {
  completo: "green", pendiente: "amber", recibido: "sky", observado: "rose", vencido: "rose", no_aplica: "slate",
};
const TABS = [{ key: "reservas", label: "Por reserva" }, { key: "pasajeros", label: "Por pasajero" }, { key: "mapi", label: "Bloqueo entradas MAPI" }, { key: "reglas", label: "Reglas y recordatorios" }];

export function DocPill({ status, label }: { status: string; label?: string }) {
  const labels: Record<string, string> = { completo: "Completo", pendiente: "Pendiente", recibido: "Por validar", observado: "Observado", vencido: "Vencido", no_aplica: "No aplica" };
  return <Pill tone={TONE[status] ?? "slate"}>{label && status !== "recibido" ? label : labels[status] ?? status}</Pill>;
}

function days(n: number | null) {
  if (n === null) return "—";
  if (n < 0) return "En viaje";
  return n === 0 ? "Hoy" : n === 1 ? "Mañana" : `En ${n} días`;
}

/** /operaciones/documentos-viaje (§2): KPIs, alerta MAPI y pestañas. Ventas ve solo sus reservas (DOC-BR-012). */
export async function TravelDocsBoard({ sp }: { sp: DocsParams }) {
  const tab = TABS.some((t) => t.key === sp.tab) ? sp.tab! : "reservas";
  const { data, meta } = await api<{ data: BoardRow[]; meta: { kpis: { upcoming: number; pax_pending: number; to_validate: number; observed_expired: number; mapi_blocked: number }; can_manage: boolean; scope: string } }>(
    `/ops/travel-docs${qs({ "filter[status]": tab === "reservas" ? sp.status : undefined })}`);
  const k = meta.kpis;

  return (
    <section>
      <PageHeader title="Documentos de viaje" subtitle={`Checklist documental por pasajero antes del viaje · ${meta.scope}`}
        actions={<ActionForm path="/ops/travel-docs/jobs/reminders" submitLabel="Ejecutar recordatorios" submitClass={btnGhostCls} disabled={!meta.can_manage} disabledReason={NEED} success="Recordatorios ejecutados" />} />
      <Kpis items={[
        { label: "Reservas próximas", value: k.upcoming },
        { label: "Pasajeros pendientes", value: k.pax_pending },
        { label: "Por validar", value: k.to_validate, hint: "Cargados por el cliente u Operaciones" },
        { label: "Observados / vencidos", value: k.observed_expired },
        { label: "Bloqueos MAPI", value: k.mapi_blocked, hint: "Entradas nominativas sin emitir" },
      ]} />
      {k.mapi_blocked > 0 && <Notice tone="danger">{k.mapi_blocked} reserva(s) con entradas a Machu Picchu bloqueadas: falta documento de identidad validado o autorización de menor (DOC-BR-010).</Notice>}
      <nav className="mb-4 mt-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Vistas de documentos">
        {TABS.map((t) => (
          <Link key={t.key} href={`/operaciones/documentos-viaje?tab=${t.key}`} aria-current={t.key === tab ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 ${t.key === tab ? "border-sky-700 font-medium text-sky-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>{t.label}</Link>
        ))}
      </nav>

      {tab === "reservas" && (
        <>
          <form method="get" action="/operaciones/documentos-viaje" className="mb-3 flex flex-wrap gap-2">
            <input type="hidden" name="tab" value="reservas" />
            <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado documental" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
              <option value="">Todos los estados</option><option value="pendiente">Pendiente</option><option value="por_validar">Por validar</option>
              <option value="observado">Observado</option><option value="vencido">Vencido</option><option value="completo">Completo</option>
            </select>
            <button className={btnGhostCls}>Filtrar</button>
          </form>
          <Table head={["Reserva", "Viaje", "Pasajeros", "Estado", "Pendientes", "Por validar", "MAPI", "Último envío"]} empty="No hay reservas próximas con documentos por revisar.">
            {data.map((r) => (
              <tr key={r.id}>
                <Td><Link href={`/operaciones/documentos-viaje/${r.code}`} className="font-medium text-sky-800 hover:underline">{r.code}</Link><span className="block max-w-56 truncate text-xs text-slate-500">{r.client ?? r.title}{r.owner ? ` · ${r.owner}` : ""}</span></Td>
                <Td>{date(r.travel_from)}<span className="block text-xs text-slate-500">{days(r.days_left)}</span></Td>
                <Td>{r.pax}{r.pax_pending ? <span className="block text-xs text-amber-800">{r.pax_pending} incompleto(s)</span> : null}</Td>
                <Td><DocPill status={r.status} label={r.status_label} /></Td>
                <Td>{r.pending}</Td>
                <Td>{r.to_validate}</Td>
                <Td>{!r.gate.applies ? <span className="text-slate-400">—</span> : r.gate.blocked ? <Pill tone="rose">Bloqueado</Pill> : <Pill tone="green">Habilitado</Pill>}</Td>
                <Td>{r.last_reminder ? dateTime(r.last_reminder) : "—"}</Td>
              </tr>
            ))}
          </Table>
        </>
      )}
      {tab === "pasajeros" && <PassengersTab />}
      {tab === "mapi" && (
        <div className="space-y-3">
          {data.filter((r) => r.gate.applies).length === 0 && <Card><p className="text-sm text-slate-500">Ninguna reserva próxima incluye entradas nominativas a Machu Picchu.</p></Card>}
          {data.filter((r) => r.gate.applies).map((r) => (
            <Card key={r.id} title={<><Link href={`/operaciones/documentos-viaje/${r.code}`} className="text-sky-800 hover:underline">{r.code}</Link> · {r.client ?? r.title}</>}
              actions={r.gate.blocked ? <Pill tone="rose">Bloqueado</Pill> : <Pill tone="green">Habilitado para emitir</Pill>}>
              <p className="mb-2 text-sm text-slate-600">{r.gate.services.map((s) => `${s.code} · ${s.name} (${date(s.date)})`).join(" · ")}</p>
              {r.gate.blocked ? (
                <ul className="list-inside list-disc text-sm text-slate-800">
                  {r.gate.missing.map((m) => <li key={`${m.passenger_id}-${m.type}`}>{m.passenger}: {m.label} — {m.status_label}</li>)}
                </ul>
              ) : <p className="text-sm text-emerald-800">Todos los pasajeros tienen la documentación validada.</p>}
            </Card>
          ))}
        </div>
      )}
      {tab === "reglas" && <RulesTab canManage={meta.can_manage} />}
    </section>
  );
}

async function PassengersTab() {
  const { data } = await api<{ data: { reservation: string; reservation_title: string; travel_from: string; days_left: number | null; passenger_id: string; name: string; pax_type: string; nationality: string | null;
    foreign: boolean; minor: boolean; status: string; status_label: string; missing: { type: string; label: string; status: string }[] }[] }>("/ops/travel-docs/passengers");
  return (
    <Table head={["Pasajero", "Reserva", "Viaje", "Perfil", "Estado", "Falta"]} empty="Sin pasajeros en reservas próximas.">
      {data.map((p) => (
        <tr key={p.passenger_id}>
          <Td><span className="font-medium text-slate-900">{p.name}</span><span className="block text-xs text-slate-500">{p.nationality ?? "Nacionalidad sin registrar"}</span></Td>
          <Td><Link href={`/operaciones/documentos-viaje/${p.reservation}`} className="text-sky-800 hover:underline">{p.reservation}</Link></Td>
          <Td>{date(p.travel_from)}<span className="block text-xs text-slate-500">{days(p.days_left)}</span></Td>
          <Td>{p.pax_type}{p.foreign ? " · extranjero" : ""}{p.minor ? " · menor" : ""}</Td>
          <Td><DocPill status={p.status} label={p.status_label} /></Td>
          <Td className="min-w-48 text-xs">{p.missing.length ? p.missing.map((m) => m.label).join(", ") : "—"}</Td>
        </tr>
      ))}
    </Table>
  );
}

async function RulesTab({ canManage }: { canManage: boolean }) {
  const { data: s } = await api<{ data: Settings }>("/settings/travel-docs");
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card title="Reglas documentales y recordatorios">
        <ActionForm path="/settings/travel-docs" method="PUT" submitLabel="Guardar reglas" disabled={!canManage} disabledReason={NEED} success="Reglas guardadas">
          <Input label="Días de recordatorio antes del viaje (separados por coma)" name="reminder_days" defaultValue={s.reminder_days.join(", ")} disabled={!canManage} />
          <fieldset className="text-sm"><legend className="mb-1 font-medium text-slate-700">Canales</legend>
            {["Email", "WhatsApp"].map((c) => (
              <label key={c} className="mr-4 inline-flex items-center gap-1"><input type="checkbox" name="channels[]" value={c} defaultChecked={s.channels.includes(c)} disabled={!canManage} /> {c}</label>
            ))}
          </fieldset>
          <Input label="Vigencia mínima del pasaporte tras el viaje (meses)" name="passport_months" type="number" min={0} max={24} defaultValue={s.passport_months} disabled={!canManage} />
          <Input label="Nacionalidades que requieren visa" name="visa_nationalities" defaultValue={s.visa_nationalities.join(", ")} disabled={!canManage} />
          <Select name="insurance_trekking" label="Seguro obligatorio en programas de aventura (trekking/altura)" defaultValue={String(s.insurance_trekking)}
            options={[{ value: "true", label: "Sí, también para nacionales" }, { value: "false", label: "No, solo extranjeros" }]} />
          <TextArea label="Términos clínicos rechazados en atención especial" name="clinical_terms" defaultValue={s.clinical_terms.join(", ")} disabled={!canManage} />
        </ActionForm>
      </Card>
      <Card title="Qué se exige">
        <ul className="list-inside list-disc space-y-1 text-sm text-slate-700">
          <li>Extranjeros: pasaporte vigente hasta el fin del viaje + {s.passport_months} meses, seguro y registro migratorio (TAM).</li>
          <li>Peruanos: DNI o pasaporte vigente al fin del viaje.</li>
          <li>Visa: {s.visa_nationalities.join(", ") || "ninguna nacionalidad configurada"}.</li>
          <li>Fiebre amarilla: viajes con destinos en Loreto o Madre de Dios.</li>
          <li>Menores: autorización (permiso notarial o acreditación de filiación) con archivo.</li>
          <li>Todos: contacto de emergencia con nombre y teléfono.</li>
          <li>Entradas a Machu Picchu: sin documento validado no se emite la entrada ni su voucher.</li>
          <li>Recordatorios automáticos a {s.reminder_days.join(", ")} días del viaje por {s.channels.join(" y ")} (JOB-11, diario 08:00).</li>
        </ul>
      </Card>
    </div>
  );
}

type Detail = {
  reservation: { id: string; code: string; title: string; status: string; status_label: string; travel_from: string; travel_to: string; owner: string | null; days_left: number | null };
  client: { code: string; name: string; email: string | null; phone: string | null } | null;
  status: string; status_label: string; gate: Gate; passengers: Pax[]; context: { adventure: boolean; amazon: boolean };
  kpis: { passengers: number; complete: number; to_validate: number; pending: number; observed: number; expired: number };
  reminders: { at: string; channels: string; recipient: string; trigger: string; days_before: number | null; items_count: number; sent_by: string; message: string | null }[];
  log: { at: string; action: string; actor: string; before: string | null; after: string | null }[];
  can_manage: boolean; can_request: boolean; relations: string[]; channels: string[];
};

/** /operaciones/documentos-viaje/[reservationCode] (§2): checklist por pasajero, bloqueo MAPI, solicitudes y bitácora. */
export async function TravelDocsDetail({ code, sp }: { code: string; sp: DocParams }) {
  let d: Detail;
  try {
    d = (await api<{ data: Detail }>(`/reservations/${encodeURIComponent(code)}/travel-docs`)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 403) return <OutOfScope back="/operaciones/documentos-viaje" />;
    if (e instanceof ApiError && e.status === 404) notFound();
    throw e;
  }
  const base = `/operaciones/documentos-viaje/${d.reservation.code}`;
  const [itemPax, itemType] = (sp.item ?? "").split(":");
  const pax = d.passengers.find((p) => p.id === (sp.item ? itemPax : sp.pax));
  const item = pax && sp.item ? pax.items.find((i) => i.type === itemType) : undefined;

  return (
    <section>
      <p className="mb-2 text-sm"><Link href="/operaciones/documentos-viaje" className="text-sky-800 hover:underline">← Documentos de viaje</Link></p>
      <PageHeader title={`Documentos · ${d.reservation.code}`}
        subtitle={<>{d.reservation.title} · {date(d.reservation.travel_from)} – {date(d.reservation.travel_to)} · {days(d.reservation.days_left)} · <DocPill status={d.status} label={d.status_label} /></>}
        actions={d.can_request ? <Link href={`${base}?solicitar=1`} scroll={false} className={btnCls}>Solicitar documentos al cliente</Link>
          : <button disabled className={btnCls} title="Solo Operaciones o el asesor de la reserva">Solicitar documentos al cliente</button>} />
      {d.gate.applies && (d.gate.blocked ? (
        <div data-testid="mapi-gate"><Notice tone="danger">
          <strong>Entradas a Machu Picchu bloqueadas.</strong> No se emiten la entrada ni su voucher hasta validar: {d.gate.missing.map((m) => `${m.passenger} (${m.label})`).join(", ")}.
        </Notice></div>
      ) : <Notice tone="info">Entradas a Machu Picchu habilitadas: todos los pasajeros tienen la documentación validada.</Notice>)}
      <div className="mt-4"><Kpis items={[
        { label: "Pasajeros completos", value: `${d.kpis.complete} / ${d.kpis.passengers}` },
        { label: "Pendientes", value: d.kpis.pending },
        { label: "Por validar", value: d.kpis.to_validate },
        { label: "Observados", value: d.kpis.observed },
        { label: "Vencidos", value: d.kpis.expired },
      ]} /></div>
      {!d.can_manage && <p className="mb-3 text-sm text-slate-600">Consulta: cargar, validar y observar documentos lo hace Operaciones ({NEED}).</p>}

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          {d.passengers.map((p) => (
            <Card key={p.id} title={<span>{p.name}{p.is_lead ? " · titular" : ""}</span>} actions={<DocPill status={p.status} />}>
              <p className="mb-2 text-xs text-slate-500">{p.pax_type} · {p.nationality ?? "Nacionalidad sin registrar"}{p.foreign ? " · extranjero" : ""}{p.minor ? " · menor de edad" : ""}{p.birth_date ? ` · nac. ${date(p.birth_date)}` : ""}</p>
              <ul className="divide-y divide-slate-100 text-sm">
                {p.items.map((i) => (
                  <li key={i.type} className="flex flex-wrap items-center justify-between gap-2 py-2" data-doc={`${p.seq}-${i.type}`}>
                    <div className="min-w-0">
                      <p className="font-medium text-slate-800">{i.label}</p>
                      <p className="text-xs text-slate-500">
                        {i.type === "emergency" ? (p.contact ? `${p.contact.name} · ${p.contact.phone} · ${p.contact.relation}` : "Sin registrar")
                          : i.type === "special" ? (p.special?.operational_note ?? "—")
                          : [i.file_name, i.doc_number, i.expiry_date ? `vence ${date(i.expiry_date)}` : null, i.validated_by ? `validó ${i.validated_by}` : null].filter(Boolean).join(" · ") || "Sin archivo"}
                      </p>
                      {i.reason && <p className="text-xs text-rose-700">{i.reason}</p>}
                      {i.observation && i.status === "observado" && <p className="text-xs text-rose-700">Observación: {i.observation}</p>}
                    </div>
                    <div className="flex items-center gap-2">
                      <DocPill status={i.status} label={i.status_label} />
                      {i.data_type ? (
                        d.can_manage ? <Link href={`${base}?pax=${p.id}&form=${i.type === "emergency" ? "contacto" : "especial"}`} scroll={false} className="text-xs font-medium text-sky-800 hover:underline">Registrar</Link>
                          : <span className="text-xs text-slate-400" title={NEED}>Registrar</span>
                      ) : d.can_manage ? <Link href={`${base}?item=${p.id}:${i.type}`} scroll={false} className="text-xs font-medium text-sky-800 hover:underline" aria-label={`Gestionar ${i.label} de ${p.name}`}>Gestionar</Link>
                        : <span className="text-xs text-slate-400" title={NEED}>Gestionar</span>}
                    </div>
                  </li>
                ))}
              </ul>
              {p.not_applicable.length > 0 && <p className="mt-2 text-xs text-slate-500">No aplica: {p.not_applicable.map((n) => `${n.label} (${n.reason})`).join(" · ")}</p>}
              {d.can_manage && !p.special && <p className="mt-2 text-xs"><Link href={`${base}?pax=${p.id}&form=especial`} scroll={false} className="text-sky-800 hover:underline">Declarar atención especial</Link></p>}
            </Card>
          ))}
        </div>
        <div className="space-y-4">
          <Card title="Contacto del cliente">
            {d.client ? <p className="text-sm text-slate-700">{d.client.name}<span className="block text-xs text-slate-500">{d.client.email ?? "sin correo"} · {d.client.phone ?? "sin teléfono"}</span></p> : <p className="text-sm text-slate-500">—</p>}
            <p className="mt-2 text-xs text-slate-500">Asesor: {d.reservation.owner ?? "—"}</p>
          </Card>
          <Card title="Solicitudes y recordatorios">
            {d.reminders.length === 0 ? <p className="text-sm text-slate-500">Aún no se solicitaron documentos.</p> : (
              <ol className="space-y-2 text-sm">{d.reminders.map((r, i) => (
                <li key={i} className="border-l-2 border-slate-200 pl-3">
                  <p className="text-slate-800">{r.trigger === "auto" ? `Recordatorio automático${r.days_before !== null ? ` (${r.days_before} d)` : ""}` : "Solicitud"} · {r.items_count} ítem(s)</p>
                  <p className="text-xs text-slate-500">{r.channels} · {r.recipient} · {r.sent_by} · {dateTime(r.at)}</p>
                  {r.message && <p className="text-xs text-slate-600">{r.message}</p>}
                </li>
              ))}</ol>
            )}
          </Card>
          <Card title="Bitácora">
            {d.log.length === 0 ? <p className="text-sm text-slate-500">Sin movimientos.</p> : (
              <ol className="space-y-2 text-sm">{d.log.map((e, i) => (
                <li key={i} className="border-l-2 border-slate-200 pl-3"><p className="text-slate-800">{e.action.replaceAll("_", " ")}: {e.after}</p><p className="text-xs text-slate-500">{e.actor} · {dateTime(e.at)}</p></li>
              ))}</ol>
            )}
          </Card>
        </div>
      </div>

      {sp.solicitar && d.can_request && (
        <Drawer title="Solicitar documentos al cliente" close={base}>
          <ActionForm path={`/reservations/${d.reservation.code}/travel-docs/requests`} idempotent redirectTo={base} success="Solicitud enviada" submitLabel="Enviar solicitud">
            <fieldset className="text-sm"><legend className="mb-1 font-medium text-slate-700">Canales</legend>
              {["Email", "WhatsApp"].map((c) => (
                <label key={c} className="mr-4 inline-flex items-center gap-1"><input type="checkbox" name="channels[]" value={c} defaultChecked={d.channels.includes(c)} /> {c}</label>
              ))}
            </fieldset>
            <TextArea label="Mensaje (opcional)" name="message" maxLength={500} placeholder="Se adjunta la lista de documentos pendientes y el enlace del portal." />
            <p className="text-xs text-slate-500">Destinatario: {d.client?.email ?? d.client?.phone ?? "cliente"} · {d.kpis.pending + d.kpis.observed + d.kpis.expired + d.kpis.to_validate} ítem(s) pendientes.</p>
          </ActionForm>
        </Drawer>
      )}
      {item && pax && d.can_manage && (
        <Drawer title={`${item.label} · ${pax.name}`} close={base}>
          <p className="mb-2 text-sm"><DocPill status={item.status} label={item.status_label} /></p>
          {item.type === "id_doc" && item.min_expiry && <p className="mb-3 text-sm text-slate-600">Vigencia mínima exigida: {date(item.min_expiry)} {pax.foreign ? "(fin del viaje + meses configurados)" : "(fin del viaje)"}.</p>}
          {item.type === "minor_auth" && <p className="mb-3 text-sm text-slate-600">Permiso notarial si no viaja con ambos padres, o acreditación de filiación. Validar requiere el archivo.</p>}
          {item.has_file && <p className="mb-3 text-sm text-slate-700">Archivo: {item.file_name} · cargado {dateTime(item.uploaded_at)}{" "}
            <a href={`/bff/travel-doc/${d.reservation.code}/${pax.id}/${item.type}`} target="_blank" rel="noreferrer" className="text-sky-800 hover:underline">Ver</a></p>}
          <h3 className="mb-2 text-sm font-semibold text-slate-800">{item.has_file ? "Reemplazar" : "Cargar"}</h3>
          <TravelDocUpload path={`/reservations/${d.reservation.code}/travel-docs/${pax.id}/${item.type}/files`} withIdentity={item.type === "id_doc" || item.type === "visa" || item.type === "insurance"}
            docNumber={item.doc_number ?? pax.doc_number} expiry={item.expiry_date} replace={item.has_file} />
          <h3 className="mb-2 mt-5 text-sm font-semibold text-slate-800">Validar</h3>
          <ActionForm path={`/reservations/${d.reservation.code}/travel-docs/${pax.id}/${item.type}/validate`} ifMatch={item.lock_version} submitLabel="Validar"
            disabled={!item.has_file || item.stored_status === "completo"} disabledReason={item.stored_status === "completo" ? "Ya está validado" : "Cargue el archivo antes de validar"} success="Documento validado">
            {item.type === "id_doc" && (
              <div className="grid grid-cols-2 gap-2">
                <Input label="N.º de documento" name="doc_number" defaultValue={item.doc_number ?? pax.doc_number ?? ""} maxLength={40} />
                <Input label="Vencimiento" name="expiry_date" type="date" defaultValue={item.expiry_date ?? ""} />
              </div>
            )}
          </ActionForm>
          <h3 className="mb-2 mt-5 text-sm font-semibold text-slate-800">Observar</h3>
          <ActionForm path={`/reservations/${d.reservation.code}/travel-docs/${pax.id}/${item.type}/observe`} ifMatch={item.lock_version} submitLabel="Observar" submitClass={btnGhostCls}
            disabled={item.stored_status === "observado"} disabledReason="Ya está observado" success="Documento observado: se avisó al cliente">
            <Input label="Motivo de la observación" name="reason" required maxLength={500} />
          </ActionForm>
        </Drawer>
      )}
      {pax && sp.form === "contacto" && d.can_manage && (
        <Drawer title={`Contacto de emergencia · ${pax.name}`} close={base}>
          <ActionForm path={`/reservations/${d.reservation.code}/travel-docs/${pax.id}/emergency-contact`} method="PUT" redirectTo={base} success="Contacto registrado" submitLabel="Guardar contacto">
            <Input label="Nombre" name="name" required maxLength={150} defaultValue={pax.contact?.name} />
            <Input label="Teléfono" name="phone" required maxLength={30} defaultValue={pax.contact?.phone} placeholder="+51 9…" />
            <Select name="relation" label="Relación" defaultValue={pax.contact?.relation ?? "Familiar"} options={d.relations.map((r) => ({ value: r, label: r }))} />
          </ActionForm>
        </Drawer>
      )}
      {pax && sp.form === "especial" && d.can_manage && (
        <Drawer title={`Atención especial · ${pax.name}`} close={base}>
          <Notice tone="info">Registre solo la necesidad operativa (movilidad, asiento, acompañante). No ingrese diagnósticos ni datos clínicos (Ley 29733).</Notice>
          <ActionForm path={`/reservations/${d.reservation.code}/travel-docs/${pax.id}/special-attention`} method="PUT" redirectTo={base} success="Atención especial registrada" submitLabel="Guardar">
            <Select name="requires_attention" label="¿Requiere atención especial?" defaultValue={String(pax.special?.requires_attention ?? true)}
              options={[{ value: "true", label: "Sí" }, { value: "false", label: "No" }]} />
            <TextArea label="Nota operativa" name="operational_note" maxLength={300} defaultValue={pax.special?.operational_note ?? ""} />
          </ActionForm>
        </Drawer>
      )}
    </section>
  );
}
