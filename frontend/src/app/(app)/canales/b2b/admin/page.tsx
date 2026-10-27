import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { money } from "@/lib/finance";
import type { Agency, B2bSummary } from "@/lib/channels";
import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, Input, Kpis, Notice, PageHeader, Select, Table, Td, TextArea, btnGhostCls, inputCls } from "@/components/crm/ui";
import { ChStatus, Dl } from "@/components/channels/ui";
import { RowAction, withParams } from "@/components/supplier/ui";
import { B2bTabs } from "../nav";

type Detail = Agency & {
  rates: { id: string; product: string | null; product_ref: string | null; price: string; currency: string; commission_pct: string; is_credit: boolean; valid_to: string | null; expired: boolean }[];
  reservations: { code: string; title: string; status: string; status_label: string; total: string; currency: string; travel_from: string }[];
};
type Movement = { id: string; kind: string; amount: string; currency: string; balance_after: string; reservation: string | null; payment: string | null; note: string | null; at: string };
type Sp = { q?: string; status?: string; list?: string; agencia?: string; nueva?: string; editar?: string; accion?: string };

const KIND: Record<string, string> = { cargo_reserva: "Cargo por reserva", pago_credito: "Cobro con crédito", pago_aplicado: "Pago aplicado", reversion_pago: "Reversión de pago", anulacion_reserva: "Anulación de reserva", ajuste: "Ajuste" };

