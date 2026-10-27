import Link from "@/components/Link";
import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, inputCls, Notice, PageHeader } from "@/components/crm/ui";
import { OpsStatus } from "@/components/ops/ui";

type Candidate = { id: string; code: string; title: string; client: string | null; status: string; status_label: string; travel_from: string; pax: number; ok: boolean; reason: string | null };

/** Nueva orden operativa (PROC-031): solo reservas confirmadas o en operación; una orden por reserva (OPS-BR-02). */
export default async function NewOrderPage({ searchParams }: { searchParams: Promise<{ reserva?: string }> }) {
  const sp = await searchParams;
  const { data, meta } = await api<{ data: Candidate[]; meta: { coordinators: { id: string; name: string }[] } }>("/ops/orders/candidates");
  const selected = data.find((c) => c.code === sp.reserva);
  const usable = data.filter((c) => c.ok);

  return (
    <>
      <PageHeader title="Nueva orden operativa" actions={<Link href="/operaciones/ordenes" className="text-sm text-sky-800 hover:underline">← Órdenes</Link>} />
      {selected && !selected.ok && <Notice tone="warning">{selected.code}: {selected.reason}</Notice>}
      <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
        <Card title="Reserva">
          <ActionForm path="/ops/orders" idempotent redirectTo="/operaciones/ordenes/{data.code}" submitLabel="Crear orden" disabled={usable.length === 0}
            disabledReason="No hay reservas confirmadas sin orden">
            <label className="block">
              <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Reserva confirmada</span>
              <select name="reservation_id" required defaultValue={selected?.ok ? selected.id : ""} className={inputCls} aria-label="Reserva">
                <option value="" disabled>Seleccione…</option>
                {usable.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.title} · {c.client} · {date(c.travel_from)} · {c.pax} pax</option>)}
              </select>
            </label>
            <label className="block">
              <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Coordinador</span>
              <select name="coordinator_id" required className={inputCls} aria-label="Coordinador">
                {meta.coordinators.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
              </select>
            </label>
            <p className="text-sm text-slate-600">Se replican los servicios con proveedor que requieren guía, conductor o vehículo; se crean las 5 tareas del checklist y se avisa al coordinador por email.</p>
          </ActionForm>
        </Card>
        <Card title="Reservas sin orden">
          <ul className="space-y-2 text-sm">
            {data.map((c) => (
              <li key={c.id} className="flex items-center justify-between gap-2">
                <span>{c.code}<span className="block text-xs text-slate-500">{c.client} · {date(c.travel_from)}</span></span>
                {c.ok ? <OpsStatus value="confirmada" label={c.status_label} /> : <span title={c.reason ?? undefined}><OpsStatus value="cancelada" label={c.status_label} /></span>}
              </li>
            ))}
            {data.length === 0 && <li className="text-slate-500">Todas las reservas activas ya tienen orden.</li>}
          </ul>
        </Card>
      </div>
    </>
  );
}
