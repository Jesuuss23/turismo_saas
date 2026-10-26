import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api } from "@/lib/api";
import { fetchScoped } from "@/lib/crm-server";
import { date, dateTime, type UserRef } from "@/lib/crm";
import { price } from "@/lib/catalog";
import { INTERNAL_LABEL, QUOTE_FLOW, QUOTE_STATUS, marginTone, toDocument, type Quote, type QuoteAction, type QuoteMeta, type QuoteVersion } from "@/lib/quotes";
import { btnCls, btnGhostCls, Card, inputCls, Notice, OutOfScope, PageHeader, Stepper, Table, Td } from "@/components/crm/ui";
import { Field, PathTabs } from "@/components/catalog/ui";
import { InternalBadge, LockedButton, QuoteStatus } from "@/components/quotes/ui";
import { QuoteDialog } from "@/components/quotes/QuoteDialogs";
import { QuoteDocument } from "@/components/quotes/QuoteDocument";

type Search = { accion?: string; v?: string; a?: string; b?: string };
type Compare = {
  a: number; b: number; differences: number;
  lines: { key: string; product: string; segment: string; change: string; a: Record<string, string> | null; b: Record<string, string> | null; changed_fields: string[] }[];
  summary: { field: string; a: string; b: string; changed: boolean }[];
};
type AuditRow = { action: string; before: string | null; after: string | null; actor: UserRef | { name: string }; actor_role: string | null; at: string };

const TERMINAL = ["rechazada", "vencida", "cancelada"];
const LINKS: Record<string, (code: string) => string> = {
  edit: (c) => `/cotizaciones/${c}/editar`,
  print: (c) => `/cotizaciones/${c}/imprimir`,
  compare: (c) => `/cotizaciones/${c}/versiones`,
};
const FIELD_LABEL: Record<string, string> = {
  service_date: "Fecha", qty: "Cant.", pax: "Pax", unit_price: "Precio unit.", sale_total: "Subtotal", unit_cost: "Costo unit.",
  discount_pct: "Descuento %", tax_pct: "IGV %", discount_total: "Descuento", net_total: "Valor de venta", tax_total: "IGV", grand_total: "Total",
  cost_total: "Costo", profit_total: "Utilidad", margin_pct: "Margen %",
};

