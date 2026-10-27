import { api } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import type { SupplierVoucher } from "@/lib/supplier";
import { supplierMe } from "@/lib/supplier-server";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnGhostCls, Card, Input, inputCls, PageHeader, Table, Td } from "@/components/crm/ui";
import { Check, Hidden } from "@/components/catalog/ui";
import { SupStatus } from "@/components/supplier/ui";

type Search = { status?: string; q?: string };

/** Vouchers a nombre del proveedor (spec 06 §4.3): validación en recepción y marca de utilizado. */
export default async function SupplierVouchersPage({ searchParams }: { searchParams: Promise<Search> }) {
  const { me } = await supplierMe();
  if (!me) return null;
  const sp = await searchParams;
  const { data: rows } = await api<{ data: SupplierVoucher[] }>(`/supplier/vouchers${qs({ "filter[status]": sp.status, q: sp.q })}`);
  const can = me.permissions.includes("supplier.vouchers.redeem");

  return (
    <>
      <PageHeader title="Vouchers" subtitle="Vouchers emitidos por la agencia a su nombre. Valide el código cuando el pasajero se presente." />
      {can && (
        <Card title="Validar voucher en recepción" className="mb-4">
          <ActionForm path="/supplier/vouchers/redeem" idempotent submitLabel="Validar y marcar utilizado" success="Voucher validado y marcado como utilizado"
            className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
            <Input label="Código del voucher" name="code" required placeholder="VOU-2026-00000" autoComplete="off" />
            <div className="sm:col-span-2"><Check name="confirm_future" label="El servicio es de mañana y el pasajero ya está en recepción (confirmo el uso anticipado)" /></div>
          </ActionForm>
        </Card>
      )}

      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <input name="q" defaultValue={sp.q} placeholder="Buscar por código, reserva o servicio" aria-label="Buscar" className={`${inputCls} max-w-sm`} />
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Estado: todos</option><option value="emitido">Emitido</option><option value="utilizado">Utilizado</option><option value="anulado">Anulado</option>
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Voucher", "Emisión", "Servicio", "Fecha", "Pax", "Estado", "Uso", ""]} empty="Sin vouchers con estos filtros">
        {rows.map((v) => (
          <tr key={v.id}>
            <Td className="whitespace-nowrap"><span className="font-mono font-medium">{v.code}</span><span className="block text-xs text-slate-500">v{v.version}</span></Td>
            <Td className="whitespace-nowrap">{dateTime(v.issued_at)}</Td>
            <Td>{v.service_label}<span className="block text-xs text-slate-500">{v.reservation_code}</span></Td>
            <Td className="whitespace-nowrap">{date(v.service_date)}</Td>
            <Td>{v.pax}</Td>
            <Td><SupStatus value={v.status} label={v.status_label} /></Td>
            <Td className="whitespace-nowrap">{v.used_at ? <>{dateTime(v.used_at)}{v.used_via && <span className="block text-xs text-slate-500">{v.used_via}</span>}</> : "—"}</Td>
            <Td>
              {can && v.status === "emitido" && v.service_date <= nextDay(me.today) && (
                <ActionForm path="/supplier/vouchers/redeem" idempotent inline ifMatch={v.lock_version} submitLabel="Marcar utilizado" submitClass={btnGhostCls}
                  success="Marcado como utilizado" confirm={v.service_date > me.today ? `El servicio es el ${date(v.service_date)}. ¿El pasajero ya está en recepción?` : undefined}>
                  <Hidden name="code" value={v.code} />
                  <Hidden name="confirm_future" value={v.service_date > me.today} />
                </ActionForm>
              )}
            </Td>
          </tr>
        ))}
      </Table>
    </>
  );
}

function nextDay(today: string) {
  const d = new Date(`${today}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + 1);
  return d.toISOString().slice(0, 10);
}
