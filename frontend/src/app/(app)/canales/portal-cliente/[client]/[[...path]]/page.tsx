import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { money } from "@/lib/finance";
import { currentUser } from "@/lib/crm-server";
import { clientBase, type Banner, type ClientQuoteRow, type ClientReservation, type Receivable, type Ticket } from "@/lib/channels";
import type { PortalQuote } from "@/lib/quotes";
import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, Input, Kpis, Notice, Select, Table, Td, TextArea } from "@/components/crm/ui";
import { ChannelFrame, ChStatus, CmsBanner, Dl, PreviewPicker } from "@/components/channels/ui";
import { QuoteDocument } from "@/components/quotes/QuoteDocument";
import { QuoteStatus } from "@/components/quotes/ui";
import { RowAction, withParams } from "@/components/supplier/ui";

type Notif = { id: string; event: string; subject: string; body: string; channel: string; at: string; read: boolean };
type Home = {
  client: { id: string; code: string; name: string; email: string | null };
  kpis: { quotes_pending: number; upcoming_trips: number; balance: string; overdue: number };
  pending: { type: "quote" | "receivable"; code: string; id?: string; label: string; detail: string; due: string; overdue?: boolean }[];
  next_trip: (ClientReservation & { days_to_go: number }) | null;
  advisor: { name: string; email: string; phone: string | null } | null;
  notifications: Notif[]; banners: Banner[];
};
type Doc = { kind: string; code: string; label: string; reservation: string | null; date: string | null };
type Payment = { code: string; date: string | null; method: string; amount: string; currency: string; status: string; status_label: string; reservation: string | null; reference: string | null };
type Quote = PortalQuote & { reject_reasons: string[]; lock_version: number };
type Sp = { accion?: string; pagar?: string; nuevo?: string };

const SECTIONS = [["inicio", "Inicio"], ["cotizaciones", "Cotizaciones"], ["viajes", "Mis viajes"], ["documentos", "Documentos"], ["pagos", "Pagos"], ["soporte", "Soporte"]] as const;

/** Portal del cliente (spec 08 §2 `portal-cliente/:client`, PROC-056). Un interno lo ve como "Vista del canal" en solo lectura. */
export default async function ClientPortalPage({ params, searchParams }: { params: Promise<{ client: string; path?: string[] }>; searchParams: Promise<Sp> }) {
  const [{ client, path = [] }, sp, me] = await Promise.all([params, searchParams, currentUser()]);
  const [section = "inicio", code] = path;
  if (!SECTIONS.some(([k]) => k === section) || path.length > 2 || (code && !["cotizaciones", "viajes"].includes(section))) notFound();
  const preview = me.experience === "backoffice";
  const api0 = clientBase(preview, client);
  const root = `/canales/portal-cliente/${client}`;
  const self = `${root}/${path.join("/")}`;
  const get = <T,>(p: string) => api<{ data: T }>(`${api0}${p}`).then((r) => r.data).catch((e) => {
    if (e instanceof ApiError && e.status === 404) notFound();
    throw e;
  });

  let body: React.ReactNode;
  if (section === "inicio") body = <HomeSection home={await get<Home>("/home")} root={root} preview={preview} />;
  else if (section === "cotizaciones" && code) body = <QuoteSection q={await get<Quote>(`/quotes/${encodeURIComponent(code)}`)} root={root} self={self} sp={sp} preview={preview} agency={me.tenant.name} name={me.name} />;
  else if (section === "cotizaciones") body = <QuotesList rows={await get<ClientQuoteRow[]>("/quotes")} root={root} />;
  else if (section === "viajes" && code) body = <TripSection r={await get<ClientReservation>(`/reservations/${encodeURIComponent(code)}`)} root={root} preview={preview} />;
  else if (section === "viajes") body = <TripsList rows={await get<ClientReservation[]>("/reservations")} root={root} />;
  else if (section === "documentos") body = <DocsSection docs={await get<Doc[]>("/documents")} />;
  else if (section === "pagos") body = <PaySection rows={await get<Receivable[]>("/receivables")} history={await get<Payment[]>("/payments")} self={self} sp={sp} preview={preview} />;
  else body = <SupportSection rows={await get<Ticket[]>("/tickets")} trips={await get<ClientReservation[]>("/reservations")} self={self} sp={sp} preview={preview} />;

  if (!preview) return body;
  const clients = (await api<{ data: { id: string; name: string; code: string }[] }>("/channels/portal-preview/clients")).data;
  return (
    <ChannelFrame preview label="Portal del cliente" active={section} nav={SECTIONS.map(([key, label]) => ({ key, label, href: `${root}/${key}` }))}
      picker={<PreviewPicker name="client" label="Cliente" value={client} action="/canales/portal-cliente" options={clients.map((c) => ({ value: c.id, label: `${c.name} (${c.code})` }))} />}>
      {body}
    </ChannelFrame>
  );
}

