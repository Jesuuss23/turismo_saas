import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { amount, type Contact, type RateRequestRow, type SupplierContract, type SupplierRate } from "@/lib/supplier";
import { supplierMe } from "@/lib/supplier-server";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnGhostCls, Card, Drawer, Input, Notice, PageHeader, Select, Table, Td, TextArea } from "@/components/crm/ui";
import { Field, Hidden } from "@/components/catalog/ui";
import { RowAction, SupStatus } from "@/components/supplier/ui";

/** Mi contrato (spec 06 §2, §4.6): condiciones, contactos, tarifas netas y solicitudes de actualización. */
export default async function SupplierContractPage({ searchParams }: { searchParams: Promise<{ tarifa?: string }> }) {
  const { me } = await supplierMe();
  if (!me) return null;
  const sp = await searchParams;
  const [{ data: c }, rates, { data: history }] = await Promise.all([
    api<{ data: SupplierContract }>("/supplier/contract"),
    api<{ data: SupplierRate[]; meta: { reasons: string[]; notice_days: number } }>("/supplier/rates"),
    api<{ data: RateRequestRow[] }>("/supplier/rate-requests"),
  ]);
  const can = me.permissions.includes("supplier.rates.request");
  const editing = sp.tarifa && can ? rates.data.find((r) => r.id === sp.tarifa && r.can_request) : undefined;
  const minDate = addDays(me.today, rates.meta.notice_days);

  return (
    <>
      <PageHeader title="Mi contrato" subtitle={`Condiciones pactadas con ${me.agency}`} />
      {c.alert && <Notice tone={c.alert.tone}>{c.alert.message}</Notice>}

      <div className="mb-4 grid gap-4 lg:grid-cols-2">
        <Card title="Condiciones">
          <dl className="grid grid-cols-2 gap-3">
            <Field label="Razón social">{c.provider.name}</Field>
            <Field label="RUC">{c.provider.ruc}</Field>
            <Field label="Tipo">{c.provider.type}{c.provider.stars ? ` · ${"★".repeat(c.provider.stars)}` : ""}</Field>
            <Field label="Ciudad">{c.provider.city}</Field>
            <Field label="Vigencia">{date(c.provider.contract_start)} → {date(c.provider.contract_end)}</Field>
            <Field label="Estado"><SupStatus value={c.provider.status} label={c.provider.status.charAt(0).toUpperCase() + c.provider.status.slice(1)} /></Field>
            <Field label="Moneda">{c.provider.currency}</Field>
            <Field label="Condición de pago">{c.provider.payment_terms}</Field>
          </dl>
          <h3 className="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Facture a</h3>
          <p className="text-sm text-slate-800">{c.billing.legal_name} · RUC {c.billing.ruc}{c.billing.trade_name && <span className="block text-slate-500">{c.billing.trade_name}</span>}</p>
        </Card>
        <Card title="Contactos">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Su empresa</h3>
          <Contacts rows={c.contacts.provider} />
          <h3 className="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{me.agency}</h3>
          <Contacts rows={c.contacts.agency} />
        </Card>
      </div>

      <h2 className="mb-2 text-sm font-semibold text-slate-800">Tarifas netas contratadas</h2>
      <div className="mb-4">
        <Table head={["Servicio", "Unidad", "Tarifa", "Vigencia", "Estado", ""]} empty="Sin servicios contratados">
          {rates.data.map((r) => (
            <tr key={r.id}>
              <Td><span className="font-medium">{r.name}</span><span className="block text-xs text-slate-500">{r.ref} · {r.type}</span></Td>
              <Td>{r.unit}</Td>
              <Td className="whitespace-nowrap">{amount(r.cost, r.currency)}
                {r.next_rate && <span className="block text-xs text-emerald-800">{amount(r.next_rate.cost, r.next_rate.currency)} desde {date(r.next_rate.valid_from)}</span>}</Td>
              <Td className="whitespace-nowrap">{date(r.valid_from)} → {date(r.valid_to)}</Td>
              <Td><SupStatus value={r.status} label={r.status.charAt(0).toUpperCase() + r.status.slice(1)} /></Td>
              <Td>{r.pending_request ? <span className="whitespace-nowrap text-xs text-slate-600">Actualización enviada ({r.pending_request})</span>
                : can && r.can_request && <RowAction href={`/proveedor/contrato?tarifa=${r.id}`}>Actualizar tarifa</RowAction>}</Td>
            </tr>
          ))}
        </Table>
      </div>

      <h2 className="mb-2 text-sm font-semibold text-slate-800">Solicitudes de actualización</h2>
      <Table head={["Solicitud", "Servicio", "Actual → propuesta", "Desde", "Motivo", "Estado", ""]} empty="No ha enviado solicitudes de actualización">
        {history.map((h) => (
          <tr key={h.id}>
            <Td className="whitespace-nowrap"><span className="font-medium">{h.code}</span><span className="block text-xs text-slate-500">{dateTime(h.requested_at)}</span></Td>
            <Td>{h.service}</Td>
            <Td className="whitespace-nowrap">{amount(h.current_cost, h.currency)} → {amount(h.proposed_cost, h.currency)}</Td>
            <Td className="whitespace-nowrap">{date(h.effective_from)}</Td>
            <Td>{h.reason_type}{h.reason_detail && <span className="block text-xs text-slate-500">{h.reason_detail}</span>}</Td>
            <Td><SupStatus value={h.status} label={h.status_label} />{h.review_note && <span className="mt-1 block text-xs text-slate-600">{h.review_note}</span>}</Td>
            <Td>{can && h.status === "pendiente" && (
              <ActionForm path={`/supplier/rate-requests/${h.id}/withdraw`} inline submitLabel="Retirar" submitClass={btnGhostCls} confirm={`¿Retirar la solicitud ${h.code}?`} success="Solicitud retirada" />
            )}</Td>
          </tr>
        ))}
      </Table>

      {editing && (
        <Drawer title="Actualizar tarifa" close="/proveedor/contrato">
          <p className="mb-3 text-sm text-slate-600">{editing.name} · tarifa actual {amount(editing.cost, editing.currency)} por {editing.unit}</p>
          <ActionForm path="/supplier/rate-requests" idempotent redirectTo="/proveedor/contrato" submitLabel="Enviar solicitud">
            <Hidden name="service_id" value={editing.id} />
            <Input label={`Nueva tarifa neta (${editing.currency})`} name="proposed_cost" type="number" step="0.01" min="0.01" required />
            <Input label="Vigente desde" name="effective_from" type="date" min={minDate} defaultValue={minDate} required />
            <Select label="Motivo" name="reason_type" required placeholder="Elija" options={rates.meta.reasons.map((x) => ({ value: x, label: x }))} />
            <TextArea label="Detalle (opcional)" name="reason_detail" maxLength={500} />
            <p className="text-xs text-slate-500">Según contrato, la nueva tarifa requiere {rates.meta.notice_days} días de anticipación. Las reservas ya confirmadas conservan la tarifa pactada.</p>
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}

function Contacts({ rows }: { rows: Contact[] }) {
  return (
    <ul className="mt-1 space-y-2 text-sm">
      {rows.map((r) => (
        <li key={r.label + r.name}>
          <span className="font-medium text-slate-800">{r.label}</span>: {r.name ?? "—"}
          <span className="block break-all text-xs text-slate-500">{[r.email, r.phone].filter(Boolean).join(" · ")}</span>
        </li>
      ))}
    </ul>
  );
}

function addDays(today: string, n: number) {
  const d = new Date(`${today}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}