/** Vista 360 de la cotización (spec 03 §2): flujo, acciones por estado y permiso, versiones, pricing, documento y seguimiento. */
export default async function QuoteDetailPage({ params, searchParams }: { params: Promise<{ code: string; tab?: string[] }>; searchParams: Promise<Search> }) {
  const [{ code, tab = [] }, sp] = await Promise.all([params, searchParams]);
  const res = await fetchScoped<{ data: Quote }>(`/quotes/${code}`);
  if (!res) return <OutOfScope back="/cotizaciones" />;
  const q = res.data;
  const active = tab[0] ?? "detalle";
  const tabs = [
    { key: "detalle", label: "Detalle e ítems" },
    { key: "versiones", label: `Versiones (${q.versions.length})` },
    ...(q.see_margin ? [{ key: "pricing", label: "Pricing y margen" }] : []),
    { key: "documento", label: "Documento" },
    { key: "seguimiento", label: "Envío y seguimiento" },
    { key: "actividad", label: "Actividad" },
  ];
  if (!tabs.some((t) => t.key === active)) notFound();
  const { data: meta } = await api<{ data: QuoteMeta }>("/quotes/meta");
  const base = `/cotizaciones/${q.code}`;
  const here = active === "detalle" ? base : `${base}/${active}`;
  const readOnly = !meta.can.create && !meta.can.send && !meta.can.client_approval;

  const terminal = TERMINAL.includes(q.display_status);
  const steps = terminal ? [...QUOTE_FLOW.slice(0, 4).map((s) => QUOTE_STATUS[s]), q.status_label] : QUOTE_FLOW.map((s) => QUOTE_STATUS[s]);
  const current = terminal ? 5 : QUOTE_FLOW.indexOf(q.status as (typeof QUOTE_FLOW)[number]) + 1;
  const primary = q.actions.filter((a) => a.group === "primary");
  const more = q.actions.filter((a) => a.group === "more");
  const ia = q.internal_approval;

  return (
    <>
      <PageHeader
        title={`${q.code} · ${q.title}`}
        subtitle={<span className="flex flex-wrap items-center gap-2"><QuoteStatus value={q.display_status} label={q.status_label} /> versión {q.version_no} · {q.client?.name} · {q.owner?.name}</span>}
        actions={<Link href="/cotizaciones" className="text-sm text-sky-800 hover:underline">← Cotizaciones</Link>}
      />
      <div className="mb-4"><Stepper steps={steps} current={current} failed={terminal} /></div>

      {readOnly && <Notice>Modo consulta: puede revisar la cotización y exportarla, pero su rol no edita, envía ni aprueba cotizaciones.</Notice>}
      {q.expired && <Notice tone="danger">Cotización vencida el {date(q.valid_until)}. Cree una nueva versión para renovar la validez (QTE-BR-18).</Notice>}
      {ia?.state === "pendiente" && ia.pending && (
        <Notice tone="warning"><strong>Pendiente de aprobación de gerencia</strong> ({ia.reasons.join("; ")}). Solicitada por {ia.pending.requested_by?.name} el {dateTime(ia.pending.requested_at)}: «{ia.pending.request_note}».</Notice>
      )}
      {ia?.state === "requerida" && <Notice tone="warning"><strong>Requiere aprobación interna</strong> antes de enviarse: {ia.reasons.join("; ")} (QTE-BR-12).</Notice>}
      {ia?.state === "rechazada" && ia.last_decision && (
        <Notice tone="danger"><strong>Aprobación interna rechazada</strong> por {ia.last_decision.decided_by?.name}: «{ia.last_decision.decision_note}». Ajuste la versión y vuelva a solicitarla.</Notice>
      )}
      {ia?.state === "aprobada" && ia.last_decision && <Notice>Aprobada por gerencia ({ia.last_decision.decided_by?.name}, {dateTime(ia.last_decision.decided_at)}): {ia.reasons.join("; ")}.</Notice>}
      {q.approval && <Notice>Aprobada por el cliente: {q.approval.by_name} · {q.approval.channel}{q.approval.ref ? ` (${q.approval.ref})` : ""} · {dateTime(q.approval.at)} · versión {q.approval.version_no}.</Notice>}
      {q.rejection && <Notice tone="danger">Rechazada el {dateTime(q.rejection.at)}: {q.rejection.reason}</Notice>}

      <div className="mb-4 flex flex-wrap items-center gap-2" aria-label="Acciones">
        {primary.map((a, i) => <ActionButton key={a.key} a={a} code={q.code} here={here} primary={i === 0} />)}
        {more.length > 0 && (
          <details className="relative">
            <summary className={`${btnGhostCls} cursor-pointer list-none`}>Más acciones ▾</summary>
            <div className="absolute z-20 mt-1 flex w-64 flex-col gap-1 rounded-md border border-slate-200 bg-white p-2 shadow-lg">
              {more.map((a) => a.enabled
                ? <Link key={a.key} href={LINKS[a.key]?.(q.code) ?? `${here}?accion=${a.key}`} className="rounded px-2 py-1 text-sm hover:bg-slate-50">{a.label}</Link>
                : <span key={a.key} title={a.reason ?? undefined} className="rounded px-2 py-1 text-sm text-slate-400">🔒 {a.label}</span>)}
            </div>
          </details>
        )}
      </div>

      <div className="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-3 lg:grid-cols-6">
        <Field label="Total">{q.version ? price(q.version.totals.total, q.currency) : "—"}{q.currency !== "PEN" && <span className="block text-xs text-slate-500">≈ {price(q.total_pen, "PEN")}</span>}</Field>
        <Field label="Válida hasta">{date(q.valid_until)}{!q.expired && q.days_left >= 0 && <span className="block text-xs text-slate-500">{q.days_left} día(s)</span>}</Field>
        <Field label="Viaje">{date(q.travel_from)} – {date(q.travel_to)}</Field>
        <Field label="Pasajeros">{q.pax.adt} ADT · {q.pax.chd} CHD · {q.pax.inf} INF</Field>
        <Field label="Aprobación interna"><InternalBadge state={ia?.state ?? "no_requerida"} /></Field>
        <Field label="Oportunidad">{q.opportunity ? <Link href={`/crm/oportunidades/${q.opportunity.code}`} className="text-sky-800 hover:underline">{q.opportunity.code}</Link> : "—"}</Field>
      </div>

      <PathTabs base={base} tabs={tabs} active={active} />
      {active === "detalle" && <DetailTab q={q} versionNo={Number(sp.v) || q.version_no} />}
      {active === "versiones" && <VersionsTab q={q} a={sp.a} b={sp.b} />}
      {active === "pricing" && q.version && <PricingTab q={q} v={q.version} />}
      {active === "documento" && (q.version ? <QuoteDocument doc={toDocument(q, q.version)} /> : <p className="text-sm text-slate-500">Sin versión.</p>)}
      {active === "seguimiento" && <FollowUpTab q={q} />}
      {active === "actividad" && <ActivityTab code={q.code} />}

      {sp.accion && q.actions.some((a) => a.key === sp.accion && a.enabled) && <QuoteDialog action={sp.accion} q={q} meta={meta} close={here} />}
    </>
  );
}