const h1 = "mb-4 text-xl font-semibold text-slate-900";

function HomeSection({ home, root, preview }: { home: Home; root: string; preview: boolean }) {
  return (
    <>
      <h1 className={h1}>Hola, {home.client.name}</h1>
      {home.banners.map((b) => <CmsBanner key={b.code} banner={b} />)}
      <Kpis items={[
        { label: "Propuestas por responder", value: home.kpis.quotes_pending },
        { label: "Próximos viajes", value: home.kpis.upcoming_trips },
        { label: "Saldo pendiente", value: money(home.kpis.balance, "PEN"), hint: home.kpis.overdue ? `${home.kpis.overdue} cuota(s) vencida(s)` : "Al día" },
      ]} />
      <div className="mt-4 grid gap-4 md:grid-cols-2">
        <section className="rounded-lg border border-slate-200 bg-white p-4">
          <h2 className="mb-2 font-semibold text-slate-900">Pendientes</h2>
          {home.pending.length === 0 ? <p className="text-sm text-slate-500">No tiene pendientes.</p> : (
            <ul className="space-y-2 text-sm">
              {home.pending.map((p) => (
                <li key={`${p.type}-${p.code}`} className="flex items-start justify-between gap-2">
                  <span>{p.label}<span className="block text-xs text-slate-500">{p.detail} · vence {date(p.due)}{p.overdue && <span className="text-rose-700"> (vencida)</span>}</span></span>
                  <Link href={p.type === "quote" ? `${root}/cotizaciones/${p.code}` : `${root}/pagos${preview ? "" : `?pagar=${p.id}`}`} className="whitespace-nowrap text-sky-800 hover:underline">{p.type === "quote" ? "Ver" : "Pagar"}</Link>
                </li>
              ))}
            </ul>
          )}
        </section>
        <section className="rounded-lg border border-slate-200 bg-white p-4">
          <h2 className="mb-2 font-semibold text-slate-900">Próximo viaje</h2>
          {home.next_trip ? (
            <p className="text-sm"><Link href={`${root}/viajes/${home.next_trip.code}`} className="font-medium text-sky-800 hover:underline">{home.next_trip.title}</Link><br />
              {date(home.next_trip.travel_from)} – {date(home.next_trip.travel_to)} · faltan {home.next_trip.days_to_go} día(s)</p>
          ) : <p className="text-sm text-slate-500">Sin viajes programados.</p>}
          {home.advisor && <p className="mt-3 text-sm text-slate-600">Su asesor: <span className="font-medium">{home.advisor.name}</span> · {home.advisor.email}{home.advisor.phone ? ` · ${home.advisor.phone}` : ""}</p>}
        </section>
      </div>
      <section className="mt-4 rounded-lg border border-slate-200 bg-white p-4">
        <h2 className="mb-2 font-semibold text-slate-900">Notificaciones recientes</h2>
        {home.notifications.length === 0 ? <p className="text-sm text-slate-500">Sin notificaciones.</p> : (
          <ul className="space-y-2 text-sm">{home.notifications.map((n) => <li key={n.id}><span className="text-xs text-slate-500">{dateTime(n.at)}</span><span className="block font-medium">{n.subject}</span><span className="block text-slate-600 [overflow-wrap:anywhere]">{n.body}</span></li>)}</ul>
        )}
      </section>
    </>
  );
}

