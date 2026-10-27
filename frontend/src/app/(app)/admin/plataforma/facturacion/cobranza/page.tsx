import { api } from "@/lib/api";
import { date, dateTime } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { PlatformBillingHeader, StatusPill } from "@/components/billing/ui";
import { pen, type PlatformInvoice } from "@/lib/billing";

type Dunning = PlatformInvoice & { calendar: { label: string; at: string; state: "done" | "next" | "pending" | string }[]; tenant_status: string };
type Policy = { retry_days: number[]; retry_hour: number; suspend_after_last: boolean };

const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50";
const STEP: Record<string, "green" | "sky" | "slate" | "rose"> = { done: "slate", next: "sky", pending: "slate", failed: "rose" };

/** Cobranza (dunning, spec 13 §4.1): calendario D+1/D+3/D+7, reintentar, enlace de pago, prórroga, pago manual, política y job. */
export default async function DunningPage() {
  const res = await api<{ data: Dunning[]; policy: Policy }>("/platform/dunning");
  const p = res.policy;

  return (
    <>
      <PlatformBillingHeader active="cobranza" />
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="min-w-0 space-y-3 lg:col-span-2">
          {res.data.length === 0 && <p className="rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">No hay facturas en cobranza.</p>}
          {res.data.map((i) => (
            <Card key={i.id} title={<span>{i.tenant_name} <span className="font-mono text-xs font-normal text-slate-500">{i.tenant_code}</span></span>}
              actions={<StatusPill status={i.status} label={i.status_label} />}>
              <div className="space-y-3 text-sm" data-dunning={i.tenant_code}>
                <p className="text-slate-700">{i.number} · {pen(i.total)} · falló el {date(i.dunning_failed_at ?? null)}
                  {i.tenant_status === "suspendido" && <span className="ml-1 text-rose-700">· tenant suspendido</span>}
                  {(i.dunning_extended_days ?? 0) > 0 && <span className="ml-1 text-amber-800">· prórroga +{i.dunning_extended_days} días</span>}</p>
                <ol className="flex flex-wrap gap-2" aria-label="Calendario de reintentos">
                  {i.calendar.map((c) => (
                    <li key={c.label} data-step={c.state}><Pill tone={STEP[c.state] ?? "slate"}>{c.label} · {date(c.at)}{c.state === "done" ? " ✓" : ""}</Pill></li>
                  ))}
                </ol>
                {i.dunning_next_retry_at && <p className="text-xs text-slate-600" data-next-retry>Próximo reintento: {dateTime(i.dunning_next_retry_at)}</p>}
                {i.status !== "paid" && i.status !== "void" && <div className="flex flex-wrap items-start gap-1">
                  <ActionForm path={`/platform/invoices/${i.id}/retry`} idempotent inline submitLabel="Reintentar ahora" submitClass={small} />
                  <ActionForm path={`/platform/invoices/${i.id}/payment-link`} inline submitLabel="Enviar enlace de pago" submitClass={small} />
                  {i.status === "failed" && <ActionForm path={`/platform/invoices/${i.id}/dunning/extend`} inline submitLabel="Prórroga 7 días" submitClass={small} />}
                  <details><summary className={`${small} cursor-pointer list-none`}>Pago manual</summary>
                    <ActionForm path={`/platform/invoices/${i.id}/manual-payment`} idempotent submitLabel="Registrar pago" className="mt-1 space-y-1" submitClass={small}>
                      <input name="reference" required placeholder="N.° de operación" aria-label="Referencia" className="w-40 rounded border border-slate-300 px-2 py-1 text-xs" />
                      <select name="method" aria-label="Medio" className="block rounded border border-slate-300 px-2 py-1 text-xs"><option value="transferencia">Transferencia</option><option value="deposito">Depósito</option></select>
                    </ActionForm>
                  </details>
                </div>}
              </div>
            </Card>
          ))}
        </div>
        <div className="min-w-0 space-y-4">
          <Card title="Job de cobranza">
            <p className="mb-2 text-sm text-slate-600">billing:dunning corre cada hora y reintenta las facturas vencidas en su calendario.</p>
            <ActionForm path="/platform/dunning/run" submitLabel="Ejecutar ahora" />
          </Card>
          <Card title="Política de reintentos">
            <p className="mb-2 text-sm text-slate-700">Reintentos a D+{p.retry_days.join(", D+")} a las {String(p.retry_hour).padStart(2, "0")}:00 (Lima). {p.suspend_after_last ? "Tras el último rechazo se suspende el tenant." : "Tras el último rechazo la factura queda vencida, sin suspender."}</p>
            <ActionForm path="/platform/dunning-policy" method="PATCH" submitLabel="Guardar política" className="space-y-2">
              <div className="flex flex-wrap gap-2">
                {[0, 1, 2].map((n) => (
                  <label key={n} className="text-xs text-slate-600">Reintento {n + 1} (días)
                    <input type="number" name="retry_days[]" min={1} max={30} defaultValue={p.retry_days[n] ?? ""} className="mt-1 block w-20 rounded border border-slate-300 px-2 py-1 text-sm" /></label>
                ))}
              </div>
              <label className="flex items-center gap-2 text-sm"><input type="hidden" name="suspend_after_last" value="false" />
                <input type="checkbox" name="suspend_after_last" value="true" defaultChecked={p.suspend_after_last} /> Suspender tras el último rechazo</label>
            </ActionForm>
          </Card>
        </div>
      </div>
    </>
  );
}