function ActionButton({ a, code, here, primary }: { a: QuoteAction; code: string; here: string; primary: boolean }) {
  if (!a.enabled) return <LockedButton label={a.label} reason={a.reason} />;
  return <Link href={LINKS[a.key]?.(code) ?? `${here}?accion=${a.key}`} className={primary ? btnCls : btnGhostCls}>{a.label}</Link>;
}

async function DetailTab({ q, versionNo }: { q: Quote; versionNo: number }) {
  const v = versionNo === q.version_no ? q.version : (await api<{ data: QuoteVersion }>(`/quotes/${q.code}/versions/${versionNo}`).catch(() => null))?.data;
  if (!v) return <p className="text-sm text-slate-500">Versión no encontrada.</p>;
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
      <Card title={<span>Ítems · versión {v.version_no} <span className="text-xs font-normal text-slate-500">({v.status_label}{v.frozen_at ? ", congelada" : ""})</span></span>}
        actions={q.versions.length > 1 && (
          <form className="flex items-center gap-1">
            <select name="v" defaultValue={v.version_no} aria-label="Versión" className={`${inputCls} py-1`}>
              {q.versions.map((x) => <option key={x.version_no} value={x.version_no}>Versión {x.version_no}</option>)}
            </select>
            <button className="text-xs text-sky-800 hover:underline">Ver</button>
          </form>
        )}>
        <Table head={["#", "Producto", "Fecha", "Segmento", "Cant. × pax", "Precio unit.", "Subtotal", ...(q.see_margin ? ["Margen"] : [])]}>
          {v.items.map((i) => (
            <tr key={i.id}>
              <Td>{i.line_no}</Td>
              <Td>{i.product ? <Link href={`/catalogo/productos/${i.product.ref}`} className="text-sky-800 hover:underline">{i.product.ref}</Link> : "—"} {i.product?.name}
                <span className="block text-xs text-slate-500">{i.rate?.ref ? `Tarifa ${i.rate.ref}` : "Precio manual"}{i.season ? ` · ${i.season} ×${i.season_factor}` : ""} · v{i.product_version}</span></Td>
              <Td>{date(i.service_date)}</Td>
              <Td>{i.segment}</Td>
              <Td>{i.qty} × {i.pax}</Td>
              <Td>{price(i.unit_price, q.currency)}</Td>
              <Td>{price(i.sale_total, q.currency)}</Td>
              {q.see_margin && <Td className={marginTone(i.margin_pct)}>{i.margin_pct} %</Td>}
            </tr>
          ))}
        </Table>
        {(v.notes || v.conditions) && (
          <div className="mt-3 grid gap-3 text-sm md:grid-cols-2">
            {v.notes && <div><p className="text-xs font-medium uppercase text-slate-500">Notas</p><p className="whitespace-pre-line">{v.notes}</p></div>}
            {v.conditions && <div><p className="text-xs font-medium uppercase text-slate-500">Condiciones</p><p className="whitespace-pre-line">{v.conditions}</p></div>}
          </div>
        )}
      </Card>
      <div className="space-y-4">
        <Card title="Totales"><Totals v={v} currency={q.currency} margin={q.see_margin} /></Card>
        <Card title="Cliente">
          <dl className="space-y-1 text-sm">
            <div><Link href={`/crm/clientes/${q.client?.code}`} className="font-medium text-sky-800 hover:underline">{q.client?.code} · {q.client?.name}</Link></div>
            <div className="text-slate-600">{q.client?.country} · {q.client?.segment}</div>
            <div className="text-slate-600">{q.client?.email ?? "sin correo"} · {q.client?.phone ?? "sin teléfono"}</div>
          </dl>
        </Card>
        <Card title="Precio congelado">
          <p className="text-sm text-slate-600">{v.snapshot_label}. Tipo de cambio {v.fx_rate}.</p>
          {v.content_hash && <p className="mt-1 break-all font-mono text-[11px] text-slate-500">sha256 {v.content_hash}</p>}
        </Card>
      </div>
    </div>
  );
}