function QuotesList({ rows, root }: { rows: ClientQuoteRow[]; root: string }) {
  return (
    <>
      <h1 className={h1}>Mis cotizaciones</h1>
      <Table head={["Cotización", "Viaje", "Estado", "Total", "Válida hasta"]} empty="Aún no tiene cotizaciones">
        {rows.map((q) => (
          <tr key={q.code}>
            <Td><Link href={`${root}/cotizaciones/${q.code}`} className="font-medium text-sky-800 hover:underline">{q.title}</Link><span className="block text-xs text-slate-500">{q.code} · v{q.version_no}</span></Td>
            <Td className="whitespace-nowrap">{date(q.travel_from)} – {date(q.travel_to)}</Td>
            <Td><QuoteStatus value={q.display_status} label={q.status_label} /></Td>
            <Td className="whitespace-nowrap">{money(q.total, q.currency)}</Td>
            <Td className="whitespace-nowrap">{date(q.valid_until)}{q.expired && <span className="block text-xs text-amber-800">Vencida</span>}</Td>
          </tr>
        ))}
      </Table>
    </>
  );
}

function QuoteSection({ q, root, self, sp, preview, agency, name }: { q: Quote; root: string; self: string; sp: Sp; preview: boolean; agency: string; name: string }) {
  const api0 = `/portal/client/quotes/${q.code}`;
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Link href={`${root}/cotizaciones`} className="text-sm text-sky-800 hover:underline">← Mis cotizaciones</Link>
        <QuoteStatus value={q.display_status} label={q.status_label} />
      </div>
      {q.notice && <Notice tone="warning">{q.notice}</Notice>}
      {q.approval && <Notice>Usted aprobó la versión {q.approval.version_no} el {dateTime(q.approval.at)}. Su asesor le contactará para confirmar la reserva.</Notice>}
      {q.rejection && <Notice tone="danger">Rechazada el {dateTime(q.rejection.at)}: {q.rejection.reason}</Notice>}
      <QuoteDocument doc={q} agency={agency} />
      {preview ? <Notice>Vista previa: el cliente aprueba o rechaza desde su usuario; abrirla aquí no la marca como vista.</Notice> : (
        <section className="rounded-lg border border-sky-200 bg-sky-50 p-4">
          {q.can_respond && (
            <>
              <h2 className="mb-2 font-semibold text-sky-900">¿Qué le parece la propuesta?</h2>
              <div className="mb-3 flex gap-2">
                <RowAction primary href={`${self}?accion=aprobar`}>Aprobar cotización</RowAction>
                <RowAction href={`${self}?accion=rechazar`}>Rechazar</RowAction>
              </div>
              {sp.accion === "aprobar" && (
                <ActionForm path={`${api0}/approve`} ifMatch={q.lock_version} idempotent redirectTo={self} submitLabel="Confirmar aprobación">
                  <Input name="approver_name" label="Nombre de quien aprueba" required defaultValue={name} maxLength={150} />
                  <TextArea name="note" label="Comentario (opcional)" rows={2} maxLength={300} />
                  <label className="flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="accept_terms" value="true" required className="mt-0.5 h-4 w-4" />
                    Acepto la propuesta, sus condiciones y las políticas de cancelación indicadas.
                  </label>
                </ActionForm>
              )}
              {sp.accion === "rechazar" && (
                <ActionForm path={`${api0}/reject`} ifMatch={q.lock_version} redirectTo={self} submitLabel="Enviar rechazo">
                  <Select name="reason" label="Motivo" required placeholder="Elegir…" options={q.reject_reasons.map((r) => ({ value: r, label: r }))} />
                  <TextArea name="comment" label="Cuéntenos más" required rows={3} maxLength={1500} />
                </ActionForm>
              )}
            </>
          )}
          {q.expired && (
            <ActionForm path={`${api0}/update-request`} submitLabel="Solicitar versión actualizada" success="Solicitud enviada a su asesor.">
              <TextArea name="message" label="Mensaje para su asesor (opcional)" rows={2} maxLength={1000} />
            </ActionForm>
          )}
        </section>
      )}
    </div>
  );
}

function TripsList({ rows, root }: { rows: ClientReservation[]; root: string }) {
  return (
    <>
      <h1 className={h1}>Mis viajes</h1>
      <Table head={["Viaje", "Fechas", "Pasajeros", "Total", "Estado"]} empty="Aún no tiene reservas">
        {rows.map((r) => (
          <tr key={r.code}>
            <Td><Link href={`${root}/viajes/${r.code}`} className="font-medium text-sky-800 hover:underline">{r.title}</Link><span className="block text-xs text-slate-500">{r.code}</span></Td>
            <Td className="whitespace-nowrap">{date(r.travel_from)} – {date(r.travel_to)}</Td>
            <Td>{r.pax}</Td>
            <Td className="whitespace-nowrap">{money(r.total, r.currency)}</Td>
            <Td><ChStatus value={r.status} label={r.status_label} /></Td>
          </tr>
        ))}
      </Table>
    </>
  );
}

