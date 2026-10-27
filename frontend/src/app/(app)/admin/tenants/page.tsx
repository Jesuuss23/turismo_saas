import Link from "@/components/Link";
import { api } from "@/lib/api";
import { dateTime, qs } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, Notice, PageHeader, Select, Table, Td, TextArea, btnCls, btnGhostCls } from "@/components/crm/ui";
import { Check, KpiGrid, Pill, fmt, type Kpi } from "@/components/admin/ui";
import { IsolationTest } from "@/components/admin/IsolationTest";

type Tenant = {
  id: string; code: string; slug: string; domain: string; trade_name: string; legal_name: string; ruc: string; country: string; plan_id: string; plan: string;
  status: string; status_label: string; users: number; max_users: number | null; mrr: number; modules: number; storage_gb: number; admin_email: string | null; lock_version: number;
  trial_ends_at: string | null; last_activity: string | null;
};
type Detail = Tenant & {
  fiscal_address: string | null; business_type: string | null; legal_rep: string | null; timezone: string; locale: string; currency: string; suspend_reason: string | null; suspended_at: string | null; created_at: string;
  usage: { label: string; used: number; limit: number | null }[]; features: { key: string; label: string; value: string; included: boolean }[];
  channels: { key: string; label: string; min_plan: string; included: boolean }[];
  health: { mfa_missing: number; open_errors: number; definitive_errors: number; jobs_error: number; integrations_ok: number; integrations_total: number; sedes: number };
  history: { code: string; action: string; before: string | null; after: string | null; actor: string | null; at: string }[];
};
type Plan = { id: string; name: string; rank: number; price: number; currency: string; max_users: number | null; max_storage_gb: number | null; max_sedes: number | null; support: string; modules: number };
type Meta = {
  kpis: Kpi[]; plans: Plan[]; statuses: Record<string, string>;
  entitlements: { features: { key: string; label: string; values: Record<string, string> }[]; channels: { channel_key: string; label: string; min_plan_id: string; description: string }[] };
};
type Params = { status?: string; plan?: string; q?: string; id?: string };

const TONE: Record<string, "green" | "amber" | "rose"> = { activo: "green", prueba: "amber", suspendido: "rose" };

