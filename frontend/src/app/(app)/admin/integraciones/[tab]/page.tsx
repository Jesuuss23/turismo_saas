import { api } from "@/lib/api";
import { dateTime, qs } from "@/lib/crm";
import { isPlatformSession } from "@/lib/session";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Input, Kpis, Notice, PageHeader, Select, Table, Td } from "@/components/crm/ui";
import { Pill, RouteTabs } from "@/components/admin/ui";

type Adapter = {
  id: string; code: string; name: string; kind: string; kind_label: string; status: string; status_label: string; environment: string; owner: string | null; owner_user_id: string | null;
  max_retries: number; backoff_base_s: number; timeout_s: number; secret: string; secret_rotation_due: string | null; note: string | null;
  last_test_at: string | null; last_test_ok: boolean | null; last_test_result: string | null; contract: string[]; can_production: boolean; open_errors: number;
};
type Job = {
  id: string; code: string; name: string; cron: string; cron_label: string; queue: string; paused: boolean; last_run_at: string | null; next_run_at: string | null;
  last_status: string | null; last_result: string | null; is_system: boolean; report_key: string | null; report_format: string | null; recipients: string | null; owner: string | null;
};
type ErrorRow = {
  id: string; code: string; source: string; ref_code: string; target: string; error: string; error_class: string; attempt: number; max_attempts: number;
  next_retry_at: string | null; status: string; status_label: string; resolution: string | null; updated_at: string;
};
type Health = { id: string; code: string; name: string; plan: string; status: string; integrations: string; jobs_error: number; jobs_paused: number; open_errors: number; definitive_errors: number; mfa_missing: number; last_activity: string | null; level: string };
type Platform = { health: Health[]; adapters: { code: string; name: string; kind: string; default_status: string; contract: string[] | null }[]; jobs: { code: string; name: string; cron: string; cron_label: string; queue: string }[] };

const TABS = [{ key: "adapters", label: "Adapters" }, { key: "jobs", label: "Scheduler" }, { key: "errores", label: "Errores" }];
const TONE: Record<string, "green" | "amber" | "rose" | "slate" | "sky"> = {
  activo: "green", ok: "green", sandbox: "sky", parcial: "amber", advertencia: "amber", por_validar: "slate", futuro: "slate", error: "rose", reintentando: "amber", fallo_definitivo: "rose", resuelto: "green",
};
const small = "rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50";

/** Integraciones, scheduler y errores (PROC-064/065, spec 09 §4.7). En la consola de plataforma: salud por tenant. */
export default async function IntegrationsPage({ params, searchParams }: { params: Promise<{ tab: string }>; searchParams: Promise<{ status?: string }> }) {
  const [{ tab }, sp] = await Promise.all([params, searchParams]);
  if (await isPlatformSession()) return <PlatformView />;

  return (
    <section>
      <PageHeader title="Integraciones" subtitle="Proveedores externos, tareas programadas y errores con reintento." />
      <RouteTabs base="/admin/integraciones" tabs={TABS} active={tab} />
      {tab === "adapters" && <AdaptersTab />}
      {tab === "jobs" && <JobsTab />}
      {tab === "errores" && <ErrorsTab status={sp.status} />}
    </section>
  );
}

