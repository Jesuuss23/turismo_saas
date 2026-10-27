import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import type { VoucherDocument } from "@/lib/ops";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnGhostCls, inputCls, PageHeader } from "@/components/crm/ui";
import { Hidden } from "@/components/catalog/ui";
import { VoucherDoc } from "@/components/ops/VoucherDoc";

type Search = { tab?: string; reserva?: string; v?: string; doc?: string; s?: string };
type TripService = {
  id: string; code: string; name: string; date: string; time: string | null; kind: string; status: string; status_label: string; provider: string | null;
  confirmation_code: string | null; meeting_point: string; guide: { name: string; phone?: string | null } | null; driver: { name: string; vehicle: string | null } | null; includes: string[];
};
type Hotel = { name: string; phone: string | null; check_in: string; check_out: string; room: string | null; room_type: string | null };
type Trip = {
  reservation: { code: string; title: string; status: string; status_label: string; travel_from: string; travel_to: string; pax: number };
  state: { key: string; label: string }; days: number; critical_alert: { subject: string; body: string } | null;
  next_activity: (TripService & { starts_at: string }) | null; hotel: Hotel | null; unread_alerts: number; unread_messages: number; emergency: string;
};
type Preview = { preview: false } | { preview: true; reservations: { code: string; title: string }[] };
type Doc = { key: string; label: string; kind: string; available: boolean; detail?: string };

const TABS = [
  { key: "viaje", label: "Mi viaje" }, { key: "itinerario", label: "Itinerario" }, { key: "vouchers", label: "Vouchers" },
  { key: "docs", label: "Docs" }, { key: "alertas", label: "Alertas" }, { key: "soporte", label: "Soporte" },
];
const card = "rounded-xl bg-white p-3 shadow-sm";
const DOC_FIELDS: Record<string, string> = { code: "Reserva", title: "Viaje", travel_from: "Desde", travel_to: "Hasta", status: "Estado" };

