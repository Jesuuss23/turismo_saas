import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { currentUser } from "@/lib/crm-server";
import { voucherRows, type Banner, type PaxRules, type PaxService, type Ticket } from "@/lib/channels";
import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, Input, Notice, Select, Table, Td, TextArea, btnGhostCls, inputCls } from "@/components/crm/ui";
import { ChannelFrame, ChStatus, CmsBanner, Dl } from "@/components/channels/ui";
import { RowAction, withParams } from "@/components/supplier/ui";
import { OwnReviewForm } from "@/components/experience/OwnReviewForm";
import { submitPassengerReview } from "@/lib/own-review-actions";
import { stars, type ReviewEligibility } from "@/lib/own-reviews";

type Hotel = { name: string | null; phone: string | null; check_in: string; check_out: string; room: string | null; room_type: string | null; visual?: { key: string; label: string }; nights?: number };
type Trip = {
  reservation: { code: string; title: string; status: string; status_label: string; travel_from: string; travel_to: string; pax: number };
  state: { key: string; label: string }; days: number; critical_alert: { code: string; title: string; type: string } | null;
  next_activity: PaxService | null; hotel: Hotel | null; emergency: string; today: PaxService[]; alerts: number; unacked_alerts: number; rules: PaxRules; banners: Banner[];
};
type Day = { date: string; n: number; services: PaxService[]; night: Hotel | null };
type Voucher = { code: string; service: string; date: string; provider: string | null; status: string; status_label: string; version: number; pax: number };
type Alert = { id: string; code: string; type: string; severity: string; title: string; message: string; at: string; acknowledged: boolean; acknowledged_at: string | null };
type Msg = { id: string; event: string; channel: string; subject: string; body: string; status: string; at: string; read: boolean };
type Contacts = { emergency: { label: string; phone: string }; coordinator: { name: string; phone: string | null; email: string } | null; advisor: { name: string; email: string } | null;
  next_guide: { name: string; phone: string | null } | null; hotel: Hotel | null };
type Feedback = { id: string; code: string; type: string; type_label: string; rating: number | null; text: string; service: string | null; status: string; status_label: string; response: string | null; at: string; complaints_book: string | null };
type Companions = { editable: boolean; deadline: string; lock_version: number; passengers: { id: string; seq: number; name: string; doc_type: string | null; doc_number: string | null; birth_date: string | null; type: string; lead: boolean; nationality: string | null; placeholder: boolean }[] };
type Meta = { preview: boolean; rules: PaxRules };
type Sp = { reservation?: string; vista?: string; voucher?: string; nuevo?: string };

const SECTIONS = [["viajes", "Mi viaje"], ["itinerario", "Itinerario"], ["vouchers", "Vouchers"], ["alertas", "Alertas"], ["contacto", "Contacto"], ["resena", "Califica tu viaje"]] as const;
const FEEDBACK_TYPES = [["comentario", "Comentario"], ["sugerencia", "Sugerencia"], ["felicitacion", "Felicitación"], ["reclamo", "Reclamo"], ["incidencia", "Incidencia"]];

