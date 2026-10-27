import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import type { RoomingDetail } from "@/lib/ops";
import { ActionForm } from "@/components/crm/ActionForm";
import { btnCls, btnGhostCls, Card, Drawer, inputCls, Notice, PageHeader, Table, Td } from "@/components/crm/ui";
import { Field, Meter } from "@/components/catalog/ui";
import { LockedButton } from "@/components/quotes/ui";
import { OpsNav, OpsStatus, RuleList } from "@/components/ops/ui";
import { PrintButton } from "@/components/quotes/PrintButton";
import type { RoomingMeta } from "../page";

type Search = { accion?: string; room?: string; hotel?: string };
type RoomingDocument = { title: string; hotel: string | null; check_in: string; check_out: string; version: number; rooms: { room_number: string; room_type: string; guests: string[]; note: string | null }[] };

const label = "mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500";

/** Rooming de una reserva (spec 05 §2): pills por hotel, habitaciones con ocupación, validación, vista previa, confirmar y enviar. */
export default async function RoomingPage({ params, searchParams }: { params: Promise<{ key: string }>; searchParams: Promise<Search> }) {
  const [{ key }, sp] = await Promise.all([params, searchParams]);
  const { data: l } = await api<{ data: RoomingDetail }>(`/ops/rooming-lists/${key}${sp.hotel ? `?hotel=${sp.hotel}` : ""}`);
  const { meta } = await api<{ meta: RoomingMeta }>(`/ops/rooming-lists?filter[reservation]=${l.reservation?.code}`);
  const here = `/operaciones/rooming/${l.id}`;
  const can = l.can_modify;
  const assigned = new Set(l.rooms.flatMap((r) => r.guests.map((g) => g.passenger_id)));
  const unassigned = l.passengers.filter((p) => !assigned.has(p.id));
  const room = sp.room ? l.rooms.find((r) => r.id === sp.room) : undefined;
  const blocked = l.validation.conflicts.length > 0;
  const doc = sp.accion === "preview" ? (await api<{ data: RoomingDocument }>(`/ops/rooming-lists/${l.id}/document`)).data : null;

  const roomForm = (r?: RoomingDetail["rooms"][number]) => (
    <ActionForm path={r ? `/ops/rooming-rooms/${r.id}` : `/ops/rooming-lists/${l.id}/rooms`} method={r ? "PUT" : "POST"} ifMatch={l.lock_version} redirectTo={here}
      submitLabel={r ? "Guardar habitación" : "Agregar habitación"}>
      <div className="grid grid-cols-2 gap-3">
        <label className="block text-sm"><span className={label}>Número</span><input name="room_number" required maxLength={20} defaultValue={r?.room_number} className={inputCls} /></label>
        <label className="block text-sm"><span className={label}>Tipo</span>
          <select name="room_type" required defaultValue={r?.room_type ?? "Doble"} className={inputCls}>{Object.entries(meta.room_types).map(([t, c]) => <option key={t} value={t}>{t} ({c})</option>)}</select></label>
      </div>
      <label className="block text-sm"><span className={label}>Estado con el hotel</span>
        <select name="status" defaultValue={r?.status ?? "pendiente"} className={inputCls}>{meta.room_statuses.map((s) => <option key={s}>{s}</option>)}</select></label>
      <fieldset className="text-sm">
        <legend className={label}>Huéspedes</legend>
        <div className="space-y-1">
          {l.passengers.map((p) => {
            const inRoom = r?.guests.some((g) => g.passenger_id === p.id) ?? false;
            const elsewhere = !inRoom && assigned.has(p.id);
            return (
              <label key={p.id} className="flex items-center gap-2">
                <input type="checkbox" name="passenger_ids[]" value={p.id} defaultChecked={inRoom} className="h-4 w-4 rounded border-slate-300" />
                {p.name} <span className="text-xs text-slate-500">{p.type}{elsewhere && " · ya en otra habitación"}</span>
              </label>
            );
          })}
        </div>
      </fieldset>
      <p className="text-xs text-slate-500">Los infantes no ocupan cama; la ocupación no puede superar la capacidad (OPS-BR-32).</p>
    </ActionForm>
  );

  return (
    <>
      <PageHeader title={`Rooming · ${l.reservation?.code}`} subtitle={l.reservation?.title}
        actions={<Link href="/operaciones/rooming" className="text-sm text-sky-800 hover:underline">← Rooming</Link>} />
      <OpsNav active="/operaciones/rooming" />
      {l.lists.length > 1 && (
        <nav className="mb-3 flex flex-wrap gap-2 text-sm" aria-label="Rooming lists de la reserva">
          {l.lists.map((x) => (
            <Link key={x.id} href={`/operaciones/rooming/${x.id}`} aria-current={x.id === l.id ? "page" : undefined}
              className={`rounded-full px-3 py-1 ring-1 ${x.id === l.id ? "bg-sky-700 text-white ring-sky-700" : "bg-white text-slate-700 ring-slate-300 hover:bg-slate-50"}`}>{x.hotel} · {date(x.check_in)}</Link>
          ))}
        </nav>
      )}

      <dl className="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-5">
        <Field label="Hotel">{l.hotel?.name}</Field>
        <Field label="Estadía">{date(l.check_in)} – {date(l.check_out)}</Field>
        <Field label="Estado"><OpsStatus value={l.status} label={l.status_label} /></Field>
        <Field label="Habitaciones">{l.rooms.length}</Field>
        <Field label="Enviada">{l.sent_at ? `${dateTime(l.sent_at)} · v${l.sent_version}` : "No enviada"}</Field>
      </dl>

      {l.status === "modificada" && <Notice tone="warning">La lista cambió después del envío: confirme y reenvíe al hotel (OPS-BR-35).</Notice>}
      <div className="mb-4"><RuleList blocks={l.validation.conflicts} warnings={l.validation.warnings} /></div>
      {unassigned.length > 0 && <Notice>Sin habitación: {unassigned.map((p) => p.name).join(", ")}.</Notice>}

      <div className="mb-4 flex flex-wrap gap-2" aria-label="Acciones">
        <Link href={`${here}?accion=preview`} scroll={false} className={btnGhostCls}>Vista previa</Link>
        {can ? (
          <>
            <Link href={`${here}?accion=room`} scroll={false} className={btnGhostCls}>Agregar habitación</Link>
            {["borrador", "modificada"].includes(l.status) && (
              <ActionForm path={`/ops/rooming-lists/${l.id}/confirm`} ifMatch={l.lock_version} inline submitLabel="Confirmar" disabled={blocked} disabledReason="Resuelva los conflictos" />
            )}
            {["confirmada", "enviada"].includes(l.status) && (
              <ActionForm path={`/ops/rooming-lists/${l.id}/send`} ifMatch={l.lock_version} idempotent inline submitLabel={l.status === "enviada" ? "Reenviar al hotel" : "Enviar al hotel"}
                confirm="¿Enviar la rooming list al hotel por correo?" />
            )}
            <Link href={`/operaciones/rooming?accion=new&reserva=${l.reservation?.code}`} className={btnGhostCls}>Nueva rooming list</Link>
          </>
        ) : (
          <>
            <LockedButton label="Agregar habitación" reason="Requiere permiso reservations.modify" />
            <LockedButton label="Enviar al hotel" reason="Requiere permiso reservations.modify" />
          </>
        )}
      </div>

      <Card title="Habitaciones">
        <Table head={["Habitación", "Tipo", "Huéspedes", "Ocupación", "Estado", ""]} empty="Agregue la primera habitación">
          {l.rooms.map((r) => (
            <tr key={r.id}>
              <Td><span className="font-medium">{r.room_number}</span>{r.hotel_note && <span className="block text-xs text-slate-500">{r.hotel_note}</span>}</Td>
              <Td>{r.room_type}</Td>
              <Td>{r.guests.map((g) => g.name).join(", ") || "—"}{r.infants > 0 && <span className="block text-xs text-slate-500">{r.infants} infante(s) sin cama</span>}</Td>
              <Td><div className="w-24"><Meter value={r.occupancy} max={r.capacity} tone={r.occupancy > r.capacity ? "rose" : "sky"} /><span className="text-xs text-slate-500">{r.occupancy}/{r.capacity}</span></div></Td>
              <Td><OpsStatus value={r.status === "confirmada" ? "confirmado" : r.status === "observada" ? "rechazado" : "pendiente"} label={r.status} /></Td>
              <Td>{can && (
                <span className="flex gap-2 text-xs">
                  <Link href={`${here}?accion=room&room=${r.id}`} scroll={false} className="text-sky-800 hover:underline">Editar</Link>
                  <ActionForm path={`/ops/rooming-rooms/${r.id}`} method="DELETE" ifMatch={l.lock_version} inline submitLabel="Eliminar" confirm={`¿Eliminar la habitación ${r.room_number}?`} submitClass="text-xs text-rose-700 hover:underline" />
                </span>
              )}</Td>
            </tr>
          ))}
        </Table>
      </Card>

      {sp.accion === "room" && can && <Drawer title={room ? `Habitación ${room.room_number}` : "Agregar habitación"} close={here}>{roomForm(room)}</Drawer>}
      {doc && (
        <Drawer title="Vista previa para el hotel" close={here}>
          <article className="space-y-3 text-sm print:text-xs">
            <header>
              <h3 className="text-base font-semibold">{doc.title}</h3>
              <p className="text-slate-600">{doc.hotel} · {date(doc.check_in)} – {date(doc.check_out)} · versión {doc.version}</p>
            </header>
            <Table head={["Hab.", "Tipo", "Huéspedes", "Nota"]}>
              {doc.rooms.map((r) => <tr key={r.room_number}><Td>{r.room_number}</Td><Td>{r.room_type}</Td><Td>{r.guests.join(", ")}</Td><Td>{r.note ?? ""}</Td></tr>)}
            </Table>
          </article>
          <div className="mt-4 flex gap-2"><PrintButton /><Link href={here} className={btnCls}>Cerrar</Link></div>
        </Drawer>
      )}
    </>
  );
}