function TripSection({ r, root, preview }: { r: ClientReservation; root: string; preview: boolean }) {
  return (
    <div className="space-y-4">
      <Link href={`${root}/viajes`} className="text-sm text-sky-800 hover:underline">← Mis viajes</Link>
      <div className="flex flex-wrap items-center gap-2"><h1 className="text-xl font-semibold text-slate-900">{r.title}</h1><ChStatus value={r.status} label={r.status_label} /></div>
      <Dl rows={[["Reserva", r.code], ["Fechas", `${date(r.travel_from)} – ${date(r.travel_to)}`], ["Total", money(r.total, r.currency)], ["Itinerario", r.itinerary_available ? "Disponible en Documentos" : "Se habilita al confirmar la reserva"]]} />
      <section>
        <h2 className="mb-2 font-semibold">Servicios</h2>
        <Table head={["Servicio", "Fecha", "Pax", "Estado"]} empty="Sin servicios">
          {r.services?.map((s) => <tr key={s.code}><Td>{s.name}</Td><Td className="whitespace-nowrap">{date(s.date)} {s.time ?? ""}</Td><Td>{s.pax}</Td><Td><ChStatus value={s.status} label={s.status_label} /></Td></tr>)}
        </Table>
      </section>
      <section>
        <h2 className="mb-2 font-semibold">Pasajeros</h2>
        <ul className="text-sm">{r.passengers?.map((p, i) => <li key={i}>{p.name}{p.lead && " (titular)"} · {p.type} · {p.doc_type ?? ""} {p.doc ?? "Por registrar"}</li>)}</ul>
      </section>
      {(r.vouchers?.length ?? 0) > 0 && (
        <section>
          <h2 className="mb-2 font-semibold">Vouchers</h2>
          <ul className="text-sm">{r.vouchers?.map((v) => <li key={v.code}>{v.code} · {v.service} · {date(v.date)} · {v.status_label}</li>)}</ul>
        </section>
      )}
      {(r.receivables?.length ?? 0) > 0 && (
        <section>
          <h2 className="mb-2 font-semibold">Pagos del viaje</h2>
          <ul className="text-sm">{r.receivables?.map((x) => <li key={x.id}>{x.concept} · saldo {money(x.balance, x.currency)} · vence {date(x.due_date)} · <ChStatus value={x.status} label={x.status_label} />
            {x.can_pay && !preview && <Link href={`${root}/pagos?pagar=${x.id}`} className="ml-2 text-sky-800 hover:underline">Pagar</Link>}</li>)}</ul>
        </section>
      )}
    </div>
  );
}

function DocsSection({ docs }: { docs: Doc[] }) {
  return (
    <>
      <h1 className={h1}>Documentos</h1>
      <Table head={["Documento", "Tipo", "Reserva", "Fecha"]} empty="Aún no tiene documentos disponibles">
        {docs.map((d) => <tr key={`${d.kind}-${d.code}`}><Td>{d.label}</Td><Td>{d.kind}</Td><Td>{d.reservation ?? "—"}</Td><Td className="whitespace-nowrap">{date(d.date)}</Td></tr>)}
      </Table>
    </>
  );
}