/** App del pasajero (PROC-057) como web móvil: el pasajero ve SU reserva; los internos, una vista previa con selector de reserva. */
export default async function PassengerPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const tab = TABS.some((t) => t.key === sp.tab) ? sp.tab! : "viaje";
  const rq = sp.reserva ? `?reservation=${encodeURIComponent(sp.reserva)}` : "";
  const { data: trip, meta } = await api<{ data: Trip; meta: Preview }>(`/mobile/passenger/trip${rq}`);
  const href = (over: Partial<Search>) => `/mobile/pasajero${qs({ reserva: sp.reserva, tab, ...over })}`;
  const here = href({});

  const body = await (async () => {
    switch (tab) {
      case "itinerario": {
        const { data: days } = await api<{ data: { date: string; n: number; services: TripService[]; night: Hotel | null }[] }>(`/mobile/passenger/itinerary${rq}`);
        return days.map((d) => (
          <section key={d.date} className="space-y-2">
            <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Día {d.n} · {date(d.date)}</h3>
            {d.services.map((s) => (
              <Link key={s.id} href={href({ s: sp.s === s.id ? undefined : s.id })} scroll={false} className={`${card} block`}>
                <div className="flex justify-between text-xs text-slate-500"><span>{s.time ?? "Sin hora"}</span><span>{s.status_label}</span></div>
                <p className="font-medium text-slate-900">{s.name}</p>
                {sp.s === s.id && <ServiceInfo s={s} />}
              </Link>
            ))}
            {d.services.length === 0 && <p className={`${card} text-xs text-slate-500`}>Día libre</p>}
            {d.night && <p className="rounded-xl bg-indigo-50 px-3 py-2 text-xs text-indigo-900">🌙 {d.night.name}{d.night.room && ` · habitación ${d.night.room}`}</p>}
          </section>
        ));
      }
      case "vouchers": {
        if (sp.v) {
          const { data: v } = await api<{ data: { code: string; status: string; version: number; qr_token: string; document: VoucherDocument } }>(`/mobile/passenger/vouchers/${sp.v}${rq}`);
          return <><Link href={href({ v: undefined })} className="text-xs text-sky-800">← Vouchers</Link><VoucherDoc code={v.code} version={v.version} status={v.status} qr={v.qr_token} d={v.document} /></>;
        }
        const { data } = await api<{ data: { id: string; code: string; service: string; date: string; provider: string | null; status_label: string; version: number; pax: number }[] }>(`/mobile/passenger/vouchers${rq}`);
        return data.length ? data.map((v) => (
          <Link key={v.id} href={href({ v: v.code })} className={`${card} block`}>
            <div className="flex justify-between text-xs text-slate-500"><span>{date(v.date)}</span><span>{v.code} · v{v.version}</span></div>
            <p className="font-medium text-slate-900">{v.service}</p><p className="text-xs text-slate-600">{v.provider} · {v.pax} pax</p>
          </Link>
        )) : <p className={`${card} text-xs text-slate-500`}>Aún no hay vouchers emitidos.</p>;
      }
      case "docs": {
        const { data } = await api<{ data: Doc[] }>(`/mobile/passenger/documents${rq}`);
        const open = sp.doc ? (await api<{ data: Doc & { content: unknown } }>(`/mobile/passenger/documents/${sp.doc}/download${rq}`).catch(() => null))?.data : null;
        return (
          <>
            {data.map((d) => (
              <div key={d.key} className={`${card} flex items-center justify-between text-sm`}>
                <span><span className="font-medium text-slate-900">{d.label}</span><span className="block text-xs text-slate-500">{d.kind}{d.detail && ` · ${d.detail}`}</span></span>
                {d.available ? <Link href={href({ doc: d.key })} scroll={false} className="text-xs font-medium text-sky-800">Ver</Link> : <span className="text-xs text-slate-400">No disponible</span>}
              </div>
            ))}
            {open && <DocView d={open} />}
          </>
        );
      }
      case "alertas": {
        const { data } = await api<{ data: { id: string; subject: string; body: string; at: string; read: boolean }[] }>(`/mobile/passenger/alerts${rq}`);
        return data.length ? data.map((a) => (
          <div key={a.id} className={`${card} text-sm ${a.read ? "" : "ring-2 ring-sky-300"}`}>
            <div className="flex justify-between text-xs text-slate-500"><span>{dateTime(a.at)}</span>{!a.read && <span className="font-semibold text-sky-700">Nueva</span>}</div>
            <p className="font-medium text-slate-900">{a.subject}</p><p className="text-slate-700">{a.body}</p>
            {!a.read && <ActionForm path={`/mobile/passenger/alerts/${a.id}/read${rq}`} inline submitLabel="Marcar como leída" submitClass="mt-1 text-xs text-sky-800 hover:underline" />}
          </div>
        )) : <p className={`${card} text-xs text-slate-500`}>Sin alertas.</p>;
      }
      case "soporte": {
        const [{ data: support }, messages] = await Promise.all([
          api<{ data: { emergency_phone: string; hours: string; whatsapp: string | null } }>(`/mobile/passenger/support${rq}`),
          api<{ data: { id: string; sender: string; body: string; at: string }[]; meta: { quick_replies: string[] } }>(`/mobile/passenger/messages${rq}`),
        ]);
        return (
          <>
            <div className="rounded-xl bg-rose-600 p-3 text-white">
              <p className="text-xs">Emergencias {support.hours}</p>
              <p className="text-lg font-semibold">{support.emergency_phone}</p>
              <a href={`tel:${support.emergency_phone.replace(/\s/g, "")}`} className="mt-2 inline-block rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-rose-700">Llamar</a>
            </div>
            <section className="space-y-2" aria-label="Chat con la agencia">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Chat con la agencia</h3>
              {messages.data.map((m) => (
                <p key={m.id} className={`max-w-[85%] rounded-2xl px-3 py-2 text-sm ${m.sender === "agency" ? "bg-white text-slate-800 shadow-sm" : "ml-auto bg-sky-700 text-white"}`}>
                  {m.body}<span className={`block text-[10px] ${m.sender === "agency" ? "text-slate-400" : "text-sky-100"}`}>{dateTime(m.at)}</span>
                </p>
              ))}
              <div className="flex flex-wrap gap-1">
                {messages.meta.quick_replies.map((r) => (
                  <ActionForm key={r} path={`/mobile/passenger/messages${rq}`} inline redirectTo={here} submitLabel={r} submitClass="rounded-full bg-white px-3 py-1 text-xs text-sky-800 ring-1 ring-sky-200">
                    <Hidden name="body" value={r} /><Hidden name="quick_reply" value={true} />
                  </ActionForm>
                ))}
              </div>
              <ActionForm path={`/mobile/passenger/messages${rq}`} redirectTo={here} submitLabel="Enviar" className="flex items-start gap-2">
                <input name="body" required maxLength={2000} placeholder="Escriba su mensaje" aria-label="Mensaje" className={inputCls} />
              </ActionForm>
            </section>
          </>
        );
      }
      default: {
        const n = trip.next_activity;
        return (
          <>
            <div className="rounded-xl bg-sky-800 p-4 text-white">
              <p className="text-xs text-sky-100">{trip.reservation.code}</p>
              <h2 className="text-lg font-semibold">{trip.reservation.title}</h2>
              <p className="text-sm">{date(trip.reservation.travel_from)} – {date(trip.reservation.travel_to)} · {trip.reservation.pax} pasajeros</p>
              <p className="mt-2 inline-block rounded-full bg-white/20 px-2 py-0.5 text-xs font-medium">{trip.state.label}</p>
            </div>
            {trip.critical_alert && <div className="rounded-xl bg-rose-600 p-3 text-sm text-white"><p className="font-semibold">{trip.critical_alert.subject}</p><p>{trip.critical_alert.body}</p></div>}
            {n && (
              <div className={card}>
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Próxima actividad</p>
                <p className="font-medium text-slate-900">{n.name}</p>
                <p className="text-sm text-slate-700">{date(n.date)} · {n.time ?? "Hora por confirmar"} · <Countdown at={n.starts_at} /></p>
                <ServiceInfo s={n} />
              </div>
            )}
            {trip.hotel && (
              <div className={card}>
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Hotel</p>
                <p className="font-medium text-slate-900">{trip.hotel.name}</p>
                <p className="text-sm text-slate-700">{trip.hotel.room ? `Habitación ${trip.hotel.room} (${trip.hotel.room_type})` : "Habitación por asignar"} · {date(trip.hotel.check_in)} – {date(trip.hotel.check_out)}</p>
                {trip.hotel.phone && <a href={`tel:${trip.hotel.phone}`} className="text-xs text-sky-800">{trip.hotel.phone}</a>}
              </div>
            )}
            <div className="grid grid-cols-2 gap-2 text-sm">
              <Link href={href({ tab: "itinerario" })} className={`${card} text-center font-medium text-sky-800`}>Itinerario</Link>
              <Link href={href({ tab: "vouchers" })} className={`${card} text-center font-medium text-sky-800`}>Vouchers</Link>
              <Link href={href({ tab: "soporte" })} className={`${card} text-center font-medium text-sky-800`}>Chat{trip.unread_messages > 0 && ` (${trip.unread_messages})`}</Link>
              <a href={`tel:${trip.emergency.replace(/\s/g, "")}`} className="rounded-xl bg-rose-600 p-3 text-center font-semibold text-white shadow-sm">Emergencia</a>
            </div>
          </>
        );
      }
    }
  })();

  const phone = (
    <div className="mx-auto flex h-[44rem] w-full max-w-sm flex-col overflow-hidden rounded-[2rem] border-8 border-slate-800 bg-slate-100 shadow-xl" aria-label="App del pasajero">
      <header className="bg-sky-800 px-4 py-2 text-sm font-semibold text-white">Mi viaje · {trip.reservation.code}</header>
      <main className="flex-1 space-y-3 overflow-y-auto p-3">{body}</main>
      <nav className="grid grid-cols-6 border-t border-slate-200 bg-white text-[11px]" aria-label="Navegación de la app">
        {TABS.map((t) => (
          <Link key={t.key} href={`/mobile/pasajero${qs({ reserva: sp.reserva, tab: t.key })}`} aria-current={t.key === tab ? "page" : undefined}
            className={`relative py-2.5 text-center ${t.key === tab ? "font-semibold text-sky-800" : "text-slate-500"}`}>
            {t.label}{t.key === "alertas" && trip.unread_alerts > 0 && <span className="absolute right-1 top-1 rounded-full bg-rose-600 px-1 text-[9px] text-white">{trip.unread_alerts}</span>}
          </Link>
        ))}
      </nav>
    </div>
  );
  if (!meta.preview) return <div className="py-2">{phone}</div>;

  return (
    <>
      <PageHeader title="App del pasajero" subtitle="Vista previa interna de la app del viajero" />
      <div className="grid gap-6 lg:grid-cols-[24rem_1fr]">
        {phone}
        <aside className="space-y-4 text-sm text-slate-700">
          <form className="flex items-end gap-2 rounded-lg border border-slate-200 bg-white p-4">
            <input type="hidden" name="tab" value={tab} />
            <label className="flex-1"><span className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Reserva</span>
              <select name="reserva" defaultValue={trip.reservation.code} className={inputCls} aria-label="Reserva">
                {meta.reservations.map((r) => <option key={r.code} value={r.code}>{r.code} · {r.title}</option>)}
              </select></label>
            <button className={btnGhostCls}>Ver</button>
          </form>
          <section className="rounded-lg border border-slate-200 bg-white p-4">
            <h2 className="mb-2 font-semibold text-slate-900">PROC-057 · Acompañamiento del viajero</h2>
            <ol className="list-inside list-decimal space-y-1">
              <li>Con la reserva confirmada, el pasajero ve su viaje, itinerario día por día y hotel con habitación (de la rooming list).</li>
              <li>Cada voucher emitido aparece con su QR; si se versiona o anula, recibe una alerta.</li>
              <li>Los cambios de horario por incidencias y los recordatorios (48 h antes) llegan como alertas.</li>
              <li>El chat llega al asesor y al coordinador de la orden; el número de emergencia 24/7 está siempre visible.</li>
            </ol>
          </section>
        </aside>
      </div>
    </>
  );
}

