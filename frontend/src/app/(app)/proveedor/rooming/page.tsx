import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { IMPACTS, OBSERVATION_KINDS, type RoomingCard } from "@/lib/supplier";
import { supplierMe } from "@/lib/supplier-server";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnGhostCls, Card, Drawer, PageHeader, Select, TextArea } from "@/components/crm/ui";
import { OpsStatus } from "@/components/ops/ui";
import { RowAction, SupStatus } from "@/components/supplier/ui";

/** Rooming lists del hotel (spec 06 §4.2): acuse de recibo, confirmación de habitaciones y observaciones por habitación. */
export default async function SupplierRoomingPage({ searchParams }: { searchParams: Promise<{ obs?: string }> }) {
  const { me } = await supplierMe();
  if (!me) return null;
  const sp = await searchParams;
  const { data: lists } = await api<{ data: RoomingCard[] }>("/supplier/rooming-lists");
  const can = me.permissions.includes("supplier.rooming.manage");
  const observing = sp.obs ? lists.flatMap((l) => l.rooms.map((r) => ({ list: l, room: r }))).find((x) => x.room.id === sp.obs) : undefined;

  return (
    <>
      <PageHeader title="Rooming lists" subtitle="Distribución de habitaciones enviada por la agencia. Acuse recibo y confirme; si algo no cuadra, observe la habitación." />
      {lists.length === 0 && <p className="rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">No hay rooming lists enviadas a su hotel.</p>}
      <div className="space-y-4">
        {lists.map((l) => (
          <Card key={l.id}
            title={<>{l.reservation_code} · {l.holder_label}{l.modified && <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900">Modificada · v{l.version}</span>}</>}
            actions={<SupStatus value={l.state} label={l.state_label} />}>
            <dl className="mb-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
              <div><dt className="inline text-slate-500">Check-in </dt><dd className="inline font-medium">{date(l.check_in)}</dd></div>
              <div><dt className="inline text-slate-500">Check-out </dt><dd className="inline font-medium">{date(l.check_out)}</dd></div>
              <div><dt className="inline text-slate-500">Noches </dt><dd className="inline font-medium">{l.nights}</dd></div>
              <div><dt className="inline text-slate-500">Habitaciones </dt><dd className="inline font-medium">{l.rooms_count}</dd></div>
              <div><dt className="inline text-slate-500">Huéspedes </dt><dd className="inline font-medium">{l.guests_count}</dd></div>
              <div><dd className="inline font-medium text-sky-800">{l.arrival}</dd></div>
            </dl>
            <ul className="divide-y divide-slate-100 rounded-md border border-slate-200">
              {l.rooms.map((r) => (
                <li key={r.id} className="flex flex-wrap items-start justify-between gap-2 p-3 text-sm">
                  <div className="min-w-0">
                    <p className="font-medium">Hab. {r.room_number} · {r.room_type} <OpsStatus value={r.status} label={r.status.charAt(0).toUpperCase() + r.status.slice(1)} /></p>
                    <p className="text-slate-600">{r.guests.join(", ") || "Sin huéspedes asignados"}</p>
                    {r.hotel_note && <p className="mt-1 text-xs text-amber-900">Observación: {r.hotel_note}</p>}
                  </div>
                  {can && r.status !== "observada" && !l.confirmed_at && <RowAction href={`/proveedor/rooming?obs=${r.id}`}>Reportar observación</RowAction>}
                </li>
              ))}
            </ul>
            <div className="mt-3 flex flex-wrap items-center gap-3 text-sm">
              {l.ack_at ? <span className="text-slate-600">Recibida el {dateTime(l.ack_at)}</span>
                : can && <ActionForm path={`/supplier/rooming-lists/${l.id}/acknowledge`} idempotent inline submitLabel="Acusar recibo" submitClass={btnGhostCls} success="Acuse registrado" />}
              {l.confirmed_at ? <span className="text-emerald-800">Confirmada por el hotel el {dateTime(l.confirmed_at)}</span>
                : can && <ActionForm path={`/supplier/rooming-lists/${l.id}/confirm`} idempotent inline ifMatch={l.lock_version} submitLabel="Confirmar habitaciones" success="Habitaciones confirmadas" />}
            </div>
          </Card>
        ))}
      </div>

      {observing && can && (
        <Drawer title={`Observación · hab. ${observing.room.room_number}`} close="/proveedor/rooming">
          <p className="mb-3 text-sm text-slate-600">{observing.list.reservation_code} · {observing.room.room_type} · {observing.room.guests.join(", ")}</p>
          <ActionForm path={`/supplier/rooming-rooms/${observing.room.id}/observations`} idempotent redirectTo="/proveedor/rooming" submitLabel="Enviar observación">
            <Select label="Motivo" name="kind" required placeholder="Elija" options={OBSERVATION_KINDS.map((x) => ({ value: x, label: x }))} />
            <Select label="Impacto" name="impact" required placeholder="Elija" options={IMPACTS.map((x) => ({ value: x.key, label: x.label }))} />
            <TextArea label="Propuesta del hotel" name="proposal" required maxLength={1000} placeholder="Ej. Podemos asignar la 210, del mismo tipo, en el segundo piso." />
            <p className="text-xs text-slate-500">Se crea una incidencia para Operaciones. Las demás habitaciones puede confirmarlas normalmente.</p>
          </ActionForm>
        </Drawer>
      )}
    </>
  );
}
