import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { date } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Input, Kpis, Notice, Select, Table, Td, TextArea, btnGhostCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { Banners, Facts, Meter, StatusPill, SubscriptionHeader } from "@/components/billing/ui";
import { ORIGIN, num, pen, type Preview, type Subscription, type UsageRow } from "@/lib/billing";

type PlanRules = {
  plan_id: string; plan_name: string; is_demo: boolean; included: number; total: number;
  groups: { key: string; label: string; rules: { code: string; label: string; min_plan_name: string; included: boolean }[] }[];
  upgrades: { plan_id: string; plan_name: string; adds: string[] }[];
};

const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-50";

/** Suscripción del tenant (spec 13 §2): KPIs, avisos, consumo, plan, comparativo, add-ons, cupón y cancelación con encuesta. */
export default async function SubscriptionPage({ searchParams }: { searchParams: Promise<{ plan?: string; cycle?: string; cancelar?: string }> }) {
  const sp = await searchParams;
  const [s, usage, rules] = await Promise.all([
    api<{ data: Subscription }>("/billing/subscription").then((r) => r.data),
    api<{ data: UsageRow[] }>("/billing/usage").then((r) => r.data),
    api<{ data: PlanRules }>("/billing/plan-rules").then((r) => r.data),
  ]);
  const sub = s.subscription;
  const preview = sp.plan ? await previewChange(sp.plan, sp.cycle === "annual" ? "annual" : "monthly") : null;
  const operable = !["suspended", "canceled"].includes(sub.status);

  return (
    <>
      <SubscriptionHeader active="" subtitle={`${sub.code} · ${sub.plan_name} ${sub.cycle_label.toLowerCase()}`} />
      <Banners rows={s.banners} />
      <Kpis items={[
        { label: "Plan", value: sub.plan_name, hint: <StatusPill status={sub.status} label={`${sub.status_label} · ${sub.cycle_label}`} /> },
        { label: sub.status === "cancel_scheduled" ? "Termina" : sub.status === "trialing" ? "Fin de la prueba" : "Renovación",
          value: date(sub.status === "cancel_scheduled" ? sub.cancel_at : sub.status === "trialing" ? sub.trial_ends_at : sub.renews_on),
          hint: sub.auto_renew ? "Renovación automática" : "Sin renovación automática" },
        { label: "Próximo cargo", value: s.next_charge && !s.next_charge.skipped ? pen(s.next_charge.total) : "—", hint: s.next_charge && !s.next_charge.skipped ? `IGV incluido · ${date(s.next_charge.date)}` : "No habrá renovación" },
        { label: "Tarjeta", value: s.payment_method ? `•••• ${s.payment_method.last4}` : "Sin tarjeta",
          hint: <Link href="/admin/suscripcion/pago" className="text-sky-800 hover:underline">{s.payment_method ? `${s.payment_method.brand.toUpperCase()} · vence ${s.payment_method.exp}` : "Registrar tarjeta"}</Link> },
      ]} />

      {preview && <ChangePanel s={s} preview={preview} />}
      {sp.cancelar && sub.status !== "cancel_scheduled" && <CancelPanel s={s} />}

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="min-w-0 space-y-4 lg:col-span-2">
          <Card title="Consumo del mes" actions={<Link href="/admin/suscripcion/historial" className="text-sm text-sky-800 hover:underline">Historial</Link>}>
            <div className="grid gap-4 sm:grid-cols-2">{usage.map((u) => <Meter key={u.metric} row={u} />)}</div>
          </Card>

          <Card title={`Qué incluye su plan · ${rules.included} de ${rules.total} reglas de reserva`}>
            {rules.is_demo && <p className="mb-2 text-xs text-slate-500">Matriz de demostración: la plataforma puede ajustar qué plan habilita cada regla.</p>}
            <div className="grid gap-3 sm:grid-cols-2">
              {rules.groups.filter((g) => g.rules.length).map((g) => (
                <div key={g.key}>
                  <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{g.label}</p>
                  <ul className="mt-1 space-y-0.5 text-sm">
                    {g.rules.map((r) => (
                      <li key={r.code} className={r.included ? "text-slate-800" : "text-slate-400"} data-rule={r.code}>
                        {r.included ? "✓" : "🔒"} {r.label}{!r.included && <span className="text-xs"> · desde {r.min_plan_name}</span>}
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          </Card>

          <Card title="Planes">
            <div className="grid gap-3 md:grid-cols-3">
              {s.plans.map((p) => (
                <div key={p.id} className={`rounded-lg border p-3 text-sm ${p.current ? "border-sky-600 ring-1 ring-sky-200" : "border-slate-200"}`} data-plan={p.id}>
                  <p className="font-semibold text-slate-900">{p.name}{p.current && <span className="ml-1 text-xs font-normal text-sky-800">(actual)</span>}</p>
                  <p className="mt-1">{pen(p.price_month)} / mes · {pen(p.price_year)} / año</p>
                  <p className="mt-1 text-xs text-slate-600">
                    {num(p.limits.users)} usuarios · {num(p.limits.sedes)} sedes · {num(p.limits.storage_gb, "GB")} · {p.limits.api_calls_month ? `${num(p.limits.api_calls_month)} llamadas API` : "sin API"}
                  </p>
                  <p className="mt-1 text-xs text-slate-600">{p.rules_count} reglas de reserva</p>
                  {p.direction === "upgrade" && p.rules_added.length > 0 && <p className="mt-1 text-xs text-emerald-800">Suma: {p.rules_added.slice(0, 3).join(", ")}{p.rules_added.length > 3 ? "…" : ""}</p>}
                  <div className="mt-2 flex flex-wrap gap-1">
                    {!p.current && operable && (
                      <Link href={`/admin/suscripcion?plan=${p.id}&cycle=${sub.cycle}`} className={small}>{p.direction === "upgrade" ? `Mejorar a ${p.name}` : `Pasar a ${p.name}`}</Link>
                    )}
                    {p.current && sub.cycle === "monthly" && operable && <Link href={`/admin/suscripcion?plan=${p.id}&cycle=annual`} className={small}>Pasar a anual</Link>}
                  </div>
                </div>
              ))}
            </div>
          </Card>

          <Card title="Add-ons">
            <Table head={["Add-on", "Precio mensual", "Estado", ""]}>
              {s.addons.map((a) => (
                <tr key={a.code} data-addon={a.code}>
                  <Td><span className="font-medium text-slate-900">{a.label}</span>{a.quantity > 0 && <span className="block text-xs text-slate-500">Cantidad {a.quantity}</span>}</Td>
                  <Td>{a.state === "included" ? "Incluido" : pen(a.price)}{a.quantity_allowed && a.state !== "included" && <span className="text-xs text-slate-500"> por {a.unit}</span>}</Td>
                  <Td>
                    {a.state === "included" && <Pill tone="green">Incluido en el plan</Pill>}
                    {a.state === "active" && <Pill tone="green">Activo</Pill>}
                    {a.state === "ending" && <Pill tone="amber">Se retira el {date(a.ends_at)}</Pill>}
                    {a.state === "available" && <Pill tone="slate">Disponible</Pill>}
                    {a.state === "unavailable" && <Pill tone="slate">No disponible en su plan</Pill>}
                  </Td>
                  <Td>
                    <div className="flex flex-wrap items-end gap-1">
                      {a.state === "available" && operable && (
                        <ActionForm path={`/billing/addons/${a.code}`} idempotent inline submitLabel="Contratar" submitClass={small}
                          confirm={`¿Contratar ${a.label}? Se factura el prorrateo del periodo en curso.`}>
                          {a.quantity_allowed && <input type="number" name="quantity" min={1} max={100} defaultValue={1} aria-label="Cantidad" className="w-16 rounded border border-slate-300 px-2 py-1 text-xs" />}
                        </ActionForm>
                      )}
                      {a.state === "active" && a.quantity_allowed && (
                        <ActionForm path={`/billing/addons/${a.code}`} method="PATCH" idempotent inline submitLabel="Cambiar cantidad" submitClass={small}>
                          <input type="number" name="quantity" min={1} max={100} defaultValue={a.quantity} aria-label="Cantidad" className="w-16 rounded border border-slate-300 px-2 py-1 text-xs" />
                        </ActionForm>
                      )}
                      {a.state === "active" && <ActionForm path={`/billing/addons/${a.code}`} method="DELETE" inline submitLabel="Retirar" submitClass={small} confirm={`¿Retirar ${a.label} al cierre del periodo?`} />}
                      {a.state === "ending" && <ActionForm path={`/billing/addons/${a.code}`} idempotent inline submitLabel="Mantener" submitClass={small} />}
                    </div>
                  </Td>
                </tr>
              ))}
            </Table>
          </Card>
        </div>

        <div className="min-w-0 space-y-4">
          <Card title="Plan y periodo">
            <Facts rows={[
              ["Plan", `${sub.plan_name} ${sub.cycle_label.toLowerCase()}`],
              ["Precio", <>{pen(sub.price)} + IGV{sub.negotiated && <span className="block text-xs text-slate-500">Precio pactado</span>}</>],
              ["Periodo", `${date(sub.period_start)} al ${date(sub.period_end)}`],
              ["Estado", <StatusPill key="st" status={sub.status} label={sub.status_label} />],
              ...(sub.credit_balance > 0 ? [["Saldo a favor", pen(sub.credit_balance)] as [string, string]] : []),
            ]} />
            {operable && sub.status !== "cancel_scheduled" && (
              <ActionForm path="/billing/subscription" method="PATCH" ifMatch={sub.lock_version} className="mt-3 flex flex-wrap items-end gap-2"
                submitLabel={sub.auto_renew ? "Desactivar renovación automática" : "Activar renovación automática"} submitClass={small}>
                <input type="hidden" name="auto_renew" value={sub.auto_renew ? "false" : "true"} />
              </ActionForm>
            )}
            {s.scheduled_change && (
              <div className="mt-3 rounded-md bg-sky-50 p-3 text-sm text-sky-900 ring-1 ring-sky-200" data-scheduled-change>
                Cambio programado a {s.scheduled_change.to_plan_name} desde el {date(s.scheduled_change.effective_at)}.
                <ActionForm path="/billing/subscription/scheduled-change" method="DELETE" ifMatch={sub.lock_version} className="mt-2" submitLabel="Anular cambio" submitClass={small} />
              </div>
            )}
            {s.next_charge && !s.next_charge.skipped && (
              <details className="mt-3 text-sm"><summary className="cursor-pointer text-slate-600">Detalle del próximo cargo</summary>
                <ul className="mt-1 space-y-0.5 text-xs text-slate-700">
                  {s.next_charge.lines.map((l, i) => <li key={i} className="flex justify-between gap-2"><span>{l.description}</span><span className="shrink-0">{pen(l.unit_price * l.quantity)}</span></li>)}
                  <li className="flex justify-between gap-2 border-t border-slate-100 pt-1"><span>IGV</span><span>{pen(s.next_charge.igv)}</span></li>
                  <li className="flex justify-between gap-2 font-medium"><span>Total</span><span>{pen(s.next_charge.total)}</span></li>
                </ul>
              </details>
            )}
          </Card>

          <Card title="Cupón">
            {s.coupon
              ? <p className="text-sm text-slate-700" data-coupon={s.coupon.code}><span className="font-mono font-medium">{s.coupon.code}</span>: {s.coupon.label} · quedan {s.coupon.remaining_cycles} ciclo(s)</p>
              : operable && (
                <ActionForm path="/billing/coupon" className="flex flex-wrap items-end gap-2" submitLabel="Aplicar" submitClass={small}>
                  <input name="code" required placeholder="Código" aria-label="Código del cupón" className="w-36 rounded-md border border-slate-300 px-2 py-1.5 text-sm uppercase" />
                </ActionForm>
              )}
          </Card>

          <Card title="Módulos habilitados">
            <ul className="space-y-1 text-sm">
              {s.modules.map((m) => (
                <li key={m.key} className="flex justify-between gap-2" data-module={m.key}>
                  <span className="min-w-0 text-slate-700">{m.label}{m.value !== "included" && <span className="text-xs text-slate-500"> · {m.value}</span>}</span>
                  <span className="shrink-0 text-xs text-slate-500">{ORIGIN[m.origin] ?? m.origin}{m.valid_until && ` hasta ${date(m.valid_until)}`}</span>
                </li>
              ))}
            </ul>
          </Card>

          <Card title="Cancelar suscripción">
            {sub.status === "cancel_scheduled" ? (
              <div className="space-y-2 text-sm">
                <p className="text-amber-900">Cancelación programada para el {date(sub.cancel_at)}. Sus datos se conservan 90 días después del cierre.</p>
                <ActionForm path="/billing/subscription/resume" ifMatch={sub.lock_version} submitLabel="Reanudar suscripción" />
              </div>
            ) : operable ? (
              <p className="text-sm text-slate-600">La cancelación se aplica al cierre del periodo y no genera reembolsos. <Link href="/admin/suscripcion?cancelar=1" className="text-rose-700 hover:underline">Cancelar suscripción</Link></p>
            ) : <p className="text-sm text-slate-600">Regularice el pago pendiente para gestionar la suscripción.</p>}
          </Card>
        </div>
      </div>
    </>
  );
}

async function previewChange(plan: string, cycle: string): Promise<Preview | { error: string }> {
  try {
    return (await api<{ data: Preview }>("/billing/subscription/preview-change", { method: "POST", body: JSON.stringify({ plan, cycle }) })).data;
  } catch (e) {
    return { error: e instanceof ApiError ? e.body?.message ?? "No se pudo calcular el cambio" : "No se pudo calcular el cambio" };
  }
}

/** Vista previa del cambio de plan o ciclo: prorrateo (SUB-BR-01), reglas que gana o pierde y bloqueos del downgrade (SUB-BR-03). */
function ChangePanel({ s, preview }: { s: Subscription; preview: Preview | { error: string } }) {
  if ("error" in preview) return <div className="mb-4"><Notice tone="danger">{preview.error}</Notice></div>;
  const p = preview.proration;
  const cycleLabel = preview.cycle === "annual" ? "anual" : "mensual";
  return (
    <Card title={`Cambio a ${preview.plan.name} ${cycleLabel}`} className="mb-4" actions={<Link href="/admin/suscripcion" className="text-sm text-slate-600 hover:underline">Cerrar</Link>}>
      <div className="space-y-3 text-sm" data-change-preview={preview.kind}>
        <p className="text-slate-700">
          {preview.immediate ? "El cambio se aplica hoy." : `El cambio se aplica el ${date(preview.effective_at)}, al cierre de su periodo actual. No hay reembolso por el periodo en curso.`}
          {" "}Precio: {pen(preview.price)} + IGV por {preview.cycle === "annual" ? "año" : "mes"}.
        </p>
        {p && (
          <Facts rows={[
            [p.new_period ? "Nuevo periodo" : "Prorrateo", p.new_period ? `${date(p.period_start)} al ${date(p.period_end)}` : `${p.days_left} de ${p.period_days} días`],
            ["Cargo", pen(p.charge)], ["Crédito del plan actual", `− ${pen(p.credit)}`], ["Neto", pen(p.net)], ["IGV", pen(p.igv)],
            ["Total a cobrar hoy", <strong key="t">{pen(p.total)}</strong>],
          ]} />
        )}
        {preview.rules_gained.length > 0 && <p className="text-emerald-800">Gana: {preview.rules_gained.join(", ")}.</p>}
        {preview.rules_lost.length > 0 && <p className="text-amber-900">Pierde: {preview.rules_lost.join(", ")}.</p>}
        {preview.blockers.length > 0 && (
          <div className="rounded-md bg-rose-50 p-3 text-rose-900 ring-1 ring-rose-200" data-blockers>
            <p className="font-medium">No puede pasar a {preview.plan.name} todavía:</p>
            <ul className="mt-1 list-disc pl-5">{preview.blockers.map((b) => <li key={`${b.type}-${b.key}`}>{b.message}</li>)}</ul>
          </div>
        )}
        <ActionForm path="/billing/subscription/change" ifMatch={s.subscription.lock_version} idempotent redirectTo="/admin/suscripcion"
          submitLabel={preview.immediate ? `Confirmar y pagar ${p ? pen(p.total) : ""}`.trim() : "Programar cambio"} disabled={preview.blockers.length > 0}
          disabledReason="Resuelva los bloqueos antes de cambiar de plan">
          <input type="hidden" name="plan" value={preview.plan.id} />
          <input type="hidden" name="cycle" value={preview.cycle} />
        </ActionForm>
      </div>
    </Card>
  );
}

/** Encuesta de cancelación (SUB-BR-11): motivo obligatorio, aceptación de condiciones y opción de exportar los datos. */
function CancelPanel({ s }: { s: Subscription }) {
  const sub = s.subscription;
  return (
    <Card title="Cancelar suscripción" className="mb-4" actions={<Link href="/admin/suscripcion" className="text-sm text-slate-600 hover:underline">Cerrar</Link>}>
      <ActionForm path="/billing/subscription/cancel" ifMatch={sub.lock_version} redirectTo="/admin/suscripcion" submitLabel="Programar cancelación"
        submitClass="rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800" className="grid gap-3 sm:grid-cols-2">
        <Select name="reason" label="Motivo" required placeholder="Elija un motivo" options={Object.entries(s.cancel_reasons).map(([value, label]) => ({ value, label }))} />
        <Input name="to" label="¿A qué proveedor se cambia? (opcional)" />
        <Input name="score" label="¿Nos recomendaría? (0 a 10)" type="number" min={0} max={10} />
        <Input name="stay" label="¿Qué haría que se quede?" />
        <div className="sm:col-span-2"><TextArea name="comment" label="Comentario" rows={2} /></div>
        <label className="flex items-start gap-2 text-sm text-slate-700 sm:col-span-2"><input type="checkbox" name="export_requested" value="true" className="mt-1" /> Quiero exportar mis datos antes del cierre.</label>
        <label className="flex items-start gap-2 text-sm text-slate-700 sm:col-span-2">
          <input type="checkbox" name="accept" value="true" className="mt-1" />
          Entiendo que la suscripción termina el {date(sub.status === "trialing" ? sub.trial_ends_at : sub.period_end)}, que no hay reembolso y que los datos se conservan 90 días.
        </label>
      </ActionForm>
      <p className="mt-2"><Link href="/admin/suscripcion" className={btnGhostCls}>Mantener mi suscripción</Link></p>
    </Card>
  );
}