function ServiceInfo({ s }: { s: TripService }) {
  return (
    <dl className="mt-2 space-y-1 text-xs text-slate-700">
      <div><dt className="inline text-slate-500">Encuentro: </dt><dd className="inline">{s.meeting_point}</dd></div>
      {s.provider && <div><dt className="inline text-slate-500">Proveedor: </dt><dd className="inline">{s.provider}{s.confirmation_code && ` · ${s.confirmation_code}`}</dd></div>}
      {s.guide && <div><dt className="inline text-slate-500">Guía: </dt><dd className="inline">{s.guide.name}</dd></div>}
      {s.driver && <div><dt className="inline text-slate-500">Conductor: </dt><dd className="inline">{s.driver.name}{s.driver.vehicle && ` · ${s.driver.vehicle}`}</dd></div>}
      {s.includes.length > 0 && <div><dt className="inline text-slate-500">Incluye: </dt><dd className="inline">{s.includes.join(", ")}</dd></div>}
    </dl>
  );
}

function Countdown({ at }: { at: string }) {
  const diff = new Date(`${at}:00-05:00`).getTime() - Date.now();
  if (diff <= 0) return <span>en curso</span>;
  const h = Math.floor(diff / 3_600_000);
  return <span className="font-medium text-sky-800">{h >= 24 ? `en ${Math.floor(h / 24)} d ${h % 24} h` : `en ${h} h ${Math.floor((diff % 3_600_000) / 60_000)} min`}</span>;
}