/** Portal del pasajero (spec 08 §2 `portal-pasajero`, PROC-057). Un interno con operations.assign lo previsualiza con `?reservation=` (solo lectura). */
export default async function PassengerPortalPage({ params, searchParams }: { params: Promise<{ tab?: string[] }>; searchParams: Promise<Sp> }) {
  const [{ tab = [] }, sp, me] = await Promise.all([params, searchParams, currentUser()]);
  const section = tab[0] ?? "viajes";
  if (!SECTIONS.some(([k]) => k === section) || tab.length > 1) notFound();
  const preview = me.experience === "backoffice";
  const rq = preview && sp.reservation ? `reservation=${encodeURIComponent(sp.reservation)}` : "";
  const q = (path: string, extra = "") => `/portal/passenger${path}${[rq, extra].filter(Boolean).length ? `?${[rq, extra].filter(Boolean).join("&")}` : ""}`;
  const base = `/canales/portal-pasajero/${section}${rq ? `?${rq}` : ""}`;

  let trip: Trip | null = null;
  try {
    trip = (await api<{ data: Trip; meta: Meta }>(q("/trip"))).data;
  } catch (e) {
    if (!(e instanceof ApiError) || e.status !== 404) throw e;
  }
  // Spec 18: «Califica tu viaje» (R-OWN-REVIEWS).
  const reviews = trip ? await api<{ data: ReviewEligibility }>(q("/reviews/eligibility")).then((r) => r.data).catch(() => null) : null;
  const reviewsOn = !!reviews?.enabled;

  const body = !trip ? <Notice tone="warning">{preview ? "No hay un viaje para previsualizar: indique un código de reserva." : "No encontramos un viaje vinculado a su cuenta."}</Notice> : (
    <>
      <header className="mb-4">
        <h1 className="text-xl font-semibold text-slate-900">{trip.reservation.title}</h1>
        <p className="text-sm text-slate-600">{trip.reservation.code} · {date(trip.reservation.travel_from)} – {date(trip.reservation.travel_to)} · {trip.state.label}</p>
      </header>
      {trip.critical_alert && <Notice tone="danger">{trip.critical_alert.title}. Revise Alertas o llame al {trip.emergency}.</Notice>}
      {trip.unacked_alerts > 0 && section !== "alertas" && <Notice tone="warning">Tiene {trip.unacked_alerts} alerta(s) sin confirmar. <Link href={base.replace(`/${section}`, "/alertas")} className="underline">Ver alertas</Link></Notice>}
      {reviewsOn && reviews!.pending > 0 && section === "viajes" && (
        <div data-rate-notice><Notice>Califica tu viaje: tiene {reviews!.pending} servicio(s) pendiente(s) de calificar. <Link href={base.replace(`/${section}`, "/resena")} className="font-medium underline">Calificar ahora</Link></Notice></div>
      )}
      <div className="mt-4">
        {section === "viajes" && <TripSection trip={trip} q={q} sp={sp} base={base} preview={preview} />}
        {section === "itinerario" && <ItinerarySection q={q} />}
        {section === "vouchers" && <VoucherSection q={q} sp={sp} base={base} />}
        {section === "alertas" && <AlertSection q={q} preview={preview} rules={trip.rules} />}
        {section === "contacto" && <ContactSection q={q} sp={sp} base={base} preview={preview} rules={trip.rules} />}
        {section === "resena" && <ReviewSection reviews={reviews} preview={preview} />}
      </div>
    </>
  );

  if (!preview) return body;
  return (
    <ChannelFrame preview label="Portal del pasajero" active={section}
      nav={SECTIONS.filter(([key]) => key !== "resena" || reviewsOn).map(([key, label]) => ({ key, label, href: `/canales/portal-pasajero/${key}${rq ? `?${rq}` : ""}` }))}
      picker={(
        <form action={`/canales/portal-pasajero/${section}`} className="flex items-center gap-2">
          <label htmlFor="pick-res" className="text-xs font-medium">Reserva</label>
          <input id="pick-res" name="reservation" defaultValue={sp.reservation ?? trip?.reservation.code ?? ""} placeholder="RES-2026-…" className="w-36 rounded-md border border-indigo-200 px-2 py-1 text-xs" />
          <button className="rounded-md bg-indigo-700 px-2 py-1 text-xs font-medium text-white hover:bg-indigo-800">Ver</button>
        </form>
      )}>
      {body}
    </ChannelFrame>
  );
}

type Q = (path: string, extra?: string) => string;

