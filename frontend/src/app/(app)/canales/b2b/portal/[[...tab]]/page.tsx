import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { money } from "@/lib/finance";
import { currentUser } from "@/lib/crm-server";
import { voucherRows, type B2bMe, type B2bProduct, type B2bReservation, type Statement } from "@/lib/channels";
import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, Input, Kpis, Notice, Select, Table, Td, TextArea, btnGhostCls, inputCls } from "@/components/crm/ui";
import { ChannelFrame, ChStatus, Dl, PreviewPicker } from "@/components/channels/ui";
import { RowAction, withParams } from "@/components/supplier/ui";
import { B2bTabs } from "../../nav";

type Sp = { agency?: string; producto?: string; reserva?: string; q?: string; status?: string; pagar?: string; voucher?: string };
type Meta = { preview: boolean; agency?: string; agencies?: { code: string; name: string; status: string; status_label: string }[] };
type VoucherDoc = { code: string; version: number; status_label: string; qr_token: string; document: Record<string, unknown> };

const SECTIONS = [["catalogo", "Catálogo y tarifas"], ["reservas", "Mis reservas"], ["estado-cuenta", "Estado de cuenta"]] as const;

/** Portal de agencias (spec 08 §2 `portal`, PROC-053). El usuario B2B ve su agencia; un interno lo previsualiza en solo lectura con `?agency=`. */
export default async function B2bPortalPage({ params, searchParams }: { params: Promise<{ tab?: string[] }>; searchParams: Promise<Sp> }) {
  const [{ tab = [] }, sp, me] = await Promise.all([params, searchParams, currentUser()]);
  const section = tab[0] ?? "catalogo";
  if (!SECTIONS.some(([k]) => k === section) || tab.length > 1) notFound();
  const preview = me.experience === "backoffice";
  const aq = preview && sp.agency ? `agency=${encodeURIComponent(sp.agency)}` : "";
  const join = (path: string, extra = "") => `${path}${[aq, extra].filter(Boolean).length ? `?${[aq, extra].filter(Boolean).join("&")}` : ""}`;
  const { data: ag, meta } = await api<{ data: B2bMe; meta: Meta }>(join("/b2b/me"));
  const base = `/canales/b2b/portal/${section}${preview ? `?agency=${ag.code}` : ""}`;
  const nav = SECTIONS.map(([key, label]) => ({ key, label, href: `/canales/b2b/portal/${key}${preview ? `?agency=${ag.code}` : ""}` }));

  const body = (
    <>
      <header className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">{ag.name}</h1>
          <p className="text-sm text-slate-600">{ag.code} · Lista {ag.price_list ?? "—"} · Comisión {ag.commission_pct} % · Convenio hasta {date(ag.valid_to)}</p>
        </div>
        <div className="text-right text-sm">
          <ChStatus value={ag.status} label={ag.status_label} />
          {Number(ag.credit_limit) > 0 && <p className="mt-1 text-slate-700">Crédito disponible <span className="font-semibold">{money(ag.credit_available, ag.credit_currency)}</span> de {money(ag.credit_limit, ag.credit_currency)}</p>}
        </div>
      </header>
      {ag.notice && <Notice tone="warning">{ag.notice}</Notice>}
      {!ag.notice && ag.expiring_days !== null && ag.expiring_days <= 30 && <Notice tone="warning">Su convenio vence en {ag.expiring_days} día(s). Coordine la renovación con su ejecutivo.</Notice>}
      <div className="mt-4">
        {section === "catalogo" && <Catalog ag={ag} sp={sp} base={base} join={join} preview={preview} />}
        {section === "reservas" && <Reservations sp={sp} base={base} join={join} preview={preview} />}
        {section === "estado-cuenta" && <AccountStatement sp={sp} base={base} join={join} preview={preview} />}
      </div>
    </>
  );

  if (!preview) return body;
  return (
    <>
      <B2bTabs active="portal" />
      <ChannelFrame preview label="Portal de agencias" nav={nav} active={section}
        picker={<PreviewPicker name="agency" label="Agencia" value={ag.code} action={`/canales/b2b/portal/${section}`}
          options={(meta.agencies ?? []).map((a) => ({ value: a.code, label: `${a.name} (${a.status_label})` }))} />}>
        {body}
      </ChannelFrame>
    </>
  );
}

type Ctx = { sp: Sp; base: string; join: (path: string, extra?: string) => string; preview: boolean };