async function AdaptersTab() {
  const adapters = await api<Adapter[]>("/integrations");
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {adapters.map((a) => (
        <Card key={a.id} title={<span>{a.name} <span className="font-mono text-xs font-normal text-slate-500">{a.code}</span></span>}
          actions={<Pill tone={TONE[a.status] ?? "slate"}>{a.status_label}</Pill>}>
          <div className="space-y-3 p-4 text-sm" data-adapter={a.code}>
            <p className="text-slate-600">{a.kind_label} · entorno {a.environment} · {a.secret}{a.owner && ` · responsable ${a.owner}`}</p>
            {a.note && <p className="text-xs text-slate-500">{a.note}</p>}
            {a.last_test_at && <p className={a.last_test_ok ? "text-emerald-800" : "text-rose-700"}>Última prueba {dateTime(a.last_test_at)}: {a.last_test_result}</p>}
            {a.open_errors > 0 && <Notice tone="warning">{a.open_errors} error(es) abierto(s).</Notice>}
            <details><summary className="cursor-pointer text-xs text-slate-600">Contrato del adapter</summary>
              <ul className="mt-1 list-disc pl-5 font-mono text-xs text-slate-600">{a.contract.map((c) => <li key={c}>{c}</li>)}</ul></details>
            <ActionForm path={`/integrations/${a.code}/test`} inline submitLabel="Probar conexión" submitClass={small} />
            <details><summary className="cursor-pointer text-xs text-slate-600">Configurar reintentos y entorno</summary>
              <ActionForm path={`/integrations/${a.code}/config`} method="PATCH" submitLabel="Guardar" className="mt-2 grid grid-cols-2 gap-2">
                <Select name="environment" label="Entorno" options={[{ value: "sandbox", label: "Sandbox" }, ...(a.can_production ? [{ value: "production", label: "Producción" }] : [])]} defaultValue={a.environment} />
                <Input name="max_retries" label="Reintentos" type="number" min={0} max={10} defaultValue={a.max_retries} />
                <Input name="backoff_base_s" label="Backoff base (s)" type="number" min={5} defaultValue={a.backoff_base_s} />
                <Input name="timeout_s" label="Timeout (s)" type="number" min={5} max={120} defaultValue={a.timeout_s} />
              </ActionForm>
            </details>
          </div>
        </Card>
      ))}
    </div>
  );
}

async function JobsTab() {
  const jobs = await api<Job[]>("/jobs");
  return (
    <Table head={["Job", "Frecuencia", "Último resultado", "Próxima", ""]}>
      {jobs.map((j) => (
        <tr key={j.id} data-job={j.code}>
          <Td><span className="font-mono text-xs text-slate-500">{j.code}</span> <span className="font-medium text-slate-900">{j.name}</span>
            {j.recipients && <span className="block text-xs text-slate-500">{j.report_format} → {j.recipients}{j.owner && ` · ${j.owner}`}</span>}</Td>
          <Td>{j.cron_label}<span className="block font-mono text-xs text-slate-500">{j.cron} · {j.queue}</span></Td>
          <Td>{j.paused ? <Pill>Pausado</Pill> : j.last_status ? <Pill tone={TONE[j.last_status] ?? "slate"}>{j.last_status}</Pill> : "—"}
            {j.last_result && <span className="block max-w-xs text-xs text-slate-600">{j.last_result}</span>}<span className="block text-xs text-slate-500">{dateTime(j.last_run_at)}</span></Td>
          <Td>{j.paused ? "—" : dateTime(j.next_run_at)}</Td>
          <Td>
            <div className="flex flex-wrap gap-1">
              <ActionForm path={`/jobs/${j.code}/run`} idempotent inline submitLabel="Ejecutar ahora" submitClass={small} />
              <ActionForm path={`/jobs/${j.code}/${j.paused ? "resume" : "pause"}`} inline submitLabel={j.paused ? "Reanudar" : "Pausar"} submitClass={small} />
              {!j.is_system && <ActionForm path={`/jobs/${j.code}`} method="DELETE" inline submitLabel="Eliminar" submitClass={small} confirm={`¿Eliminar ${j.code}?`} />}
            </div>
          </Td>
        </tr>
      ))}
    </Table>
  );
}

