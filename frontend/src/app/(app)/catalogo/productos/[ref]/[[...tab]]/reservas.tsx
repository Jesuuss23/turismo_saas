import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import type { Product } from "@/lib/catalog";
import { MODE_LABELS, SECTION_LABELS, WEEKDAYS, type BookingConfig, type DayStatus, type Quote, type RuleRow } from "@/lib/booking";
import { Card, Input, Notice, Select, Table, Td, TextArea } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { Check, Field } from "@/components/catalog/ui";
import { BookingCalendar } from "@/components/catalog/booking/BookingCalendar";
import { PaxSimulator } from "@/components/catalog/booking/PaxSimulator";

/**
 * Spec 16 §2: pestaña «Reservas online» del Product 360. Sub-secciones por ruta (`…/reservas/pasajeros`…); cada una consulta su
 * regla: apagada en el tenant ⇒ «Regla desactivada para este tenant»; apagada solo por el producto ⇒ «Desactivada solo para este
 * producto» con *Reactivar*. La configuración guardada no se borra al apagar una regla.
 */
type Props = { product: Product; search: Record<string, string | undefined>; sub?: string };

const small = "rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50";
const danger = "rounded-md border border-rose-300 bg-white px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50";
const SUBS = ["disponibilidad", "pasajeros", "adicionales", "alojamiento", "pago", "recojo", "reglas"] as const;
const CATEGORIES = ["Museo", "Entrada", "Alimentación", "Transporte", "Extensión", "Seguro", "Experiencia", "Servicio", "Otro"];
const HOTEL_CATEGORIES = ["3 estrellas", "4 estrellas", "5 estrellas", "Hostal", "Lodge", "Boutique"];
const ROOM_TYPES = ["Simple", "Doble", "Matrimonial", "Triple", "Familiar", "Suite"];

export async function BookingTab({ product: p, search, sub }: Props) {
  const { data: c } = await api<{ data: BookingConfig }>(`/catalog/products/${p.ref}/booking-config`);
  const active = (SUBS as readonly string[]).includes(sub ?? "") ? (sub as (typeof SUBS)[number]) : "disponibilidad";
  const readOnly = !c.can.availability && !c.can.update && !c.can.rates;
  const base = `/catalogo/productos/${p.ref}/reservas`;
  const label = (key: string) => `${SECTION_LABELS[key]}${c.sections[key]?.state !== "on" ? " (desactivada)" : ""}`;

  return (
    <div className="space-y-4">
      {readOnly && <Notice>Modo consulta · la configuración comercial se muestra en solo lectura.</Notice>}
      <nav className="flex flex-wrap gap-1 text-sm" aria-label="Secciones de reservas online">
        {SUBS.map((s) => (
          <Link key={s} href={s === "disponibilidad" ? base : `${base}/${s}`} aria-current={s === active ? "page" : undefined}
            className={`rounded-full px-3 py-1 ring-1 ${s === active ? "bg-sky-700 text-white ring-sky-700" : "bg-white text-slate-700 ring-slate-200 hover:bg-slate-50"}`}>{label(s)}</Link>
        ))}
      </nav>
      <SectionState c={c} section={active} refCode={p.ref} />
      {active === "disponibilidad" && <AvailabilitySection c={c} p={p} search={search} />}
      {active === "pasajeros" && <PaxSection c={c} p={p} />}
      {active === "adicionales" && <AddonsSection c={c} p={p} />}
      {active === "alojamiento" && <LodgingSection c={c} p={p} />}
      {active === "pago" && <PaymentSection c={c} p={p} />}
      {active === "recojo" && <PickupSection c={c} p={p} />}
      {active === "reglas" && <RulesSection c={c} p={p} />}
    </div>
  );
}

function SectionState({ c, section, refCode }: { c: BookingConfig; section: string; refCode: string }) {
  const s = c.sections[section];
  if (!s || s.state === "on") return null;
  if (s.state === "off_tenant") {
    return (
      <Notice tone="warning">
        Regla desactivada para este tenant ({s.rules.map((r) => r.name).join(", ")}). La configuración guardada se conserva.{" "}
        {c.can.rules_center ? <Link href="/admin/reglas" className="font-medium underline">Abrir centro de reglas</Link> : <span>La administra el Administrador del tenant.</span>}
      </Notice>
    );
  }
  return (
    <Notice tone="warning">
      <span className="flex flex-wrap items-center gap-2">
        Desactivada solo para este producto.
        {c.can.update && s.rules.filter((r) => r.state === "off_product").map((r) => (
          <ActionForm key={r.key} path={`/catalog/products/${refCode}/rule-overrides/${r.key}`} method="PUT" submitLabel={s.rules.length > 1 ? `Reactivar ${r.name}` : "Reactivar"} submitClass={small} success="Regla reactivada" inline>
            <input type="hidden" name="enabled" value="true" />
          </ActionForm>
        ))}
      </span>
    </Notice>
  );
}