function PaySection({ rows, history, self, sp, preview }: { rows: Receivable[]; history: Payment[]; self: string; sp: Sp; preview: boolean }) {
  const paying = sp.pagar ? rows.find((r) => r.id === sp.pagar && r.can_pay) ?? null : null;
  return (
    <>
      <h1 className={h1}>Pagos</h1>
      <Table head={["Concepto", "Reserva", "Monto", "Saldo", "Vence", "Estado", ""]} empty="No tiene cuotas">
        {rows.map((r) => (
          <tr key={r.id}>
            <Td>{r.concept}<span className="block text-xs text-slate-500">{r.code}</span></Td>
            <Td>{r.reservation ?? "—"}</Td>
            <Td className="whitespace-nowrap">{money(r.amount, r.currency)}</Td>
            <Td className="whitespace-nowrap">{money(r.balance, r.currency)}</Td>
            <Td className="whitespace-nowrap">{date(r.due_date)}{r.overdue && <span className="block text-xs text-rose-700">Vencida</span>}</Td>
            <Td><ChStatus value={r.status} label={r.status_label} /></Td>
            <Td>{r.can_pay && !preview && <RowAction primary href={withParams(self, { pagar: r.id })}>Pagar</RowAction>}</Td>
          </tr>
        ))}
      </Table>
      <h2 className="mb-2 mt-6 font-semibold text-slate-900">Historial de pagos</h2>
      <Table head={["Pago", "Fecha", "Medio", "Monto", "Estado"]} empty="Sin pagos registrados">
        {history.map((p) => (
          <tr key={p.code}><Td>{p.code}<span className="block text-xs text-slate-500">{p.reservation ?? ""}</span></Td><Td className="whitespace-nowrap">{date(p.date)}</Td><Td>{p.method}</Td>
            <Td className="whitespace-nowrap">{money(p.amount, p.currency)}</Td><Td><ChStatus value={p.status} label={p.status_label} />{p.reference && <span className="block text-xs text-rose-700">{p.reference}</span>}</Td></tr>
        ))}
      </Table>
      {paying && (
        <Drawer title={`Pagar ${paying.concept}`} close={self}>
          <ActionForm path={`/portal/client/receivables/${paying.id}/payments`} idempotent submitLabel="Pagar ahora">
            <p className="text-sm text-slate-600">Saldo {money(paying.balance, paying.currency)} · vence {date(paying.due_date)}</p>
            <Input name="amount" type="number" step="0.01" min="0.01" max={paying.balance} label={`Monto (${paying.currency})`} required defaultValue={paying.balance} />
            <Input name="card.holder" label="Titular de la tarjeta" required autoComplete="cc-name" />
            <Input name="card.number" label="Número de tarjeta" required inputMode="numeric" autoComplete="cc-number" pattern="[\d ]{13,23}" />
            <div className="grid grid-cols-2 gap-3">
              <Input name="card.expiry" label="Vencimiento (MM/AA)" required placeholder="12/28" pattern="\d{2}/\d{2}" autoComplete="cc-exp" />
              <Input name="card.cvv" label="CVV" required inputMode="numeric" pattern="\d{3,4}" autoComplete="cc-csc" />
            </div>
            <p className="text-xs text-slate-500">Pasarela de prueba: la tarjeta 4000 0000 0000 0002 simula un rechazo. No guardamos los datos de su tarjeta.</p>
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}

const CATEGORIES = ["Cambio de fecha", "Documentos", "Facturación", "Pagos", "Cotizaciones", "Reclamo", "Consulta general"];

function SupportSection({ rows, trips, self, sp, preview }: { rows: Ticket[]; trips: ClientReservation[]; self: string; sp: Sp; preview: boolean }) {
  return (
    <>
      <div className="mb-4 flex items-center justify-between"><h1 className="text-xl font-semibold text-slate-900">Soporte</h1>
        {!preview && <RowAction primary href={withParams(self, { nuevo: "1" })}>Nueva solicitud</RowAction>}</div>
      <Table head={["Solicitud", "Categoría", "Reserva", "Creada", "Estado"]} empty="No tiene solicitudes">
        {rows.map((t) => (
          <tr key={t.id}><Td>{t.subject}<span className="block text-xs text-slate-500">{t.code}</span></Td><Td>{t.category}</Td><Td>{t.reservation ?? "—"}</Td>
            <Td className="whitespace-nowrap">{dateTime(t.created_at)}</Td><Td><ChStatus value={t.status} label={t.status_label} /></Td></tr>
        ))}
      </Table>
      {sp.nuevo && !preview && (
        <Drawer title="Nueva solicitud" close={self}>
          <ActionForm path="/portal/client/tickets" submitLabel="Enviar" redirectTo={self} success="Solicitud enviada.">
            <Select name="category" label="Categoría" required options={CATEGORIES.map((c) => ({ value: c, label: c }))} defaultValue="Consulta general" />
            <Select name="reservation" label="Reserva (opcional)" placeholder="Sin reserva" options={trips.map((r) => ({ value: r.code, label: `${r.code} · ${r.title}` }))} />
            <Input name="subject" label="Asunto" required maxLength={150} />
            <TextArea name="message" label="Mensaje" required rows={4} maxLength={2000} />
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}
