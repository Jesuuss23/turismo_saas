import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import type { RoomingRow } from "@/lib/ops";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnCls, Drawer, inputCls, PageHeader, Table, Td } from "@/components/crm/ui";
import { LockedButton } from "@/components/quotes/ui";
import { OpsNav, OpsStatus } from "@/components/ops/ui";

type Search = { accion?: string; reserva?: string };
export type RoomingMeta = { can_modify: boolean; hotels: { id: string; name: string }[]; room_types: Record<string, number>; room_statuses: string[] };

const label = "mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500";

/** Rooming lists de todas las reservas (spec 05 §2) y alta de una nueva lista por hotel y estadía. */
export default async function RoomingIndexPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const { data: lists, meta } = await api<{ data: RoomingRow[]; meta: RoomingMeta }>(`/ops/rooming-lists${qs({ "filter[reservation]": sp.accion ? undefined : sp.reserva })}`);
  const close = "/operaciones/rooming";

  return (
    <>
      <PageHeader title="Rooming" subtitle="Distribución de pasajeros por habitación y envío al hotel"
        actions={meta.can_modify ? <Link href="/operaciones/rooming?accion=new" scroll={false} className={btnCls}>Nueva rooming list</Link> : <LockedButton label="Nueva rooming list" reason="Requiere permiso reservations.modify" />} />
      <OpsNav active="/operaciones/rooming" />
      <Table head={["Reserva", "Hotel", "Estadía", "Habitaciones", "Estado", "Envío al hotel"]} empty="Sin rooming lists">
        {lists.map((l) => (
          <tr key={l.id}>
            <Td><Link href={`/operaciones/rooming/${l.id}`} className="font-medium text-sky-800 hover:underline">{l.reservation?.code}</Link><span className="block text-xs text-slate-500">{l.reservation?.title}</span></Td>
            <Td>{l.hotel?.name}</Td>
            <Td>{date(l.check_in)} – {date(l.check_out)}</Td>
            <Td>{l.rooms_count}</Td>
            <Td><OpsStatus value={l.status} label={l.status_label} /></Td>
            <Td>{l.sent_at ? <>{dateTime(l.sent_at)} · v{l.sent_version}{l.hotel_confirmed_at ? <span className="block text-xs text-emerald-700">Confirmada por el hotel</span> : l.hotel_ack_at && <span className="block text-xs text-slate-500">Recibida por el hotel</span>}</> : "—"}</Td>
          </tr>
        ))}
      </Table>

      {sp.accion === "new" && (
        <Drawer title="Nueva rooming list" close={close}>
          {!meta.can_modify ? <p className="text-sm text-slate-600">Requiere permiso reservations.modify.</p> : (
            <ActionForm path="/ops/rooming-lists" redirectTo="/operaciones/rooming/{data.id}" submitLabel="Crear rooming list">
              <label className="block text-sm"><span className={label}>Reserva (código)</span>
                <input name="reservation_id" required defaultValue={sp.reserva} placeholder="RES-2026-000350" className={inputCls} /></label>
              <label className="block text-sm"><span className={label}>Hotel</span>
                <select name="hotel_provider_id" required className={inputCls}>{meta.hotels.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}</select></label>
              <div className="grid grid-cols-2 gap-3">
                <label className="block text-sm"><span className={label}>Check-in</span><input name="check_in" type="date" required className={inputCls} /></label>
                <label className="block text-sm"><span className={label}>Check-out</span><input name="check_out" type="date" required className={inputCls} /></label>
              </div>
              <p className="text-xs text-slate-500">Solo hoteles con contrato vigente; la estadía debe estar dentro del viaje.</p>
            </ActionForm>
          )}
        </Drawer>
      )}
    </>
  );
}