// --- Resumen y disponibilidad ---

async function AvailabilitySection({ c, p, search }: { c: BookingConfig; p: Product; search: Record<string, string | undefined> }) {
  const month = /^\d{4}-\d{2}$/.test(search.mes ?? "") ? search.mes! : (c.next_available ?? c.today).slice(0, 7);
  const seats = Math.max(0, Math.min(50, Number(search.pax ?? 0) || 0));
  const [y, m] = month.split("-").map(Number);
  const last = new Date(Date.UTC(y, m, 0)).getUTCDate();
  const { data: cal } = await api<{ data: { days: DayStatus[]; legend: string } }>(`/catalog/products/${p.ref}/booking-availability?from=${month}-01&to=${month}-${String(last).padStart(2, "0")}&seats=${seats}`);
  const shift = (n: number) => { const d = new Date(Date.UTC(y, m - 1 + n, 1)); return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, "0")}`; };
  const monthName = new Date(Date.UTC(y, m - 1, 15)).toLocaleDateString("es-PE", { month: "long", year: "numeric", timeZone: "UTC" });
  const href = (q: Record<string, string | number>) => `/catalogo/productos/${p.ref}/reservas?${new URLSearchParams({ mes: month, pax: String(seats), ...Object.fromEntries(Object.entries(q).map(([k, v]) => [k, String(v)])) })}`;
  const op = c.operation;
  const avail = c.sections.disponibilidad.state === "on";
  const pax = c.pax.effective;

  return (
    <div className="space-y-4">
      <Card title="Resumen comercial">
        <dl className="grid gap-3 sm:grid-cols-3">
          <Field label="Disponibilidad">{avail ? <span data-legend>{c.legend || "Sin rangos configurados (Consultar)"}</span> : "Sin control por rangos"}</Field>
          <Field label="Próxima fecha vendible">{c.next_available ? date(c.next_available) : "—"}</Field>
          <Field label="Operación">{op.operating_weekdays.length === 7 ? "Todos los días" : op.operating_weekdays.map((d) => WEEKDAYS[d]).join(", ")} · anticipación {op.effective_cutoff_hours} h</Field>
          <Field label="Pasajeros">Adulto {pax.adult_min_age}+ · niño {pax.child_min_age}–{pax.child_max_age} ({pax.child_pays ? `${pax.child_price_pct} % o tarifa Niño` : "no paga"}) · infante 0–{pax.infant_max_age}</Field>
          <Field label="Adicionales">{c.addons.length ? `${c.addons.length} configurado(s)` : "Ninguno"}</Field>
          <Field label="Alojamiento">{c.accommodation.enabled ? `${c.accommodation.optional ? "Opcional" : "Incluido"} · ${c.accommodation.summary}` : "No ofrece"}</Field>
          <Field label="Pago">{c.payment.effective_modes.map((x) => MODE_LABELS[x] ?? x).join(" · ")}</Field>
          <Field label="Recojo">{c.pickup.points.length ? `${c.pickup.points.length} punto(s)${c.pickup.required ? " · obligatorio" : ""}` : "Sin puntos"}</Field>
          <Field label="Reglas con ajuste propio">{c.rules.filter((r) => r.override).length || "Ninguna"}</Field>
        </dl>
      </Card>

      <Card title={<span className="capitalize">{monthName}</span>} actions={
        <span className="flex flex-wrap items-center gap-1 text-xs">
          <Link href={href({ mes: shift(-1) })} className={small} aria-label="Mes anterior">‹</Link>
          <Link href={href({ mes: shift(1) })} className={small} aria-label="Mes siguiente">›</Link>
          <span className="ml-2">Pasajeros:</span>
          {[0, 2, 4, 8].map((n) => <Link key={n} href={href({ pax: n })} aria-current={n === seats ? "true" : undefined} className={`${small} ${n === seats ? "bg-sky-50 ring-1 ring-sky-300" : ""}`}>{n || "—"}</Link>)}
        </span>
      }>
        <BookingCalendar days={cal.days} refCode={p.ref} canClose={c.can.availability && avail} />
      </Card>

      <div className="grid grid-cols-1 gap-4 [&>*]:min-w-0 lg:grid-cols-2">
        <Card title="Días de operación y anticipación">
          {c.can.availability ? (
            <ActionForm path={`/catalog/products/${p.ref}/operation`} method="PUT" submitLabel="Guardar operación" success="Operación guardada" className="space-y-3">
              <fieldset className="flex flex-wrap gap-3 text-sm"><legend className="sr-only">Días de operación</legend>
                {WEEKDAYS.map((w, i) => (
                  <label key={w} className="flex items-center gap-1"><input type="checkbox" name="operating_weekdays[]" value={i} defaultChecked={op.operating_weekdays.includes(i)} className="h-4 w-4" />{w}</label>
                ))}
              </fieldset>
              <Input label={`Anticipación mínima (horas; vacío = ${op.advance_min_days ? `${op.advance_min_days * 24} h de la ficha` : `${op.default_hours} h del tenant`})`} name="cutoff_hours" type="number" min={0} max={8760} defaultValue={op.cutoff_hours ?? ""} />
            </ActionForm>
          ) : (
            <p className="text-sm">{op.operating_weekdays.map((d) => WEEKDAYS[d]).join(", ")} · {op.effective_cutoff_hours} h de anticipación</p>
          )}
        </Card>
        <Card title="Nuevo rango">
          {c.can.availability ? (
            <ActionForm path={`/catalog/products/${p.ref}/availability-ranges`} submitLabel="Nuevo rango" className="grid gap-2 sm:grid-cols-2">
              <Input label="Desde" name="date_from" type="date" required min={c.today} />
              <Input label="Hasta" name="date_to" type="date" required min={c.today} />
              <Select label="Estado" name="status" options={[{ value: "disponible", label: "Disponible" }, { value: "cerrado", label: "Cerrado" }, { value: "agotado", label: "Agotado" }]} />
              <Input label="Cupos por día (disponible)" name="capacity_per_day" type="number" min={1} max={500} />
              <Input label="Nota (visible en el calendario)" name="note" maxLength={200} />
              <label className="flex items-center gap-1 pt-6 text-xs text-slate-600"><input type="checkbox" name="confirm_sales" value="true" /> Confirmo aunque haya ventas</label>
            </ActionForm>
          ) : <p className="text-sm text-slate-500">Su rol no edita la disponibilidad.</p>}
        </Card>
      </div>

      <Table head={["Rango", "Desde", "Hasta", "Estado", "Cupos/día", "Nota", ""]} empty="Sin rangos vigentes">
        {c.ranges.map((r) => (
          <tr key={r.id}>
            <Td className="font-mono text-xs">{r.code}</Td><Td>{date(r.date_from)}</Td><Td>{date(r.date_to)}</Td>
            <Td><span className={`rounded px-1.5 py-0.5 text-xs ring-1 ${r.status === "disponible" ? "bg-emerald-50 text-emerald-900 ring-emerald-200" : "bg-slate-200 text-slate-700 ring-slate-300"}`}>{r.status}</span></Td>
            <Td>{r.status === "disponible" ? r.capacity_per_day : "—"}</Td><Td className="text-xs">{r.note ?? ""}</Td>
            <Td>
              {c.can.availability && (
                <span className="flex flex-wrap gap-1">
                  {r.status === "disponible" && (
                    <ActionForm path={`/catalog/products/${p.ref}/availability-ranges/${r.id}`} method="PATCH" submitLabel="Cupos" submitClass={small} inline>
                      <input type="hidden" name="lock_version" value={r.lock_version} />
                      <input name="capacity_per_day" type="number" min={1} max={500} defaultValue={r.capacity_per_day} aria-label={`Cupos de ${r.code}`} className="w-16 rounded border border-slate-300 px-1 py-0.5 text-xs" />
                    </ActionForm>
                  )}
                  <ActionForm path={`/catalog/products/${p.ref}/availability-ranges/${r.id}`} method="DELETE" submitLabel="Eliminar" submitClass={danger} confirm={`¿Eliminar el rango ${r.code}?`} inline />
                </span>
              )}
            </Td>
          </tr>
        ))}
      </Table>
      <p className="text-xs text-slate-500">El modelo de disponibilidad y las salidas programadas siguen en las pestañas <Link href={`/catalogo/productos/${p.ref}/disponibilidad`} className="underline">Disponibilidad</Link> y <Link href={`/catalogo/productos/${p.ref}/salidas`} className="underline">Salidas</Link>; una salida en la fecha prevalece sobre el rango.</p>
    </div>
  );
}

// --- Pasajeros ---

function PaxSection({ c, p }: { c: BookingConfig; p: Product }) {
  const r = c.pax.stored ?? c.pax.effective;
  const edit = c.can.update;
  return (
    <div className="grid grid-cols-1 gap-4 [&>*]:min-w-0 lg:grid-cols-[1fr_380px]">
      <Card title={`Bandas de edad y precios ${c.pax.stored ? "(propias del producto)" : "(hereda los valores del tenant)"}`}>
        {c.pax.warnings.map((w) => <Notice key={w} tone="warning">{w}</Notice>)}
        {edit ? (
          <ActionForm path={`/catalog/products/${p.ref}/pax-rules`} method="PUT" submitLabel="Guardar pasajeros" success="Reglas de pasajeros guardadas" className="grid gap-2 sm:grid-cols-3">
            <Input label="Adulto desde (años)" name="adult_min_age" type="number" min={1} defaultValue={r.adult_min_age} />
            <Input label="Niño desde" name="child_min_age" type="number" min={0} defaultValue={r.child_min_age} />
            <Input label="Niño hasta" name="child_max_age" type="number" min={0} defaultValue={r.child_max_age} />
            <Input label="Infante hasta" name="infant_max_age" type="number" min={0} defaultValue={r.infant_max_age} />
            <Input label="% que paga el niño (si no hay tarifa Niño)" name="child_price_pct" type="number" min={0} max={100} step="0.01" defaultValue={r.child_price_pct} />
            <Input label="Máx. niños por adulto" name="max_children_per_adult" type="number" min={0} max={10} defaultValue={r.max_children_per_adult ?? 10} />
            <Input label="Máx. infantes por adulto" name="max_infants_per_adult" type="number" min={0} max={5} defaultValue={r.max_infants_per_adult ?? 5} />
            <Input label="Mínimo de adultos" name="min_adults" type="number" min={0} max={10} defaultValue={r.min_adults} />
            <div className="flex flex-col gap-1 pt-5"><Check name="child_pays" label="El niño paga" defaultChecked={r.child_pays} /><Check name="infant_allowed" label="Admite infantes" defaultChecked={r.infant_allowed} /><Check name="infant_pays" label="El infante paga" defaultChecked={r.infant_pays} /></div>
            <div className="sm:col-span-3"><TextArea label="Nota al cliente" name="note" defaultValue={r.note ?? ""} /></div>
          </ActionForm>
        ) : (
          <dl className="grid gap-3 sm:grid-cols-3">
            <Field label="Adulto">{r.adult_min_age}+ años</Field><Field label="Niño">{r.child_min_age}–{r.child_max_age} años · {r.child_pays ? `${r.child_price_pct} %` : "no paga"}</Field>
            <Field label="Infante">0–{r.infant_max_age} años · {r.infant_pays ? "paga" : "no paga"}</Field><Field label="Por adulto">{r.max_children_per_adult ?? "∞"} niño(s) · {r.max_infants_per_adult ?? "∞"} infante(s)</Field>
            <Field label="Mínimo de adultos">{r.min_adults}</Field>
          </dl>
        )}
        {edit && c.pax.stored && (
          <div className="mt-3"><ActionForm path={`/catalog/products/${p.ref}/pax-rules`} method="DELETE" submitLabel="Volver a los valores del tenant" submitClass={small} inline /></div>
        )}
        <p className="mt-3 text-xs text-slate-500">Efectivo: máx. {c.pax.effective.max_children_per_adult ?? "sin límite"} niño(s) y {c.pax.effective.max_infants_per_adult ?? "sin límite"} infante(s) por adulto · niño {c.pax.effective.child_price_pct} % · mínimo {c.pax.effective.min_adults} adulto(s). La tarifa Niño vigente prevalece sobre el %.</p>
      </Card>
      <Card title="Simulador"><PaxSimulator refCode={p.ref} /></Card>
    </div>
  );
}

// --- Adicionales ---

function AddonFields({ a }: { a?: BookingConfig["addons"][number] }) {
  return (
    <>
      <Input label="Nombre" name="name" required defaultValue={a?.name} maxLength={150} />
      <Select label="Categoría" name="category" defaultValue={a?.category ?? "Museo"} options={CATEGORIES.map((x) => ({ value: x, label: x }))} />
      <Input label="Precio" name="price" type="number" min={0} step="0.01" required defaultValue={a?.price} />
      <Select label="Moneda" name="currency" defaultValue={a?.currency ?? "USD"} options={[{ value: "USD", label: "USD" }, { value: "PEN", label: "PEN" }]} />
      <Select label="Cobro" name="charge_per" defaultValue={a?.charge_per ?? "pax"} options={[{ value: "pax", label: "Por pasajero" }, { value: "reserva", label: "Por reserva" }]} />
      <fieldset className="flex items-end gap-2 text-sm"><legend className="text-xs text-slate-600">Aplica a</legend>
        {["ADT", "CHD", "INF"].map((t) => <label key={t} className="flex items-center gap-1"><input type="checkbox" name="applies_to[]" value={t} defaultChecked={a ? a.applies_to.includes(t) : t !== "INF"} />{t}</label>)}
      </fieldset>
      <Input label="Stock / nota" name="stock_note" defaultValue={a?.stock_note ?? ""} maxLength={200} />
    </>
  );
}

function AddonsSection({ c, p }: { c: BookingConfig; p: Product }) {
  const edit = c.can.rates;
  return (
    <div className="space-y-4">
      <Table head={["Código", "Adicional", "Categoría", "Precio", "Cobro", "Aplica a", "Proveedor", ""]} empty="Sin adicionales">
        {c.addons.map((a) => (
          <tr key={a.id}>
            <Td className="font-mono text-xs">{a.code}</Td><Td>{a.name}{a.stock_note && <span className="block text-xs text-slate-500">{a.stock_note}</span>}</Td><Td>{a.category}</Td>
            <Td>{a.currency} {a.price}</Td><Td>{a.charge_per === "pax" ? "Por pasajero" : "Por reserva"}</Td><Td>{a.applies_to.join(", ")}</Td><Td>{a.provider ?? "—"}</Td>
            <Td>{edit && (
              <details><summary className="cursor-pointer text-xs text-sky-800">Editar</summary>
                <ActionForm path={`/catalog/products/${p.ref}/addons/${a.id}`} method="PATCH" submitLabel="Guardar adicional" className="mt-2 grid w-72 gap-2"><AddonFields a={a} /></ActionForm>
                <div className="mt-2"><ActionForm path={`/catalog/products/${p.ref}/addons/${a.id}`} method="DELETE" submitLabel="Eliminar" submitClass={danger} confirm={`¿Eliminar ${a.name}? Las reservas existentes no cambian.`} inline /></div>
              </details>
            )}</Td>
          </tr>
        ))}
      </Table>
      {edit && (
        <Card title="Nuevo adicional">
          <ActionForm path={`/catalog/products/${p.ref}/addons`} submitLabel="Agregar adicional" success="Adicional creado" className="grid gap-2 sm:grid-cols-4 sm:items-end"><AddonFields /></ActionForm>
        </Card>
      )}
    </div>
  );
}

// --- Alojamiento ---

function HotelFields({ h }: { h?: BookingConfig["accommodation"]["legs"][number]["hotels"][number] }) {
  const rows = [...(h?.room_types ?? []), ...Array.from({ length: Math.max(1, 3 - (h?.room_types.length ?? 0)) }, () => null)];
  return (
    <>
      <Input label="Hotel" name="name" required defaultValue={h?.name} />
      <Select label="Categoría" name="category" defaultValue={h?.category ?? "3 estrellas"} options={HOTEL_CATEGORIES.map((x) => ({ value: x, label: x }))} />
      <div className="sm:col-span-3 space-y-1">
        <p className="text-xs text-slate-600">Tipos de habitación (deje vacío el precio para no ofrecerlo)</p>
        {rows.map((t, i) => (
          <div key={i} className="flex flex-wrap items-center gap-1 text-xs">
            <select name={`room_types.${i}.room_type`} defaultValue={t?.room_type ?? ROOM_TYPES[i] ?? "Doble"} aria-label={`Tipo ${i + 1}`} className="rounded border border-slate-300 px-1 py-1">{ROOM_TYPES.map((x) => <option key={x}>{x}</option>)}</select>
            <input name={`room_types.${i}.capacity`} type="number" min={1} max={8} defaultValue={t?.capacity ?? (i === 0 ? 1 : i === 1 ? 2 : 3)} aria-label={`Capacidad ${i + 1}`} className="w-14 rounded border border-slate-300 px-1 py-1" />
            <input name={`room_types.${i}.price_per_night`} type="number" min={0} step="0.01" defaultValue={t?.price_per_night ?? ""} placeholder="Precio/noche" aria-label={`Precio por noche ${i + 1}`} className="w-24 rounded border border-slate-300 px-1 py-1" />
          </div>
        ))}
      </div>
    </>
  );
}

async function LodgingSection({ c, p }: { c: BookingConfig; p: Product }) {
  const acc = c.accommodation;
  const edit = c.can.rates;
  const path = `/catalog/products/${p.ref}/accommodation`;
  const example = acc.enabled && acc.legs.length && c.sections.alojamiento.state === "on"
    ? (await api<{ data: Quote }>(`/catalog/products/${p.ref}/booking-quote`, { method: "POST", body: JSON.stringify({
      date: c.next_available, pax: { adt: 2 }, channel: "Directo",
      lodging: acc.legs.filter((l) => l.hotels.length).map((l) => {
        const cheapest = l.hotels.flatMap((h) => h.room_types.filter((t) => t.capacity >= 2).map((t) => ({ h, t }))).sort((a, b) => Number(a.t.price_per_night) - Number(b.t.price_per_night))[0];
        return cheapest ? { leg_id: l.id, hotel_id: cheapest.h.id, rooms: [{ type: cheapest.t.room_type, qty: 1 }] } : null;
      }).filter(Boolean),
    }) })).data : null;

  return (
    <div className="space-y-4">
      <Card title="Configuración">
        {acc.warnings.map((w) => <Notice key={w} tone="warning">{w}</Notice>)}
        <p className="mb-2 text-sm" data-lodging-summary>{acc.enabled ? "Ofrece alojamiento" : "No ofrece alojamiento"} · {acc.optional ? "opcional" : "incluido"} · {acc.summary}{acc.expected_nights !== null ? ` (duración: ${acc.expected_nights} noche(s))` : ""}</p>
        {edit && (
          <ActionForm path={path} method="PUT" submitLabel={acc.enabled ? "Guardar alojamiento" : "Activar alojamiento"} success="Alojamiento guardado" className="grid gap-2 sm:grid-cols-4 sm:items-end">
            <input type="hidden" name="enabled" value="true" />
            <Select label="Modalidad" name="optional" defaultValue={String(acc.optional)} options={[{ value: "true", label: "Opcional" }, { value: "false", label: "Incluido" }]} />
            <Select label="Moneda" name="currency" defaultValue={acc.currency ?? "USD"} options={[{ value: "USD", label: "USD" }, { value: "PEN", label: "PEN" }]} />
            <Input label="Nota" name="note" defaultValue={acc.note ?? ""} />
          </ActionForm>
        )}
        {edit && acc.enabled && <div className="mt-2"><ActionForm path={path} method="PUT" submitLabel="Retirar alojamiento" submitClass={danger} confirm="¿Retirar el alojamiento? La configuración se conserva." inline><input type="hidden" name="enabled" value="false" /></ActionForm></div>}
      </Card>

      {acc.legs.map((l) => (
        <Card key={l.id} title={`${l.sequence}. ${l.city} · ${l.nights} noche(s)`} actions={edit && (
          <span className="flex gap-1">
            <ActionForm path={`${path}/legs/${l.id}`} method="DELETE" submitLabel="Eliminar tramo" submitClass={danger} confirm={`¿Eliminar el tramo ${l.city} y sus hoteles?`} inline />
          </span>
        )}>
          <Table head={["Hotel", "Categoría", "Habitaciones", ""]} empty="Sin hoteles">
            {l.hotels.map((h) => (
              <tr key={h.id}>
                <Td>{h.name}</Td><Td>{h.category}</Td>
                <Td className="text-xs">{h.room_types.map((t) => `${t.room_type} (${t.capacity}) ${acc.currency ?? ""} ${t.price_per_night}/noche`).join(" · ")}</Td>
                <Td>{edit && (
                  <details><summary className="cursor-pointer text-xs text-sky-800">Editar</summary>
                    <ActionForm path={`${path}/legs/${l.id}/hotels/${h.id}`} method="PATCH" submitLabel="Guardar hotel" className="mt-2 grid w-80 gap-2"><HotelFields h={h} /></ActionForm>
                    <div className="mt-2"><ActionForm path={`${path}/legs/${l.id}/hotels/${h.id}`} method="DELETE" submitLabel="Eliminar hotel" submitClass={danger} inline /></div>
                  </details>
                )}</Td>
              </tr>
            ))}
          </Table>
          {edit && (
            <details className="mt-3"><summary className="cursor-pointer text-sm text-sky-800">Hotel nuevo en {l.city}</summary>
              <ActionForm path={`${path}/legs/${l.id}/hotels`} submitLabel="Agregar hotel" success="Hotel agregado" className="mt-2 grid gap-2 sm:grid-cols-3"><HotelFields /></ActionForm>
            </details>
          )}
        </Card>
      ))}

      {edit && (
        <Card title="Nuevo tramo">
          <ActionForm path={`${path}/legs`} submitLabel="Agregar tramo" success="Tramo agregado" className="grid gap-2 sm:grid-cols-3 sm:items-end">
            <Input label="Ciudad" name="city" required maxLength={80} />
            <Input label="Noches" name="nights" type="number" min={1} max={30} required />
          </ActionForm>
        </Card>
      )}

      {example && (
        <Card title="Ejemplo calculado por el motor (2 adultos, habitación doble más económica)">
          <ul className="text-sm">{example.lines.filter((x) => x.kind === "lodging").map((x) => <li key={x.key}>{x.label}: {example.currency} {x.amount}</li>)}</ul>
          <p className="mt-2 text-sm font-medium" data-lodging-example>{example.rooms} habitación(es) · {example.nights} noche(s) · alojamiento {example.currency} {example.lodging_total}</p>
          {example.warnings.filter((w) => w.code.startsWith("R-LODGING")).map((w) => <p key={w.message} className="text-xs text-amber-800">{w.message}</p>)}
        </Card>
      )}
    </div>
  );
}

// --- Pago ---

async function PaymentSection({ c, p }: { c: BookingConfig; p: Product }) {
  const pay = c.payment;
  const date0 = c.next_available;
  const previews = date0 ? await Promise.all(pay.effective_modes.map(async (mode) => (await api<{ data: Quote }>(`/catalog/products/${p.ref}/booking-quote`, {
    method: "POST", body: JSON.stringify({ date: date0, pax: { adt: 2 }, payment_mode: mode, channel: "Directo" }),
  })).data)) : [];
  return (
    <div className="grid grid-cols-1 gap-4 [&>*]:min-w-0 lg:grid-cols-2">
      <Card title="Modalidades de pago">
        {c.can.rates ? (
          <ActionForm path={`/catalog/products/${p.ref}/payment-policy`} method="PUT" submitLabel="Guardar pago" success="Política de pago guardada" className="space-y-3">
            <fieldset className="flex flex-wrap gap-3 text-sm"><legend className="text-xs text-slate-600">Modalidades con cobro</legend>
              {["total", "adelanto"].map((m) => <label key={m} className="flex items-center gap-1"><input type="checkbox" name="modes[]" value={m} defaultChecked={pay.modes.includes(m)} />{MODE_LABELS[m]}</label>)}
            </fieldset>
            <div className="grid gap-2 sm:grid-cols-2">
              <Input label={`% de adelanto (vacío = ${pay.defaults.pct} % del tenant)`} name="deposit_pct" type="number" min={1} max={99} step="0.01" defaultValue={pay.deposit_pct ?? ""} />
              <Input label={`Saldo: días antes del viaje (vacío = ${pay.defaults.balance_due_days})`} name="balance_due_days" type="number" min={0} max={180} defaultValue={pay.balance_due_days ?? ""} />
            </div>
            <Check name="pay_later_enabled" label={`Permitir «Reservar y pagar después» (hold de ${pay.defaults.hold_hours} h)${pay.pay_later_state === "off_tenant" ? " · apagada en el tenant" : ""}`} defaultChecked={pay.pay_later_enabled} />
            <TextArea label="Nota" name="note" defaultValue={pay.note ?? ""} />
          </ActionForm>
        ) : (
          <p className="text-sm">{pay.modes.map((m) => MODE_LABELS[m]).join(" · ")} · adelanto {pay.deposit_pct ?? pay.defaults.pct} % · saldo {pay.balance_due_days ?? pay.defaults.balance_due_days} día(s) antes</p>
        )}
        {pay.deposit_state !== "on" && <p className="mt-2 text-xs text-amber-800">El adelanto está desactivado ({pay.deposit_state === "off_tenant" ? "tenant" : "producto"}): no se ofrece.</p>}
      </Card>
      <Card title={`Vista previa (2 adultos${date0 ? `, ${date(date0)}` : ""})`}>
        {previews.length ? (
          <Table head={["Modalidad", "Total", "Ahora", "Saldo", "Vence"]}>
            {previews.map((q) => (
              <tr key={q.payment_mode}>
                <Td>{MODE_LABELS[q.payment_mode]}</Td><Td>{q.currency} {q.total}</Td><Td>{q.currency} {q.deposit} ({q.deposit_pct} %)</Td><Td>{q.currency} {q.balance}</Td>
                <Td>{q.payment_mode === "pagar_despues" ? `hold ${q.hold_hours} h` : q.balance_due ? date(q.balance_due) : "—"}</Td>
              </tr>
            ))}
          </Table>
        ) : <p className="text-sm text-slate-500">Sin fecha vendible para calcular.</p>}
      </Card>
    </div>
  );
}

// --- Recojo ---

function PickupSection({ c, p }: { c: BookingConfig; p: Product }) {
  const pk = c.pickup;
  const rows = [...pk.points.map((x) => x.label), "", ""];
  return (
    <Card title="Puntos de partida y recojo">
      {c.can.pickup ? (
        <ActionForm path={`/catalog/products/${p.ref}/pickup`} method="PUT" submitLabel="Guardar recojo" success="Recojo guardado" className="space-y-3">
          <div className="flex flex-wrap gap-4"><Check name="required" label="Recojo obligatorio" defaultChecked={pk.required} /><Check name="ask_flight" label="Pedir número de vuelo" defaultChecked={pk.ask_flight} /></div>
          <div className="space-y-1">
            {rows.map((label, i) => <input key={i} name="points[]" defaultValue={label} placeholder="Ej.: Hotel en Miraflores" aria-label={`Punto ${i + 1}`} className="block w-full max-w-md rounded-md border border-slate-300 px-2 py-1.5 text-sm" />)}
          </div>
        </ActionForm>
      ) : (
        <ul className="list-disc pl-5 text-sm">{pk.points.map((x) => <li key={x.id}>{x.label}</li>)}{!pk.points.length && <li>Sin puntos</li>}</ul>
      )}
    </Card>
  );
}

// --- Reglas del producto ---

function RuleParams({ r, refCode }: { r: RuleRow; refCode: string }) {
  const names = Object.keys(r.schema.properties ?? {});
  if (!names.length) return null;
  return (
    <ActionForm path={`/catalog/products/${refCode}/rule-overrides/${r.key}`} method="PUT" submitLabel="Guardar parámetros" submitClass={small} success="Parámetros guardados" className="flex flex-wrap items-end gap-2">
      {names.map((n) => (
        <label key={n} className="text-xs">{n}
          <input name={`params.${n}`} type="number" step="any" min={r.schema.properties![n].minimum} max={r.schema.properties![n].maximum} defaultValue={r.params[n]} className="ml-1 w-20 rounded border border-slate-300 px-1 py-0.5" />
        </label>
      ))}
    </ActionForm>
  );
}

function RulesSection({ c, p }: { c: BookingConfig; p: Product }) {
  const overridable = c.rules.filter((r) => r.product_override);
  const tenantOnly = c.rules.filter((r) => !r.product_override);
  return (
    <div className="space-y-4">
      <Table head={["Regla", "Tenant", "Aplica a este producto", "Parámetros"]}>
        {overridable.map((r) => (
          <tr key={r.key} className={r.tenant_enabled ? "" : "opacity-60"} data-rule={r.key}>
            <Td><span className="font-medium">{r.name}</span><span className="block font-mono text-[11px] text-slate-500">{r.key} · {r.group}</span></Td>
            <Td>{r.tenant_enabled ? "Activa" : (
              <span>Apagada · {c.can.rules_center ? <Link href="/admin/reglas" className="underline">centro de reglas</Link> : "la administra el Administrador del tenant"}</span>
            )}</Td>
            <Td>
              {r.tenant_enabled && c.can.update ? (
                <span className="flex flex-wrap items-center gap-2">
                  <span className={r.effective ? "text-emerald-700" : "text-rose-700"}>{r.effective ? "Sí" : "No"}</span>
                  <ActionForm path={`/catalog/products/${p.ref}/rule-overrides/${r.key}`} method="PUT" submitLabel={r.effective ? "No aplicar" : "Aplicar"} submitClass={small} inline>
                    <input type="hidden" name="enabled" value={r.effective ? "false" : "true"} />
                  </ActionForm>
                  {r.override && <ActionForm path={`/catalog/products/${p.ref}/rule-overrides/${r.key}`} method="DELETE" submitLabel="Restablecer" submitClass={small} inline />}
                </span>
              ) : <span>{r.effective ? "Sí" : "No"}</span>}
            </Td>
            <Td>{r.tenant_enabled && c.can.update ? <RuleParams r={r} refCode={p.ref} /> : <span className="text-xs">{Object.entries(r.params).map(([k, v]) => `${k}: ${v}`).join(" · ") || "—"}</span>}</Td>
          </tr>
        ))}
      </Table>
      <Card title="Reglas solo del tenant">
        <ul className="grid gap-1 text-sm sm:grid-cols-2">
          {tenantOnly.map((r) => <li key={r.key}><span className={r.tenant_enabled ? "" : "text-slate-400"}>{r.name}</span> <span className="text-xs text-slate-500">({r.tenant_enabled ? "activa" : "apagada"})</span></li>)}
        </ul>
      </Card>
    </div>
  );
}