async function Catalog({ ag, sp, base, join, preview }: Ctx & { ag: B2bMe }) {
  const { data: products } = await api<{ data: B2bProduct[] }>(join("/b2b/catalog"));
  const open = sp.producto ? products.find((p) => p.ref === sp.producto) ?? null : null;
  const departures = open?.by_departure ? (await api<{ data: B2bProduct["departures"] }>(join(`/b2b/catalog/${open.ref}/departures`))).data : [];

  return (
    <>
      {products.length === 0 ? <Notice>Aún no tiene productos con tarifa asignada. Consulte a su ejecutivo de cuenta.</Notice> : (
        <ul className="grid gap-3 md:grid-cols-2">
          {products.map((p) => (
            <li key={p.id} className={`rounded-lg border bg-white p-4 ${p.ok ? "border-slate-200" : "border-dashed border-slate-300 opacity-80"}`}>
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="font-medium text-slate-900">{p.name}</p>
                  <p className="text-xs text-slate-500">{p.ref} · {[p.destination, p.category, p.duration].filter(Boolean).join(" · ")}</p>
                </div>
                {p.credit ? <span className="rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800">Crédito</span> : <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">Prepago</span>}
              </div>
              {p.ok ? (
                <dl className="mt-3 grid grid-cols-3 gap-2 text-sm">
                  <div><dt className="text-xs text-slate-500">Neto agencia</dt><dd className="font-semibold">{money(p.net, p.currency)}</dd></div>
                  <div><dt className="text-xs text-slate-500">PVP sugerido</dt><dd>{p.pvp ? money(p.pvp, p.pvp_currency) : "—"}</dd></div>
                  <div><dt className="text-xs text-slate-500">Comisión {p.commission_pct} %</dt><dd>{money(p.commission, p.currency)}</dd></div>
                </dl>
              ) : <p className="mt-3 text-sm text-amber-800">{p.reason}</p>}
              {p.departures.length > 0 && <p className="mt-2 text-xs text-slate-600">Próximas salidas: {p.departures.map((d) => `${date(d.date)} (${d.available} cupos)`).join(" · ")}</p>}
              <div className="mt-3 flex items-center justify-between text-xs text-slate-500">
                <span>Tarifa vigente hasta {date(p.valid_to)} · {p.min_pax}–{p.max_pax} pax · anticipación {p.advance_min_days} día(s)</span>
                {p.ok && <RowAction primary href={withParams(base, { producto: p.ref })}>Reservar</RowAction>}
              </div>
            </li>
          ))}
        </ul>
      )}
      {open && (
        <Drawer title={`Reservar ${open.name}`} close={base}>
          <div className="space-y-4">
            <Dl rows={[["Neto por pasajero", money(open.net, open.currency)], ["Comisión", `${open.commission_pct} %`], ["Condiciones", open.conditions], ["Restricciones", open.restrictions]]} />
            {preview ? <Notice>Vista previa: la reserva se hace desde el usuario de la agencia.</Notice> : (
              <ActionForm path="/b2b/reservations" idempotent submitLabel="Confirmar reserva" redirectTo="/canales/b2b/portal/reservas?reserva={data.code}">
                <input type="hidden" name="product_id" value={open.ref} />
                {open.by_departure ? (
                  <Select name="departure_id" label="Salida" required placeholder="Elegir salida…"
                    options={departures.filter((d) => !d.closed && d.available > 0).map((d) => ({ value: d.id, label: `${date(d.date)} ${d.time} · ${d.available} cupo(s)` }))} />
                ) : <Input name="date" type="date" label="Fecha del servicio" required />}
                <Input name="pax" type="number" min={open.min_pax} max={open.max_pax} label="Pasajeros" required defaultValue={String(open.min_pax)} />
                <Select name="pay_mode" label="Forma de pago" defaultValue={open.credit && ag.credit_enabled ? "credito" : "prepago"}
                  options={[...(open.credit && ag.credit_enabled ? [{ value: "credito", label: `Línea de crédito (disponible ${money(ag.credit_available, ag.credit_currency)})` }] : []), { value: "prepago", label: "Prepago" }]} />
                <Input name="agency_ref" label="Su referencia (file de la agencia)" maxLength={60} />
                <TextArea name="passengers" label="Pasajeros (uno por línea: Nombre — Documento)" rows={4} placeholder={"Ana Pérez — 45678912\nLuis Soto — 70112233"} />
                <p className="text-xs text-slate-500">Puede completar los pasajeros luego; los faltantes quedan &quot;Por registrar&quot;.</p>
              </ActionForm>
            )}
          </div>
        </Drawer>
      )}
    </>
  );
}

async function Reservations({ sp, base, join, preview }: Ctx) {
  const qs = new URLSearchParams();
  if (sp.q) qs.set("q", sp.q);
  if (sp.status) qs.set("filter[status]", sp.status);
  const { data: rows, meta } = await api<{ data: B2bReservation[]; meta: { statuses: Record<string, string> } }>(join("/b2b/reservations", qs.toString()));
  const open = sp.reserva ? (await api<{ data: B2bReservation }>(join(`/b2b/reservations/${encodeURIComponent(sp.reserva)}`))).data : null;
  const voucher = open && sp.voucher ? (await api<{ data: VoucherDoc }>(join(`/b2b/vouchers/${encodeURIComponent(sp.voucher)}`))).data : null;
  const close = base;

  return (
    <>
      <form className="mb-3 flex flex-wrap gap-2" action={base.split("?")[0]}>
        {preview && <input type="hidden" name="agency" value={new URLSearchParams(base.split("?")[1] ?? "").get("agency") ?? ""} />}
        <input name="q" defaultValue={sp.q} placeholder="Código, título o su referencia" aria-label="Buscar reserva" className={`${inputCls} max-w-xs`} />
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Todos los estados</option>
          {Object.entries(meta.statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Reserva", "Su referencia", "Viaje", "Pax", "Total neto", "Pago", "Estado"]} empty="Aún no tiene reservas">
        {rows.map((r) => (
          <tr key={r.code}>
            <Td><Link href={withParams(base, { reserva: r.code })} scroll={false} className="font-medium text-sky-800 hover:underline">{r.code}</Link><span className="block text-xs text-slate-500">{r.title}</span></Td>
            <Td>{r.agency_ref ?? "—"}</Td>
            <Td className="whitespace-nowrap">{date(r.travel_from)}</Td>
            <Td>{r.pax}</Td>
            <Td className="whitespace-nowrap">{money(r.total, r.currency)}</Td>
            <Td>{r.pay_mode === "prepago" ? "Prepago" : "Crédito"}</Td>
            <Td><ChStatus value={r.status} label={r.status_label} /></Td>
          </tr>
        ))}
      </Table>
      {open && (
        <Drawer title={`Reserva ${open.code}`} close={close}>
          <div className="space-y-4">
            <div className="flex items-center gap-2"><ChStatus value={open.status} label={open.status_label} /><span className="text-xs text-slate-500">{open.title}</span></div>
            <Dl rows={[["Viaje", `${date(open.travel_from)} – ${date(open.travel_to)}`], ["Total neto", money(open.total, open.currency)], ["Comisión", `${open.commission_pct ?? "—"} %`],
              ["Forma de pago", open.pay_mode === "prepago" ? "Prepago" : "Línea de crédito"], ["Su referencia", open.agency_ref], ["Creada", dateTime(open.created_at)]]} />
            <section>
              <h3 className="mb-2 text-sm font-semibold">Servicios</h3>
              <ul className="space-y-2 text-sm">
                {open.services?.map((s) => (
                  <li key={s.code} className="rounded border border-slate-200 p-2">
                    <div className="flex justify-between gap-2"><span className="font-medium">{s.name}</span><ChStatus value={s.status} label={s.status_label} /></div>
                    <p className="text-xs text-slate-500">{date(s.date)} {s.time ?? ""} · {s.pax} pax {s.confirmation_code ? `· Conf. ${s.confirmation_code}` : ""}</p>
                    {s.voucher ? <RowAction href={withParams(base, { reserva: open.code, voucher: s.voucher })}>Ver voucher {s.voucher}</RowAction>
                      : <p className="text-xs text-slate-500">Voucher: {s.voucher_note}</p>}
                  </li>
                ))}
              </ul>
            </section>
            {voucher && (
              <section className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm">
                <p className="font-semibold">Voucher {voucher.code} · v{voucher.version} · {voucher.status_label}</p>
                <p className="mt-1 font-mono text-xs">QR {voucher.qr_token}</p>
                <Dl rows={voucherRows(voucher.document)} />
              </section>
            )}
            <section>
              <h3 className="mb-2 text-sm font-semibold">Pasajeros</h3>
              <ul className="text-sm">{open.passengers?.map((p, i) => <li key={i}>{p.name} · {p.type} · <span className={p.placeholder ? "text-amber-800" : ""}>{p.doc}</span></li>)}</ul>
            </section>
            {open.receivables && open.receivables.length > 0 && (
              <section>
                <h3 className="mb-2 text-sm font-semibold">Cuotas</h3>
                <ul className="text-sm">{open.receivables.map((x) => <li key={x.id}>{x.code} · {x.concept} · saldo {money(x.balance, x.currency)} · vence {date(x.due_date)} · <ChStatus value={x.status} label={x.status_label} /></li>)}</ul>
                <Link href={base.replace("/reservas", "/estado-cuenta")} className="text-xs text-sky-800 hover:underline">Informar un pago en Estado de cuenta</Link>
              </section>
            )}
          </div>
        </Drawer>
      )}
    </>
  );
}

async function AccountStatement({ sp, base, join, preview }: Ctx) {
  const { data: s } = await api<{ data: Statement }>(join("/b2b/statement"));
  const paying = sp.pagar ? s.receivables.find((r) => r.id === sp.pagar) ?? null : null;

  return (
    <>
      <Kpis items={[
        { label: "Facturado", value: money(s.kpis.billed, "PEN") },
        { label: "Pagado", value: money(s.kpis.paid, "PEN") },
        { label: "Por pagar", value: money(s.kpis.due, "PEN") },
        { label: "Comisiones", value: money(s.kpis.commissions, "PEN"), hint: `Neto a pagar ${money(s.kpis.net, "PEN")}` },
      ]} />
      <section className="mt-4">
        <h2 className="mb-2 text-sm font-semibold text-slate-900">Cuentas por pagar</h2>
        <Table head={["Cuota", "Reserva", "Monto", "Saldo", "Vence", "Estado", ""]} empty="Sin cuotas pendientes">
          {s.receivables.map((r) => (
            <tr key={r.id}>
              <Td>{r.code}<span className="block text-xs text-slate-500">{r.concept}</span></Td>
              <Td>{r.reservation ?? "—"}</Td>
              <Td className="whitespace-nowrap">{money(r.amount, r.currency)}</Td>
              <Td className="whitespace-nowrap">{money(r.balance, r.currency)}</Td>
              <Td className="whitespace-nowrap">{date(r.due_date)}</Td>
              <Td><ChStatus value={r.status} label={r.status_label} />{r.reported && <span className="block text-xs text-amber-800">Pago informado por validar</span>}</Td>
              <Td>{!preview && Number(r.balance) > 0 && <RowAction href={withParams(base, { pagar: r.id })}>Informar pago</RowAction>}</Td>
            </tr>
          ))}
        </Table>
      </section>
      <section className="mt-4">
        <div className="mb-2 flex items-center justify-between">
          <h2 className="text-sm font-semibold text-slate-900">Comisiones</h2>
          {!preview && s.can_settle && (
            <ActionForm path="/b2b/settlement-requests" idempotent inline submitLabel="Solicitar liquidación" success="Solicitud enviada a Finanzas."
              submitClass="inline-flex rounded-md bg-sky-700 px-2 py-1 text-xs font-medium text-white hover:bg-sky-800" />
          )}
        </div>
        <Table head={["Comisión", "Reserva", "%", "Monto", "Estado"]} empty="Sin comisiones">
          {s.commissions.map((c) => (
            <tr key={c.code}><Td>{c.code}</Td><Td>{c.reservation ?? "—"}</Td><Td>{c.pct} %</Td><Td className="whitespace-nowrap">{money(c.amount, c.currency)}</Td>
              <Td><ChStatus value={c.status} label={c.status_label} />{c.requested && <span className="block text-xs text-slate-500">Liquidación solicitada</span>}</Td></tr>
          ))}
        </Table>
      </section>
      <section className="mt-4 grid gap-4 md:grid-cols-2">
        <div>
          <h2 className="mb-2 text-sm font-semibold text-slate-900">Pagos</h2>
          <Table head={["Pago", "Fecha", "Monto", "Estado"]} empty="Sin pagos">
            {s.payments.map((p) => (
              <tr key={p.code}><Td>{p.code}<span className="block text-xs text-slate-500">{p.method}{p.reference ? ` · ${p.reference}` : ""}</span></Td><Td className="whitespace-nowrap">{date(p.paid_on)}</Td>
                <Td className="whitespace-nowrap">{money(p.amount, p.currency)}</Td><Td><ChStatus value={p.status} label={p.status_label} /></Td></tr>
            ))}
          </Table>
        </div>
        <div>
          <h2 className="mb-2 text-sm font-semibold text-slate-900">Liquidaciones</h2>
          <Table head={["Solicitud", "Fecha", "Comisiones", "Monto", "Estado"]} empty="Sin solicitudes">
            {s.settlements.map((x) => (
              <tr key={x.code}><Td>{x.code}</Td><Td className="whitespace-nowrap">{date(x.at)}</Td><Td>{x.count}</Td><Td className="whitespace-nowrap">{money(x.amount_pen, "PEN")}</Td>
                <Td><ChStatus value={x.status} label={x.status_label} /></Td></tr>
            ))}
          </Table>
        </div>
      </section>
      {paying && (
        <Drawer title={`Informar pago de ${paying.code}`} close={base}>
          <ActionForm path={`/b2b/receivables/${paying.id}/payment-reports`} idempotent submitLabel="Informar pago" redirectTo={base}
            success="Pago informado: Finanzas lo validará antes de aplicarlo.">
            <p className="text-sm text-slate-600">Saldo {money(paying.balance, paying.currency)} · vence {date(paying.due_date)}</p>
            <Input name="amount" type="number" step="0.01" min="0.01" max={paying.balance} label={`Monto (${paying.currency})`} required defaultValue={paying.balance} />
            <Select name="method" label="Medio" required options={s.methods.map((m) => ({ value: m, label: m }))} placeholder="Elegir…" />
            <Input name="operation_number" label="N.º de operación" required maxLength={40} />
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}