function ServiceCard({ s }: { s: PaxService }) {
  return (
    <li className="rounded-lg border border-slate-200 bg-white p-3 text-sm">
      <div className="flex items-start justify-between gap-2">
        <span className="font-medium">{s.time ? `${s.time} · ` : ""}{s.name}</span>
        {s.visual && <ChStatus value={s.visual.key} label={s.visual.label} />}
      </div>
      <p className="text-xs text-slate-500">{date(s.date)}{s.meeting_point ? ` · Encuentro: ${s.meeting_point}` : ""}{s.confirmation_code ? ` · Conf. ${s.confirmation_code}` : ""}</p>
      {(s.guide || s.driver) && <p className="mt-1 text-xs text-slate-600">{s.guide && `Guía ${s.guide.name}${s.guide.phone ? ` (${s.guide.phone})` : ""}`}{s.guide && s.driver && " · "}{s.driver && `Conductor ${s.driver.name}${s.driver.vehicle ? ` · ${s.driver.vehicle}` : ""}`}</p>}
      {s.includes.length > 0 && <p className="mt-1 text-xs text-slate-500">Incluye: {s.includes.join(", ")}</p>}
    </li>
  );
}

async function TripSection({ trip, q, sp, base, preview }: { trip: Trip; q: Q; sp: Sp; base: string; preview: boolean }) {
  const view = ["hoteles", "transporte", "actividades", "acompanantes"].includes(sp.vista ?? "") ? sp.vista! : "resumen";
  const views = [["resumen", "Resumen"], ["hoteles", "Hoteles"], ["transporte", "Transporte"], ["actividades", "Actividades"], ...(trip.rules["R-COMPANIONS"] ? [["acompanantes", "Acompañantes"]] : [])];
  return (
    <>
      {trip.banners.map((b) => <CmsBanner key={b.code} banner={b} />)}
      <nav className="mb-3 flex flex-wrap gap-2 text-sm" aria-label="Mi viaje">
        {views.map(([k, l]) => <Link key={k} href={withParams(base, { vista: k })} className={`rounded-full px-3 py-1 ring-1 ${k === view ? "bg-sky-700 text-white ring-sky-700" : "bg-white text-slate-700 ring-slate-300"}`}>{l}</Link>)}
      </nav>
      {view === "resumen" && (
        <div className="grid gap-4 md:grid-cols-2">
          <section>
            <h2 className="mb-2 font-semibold">Hoy</h2>
            {trip.today.length === 0 ? <p className="text-sm text-slate-500">No tiene actividades hoy.</p> : <ul className="space-y-2">{trip.today.map((s) => <ServiceCard key={s.id} s={s} />)}</ul>}
            {trip.next_activity && <><h2 className="mb-2 mt-4 font-semibold">Próxima actividad</h2><ul><ServiceCard s={trip.next_activity} /></ul></>}
          </section>
          <section className="rounded-lg border border-slate-200 bg-white p-4 text-sm">
            <Dl rows={[["Estado", trip.reservation.status_label], ["Pasajeros", String(trip.reservation.pax)], ["Duración", `${trip.days} día(s)`],
              ["Hotel", trip.hotel ? `${trip.hotel.name} · ${date(trip.hotel.check_in)} – ${date(trip.hotel.check_out)}${trip.hotel.room ? ` · Hab. ${trip.hotel.room}` : ""}` : null],
              ["Emergencias 24/7", trip.emergency]]} />
          </section>
        </div>
      )}
      {view === "hoteles" && <HotelList rows={(await api<{ data: Hotel[] }>(q("/hotels"))).data} />}
      {view === "transporte" && <ServiceList rows={(await api<{ data: PaxService[] }>(q("/transport"))).data} empty="Sin traslados en su viaje." />}
      {view === "actividades" && <ServiceList rows={(await api<{ data: PaxService[] }>(q("/activities"))).data} empty="Sin actividades en su viaje." />}
      {view === "acompanantes" && <CompanionSection c={(await api<{ data: Companions }>(q("/companions"))).data} preview={preview} back={withParams(base, { vista: "acompanantes" })} />}
    </>
  );
}

