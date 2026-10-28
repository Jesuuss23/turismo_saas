"use client";

import { useEffect, useMemo, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { addToCart, calendar, quote } from "@/app/sitio/actions";
import { fmtDate, money, REASONS, t, type CalendarDay, type Currency, type Locale, type ProductDetail, type Quote } from "@/lib/site-shared";
import { trackEvent } from "./Analytics";

const KIND: Record<string, string> = { alta: "bg-rose-50 text-rose-900", baja: "bg-emerald-50 text-emerald-900", especial: "bg-violet-50 text-violet-900", media: "bg-white" };

function Stepper({ label, value, min, max, onChange, testid }: { label: string; value: number; min: number; max: number; onChange: (v: number) => void; testid: string }) {
  return (
    <div className="flex items-center justify-between gap-2 text-sm">
      <span>{label}</span>
      <span className="flex items-center gap-2">
        <button type="button" aria-label={`− ${label}`} disabled={value <= min} onClick={() => onChange(value - 1)} className="h-7 w-7 rounded-full border border-slate-300 disabled:opacity-40">−</button>
        <span className="w-5 text-center tabular-nums" data-testid={testid}>{value}</span>
        <button type="button" aria-label={`+ ${label}`} disabled={value >= max} onClick={() => onChange(value + 1)} className="h-7 w-7 rounded-full border border-slate-300 disabled:opacity-40">+</button>
      </span>
    </div>
  );
}

/**
 * Panel de reserva (spec 15 §2 ficha): calendario con precio adulto por día y temporada (solo días vendibles), viajeros, opciones,
 * extras y desglose calculado por el servidor (RT-5); «Añadir al carrito» crea el hold de cupos y «Reservar ahora» va al checkout.
 */
export function BookingPanel({ p, locale, currency, whatsapp, initial, preset }: {
  p: ProductDetail; locale: Locale; currency: Currency; whatsapp: string;
  initial: { month: string; days: CalendarDay[]; next_available: string | null }; preset: { date?: string; pax?: number };
}) {
  const d = t(locale);
  const en = locale === "en";
  const router = useRouter();
  const [month, setMonth] = useState(initial.month);
  const [days, setDays] = useState(initial.days);
  const [date, setDate] = useState<string | null>(preset.date && initial.days.some((x) => x.date === preset.date && x.sellable) ? preset.date : null);
  const [adults, setAdults] = useState(Math.max(p.min_pax, Math.min(preset.pax ?? 2, p.max_pax)));
  const [children, setChildren] = useState(0);
  const [infants, setInfants] = useState(0);
  const [options, setOptions] = useState<Record<string, string>>({});
  const [extras, setExtras] = useState({ insurance: false, airport_transfer: false });
  const [q, setQ] = useState<Quote | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [added, setAdded] = useState(false);
  const [pending, start] = useTransition();
  const pax = adults + children;
  const day = days.find((x) => x.date === date) ?? null;

  const load = (m: string, n = pax) => start(async () => {
    const r = await calendar(p.slug, m, n);
    if (r.ok) {
      setMonth(r.data.month);
      setDays(r.data.days);
    }
  });
  useEffect(() => {
    if (!date) return setQ(null);
    let alive = true;
    quote({ product: p.slug, date, adults, children, infants, options, extras, currency }).then((r) => {
      if (!alive) return;
      if (r.ok) {
        setQ(r.data);
        setError(null);
      } else setError(r.error);
    });
    return () => {
      alive = false;
    };
  }, [date, adults, children, infants, options, extras, currency, p.slug]);

  const grid = useMemo(() => {
    const first = new Date(`${month}-01T12:00:00`);
    const lead = (first.getDay() + 6) % 7;
    return [...Array(lead).fill(null), ...days];
  }, [month, days]);
  const shift = (n: number) => {
    const dt = new Date(`${month}-01T12:00:00`);
    dt.setMonth(dt.getMonth() + n);
    load(`${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, "0")}`);
  };

  function add(goCheckout: boolean) {
    if (!date || !day) return setError(d.chooseDate);
    start(async () => {
      const r = await addToCart({ product: p.slug, date, departure_id: day.departure?.id, adults, children, infants, options, extras });
      if (!r.ok) {
        setError(r.error);
        if (r.status === 409) load(month);
        return;
      }
      trackEvent("add_to_cart", { currency, value: q?.total, items: [{ item_id: p.ref, item_name: p.name, quantity: pax }] });
      setAdded(true);
      router.refresh();
      if (goCheckout) router.push("/sitio/checkout");
    });
  }

  const wa = `https://wa.me/${whatsapp.replace(/\D/g, "")}?text=${encodeURIComponent(`${en ? "Hello! I'm interested in" : "¡Hola! Me interesa"} ${p.name}${date ? ` (${date}, ${pax} pax)` : ""}`)}`;
  return (
    <aside className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:sticky lg:top-20" aria-label={en ? "Booking" : "Reserva"}>
      <p className="text-sm text-slate-500">{d.from}</p>
      <p className="text-2xl font-bold text-[#0b1f3a]">
        {p.list_price && <span className="mr-2 text-sm font-normal text-slate-400 line-through">{money(p.list_price, p.currency)}</span>}
        {money(p.from_price, p.currency)} <span className="text-xs font-normal text-slate-500">{d.perPerson}</span>
      </p>

      <div className="mt-4">
        <div className="flex items-center justify-between">
          <button type="button" onClick={() => shift(-1)} aria-label={en ? "Previous month" : "Mes anterior"} className="rounded px-2 py-1 hover:bg-slate-100">‹</button>
          <p className="text-sm font-semibold capitalize">{fmtDate(`${month}-01`, locale, { month: "long", year: "numeric" })}</p>
          <button type="button" onClick={() => shift(1)} aria-label={en ? "Next month" : "Mes siguiente"} className="rounded px-2 py-1 hover:bg-slate-100">›</button>
        </div>
        <div className="mt-2 grid grid-cols-7 gap-1 text-center text-[11px] text-slate-500">
          {(en ? ["M", "T", "W", "T", "F", "S", "S"] : ["L", "M", "M", "J", "V", "S", "D"]).map((x, i) => <span key={i}>{x}</span>)}
        </div>
        <div className="mt-1 grid grid-cols-7 gap-1" data-testid="calendar" aria-busy={pending}>
          {grid.map((x, i) => x === null ? <span key={i} /> : (
            <button key={x.date} type="button" disabled={!x.sellable} data-date={x.date} data-sellable={x.sellable ? "1" : "0"}
              title={x.sellable ? `${x.season_name ?? ""}` : REASONS[x.reason ?? ""]?.[locale] ?? ""}
              onClick={() => { setDate(x.date); setAdded(false); }}
              className={`min-w-0 rounded-md border px-0.5 py-1 text-[11px] leading-tight ${x.date === date ? "border-[#0b1f3a] ring-2 ring-[#0b1f3a]" : "border-slate-200"} ${x.sellable ? KIND[x.season ?? "media"] : "bg-slate-50 text-slate-300"}`}>
              <span className="block font-medium">{Number(x.date.slice(8))}</span>
              {x.sellable && x.price && <span className="block truncate">{Math.round(Number(x.price))}</span>}
            </button>
          ))}
        </div>
        <p className="mt-2 flex flex-wrap gap-2 text-[11px] text-slate-500">
          <span className="rounded bg-emerald-50 px-1">{en ? "Low" : "Baja"}</span><span className="rounded border px-1">{en ? "Mid" : "Media"}</span>
          <span className="rounded bg-rose-50 px-1">{en ? "High" : "Alta"}</span><span className="rounded bg-violet-50 px-1">{en ? "Special" : "Especial"}</span>
          {initial.next_available && !days.some((x) => x.sellable) && <span>{en ? "Next available" : "Próxima fecha"}: {fmtDate(initial.next_available, locale)}</span>}
        </p>
        {day && <p className="mt-1 text-xs text-slate-600" data-testid="selected-date">{fmtDate(day.date, locale, { weekday: "long", day: "numeric", month: "long" })}{day.departure ? ` · ${day.departure.time} · ${day.available} ${d.seats}${day.departure.guaranteed ? ` · ${d.guaranteed}` : ""}` : ` · ${REASONS.bajo_solicitud[locale]}`}</p>}
      </div>

      <div className="mt-4 space-y-2 border-t border-slate-100 pt-3">
        <Stepper label={d.adults} value={adults} min={1} max={p.max_pax - children} onChange={setAdults} testid="adults" />
        {p.children_allowed && <Stepper label={d.children} value={children} min={0} max={Math.max(0, p.max_pax - adults)} onChange={setChildren} testid="children" />}
        {p.infants_allowed && <Stepper label={d.infants} value={infants} min={0} max={adults} onChange={setInfants} testid="infants" />}
        {p.min_age > 0 && <p className="text-[11px] text-slate-500">{en ? `Minimum age ${p.min_age}` : `Edad mínima ${p.min_age} años`}</p>}
      </div>

      {p.config.length > 0 && (
        <div className="mt-3 space-y-2 border-t border-slate-100 pt-3">
          {p.config.map((g) => (
            <label key={g.id} className="block text-sm">{g.name}
              <select value={options[g.id] ?? ""} onChange={(e) => setOptions({ ...options, [g.id]: e.target.value })} className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5">
                {g.options.map((o) => <option key={o.id} value={o.id}>{o.label}{Number(o.delta_pp) ? ` (+${o.delta_pp} p.p.)` : ""}</option>)}
              </select>
            </label>
          ))}
        </div>
      )}

      {p.extras.length > 0 && (
        <fieldset className="mt-3 space-y-1 border-t border-slate-100 pt-3 text-sm">
          <legend className="sr-only">{d.extras}</legend>
          {p.extras.map((x) => (
            <label key={x.code} className="flex items-start gap-2">
              <input type="checkbox" className="mt-1" checked={extras[x.code as keyof typeof extras] ?? false} onChange={(e) => setExtras({ ...extras, [x.code]: e.target.checked })} />
              <span>{x.label} <span className="text-xs text-slate-500">({x.price})</span></span>
            </label>
          ))}
        </fieldset>
      )}

      {q && (
        <dl className="mt-3 space-y-1 border-t border-slate-100 pt-3 text-sm" data-testid="quote">
          <div className="flex justify-between"><dt>{adults} × {money(q.unit_adult, q.currency)}{children ? ` + ${children} × ${money(q.unit_child, q.currency)}` : ""}</dt><dd>{money(q.subtotal, q.currency)}</dd></div>
          {Number(q.discount) > 0 && <div className="flex justify-between text-emerald-700"><dt>{q.discount_label}</dt><dd>−{money(q.discount, q.currency)}</dd></div>}
          {q.extras.map((x) => <div key={x.code} className="flex justify-between text-slate-600"><dt>{x.label}</dt><dd>{money(x.amount, q.currency)}</dd></div>)}
          <div className="flex justify-between font-semibold"><dt>{d.total}</dt><dd data-testid="quote-total">{money(q.total, q.currency)}</dd></div>
          {q.season && <p className="text-[11px] text-slate-500">{q.season.name}</p>}
        </dl>
      )}
      {error && <p role="alert" className="mt-2 text-sm text-rose-700">{error}</p>}
      {added && <p role="status" className="mt-2 text-sm text-emerald-700">{en ? "Added! Your seats are held." : "¡Añadido! Sus cupos quedaron bloqueados."} <Link href="/sitio/carrito" className="underline">{d.cart}</Link></p>}
      <div className="mt-3 grid gap-2">
        <button type="button" disabled={pending || !date} onClick={() => add(true)} className="rounded-lg bg-amber-400 py-2.5 font-semibold text-slate-900 disabled:opacity-50">{d.bookNow}</button>
        <button type="button" disabled={pending || !date} onClick={() => add(false)} className="rounded-lg border border-[#0b1f3a] py-2 font-medium text-[#0b1f3a] disabled:opacity-50">{d.addToCart}</button>
        <a href={wa} target="_blank" rel="noopener noreferrer" onClick={() => trackEvent("contact_whatsapp", { item_id: p.ref })} className="text-center text-sm text-emerald-700 hover:underline">{d.whatsapp}</a>
        <Link href={`/sitio/viajes-a-medida?producto=${p.slug}${date ? `&fecha=${date}` : ""}&pax=${pax}`} className="text-center text-sm text-sky-700 hover:underline">{d.quoteCustom}</Link>
      </div>
    </aside>
  );
}