/** Consola de tenants (PROC-001, spec 09 §4.1): alta, plan, suspensión y prueba de aislamiento. Sin datos de negocio. */
export default async function TenantsPage({ searchParams }: { searchParams: Promise<Params> }) {
  const sp = await searchParams;
  const filters = { status: sp.status, plan: sp.plan, q: sp.q };
  const { data, meta } = await api<{ data: Tenant[]; meta: Meta }>(`/platform/tenants${qs(filters)}`);
  const detail = sp.id ? (await api<{ data: Detail }>(`/platform/tenants/${encodeURIComponent(sp.id)}`)).data : null;
  const back = `/admin/tenants${qs(filters)}`;
  const join = back.includes("?") ? "&" : "?";

  return (
    <section>
      <PageHeader title="Tenants" subtitle="Cuentas de clientes de la plataforma." actions={<Link href="/admin/tenants/nuevo" className={btnCls}>Nuevo tenant</Link>} />
      <KpiGrid items={meta.kpis} />
      <form method="get" className="mb-4 flex flex-wrap gap-2">
        <input name="q" defaultValue={sp.q} placeholder="Nombre, RUC o subdominio" className="rounded-md border border-slate-300 px-3 py-2 text-sm" />
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
          <option value="">Todos los estados</option>{Object.entries(meta.statuses).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        <select name="plan" defaultValue={sp.plan ?? ""} aria-label="Plan" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
          <option value="">Todos los planes</option>{meta.plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Tenant", "Plan", "Estado", "Usuarios", "MRR", "Almacenamiento", "Última actividad"]}>
        {data.map((t) => (
          <tr key={t.id} data-tenant={t.code}>
            <Td><Link href={`${back}${join}id=${t.code}`} className="font-medium text-sky-800 hover:underline">{t.trade_name}</Link><span className="block text-xs text-slate-500">{t.code} · {t.domain}</span></Td>
            <Td>{t.plan}</Td>
            <Td><Pill tone={TONE[t.status]}>{t.status_label}</Pill>{t.trial_ends_at && <span className="block text-xs text-slate-500">prueba hasta {dateTime(t.trial_ends_at)}</span>}</Td>
            <Td>{t.users}{t.max_users ? ` / ${t.max_users}` : ""}</Td>
            <Td>{fmt(t.mrr, "PEN")}</Td>
            <Td>{fmt(t.storage_gb, "GB")}</Td>
            <Td>{dateTime(t.last_activity)}</Td>
          </tr>
        ))}
      </Table>

      <div className="mt-5 grid gap-4 lg:grid-cols-2">
        <Card title="Planes y entitlements">
          <div className="overflow-x-auto">
            <table className="min-w-full text-xs">
              <thead className="bg-slate-50"><tr><th className="px-3 py-2 text-left">Funcionalidad</th>{meta.plans.map((p) => <th key={p.id} className="px-2 py-2">{p.name}<span className="block font-normal text-slate-500">{fmt(p.price, p.currency)}</span></th>)}</tr></thead>
              <tbody>
                {meta.entitlements.features.map((f) => (
                  <tr key={f.key} className="border-t border-slate-100"><td className="px-3 py-1.5">{f.label}</td>
                    {meta.plans.map((p) => <td key={p.id} className="text-center">{f.values[p.id] === "included" ? <Check ok /> : f.values[p.id] === "addon" ? <span className="text-amber-700">add-on</span> : <Check ok={false} />}</td>)}</tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
        <Card title="Prueba de aislamiento entre tenants">
          <div className="p-4"><IsolationTest tenants={data.map((t) => ({ value: t.code, label: `${t.code} · ${t.trade_name}` }))} /></div>
        </Card>
      </div>

      {detail && (
        <Drawer title={`${detail.code} · ${detail.trade_name}`} close={back}>
          <div className="space-y-4">
            <div className="flex flex-wrap gap-2"><Pill tone={TONE[detail.status]}>{detail.status_label}</Pill><Pill tone="sky">{detail.plan}</Pill></div>
            {detail.suspend_reason && <Notice tone="danger">Suspendido {dateTime(detail.suspended_at)}: {detail.suspend_reason}</Notice>}
            <dl className="grid grid-cols-2 gap-2 text-sm">
              <dt className="text-slate-500">Razón social</dt><dd>{detail.legal_name}</dd>
              <dt className="text-slate-500">RUC</dt><dd>{detail.ruc}</dd>
              <dt className="text-slate-500">Dominio</dt><dd>{detail.domain}</dd>
              <dt className="text-slate-500">Administrador</dt><dd className="break-words">{detail.admin_email ?? "—"}</dd>
              <dt className="text-slate-500">Rubro</dt><dd>{detail.business_type ?? "—"}</dd>
              <dt className="text-slate-500">Zona / idioma</dt><dd>{detail.timezone} · {detail.locale} · {detail.currency}</dd>
            </dl>
            <Card title="Uso del plan">
              <ul className="space-y-2 p-4 text-sm">{detail.usage.map((u) => <li key={u.label} className="flex justify-between"><span>{u.label}</span><span>{u.used} / {u.limit ?? "∞"}</span></li>)}</ul>
            </Card>
            <Card title="Salud">
              <p className="p-4 text-sm text-slate-700">Integraciones {detail.health.integrations_ok}/{detail.health.integrations_total} · jobs con error {detail.health.jobs_error} · errores abiertos {detail.health.open_errors} · usuarios internos sin MFA {detail.health.mfa_missing}</p>
            </Card>
            <Card title="Cambiar plan">
              <div className="p-4">
                <ActionForm path={`/platform/tenants/${detail.code}/change-plan`} ifMatch={detail.lock_version} submitLabel="Cambiar plan" success="Plan actualizado">
                  <Select name="plan_id" label="Plan" options={meta.plans.map((p) => ({ value: p.id, label: `${p.name} · ${fmt(p.price, p.currency)} · ${p.max_users ?? "∞"} usuarios` }))} defaultValue={detail.plan_id} />
                  <Select name="effective" label="Vigencia" options={[{ value: "immediate", label: "Inmediata" }, { value: "next_cycle", label: "Próximo ciclo" }]} defaultValue="immediate" />
                  <p className="text-xs text-slate-500">Bajar de plan exige que usuarios y almacenamiento quepan en el nuevo límite; los canales no incluidos se desactivan.</p>
                </ActionForm>
              </div>
            </Card>
            <Card title={detail.status === "suspendido" ? "Reactivar" : "Suspender"}>
              <div className="p-4">
                <ActionForm path={`/platform/tenants/${detail.code}/${detail.status === "suspendido" ? "reactivate" : "suspend"}`} ifMatch={detail.lock_version}
                  submitLabel={detail.status === "suspendido" ? "Reactivar tenant" : "Suspender tenant"}
                  submitClass={detail.status === "suspendido" ? btnCls : "rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800 disabled:opacity-50"}>
                  <TextArea name="reason" label="Motivo (obligatorio)" required />
                  {detail.status !== "suspendido" && <p className="text-xs text-slate-500">Los usuarios del tenant pierden acceso de inmediato; los datos se conservan.</p>}
                </ActionForm>
              </div>
            </Card>
            <Card title="Funcionalidades y canales del plan">
              <ul className="grid grid-cols-1 gap-1 p-4 text-xs sm:grid-cols-2">
                {detail.features.map((f) => <li key={f.key}><Check ok={f.included} /> {f.label}</li>)}
                {detail.channels.map((c) => <li key={c.key}><Check ok={c.included} /> {c.label}</li>)}
              </ul>
            </Card>
            {detail.history.length > 0 && (
              <Card title="Historial">
                <ul className="divide-y divide-slate-100 text-sm">{detail.history.map((h) => <li key={h.code} className="px-4 py-2">{h.action}: {h.after}<span className="block text-xs text-slate-500">{h.actor} · {dateTime(h.at)}</span></li>)}</ul>
              </Card>
            )}
          </div>
        </Drawer>
      )}
    </section>
  );
}