function HotelList({ rows }: { rows: Hotel[] }) {
  if (rows.length === 0) return <p className="text-sm text-slate-500">Su viaje no incluye alojamiento.</p>;
  return (
    <ul className="space-y-2">
      {rows.map((h, i) => (
        <li key={i} className="rounded-lg border border-slate-200 bg-white p-3 text-sm">
          <div className="flex justify-between"><span className="font-medium">{h.name}</span>{h.visual && <ChStatus value={h.visual.key} label={h.visual.label} />}</div>
          <p className="text-xs text-slate-500">{date(h.check_in)} – {date(h.check_out)} · {h.nights} noche(s){h.room ? ` · Hab. ${h.room} (${h.room_type})` : ""}{h.phone ? ` · ${h.phone}` : ""}</p>
        </li>
      ))}
    </ul>
  );
}

function ServiceList({ rows, empty }: { rows: PaxService[]; empty: string }) {
  return rows.length === 0 ? <p className="text-sm text-slate-500">{empty}</p> : <ul className="space-y-2">{rows.map((s) => <ServiceCard key={s.id} s={s} />)}</ul>;
}

function CompanionSection({ c, preview, back }: { c: Companions; preview: boolean; back: string }) {
  const locked = preview || !c.editable;
  return (
    <>
      <p className="mb-3 text-sm text-slate-600">{c.editable ? `Puede actualizar los datos hasta el ${date(c.deadline)}.` : `Los datos se editaban hasta el ${date(c.deadline)}. Para cambios, contacte a su asesor.`}
        {" "}El titular solo puede actualizar su documento y fecha de nacimiento.</p>
      <ActionForm path="/portal/passenger/companions" method="PUT" ifMatch={c.lock_version} submitLabel="Guardar datos" redirectTo={back} disabled={locked}
        disabledReason={preview ? "Vista previa de solo lectura." : "Fuera de plazo."}>
        {c.passengers.map((p, i) => (
          <fieldset key={p.id} className="rounded-lg border border-slate-200 p-3">
            <legend className="px-1 text-sm font-medium">{p.lead ? "Titular" : `Acompañante ${p.seq}`} · {p.type}</legend>
            <input type="hidden" name={`passengers.${i}.id`} value={p.id} />
            <div className="grid gap-2 md:grid-cols-2">
              {!p.lead && <Input name={`passengers.${i}.name`} label="Nombre completo" defaultValue={p.name} required />}
              {p.lead && <p className="text-sm">{p.name}</p>}
              <Select name={`passengers.${i}.doc_type`} label="Documento" options={["DNI", "Pasaporte", "CE"].map((d) => ({ value: d, label: d }))} defaultValue={p.doc_type ?? "DNI"} />
              <Input name={`passengers.${i}.doc_number`} label="Número" defaultValue={p.doc_number ?? ""} maxLength={20} />
              <Input name={`passengers.${i}.birth_date`} type="date" label="Fecha de nacimiento" defaultValue={p.birth_date ?? ""} />
              {!p.lead && <Input name={`passengers.${i}.nationality`} label="Nacionalidad" defaultValue={p.nationality ?? ""} maxLength={60} />}
            </div>
          </fieldset>
        ))}
      </ActionForm>
    </>
  );
}

async function ItinerarySection({ q }: { q: Q }) {
  const { data: days } = await api<{ data: Day[] }>(q("/itinerary"));
  return (
    <ol className="space-y-4">
      {days.map((d) => (
        <li key={d.date}>
          <h2 className="mb-2 font-semibold text-slate-900">Día {d.n} · {date(d.date)}</h2>
          {d.services.length === 0 ? <p className="text-sm text-slate-500">Día libre.</p> : <ul className="space-y-2">{d.services.map((s) => <ServiceCard key={s.id} s={s} />)}</ul>}
          {d.night && <p className="mt-1 text-xs text-slate-500">Noche en {d.night.name}{d.night.room ? ` · Hab. ${d.night.room}` : ""}</p>}
        </li>
      ))}
    </ol>
  );
}

