import { api, ApiError } from "@/lib/api";
import { Card, Notice, btnCls, inputCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { DAY_TONE, MODE_LABELS, WEEKDAYS, type BookingConfig } from "@/lib/booking";
import { money } from "@/lib/finance";
import type { CurrencyList, RuleCenterMeta, Simulation } from "@/lib/experience";

export type SimSearch = Record<string, string | string[] | undefined>;

const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";
const many = (v: string | string[] | undefined) => (Array.isArray(v) ? v : v ? [v] : []);
const RULE_TONE: Record<string, "green" | "slate" | "amber" | "rose" | "sky"> = { on: "green", off_tenant: "slate", off_plan: "amber", off_product: "sky", off_dependency: "rose" };
const RULE_STATE: Record<string, string> = { on: "Aplica", off_tenant: "Inactiva", off_plan: "Fuera del plan", off_product: "Apagada en el producto", off_dependency: "Sin efecto" };

/**
 * Simulador del centro de reglas (spec 17 §2): formulario GET (la URL es la simulación, se puede compartir) que llama a
 * `POST /admin/booking-rules/simulate` con el plan a simular; muestra el día, el mes, las líneas de `quote` con «quitar» y el estado
 * de cada regla para el producto.
 */
export async function Simulator({ sp, meta }: { sp: SimSearch; meta: RuleCenterMeta }) {
  const { data: products } = await api<{ data: { ref: string; name: string; is_bookable: boolean; status: string }[] }>("/catalog/products?per_page=200");
  const bookable = products.filter((p) => p.is_bookable && p.status === "ACTIVE");
  const ref = one(sp.producto) || bookable.find((p) => p.ref === "PRD-013")?.ref || bookable[0]?.ref;
  if (!ref) return <p className="text-sm text-slate-500">No hay productos vendibles online.</p>;
  const [{ data: cfg }, { data: fx }] = await Promise.all([
    api<{ data: BookingConfig }>(`/catalog/products/${ref}/booking-config`),
    api<{ data: CurrencyList }>("/finance/currencies").catch(() => ({ data: null })),
  ]);
  const adt = Number(one(sp.adt) || 2);
  const chd = Number(one(sp.chd) || 0);
  const inf = Number(one(sp.inf) || 0);
  const seats = Math.max(1, adt + chd);
  const addons = many(sp.adicional);
  const lodging = cfg.accommodation.legs.flatMap((leg) => {
    const hotel = leg.hotels.find((h) => h.id === one(sp[`h_${leg.id}`]));
    if (!hotel) return [];
    const type = hotel.room_types.find((t) => t.room_type === one(sp[`t_${leg.id}`])) ?? hotel.room_types.find((t) => t.capacity === 2) ?? hotel.room_types[0];
    const qty = Number(one(sp[`q_${leg.id}`])) || Math.ceil(seats / Math.max(1, type?.capacity ?? 1));
    return type ? [{ leg_id: leg.id, hotel_id: hotel.id, rooms: [{ type: type.room_type, qty }] }] : [];
  });
  const body = {
    product: ref, plan: one(sp.plan_sim) || undefined,
    selection: {
      date: one(sp.fecha) || undefined, adt, chd, inf, currency: one(sp.moneda) || undefined, payment_mode: one(sp.modo) || undefined, payment_method: one(sp.medio) || undefined,
      promo_code: one(sp.promo) || undefined, addons: addons.map((id) => ({ id, qty: 1 })), lodging, removed: many(sp.quitar), channel: "E-commerce",
    },
  };
  let sim: Simulation | null = null;
  let error: string | null = null;
  try {
    sim = (await api<{ data: Simulation }>("/admin/booking-rules/simulate", { method: "POST", body: JSON.stringify(body) })).data;
  } catch (e) {
    error = e instanceof ApiError ? e.body?.message ?? "No se pudo simular" : "No se pudo simular";
  }
  const currencies = fx?.items.filter((c) => c.active).map((c) => c.code) ?? ["USD", "PEN"];
  const q = sim?.quote;
  const first = sim ? new Date(`${sim.month[0]?.date ?? sim.date}T12:00:00Z`).getUTCDay() : 0;

  return (
    <div className="grid grid-cols-1 gap-4 [&>*]:min-w-0 xl:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
      <Card title="Selección">
        <form method="get" className="space-y-3 text-sm" data-simulator>
          <input type="hidden" name="tab" value="simulador" />
          <Field label="Producto">
            <select name="producto" defaultValue={ref} className={inputCls}>
              {bookable.map((p) => <option key={p.ref} value={p.ref}>{p.ref} · {p.name}</option>)}
            </select>
          </Field>
          <Field label="Plan a simular">
            <select name="plan_sim" defaultValue={one(sp.plan_sim)} className={inputCls}>
              <option value="">Plan actual ({meta.plan_name})</option>
              {meta.plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </Field>
          <Field label="Fecha (vacío = próxima disponible)"><input type="date" name="fecha" defaultValue={one(sp.fecha)} className={inputCls} /></Field>
          <div className="grid grid-cols-3 gap-2">
            <Field label="Adultos"><input type="number" name="adt" min={0} max={50} defaultValue={adt} className={inputCls} /></Field>
            <Field label="Niños"><input type="number" name="chd" min={0} max={50} defaultValue={chd} className={inputCls} /></Field>
            <Field label="Infantes"><input type="number" name="inf" min={0} max={20} defaultValue={inf} className={inputCls} /></Field>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Field label="Moneda">
              <select name="moneda" defaultValue={one(sp.moneda)} className={inputCls}>
                <option value="">Del tarifario</option>
                {currencies.map((c) => <option key={c} value={c}>{c}</option>)}
              </select>
            </Field>
            <Field label="Modalidad">
              <select name="modo" defaultValue={one(sp.modo)} className={inputCls}>
                <option value="">Por defecto</option>
                {Object.entries(MODE_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </Field>
            <Field label="Medio de pago">
              <select name="medio" defaultValue={one(sp.medio)} className={inputCls}>
                <option value="">—</option><option value="tarjeta">Tarjeta</option><option value="transferencia">Transferencia</option>
              </select>
            </Field>
            <Field label="Código promocional"><input name="promo" defaultValue={one(sp.promo)} maxLength={40} className={inputCls} /></Field>
          </div>
          {cfg.addons.length > 0 && (
            <fieldset><legend className="mb-1 font-medium text-slate-700">Adicionales</legend>
              {cfg.addons.map((a) => (
                <label key={a.id} className="flex items-center gap-2"><input type="checkbox" name="adicional" value={a.id} defaultChecked={addons.includes(a.id)} />{a.name} <span className="text-xs text-slate-500">{money(a.price, a.currency)} / {a.charge_per}</span></label>
              ))}
            </fieldset>
          )}
          {cfg.accommodation.legs.length > 0 && (
            <fieldset className="space-y-2"><legend className="mb-1 font-medium text-slate-700">Alojamiento por ciudad</legend>
              {cfg.accommodation.legs.map((leg) => {
                const hotel = leg.hotels.find((h) => h.id === one(sp[`h_${leg.id}`]));
                return (
                  <div key={leg.id} className="grid grid-cols-[minmax(0,1fr)_auto_auto] items-end gap-1">
                    <Field label={`${leg.city} · ${leg.nights} noche(s)`}>
                      <select name={`h_${leg.id}`} defaultValue={hotel?.id ?? ""} className={inputCls}>
                        <option value="">Sin hotel</option>
                        {leg.hotels.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}
                      </select>
                    </Field>
                    <select name={`t_${leg.id}`} defaultValue={one(sp[`t_${leg.id}`])} aria-label={`Tipo de habitación en ${leg.city}`} className={`${inputCls} w-24`}>
                      <option value="">Auto</option>
                      {[...new Set(leg.hotels.flatMap((h) => h.room_types.map((t) => t.room_type)))].map((t) => <option key={t} value={t}>{t}</option>)}
                    </select>
                    <input type="number" name={`q_${leg.id}`} min={0} max={20} defaultValue={one(sp[`q_${leg.id}`])} placeholder="hab." aria-label={`Habitaciones en ${leg.city}`} className={`${inputCls} w-16`} />
                  </div>
                );
              })}
            </fieldset>
          )}
          {many(sp.quitar).map((k) => <input key={k} type="hidden" name="quitar" value={k} />)}
          <button className={btnCls}>Simular</button>
        </form>
      </Card>

      <div className="space-y-4">
        {error && <Notice tone="danger">{error}</Notice>}
        {sim && q && (
          <>
            <Card title={<>Día {sim.date} · <span className="font-normal">{sim.day.label}</span>{sim.plan !== meta.plan && <span className="ml-2"><Pill tone="amber">Simulando plan {meta.plans.find((p) => p.id === sim!.plan)?.name}</Pill></span>}</>}>
              <p className="mb-2 text-xs text-slate-600" data-legend>{sim.legend}</p>
              <div className="grid grid-cols-7 gap-1 text-center text-[11px]">
                {WEEKDAYS.map((w) => <span key={w} className="font-medium text-slate-500">{w}</span>)}
                {Array.from({ length: first }).map((_, i) => <span key={`e${i}`} />)}
                {sim.month.map((d) => (
                  <span key={d.date} title={`${d.label}${d.note ? ` · ${d.note}` : ""}`} className={`rounded px-1 py-1 ring-1 ${DAY_TONE[d.status]} ${d.date === sim!.date ? "outline outline-2 outline-sky-600" : ""}`}>
                    {Number(d.date.slice(8))}{d.left !== null && d.status === "disponible" ? <span className="block text-[10px]">{d.left}</span> : null}
                  </span>
                ))}
              </div>
            </Card>

            <Card title={`Cotización · ${sim.product.ref}`}>
              {!sim.pax.ok && <Notice tone="warning">{sim.pax.errors.join(" ")}</Notice>}
              {q.warnings.map((w) => <Notice key={w.code + w.message} tone={w.blocking ? "danger" : "warning"}>{w.message}</Notice>)}
              <form method="get">
                {Object.entries(sp).filter(([k]) => k !== "quitar").flatMap(([k, v]) => many(v).map((x, i) => <input key={`${k}${i}`} type="hidden" name={k} value={x} />))}
                <table className="w-full text-sm">
                  <thead className="text-left text-xs uppercase text-slate-500"><tr><th className="py-1">Quitar</th><th>Concepto</th><th className="text-right">Cant.</th><th className="text-right">Importe</th></tr></thead>
                  <tbody className="divide-y divide-slate-100">
                    {q.lines.map((l) => (
                      <tr key={l.key} className={l.removed ? "text-slate-400 line-through" : ""}>
                        <td className="py-1">{l.removable ? <input type="checkbox" name="quitar" value={l.key} defaultChecked={l.removed} aria-label={`Quitar ${l.label}`} /> : null}</td>
                        <td>{l.label}</td>
                        <td className="text-right">{l.qty || ""}</td>
                        <td className="text-right">{l.kind === "info" ? "" : money(l.amount, q.currency)}</td>
                      </tr>
                    ))}
                    {q.adjustments.map((a) => <tr key={a.key}><td /><td>{a.label}</td><td /><td className="text-right">{money(a.amount, q.currency)}</td></tr>)}
                  </tbody>
                </table>
                <button className="mt-2 rounded-md border border-slate-300 px-2 py-1 text-xs">Recalcular sin los ítems marcados</button>
              </form>
              <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
                <Kv k="Total" v={<strong data-sim-total>{money(q.total, q.currency)}</strong>} />
                <Kv k="Adelanto" v={`${money(q.deposit, q.currency)} (${Number(q.deposit_pct)} %)`} />
                <Kv k="Saldo" v={`${money(q.balance, q.currency)}${q.balance_due ? ` · vence ${q.balance_due}` : ""}`} />
                <Kv k="Habitaciones / noches" v={`${q.rooms} / ${q.nights}`} />
                <Kv k="Modalidades" v={q.modes.map((m) => MODE_LABELS[m] ?? m).join(", ")} />
                <Kv k="Bloqueo" v={q.hold_hours ? `${q.hold_hours} h` : "—"} />
                {q.fx_rate && q.fx_rate !== "1" && <Kv k="TC aplicado" v={q.fx_rate} />}
              </dl>
            </Card>

            <Card title="Estado de cada regla para este producto">
              <ul className="grid gap-1 text-xs sm:grid-cols-2">
                {sim.rules.map((r) => (
                  <li key={r.key} className="flex items-center justify-between gap-2" data-sim-rule={r.key}>
                    <span className="min-w-0 truncate"><span className="font-mono text-slate-500">{r.key}</span> {r.name}</span>
                    <Pill tone={RULE_TONE[r.state] ?? "slate"}>{r.state === "off_plan" ? `Fuera del plan ${meta.plans.find((p) => p.id === sim!.plan)?.name ?? ""}` : RULE_STATE[r.state] ?? r.state}</Pill>
                  </li>
                ))}
              </ul>
            </Card>
          </>
        )}
      </div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className="block"><span className="mb-1 block font-medium text-slate-700">{label}</span>{children}</label>;
}

function Kv({ k, v }: { k: string; v: React.ReactNode }) {
  return <div><dt className="text-xs text-slate-500">{k}</dt><dd>{v}</dd></div>;
}