async function ErrorsTab({ status }: { status?: string }) {
  const { data, meta } = await api<{ data: ErrorRow[]; meta: { kpis: { retrying: number; failed: number; resolved: number }; statuses: Record<string, string> } }>(`/integration-errors${qs({ status })}`);
  return (
    <div>
      <Kpis items={[{ label: "Reintentando", value: meta.kpis.retrying }, { label: "Fallo definitivo", value: meta.kpis.failed }, { label: "Resueltos", value: meta.kpis.resolved }]} />
      <form method="get" className="mb-4 flex gap-2">
        <select name="status" defaultValue={status ?? ""} aria-label="Estado" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
          <option value="">Todos</option>{Object.entries(meta.statuses).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        <button className="rounded-md border border-slate-300 px-3 py-2 text-sm">Filtrar</button>
      </form>
      <Table head={["Error", "Origen", "Detalle", "Intento", "Estado", ""]}>
        {data.map((e) => (
          <tr key={e.id} data-error={e.code}>
            <Td><span className="font-mono text-xs">{e.code}</span><span className="block text-xs text-slate-500">{dateTime(e.updated_at)}</span></Td>
            <Td>{e.ref_code}<span className="block text-xs text-slate-500">{e.target}</span></Td>
            <Td><span className="block max-w-sm">{e.error}</span><span className="text-xs text-slate-500">{e.error_class}</span>{e.resolution && <span className="block text-xs text-emerald-800">{e.resolution}</span>}</Td>
            <Td>{e.attempt}/{e.max_attempts}{e.next_retry_at && <span className="block text-xs text-slate-500">próximo {dateTime(e.next_retry_at)}</span>}</Td>
            <Td><Pill tone={TONE[e.status] ?? "slate"}>{e.status_label}</Pill></Td>
            <Td>
              {e.status !== "resuelto" && (
                <div className="flex flex-wrap gap-1">
                  <ActionForm path={`/integration-errors/${e.code}/retry`} inline submitLabel="Reintentar" submitClass={small} />
                  <ActionForm path={`/integration-errors/${e.code}/resolve`} inline submitLabel="Marcar resuelto" submitClass={small}>
                    <input name="resolution" placeholder="Resolución" aria-label="Resolución" className="w-32 rounded border border-slate-300 px-2 py-1 text-xs" />
                  </ActionForm>
                </div>
              )}
            </Td>
          </tr>
        ))}
      </Table>
    </div>
  );
}

async function PlatformView() {
  const d = await api<Platform>("/platform/integrations");
  return (
    <section>
      <PageHeader title="Integraciones de la plataforma" subtitle="Salud por tenant y catálogo de adapters y jobs. Sin acceso a datos de negocio." />
      <Table head={["Tenant", "Plan", "Integraciones", "Jobs con error", "Errores abiertos", "Sin MFA", "Estado"]}>
        {d.health.map((h) => (
          <tr key={h.id}>
            <Td><span className="font-medium">{h.name}</span><span className="block font-mono text-xs text-slate-500">{h.code}</span></Td>
            <Td>{h.plan}</Td><Td>{h.integrations}</Td><Td>{h.jobs_error}</Td><Td>{h.open_errors} ({h.definitive_errors} definitivos)</Td><Td>{h.mfa_missing}</Td>
            <Td><Pill tone={h.level === "ok" ? "green" : h.level === "error" ? "rose" : "amber"}>{h.level}</Pill></Td>
          </tr>
        ))}
      </Table>
      <div className="mt-5 grid gap-4 lg:grid-cols-2">
        <Card title="Adapters">
          <ul className="divide-y divide-slate-100 text-sm">{d.adapters.map((a) => <li key={a.code} className="px-4 py-2"><span className="font-mono text-xs text-slate-500">{a.code}</span> {a.name} <span className="text-xs text-slate-500">· {a.default_status}</span></li>)}</ul>
        </Card>
        <Card title="Jobs del sistema">
          <ul className="divide-y divide-slate-100 text-sm">{d.jobs.map((j) => <li key={j.code} className="px-4 py-2"><span className="font-mono text-xs text-slate-500">{j.code}</span> {j.name} <span className="text-xs text-slate-500">· {j.cron_label} · {j.queue}</span></li>)}</ul>
        </Card>
      </div>
    </section>
  );
}
