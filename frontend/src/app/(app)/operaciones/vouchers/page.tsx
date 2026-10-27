import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, qs } from "@/lib/crm";
import type { Rule, VoucherRow } from "@/lib/ops";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnGhostCls, Card, Drawer, inputCls, Kpis, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { Hidden } from "@/components/catalog/ui";
import { LockedButton } from "@/components/quotes/ui";
import { OpsNav, OpsStatus, RuleList } from "@/components/ops/ui";

type Search = { status?: string; provider?: string; q?: string; emitir?: string };
type Pending = { id: string; code: string; name: string; date: string; provider: string | null; reservation: string | null; status: string; confirmation_code: string | null };

/** Vouchers (spec 05 §2): KPIs, servicios confirmados pendientes de voucher y listado filtrable. */
export default async function VouchersPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const [list, { data: pending }] = await Promise.all([
    api<{ data: VoucherRow[]; meta: { kpis: { issued: number; used: number; voided: number; pending_issue: number }; can_issue: boolean } }>(
      `/ops/vouchers${qs({ "filter[status]": sp.status, "filter[provider]": sp.provider, q: sp.q })}`),
    api<{ data: Pending[] }>("/ops/vouchers/pending-issue"),
  ]);
  const k = list.meta.kpis;
  const can = list.meta.can_issue;
  const providers = [...new Map(list.data.filter((v) => v.provider).map((v) => [v.provider!.id, v.provider!.name])).entries()];
  const issuing = sp.emitir ? pending.find((s) => s.id === sp.emitir) : undefined;
  const check = issuing && can
    ? (await api<{ data: { blocks: Rule[]; warnings: Rule[] } }>("/ops/vouchers/check", { method: "POST", body: JSON.stringify({ reservation_service_id: issuing.id }) })).data
    : null;
  const close = `/operaciones/vouchers${qs({ status: sp.status, provider: sp.provider, q: sp.q })}`;

  return (
    <>
      <PageHeader title="Vouchers" subtitle="Documentos de servicio para proveedores y pasajeros" />
      <OpsNav active="/operaciones/vouchers" />
      {!can && <Notice>Su rol puede consultar el voucher pero no emitir, reenviar, versionar ni anular (requiere operations.vouchers).</Notice>}
      <Kpis items={[
        { label: "Vigentes", value: k.issued }, { label: "Utilizados", value: k.used }, { label: "Anulados", value: k.voided },
        { label: "Confirmados sin voucher", value: k.pending_issue },
      ]} />

      <Card title={`Pendientes de voucher (${pending.length})`} className="mb-4">
        <Table head={["Servicio", "Fecha", "Reserva", "Proveedor", "Confirmación", ""]} empty="Todos los servicios confirmados tienen voucher">
          {pending.map((s) => (
            <tr key={s.id}>
              <Td><span className="font-medium">{s.name}</span><span className="block text-xs text-slate-500">{s.code}</span></Td>
              <Td>{date(s.date)}</Td><Td>{s.reservation}</Td><Td>{s.provider}</Td><Td>{s.confirmation_code ?? <span className="text-amber-800">Sin código</span>}</Td>
              <Td>{can ? <Link href={`${close}${close.includes("?") ? "&" : "?"}emitir=${s.id}`} scroll={false} className="text-sm font-medium text-sky-800 hover:underline">Emitir</Link>
                : <LockedButton label="Emitir" reason="Requiere permiso operations.vouchers" />}</Td>
            </tr>
          ))}
        </Table>
      </Card>

      <form className="mb-4 flex flex-wrap gap-2" role="search">
        <input name="q" defaultValue={sp.q} placeholder="Buscar por voucher o servicio" aria-label="Buscar" className={`${inputCls} max-w-sm`} />
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Estado: todos</option><option value="emitido">Emitido</option><option value="utilizado">Utilizado</option><option value="anulado">Anulado</option>
        </select>
        <select name="provider" defaultValue={sp.provider ?? ""} aria-label="Proveedor" className={`${inputCls} max-w-[14rem]`}>
          <option value="">Proveedor: todos</option>{providers.map(([id, name]) => <option key={id} value={id}>{name}</option>)}
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Voucher", "Servicio", "Fecha", "Reserva", "Proveedor", "Pax", "Estado", "Versión"]} empty="Sin vouchers con estos filtros">
        {list.data.map((v) => (
          <tr key={v.id}>
            <Td><Link href={`/operaciones/vouchers/${v.code}`} className="font-medium text-sky-800 hover:underline">{v.code}</Link></Td>
            <Td>{v.service_label}</Td><Td>{date(v.service_date)}</Td>
            <Td>{v.reservation?.code}<span className="block text-xs text-slate-500">{v.reservation?.client}</span></Td>
            <Td>{v.provider?.name}</Td><Td>{v.pax}</Td>
            <Td><OpsStatus value={v.status} label={v.status_label} /></Td><Td>v{v.version}</Td>
          </tr>
        ))}
      </Table>

      {issuing && check && (
        <Drawer title="Emitir voucher" close={close}>
          <ActionForm path="/ops/vouchers" idempotent redirectTo="/operaciones/vouchers/{data.code}" submitLabel="Emitir voucher" disabled={check.blocks.length > 0} disabledReason="Resuelva los bloqueos">
            <p className="text-sm text-slate-600">{issuing.code} · {issuing.name} · {date(issuing.date)} · {issuing.provider}</p>
            <RuleList blocks={check.blocks} warnings={check.warnings} />
            <Hidden name="reservation_service_id" value={issuing.id} />
            <label className="block text-sm"><span className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Nota (opcional)</span><textarea name="note" rows={2} maxLength={500} className={inputCls} /></label>
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}