function DocView({ d }: { d: Doc & { content: unknown } }) {
  const c = d.content as Record<string, unknown> | { date: string; n: number; services: TripService[] }[] | null;
  return (
    <article className={`${card} text-sm`} aria-label={d.label}>
      <h3 className="font-semibold text-slate-900">{d.label}</h3>
      {Array.isArray(c) ? (
        <ul className="mt-2 space-y-1 text-xs">{c.map((day) => <li key={day.date}><strong>Día {day.n} · {date(day.date)}:</strong> {day.services.map((s) => `${s.time ?? ""} ${s.name}`).join(" · ") || "Libre"}</li>)}</ul>
      ) : c && "text" in c ? <p className="mt-1 text-slate-700">{String(c.text)}</p>
        : c ? <dl className="mt-1 text-xs">{Object.entries(c).map(([k, v]) => <div key={k}><dt className="inline text-slate-500">{DOC_FIELDS[k] ?? k}: </dt><dd className="inline">{/^\d{4}-\d{2}-\d{2}$/.test(String(v)) ? date(String(v)) : String(v)}</dd></div>)}</dl>
        : <p className="mt-1 text-xs text-slate-600">Documento registrado en la reserva {d.detail && `(${d.detail})`}. Por seguridad, el archivo completo solo lo ve la agencia.</p>}
    </article>
  );
}