function Totals({ v, currency, margin }: { v: QuoteVersion; currency: string; margin: boolean }) {
  const t = v.totals;
  return (
    <dl className="space-y-1 text-sm">
      <div className="flex justify-between"><dt>Venta bruta</dt><dd>{price(t.sale, currency)}</dd></div>
      <div className="flex justify-between"><dt>Descuento ({Number(v.discount_pct)} %)</dt><dd>− {price(t.discount, currency)}</dd></div>
      <div className="flex justify-between"><dt>Valor de venta</dt><dd>{price(t.net, currency)}</dd></div>
      <div className="flex justify-between"><dt>IGV ({Number(v.tax_pct)} %)</dt><dd>{price(t.tax, currency)}</dd></div>
      <div className="flex justify-between border-t border-slate-200 pt-1 text-base font-semibold"><dt>Total</dt><dd>{price(t.total, currency)}</dd></div>
      {margin && t.margin_pct !== undefined && (
        <div className={`flex justify-between pt-1 font-medium ${marginTone(t.margin_pct)}`}><dt>Margen</dt><dd>{t.margin_pct} %</dd></div>
      )}
    </dl>
  );
}

async function VersionsTab({ q, a, b }: { q: Quote; a?: string; b?: string }) {
  const latest = q.versions.length;
  const [va, vb] = [Number(a) || Math.max(1, latest - 1), Number(b) || latest];
  const cmp = latest > 1 ? (await api<{ data: Compare }>(`/quotes/${q.code}/versions/compare?a=${va}&b=${vb}`).catch(() => null))?.data : null;
  return (
    <div className="space-y-4">
      <Table head={["Versión", "Estado", "Emitida", "Autor", "Descuento", "Total", ...(q.see_margin ? ["Margen"] : []), "Precio congelado", ""]}>
        {q.versions.map((v) => (
          <tr key={v.id}>
            <Td>v{v.version_no}{v.version_no === q.version_no && <span className="ml-1 text-xs text-sky-700">(vigente)</span>}</Td>
            <Td>{v.status_label}</Td>
            <Td>{date(v.issued_on)}</Td>
            <Td>{v.author?.name}</Td>
            <Td>{Number(v.discount_pct)} %</Td>
            <Td>{price(v.grand_total, q.currency)}</Td>
            {q.see_margin && <Td className={marginTone(v.margin_pct)}>{v.margin_pct} %</Td>}
            <Td className="text-xs text-slate-500">{v.snapshot_label}</Td>
            <Td><Link href={`/cotizaciones/${q.code}?v=${v.version_no}`} className="text-sm text-sky-800 hover:underline">Ver</Link></Td>
          </tr>
        ))}
      </Table>
      {latest > 1 && (
        <Card title="Comparar versiones" actions={
          <form className="flex items-center gap-1 text-sm">
            <select name="a" defaultValue={va} aria-label="Versión A" className={`${inputCls} py-1`}>{q.versions.map((v) => <option key={v.version_no} value={v.version_no}>v{v.version_no}</option>)}</select>
            <span>vs</span>
            <select name="b" defaultValue={vb} aria-label="Versión B" className={`${inputCls} py-1`}>{q.versions.map((v) => <option key={v.version_no} value={v.version_no}>v{v.version_no}</option>)}</select>
            <button className="text-xs text-sky-800 hover:underline">Comparar</button>
          </form>
        }>
          {cmp ? (
            <div className="space-y-3">
              <p className="text-sm text-slate-600" data-testid="compare-summary">{cmp.differences} diferencia(s) entre v{cmp.a} y v{cmp.b}.</p>
              <Table head={["Ítem", "Cambio", `v${cmp.a}`, `v${cmp.b}`]}>
                {cmp.lines.map((l) => (
                  <tr key={l.key} className={l.change === "igual" ? "text-slate-500" : ""}>
                    <Td>{l.product}<span className="block text-xs text-slate-500">{l.segment}</span></Td>
                    <Td>{l.change}</Td>
                    <Td>{l.a ? <Diff values={l.a} changed={l.changed_fields} /> : "—"}</Td>
                    <Td>{l.b ? <Diff values={l.b} changed={l.changed_fields} /> : "—"}</Td>
                  </tr>
                ))}
              </Table>
              <Table head={["Total", `v${cmp.a}`, `v${cmp.b}`]}>
                {cmp.summary.map((s) => (
                  <tr key={s.field} className={s.changed ? "font-medium" : "text-slate-500"}>
                    <Td>{FIELD_LABEL[s.field] ?? s.field}</Td><Td>{s.a}</Td><Td>{s.b}</Td>
                  </tr>
                ))}
              </Table>
            </div>
          ) : <p className="text-sm text-slate-500">No se pudo comparar.</p>}
        </Card>
      )}
    </div>
  );
}

