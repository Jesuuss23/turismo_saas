import Link from "@/components/Link";
import { api } from "@/lib/api";
import { qs } from "@/lib/crm";
import { limaToday } from "@/lib/ops";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, inputCls } from "@/components/crm/ui";
import { MovementsTable, TreasuryHeader } from "@/components/treasury/ui";
import { MovementDrawer } from "@/components/treasury/MovementDrawer";
import { TransferForm } from "@/components/treasury/forms";
import { NEED_REGISTER, type AccountOption, type Movement, type TreasuryCan } from "@/lib/treasury";

type Params = { account?: string; type?: string; direction?: string; status?: string; from?: string; to?: string; q?: string; page?: string; mov?: string; nuevo?: string };
type Listing = { data: Movement[]; meta: { total: number; page: number; per_page: number }; options: { types: Record<string, string>; accounts: AccountOption[]; can: TreasuryCan } };

/** Libro de movimientos (§2): filtros, origen enlazado, detalle en drawer, alta manual y transferencias. */
export default async function MovementsPage({ searchParams }: { searchParams: Promise<Params> }) {
  const sp = await searchParams;
  const filters = { account: sp.account, type: sp.type, direction: sp.direction, status: sp.status };
  const query = new URLSearchParams();
  Object.entries(filters).forEach(([k, v]) => v && query.set(`filter[${k}]`, v));
  ["from", "to", "q", "page"].forEach((k) => sp[k as keyof Params] && query.set(k, String(sp[k as keyof Params])));
  const res = await api<Listing>(`/treasury/movements?${query.toString()}`);
  const { can, accounts, types } = res.options;
  const base = `/finanzas/tesoreria/movimientos${qs({ ...filters, from: sp.from, to: sp.to, q: sp.q, page: sp.page })}`;
  const pages = Math.max(1, Math.ceil(res.meta.total / res.meta.per_page));
  const today = limaToday();

  return (
    <>
      <TreasuryHeader active="movimientos" actions={
        <>
          <Link href={`/finanzas/tesoreria/movimientos${qs({ ...filters, nuevo: "movimiento" })}`} aria-disabled={!can.register} title={can.register ? undefined : NEED_REGISTER}
            className={`rounded-md px-3 py-2 text-sm font-medium ${can.register ? "bg-sky-700 text-white hover:bg-sky-800" : "pointer-events-none bg-slate-200 text-slate-500"}`}>Nuevo movimiento</Link>
          <Link href={`/finanzas/tesoreria/movimientos${qs({ ...filters, nuevo: "transferencia" })}`} aria-disabled={!can.register} title={can.register ? undefined : NEED_REGISTER}
            className={`rounded-md border px-3 py-2 text-sm font-medium ${can.register ? "border-slate-300 bg-white text-slate-700 hover:bg-slate-50" : "pointer-events-none border-slate-200 bg-slate-100 text-slate-400"}`}>Transferencia</Link>
        </>
      } />
      {!can.register && <p className="mb-4 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700 ring-1 ring-slate-200" role="note">Modo consulta: registrar movimientos y transferencias corresponde a Finanzas ({NEED_REGISTER}).</p>}

      {sp.nuevo === "movimiento" && can.register && (
        <Card title="Otro ingreso o egreso" className="mb-4" actions={<Link href={`/finanzas/tesoreria/movimientos${qs(filters)}`} className="text-sm text-slate-600 hover:underline">Cerrar</Link>}>
          <ActionForm path="/treasury/movements" idempotent submitLabel="Registrar movimiento" success="Movimiento registrado" className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Cuenta</span>
              <select name="account_id" required className={inputCls}>{accounts.filter((a) => a.kind !== "gateway").map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name} ({a.currency})</option>)}</select></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Sentido</span>
              <select name="direction" required className={inputCls}><option value="in">Ingreso</option><option value="out">Egreso</option></select></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Monto</span><input name="amount" type="number" step="0.01" min="0.01" required className={inputCls} /></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Fecha</span><input name="movement_date" type="date" defaultValue={today} max={today} required className={inputCls} /></label>
            <label className="block text-sm sm:col-span-2"><span className="mb-1 block font-medium text-slate-700">Descripción</span><input name="description" required maxLength={255} placeholder="Comisión de mantenimiento, intereses…" className={inputCls} /></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Categoría</span><input name="category" maxLength={60} className={inputCls} /></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">N° de operación</span><input name="reference" maxLength={80} className={inputCls} /></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">TC (solo cuentas USD)</span><input name="fx_rate" type="number" step="0.0001" min="0" className={inputCls} /></label>
          </ActionForm>
        </Card>
      )}
      {sp.nuevo === "transferencia" && can.register && (
        <Card title="Transferencia entre cuentas" className="mb-4" actions={<Link href={`/finanzas/tesoreria/movimientos${qs(filters)}`} className="text-sm text-slate-600 hover:underline">Cerrar</Link>}>
          <TransferForm accounts={accounts} today={today} />
        </Card>
      )}

      <form className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-8" aria-label="Filtros">
        <select name="account" defaultValue={sp.account ?? ""} aria-label="Cuenta" className={`${inputCls} col-span-2`}>
          <option value="">Todas las cuentas</option>{accounts.map((a) => <option key={a.id} value={a.code}>{a.code} · {a.name}</option>)}
        </select>
        <select name="type" defaultValue={sp.type ?? ""} aria-label="Tipo" className={inputCls}>
          <option value="">Todos los tipos</option>{Object.entries(types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
        <select name="direction" defaultValue={sp.direction ?? ""} aria-label="Sentido" className={inputCls}><option value="">Ingresos y egresos</option><option value="in">Ingresos</option><option value="out">Egresos</option></select>
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={inputCls}><option value="">Todos</option><option value="posted">Registrados</option><option value="voided">Anulados</option></select>
        <input name="from" type="date" defaultValue={sp.from} aria-label="Desde" className={inputCls} />
        <input name="to" type="date" defaultValue={sp.to} aria-label="Hasta" className={inputCls} />
        <div className="col-span-2 flex gap-2 sm:col-span-4 lg:col-span-8">
          <input name="q" defaultValue={sp.q} placeholder="Código, glosa, N° de operación u origen" aria-label="Buscar" className={inputCls} />
          <button className="rounded-md bg-sky-700 px-3 py-2 text-sm font-medium text-white">Filtrar</button>
        </div>
      </form>
      <p className="mb-2 text-sm text-slate-600">{res.meta.total} movimiento(s)</p>
      <MovementsTable rows={res.data} detailBase={base} />
      {pages > 1 && (
        <nav className="mt-3 flex flex-wrap gap-2 text-sm" aria-label="Páginas">
          {Array.from({ length: pages }, (_, i) => i + 1).map((p) => (
            <Link key={p} href={`/finanzas/tesoreria/movimientos${qs({ ...filters, from: sp.from, to: sp.to, q: sp.q, page: String(p) })}`}
              className={`rounded px-2 py-1 ${p === res.meta.page ? "bg-sky-700 text-white" : "bg-white ring-1 ring-slate-200"}`}>{p}</Link>
          ))}
        </nav>
      )}
      {sp.mov && <MovementDrawer code={sp.mov} close={base} can={can} />}
    </>
  );
}
