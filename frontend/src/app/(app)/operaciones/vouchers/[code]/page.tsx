import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import type { VoucherDetail } from "@/lib/ops";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnGhostCls, Card, Drawer, inputCls, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { Field } from "@/components/catalog/ui";
import { PrintButton } from "@/components/quotes/PrintButton";
import { OpsActionLink, OpsStatus } from "@/components/ops/ui";
import { VoucherDoc } from "@/components/ops/VoucherDoc";

type Search = { accion?: string; doc?: string };
const danger = "rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800 disabled:opacity-50";

/** Voucher (spec 05 §2): documento con QR, datos, versiones y notificaciones; reenviar, nueva versión, marcar utilizado y anular. */
export default async function VoucherPage({ params, searchParams }: { params: Promise<{ code: string }>; searchParams: Promise<Search> }) {
  const [{ code }, sp] = await Promise.all([params, searchParams]);
  const { data: v } = await api<{ data: VoucherDetail }>(`/ops/vouchers/${code}`);
  const here = `/operaciones/vouchers/${v.code}`;
  const doc = <VoucherDoc code={v.code} version={v.version} status={v.status} qr={v.qr_token} d={v.document} />;

  if (sp.doc) {
    return (
      <div className="mx-auto max-w-2xl space-y-4">
        <div className="flex justify-between print:hidden"><Link href={here} className="text-sm text-sky-800 hover:underline">← Volver al voucher</Link><PrintButton /></div>
        {doc}
      </div>
    );
  }

  const forms: Record<string, React.ReactNode> = {
    resend: (
      <ActionForm path={`/ops/vouchers/${v.code}/resend`} idempotent redirectTo={here} submitLabel="Reenviar">
        <p className="text-sm text-slate-600">Se reenvía la versión vigente al proveedor ({v.provider?.name}) y al pasajero.</p>
      </ActionForm>
    ),
    new_version: (
      <ActionForm path={`/ops/vouchers/${v.code}/versions`} ifMatch={v.lock_version} idempotent redirectTo={here} submitLabel="Emitir nueva versión">
        <p className="text-sm text-slate-600">Toma los datos actuales del servicio y la asignación; la versión anterior queda en el historial (OPS-BR-44).</p>
        <label className="block text-sm"><span className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Motivo</span><input name="reason" required maxLength={300} className={inputCls} /></label>
      </ActionForm>
    ),
    redeem: (
      <ActionForm path={`/ops/vouchers/${v.code}/redeem`} ifMatch={v.lock_version} idempotent redirectTo={here} submitLabel="Marcar utilizado">
        <p className="text-sm text-slate-600">Registra que el proveedor prestó el servicio con este voucher.</p>
      </ActionForm>
    ),
    void: (
      <ActionForm path={`/ops/vouchers/${v.code}/void`} ifMatch={v.lock_version} idempotent redirectTo={here} submitLabel="Anular voucher" submitClass={danger} confirm="¿Anular el voucher? Se avisa al proveedor y al pasajero.">
        <label className="block text-sm"><span className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Motivo</span><input name="reason" required maxLength={300} className={inputCls} /></label>
      </ActionForm>
    ),
  };

  return (
    <>
      <PageHeader title={`Voucher ${v.code}`} subtitle={<span className="flex items-center gap-2"><OpsStatus value={v.status} label={v.status_label} /> {v.service_label} · v{v.version}</span>}
        actions={<Link href="/operaciones/vouchers" className="text-sm text-sky-800 hover:underline">← Vouchers</Link>} />
      {!v.can_issue && <Notice>Su rol puede consultar el voucher pero no emitir, reenviar, versionar ni anular (requiere operations.vouchers).</Notice>}
      {v.void_reason && <Notice tone="danger">Anulado: {v.void_reason}</Notice>}
      <div className="mb-4 flex flex-wrap gap-2" aria-label="Acciones">
        <Link href={`${here}?doc=1`} className={btnGhostCls}>Descargar PDF</Link>
        {v.actions.map((a) => <OpsActionLink key={a.key} a={a} href={`${here}?accion=${a.key}`} />)}
      </div>
      <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
        {doc}
        <div className="space-y-4">
          <Card title="Datos">
            <dl className="grid grid-cols-2 gap-3">
              <Field label="Reserva">{v.reservation?.code ? <Link href={`/reservas/${v.reservation.code}`} className="text-sky-800 hover:underline">{v.reservation.code}</Link> : "—"}</Field>
              <Field label="Orden">{v.order ? <Link href={`/operaciones/ordenes/${v.order.code}/vouchers`} className="text-sky-800 hover:underline">{v.order.code}</Link> : "—"}</Field>
              <Field label="Fecha del servicio">{date(v.service_date)}</Field>
              <Field label="Emitido">{dateTime(v.issued_at)}</Field>
              <Field label="Utilizado">{v.used_at ? `${dateTime(v.used_at)}${v.used_via ? ` · ${v.used_via}` : ""}` : "—"}</Field>
              <Field label="Origen">{v.origin}</Field>
            </dl>
          </Card>
          <Card title="Versiones">
            <Table head={["Versión", "Emitida", "Por", "Motivo"]}>
              {v.versions.map((x) => <tr key={x.version}><Td>v{x.version}</Td><Td>{dateTime(x.issued_at)}</Td><Td>{x.issued_by?.name ?? "Sistema"}</Td><Td>{x.reason ?? "—"}</Td></tr>)}
            </Table>
          </Card>
          <Card title="Notificaciones">
            <Table head={["Evento", "Canal", "Destinatario", "Fecha"]} empty="Sin notificaciones">
              {v.notifications.map((n, i) => <tr key={i}><Td>{n.event}</Td><Td>{n.channel}</Td><Td>{n.recipient}</Td><Td>{dateTime(n.at)}</Td></tr>)}
            </Table>
          </Card>
        </div>
      </div>
      {sp.accion && forms[sp.accion] && v.actions.find((a) => a.key === sp.accion)?.enabled && (
        <Drawer title={v.actions.find((a) => a.key === sp.accion)!.label} close={here}>{forms[sp.accion]}</Drawer>
      )}
    </>
  );
}