function Diff({ values, changed }: { values: Record<string, string>; changed: string[] }) {
  return (
    <span className="text-xs">
      {Object.entries(values).map(([k, v]) => (
        <span key={k} className={`mr-2 inline-block ${changed.includes(k) ? "rounded bg-amber-100 px-1 font-medium text-amber-900" : ""}`}>{FIELD_LABEL[k] ?? k}: {v}</span>
      ))}
    </span>
  );
}

function PricingTab({ q, v }: { q: Quote; v: QuoteVersion }) {
  const t = v.totals;
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
      <Card title="Costo, precio y margen por ítem">
        <Table head={["Ítem", "Costo unit.", "Precio unit.", "Costo total", "Venta", "Margen", "Origen"]}>
          {v.items.map((i) => (
            <tr key={i.id}>
              <Td>{i.line_no}. {i.product?.name}</Td>
              <Td>{price(i.unit_cost, q.currency)}</Td>
              <Td>{price(i.unit_price, q.currency)}</Td>
              <Td>{price(i.cost_total, q.currency)}</Td>
              <Td>{price(i.sale_total, q.currency)}</Td>
              <Td className={marginTone(i.margin_pct)}>{i.margin_pct} %</Td>
              <Td className="text-xs">{i.price_source}</Td>
            </tr>
          ))}
        </Table>
      </Card>
      <div className="space-y-4">
        <Card title="Rentabilidad">
          <dl className="space-y-1 text-sm">
            <div className="flex justify-between"><dt>Valor de venta</dt><dd>{price(t.net, q.currency)}</dd></div>
            <div className="flex justify-between"><dt>Costo</dt><dd>{price(t.cost, q.currency)}</dd></div>
            <div className="flex justify-between"><dt>Utilidad</dt><dd>{price(t.profit, q.currency)}</dd></div>
            <div className={`flex justify-between font-semibold ${marginTone(t.margin_pct)}`}><dt>Margen</dt><dd>{t.margin_pct} %</dd></div>
            <div className="flex justify-between text-slate-600"><dt>Markup</dt><dd>{t.markup_pct} %</dd></div>
          </dl>
        </Card>
        <Card title="Política comercial">
          <p className="text-sm text-slate-600">Descuento hasta 5 % y margen desde 15 % sin aprobación; tope de descuento 10 % (QTE-BR-08/11).</p>
          {q.internal_approval && <p className="mt-2 text-sm">Estado: <strong>{INTERNAL_LABEL[q.internal_approval.state]}</strong>{q.internal_approval.reasons.length > 0 && ` · ${q.internal_approval.reasons.join("; ")}`}</p>}
        </Card>
      </div>
    </div>
  );
}

