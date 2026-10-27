import Link from "@/components/Link";
import { api } from "@/lib/api";
import { dateTime } from "@/lib/crm";
import { Card, PageHeader, Table, Td } from "@/components/crm/ui";
import { KpiGrid, Pill, fmt, type Kpi } from "@/components/admin/ui";

type Health = { id: string; code: string; name: string; plan: string; status: string; integrations: string; jobs_error: number; jobs_paused: number; open_errors: number; definitive_errors: number; mfa_missing: number; last_activity: string | null; level: string };
type Plan = { id: string; name: string; price: number; currency: string; max_users: number | null; max_storage_gb: number | null; max_sedes: number | null; support: string; modules: number };

const LEVEL: Record<string, ["green" | "amber" | "rose", string]> = { ok: ["green", "Sano"], advertencia: ["amber", "Atención"], error: ["rose", "Con errores"], suspendido: ["rose", "Suspendido"] };

/** Salud de la plataforma (spec 09 §4.1): indicadores por tenant sin acceso a datos de negocio. */
export default async function PlatformPage() {
  const [{ data }, tenants, plans] = await Promise.all([
    api<{ data: Health[] }>("/platform/tenants/health"),
    api<{ meta: { kpis: Kpi[] } }>("/platform/tenants"),
    api<{ data: Plan[] }>("/platform/plans"),
  ]);

  return (
    <section>
      <PageHeader title="Plataforma" subtitle="Salud operativa de los tenants y catálogo de planes." />
      <nav className="mb-4 flex gap-3 text-sm" aria-label="Consola de plataforma">
        <span className="font-medium text-sky-800">Salud de tenants</span>
        <Link href="/admin/plataforma/facturacion" className="text-slate-600 hover:underline">Facturación SaaS</Link>
      </nav>
      <KpiGrid items={tenants.meta.kpis} />
      <Table head={["Tenant", "Plan", "Integraciones", "Jobs con error / pausados", "Errores abiertos", "Internos sin MFA", "Última actividad", "Salud"]}>
        {data.map((h) => (
          <tr key={h.id} data-health={h.code}>
            <Td><Link href={`/admin/tenants?id=${h.code}`} className="font-medium text-sky-800 hover:underline">{h.name}</Link><span className="block font-mono text-xs text-slate-500">{h.code} · {h.status}</span></Td>
            <Td>{h.plan}</Td><Td>{h.integrations}</Td><Td>{h.jobs_error} / {h.jobs_paused}</Td><Td>{h.open_errors} ({h.definitive_errors} definitivos)</Td><Td>{h.mfa_missing}</Td>
            <Td>{dateTime(h.last_activity)}</Td>
            <Td><Pill tone={(LEVEL[h.level] ?? LEVEL.advertencia)[0]}>{(LEVEL[h.level] ?? [null, h.level])[1]}</Pill></Td>
          </tr>
        ))}
      </Table>
      <div className="mt-5 grid gap-4 md:grid-cols-3">
        {plans.data.map((p) => (
          <Card key={p.id} title={p.name}>
            <div className="space-y-1 p-4 text-sm">
              <p className="text-xl font-semibold">{fmt(p.price, p.currency)}<span className="text-sm font-normal text-slate-500"> / mes</span></p>
              <p>{p.max_users ?? "Ilimitados"} usuarios · {p.max_sedes ?? "∞"} sedes · {p.max_storage_gb ?? "∞"} GB</p>
              <p className="text-slate-600">{p.modules} módulos · {p.support}</p>
            </div>
          </Card>
        ))}
      </div>
    </section>
  );
}