/** Administración del canal B2B (spec 08 §2 `admin`, PROC-053). */
export default async function B2bAdminPage({ searchParams }: { searchParams: Promise<Sp> }) {
  const sp = await searchParams;
  const qs = new URLSearchParams();
  if (sp.q) qs.set("q", sp.q);
  if (sp.status) qs.set("filter[status]", sp.status);
  if (sp.list) qs.set("filter[price_list]", sp.list);
  const [{ data: s }, { data: rows, meta }] = await Promise.all([
    api<{ data: B2bSummary }>("/channels/b2b/summary"),
    api<{ data: Agency[]; meta: { price_lists: { id: string; name: string }[]; statuses: Record<string, string> } }>(`/channels/b2b/agencies?${qs}`),
  ]);
  const base = `/canales/b2b/admin${qs.size ? `?${new URLSearchParams(Object.entries(sp).filter(([k, v]) => ["q", "status", "list"].includes(k) && v) as [string, string][])}` : ""}`;
  const open = sp.agencia ? await api<{ data: Detail }>(`/channels/b2b/agencies/${sp.agencia}`).then((r) => r.data) : null;
  const moves = open && sp.accion === "libro" ? (await api<{ data: Movement[] }>(`/channels/b2b/agencies/${open.code}/credit-movements`)).data : null;
  const editing = sp.editar ? rows.find((a) => a.code === sp.editar) ?? null : null;
  const lists = meta.price_lists.map((l) => ({ value: l.id, label: l.name }));

  return (
    <>
      <PageHeader title="Canal B2B" subtitle="Agencias con convenio, línea de crédito, listas de precios y vista de su portal."
        actions={<Link href={withParams(base, { nueva: "1" })} scroll={false} className="rounded-md bg-sky-700 px-3 py-2 text-sm font-medium text-white hover:bg-sky-800">Nueva agencia</Link>} />
      <B2bTabs active="admin" />
      <Kpis items={[
        { label: "Agencias activas", value: `${s.active} / ${s.total}` },
        { label: "Crédito otorgado", value: money(s.credit_granted, "PEN"), hint: `Utilizado ${money(s.credit_used, "PEN")}` },
        { label: "Ventas B2B", value: money(s.sales, "PEN"), hint: `${s.sales_count} reserva(s), equivalente en PEN` },
        { label: "Comisiones por liquidar", value: money(s.commissions_pending, "PEN") },
      ]} />
      {s.restricted.length > 0 && (
        <Notice tone="warning">
          <span className="font-medium">{s.restricted.length} agencia(s) con restricción:</span>
          <ul className="mt-1 list-disc pl-5">{s.restricted.map((r) => <li key={r.code}><Link href={withParams(base, { agencia: r.code })} scroll={false} className="underline">{r.name}</Link> — {r.reason}</li>)}</ul>
        </Notice>
      )}

      <form className="my-4 flex flex-wrap items-end gap-2" action="/canales/b2b/admin">
        <input name="q" defaultValue={sp.q} placeholder="Buscar por nombre, RUC o código" aria-label="Buscar agencia" className={`${inputCls} max-w-xs`} />
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Todos los estados</option>
          {Object.entries(meta.statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
        <select name="list" defaultValue={sp.list ?? ""} aria-label="Lista de precios" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Todas las listas</option>
          {meta.price_lists.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>

      <Table head={["Agencia", "RUC", "Lista", "Comisión", "Crédito", "Vigencia", "Estado", ""]} empty="No hay agencias con esos filtros">
        {rows.map((a) => (
          <tr key={a.id}>
            <Td><Link href={withParams(base, { agencia: a.code })} scroll={false} className="font-medium text-sky-800 hover:underline">{a.name}</Link><span className="block text-xs text-slate-500">{a.code} · {a.contact_name ?? "—"}</span></Td>
            <Td className="font-mono text-xs">{a.ruc ?? "—"}</Td>
            <Td>{a.price_list ?? "—"}</Td>
            <Td>{a.commission_pct} %</Td>
            <Td className="whitespace-nowrap">
              {Number(a.credit_limit) > 0 ? <>{money(a.credit_used, a.credit_currency)} / {money(a.credit_limit, a.credit_currency)}
                <span className="mt-1 block h-1.5 w-28 overflow-hidden rounded bg-slate-100" aria-hidden><span className={`block h-full ${(a.usage_pct ?? 0) > 100 ? "bg-rose-600" : "bg-sky-600"}`} style={{ width: `${Math.min(100, a.usage_pct ?? 0)}%` }} /></span>
              </> : <span className="text-slate-500">Solo prepago</span>}
            </Td>
            <Td className="whitespace-nowrap">{date(a.valid_to)}</Td>
            <Td><ChStatus value={a.effective_status} label={a.status_label} /></Td>
            <Td><RowAction href={withParams(base, { editar: a.code })}>Editar</RowAction></Td>
          </tr>
        ))}
      </Table>

      {sp.nueva && (
        <Drawer title="Nueva agencia" close={base}>
          <AgencyForm lists={lists} redirect={`/canales/b2b/admin?agencia={data.code}`} />
        </Drawer>
      )}
      {editing && (
        <Drawer title={`Editar ${editing.name}`} close={base}>
          <AgencyForm agency={editing} lists={lists} redirect={withParams(base, { agencia: editing.code })} />
        </Drawer>
      )}
      {open && (
        <Drawer title={open.name} close={base}>
          <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2"><ChStatus value={open.effective_status} label={open.status_label} /><span className="text-xs text-slate-500">{open.code}</span></div>
            {open.restriction && <Notice tone="warning">{open.restriction}</Notice>}
            <Dl rows={[["RUC", open.ruc], ["Contacto", open.contact_name], ["Correo de reservas", open.booking_email], ["Lista de precios", open.price_list],
              ["Comisión", `${open.commission_pct} %`], ["Línea de crédito", `${money(open.credit_limit, open.credit_currency)} · usado ${money(open.credit_used, open.credit_currency)} · disponible ${money(open.credit_available, open.credit_currency)}`],
              ["Vigencia", `${date(open.valid_from)} – ${date(open.valid_to)}`]]} />
            <div className="flex flex-wrap gap-2">
              <RowAction href={withParams(base, { editar: open.code })}>Editar</RowAction>
              <RowAction href={withParams(base, { agencia: open.code, accion: "libro" })}>Libro de crédito</RowAction>
              <RowAction href={withParams(base, { agencia: open.code, accion: open.status === "bloqueada" ? "desbloquear" : "bloquear" })}>{open.status === "bloqueada" ? "Desbloquear" : "Bloquear"}</RowAction>
              <ActionForm path={`/channels/b2b/agencies/${open.code}/impersonation`} submitLabel="Ver portal como esta agencia" inline
                submitClass="inline-flex rounded-md bg-indigo-700 px-2 py-1 text-xs font-medium text-white hover:bg-indigo-800" redirectTo={`/canales/b2b/portal/catalogo?agency=${open.code}`} />
            </div>
            {sp.accion === "bloquear" && (
              <ActionForm path={`/channels/b2b/agencies/${open.code}/block`} ifMatch={open.lock_version} submitLabel="Bloquear agencia" success="Agencia bloqueada: solo podrá reservar con prepago.">
                <TextArea name="reason" label="Motivo del bloqueo" required maxLength={300} />
              </ActionForm>
            )}
            {sp.accion === "desbloquear" && (
              <ActionForm path={`/channels/b2b/agencies/${open.code}/unblock`} ifMatch={open.lock_version} submitLabel="Desbloquear agencia" success="Agencia desbloqueada.">
                <Input name="reason" label="Nota (opcional)" maxLength={300} />
              </ActionForm>
            )}
            {moves && (
              <section>
                <h3 className="mb-2 text-sm font-semibold text-slate-900">Libro de crédito</h3>
                <Table head={["Fecha", "Movimiento", "Monto", "Saldo"]} empty="Sin movimientos">
                  {moves.map((m) => (
                    <tr key={m.id}><Td className="whitespace-nowrap text-xs">{dateTime(m.at)}</Td><Td>{KIND[m.kind] ?? m.kind}<span className="block text-xs text-slate-500">{[m.reservation, m.payment, m.note].filter(Boolean).join(" · ")}</span></Td>
                      <Td className="whitespace-nowrap">{money(m.amount, m.currency)}</Td><Td className="whitespace-nowrap">{money(m.balance_after, m.currency)}</Td></tr>
                  ))}
                </Table>
              </section>
            )}
            <section>
              <h3 className="mb-2 text-sm font-semibold text-slate-900">Tarifas asignadas</h3>
              {open.rates.length === 0 ? <p className="text-sm text-slate-500">Sin tarifas: la agencia no ve productos reservables (Regla 14).</p> : (
                <ul className="space-y-1 text-sm">{open.rates.map((r) => <li key={r.id}>{r.product} · {money(r.price, r.currency)} · {r.commission_pct} % {r.is_credit ? "· crédito" : "· prepago"} · hasta {date(r.valid_to)}{r.expired && <span className="ml-1 text-amber-800">(vencida)</span>}</li>)}</ul>
              )}
              <Link href="/canales/b2b/precios" className="mt-1 inline-block text-xs text-sky-800 hover:underline">Gestionar tarifas</Link>
            </section>
            <section>
              <h3 className="mb-2 text-sm font-semibold text-slate-900">Últimas reservas</h3>
              {open.reservations.length === 0 ? <p className="text-sm text-slate-500">Sin reservas B2B.</p> : (
                <ul className="space-y-1 text-sm">{open.reservations.map((r) => <li key={r.code}><Link href={`/reservas/${r.code}`} className="text-sky-800 hover:underline">{r.code}</Link> · {r.title} · {money(r.total, r.currency)} · <ChStatus value={r.status} label={r.status_label} /></li>)}</ul>
              )}
            </section>
          </div>
        </Drawer>
      )}
    </>
  );
}

function AgencyForm({ agency, lists, redirect }: { agency?: Agency; lists: { value: string; label: string }[]; redirect: string }) {
  return (
    <ActionForm path={agency ? `/channels/b2b/agencies/${agency.code}` : "/channels/b2b/agencies"} method={agency ? "PATCH" : "POST"} ifMatch={agency?.lock_version}
      submitLabel={agency ? "Guardar cambios" : "Crear agencia"} redirectTo={redirect}>
      <Input name="name" label="Razón social" required defaultValue={agency?.name} maxLength={150} />
      <div className="grid grid-cols-2 gap-3">
        <Input name="ruc" label="RUC" required defaultValue={agency?.ruc ?? ""} pattern="\d{11}" title="11 dígitos" inputMode="numeric" />
        <Input name="contact_name" label="Contacto" required defaultValue={agency?.contact_name ?? ""} />
      </div>
      <Input name="booking_email" type="email" label="Correo de reservas" required={!agency} defaultValue={agency?.booking_email ?? ""} />
      <div className="grid grid-cols-2 gap-3">
        <Select name="price_list_id" label="Lista de precios" options={lists} defaultValue={agency?.price_list_id} required placeholder="Elegir…" />
        <Input name="commission_pct" type="number" step="0.01" min="0" max="30" label="Comisión (%)" required defaultValue={agency?.commission_pct ?? "10"} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Select name="credit_currency" label="Moneda de la línea" options={[{ value: "PEN", label: "PEN" }, { value: "USD", label: "USD" }]} defaultValue={agency?.credit_currency ?? "PEN"} />
        <Input name="credit_limit" type="number" step="0.01" min="0" label="Límite de crédito" defaultValue={agency?.credit_limit ?? "0"} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Input name="valid_from" type="date" label="Vigencia desde" required defaultValue={agency?.valid_from ?? ""} />
        <Input name="valid_to" type="date" label="Vigencia hasta" required defaultValue={agency?.valid_to ?? ""} />
      </div>
      {agency && <Select name="status" label="Estado" options={[{ value: "activa", label: "Activa" }, { value: "bloqueada", label: "Bloqueada" }, { value: "inactiva", label: "Inactiva" }]} defaultValue={agency.status} />}
      <p className="text-xs text-slate-500">Si el crédito utilizado supera el nuevo límite, la agencia queda bloqueada automáticamente (B2B-BR-08).</p>
    </ActionForm>
  );
}
