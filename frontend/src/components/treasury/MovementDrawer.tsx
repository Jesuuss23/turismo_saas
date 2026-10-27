import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, Notice, btnGhostCls } from "@/components/crm/ui";
import { Dl } from "@/components/finance/ui";
import { amount, NEED_REGISTER, type MovementDetail, type TreasuryCan } from "@/lib/treasury";
import { Signed, SourceLink, StatusPill } from "./ui";

/** Detalle del movimiento (drawer `?mov=`): origen, comprobante, partidas de la transferencia y anulación (TES-BR-03/10). */
export async function MovementDrawer({ code, close, can }: { code: string; close: string; can: TreasuryCan }) {
  let m: MovementDetail | null = null;
  try {
    m = (await api<{ data: MovementDetail }>(`/treasury/movements/${encodeURIComponent(code)}`)).data;
  } catch (e) {
    if (!(e instanceof ApiError && e.status === 404)) throw e;
  }
  if (!m) return <Drawer title="Movimiento" close={close}><Notice tone="warning">El movimiento no existe.</Notice></Drawer>;
  return (
    <Drawer title={`Movimiento ${m.code}`} close={close}>
      <p className="mb-3 flex flex-wrap items-center gap-2 text-sm"><Signed m={m} /> <StatusPill status={m.status} /> <span className="text-slate-500">{m.type_label}</span></p>
      <Dl items={[
        ["Fecha", date(m.date)], ["Cuenta", <Link key="a" href={`/finanzas/tesoreria/cuentas/${m.account.code}`} className="text-sky-800 hover:underline">{m.account.code} · {m.account.name}</Link>],
        ["Concepto", m.description], ["Contraparte", m.counterparty], ["N° de operación", m.reference], ["Categoría", m.category],
        ["Origen", <SourceLink key="s" m={m} />], ["Reserva", m.reservation ? <Link key="r" href={`/reservas/${m.reservation}`} className="text-sky-800 hover:underline">{m.reservation}</Link> : null],
        ["Monto original", m.orig_amount && m.orig_currency ? `${amount(m.orig_amount, m.orig_currency)} · TC ${m.orig_fx_rate}` : null], ["TC", m.fx_rate],
        ["Registrado por", `${m.created_by ?? "Sistema"} · ${dateTime(m.created_at)}`], ["Reposición", m.replenishment], ["Depositado en", m.deposited],
      ]} />
      {m.synced && <Notice tone="info">Sincronizado desde Finanzas: se corrige en el documento de origen (TES-BR-03).</Notice>}
      {m.receipt && (
        <div className="mt-4 rounded-md border border-slate-200 p-3 text-sm">
          <p className="font-medium text-slate-800">Comprobante: {m.receipt.type_label}{m.receipt.series_number ? ` ${m.receipt.series_number}` : ""}</p>
          <p className="text-slate-600">{m.receipt.supplier_name}{m.receipt.issuer_ruc ? ` · RUC ${m.receipt.issuer_ruc}` : ""}</p>
          {m.receipt.file_name && <a href={`/bff/file?path=${encodeURIComponent(`/treasury/movements/${m.code}/receipt`)}`} target="_blank" className="text-sky-800 hover:underline">Ver archivo ({m.receipt.file_name})</a>}
        </div>
      )}
      {m.partidas.length > 0 && (
        <div className="mt-4">
          <h3 className="mb-1 text-sm font-semibold text-slate-800">Partidas</h3>
          <ul className="space-y-1 text-sm">{m.partidas.map((p) => <li key={p.code} className="flex justify-between gap-2"><span className="font-mono">{p.code} · {p.account}</span><Signed m={p} /></li>)}</ul>
        </div>
      )}
      {m.status === "voided" && <Notice tone="warning">Anulado por {m.voided_by ?? "—"} ({dateTime(m.voided_at)}): {m.void_reason}</Notice>}
      {m.can_void && (
        <div className="mt-5">
          <h3 className="mb-2 text-sm font-semibold text-slate-800">Anular</h3>
          {!can.register && <p className="mb-2 text-xs text-slate-500">{NEED_REGISTER}</p>}
          <ActionForm path={`/treasury/movements/${m.code}/void`} submitLabel="Anular movimiento" submitClass={btnGhostCls} success="Movimiento anulado" disabled={!can.register}
            disabledReason={NEED_REGISTER} confirm={m.partidas.length ? "Se anulan todas las partidas de la transferencia. ¿Continuar?" : undefined}>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Motivo</span>
              <input name="reason" required minLength={5} maxLength={255} disabled={!can.register} className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" /></label>
          </ActionForm>
        </div>
      )}
    </Drawer>
  );
}
