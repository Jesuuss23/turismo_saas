import Link from "@/components/Link";
import { api } from "@/lib/api";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Notice, PageHeader, Table, Td, btnGhostCls, inputCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { LineChart } from "@/components/experience/LineChart";
import { dateTime } from "@/lib/crm";
import type { CurrencyList, FxPoint } from "@/lib/experience";

const small = "rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50";
const ADMIN = "Requiere permiso admin.settings.manage";
const PROVIDER_TONE: Record<string, "green" | "amber" | "slate"> = { activo: "green", por_validar: "amber", inactivo: "slate" };

type Search = { moneda?: string; monto?: string; de?: string; a?: string };

/**
 * Monedas y tipos de cambio (spec 17 §2, v2.2 DEMO): proveedores, historial de 30 días, umbral de alerta, TC por moneda con margen al
 * cliente y conversor. Finanzas, Contabilidad y Manager (`finance.treasury`) solo editan el TC; activar, cobro y margen son del admin.
 */
export default async function CurrenciesPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const { data: fx, meta } = await api<{ data: CurrencyList; meta: { can_manage: boolean } }>("/finance/currencies");
  const admin = meta.can_manage;
  const foreign = fx.items.filter((c) => !c.is_base);
  const code = foreign.some((c) => c.code === sp.moneda) ? sp.moneda! : "USD";
  const { data: history } = await api<{ data: FxPoint[] }>(`/finance/currencies/${code}/rates?days=30`);
  const amount = sp.monto && Number(sp.monto) > 0 ? sp.monto : "100";
  const from = sp.de ?? "PEN";
  const to = sp.a ?? "USD";
  const conv = await api<{ data: { amount: string; from: string; to: string; result: string; result_with_margin: string; margin_pct: number } }>(
    `/finance/currencies/convert?${new URLSearchParams({ amount, from, to })}`,
  ).then((r) => r.data).catch(() => null);
  const active = fx.items.filter((c) => c.active);
  const current = fx.providers.find((p) => p.is_current);

  return (
    <section>
      <PageHeader
        title="Tipos de cambio de demostración"
        subtitle={<>Monedas que ve el cliente en el sitio y el widget, y el TC (soles por unidad) que usa el motor. <Pill tone="amber">Demo, editable</Pill> Este entorno no consulta proveedores reales: el simulador genera una variación de ±0,6 % sobre el último valor.</>}
        actions={<ActionForm path="/finance/currencies/refresh" submitLabel="Actualizar ahora desde proveedor (demo)" submitClass={btnGhostCls} success="Tipos de cambio actualizados" inline />}
      />
      {!fx.multicurrency_on && (
        <Notice tone="warning">La regla «Varias monedas» (R-MULTICURRENCY) está desactivada: el cliente solo verá soles y la moneda del tarifario. <Link href="/admin/reglas" className="underline">Centro de reglas</Link></Notice>
      )}
      {fx.alerts.length > 0 && (
        <Notice tone="warning">
          Variación diaria sobre el umbral de {fx.settings.fx_alert_pct} %: {fx.alerts.map((a) => `${a.currency} ${a.date} (${a.change_pct > 0 ? "+" : ""}${a.change_pct.toFixed(2)} %)`).join(" · ")}.
        </Notice>
      )}

      <div className="grid grid-cols-1 gap-4 [&>*]:min-w-0 xl:grid-cols-2">
        <Card title="Proveedores de tipo de cambio">
          <ul className="divide-y divide-slate-100 text-sm">
            {fx.providers.map((p) => (
              <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-2" data-provider={p.code}>
                <span className="min-w-0">
                  <span className="font-medium">{p.name}</span> {p.is_current && <Pill tone="sky">Proveedor activo</Pill>}
                  <span className="block text-xs text-slate-500">{p.kind} · {p.schedule_cron ? `cron ${p.schedule_cron}` : "manual"}{p.last_run_at ? ` · última ejecución ${dateTime(p.last_run_at)}` : ""}</span>
                  {p.note && <span className="block text-xs text-slate-500">{p.note}</span>}
                  {p.last_error && <span className="block text-xs text-rose-700">{p.last_error}</span>}
                </span>
                <span className="flex items-center gap-2">
                  <Pill tone={PROVIDER_TONE[p.status] ?? "slate"}>{p.status.replace("_", " ")}</Pill>
                  {p.status === "por_validar" && (
                    <ActionForm path={`/finance/fx-providers/${p.code}/test`} submitLabel="Probar conexión (demo)" submitClass={small} inline disabled={!admin} disabledReason={ADMIN} />
                  )}
                </span>
              </li>
            ))}
          </ul>
        </Card>

        <Card title="Ajustes">
          <ActionForm path="/finance/fx-settings" method="PATCH" submitLabel="Guardar ajustes" success="Ajustes guardados" className="grid gap-3 text-sm sm:grid-cols-3" disabled={!admin} disabledReason={ADMIN}>
            <label className="block sm:col-span-3"><span className="mb-1 block font-medium text-slate-700">Proveedor activo (solo los «activo»)</span>
              <select name="rate_provider_id" defaultValue={current?.id ?? ""} disabled={!admin} className={inputCls}>
                {fx.providers.filter((p) => p.status === "activo").map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </label>
            <label className="block"><span className="mb-1 block font-medium text-slate-700">Umbral de alerta (%)</span>
              <input type="number" name="fx_alert_pct" min={0.1} max={20} step={0.1} defaultValue={fx.settings.fx_alert_pct} disabled={!admin} className={inputCls} />
            </label>
            <label className="block"><span className="mb-1 block font-medium text-slate-700">Margen de conversión (%)</span>
              <input type="number" name="fx_margin_pct" min={0} max={15} step={0.1} defaultValue={fx.settings.fx_margin_pct} disabled={!admin} className={inputCls} />
            </label>
            <p className="text-xs text-slate-500 sm:col-span-1">Fuente: {fx.settings.rate_source ?? "—"}{fx.settings.rate_updated_at ? ` · actualizado ${dateTime(fx.settings.rate_updated_at)}` : ""}. TC al cliente = TC × (1 − margen).</p>
          </ActionForm>
        </Card>
      </div>

      <div className="mt-4">
        <Table head={["Moneda", "TC (S/ por unidad)", "TC al cliente", "S/ 100 equivalen a", "Activa", "Moneda de cobro", ""]}>
          {fx.items.map((c) => (
            <tr key={c.code} data-currency={c.code}>
              <Td><span className="font-medium">{c.code}</span> <span className="text-slate-500">{c.symbol}</span><span className="block text-xs text-slate-500">{c.name}</span></Td>
              <Td>
                {c.is_base ? <span>1 (base)</span> : (
                  <ActionForm path={`/finance/currencies/${c.code}`} method="PATCH" ifMatch={c.lock_version} submitLabel="Guardar" submitClass={small} success={`TC de ${c.code} guardado`} className="flex flex-wrap items-center gap-1">
                    <input name="rate_pen" type="number" step="0.000001" min="0.000001" defaultValue={Number(c.rate_pen)} aria-label={`TC ${c.code}`} className="w-28 rounded border border-slate-300 px-1 py-0.5 text-sm" />
                    <label className="flex items-center gap-1 text-[11px] text-slate-600" title="FX-01: confirma una variación mayor al umbral"><input type="checkbox" name="confirm" value="true" />confirmar variación</label>
                  </ActionForm>
                )}
              </Td>
              <Td>{Number(c.client_rate).toFixed(c.client_rate.startsWith("0.00") ? 6 : 4)}</Td>
              <Td>{c.symbol} {Number(c.per_100_pen).toLocaleString("es-PE", { maximumFractionDigits: 2 })}</Td>
              <Td>
                <ActionForm path={`/finance/currencies/${c.code}`} method="PATCH" ifMatch={c.lock_version} submitLabel={c.active ? "Desactivar" : "Activar"} submitClass={small} inline
                  success={c.active ? `${c.code} desactivada` : `${c.code} activada`} disabled={!admin || c.is_base} disabledReason={c.is_base ? "La moneda base siempre está activa" : ADMIN}>
                  <input type="hidden" name="active" value={c.active ? "false" : "true"} />
                </ActionForm>
              </Td>
              <Td>
                <ActionForm path={`/finance/currencies/${c.code}`} method="PATCH" ifMatch={c.lock_version} submitLabel={c.is_charge ? "Sí · quitar" : "No · usar"} submitClass={small} inline
                  success="Moneda de cobro actualizada" disabled={!admin || c.is_base} disabledReason={c.is_base ? "La moneda base siempre se cobra" : ADMIN}>
                  <input type="hidden" name="is_charge" value={c.is_charge ? "false" : "true"} />
                </ActionForm>
              </Td>
              <Td>{!c.is_base && <Link href={`/admin/monedas?moneda=${c.code}`} scroll={false} className="text-xs text-sky-800 hover:underline">Historial</Link>}</Td>
            </tr>
          ))}
        </Table>
      </div>

      <div className="mt-4 grid grid-cols-1 gap-4 [&>*]:min-w-0 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <Card title={`Historial de 30 días · ${code}`} actions={
          <span className="flex flex-wrap gap-1">{foreign.filter((c) => c.active).map((c) => <Link key={c.code} href={`/admin/monedas?moneda=${c.code}`} scroll={false} className={`${small} ${c.code === code ? "bg-sky-50 ring-1 ring-sky-300" : ""}`}>{c.code}</Link>)}</span>
        }>
          <LineChart label={`Tipo de cambio ${code} de los últimos 30 días`} points={history.map((h) => ({ x: h.date, y: Number(h.rate), alert: h.alert }))} />
          <div className="mt-3 max-h-64 overflow-y-auto">
            <Table head={["Fecha", "TC", "Var. diaria", "Fuente"]}>
              {[...history].reverse().map((h) => (
                <tr key={h.date} data-fx-date={h.date}>
                  <Td>{h.date}</Td>
                  <Td>{Number(h.rate).toFixed(4)}</Td>
                  <Td className={h.alert ? "font-medium text-rose-700" : ""}>{h.change_pct === null ? "—" : `${h.change_pct > 0 ? "+" : ""}${h.change_pct.toFixed(2)} %`}</Td>
                  <Td className="text-xs">{h.source ?? "—"}{h.is_demo && " (demo)"}</Td>
                </tr>
              ))}
            </Table>
          </div>
        </Card>

        <Card title="Conversor">
          <form method="get" className="grid grid-cols-3 gap-2 text-sm">
            <input type="hidden" name="moneda" value={code} />
            <label className="col-span-3 block"><span className="mb-1 block font-medium text-slate-700">Monto</span><input name="monto" type="number" min="0.01" step="0.01" defaultValue={amount} className={inputCls} /></label>
            <label className="block"><span className="mb-1 block font-medium text-slate-700">De</span><select name="de" defaultValue={from} className={inputCls}>{active.map((c) => <option key={c.code}>{c.code}</option>)}</select></label>
            <label className="block"><span className="mb-1 block font-medium text-slate-700">A</span><select name="a" defaultValue={to} className={inputCls}>{active.map((c) => <option key={c.code}>{c.code}</option>)}</select></label>
            <button className={`${btnGhostCls} self-end`}>Convertir</button>
          </form>
          {conv && (
            <dl className="mt-3 space-y-1 text-sm" data-conversion>
              <div><dt className="inline text-slate-500">Sin margen: </dt><dd className="inline font-medium">{conv.to} {Number(conv.result).toLocaleString("es-PE", { maximumFractionDigits: 2 })}</dd></div>
              <div><dt className="inline text-slate-500">Al cliente (margen {conv.margin_pct} %): </dt><dd className="inline font-medium">{conv.to} {Number(conv.result_with_margin).toLocaleString("es-PE", { maximumFractionDigits: 2 })}</dd></div>
            </dl>
          )}
        </Card>
      </div>
    </section>
  );
}