function FollowUpTab({ q }: { q: Quote }) {
  return (
    <div className="space-y-4">
      <Card title="Enlace del cliente">
        {q.token
          ? <p className="text-sm">Activo hasta {date(q.token.expires_at)}: <a href={q.token.url} className="break-all font-mono text-xs text-sky-800 underline" data-testid="quote-link">{q.token.url}</a></p>
          : <p className="text-sm text-slate-500">Sin enlace generado.</p>}
        {q.viewed_at && <p className="mt-1 text-sm text-slate-600">Primera apertura: {dateTime(q.viewed_at)}</p>}
      </Card>
      <Card title="Envíos">
        <Table head={["Versión", "Canal", "Destinatario", "Mensaje", "Enviado por", "Fecha"]} empty="Aún no se envía">
          {q.sends.map((s) => <tr key={s.id}><Td>v{s.version_no}</Td><Td>{s.channel}</Td><Td>{s.recipient}</Td><Td className="max-w-xs truncate">{s.message ?? "—"}</Td><Td>{s.sent_by?.name}</Td><Td>{dateTime(s.sent_at)}</Td></tr>)}
        </Table>
      </Card>
      <Card title="Aperturas">
        <Table head={["Versión", "Origen", "IP", "Registrada por", "Fecha"]} empty="Sin aperturas registradas">
          {q.views.map((v) => <tr key={v.id}><Td>v{v.version_no}</Td><Td>{v.source}</Td><Td>{v.ip ?? "—"}</Td><Td>{v.recorded_by?.name ?? "Cliente"}</Td><Td>{dateTime(v.viewed_at)}</Td></tr>)}
        </Table>
      </Card>
      <Card title="Negociación">
        {q.negotiations.length ? (
          <ol className="space-y-2 text-sm">
            {q.negotiations.map((n) => (
              <li key={n.id} className="border-l-2 border-amber-300 pl-3">
                <p>{n.note}</p>
                <p className="text-xs text-slate-500">{n.channel} · {n.by?.name} · {dateTime(n.at)}{n.created_version_no ? ` · generó la versión ${n.created_version_no}` : ""}</p>
              </li>
            ))}
          </ol>
        ) : <p className="text-sm text-slate-500">Sin contrapropuestas.</p>}
      </Card>
      {q.approval && (
        <Card title="Evidencia de aprobación">
          <dl className="grid gap-2 text-sm sm:grid-cols-2">
            <Field label="Aprobado por">{q.approval.by_name}</Field><Field label="Canal">{q.approval.channel}</Field>
            <Field label="Referencia">{q.approval.ref}</Field><Field label="Registrado por">{q.approval.recorded_by?.name ?? "Cliente (portal)"}</Field>
            <Field label="IP">{q.approval.ip}</Field><Field label="Fecha">{dateTime(q.approval.at)}</Field>
          </dl>
          {q.approval.hash && <p className="mt-2 break-all font-mono text-[11px] text-slate-500">sha256 {q.approval.hash}</p>}
        </Card>
      )}
    </div>
  );
}

async function ActivityTab({ code }: { code: string }) {
  const { data } = await api<{ data: AuditRow[] }>(`/quotes/${code}/activity`);
  if (!data.length) return <p className="text-sm text-slate-500">Sin movimientos.</p>;
  return (
    <ol className="space-y-3 text-sm">
      {data.map((r, i) => (
        <li key={i} className="border-l-2 border-slate-200 pl-3">
          <p className="font-medium text-slate-800">{r.action.replaceAll("_", " ")}</p>
          {(r.before || r.after) && <p className="text-slate-600">{r.before ?? "—"} → {r.after ?? "—"}</p>}
          <p className="text-xs text-slate-500">{r.actor?.name ?? "Sistema"}{r.actor_role ? ` (${r.actor_role})` : ""} · {dateTime(r.at)}</p>
        </li>
      ))}
    </ol>
  );
}