async function VoucherSection({ q, sp, base }: { q: Q; sp: Sp; base: string }) {
  const { data: rows } = await api<{ data: Voucher[] }>(q("/vouchers"));
  const open = sp.voucher ? (await api<{ data: { code: string; version: number; status_label: string; qr_token: string; document: Record<string, unknown> } }>(q(`/vouchers/${encodeURIComponent(sp.voucher)}`))).data : null;
  return (
    <>
      <Table head={["Voucher", "Servicio", "Fecha", "Proveedor", "Estado", ""]} empty="Aún no tiene vouchers emitidos">
        {rows.map((v) => (
          <tr key={v.code}><Td>{v.code}<span className="block text-xs text-slate-500">v{v.version} · {v.pax} pax</span></Td><Td>{v.service}</Td><Td className="whitespace-nowrap">{date(v.date)}</Td>
            <Td>{v.provider ?? "—"}</Td><Td><ChStatus value={v.status} label={v.status_label} /></Td><Td><RowAction href={withParams(base, { voucher: v.code })}>Ver</RowAction></Td></tr>
        ))}
      </Table>
      {open && (
        <Drawer title={`Voucher ${open.code}`} close={base}>
          <p className="text-sm">Versión {open.version} · {open.status_label}</p>
          <p className="my-3 rounded bg-slate-900 px-3 py-6 text-center font-mono text-xs text-white" aria-label="Código QR">{open.qr_token}</p>
          <Dl rows={voucherRows(open.document)} />
          <p className="mt-3 text-xs text-slate-500">Muestre este voucher al proveedor. Si cambia, verá aquí siempre la última versión.</p>
        </Drawer>
      )}
    </>
  );
}

