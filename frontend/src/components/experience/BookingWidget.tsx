"use client";

import { useEffect, useMemo, useRef, useState, useTransition } from "react";
import { widgetHandoff, widgetMonth, widgetQuote } from "@/app/widget/actions";
import type { DayStatus, Quote } from "@/lib/booking";
import type { WidgetHandoff, WidgetProduct, WidgetPublicConfig } from "@/lib/experience";

type Lang = "es" | "en";
const T = {
  es: {
    date: "1. Fecha", pax: "2. Pasajeros", lodging: "3. Alojamiento (opcional)", addons: "4. Adicionales", summary: "5. Resumen", adults: "Adultos", children: "Niños", infants: "Infantes",
    years: "años", noHotel: "Sin hotel en", rooms: "habitaciones", nights: "noches", remove: "Quitar", restore: "Restaurar", total: "Total", deposit: "Adelanto", balance: "Saldo",
    pay: "Modalidad de pago", currency: "Moneda", continue: "Continuar reserva", choose: "Elija una fecha disponible", busy: "Ocupado", noOp: "No opera", noSpace: "Sin cupo",
    seats: "cupos", promo: "Código promocional", ref: "Su reserva está lista para pagar", checkout: "Ir al checkout", prev: "Mes anterior", next: "Mes siguiente",
    perPax: "por pasajero", perBooking: "por reserva", free: "no pagan", pays: "pagan", loading: "Calculando…", reviews: "Lo que dicen nuestros viajeros", expires: "La selección se guarda 24 h.",
    modes: { total: "Pago total", adelanto: "Adelanto", pagar_despues: "Reservar y pagar después" } as Record<string, string>, catalog: "Elija su experiencia",
  },
  en: {
    date: "1. Date", pax: "2. Travellers", lodging: "3. Accommodation (optional)", addons: "4. Extras", summary: "5. Summary", adults: "Adults", children: "Children", infants: "Infants",
    years: "years", noHotel: "No hotel in", rooms: "rooms", nights: "nights", remove: "Remove", restore: "Restore", total: "Total", deposit: "Deposit", balance: "Balance",
    pay: "Payment", currency: "Currency", continue: "Continue booking", choose: "Choose an available date", busy: "Busy", noOp: "Not operating", noSpace: "Full",
    seats: "seats", promo: "Promo code", ref: "Your booking is ready to pay", checkout: "Go to checkout", prev: "Previous month", next: "Next month",
    perPax: "per traveller", perBooking: "per booking", free: "free", pays: "pay", loading: "Calculating…", reviews: "What our travellers say", expires: "Your selection is kept for 24 h.",
    modes: { total: "Full payment", adelanto: "Deposit", pagar_despues: "Book now, pay later" } as Record<string, string>, catalog: "Choose your experience",
  },
};
const WEEK = { es: ["Dom", "Lun", "Mar", "Mié", "Jue", "Vie", "Sáb"], en: ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"] };

function post(type: string, payload: Record<string, unknown> = {}) {
  if (typeof window !== "undefined" && window.parent !== window) window.parent.postMessage({ type, ...payload }, "*");
}
const fmt = (v: string | number, c: string, lang: Lang) => `${c} ${Number(v).toLocaleString(lang === "en" ? "en-US" : "es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/**
 * Widget de reservas anónimo (spec 17 §2, `/widget/[code]`): calendario mensual del motor, pasajeros con edades y límites, alojamiento
 * por ciudad, adicionales, resumen con quitar/restaurar, modalidad de pago, moneda e idioma y «Continuar reserva» (traspaso WHO-).
 * Toda cifra viene del servidor (POST /public/quote); el navegador solo arma la selección. Emite eventos `qhapaq:*` a la página anfitriona.
 */
export function BookingWidget({ product: p, config, initial }: { product: WidgetProduct; config: WidgetPublicConfig; initial: { lang: Lang; currency: string; color: string; radius: number } }) {
  const [lang, setLang] = useState<Lang>(initial.lang);
  const t = T[lang];
  const currencies = Object.keys(config.currencies);
  const [currency, setCurrency] = useState(currencies.includes(initial.currency) ? initial.currency : currencies[0] ?? "USD");
  const [month, setMonth] = useState((p.next_available ?? new Date().toISOString()).slice(0, 7));
  const [days, setDays] = useState<DayStatus[]>([]);
  const [legend, setLegend] = useState("");
  const [date, setDate] = useState<string | null>(null);
  const [adt, setAdt] = useState(2);
  const [chd, setChd] = useState(0);
  const [inf, setInf] = useState(0);
  const [hotels, setHotels] = useState<Record<string, string>>({});
  const [rooms, setRooms] = useState<Record<string, Record<string, number>>>({});
  const [addons, setAddons] = useState<string[]>([]);
  const [removed, setRemoved] = useState<string[]>([]);
  const [mode, setMode] = useState(p.modes[0] ?? "total");
  const [promo, setPromo] = useState("");
  const [quote, setQuote] = useState<(Quote & { fx_rate?: string }) | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [handoff, setHandoff] = useState<WidgetHandoff | null>(null);
  const [pending, start] = useTransition();
  const [idem, setIdem] = useState(() => crypto.randomUUID());
  const root = useRef<HTMLDivElement>(null);
  const seats = adt + chd;

  const lodging = useMemo(() => (p.lodging?.legs ?? []).flatMap((leg) => {
    const hotel = hotels[leg.id];
    if (!hotel) return [];
    const r = Object.entries(rooms[leg.id] ?? {}).filter(([, q]) => q > 0).map(([type, qty]) => ({ type, qty }));
    return [{ leg_id: leg.id, hotel_id: hotel, rooms: r }];
  }), [p.lodging, hotels, rooms]);
  const selection = useMemo(() => ({
    product_id: p.ref, product: p.ref, date, adt, chd, inf, currency, payment_mode: mode, promo_code: promo || undefined, lang, removed,
    addons: addons.map((id) => ({ id, qty: 1 })), lodging, channel: "E-commerce",
  }), [p.ref, date, adt, chd, inf, currency, mode, promo, lang, removed, addons, lodging]);

  useEffect(() => { post("qhapaq:ready", { height: root.current?.scrollHeight ?? 0 }); }, []);
  useEffect(() => {
    const el = root.current;
    if (!el || typeof ResizeObserver === "undefined") return;
    const ro = new ResizeObserver(() => post("qhapaq:resize", { height: el.scrollHeight }));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  useEffect(() => {
    let live = true;
    widgetMonth(p.ref, month, Math.max(1, seats)).then((r) => {
      if (!live) return;
      if (r.ok) { setDays(r.data.days); setLegend(r.data.legend); } else setError(r.error);
    });
    return () => { live = false; };
  }, [p.ref, month, seats]);
  useEffect(() => {
    if (!date || adt + chd < 1) { setQuote(null); return; }
    let live = true;
    const timer = setTimeout(() => {
      widgetQuote(selection).then((r) => {
        if (!live) return;
        if (r.ok) { setQuote(r.data); setError(null); post("qhapaq:quote", { total: r.data.total, currency: r.data.currency }); } else setError(r.error);
      });
    }, 250);
    return () => { live = false; clearTimeout(timer); };
  }, [selection, date, adt, chd]);

  const shift = (n: number) => {
    const [y, m] = month.split("-").map(Number);
    const d = new Date(Date.UTC(y, m - 1 + n, 1));
    setMonth(d.toISOString().slice(0, 7));
  };
  const pick = (d: DayStatus) => {
    setDate(d.date);
    setHandoff(null);
    post("qhapaq:date_selected", { date: d.date });
  };
  const toggle = (list: string[], v: string) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);
  const first = days[0] ? new Date(`${days[0].date}T12:00:00Z`).getUTCDay() : 0;
  const monthName = new Date(`${month}-15T12:00:00Z`).toLocaleDateString(lang === "en" ? "en-US" : "es-PE", { month: "long", year: "numeric", timeZone: "UTC" });
  const dayText = (d: DayStatus) => d.status === "disponible" ? `${d.left ?? ""} ${t.seats}` : (d.status === "cerrado" && d.left !== null) || d.status === "agotado" ? t.busy : d.status === "sin_cupo" ? t.noSpace : d.status === "no_opera" ? t.noOp : "";
  const blocking = quote?.warnings.some((w) => w.blocking) ?? true;

  const cont = () => start(async () => {
    const r = await widgetHandoff(selection, idem);
    if (r.ok) {
      setHandoff(r.data);
      setIdem(crypto.randomUUID());
      post("qhapaq:checkout", { code: r.data.code, total: r.data.total, currency: r.data.currency, checkout_url: r.data.checkout_url });
    } else setError(r.error);
  });

  const style = { "--qc": initial.color, "--qr": `${initial.radius}px` } as React.CSSProperties;
  const box = "rounded-[var(--qr)] border border-slate-200 bg-white p-4";
  const step = (n: number, max: number | null, set: (v: number) => void, v: number, min = 0, label = "") => (
    <span className="inline-flex items-center gap-1">
      <button type="button" aria-label={`− ${label}`} onClick={() => set(Math.max(min, v - 1))} disabled={v <= min} className="h-8 w-8 rounded-[var(--qr)] border border-slate-300 disabled:opacity-40">−</button>
      <output aria-label={label} className="w-6 text-center font-medium">{n}</output>
      <button type="button" aria-label={`+ ${label}`} onClick={() => set(v + 1)} disabled={max !== null && v >= max} className="h-8 w-8 rounded-[var(--qr)] border border-slate-300 disabled:opacity-40">+</button>
    </span>
  );

  return (
    <div ref={root} style={style} className="mx-auto max-w-3xl space-y-4 p-3 text-sm text-slate-800 sm:p-5" data-widget={p.ref}>
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold">{p.name}</h1>
          <p className="text-xs text-slate-500">{p.duration}{p.category ? ` · ${p.category}` : ""} · {config.agency}</p>
        </div>
        <div className="flex items-center gap-2">
          <label className="sr-only" htmlFor="qw-lang">Idioma</label>
          <select id="qw-lang" value={lang} onChange={(e) => setLang(e.target.value as Lang)} className="rounded-[var(--qr)] border border-slate-300 px-2 py-1"><option value="es">ES</option><option value="en">EN</option></select>
          <label className="sr-only" htmlFor="qw-cur">{t.currency}</label>
          <select id="qw-cur" value={currency} onChange={(e) => setCurrency(e.target.value)} className="rounded-[var(--qr)] border border-slate-300 px-2 py-1" aria-label={t.currency}>
            {currencies.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
      </header>

      <section className={box} aria-labelledby="qw-date">
        <div className="mb-2 flex items-center justify-between">
          <h2 id="qw-date" className="font-semibold">{t.date}</h2>
          <span className="flex items-center gap-1">
            <button type="button" onClick={() => shift(-1)} aria-label={t.prev} className="rounded border border-slate-300 px-2">‹</button>
            <span className="min-w-32 text-center capitalize">{monthName}</span>
            <button type="button" onClick={() => shift(1)} aria-label={t.next} className="rounded border border-slate-300 px-2">›</button>
          </span>
        </div>
        <div className="grid grid-cols-7 gap-1 text-center text-xs">
          {WEEK[lang].map((w) => <span key={w} className="font-medium text-slate-500">{w}</span>)}
          {Array.from({ length: first }).map((_, i) => <span key={`e${i}`} />)}
          {days.map((d) => {
            const ok = d.status === "disponible";
            const sel = d.date === date;
            return (
              <button key={d.date} type="button" disabled={!ok} onClick={() => pick(d)} data-day={d.date} data-status={d.status} title={d.note ?? d.label} aria-label={`${d.date} · ${ok ? d.label : dayText(d) || d.label}`} aria-pressed={sel}
                className={`min-h-12 rounded-[var(--qr)] border px-0.5 py-1 ${sel ? "border-[var(--qc)] bg-[var(--qc)] text-white" : ok ? "border-emerald-200 bg-emerald-50 hover:border-[var(--qc)]" : "cursor-not-allowed border-slate-100 bg-slate-50 text-slate-400"}`}>
                <span className="block font-medium">{Number(d.date.slice(8))}</span>
                <span className="block truncate text-[10px]">{dayText(d)}</span>
              </button>
            );
          })}
        </div>
        {legend && <p className="mt-2 text-xs text-slate-500" data-legend>{legend}</p>}
      </section>

      <section className={box} aria-labelledby="qw-pax">
        <h2 id="qw-pax" className="mb-2 font-semibold">{t.pax}</h2>
        <div className="grid gap-2 sm:grid-cols-3">
          <div><p>{t.adults} <span className="text-xs text-slate-500">({p.pax.adult_min_age}+ {t.years})</span></p>{step(adt, p.max_pax, setAdt, adt, p.pax.min_adults, t.adults)}</div>
          <div><p>{t.children} <span className="text-xs text-slate-500">({p.pax.child_min_age}–{p.pax.child_max_age} {t.years}, {p.pax.child_pays ? t.pays : t.free})</span></p>
            {step(chd, p.pax.max_children_per_adult !== null ? p.pax.max_children_per_adult * adt : p.max_pax, setChd, chd, 0, t.children)}</div>
          {p.pax.infant_allowed && <div><p>{t.infants} <span className="text-xs text-slate-500">(0–{p.pax.infant_max_age} {t.years}, {t.free})</span></p>
            {step(inf, p.pax.max_infants_per_adult !== null ? p.pax.max_infants_per_adult * adt : null, setInf, inf, 0, t.infants)}</div>}
        </div>
      </section>

      {p.lodging && (
        <section className={box} aria-labelledby="qw-lodging">
          <h2 id="qw-lodging" className="mb-1 font-semibold">{t.lodging}</h2>
          {p.lodging.note && <p className="mb-2 text-xs text-slate-500">{p.lodging.note}</p>}
          <div className="space-y-3">
            {p.lodging.legs.map((leg) => {
              const hotel = leg.hotels.find((h) => h.id === hotels[leg.id]);
              return (
                <fieldset key={leg.id} className="rounded-[var(--qr)] border border-slate-100 p-2" data-leg={leg.city}>
                  <legend className="px-1 font-medium">{leg.city} · {leg.nights} {t.nights}</legend>
                  <select aria-label={`Hotel ${leg.city}`} value={hotels[leg.id] ?? ""} onChange={(e) => {
                    const id = e.target.value;
                    setHotels((h) => { const n = { ...h }; if (id) n[leg.id] = id; else delete n[leg.id]; return n; });
                    const h = leg.hotels.find((x) => x.id === id);
                    const twin = h?.room_types.find((r) => r.capacity === 2) ?? h?.room_types[0];
                    setRooms((r) => ({ ...r, [leg.id]: twin ? { [twin.room_type]: Math.max(1, Math.ceil(Math.max(1, seats) / twin.capacity)) } : {} }));
                  }} className="w-full rounded-[var(--qr)] border border-slate-300 px-2 py-1">
                    <option value="">{t.noHotel} {leg.city}</option>
                    {leg.hotels.map((h) => <option key={h.id} value={h.id}>{h.name} · {h.category}</option>)}
                  </select>
                  {hotel && (
                    <div className="mt-2 flex flex-wrap gap-3">
                      {hotel.room_types.map((rt) => (
                        <label key={rt.room_type} className="flex items-center gap-1 text-xs">{rt.room_type} ({rt.capacity}) · {fmt(rt.price_per_night, p.lodging!.currency ?? "USD", lang)}
                          <input type="number" min={0} max={20} value={rooms[leg.id]?.[rt.room_type] ?? 0} aria-label={`${rt.room_type} ${leg.city}`}
                            onChange={(e) => setRooms((r) => ({ ...r, [leg.id]: { ...(r[leg.id] ?? {}), [rt.room_type]: Math.max(0, Number(e.target.value)) } }))}
                            className="w-14 rounded border border-slate-300 px-1 py-0.5" />
                        </label>
                      ))}
                    </div>
                  )}
                </fieldset>
              );
            })}
          </div>
        </section>
      )}

      {p.addons.length > 0 && (
        <section className={box} aria-labelledby="qw-addons">
          <h2 id="qw-addons" className="mb-2 font-semibold">{t.addons}</h2>
          <div className="grid gap-1 sm:grid-cols-2">
            {p.addons.map((a) => (
              <label key={a.id} className="flex items-center gap-2"><input type="checkbox" checked={addons.includes(a.id)} onChange={() => setAddons((x) => toggle(x, a.id))} />
                {a.name} <span className="text-xs text-slate-500">{fmt(a.price, a.currency, lang)} {a.charge_per === "pax" ? t.perPax : t.perBooking}</span></label>
            ))}
          </div>
        </section>
      )}

      <section className={box} aria-labelledby="qw-summary" aria-busy={pending}>
        <h2 id="qw-summary" className="mb-2 font-semibold">{t.summary}</h2>
        {!date && <p className="text-slate-500">{t.choose}</p>}
        {error && <p role="alert" className="mb-2 rounded bg-rose-50 px-2 py-1 text-rose-800">{error}</p>}
        {date && quote && (
          <>
            {quote.warnings.map((w) => <p key={w.code + w.message} className={`mb-1 rounded px-2 py-1 text-xs ${w.blocking ? "bg-rose-50 text-rose-800" : "bg-amber-50 text-amber-900"}`}>{w.message}</p>)}
            <ul className="divide-y divide-slate-100">
              {quote.lines.filter((l) => l.kind !== "info").map((l) => (
                <li key={l.key} className={`flex items-center justify-between gap-2 py-1 ${l.removed ? "text-slate-400 line-through" : ""}`} data-line={l.key}>
                  <span className="min-w-0">{l.label}{l.qty > 1 ? ` ×${l.qty}` : ""}</span>
                  <span className="flex items-center gap-2 whitespace-nowrap">
                    {fmt(l.amount, quote.currency, lang)}
                    {l.removable && p.rules.preview_remove && (
                      <button type="button" onClick={() => setRemoved((x) => toggle(x, l.key))} className="rounded border border-slate-300 px-1.5 text-xs no-underline">{l.removed ? t.restore : t.remove}</button>
                    )}
                  </span>
                </li>
              ))}
              {quote.adjustments.map((a) => <li key={a.key} className="flex justify-between py-1"><span>{a.label}</span><span>{fmt(a.amount, quote.currency, lang)}</span></li>)}
            </ul>
            {quote.rooms > 0 && <p className="mt-1 text-xs text-slate-600" data-rooms>{quote.rooms} {t.rooms} · {quote.nights} {t.nights}</p>}
            {p.rules.promo && <input value={promo} onChange={(e) => setPromo(e.target.value.toUpperCase())} placeholder={t.promo} aria-label={t.promo} className="mt-2 w-40 rounded-[var(--qr)] border border-slate-300 px-2 py-1" />}
            {quote.modes.length > 1 && (
              <fieldset className="mt-3"><legend className="font-medium">{t.pay}</legend>
                <div className="flex flex-wrap gap-3">{quote.modes.map((m) => <label key={m} className="flex items-center gap-1"><input type="radio" name="qw-mode" checked={mode === m} onChange={() => setMode(m)} />{t.modes[m] ?? m}</label>)}</div>
              </fieldset>
            )}
            <dl className="mt-3 grid grid-cols-3 gap-2 rounded-[var(--qr)] bg-slate-50 p-2">
              <div><dt className="text-xs text-slate-500">{t.total}</dt><dd className="font-semibold" data-total>{fmt(quote.total, quote.currency, lang)}</dd></div>
              <div><dt className="text-xs text-slate-500">{t.deposit}{quote.payment_mode === "adelanto" ? ` (${Number(quote.deposit_pct)} %)` : ""}</dt><dd data-deposit>{fmt(quote.deposit, quote.currency, lang)}</dd></div>
              <div><dt className="text-xs text-slate-500">{t.balance}</dt><dd>{fmt(quote.balance, quote.currency, lang)}</dd></div>
            </dl>
            {handoff ? (
              <div className="mt-3 rounded-[var(--qr)] border border-emerald-200 bg-emerald-50 p-3" data-handoff={handoff.code}>
                <p className="font-medium text-emerald-900">{t.ref}: <span className="font-mono">{handoff.code}</span></p>
                <p className="text-xs text-emerald-900">{t.expires}</p>
                <a href={handoff.checkout_url} target="_top" className="mt-2 inline-block rounded-[var(--qr)] bg-[var(--qc)] px-4 py-2 font-medium text-white">{t.checkout}</a>
              </div>
            ) : (
              <button type="button" onClick={cont} disabled={pending || blocking} className="mt-3 w-full rounded-[var(--qr)] bg-[var(--qc)] px-4 py-2 font-medium text-white disabled:opacity-50">
                {pending ? t.loading : t.continue}
              </button>
            )}
          </>
        )}
      </section>
    </div>
  );
}

/** Lista de productos vendibles (widget sin producto válido o catálogo por categoría). */
export function WidgetCatalog({ config, lang, query, category }: { config: WidgetPublicConfig; lang: Lang; query: string; category?: string | null }) {
  const items = category ? config.products.filter((p) => p.category === category) : config.products;
  return (
    <div className="mx-auto max-w-3xl p-4 text-sm">
      <h1 className="mb-3 text-xl font-semibold">{T[lang].catalog}</h1>
      <ul className="grid gap-2 sm:grid-cols-2">
        {items.map((p) => (
          <li key={p.ref}>
            <a href={`/widget/${p.ref}${query ? `?${query}` : ""}`} className="block rounded-lg border border-slate-200 p-3 hover:border-slate-400" data-product={p.ref}>
              <span className="font-medium">{p.name}</span>
              <span className="block text-xs text-slate-500">{p.duration}{p.category ? ` · ${p.category}` : ""}</span>
            </a>
          </li>
        ))}
      </ul>
    </div>
  );
}