async function AlertSection({ q, preview, rules }: { q: Q; preview: boolean; rules: PaxRules }) {
  const { data: alerts, meta } = await api<{ data: Alert[]; meta: Meta & { messages: Msg[] } }>(q("/alerts"));
  const unread = meta.messages.filter((m) => !m.read).length;
  return (
    <>
      <h2 className="mb-2 font-semibold">Alertas del viaje</h2>
      {alerts.length === 0 ? <p className="text-sm text-slate-500">Sin alertas activas.</p> : (
        <ul className="space-y-2">
          {alerts.map((a) => (
            <li key={a.id} className={`rounded-lg border p-3 text-sm ${a.acknowledged ? "border-slate-200 bg-white" : "border-amber-300 bg-amber-50"}`}>
              <p className="font-medium">{a.title}</p>
              <p className="text-slate-700">{a.message}</p>
              <p className="mt-1 text-xs text-slate-500">{a.type} · {dateTime(a.at)}{a.acknowledged_at && ` · Confirmada ${dateTime(a.acknowledged_at)}`}</p>
              {!a.acknowledged && !preview && <ActionForm path={`/portal/passenger/alerts/${a.id}/ack`} inline submitLabel="Entendido" className="mt-2 inline-flex" />}
            </li>
          ))}
        </ul>
      )}
      {rules["R-PAX-MESSAGES"] && (
        <section className="mt-6">
          <div className="mb-2 flex items-center justify-between">
            <h2 className="font-semibold">Mensajes {unread > 0 && <span className="text-sm font-normal text-slate-500">({unread} sin leer)</span>}</h2>
            {unread > 0 && !preview && <ActionForm path="/portal/passenger/messages/read-all" inline submitLabel="Marcar todo como leído" submitClass={btnGhostCls} />}
          </div>
          {meta.messages.length === 0 ? <p className="text-sm text-slate-500">Sin mensajes.</p> : (
            <ul className="space-y-2 text-sm">
              {meta.messages.map((m) => (
                <li key={m.id} className={`rounded border p-2 ${m.read ? "border-slate-200" : "border-sky-300 bg-sky-50"}`}>
                  <p className="font-medium">{m.subject}</p><p className="text-slate-700 [overflow-wrap:anywhere]">{m.body}</p>
                  <p className="text-xs text-slate-500">{m.channel} · {dateTime(m.at)} · {m.status === "fallido" ? "No entregado" : m.read ? "Leído" : "Entregado"}</p>
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
    </>
  );
}

async function ContactSection({ q, sp, base, preview, rules }: { q: Q; sp: Sp; base: string; preview: boolean; rules: PaxRules }) {
  const { data: c } = await api<{ data: Contacts }>(q("/contacts"));
  const inbox = rules["R-PAX-MESSAGES"] ? await api<{ meta: { tickets: Ticket[]; topics: string[] } }>(q("/messages")) : null;
  const fb = rules["R-PAX-FEEDBACK"] ? await api<{ data: Feedback[]; meta: { services: { id: string; name: string; date: string }[] } }>(q("/feedback")) : null;
  return (
    <>
      <section className="grid gap-3 md:grid-cols-2">
        <div className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm"><p className="font-semibold text-rose-900">{c.emergency.label}</p><a href={`tel:${c.emergency.phone}`} className="text-lg text-rose-800 underline">{c.emergency.phone}</a></div>
        <div className="rounded-lg border border-slate-200 bg-white p-4">
          <Dl rows={[["Coordinador", c.coordinator ? `${c.coordinator.name}${c.coordinator.phone ? ` · ${c.coordinator.phone}` : ""}` : null], ["Asesor", c.advisor ? `${c.advisor.name} · ${c.advisor.email}` : null],
            ["Guía de la próxima actividad", c.next_guide ? `${c.next_guide.name}${c.next_guide.phone ? ` · ${c.next_guide.phone}` : ""}` : null], ["Hotel", c.hotel ? `${c.hotel.name}${c.hotel.phone ? ` · ${c.hotel.phone}` : ""}` : null]]} />
        </div>
      </section>
      {inbox && (
        <section className="mt-6">
          <div className="mb-2 flex items-center justify-between"><h2 className="font-semibold">Mensajes a operaciones</h2>
            {!preview && <RowAction primary href={withParams(base, { nuevo: "mensaje" })}>Escribir</RowAction>}</div>
          <Table head={["Asunto", "Creado", "Estado"]} empty="Sin mensajes enviados">
            {inbox.meta.tickets.map((t) => <tr key={t.id}><Td>{t.subject}<span className="block text-xs text-slate-500">{t.message}</span></Td><Td className="whitespace-nowrap">{dateTime(t.created_at)}</Td><Td><ChStatus value={t.status} label={t.status_label} /></Td></tr>)}
          </Table>
        </section>
      )}
      {fb && (
        <section className="mt-6">
          <div className="mb-2 flex items-center justify-between"><h2 className="font-semibold">Sus comentarios</h2>
            {!preview && <RowAction primary href={withParams(base, { nuevo: "comentario" })}>Dejar comentario</RowAction>}</div>
          {fb.data.length === 0 ? <p className="text-sm text-slate-500">Aún no ha dejado comentarios.</p> : (
            <ul className="space-y-2 text-sm">
              {fb.data.map((f) => (
                <li key={f.id} className="rounded border border-slate-200 bg-white p-2">
                  <p><span className="font-medium">{f.type_label}</span>{f.rating ? ` · ${"★".repeat(f.rating)}` : ""}{f.service ? ` · ${f.service}` : ""} · <ChStatus value={f.status} label={f.status_label} /></p>
                  <p className="text-slate-700">{f.text}</p>
                  {f.response && <p className="mt-1 text-xs text-sky-800">Respuesta: {f.response}</p>}
                  {f.complaints_book && <p className="mt-1 text-xs text-slate-500">{f.complaints_book} <Link href="/libro-reclamaciones" className="underline">Libro de reclamaciones</Link></p>}
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
      {sp.nuevo === "mensaje" && inbox && !preview && (
        <Drawer title="Mensaje a operaciones" close={base}>
          <ActionForm path="/portal/passenger/messages" submitLabel="Enviar" redirectTo={base}>
            <Select name="topic" label="Tema" options={inbox.meta.topics.map((t) => ({ value: t, label: t }))} defaultValue="Otro" />
            <TextArea name="message" label="Mensaje" required rows={4} maxLength={2000} />
            <p className="text-xs text-slate-500">Para emergencias llame al {c.emergency.phone}.</p>
          </ActionForm>
        </Drawer>
      )}
      {sp.nuevo === "comentario" && fb && !preview && (
        <Drawer title="Dejar comentario" close={base}>
          <ActionForm path="/portal/passenger/feedback" idempotent submitLabel="Enviar" redirectTo={base}>
            <Select name="type" label="Tipo" options={FEEDBACK_TYPES.map(([value, label]) => ({ value, label }))} defaultValue="comentario" />
            <Select name="service_id" label="Servicio (opcional)" placeholder="Viaje en general" options={fb.meta.services.map((s) => ({ value: s.id, label: `${date(s.date)} · ${s.name}` }))} />
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Calificación (opcional)</span>
              <select name="rating" className={inputCls} defaultValue=""><option value="">Sin calificación</option>{[5, 4, 3, 2, 1].map((n) => <option key={n} value={n}>{"★".repeat(n)}</option>)}</select></label>
            <TextArea name="text" label="Comentario" required minLength={5} maxLength={1000} rows={4} />
            <p className="text-xs text-slate-500">Un reclamo o incidencia abre una conversación con operaciones.</p>
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}

/** Spec 18: «Califica tu viaje» y «Mis reseñas» (estado como lo ve el pasajero y la respuesta de la agencia). */
function ReviewSection({ reviews, preview }: { reviews: ReviewEligibility | null; preview: boolean }) {
  if (!reviews?.enabled) return <Notice>Las reseñas no están disponibles para su viaje.</Notice>;
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <section>
        <h2 className="mb-1 font-semibold">Califica tu viaje</h2>
        <p className="mb-3 text-sm text-slate-600">Solo los viajeros con servicio realizado pueden calificar. Su reseña aparecerá con la marca «Viaje verificado».</p>
        <ul className="mb-4 space-y-1 text-sm">
          {reviews.items.map((i) => (
            <li key={i.product.ref} data-rate-item={i.product.ref}>
              {i.product.name} · {date(i.date)} — <span className="text-slate-600">{({ pendiente: "Pendiente de calificar", calificado: "Ya calificado", aun_no: "Podrá calificarlo cuando se realice", no_disponible: "No disponible" } as const)[i.status]}</span>
            </li>
          ))}
        </ul>
        {preview && <Notice>Vista previa de solo lectura: la reseña la escribe el pasajero.</Notice>}
        <OwnReviewForm action={submitPassengerReview} items={reviews.items} subratings={reviews.subratings} langs={reviews.langs} preview={preview} />
      </section>
      <section>
        <h2 className="mb-2 font-semibold">Mis reseñas</h2>
        {reviews.reviews.length === 0 ? <p className="text-sm text-slate-500">Aún no ha escrito reseñas.</p> : (
          <ul className="space-y-3 text-sm">
            {reviews.reviews.map((r) => (
              <li key={r.code} className="rounded-lg border border-slate-200 bg-white p-3" data-my-review={r.code}>
                <div className="flex flex-wrap items-start justify-between gap-2"><span className="font-medium">{r.title}</span><ChStatus value={r.status} label={r.status_label} /></div>
                <p className="text-amber-500">{stars(r.stars)}</p>
                <p className="text-xs text-slate-500">{r.product} · {dateTime(r.created_at)}</p>
                <p className="mt-1 text-slate-700 [overflow-wrap:anywhere]">{r.body}</p>
                {r.reply && <p className="mt-2 rounded border-l-4 border-sky-600 bg-sky-50 px-2 py-1 text-xs" data-agency-reply><span className="font-medium">Respuesta de la agencia:</span> {r.reply}</p>}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
